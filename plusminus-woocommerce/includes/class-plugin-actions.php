<?php

if (!defined( 'ABSPATH')) {
    die;
}

/**
 * PlusMinus_WooCommerce_Actions
 * The main plugin actions for quantity and price updates.
 */
class PlusMinus_WooCommerce_Actions {

    private $api_url = 'https://plusminus.shop/eshop/api';

    private $login_url = 'https://plusminus.shop/eshop/login';

    private static $_instance;

    /**
     * Start up
     */
    public function __construct() {        
    }    

    public static function instance() {
		if ( ! ( self::$_instance instanceof self ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

    /**
     * Register the actions that the plugin supports. Those actions require a logged-in user.
     */
    public function register_actions() {      
        // Connection test  
        add_action('wp_ajax_pmwoo_test_connection', array($this, 'test_connection') );
        // Manual data update
        add_action('wp_ajax_pmwoo_manual_update', array($this, 'manual_update') );
        // Product import
        add_action('wp_ajax_pmwoo_import_products', array($this, 'manual_import') );
    }

        
    /**
     * Doing an import of the new products from the Plus Minus app into WooCommerce.
     */
    public function manual_import() {
        $result = array('code' => '', 'message' => '');

        if( wp_verify_nonce( $_REQUEST['nonce'], 'plusminus-woocommerce-settings') ){
            $server_response = $this->read_store_data();

            /**
             * Only if the data reading and connection tests are OK (response code 200).
             */
            if (isset($server_response['code']) && $server_response['code'] == '200') {
                $update_result = $this->import_products($server_response['data']);

                if (isset($update_result['count']) && intval($update_result['count']) > 0) {
                    $result = array('code' => '200', 'count' => $update_result['count'], 'message' => sprintf( _n( 'Import process completed. %s product is created.', 'Import process completed. %s products are created.', $update_result['count'], 'plusminus-woocommerce' ), $update_result['count'] ));
                }
                else {
                    $result = array('code' => '200', 'count' => $update_result['count'], 'message' => esc_html__('No products found for import', 'plusminus-woocommerce'), 'log' => $update_result['log']);
                }
            }
            else {
                $result = $server_response;
            }
           
        }
        else {
            $result = array('code' => '500', 'message' => esc_html__('Cannot connect to the server', 'plusminus-woocommerce') );
        }
        
        wp_send_json($result);
    }

    /**
     * Doing a background automatic update of the quantities and prices (only if configured in the settings). The action runs via the WordPress cron jobs.
     */
    public function automatic_update() {
        $result = array('code' => '', 'message' => '');
        PlusMinus_WooCommerce::log('Starting automatic data update');
        $server_response = $this->read_store_data();

        /**
        * Only if the data reading and connection tests are OK (response code 200).
        */
        if (isset($server_response['code']) && $server_response['code'] == '200') {
            $update_result = $this->update_quantity_and_price($server_response['data']);

            if (isset($update_result['count']) && intval($update_result['count']) > 0) {
                $result = array('code' => '200', 'count' => $update_result['count'], 'message' => sprintf( _n( 'Update process completed for %s product.', 'Update process completed for %s products.', $update_result['count'], 'plusminus-woocommerce' ), $update_result['count'] ));
            }
            else {
                $result = array('code' => '200', 'count' => $update_result['count'], 'message' => esc_html__('No products found for update', 'plusminus-woocommerce'), 'log' => $update_result['log']);
            }
        }
        else {
            $result = $server_response;
        }

        PlusMinus_WooCommerce::log('Automatic data update completed = ' . $result['code'] . '|' . $result['message'] );

        return $result;
    }

    /**
     * Do a manual update of the quantities/prices based on the plugin configuration.
     */
    public function manual_update() {
        $result = array('code' => '', 'message' => '');

        if( wp_verify_nonce( $_REQUEST['nonce'], 'plusminus-woocommerce-settings') ){
            PlusMinus_WooCommerce::log('Starting manual data update');
            $server_response = $this->read_store_data();

            /**
            * Only if the data reading and connection tests are OK (response code 200).
            */            
            if (isset($server_response['code']) && $server_response['code'] == '200') {
                $update_result = $this->update_quantity_and_price($server_response['data']);

                if (isset($update_result['count']) && intval($update_result['count']) > 0) {
                    $result = array('code' => '200', 'count' => $update_result['count'], 'message' => sprintf( _n( 'Update process completed for %s product.', 'Update process completed for %s products.', $update_result['count'], 'plusminus-woocommerce' ), $update_result['count'] ));
                }
                else {
                    $result = array('code' => '200', 'count' => $update_result['count'], 'message' => esc_html__('No products found for update', 'plusminus-woocommerce'), 'log' => $update_result['log']);
                }

                PlusMinus_WooCommerce::log('Manual data update completed = ' . $result['code'] . '|' . $result['message'] );
            }
            else {
                $result = $server_response;
                PlusMinus_WooCommerce::log('Manual data update error = ' . $result['code'] . '|' . $result['message'] );
            }
           
        }
        else {
            $result = array('code' => '500', 'message' => esc_html__('Cannot connect to the server', 'plusminus-woocommerce') );
        }
        
        wp_send_json($result);
    }
    
    /**
     * Doing a connection test
     */
    public function test_connection() {
        if( wp_verify_nonce( $_REQUEST['nonce'], 'plusminus-woocommerce-settings') ){
            $result = $this->read_store_data();
            if ($result['code'] == '200' && !isset($result['message'])) {
                $result['message'] = esc_html__('Connection successful', 'plusminus-woocommerce');

                if (isset($result['data'])) {
                    unset($result['data']);
                }
            }
        }
        else {
            $result = array('code' => '500', 'message' => esc_html__('Cannot connect to the server', 'plusminus-woocommerce') );
        }

        PlusMinus_WooCommerce::log('Connection test = ' . $result['code'] . '|' . $result['message']);
        
        wp_send_json($result);
    }
    
    /**
     * Parsing the Plus Minus store data to create new products that did not exist in the WooCommerce
     *
     * @param  object $store_data
     * @return array
     */
    public function import_products($store_data) {
        //
        $options = PlusMinus_WooCommerce_Options::instance()->get_options();
        $store = PlusMinus_WooCommerce_Helpers::object_value($options, 'store_name');

        $update_setup = PlusMinus_WooCommerce_Helpers::get_update_setup($options);

        $count = 0;
        $product_log = array();

        PlusMinus_WooCommerce::log('Starting manual products import');

        if (isset($store_data->$store) && ($update_setup['update_price'] || $update_setup['update_quantity'])) {
            foreach ($store_data->$store as $item => $item_information) {
                $code = isset($item_information->code) ? $item_information->code : '';
                $barcode = isset($item_information->barcode) ? $item_information->barcode : '';

                $lookup_code = $code;
                if (empty($lookup_code)) {
                    $lookup_code = $barcode;
                }

                $quantity = isset($item_information->quantity) ? $item_information->quantity : 0;
                $price_param = $update_setup['price_parameter'];
                $price = isset($item_information->$price_param) ? $item_information->$price_param : '';

                if (empty($lookup_code) || floatval($quantity) == 0 || floatval($price) == 0) {
                    continue;
                }

                PlusMinus_WooCommerce::log(' + Looking for: ' . $lookup_code . ' | item = ' . $item);

                $product_id = wc_get_product_id_by_sku($lookup_code);

                if (empty($product_id)) {
                    $last_splitter = strrpos($item, ":");
                    $new_product_name = "";

                    if ($last_splitter !== false) {
                        $new_product_name = substr($item, $last_splitter + 1);
                    }

                    $new_product = new WC_Product();
                    $new_product->set_name($new_product_name);
                    $new_product->set_sku($lookup_code);
                    $new_product->set_regular_price(floatval($price));
                    $new_product->set_stock_quantity(floatval($quantity));
                    $new_product->set_manage_stock(true);
                    $new_product->save();
                    $count++;

                    PlusMinus_WooCommerce::log(' ++ Creating new product ' . "\n" . $new_product->get_name() . "\n" . 'SKU: ' . $new_product->get_sku() . "\n" . 'Quantity: ' . $quantity. "\n" . 'Price: ' . $price);

                    $product_log[] = array('name' => $new_product->get_name(), 'sku' => $new_product->get_sku());
                }
                else {
                    PlusMinus_WooCommerce::log(' + Product already exists: ' . $lookup_code . ' | item = ' . $item .' | product_id = ' . $product_id);
                }
            }
        }

        PlusMinus_WooCommerce::log('Finished manual products import. Created products = ' . $count);

        return array('count' => $count, 'log' => $product_log);
    }

    /**
     * Parsing the Plus Minus store data to update products' price and/or quantity
     *
     * @param  object $store_data
     * @return array
     */    
    public function update_quantity_and_price($store_data) {
        //
        $options = PlusMinus_WooCommerce_Options::instance()->get_options();
        $store = PlusMinus_WooCommerce_Helpers::object_value($options, 'store_name');

        $update_setup = PlusMinus_WooCommerce_Helpers::get_update_setup($options);

        $count = 0;
        $product_log = array();

        if (isset($store_data->$store) && ($update_setup['update_price'] || $update_setup['update_quantity'])) {
            foreach ($store_data->$store as $item => $item_information) {
                $code = isset($item_information->code) ? $item_information->code : '';
                $barcode = isset($item_information->barcode) ? $item_information->barcode : '';
                $product_id = '';

                if (!empty($code)) {
                    $product_id = wc_get_product_id_by_sku($code);
                }

                if ((!$product_id || empty($product_id)) && !empty($barcode)) {
                    $product_id = wc_get_product_id_by_sku($barcode);
                }

                if ($product_id && !empty($product_id)) {
                    $product = wc_get_product( $product_id );

                    if ($product) {
                        $change_made = false;

                        if ($update_setup['update_quantity']) {
                            $quantity = isset($item_information->quantity) ? $item_information->quantity : 0;
                            $product->set_stock_quantity(floatval($quantity));
                            $change_made = true;
                            $product_log[] = array('product_id' => $product_id, 'product_name' => $product->get_name(), 'parameter' => 'stock_quantity', 'value' => floatval($quantity));
                            PlusMinus_WooCommerce::log('Updating product (id: '.$product_id.') quantity ' . "\n" . $product->get_name() . "\n" . 'SKU: ' . $product->get_sku() . "\n" . 'Quantity: ' . $quantity. "\n");
                        }

                        if ($update_setup['update_price'] && !empty($update_setup['price_parameter'])) {
                            $price_param = $update_setup['price_parameter'];
                            $price = isset($item_information->$price_param) ? $item_information->$price_param : '';
                            if (!empty($price)) {
                                $product->set_regular_price(floatval($price));
                                $product_log[] = array('product_id' => $product_id, 'product_name' => $product->get_name(), 'parameter' => 'regular_price', 'value' => floatval($price));
                                PlusMinus_WooCommerce::log('Updating product (id: '.$product_id.') price ' . "\n" . $product->get_name() . "\n" . 'SKU: ' . $product->get_sku() . "\n" . 'Price: ' . $price. "\n");
                                $change_made = true;
                            }
                        }
                        
                        if ($change_made) {
                            $product->save();
                            $count++;
                        }
                    }
                }
            }
        }

        return array('count' => $count, 'log' => $product_log);
    }
    
    /**
     * Reading store information from the Plus Minus app
     *
     * @return object
     */
    public function read_store_data() {
        $result = array('code' => '', 'message' => '');

        $options = PlusMinus_WooCommerce_Options::instance()->get_options();
        if (!is_array($options)) {
            $options = array();
        }

        $token = PlusMinus_WooCommerce_Helpers::object_value($options, 'private_key');
        $store = PlusMinus_WooCommerce_Helpers::object_value($options, 'store_name');
        $company = PlusMinus_WooCommerce_Helpers::object_value($options, 'company_name');
        $serial_number = PlusMinus_WooCommerce_Helpers::object_value($options, 'serial_number');

        if (empty($token)) {
            $result = array('code' => '401', 'message' => esc_html__('Missing token key', 'plusminus-woocommerce'));
        }
        if (empty($store)) {
            $result = array('code' => '402', 'message' => esc_html__('Missing store name', 'plusminus-woocommerce'));
        }
        if (empty($company)) {
            $result = array('code' => '403', 'message' => esc_html__('Missing company name', 'plusminus-woocommerce'));
        }
        if (empty($serial_number)) {
            $result = array('code' => '404', 'message' => esc_html__('Missing serial number', 'plusminus-woocommerce'));
        }

        if (!empty($token) && !empty($store) && !empty($company) && !empty($serial_number)) {
            $login_response = $this->api_login($serial_number, $token);
            if (strpos('error', $login_response)) {
                $result = array('code' => '405', 'message' => esc_html__('Invalid login details', 'plusminus-woocommerce'));
            }
            else {
                $data = array('items', $company, '{"warehouse":"'.$store.'"}');
                //$response = $this->api_request($token, "['items', '$company', { \"warehouse\" : \"$store\"}]");
                $response = $this->api_request($token, $data);
                //print_r ($response);
                if (!empty($response)) {
                    $error_check = substr($response, 0, 5);
                    if (strtolower($error_check) == 'error') {
                        $result = array('code' => '406', 'message' => esc_html__('Invalid command', 'plusminus-woocommerce'));
                    }
                    else {
                        $response = json_decode($response);
                        if (isset($response->$store)) {
                            $result = array('code' => '200', 'data' => $response);
                        }
                        else {
                            $result = array('code' => '408', 'message' => esc_html__('Provided store was not found on the server', 'plusminus-woocommerce'));
                        }
                    }
                }
                else {
                    $result = array('code' => '407', 'message' => esc_html__('Blank server response', 'plusminus-woocommerce'));
                }
                
            }
        }

        return $result;
    }

    public function api_login($serial, $token) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->login_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array('sn' => $serial, 'token' => $token)));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);
        $result = curl_exec($curl);
        curl_close($curl);
        return $result;
    }

    public function api_request($token, $data) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->api_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization:" . $token
        ]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);
        $result = curl_exec($curl);
        curl_close($curl);

        return $result;
    }
}
