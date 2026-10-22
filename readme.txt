=== BookaClass ===
Contributors: developer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce extension for class booking and video course sales at a cooking school. RTL / Jalali-first.

== Description ==

Cooking School Booking (CSB) turns WooCommerce into a full-featured cooking-school platform.

= Core features =

* Two custom WC product types: **class** (in-person / live-online) and **video_course**.
* Session management with capacity, seat locking, and attendance tracking.
* Booking state machine: pending → deposit_paid → fully_paid → confirmed → completed.
* Deposit + balance payment flow with configurable settle deadline.
* Wallet credit system: students keep balance, pay faster, receive refunds there.
* Refund policy engine: tiered cancellation penalty by days-before-session.
* CSB coupons: percent / fixed-amount, class-scoped, usage-limited.
* Early-bird pricing: per-class discounted price valid until a Jalali deadline.
* Student reschedule: direct atomic seat move to another session of the same class (policy cutoff enforced).
* ICS export: RFC 5545 `.ics` download + Google Calendar link, Asia/Tehran timezone.
* Mobile OTP login: phone-number registration/login via 6-digit OTP code, rate-limited.
* Schema.org JSON-LD: Course + CourseInstance + AggregateRating + Review (class), VideoObject (video_course).
* WooCommerce star ratings enabled on both product types; shown on archive cards.
* Certificate issuance and public serial-number verification page.
* Attendance roster: check-in / no-show per session; automatic no-show transition.
* Instructor panel: restricted to own sessions via `_csb_instructor_id` postmeta.
* Jalali (Solar Hijri) calendar throughout; Asia/Tehran timezone.
* Full RTL admin and front-end.

= Requirements =

1. **WooCommerce** ≥ 8.0 — the plugin will not activate without it.
2. **PHP** ≥ 8.1 (uses named arguments, enums, and nullable union types).
3. **MySQL/MariaDB** with InnoDB (13 custom tables, uses transactions).

= Optional integrations =

* **Iranian WooCommerce payment gateways** — any Iranian gateway that hooks into
  WooCommerce's standard checkout pipeline will work with CSB's deposit + balance
  order flow without extra configuration.
* **WP SMS Panel plugin** (or any plugin that exposes `wp_sms_panel_send()`,
  `WP_SMS_Panel\Gateway::send()`, or the `wp_sms_panel_send_sms` action) — used
  for OTP codes and booking notifications.  When absent, the plugin falls back to
  `wp_mail()` and logs the miss; it never hard-fails.

= Database tables (13) =

| Table | Purpose |
| ----- | ------- |
| csb_sessions | Class sessions (capacity, schedule, instructor) |
| csb_seat_locks | Temporary cart-time seat reservations |
| csb_bookings | Booking rows with state-machine status |
| csb_wallet_txns | Wallet credit/debit ledger |
| csb_enrollments | Video course access grants + progress |
| csb_reschedule_requests | Student reschedule request queue |
| csb_video_tokens | Signed short-lived stream tokens |
| csb_refund_requests | Cancellation + refund request queue |
| csb_audit_log | Immutable audit trail for all mutations |
| csb_certificates | Issued certificates with serial numbers |
| csb_attendance | Session attendance + no-show records |
| csb_otp_tokens | OTP codes with expiry, rate-limit, brute-force guard |
| csb_coupons | CSB discount coupons (class-scoped) |

Tables are created / upgraded automatically on activation via a `CSB_DB_VERSION` constant.
The `csb_settings['delete_data_on_uninstall']` option (default **false**) controls whether
uninstalling the plugin also drops these tables.

== Installation ==

1. Upload the `bookaclass` folder to `/wp-content/plugins/`.
2. Activate from **Plugins > Installed Plugins**.
3. Make sure WooCommerce is active first.
4. Go to **Settings > آموزشگاه** to configure deposit rates, settle days, wallet, and refund policy.
5. Create your first class from **Products > Add New**, selecting «کلاس» as the product type.

== Frequently Asked Questions ==

= Does it work with any WooCommerce theme? =

Yes. CSB uses its own archive and single templates loaded via `template_redirect`; no theme modification is required.

= Can I disable the wallet? =

Yes — uncheck «فعال‌سازی کیف‌پول» in CSB settings. All wallet UI disappears from checkout and My Account.

= What happens when an SMS gateway plugin is not installed? =

Notifications fall back to `wp_mail()`. OTP codes are sent by email. Nothing breaks.

= Is Jalali (Shamsi) calendar supported everywhere? =

Yes. All dates displayed to end users go through `Jalali::toJalali()`. Admin inputs accept Jalali strings where indicated.

== Changelog ==

= 0.2.0 =
* Phase 7: WC reviews on CSB products + Schema.org AggregateRating; star ratings on archive cards.
* Early-bird pricing: `_csb_earlybird_price` + `_csb_earlybird_until` (Jalali) admin fields; automatic price hook.
* Student reschedule via REST `POST csb/v1/booking/{id}/reschedule` — atomic seat move, policy cutoff.
* ICS export with Asia/Tehran VTIMEZONE component + Google Calendar link.
* Mobile OTP login on WC account page: `POST csb/v1/auth/otp/request` + `auth/otp/verify`.
* SMS adapter: hooks `csb_notify` + `csb_bulk_sms` onto WP SMS Panel plugin; graceful wp_mail fallback.
* CSB coupon management admin page.
* Removed dead `RefundService::decrement_seats_taken()` method.
* Fixed `ExpireUnsettledCron` step order: transition first, then release_seat.

= 0.1.0 =
* Initial release: product types, sessions, bookings, wallet, certs, attendance, instructor panel, video courses, REST API.
