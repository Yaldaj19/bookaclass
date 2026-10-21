<?php
/**
 * REST controller for OTP phone-number login / registration.
 *
 * Routes (§15 of BUILD-SPEC):
 *   POST csb/v1/auth/otp/request  — sends OTP to given phone (no auth required).
 *   POST csb/v1/auth/otp/verify   — verifies code; sets WP auth cookies on success.
 *
 * Rate-limiting and brute-force guarding are inside OtpService.
 * On successful verify the WP user is created if their phone is not yet registered,
 * then wp_set_auth_cookie() is called so the session is immediately usable.
 *
 * @package CookingSchoolBooking\REST
 */

namespace CookingSchoolBooking\REST;

use CookingSchoolBooking\Services\AuditLogger;
use CookingSchoolBooking\Services\OtpService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OtpController
 */
class OtpController {

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		// Inject mobile+OTP login form on WC account / login pages.
		add_action( 'woocommerce_login_form_end', [ $this, 'render_otp_form' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'csb/v1', '/auth/otp/request', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'request_otp' ],
			'permission_callback' => '__return_true', // public — no auth needed.
			'args'                => [
				'phone' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'csb/v1', '/auth/otp/verify', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'verify_otp' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'phone' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'code'  => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	// ── Request OTP ───────────────────────────────────────────────────────────

	/**
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function request_otp( \WP_REST_Request $request ) {
		$phone = (string) $request->get_param( 'phone' );
		$ip    = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		try {
			$sent = OtpService::send( $phone, $ip );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'csb_otp_invalid_phone', $e->getMessage(), [ 'status' => 422 ] );
		}

		if ( ! $sent ) {
			return new \WP_Error(
				'csb_otp_rate_limited',
				__( 'تعداد درخواست‌های ارسال کد بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید.', 'csb' ),
				[ 'status' => 429 ]
			);
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	// ── Verify OTP ────────────────────────────────────────────────────────────

	/**
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify_otp( \WP_REST_Request $request ) {
		$phone = (string) $request->get_param( 'phone' );
		$code  = (string) $request->get_param( 'code' );

		try {
			$user_id = OtpService::verify( $phone, $code );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'csb_otp_invalid', $e->getMessage(), [ 'status' => 401 ] );
		}

		// Set WP auth cookies so subsequent requests are authenticated.
		wp_set_auth_cookie( $user_id, true );
		wp_set_current_user( $user_id );

		$user = get_userdata( $user_id );

		AuditLogger::log( 'otp_login_cookie_set', 'user', $user_id, [
			'ip' => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
		] );

		return rest_ensure_response( [
			'success'      => true,
			'user_id'      => $user_id,
			'display_name' => $user ? $user->display_name : '',
			'redirect'     => wc_get_account_endpoint_url( 'dashboard' ),
		] );
	}

	// ── Mobile OTP login form (injected into WC login page) ──────────────────

	/**
	 * Renders a phone + OTP login section below the standard WC login form.
	 * Vanilla JS — no build step, no external dependencies.
	 */
	public function render_otp_form(): void {
		$nonce   = wp_create_nonce( 'wp_rest' );
		$api_req = esc_url( rest_url( 'csb/v1/auth/otp/request' ) );
		$api_ver = esc_url( rest_url( 'csb/v1/auth/otp/verify' ) );
		?>
		<div class="csb-otp-login" dir="rtl" style="margin-top:1.5em;padding-top:1.5em;border-top:1px solid #ddd;">
			<h3 style="font-size:1em;margin-bottom:.75em;"><?php esc_html_e( 'ورود با شماره موبایل', 'csb' ); ?></h3>

			<div id="csb-otp-step1">
				<p>
					<label for="csb_otp_phone"><?php esc_html_e( 'شماره موبایل', 'csb' ); ?></label><br>
					<input type="tel" id="csb_otp_phone" name="csb_otp_phone"
						placeholder="09xxxxxxxxx" maxlength="11" class="input-text"
						style="max-width:220px;direction:ltr;" />
				</p>
				<p>
					<button type="button" id="csb-otp-send" class="button woocommerce-button">
						<?php esc_html_e( 'دریافت کد', 'csb' ); ?>
					</button>
				</p>
				<p id="csb-otp-msg" style="color:#c62d2d;display:none;"></p>
			</div>

			<div id="csb-otp-step2" style="display:none;">
				<p>
					<label for="csb_otp_code"><?php esc_html_e( 'کد تأیید', 'csb' ); ?></label><br>
					<input type="text" id="csb_otp_code" name="csb_otp_code"
						placeholder="------" maxlength="6" class="input-text"
						style="max-width:140px;letter-spacing:.2em;direction:ltr;" />
				</p>
				<p>
					<button type="button" id="csb-otp-verify" class="button woocommerce-button button-primary">
						<?php esc_html_e( 'ورود', 'csb' ); ?>
					</button>
					<button type="button" id="csb-otp-back" class="button" style="margin-right:.5em;">
						<?php esc_html_e( 'بازگشت', 'csb' ); ?>
					</button>
				</p>
				<p id="csb-otp-msg2" style="color:#c62d2d;display:none;"></p>
			</div>
		</div>
		<script>
		(function () {
			var nonce  = <?php echo wp_json_encode( $nonce ); ?>;
			var urlReq = <?php echo wp_json_encode( $api_req ); ?>;
			var urlVer = <?php echo wp_json_encode( $api_ver ); ?>;

			function post( url, data, ok, fail ) {
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', url );
				xhr.setRequestHeader( 'Content-Type', 'application/json' );
				xhr.setRequestHeader( 'X-WP-Nonce', nonce );
				xhr.onload = function () {
					var r = JSON.parse( xhr.responseText );
					( xhr.status >= 200 && xhr.status < 300 ) ? ok( r ) : fail( r );
				};
				xhr.onerror = function () { fail( { message: 'خطای شبکه' } ); };
				xhr.send( JSON.stringify( data ) );
			}

			document.getElementById( 'csb-otp-send' ).addEventListener( 'click', function () {
				var phone = document.getElementById( 'csb_otp_phone' ).value.trim();
				var msg   = document.getElementById( 'csb-otp-msg' );
				msg.style.display = 'none';
				post(
					urlReq,
					{ phone: phone },
					function () {
						document.getElementById( 'csb-otp-step1' ).style.display = 'none';
						document.getElementById( 'csb-otp-step2' ).style.display = '';
					},
					function ( r ) {
						msg.textContent   = ( r && r.message ) ? r.message : <?php echo wp_json_encode( __( 'خطا در ارسال کد.', 'csb' ) ); ?>;
						msg.style.display = '';
					}
				);
			} );

			document.getElementById( 'csb-otp-verify' ).addEventListener( 'click', function () {
				var phone = document.getElementById( 'csb_otp_phone' ).value.trim();
				var code  = document.getElementById( 'csb_otp_code' ).value.trim();
				var msg   = document.getElementById( 'csb-otp-msg2' );
				msg.style.display = 'none';
				post(
					urlVer,
					{ phone: phone, code: code },
					function ( r ) {
						if ( r.redirect ) { window.location.href = r.redirect; }
						else { window.location.reload(); }
					},
					function ( r ) {
						msg.textContent   = ( r && r.message ) ? r.message : <?php echo wp_json_encode( __( 'کد اشتباه است.', 'csb' ) ); ?>;
						msg.style.display = '';
					}
				);
			} );

			document.getElementById( 'csb-otp-back' ).addEventListener( 'click', function () {
				document.getElementById( 'csb-otp-step2' ).style.display = 'none';
				document.getElementById( 'csb-otp-step1' ).style.display = '';
				document.getElementById( 'csb-otp-msg2' ).style.display  = 'none';
			} );
		}());
		</script>
		<?php
	}
}
