<?php
/**
 * Main plugin bootstrap — singleton entry point.
 *
 * @package CookingSchoolBooking
 */

namespace CookingSchoolBooking;

use CookingSchoolBooking\Admin\AttendancePage;
use CookingSchoolBooking\Admin\BookingsPage;
use CookingSchoolBooking\Admin\CalendarPage;
use CookingSchoolBooking\Admin\ContentTypesPage;
use CookingSchoolBooking\Admin\CouponsPage;
use CookingSchoolBooking\Admin\InstructorPanel;
use CookingSchoolBooking\Admin\RefundRequestsPage;
use CookingSchoolBooking\Admin\SessionsPage;
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
use CookingSchoolBooking\Frontend\Schema;
use CookingSchoolBooking\Frontend\SeoMeta;
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

		// Phase 0 — Settings page.
		( new SettingsPage() )->init();

		// Phase 1 — WC product types + admin panels + meta save.
		( new ProductTypes() )->init();

		// Phase 1 — Custom rewrite rules + archive query filter + template routing.
		( new Rewrites() )->init();

		// Phase 1 — Schema.org JSON-LD (suppresses WC default + emits Course/VideoObject).
		( new Schema() )->init();

		// Phase 1 — SEO meta/title/OG (only when Rank Math/Yoast absent or forced).
		( new SeoMeta() )->init();

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
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
	}

	/**
	 * Callback: adds menu items.
	 */
	public function add_admin_menu(): void {
		// Top-level menu.
		add_menu_page(
			esc_html__( 'آموزشگاه', 'csb' ),
			esc_html__( 'آموزشگاه', 'csb' ),
			'manage_woocommerce',
			'csb-dashboard',
			[ $this, 'render_dashboard_page' ],
			'dashicons-food',
			58
		);

		// Settings sub-menu.
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'تنظیمات آموزشگاه', 'csb' ),
			esc_html__( 'تنظیمات', 'csb' ),
			'manage_woocommerce',
			'csb-settings',
			[ SettingsPage::class, 'render' ]
		);
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
