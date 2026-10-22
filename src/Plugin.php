<?php
/**
 * Main plugin bootstrap — singleton entry point.
 *
 * @package CookingSchoolBooking
 */

namespace CookingSchoolBooking;

use CookingSchoolBooking\Admin\AppearancePage;
use CookingSchoolBooking\Admin\AttendancePage;
use CookingSchoolBooking\Admin\BookingsPage;
use CookingSchoolBooking\Admin\CalendarPage;
use CookingSchoolBooking\Admin\ContentTypesPage;
use CookingSchoolBooking\Admin\CouponsPage;
use CookingSchoolBooking\Admin\InstructorPanel;
use CookingSchoolBooking\Admin\RefundRequestsPage;
use CookingSchoolBooking\Admin\SessionsPage;
use CookingSchoolBooking\Admin\SettingsHub;
use CookingSchoolBooking\Admin\SettingsPage;
use CookingSchoolBooking\Cron\ExpireUnsettledCron;
use CookingSchoolBooking\Cron\MarkCompletedCron;
use CookingSchoolBooking\Cron\MinToRunCron;
use CookingSchoolBooking\Cron\ReminderCron;
use CookingSchoolBooking\Cron\SeatLockCron;
use CookingSchoolBooking\Frontend\CartValidation;
use CookingSchoolBooking\Frontend\CheckoutHooks;
use CookingSchoolBooking\Frontend\OrderBooking;
use CookingSchoolBooking\Frontend\Rewrites;
use CookingSchoolBooking\Frontend\CsbArchiveSitemapProvider;
use CookingSchoolBooking\Frontend\Schema;
use CookingSchoolBooking\Frontend\SeoMeta;
use CookingSchoolBooking\Frontend\Sitemap;
use CookingSchoolBooking\Frontend\StyleVars;
use CookingSchoolBooking\Install\Migrator;
use CookingSchoolBooking\Products\ProductTypes;
use CookingSchoolBooking\Frontend\MyAccount;
use CookingSchoolBooking\REST\AdminSessionController;
use CookingSchoolBooking\REST\AttendanceController;
use CookingSchoolBooking\REST\BookingController;
use CookingSchoolBooking\REST\OtpController;
use CookingSchoolBooking\REST\VideoController;
use CookingSchoolBooking\Support\RedirectManager;
use CookingSchoolBooking\Support\Relabel;
// Services are loaded via autoloader; no use-statement needed for static-only classes.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Bootstraps all services after `plugins_loaded`.
 */
final class Plugin {

	/** @var Plugin|null */
	private static ?Plugin $instance = null;

	/**
	 * Returns (and lazily creates) the singleton instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Private constructor — use instance(). */
	private function __construct() {
		$this->load_textdomain();

		// Guard: require WooCommerce.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_woocommerce_missing' ] );
			return;
		}

