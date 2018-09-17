<?php
/**
 * Plugin Name: Paynow for Easy Digital Downloads
 * Plugin URI: http://www.paynow.co.zw
 * Description: Paynow for Easy Digital Downloads
 * Version: 1.0.0
 * Author: Webdev
 * Author URI: http://www.webdev.co.zw
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

//Define Constants
define('ps_error', 'error');
define('ps_ok','ok');
define('ps_created_but_not_paid','created but not paid');
define('ps_cancelled','cancelled');
define('ps_failed','failed');
define('ps_paid','paid');
define('ps_awaiting_delivery','awaiting delivery');
define('ps_delivered','delivered');
define('ps_awaiting_redirect','awaiting redirect');

// registers this gateway
function pn_edd_register_gateway( $gateways ) {
	$gateways['paynow_gateway'] = array( 'admin_label' => 'Paynow Gateway', 'checkout_label' => __( 'Paynow Gateway', 'pn_edd' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'pn_edd_register_gateway' );
 
/**
 * Paynow Remove CC Form
 *
 * Paynow does not need a CC form, so remove it.
 *
 * @access private
 * @since 1.0
 */
add_action( 'edd_paynow_gateway_cc_form', '__return_false' );

/**
 * Process Paynow Purchase
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @param array   $purchase_data Purchase Data
 * @return void
 */
function edd_process_paynow_purchase( $purchase_data ) {
	global $edd_options;

	// Collect payment data
	$payment_data = array(
		'price'         => $purchase_data['price'],
		'date'          => $purchase_data['date'],
		'user_email'    => $purchase_data['user_email'],
		'purchase_key'  => $purchase_data['purchase_key'],
		'currency'      => edd_get_currency(),
		'downloads'     => $purchase_data['downloads'],
		'user_info'     => $purchase_data['user_info'],
		'cart_details'  => $purchase_data['cart_details'],
		'gateway'       => 'paynow',
		'status'        => 'pending'
	);

	// Record the pending payment
	$payment_id = edd_insert_payment( $payment_data );

	// Check payment
	if ( ! $payment_id ) {
		// Record the error
		edd_record_gateway_error( __( 'Payment Error', 'pn_edd' ), sprintf( __( 'Payment creation failed before sending buyer to Paynow. Payment data: %s', 'pn_edd' ), json_encode( $payment_data ) ), $payment_id );
		// Problems? send back
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	} else {
			// Only send to Paynow if the pending payment is created successfully
			$listener_url = add_query_arg( 
				array(
					'edd-paynow-listener' => 'IPN',
					'payment-id' => $payment_id

				), home_url('/index.php' ) ); //Original
				//), str_replace('/:', ':', home_url(':8080/index.php' )) ); //TODO: this is hacky, but it works, if you find a better approach of putting in the port and still using wordpress functions, improve
			
			// Get the success url
			$return_url = add_query_arg( array(
					'payment-confirmation' => 'paynow',
					'payment-id' => $payment_id
				), get_permalink( $edd_options['success_page'] ) );

			// Get the Paynow redirect uri
			$paynow_redirect = trailingslashit( edd_get_paynow_redirect(false) ) . '?';

			// Setup Paynow arguments
			$MerchantId =       $edd_options['paynow_live_api_id'];
			$MerchantKey =      $edd_options['paynow_live_api_secret_key'];
			$ConfirmUrl =       $listener_url;
			$ReturnUrl =        $return_url;
			$Reference =        'Payment #'.$payment_id;
			$Amount =           $payment_data['price'];
			$AdditionalInfo =   "";
			$Status =           "Message";
			$custEmail = $payment_data['user_email'];

			//set POST variables
			$values = array('resulturl' => $ConfirmUrl,
						'returnurl' => $ReturnUrl,
						'reference' => $Reference,
						'amount' => $Amount,
						'id' => $MerchantId,
						'additionalinfo' => $AdditionalInfo,
						'authemail' => $custEmail,
						'status' => $Status);
						
			$fields_string = CreateMsg($values, $MerchantKey);

			//open connection
			$ch = curl_init();

			$url = $paynow_redirect;
			
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			
			//execute post
			$result = curl_exec($ch);			
			
			if($result) {
				//close connection
				$msg = ParseMsg($result);
				
				//first check status, take appropriate action
				if (strtolower($msg["status"]) == strtolower(ps_error)){
					$error = "Failed with error: " . $msg["Error"];
					// Record the error
					edd_record_gateway_error( __( 'Payment Error', 'pn_edd' ), sprintf( __( 'Payment creation failed before sending buyer to Paynow. Payment data: %s', 'pn_edd' ), json_encode( $payment_data ) ), $payment_id );
					// Problems? send back
					edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
				}
				else if (strtolower($msg["status"]) == strtolower(ps_ok)){
								
					//second, check hash
					$validateHash = CreateHash($msg, $MerchantKey);
					if($validateHash != $msg["hash"]){
						$error =  "Paynow reply hashes do not match : " . $validateHash . " - " . $msg["hash"];
					}
					else
					{
					
						$theProcessUrl = $msg["browserurl"];

						$payment_meta = get_post_meta( $payment_id, '_edd_payment_meta', true );
						$payment_meta['BrowserUrl'] = $msg["browserurl"];
						$payment_meta['PollUrl'] = $msg["pollurl"];
						$payment_meta['PaynowReference'] = $msg["paynowreference"];
						$payment_meta['Amount'] = $msg["amount"];
						$payment_meta['Status'] = "Sent to Paynow";
						update_post_meta( $payment_id, '_edd_payment_meta', $payment_meta );
					}
				}
				else {						
					//unknown status
					$error =  "Invalid status in from Paynow, cannot continue.";
				}

			}
			else
			{
			   $error = curl_error($ch);
			}

			curl_close($ch);
		}
		
		//TODO: This is where the error is
		if(isset($error))
		{	
			edd_record_gateway_error( __( 'Payment Error', 'pn_edd' ), sprintf( __( 'Payment creation failed before sending buyer to Paynow. Payment data: %s', 'pn_edd' ), json_encode( $payment_data ) ), $payment_id );
			// Problems? send back
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}
		else
		{ 
			header("Location: $theProcessUrl");
		}		
		
		exit;
}
add_action( 'edd_gateway_paynow_gateway', 'edd_process_paynow_purchase' );

