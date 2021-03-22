<?php
/**
 * Plugin Name: PayGate PayBatch (with PayHost tokenization) plugin for WooCommerce
 * Plugin URI: https://github.com/PayGate/PayBatch_PayHost_WooCommerce
 * Description: Accept payments for WooCommerce using PayGate's PayBatch and PayHost services
 * Version: 1.0.1
 * Tested: 5.5.1
 * Author: PayGate (Pty) Ltd
 * Author URI: https://www.paygate.co.za/
 * Developer: App Inlet (Pty) Ltd
 * Developer URI: https://www.appinlet.com/
 *
 * WC requires at least: 3.0
 * WC tested up to: 4.5
 *
 * Copyright: © 2020 PayGate (Pty) Ltd.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

add_action( 'plugins_loaded', 'woocommerce_payhostpaybatch_init', 0 );

/**
 * Initialize the gateway.
 *
 * @since 1.0.0
 */

if ( !defined( 'PAYHOSTPAYBATCH_PLUGIN_URL' ) ) {
    define( 'PAYHOSTPAYBATCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

function woocommerce_payhostpaybatch_init()
{

    if ( !class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }


	define( 'PAYHOST_PAYBATCH_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );

    require_once PAYHOST_PAYBATCH_DIR . '/classes/payhostpaybatch.class.php';
    require_once PAYHOST_PAYBATCH_DIR . '/classes/constants.php';

    add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_payhostpaybatch_gateway' );

    require_once 'classes/updater.class.php';

    if ( is_admin() ) {
        // Note the use of is_admin() to double check that this is happening on the admin backend.

        $config = array(
            'slug'               => plugin_basename( __FILE__ ),
            'proper_folder_name' => 'woocommerce-gateway-payhostpaybatch',
            'api_url'            => 'https://api.github.com/repos/PayGate/PayBatch_PayHost_WooCommerce',
            'raw_url'            => 'https://raw.github.com/PayGate/PayBatch_PayHost_WooCommerce/master',
            'github_url'         => 'https://github.com/PayGate/PayBatch_PayHost_WooCommerce',
            'zip_url'            => 'https://github.com/PayGate/PayBatch_PayHost_WooCommerce/archive/master.zip',
            'homepage'           => 'https://github.com/PayGate/PayBatch_PayHost_WooCommerce',
            'sslverify'          => true,
            'requires'           => '4.0',
            'tested'             => '5.5.1',
            'readme'             => 'README.md',
            'access_token'       => '',
        );

        new WP_GitHub_Updater_PHPB( $config );

    }

} // End woocommerce_payhostpaybatch_init()

/**
 * Add the gateway to WooCommerce
 *
 * @since 1.0.0
 */

function woocommerce_add_payhostpaybatch_gateway( $methods )
{

    $methods[] = 'WC_Gateway_Payhostpaybatch';

    return $methods;

} // End woocommerce_add_payhostpaybatch_gateway()
