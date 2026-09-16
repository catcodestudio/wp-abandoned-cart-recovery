# Abandoned Cart Recovery for WooCommerce

Records carts that never became an order and brings the shopper back with a reminder e-mail and a one-click restore link.

**Status:** v1.0.0 — tested live on WooCommerce (WordPress 7.0): capture → cron marks the cart abandoned → reminder e-mail → restore link rebuilds the cart → the order that follows flips the cart to *recovered* with its revenue.

## Features

- **Capture** — logged-in customers immediately; guests as soon as they type an e-mail at checkout. Nothing is stored for anonymous browsing.
- Works on **both checkouts**: the classic one server-side through `update_order_review`, the block one through the plugin's own REST route.
- **Abandoned after N minutes** of inactivity (60 by default), scanned every 15 minutes by WP-Cron.
- **Reminder e-mail** with an editable subject and body — `{customer_name}`, `{cart_items}`, `{recovery_link}`, `{store_name}`.
- **One-time restore link**: a 32-character token whose SHA-256 hash alone is stored, compared with `hash_equals()`, burned on use and stripped from the address bar.
- **Statuses** active → abandoned → recovered / lost, with recovery detected automatically from the order.
- **Admin list** with status filter, e-mail search and four statistics tiles (abandoned, recovered, recovered revenue, recovery rate).
- **Anti-spam** — never mails the same address more often than once every N days.
- **Data hygiene** — automatic clean-up after a retention period, plus WordPress personal-data export and erase integration.
- **Checkout error log** (WooCommerce → Checkout Errors) — validation messages, payment failures, expired sessions and failed Store API checkouts server-side; JavaScript errors and block-checkout field errors from the page. Grouped by error, with shoppers affected and shoppers who left without an order (last 7 days). No names, e-mails or phones stored.
- HPOS-compatible.

## Pro

- Chain of up to **three reminder e-mails**, each with its own delay, subject and body.
- **Personal discount coupon** — a real single-use WooCommerce coupon limited to the shopper's e-mail, percentage or fixed, with its own expiry.
- **Viber / SMS reminder** through TurboSMS: one message per cart with its own recovery link, also for guests who left only a phone; Viber with SMS fallback, quiet hours, balance check and test message.
- **Telegram notification** the moment a cart is marked abandoned. "Connect bot" registers a Telegram webhook with a `secret_token`; the shop owner sends `/start` and the chat ID is saved by itself.
- **CSV export** of the cart list.
- **Checkout error log**: 30/90-day periods, latest occurrences of an error with page, browser, gateway and cart, CSV export.

Pro is unlocked by a licence key or by a 7-day trial the owner starts from the settings screen. A trial switches Pro off when it ends; a purchased key, once confirmed by the licence server, keeps Pro on for good — the 1–5 year term covers updates and support. The free tier keeps working in every case.

## Requirements

WooCommerce 7.0+, WordPress 6.2+, PHP 7.4+, and a working WP-Cron (or a system cron hitting `wp-cron.php`).

## External services

None in the free tier. With Telegram notifications enabled the plugin talks to `api.telegram.org`; with the Viber/SMS reminder enabled, to `api.turbosms.ua`. Nothing else.

## Licence

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html
