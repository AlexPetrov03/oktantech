<?php

if (!defined( 'ABSPATH')) {
    die;
}



/**
 * Making it possible to export orders to the Plus Minus app (and marking orders as exported to prevent reading them multiple times)
 */
class PlusMinus_WooCommerce_Orders_Export {

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
     * Register all required actions, endpoints, and functions.
     */
    public function register_actions() {

        /**
         * Additional columns to the single order screen and order list
         */
        add_action( 'woocommerce_admin_order_data_after_order_details', array($this, 'show_pmdoc_in_order' ) );
        add_filter( 'manage_edit-shop_order_columns', array($this, 'add_pmdoc_column'), 20 );
        add_action( 'manage_shop_order_posts_custom_column', array($this, 'add_pmdoc_column_content') );

        /**
         * Additional actions to clear/manually add Plus Minus document
         */
        add_action('wp_ajax_pmwoo_order_clear_pmdoc', array($this, 'action_clear_pmdoc') );
        add_action('wp_ajax_pmwoo_order_add_pmdoc', array($this, 'action_add_pmdoc') );

        /**
         * Regiser REST API endpoints for orders export
         */
        add_action( 'rest_api_init', function() {
            register_rest_route( 'plusminus-woo/v1', '/get-orders', [
                'method'   => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_orders'),
                'show_in_index' => false,
                'permission_callback' => '__return_true',
                'args' => array(
                    'token'   => array(
                        'description'       => 'Authorization key',
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'date_from'   => array(
                        'description'       => 'Optional: period filter from. Format: yyyy-mm-dd',
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'date_to'   => array(
                        'description'       => 'Optional: period filter to. Format: yyyy-mm-dd',
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'download'   => array(
                        'description'       => 'Download as a file',
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                )
            ] );

            register_rest_route( 'plusminus-woo/v1', '/mark-orders-exporeted', [
                'method'   => WP_REST_Server::READABLE,
                'callback' => array($this, 'mark_orders_exporeted'),
                'show_in_index' => false,
                'permission_callback' => '__return_true',
                'args' => array(
                    'token'   => array(
                        'description'       => 'Authorization key',
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'orders'   => array(
                        'description'       => 'Orders list',
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                      )
                )
            ] );            
        } );

    }
    
    /**
     * Mark the list of orders as exported.
     *
     * @param  WP_REST_Request $request
     */
    public function mark_orders_exporeted($request) {
        $token = $request->get_param( 'token' );
        $valid = true;
        $count = 0;

        if (empty($token)) {
            $output = array('code' => '400', 'message' => 'Authorization failed');
            $valid = false;
        }

        
        $options = PlusMinus_WooCommerce_Options::instance()->get_options();

        if ($token != PlusMinus_WooCommerce_Helpers::object_value($options, 'export_token')) {
            $output = array('code' => '400', 'message' => 'Authorization failed');
            $valid = false;
        }

        $orders = $request->get_param( 'orders' );
        if (empty($orders)) {
            $output = array('code' => '400', 'message' => 'Missing order information');
            $valid = false;
        }

        if ($valid) {
            $orders_list = explode('|', $orders);
            $count = 0;
            foreach ($orders_list as $single_order_data) {
                $single_order_data = explode(':', $single_order_data);


                $woo_order_id = isset($single_order_data[0]) ? $single_order_data[0] : '';
                $order_pm_doc = isset($single_order_data[1]) ? $single_order_data[1] : '';

                $shop_order = wc_get_order(intval($woo_order_id));
                if ($shop_order && !empty($order_pm_doc)) {
                    $shop_order->update_meta_data('plusminus_doc', $order_pm_doc);
                    $shop_order->save_meta_data();
                    $count++;
                }
            }

            $output = array('code' => '200', 'count' => $count);
        }

        return $output;
    }

        
    /**
     * Return a list of orders that are not exported in JSON format.
     *
     * @param  WP_REST_Request $request
     */
    public function get_orders($request) {
        $token = $request->get_param( 'token' );
        $download = $request->get_param( 'download' );
        $output = array();
        $valid = true;

        $validate_status = PlusMinus_WooCommerce_Helpers::option_number_value('ignore_incomplete_orders');
        
        if (empty($token)) {
            $output = array('code' => '400', 'message' => 'Authorization failed');
            $valid = false;
        }

        $options = PlusMinus_WooCommerce_Options::instance()->get_options();

        if ($token != PlusMinus_WooCommerce_Helpers::object_value($options, 'export_token')) {
            $output = array('code' => '400', 'message' => 'Authorization failed');
            $valid = false;
        }

        if ($valid) {        
            $query_args = array('numberposts' => -1, 'orderby' => 'date', 'order' => 'ASC', 'meta_key' => 'plusminus_doc', 'meta_compare' => 'NOT EXISTS');
            $export_status_only = PlusMinus_WooCommerce_Helpers::object_value($options, 'export_order_status');        

            if (!empty($export_status_only)) {
                $query_args['status'] = array($export_status_only);
            }

            /**
             * Date range filters if provided
             */
            $date_from = $request->get_param('date_from');
            $date_to = $request->get_param('date_to');
            if (!empty($date_from) && !empty($date_to)) {
                $date_from = strtotime($date_from . ' 00:00:00');
                $date_to = strtotime($date_to . ' 00:00:00');
                $date_to = strtotime('+1 day', $date_to);
                $query_args['date_created'] = $date_from.'...'.$date_to;
            }


            $orders = wc_get_orders($query_args);
            $extra_fields   = array( 'meta_data', 'line_items', 'tax_lines', 'shipping_lines', 'fee_lines', 'coupon_lines', 'refunds', 'payment_url', 'is_editable', 'needs_payment', 'needs_processing' );
            $format_decimal = array( 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total', 'total_tax' );
            $format_date    = array( 'date_created', 'date_modified', 'date_completed', 'date_paid' );


            foreach ($orders as $order) {
                // ignore cancelled, failed or draft orders
                if ($validate_status) {
                    if ($order->has_status('cancelled') || $order->has_status('failed') || $order->has_status('checkout-draft')) {
                        continue;
                    }
                }

            $data = $order->get_base_data();

                // Add extra data as necessary.
                foreach ( $extra_fields as $field ) {
                    switch ( $field ) {
                        case 'meta_data':
                            $meta_data         = $order->get_meta_data();
                            $data['meta_data'] = $meta_data;
                            break;
                        case 'line_items':
                            $data['line_items'] = $this->get_product_details($order->get_items( 'line_item' ));
                            break;
                        case 'tax_lines':
                            $data['tax_lines'] = $this->get_object_details($order->get_items( 'tax' ));
                            break;
                        case 'shipping_lines':
                            $data['shipping_lines'] = $this->get_object_details($order->get_items( 'shipping' ));
                            break;
                        case 'fee_lines':
                            $data['fee_lines'] = $this->get_object_details($order->get_items( 'fee' ));
                            break;
                        case 'coupon_lines':
                            $data['coupon_lines'] = $this->get_object_details($order->get_items( 'coupon' ));
                            break;
                        case 'refunds':
                            $data['refunds'] = array();
                            if (method_exists($order, 'get_refunds')) {
                                foreach ( $order->get_refunds() as $refund ) {
                                    $data['refunds'][] = array(
                                        'id'     => $refund->get_id(),
                                        'reason' => $refund->get_reason() ? $refund->get_reason() : '',
                                        'total'  => '-' . wc_format_decimal( $refund->get_amount(), wc_get_price_decimals() ),
                                    );
                                }
                            }
                            break;
                        case 'payment_url':
                            if (method_exists($order, 'get_checkout_payment_url')) {
                                $data['payment_url'] = $order->get_checkout_payment_url();
                            }
                            break;
                        case 'is_editable':
                            if (method_exists($order, 'is_editable')) {
                                $data['is_editable'] = $order->is_editable();
                            }
                            break;
                        case 'needs_payment':
                            if (method_exists($order, 'needs_payment')) {
                                $data['needs_payment'] = $order->needs_payment();
                            }
                            break;
                        case 'needs_processing':
                            if (method_exists($order, 'needs_processing')) {
                                $data['needs_processing'] = $order->needs_processing();
                            }
                            break;
                    }
                }

                foreach ( $format_decimal as $key ) {
                    $data[ $key ] = wc_format_decimal( $data[ $key ], wc_get_price_decimals() );
                }

                foreach ( $format_date as $key ) {
                    $datetime              = $data[ $key ];
                    $data[ $key ]          = wc_rest_prepare_date_response( $datetime, false );
                    $data[ $key . '_gmt' ] = wc_rest_prepare_date_response( $datetime );
                }

                $output[] = $data;
            }

        }
        
        if ($download == 'true' && $valid) {
            /**
             * Sending data as a download file
             */
            $filename = 'pmwoo-orders-'.current_time('Y-m-d').'.json';
            header('Content-Type: application/json; charset=utf-8');
            header("Access-Control-Expose-Headers: Content-Disposition", false);
            header("Content-Disposition: attachment; filename=\"$filename\"");
            print json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
        else return $output;
    }

    /**
     * Generating object data for a WC_Order key
     *
     * @param  object $data
     * @return mixed
     */
    private function get_product_details($data) {
        $r = array();

        foreach ($data as $key => $object) {
            $r[$key] = $object->get_data();

            $product_obj = $object->get_product();
		    $r[$key]['sku'] = $product_obj ? $product_obj->get_sku() : '';
        }

        return $r;
    }
    
    /**
     * Generating object data for a WC_Order key
     *
     * @param  object $data
     * @return mixed
     */
    private function get_object_details($data) {
        $r = array();

        foreach ($data as $key => $object) {
            $r[$key] = $object->get_data();
        }

        return $r;
    }
    
    /**
     * Include the additional parameter showing the Plus Minus document in the order
     *
     * @param  mixed $order
     */
    public function show_pmdoc_in_order($order) {
        $current_doc = get_post_meta( $order->get_id(), 'plusminus_doc', true );
        $nonce = wp_create_nonce( 'plusminus-woocommerce-order' );

        print '<div class="pm-settings-docpanel">';
        print '<div class="pm-settings-docpanel-header"><strong>Plus Minus Document:</strong></div>';
        print '<div class="pm-settings-docpanel-content">';
        print '<span>'.$current_doc.'</span>';
        if (!empty($current_doc)) {
            print '<a href="#" class="button" id="pmWooRemoveDocBtn" data-nonce="'.$nonce.'" data-order-id="'.$order->get_id().'" data-message="'.esc_html__('Please confirm that you wish to remove the Plus Minus document', 'plusminus-woocommerce').'"><span class="dashicons dashicons-no"></span></a>';
        }
        else {
            print '<a href="#" class="button" id="pmWooAddDocBtn" data-nonce="'.$nonce.'" data-order-id="'.$order->get_id().'" data-message="'.esc_html__('Enter Plus Minus document', 'plusminus-woocommerce').'">'.esc_html__('Add document', 'plusminus-woocommerce').'</a>';
        }
        print '<span class="spinner pmWooOrderSpinner"></span>';
        print '</div>'; // content
        print "</div>"; //
    }

    /**
     * Register a new column showing the Plus Minus document
     */
    public function add_pmdoc_column($columns) {
        $new_columns = array();

        foreach ( $columns as $column_name => $column_info ) {

            $new_columns[ $column_name ] = $column_info;

            if ( 'order_date' === $column_name ) {
                $new_columns['pmdoc_exported column-primary'] = esc_html__( 'PM Document', 'plusminus-woocommerce' );
            }
        }
    
        return $new_columns;
    }

    /**
     * Showing saved Plus Minus documents in the column
     */
    public function add_pmdoc_column_content($column) {
        global $post;

        if ( 'pmdoc_exported column-primary' === $column ) {
            print get_post_meta( $post->ID, 'plusminus_doc', true );
        }
    }

    /**
     * Clearing the saved Plus-Minus document 
     */
    public function action_clear_pmdoc() {
        $result = array('code' => '', 'message' => '');
        if( wp_verify_nonce( $_REQUEST['nonce'], 'plusminus-woocommerce-order') ) {
            $order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : '';

            if (empty($order)) {
                $result['code'] = '400';
                $result['message'] = esc_html__('Missing required parameters', 'plusminus-woocommerce');
            }
            else {
                $shop_order = wc_get_order( intval($order) );

                if ($shop_order) {
                    $shop_order->delete_meta_data('plusminus_doc');
                    $shop_order->save_meta_data();

                    $result['code'] = '200';
                    $result['message'] = esc_html__('Document cleared. The order page will reload.', 'plusminus-woocommerce');
                }
                else {
                    $result['code'] = '400';
                    $result['message'] = esc_html__('Order not found!', 'plusminus-woocommerce');
                }
            }
        }
        else {
            $result['code'] = '500';
            $result['message'] = esc_html__('Cannot executed the command. Please reload the page and try again', 'plusminus-woocommerce');
        }

        wp_send_json($result);
    }

    /**
     * Saving information for a Plus Minus document
     */
    public function action_add_pmdoc() {
        $result = array('code' => '', 'message' => '');
        if( wp_verify_nonce( $_REQUEST['nonce'], 'plusminus-woocommerce-order') ) {
            $order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : '';
            $pmdoc = isset($_REQUEST['pm_doc']) ? sanitize_text_field($_REQUEST['pm_doc']) : '';

            if (empty($order) || empty($pmdoc)) {
                $result['code'] = '400';
                $result['message'] = esc_html__('Missing required parameters', 'plusminus-woocommerce');
            }
            else {
                $shop_order = wc_get_order( intval($order) );

                if ($shop_order) {
                    $shop_order->update_meta_data('plusminus_doc', $pmdoc);
                    $shop_order->save_meta_data();

                    $result['code'] = '200';
                    $result['message'] = esc_html__('Document saved. The order page will reload.', 'plusminus-woocommerce');
                }
                else {
                    $result['code'] = '400';
                    $result['message'] = esc_html__('Order not found!', 'plusminus-woocommerce');
                }
            }
        }
        else {
            $result['code'] = '500';
            $result['message'] = esc_html__('Cannot executed the command. Please reload the page and try again', 'plusminus-woocommerce');
        }

        wp_send_json($result);
    }
}
