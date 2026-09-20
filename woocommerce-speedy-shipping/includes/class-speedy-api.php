<?php

defined( 'ABSPATH' ) or exit;

class Speedy_API {
    public static function verify_account() {
        $arr_data = [ 'userName' => speedy_username(), 'password' => speedy_password() ];
        // $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'location/country/', $arr_data);
        $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'client/contract/', $arr_data);
        
        if(WS_Speedy_Request::is_api_error($response)) {
            echo '<div style="position: relative; z-index: 9999; padding: 10px; color: #fff; background: #ff0000; text-align: center; font-size: 20px;">Грешно потребителско име или парола!</div>';
            //WS_Speedy_Request::is_api_error($response)
            return false;
        } else {
            return true;
        }
    }

    private static function get_speedy_country_id_by_iso2( $iso2 ) {
    $iso2 = strtoupper( (string) $iso2 );

    if ( $iso2 === '' || $iso2 === 'BG' ) {
        return 100;
    }

    // Prefer local fallback map to avoid unnecessary location/country calls.
    if ( function_exists( 'speedy_country_info_fallback_by_iso' ) ) {
        $fallback = speedy_country_info_fallback_by_iso( $iso2 );
        if ( is_array( $fallback ) && ! empty( $fallback['id'] ) ) {
            return (int) $fallback['id'];
        }
    }

    $cache_key = 'speedy_country_info_v2_' . $iso2;
    $cached = get_transient( $cache_key );
    if ( $cached !== false ) {
        if ( is_array( $cached ) && isset( $cached['countryId'] ) ) {
            return (int) $cached['countryId'];
        }
        return (int) $cached;
    }

    $country_name = '';
    if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
        $countries = WC()->countries->get_countries();
        if ( is_array( $countries ) && isset( $countries[ $iso2 ] ) ) {
            $country_name = (string) $countries[ $iso2 ];
        }
    }

    if ( $country_name === '' ) {
        $country_name = $iso2;
    }

    $arr_data = [
        'userName' => speedy_username(),
        'password' => speedy_password(),
        'name'     => $country_name,
    ];

    $response = WS_Speedy_Request::call( SPEEDY_API_BASE_URL . 'location/country', $arr_data );

    if ( ! is_array( $response ) || empty( $response['countries'] ) || ! is_array( $response['countries'] ) ) {
        return 0;
    }

    foreach ( $response['countries'] as $c ) {
        if ( ! empty( $c['id'] ) && ! empty( $c['isoAlpha2'] ) && strtoupper( (string) $c['isoAlpha2'] ) === $iso2 ) {
            $country_id   = (int) $c['id'];
            $address_type = isset( $c['addressType'] ) ? (int) $c['addressType'] : 1;

            set_transient(
                $cache_key,
                [
                    'countryId'   => $country_id,
                    'addressType' => $address_type,
                ],
                DAY_IN_SECONDS
            );

            return $country_id;
        }
    }

    return 0;
}

    private static function normalize_country_token( $value ) {
        $value = (string) $value;
        $value = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/i', '', $value );

        return trim( (string) $value );
    }

    private static function resolve_country_iso2_from_input( $country_input ) {
        $raw = trim( (string) $country_input );
        if ( $raw === '' ) {
            return '';
        }

        $upper = strtoupper( $raw );
        if ( preg_match( '/^[A-Z]{2}$/', $upper ) ) {
            return $upper;
        }

        if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
            $countries = WC()->countries->get_countries();
            if ( is_array( $countries ) ) {
                $needle = self::normalize_country_token( $raw );

                foreach ( $countries as $iso2 => $name ) {
                    if ( self::normalize_country_token( $name ) === $needle ) {
                        return strtoupper( (string) $iso2 );
                    }
                }
            }
        }

        return '';
    }

    private static function get_speedy_country_id_from_input( $country_input, &$resolved_iso2 = '' ) {
        $raw = trim( (string) $country_input );

        if ( is_numeric( $raw ) && (int) $raw > 0 ) {
            $resolved_iso2 = '';
            return (int) $raw;
        }

        $resolved_iso2 = self::resolve_country_iso2_from_input( $raw );
        if ( $resolved_iso2 !== '' ) {
            return self::get_speedy_country_id_by_iso2( $resolved_iso2 );
        }

        if ( $raw === '' ) {
            return 0;
        }

        $name_cache_key = 'speedy_country_id_by_name_' . md5( $needle );
        $cached_by_name = get_transient( $name_cache_key );
        if ( $cached_by_name !== false ) {
            $cached_by_name = (int) $cached_by_name;
            if ( $cached_by_name > 0 ) {
                return $cached_by_name;
            }
        }

        $arr_data = [
            'userName' => speedy_username(),
            'password' => speedy_password(),
            'name'     => $raw,
        ];

        $response = WS_Speedy_Request::call( SPEEDY_API_BASE_URL . 'location/country', $arr_data );
        if ( ! is_array( $response ) || empty( $response['countries'] ) || ! is_array( $response['countries'] ) ) {
            return 0;
        }

        $needle = self::normalize_country_token( $raw );
        foreach ( $response['countries'] as $country ) {
            if ( empty( $country['id'] ) ) {
                continue;
            }

            $candidates = [];
            if ( ! empty( $country['isoAlpha2'] ) ) {
                $candidates[] = (string) $country['isoAlpha2'];
            }
            if ( ! empty( $country['name'] ) ) {
                $candidates[] = (string) $country['name'];
            }
            if ( ! empty( $country['nameEn'] ) ) {
                $candidates[] = (string) $country['nameEn'];
            }

            foreach ( $candidates as $candidate ) {
                if ( self::normalize_country_token( $candidate ) === $needle ) {
                    if ( ! empty( $country['isoAlpha2'] ) ) {
                        $resolved_iso2 = strtoupper( (string) $country['isoAlpha2'] );
                    }
                    $found_id = (int) $country['id'];
                    if ( $found_id > 0 ) {
                        set_transient( $name_cache_key, $found_id, DAY_IN_SECONDS );
                    }
                    return $found_id;
                }
            }
        }

        return 0;
    }



private static function fx_per_eur( $currency ) {
$currency = strtoupper( trim( (string) $currency ) );
if ( $currency === 'EUR' ) return 1.0;
if ( $currency === 'BGN' ) return 1.95583;

$rates = speedy_get_setting( 'currency_rate', [] );
if ( ! is_array( $rates ) ) {
    return 0.0;
}


if (is_array($rates) && !empty($rates)) {
    foreach ($rates as $item) {
        if (
            is_array($item) &&
            isset($item['iso_code'], $item['rate']) &&
            strtoupper(trim($item['iso_code'])) === $currency
        ) {
            return (float) $item['rate'];
        }
    }
}
return 0.0;
}

private static function convert_via_eur( $amount, $from_currency, $to_currency ) {
$amount = (float) $amount;
$from = strtoupper( trim( (string) $from_currency ) );
$to = strtoupper( trim( (string) $to_currency ) );

if ( $amount <= 0 ) return 0.0;
if ( $from === $to ) return $amount;

$from_per_eur = self::fx_per_eur( $from );
$to_per_eur = self::fx_per_eur( $to );

if ( $from_per_eur <= 0 || $to_per_eur <= 0 ) {
return $amount;
}

$amount_in_eur = $amount / $from_per_eur;
return $amount_in_eur * $to_per_eur;
}

private static function is_truthy_flag( $value ) {
    if ( is_bool( $value ) ) {
        return $value;
    }

    if ( is_numeric( $value ) ) {
        return ( (float) $value ) !== 0.0;
    }

    if ( is_string( $value ) ) {
        return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'y', 'allowed' ], true );
    }

    return ! empty( $value );
}

private static function get_destination_service_id( array $service ) {
    if ( isset( $service['serviceId'] ) ) {
        return (int) $service['serviceId'];
    }

    if ( isset( $service['id'] ) ) {
        return (int) $service['id'];
    }

    return 0;
}

private static function get_destination_cod_policies( $destination_response ) {
    $policies = [];

    if (
        ! is_array( $destination_response )
        || empty( $destination_response['services'] )
        || ! is_array( $destination_response['services'] )
    ) {
        return $policies;
    }

    foreach ( $destination_response['services'] as $service ) {
        if ( ! is_array( $service ) ) {
            continue;
        }

        $service_id = self::get_destination_service_id( $service );
        if ( $service_id <= 0 ) {
            continue;
        }

        $policy = [
            'serviceId' => $service_id,
            'allowed'   => false,
            'forbidden' => false,
            'message'   => '',
            'rawCod'    => null,
        ];

        $cod = $service['additionalServices']['cod'] ?? null;
        $policy['rawCod'] = $cod;

        if ( is_array( $cod ) ) {
            if ( array_key_exists( 'allowance', $cod ) ) {
                $allowance = strtoupper( trim( (string) $cod['allowance'] ) );
                if ( $allowance === 'ALLOWED' ) {
                    $policy['allowed'] = true;
                } elseif ( $allowance === 'FORBIDDEN' ) {
                    $policy['forbidden'] = true;
                }
            }

            if ( array_key_exists( 'allowed', $cod ) ) {
                $policy['allowed'] = self::is_truthy_flag( $cod['allowed'] );
            }

            if ( array_key_exists( 'forbidden', $cod ) ) {
                if ( is_array( $cod['forbidden'] ) ) {
                    $policy['forbidden'] = true;

                    if ( ! empty( $cod['forbidden']['message'] ) ) {
                        $policy['message'] = (string) $cod['forbidden']['message'];
                    } elseif ( ! empty( $cod['forbidden']['error']['message'] ) ) {
                        $policy['message'] = (string) $cod['forbidden']['error']['message'];
                    }
                } else {
                    $policy['forbidden'] = self::is_truthy_flag( $cod['forbidden'] );
                }
            }

            if ( $policy['message'] === '' ) {
                if ( ! empty( $cod['message'] ) ) {
                    $policy['message'] = (string) $cod['message'];
                } elseif ( ! empty( $cod['error']['message'] ) ) {
                    $policy['message'] = (string) $cod['error']['message'];
                }
            }

            if ( ! $policy['allowed'] && ! $policy['forbidden'] && ! empty( $cod ) ) {
                $policy['allowed'] = true;
            }
        } elseif ( self::is_truthy_flag( $cod ) ) {
            $policy['allowed'] = true;
        }

        $policies[ $service_id ] = $policy;
    }

    return $policies;
}