/**
 * Listens for a Paynow IPN requests and then sends to the processing function
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @return void
 */
function edd_listen_for_paynow_ipn() {
	global $edd_options;

	// Regular Paynow IPN
	if ( isset( $_GET['edd-paynow-listener'] ) && $_GET['edd-paynow-listener'] == 'IPN' ) {
		do_action( 'edd_verify_paynow_ipn' );
	}
}
//Originally add_action( 'init', 'edd_listen_for_paynow_ipn' )
add_action( 'init', 'edd_listen_for_paynow_ipn' );

/**
 * Process Paynow IPN
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @return void
 */
function edd_process_paynow_ipn() {
	global $edd_options;

	// Check the request method is POST
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
		return;
	}

	$payment_id = $_GET['payment-id'];

	//first verify payment
	$payment_meta = get_post_meta( $payment_id, '_edd_payment_meta', true );
		
	if($payment_meta)
	{
		//open connection
		$ch = curl_init();

		$url = $payment_meta["PollUrl"];;
		
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 0);
		curl_setopt($ch, CURLOPT_POSTFIELDS, '');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		
		//execute post
		$result = curl_exec($ch);
		
		if($result)
		{
		
			//close connection
			$msg = ParseMsg($result);
			
			$MerchantKey =  $edd_options['paynow_live_api_secret_key'];
			$validateHash = CreateHash($msg, $MerchantKey);
			
			if($validateHash != $msg["hash"]){
				// Record the error
				edd_record_gateway_error( __( 'Payment Error', 'pn_edd' ), sprintf( __( 'Paynow reply hashes do not match : %s - %s', 'pn_edd' ), $validateHash, $msg["hash"] ), $payment_id );
			}
			else
			{
				
				if ( edd_get_payment_gateway( $payment_id ) != 'paynow' ) {
					return; // this isn't a Paynow IPN
				}

				$payment_meta = get_post_meta( $payment_id, '_edd_payment_meta', true );
				$payment_meta['PollUrl'] = $msg["pollurl"];
				$payment_meta['PaynowReference'] = $msg["paynowreference"];
				$payment_meta['Amount'] = $msg["amount"];
				$payment_meta['Status'] = $msg["status"];
				$payment_meta['resulturl'] = $msg["resulturl"];
				update_post_meta( $payment_id, '_edd_payment_meta', $payment_meta );

				if (trim(strtolower($msg["status"])) == ps_created_but_not_paid){
					//keep current state
				}
				else if (trim(strtolower($msg["status"])) == ps_cancelled){
					edd_record_gateway_error( __( 'IPN Error', 'edd' ), sprintf( __( 'Invalid business email in IPN response. IPN data: %s', 'edd' ), json_encode( $data ) ), $payment_id );
					edd_update_payment_status( $payment_id, 'cancelled' );
					edd_insert_payment_note( $payment_id, __( 'Payment cancelled by user.', 'edd' ) );
					return;
				}
				else if (trim(strtolower($msg["status"])) == ps_failed){
					edd_record_gateway_error( __( 'IPN Error', 'edd' ), sprintf( __( 'Invalid business email in IPN response. IPN data: %s', 'edd' ), json_encode( $data ) ), $payment_id );
					edd_update_payment_status( $payment_id, 'failed' );
					edd_insert_payment_note( $payment_id, __( 'Payment failed.', 'edd' ) );
					return;
				}
				else if (trim(strtolower($msg["status"])) == ps_paid || trim(strtolower($msg["status"])) == ps_awaiting_delivery || trim(strtolower($msg["status"])) == ps_delivered){

					edd_insert_payment_note( $payment_id, sprintf( __( 'Paynow Transaction ID: %s', 'edd' ) , $payment_id ) );
					edd_update_payment_status( $payment_id, 'publish' );
				}
				else {

				}
			}
		
		}
	}
	else
	{

	}
	exit;
}
add_action( 'edd_verify_paynow_ipn', 'edd_process_paynow_ipn' );

