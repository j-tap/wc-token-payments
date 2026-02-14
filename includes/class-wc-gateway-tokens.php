<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WC_Payment_Gateway')) return;

class WC_Gateway_Tokens extends WC_Payment_Gateway {

    const SPEND_LOCK_PREFIX = 'wctk_spend_';
    const SPEND_LOCK_TTL = 45;

    public function __construct() {
        $this->id                 = 'wctk_tokens';
        $this->method_title       = __('Pay with Tokens', WCTK_TEXT_DOMAIN);
        $this->method_description = __('Pay using token balance.', WCTK_TEXT_DOMAIN);
        $this->has_fields         = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title   = $this->get_option('title', __('Pay with Tokens', WCTK_TEXT_DOMAIN));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function payment_fields(): void {
        if (!is_user_logged_in()) return;

        $user_id = get_current_user_id();
        $balance = WCTK_Balance::get($user_id);

        echo '<div class="wctk-gateway-info">';
        echo '<p class="wctk-gateway-info__balance">';
        echo esc_html__('Token balance:', WCTK_TEXT_DOMAIN) . ' ';
        echo '<strong class="wctk-gateway-info__balance-value">' . esc_html((string) $balance) . '</strong>';
        echo '</p>';

        if (WC()->cart) {
            $total   = (float) WC()->cart->get_total('edit');
            $rate    = WCTK_Plugin::get_rate();
            $currency = get_woocommerce_currency();
            $default  = get_option('woocommerce_currency', 'EUR');

            if ($currency !== $default) {
                $total = WCTK_Shortcode_Buy::convert_to_default_currency($total, $currency);
            }

            $needed = $rate > 0 ? (int) ceil($total / $rate) : 0;

            echo '<p class="wctk-gateway-info__cost">';
            echo esc_html(sprintf(
                /* translators: %d = tokens needed for this order */
                __('Tokens needed for this order: %d', WCTK_TEXT_DOMAIN),
                $needed
            ));
            echo '</p>';

            if ($balance < $needed) {
                echo '<p class="wctk-gateway-info__warning" style="color:#b32d2e;">';
                echo esc_html(sprintf(
                    __('Not enough tokens. Need %1$d, you have %2$d.', WCTK_TEXT_DOMAIN),
                    $needed,
                    $balance
                ));
                echo '</p>';
            }
        }

        echo '</div>';
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', WCTK_TEXT_DOMAIN),
                'type'    => 'checkbox',
                'label'   => __('Enable token payments', WCTK_TEXT_DOMAIN),
                'default' => 'yes',
            ],
            'title' => [
                'title'   => __('Title', WCTK_TEXT_DOMAIN),
                'type'    => 'text',
                'default' => __('Pay with Tokens', WCTK_TEXT_DOMAIN),
            ],
        ];
    }

    /** @return array{result: string, redirect?: string} */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'fail'];
        }

        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0) {
            wc_add_notice(__('You must be logged in to pay with tokens.', WCTK_TEXT_DOMAIN), 'error');
            return ['result' => 'fail'];
        }

        if ($order->get_meta(WCTK_ORDER_META_IS_TOPUP) === 'yes') {
            wc_add_notice(__('Top-up orders cannot be paid with tokens.', WCTK_TEXT_DOMAIN), 'error');
            return ['result' => 'fail'];
        }

        $rate  = WCTK_Plugin::get_rate();
        $total = (float) $order->get_total();

        // Convert order total to default currency before token calculation
        $total_default = WCTK_Shortcode_Buy::convert_to_default_currency(
            $total,
            $order->get_currency()
        );

        $needed_tokens = (int) ceil($total_default / $rate);
        $balance = WCTK_Balance::get($user_id);

        if ($needed_tokens <= 0) {
            wc_add_notice(__('Invalid token calculation.', WCTK_TEXT_DOMAIN), 'error');
            return ['result' => 'fail'];
        }

        if ($balance < $needed_tokens) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: needed tokens, 2: current balance */
                    __('Not enough tokens. Need %1$d, you have %2$d.', WCTK_TEXT_DOMAIN),
                    $needed_tokens,
                    $balance
                ),
                'error'
            );
            return ['result' => 'fail'];
        }

        if ($order->get_meta(WCTK_ORDER_META_TOKENS_SPENT) === 'yes') {
            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        $lock_key = self::SPEND_LOCK_PREFIX . $order_id;
        if (get_transient($lock_key)) {
            if ($order->get_meta(WCTK_ORDER_META_TOKENS_SPENT) === 'yes') {
                return [
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                ];
            }
            wc_add_notice(__('Payment in progress, please try again.', WCTK_TEXT_DOMAIN), 'error');
            return ['result' => 'fail'];
        }
        set_transient($lock_key, 1, self::SPEND_LOCK_TTL);

        try {
            if ($order->get_meta(WCTK_ORDER_META_TOKENS_SPENT) === 'yes') {
                delete_transient($lock_key);
                return [
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                ];
            }

            WCTK_Balance::change($user_id, -$needed_tokens, WCTK_Ledger::KIND_SPEND, (int) $order_id, __('Paid with tokens', WCTK_TEXT_DOMAIN), [
                'order_total'          => $total,
                'order_total_default'  => $total_default,
                'rate'                 => $rate,
                'needed_tokens'        => $needed_tokens,
                'currency'             => $order->get_currency(),
                'default_currency'     => WCTK_Shortcode_Buy::get_default_currency(),
            ]);

            $order->update_meta_data(WCTK_ORDER_META_TOKENS_SPENT, 'yes');
            $order->update_meta_data(WCTK_ORDER_META_TOKENS_SPENT_QTY, $needed_tokens);
            $order->payment_complete();

            if ($order->needs_processing()) {
                $order->update_status('processing', 'Paid with tokens.');
            } else {
                $order->update_status('completed', 'Paid with tokens.');
            }

            $order->add_order_note(sprintf('Spent %d tokens from user #%d', $needed_tokens, $user_id));
            $order->save();

            delete_transient($lock_key);
            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Throwable $e) {
            delete_transient($lock_key);
            wc_add_notice(
                sprintf(__('Token payment failed: %s', WCTK_TEXT_DOMAIN), esc_html($e->getMessage())),
                'error'
            );
            return ['result' => 'fail'];
        }
    }
}