private static function get_destination_obpd_policies( $destination_response ) {
    $policies = [];

    if (
        ! is_array( $destination_response )
        || empty( $destination_response['services'] )
        || ! is_array( $destination_response['services'] )
    ) {
        return $policies;
    }

    foreach ( $destination_response['services'] as $service ) {
        if ( ! is_array( $service ) ) {
            continue;
        }

        $service_id = self::get_destination_service_id( $service );
        if ( $service_id <= 0 ) {
            continue;
        }

        $policy = [
            'serviceId'  => $service_id,
            'allowed'    => false,
            'forbidden'  => false,
            'message'    => '',
            'rawObpd'    => null,
        ];

        $obpd = $service['additionalServices']['obpd'] ?? ( $service['additionalServices']['obpDetails'] ?? null );
        $policy['rawObpd'] = $obpd;

        if ( is_array( $obpd ) ) {
            if ( array_key_exists( 'allowance', $obpd ) ) {
                $allowance = strtoupper( trim( (string) $obpd['allowance'] ) );
                if ( $allowance === 'ALLOWED' ) {
                    $policy['allowed'] = true;
                } elseif ( $allowance === 'FORBIDDEN' ) {
                    $policy['forbidden'] = true;
                }
            }

            if ( array_key_exists( 'allowed', $obpd ) ) {
                $policy['allowed'] = self::is_truthy_flag( $obpd['allowed'] );
            }

            if ( array_key_exists( 'forbidden', $obpd ) ) {
                if ( is_array( $obpd['forbidden'] ) ) {
                    $policy['forbidden'] = true;

                    if ( ! empty( $obpd['forbidden']['message'] ) ) {
                        $policy['message'] = (string) $obpd['forbidden']['message'];
                    } elseif ( ! empty( $obpd['forbidden']['error']['message'] ) ) {
                        $policy['message'] = (string) $obpd['forbidden']['error']['message'];
                    }
                } else {
                    $policy['forbidden'] = self::is_truthy_flag( $obpd['forbidden'] );
                }
            }

            if ( $policy['message'] === '' ) {
                if ( ! empty( $obpd['message'] ) ) {
                    $policy['message'] = (string) $obpd['message'];
                } elseif ( ! empty( $obpd['error']['message'] ) ) {
                    $policy['message'] = (string) $obpd['error']['message'];
                }
            }

            if ( ! $policy['allowed'] && ! $policy['forbidden'] && ! empty( $obpd ) ) {
                $policy['allowed'] = true;
            }
        } elseif ( self::is_truthy_flag( $obpd ) ) {
            $policy['allowed'] = true;
        }

        $policies[ $service_id ] = $policy;
    }

    return $policies;
}

private static function resolve_cod_processing_for_policy( $moneytransfer_mode, $is_bg_destination, array $policy = [] ) {
    $result = [
        'status'         => 'fallback',
        'processingType' => 'CASH',
        'message'        => '',
    ];

    if ( $moneytransfer_mode !== 'YES' ) {
        return $result;
    }

    if ( ! empty( $policy['forbidden'] ) ) {
        $result['status'] = 'forbidden';
        $result['processingType'] = '';
        $result['message'] = ! empty( $policy['message'] ) ? (string) $policy['message'] : 'Наложеният платеж не е позволен за избраната услуга.';
        return $result;
    }

    if ( ! empty( $policy['allowed'] ) ) {
        $result['status'] = $is_bg_destination ? 'postal-money-transfer' : 'cash-fallback';
        $result['processingType'] = $is_bg_destination ? 'POSTAL_MONEY_TRANSFER' : 'CASH';
        return $result;
    }

    if ( $is_bg_destination ) {
        $result['processingType'] = 'POSTAL_MONEY_TRANSFER';
    }

    return $result;
}

private static function resolve_cod_processing_for_destination( array $policies, $requested_service_id, $moneytransfer_mode, $is_bg_destination ) {
    if ( $moneytransfer_mode !== 'YES' ) {
        return [
            'serviceId'      => 0,
            'status'         => 'fallback',
            'processingType' => 'CASH',
            'message'        => '',
        ];
    }

    if ( $requested_service_id > 0 && isset( $policies[ $requested_service_id ] ) ) {
        $resolved = self::resolve_cod_processing_for_policy(
            $moneytransfer_mode,
            $is_bg_destination,
            $policies[ $requested_service_id ]
        );

        $resolved['serviceId'] = (int) $requested_service_id;
        return $resolved;
    }

    foreach ( $policies as $service_id => $policy ) {
        if ( ! empty( $policy['allowed'] ) && empty( $policy['forbidden'] ) ) {
            $resolved = self::resolve_cod_processing_for_policy(
                $moneytransfer_mode,
                $is_bg_destination,
                $policy
            );

            $resolved['serviceId'] = (int) $service_id;
            return $resolved;
        }
    }

    foreach ( $policies as $service_id => $policy ) {
        if ( ! empty( $policy['forbidden'] ) ) {
            $resolved = self::resolve_cod_processing_for_policy(
                $moneytransfer_mode,
                $is_bg_destination,
                $policy
            );

            $resolved['serviceId'] = (int) $service_id;
            return $resolved;
        }
    }

    return [
        'serviceId'      => 0,
        'status'         => $is_bg_destination ? 'postal-money-transfer' : 'cash-fallback',
        'processingType' => $is_bg_destination ? 'POSTAL_MONEY_TRANSFER' : 'CASH',
        'message'        => '',
    ];
}

private static function should_send_obpd_for_destination( array $policies, $requested_service_id ) {
    if ( $requested_service_id > 0 ) {
        if ( ! isset( $policies[ $requested_service_id ] ) ) {
            return false;
        }

        return ! empty( $policies[ $requested_service_id ]['allowed'] ) && empty( $policies[ $requested_service_id ]['forbidden'] );
    }

    if ( empty( $policies ) ) {
        return false;
    }

    foreach ( $policies as $policy ) {
        if ( empty( $policy['allowed'] ) || ! empty( $policy['forbidden'] ) ) {
            return false;
        }
    }

    return true;
}

private static function sync_declared_value_with_cod( array &$service ) {
    if ( speedy_get_setting( 'includeshippingprice' ) !== 'YES' ) {
        return;
    }

    if ( speedy_get_setting( 'obqvena' ) !== 'YES' ) {
        return;
    }

    if (
        empty( $service['additionalServices']['cod'] )
        || ! is_array( $service['additionalServices']['cod'] )
        || ! isset( $service['additionalServices']['cod']['amount'] )
        || empty( $service['additionalServices']['cod']['includeShippingPrice'] )
    ) {
        return;
    }

    if (
        empty( $service['additionalServices']['declaredValue'] )
        || ! is_array( $service['additionalServices']['declaredValue'] )
    ) {
        return;
    }

    $service['additionalServices']['declaredValue']['amount'] = (float) $service['additionalServices']['cod']['amount'];
}

