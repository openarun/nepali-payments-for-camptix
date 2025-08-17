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
     * Initialize the gateway
     *
     * @return void
     */
    public function camptix_init()
    {

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
        if (! isset($_REQUEST['tix_payment_method']) || 'camptix_khalti' !== sanitize_text_field(wp_unslash($_REQUEST['tix_payment_method']))) {
            return;
        }

        if (isset($_GET['tix_action']) && !empty(sanitize_text_field(wp_unslash($_GET['tix_action'])))) {
            $action = sanitize_text_field(wp_unslash($_GET['tix_action']));
            if ('payment_return' === $action) {
                $this->payment_return();
            }
        }
    }

    /**
     * Handle payment return
     *
     * @return void
     */
    public function payment_return()
    {
        global $camptix;

        $payment_token = isset($_REQUEST['tix_payment_token']) ? sanitize_text_field(wp_unslash($_REQUEST['tix_payment_token'])) : '';
        if (empty($payment_token)) {
            return;
        }

        $pidx = isset($_GET['pidx']) ? sanitize_text_field(wp_unslash($_GET['pidx'])) : '';
        $status = $this->verify_transaction($pidx);

        switch ($status) {
            case 'Completed':
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $pidx);
                break;
            case 'Pending':    // Payment is in progress
            case 'Initiated':  // Initial stage, waiting for customer
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING, $pidx);
                break;
            case 'Refunded':
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_REFUNDED, $pidx);
                break;
            case 'Expired':
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_TIMEOUT, $pidx);
                break;
            case 'Cancelled':  // Both spellings supported by Khalti
            case 'Canceled':
            case 'User cancelled':
            case 'User canceled':
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED, $pidx);
                break;
            case 'Failed':     // Payment attempt failed
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $pidx);
                break;
            default:
                $this->log(sprintf('Unknown Khalti payment status for pidx %s: %s', $pidx, esc_html($status)), null, array(
                    'pidx' => $pidx,
                    'status' => $status,
                ));
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING, $pidx);
                break;
        }

        $attendees = get_posts(
            array(
                'posts_per_page' => 1,
                'post_type'      => 'tix_attendee',
                'post_status'    => array('draft', 'pending', 'publish', 'cancel', 'refund', 'failed'),
                'meta_query'     => array(
                    array(
                        'key'     => 'tix_payment_token',
                        'compare' => '=',
                        'value'   => $payment_token,
                        'type'    => 'CHAR',
                    ),
                ),
            )
        );

        if (empty($attendees)) {
            return;
        }

        $attendee = reset($attendees);
        $access_token = get_post_meta($attendee->ID, 'tix_access_token', true);
        $url = add_query_arg(
            array(
                'tix_action'       => 'access_tickets',
                'tix_access_token' => $access_token,
            ),
            $camptix->get_tickets_url()
        );

        wp_safe_redirect(esc_url_raw($url . '#tix'));
        exit;
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

        if (! in_array($this->camptix_options['currency'], $this->supported_currencies, true)) {
            wp_die(esc_html__('The selected currency is not supported by this payment method.', 'nepali-payments-for-camptix'));
        }

        $return_url = add_query_arg(
            array(
                'tix_action'         => 'payment_return',
                'tix_payment_token'  => $payment_token,
                'tix_payment_method' => 'camptix_khalti',
            ),
            $this->get_tickets_url()
        );

        $order = $this->get_order($payment_token);
        if (! $order) {
            return false;
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
            $base_name = sprintf('Tickets X %d', $total_quantity);
        } else {
            $first_item = reset($order_items);
            $item_name = wp_strip_all_tags($first_item['name']);
            $item_quantity = intval($first_item['quantity']);
            $base_name = sprintf('%1$s X %2$d', $item_name, $item_quantity);
        }

        $purchase_order_name = sanitize_text_field($ref_prefix . $base_name);

        $amount_breakdown = [];
        $product_details  = [];
        foreach ($order_items as $item) {

            $item_name     = wp_strip_all_tags($item['name']);
            $item_quantity = intval($item['quantity']);

            $ticket_name = $ref_prefix . $item_name;

            // Convert price to paisa (integer)
            $ticket_amount = intval($item['price'] * 100);

            $amount_breakdown[] = [
                'label'  => sprintf('%1$s x %2$d', $item_name, $item_quantity),
                'amount' => $ticket_amount * $item_quantity,
            ];

            $product_details[] = [
                'identity'     => $item['id'],
                'name'         => sanitize_text_field($ticket_name),
                'total_price'  => $ticket_amount * $item_quantity,
                'quantity'     => $item_quantity,
                'unit_price'   => $ticket_amount,
            ];
        }

        $purchase_order_id = $payment_token;

        $total_amount = intval($order['total'] * 100);

        $customer_name = sanitize_text_field($buyer_name);
        $customer_email = sanitize_email($buyer_email);

        if (! is_email($customer_email)) {
            $this->log('Invalid email format provided: ' . esc_html($buyer_email));
            return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);
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
            'product_details'     => $product_details
        );

        $merchant_key = $this->options['merchant_key'];
        $headers = array(
            'Authorization' => 'key ' . sanitize_text_field($merchant_key),
            'Content-Type'  => 'application/json',
        );

        $url = $this->options['sandbox']
            ? 'https://dev.khalti.com/api/v2/epayment/initiate/'
            : 'https://khalti.com/api/v2/epayment/initiate/';

        $remote_response = wp_remote_post(
            $url,
            array(
                'method'    => 'POST',
                'headers'   => $headers,
                'body'      => wp_json_encode($payload),
                'timeout'   => 15,
                'blocking'  => true,
            )
        );

        if (is_wp_error($remote_response)) {
            $error_message = $remote_response->get_error_message();
            $this->log('Khalti Remote Request failed:' . esc_html($error_message));
            return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);
        }

        $result = json_decode(wp_remote_retrieve_body($remote_response), true);

        if (isset($result['payment_url'])) {
            $this->output_khalti_redirect_page(esc_url_raw($result['payment_url']));
            die();
        }

        $camptix->error('Payments to Khalti failed : ' . esc_html($result["error_key"] ?? "Unknown error"));
        return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);
    }

    /**
     * Verify transaction
     *
     * @param string $pidx Transaction ID.
     * @return string
     */
    public function verify_transaction($pidx)
    {
        if (empty($pidx)) {
            $this->log('Empty pidx provided for transaction verification');
            return 'Failed';
        }

        $merchant_key = $this->options['merchant_key'];
        $headers = array(
            'Authorization' => 'key ' . sanitize_text_field($merchant_key),
            'Content-Type'  => 'application/json',
        );

        $url = $this->options['sandbox']
            ? 'https://dev.khalti.com/api/v2/epayment/lookup/'
            : 'https://khalti.com/api/v2/epayment/lookup/';

        $payload = array(
            'pidx' => sanitize_text_field($pidx),
        );

        $remote_response = wp_remote_post(
            $url,
            array(
                'method'    => 'POST',
                'headers'   => $headers,
                'body'      => wp_json_encode($payload),
                'timeout'   => 15,
                'blocking'  => true,
            )
        );

        if (is_wp_error($remote_response)) {
            $error_message = $remote_response->get_error_message();
            $this->log(sprintf('Remote Request failed: %s', esc_html($error_message)));
            return 'Pending';
        }

        $result = json_decode(wp_remote_retrieve_body($remote_response), true);
        return isset($result['status']) ? sanitize_text_field($result['status']) : 'Unknown';
    }
}
