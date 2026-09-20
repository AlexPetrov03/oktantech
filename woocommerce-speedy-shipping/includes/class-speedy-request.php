<?php

defined( 'ABSPATH' ) or exit;

class WS_Speedy_Request {
	public static function call($api_url, $arr_data, $return_array = TRUE) {
        #-> Encode the array into JSON.
        $json_data_encoded = json_encode($arr_data);

        #-> Initiate cURL
        $curl = curl_init($api_url);

        #-> Set curl options
        curl_setopt($curl, CURLOPT_POST, 1); // Tell cURL that we want to send a POST request.
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Verify the peer's SSL certificate.
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Stop showing results on the screen.
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5); // The number of seconds to wait while trying to connect. Use 0 to wait indefinitely.
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); // Set the content type to application/json
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data_encoded); // Attach our encoded JSON string to the POST fields.

        #-> Get the response
        $json_response = curl_exec($curl);
        
        if ($json_response === FALSE) {
            exit("cURL Error: " . curl_error($curl));
        }

        if($return_array) {
            $response = json_decode($json_response, true);    
        } else {
            $response = $json_response;
        }
          
        return $response;
    }

    public static function raw_call($url, $data) {
    	$ch = curl_init();

        curl_setopt_array( $ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode( $data )
        ] );

        curl_setopt( $ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ] );

        $response = curl_exec( $ch );

        curl_close( $ch );

        return $response;
    }

    public static function is_api_error( $response ) {
        if( array_key_exists( 'error', $response ) ) {
            return $response['error']['message'];
        }

        return false;
    }
}