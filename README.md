# BookaClass

**Version:** 0.7.0 | **Requires WordPress:** 6.0+ | **Requires PHP:** 8.1+ | **Requires WooCommerce:** 8.0+ | **License:** GPL-2.0-or-later | **GitHub:** Yaldaj19/bookaclass

A comprehensive class and course booking platform for schools and academies, built as a WooCommerce extension. WooCommerce handles cart, checkout, payment, and refunds under the hood; the admin interface is white-labeled so operators see "آموزشگاه" (Academy) — not WooCommerce.

---

## Features

### Product Types
- **Class** (`class`) — in-person or live-online sessions with capacity, seat locking, and deposit/settlement flow
- **Video Course** (`video_course`) — sell access to pre-recorded video content with protected streaming tokens

### Booking Engine
- Session management with configurable capacity per session
- Temporary seat locking during cart checkout (released every 5 minutes by cron)
- Booking state machine: `pending` → `deposit_paid` → `fully_paid` → `confirmed` → `completed`
- Deposit + balance payment: configurable deposit percentage and settlement deadline
- Waitlist support when class capacity is full
- Minimum-to-run check: sessions with insufficient enrollment are automatically cancelled

### Payments & Wallet
- Deposit orders and settlement orders routed through WooCommerce checkout
- Digital wallet: students hold credit balance; refunds land in the wallet
- Tiered cancellation penalty engine (configurable by hours before session)
- Configurable gateway allow-list per settings

### Student Features
- **Reschedule:** atomic seat move to another session of the same class (policy cutoff enforced)
- **ICS export:** RFC 5545 `.ics` download + Google Calendar link (Asia/Tehran timezone)
- **My Account tabs:** Bookings, Video Courses, Wallet, Certificates
- **OTP login:** phone-number registration and login via 6-digit OTP code, rate-limited and brute-force guarded

### Instructor Panel
- Restricted instructor role with access only to their own sessions
- Attendance roster per session: check-in / no-show per student
- Automatic no-show transition after session

### Coupons & Pricing
- CSB-native discount coupons: percent or fixed-amount, class-scoped, usage-limited
- Early-bird pricing: discounted price valid until a configurable Jalali deadline

### Certificates
- Issuance upon booking completion
- Public serial-number verification page

### Notifications
- Per-event SMS and email templates editable in admin (OTP, booking confirmed, deposit received, settlement reminders, class tomorrow, cancellation, session cancellation)
- SMS dispatched via adapter pattern (see Integration Hooks below); falls back to `wp_mail()` gracefully
- Optional Telegram admin notification on new bookings

### SEO
- Schema.org JSON-LD: `Course` + `hasCourseInstance` + `Offer` + `BreadcrumbList` + `AggregateRating` + `Review` on class singles
- `VideoObject` + `Offer` + `BreadcrumbList` + `AggregateRating` + `Review` on video singles
- `ItemList` on archive pages
- Custom sitemap provider for class/video archive pages
- WooCommerce's default `Product` schema suppressed on BookaClass types

### Appearance
- 4 card styles: `classic`, `overlay`, `minimal`, `horizontal`
- Configurable archive layout: grid/list, columns (2/3/4), sidebar position, per-page count
- CSS custom-property token system (`--csb-primary`, `--csb-accent`, `--csb-bg`, etc.)
- Radius, container width, and font family controls
- Per-card element visibility toggles (image, category, instructor, level, seats, rating, early-bird badge, etc.)

### Internationalization
- Full RTL support (admin and front-end)
- Jalali (Solar Hijri) calendar throughout; all dates through `Jalali::toJalali()`
- Text domain: `csb`

### Admin White-labeling
- WooCommerce top-level menus hidden (unless ecommerce mode is enabled)
- Admin reads as "آموزشگاه" with submenu order: Dashboard → Classes → Video Courses → Sessions → Bookings → Calendar → Attendance → Refunds → Coupons → Financial Report → Settings → Appearance

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0 or higher |
| PHP | 8.1 or higher (uses enums, named arguments, nullable union types) |
| WooCommerce | 8.0 or higher |
| MySQL/MariaDB | InnoDB engine (uses transactions) |

**Optional:**
- An Iranian WooCommerce payment gateway (ZarinPal, IDPay, etc.) — standard WC checkout pipeline, no extra configuration needed
- An SMS gateway plugin exposing `wp_sms_panel_send()`, `WP_SMS_Panel\Gateway::send()`, `wp_sms_send()`, or the `wp_sms_panel_send_sms` action

---

## Installation

1. Upload the `bookaclass` folder to `/wp-content/plugins/`.
2. Activate WooCommerce first.
3. Activate BookaClass from **Plugins > Installed Plugins**.
4. Go to **آموزشگاه > تنظیمات** to configure deposit rate, settlement deadline, wallet, notifications, and refund policy.
5. Create your first class from **آموزشگاه > کلاس‌ها > Add New**, selecting «کلاس» as the product type.

**With Composer (optional):** run `composer install` inside the plugin folder to use the Composer autoloader. Without it, the built-in PSR-4 autoloader loads `src/` automatically.

---

## Architecture

BookaClass uses WooCommerce as its **payment and order engine**. It does not reinvent cart, checkout, or payment processing — those remain standard WC flows. The plugin adds two WC product types (`class`, `video_course`), 13 custom database tables for booking state, and a complete front-end and admin layer that white-labels the WC foundation.