private static function normalize_fileprice_delivery_target( $value ) {
    if ( is_numeric( $value ) ) {
        return (int) $value;
    }

    $normalized = function_exists( 'mb_strtolower' )
        ? mb_strtolower( trim( (string) $value ), 'UTF-8' )
        : strtolower( trim( (string) $value ) );

    $map = [
        'address'    => 0,
        'адрес'      => 0,
        'до адрес'   => 0,
        'office'     => 1,
        'офис'       => 1,
        'до офис'    => 1,
        'office2'    => 2,
        'automat'    => 2,
        'автомат'    => 2,
        'до автомат' => 2,
        'apt'        => 2,
    ];

    return array_key_exists( $normalized, $map ) ? $map[ $normalized ] : -1;
}

    public static function cities() {
        $data = [ 'userName' => speedy_username(), 'password' => speedy_password() ];

        $response = WS_Speedy_Request::raw_call(SPEEDY_API_BASE_URL . 'location/site/csv/' . SPEEDY_API_COUNTRY_ID, $data);

        file_put_contents( __DIR__ . '/cities.csv', $response );

        unset( $response );
        
        $cities = speedy_csv_to_arr( __DIR__ . '/cities.csv' );

        unlink( __DIR__ . '/cities.csv' );

        return $cities;
    }

    public static function offices() {
        $data = [ 'userName' => speedy_username(), 'password' => speedy_password() ];

        $response = WS_Speedy_Request::raw_call(SPEEDY_API_BASE_URL . 'location/office/', $data);

        file_put_contents( __DIR__ . '/offices.json', $response );

        unset( $response );

        $json = file_get_contents( __DIR__ . '/offices.json' );

        $json = json_decode( $json, true );

        unlink( __DIR__ . '/offices.json' );

        return $json['offices'];
    }

    public static function calculate_shipping( $data, $return_order_total = false ) {
        if( empty( $data ) ) {
            WC()->session->set( 'calculate_shipping_error', false );
            return 0;
        }

        if( WC()->session->get('chosen_shipping_methods')[0] !== 'speedy_shipping' ) {
            WC()->session->set('calculate_shipping_error', false);
            WC()->session->set('shipping_error_message', '');
            WC()->session->save_data();
            return 0;
        }

        if ( empty( $data['city_id'] ) && ! empty( $data['billing_city'] ) ) {
            $billing_city_raw = is_string( $data['billing_city'] ) ? trim( $data['billing_city'] ) : $data['billing_city'];

            // приемаме billing_city за city_id само ако е числова стойност
            if ( is_numeric( $billing_city_raw ) ) {
                $data['city_id'] = $billing_city_raw;
            }
        }

       
        $shipping_type = isset($data['billing_shipping_type']) ? (string) $data['billing_shipping_type'] : '';
        if ($shipping_type === '') {
            $shipping_type = 'address';
        }

        $billing_office_id = 0;
        if (!empty($data['billing_office'])) {
            $billing_office_id = (int) $data['billing_office'];
        } elseif (!empty($_REQUEST['billing_office'])) {
            $billing_office_id = (int) sanitize_text_field(wp_unslash($_REQUEST['billing_office']));
        }

        $billing_abroad_office_id = 0;
        if (!empty($data['billing_abroadoffice_id'])) {
            $billing_abroad_office_id = (int) $data['billing_abroadoffice_id'];
        } elseif (!empty($_REQUEST['billing_abroadoffice_id'])) {
            $billing_abroad_office_id = (int) sanitize_text_field(wp_unslash($_REQUEST['billing_abroadoffice_id']));
        }

        $has_office_pickup = ($billing_office_id > 0 || $billing_abroad_office_id > 0);

        // `billing_office` remains in WooCommerce's serialized checkout data after
        // switching to address delivery. The explicitly selected shipping type is
        // authoritative; a stale office value must not turn an address request into
        // an office request.

        $postcode_check = isset($data['billing_address_2']) ? trim($data['billing_address_2']) : (isset($_REQUEST['billing_address_2']) ? trim(wp_unslash($_REQUEST['billing_address_2'])) : '');
        $country_check = strtoupper(isset($data['billing_country']) ? $data['billing_country'] : (isset($_REQUEST['billing_country']) ? wp_unslash($_REQUEST['billing_country']) : ''));
        $is_foreign_postcode_only = ($country_check !== '' && $country_check !== 'BG' && $postcode_check !== '');
        if (empty($_REQUEST['city_id']) && empty($data['city_id']) && !$has_office_pickup && !$is_foreign_postcode_only) return 0;
        // Recipient
        $receiver_city_id = isset( $_REQUEST['city_id'] ) ? $_REQUEST['city_id'] : $data['city_id'];
        $receiver_city_id = intval( $receiver_city_id );

        // NEW: ако пак сме стигнали до 0, по-добре да прекъснем (чужбина без siteId)
        if ($shipping_type === 'address' && $receiver_city_id <= 0 && !$is_foreign_postcode_only) {
    WC()->session->set( 'calculate_shipping_error', true );
    WC()->session->set( 'shipping_error_message', 'Моля изберете населено място (siteId) за Speedy.' );
    WC()->session->save_data();
    return 0;
}

       

        if (str_contains_any($shipping_type, ['office', 'office2']) && !$has_office_pickup) {            WC()->session->set( 'calculate_shipping_error', true );
            WC()->session->set( 'shipping_error_message', '' );
            WC()->session->save_data();

            return 0;
        }


        // Sender
        $sender_id = speedy_get_setting( 'sender_id' );
        $sender_city_id   = speedy_get_setting( 'city_id' );
        $sender_office_id = speedy_get_setting( 'sender_office' );
        $sender_officeyesno = speedy_get_setting( 'sender_officeyesno' );

        $arr_sender = [
            'clientId' => $sender_id,
            'phone1' => ['number' => speedy_get_setting('sender_phone')],
            'contactName' => speedy_get_setting('sender_name'),
            'email' => speedy_get_setting('sender_email'),
        ];

        if ($sender_officeyesno === 'YES') {
            $arr_sender['dropoffOfficeId'] = $sender_office_id;
        }

        // Recipient
        $receiver_city_id = isset( $_REQUEST['city_id'] ) ? $_REQUEST['city_id'] : $data['city_id'];
        $receiver_city_id = intval( $receiver_city_id );
        $receiver_office_id = null;

        $company = $data['billing_company'];

        $arr_recipient = [
            'privatePerson' => empty($company), 
        ];

       $billing_address = $data['billing_address_1']; // Пример: "ул. Минзухар 33"

       $billing_neighborhood = $data['billing_neighborhood'];
            $billing_street         = $data['billing_street'];
            $billing_street_number  = $data['billing_street_number'];
            $billing_block          = $data['billing_block'];
            $billing_entrance       = $data['billing_entrance'];
            $billing_floor          = $data['billing_floor'];
            $billing_apartment      = $data['billing_apartment'];

        

        $arr_recipient = [
            'privatePerson' => empty($company), 
        ];

        //if( str_contains_any($shipping_type, ['office', 'office2']) ) {
        //    $arr_recipient['pickupOfficeId'] = intval( $data['billing_office'] );
        //} else {
        if ( str_contains_any( $shipping_type, ['office', 'office2'] ) ) {
            $pickupOfficeId = 0;

            if ( ! empty( $data['billing_abroadoffice_id'] ) ) {
                $pickupOfficeId = intval( $data['billing_abroadoffice_id'] );
            } elseif ( ! empty( $_REQUEST['billing_abroadoffice_id'] ) ) {
                $pickupOfficeId = intval( sanitize_text_field( wp_unslash( $_REQUEST['billing_abroadoffice_id'] ) ) );
            } elseif ( ! empty( $data['billing_office'] ) ) {
                $pickupOfficeId = intval( $data['billing_office'] );
            } elseif ( ! empty( $_REQUEST['billing_office'] ) ) {
                $pickupOfficeId = intval( sanitize_text_field( wp_unslash( $_REQUEST['billing_office'] ) ) );
            }

            if ( $pickupOfficeId > 0 ) {
                $arr_recipient['pickupOfficeId'] = $pickupOfficeId;
                if ( isset( $arr_recipient['addressLocation'] ) ) {
                    unset( $arr_recipient['addressLocation'] );
                }
            }
        } else {

            $billing_country_raw = '';
            if ( isset( $data['billing_country'] ) ) {
                $billing_country_raw = sanitize_text_field( $data['billing_country'] );
            } elseif ( isset( $_REQUEST['billing_country'] ) ) {
                $billing_country_raw = sanitize_text_field( wp_unslash( $_REQUEST['billing_country'] ) );
            } elseif ( function_exists( 'WC' ) && WC() && WC()->customer ) {
                $billing_country_raw = (string) WC()->customer->get_billing_country();
            }

            $billing_country_iso2 = self::resolve_country_iso2_from_input( $billing_country_raw );
            if ( $billing_country_iso2 === '' && preg_match( '/^[A-Za-z]{2}$/', (string) $billing_country_raw ) ) {
                $billing_country_iso2 = strtoupper( (string) $billing_country_raw );
            }

            // FIX: взимаме Speedy countryId директно от hidden input speedy_country_id (ако е подаден),
            // иначе fallback към търсене по ISO2.
            $speedy_country_id = 0;
            if ( isset( $_REQUEST['speedy_country_id'] ) ) {
                $speedy_country_id = (int) sanitize_text_field( wp_unslash( $_REQUEST['speedy_country_id'] ) );
            } elseif ( isset( $data['speedy_country_id'] ) ) {
                $speedy_country_id = (int) $data['speedy_country_id'];
            }

            if ( $speedy_country_id <= 0 && $billing_country_iso2 !== '' ) {
                $speedy_country_id = self::get_speedy_country_id_by_iso2( $billing_country_iso2 );
            }

            if ( $speedy_country_id <= 0 ) {
                $resolved_iso2 = '';
                $speedy_country_id = self::get_speedy_country_id_from_input( $billing_country_raw, $resolved_iso2 );
                if ( $billing_country_iso2 === '' && $resolved_iso2 !== '' ) {
                    $billing_country_iso2 = $resolved_iso2;
                }
            }

            $billing_country_iso2 = strtoupper( trim( (string) $billing_country_iso2 ) );
            $is_foreign_destination = ( $billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG' )
                || ( $speedy_country_id > 0 && $speedy_country_id !== 100 );

            $arr_recipient['addressLocation'] = [];

            if ( $receiver_city_id > 0 ) {
                $arr_recipient['addressLocation']['siteId'] = $receiver_city_id;
            }

            if ( $is_foreign_destination && $speedy_country_id > 0 ) {
                $arr_recipient['addressLocation']['countryId'] = (int) $speedy_country_id;
            }

            // За чужбина, когато няма siteId, подаваме postCode от billing_address_2.
            $postcode = '';
            if ( isset( $data['billing_address_2'] ) ) {
                $postcode = (string) $data['billing_address_2'];
            } elseif ( isset( $_REQUEST['billing_address_2'] ) ) {
                $postcode = (string) wp_unslash( $_REQUEST['billing_address_2'] );
            }
            $postcode = trim( sanitize_text_field( $postcode ) );

            if ( $is_foreign_destination && $postcode !== '' && $receiver_city_id <= 0 ) {
                $arr_recipient['addressLocation']['postCode'] = $postcode;
            }

        }



        // Get order data
        $order_weight          = 0;
        $module_default_weight = speedy_get_setting('teglo');

        if (is_cart() || is_checkout()) {

            // Тегло от количката (включва вариации)
            if (WC()->session->get('calculate_product_weight')) {
                $order_weight = (float) WC()->session->get('calculate_product_weight');
            } else {
                $order_weight = (float) WC()->cart->get_cart_contents_weight();
            }

            // Ако в количката теглото е 0 → fallback
            if ($order_weight <= 0) {
                if ($module_default_weight !== '' && $module_default_weight !== null) {
                    $order_weight = (float) $module_default_weight;
                } else {
                    $order_weight = (float) SPEEDY_API_DEFAULT_WEIGHT;
                }
            }

            // order_total се изчислява по-надолу – не пипаме тук

        } else {

            // Калкулация за единичен продукт (бутон за бърза калкулация и т.н.)
            $product_id           = isset($_REQUEST['product'])     ? (int) $_REQUEST['product']     : 0;
            $product_variation_id = isset($_REQUEST['prod_var_id']) ? (int) $_REQUEST['prod_var_id'] : 0;
            $quantity             = isset($_REQUEST['quantity'])    ? (float) $_REQUEST['quantity']  : 1;

            $single_weight = 0.0;
            $prod_price    = 0.0;

            if ($product_variation_id) {
                // Първо вариацията
                $variation = wc_get_product($product_variation_id);
                if ($variation) {
                    $single_weight = (float) $variation->get_weight();
                    $prod_price    = (float) $variation->get_price();
                }

                // Ако вариацията няма тегло → гледаме родителя
                if ($single_weight <= 0 && $product_id) {
                    $parent = wc_get_product($product_id);
                    if ($parent) {
                        $single_weight = (float) $parent->get_weight();
                        if ($prod_price <= 0) {
                            $prod_price = (float) $parent->get_price();
                        }
                    }
                }

            } else {
                // Обикновен продукт без вариация
                if ($product_id) {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $single_weight = (float) $product->get_weight();
                        $prod_price    = (float) $product->get_price();
                    }
                }
            }

            // Ако и продуктът/вариацията са без тегло → fallback към настройката / DEFAULT
            if ($single_weight <= 0) {
                if ($module_default_weight !== '' && $module_default_weight !== null) {
                    $single_weight = (float) $module_default_weight;
                } else {
                    $single_weight = (float) SPEEDY_API_DEFAULT_WEIGHT;
                }
            }

            $order_weight = $single_weight * max(1, $quantity);

            // order_total за бързата калкулация – пазим старата логика
            $order_total = $prod_price * max(1, $quantity);
        }

        $services_text = speedy_get_setting('uslugitext'); 

        $service_ids = array_map('intval', explode(',', $services_text));


        // saturday delivery
        if( speedy_get_setting('saturdayoption') == 'YES' ) {
            $arr_service = [
               'saturdayDelivery' => true,
            ];
             // Services
                $arr_service = [
                   'autoAdjustPickupDate' => true,
                   'serviceIds' => $service_ids,
                   'saturdayDelivery' => true,
                ];
        }
        else{
                 // Services
                $arr_service = [
                   'autoAdjustPickupDate' => true,
                   'serviceIds' => $service_ids,
                ];
        }

       if ( ! array_key_exists( 'payment_method', $data ) || empty( $data['payment_method'] ) ) {
            if ( ! empty( $_REQUEST['payment_method'] ) ) {
                $data['payment_method'] = sanitize_text_field( wp_unslash( $_REQUEST['payment_method'] ) );
            } elseif ( ! empty( $_REQUEST['post_data'] ) && is_string( $_REQUEST['post_data'] ) ) {
                parse_str( wp_unslash( $_REQUEST['post_data'] ), $parsed_post );
                if ( ! empty( $parsed_post['payment_method'] ) ) {
                    $data['payment_method'] = sanitize_text_field( $parsed_post['payment_method'] );
                }
            }
        }

        // Ако платежният метод е променен от COD → всички други, чистим грешката ВЕДНАГА
    if ( isset($data['payment_method']) || !empty($_REQUEST['payment_method']) ) {
        $current_pm = isset($data['payment_method']) ? $data['payment_method'] : '';
        if ( empty($current_pm) && !empty($_REQUEST['payment_method']) ) {
            $current_pm = sanitize_text_field( wp_unslash($_REQUEST['payment_method']) );
        }
        
        // Ако платежният метод е НЕ-COD, чистим старата COD грешка
        if ( $current_pm !== '' && $current_pm !== 'cod' ) {
            WC()->session->set('calculate_shipping_error', false);
            WC()->session->set('shipping_error_message', '');
            WC()->session->save_data();
        }
}

        // Cash on delivery
        $payment_method = isset($data['payment_method']) ? $data['payment_method'] : '';

        // Считаме за COD САМО ако изрично е избран метод 'cod'
        $is_cash_on_delivery = ($payment_method === 'cod');

        $requested_service_id = 0;
        if ( isset( $data['speedy_selected_service_id'] ) ) {
            $requested_service_id = (int) $data['speedy_selected_service_id'];
        } elseif ( isset( $_REQUEST['speedy_selected_service_id'] ) ) {
            $requested_service_id = (int) sanitize_text_field( wp_unslash( $_REQUEST['speedy_selected_service_id'] ) );
        } elseif ( ! empty( $_REQUEST['post_data'] ) && is_string( $_REQUEST['post_data'] ) ) {
            parse_str( wp_unslash( $_REQUEST['post_data'] ), $parsed_post );
            if ( ! empty( $parsed_post['speedy_selected_service_id'] ) ) {
                $requested_service_id = (int) $parsed_post['speedy_selected_service_id'];
            }
        }

        if ( ! $is_cash_on_delivery ) {
            $arr_payment['courierServicePayer'] = 'SENDER';

            if (isset($arr_service['additionalServices']['cod'])) {
                unset($arr_service['additionalServices']['cod']);
            }
        }

        if ( isset($is_foreign) && $is_foreign && WC()->cart && isset($arr_service['additionalServices']['cod']['amount']) ) {
            $totals = WC()->cart->get_totals();
          $shipping_with_vat = (float) ($totals['shipping_total'] ?? 0) + (float) ($totals['shipping_tax'] ?? 0);
$products_only = max(0, (float) $totals['total'] - $shipping_with_vat);
            $arr_service['additionalServices']['cod']['amount'] = round($products_only, 2);
        }

    
        // Ensure for foreign COD: payer must be SENDER and amount = products only
        if ( isset($is_foreign) && $is_foreign && isset($is_cash_on_delivery) && $is_cash_on_delivery ) {
            // force payer
            $arr_payment['courierServicePayer'] = 'SENDER';

            // ensure structure exists
            if (!isset($arr_service['additionalServices'])) {
                $arr_service['additionalServices'] = [];
            }
            if (!isset($arr_service['additionalServices']['cod'])) {
                $arr_service['additionalServices']['cod'] = [];
            }

            // Prefer cart totals (checkout/calc). Exclude shipping.
            if ( function_exists('WC') && WC()->cart ) {
                $totals = WC()->cart->get_totals();
                $shipping_with_vat = (float) ($totals['shipping_total'] ?? 0) + (float) ($totals['shipping_tax'] ?? 0);
$products_only = max(0, (float) $totals['total'] - $shipping_with_vat);
                $arr_service['additionalServices']['cod']['amount'] = round($products_only, 2);
            } elseif ( isset($order) && is_object($order) ) {
                // fallback for admin/order-based flows: sum line items (total + tax)
                $products_only_with_vat = 0.0;
                foreach ($order->get_items('line_item') as $item) {
                    $products_only_with_vat += (float) $item->get_total() + (float) $item->get_total_tax();
                }
                $arr_service['additionalServices']['cod']['amount'] = round($products_only_with_vat, 2);
            }
        }

        if ($is_cash_on_delivery) {
            // При преминаване на COD винаги чистим стария cod блок
            $arr_service['additionalServices']['cod'] = [];

            $processing_type = 'CASH';

            // ВИНАГИ: при COD искаме наложеният платеж да е само за продуктите (без доставка)
            $totals = WC()->cart->get_totals();

            $products_only_with_vat = 0.0;
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                $products_only_with_vat += (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
            }

            $cod_base_amount = $products_only_with_vat;

            $order_total = number_format( $cod_base_amount, 2, '.', '' );
            $cod_amount  = (float) $order_total;
            // Ако имаш надбавка към COD – добавяме я
            if (speedy_get_setting('cenadostavka') == 'nadbavka') {
                $cod_amount += (float) speedy_get_setting('suma_nadbavka');
            }

            $arr_service['additionalServices']['cod'] = [
                'amount'         => round($cod_amount, 2),
                'processingType' => $processing_type,
            ];

            // При COD не трябва да има стари fiscalReceiptItems от банков превод
            if (isset($arr_service['additionalServices']['cod']['fiscalReceiptItems'])) {
                unset($arr_service['additionalServices']['cod']['fiscalReceiptItems']);
            }

            if (speedy_get_setting('includeshippingprice') == 'YES') {
                $arr_service['additionalServices']['cod']['includeShippingPrice'] = true;
                $arr_payment = [
                    'courierServicePayer' => 'SENDER'
                ];
            }

            if (speedy_get_setting('vaucher') == 'YES') {
                $arr_service['additionalServices']['returns']['returnVoucher']['serviceId'] = 505;
                $arr_service['additionalServices']['returns']['returnVoucher']['payer'] = speedy_get_setting('vaucherpayer');
                $voucherPayerDays = speedy_get_setting('vaucherpayerdays');
                if (!empty($voucherPayerDays)) {
                    $arr_service['additionalServices']['returns']['returnVoucher']['validityPeriod'] = $voucherPayerDays;
                }
            }

            if (speedy_get_setting('dopalnitelni')) {
                $arr_service['additionalServices']['specialDeliveryId'] = speedy_get_setting('dopalnitelni');
            }

        }

        if ( speedy_get_setting('obqvena') == 'YES' ) {
            $declared_value_amount = 0.0;

            if ( function_exists('WC') && WC()->cart ) {
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    $declared_value_amount += (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
                }
            } else {
                $declared_value_amount = (float) $order_total;
            }

            if ( ! isset( $arr_service['additionalServices'] ) || ! is_array( $arr_service['additionalServices'] ) ) {
                $arr_service['additionalServices'] = [];
            }

            $arr_service['additionalServices']['declaredValue'] = [
                'amount'  => (float) round( $declared_value_amount, 2 ),
                'fragile' => ( speedy_get_setting('chuplivost') == 'YES' ),
            ];
        }

     
        // Options $shipping_type
        $test_before_pay = (string) speedy_get_setting('test_before_pay');
        $autoclose = (string) speedy_get_setting('autoclose');

        $should_send_obpd =
            in_array($test_before_pay, ['OPEN', 'TEST'], true)
            && ($shipping_type !== 'office2' || $autoclose === 'NO');

$obpd_return_service_id = 505;
$is_foreign_destination = ( $country_check !== '' && $country_check !== 'BG' );
$is_bg_destination = ! $is_foreign_destination;

$destination_request = [
    'userName'  => speedy_username(),
    'password'  => speedy_password(),
    'date'      => date('Y-m-d'),
    'recipient' => $arr_recipient,
];

$destination_response = WS_Speedy_Request::call(
    SPEEDY_API_BASE_URL . 'services/destination',
    $destination_request
);

$allowed_service_ids = [];
$destination_cod_policies = self::get_destination_cod_policies( $destination_response );
$destination_obpd_policies = self::get_destination_obpd_policies( $destination_response );
if (
    is_array($destination_response)
    && !empty($destination_response['services'])
    && is_array($destination_response['services'])
) {
    foreach ($destination_response['services'] as $service) {
        if ( ! is_array( $service ) ) {
            continue;
        }

        $service_id = self::get_destination_service_id( $service );
        if ( $service_id > 0 ) {
            $allowed_service_ids[] = $service_id;
        }
    }
}
$allowed_service_ids = array_values(array_unique(array_filter($allowed_service_ids)));

if ( $is_cash_on_delivery && speedy_get_setting( 'moneytransfer' ) === 'YES' ) {
    $precalc_cod_resolution = self::resolve_cod_processing_for_destination(
        $destination_cod_policies,
        $requested_service_id,
        'YES',
        $is_bg_destination
    );

    if ( $precalc_cod_resolution['status'] === 'forbidden' ) {
        WC()->session->set( 'calculate_shipping_error', true );
        WC()->session->set( 'shipping_error_message', $precalc_cod_resolution['message'] );
        WC()->session->set( 'shipping_speedy_available_services', [] );
        WC()->session->set( 'shipping_speedy_selected_service_id', 0 );
        WC()->session->save_data();
        return 0;
    }

    if (
        isset( $arr_service['additionalServices']['cod'] )
        && is_array( $arr_service['additionalServices']['cod'] )
    ) {
        $arr_service['additionalServices']['cod']['processingType'] = $precalc_cod_resolution['processingType'];
    }
}

if ($is_foreign_destination) {
    $foreign_candidates = array_values(array_filter($allowed_service_ids, static function($id) {
        return (int) $id !== 505;
    }));

    $obpd_return_service_id = !empty($foreign_candidates) ? (int) $foreign_candidates[0] : 0;
}
        if (
            $should_send_obpd
            && $obpd_return_service_id > 0
            && self::should_send_obpd_for_destination( $destination_obpd_policies, $requested_service_id )
        ) {
            if (!isset($arr_service['additionalServices']) || !is_array($arr_service['additionalServices'])) {
                $arr_service['additionalServices'] = [];
            }

            $arr_service['additionalServices']['obpd'] = [
                'option'                  => $test_before_pay,
                'returnShipmentServiceId' => $obpd_return_service_id,
                'returnShipmentPayer'     => speedy_get_setting('testplatec') ?: 'SENDER',
            ];
        }
        
        // Content
        $arr_content = [
            'parcelsCount' => 1,
            'totalWeight' => $order_weight
        ];

        // Payment
        if ( $is_cash_on_delivery ) {
            $arr_payment = [
                'courierServicePayer' => 'RECIPIENT' // (SENDER, RECIPIENT, THIRD_PARTY)
            ];
        } else {
            $arr_payment = [
                'courierServicePayer' => 'SENDER' // (SENDER, RECIPIENT, THIRD_PARTY)
            ];
        }


        if (speedy_get_setting('includeshippingprice') == 'YES') {
            $arr_payment = [
                'courierServicePayer' => 'SENDER' // (SENDER, RECIPIENT, THIRD_PARTY)
            ];
        }

        // Include administrativeFee
        if (speedy_get_setting('administrative') == 'YES') {
            $arr_payment['administrativeFee'] = true;  // Boolean true
        }

        // Handle free shpping
        $is_free_shipping = false;
        $is_nadbavka = false;
        $is_fixed_shipping_office = false;
        $is_fixed_shipping_automat = false;
        $is_fixed_shipping_address = false;

        if ( function_exists( 'WC' ) && WC()->cart ) {
            $order_total = speedy_get_cart_products_total_with_vat();
        } else {
            $order_total = isset( $order_total ) ? (float) $order_total : 0.0;
        }

       if ( speedy_get_setting( 'free_shipping' ) == 'yes' ) {
            $free_shipping_amount_office  = (float) speedy_get_setting( 'free_shipping_office' );
            $free_shipping_amount_automat = (float) speedy_get_setting( 'free_shipping_automat' );
            $free_shipping_amount_address = (float) speedy_get_setting( 'free_shipping_address' );

            if (
                ( $free_shipping_amount_office > 0 && $order_total >= $free_shipping_amount_office && $shipping_type == 'office' ) ||
                ( $free_shipping_amount_automat > 0 && $order_total >= $free_shipping_amount_automat && $shipping_type == 'office2' ) ||
                ( $free_shipping_amount_address > 0 && $order_total >= $free_shipping_amount_address && $shipping_type == 'address' )
            ) {
                $is_free_shipping = true;

                $arr_payment = [
                    'courierServicePayer' => 'SENDER' // (SENDER, RECIPIENT, THIRD_PARTY)
                ];
            }
        }


        if( !$is_free_shipping && speedy_get_setting( 'fixed_shipping' ) == 'yes' ) {
            $fixed_shipping_office = speedy_get_setting( 'fixed_shipping_office' );
            $fixed_shipping_automat = speedy_get_setting( 'fixed_shipping_automat' );
            $fixed_shipping_address = speedy_get_setting( 'fixed_shipping_address' );

            $order_total = number_format(WC()->cart->get_totals()['total'], 2);
            $subtotal = WC()->cart->get_subtotal(); 

           // За office
            if ($fixed_shipping_office > 0 && $shipping_type === 'office') {
                $arr_payment = [
                    'courierServicePayer' => 'SENDER'
                ];
               if ($is_cash_on_delivery && speedy_get_setting('cenadostavka') !== 'nadbavka') {
                $arr_service['additionalServices']['cod']['amount'] = $subtotal + $fixed_shipping_office;
                }
                $is_fixed_shipping_office = true;
            }

            // За office2 -> автомат
            if ($fixed_shipping_automat > 0 && $shipping_type === 'office2') {
                $arr_payment = [
                    'courierServicePayer' => 'SENDER'
                ];
            if ($is_cash_on_delivery && speedy_get_setting('cenadostavka') !== 'nadbavka') {
                    $arr_service['additionalServices']['cod']['amount'] = $subtotal + $fixed_shipping_automat;
                                    }
                $is_fixed_shipping_automat = true;
            }



            if($fixed_shipping_address > 0 && $shipping_type == 'address' ) {
                $arr_payment = [
                    'courierServicePayer' => 'SENDER' // (SENDER, RECIPIENT, THIRD_PARTY)
                ];
                            if ($is_cash_on_delivery && speedy_get_setting('cenadostavka') !== 'nadbavka') {
                $arr_service['additionalServices']['cod']['amount'] = $subtotal + $fixed_shipping_address;
                }
                $is_fixed_shipping_address = true;
            }
        }

        $filepricecost = 0;

       if ( speedy_get_setting( 'cenadostavka' ) == 'fileprices' ) {
            $subtotal = floatval(WC()->cart->get_subtotal()); 
            $order_total = floatval(WC()->cart->get_totals()['total']); // правилно число
            // Запазваме вече изчисленото тегло. То включва fallback към
            // настройката "teglo", когато всички продукти са без тегло.
            $file_path = get_option('speedy_fileceni_path');

            if ( $file_path && file_exists($file_path) ) {

                // Определяме TakeFromOffice според shipping_type
                $take_from_office = 0;
                if ($shipping_type === 'office') {
                    $take_from_office = 1;
                } elseif ($shipping_type === 'office2') {
                    $take_from_office = 2;
                } elseif ($shipping_type === 'address') {
                    $take_from_office = 0;
                }

                // Отваряме CSV файла
                if (($handle = fopen($file_path, "r")) !== false) {
                    // Пропускаме заглавния ред
                    fgetcsv($handle);

                    $best_fit_price = null;
                    $best_fit_order_total = null;

                    while (($data = fgetcsv($handle)) !== false) {
                        list($service_id, $csv_take_from_office, $csv_weight, $csv_order_total, $csv_price) = $data;

                       if (
                           self::normalize_fileprice_delivery_target( $csv_take_from_office ) === $take_from_office &&
                            $order_weight <= floatval($csv_weight) &&
                            $subtotal <= floatval($csv_order_total)
                        ){
                            // Вземаме реда с най-малък csv_order_total, който покрива поръчката
                            if ($best_fit_order_total === null || floatval($csv_order_total) < $best_fit_order_total) {
                                $best_fit_order_total = floatval($csv_order_total);
                                $best_fit_price = floatval($csv_price);
                            }
                        }
                    }
                    fclose($handle);

                    if ($best_fit_price !== null) {
                        $cost = number_format($best_fit_price, 2, '.', '');
                        $filepricecost = number_format($best_fit_price, 2, '.', '');
                    } else {
                        // fallback – ако не намерим ред, взимаме API цена
                        $cost = 1;
                        $filepricecost = 1;
                    }

                    $arr_payment = [
                        'courierServicePayer' => 'SENDER'
                    ];
                    $arr_delivery_data['content']['totalWeight'] = $order_weight;

                  if ( $is_cash_on_delivery && speedy_get_setting('cenadostavka') !== 'nadbavka' ) {
$arr_service['additionalServices']['cod']['amount'] = $subtotal + $filepricecost;
}
                }
            }
        }

        $uses_non_speedy_shipping_price = (
            $is_free_shipping
            || $is_fixed_shipping_office
            || $is_fixed_shipping_automat
            || $is_fixed_shipping_address
            || speedy_get_setting( 'cenadostavka' ) == 'fileprices'
        );

        if (
            $uses_non_speedy_shipping_price
            && isset( $arr_service['additionalServices']['cod']['includeShippingPrice'] )
        ) {
            unset( $arr_service['additionalServices']['cod']['includeShippingPrice'] );
        }

        self::sync_declared_value_with_cod( $arr_service );


        // Group delivery data
        $arr_delivery_data = [
            'sender' => $arr_sender,
            'recipient' => $arr_recipient,
            'service' => $arr_service,
            'content' => $arr_content,
            'payment' => $arr_payment
        ];
        $arr_delivery_data['content']['totalWeight'] = $order_weight;

        // NEW: определяме реален Speedy countryId (за чужбина) и го слагаме в addressLocation
        $billing_country_raw = '';
        if ( isset( $data['billing_country'] ) ) {
            $billing_country_raw = (string) $data['billing_country'];
        } elseif ( isset( $_REQUEST['billing_country'] ) ) {
            $billing_country_raw = sanitize_text_field( wp_unslash( $_REQUEST['billing_country'] ) );
        } elseif ( function_exists( 'WC' ) && WC() && WC()->customer ) {
            $billing_country_raw = (string) WC()->customer->get_billing_country();
        }
        $billing_country_iso2 = self::resolve_country_iso2_from_input( $billing_country_raw );
        if ( $billing_country_iso2 === '' && preg_match( '/^[A-Za-z]{2}$/', (string) $billing_country_raw ) ) {
            $billing_country_iso2 = strtoupper( (string) $billing_country_raw );
        }
        $billing_country_iso2 = strtoupper( trim( (string) $billing_country_iso2 ) );

        $speedy_country_id = 0;
        if ( isset( $_REQUEST['speedy_country_id'] ) ) {
            $speedy_country_id = (int) sanitize_text_field( wp_unslash( $_REQUEST['speedy_country_id'] ) );
        } elseif ( isset( $data['speedy_country_id'] ) ) {
            $speedy_country_id = (int) $data['speedy_country_id'];
        }

        if ( $speedy_country_id <= 0 && $billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG' ) {
            $speedy_country_id = (int) self::get_speedy_country_id_by_iso2( $billing_country_iso2 );
        }

        if ( $speedy_country_id <= 0 ) {
            $resolved_iso2 = '';
            $speedy_country_id = (int) self::get_speedy_country_id_from_input( $billing_country_raw, $resolved_iso2 );
            if ( $billing_country_iso2 === '' && $resolved_iso2 !== '' ) {
                $billing_country_iso2 = strtoupper( $resolved_iso2 );
            }
        }

      
     

// стана:
$is_foreign = ( $speedy_country_id > 0 && $speedy_country_id !== 100 )
              || ( $billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG' );



$parcel_width  = 0.0;
$parcel_depth  = 0.0;
$parcel_height = 0.0;
$max_volume    = 0.0;

$try_product_dimensions = static function( $product ) use ( &$parcel_width, &$parcel_depth, &$parcel_height, &$max_volume ) {
    if ( ! $product || ! is_object( $product ) ) {
        return;
    }

    $w = (float) $product->get_width();
    $d = (float) $product->get_length();
    $h = (float) $product->get_height();

    if ( $w <= 0 || $d <= 0 || $h <= 0 ) {
        return;
    }

    // Най-голям артикул по обем
    $volume = $w * $d * $h;

    if ( $volume > $max_volume ) {
        $max_volume    = $volume;
        $parcel_width  = $w;
        $parcel_depth  = $d;
        $parcel_height = $h;
    }
};

if ( function_exists( 'WC' ) && WC()->cart && ( is_cart() || is_checkout() ) ) {
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

        $try_product_dimensions( $product );

        if (
            $product
            && is_object( $product )
            && method_exists( $product, 'is_type' )
            && $product->is_type( 'variation' )
        ) {
            $parent_id = (int) $product->get_parent_id();
            if ( $parent_id > 0 ) {
                $parent = wc_get_product( $parent_id );
                $try_product_dimensions( $parent );
            }
        }
    }
} else {
    $product_id   = isset( $_REQUEST['product'] ) ? (int) $_REQUEST['product'] : 0;
    $variation_id = isset( $_REQUEST['prod_var_id'] ) ? (int) $_REQUEST['prod_var_id'] : 0;

    $product = null;
    if ( $variation_id > 0 ) {
        $product = wc_get_product( $variation_id );
    } elseif ( $product_id > 0 ) {
        $product = wc_get_product( $product_id );
    }

    $try_product_dimensions( $product );

    if (
        $product
        && is_object( $product )
        && method_exists( $product, 'is_type' )
        && $product->is_type( 'variation' )
    ) {
        $parent_id = (int) $product->get_parent_id();
        if ( $parent_id > 0 ) {
            $parent = wc_get_product( $parent_id );
            $try_product_dimensions( $parent );
        }
    }
}

// Подаваме parcels за всички държави, ако имаме валидни размери
if ( $parcel_width > 0 && $parcel_depth > 0 && $parcel_height > 0 ) {
    $arr_delivery_data['content']['parcelsCount'] = 1;
    $arr_delivery_data['content']['parcels'] = [
        [
            'seqNo' => 1,
            'size' => [
                'width'  => $parcel_width,
                'depth'  => $parcel_depth,
                'height' => $parcel_height,
            ],
            'weight' => (float) $order_weight,
        ],
    ];
} else {
    unset( $arr_delivery_data['content']['parcels'] );
}

// Запазваме строгото изискване за чужбина
if ( $is_foreign && ! isset( $arr_delivery_data['content']['parcels'] ) ) {
    WC()->session->set( 'calculate_shipping_error', true );
    WC()->session->set( 'shipping_error_message', 'Изискват се описани размери на пакета' );
    WC()->session->save_data();

    return 0;
}

        // NEW: за чужбина правим cod.amount = само продуктите (без shipping), дори при НЕ-COD
        if ( $is_foreign && WC()->cart ) {
            $totals = WC()->cart->get_totals();

           $shipping_with_vat = (float) ($totals['shipping_total'] ?? 0) + (float) ($totals['shipping_tax'] ?? 0);
$products_only = max(0, (float) $totals['total'] - $shipping_with_vat);

            if ( isset( $arr_service['additionalServices']['cod']['amount'] ) ) {
                $arr_service['additionalServices']['cod']['amount'] = round( $products_only, 2 );
            }
        }

        $mode = speedy_get_setting('moneytransfer');

        // NEW: за чужбина НЕ позволяваме fiscal/fiscalone
        if ( $is_foreign && ( $mode === 'fiscal' || $mode === 'fiscalone' ) ) {
            $mode = 'NO';
        }


    
        $processing_surcharge_with_vat = 0.0;
        if (
            $is_cash_on_delivery
            && speedy_get_setting('cenadostavka') === 'nadbavka'
        ) {
            $processing_surcharge_with_vat = max(0, (float) speedy_get_setting('suma_nadbavka'));
        }

        $configured_fiscal_shipping_amount = 0.0;
        if ( $is_cash_on_delivery && speedy_get_setting( 'cenadostavka' ) === 'fixedprices' ) {
            $configured_fiscal_shipping_amount = speedy_get_configured_shipping_amount_by_type( $shipping_type );
        }

        //
        // 1) РЕЖИМ FISCAL — всеки продукт на отделен ред
        //
        if ( $is_cash_on_delivery && $mode === 'fiscal' ) {

            $fiscal_items = [];
            $products_total_with_vat = 0.0;

            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

                $product = $cart_item['data'];
                $name    = $product->get_name();
                $qty     = $cart_item['quantity'];

                // Добавяме (x qty) към името
                $display_name = $name . ' (x' . $qty . ')';

                // Ограничаваме описанието до 50 символа
                $description = mb_substr( $display_name, 0, 50 );

                // Данъчна група
                $tax_class = $product->get_tax_class();
                $vat_group = 'Б';
                $vat_rate  = 0.20;

                if ( $tax_class === 'zero-rate' ) {
                    $vat_group = 'А';
                    $vat_rate  = 0.00;
                } elseif ( $tax_class === 'reduced-rate' ) {
                    $vat_group = 'Г';
                    $vat_rate  = 0.09;
                }

                // Крайна цена с ДДС
                $price_incl_vat = (float) $product->get_price();

                // Цена без ДДС
                $price_excl_vat = $vat_rate > 0
                    ? $price_incl_vat / (1 + $vat_rate)
                    : $price_incl_vat;

                $line_amount_with_vat = round( $price_incl_vat * $qty, 2 );
                $line_amount_ex_vat   = round( $price_excl_vat * $qty, 2 );

                // Натрупваме общата сума на продуктите (с ДДС)
                $products_total_with_vat += $line_amount_with_vat;

                // Добавяме ред
                $fiscal_items[] = [
                    'description'   => $description,
                    'vatGroup'      => $vat_group,
                    'amount'        => $line_amount_ex_vat,
                    'amountWithVat' => $line_amount_with_vat,
                ];
            }

            /**
             * Добавяме отделен ред "Delivery" САМО когато:
             * - НЕ е избран наложен платеж (COD);
             * - имаме зададен cod.amount;
             * - cod.amount е по-голям от сумата на продуктовите редове.
             */
            if (
    $is_cash_on_delivery &&
    isset( $arr_delivery_data['service']['additionalServices']['cod']['amount'] )
) {
                $cod_amount = (float) $arr_delivery_data['service']['additionalServices']['cod']['amount'];

                $should_add_shipping_row = ! empty( $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] )
                    || $configured_fiscal_shipping_amount > 0;

                $shipping_amount_with_vat = $configured_fiscal_shipping_amount > 0
                    ? $configured_fiscal_shipping_amount
                    : max( 0, $cod_amount - $processing_surcharge_with_vat - $products_total_with_vat );

                if ( $should_add_shipping_row && $shipping_amount_with_vat > 0 ) {
                    // Приемаме 20% ДДС за доставката – група "Б"
                    $shipping_vat_rate      = 0.20;
                    $shipping_amount_ex_vat = $shipping_amount_with_vat / ( 1 + $shipping_vat_rate );

                    $fiscal_items[] = [
                        'description'   => 'Delivery',
                        'vatGroup'      => 'Б',
                        'amount'        => round( $shipping_amount_ex_vat, 2 ),
                        'amountWithVat' => round( $shipping_amount_with_vat, 2 ),
                    ];

                    // Обновяваме сумата на редовете с ДДС (продукти + доставка)
                    $products_total_with_vat += round( $shipping_amount_with_vat, 2 );
                }

                
                if ($processing_surcharge_with_vat > 0) {
                    $surcharge_vat_rate = 0.20;
                    $surcharge_ex_vat   = $processing_surcharge_with_vat / (1 + $surcharge_vat_rate);

                    $fiscal_items[] = [
                        'description'   => 'Надбавка за обработка',
                        'vatGroup'      => 'Б',
                        'amount'        => round($surcharge_ex_vat, 2),
                        'amountWithVat' => round($processing_surcharge_with_vat, 2),
                    ];

                    $products_total_with_vat += round($processing_surcharge_with_vat, 2);
                }
            }

            // Пропорционално намаление при ваучер/промокод:
            // Включено само ако includeshippingprice == NO (за да не „режем“ доставка)
            if (
                $is_cash_on_delivery
                && speedy_get_setting('includeshippingprice') !== 'YES'
                && isset($arr_delivery_data['service']['additionalServices']['cod']['amount'])
                && $products_total_with_vat > 0
            ) {
                $target_cod = (float) $arr_delivery_data['service']['additionalServices']['cod']['amount'];
                if ($target_cod < $products_total_with_vat) {
                    $ratio = $target_cod / $products_total_with_vat;

                    // Скалираме всеки ред
                    $scaled_total = 0.0;
                    foreach ($fiscal_items as $i => $it) {
                        $new_with_vat = round($it['amountWithVat'] * $ratio, 2);

                        // Ако имаме данъчна ставка, преизчисляваме и exVAT пропорционално
                        // (За да няма отклонения при различни групи)
                        $new_ex_vat = round($it['amount'] * $ratio, 2);

                        $fiscal_items[$i]['amountWithVat'] = $new_with_vat;
                        $fiscal_items[$i]['amount']        = $new_ex_vat;

                        $scaled_total += $new_with_vat;
                    }

                    // Корекция на последния ред заради закръгляния
                    $diff = round($target_cod - $scaled_total, 2);
                    if (abs($diff) >= 0.01) {
                        $last = count($fiscal_items) - 1;
                        $fiscal_items[$last]['amountWithVat'] = round($fiscal_items[$last]['amountWithVat'] + $diff, 2);

                        // Пресмятаме exVAT на база групата:
                        $vg = $fiscal_items[$last]['vatGroup'];
                        $vat_rate = ($vg === 'А') ? 0.00 : (($vg === 'Г') ? 0.09 : 0.20);
                        $fiscal_items[$last]['amount'] = $vat_rate > 0
                            ? round($fiscal_items[$last]['amountWithVat'] / (1 + $vat_rate), 2)
                            : $fiscal_items[$last]['amountWithVat'];
                    }
                }
            }

            // Задаваме редовете към COD
            $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;

            // ВАЖНО:
            // ЗАВИНАГИ изравняваме cod.amount със сбора на amountWithVat от всички редове,
            // за да няма mismatch в Speedy (независимо дали е COD или не).
            $total_receipt_with_vat = 0.0;
            foreach ( $fiscal_items as $it ) {
                if ( isset( $it['amountWithVat'] ) ) {
                    $total_receipt_with_vat += (float) $it['amountWithVat'];
                }
            }
            $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round( $total_receipt_with_vat, 2 );
        }


        //
        // 2) РЕЖИМ FISCALONE — групиране по ДДС групи
        //
       if ( $is_cash_on_delivery && $mode === 'fiscalone' ) {

            // Инициализация на групи: екс (без ДДС) и ин (с ДДС)
            $groups = [
                'А' => ['ex' => 0, 'in' => 0], // 0%
                'Г' => ['ex' => 0, 'in' => 0], // 9%
                'Б' => ['ex' => 0, 'in' => 0], // 20%
            ];

            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

                $product = $cart_item['data'];
                $qty     = $cart_item['quantity'];

                // Данъчна група
                $tax_class = $product->get_tax_class();
                $vat_group = 'Б';
                $vat_rate  = 0.20;

                if ( $tax_class === 'zero-rate' ) {
                    $vat_group = 'А';
                    $vat_rate  = 0.00;
                } elseif ( $tax_class === 'reduced-rate' ) {
                    $vat_group = 'Г';
                    $vat_rate  = 0.09;
                }

                // Цена с ДДС
                $price_incl_vat = (float) $product->get_price();

                // Цена без ДДС
                $price_excl_vat = $vat_rate > 0
                    ? $price_incl_vat / (1 + $vat_rate)
                    : $price_incl_vat;

                // Групиране
                $groups[$vat_group]['ex'] += $price_excl_vat * $qty;
                $groups[$vat_group]['in'] += $price_incl_vat * $qty;
            }

            // Финални редове
            $fiscal_items           = [];
            $products_total_with_vat = 0.0;

            foreach ($groups as $group => $sum) {

                if ($sum['in'] <= 0) continue;

                $products_total_with_vat += $sum['in'];

                $fiscal_items[] = [
                    'description'   => 'Продукти от поръчка (група ' . $group . ')',
                    'vatGroup'      => $group,
                    'amount'        => round($sum['ex'], 2),
                    'amountWithVat' => round($sum['in'], 2),
                ];
            }

            /**
             * Добавяме ред "Delivery" при НЕ‑COD,
             * така че сборът на редовете (по ДДС групи) + доставка да е равен на cod.amount.
             */
           if (
    $is_cash_on_delivery &&
    isset( $arr_delivery_data['service']['additionalServices']['cod']['amount'] )
) {
                $cod_amount = (float) $arr_delivery_data['service']['additionalServices']['cod']['amount'];

                $should_add_shipping_row = ! empty( $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] )
                    || $configured_fiscal_shipping_amount > 0;

                $shipping_amount_with_vat = $configured_fiscal_shipping_amount > 0
                    ? $configured_fiscal_shipping_amount
                    : max( 0, $cod_amount - $processing_surcharge_with_vat - $products_total_with_vat );

                if ( $should_add_shipping_row && $shipping_amount_with_vat > 0 ) {
                    $shipping_vat_rate      = 0.20;
                    $shipping_amount_ex_vat = $shipping_amount_with_vat / ( 1 + $shipping_vat_rate );

                    $fiscal_items[] = [
                        'description'   => 'Delivery',
                        'vatGroup'      => 'Б',
                        'amount'        => round( $shipping_amount_ex_vat, 2 ),
                        'amountWithVat' => round( $shipping_amount_with_vat, 2 ),
                    ];

                    $products_total_with_vat += round( $shipping_amount_with_vat, 2 );
                }

                       
                if ($processing_surcharge_with_vat > 0) {
                    $surcharge_vat_rate = 0.20;
                    $surcharge_ex_vat   = $processing_surcharge_with_vat / (1 + $surcharge_vat_rate);

                    $fiscal_items[] = [
                        'description'   => 'Надбавка за обработка',
                        'vatGroup'      => 'Б',
                        'amount'        => round($surcharge_ex_vat, 2),
                        'amountWithVat' => round($processing_surcharge_with_vat, 2),
                    ];

                    $products_total_with_vat += round($processing_surcharge_with_vat, 2);
                }
            }

            // Пропорционално намаление при ваучер/промокод (по групи)
            if (
                $is_cash_on_delivery
                && speedy_get_setting('includeshippingprice') !== 'YES'
                && isset($arr_delivery_data['service']['additionalServices']['cod']['amount'])
                && $products_total_with_vat > 0
            ) {
                $target_cod = (float) $arr_delivery_data['service']['additionalServices']['cod']['amount'];
                if ($target_cod < $products_total_with_vat) {
                    $ratio = $target_cod / $products_total_with_vat;

                    $scaled_total = 0.0;
                    foreach ($fiscal_items as $i => $it) {
                        $new_with_vat = round($it['amountWithVat'] * $ratio, 2);
                        $new_ex_vat   = round($it['amount'] * $ratio, 2);

                        $fiscal_items[$i]['amountWithVat'] = $new_with_vat;
                        $fiscal_items[$i]['amount']        = $new_ex_vat;

                        $scaled_total += $new_with_vat;
                    }

                    // Корекция на последния ред заради закръгляния
                    $diff = round($target_cod - $scaled_total, 2);
                    if (abs($diff) >= 0.01) {
                        $last = count($fiscal_items) - 1;
                        $fiscal_items[$last]['amountWithVat'] = round($fiscal_items[$last]['amountWithVat'] + $diff, 2);

                        // Преизчисляваме exVAT според групата на последния ред
                        $vg = $fiscal_items[$last]['vatGroup'];
                        $vat_rate = ($vg === 'А') ? 0.00 : (($vg === 'Г') ? 0.09 : 0.20);
                        $fiscal_items[$last]['amount'] = $vat_rate > 0
                            ? round($fiscal_items[$last]['amountWithVat'] / (1 + $vat_rate), 2)
                            : $fiscal_items[$last]['amountWithVat'];
                    }
                }
            }

            // Задаваме редовете към COD
            $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;

            // Изравняваме cod.amount със сбора на amountWithVat от всички редове
            $total_receipt_with_vat = 0.0;
            foreach ( $fiscal_items as $it ) {
                if ( isset( $it['amountWithVat'] ) ) {
                    $total_receipt_with_vat += (float) $it['amountWithVat'];
                }
            }
            $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round( $total_receipt_with_vat, 2 );
        }

    
