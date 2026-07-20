=== CatCode Abandoned Cart Recovery for WooCommerce ===
Contributors: catcodestudio
Tags: woocommerce, abandoned cart, cart recovery, email, ecommerce
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Captures abandoned WooCommerce carts and wins them back with a reminder email containing a one-click recovery link.

== Description ==

Most shoppers who fill a cart never reach the "thank you" page. This plugin records those carts, waits a configurable amount of time, and then emails the shopper a single link that puts every product straight back into their cart.

= How it works =

1. A shopper adds products to the cart. Logged-in customers are recorded immediately; guests are recorded as soon as they type an email address at checkout (classic checkout and Checkout Blocks are both supported).
2. After the configured period of inactivity (60 minutes by default) a background scan marks the cart as **abandoned**.
3. A reminder email goes out after the delay you choose, carrying a personal recovery link.
4. Clicking the link restores the cart and pre-fills the checkout with the address already known. The cart becomes **recovered** automatically once an order is placed by that customer.

= Free features =

* Cart capture for logged-in customers and for guests who entered an email at checkout
* Configurable inactivity window before a cart counts as abandoned
* One reminder email with a fully editable subject and body
* Placeholders: `{customer_name}`, `{cart_items}`, `{recovery_link}`, `{store_name}`
* One-time recovery links with a configurable lifetime (7 days by default) — only a SHA-256 hash of the token is ever stored
* Cart statuses: active → abandoned → recovered / lost, with automatic recovery detection when an order arrives
* Admin cart list with a status filter, email search and four statistic tiles: carts abandoned, carts recovered, revenue recovered and recovery rate
* Anti-spam guard: the same address is not emailed more often than once every N days
* Automatic data retention cleanup and integration with the WordPress personal data exporter and eraser
* HPOS (custom order tables) compatible, works with the block-based checkout

= Pro features =

* A chain of up to three reminder emails, each with its own delay, subject and body
* Automatic personal discount coupon inserted into the second and third emails — a single-use WooCommerce coupon restricted to the shopper's email address, percentage or fixed amount, with its own expiry
* Telegram notification to the shop owner the moment a cart is abandoned
* CSV export of the captured carts

Every install gets all Pro features free for 7 days after activation. When the trial ends the free tier keeps working exactly as before; the Pro features unlock again with a licence key.

= Requirements =

* WooCommerce 7.0 or newer
* PHP 7.4+
* Working WP-Cron (or a real system cron calling `wp-cron.php`)

The plugin interface is fully translated into Ukrainian. / Інтерфейс плагіна повністю перекладено українською.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/catcode-abandoned-cart-recovery-for-woocommerce/`
2. Activate it in the "Plugins" menu
3. Go to WooCommerce → Abandoned Cart Settings and set the timings and the email text
4. Watch the results under WooCommerce → Abandoned Carts

== Frequently Asked Questions ==

= When exactly is a guest cart captured? =

Only after the shopper has entered an email address at checkout. Before that there is nothing to send a reminder to, so nothing is stored — no personal data is collected from anonymous browsing.

= Does it work with the block-based checkout? =

Yes. The classic checkout is captured server-side through the `update_order_review` AJAX call; the block checkout posts the email to the plugin's own REST route as soon as the field is filled in. Both paths end in the same table.

= How often does the scan run? =

Every 15 minutes, through WP-Cron (`catcode_abandoned_cart_scan`). A second daily event (`catcode_abandoned_cart_cleanup`) closes expired carts and enforces the retention period.

= Is the recovery link safe to email? =

The link carries a 32-character random token. Only its SHA-256 hash is stored in the database, the comparison is done with `hash_equals()`, the token is single-use, and it is stripped from the address bar right after the cart is restored.

= What happens when the Pro trial ends? =

Nothing breaks. Cart capture, the first reminder email, recovery links, the cart list and the statistics keep working. Only the second and third emails, coupons, Telegram notifications and CSV export need a licence.

= Will a customer receive several reminders for several carts? =

No. The "do not email the same address more often than once every N days" setting throttles reminders per address across all carts.

== Privacy ==

This plugin stores personal data of shoppers who did not complete an order, because a reminder cannot be sent without it.

**What is stored:** the email address, the customer name (when known), the WordPress user id for logged-in customers, a snapshot of the cart contents (product name, quantity, price), the cart total and currency, the cart status, how many reminders were sent and when, and — with the Pro coupon feature — the generated coupon code. Recovery tokens are stored only as a SHA-256 hash.

**Where it is stored:** in the `{prefix}catcode_abandoned_carts` table in your own WordPress database. Nothing is sent to CatCode or to any other third party.

**How long it is kept:** rows are deleted automatically once they are older than the retention period set on the settings page (90 days by default; set it to 0 to keep data indefinitely, which is not recommended). Uninstalling the plugin drops the table entirely.

**Data subject requests:** the plugin registers a WordPress personal data exporter and eraser, so a shopper's carts are included in the standard Tools → Export Personal Data and Tools → Erase Personal Data flows, keyed by email address. The plugin also contributes suggested wording to the site privacy policy screen.

== External services ==

The free tier of this plugin contacts no external service whatsoever. All processing — capture, scanning, email sending through your own site's `wp_mail()` — happens on your server.

The single optional exception is the Pro Telegram notification feature. When you enable it and enter a bot token and chat id, the plugin sends one request per abandoned cart to the Telegram Bot API:

* `POST https://api.telegram.org/bot<token>/sendMessage` — fired when a cart is marked as abandoned by the scan.

What is sent: your bot token and chat id, plus the abandoned cart summary — the shopper's email address, their name (when known), the product names and quantities in the cart, and the cart total and currency. Nothing is sent unless you explicitly enable the feature and supply the credentials; with the feature off, the plugin makes no outbound requests at all.

This service is provided by Telegram: [terms of service](https://telegram.org/tos), [privacy policy](https://telegram.org/privacy).

== Screenshots ==

1. Abandoned cart list with the four statistic tiles and the status filter
2. Settings: inactivity window, recovery link lifetime and anti-spam throttle
3. Settings: reminder email template with the available placeholders
4. Settings: Pro coupon and Telegram notification sections

== Changelog ==

= 1.0.0 =
* First release: cart capture for customers and guests, abandonment scan, reminder email with a one-time recovery link, cart list with statistics, retention cleanup and privacy tools.
* Pro: chain of up to three reminders, personal discount coupons, Telegram notifications, CSV export.

== Upgrade Notice ==

= 1.0.0 =
First public release.
