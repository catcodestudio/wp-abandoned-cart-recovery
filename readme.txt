=== CatCode Abandoned Cart Recovery for WooCommerce ===
Contributors: catcodestudio
Tags: woocommerce, abandoned cart, cart recovery, email, ecommerce
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
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
* Viber or SMS reminder through TurboSMS (turbosms.ua) — one short message with the recovery link, also for shoppers who typed only a phone number at checkout; Viber with SMS fallback, quiet hours, balance check and a test message in the settings
* Telegram notification to the shop owner the moment a cart is abandoned
* CSV export of the captured carts

Pro never switches itself on. A fresh install is the free version: the Pro settings are visible in their own places, greyed out and labelled, so you can see exactly where the line is. If you want to try them, the settings screen has a "Try Pro for 7 days" button — you enter an email, we issue a real 7-day key and unlock Pro right away. When the trial or the licence ends the free tier keeps working exactly as before, and your settings stay where they were.

= Requirements =

* WooCommerce 6.0 or newer
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

= How do I start the Pro trial? =

WooCommerce → Abandoned Cart Settings → Pro licence → "Try Pro for 7 days". Enter your email and the key is issued and activated immediately, and also emailed to you. Nothing is charged and no card is asked for. The trial never starts by itself, and it is one per site.

= What happens when the Pro trial ends? =

Nothing breaks. Cart capture, the first reminder email, recovery links, the cart list and the statistics keep working. Only the second and third emails, coupons, Viber/SMS reminders, Telegram notifications and CSV export need a licence.

= Can the reminder go to Viber instead of email? =

Yes, in Pro. Connect a TurboSMS account (API token plus approved Viber and SMS sender names) under Abandoned Cart Settings → Viber / SMS reminder. The shopper gets exactly one message per cart, never at night (quiet hours are configurable), with a link that restores the cart. While the feature is off the plugin does not store phone numbers at all.

= Will a customer receive several reminders for several carts? =

No. The "do not email the same address more often than once every N days" setting throttles reminders per address across all carts.

== Privacy ==

This plugin stores personal data of shoppers who did not complete an order, because a reminder cannot be sent without it.

**What is stored:** the email address, the customer name (when known), the WordPress user id for logged-in customers, a snapshot of the cart contents (product name, quantity, price), the cart total and currency, the cart status, how many reminders were sent and when, — with the Pro coupon feature — the generated coupon code, and — only while the Pro Viber/SMS reminder is enabled — the phone number and the delivery status of that one message. Recovery tokens are stored only as a SHA-256 hash.

**Where it is stored:** in the `{prefix}catcode_abandoned_carts` table in your own WordPress database. Nothing is sent to CatCode or to any other third party.

**How long it is kept:** rows are deleted automatically once they are older than the retention period set on the settings page (90 days by default; set it to 0 to keep data indefinitely, which is not recommended). Uninstalling the plugin drops the table entirely.

**Data subject requests:** the plugin registers a WordPress personal data exporter and eraser, so a shopper's carts are included in the standard Tools → Export Personal Data and Tools → Erase Personal Data flows, keyed by email address. The plugin also contributes suggested wording to the site privacy policy screen.

== External services ==

The free tier of this plugin contacts no external service whatsoever. All processing — capture, scanning, email sending through your own site's `wp_mail()` — happens on your server.

There are two optional exceptions, both Pro features that do nothing until you enable them and enter credentials.

**Telegram notification.** When you enable it and enter a bot token and chat id, the plugin sends one request per abandoned cart to the Telegram Bot API:

* `POST https://api.telegram.org/bot<token>/sendMessage` — fired when a cart is marked as abandoned by the scan.

What is sent: your bot token and chat id, plus the abandoned cart summary — the shopper's email address, their name (when known), the product names and quantities in the cart, and the cart total and currency. Nothing is sent unless you explicitly enable the feature and supply the credentials; with the feature off, the plugin makes no outbound requests at all.

This service is provided by Telegram: [terms of service](https://telegram.org/tos), [privacy policy](https://telegram.org/privacy).

**Viber / SMS reminder.** When you enable it and enter a TurboSMS API token, the plugin talks to the TurboSMS API:

* `POST https://api.turbosms.ua/message/send.json` — once per abandoned cart that has a phone number, after the delay you set, and when you press "Send test message".
* `POST https://api.turbosms.ua/user/balance.json` — only when you press "Check connection and balance".

What is sent: your API token, the sender names, the shopper's phone number and the message text (store name, cart total, item count, customer name if used in your template, and the recovery link). This service is provided by TurboSMS: [website and terms](https://turbosms.ua/), [privacy policy](https://turbosms.ua/privacy.html).

== Screenshots ==

1. Abandoned cart list with the four statistic tiles and the status filter
2. Settings: inactivity window, recovery link lifetime and anti-spam throttle
3. Settings: reminder email template with the available placeholders
4. Settings: Pro coupon and Telegram notification sections

== Changelog ==

= 1.2.0 =
* Pro: Viber / SMS reminder through TurboSMS. Shoppers who typed only a phone number at checkout are captured too (only while the feature is on); one message per cart with its own recovery link, Viber with SMS fallback, quiet hours, per-number cooldown, balance check and test message in the settings.
* The cart list, CSV export and Telegram notification show the phone and the message status.

= 1.1.0 =
* Pro no longer switches itself on: a fresh install is the free version until you start the trial or activate a key.
* New "Try Pro for 7 days" button on the settings screen — it issues a real 7-day licence key by email, on request only.
* Licence keys are now checked against the CatCode licence server (activate / daily re-check / release), with a 14-day grace period if the server is unreachable.
* Pro settings stay visible where they belong, greyed out, with a Pro badge and one line explaining what each one gives you.
* One dismissible notice, shown only after the plugin has actually captured a cart or sent a reminder — dismissed once, never shown again.

= 1.0.0 =
* First release: cart capture for customers and guests, abandonment scan, reminder email with a one-time recovery link, cart list with statistics, retention cleanup and privacy tools.
* Pro: chain of up to three reminders, personal discount coupons, Telegram notifications, CSV export.

== Upgrade Notice ==

= 1.2.0 =
Adds the Pro Viber / SMS reminder. The database table gets five new columns automatically on update; nothing changes until you enable the feature.

= 1.1.0 =
The automatic 7-day Pro trial is gone. If you were inside it, the Pro features switch off on update and the free tier keeps running; start the trial yourself, or activate a licence key, from the settings screen.

= 1.0.0 =
First public release.