		$this->init_services();
		$this->register_admin_menu();
	}

	// ─── Textdomain ──────────────────────────────────────────────────────────

	/**
	 * Loads the plugin textdomain from the languages/ folder.
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'csb',
			false,
			dirname( plugin_basename( CSB_FILE ) ) . '/languages'
		);
	}

	// ─── WooCommerce missing notice ───────────────────────────────────────────

	/**
	 * Admin notice shown when WooCommerce is not active.
	 */
	public function notice_woocommerce_missing(): void {
		$message = sprintf(
			/* translators: %s: WooCommerce plugin name. */
			esc_html__( 'افزونه‌ی «Cooking School Booking» برای کار نیاز به %s دارد. لطفاً ابتدا WooCommerce را نصب و فعال کنید.', 'csb' ),
			'<strong>WooCommerce</strong>'
		);

		echo '<div class="notice notice-error"><p>' . wp_kses_post( $message ) . '</p></div>';
	}

	// ─── Services ─────────────────────────────────────────────────────────────

	/**
	 * Initialises all plugin services.
	 */
	private function init_services(): void {
		// Phase 0 — DB upgrade check (cheap: only acts when version differs).
		Migrator::maybe_upgrade();

		// Phase 0 — Relabel WooCommerce UI strings.
		( new Relabel() )->init();

		// Phase 0 — Settings: register the old option group (backward-compat) + new hub.
		( new SettingsPage() )->init();
		( new SettingsHub() )->init();

		// Phase 1 — WC product types + admin panels + meta save.
		( new ProductTypes() )->init();

		// Phase 1 — Custom rewrite rules + archive query filter + template routing.
		( new Rewrites() )->init();

		// Phase 1 — Schema.org JSON-LD (suppresses WC default + emits Course/VideoObject).
		( new Schema() )->init();

		// Phase 1 — SEO meta/title/OG (only when Rank Math/Yoast absent or forced).
		( new SeoMeta() )->init();

		// Phase 1 — Sitemap: ensure class/video singles + archives are discoverable.
		( new Sitemap() )->init();

		// Phase 1 — WP core sitemap provider for archive URLs (no-op when Rank Math/Yoast active).
		if ( ! class_exists( 'RankMath' ) && ! defined( 'WPSEO_VERSION' ) ) {
			add_action( 'init', static function (): void {
				if ( function_exists( 'wp_sitemaps_get_server' ) ) {
					wp_sitemaps_get_server()->registry->add_provider( new CsbArchiveSitemapProvider() );
				}
			}, 20 );
		}

		// Phase 1 — 301 redirects for slug changes.
		( new RedirectManager() )->init();

		// Phase 1 — «انواع محتوا» admin control panel (§16.5).
		( new ContentTypesPage() )->init();

		// Phase 2 — Sessions CRUD admin page.
		( new SessionsPage() )->init();

		// Phase 2 — Cart/checkout validation + atomic seat lock wiring.
		( new CartValidation() )->init();

		// Phase 2 — Cron: release expired seat locks (every 5 min).
		( new SeatLockCron() )->init();

		// Phase 2 — Cron: check min_to_run threshold (hourly).
		( new MinToRunCron() )->init();

		// Phase 3 — Checkout: payment mode groundwork, terms checkbox, relabelling.
		( new CheckoutHooks() )->init();

		// Phase 3 — WC order status → booking creation + confirmation pipeline.
		( new OrderBooking() )->init();

		// Phase 4 — Cron: reminder emails for settle-due and class-tomorrow.
		( new ReminderCron() )->init();

		// Phase 4 — Cron: expire deposit bookings past their settle deadline.
		( new ExpireUnsettledCron() )->init();

		// Phase 4 — Cron: transition confirmed bookings to completed after session ends.
		( new MarkCompletedCron() )->init();

		// Phase 4 — REST: booking cancellation with policy enforcement.
		( new BookingController() )->init();

		// Phase 5 — REST: signed video stream + resource + progress endpoints.
		( new VideoController() )->init();

		// Phase 6 — WC My Account tabs (bookings + تسویه مانده + cancel via RefundService, courses, wallet, certs + printable).
		( new MyAccount() )->init();

		// Phase 6 — Admin: full bookings list + CSV + bulk SMS + status change + refund links.
		( new BookingsPage() )->init();

		// Phase 6 — Admin: pending refund requests — approve (cash/wallet) / reject.
		( new RefundRequestsPage() )->init();

		// Phase 6 — Admin: Jalali month calendar of sessions.
		( new CalendarPage() )->init();

		// Phase 6 — Admin: session attendance roster + check-in / no-show.
		( new AttendancePage() )->init();

		// Phase 6 — Instructor role + restricted panel (own sessions only).
		( new InstructorPanel() )->init();

		// Phase 6 — REST: attendance check-in, no-show, certificate issuance + verification.
		( new AttendanceController() )->init();

		// Phase 6 — REST: admin group SMS to session students.
		( new AdminSessionController() )->init();

		// Phase 8a — Appearance settings: color tokens + theme presets.
		( new AppearancePage() )->init();

		// Phase 8a — Front-end: inline :root CSS vars + enqueue csb.css.
		( new StyleVars() )->init();

		// Phase 7 — Admin: CSB coupon management.
		( new CouponsPage() )->init();

		// Phase 7 — REST: OTP phone-number login.
		( new OtpController() )->init();

		// Phase 7 — SMS adapter: hooks csb_notify + csb_bulk_sms onto the SMS gateway plugin.
		( new \CookingSchoolBooking\Support\SmsAdapter() )->init();

		// Phase 7 — Cron: purge expired OTP tokens (daily).
		add_action( 'csb_purge_otp', [ \CookingSchoolBooking\Services\OtpService::class, 'purge_expired' ] );
	}

	// ─── Admin menu ───────────────────────────────────────────────────────────

	/**
	 * Registers the top-level «آموزشگاه» admin menu and sub-pages.
	 */
	private function register_admin_menu(): void {
		// Priority 1 so the «آموزشگاه» parent menu exists before any sub-page
		// (SettingsHub, Bookings, etc.) attaches its submenu on admin_menu @10.
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ], 1 );
	}

	/**
	 * Callback: adds the complete «آموزشگاه» admin menu.
	 *
	 * Order mirrors WooCommerce: Dashboard → content → operations → config → appearance.
	 * Sub-pages whose init() registers their own add_submenu_page() calls are listed
	 * here only when they haven't done so already (we use the slug they registered).
	 */
	public function add_admin_menu(): void {
		// ── Top-level ─────────────────────────────────────────────────────────
		add_menu_page(
			esc_html__( 'آموزشگاه', 'csb' ),
			esc_html__( 'آموزشگاه', 'csb' ),
			'manage_woocommerce',
			'csb-dashboard',
			[ $this, 'render_dashboard_page' ],
			'dashicons-food',
			58
		);

		// Override the auto-created duplicate of the top-level item with «داشبورد».
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'داشبورد', 'csb' ),
			esc_html__( 'داشبورد', 'csb' ),
			'manage_woocommerce',
			'csb-dashboard',
			[ $this, 'render_dashboard_page' ]
		);

		// ── کلاس‌ها — WC product list filtered to type=class ─────────────────
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'کلاس‌ها', 'csb' ),
			esc_html__( 'کلاس‌ها', 'csb' ),
			'manage_woocommerce',
			esc_url( admin_url( 'edit.php?post_type=product&product_type=class' ) )
		);

		// ── دوره‌های ویدیویی ──────────────────────────────────────────────────
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'دوره‌های ویدیویی', 'csb' ),
			esc_html__( 'دوره‌های ویدیویی', 'csb' ),
			'manage_woocommerce',
			esc_url( admin_url( 'edit.php?post_type=product&product_type=video_course' ) )
		);

		// ── جلسات — registered by SessionsPage::init() via add_submenu_page ──
		// (slug: csb-sessions — already registered; listed here for menu ordering)

		// ── رزروها — registered by BookingsPage::init() ───────────────────────
		// (slug: csb-bookings)

		// ── تقویم — registered by CalendarPage::init() ───────────────────────
		// (slug: csb-calendar)

		// ── درخواست‌های استرداد — registered by RefundRequestsPage::init() ──
		// (slug: csb-refund-requests)

		// ── کوپن‌ها — registered by CouponsPage::init() ──────────────────────
		// (slug: csb-coupons)

		// ── حضور و غیاب — registered by AttendancePage::init() ───────────────
		// (slug: csb-attendance)

		// ── گزارش مالی ────────────────────────────────────────────────────────
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'گزارش مالی', 'csb' ),
			esc_html__( 'گزارش مالی', 'csb' ),
			'manage_woocommerce',
			'csb-financial-report',
			[ $this, 'render_financial_report_page' ]
		);

		// ── تنظیمات — registered by SettingsHub::init() ──────────────────────
		// (slug: csb-settings)

		// ── ظاهر — registered by AppearancePage::init() ──────────────────────
		// (slug: csb-appearance)
	}

	/**
	 * Renders a minimal financial summary page (revenue + outstanding balance).
	 *
	 * Shows total confirmed revenue and outstanding deposit balances from the
	 * csb_bookings table. A full reports module can replace this in a future phase.
	 */
	public function render_financial_report_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		global $wpdb;

		$table = $wpdb->prefix . 'csb_bookings';

		// Total revenue: sum of amount_paid for confirmed/completed bookings.
		$revenue = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount_paid), 0) FROM {$table}
			  WHERE status IN ('confirmed','completed')"
		);

		// Outstanding: sum of balance_due for deposit_paid bookings.
		$outstanding = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(balance_due), 0) FROM {$table}
			  WHERE status = 'deposit_paid'"
		);

		// Booking counts by status.
		$counts_raw = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status",
			ARRAY_A
		);
		$counts = [];
		foreach ( (array) $counts_raw as $row ) {
			$counts[ $row['status'] ] = (int) $row['cnt'];
		}

		$status_labels = [
			'confirmed'    => __( 'تأیید شده', 'csb' ),
			'deposit_paid' => __( 'بیعانه پرداخت شده', 'csb' ),
			'completed'    => __( 'تکمیل شده', 'csb' ),
			'cancelled'    => __( 'لغو شده', 'csb' ),
			'pending'      => __( 'در انتظار پرداخت', 'csb' ),
		];

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>' . esc_html__( 'گزارش مالی', 'csb' ) . '</h1>';
		echo '<div style="display:flex;gap:1.5em;flex-wrap:wrap;margin-bottom:2em;">';

		// Revenue card.
		echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:1.2em 1.8em;min-width:200px;">';
		echo '<div style="font-size:12px;color:#666;margin-bottom:.3em;">' . esc_html__( 'درآمد تأیید‌شده', 'csb' ) . '</div>';
		echo '<div style="font-size:1.8em;font-weight:700;color:#27ae60;">' . esc_html( number_format( $revenue ) ) . '</div>';
		echo '</div>';

		// Outstanding card.
		echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:1.2em 1.8em;min-width:200px;">';
		echo '<div style="font-size:12px;color:#666;margin-bottom:.3em;">' . esc_html__( 'مانده معوق', 'csb' ) . '</div>';
		echo '<div style="font-size:1.8em;font-weight:700;color:#e67e22;">' . esc_html( number_format( $outstanding ) ) . '</div>';
		echo '</div>';

		echo '</div>';

		// Booking status breakdown.
		echo '<h2 style="font-size:1.1em;">' . esc_html__( 'تعداد رزروها بر اساس وضعیت', 'csb' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:500px;"><thead><tr>';
		echo '<th>' . esc_html__( 'وضعیت', 'csb' ) . '</th>';
		echo '<th>' . esc_html__( 'تعداد', 'csb' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $status_labels as $status => $label ) {
			$cnt = $counts[ $status ] ?? 0;
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $cnt ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders the top-level dashboard placeholder.
	 * Full dashboard built in Phase 6.
	 */
	public function render_dashboard_page(): void {
		echo '<div class="wrap" dir="rtl">';
		echo '<h1>' . esc_html__( 'آموزشگاه', 'csb' ) . '</h1>';
		echo '<p>' . esc_html__( 'به پنل مدیریت آموزشگاه خوش آمدید.', 'csb' ) . '</p>';
		echo '</div>';
	}
}
