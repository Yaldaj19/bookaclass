=== BookaClass ===
Contributors: developer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Comprehensive class and course booking platform for schools and academies — built on WooCommerce with a clean «آموزشگاه» admin interface.

== Description ==

BookaClass turns WooCommerce into a full-featured academy booking system for schools, training centers, and online educators.

WooCommerce handles cart, checkout, payment, and refunds under the hood. BookaClass adds its own booking engine, session management, and admin layer on top — white-labeled so operators never see «WooCommerce» in the dashboard.

= Product Types =

* **کلاس** (in-person / live-online) — sessions, capacity, seat locking, deposit and settlement payment flow.
* **دوره ویدیویی** — pre-recorded video course with protected streaming tokens and enrollment management.

= Booking Engine =

* Session management with configurable per-session capacity.
* Temporary seat locking during cart checkout; locks released every 5 minutes by cron.
* Booking state machine: pending → deposit_paid → fully_paid → confirmed → completed.
* Deposit + balance payment: configurable deposit percentage and settlement deadline (days before session).
* Automatic expiry of unsettled bookings; automatic completion after session end.
* Waitlist support when class capacity is full.
* Minimum-to-run check: sessions that do not reach minimum enrollment within the configured window are automatically cancelled.

= Payments & Wallet =

* Deposit orders and settlement (balance) orders routed through standard WooCommerce checkout.
* Digital wallet: students hold credit; refunds land in the wallet by default.
* Tiered cancellation penalty engine — configurable by hours before session start.
* Gateway allow-list per settings.

= Student Features =

* **Reschedule** — atomic seat move to another session of the same class with policy cutoff enforcement.
* **ICS export** — RFC 5545 .ics download + Google Calendar link (Asia/Tehran timezone).
* **OTP login** — phone-number registration and login via 6-digit SMS code; rate-limited and brute-force guarded.
* **My Account tabs** — Bookings, Video Courses, Wallet, Certificates.

= Instructor Panel =

* Restricted instructor role — access limited to own sessions only.
* Attendance roster per session: check-in / no-show per student.
* Automatic no-show transition after session.

= Coupons & Pricing =

* CSB-native discount coupons: percent or fixed-amount, class-scoped, usage-limited.
* Early-bird pricing: discounted price valid until a configurable Jalali date.
* WooCommerce star ratings enabled on both product types; displayed on archive cards.

= Certificates =

* Issued upon booking completion.
* Public serial-number verification page.

= Notifications =

* Per-event SMS and email templates editable in admin (OTP, booking confirmed, deposit received, settlement reminders, class tomorrow, cancellation, session cancellation).
* SMS dispatched through an adapter; falls back to wp_mail() when no SMS plugin is found — never hard-fails.
* Optional Telegram admin notification on new bookings.

= SEO =

* Schema.org JSON-LD on class singles: Course + hasCourseInstance + Offer + BreadcrumbList + AggregateRating + Review.
* Schema.org JSON-LD on video singles: VideoObject + Offer + BreadcrumbList + AggregateRating + Review.
* ItemList schema on archive pages.
* Custom sitemap provider for class and video archive URLs.
* WooCommerce Product schema suppressed on BookaClass types to avoid duplication.

= Appearance =

* 4 card styles: classic, overlay, minimal, horizontal.
* Configurable archive grid: columns (2/3/4), list/grid layout, sidebar position, per-page count.
* CSS custom-property token system for colors, radius, container width, and font.
* Per-card element visibility toggles.

= Internationalization =

* Full RTL support — admin and front-end.
* Jalali (Solar Hijri) calendar throughout (Asia/Tehran timezone).
* Text domain: csb.

= Requirements =

1. **WooCommerce** >= 8.0 — the plugin will not activate without it.
2. **PHP** >= 8.1 (uses enums, named arguments, and nullable union types).
3. **MySQL/MariaDB** with InnoDB engine (13 custom tables, uses transactions).

= Optional Integrations =

* **Iranian WooCommerce payment gateway** (ZarinPal, IDPay, etc.) — any gateway that hooks into the standard WC checkout pipeline works with the deposit + settlement order flow without extra configuration.
* **SMS gateway plugin** — any plugin exposing `wp_sms_panel_send()`, `WP_SMS_Panel\Gateway::send()`, `wp_sms_send()`, or the `wp_sms_panel_send_sms` action. When none is found, notifications fall back to wp_mail().

= Database Tables (13) =

All tables use the wp_csb_ prefix (e.g., wp_csb_bookings on a standard install).

* **csb_sessions** — class sessions; capacity, schedule, instructor assignment
* **csb_seat_locks** — temporary cart-time seat reservations (released every 5 minutes)
* **csb_bookings** — booking rows with state-machine status
* **csb_wallet_txns** — wallet credit/debit ledger
* **csb_enrollments** — video course access grants and progress
* **csb_reschedule_requests** — student reschedule request queue
* **csb_video_tokens** — signed short-lived stream tokens
* **csb_refund_requests** — cancellation and refund request queue
* **csb_audit_log** — immutable audit trail for all mutations
* **csb_certificates** — issued certificates with serial numbers
* **csb_attendance** — session attendance and no-show records
* **csb_otp_tokens** — OTP codes with expiry, rate-limit, and brute-force guard
* **csb_coupons** — CSB discount coupons (class-scoped)

Tables are created and upgraded automatically on activation. The csb_settings['delete_data_on_uninstall'] option (default false) controls whether uninstalling the plugin drops these tables.

== Installation ==