if ( isset( $is_foreign ) && $is_foreign ) {
    if ( empty( $is_cash_on_delivery ) || ! $is_cash_on_delivery ) {
        if ( isset( $arr_delivery_data['service']['additionalServices']['cod'] ) ) {
            unset( $arr_delivery_data['service']['additionalServices']['cod'] );
        }

        if (
            isset( $arr_delivery_data['service']['additionalServices'] )
            && empty( $arr_delivery_data['service']['additionalServices'] )
        ) {
            unset( $arr_delivery_data['service']['additionalServices'] );
        }
    } else {
        if ( ! isset( $arr_delivery_data['payment'] ) || ! is_array( $arr_delivery_data['payment'] ) ) {
            $arr_delivery_data['payment'] = [];
        }
        $arr_delivery_data['payment']['courierServicePayer'] = 'SENDER';

        if ( ! isset( $arr_delivery_data['service']['additionalServices'] ) || ! is_array( $arr_delivery_data['service']['additionalServices'] ) ) {
            $arr_delivery_data['service']['additionalServices'] = [];
        }
        if ( ! isset( $arr_delivery_data['service']['additionalServices']['cod'] ) || ! is_array( $arr_delivery_data['service']['additionalServices']['cod'] ) ) {
            $arr_delivery_data['service']['additionalServices']['cod'] = [];
        }

        $products_only = 0.0;

        $cod_amount = 0.0;

        if ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                $cod_amount += (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
            }
        } elseif ( isset( $arr_delivery_data['service']['additionalServices']['cod']['amount'] ) ) {
            $cod_amount = (float) $arr_delivery_data['service']['additionalServices']['cod']['amount'];
        }

        $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round( $cod_amount, 2 );
    }
}


