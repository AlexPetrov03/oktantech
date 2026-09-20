<?php

class Speedy_Admin_Post {
    public function __construct() {
        add_action( 'wp_ajax_speedy_regenerate', array( $this, 'regenerate_data' ) );
        add_action( 'wp_ajax_speedy_city_autocomplete', array( $this, 'get_city_autocomplete' ) );
        add_action( 'wp_ajax_nopriv_speedy_city_autocomplete', array( $this, 'get_city_autocomplete' ) );

        add_action( 'wp_ajax_speedy_get_cities_by_region', array( $this, 'get_cities_by_region' ) );
        add_action( 'wp_ajax_nopriv_speedy_get_cities_by_region', array( $this, 'get_cities_by_region' ) );

        add_action( 'wp_ajax_speedy_get_offices_by_city', array( $this, 'get_offices_by_city' ) );
        add_action( 'wp_ajax_nopriv_speedy_get_offices_by_city', array( $this, 'get_offices_by_city' ) );

        add_action( 'wp_ajax_speedy_calculate_shipping', array( $this, 'calculate_shipping' ) );
        add_action( 'wp_ajax_nopriv_speedy_calculate_shipping', array( $this, 'calculate_shipping' ) );

        add_action( 'wp_ajax_speedy_fast_order', array( $this, 'create_order' ) );
        add_action( 'wp_ajax_nopriv_speedy_fast_order', array( $this, 'create_order' ) );

        add_action( 'wp_ajax_speedy_get_offices_by_city_id', array( $this, 'get_offices_by_city_id' ) );
        add_action( 'wp_ajax_nopriv_speedy_get_offices_by_city_id', array( $this, 'get_offices_by_city_id' ) );
    }


    public function regenerate_data() {
        global $wpdb;

        if( ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( false );
            exit;
        }

        // The old setting is no longer exposed in the UI. Refreshing Speedy
        // data explicitly turns it off for existing installations as well.
        speedy_disable_automatic_waybills();

        delete_transient('speedy_offices_cache');

        $cities = Speedy_API::cities();
        $cities_table = $wpdb->prefix . 'speedy_cities';

        if( $cities ) {
            $wpdb->query( "TRUNCATE TABLE $cities_table" );
            
            $arr_excluded_cities = [21539, 21542]; // с. Добрич causes bug in senders office!!!
            foreach( $cities as $city ) {
                if( in_array( $city['id'], $arr_excluded_cities ) ) continue;
                $wpdb->insert(
                    $cities_table,
                    array(
                        'id'        => $city['id'],
                        'name'      => $this->mb_ucfirst( is_string( $city['name'] ) ? $city['name'] : '' ),
                        'post_code' => is_string( $city['postCode'] ) ? $city['postCode'] : '',
                        'region'    => $this->mb_ucfirst( is_string( $city['region'] ) ? $city['region'] : '' ),
                        'type'      => is_string( $city['type'] ) ? $city['type'] : '' 
                    )
                );
            }
        } else {
            wp_send_json_error( $cities );
            exit;
        }

        $offices = Speedy_API::offices();   
        $offices_table = $wpdb->prefix . 'speedy_offices';

        if( $offices ) {
            $wpdb->query( "TRUNCATE TABLE $offices_table" );

            foreach( $offices as $office ) {
                $wpdb->insert(
                    $offices_table,
                    array(
                        'id'        => $office['id'],
                        'name'      => $office['name'],
                        'city'      => $this->mb_ucfirst( $office['address']['siteName'] ),
                        'address'   => $office['address']['fullAddressString'],
                        'latitude'  => $office['address']['x'],
                        'longitude' => $office['address']['y'],
                        'office_code' => $office['siteId'],
                        'post_code' =>   isset( $office['address']['postCode'] ) ? $office['address']['postCode'] : '',
                        'address_details' => maybe_serialize( $office['address'] ),
                        'office_details'  => '',
                        'phone'           => '', 
                        'email'           => ''
                    )
                );
            }
        }

         $arr_data = ['userName' => speedy_username(), 'password' => speedy_password()];
    $clientid_response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'client/contract', $arr_data);

    $clientId = null;
    if (is_array($clientid_response) && isset($clientid_response['clientId'])) {
        $clientId = $clientid_response['clientId'];

        // Обновяване на sender_id в базата данни
        update_option('woocommerce_speedy_shipping_sender_id', $clientId);
    }

    // Стъпка 4: Връщане на отговор
    if ($clientId) {
        wp_send_json_success([
            'success' => true,
            'message' => 'Data regenerated successfully',
            'clientId' => $clientId,
        ]);
    } else {
        wp_send_json_error([
            'error' => 'Failed to fetch clientId from Speedy API.',
            'response' => $clientid_response,
        ]);
    }

