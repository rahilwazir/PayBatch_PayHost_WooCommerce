<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 */

class payhostpaybatch_tokens
{
    public static function createToken( $gateway_id, $token, $user_id )
    {
        global $wpdb;

        $query = "insert into {$wpdb->prefix}woocommerce_payment_tokens (gateway_id, token, user_id, `type`, is_default) values (%s, %s, %d, 'cc', 0 )";
        $wpdb->query(
            $wpdb->prepare( $query, $gateway_id, $token, $user_id )
        );
    }

    public static function updateToken( $token, $token_id )
    {
        global $wpdb;

        $query = "update {$wpdb->prefix}woocommerce_payment_tokens set token = %s where token_id = %d";
        $wpdb->query(
            $wpdb->prepare( $query, $token, $token_id )
        );
    }

    public static function getTokens( $user_id, $gateway_id )
    {
        return WC_Payment_Tokens::get_tokens( ['user_id' => $user_id, 'gateway_id' => $gateway_id] );
    }

    public static function deleteToken( $token )
    {
        $token->delete();

    }
}
