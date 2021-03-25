<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

if ( !defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/**
 * PayGate Payment Gateway - PayHost _ PayBatch
 *
 * Provides a PayGate PayHost / PayBatch Payment Gateway.
 *
 * @class       woocommerce_payhostpaybatch
 * @package     WooCommerce
 * @category    Payment Gateways
 * @author      PayGate
 *
 */

require_once 'constants.php';

spl_autoload_register( function ( $class ) {
    $classes = ['payhostsoap', 'paybatchsoap', 'payhostpaybatch_tokens'];
    if ( in_array( $class, $classes ) ) {
        require_once plugin_basename( $class . '.class.php' );
    }
} );

class WC_Gateway_Payhostpaybatch extends WC_Payment_Gateway
{

    protected static $_instance = null;
    private static $log;

    public $version = '1.0.1';

    public $id = 'payhostpaybatch';

    private $payhost_id;
    private $payhost_key;
    private $paybatch_id;
    private $paybatch_key;
    private $testmode;
    private $disable_recurring = 'no';
    private $vaulting          = true;
    private $vaultPattern      = '/^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}$/';
    private $process_url;
    private $redirect_url;
    private $msg;
    private $enable_iframe   = 'yes';
    private $recurring_error = false;

    private $woocommerce_subscriptions_active = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->process_url        = payhostsoap::$process_url;
        $this->method_title       = __( 'PayGate using PayHost / PayBatch', 'payhostpaybatch' );
        $this->method_description = __( 'PayGate using PayHost / PayBatch works by sending the customer to PayHost to complete their initial payment, whereafter PayBatch is used to process repeat payments', 'payhostpaybatch' );
        $this->icon               = PAYHOSTPAYBATCH_PLUGIN_URL . '/assets/images/logo_small.png';
        $this->has_fields         = true;
        $this->supports           = array(
            'products',
            'tokenization',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
        );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        $this->woocommerce_subscriptions_active = get_option( 'woocommerce_subscriptions_is_active' ) === '1' ? true : false;

        // Define variables test or not.
        if ( isset( $this->settings['testmode'] ) && $this->settings['testmode'] == 'yes' ) {
            $this->testmode     = true;
            $this->payhost_id   = PAYGATETESTID;
            $this->payhost_key  = PAYGATETESTKEY;
            $this->paybatch_id  = PAYGATETESTID;
            $this->paybatch_key = PAYGATETESTKEY;
            $this->add_testmode_admin_settings_notice();
        } else {
            $this->testmode     = false;
            $this->payhost_id   = $this->settings['payhost_id'];
            $this->payhost_key  = $this->settings['payhost_key'];
            $this->paybatch_id  = $this->settings['paybatch_id'];
            $this->paybatch_key = $this->settings['paybatch_key'];
        }

        if ( isset( $this->settings['title'] ) ) {
            $this->title = $this->settings['title'];
        } else {
            $this->title = 'PayGate PayHost / PayBatch Gateway';
        }

        if ( isset( $this->settings['button_text'] ) ) {
            $this->order_button_text = $this->settings['button_text'];
        }

        if ( isset( $this->settings['description'] ) ) {
            $this->description = $this->settings['description'];
        } else {
            $this->description = 'PayGate PayHost/PayBatch Gateway';
        }

        if ( isset( $this->settings['vaulting'] ) && $this->settings['vaulting'] != 'yes' ) {
            $this->vaulting = false;
        } else {
            $this->vaulting = true;
        }

        if ( isset( $this->settings['enable_iframe'] ) ) {
            $this->enable_iframe = $this->settings['enable_iframe'] != 'no' ? 'yes' : 'no';
        }

        if ( isset( $this->settings['disable_recurring'] ) ) {
            $this->disable_recurring = $this->settings['disable_recurring'] != 'yes' ? 'no' : 'yes';
        }

        $this->redirect_url = add_query_arg( 'wc-api', 'WC_Gateway_Payhostpaybatch_Redirect', home_url( '/' ) );

        add_action( 'woocommerce_api_wc_gateway_payhostpaybatch_redirect', array(
            $this,
            'check_payhostpaybatch_response',
        ) );

        add_action( 'wp_ajax_order_pay_payment', array( $this, 'process_review_payment' ) );
        add_action( 'wp_ajax_nopriv_order_pay_payment', array( $this, 'process_review_payment' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'payhostpaybatch_payment_scripts' ) );

        if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options',
            ) );
        } else {
            add_action( 'woocommerce_update_options_payment_gateways', array(
                $this,
                'process_admin_options',
            ) );
        }

        add_action( 'woocommerce_receipt_payhostpaybatch', array(
            $this,
            'receipt_page',
        ) );

        add_action( 'woocommerce_scheduled_subscription_payment', [ $this, 'payhostpaybatch_process_paybatch' ] );
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @since 1.0.0
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'           => array(
                'title'       => __( 'Enable/Disable', 'payhostpaybatch' ),
                'label'       => __( 'Enable PayHost / PayBatch Payment Gateway', 'payhostpaybatch' ),
                'type'        => 'checkbox',
                'description' => __( 'This controls whether or not this gateway is enabled within WooCommerce.', 'payhostpaybatch' ),
                'desc_tip'    => true,
                'default'     => 'no',
            ),
            'title'             => array(
                'title'       => __( 'Title', 'woocommerce_gateway_payhostpaybatch' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce_gateway_payhostpaybatch' ),
                'desc_tip'    => false,
                'default'     => 'PayGate PayHost_PayBatch Gateway',
                'woocommerce_gateway_payhostpaybatch',
            ),
            'payhost_id'        => array(
                'title'       => __( 'PayHost ID', 'payhostpaybatch' ),
                'type'        => 'text',
                'description' => __( 'This is the PayGate PayHost ID, received from PayGate.', 'payhostpaybatch' ),
                'desc_tip'    => false,
                'default'     => '',
            ),
            'payhost_key'       => array(
                'title'       => __( 'PayHost Secret Key', 'payhostpaybatch' ),
                'type'        => 'text',
                'description' => __( 'This is the PayHost Secret Key set in the PayGate Back Office.', 'payhostpaybatch' ),
                'desc_tip'    => false,
                'default'     => '',
            ),
            'paybatch_id'       => array(
                'title'       => __( 'PayBatch ID', 'payhostpaybatch' ),
                'type'        => 'text',
                'description' => __( 'This is the PayGate PayBatch ID, received from PayGate.', 'payhostpaybatch' ),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'paybatch_key'      => array(
                'title'       => __( 'PayBatch Secret Key', 'payhostpaybatch' ),
                'type'        => 'text',
                'description' => __( 'This is the PayBatch Secret Key set in the PayGate Back Office.', 'payhostpaybatch' ),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'testmode'          => array(
                'title'       => __( 'Test Mode', 'payhostpaybatch' ),
                'type'        => 'checkbox',
                'description' => __( 'Uses PayGate test accounts. Request test cards from PayGate', 'payhostpaybatch' ),
                'desc_tip'    => true,
                'default'     => 'yes',
            ),
            'vaulting'          => array(
                'title'       => __( 'Use Card Vaulting', 'payhostpaybatch' ),
                'type'        => 'checkbox',
                'description' => __( 'Enable card vaulting (PayBatch won\'t work without it)', 'payhostpaybatch' ),
                'desc_tip'    => true,
                'default'     => 'yes',
            ),
            'enable_iframe'     => array(
                'title'       => __( 'Enable iFrame', 'payhostpaybatch' ),
                'type'        => 'checkbox',
                'description' => __( 'Enable iFrame checkout. If not selected, will use redirect to payment portal instead.', 'payhostpaybatch' ),
                'desc_tip'    => true,
                'default'     => 'yes',
            ),
            'disable_recurring' => array(
                'title'       => __( 'Disable Recurring / Subscription Payments', 'payhostpaybatch' ),
                'type'        => 'checkbox',
                'description' => __( 'Disable Recurring / Subscription Payments. PayHost will process once-off transactions, but PayBatch will not be available for repeat payments', 'payhostpaybatch' ),
                'desc_tip'    => true,
                'default'     => 'no',
            ),
            'description'       => array(
                'title'       => __( 'Description', 'payhostpaybatch' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'payhostpaybatch' ),
                'default'     => 'Pay via PayHost / PayBatch',
            ),
            'button_text'       => array(
                'title'       => __( 'Order Button Text', 'payhostpaybatch' ),
                'type'        => 'text',
                'description' => __( 'Changes the text that appears on the Place Order button', 'payhostpaybatch' ),
                'default'     => 'Proceed to PayGate PayHost',
            ),
        );

    }

    /**
     * Add a notice to the merchant_key and merchant_id fields when in test mode.
     *
     * @since 1.0.0
     */
    public function add_testmode_admin_settings_notice()
    {
        $this->form_fields['payhost_id']['description'] .= ' <br><br><strong>' . __( 'PayGate ID currently in use.', 'payhostpaybatch' ) . ' ( 10011072130 )</strong>';
        $this->form_fields['payhost_key']['description'] .= ' <br><br><strong>' . __( 'PayGate Encryption Key currently in use.', 'payhostpaybatch' ) . ' ( secret )</strong>';
    }

    public static function instance()
    {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Debug logger
     *
     * @since 1.1.3
     */

    public static function log( $message )
    {

        if ( empty( self::$log ) ) {

            self::$log = new WC_Logger();
        }

        self::$log->add( 'Paysubs', $message );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $message );
        }
    }

    public function getPaybatchId()
    {
        return $this->paybatch_id;
    }

    public function getPaybatchKey()
    {
        return $this->paybatch_key;
    }

    /**
     * @param $renewal_total float - value of order total
     * @param $renewal_order - WC_Order - the renewal order to be paid
     */
    public function payhostpaybatch_process_paybatch( $subscription_id )
    {
        echo 'Renewal order triggered';
    }

    /**
     * Add payment scripts for iFrame support
     *
     * @since 1.0.0
     */
    public function payhostpaybatch_payment_scripts()
    {
        wp_enqueue_script( 'payhostpaybatch-checkout-js', $this->get_plugin_url() . '/assets/js/payhostpaybatch_checkout.js', array(), WC_VERSION, true );
        if ( is_wc_endpoint_url( 'order-pay' ) ) {
            wp_localize_script( 'payhostpaybatch-checkout-js', 'payhostpaybatch_checkout_js', array(
                'order_id' => $this->get_order_id_order_pay(),
            ) );

        } else {
            wp_localize_script( 'payhostpaybatch-checkout-js', 'payhostpaybatch_checkout_js', array(
                'order_id' => 0,
            ) );
        }

        wp_enqueue_style( 'payhostpaybatch-checkout-css', $this->get_plugin_url() . '/assets/css/payhostpaybatch_checkout-03-19.css', array(), WC_VERSION );
    }

    /**
     * Get the plugin URL
     *
     * @since 1.0.0
     */
    public function get_plugin_url()
    {
        if ( isset( $this->plugin_url ) ) {
            return $this->plugin_url;
        }

        if ( is_ssl() ) {
            return $this->plugin_url = str_replace( 'http://', 'https://', WP_PLUGIN_URL ) . "/" . plugin_basename( dirname( dirname( __FILE__ ) ) );
        } else {
            return $this->plugin_url = WP_PLUGIN_URL . "/" . plugin_basename( dirname( dirname( __FILE__ ) ) );
        }
    }

    public function get_order_id_order_pay()
    {
        global $wp;

        // Get the order ID.
        $order_id = absint( $wp->query_vars['order-pay'] );

        if ( empty( $order_id ) || $order_id == 0 ) {
            return;
        }

        // Exit.
        return $order_id;
    }

    /**
     * Show Message.
     *
     * Display message depending on order results.
     *
     * @param $content
     *
     * @return string
     * @since 1.0.0
     *
     */
    public function show_message( $content )
    {
        return '<div class="' . $this->msg['class'] . '">' . $this->msg['message'] . '</div>' . $content;
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title'
     *
     * @since 1.0.0
     */
    public function admin_options()
    {
        ?>
        <h3><?php _e( 'Payhostpaybatch Payment Gateway', 'payhostpaybatch' );?></h3>
        <p><?php printf( __( 'Payhostpaybatch works by sending the user to %sPayGate%s to enter their payment information.', 'payhostpaybatch' ), '<a href="https://www.paygate.co.za/">', '</a>' );?></p>

        <table class="form-table">
			<?php $this->generate_settings_html(); // Generate the HTML For the settings form.
        ?>
        </table><!--/.form-table-->
		<?php
}

    /**
     * Return false to bypass adding Tokenization in "My Account" section
     *
     * @return bool
     */
    public function add_payment_method()
    {
        return false;
    }

    /**
     * There are no payment fields for Payhostpaybatch, but we want to show the description if set
     *
     * @since 1.0.0
     */
    public function payment_fields()
    {
        if ( isset( $this->settings['description'] ) && $this->settings['description'] != '' ) {
            echo wpautop( wptexturize( $this->settings['description'] ) );
        } else {
            echo wpautop( wptexturize( $this->description ) );
        }
    }

    /**
     * Receipt page.
     *
     * Display text and a button to direct the customer to Payhostpaybatch.
     *
     * @param $order
     *
     * @since 1.0.0
     *
     */
    public function receipt_page( $order )
    {
        echo $this->generate_payhostpaybatch_form( $order );
    }

    /**
     * Generate the Payhostpaybatch button link.
     *
     * @param $order_id
     *
     * @return string
     * @since 1.0.0
     *
     */
    public function generate_payhostpaybatch_form( $order_id )
    {
        $order = new WC_Order( $order_id );

        $messageText = esc_js( __( 'Thank you for your order. We are now redirecting you to PayGate to make payment.', 'payhostpaybatch' ) );

        $heading    = __( 'Thank you for your order, please click the button below to pay via PayGate.', 'payhostpaybatch' );
        $buttonText = __( $this->order_button_text, 'payhostpaybatch' );
        $cancelUrl  = esc_url( $order->get_cancel_order_url() );
        $cancelText = __( 'Cancel order &amp; restore cart', 'payhostpaybatch' );

        // Check to see if this is a subscription order.
        // And that WC Subscriptions is loaded
        if ( function_exists( 'wcs_order_contains_subscription' ) ) {
            $subs = wcs_order_contains_subscription( $order );
            if ( $subs ) {
                if ( $this->disable_recurring === 'yes' ) {
                    $this->recurring_error = true;
                }
            }
        }

        if ( $this->recurring_error && $this->enable_iframe != 'yes' ) {
            $message = <<<MESSAGE
We cannot process the order with this payment gateway.
This is a WooCommerce subscription, but you have disabled recurring payments.
Please change this in the payments configuration to enable processing.
MESSAGE;
            $this->add_notice( $message, 'error' );
            $this->recurring_error = true;
            wp_redirect( $order->get_cancel_order_url() );
            exit;
        }

        $data  = $this->fetch_payment_params( $order_id );
        $value = "";
        foreach ( $data as $index => $v ) {
            $value .= '<input type="hidden" name="' . $index . '" value="' . $v . '" />';
        }

        $form = <<<HTML
<p>{$heading}</p>
<form action="{$this->process_url}" method="post" id="payhostpaybatch_payment_form">
    {$value}
    <!-- Button Fallback -->
    <div class="payment_buttons">
        <input type="submit" class="button alt" id="submit_payhostpaybatch_payment_form" value="{$buttonText}" /> <a class="button cancel" href="{$cancelUrl}">{$cancelText}</a>
    </div>
</form>
<script>
jQuery(document).ready(function(){
    jQuery(function(){
        jQuery("body").block({
            message: "{$messageText}",
            overlayCSS: {
                background: "#fff",
                opacity: 0.6
            },
            css: {
                padding:        20,
                textAlign:      "center",
                color:          "#555",
                border:         "3px solid #aaa",
                backgroundColor:"#fff",
                cursor:         "wait"
            }
        });
    });

    jQuery("#submit_payhostpaybatch_payment_form").click();
    jQuery("#submit_payhostpaybatch_payment_form").attr("disabled", true);
});
</script>
HTML;

        return $form;
    }

    /**
     * Add WooCommerce notice
     *
     * @since 1.0.0
     *
     */
    public function add_notice( $message, $notice_type = 'success' )
    {
        // If function should we use?
        if ( function_exists( "wc_add_notice" ) ) {
            // Use the new version of the add_error method.
            wc_add_notice( $message, $notice_type );
        } else {
            // Use the old version.
            $woocommerce->add_error( $message );
        }
    }

    /**
     * Fetch required fields for Payhostpaybatch
     *
     * @since 1.0.1
     */
    public function fetch_payment_params( $order_id )
    {
        $order  = wc_get_order( $order_id );
        $userId = _wp_get_current_user()->ID;

        if ( session_status() === PHP_SESSION_NONE ) {
            session_start();
        }

        // Check to see if the user has a card vaulted for this gateway.
        $ccTokens = payhostpaybatch_tokens::getTokens( $userId, $this->id );
        if ( count( $ccTokens ) == 1 ) {
            foreach ( $ccTokens as $ccToken ) {
                $vaultId = $ccToken->get_token();
            }

            if ( preg_match( $this->vaultPattern, $vaultId ) != 1 ) {
                $vaultId = '';
            }
        } else {
            $vaultId = '';
        }

        // Construct variables for post.
        $data                      = [];
        $data['pgid']              = $this->payhost_id;
        $data['encryptionKey']     = $this->payhost_key;
        $data['reference']         = $order_id;
        $data['amount']            = intval( $order->get_total() * 100 );
        $data['currency']          = $order->get_currency();
        $data['transDate']         = substr( $order->get_date_created(), 0, 19 );
        $data['locale']            = 'en-us';
        $data['firstName']         = $order->get_billing_first_name();
        $data['lastName']          = $order->get_billing_last_name();
        $data['email']             = $order->get_billing_email();
        $data['customerTitle']     = isset( $data['customerTitle'] ) ? $data['customerTitle'] : 'Mr';
        $data['country']           = 'ZAF';
        $data['retUrl']            = $this->redirect_url;
        $data['disable_recurring'] = $this->disable_recurring;

        //Vaulting may be enabled to store cards even if recurring is disabled
        if ( $this->vaulting ) {
            $data['vaulting'] = true;
        }
        if ( $vaultId != '' && $this->vaulting ) {
            $data['vaultId'] = $vaultId;
        }

        // Check to see if this is a subscription order.
        // And that WC Subscriptions is loaded
        if ( function_exists( 'wcs_order_contains_subscription' ) ) {
            $subs = wcs_order_contains_subscription( $order );
            if ( $subs ) {
                if ( $this->disable_recurring === 'yes' ) {
                    $this->recurring_error = true;
                }
            }
        }

        $payhostSoap = new payhostsoap();
        $payhostSoap->setData( $data );
        $xml = $payhostSoap->getSOAP();

        // Use PHP SoapClient to handle request.
        ini_set( 'soap.wsdl_cache', 0 );
        $soapClient = new SoapClient( PAYHOSTAPIWSDL, ['trace' => 1] );

        try {
            $result = $soapClient->__soapCall( 'SinglePayment', [
                new SoapVar( $xml, XSD_ANYXML ),
            ] );

            if ( property_exists( $result->WebPaymentResponse, 'Redirect',  ) ) {
                // Redirect to Payment Portal.
                /** Store order info for response handling */
                update_post_meta(
                    $order_id,
                    'PAYHOST_PAY_REQUEST_ID',
                    $result->WebPaymentResponse->Redirect->UrlParams[1]->value
                );
                update_post_meta(
                    $order_id,
                    'PAYHOST_REFERENCE',
                    $result->WebPaymentResponse->Redirect->UrlParams[2]->value
                );

                // Do redirect.
                // First check that the checksum is valid.
                $d = $result->WebPaymentResponse->Redirect->UrlParams;

                $checkSource = $d[0]->value;
                $checkSource .= $d[1]->value;
                $checkSource .= $d[2]->value;
                $checkSource .= $this->payhost_key;
                $check = md5( $checkSource );
                if ( $check == $d[3]->value ) {
                    $data = [];
                    foreach ( $d as $item ) {
                        $data[$item->key] = $item->value;
                    }
                    $data['enable_iframe']   = $this->enable_iframe;
                    $data['recurring_error'] = $this->recurring_error;

                    return $data;
                }
            }
        } catch ( SoapFault $f ) {
            var_dump( $f );
        }

        return $data;
    }

    /**
     * Process valid Payhostpaybatch Redirect from PayHost portal
     *
     * @since 1.0.0
     */
    public function check_payhostpaybatch_response()
    {
        global $woocommerce;
        global $wpdb;
        $order_id = '';

        if ( session_status() === PHP_SESSION_NONE ) {
            session_start();
        }

        if (isset($_POST['PAY_REQUEST_ID']) && isset($_POST['TRANSACTION_STATUS'])) {
            // Find the post again
            $args  = [
                'post_status' => 'any',
                'post_type' => 'shop_order',
                'meta_query' => [
                    'key'     => 'PAYHOST_PAY_REQUEST_ID',
                    'value'   => $_POST['PAY_REQUEST_ID'],
                    'compare' => '=',
                ],
            ];
            $query = new WP_Query($args);

            $postId    = $query->post->ID;
            $reference = get_post_meta($postId, 'PAYHOST_REFERENCE', true);

            $post_checksum = $_POST['CHECKSUM'];
            unset( $_POST['CHECKSUM'] );
            $check_string = $this->payhost_id . $_POST['PAY_REQUEST_ID'] . $_POST['TRANSACTION_STATUS'] . $reference . $this->payhost_key;
            $check_sum    = md5( $check_string );
            $onote        = 'No token was stored';
            if ( hash_equals( $post_checksum, $check_sum ) ) {
                // Query PayHost to get the vault id
                if ( $_POST['TRANSACTION_STATUS'] == 1 ) {
                    $paysoap  = new payhostsoap();
                    $response = $paysoap->getQuery( $this->payhost_id, $this->payhost_key, $_POST['PAY_REQUEST_ID'] );

                    $token = null;
                    //Response returns null values if vaulting doesn't work
                    if ( is_array( $response ) && $response['token'] ) {
                        $token   = $response['token'];
                        $transId = $response['transactionId'];
                    }

                    if ( preg_match( $this->vaultPattern, $token ) != 1 ) {
                        $token = null;
                    } else {
                        $onote = 'Token ' . $token . ' was stored';
                    }

                    $userId = _wp_get_current_user()->ID;
                    // And store it.
                    $ccTokens = payhostpaybatch_tokens::getTokens( $userId, $this->id );

                    if ( is_array( $ccTokens ) && $token ) {
                        switch ( count( $ccTokens ) ) {
                            case 0:
                                payhostpaybatch_tokens::createToken( $this->id, $token, $userId );
                                break;
                            case 1:
                                foreach ( $ccTokens as $ccToken ) {
                                    $tokenId = $ccToken->get_id();
                                    payhostpaybatch_tokens::updateToken( $token, $tokenId );
                                }
                                break;
                            default:
                                foreach ( $ccTokens as $ccToken ) {
                                    payhostpaybatch_tokens::deleteToken( $ccToken );
                                }
                                payhostpaybatch_tokens::createToken( $this->id, $token, $userId );
                                break;
                        }
                    }
                }

                $order_id = $reference;

                if ( $order_id != '' ) {
                    $order = wc_get_order( $order_id );
                } else {
                    $order = null;
                }

                if ( $_POST['TRANSACTION_STATUS'] == 4 && isset( $order ) ) {
                    $this->add_notice( 'Your order was cancelled by the user.', 'notice' );
                    $order->add_order_note( __( 'Response via Redirect, Transaction cancelled by user', 'woocommerce' ) );
                    $order->add_order_note( __( $onote, 'woocommerce' ) );
                    echo '<script>window.top.location.href="' . $order->get_cancel_order_url() . '";</script>';
                    exit;
                }

                if ( $_POST['TRANSACTION_STATUS'] == 2 && isset( $order ) ) {
                    $this->add_notice( 'Your order was declined by the bank.', 'notice' );
                    $order->add_order_note( __( 'Response via Redirect, Transaction declined by bank', 'woocommerce' ) );
                    $order->add_order_note( __( $onote, 'woocommerce' ) );
                    echo '<script>window.top.location.href="' . $order->get_cancel_order_url() . '";</script>';
                    exit;
                }

                if ( $_POST['TRANSACTION_STATUS'] == 1 && isset( $order ) ) {
                    // Success.
                    $order->payment_complete();
                    $order->add_order_note( __( 'Response via Redirect, Transaction successful', 'woocommerce' ) );
                    $order->add_order_note( __( $onote, 'woocommerce' ) );

                    // Empty the cart.
                    $woocommerce->cart->empty_cart();
                    if ( $this->settings['enable_iframe'] == 'no' ) {
                        wp_redirect( $this->get_return_url( $order ) );
                    } else {
                        $redirect_link = $this->get_return_url( $order );

                        echo '<script>window.top.location.href="' . $redirect_link . '";</script>';
                    }
                    exit;
                } else {
                    if ($_POST['TRANSACTION_STATUS'] == 5 && isset($order)) {
                    // Repeats successfully loaded.
                    $order->payment_complete();
                    $order->add_order_note( __( 'Response via Redirect, Repeat transactions successful', 'woocommerce' ) );
                    $order->add_order_note( __( $onote, 'woocommerce' ) );

                    // Empty the cart.
                    $woocommerce->cart->empty_cart();
                    if ( $this->settings['enable_iframe'] == 'no' ) {
                        wp_redirect( $this->get_return_url( $order ) );
                    } else {
                        $redirect_link = $this->get_return_url( $order );

                        echo '<script>window.top.location.href="' . $redirect_link . '";</script>';
                    }
                    exit;
                } else {
                    $order->add_order_note( 'Response via Redirect, Transaction declined.' . '<br/>' );
                    if ( !$order->has_status( 'failed' ) ) {
                        $order->update_status( 'failed' );
                    }
                    if ( $this->settings['enable_iframe'] == 'no' ) {
                        $this->add_notice( 'Your order was cancelled.', 'notice' );
                        wp_redirect( $order->get_cancel_order_url() );
                    } else {
                        $redirect_link = htmlspecialchars_decode( urldecode( $order->get_cancel_order_url() ) );
                        echo '<script>window.top.location.href="' . $redirect_link . '";</script>';
                    }
                    exit;
                }
            }
        }
        }
        wp_redirect( DOC_ROOT . '/index.php/checkout' );
    }

    public function process_review_payment()
    {
        if ( !empty( $_POST['order_id'] ) ) {
            $this->process_payment( $_POST['order_id'] );
        }
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id
     *
     * @return array
     * @since 1.0.0
     *
     */
    public function process_payment( $order_id )
    {
        $order   = new WC_Order( $order_id );
        $message = <<<MESSAGE
We cannot process the order with this payment gateway.
This is a WooCommerce subscription, but you have disabled recurring payments.
Please change this in the payments configuration to enable processing.
MESSAGE;

        if ( $this->enable_iframe !== 'yes' ) {
            if ( $this->recurring_error ) {
                //Don't process subscription if recurring is disabled in the gateway
                $this->add_notice( $message, 'error' );
                $this->recurring_error = true;
                wp_redirect( $order->get_cancel_order_url() );
                exit;
            }

            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url( true ),
            );
        } else {
            $result               = $this->fetch_payment_params( $order_id );
            $result['processUrl'] = $this->process_url;
            if ( $result['recurring_error'] ) {
                $this->add_notice( $message, 'error' );
                $this->recurring_error = true;
                $result['cancel_url']  = $order->get_cancel_order_url();
            }
            echo json_encode( $result );
            die;
        }
    }

}
