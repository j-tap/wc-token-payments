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

## Installation

1. Clone or download into `wp-content/plugins/wc-token-payments/`:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/j-tap/wc-token-payments.git
   ```
2. In WordPress: **Plugins → Installed Plugins**, activate **WC Token Payments** (WooCommerce must be active).
3. **WooCommerce → Settings → Token Payments**: set the rate (e.g. `1` = 1 token per 1 unit of store currency).
4. Create a page and add the shortcode `[wctk_buy_tokens]` for customers to buy tokens.

## Usage

### Shortcode

Place `[wctk_buy_tokens]` on any page. Logged-in users see:

- Current token balance
- Form: “How many tokens to buy” + button “Create order”

Submitting creates a WooCommerce order (Token top-up); the customer pays on checkout with the usual methods. After payment (processing/completed), tokens are credited to their balance.

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

**Single source of version:** `readme.txt` — строка `Stable tag: X.Y.Z`. Её можно править вручную; в `wc-token-payments.php` версию подставляет скрипт при релизе.

**Requirements:** [GitHub CLI](https://cli.github.com/) (`brew install gh`), `gh auth login`.

```bash
./make-release.sh 0.2.0   # записать 0.2.0 в readme.txt и сделать релиз
./make-release.sh         # взять версию из readme.txt и сделать релиз
```

Скрипт:
1. Берёт версию из `readme.txt` (или сначала пишет её туда, если передан аргумент)
2. Подставляет её в `wc-token-payments.php`, собирает ZIP
3. Коммитит изменения, создаёт тег `vX.Y.Z`, пушит, создаёт GitHub Release с ZIP

Уже установленные копии плагина (с `vendor/`) увидят обновление в **Плагины** или **Обновления**.

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
    ├── class-wctk-shortcode-buy.php
    ├── class-wctk-account-token-balance.php
    ├── class-wctk-gateway-tokens.php
    ├── class-wc-gateway-tokens.php
    └── class-wctk-admin.php
```

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