Namespace: `BookaClass\`
DB table prefix: `wp_csb_`
REST API namespace: `csb/v1`
Option key (settings): `csb_settings`
Option key (appearance): `csb_style`

### Source structure

```
src/
  Admin/          Settings, appearance, bookings, sessions, attendance, coupons, refunds, calendar, instructor panel, white-label
  Cron/           Seat lock expiry, min-to-run check, settlement expiry, reminders, mark-completed, OTP purge
  Frontend/       Archive/single templates, My Account, checkout hooks, schema, SEO meta, sitemap, style vars, rewrites
  Install/        DB migrator (13 tables, versioned via CSB_DB_VERSION)
  Products/       ClassProduct, VideoCourseProduct, ProductTypes
  REST/           Booking, OTP, video, attendance, admin-session controllers
  Services/       BookingService, WalletService, CertificateService, RefundService, RescheduleService, EnrollmentService, VideoAccessService, OtpService, NotificationService, SettlementService, CapacityService, CouponService, IcsService, AuditLogger
  Support/        Jalali, SmsAdapter, Relabel, RedirectManager
```

---

## Database Tables (13)

All tables use the `wp_csb_` prefix (e.g., `wp_csb_bookings` on a standard install).

| Table | Purpose |
|---|---|
| `csb_sessions` | Class sessions — capacity, schedule, instructor assignment |
| `csb_seat_locks` | Temporary cart-time seat reservations (released by cron every 5 min) |
| `csb_bookings` | Booking rows with state-machine status |
| `csb_wallet_txns` | Wallet credit/debit ledger |
| `csb_enrollments` | Video course access grants and progress |
| `csb_reschedule_requests` | Student reschedule request queue |
| `csb_video_tokens` | Signed short-lived stream tokens |
| `csb_refund_requests` | Cancellation and refund request queue |
| `csb_audit_log` | Immutable audit trail for all mutations |
| `csb_certificates` | Issued certificates with serial numbers |
| `csb_attendance` | Session attendance and no-show records |
| `csb_otp_tokens` | OTP codes with expiry, rate-limit, and brute-force guard |
| `csb_coupons` | CSB discount coupons (class-scoped) |

Tables are created and upgraded automatically on plugin activation via the `CSB_DB_VERSION` constant. The `csb_settings['delete_data_on_uninstall']` option (default `false`) controls whether uninstalling the plugin drops these tables.

---

## Integration Hooks

### `csb_sms_sender` (filter)
Lets you supply a custom SMS callable. Return a `callable($phone, $message): bool` to override the built-in gateway detection.

```php
add_filter( 'csb_sms_sender', function () {
    return function ( string $phone, string $message ): bool {
        // your gateway
        return true;
    };
} );
```

### `csb_notify` (action)
Fired by `NotificationService` when a notification event is dispatched.

```php
// Signature:
do_action( 'csb_notify', string $event, array $payload );
// $payload keys: student_id, phone, body, subject, booking_id, ...
```

### `csb_notify_handled` (filter)
Return `true` to mark the notification as handled and skip the `wp_mail()` fallback. The SMS adapter uses this internally.

```php
// Signature:
apply_filters( 'csb_notify_handled', bool $handled, string $event, array $payload );
```

### `csb_bulk_sms` (action)
Send a single message to multiple bookings' students.

```php
// Signature:
do_action( 'csb_bulk_sms', int[] $booking_ids, string $message );
```

---

## Cron Jobs

| Hook | Schedule | Purpose |
|---|---|---|
| `csb_release_locks` | Every 5 min | Release expired seat locks |
| `csb_check_min_to_run` | Hourly | Cancel sessions below minimum enrollment |
| `csb_send_reminders` | Hourly | Send settlement and class-tomorrow reminders |
| `csb_expire_unsettled` | Hourly | Expire bookings that missed the settlement deadline |
| `csb_mark_completed` | Hourly | Auto-complete bookings after session end |
| `csb_purge_otp` | Daily | Delete expired OTP tokens |

---

## REST API

Base: `csb/v1`

Key endpoints (authentication per WP REST API standard — nonce or Application Passwords):

| Method | Path | Description |
|---|---|---|
| `POST` | `/booking/{id}/reschedule` | Atomic seat move to another session |
| `POST` | `/auth/otp/request` | Request OTP for phone-number login |
| `POST` | `/auth/otp/verify` | Verify OTP and authenticate |
| `GET` | `/video/{id}/token` | Issue a short-lived stream token |
| `POST` | `/attendance/{session_id}` | Record check-in/no-show (instructor/admin) |

---

## Screenshots

_Screenshots to be added._

1. Settings hub (7 tabs: General, Booking, Payment, Accounts, Notifications, Advanced, Appearance)
2. Class product editor with sessions, capacity, and deposit fields
3. Front-end class archive with card styles
4. Student My Account — Bookings tab
5. Instructor panel — attendance roster

---

## Changelog

See [readme.txt](readme.txt) for full version history.

---

## درباره BookaClass

BookaClass یک افزونه وردپرس برای مدیریت کامل آموزشگاه‌ها و آکادمی‌هاست. از WooCommerce به عنوان موتور پرداخت و سبد خرید استفاده می‌کند، اما رابط مدیریت کاملاً به‌صورت «آموزشگاه» نمایش داده می‌شود. ویژگی‌های اصلی: رزرو کلاس با مدیریت صندلی، سیستم بیعانه و تسویه، کیف‌پول دیجیتال، پنل مدرس، گواهینامه، ورود با OTP، تقویم جلالی، و SEO کامل با Schema.org.

**نصب:** WooCommerce را ابتدا فعال کنید، سپس افزونه را فعال کرده و از **آموزشگاه > تنظیمات** پیکربندی کنید.
