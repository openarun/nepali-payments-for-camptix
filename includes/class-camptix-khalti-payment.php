<?php

/**
 * Khalti Payment Gateway for CampTix
 *
 * @package CampTix_Nepali_Payments
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Khalti Payment Gateway Class
 */
class CampTix_Khalti_Payment_Method extends CampTix_Payment_Method
{
    /**
     * Minimum payable amount in paisa (Rs. 10).
     *
     * @var int
     */
    const MIN_AMOUNT_PAISA = 1000;

    /**
     * Attendee meta key for the Khalti pidx from initiate.
     *
     * Bound on return so a completed pidx cannot complete a different order.
     *
     * @var string
     */
    const META_PIDX = '_camptix_khalti_pidx';

    /**
     * Payment gateway ID
     *
     * @var string
     */
    public $id = 'camptix_khalti';

    /**
     * Payment gateway name
     *
     * @var string
     */
    public $name = 'Khalti';

    /**
     * Payment gateway description
     *
     * @var string
     */
    public $description = 'CampTix payment methods for Khalti Gateway';

    /**
     * Supported currencies
     *
     * @var array
     */
    public $supported_currencies = array('NPR');

    /**
     * Gateway options
     *
     * @var array
     */
    protected $options = array();

    /**
     * Tokens reconciled in this timeout sweep.
     *
     * @var array<string, true>
     */
    protected static $timeout_reconciled_tokens = array();

    /**
     * Initialize the gateway
     *
     * @return void
     */
    public function camptix_init()
    {
        // Translate after text domain is available (constructor requires a non-empty default).
        $this->description = __('CampTix payment method for the Khalti gateway.', 'nepali-payments-for-camptix');

        wp_register_script(
            'camptix-khalti-redirect',
            plugin_dir_url(dirname(__FILE__)) . 'js/khalti-redirect.js',
            array(),
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'js/khalti-redirect.js'),
            true
        );

        $this->options = array_merge(
            array(
                'ref_code'      => '',
                'merchant_key'  => '',
                'sandbox'       => true,
            ),
            $this->get_payment_options()
        );

