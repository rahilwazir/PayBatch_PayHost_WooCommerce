<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * Script that runs to find all subscriptions due and then process them to PayBatch for payment
 */

/** Check whether running from the command line */
if(php_sapi_name() !== 'cli'){
    die('This cannot be accessed from the browser');
}

/** Loads the WordPress Environment and Template */
$doc_root = dirname( __FILE__, 5 );
$classes  = dirname( __FILE__, 2 ) . '/classes';

$_SERVER['REQUEST_METHOD'] = 'batch'; //Work-around to satisfy template-loader.php
require_once $doc_root . '/wp-blog-header.php';

require_once $classes . '/constants.php';
require_once $classes . '/paybatchsoap.class.php';
require_once $classes . '/payhostpaybatch_tokens.class.php';
require_once $classes . '/payhostpaybatch.class.php';

$gateway_id   = 'payhostpaybatch';
$vaultPattern = '/^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}$/';
$today        = new DateTime();
if ( isset($argv) && count( $argv ) > 1 ) {
    foreach ( $argv as $value ) {
        if ( strpos( $value, 'date' ) !== false ) {
            $today = new DateTime( substr( $value, 5 ) );
        }
    }
}

$payhostpaybatch   = new WC_Gateway_Payhostpaybatch();
$payBatchId        = $payhostpaybatch->getPaybatchId();
$payBatchSecretKey = $payhostpaybatch->getPaybatchKey();

$notify_url = add_query_arg( 'wc-api', 'WC_Gateway_Payhostpaybatch_Paybatch_Notify', home_url( '/' ) );

$finished = false;
$offset   = 0;
$pagelen  = 10;
$orders   = [];

/**
 * Check that WC Subscriptions is enabled and also that recurring is enabled for the gateway
 */
if ( $payhostpaybatch->settings['disable_recurring'] != 'yes' && $payhostpaybatch->settings['vaulting'] === 'yes' ) {
    if ( !function_exists( 'wcs_get_subscriptions' ) ) {
        die( 'WC Subscriptions are not enabled' );
    }

    do {
        $partorders = wc_get_orders( [
            'payment_method' => 'payhostpaybatch',
            'offset'         => $offset,
            'limit'          => $pagelen,
            'paginate'       => true,
        ] );
        foreach ( $partorders->orders as $order ) {
            $orders[] = $order;
        }
        $offset += $pagelen;
        if ( $offset > $partorders->total ) {
            $finished = true;
        }
    } while ( $finished == false );

    $data    = [];
    $vaultId = '';

    foreach ( $orders as $order ) {
        $subscriptions = wcs_get_subscriptions( array(
            'order_id'            => $order->get_id(),
            'subscription_status' => array( 'active' ),
        )
        );
        foreach ( $subscriptions as $subscription ) {
            $status = $subscription->get_status();
            if ( $status !== 'cancelled' ) {
                $customer_id      = $order->get_customer_id();
                $order_id         = $order->get_id();
                $order_key        = $order->get_order_key();
                $subscription_id  = $subscription->get_id();
                $subscription_key = $subscription->get_order_key();
                $tokens           = payhostpaybatch_tokens::getTokens( $customer_id, $gateway_id );
                foreach ( $tokens as $token ) {
                    $vaultId = $token->get_token();
                    if ( preg_match( $vaultPattern, $vaultId ) != 1 ) {
                        $vaultId = false;
                    }
                }

                if (  ( $subscription->get_date( 'next_payment' ) ) ) {
                    $next_payment = new DateTime( substr( $subscription->get_date( 'next_payment' ), 0, 10 ) );
                    if ( $vaultId && $next_payment <= $today ) {
                        $payment_amount = $subscription->get_total();
                        $currency       = $order->get_currency();
                        $payment_amount = (int) ( 100 * convertToZar( $payment_amount, $currency ) );
                        $batch_line     = [
                            'A',
                            $order_id . '_' . $order_key,
                            $order->get_billing_first_name() . '_' . $order->get_billing_last_name(),
                            $vaultId,
                            '00',
                            $payment_amount,
                        ];
                    }
                }
                if ( isset( $batch_line ) ) {
                    $data[] = $batch_line;
                }
            }
        }
    }

    if ( count( $data ) > 0 ) {
        $payBatchSoap = new paybatchsoap( $notify_url );
        $errors       = false;
        $invalids     = true;

        while ( !$errors && $invalids && count( $data ) > 0 ) {
            try {
                // Make PayBatch authorisation request.
                $soap    = $payBatchSoap->getAuthRequest( $data );
                $wsdl    = PAYBATCHAPIWSDL;
                $options = ['trace' => 1, 'login' => $payBatchId, 'password' => $payBatchSecretKey];

                $soapClient = new SoapClient( $wsdl, $options );
                $result     = $soapClient->__soapCall( 'Auth', [
                    new SoapVar( $soap, XSD_ANYXML ),
                ] );
                if ( $result->Invalid == 0 ) {
                    $invalids = false;
                    $uploadId = $result->UploadID;
                    // Now make confirmation request to trigger actual payment attempt.
                    $confirmXml    = $payBatchSoap->getConfirmRequest( $uploadId );
                    $confirmResult = $soapClient->__soapCall( 'Confirm', [
                        new SoapVar( $confirmXml, XSD_ANYXML ),
                    ] );
                    if ( $confirmResult->Invalid != 0 ) {
                        $errors = true;
                    }
                } else {
                    foreach ( $result->InvalidReason as $invalid ) {
                        unset( $data[$invalid->Line - 1] );
                    }
                }
            } catch ( SoapFault $fault ) {
                $errors = true;
                echo $fault->getMessage();
            }
        }

        if ( $errors ) {
            // Log and die.
            die( 'Could not process batch transaction' );
        }

        // Store the upload ids so we can process later.
        try {
            $post_data = [
                'post_name'    => 'payhostpaybatch_paybatch_record',
                'post_title'   => $uploadId,
                'post_content' => json_encode( $data ),
            ];

            wp_insert_post( $post_data );
        } catch ( Exception $e ) {
            die( $e->getMessage() );
        }
        die( count( $data ) . ' invoices were successfully uploaded to PayGate PayBatch for processing' );
    } else {
        die( 'No matching invoices found!' );
    }
} else {
    die( 'Recurring and / or vaulting not enabled for the Gateway ' );
}

function convertToZar( $amount, $currency )
{
    if ( $currency === 'ZAR' ) {
        return $amount;
    }
    $allowed_currencies = ['ZAR', 'USD', 'EUR', 'GBP'];
    if ( in_array( $currency, $allowed_currencies ) ) {
        $url   = "https://api.exchangeratesapi.io/latest?base=" . $currency . "&symbols=ZAR";
        $rates = file_get_contents( $url );
        $rate  = (float) json_decode( $rates, true )['rates']['ZAR'];

        return $amount * $rate;
    } else {
        throw new Exception( 'Invalid exchange rate used' );
    }
}