if ( ! $is_cash_on_delivery ) {
    if ( isset( $arr_delivery_data['service']['additionalServices']['cod'] ) ) {
        unset( $arr_delivery_data['service']['additionalServices']['cod'] );
    }

    if (
        isset( $arr_delivery_data['service']['additionalServices'] ) &&
        empty( $arr_delivery_data['service']['additionalServices'] )
    ) {
        unset( $arr_delivery_data['service']['additionalServices'] );
    }
}


    
        if ( $is_foreign && $is_cash_on_delivery ) {
            $target_currency = '';

            $wc_countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [];
            $country_name = isset( $wc_countries[ $billing_country_iso2 ] ) ? $wc_countries[ $billing_country_iso2 ] : '';

            $speedy_country_name_overrides = [
                'US' => 'САЩ',
            ];

            if ( isset( $speedy_country_name_overrides[ $billing_country_iso2 ] ) ) {
                $country_name = $speedy_country_name_overrides[ $billing_country_iso2 ];
            }

            if ( $country_name !== '' ) {
                $country_response = WS_Speedy_Request::call(
                    SPEEDY_API_BASE_URL . 'location/country',
                    [
                        'userName' => speedy_username(),
                        'password' => speedy_password(),
                        'name'     => $country_name,
                    ]
                );

                if ( is_array( $country_response ) && ! empty( $country_response['countries'] ) && is_array( $country_response['countries'] ) ) {
                    foreach ( $country_response['countries'] as $country ) {
                        if (
                            ! empty( $country['isoAlpha2'] )
                            && strtoupper( (string) $country['isoAlpha2'] ) === $billing_country_iso2
                        ) {
                            if ( ! empty( $country['currencyCode'] ) ) {
                                $target_currency = strtoupper( trim( (string) $country['currencyCode'] ) );
                            } elseif ( ! empty( $country['currency']['code'] ) ) {
                                $target_currency = strtoupper( trim( (string) $country['currency']['code'] ) );
                            }
                            break;
                        }
                    }
                }
            }

            if ( $target_currency === '' ) {
                WC()->session->set( 'calculate_shipping_error', true );
                WC()->session->set( 'shipping_error_message', 'Не може да използвате Наложен платеж, валутата за държавата липсва. Моля обърнете се към администраторите на магазина!' );
                WC()->session->set( 'shipping_speedy_available_services', [] );
                WC()->session->set( 'shipping_speedy_selected_service_id', 0 );
                WC()->session->save_data();
                return 0;
            }

            $target_rate = self::fx_per_eur( $target_currency );

            if ( $target_rate <= 0 ) {
                WC()->session->set( 'calculate_shipping_error', true );
                WC()->session->set( 'shipping_error_message', sprintf(
                    'Не може да използвате Наложен платеж, валутата %s липсва. Моля обърнете се към администраторите на магазина!',
                    $target_currency
                ) );
                WC()->session->set( 'shipping_speedy_available_services', [] );
                WC()->session->set( 'shipping_speedy_selected_service_id', 0 );
                WC()->session->save_data();
                return 0;
            }

            $store_currency = strtoupper( get_woocommerce_currency() );
            $current_amount = isset( $arr_delivery_data['service']['additionalServices']['cod']['amount'] )
                ? (float) $arr_delivery_data['service']['additionalServices']['cod']['amount']
                : 0.0;

            if ( $current_amount > 0 && $store_currency !== $target_currency ) {
                $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round(
                    self::convert_via_eur( $current_amount, $store_currency, $target_currency ),
                    2
                );
            }
        }
        // Save delivery data to session
        WC()->session->set( 'shipping_speedy_shipping', $arr_delivery_data );

        // Add Speedy credentials
        $arr_delivery_data['userName'] = speedy_username();
        $arr_delivery_data['password'] = speedy_password();
   

        // Calculate
        $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'calculate/', $arr_delivery_data);

       