        add_action('template_redirect', array($this, 'template_redirect'));
        add_action('camptix_pre_attendee_timeout', array($this, 'pre_attendee_timeout'));
    }

    /**
     * Add settings fields
     *
     * @return void
     */
    public function payment_settings_fields()
    {
        $this->add_settings_field_helper('ref_code', __('Reference Code', 'nepali-payments-for-camptix'), array($this, 'field_text'));
        $this->add_settings_field_helper('merchant_key', __('Merchant Key', 'nepali-payments-for-camptix'), array($this, 'field_text'));
        $this->add_settings_field_helper('sandbox', __('Sandbox Mode', 'nepali-payments-for-camptix'), array($this, 'field_yesno'));
    }

    /**
     * Validate options
     *
     * @param array $input Input data.
     * @return array
     */
    public function validate_options($input)
    {
        $output = $this->options;

        if (isset($input['ref_code'])) {
            $output['ref_code'] = sanitize_text_field($input['ref_code']);
        }
        if (isset($input['merchant_key'])) {
            $output['merchant_key'] = sanitize_text_field($input['merchant_key']);
        }
        if (isset($input['sandbox'])) {
            $output['sandbox'] = (bool) $input['sandbox'];
        }

        return $output;
    }

    /**
     * Handle template redirect
     *
     * @return void
     */
    public function template_redirect()
    {
        // Khalti returns via GET redirect; auth is the server-side lookup, not a WP form nonce.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        // ConnectIPS mangles the CampTix return query string; repair before the standard checks.
        $this->normalize_khalti_return_query_vars();

        if (! isset($_GET['tix_payment_method']) || $this->id !== sanitize_text_field(wp_unslash($_GET['tix_payment_method']))) {
            return;
        }

        if (isset($_GET['tix_action']) && 'payment_return' === sanitize_text_field(wp_unslash($_GET['tix_action']))) {
            $this->payment_return();
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Repair ConnectIPS-mangled return query strings in place.
     *
     * ConnectIPS (via Khalti) has been observed to:
     * 1. HTML-encode separators (`&` → `&amp;`), producing keys like `amp;tix_payment_token`
     * 2. Append `/?pidx=...` to the end of the URL, gluing pidx onto `tix_payment_method`
     *
     * Only known payment-return keys are promoted; values are sanitized where
     * payment_return / template_redirect consume them.
     *
     * @return void
     */
    private function normalize_khalti_return_query_vars()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $allowlist = array(
            'tix_action',
            'tix_payment_token',
            'tix_payment_method',
            'pidx',
        );

        foreach (array_keys($_GET) as $key) {
            $key = (string) $key;
            if (0 !== strpos($key, 'amp;')) {
                continue;
            }
            $fixed = substr($key, 4);
            if ('' === $fixed || ! in_array($fixed, $allowlist, true) || isset($_GET[$fixed])) {
                continue;
            }

            // Glued method+pidx must pass through for the split below.
            if ('tix_payment_method' === $fixed && is_string($_GET[$key])) {
                $method_candidate = wp_unslash($_GET[$key]);
                if (false !== strpos($method_candidate, '?') && 0 === strpos($method_candidate, $this->id)) {
                    $_GET[$fixed] = $method_candidate;
                    continue;
                }
            }

            if (is_string($_GET[$key])) {
                $_GET[$fixed] = wp_unslash($_GET[$key]);
            }
        }

        if (empty($_GET['tix_payment_method']) || ! is_string($_GET['tix_payment_method'])) {
            return;
        }

        $method_raw = wp_unslash($_GET['tix_payment_method']);
        if (false === strpos($method_raw, '?') || 0 !== strpos($method_raw, $this->id)) {
            return;
        }

        // e.g. camptix_khalti/?pidx=XXX or camptix_khalti?pidx=XXX
        $parts       = explode('?', $method_raw, 2);
        $method_base = preg_replace('#/$#', '', $parts[0]);
        if ($this->id !== $method_base) {
            return;
        }
        $_GET['tix_payment_method'] = $this->id;

        // ConnectIPS only glues pidx here; do not import arbitrary query keys.
        if (empty($parts[1]) || isset($_GET['pidx'])) {
            return;
        }

        parse_str($parts[1], $extra);
        if (! empty($extra['pidx']) && is_string($extra['pidx'])) {
            $_GET['pidx'] = $extra['pidx'];
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Before CampTix marks a draft attendee as timed out, complete the order if
     * Khalti already shows the payment as Completed (paid but return never ran).
     *
     * @param int $attendee_id Attendee post ID about to time out.
     * @return void
     */
    public function pre_attendee_timeout($attendee_id)
    {
        $attendee_id = absint($attendee_id);
        if (! $attendee_id || 'draft' !== get_post_field('post_status', $attendee_id)) {
            return;
        }

        if ($this->id !== get_post_meta($attendee_id, 'tix_payment_method', true)) {
            return;
        }

        $payment_token = (string) get_post_meta($attendee_id, 'tix_payment_token', true);
        $pidx          = (string) get_post_meta($attendee_id, self::META_PIDX, true);
        if ('' === $payment_token || '' === $pidx) {
            return;
        }

        if (isset(self::$timeout_reconciled_tokens[$payment_token])) {
            return;
        }
        self::$timeout_reconciled_tokens[$payment_token] = true;

        $lookup = $this->verify_transaction($pidx);
        if (empty($lookup['status']) || 'Completed' !== $lookup['status']) {
            return;
        }

        if (empty($lookup['transaction_id'])) {
            $this->log('Khalti timeout reconcile: Completed without transaction_id.', $attendee_id, $lookup);
            return;
        }

        $order = $this->get_order($payment_token);
        if (empty($order)) {
            return;
        }

        $expected_amount = (int) round(((float) $order['total']) * 100);
        $paid_amount     = isset($lookup['total_amount']) ? (int) $lookup['total_amount'] : 0;
        if ($paid_amount !== $expected_amount) {
            $this->log(
                'Khalti timeout reconcile: amount mismatch.',
                $attendee_id,
                compact('expected_amount', 'paid_amount', 'lookup')
            );
            return;
        }

        $payment_data = array(
            'transaction_id'      => sanitize_text_field($lookup['transaction_id']),
            'transaction_details' => $lookup,
        );

        $this->payment_result(
            $payment_token,
            CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
            $payment_data,
            false // Non-interactive: do not redirect.
        );
    }

    /**
     * FAILED unless attendees already publish/pending/refund.
     *
     * @param string $payment_token Payment token.
     * @param array  $payment_data  Optional payment data for payment_result().
     * @param bool   $interactive   Whether to redirect.
     * @return mixed
     */
    protected function payment_result_failed($payment_token, $payment_data = array(), $interactive = true)
    {
        // Uncached status check.
        $finalized = get_posts(
            array(
                'posts_per_page' => 1,
                'post_type'      => 'tix_attendee',
                'post_status'    => array('publish', 'pending', 'refund'),
                'meta_query'     => array(
                    array(
                        'key'   => 'tix_payment_token',
                        'value' => $payment_token,
                    ),
                ),
            )
        );

        if (! empty($finalized)) {
            $attendee = $finalized[0];
            $this->log(
                sprintf('Refusing to mark payment failed; attendee already in %s status.', $attendee->post_status),
                $attendee->ID,
                $payment_data
            );

            if (! $interactive) {
                return false;
            }

            if (in_array($attendee->post_status, array('publish', 'pending'), true)) {
                $access_token = get_post_meta($attendee->ID, 'tix_access_token', true);
                $url          = add_query_arg(
                    array(
                        'tix_action'       => 'access_tickets',
                        'tix_access_token' => $access_token,
                    ),
                    $this->get_tickets_url()
                );
                wp_safe_redirect($url . '#tix');
                exit;
            }

            wp_safe_redirect(esc_url_raw($this->get_tickets_url()));
            exit;
        }

        return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data, $interactive);
    }

    /**
     * Handle payment return
     *
     * @return void
     */
    public function payment_return()
    {
        global $camptix;

        // CampTix order id comes from our return_url; pidx is bound via attendee meta.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $payment_token = isset($_GET['tix_payment_token']) ? sanitize_text_field(wp_unslash($_GET['tix_payment_token'])) : '';
        $pidx          = isset($_GET['pidx']) ? sanitize_text_field(wp_unslash($_GET['pidx'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (empty($payment_token) || empty($pidx)) {
            return;
        }

        $order = $this->get_order($payment_token);
        if (empty($order)) {
            $this->log('Khalti payment return: order not found for token ' . $payment_token);
            return;
        }

        $stored_pidx = (string) get_post_meta($order['attendee_id'], self::META_PIDX, true);
        if ('' === $stored_pidx || ! hash_equals($stored_pidx, $pidx)) {
            $this->log(
                'Khalti payment return: pidx does not match the order initiate.',
                $order['attendee_id'],
                array(
                    'pidx'        => $pidx,
                    'stored_pidx' => $stored_pidx,
                )
            );
            wp_safe_redirect(esc_url_raw($this->get_tickets_url()));
            exit;
        }

        $lookup = $this->verify_transaction($pidx);
        if (empty($lookup) || empty($lookup['status'])) {
            // Leave draft on lookup/API failure so a transient error cannot fail a paid order.
            $this->log(
                'Khalti payment return: lookup failed or returned no status; leaving order as draft.',
                $order['attendee_id'],
                array(
                    'pidx'   => $pidx,
                    'lookup' => is_array($lookup) ? $lookup : array(),
                )
            );
            wp_safe_redirect(esc_url_raw($this->get_tickets_url()));
            exit;
        }

        $status = sanitize_text_field($lookup['status']);
        $payment_data = [
            'transaction_id'      => ! empty($lookup['transaction_id']) ? sanitize_text_field($lookup['transaction_id']) : $pidx,
            'transaction_details' => $lookup,
        ];

        switch ($status) {
            case 'Completed':
                if (empty($lookup['transaction_id'])) {
                    $this->log('Khalti lookup completed without transaction_id for pidx ' . $pidx, $order['attendee_id'], $lookup);
                    $this->payment_result_failed($payment_token, $payment_data);
                    return;
                }

                $expected_amount = (int) round(((float) $order['total']) * 100);
                $paid_amount     = isset($lookup['total_amount']) ? (int) $lookup['total_amount'] : 0;

                if ($paid_amount !== $expected_amount) {
                    $this->log(
                        sprintf('Khalti amount mismatch for pidx %s.', $pidx),
                        $order['attendee_id'],
                        compact('expected_amount', 'paid_amount', 'lookup')
                    );
                    $this->payment_result_failed($payment_token, $payment_data);
                    return;
                }

                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data);
                break;
            case 'Expired':
                // Checkout session expired.
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_TIMEOUT, $payment_data);
                break;
            case 'Cancelled':  // Both spellings supported by Khalti.
            case 'Canceled':
            case 'User cancelled':
            case 'User canceled':
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED, $payment_data);
                break;
            case 'Failed':     // Payment attempt failed.
                $this->payment_result_failed($payment_token, $payment_data);
                break;
            case 'Pending':    // Payment still in progress — do not issue tickets.
            case 'Initiated':  // Checkout started; customer has not paid yet.
            default:
                // Leave attendees as draft until Khalti reports Completed.
                $this->log(
                    sprintf('Khalti payment not confirmed for pidx %s (status: %s)', esc_html($pidx), esc_html($status)),
                    $order['attendee_id'],
                    array(
                        'pidx'   => $pidx,
                        'status' => $status,
                    )
                );
                wp_safe_redirect(esc_url_raw($this->get_tickets_url()));
                exit;
        }
    }

    /**
     * Output the redirect page with proper script enqueuing
     *
     * @param string $payment_url Khalti payment URL to redirect to.
     */
    private function output_khalti_redirect_page($payment_url)
    {
        wp_enqueue_script('camptix-khalti-redirect');
        wp_localize_script(
            'camptix-khalti-redirect',
            'camptixKhaltiData',
            array(
                'paymentUrl' => $payment_url,
            )
        );
?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="UTF-8">
            <title><?php esc_html_e('Redirecting to Khalti Payment', 'nepali-payments-for-camptix'); ?></title>
            <?php wp_head(); ?>
        </head>

        <body>
            <p><?php esc_html_e('Redirecting you to Khalti Payment...', 'nepali-payments-for-camptix'); ?></p>
            <p>
                <?php esc_html_e('If you are not redirected automatically,', 'nepali-payments-for-camptix'); ?>
                <a href="<?php echo esc_url($payment_url); ?>">
                    <?php esc_html_e('click here', 'nepali-payments-for-camptix'); ?>
                </a>.
            </p>
            <?php wp_footer(); ?>
        </body>

        </html>
<?php
        exit;
    }

    /**
     * Handle payment checkout
     *
     * @param string $payment_token Payment token.
     * @return bool|void
     */
    public function payment_checkout($payment_token)
    {
        global $camptix;

        if (! $payment_token || empty($payment_token)) {
            return false;
        }

        if (empty($this->options['merchant_key'])) {
            $this->log('Khalti merchant key is not configured.');
            return $this->payment_result_failed($payment_token);
        }

        if (! in_array($this->camptix_options['currency'], $this->supported_currencies, true)) {
            wp_die(esc_html__('The selected currency is not supported by this payment method.', 'nepali-payments-for-camptix'));
        }

        $return_url = add_query_arg(
            array(
                'tix_action'         => 'payment_return',
                'tix_payment_token'  => $payment_token,
                'tix_payment_method' => $this->id,
            ),
            $this->get_tickets_url()
        );

        $order = $this->get_order($payment_token);
        if (! $order) {
            return false;
        }

        // One final check before sending the buyer to Khalti
        if (! $camptix->verify_order($order)) {
            $this->log('Could not verify CampTix order before Khalti checkout.', $order['attendee_id']);
            return $this->payment_result_failed($payment_token);
        }

        $buyer_name = trim(
            get_post_meta($order['attendee_id'], 'tix_first_name', true) . ' ' .
                get_post_meta($order['attendee_id'], 'tix_last_name', true)
        );
        $buyer_email = get_post_meta($order['attendee_id'], 'tix_email', true);
        $buyer_phone = get_post_meta($order['attendee_id'], 'tix_phone', true);

        $ref_prefix = ! empty($this->options['ref_code']) ? $this->options['ref_code'] . '-' : '';

        $order_items = $order['items'];
        if (empty($order_items)) {
            return false;
        }

        $total_quantity = array_sum(array_column($order_items, 'quantity'));
        if (count($order_items) > 1) {
            $base_name = sprintf(
                /* translators: %d: total ticket quantity */
                __('Tickets X %d', 'nepali-payments-for-camptix'),
                $total_quantity
            );
        } else {
            $first_item = reset($order_items);
            $item_name = wp_strip_all_tags($first_item['name']);
            $item_quantity = intval($first_item['quantity']);
            $base_name = sprintf(
                /* translators: 1: ticket name, 2: quantity */
                __('%1$s X %2$d', 'nepali-payments-for-camptix'),
                $item_name,
                $item_quantity
            );
        }

        $purchase_order_name = sanitize_text_field($ref_prefix . $base_name);

        $amount_breakdown = [];
        $product_details  = [];
        foreach ($order_items as $item) {
            $item_name     = wp_strip_all_tags($item['name']);
            $item_quantity = (int) $item['quantity'];
            $unit_price    = (int) round((float) $item['price'] * 100);
            $line_total    = $unit_price * $item_quantity;

            $amount_breakdown[] = [
                'label'  => sprintf(
                    /* translators: 1: ticket name, 2: quantity */
                    __('%1$s x %2$d', 'nepali-payments-for-camptix'),
                    $item_name,
                    $item_quantity
                ),
                'amount' => $line_total,
            ];

            $product_details[] = [
                'identity'    => (string) $item['id'],
                'name'        => sanitize_text_field($ref_prefix . $item_name),
                'total_price' => $line_total,
                'quantity'    => $item_quantity,
                'unit_price'  => $unit_price,
            ];
        }

        $purchase_order_id = $payment_token;
        $total_amount      = (int) round((float) $order['total'] * 100);

        if ($total_amount < self::MIN_AMOUNT_PAISA) {
            $this->log(
                sprintf('Khalti order total is below the minimum of %d paisa.', self::MIN_AMOUNT_PAISA),
                $order['attendee_id']
            );
            return $this->payment_result_failed($payment_token);
        }

        $customer_name = sanitize_text_field($buyer_name);
        $customer_email = sanitize_email($buyer_email);

        if (! is_email($customer_email)) {
            $this->log('Invalid email format provided: ' . esc_html($buyer_email));
            return $this->payment_result_failed($payment_token);
        }

        $buyer_phone = preg_replace('/[^\d+]/', '', $buyer_phone);

        // If the phone number exceeds 16 chars, reset to empty string.
        if (strlen($buyer_phone) > 16) {
            $buyer_phone = '';
        }

        $customer_phone = sanitize_text_field($buyer_phone);

        $payload = array(
            'return_url'          => esc_url_raw($return_url),
            'website_url'         => esc_url_raw(home_url()),
            'amount'              => $total_amount,
            'purchase_order_id'   => $purchase_order_id,
            'purchase_order_name' => $purchase_order_name,
            'customer_info'       => array(
                'name'  => $customer_name,
                'email' => $customer_email,
                'phone' => $customer_phone,
            ),
            'amount_breakdown'    => $amount_breakdown,
            'product_details'     => $product_details,
        );

        $remote_response = $this->request_khalti_api('epayment/initiate/', $payload);

        if (is_wp_error($remote_response)) {
            $error_message = $remote_response->get_error_message();
            $this->log('Khalti Remote Request failed:' . esc_html($error_message));
            return $this->payment_result_failed($payment_token);
        }

        $response_code = (int) wp_remote_retrieve_response_code($remote_response);
        if ($response_code < 200 || $response_code >= 300) {
            $this->log(
                sprintf('Khalti initiate failed with HTTP %d.', $response_code),
                $order['attendee_id']
            );
            return $this->payment_result_failed($payment_token);
        }

        $result = json_decode(wp_remote_retrieve_body($remote_response), true);

        if (! empty($result['pidx']) && ! empty($result['payment_url'])) {
            $this->save_pidx_on_order_attendees($payment_token, $result['pidx']);
            $this->output_khalti_redirect_page(esc_url_raw($result['payment_url']));
            die();
        }

        $camptix->error(
            sprintf(
                /* translators: %s: Khalti error key or generic failure label */
                __('Payments to Khalti failed: %s', 'nepali-payments-for-camptix'),
                esc_html($result['error_key'] ?? __('Unknown error', 'nepali-payments-for-camptix'))
            )
        );
        return $this->payment_result_failed($payment_token);
    }

    /**
     * Verify a Khalti transaction via the lookup API.
     *
     * Per Khalti docs, lookup HTTP status codes are:
     * - 200: Completed, Pending, Initiated, Refunded, Partially Refunded
     * - 400: Expired, User canceled (JSON body still includes status)
     *
     * @param string $pidx Khalti payment identifier.
     * @return array|false Full lookup response, or false on failure.
     */
    public function verify_transaction($pidx)
    {
        if (empty($pidx)) {
            return false;
        }

        $remote_response = $this->request_khalti_api(
            'epayment/lookup/',
            array(
                'pidx' => sanitize_text_field($pidx),
            )
        );

        if (is_wp_error($remote_response)) {
            $this->log(sprintf('Remote Request failed: %s', esc_html($remote_response->get_error_message())));
            return false;
        }

        $response_code = (int) wp_remote_retrieve_response_code($remote_response);
        $raw_body      = wp_remote_retrieve_body($remote_response);
        $result        = json_decode($raw_body, true);
        $status        = (is_array($result) && ! empty($result['status'])) ? (string) $result['status'] : '';

        $this->log(
            sprintf('Khalti lookup response for pidx %s.', $pidx),
            null,
            array(
                'http_code' => $response_code,
                'status'    => $status,
                'body'      => is_array($result) ? $result : $raw_body,
            )
        );

        if ('' === $status) {
            $this->log(sprintf('Khalti lookup HTTP %d returned no status.', $response_code));
            return false;
        }

        if ($response_code >= 200 && $response_code < 300) {
            return $result;
        }

        // Documented non-success payment outcomes, not transport failures.
        if (400 === $response_code && in_array($status, array('Expired', 'User canceled'), true)) {
            return $result;
        }

        $this->log(
            sprintf('Khalti lookup HTTP %d with status "%s" is not a usable response.', $response_code, $status),
            null,
            $result
        );
        return false;
    }

    /**
     * Save Khalti pidx on every attendee in the order.
     *
     * @param string $payment_token CampTix payment token.
     * @param string $pidx          Khalti payment identifier from initiate.
     * @return void
     */
    private function save_pidx_on_order_attendees($payment_token, $pidx)
    {
        global $camptix;

        $payment_token = sanitize_text_field($payment_token);
        $pidx          = sanitize_text_field($pidx);

        if ('' === $payment_token || '' === $pidx) {
            return;
        }

        $attendees = $camptix->get_attendees_from_payment_token($payment_token);
        if (empty($attendees)) {
            return;
        }

        foreach ($attendees as $attendee) {
            update_post_meta($attendee->ID, self::META_PIDX, $pidx);
        }
    }

    /**
     * POST to a Khalti API endpoint (transport only).
     *
     * @param string $endpoint API endpoint path relative to /api/v2/.
     * @param array  $payload  Request payload.
     * @return array|WP_Error
     */
    private function request_khalti_api($endpoint, array $payload)
    {
        $base_url = $this->options['sandbox']
            ? 'https://dev.khalti.com/api/v2/'
            : 'https://khalti.com/api/v2/';

        return wp_remote_post(
            $base_url . ltrim($endpoint, '/'),
            array(
                'method'   => 'POST',
                'headers'  => array(
                    'Authorization' => 'key ' . sanitize_text_field($this->options['merchant_key']),
                    'Content-Type'  => 'application/json',
                ),
                'body'     => wp_json_encode($payload),
                'timeout'  => 30,
                'blocking' => true,
            )
        );
    }
}
