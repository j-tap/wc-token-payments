=== WC Token Payments ===

Contributors: j-tap
Tags: woocommerce, tokens, wallet, payment gateway, top-up
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1.27
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Token wallet for WooCommerce: top-up with money (any gateway/currency), pay for orders with tokens.

== Description ==

* **Token balance** — each customer has a token balance (user meta + ledger).
* **Top-up** — shortcode creates an order for "Token top-up"; customer pays via any WooCommerce payment method (supports multi-currency).
* **Pay with Tokens** — payment gateway: deduct tokens from balance to pay for orders.
* **My Account** — "Token Balance" endpoint with balance and transaction history.
* **Admin** — WooCommerce → Token Payments: rate (currency per token), manual balance adjustment.

Requires WooCommerce 8.0+.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via Plugins → Add New.
2. Activate "WC Token Payments" (WooCommerce must be active).
3. Go to WooCommerce → Settings → Token Payments to set the rate (e.g. 1 token = 1 unit of currency).
4. Add shortcode `[wctk_balance]` to show token balance, and/or `[wctk_buy_tokens]` for the top-up form.

== Frequently Asked Questions ==

= How do customers see balance and buy tokens? =

Use `[wctk_balance]` to display token balance and `[wctk_buy_tokens]` for the top-up form (or both on one page). Logged-in users see the form; submitting creates a WooCommerce order that they pay with the usual payment methods.

= Can they pay with tokens for top-up? =

No. Top-up orders cannot use the "Pay with Tokens" gateway to avoid loops.

== Changelog ==

= 0.1.0 =
* Initial release: balance, top-up shortcode, Pay with Tokens gateway, My Account endpoint, admin settings and manual adjustment.