$selected_calculation = null;
$first_valid_calculation = null;
$selected_service_error = '';
$available_services = [];

if (is_array($response) && !empty($response['calculations']) && is_array($response['calculations'])) {
    foreach ($response['calculations'] as $calc) {
        if (!is_array($calc)) {
            continue;
        }

        $service_id = isset($calc['serviceId']) ? (int) $calc['serviceId'] : 0;

        if (!empty($calc['error'])) {
            if ($requested_service_id > 0 && $service_id === $requested_service_id) {
                $selected_service_error = isset($calc['error']['message']) ? (string) $calc['error']['message'] : '';
            }
            continue;
        }

        if (empty($calc['price']) || !isset($calc['price']['total'])) {
            continue;
        }

        $total    = (float) $calc['price']['total'];
        $currency = isset($calc['price']['currency']) ? (string) $calc['price']['currency'] : '';

        $available_services[] = [
            'serviceId' => $service_id,
            'total'     => $total,
            'currency'  => $currency,
        ];

        if ($first_valid_calculation === null) {
            $first_valid_calculation = $calc;
        }

        if ($requested_service_id > 0 && $service_id === $requested_service_id) {
            $selected_calculation = $calc;
        }
    }
}

// Ако няма ръчно избрана услуга, взимаме първата валидна от API
if ($selected_calculation === null && $requested_service_id <= 0 && $first_valid_calculation !== null) {
    $selected_calculation = $first_valid_calculation;
}

