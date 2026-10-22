<?php
/**
 * WooCommerce My Account tabs for Cooking School Booking.
 *
 * Tabs:
 *   csb-bookings — booking history + «تسویه مانده» for deposit_paid + cancel via RefundService
 *   csb-courses  — enrolled video courses + progress
 *   csb-wallet   — balance + paginated txn history
 *   csb-certs    — printable certificates (also handles ?csb_cert=SERIAL public view)
 *
 * @package BookaClass\Frontend
 */

namespace BookaClass\Frontend;

use BookaClass\Admin\SettingsPage;
use BookaClass\Services\AuditLogger;
use BookaClass\Services\BookingService;
use BookaClass\Services\CertificateService;
use BookaClass\Services\EnrollmentService;
use BookaClass\Services\IcsService;
use BookaClass\Services\RefundService;
use BookaClass\Services\WalletService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MyAccount
 */
class MyAccount {

	/** @var array<string,string>  slug => label */
	private const ENDPOINTS = [
		'csb-bookings' => 'رزروهای من',
		'csb-courses'  => 'دوره‌های من',
		'csb-wallet'   => 'کیف‌پول',
		'csb-certs'    => 'گواهی‌ها',
	];

	public function init(): void {
		add_action( 'init',                                  [ $this, 'register_endpoints' ] );
		add_filter( 'woocommerce_account_menu_items',        [ $this, 'add_menu_items' ] );
		add_filter( 'woocommerce_get_query_vars',            [ $this, 'add_query_vars' ] );

		foreach ( array_keys( self::ENDPOINTS ) as $slug ) {
			add_action(
				'woocommerce_account_' . $slug . '_endpoint',
				[ $this, 'render_' . str_replace( '-', '_', $slug ) ]
			);
		}

		// Admin-post handler for cancel action (POST form, no JS required).
		add_action( 'admin_post_csb_request_cancel',        [ $this, 'handle_cancel_request' ] );
		add_action( 'admin_post_nopriv_csb_request_cancel', [ $this, 'handle_cancel_request' ] );

		// Reschedule is handled via REST (csb/v1/booking/{id}/reschedule) — no admin-post needed.

		// ICS calendar download.
		add_action( 'template_redirect', [ $this, 'maybe_serve_ics' ], 5 );

		// Public certificate view (?csb_cert=SERIAL).
		add_action( 'template_redirect', [ $this, 'maybe_render_certificate_page' ] );

		// Enable WC reviews on class and video_course products.
		add_filter( 'woocommerce_product_reviews_enabled', [ $this, 'enable_csb_product_reviews' ], 10, 2 );
	}

	// ── Endpoint registration ─────────────────────────────────────────────────