    exit;
    }

    public function get_cities_by_region() {        
        $state_code = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '';

        // Use state code first so WPML-translated labels do not break city lookup.
        $region = $this->map_bg_state_code_to_region( $state_code );

        if ( $region === '' ) {
            $states = WC()->countries->get_states( 'BG' );
            if ( isset( $states[ $state_code ] ) ) {
                $region = (string) $states[ $state_code ];
            } else {
                // Some checkouts post region label/value directly instead of BG-XX code.
                $region = (string) $state_code;
            }
        }

        $region = trim( $region );

        if ( $region === '' ) {
            wp_send_json_success( [] );
            exit;
        }

        if( $region === 'Област София' ) {
            $region = 'София';
        } else if( $region === 'София-Град' ) {
            $region = 'София (столица)';
        }

        $results = Speedy_DB::get_cities_by_region( $region );

        $api_language = $this->get_speedy_api_language();
        if ( $api_language !== 'BG' && is_array( $results ) ) {
            foreach ( $results as $city ) {
                if ( ! is_object( $city ) ) {
                    continue;
                }

                if ( isset( $city->name ) ) {
                    $city->name = $this->transliterate_bg_text( (string) $city->name );
                }

                if ( isset( $city->type ) ) {
                    $type = trim( (string) $city->type );
                    if ( $type === 'гр.' || $type === 'гр' ) {
                        $city->type = 'gr.';
                    } elseif ( $type === 'с.' || $type === 'с' ) {
                        $city->type = 's.';
                    } else {
                        $city->type = $this->transliterate_bg_text( $type );
                    }
                }
            }
        }

        wp_send_json_success( $results );
        exit;
    }

    public function get_offices_by_city_id() {
        $city_id = intval( $_POST['city'] );

        $offices = Speedy_DB::get_office_by_city_id( $city_id );

        wp_send_json_success( $offices );
        exit;
    }

    public function get_city_autocomplete() {
        $cities = Speedy_DB::get_city_autocomplete( sanitize_text_field( $_POST['term'] ) );

        $response = [];

        foreach( $cities as $city ) {
            
            $response[] = [
                'label' => $city->name . ' (' . $city->post_code . ')',
                'value' => $city->id
            ];
        }

        wp_send_json_success( $response );
        exit;
    }

    public function get_offices_by_city() {
        $city = sanitize_text_field( $_POST['city'] );

        $cities = Speedy_DB::get_offices_by_city( $city );

        wp_send_json_success( $cities );
        exit;
    }

    private function mb_ucfirst($string, $encoding = 'UTF-8') {
        $string = mb_strtolower( $string );
        $strlen = mb_strlen($string, $encoding);
        $firstChar = mb_substr($string, 0, 1, $encoding);
        $then = mb_substr($string, 1, $strlen - 1, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $then;
    }

    private function get_speedy_api_language() {
        $lang = '';

        if ( function_exists( 'has_filter' ) && has_filter( 'wpml_current_language' ) ) {
            $lang = (string) apply_filters( 'wpml_current_language', null );
        }

        if ( $lang === '' ) {
            $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
            $lang = substr( (string) $locale, 0, 2 );
        }

        $lang = strtoupper( trim( (string) $lang ) );

        return $lang === 'BG' ? 'BG' : 'EN';
    }

    private function transliterate_bg_text( $text ) {
        $map = [
            'Щ' => 'Sht', 'Ш' => 'Sh', 'Ч' => 'Ch', 'Ц' => 'Ts', 'Ж' => 'Zh',
            'Ю' => 'Yu', 'Я' => 'Ya', 'Ъ' => 'A',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E',
            'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
            'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ь' => 'Y',
            'щ' => 'sht', 'ш' => 'sh', 'ч' => 'ch', 'ц' => 'ts', 'ж' => 'zh',
            'ю' => 'yu', 'я' => 'ya', 'ъ' => 'a',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
            'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ь' => 'y',
        ];

        return strtr( (string) $text, $map );
    }

    private function map_bg_state_code_to_region( $state_code ) {
        $map = [
            'BG-01' => 'Благоевград',
            'BG-02' => 'Бургас',
            'BG-03' => 'Варна',
            'BG-04' => 'Велико Търново',
            'BG-05' => 'Видин',
            'BG-06' => 'Враца',
            'BG-07' => 'Габрово',
            'BG-08' => 'Добрич',
            'BG-09' => 'Кърджали',
            'BG-10' => 'Кюстендил',
            'BG-11' => 'Ловеч',
            'BG-12' => 'Монтана',
            'BG-13' => 'Пазарджик',
            'BG-14' => 'Перник',
            'BG-15' => 'Плевен',
            'BG-16' => 'Пловдив',
            'BG-17' => 'Разград',
            'BG-18' => 'Русе',
            'BG-19' => 'Силистра',
            'BG-20' => 'Сливен',
            'BG-21' => 'Смолян',
            'BG-22' => 'София',
            'BG-23' => 'София (столица)',
            'BG-24' => 'Стара Загора',
            'BG-25' => 'Търговище',
            'BG-26' => 'Хасково',
            'BG-27' => 'Шумен',
            'BG-28' => 'Ямбол',
        ];

        return isset( $map[ $state_code ] ) ? $map[ $state_code ] : '';
    }

    public function calculate_shipping() {
        $this->validate();

        $product_id = abs( $_POST['product'] );

        $product = wc_get_product( $product_id );

        if( ! $product->exists() ) {
            wp_send_json_error( ['Продуктът не съществува'], 400 );
            exit;
        }

        $data = [
            'billing_office'        => isset( $_POST['office'] ) ? intval( $_POST['office'] ) : null,
            'billing_shipping_type' => sanitize_text_field( $_POST['shipping-type'] ),
            'billing_neighborhood' => sanitize_text_field( $_POST['neighborhood'] ),
            'billing_street'       => sanitize_text_field( $_POST['street'] ),
            'billing_street_number' => sanitize_text_field( $_POST['street_number'] ),
            'billing_block'         => sanitize_text_field( $_POST['block'] ),
            'billing_entrance'      => sanitize_text_field( $_POST['entrance'] ),
            'billing_floor'         => sanitize_text_field( $_POST['floor'] ),
            'billing_apartment'     => sanitize_text_field( $_POST['apartment'] ),
            'billing_address_1'     => sanitize_text_field( $_POST['address'] )
        ];

        WC()->session->set('chosen_shipping_methods', ['speedy_shipping']);

        WC()->session->set('calculate_product_weight', $product->get_weight());

        $price = Speedy_API::calculate_shipping( $data, true );

        if( $price === 0 )
            wp_send_json_error( ['Не може да се изчисли доставка за този адрес'], 400 );
        else
            wp_send_json_success( [ 'price' => floatval($price['cost']), 'order' => floatval($price['order_total']) ], 200 );

        exit;
    }

    public function create_order() {
        $this->validate();

        if( count( explode( ' ', $_POST['first_last_name'] ) ) !== 2 ) {
            wp_send_json_error( ['Въведете име и фамилия'], 400 );
            exit;
        }

        $name  = explode( ' ', $_POST['first_last_name'] );
        $email = sanitize_email( $_POST['email'] ); 
        $phone = sanitize_text_field( $_POST['phone'] );
        $quantity = intval( $_POST['quantity'] );

        $arr_variation = array();
        if(isset($_POST['prod_var_id']) && $_POST['prod_var_id'] != '') {
            $variation_id = intval($_POST['prod_var_id']);
            $product_variation = new WC_Product_Variation($variation_id);

            foreach($product_variation->get_variation_attributes() as $attribute => $attribute_value){
                $arr_variation['variation'][$attribute] = $attribute_value;
            }
        } else {
            $variation_id = NULL;
        }

        $data = [];

        if( $_POST['shipping-type'] === 'office' ) {
            $office = Speedy_DB::get_office_by_id( intval( $_POST['office'] ) );
            if( empty( $office ) ) {
                wp_send_json_error( ['Невалиден офис'], 400 );
                exit;
            }

            $data['address_1'] = 'Офис: ' . $office->address;
            $data['city']      = $office->city;
            $data['postcode']  = $office->post_code;
        } elseif( $_POST['shipping-type'] === 'office2' ) {
            $office = Speedy_DB::get_office_by_id( intval( $_POST['office2'] ) );
            if( empty( $office ) ) {
                wp_send_json_error( ['Невалиден автомат'], 400 );
                exit;
            }

            $data['address_1'] = 'Автомат: ' . $office->address;
            $data['city']      = $office->city;
            $data['postcode']  = $office->post_code;
        } else {
            $city = Speedy_DB::get_city_by_id( intval( $_POST['city_id'] ) );

            if( empty( $city ) ) {
                wp_send_json_error( ['Невалиден град'], 400 );
                exit;
            }

            $data['address_1'] = sanitize_text_field( $_POST['address'] );
            $data['city']      = $city->name;
            $data['postcode']  = $city->post_code;
        }

        $data['first_name'] = $name[0];
        $data['last_name']  = $name[1];
        $data['email']      = $email;
        $data['phone']      = $phone;
        $data['country']    = 'BG';


        $order = wc_create_order();

        if( ! empty( $_POST['notes'] ) ) {
            $order->set_customer_note( sanitize_textarea_field( $_POST['notes'] ) );
        }

        $order->update_meta_data( 'shipping_type', $_POST['shipping-type'] === 'office' ? 'Офис' : 'Адрес' );
        $order->update_meta_data( 'neighborhood', sanitize_text_field( $_POST['neighborhood'] ) );
        $order->update_meta_data( 'street', sanitize_text_field( $_POST['street'] ) );
        $order->update_meta_data( 'street_number', sanitize_text_field( $_POST['street_number'] ) );
        $order->update_meta_data( 'block', sanitize_text_field( $_POST['block'] ) );
        $order->update_meta_data( 'entrance', sanitize_text_field( $_POST['entrance'] ) );
        $order->update_meta_data( 'floor', sanitize_text_field( $_POST['floor'] ) );
        $order->update_meta_data( 'apartment', sanitize_text_field( $_POST['apartment'] ) );

        if($variation_id) {
            $order->update_meta_data( 'variation_id', $variation_id );
        }
        
        $order->add_product( wc_get_product( abs( $_POST['product'] ) ), $quantity, $arr_variation );
        $order->set_address( $data, 'shipping' );
        $order->set_address( $data, 'billing' );

        $order->calculate_totals();

        $data = [
            'billing_office'        => isset( $_POST['office'] ) ? intval( $_POST['office'] ) : null,
            'billing_shipping_type' => sanitize_text_field( $_POST['shipping-type'] ),
            'billing_neighborhood'  => sanitize_text_field( $_POST['neighborhood'] ),
            'billing_street'        => sanitize_text_field( $_POST['street'] ),
            'billing_street_number' => sanitize_text_field( $_POST['street_number'] ),
            'billing_block'         => sanitize_text_field( $_POST['block'] ),
            'billing_entrance'      => sanitize_text_field( $_POST['entrance'] ),
            'billing_floor'         => sanitize_text_field( $_POST['floor'] ),
            'billing_apartment'     => sanitize_text_field( $_POST['apartment'] ),
            'billing_address_1'     => sanitize_text_field( $_POST['address'] )
        ];

        $price = Speedy_API::calculate_shipping( $data );

        $order->set_shipping_total( $price );
        $order->set_total( $order->get_total( 'edit' ) + $price );

        $item = new WC_Order_Item_Shipping();
        $item->legacy_package_key = 'speedy_shipping';
        $item->set_method_title( 'Доставка със Спиди' );
        $item->set_method_id( 'speedy_shipping' );
        $item->set_instance_id( 'speedy_shipping' );
        $item->set_total( wc_format_decimal( $price ) );
        $item->set_taxes( 0 );

        $order->add_item( $item );
        
        $order->save();

        $order->update_status('processing');

        $order->save();

        wp_send_json_success( [ 'url' => $order->get_checkout_order_received_url() ] );
        exit;
    }

    private function validate() {
        $errors = [];

        $required_fields = [ 
            'city_id' => 'Моля въведете град', 
            'product' => 'Грешка. Презаредете и опитайте отново', 
            'first_last_name' => 'Въведете Име и Фамилия',
            'email'   => 'Въведете имейл',
            'phone'   => 'Въведете телефон',
            'shipping-method' => 'Изберете метод на доставка',
            'shipping-type'   => 'Изберете вида на доставката',
            'city'            => 'Въведете град'
        ];

        if( ! isset( $_POST['quantity'] ) || intval( $_POST['quantity'] ) === 0 ) {
            $errors[] = 'Въведете правилно количество';
        }

        foreach( $required_fields as $key => $error ) {
            if( empty( $_POST[$key] ) ) {
                $errors[] = $error;
            }
        }

        if( ! isset( $_POST['terms'] ) || $_POST['terms'] != '1' ) {
            wp_send_json_error( ['Съгласете се с Общите условия на сайта'], 400 );
            exit;
        }

        if( count( $errors ) ) {
            wp_send_json_error( $errors, 400 );
            exit;
        }

        if( $_POST['shipping-type'] === 'office' && empty( $_POST['office'] ) ) {
            wp_send_json_error( ['Моля изберете офис'], 400 );
            exit;
        }

        if( $_POST['shipping-type'] === 'address' && empty( $_POST['address'] ) ) {
            wp_send_json_error( ['Моля въведете адрес'], 400 );
            exit;
        }

        if( ! filter_var( $_POST['email'], FILTER_VALIDATE_EMAIL ) ) {
            wp_send_json_error( ['Невалиден имейл адрес'], 400 );
            exit;
        }
    }    
}

new Speedy_Admin_Post;