$selected_service_id = (
    is_array($selected_calculation) && isset($selected_calculation['serviceId'])
)
    ? (int) $selected_calculation['serviceId']
    : 0;

if ( $is_cash_on_delivery && $selected_service_id > 0 ) {
    $selected_cod_policy = isset( $destination_cod_policies[ $selected_service_id ] )
        ? $destination_cod_policies[ $selected_service_id ]
        : [];

    $selected_cod_resolution = self::resolve_cod_processing_for_policy(
        (string) speedy_get_setting( 'moneytransfer' ),
        ! $is_foreign,
        $selected_cod_policy
    );

    if ( $selected_cod_resolution['status'] === 'forbidden' ) {
        WC()->session->set( 'calculate_shipping_error', true );
        WC()->session->set( 'shipping_error_message', $selected_cod_resolution['message'] );
        WC()->session->set( 'shipping_speedy_available_services', [] );
        WC()->session->set( 'shipping_speedy_selected_service_id', 0 );
        WC()->session->save_data();
        return 0;
    }

    if (
        isset( $arr_delivery_data['service']['additionalServices']['cod'] )
        && is_array( $arr_delivery_data['service']['additionalServices']['cod'] )
    ) {
        $arr_delivery_data['service']['additionalServices']['cod']['processingType'] = $selected_cod_resolution['processingType'];
    }
}

if ( $selected_service_id > 0 ) {
    $selected_obpd_policy = isset( $destination_obpd_policies[ $selected_service_id ] )
        ? $destination_obpd_policies[ $selected_service_id ]
        : [];

    if (
        ! empty( $selected_obpd_policy['forbidden'] )
        && isset( $arr_delivery_data['service']['additionalServices']['obpd'] )
    ) {
        unset( $arr_delivery_data['service']['additionalServices']['obpd'] );

        if ( empty( $arr_delivery_data['service']['additionalServices'] ) ) {
            unset( $arr_delivery_data['service']['additionalServices'] );
        }
    }
}