1. Upload the `bookaclass` folder to `/wp-content/plugins/`.
2. Make sure WooCommerce is active first.
3. Activate BookaClass from **Plugins > Installed Plugins**.
4. Go to **آموزشگاه > تنظیمات** to configure deposit rate, settlement deadline, wallet, notifications, and refund policy.
5. Create your first class from **آموزشگاه > کلاس‌ها > Add New**, selecting «کلاس» as the product type.

== Frequently Asked Questions ==

= Does it work with any WooCommerce theme? =

Yes. BookaClass uses its own archive and single templates loaded via template_redirect. No theme modification is required.

= Can I use it with a standard WooCommerce shop? =

Yes. Enable «فروش محصول فیزیکی» in the General settings tab. The WooCommerce admin menus will remain visible alongside the آموزشگاه menu.

= Can I disable the wallet? =

Yes — uncheck «فعال‌سازی کیف‌پول» in the General settings tab. All wallet UI disappears from checkout and My Account.

= What happens when no SMS gateway plugin is installed? =

Notifications fall back to wp_mail(). OTP codes are sent by email. Nothing breaks.

= Is Jalali (Shamsi) calendar supported everywhere? =

Yes. All dates shown to end users go through the Jalali helper. Admin inputs accept Jalali strings where indicated.

= How do I connect a custom SMS gateway? =

Use the `csb_sms_sender` filter to return a callable($phone, $message): bool. See the plugin README for an example.

= What schema types does BookaClass emit? =

Class singles: Course + hasCourseInstance + Offer + BreadcrumbList + AggregateRating + Review + Organization + WebSite.
Video singles: VideoObject + Offer + BreadcrumbList + AggregateRating + Review + Organization + WebSite.
Archives: ItemList + Organization + WebSite.

= Will it conflict with Rank Math or Yoast SEO? =

When those plugins are present, BookaClass defers title and meta tags to them. It still emits its own Course / VideoObject / ItemList JSON-LD because those plugins do not generate those types.

= Does it work with WooCommerce Subscriptions or WPML? =

Subscriptions integration is not built-in. WPML/Polylang compatibility has not been tested; standard WC translation workflows should apply to the product types.

= How do I upgrade the database? =

Database migrations run automatically on plugin activation. Increase the CSB_DB_VERSION constant and add an upgrade routine to the Migrator if you are developing a custom version.

= Can I delete all data on uninstall? =

Enable «حذف داده‌ها هنگام حذف افزونه» in the Advanced settings tab. By default this is off and all 13 tables are preserved.

== Screenshots ==

1. Settings hub — 7 tabs covering General, Booking, Payment, Accounts, Notifications, Advanced, and Appearance.
2. Class product editor with sessions, capacity, deposit settings, and early-bird pricing.
3. Front-end class archive with configurable card styles.
4. Student My Account page — Bookings, Wallet, and Certificates tabs.
5. Instructor panel — session attendance roster.

== Changelog ==

= 0.7.0 =
* Settings hub extended: Notifications tab with per-event SMS/email templates and editable message bodies.
* Telegram admin notification on new bookings.
* Gateway allow-list in Payment tab.
* Full white-label mode: WC menus hidden; ecommerce toggle shows them when needed.
* Appearance panel: 4 card styles, per-card element toggles, CSS custom-property token system.
* SEO: ItemList schema on archives; custom sitemap provider; Rank Math/Yoast coexistence.
* OTP brute-force guard and daily purge cron.
* Instructor panel: restricted access via _csb_instructor_id postmeta.
* Cron status visible in Advanced settings tab.
* InnoDB transactions on all multi-table writes.

= 0.6.0 =
* Refund request queue and admin review page.
* Tiered cancellation penalty engine (hours_before / fee_percent JSON config).
* Wallet: refunds credited to wallet balance; wallet balance applied at checkout.
* csb_audit_log table: immutable trail for all booking and wallet mutations.
* Certificate issuance and public serial-number verification page.
* Video course: signed short-lived stream tokens (csb_video_tokens).

= 0.5.0 =
* Attendance roster per session: check-in / no-show per student.
* Automatic no-show transition after session end.
* Calendar admin page.
* MarkCompletedCron: auto-complete bookings after session.
* ExpireUnsettledCron: expire bookings that miss settlement deadline.

= 0.4.0 =
* Deposit + settlement (balance) payment flow.
* Settlement deadline and reminder crons.
* Configurable settle days and reminder day intervals.
* ReminderCron: SMS/email reminders at configurable days before session.
* MinToRunCron: session auto-cancellation below minimum enrollment.

= 0.3.0 =
* Waitlist: students can join a waitlist when capacity is full.
* CSB-native coupon management admin page and CouponService.
* Early-bird pricing: _csb_earlybird_price + _csb_earlybird_until (Jalali date).
* Student reschedule via REST POST csb/v1/booking/{id}/reschedule — atomic seat move.
* ICS export with Asia/Tehran VTIMEZONE component + Google Calendar link.

= 0.2.0 =
* WooCommerce star ratings on BookaClass product types.
* Schema.org AggregateRating + Review on class and video singles.
* Star rating display on archive cards.
* Mobile OTP login: POST csb/v1/auth/otp/request + auth/otp/verify.
* SMS adapter: csb_notify_handled + csb_bulk_sms hooks; graceful wp_mail fallback.
* Fixed ExpireUnsettledCron step order: state transition before seat release.

= 0.1.0 =
* Initial release: class and video_course product types, session management, seat locking, booking state machine, wallet, enrollment, REST API (booking, video, OTP, attendance controllers), Jalali calendar, full RTL, My Account tabs, settings page.