	public function register_endpoints(): void {
		foreach ( array_keys( self::ENDPOINTS ) as $slug ) {
			add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES );
		}
	}

	// ── Menu items ────────────────────────────────────────────────────────────

	/**
	 * @param  string[] $items
	 * @return string[]
	 */
	public function add_menu_items( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );

		foreach ( self::ENDPOINTS as $slug => $label ) {
			$items[ $slug ] = esc_html__( $label, 'csb' );
		}

		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * @param  string[] $vars
	 * @return string[]
	 */
	public function add_query_vars( array $vars ): array {
		foreach ( array_keys( self::ENDPOINTS ) as $slug ) {
			$vars[ $slug ] = $slug;
		}
		return $vars;
	}

	// ── Cancel via form POST (no JS dependency) ───────────────────────────────

	public function handle_cancel_request(): void {
		$booking_id = (int) ( $_POST['booking_id'] ?? 0 );

		if ( ! $booking_id || ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'csb-bookings' ) );
			exit;
		}

		check_admin_referer( 'csb_cancel_' . $booking_id );

		$booking = BookingService::get( $booking_id );

		// Ownership check.
		if ( ! $booking || (int) $booking['student_id'] !== get_current_user_id() ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'csb-bookings' ) );
			exit;
		}

		$cancellable = [ 'pending', 'deposit_paid', 'fully_paid', 'confirmed' ];
		if ( ! in_array( $booking['status'], $cancellable, true ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'csb-bookings' ) );
			exit;
		}

		$method = in_array( $_POST['refund_method'] ?? 'wallet', [ 'cash', 'wallet' ], true )
			? sanitize_key( $_POST['refund_method'] )
			: 'wallet';

		RefundService::request_refund( $booking_id, 'student', $method, get_current_user_id() );

		AuditLogger::log( 'student_cancel_requested', 'booking', $booking_id, [
			'student_id' => get_current_user_id(),
			'method'     => $method,
		] );

		$redirect = add_query_arg( 'csb_msg', 'cancel_requested', wc_get_account_endpoint_url( 'csb-bookings' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Tab: رزروهای من ───────────────────────────────────────────────────────

	public function render_csb_bookings(): void {
		global $wpdb;

		$student_id = get_current_user_id();
		if ( ! $student_id ) {
			return;
		}

		// Feedback message.
		$msg = sanitize_key( $_GET['csb_msg'] ?? '' );
		if ( 'cancel_requested' === $msg ) {
			echo '<div class="woocommerce-message">' . esc_html__( 'درخواست لغو ثبت شد و در انتظار بررسی است.', 'csb' ) . '</div>';
		} elseif ( 'reschedule_ok' === $msg ) {
			echo '<div class="woocommerce-message">' . esc_html__( 'جلسه با موفقیت تغییر یافت.', 'csb' ) . '</div>';
		} elseif ( 'reschedule_error' === $msg ) {
			$err_detail = sanitize_text_field( urldecode( (string) ( $_GET['csb_err'] ?? '' ) ) );
			echo '<div class="woocommerce-error">'
				. esc_html__( 'خطا در تغییر جلسه', 'csb' )
				. ( $err_detail ? ': ' . esc_html( $err_detail ) : '' )
				. '</div>';
		}

		$bookings = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.status, b.paid_amount, b.balance_due, b.balance_order_id, b.created_at,
			        s.start_at, p.post_title AS class_title
			   FROM {$wpdb->prefix}csb_bookings b
			   JOIN {$wpdb->prefix}csb_sessions s ON s.id = b.session_id
			   JOIN {$wpdb->posts} p ON p.ID = s.class_id
			  WHERE b.student_id = %d
			  ORDER BY b.created_at DESC LIMIT 50",
			$student_id
		), ARRAY_A ) ?: [];

		$status_labels = [
			'pending'      => __( 'در انتظار پرداخت', 'csb' ),
			'deposit_paid' => __( 'بیعانه پرداخت‌شده', 'csb' ),
			'fully_paid'   => __( 'پرداخت کامل', 'csb' ),
			'confirmed'    => __( 'تأیید شده', 'csb' ),
			'completed'    => __( 'برگزار شده', 'csb' ),
			'cancelled'    => __( 'لغو شده', 'csb' ),
			'expired'      => __( 'منقضی', 'csb' ),
			'no_show'      => __( 'غایب', 'csb' ),
		];

		echo '<div class="csb-account-tab csb-bookings" dir="rtl">';
		echo '<h2>' . esc_html__( 'رزروهای من', 'csb' ) . '</h2>';

		if ( empty( $bookings ) ) {
			echo '<p>' . esc_html__( 'هیچ رزروی ثبت نشده است.', 'csb' ) . '</p></div>';
			return;
		}

		echo '<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table" dir="rtl">';
		echo '<thead><tr>';
		foreach ( [ 'دوره', 'تاریخ', 'وضعیت', 'پرداخت', 'عملیات' ] as $h ) {
			echo '<th>' . esc_html__( $h, 'csb' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $bookings as $b ) {
			$bid       = (int) $b['id'];
			$status    = $b['status'];
			$start_j   = \BookaClass\Support\Jalali::to_jalali_string( $b['start_at'] );
			$can_cancel = in_array( $status, [ 'pending', 'deposit_paid', 'fully_paid', 'confirmed' ], true );

			echo '<tr>';
			echo '<td>' . esc_html( $b['class_title'] ) . '</td>';
			echo '<td>' . esc_html( $start_j ) . '</td>';
			echo '<td><span class="csb-status csb-status--' . esc_attr( $status ) . '">'
				. esc_html( $status_labels[ $status ] ?? $status ) . '</span></td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $b['paid_amount'] ) ) . '</td>';
			echo '<td>';

			// «تسویه مانده» — only for deposit_paid + a linked balance order.
			if ( 'deposit_paid' === $status && (int) $b['balance_order_id'] > 0 ) {
				$balance_order = wc_get_order( (int) $b['balance_order_id'] );
				if ( $balance_order ) {
					echo '<a href="' . esc_url( $balance_order->get_checkout_payment_url() ) . '" class="button woocommerce-button">'
						. esc_html__( 'تسویه مانده', 'csb' ) . '</a> ';
				}
			}

			// «دریافت ICS» + «افزودن به گوگل‌کالندر» — calendar links for upcoming sessions.
			if ( in_array( $status, [ 'confirmed', 'fully_paid', 'deposit_paid' ], true ) ) {
				$ics_url = add_query_arg( [
					'csb_ics'       => $bid,
					'csb_ics_nonce' => wp_create_nonce( 'csb_ics_' . $bid ),
				], home_url( '/' ) );
				echo '<a href="' . esc_url( $ics_url ) . '" class="button woocommerce-button" style="margin-left:.4em">'
					. esc_html__( 'دریافت ICS', 'csb' ) . '</a> ';

				$gc_url = IcsService::add_to_google_calendar_url( $bid, $student_id );
				if ( $gc_url ) {
					echo '<a href="' . esc_url( $gc_url ) . '" class="button woocommerce-button" '
						. 'target="_blank" rel="noopener" style="margin-left:.4em">'
						. esc_html__( 'Google Calendar', 'csb' ) . '</a> ';
				}
			}

			// «تغییر جلسه» — REST-backed reschedule (eligible: deposit_paid + confirmed).
			if ( in_array( $status, [ 'deposit_paid', 'confirmed' ], true ) ) {
				echo '<button type="button" class="button woocommerce-button csb-btn-reschedule" '
					. 'data-bid="' . esc_attr( (string) $bid ) . '" '
					. 'onclick="document.getElementById(\'csb-rs-' . esc_attr( (string) $bid ) . '\').style.display=\'block\'">'
					. esc_html__( 'تغییر جلسه', 'csb' ) . '</button>';

				// Inline panel — populated via REST call on click.
				$this->render_reschedule_form( $bid );
			}

			// «درخواست لغو» with nonce-protected form.
			if ( $can_cancel ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" '
					. 'onsubmit="return confirm(\'' . esc_js( __( 'آیا از درخواست لغو این رزرو اطمینان دارید؟', 'csb' ) ) . '\')">';
				wp_nonce_field( 'csb_cancel_' . $bid );
				echo '<input type="hidden" name="action"     value="csb_request_cancel">';
				echo '<input type="hidden" name="booking_id" value="' . esc_attr( (string) $bid ) . '">';
				echo '<input type="hidden" name="refund_method" value="wallet">';
				echo '<button type="submit" class="button csb-btn-cancel">'
					. esc_html__( 'درخواست لغو', 'csb' ) . '</button>';
				echo '</form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	// ── Tab: دوره‌های من ──────────────────────────────────────────────────────

	public function render_csb_courses(): void {
		global $wpdb;

		$student_id = get_current_user_id();
		if ( ! $student_id ) {
			return;
		}

		$enrollments = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.course_id, e.granted_at, e.expires_at, p.post_title AS course_title
			   FROM {$wpdb->prefix}csb_enrollments e
			   JOIN {$wpdb->posts} p ON p.ID = e.course_id
			  WHERE e.student_id = %d
			    AND (e.expires_at IS NULL OR e.expires_at > UTC_TIMESTAMP())
			  ORDER BY e.granted_at DESC",
			$student_id
		), ARRAY_A ) ?: [];

		echo '<div class="csb-account-tab csb-courses" dir="rtl">';
		echo '<h2>' . esc_html__( 'دوره‌های من', 'csb' ) . '</h2>';

		if ( empty( $enrollments ) ) {
			echo '<p>' . esc_html__( 'در هیچ دوره‌ای ثبت‌نام نکرده‌اید.', 'csb' ) . '</p></div>';
			return;
		}

		echo '<div class="csb-courses-grid">';
		foreach ( $enrollments as $e ) {
			$course_id  = (int) $e['course_id'];
			$progress   = EnrollmentService::get_progress( $student_id, $course_id );
			$product    = wc_get_product( $course_id );
			$lesson_cnt = $product && method_exists( $product, 'get_csb_lessons' )
				? count( $product->get_csb_lessons() )
				: 0;
			$done_cnt   = is_array( $progress['completed'] ?? null ) ? count( $progress['completed'] ) : 0;
			$pct        = $lesson_cnt > 0 ? min( 100, (int) round( $done_cnt / $lesson_cnt * 100 ) ) : 0;
			$course_url = get_permalink( $course_id );

			echo '<div class="csb-course-card">';
			if ( has_post_thumbnail( $course_id ) ) {
				echo get_the_post_thumbnail( $course_id, 'thumbnail', [ 'class' => 'csb-course-card__img' ] );
			}
			echo '<div class="csb-course-card__body">';
			echo '<h3><a href="' . esc_url( $course_url ) . '">' . esc_html( $e['course_title'] ) . '</a></h3>';
			echo '<div class="csb-progress" role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100">';
			echo '<div class="csb-progress__bar" style="width:' . esc_attr( (string) $pct ) . '%"></div>';
			echo '</div>';
			echo '<p>' . sprintf( esc_html__( '%1$d از %2$d جلسه تکمیل شده', 'csb' ), $done_cnt, $lesson_cnt ) . '</p>';
			echo '<a href="' . esc_url( $course_url ) . '" class="button">' . esc_html__( 'ادامه دهید', 'csb' ) . '</a>';
			echo '</div></div>';
		}
		echo '</div></div>';
	}

	// ── Tab: کیف‌پول ─────────────────────────────────────────────────────────

	public function render_csb_wallet(): void {
		$settings = SettingsPage::get();
		if ( empty( $settings['enable_wallet'] ) ) {
			echo '<p>' . esc_html__( 'کیف‌پول در این آموزشگاه فعال نیست.', 'csb' ) . '</p>';
			return;
		}

		global $wpdb;
		$student_id = get_current_user_id();
		if ( ! $student_id ) {
			return;
		}

		// Pagination.
		$page    = max( 1, (int) ( $_GET['txn_page'] ?? 1 ) );
		$per     = 15;
		$offset  = ( $page - 1 ) * $per;
		$total   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}csb_wallet_txns WHERE student_id = %d",
			$student_id
		) );
		$pages   = $total > 0 ? (int) ceil( $total / $per ) : 1;

		$balance = WalletService::get_balance( $student_id );
		$txns    = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_wallet_txns
			  WHERE student_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
			$student_id, $per, $offset
		), ARRAY_A ) ?: [];

		echo '<div class="csb-account-tab csb-wallet" dir="rtl">';
		echo '<h2>' . esc_html__( 'کیف‌پول', 'csb' ) . '</h2>';
		echo '<div class="csb-wallet__balance">';
		echo '<span>' . esc_html__( 'موجودی:', 'csb' ) . '</span> ';
		echo '<strong>' . wp_kses_post( wc_price( $balance ) ) . '</strong>';
		echo '</div>';

		if ( $txns ) {
			echo '<h3>' . esc_html__( 'تراکنش‌ها', 'csb' ) . '</h3>';
			echo '<table class="woocommerce-orders-table" dir="rtl">';
			echo '<thead><tr>';
			foreach ( [ 'تاریخ', 'نوع', 'مبلغ', 'موجودی بعد', 'دلیل' ] as $h ) {
				echo '<th>' . esc_html__( $h, 'csb' ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $txns as $t ) {
				$cls = 'credit' === $t['direction'] ? 'csb-txn--credit' : 'csb-txn--debit';
				$lbl = 'credit' === $t['direction'] ? __( 'واریز', 'csb' ) : __( 'برداشت', 'csb' );
				echo '<tr class="' . esc_attr( $cls ) . '">';
				echo '<td>' . esc_html( \BookaClass\Support\Jalali::to_jalali_string( $t['created_at'] ) ) . '</td>';
				echo '<td>' . esc_html( $lbl ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $t['amount'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $t['balance_after'] ) ) . '</td>';
				echo '<td>' . esc_html( $t['reason'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			// Pagination.
			if ( $pages > 1 ) {
				$base = wc_get_account_endpoint_url( 'csb-wallet' );
				for ( $p = 1; $p <= $pages; $p++ ) {
					$url = add_query_arg( 'txn_page', $p, $base );
					if ( $p === $page ) {
						echo '<strong style="margin:0 4px;">' . esc_html( (string) $p ) . '</strong>';
					} else {
						echo '<a href="' . esc_url( $url ) . '" style="margin:0 4px;">' . esc_html( (string) $p ) . '</a>';
					}
				}
			}
		}

		echo '</div>';
	}

	// ── Tab: گواهی‌ها ─────────────────────────────────────────────────────────

	public function render_csb_certs(): void {
		$student_id = get_current_user_id();
		if ( ! $student_id ) {
			return;
		}

		$certs = CertificateService::get_for_student( $student_id );

		echo '<div class="csb-account-tab csb-certs" dir="rtl">';
		echo '<h2>' . esc_html__( 'گواهی‌های پایان دوره', 'csb' ) . '</h2>';

		if ( empty( $certs ) ) {
			echo '<p>' . esc_html__( 'هنوز گواهی‌ای صادر نشده است.', 'csb' ) . '</p></div>';
			return;
		}

		echo '<div class="csb-certs-list">';
		foreach ( $certs as $cert ) {
			$course_title = get_the_title( (int) $cert['course_id'] );
			$issued_j     = \BookaClass\Support\Jalali::to_jalali_string( $cert['issued_at'] );
			$view_url     = add_query_arg( 'csb_cert', rawurlencode( $cert['serial'] ), home_url( '/' ) );

			echo '<div class="csb-cert-card">';
			echo '<div class="csb-cert-card__body">';
			echo '<h3>' . esc_html( $course_title ) . '</h3>';
			echo '<p>' . esc_html__( 'شماره:', 'csb' ) . ' <code>' . esc_html( $cert['serial'] ) . '</code></p>';
			echo '<p>' . esc_html__( 'تاریخ صدور:', 'csb' ) . ' ' . esc_html( $issued_j ) . '</p>';
			echo '<a href="' . esc_url( $view_url ) . '" class="button" target="_blank">'
				. esc_html__( 'مشاهده / چاپ گواهی', 'csb' ) . '</a>';
			echo '</div></div>';
		}
		echo '</div></div>';
	}

	// ── Printable certificate page ────────────────────────────────────────────

	/**
	 * Intercepts ?csb_cert=SERIAL and renders a printable RTL certificate page.
	 * Public — anyone with the serial can verify.
	 */
	public function maybe_render_certificate_page(): void {
		if ( empty( $_GET['csb_cert'] ) ) {
			return;
		}

		$serial = sanitize_text_field( urldecode( $_GET['csb_cert'] ) );
		if ( ! preg_match( '/^CSB-\d{4}-\d+-[A-F0-9]{6}$/', $serial ) ) {
			return;
		}

		$cert = CertificateService::verify_serial( $serial );
		if ( ! $cert ) {
			wp_die( esc_html__( 'گواهی با این شماره سریال پیدا نشد یا ابطال شده است.', 'csb' ), '', [ 'response' => 404 ] );
		}

		$student    = get_userdata( (int) $cert['student_id'] );
		$course     = get_post( (int) $cert['course_id'] );
		$student_name = $student ? $student->display_name : '';
		$course_title = $course  ? $course->post_title    : '';
		$issued_j     = \BookaClass\Support\Jalali::to_jalali_string( $cert['issued_at'] );
		$site_name    = get_bloginfo( 'name' );

		// Render a standalone printable HTML page — no WP theme wrapper.
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html__( 'گواهی پایان دوره', 'csb' ) . ' — ' . esc_html( $course_title ); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Tahoma,Arial,sans-serif;background:#f5f0e8;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}
.cert{background:#fff;border:4px solid #b8860b;max-width:720px;width:100%;padding:60px 56px;text-align:center;position:relative;box-shadow:0 4px 24px rgba(0,0,0,.12);}
.cert__corner{position:absolute;width:48px;height:48px;border-color:#b8860b;border-style:solid;}
.cert__corner--tl{top:12px;right:12px;border-width:3px 0 0 3px;}
.cert__corner--tr{top:12px;left:12px;border-width:3px 3px 0 0;}
.cert__corner--bl{bottom:12px;right:12px;border-width:0 0 3px 3px;}
.cert__corner--br{bottom:12px;left:12px;border-width:0 3px 3px 0;}
.cert__logo{font-size:28px;font-weight:bold;color:#b8860b;margin-bottom:8px;}
.cert__school{font-size:15px;color:#666;margin-bottom:32px;}
.cert__title{font-size:22px;color:#333;margin-bottom:28px;font-weight:normal;}
.cert__name{font-size:32px;font-weight:bold;color:#1a1a1a;border-bottom:2px solid #b8860b;display:inline-block;padding-bottom:8px;margin-bottom:24px;}
.cert__course{font-size:18px;color:#444;margin-bottom:28px;}
.cert__serial{font-size:12px;color:#999;margin-bottom:24px;font-family:monospace;}
.cert__date{font-size:14px;color:#555;margin-bottom:40px;}
.cert__print{display:inline-block;padding:8px 24px;background:#b8860b;color:#fff;border:none;cursor:pointer;font-size:14px;border-radius:4px;text-decoration:none;}
@media print{body{background:#fff;}.cert{box-shadow:none;border:4px solid #b8860b;}.cert__print{display:none;}}
</style>
</head>
<body>
<div class="cert">
	<div class="cert__corner cert__corner--tl"></div>
	<div class="cert__corner cert__corner--tr"></div>
	<div class="cert__corner cert__corner--bl"></div>
	<div class="cert__corner cert__corner--br"></div>

	<div class="cert__logo"><?php echo esc_html( $site_name ); ?></div>
	<div class="cert__school"><?php esc_html_e( 'گواهی پایان دوره', 'csb' ); ?></div>

	<div class="cert__title"><?php esc_html_e( 'این گواهی به:', 'csb' ); ?></div>
	<div class="cert__name"><?php echo esc_html( $student_name ); ?></div>
	<div class="cert__course">
		<?php
		printf(
			/* translators: %s: course title */
			esc_html__( 'برای گذراندن موفق دوره «%s» اعطا می‌شود.', 'csb' ),
			esc_html( $course_title )
		);
		?>
	</div>
	<div class="cert__date"><?php echo esc_html__( 'تاریخ صدور:', 'csb' ) . ' ' . esc_html( $issued_j ); ?></div>
	<div class="cert__serial"><?php echo esc_html__( 'شماره سریال:', 'csb' ) . ' ' . esc_html( $cert['serial'] ); ?></div>

	<button class="cert__print" onclick="window.print()"><?php esc_html_e( 'چاپ گواهی', 'csb' ); ?></button>
</div>
</body>
</html>
		<?php
		exit;
	}

	// ── ICS calendar download ─────────────────────────────────────────────────

	/**
	 * Intercepts ?csb_ics=BOOKING_ID and serves a .ics file.
	 * Requires valid nonce tied to the booking ID and current user ownership.
	 */
	public function maybe_serve_ics(): void {
		$booking_id = (int) ( $_GET['csb_ics'] ?? 0 );
		if ( ! $booking_id ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'csb-bookings' ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( $_GET['csb_ics_nonce'] ?? '', 'csb_ics_' . $booking_id ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر است.', 'csb' ) );
		}

		try {
			$ics = IcsService::generate( $booking_id, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html__( 'رزرو یافت نشد.', 'csb' ) );
		}

		$filename = 'csb-booking-' . $booking_id . '.ics';

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $ics ) );
		header( 'Cache-Control: no-cache, no-store' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $ics;
		exit;
	}

	// ── Reschedule inline panel (REST-backed) ────────────────────────────────

	/**
	 * Renders a hidden inline panel containing a session selector.
	 * Submission is handled via fetch() to POST csb/v1/booking/{id}/reschedule.
	 * No page reload required; on success the row is updated inline.
	 *
	 * @param int $booking_id
	 */
	private function render_reschedule_form( int $booking_id ): void {
		global $wpdb;

		$booking = BookingService::get( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$current_session_id = (int) $booking['session_id'];

		$class_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT class_id FROM {$wpdb->prefix}csb_sessions WHERE id = %d",
			$current_session_id
		) );

		if ( ! $class_id ) {
			return;
		}

		$alt_sessions = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, start_at, seats_taken, capacity
			   FROM {$wpdb->prefix}csb_sessions
			  WHERE class_id = %d
			    AND id != %d
			    AND status IN ('scheduled','running')
			    AND start_at > UTC_TIMESTAMP()
			    AND seats_taken < capacity
			  ORDER BY start_at ASC LIMIT 20",
			$class_id,
			$current_session_id
		), ARRAY_A ) ?: [];

		if ( empty( $alt_sessions ) ) {
			return;
		}

		$nonce       = wp_create_nonce( 'wp_rest' );
		$api_url     = esc_url( rest_url( 'csb/v1/booking/' . $booking_id . '/reschedule' ) );
		$bookings_url = esc_url( wc_get_account_endpoint_url( 'csb-bookings' ) );

		echo '<div id="csb-rs-' . esc_attr( (string) $booking_id ) . '" '
			. 'class="csb-reschedule-panel" '
			. 'style="display:none;margin-top:.5em;padding:.75em;background:#f9f9f9;border:1px solid #ddd;" '
			. 'dir="rtl">';
		echo '<strong>' . esc_html__( 'انتخاب جلسه جدید:', 'csb' ) . '</strong>';
		echo '<select class="csb-rs-select" style="max-width:100%;margin:.4em 0;display:block;">';
		foreach ( $alt_sessions as $s ) {
			$label = \BookaClass\Support\Jalali::to_jalali_string( $s['start_at'] )
				. ' (' . __( 'ظرفیت باقی:', 'csb' ) . ' '
				. ( (int) $s['capacity'] - (int) $s['seats_taken'] ) . ')';
			echo '<option value="' . esc_attr( (string) (int) $s['id'] ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<button type="button" class="button button-primary csb-rs-submit" '
			. 'data-bid="' . esc_attr( (string) $booking_id ) . '" '
			. 'data-api="' . esc_attr( $api_url ) . '" '
			. 'data-nonce="' . esc_attr( $nonce ) . '" '
			. 'data-redirect="' . esc_attr( $bookings_url ) . '">'
			. esc_html__( 'تأیید تغییر جلسه', 'csb' ) . '</button>';
		echo '<span class="csb-rs-msg" style="color:#c62d2d;margin-right:.5em;"></span>';
		echo '</div>';

		// Inline JS only emitted once (guarded by a CSS class on body).
		static $js_printed = false;
		if ( ! $js_printed ) {
			$js_printed = true;
			?>
			<script>
			(function () {
				document.addEventListener('click', function (e) {
					var btn = e.target.closest('.csb-rs-submit');
					if ( ! btn ) { return; }
					var panel    = btn.closest('.csb-reschedule-panel');
					var sel      = panel.querySelector('.csb-rs-select');
					var msg      = panel.querySelector('.csb-rs-msg');
					var nonce    = btn.dataset.nonce;
					var api      = btn.dataset.api;
					var redirect = btn.dataset.redirect;
					msg.textContent = '';
					btn.disabled    = true;
					fetch( api, {
						method : 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						body   : JSON.stringify( { new_session_id: parseInt( sel.value, 10 ) } )
					} )
					.then( function (r) { return r.json().then( function (d) { return { ok: r.ok, d: d }; } ); } )
					.then( function (res) {
						if ( res.ok && res.d.success ) {
							window.location.href = redirect + '?csb_msg=reschedule_ok';
						} else {
							msg.textContent = (res.d && res.d.error) ? res.d.error : '<?php echo esc_js( __( 'خطا در تغییر جلسه.', 'csb' ) ); ?>';
							btn.disabled    = false;
						}
					} )
					.catch( function () {
						msg.textContent = '<?php echo esc_js( __( 'خطای شبکه.', 'csb' ) ); ?>';
						btn.disabled    = false;
					} );
				} );
			}());
			</script>
			<?php
		}
	}

	// ── WC reviews on CSB products ────────────────────────────────────────────

	/**
	 * Enables WooCommerce reviews on class and video_course products.
	 *
	 * WC's `woocommerce_product_reviews_enabled` filter fires with `$enabled`
	 * already determined by the product's `reviews_allowed` field.  We force
	 * it to true for our types so admins don't need to tick the box per product.
	 *
	 * @param  bool        $enabled
	 * @param  \WC_Product $product
	 * @return bool
	 */
	public function enable_csb_product_reviews( bool $enabled, \WC_Product $product ): bool {
		if ( in_array( $product->get_type(), [ 'class', 'video_course' ], true ) ) {
			return true;
		}
		return $enabled;
	}
}