/**
 * Get Paynow Redirect
 *
 * @since 1.0.8.2
 * @global $edd_options Array of all the EDD Options
 * @param bool    $ssl_check Is SSL?
 * @return string
 */
function edd_get_paynow_redirect( $ssl_check = false ) {
	global $edd_options;

	if ( is_ssl() || $ssl_check ) {
		$protocol = 'https://';
	} else {
		$protocol = 'http://';
	}

	//$paynow_uri = $protocol . 'www.paynow.co.zw/interface/initiatetransaction';
	$paynow_uri = 'https://www.paynow.co.zw/interface/initiatetransaction';
	
	return apply_filters( 'edd_paynow_uri', $paynow_uri );
}

/**
 * Set the Page Style for Paynow Purchase page
 *
 * @since 1.4.1
 * @global $edd_options Array of all the EDD Options
 * @return string
 */
function edd_get_paynow_page_style() {
	global $edd_options;

	$page_style = 'Paynow';

	if ( isset( $edd_options['paynow_page_style'] ) )
		$page_style = trim( $edd_options['paynow_page_style'] );

	return apply_filters( 'edd_paynow_page_style', $page_style );
}

/**
 * Shows "Purchase Processing" message for Paynow payments are still pending on site return
 *
 * This helps address the Race Condition, as detailed in issue #1839
 *
 * @since 1.9
 * @return string
 */
function edd_paynow_success_page_content( $content ) {

	if ( ! isset( $_GET['payment-id'] ) && ! edd_get_purchase_session() ) {
		return $content;
	}

	$payment_id = isset( $_GET['payment-id'] ) ? absint( $_GET['payment-id'] ) : false;

	if ( ! $payment_id ) {
		$session    = edd_get_purchase_session();
		$payment_id = edd_get_purchase_id_by_key( $session['purchase_key'] );
	}

	$payment_id = get_post( $payment_id );

	if ( $payment_id && 'pending' == $payment->post_status ) {

		// Payment is still pending so show processing indicator to fix the Race Condition, issue #
		ob_start();

		edd_get_template_part( 'payment', 'processing' );

		$content = ob_get_clean();

	}

	return $content;

}
add_filter( 'edd_payment_confirm_paynow', 'edd_paynow_success_page_content' );

// adds the settings to the Payment Gateways section
function pn_edd_add_settings( $settings ) {

	$paynow_gateway_settings = array(
		array(
			'id' => 'paynow_gateway_settings',
			'name' => '<strong>' . __( 'Paynow Gateway Settings', 'pw_edd' ) . '</strong>',
			'desc' => __( 'Configure the gateway settings', 'pw_edd' ),
			'type' => 'header'
		),
		array(
			'id' => 'paynow_live_api_id',
			'name' => __( 'Merchant API ID', 'pw_edd' ),
			'desc' => __( 'Enter your live API ID, found in your gateway Account Settings', 'pw_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'paynow_live_api_secret_key',
			'name' => __( 'Merchant API Key', 'pw_edd' ),
			'desc' => __( 'Enter your live API key, found in your gateway Account Settings', 'pw_edd' ),
			'type' => 'text',
			'size' => 'regular'
		)
	);

	return array_merge( $settings, $paynow_gateway_settings );
}
add_filter( 'edd_settings_gateways', 'pn_edd_add_settings' );


function ParseMsg($msg) {
	//convert to array data
	$parts = explode("&",$msg);
	$result = array();
	foreach($parts as $i => $value) {
		$bits = explode("=", $value, 2);
		$result[$bits[0]] = urldecode($bits[1]);
	}

	return $result;
}

function UrlIfy($fields) {
	//url-ify the data for the POST
	$delim = "";
	$fields_string = "";
	foreach($fields as $key=>$value) {
		$fields_string .= $delim . $key . '=' . $value;
		$delim = "&";
	}

	return $fields_string;
}

function CreateHash($values, $MerchantKey){
	$string = "";
	foreach($values as $key=>$value) {
		if( strtoupper($key) != "HASH" ){
			$string .= $value;
		}
	}
	$string .= $MerchantKey;
	$hash = hash("sha512", $string);
	return strtoupper($hash);
}

function CreateMsg($values, $MerchantKey){
	$fields = array();
	foreach($values as $key=>$value) {
	   $fields[$key] = urlencode($value);
	}

	$fields["hash"] = urlencode(CreateHash($values, $MerchantKey));

	$fields_string = UrlIfy($fields);
	return $fields_string;
}