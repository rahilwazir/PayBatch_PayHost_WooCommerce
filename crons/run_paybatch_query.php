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

$doc_root                  = dirname( __FILE__, 5 );
$_SERVER['REQUEST_METHOD'] = 'batch';
require_once $doc_root . '/wp-blog-header.php';
require_once '../classes/constants.php';
require_once '../classes/paybatchsoap.class.php';
require_once '../classes/payhostpaybatch_tokens.class.php';
require_once '../classes/payhostpaybatch.class.php';
$gateway_id        = 'payhostpaybatch';
$vaultPattern      = '/^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}$/';
$payhostpaybatch   = new WC_Gateway_Payhostpaybatch();
$payBatchId        = $payhostpaybatch->getPaybatchId();
$payBatchSecretKey = $payhostpaybatch->getPaybatchKey();
$payBatchSoap      = new paybatchsoap( '' );
$batches           = [];
try {
    $posts = get_batches();
    foreach ( $posts as $post ) {
        $batches[] = $post->post_title;
    }
    $cnt = 0;
    if (  ( $nbatches = count( $batches ) ) > 0 ) {
        foreach ( $batches as $key => $batch ) {
            $queryResult = doPayBatchQuery( $batch, $payBatchId, $payBatchSecretKey, $payBatchSoap ); // Data for testing only.
            if ( $queryResult ) {
                if ( !empty( $queryResult->TransResult ) ) {
                    if ( !is_array( $queryResult->TransResult ) ) {
                        // Only single result.
                        handleLineItem( $queryResult->TransResult, $gateway_id );
                    } else {
                        foreach ( $queryResult->TransResult as $transResult ) {
                            handleLineItem( $transResult, $gateway_id );
                        }
                    }
                    wp_delete_post( $posts[$cnt]->ID );
                } else {
                    if ( isset( $queryResult->DateCompleted ) ) {
                        echo 'Batch: ' . $queryResult->Reference . ' is ' . $queryResult->DateCompleted;
                    }
                }
            }
            wp_delete_post( $posts[$cnt]->ID );
            $cnt++;
        }
        die( PHP_EOL . $nbatches . ' PayGate PayBatch batches were queried for payment information and processed' );
    } else {
        die( 'No PayGate PayBatch batches were found for processing' );
    }
} catch ( Exception $e ) {
    die( $e->getMessage() );
}
function handleLineItem( $transResult, $gateway_id )
{
    $transResult = explode( ',', $transResult );
    $headings    = [
        'txId',
        'txType',
        'txRef',
        'authcode',
        'txStatusCode',
        'txStatusDescription',
        'txResultCode',
        'txResultDescription',
    ];
    $transResult = array_combine( $headings, $transResult );
    $orderId     = explode( '_', $transResult['txRef'] )[0];
    $order       = wc_get_order( $orderId );
    if ( $transResult['txStatusCode'] === '1' && $transResult['txStatusDescription'] === 'Approved' ) {
        $order->payment_complete();
        $order->add_order_note( __( 'Response via PayBatch Query, Transaction successful', 'woocommerce' ) );
    } else {
        $order->add_order_note( __( 'Response via PayBatch Query, Transaction not successful', 'woocommerce' ) );
    }
}

function doPayBatchQuery( $uploadId, $payBatchId, $payBatchSecretKey, $payBatchSoap )
{
    $queryXml    = $payBatchSoap->getQueryRequest( $uploadId );
    $wsdl        = PAYBATCHAPIWSDL;
    $options     = ['trace' => 1, 'login' => $payBatchId, 'password' => $payBatchSecretKey];
    $soapClient  = new SoapClient( $wsdl, $options );
    $queryResult = $soapClient->__soapCall( 'Query', [new SoapVar( $queryXml, XSD_ANYXML )] );

    return $queryResult;
}

function get_batches()
{
    global $wpdb;
    $query   = "select ID, post_title from {$wpdb->prefix}posts where post_name = 'payhostpaybatch_paybatch_record'";
    $batches = $wpdb->get_results( $query );

    return $batches;
}