if (
    $is_cash_on_delivery
    && speedy_get_setting( 'includeshippingprice' ) == 'YES'
    && ! $uses_non_speedy_shipping_price
    && $selected_calculation
    && $selected_service_id > 0
    && isset( $selected_calculation['price']['total'] )
) {
    $products_only_with_vat = 0.0;

    if ( function_exists( 'WC' ) && WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $products_only_with_vat += (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
        }
    }

    $second_pass_cod_amount = $products_only_with_vat;

    if ( speedy_get_setting( 'cenadostavka' ) == 'nadbavka' ) {
        $second_pass_cod_amount += (float) speedy_get_setting( 'suma_nadbavka' );
    }

    if ( ! isset( $arr_delivery_data['service'] ) || ! is_array( $arr_delivery_data['service'] ) ) {
        $arr_delivery_data['service'] = [];
    }

    $arr_delivery_data['service']['serviceId'] = $selected_service_id;

    if (
        ! isset( $arr_delivery_data['service']['additionalServices'] )
        || ! is_array( $arr_delivery_data['service']['additionalServices'] )
    ) {
        $arr_delivery_data['service']['additionalServices'] = [];
    }

    if (
        ! isset( $arr_delivery_data['service']['additionalServices']['cod'] )
        || ! is_array( $arr_delivery_data['service']['additionalServices']['cod'] )
    ) {
        $arr_delivery_data['service']['additionalServices']['cod'] = [];
    }

    if ( $is_foreign ) {
        $target_currency = '';

        $wc_countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [];
        $country_name = isset( $wc_countries[ $billing_country_iso2 ] ) ? $wc_countries[ $billing_country_iso2 ] : '';

        $speedy_country_name_overrides = [
            'US' => 'САЩ',
        ];

        if ( isset( $speedy_country_name_overrides[ $billing_country_iso2 ] ) ) {
            $country_name = $speedy_country_name_overrides[ $billing_country_iso2 ];
        }

        if ( $country_name !== '' ) {
            $country_response = WS_Speedy_Request::call(
                SPEEDY_API_BASE_URL . 'location/country',
                [
                    'userName' => speedy_username(),
                    'password' => speedy_password(),
                    'name'     => $country_name,
                ]
            );

            if ( is_array( $country_response ) && ! empty( $country_response['countries'] ) && is_array( $country_response['countries'] ) ) {
                foreach ( $country_response['countries'] as $country ) {
                    if (
                        ! empty( $country['isoAlpha2'] )
                        && strtoupper( (string) $country['isoAlpha2'] ) === $billing_country_iso2
                    ) {
                        if ( ! empty( $country['currencyCode'] ) ) {
                            $target_currency = strtoupper( trim( (string) $country['currencyCode'] ) );
                        } elseif ( ! empty( $country['currency']['code'] ) ) {
                            $target_currency = strtoupper( trim( (string) $country['currency']['code'] ) );
                        }
                        break;
                    }
                }
            }
        }

        if ( $target_currency === '' ) {
            WC()->session->set( 'calculate_shipping_error', true );
            WC()->session->set( 'shipping_error_message', 'Не може да използвате Наложен платеж, валутата за държавата липсва. Моля обърнете се към администраторите на магазина!' );
            WC()->session->set( 'shipping_speedy_available_services', [] );
            WC()->session->set( 'shipping_speedy_selected_service_id', 0 );
            WC()->session->save_data();
            return 0;
        }

        $target_rate = self::fx_per_eur( $target_currency );

        if ( $target_rate <= 0 ) {
            WC()->session->set( 'calculate_shipping_error', true );
            WC()->session->set( 'shipping_error_message', sprintf(
                'Не може да използвате Наложен платеж, валутата %s липсва. Моля обърнете се към администраторите на магазина!',
                $target_currency
            ) );
            WC()->session->set( 'shipping_speedy_available_services', [] );
            WC()->session->set( 'shipping_speedy_selected_service_id', 0 );
            WC()->session->save_data();
            return 0;
        }

        $store_currency = strtoupper( get_woocommerce_currency() );
        if ( $store_currency !== $target_currency ) {
            $second_pass_cod_amount = self::convert_via_eur(
                $second_pass_cod_amount,
                $store_currency,
                $target_currency
            );
        }
    }

    $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round( $second_pass_cod_amount, 2 );

    $second_pass_payload = $arr_delivery_data;
    $second_pass_payload['userName'] = speedy_username();
    $second_pass_payload['password'] = speedy_password();

    $response = WS_Speedy_Request::call( SPEEDY_API_BASE_URL . 'calculate/', $second_pass_payload );

    $selected_calculation = null;
    $first_valid_calculation = null;
    $selected_service_error = '';
    $available_services = [];

    if ( is_array( $response ) && ! empty( $response['calculations'] ) && is_array( $response['calculations'] ) ) {
        foreach ( $response['calculations'] as $calc ) {
            if ( ! is_array( $calc ) ) {
                continue;
            }

            $service_id = isset( $calc['serviceId'] ) ? (int) $calc['serviceId'] : 0;

            if ( ! empty( $calc['error'] ) ) {
                if ( $selected_service_id > 0 && $service_id === $selected_service_id ) {
                    $selected_service_error = isset( $calc['error']['message'] ) ? (string) $calc['error']['message'] : '';
                }
                continue;
            }

            if ( empty( $calc['price'] ) || ! isset( $calc['price']['total'] ) ) {
                continue;
            }

            $total    = (float) $calc['price']['total'];
            $currency = isset( $calc['price']['currency'] ) ? (string) $calc['price']['currency'] : '';

            $available_services[] = [
                'serviceId' => $service_id,
                'total'     => $total,
                'currency'  => $currency,
            ];

            if ( $first_valid_calculation === null ) {
                $first_valid_calculation = $calc;
            }

            if ( $service_id === $selected_service_id ) {
                $selected_calculation = $calc;
            }
        }
    }

    if ( $selected_calculation === null && $first_valid_calculation !== null ) {
        $selected_calculation = $first_valid_calculation;
    }
}

if (
    $is_cash_on_delivery
    && speedy_get_setting( 'includeshippingprice' ) == 'YES'
    && ! $uses_non_speedy_shipping_price
    && $selected_calculation
    && isset( $selected_calculation['price']['total'] )
) {
    $products_only_with_vat = 0.0;

    if ( function_exists( 'WC' ) && WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $products_only_with_vat += (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
        }
    }

    $cod_amount = $products_only_with_vat;

    if ( speedy_get_setting( 'cenadostavka' ) == 'nadbavka' ) {
        $cod_amount += (float) speedy_get_setting( 'suma_nadbavka' );
    }

    if (
        isset( $arr_delivery_data['service']['additionalServices']['cod'] )
        && is_array( $arr_delivery_data['service']['additionalServices']['cod'] )
    ) {
        $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round( $cod_amount, 2 );
    }
}

if ( isset( $arr_delivery_data['service'] ) && is_array( $arr_delivery_data['service'] ) ) {
    self::sync_declared_value_with_cod( $arr_delivery_data['service'] );
}

WC()->session->set('shipping_speedy_available_services', $available_services);
WC()->session->set('shipping_speedy_selected_service_id', $selected_service_id);

// Ако е поискана конкретна услуга и тя е върнала грешка -> показваме грешката
if ( $requested_service_id > 0 && ! $selected_calculation ) {
    $destination_message = '';
    if (
        isset( $destination_cod_policies[ $requested_service_id ]['forbidden'] )
        && $destination_cod_policies[ $requested_service_id ]['forbidden']
    ) {
        $destination_message = (string) ( $destination_cod_policies[ $requested_service_id ]['message'] ?? '' );
    }

    $final_error_message = $selected_service_error !== '' ? $selected_service_error : $destination_message;

    if ( $final_error_message !== '' ) {
        WC()->session->set('calculate_shipping_error', true);
        WC()->session->set('shipping_error_message', $final_error_message);
        WC()->session->save_data();
        return 0;
    }
}

// Записваме serviceId в payload-а
if ($selected_service_id > 0) {
    if (!isset($arr_delivery_data['service']) || !is_array($arr_delivery_data['service'])) {
        $arr_delivery_data['service'] = [];
    }
    $arr_delivery_data['service']['serviceId'] = $selected_service_id;
    WC()->session->set('shipping_speedy_shipping', $arr_delivery_data);
}
    // Ако има успешна калкулация, чистим старата грешка от сесията
    if ($selected_calculation) {
        WC()->session->set('calculate_shipping_error', false);
        WC()->session->set('shipping_error_message', '');
        WC()->session->save_data();
    }
    
   if (!$selected_calculation && empty($available_services)) {
    $error_message = '';

    if (is_array($response) && !empty($response['calculations']) && is_array($response['calculations'])) {
        foreach ($response['calculations'] as $calc) {
            $ctx = isset($calc['error']['context']) ? (string) $calc['error']['context'] : '';
            $msg = isset($calc['error']['message']) ? (string) $calc['error']['message'] : '';

            if ($ctx === 'content.parcels..require_parcel_size') {
                $error_message = 'Изискват се описани размери на пакета';
                break;
            }

            if ($ctx === 'sla.cod.cod-not-allowed-for-service' && $msg !== '') {
                $error_message = $msg;
                break;
            }

            if ($error_message === '' && $msg !== '') {
                $error_message = $msg;
            }
        }
    }

    WC()->session->set('calculate_shipping_error', true);
    WC()->session->set('shipping_error_message', $error_message);
    WC()->session->save_data();

WC()->session->set('shipping_speedy_available_services', []);
WC()->session->set('shipping_speedy_selected_service_id', 0);
    return 0;
}

        if( $is_free_shipping ) {
            $cost = number_format( 0, 2 );
        } elseif( $is_fixed_shipping_office ) {
            $cost = number_format( $fixed_shipping_office, 2 );
        } elseif( $is_fixed_shipping_automat ) {
            $cost = number_format( $fixed_shipping_automat, 2 );
        } elseif( $is_fixed_shipping_address ) {
            $cost = number_format( $fixed_shipping_address, 2 );
        } else {
            // използваме успешната калкулация
            $cost = number_format( $selected_calculation['price']['total'], 2 );

            if (speedy_get_setting( 'cenadostavka' ) == 'nadbavka' ) {
                $cost += speedy_get_setting( 'suma_nadbavka' );
            }
        }

        if ( speedy_get_setting( 'cenadostavka' ) == 'fileprices' ) {
            $cost = $filepricecost;
        }
       

        WC()->session->set( 'shipping_speedy_shipping_cost', $cost );

        if( $return_order_total ) return [ 'cost' => $cost, 'order_total' => $order_total ];

        // echo '<pre>';
        // var_dump($shipping_type);
        // var_dump($cost);
        // echo '</pre>';
        // die("DEBUG");

        return $cost; 
    }


}

function str_contains_any($haystack, array $needles): bool
{
    if ($haystack === null) return false;

    $haystack = (string) $haystack;
    if ($haystack === '') return false;

    foreach ($needles as $n) {
        $n = (string) $n;
        if ($n === '') continue;

        // PHP 7.4 compatible
        if (strpos($haystack, $n) !== false) {
            return true;
        }
    }

    return false;
}
