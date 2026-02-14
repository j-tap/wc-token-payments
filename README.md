# WC Token Payments

WordPress plugin: token wallet for WooCommerce. Customers top up balance with money (any payment method/currency), then pay for orders with tokens.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WooCommerce 8.0+

## Features

- **Token balance** — per-user balance (user meta + ledger table)
- **Top-up** — shortcode creates a WooCommerce order; customer pays via any gateway (supports multi-currency)
- **Pay with Tokens** — payment gateway that deducts tokens from balance
- **My Account** — “Token Balance” tab with balance and transaction history
- **Admin** — WooCommerce → Token Payments: rate (currency per token), manual balance adjustment
- **Languages** — English (default), Russian (ru_RU), Spanish (es_ES); WordPress picks the locale automatically from **Settings → General → Site Language**

## Installation

1. Clone or download into `wp-content/plugins/wc-token-payments/`:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/j-tap/wc-token-payments.git
   ```
2. In WordPress: **Plugins → Installed Plugins**, activate **WC Token Payments** (WooCommerce must be active).
3. **WooCommerce → Settings → Token Payments**: set the rate (e.g. `1` = 1 token per 1 unit of store currency).
4. Create a page and add shortcodes `[wctk_balance]` and/or `[wctk_buy_tokens]` for customers to see balance and buy tokens.

## Usage

### Shortcodes

- **`[wctk_balance]`** — outputs the current user’s token balance only (guests see a message to log in).
- **`[wctk_buy_tokens]`** — outputs the top-up form: “How many tokens to buy” field and “Create order” button. No custom styling (neutral markup).

You can use both on one page: `[wctk_balance]` and `[wctk_buy_tokens]`.

Submitting the form creates a WooCommerce top-up order; the customer pays at checkout with any method. After the order reaches processing/completed, tokens are credited to their balance.

### Public API (for themes / other plugins)

**Balance (raw number, no HTML)**

- **`WCTK_Balance::get(int $user_id): int`** — returns the token balance for the given user. For the current user: `WCTK_Balance::get(get_current_user_id())`.

**Balance (HTML output)**

- **`WCTK_Shortcode_Balance::render_balance(?int $user_id = null, string $wrapper_tag = 'p'): string`** — returns HTML with the balance (label + number). `$user_id = null` uses the current user; `$wrapper_tag` can be `'p'`, `'h2'`, `'h3'`, `'div'`. For guests returns a “log in” message.

**Top-up form**

- **`WCTK_Shortcode_Buy::render_topup_form(): string`** — returns the top-up form HTML (quantity field, rate note, “Create order” button). Use `echo WCTK_Shortcode_Buy::render_topup_form();` to output it anywhere.

**Create top-up order programmatically**

- **`WCTK_Shortcode_Buy::create_topup_order(int $user_id, int $tokens_qty): WC_Order|WP_Error`** — creates a top-up order; returns the order or `WP_Error`. Redirect to payment is up to the caller, e.g. `wp_safe_redirect($order->get_checkout_payment_url()); exit;`

### Pay with Tokens

On checkout, if the cart does **not** contain the top-up product, the “Pay with Tokens” method appears. Selecting it deducts the required tokens (by rate) and completes the order. Top-up orders cannot be paid with tokens.

### My Account

Under **My Account**, the **Token Balance** tab shows balance and the last 50 ledger entries (date, kind, tokens, order, note).

### Admin

- **WooCommerce → Token Payments**
  - **Rate** — base currency per 1 token (e.g. `1` or `0.5`)
  - **Top-up product ID** — auto-created on activation; leave as is unless you know what you’re doing
  - **Manual balance adjustment** — User ID, delta (+/-), note; applies an `admin_adjust` ledger entry

## Updates (from GitHub)

If the plugin was installed from a ZIP that includes `vendor/` (e.g. from a [Release](https://github.com/j-tap/wc-token-payments/releases)), WordPress will show **Update available** when a new release is published. Update via **Plugins → Installed Plugins** or **Dashboard → Updates**.

To publish an update: create a new [Release](https://github.com/j-tap/wc-token-payments/releases) on GitHub (tag e.g. `0.2.0`), then attach the ZIP built by `./make-release.sh 0.2.0` as a release asset.

## Releasing a new version

**Single source of version:** the `Stable tag: X.Y.Z` line in `readme.txt`. You can edit it manually; the release script injects this version into `wc-token-payments.php`.

**Requirements:** [GitHub CLI](https://cli.github.com/) (`brew install gh`), `gh auth login`.

```bash
./make-release.sh 0.2.0   # write 0.2.0 to readme.txt and create the release
./make-release.sh         # use version from readme.txt and create the release
```

The script:
1. Reads the version from `readme.txt` (or writes it there first if an argument is passed)
2. Injects it into `wc-token-payments.php` and builds the ZIP
3. Commits changes, creates tag `vX.Y.Z`, pushes, and creates a GitHub Release with the ZIP

Installed copies of the plugin (with `vendor/`) will see the update under **Plugins** or **Updates**.

## Languages

The plugin is translated via `.po`/`.mo` files in `languages/`. Included:

- **English** — source strings in code (no .mo needed)
- **Russian (ru_RU)** — `wc-token-payments-ru_RU.po` / `wc-token-payments-ru_RU.mo`
- **Spanish (es_ES)** — `wc-token-payments-es_ES.po` / `wc-token-payments-es_ES.mo`

WordPress loads the translation for the site locale (`Settings → General → Site Language`). To add or update translations: edit the `.po` file, then run `msgfmt -o wc-token-payments-{locale}.mo wc-token-payments-{locale}.po` in the `languages/` folder (requires gettext).

## Project structure

```
wc-token-payments/
├── wc-token-payments.php    # Bootstrap, constants, hooks
├── composer.json            # plugin-update-checker for GitHub updates
├── make-release.sh          # Builds installable ZIP
├── readme.txt               # WordPress.org readme
├── uninstall.php            # Runs on plugin delete (no data wipe by default)
├── languages/               # .po / .mo for translations
├── vendor/                  # After composer install (needed for updates)
└── includes/
    ├── class-wctk-plugin.php
    ├── class-wctk-ledger.php
    ├── class-wctk-balance.php
    ├── class-wctk-shortcode-balance.php
    ├── class-wctk-shortcode-buy.php
    ├── class-wctk-account-token-balance.php
    ├── class-wctk-gateway-tokens.php
    ├── class-wc-gateway-tokens.php
    └── class-wctk-admin.php
```

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
