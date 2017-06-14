<?php
/*
Plugin Name: WooCommerce RBKmoney Payment Gateway
Plugin URI: https://www.rbk.money
Description: RBKmoney Payment gateway for woocommerce
Version: 1.0
Author: RBKmoney
Author URI: https://www.rbk.money
*/
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Add custom action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'rbkmoney_action_links');
function rbkmoney_action_links($links)
{
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_rbkmoney') . '">' . __('Настройки', 'rbkmoney') . '</a>',
    );

    // Merge our new link with the default ones
    return array_merge($plugin_links, $links);
}

add_filter('plugin_row_meta', 'custom_plugin_row_meta', 10, 2);

function custom_plugin_row_meta($links, $file)
{
    if (strpos($file, plugin_basename(__FILE__)) !== false) {
        $new_links = array(
            'docs' => '<a href="https://rbkmoney.github.io/docs/" target="_blank">Документация</a>',
            'docs_api' => '<a href="https://rbkmoney.github.io/api/" target="_blank">Документация по API</a>'
        );
        $links = array_merge($links, $new_links);
    }

    return $links;
}

add_action('plugins_loaded', 'rbkmoney_add_gateway_class', 0);
function rbkmoney_add_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    /**
     * RBKmoney Payment Gateway
     *
     * Provides an RBKmoney Payment Gateway
     *
     * @class       WC_RBKmoney_Gateway
     * @extends     WC_Payment_Gateway
     * @version     1.0.0
     * @package     WooCommerce/Classes/Payment
     * @author      RBKmoney
     *
     * @see https://docs.woocommerce.com/document/payment-gateway-api/
     */
    class WC_Gateway_RBKmoney extends WC_Payment_Gateway
    {

        /** @var bool Whether or not logging is enabled */
        public static $log_enabled = false;

        /** @var WC_Logger Logger instance */
        public static $log = false;

        // ------------------------------------------------------------------------ 
        // Constants
        // ------------------------------------------------------------------------

        const GATEWAY_NAME = 'RBKmoney';

        /**
         * URL-s
         */
        const PAYMENT_FORM_URL = 'https://checkout.rbk.money/checkout.js';
        const API_URL = 'https://api.rbk.money/v1/';

        /**
         * HTTP METHOD
         */
        const HTTP_METHOD_POST = 'POST';

        /**
         * Create invoice settings
         */
        const CREATE_INVOICE_TEMPLATE_DUE_DATE = 'Y-m-d\TH:i:s\Z';
        const CREATE_INVOICE_DUE_DATE = '+1 days';

        /**
         * HTTP status code
         */
        const HTTP_CODE_OK = 'HTTP/1.1 200 OK';
        const HTTP_CODE_CREATED = 'HTTP/1.1 201 CREATED';
        const HTTP_CODE_BAD_REQUEST = 'HTTP/1.1 400 BAD REQUEST';

        /**
         * Constants for Callback
         */
        const SIGNATURE = 'HTTP_CONTENT_SIGNATURE';
        const SIGNATURE_ALG = 'alg';
        const SIGNATURE_DIGEST = 'digest';
        const SIGNATURE_PATTERN = "|alg=(\S+);\sdigest=(.*)|i";

        const EVENT_TYPE = 'eventType';

        // EVENT TYPE INVOICE
        const EVENT_TYPE_INVOICE_CREATED = 'InvoiceCreated';
        const EVENT_TYPE_INVOICE_PAID = 'InvoicePaid';
        const EVENT_TYPE_INVOICE_CANCELLED = 'InvoiceCancelled';
        const EVENT_TYPE_INVOICE_FULFILLED = 'InvoiceFulfilled';

        // EVENT TYPE PAYMENT
        const EVENT_TYPE_PAYMENT_STARTED = 'PaymentStarted';
        const EVENT_TYPE_PAYMENT_PROCESSED = 'PaymentProcessed';
        const EVENT_TYPE_PAYMENT_CAPTURED = 'PaymentCaptured';
        const EVENT_TYPE_PAYMENT_CANCELLED = 'PaymentCancelled';
        const EVENT_TYPE_PAYMENT_FAILED = 'PaymentFailed';

        const INVOICE = 'invoice';
        const INVOICE_ID = 'id';
        const INVOICE_SHOP_ID = 'shopID';
        const INVOICE_METADATA = 'metadata';
        const INVOICE_STATUS = 'status';
        const INVOICE_AMOUNT = 'amount';

        const ORDER_ID = 'order_id';

        /**
         * Openssl verify
         */
        const OPENSSL_VERIFY_SIGNATURE_IS_CORRECT = 1;


        /**
         * Constructor for the gateway
         */
        public function __construct()
        {
            /**
             * The unique ID for this gateway
             */
            $this->id = "rbkmoney";

            /**
             * Title used on the front side at the checkout page
             */
            $this->title = strip_tags($this->get_option('title'));

            /**
             * Payment method description for the frontend
             */
            $this->description = strip_tags($this->get_option('description'));

            /**
             * The link to the image displayed next to the method’s title on the checkout page
             * — this is optional and doesn’t need to be set.
             */
            $this->icon = apply_filters('woocommerce_offline_icon', '');

            /**
             * This should be false for our simple gateway, but can be set to true
             * if you create a direct payment gateway that will have fields,
             * such as credit card fields.
             */
            $this->has_fields = false;

            /**
             * The title of the payment method for the admin page
             */
            $this->method_title = __(static::GATEWAY_NAME, $this->id);

            /**
             * The description for the payment method shown to the admins
             */
            $this->method_description = __('Payment RBKmoney', $this->id);

            $this->debug = 'yes' === $this->get_option('debug', 'no');

            self::$log_enabled = $this->debug;

            /**
             * Once we’ve set these variables, the constructor will need a few other functions.
             * We’ll have to initialize the form fields and settings.
             */
            $this->init_form_fields();
            $this->init_settings();


            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            // Save options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            // Payment listener/API hook
            add_action('woocommerce_api_' . $this->id . '_callback', array($this, 'callback_handler'));
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page()
        {
            $session_handler = new WC_Session_Handler();
            if (isset($_GET['status']) && $_GET['status'] == 'success') {
                $session_handler->destroy_session();
                echo "Оплата принята";
                return;
            }

            /** @var WC_Order $order */
            $order = wc_get_order( $_GET['order-received']);

            try {
                if (empty($session_handler->get("invoice_id"))) {
                    $invoice_id = $this->_create_invoice($order);
                    $session_handler->set("invoice_id", $invoice_id);
                } else {
                    $invoice_id = $session_handler->get("invoice_id");
                }

                $access_token = $this->_create_access_token($invoice_id);
            } catch (Exception $ex) {
                echo $ex->getMessage();
                exit();
            }

            $data_logo = !empty($this->get_option('form_path_logo')) ? 'data-logo="' . strip_tags($this->get_option('form_path_logo')) . '"' : '';
            $company_name = !empty($this->get_option('form_company_name')) ? 'data-name="' . strip_tags($this->get_option('form_company_name')) . '"' : '';
            $button_label = !empty($this->get_option('form_button_label')) ? 'data-label="' . strip_tags($this->get_option('form_button_label')) . '"' : '';
            $description = !empty($this->get_option('form_description')) ? 'data-description="' . strip_tags($this->get_option('form_description')) . '"' : '';


            $style = !empty($this->get_option('form_css_button')) ? '<style>' . strip_tags($this->get_option('form_css_button')) . '</style>' : '';
            $form = '<form action="' . $this->get_return_url($order) . '&status=success' . '" method="POST">
                    <script src="' . static::PAYMENT_FORM_URL . '" class="rbkmoney-checkout"
                    data-invoice-id="' . $invoice_id . '"
                    data-invoice-access-token="' . $access_token . '"
                    ' . $data_logo . '
                    ' . $company_name . '
                    ' . $button_label . '
                    ' . $description . '
                    >
                    </script>
                </form>';

            $html = $style . $form;

            echo $html;
        }

        /**
         * Return handler for Hosted Payments.
         * e.g. ?wc-api=rbkmoney_callback
         */
        public function callback_handler()
        {
            $content = file_get_contents('php://input');
            $logs = array(
                'content' => $content,
                'method' => $_SERVER['REQUEST_METHOD'],
            );

            if (empty($_SERVER[static::SIGNATURE])) {
                $message = 'Webhook notification signature missing';
                $this->output($message, $logs);
            }
            $logs['signature'] = $_SERVER[static::SIGNATURE];

            $params_signature = $this->get_parameters_content_signature($_SERVER[static::SIGNATURE]);
            if (empty($params_signature[static::SIGNATURE_ALG])) {
                $message = 'Missing required parameter ' . static::SIGNATURE_ALG;
                $this->output($message, $logs);
            }

            if (empty($params_signature[static::SIGNATURE_DIGEST])) {
                $message = 'Missing required parameter ' . static::SIGNATURE_DIGEST;
                $this->output($message, $logs);
            }

            $signature = $this->url_safe_b64decode($params_signature[static::SIGNATURE_DIGEST]);
            if (!$this->verification_signature($content, $signature, $this->_getPublicKey())) {
                $message = 'Webhook notification signature mismatch';
                $this->output($message, $logs);
            }

            $required_fields = [static::INVOICE, static::EVENT_TYPE];
            $data = json_decode($content, TRUE);

            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    $message = 'One or more required fields are missing';
                    $this->output($message, $logs);
                }
            }

            $current_shop_id = (int)$this->get_option('shop_id');
            if ($data[static::INVOICE][static::INVOICE_SHOP_ID] != $current_shop_id) {
                $message = static::INVOICE_SHOP_ID . ' is missing';
                $this->output($message, $logs);
            }

            if (empty($data[static::INVOICE][static::INVOICE_METADATA][static::ORDER_ID])) {
                $message = static::ORDER_ID . ' is missing';
                $this->output($message, $logs);
            }

            $order_id = $data[static::INVOICE][static::INVOICE_METADATA][static::ORDER_ID];
            $order = wc_get_order($order_id);

            if (empty($order)) {
                $message = 'Order ' . $order_id . ' is missing';
                $this->output($message, $logs);
            }

            if (!empty($order->order_total)) {
                $order_amount = (int)$this->_prepare_amount($order->order_total);
                $invoice_amount = (int)$data[static::INVOICE][static::INVOICE_AMOUNT];
                if ($order_amount != $invoice_amount) {
                    $message = 'Received amount vs Order amount mismatch';
                    $this->output($message, $logs);
                }
            }

            $allowed_event_types = [static::EVENT_TYPE_INVOICE_PAID, static::EVENT_TYPE_INVOICE_CANCELLED];
            $final_statuses = ['completed', 'cancelled'];
            if (!in_array($order->get_status(), $final_statuses) && in_array($data[static::EVENT_TYPE], $allowed_event_types)) {
                $order->add_order_note(sprintf(__('Payment approved (invoice ID: %1$s)', $this->id), $data[static::INVOICE][static::INVOICE_ID]));
                $order->payment_complete($data[static::INVOICE][static::INVOICE_ID]);
                $message = 'Payment approved, invoice ID: ' . $data[static::INVOICE][static::INVOICE_ID];
                $this->output($message, $logs, self::HTTP_CODE_OK);
            }

            exit();
        }

        private function _getPublicKey()
        {
            return '-----BEGIN PUBLIC KEY-----' . PHP_EOL . $this->get_option('callback_public_key') . PHP_EOL . '-----END PUBLIC KEY-----';
        }

        private function output($message, &$logs, $header = self::HTTP_CODE_BAD_REQUEST)
        {
            header($header);
            $response = array('message' => $message);
            $this->log($message . ' ' . print_r($logs, true));
            echo json_encode($response);
            exit();
        }

        public function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        function init_form_fields()
        {
            $this->form_fields = array(

                'enabled' => array(
                    'title' => __('Enable/Disable', $this->id),
                    'type' => 'checkbox',
                    'label' => __('Enable RBKmoney Payment', $this->id),
                    'default' => 'yes'
                ),

                'title' => array(
                    'title' => __('Title', $this->id),
                    'type' => 'text',
                    'description' => __('This controls the title for RBKmoney the payment method sees during checkout.', $this->id),
                    'default' => __('RBKmoney', $this->id),
                    'desc_tip' => true,
                ),

                'description' => array(
                    'title' => __('Description', $this->id),
                    'type' => 'textarea',
                    'description' => __('', $this->id),
                    'default' => __('', $this->id),
                    'desc_tip' => true,
                ),

                'shop_id' => array(
                    'title' => __('Shop ID', $this->id),
                    'type' => 'text',
                    'description' => __('Number of the merchant\'s shop system RBKmoney', $this->id),
                    'default' => __('1', $this->id),
                    'desc_tip' => true,
                ),

                'private_key' => array(
                    'title' => __('Private key', $this->id),
                    'type' => 'textarea',
                    'description' => __('The private key in the system RBKmoney', $this->id),
                    'default' => __('', $this->id),
                    'desc_tip' => true,
                ),

                'notify_url' => array(
                    'title' => __('Notification URL', $this->id),
                    'type' => 'text',
                    'description' => __('This address is to be inserted in a private office RBKmoney', $this->id),
                    'default' => __('http(s)://your-site/?wc-api=rbkmoney_callback', $this->id),
                    'desc_tip' => true,
                ),

                'callback_public_key' => array(
                    'title' => __('Callback public key', $this->id),
                    'type' => 'textarea',
                    'description' => __('Callback public key for handler payment notification.', $this->id),
                    'default' => __('', $this->id),
                    'desc_tip' => true,
                ),

                'form_path_logo' => array(
                    'title' => __('Logo in payment form', $this->id),
                    'type' => 'text',
                    'description' => __('Your logo for payment form', $this->id),
                    'default' => __('', $this->id),
                    'desc_tip' => true,
                ),

                'form_css_button' => array(
                    'title' => __('Css button in payment form', $this->id),
                    'type' => 'textarea',
                    'description' => __('Css button for payment form', $this->id),
                    'default' => __('', $this->id),
                    'desc_tip' => true,
                ),

                'form_company_name' => array(
                    'title' => __('Company name in payment form', $this->id),
                    'type' => 'text',
                    'description' => __('Your company name for payment form', $this->id),
                    'default' => __('', $this->id),
                    'desc_tip' => true,
                ),

                'form_button_label' => array(
                    'title' => __('Button label in payment form', $this->id),
                    'type' => 'text',
                    'description' => __('Your button label for payment form', $this->id),
                    'default' => __('', $this->id),
                    'desc_tip' => true,
                ),

                'form_description' => array(
                    'title' => __('Description in payment form', $this->id),
                    'type' => 'text',
                    'description' => __('Your description for payment form', $this->id),
                    'default' => __('', $this->id),
                    'desc_tip' => true,
                ),
                'debug' => array(
                    'title' => __('Debug log', $this->id),
                    'type' => 'checkbox',
                    'label' => __('Enable logging', $this->id),
                    'default' => 'no',
                    'description' => sprintf(__('Log events, inside %s', $this->id), '<code>' . wc_get_log_file_path($this->id) . '</code>'),
                ),

            );

        }

        /**
         * Process Payment.
         *
         * Process the payment. Override this in your gateway. When implemented, this should.
         * return the success and redirect in an array. e.g:
         *
         *        return array(
         *            'result'   => 'success',
         *            'redirect' => $this->get_return_url( $order )
         *        );
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('on-hold', _x('Awaiting check payment', 'Check payment method', 'woocommerce'));

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        private function _create_invoice($order)
        {
            $data = [
                'shopID' => (int)$this->get_option('shop_id'),
                'amount' => $this->_prepare_amount($order->order_total),
                'metadata' => $this->_prepare_metadata($order),
                'dueDate' => $this->_prepare_due_date(),
                'currency' => get_woocommerce_currency(),
                'product' => '' . $order->id . '',
                'description' => '',
            ];

            $url = $this->_prepare_api_url('processing/invoices');
            $response = $this->send($url, $this->_get_headers(), json_encode($data));

            if ($response['http_code'] != 201) {
                $message = 'An error occurred while creating invoice';
                throw new Exception($message);
            }

            $response_decode = json_decode($response['body'], true);
            $invoice_id = !empty($response_decode['id']) ? $response_decode['id'] : '';
            return $invoice_id;
        }

        private function _create_access_token($invoice_id)
        {
            if (empty($invoice_id)) {
                throw new Exception('An error occurred while creating invoice');
            }

            $url = $this->_prepare_api_url('processing/invoices/' . $invoice_id . '/access_tokens');
            $response = $this->send($url, $this->_get_headers());

            if ($response['http_code'] != 201) {
                throw new Exception('An error occurred while creating Invoice Access Token');
            }
            $response_decode = json_decode($response['body'], true);
            $access_token = !empty($response_decode['payload']) ? $response_decode['payload'] : '';
            return $access_token;
        }

        private function send($url, $headers = [], $data = '')
        {
            $logs = array(
                'url' => $url,
                'headers' => $headers,
                'data' => $data,
            );
            $message = '';

            if (empty($url)) {
                $message = 'Не передан обязательный параметр url';
                $this->log($message . print_r($logs, true), 'error');
                throw new Exception($message);
            }

            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_POST, TRUE);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            $body = curl_exec($curl);
            $info = curl_getinfo($curl);
            $curl_errno = curl_errno($curl);

            $response['http_code'] = $info['http_code'];
            $response['body'] = $body;
            $response['error'] = $curl_errno;

            curl_close($curl);

            $logs['response'] = $response;
            $this->log($message . print_r($logs, true));

            return $response;
        }

        private function _prepare_api_url($path = '', $query_params = [])
        {
            $url = rtrim(static::API_URL, '/') . '/' . $path;
            if (!empty($query_params)) {
                $url .= '?' . http_build_query($query_params);
            }
            return $url;
        }

        private function _prepare_amount($amount)
        {
            return $amount * 100;
        }

        private function _prepare_metadata($order)
        {
            return [
                'cms' => 'wordpress',
                'module' => 'wp-woo-commerce',
                'plugin' => 'rbkmoney_payment',
                'version' => WC()->version,
                'order_id' => $order->id,
            ];
        }

        private function _prepare_due_date()
        {
            date_default_timezone_set('UTC');
            return date(static::CREATE_INVOICE_TEMPLATE_DUE_DATE, strtotime(static::CREATE_INVOICE_DUE_DATE));
        }

        private function _get_headers()
        {
            $headers = [];
            $headers[] = 'X-Request-ID: ' . uniqid();
            $headers[] = 'Authorization: Bearer ' . $this->get_option('private_key');
            $headers[] = 'Content-type: application/json; charset=utf-8';
            $headers[] = 'Accept: application/json';
            return $headers;
        }

        public function url_safe_b64decode($string)
        {
            $data = str_replace(array('-', '_'), array('+', '/'), $string);
            $mod4 = strlen($data) % 4;
            if ($mod4) {
                $data .= substr('====', $mod4);
            }
            return base64_decode($data);
        }

        public function get_parameters_content_signature($content_signature)
        {
            preg_match_all(static::SIGNATURE_PATTERN, $content_signature, $matches, PREG_PATTERN_ORDER);
            $params = array();
            $params[static::SIGNATURE_ALG] = !empty($matches[1][0]) ? $matches[1][0] : '';
            $params[static::SIGNATURE_DIGEST] = !empty($matches[2][0]) ? $matches[2][0] : '';
            return $params;
        }

        public function verification_signature($data, $signature, $public_key)
        {
            if (empty($data) || empty($signature) || empty($public_key)) {
                return FALSE;
            }
            $public_key_id = openssl_get_publickey($public_key);
            if (empty($public_key_id)) {
                return FALSE;
            }
            $verify = openssl_verify($data, $signature, $public_key_id, OPENSSL_ALGO_SHA256);
            return ($verify == 1);
        }

        public static function log($message, $level = 'info')
        {
            if ( self::$log_enabled ) {
                if ( empty( self::$log ) ) {
                    self::$log = new WC_Logger();
                }
                self::$log->add( static::GATEWAY_NAME, $message );
            }
        }

    }

    /**
     * Add Gateway class to all payment gateway methods
     */
    function add_rbkmoney_gateway($methods)
    {
        $methods[] = 'WC_Gateway_RBKmoney';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_rbkmoney_gateway');

}

?>
