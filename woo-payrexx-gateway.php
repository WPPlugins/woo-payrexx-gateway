<?php
/**
 * Plugin Name: WooCommerce Payrexx Gateway
 * Description: Accept many different payment methods on your store using Payrexx
 * Author: Payrexx
 * Author URI: https://payrexx.com
 * Version: 1.0.0
 */

global $wpdb;

// Make sure WooCommerce is active

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

add_action('plugins_loaded', 'wc_offline_gateway_init', 11);

function wc_offline_gateway_init()
{
    class WC_Payrexx_Gateway extends WC_Payment_Gateway
    {
        public $enabled;
        public $title;
        public $instance;
        public $sid;
        public $logos;

        //__construct

        public function __construct()
        {
            $this->id = 'payrexx';
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->instance = $this->get_option('instance');
            $this->sid = $this->get_option('sid');
            $this->logos = $this->get_option('logos');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_payrexx_gateway', array($this, 'check_webhook_response'));
        }

        //get_icon

        public function get_icon()
        {
            $style = version_compare(WC()->version, '2.6', '>=') ? 'style="margin-left: 0.3em"' : '';

            $icon = '';
            foreach ($this->logos as $logo) {
                $icon .= '<img src="' . WC_HTTPS::force_https_url(plugins_url() . '/woocommerce-payrexx-gateway/cardicons/card_' . $logo . '.svg') . '" alt="' . $logo . '" width="32" ' . $style . ' />';
            }

            return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $this->form_fields = include('includes/settings-payrexx.php');
        }

        //process_payment

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', __('Awaiting offline payment', 'wc-payrexx-gateway'));
            // Reduce stock levels
            $order->reduce_order_stock();
            // Remove cart
            WC()->cart->empty_cart();
            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_payrexx_gateway($order)
            );
        }

        public function payment_scripts()
        {
            if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order']) && !is_add_payment_method_page()) {
                return;
            }
        }

        public function check_webhook_response()
        {
            $resp = $_REQUEST;
            $order_id = $resp['transaction']['invoice']['referenceId'];
            $order = new WC_Order($order_id);

            //waiting payment
            if (isset($resp['transaction']['status']) && $resp['transaction']['status'] == "waiting") {
//                add_post_meta($resp['transaction']['invoice']['referenceId'], "payrexx_response_waiting", json_encode($_REQUEST));
            }

            //Confirmed payment
            if (isset($resp['transaction']['status']) && $resp['transaction']['status'] == "confirmed") {
                $order->payment_complete();
//                add_post_meta($resp['transaction']['invoice']['referenceId'], "payrexx_response_completed", json_encode($_REQUEST));
            }
        }

        public function get_payrexx_gateway($order_id)
        {
            global $wp;
            $order = new WC_Order($order_id);
            if ($order->status != 'on-hold') {
                return;
            }
            $amount = floatval($order->get_total());
            $currency = get_woocommerce_currency();

            $customer = new WC_Customer($order->id);
            $postcode = $customer->postcode;
            $city = $customer->city;
            $address_1 = $customer->address_1;
            $country = $customer->country;
            $first_name = $order->billing_first_name;
            $last_name = $order->billing_last_name;
            $company = $order->billing_company;
            $phone = $order->billing_phone;
            $email = $order->billing_email;

            spl_autoload_register(function ($class) {
                $root = __DIR__ . '/payrexx-php-master';
                $classFile = $root . '/lib/' . str_replace('\\', '/', $class) . '.php';
                if (file_exists($classFile)) {
                    require_once $classFile;
                }
            });
        // $instanceName is a part of the url where you access your payrexx installation.
        //https://{$instanceName}.payrexx.com
            $settings = get_option("woocommerce_payrexx_settings");
        // $secret is the payrexx secret for the communication between the applications
        // if you think someone got your secret, just regenerate it in the payrexx administration
            $instanceName = $settings['instance'];
            $secret = $settings['sid'];
            $payrexx = new \Payrexx\Payrexx($instanceName, $secret);

            $gateway = new \Payrexx\Models\Request\Gateway();

            $am = $amount;
            $gateway->setAmount($am * 100);

            if ($currency == "") {
                $currency = "USD";
            }
            $gateway->setCurrency($currency);

            $gateway->setSuccessRedirectUrl($this->get_return_url($order));
            $gateway->setFailedRedirectUrl(get_home_url());
            $gateway->setPsp([]);

            $gateway->setReferenceId($order->id);

            $gateway->addField($type = 'title', $value = '');
            $gateway->addField($type = 'forename', $value = $first_name);
            $gateway->addField($type = 'surname', $value = $last_name);
            $gateway->addField($type = 'company', $value = $company);
            $gateway->addField($type = 'street', $value = $address_1);
            $gateway->addField($type = 'postcode', $value = $postcode);
            $gateway->addField($type = 'place', $value = $city);
            $gateway->addField($type = 'country', $value = $country);
            $gateway->addField($type = 'phone', $value = $phone);
            $gateway->addField($type = 'email', $value = $email);
            $gateway->addField($type = 'custom_field_1', $value = $order->id, $name = 'WooCommerce ID');

            try {
                $response = $payrexx->create($gateway);
                $language = substr(get_locale(), 0, 2);
                $res = 'https://' . $instanceName . '.payrexx.com/' . $language . '/?payment=' . $response->getHash();
                return $res;
            } catch (\Payrexx\PayrexxException $e) {
                print $e->getMessage();
            }
        }
    }
}

//wc_payrexx_add_to_gateways

function wc_payrexx_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Payrexx_Gateway';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_payrexx_add_to_gateways');
?>