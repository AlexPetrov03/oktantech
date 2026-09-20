<?php

defined( 'ABSPATH' ) or exit;

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/class-speedy-request.php';
require_once __DIR__ . '/class-speedy-api.php';
require_once __DIR__ . '/class-speedy-db.php';
require_once __DIR__ . '/class-speedy-ajax.php';

add_action( 'admin_menu', 'speedy_add_orders_submenu', 20 );

    function speedy_add_orders_submenu() {
        add_submenu_page(
            'woocommerce',                  // Родителското меню
            __( 'Спиди поръчки', 'speedy-shipping' ),                // Заглавие на страницата
            __( 'Спиди поръчки', 'speedy-shipping' ),                // Текст в менюто
            'manage_woocommerce',           // Капабилити
            'speedy-orders',                // slug за страницата
            'speedy_orders_page_callback'   // Функция за съдържанието
        );
    }

function speedy_get_waybill_id_for_order( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return '';
    }

    $waybill_json = $order->get_meta( 'shipping_speedy_waybill' );
    if ( empty( $waybill_json ) ) {
        return '';
    }

    $waybill_data = json_decode( $waybill_json, true );

    return ! empty( $waybill_data['id'] ) ? trim( (string) $waybill_data['id'] ) : '';
}

function speedy_order_has_stored_final_tracking_status( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return false;
    }

    $final_status_code = $order->get_meta( '_speedy_tracking_final_status_code', true );

    return trim( (string) $final_status_code ) !== '';
}

function speedy_normalize_tracking_status_text( $value ) {
    $value = is_string( $value ) ? trim( $value ) : '';
    $value = preg_replace( '/\s+/', ' ', $value );

    return function_exists( 'mb_strtolower' )
        ? mb_strtolower( $value, 'UTF-8' )
        : strtolower( $value );
}

function speedy_match_tracking_final_status( $status_id = null, $status_name = '' ) {
    $statuses = speedy_get_final_tracking_statuses();
    $status_name = speedy_normalize_tracking_status_text( (string) $status_name );

    if ( $status_id !== null && $status_id !== '' ) {
        $status_id = (string) $status_id;
        if ( isset( $statuses[ $status_id ] ) ) {
            return [
                'code'    => $status_id,
                'label'   => $statuses[ $status_id ]['label'],
                'english' => $statuses[ $status_id ]['english'],
            ];
        }
    }

    foreach ( $statuses as $code => $status ) {
        $label_bg = speedy_normalize_tracking_status_text( $status['label'] );
        $label_en = speedy_normalize_tracking_status_text( $status['english'] );

        if ( $status_name !== '' && ( $status_name === $label_bg || $status_name === $label_en || strpos( $status_name, $label_bg ) !== false || strpos( $status_name, $label_en ) !== false ) ) {
            return [
                'code'    => (string) $code,
                'label'   => $status['label'],
                'english' => $status['english'],
            ];
        }
    }

    return null;
}

function speedy_extract_tracking_final_status( $payload ) {
    if ( ! is_array( $payload ) ) {
        return null;
    }

    $candidates = [];

    if ( isset( $payload['status'] ) && is_array( $payload['status'] ) ) {
        $candidates[] = [
            'id'   => $payload['status']['statusId'] ?? $payload['status']['id'] ?? $payload['status']['code'] ?? null,
            'name' => $payload['status']['statusName'] ?? $payload['status']['name'] ?? $payload['status']['description'] ?? '',
        ];
    }

    if ( isset( $payload['statusId'] ) || isset( $payload['statusName'] ) || isset( $payload['code'] ) ) {
        $candidates[] = [
            'id'   => $payload['statusId'] ?? $payload['code'] ?? null,
            'name' => $payload['statusName'] ?? $payload['name'] ?? $payload['description'] ?? '',
        ];
    }

    if ( isset( $payload['operationCode'] ) || isset( $payload['description'] ) ) {
        $candidates[] = [
            'id'   => $payload['operationCode'] ?? null,
            'name' => $payload['description'] ?? '',
        ];
    }

    foreach ( $candidates as $candidate ) {
        $matched = speedy_match_tracking_final_status( $candidate['id'], $candidate['name'] );
        if ( $matched ) {
            return $matched;
        }
    }

    foreach ( $payload as $value ) {
        if ( is_array( $value ) ) {
            $matched = speedy_extract_tracking_final_status( $value );
            if ( $matched ) {
                return $matched;
            }
        }
    }

    return null;
}

function speedy_update_order_status_from_tracking( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return [
            'success' => false,
            'message' => __( 'Невалидна поръчка.', 'speedy-shipping' ),
        ];
    }

    $waybill_id = speedy_get_waybill_id_for_order( $order );
    if ( $waybill_id === '' ) {
        return [
            'success' => false,
            'message' => __( 'Не е намерена товарителница за поръчката.', 'speedy-shipping' ),
        ];
    }

    $response = WS_Speedy_Request::call(
        SPEEDY_API_BASE_URL . 'track',
        [
            'userName' => speedy_username(),
            'password' => speedy_password(),
            'language' => 'EN',
            'parcels'  => [
                [
                    'id' => (string) $waybill_id,
                ],
            ],
        ]
    );

    $api_error = is_array( $response ) ? WS_Speedy_Request::is_api_error( $response ) : false;
    if ( $api_error ) {
        return [
            'success' => false,
            'message' => $api_error,
            'waybill' => $waybill_id,
        ];
    }

    $resolved_status = speedy_extract_tracking_final_status( $response );
    $enabled = speedy_get_setting( 'status_update_enabled', 'NO' ) === 'YES';
    $mappings = speedy_get_setting( 'status_update_mappings', [] );
    $target_status = is_array( $mappings ) && ! empty( $resolved_status['code'] ) && isset( $mappings[ $resolved_status['code'] ] )
        ? $mappings[ $resolved_status['code'] ]
        : '';

    $order->update_meta_data( '_speedy_tracking_last_checked', current_time( 'mysql' ) );
    $order->update_meta_data( '_speedy_tracking_last_response', wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );

    if ( $resolved_status ) {
        $order->update_meta_data( '_speedy_tracking_final_status_code', $resolved_status['code'] );
        $order->update_meta_data( '_speedy_tracking_final_status_label', $resolved_status['label'] );
        $order->update_meta_data( '_speedy_tracking_final_status_english', $resolved_status['english'] );
    }

    if ( ! $resolved_status ) {
        $order->save();

        return [
            'success' => false,
            'message' => __( 'Не е открит окончателен статус в отговора от Спиди.', 'speedy-shipping' ),
            'waybill' => $waybill_id,
        ];
    }

    if ( ! $enabled ) {
        $order->add_order_note( sprintf( __( 'Speedy tracking: открит окончателен статус %s, но актуализирането на статусите е изключено.', 'speedy-shipping' ), speedy_get_final_tracking_status_label( $resolved_status['code'] ) ) );
        $order->save();

        return [
            'success' => true,
            'message' => sprintf( __( 'Открит е окончателен статус %s, но настройката „Актуализиране на статусите“ е изключена.', 'speedy-shipping' ), speedy_get_final_tracking_status_label( $resolved_status['code'] ) ),
            'waybill' => $waybill_id,
            'status'  => $resolved_status,
        ];
    }

    if ( $target_status === '' || ! isset( wc_get_order_statuses()[ $target_status ] ) ) {
        $order->add_order_note( sprintf( __( 'Speedy tracking: открит окончателен статус %s, но няма съпоставен WooCommerce статус.', 'speedy-shipping' ), speedy_get_final_tracking_status_label( $resolved_status['code'] ) ) );
        $order->save();

        return [
            'success' => true,
            'message' => sprintf( __( 'Открит е окончателен статус %s, но няма избран WooCommerce статус за него.', 'speedy-shipping' ), speedy_get_final_tracking_status_label( $resolved_status['code'] ) ),
            'waybill' => $waybill_id,
            'status'  => $resolved_status,
        ];
    }

    $current_status = 'wc-' . $order->get_status();
    if ( $current_status !== $target_status ) {
        $order->update_status(
            str_replace( 'wc-', '', $target_status ),
            sprintf( __( 'Статусът е актуализиран от Speedy tracking: %s.', 'speedy-shipping' ), speedy_get_final_tracking_status_label( $resolved_status['code'] ) ),
            false
        );
    } else {
        $order->add_order_note( sprintf( __( 'Speedy tracking: потвърден окончателен статус %s.', 'speedy-shipping' ), speedy_get_final_tracking_status_label( $resolved_status['code'] ) ) );
    }

    $order->save();

    return [
        'success'       => true,
        'message'       => sprintf( __( 'Статусът е актуализиран успешно: %s -> %s.', 'speedy-shipping' ), speedy_get_final_tracking_status_label( $resolved_status['code'] ), wc_get_order_statuses()[ $target_status ] ),
        'waybill'       => $waybill_id,
        'status'        => $resolved_status,
        'target_status' => $target_status,
    ];
}

add_action('admin_head', function () {
    // Само на екрана за редакция на поръчка (WooCommerce -> Orders, HPOS screen)
    $page   = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';

    if ($page !== 'wc-orders' || $action !== 'edit') {
        return;
    }

    echo '<style>
        #order_custom { display: none !important; }
    </style>';
});

add_action('wp_ajax_speedy_get_sites', 'speedy_get_sites_callback');
add_action('wp_ajax_nopriv_speedy_get_sites', 'speedy_get_sites_callback');

if ( ! function_exists( 'speedy_current_api_language' ) ) {
    function speedy_current_api_language() {
        $lang = '';

        if ( has_filter( 'wpml_current_language' ) ) {
            $lang = (string) apply_filters( 'wpml_current_language', null );
        }

        if ( $lang === '' ) {
            $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
            $lang = substr( (string) $locale, 0, 2 );
        }

        $lang = strtoupper( trim( (string) $lang ) );

        return $lang === 'BG' ? 'BG' : 'EN';
    }
}

// NEW: записваме Speedy countryId в order meta като _shipping_shipping_country
add_action('woocommerce_checkout_create_order', function( $order, $data ) {
    if ( ! $order || ! is_object( $order ) ) {
        return;
    }

    // само ако поръчката е със speedy_shipping
    if ( ! method_exists( $order, 'has_shipping_method' ) || ! $order->has_shipping_method('speedy_shipping') ) {
        return;
    }

    $country_id = 0;

    // 1) взимаме от session (най-точно при checkout)
    if ( function_exists('WC') && WC()->session ) {
        $speedy = WC()->session->get('shipping_speedy_shipping');
        if ( is_array($speedy) && isset($speedy['recipient']['addressLocation']['countryId']) ) {
            $country_id = (int) $speedy['recipient']['addressLocation']['countryId'];
        }
    }

    // 2) fallback: ако фронтенда го е подал като hidden input speedy_country_id
    if ( $country_id <= 0 && isset($_POST['speedy_country_id']) ) {
        $country_id = (int) sanitize_text_field( wp_unslash( $_POST['speedy_country_id'] ) );
    }

    if ( $country_id > 0 ) {
        $order->update_meta_data('_shipping_shipping_country', $country_id);
    }

    $selected_service_id = 0;
    if ( isset($_POST['speedy_selected_service_id']) ) {
        $selected_service_id = (int) sanitize_text_field( wp_unslash( $_POST['speedy_selected_service_id'] ) );
    } elseif ( function_exists('WC') && WC()->session ) {
        $selected_service_id = (int) WC()->session->get('shipping_speedy_selected_service_id');
    }

    if ( $selected_service_id > 0 ) {
        $order->update_meta_data('_speedy_selected_service_id', $selected_service_id);

        $available_services = WC()->session ? WC()->session->get('shipping_speedy_available_services') : [];
        if (is_array($available_services)) {
            foreach ($available_services as $service) {
                if ((int)($service['serviceId'] ?? 0) === $selected_service_id) {
                    $order->update_meta_data('_speedy_selected_service_total', (float)($service['total'] ?? 0));
                    $order->update_meta_data('_speedy_selected_service_currency', sanitize_text_field((string)($service['currency'] ?? '')));
                    break;
                }
            }
        }
    }
}, 20, 2);

function speedy_get_sites_callback() {
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $country_id = isset($_POST['countryId']) ? absint($_POST['countryId']) : 100;
    $state_code = isset($_POST['stateCode']) ? sanitize_text_field($_POST['stateCode']) : '';
    $api_language = speedy_current_api_language();

    if (empty($name)) {
        wp_send_json([]);
    }

    $arr_data = [
            'userName'  => speedy_username(),
            'password'  => speedy_password(),
            'language'  => $api_language,
            'countryId' => (string) $country_id,
            'name'      => $name
    ];

    if ( $country_id > 0 && $state_code !== '' ) {
        $state_id = speedy_lookup_state_id( $state_code, $country_id );
        if ( $state_id !== '' ) {
            $arr_data['stateId'] = (string) $state_id;
        }
    }

    $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'location/site', $arr_data);

    // Fallback for countries/cities typed with diacritics when remote search expects normalized Latin.
    if (
        ( ! is_array( $response ) || empty( $response['sites'] ) )
        && function_exists( 'remove_accents' )
    ) {
        $normalized_name = trim( (string) remove_accents( $name ) );
        if ( $normalized_name !== '' && $normalized_name !== $name ) {
            $arr_data['name'] = $normalized_name;
            $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'location/site', $arr_data);
        }
    }

    $results = [];
    if (is_array($response) && isset($response['sites'])) {
        foreach ($response['sites'] as $site) {
            $site_name = $site['type'] . ' ' . $site['name'];
            if (!empty($site['regionName'])) {
                $site_name .= ' (' . $site['regionName'] . ')';
            }

            $results[] = [
                    'id'   => $site['id'],
                    'name' => $site_name
            ];
        }
    }

    wp_send_json($results);
}

add_action('wp_ajax_speedy_get_country_id', 'speedy_get_country_id_callback');
add_action('wp_ajax_nopriv_speedy_get_country_id', 'speedy_get_country_id_callback');

if ( ! function_exists( 'speedy_fetch_speedy_countries' ) ) {
    function speedy_fetch_speedy_countries() {
        $payload = [
            'userName' => speedy_username(),
            'password' => speedy_password(),
        ];

        if ( class_exists( 'WS_Speedy_Request' ) && method_exists( 'WS_Speedy_Request', 'call' ) ) {
            return WS_Speedy_Request::call( SPEEDY_API_BASE_URL . 'location/country', $payload );
        }

        $r = wp_remote_post( SPEEDY_API_BASE_URL . 'location/country', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $r ) ) {
            return null;
        }

        return json_decode( wp_remote_retrieve_body( $r ), true );
    }
}

if ( ! function_exists( 'speedy_find_country_info_by_iso' ) ) {
    function speedy_find_country_info_by_iso( $iso2 ) {
        $iso2 = strtoupper( trim( (string) $iso2 ) );
        if ( $iso2 === '' ) {
            return null;
        }

        $response = speedy_fetch_speedy_countries();
        if ( ! is_array( $response ) || empty( $response['countries'] ) || ! is_array( $response['countries'] ) ) {
            return null;
        }

        foreach ( $response['countries'] as $c ) {
            if ( ! is_array( $c ) ) {
                continue;
            }

            if ( ! empty( $c['isoAlpha2'] ) && strtoupper( (string) $c['isoAlpha2'] ) === $iso2 && ! empty( $c['id'] ) ) {
                return [
                    'id'          => (int) $c['id'],
                    'addressType' => isset( $c['addressType'] ) ? (int) $c['addressType'] : 1,
                ];
            }
        }

        return null;
    }
}

if ( ! function_exists( 'speedy_country_info_fallback_by_iso' ) ) {
    function speedy_country_info_fallback_by_iso( $iso2 ) {
        $iso2 = strtoupper( trim( (string) $iso2 ) );

        $fallback = [
            'BG' => [ 'id' => 100, 'addressType' => 1 ],
            'RO' => [ 'id' => 642, 'addressType' => 1 ],
            'GR' => [ 'id' => 300, 'addressType' => 1 ],
            'DE' => [ 'id' => 276, 'addressType' => 2 ],
            'US' => [ 'id' => 840, 'addressType' => 2 ],
        ];

        return isset( $fallback[ $iso2 ] ) ? $fallback[ $iso2 ] : null;
    }
}

function speedy_get_country_id_callback() {
    $iso2 = isset($_POST['iso2']) ? strtoupper(sanitize_text_field($_POST['iso2'])) : '';

    if (empty($iso2)) {
        wp_send_json_error(['message' => 'Missing iso2'], 400);
    }

    if ($iso2 === 'BG') {
        wp_send_json_success(['countryId' => 100, 'addressType' => 1]);
    }


    $country_info = speedy_find_country_info_by_iso( $iso2 );
    if ( ! is_array( $country_info ) || empty( $country_info['id'] ) ) {
        $country_info = speedy_country_info_fallback_by_iso( $iso2 );
    }

    if ( ! is_array( $country_info ) || empty( $country_info['id'] ) ) {
        wp_send_json_error(['message' => 'Invalid response from Speedy location/country'], 502);
    }

    $found_id = (int) $country_info['id'];
    $address_type = isset( $country_info['addressType'] ) ? (int) $country_info['addressType'] : 1;

    if ($found_id === 0) {
        wp_send_json_error([
                'message' => 'Speedy countryId not found for ISO2=' . $iso2
        ], 404);
    }

   
    if (in_array($iso2, ['DE', 'US'], true)) {
        $address_type = 2;
    }

    $payload = [
        'countryId'   => $found_id,
        'addressType' => $address_type,
    ];

    wp_send_json_success($payload);
}


add_action('wp_ajax_speedy_get_state_id', 'speedy_get_state_id_callback');
add_action('wp_ajax_nopriv_speedy_get_state_id', 'speedy_get_state_id_callback');

/**
 * Internal helper: looks up Speedy stateId for a US state by its ISO2 code (e.g. 'FL').
 * Always uses countryId=840 (US). Returns int > 0 on success, 0 on failure.
 */

function speedy_lookup_state_id( string $state_code, int $country_id ): string {
    $state_code = strtoupper( trim( $state_code ) );

    if ( $state_code === '' || $country_id <= 0 ) {
        return '';
    }

    $response = WS_Speedy_Request::call( SPEEDY_API_BASE_URL . 'location/state', [
        'userName'  => speedy_username(),
        'password'  => speedy_password(),
        'countryId' => $country_id,
        'name'      => $state_code,
    ] );

    if ( ! is_array( $response ) || empty( $response['states'] ) ) {
        return '';
    }

    foreach ( $response['states'] as $s ) {
        if ( ! empty( $s['stateAlpha'] ) && strtoupper( (string) $s['stateAlpha'] ) === $state_code && ! empty( $s['id'] ) ) {
            return (string) $s['id'];
        }
    }

    return ! empty( $response['states'][0]['id'] ) ? (string) $response['states'][0]['id'] : '';
}

function speedy_get_state_id_callback() {
    $state_code = isset( $_POST['stateCode'] ) ? sanitize_text_field( wp_unslash( $_POST['stateCode'] ) ) : '';
    $country_id = isset( $_POST['countryId'] ) ? (int) $_POST['countryId'] : 0;

    if ( $state_code === '' || $country_id <= 0 ) {
        wp_send_json_error( ['message' => 'Missing stateCode or countryId'], 400 );
    }

    $found_id = speedy_lookup_state_id( $state_code, $country_id );

    if ( $found_id === '' ) {
        wp_send_json_error( ['message' => 'stateId not found for ' . $state_code], 404 );
    }

    wp_send_json_success( ['stateId' => $found_id] );
}

if ( ! function_exists( 'speedy_destination_service_id' ) ) {
    function speedy_destination_service_id( array $service ): int {
        if ( isset( $service['serviceId'] ) ) {
            return (int) $service['serviceId'];
        }

        if ( isset( $service['id'] ) ) {
            return (int) $service['id'];
        }

        return 0;
    }
}

if ( ! function_exists( 'speedy_destination_service_allowance' ) ) {
    function speedy_destination_service_allowance( array $service, string $key ): array {
        $result = [
            'allowed'   => false,
            'forbidden' => false,
        ];

        $additional_services = isset( $service['additionalServices'] ) && is_array( $service['additionalServices'] )
            ? $service['additionalServices']
            : [];

        $service_data = $additional_services[ $key ] ?? null;
        if ( ! is_array( $service_data ) ) {
            return $result;
        }

        if ( isset( $service_data['allowance'] ) ) {
            $allowance = strtoupper( trim( (string) $service_data['allowance'] ) );

            if ( $allowance === 'ALLOWED' ) {
                $result['allowed'] = true;
            } elseif ( $allowance === 'FORBIDDEN' ) {
                $result['forbidden'] = true;
            }
        }

        return $result;
    }
}

if ( ! function_exists( 'speedy_destination_service_policies' ) ) {
    function speedy_destination_service_policies( $destination_response ): array {
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

            $service_id = speedy_destination_service_id( $service );
            if ( $service_id <= 0 ) {
                continue;
            }

            $policies[ $service_id ] = [
                'cod'  => speedy_destination_service_allowance( $service, 'cod' ),
                'obpd' => speedy_destination_service_allowance( $service, 'obpd' ),
            ];
        }

        return $policies;
    }
}

add_action('wp_ajax_speedy_get_offices', 'speedy_get_offices_ajax');
add_action('wp_ajax_nopriv_speedy_get_offices', 'speedy_get_offices_ajax');

function speedy_get_offices_ajax() {
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $countryId = isset($_POST['countryId']) ? intval($_POST['countryId']) : 0;
    $iso = '';
    $api_language = speedy_current_api_language();

    // try to resolve countryId from submitted countryId or ISO

    if ( ! $countryId && ! empty( $_POST['countryIso'] ) ) {
        $iso = strtoupper( sanitize_text_field( $_POST['countryIso'] ) );

        // 1) try Speedy_DB helper if available
        if ( class_exists( 'Speedy_DB' ) && method_exists( 'Speedy_DB', 'get_country_by_iso' ) ) {
            $cobj = Speedy_DB::get_country_by_iso( $iso );
            if ( $cobj && ! empty( $cobj->id ) ) {
                $countryId = (int) $cobj->id;
            }
        }

        // 2) fallback: resolve by ISO2 from location/country (no localized country name dependency)
        if ( ! $countryId ) {
            $country_info = speedy_find_country_info_by_iso( $iso );
            if ( is_array( $country_info ) && ! empty( $country_info['id'] ) ) {
                $countryId = (int) $country_info['id'];
            }
        }

        // 3) hard fallback mapping for known countries (RO/GR/DE/US etc.)
        if ( ! $countryId ) {
            $country_info = speedy_country_info_fallback_by_iso( $iso );
            if ( is_array( $country_info ) && ! empty( $country_info['id'] ) ) {
                $countryId = (int) $country_info['id'];
            }
        }
    }

    // For foreign lookup, never silently fall back to BG.
    if ( ! $countryId && $iso !== '' && $iso !== 'BG' ) {
        wp_send_json_success( [ 'offices' => [] ] );
    }

    // BG default only when no country was provided at all.
    if ( ! $countryId ) {
        $countryId = 100;
    }

    $payload = [
        'userName'  => speedy_username(),
        'password'  => speedy_password(),
        'language'  => $api_language,
        'countryId' => $countryId,
        'name'      => $name,
    ];

    // Use existing request wrapper if available
    if (class_exists('WS_Speedy_Request') && method_exists('WS_Speedy_Request', 'call')) {
        $res = WS_Speedy_Request::call( SPEEDY_API_BASE_URL . 'location/office/', $payload );        wp_send_json_success($res);
    } else {
        // fallback HTTP request
        $url = SPEEDY_API_BASE_URL . 'location/office/';
        $args = [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ];
        $r = wp_remote_post($url, $args);
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
        $body = json_decode(wp_remote_retrieve_body($r), true);
        wp_send_json_success($body);
    }
}

add_action('wp_ajax_speedy_get_offices_by_site', 'speedy_get_offices_by_site_callback');
add_action('wp_ajax_nopriv_speedy_get_offices_by_site', 'speedy_get_offices_by_site_callback');

function speedy_get_offices_by_site_callback() {
    $site_id = isset($_POST['siteId']) ? absint($_POST['siteId']) : 0;
    $country_id = isset($_POST['countryId']) ? absint($_POST['countryId']) : 0;
    $api_language = speedy_current_api_language();

    if (!$site_id || !$country_id) {
        wp_send_json_success([]);
    }

    $arr_data = [
            'userName'   => speedy_username(),
            'password'   => speedy_password(),
            'language'   => $api_language,
            'countryId'  => (string) $country_id,
            'siteId'     => (int) $site_id,
    ];

    $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'location/office', $arr_data);

    $results = [];

    if (is_array($response) && !empty($response['offices']) && is_array($response['offices'])) {
        foreach ($response['offices'] as $office) {
            $results[] = [
                    'id'      => isset($office['id']) ? (int) $office['id'] : 0,
                    'name'    => isset($office['name']) ? (string) $office['name'] : '',
                    'address' => isset($office['address']['fullAddressString']) ? (string) $office['address']['fullAddressString'] : '',
            ];
        }
    }

    wp_send_json_success($results);
}


add_action('wp_ajax_speedy_get_complexes', 'speedy_get_complexes_callback');
add_action('wp_ajax_nopriv_speedy_get_complexes', 'speedy_get_complexes_callback');

function speedy_get_complexes_callback() {
    $siteId = isset($_POST['siteId']) ? sanitize_text_field($_POST['siteId']) : '';
    $name   = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $api_language = speedy_current_api_language();

    if (!$siteId || empty($name)) {
        wp_send_json([]);
    }

    $arr_data = [
        'userName' => speedy_username(),
        'password' => speedy_password(),
        'language' => $api_language,
        'siteId'   => $siteId,
        'name'     => $name
    ];

    $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'location/complex', $arr_data);

    $results = [];
    if (is_array($response) && isset($response['complexes'])) {
        foreach ($response['complexes'] as $complex) {
            $complex_name = $complex['name']; // без regionName тук обикновено
            $results[] = [
                'id' => $complex['id'],
                'name' => $complex_name
            ];
        }
    }

    wp_send_json($results);
}

add_action('wp_ajax_speedy_get_streets', 'speedy_get_streets_callback');
add_action('wp_ajax_nopriv_speedy_get_streets', 'speedy_get_streets_callback');

function speedy_get_streets_callback() {
    $siteId    = isset($_POST['siteId']) ? sanitize_text_field($_POST['siteId']) : '';
    $name      = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $countryId = isset($_POST['countryId']) ? absint($_POST['countryId']) : 0;
    $api_language = speedy_current_api_language();

    if (!$siteId || empty($name)) {
        wp_send_json([]);
    }

    $arr_data = [
            'userName' => speedy_username(),
            'password' => speedy_password(),
            'language' => $api_language,
            'siteId'   => (int) $siteId,
            'name'     => $name
    ];

    if ($countryId > 0) {
        $arr_data['countryId'] = (int) $countryId;
    }

    $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'location/street', $arr_data);

    $results = [];
    if (is_array($response) && isset($response['streets'])) {
        foreach ($response['streets'] as $street) {
            $results[] = [
                    'id' => $street['id'],
                    'name' => $street['name']
            ];
        }
    }

    wp_send_json($results);
}




//save files
add_action('woocommerce_update_options_shipping_speedy_shipping', function() {

    // 1. Зареждаме функцията за upload, ако не е заредена
    if ( ! function_exists( 'wp_handle_upload' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }

    // 2. Проверяваме дали е качен файл
    if (isset($_FILES['woocommerce_speedy_shipping_fileceni']) && !empty($_FILES['woocommerce_speedy_shipping_fileceni']['name'])) {
        $uploaded = wp_handle_upload($_FILES['woocommerce_speedy_shipping_fileceni'], ['test_form' => false]);

        if (isset($uploaded['file'])) {
            // Запазваме пътя на файла в опция
            update_option('speedy_fileceni_path', $uploaded['file']);
            $redirect_url = add_query_arg('custom_redirect', 'yes', admin_url('admin.php?page=wc-settings&tab=shipping&section=speedy_shipping'));
            wp_safe_redirect($redirect_url);
        } else {
            error_log('Speedy fileceni upload error: ' . print_r($_FILES['woocommerce_speedy_shipping_fileceni'], true));
        }
    }

    // 3. Можеш да обработиш и останалите настройки на плъгина тук, ако има нужда
    // WC()->shipping()->save_settings(); или друг hook

    // 4. Препращане след запазване
    if (!headers_sent()) {
        //$redirect_url = add_query_arg('custom_redirect', 'yes', admin_url('admin.php?page=wc-settings&tab=shipping&section=speedy_shipping'));
        //wp_safe_redirect($redirect_url);
        //exit;
    }
});






function speedy_orders_page_callback() {

if (isset($_GET['kurier']) && !empty($_GET['kurier'])) {
    $shipment_id = htmlspecialchars($_GET['id']); 
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Успешно заявихте куриер за пратка номер:', 'speedy-shipping' ) . ' ' . $shipment_id . '</p></div>';
}

if (isset($_GET['anulirane']) && !empty($_GET['anulirane'])) {
    $shipment_id = htmlspecialchars($_GET['id']); 
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Успешно анулирахте пратка номер:', 'speedy-shipping' ) . ' ' . $shipment_id . '</p></div>';
}

if (isset($_GET['update_status_order']) && !empty($_GET['update_status_order'])) {
    $order_id = absint($_GET['update_status_order']);

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'speedy_update_status_' . $order_id)) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Невалиден token за актуализиране на статуса.', 'speedy-shipping' ) . '</p></div>';
    } else {
        $order = wc_get_order($order_id);
        $result = speedy_update_order_status_from_tracking($order);
        $notice_class = !empty($result['success']) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($result['message']) . '</p></div>';
    }
}

date_default_timezone_set('Europe/Sofia');

// ============================
// ✅ ЗАЯВЯВАНЕ НА КУРИЕР
// ============================
if (isset($_GET['zaqvka']) && isset($_GET['ids'])) {
    $ids = explode(',', sanitize_text_field($_GET['ids']));
    $waybills = [];

    foreach ($ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        // Вземаме товарителницата от мета
        $waybill_json = $order->get_meta('shipping_speedy_waybill');
        if ($waybill_json) {
            $waybill_data = json_decode($waybill_json, true);
            if (!empty($waybill_data['id'])) {
                $waybills[] = $waybill_data['id'];
            }
        }
    }

    if (!empty($waybills)) {
        // --- Подготвяме заявката за pickup ---
        date_default_timezone_set('Europe/Sofia');
        $now = new DateTime();
        $cutoffHour = 16;

        if ((int)$now->format('H') < $cutoffHour) {
            $pickupDate = $now;
        } else {
            $pickupDate = (clone $now)->modify('+1 day');
        }

        $pickupDate->setTime(15, 15, 0);
        $pickupDateTime = $pickupDate->format('Y-m-d\TH:i:sO');

        $visitEndTime = speedy_get_setting('sender_time');

        $arr_data = array(
            'userName' => speedy_username(),
            'password' => speedy_password(),
            'explicitShipmentIdList' => $waybills, // всички товарителници
            'visitEndTime' => $visitEndTime,
            'autoAdjustPickupDate' => true,
        );

        $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'pickup/', $arr_data, false);

        if ($response && empty($response['error'])) {

            // ✅ Маркираме в базата, че е извикан куриер
            foreach ($ids as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) continue;

                $order->update_meta_data('_speedy_courier_requested', 'yes');
                $order->update_meta_data('_speedy_courier_requested_date', current_time('mysql'));
                $order->save();
            }

            echo '<div class="notice notice-success"><p>' . esc_html__( '✅ Заявени са куриери за пратки:', 'speedy-shipping' ) . ' ' . implode(', ', $waybills) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__( '⚠️ Грешка при заявяване на куриер:', 'speedy-shipping' ) . ' ' . esc_html($response['error']['message'] ?? __( 'неизвестна грешка', 'speedy-shipping' )) . '</p></div>';
        }

    } else {
        echo '<div class="notice notice-warning"><p>' . esc_html__( '⚠️ Не са намерени товарителници за избраните поръчки.', 'speedy-shipping' ) . '</p></div>';
    }
}


// ============================
// ❌ АНУЛИРАНЕ НА ПРАТКИ
// ============================
if (isset($_GET['cancel']) && isset($_GET['ids'])) {
    $ids = explode(',', sanitize_text_field($_GET['ids']));
    $waybills = [];
    $shop_name = get_bloginfo('name');
    $success = [];
    $failed = [];

    foreach ($ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        // Вземаме товарителницата
        $waybill_json = $order->get_meta('shipping_speedy_waybill');
        if ($waybill_json) {
            $waybill_data = json_decode($waybill_json, true);
            if (!empty($waybill_data['id'])) {
                $shipmentId = $waybill_data['id'];
                $waybills[] = $shipmentId;

                // --- Изпращаме заявка за анулиране ---
                $arr_data = array(
                    'userName' => speedy_username(),
                    'password' => speedy_password(),
                    'shipmentId' => $shipmentId,
                    'comment' => 'Cancel order from ' . $shop_name,
                );

                $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'shipment/cancel/', $arr_data, false);

                if ($response && empty($response['error'])) {
                    $success[] = $shipmentId;
                } else {
                    $failed[] = $shipmentId;
                }
            }
        }
    }

    if (!empty($success)) {
        echo '<div class="notice notice-success"><p>' . esc_html__( '❌ Анулирани са пратки с товарителници:', 'speedy-shipping' ) . ' ' . implode(', ', $success) . '</p></div>';
    }

    if (!empty($failed)) {
        echo '<div class="notice notice-error"><p>' . esc_html__( '⚠️ Неуспешно анулиране на:', 'speedy-shipping' ) . ' ' . implode(', ', $failed) . '</p></div>';
    }

    if (empty($waybills)) {
        echo '<div class="notice notice-warning"><p>' . esc_html__( '⚠️ Не са намерени товарителници за избраните поръчки.', 'speedy-shipping' ) . '</p></div>';
    }
}

if (isset($_GET['update_statuses']) && isset($_GET['ids'])) {
    $ids = explode(',', sanitize_text_field($_GET['ids']));
    $success_messages = [];
    $failed_messages = [];
    $skipped_messages = [];

    foreach ($ids as $order_id) {
        $order = wc_get_order((int) $order_id);
        if (!$order) {
            continue;
        }

        if (speedy_order_has_stored_final_tracking_status($order)) {
            $stored_code = (string) $order->get_meta('_speedy_tracking_final_status_code', true);
            $skipped_messages[] = '#' . $order->get_order_number() . ': ' . sprintf( __( 'прескочена, вече има окончателен статус %s.', 'speedy-shipping' ), speedy_get_final_tracking_status_label($stored_code) );
            continue;
        }

        $result = speedy_update_order_status_from_tracking($order);
        $message = '#' . $order->get_order_number() . ': ' . $result['message'];

        if (!empty($result['success'])) {
            $success_messages[] = $message;
        } else {
            $failed_messages[] = $message;
        }
    }

    if (!empty($success_messages)) {
        echo '<div class="notice notice-success"><p>' . esc_html(implode(' | ', $success_messages)) . '</p></div>';
    }

    if (!empty($failed_messages)) {
        echo '<div class="notice notice-error"><p>' . esc_html(implode(' | ', $failed_messages)) . '</p></div>';
    }

    if (!empty($skipped_messages)) {
        echo '<div class="notice notice-warning"><p>' . esc_html(implode(' | ', $skipped_messages)) . '</p></div>';
    }
}



if ((isset($_POST['doaction']) || isset($_POST['doaction2'])) && !empty($_POST['order_ids'])) {

    // Проверка за nonce
    if (!isset($_POST['speedy_bulk_nonce']) || !wp_verify_nonce($_POST['speedy_bulk_nonce'], 'speedy_bulk_action')) {
        wp_die( esc_html__( 'Невалидна заявка. Моля, опитайте отново.', 'speedy-shipping' ) );
    }

    $action = '-1';
    if (!empty($_POST['action']) && $_POST['action'] !== '-1') {
        $action = sanitize_text_field($_POST['action']);
    } elseif (!empty($_POST['action2']) && $_POST['action2'] !== '-1') {
        $action = sanitize_text_field($_POST['action2']);
    }
    $order_ids = array_map('intval', $_POST['order_ids']);
    $ids_param = implode(',', $order_ids);

    $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'speedy-orders';

    // Създаваме redirect URL
   if ($action === 'zaqvka') {
    $redirect_url = add_query_arg(
        ['page' => $current_page, 'zaqvka' => 'yes', 'ids' => $ids_param],
        admin_url('admin.php')
    );
    } elseif ($action === 'cancel') {
        $redirect_url = add_query_arg(
            ['page' => $current_page, 'cancel' => 'yes', 'ids' => $ids_param],
            admin_url('admin.php')
        );
    } elseif ($action === 'update_statuses') {
        $redirect_url = add_query_arg(
            ['page' => $current_page, 'update_statuses' => 'yes', 'ids' => $ids_param],
            admin_url('admin.php')
        );
    } else {
        $redirect_url = add_query_arg(['page' => $current_page], admin_url('admin.php'));
    }

    wp_safe_redirect($redirect_url);
    exit;
}







    echo '<div class="wrap"><h1>' . esc_html__( 'Спиди поръчки', 'speedy-shipping' ) . '</h1>';

    // Настройки

// Настройки за странициране
$orders_per_page = 10;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

// Пагинирана заявка: избягваме зареждане на всички поръчки в паметта.
$orders_result = wc_get_orders([
    'status'   => array_keys(wc_get_order_statuses()),
    'limit'    => $orders_per_page,
    'paged'    => $current_page,
    'paginate' => true,
    'meta_query' => [
        [
            'key'     => 'shipping_speedy_waybill',
            'compare' => 'EXISTS',
        ],
        [
            'key'     => 'shipping_speedy_waybill',
            'value'   => '',
            'compare' => '!=',
        ],
        [
            'key'     => 'shipping_speedy_waybill',
            'value'   => '"id"',
            'compare' => 'LIKE',
        ],
    ],
]);

$orders = [];
$total_orders = 0;
$total_pages = 0;

if (is_object($orders_result)) {
    $orders = is_array($orders_result->orders ?? null) ? $orders_result->orders : [];
    $total_orders = isset($orders_result->total) ? (int) $orders_result->total : count($orders);
    $total_pages = isset($orders_result->max_num_pages) ? (int) $orders_result->max_num_pages : 0;
}

echo '<p>' . esc_html__( 'Общ брой валидни Спиди поръчки:', 'speedy-shipping' ) . ' ' . $total_orders . '</p>';
// Примерно изкарване на информация

  

    if ( $orders ) {

echo '<form method="post" id="speedy-bulk-form">';
wp_nonce_field('speedy_bulk_action', 'speedy_bulk_nonce');
echo '<div class="tablenav top">
    <div class="alignleft actions bulkactions">
        <label for="bulk-action-selector-top" class="screen-reader-text">' . esc_html__( 'Масов избор', 'speedy-shipping' ) . '</label>
        <select name="action" id="bulk-action-selector-top">
            <option value="-1">' . esc_html__( 'Масови действия', 'speedy-shipping' ) . '</option>
            <option value="zaqvka">' . esc_html__( 'Заявяване на куриер', 'speedy-shipping' ) . '</option>
            <option value="cancel">' . esc_html__( 'Анулиране на пратки', 'speedy-shipping' ) . '</option>
            <option value="update_statuses">' . esc_html__( 'Актуализиране на статусите', 'speedy-shipping' ) . '</option>
        </select>
        <input type="submit" name="doaction" id="doaction" class="button action" value="' . esc_attr__( 'Прилагане', 'speedy-shipping' ) . '">
    </div>
</div>';

        echo '<table class="widefat fixed striped" id="speedy-orders-table">';
        echo '<thead><tr>';
        echo '<th><input type="checkbox" id="select-all" /></th>';
         echo '<th>' . esc_html__( 'Действие', 'speedy-shipping' ) . '</th>';
        echo '<th>' . esc_html__( 'Товарителница', 'speedy-shipping' ) . ' <button style="display:none!important;" onclick="sortTable(1)">↓</button></th>';
        echo '<th>' . esc_html__( 'Поръчка №', 'speedy-shipping' ) . ' <button style="display:none!important;" onclick="sortTable(2)">↓</button></th>';
        echo '<th>' . esc_html__( 'Клиент', 'speedy-shipping' ) . ' <button style="display:none!important;" onclick="sortTable(3)">↓</button></th>';
        echo '<th>' . esc_html__( 'Адрес за доставка (Спиди)', 'speedy-shipping' ) . '</th>';
        echo '<th>' . esc_html__( 'Дата на създаване', 'speedy-shipping' ) . ' <button style="display:none!important;" onclick="sortTable(5)">↓</button></th>';
        echo '<th>' . esc_html__( 'Статус', 'speedy-shipping' ) . '</th>';
        echo '<th>' . esc_html__( 'Получена на дата', 'speedy-shipping' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $orders as $order ) {
             $waybill_json = $order->get_meta('shipping_speedy_waybill');

    // Пропускаме поръчките, които съдържат 'error'
    if ( $waybill_json && stripos($waybill_json, '"error"') !== false ) {
        continue;
    }

            $waybill_id = '';
            $pickup_date = '';
            if ( $waybill_json ) {
                $waybill_data = json_decode( $waybill_json, true );
                if ( isset( $waybill_data['id'] ) ) {
                    $waybill_id = $waybill_data['id'];
                }
                if ( isset( $waybill_data['pickupDate'] ) ) {
                    $pickup_date = date('Y-m-d', strtotime($waybill_data['pickupDate']));
                }
            }
            $shipping_address = $order->get_meta('_billing_address_index');

            $cancel_url = wp_nonce_url(
                admin_url('admin-post.php?action=speedy_cancel_order&order_id=' . $order->get_id()),
                'speedy_cancel_order_' . $order->get_id()
            );

            $order_id = $order->get_id();

$referer = wp_get_referer();
if (!$referer) {
    $referer = admin_url('admin.php?page=wc-orders');
}

$trash_url = admin_url('admin.php?page=wc-orders&action=trash&id[0]=' . $order_id . '&_wp_http_referer=' . urlencode($referer) . '&_wpnonce=' . wp_create_nonce('bulk-orders'));


            echo '<tr>';
          $waybill_id_safe = esc_html($waybill_id);

            $kurier_url = add_query_arg(
                ['wc-api' => 'shipping_woocommerce_speedy_shipping_print_waybill_cb', 'kurier' => $waybill_id_safe],
                home_url()
            );

            $anulirane_url = add_query_arg(
                ['wc-api' => 'shipping_woocommerce_speedy_shipping_print_waybill_cb', 'anulirane' => $waybill_id_safe],
                home_url()
            );

            $track_url = 'https://www.speedy.bg/bg/track-shipment?shipmentNumber=' . rawurlencode($waybill_id_safe);
            $update_status_url = wp_nonce_url(
                admin_url('admin.php?page=speedy-orders&update_status_order=' . $order_id),
                'speedy_update_status_' . $order_id
            );

echo '<td><input type="checkbox" name="order_ids[]" value="' . $order_id . '" class="select-order" /></td>';
          
$courier_requested = $order->get_meta('_speedy_courier_requested');
$shipping_speedy = $order->get_meta('shipping_speedy_shipping');
$from_office = false;

// Проверяваме дали има dropoffOfficeId
if (!empty($shipping_speedy)) {
    $shipping_data = json_decode($shipping_speedy, true); // <-- декодиране на JSON
    if (!empty($shipping_data['sender']['dropoffOfficeId'])) {
        $from_office = true;
    }
}

echo '<td>';

if ($courier_requested === 'yes') {
    echo '<span style="color:green; font-weight:bold;">' . esc_html__( '✅ Има заявен куриер за тази поръчка', 'speedy-shipping' ) . '</span> | ';
}

// Покажи бутона само ако няма dropoffOfficeId
if (!$from_office && $courier_requested !== 'yes') {
    echo '<a href="' . esc_url($kurier_url) . '">' . esc_html__( 'Заявяване на куриер', 'speedy-shipping' ) . '</a> | ';
}

echo '<a target="_blank" href="' . esc_url($track_url) . '">' . esc_html__( 'Проследи в сайта на Спиди', 'speedy-shipping' ) . '</a> | ';
echo '<a href="' . esc_url($update_status_url) . '">' . esc_html__( 'Актуализиране на статус', 'speedy-shipping' ) . '</a> | ';
echo '<a href="' . esc_url($anulirane_url) . '" onclick="return confirm(\'' . esc_js( __( 'Сигурни ли сте, че искате да анулирате поръчката?', 'speedy-shipping' ) ) . '\')">' . esc_html__( 'Анулиране', 'speedy-shipping' ) . '</a>';

echo '</td>';






            echo '<td><a target="_blank" href="wp?wc-api=shipping_woocommerce_speedy_shipping_print_waybill_cb&speedy_waybill=' . esc_html( $waybill_id ) . '">' . esc_html( $waybill_id ) . '</a></td>';
            echo '<td><a href="' . esc_url( get_edit_post_link( $order->get_id() ) ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
            echo '<td>' . esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . '</td>';
            echo '<td>' . esc_html( $shipping_address ) . '</td>';
            echo '<td>' . esc_html( $order->get_date_created()->date('Y-m-d H:i') ) . '</td>';
            echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
            echo '<td>' . esc_html( $pickup_date ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">
    <div class="alignleft actions bulkactions">
        <label for="bulk-action-selector-bottom" class="screen-reader-text">' . esc_html__( 'Масов избор', 'speedy-shipping' ) . '</label>
        <select name="action2" id="bulk-action-selector-bottom">
            <option value="-1">' . esc_html__( 'Масови действия', 'speedy-shipping' ) . '</option>
            <option value="zaqvka">' . esc_html__( 'Заявяване на куриер', 'speedy-shipping' ) . '</option>
            <option value="cancel">' . esc_html__( 'Анулиране на пратки', 'speedy-shipping' ) . '</option>
            <option value="update_statuses">' . esc_html__( 'Актуализиране на статусите', 'speedy-shipping' ) . '</option>
        </select>
        <input type="submit" name="doaction2" id="doaction2" class="button action" value="' . esc_attr__( 'Прилагане', 'speedy-shipping' ) . '">
    </div>
</div>';

        echo '</form>';


        // Pagination
        // Pagination (компактен вариант)
        if ( $total_pages > 1 ) {
            $base_url = admin_url('admin.php?page=speedy-orders');
            echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:20px;">';

            echo '<span class="displaying-num">' . sprintf( esc_html__( '%d поръчки', 'speedy-shipping' ), $total_orders ) . '</span> ';

            // Previous page
            if ( $current_page > 1 ) {
                echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">«</a> ';
                echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', max(1, $current_page - 1), $base_url)) . '">‹</a> ';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled">«</span> ';
                echo '<span class="tablenav-pages-navspan button disabled">‹</span> ';
            }

            // Current page / total pages
            echo '<span class="paging-input">' . sprintf( esc_html__( '%1$d от %2$d', 'speedy-shipping' ), $current_page, $total_pages ) . '</span>';

            // Next page
            if ( $current_page < $total_pages ) {
                echo ' <a class="next-page button" href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">›</a>';
                echo ' <a class="next-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">»</a>';
            } else {
                echo ' <span class="tablenav-pages-navspan button disabled">›</span>';
                echo ' <span class="tablenav-pages-navspan button disabled">»</span>';
            }

            echo '</div></div>';
        }


        // JavaScript за сортиране и селект-ол
        echo '<script>
        document.getElementById("select-all").addEventListener("change", function() {
            var checkboxes = document.querySelectorAll(".select-order");
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = this.checked;
            }
        });

        function sortTable(n) {
            var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
            table = document.getElementById("speedy-orders-table");
            switching = true;
            dir = "desc";
            while (switching) {
                switching = false;
                rows = table.rows;
                for (i = 1; i < (rows.length - 1); i++) {
                    shouldSwitch = false;
                    x = rows[i].getElementsByTagName("TD")[n];
                    y = rows[i + 1].getElementsByTagName("TD")[n];
                    if (dir == "desc") {
                        if (x.innerText.toLowerCase() < y.innerText.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    }
                }
                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                    switchcount ++;
                } else {
                    if (switchcount == 0 && dir == "desc") {
                        dir = "asc";
                        switching = true;
                    }
                }
            }
        }
        </script>';

    } else {
        echo '<p>' . esc_html__( 'Няма намерени поръчки със Спиди.', 'speedy-shipping' ) . '</p>';
    }

    echo '</div>';
}







add_filter( 'woocommerce_shipping_methods', 'register_speedy_method' );

function register_speedy_method( $methods ) {
    require_once __DIR__ . '/class-speedy-shipping.php';

    $methods['speedy_method'] = Speedy_Shipping::class;

    return $methods;
}

add_action( 'admin_enqueue_scripts', 'speedy_include_scripts_admin' );
function speedy_include_scripts_admin() {
    if( isset( $_GET['section'] ) && $_GET['section'] === 'speedy_shipping' ) {
        wp_enqueue_script( 'speedy-admin', plugins_url( 'assets/admin.js', SPEEDY_DIR ), ['jquery'], microtime(), true );

        wp_localize_script( 'speedy-admin', 'speedy', [
            'admin_post' => admin_url( 'admin-post.php' ), 
            'ajax_url'   => admin_url( 'admin-ajax.php' )
        ] );
    }
}

add_action( 'init', 'init_speedy_actions' );
function init_speedy_actions() {
    // Enable plugin quick links
    add_filter( 'plugin_action_links_' . SPEEDY_SHIPPING_PLUGIN_NAME, 'speedy_shipping_plugin_settings_links' );
    
    if( defined( 'SHIPPING_COMMON_ACTIONS' ) )
        return;
    define( 'SHIPPING_COMMON_ACTIONS', true );

    add_action( 'wp_head', 'maybe_remove_country_field' );
    add_action( 'wp_head', 'autocomplete_dropdown_styles' );
    add_filter( 'woocommerce_default_address_fields', 'reorder_checkout_fields' );

    add_action( 'woocommerce_package_rates','maybe_remove_speedy_shipping', 10, 2 );

    add_filter( 'woocommerce_get_order_item_totals', 'edit_table_fields_thank_you_page', 10, 2 );
    add_action( 'woocommerce_before_checkout_billing_form', 'add_city_id_field' );

    add_filter( 'woocommerce_checkout_get_value', 'default_shipping_type_value', 10, 2 );
}

add_action( 'wp_enqueue_scripts', 'speedy_include_scripts_frontend' );
function speedy_include_scripts_frontend() {
    if( ! is_checkout() ) return;
    //if( get_locale() != 'bg_BG' ) return;

    $office_locator_lang = 'bg';
    $wpml_lang = apply_filters( 'wpml_current_language', null );

    if ( is_string( $wpml_lang ) && $wpml_lang !== '' ) {
        $office_locator_lang = strtolower( substr( $wpml_lang, 0, 2 ) );
    } else {
        $office_locator_lang = strtolower( substr( determine_locale(), 0, 2 ) );
    }

    if ( ! in_array( $office_locator_lang, [ 'bg', 'en' ], true ) ) {
        $office_locator_lang = 'bg';
    }

    wp_enqueue_script( 'popper', 'https://unpkg.com/@popperjs/core@2', false, false, true );
    wp_enqueue_script( 'tippy', 'https://unpkg.com/tippy.js@6', false, false, true );

    $checkout_js_version = file_exists( SPEEDY_PATH . '/assets/checkout.js' )
        ? (string) filemtime( SPEEDY_PATH . '/assets/checkout.js' )
        : '3.73';

    wp_enqueue_script( 'ws_speedy_checkout', plugins_url( 'assets/checkout.js', SPEEDY_DIR ), null, $checkout_js_version, true );
    wp_localize_script( 'ws_speedy_checkout', 'shipping_settings', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'office_locator_lang' => $office_locator_lang,
        'i18n' => [
            'map_button_label' => __( 'Избери офис/автомат от карта', 'speedy-shipping' ),
            'select_button_caption' => __( 'Изберете', 'speedy-shipping' ),
            'city_placeholder' => __( 'Изберете населено място', 'speedy-shipping' ),
            'office_placeholder' => __( 'Изберете офис', 'speedy-shipping' ),
            'automat_placeholder' => __( 'Изберете Автомат', 'speedy-shipping' ),
            'office2_no_test' => __( 'Автомат (без опция за ТЕСТ)', 'speedy-shipping' ),
            'office_label' => __( 'Офис', 'speedy-shipping' ),
            'automat_label' => __( 'Автомат', 'speedy-shipping' ),
            'required_title' => __( 'задължително', 'speedy-shipping' ),
            'address_note_placeholder' => __( 'Забележка към адреса:', 'speedy-shipping' ),
            'postcode_label' => __( 'Пощенски код', 'speedy-shipping' ),
        ],
    ]);
}



add_action('woocommerce_review_order_after_shipping', 'speedy_render_shipping_error_message', 15);

if (!function_exists('speedy_render_shipping_error_message')) {
    function speedy_render_shipping_error_message() {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        $chosen = WC()->session->get('chosen_shipping_methods');
        $chosen_rate_id = (string)($chosen[0] ?? '');

        // Woo rate id can be "speedy_shipping:instance_id"
        $chosen_method_id = strstr($chosen_rate_id, ':', true);
        if ($chosen_method_id === false) {
            $chosen_method_id = $chosen_rate_id;
        }

        if ($chosen_method_id !== 'speedy_shipping') {
            return;
        }

        $has_error = (bool) WC()->session->get('calculate_shipping_error');
        $msg = trim((string) WC()->session->get('shipping_error_message'));

        if (!$has_error || $msg === '') {
            return;
        }

        echo '<tr class="speedy-shipping-error-row">';
        echo '<th>Speedy</th>';
        echo '<td><ul class="woocommerce-error" role="alert" style="margin:0;"><li>' . esc_html(wp_strip_all_tags($msg)) . '</li></ul></td>';
        echo '</tr>';
    }
}


add_action('woocommerce_review_order_after_shipping', 'speedy_render_service_selector');
function speedy_render_service_selector() {
    if (!function_exists('WC') || !WC()->session) return;

    $chosen = WC()->session->get('chosen_shipping_methods');
    if (empty($chosen[0]) || $chosen[0] !== 'speedy_shipping') return;

    $available_services = WC()->session->get('shipping_speedy_available_services');
    if (!is_array($available_services) || count($available_services) < 2) return;

    $selected_service_id = (int) WC()->session->get('shipping_speedy_selected_service_id');

    $services_map = [];
    if (function_exists('speedy_get_services')) {
        $services_map = speedy_get_services();
    }

    echo '<tr class="speedy-service-selector"><th>' . esc_html__( 'Избор на услуга', 'speedy-shipping' ) . '</th><td>';
    echo '<div id="speedy-service-selector-box">';

    foreach ($available_services as $service) {
        $service_id = isset($service['serviceId']) ? (int) $service['serviceId'] : 0;
        if ($service_id <= 0) continue;

        $total = isset($service['total']) ? number_format((float)$service['total'], 2, '.', '') : '0.00';
        $currency = isset($service['currency']) ? sanitize_text_field((string)$service['currency']) : '';

        $service_name = isset($services_map[$service_id]) ? (string)$services_map[$service_id] : sprintf( __( 'Service %d', 'speedy-shipping' ), $service_id );
        $checked = ($selected_service_id === $service_id) ? ' checked="checked"' : '';

        echo '<label style="display:block;margin:4px 0;">';
        echo '<input type="radio" name="speedy_selected_service_choice" value="' . esc_attr($service_id) . '"' . $checked . '> ';
        echo esc_html($service_name . ' - ' . $total . ' ' . $currency);
        echo '</label>';
    }

    echo '</div>';
    echo '</td></tr>';
}

add_action( 'wp_enqueue_scripts', 'speedy_include_scripts_product' );
function speedy_include_scripts_product() {
    if( ! is_product() ) return;

    if(speedy_get_setting('fast_checkout') == 'no') return;

    $product  = wc_get_product( get_the_ID() );
    $status   = $product->get_stock_status();
    
    if( $status !== 'instock' || $product->get_type() == 'pw-gift-card' )
        return;

    wp_enqueue_style( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-beta.1/dist/css/select2.min.css' );
    wp_enqueue_script( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-beta.1/dist/js/select2.min.js', false, false, true );

    wp_enqueue_script( 'popper', 'https://unpkg.com/@popperjs/core@2', false, false, true );
    wp_enqueue_script( 'tippy', 'https://unpkg.com/tippy.js@6', false, false, true );

    wp_enqueue_style( 'fast-order', plugins_url( 'assets/fast-order.css', SPEEDY_DIR ), null, 1.2 );
    wp_enqueue_script( 'fast-order', plugins_url( 'assets/fast-order.js', SPEEDY_DIR ), null, microtime(), true );
}

add_filter('woocommerce_checkout_fields','speedy_add_abroad_office_field');
function speedy_add_abroad_office_field( $fields ) {
    $fields['billing']['billing_abroadoffice'] = [
        'type' => 'text',
        'label' => __( 'Офис', 'speedy-shipping' ),
        'required' => false,
        'class' => ['form-row-wide','abroad-office-field'],
        'priority' => 54
    ];
    return $fields;
}

// 2) save to order
add_action('woocommerce_checkout_update_order_meta','speedy_save_abroad_office');
function speedy_save_abroad_office( $order_id ) {
    if ( isset( $_POST['billing_abroadoffice'] ) ) {
        update_post_meta( $order_id, '_billing_abroadoffice', sanitize_text_field( wp_unslash( $_POST['billing_abroadoffice'] ) ) );
    }
    if ( isset( $_POST['billing_abroadoffice_id'] ) ) {
        update_post_meta( $order_id, '_billing_abroadoffice_id', sanitize_text_field( wp_unslash( $_POST['billing_abroadoffice_id'] ) ) );
    }
}

add_action('woocommerce_after_checkout_billing_form', 'speedy_render_abroad_office_extra');
function speedy_render_abroad_office_extra() {
    ?>
    <input type="hidden" id="billing_abroadoffice_id" name="billing_abroadoffice_id" />
    <ul id="billing_abroadoffice-suggestions" style="display:none; border:1px solid #ccc; max-height:200px; overflow:auto; list-style:none; padding:5px; margin-top:2px;"></ul>
    <?php
}

/**
 * Register plugin quick links
 *
 * @since   1.0.0
 */
function speedy_shipping_plugin_settings_links( array $links ) {
    $url = get_admin_url() . "admin.php?page=wc-settings&tab=shipping&section=speedy_shipping";

    $settings_links = [];
    $settings_links[] = '<a href="' . $url . '">' . esc_html__( 'Настройки', 'speedy-shipping' ) . '</a>';
    $settings_links[] = '<a href="https://speedy.bg/" target="_blank">' . esc_html__( 'Поддържка', 'speedy-shipping' ) . '</a>';

    $new_links = array_merge( $settings_links, $links );

    return $new_links;
}

if( ! function_exists( 'maybe_remove_country_field' ) ) {
    function maybe_remove_country_field() {
        $countries = WC()->countries->get_shipping_countries();

        foreach( $countries as $code => $country ) {
            if( get_locale() != 'bg_BG' ) break;
            if( $code != 'BG' && is_checkout() ) unset( $countries[$code] );
        }

        if( ! is_checkout() || count( $countries ) > 1 ) return;
        ?>
            <style>
                #billing_country_field {
                    /*
                    opacity: 0;
                    visibility: hidden;
                    overflow: hidden;
                    position: absolute;
                    left: -20000px;
                    */
                }
            </style>
        <?php
    }
}

if( ! function_exists( 'maybe_remove_speedy_shipping' ) ) {
    function maybe_remove_speedy_shipping( $rates, $package ) {
        if ( speedy_get_setting( 'availability_countries_mode', 'all' ) !== 'specific' ) {
            return $rates;
        }

        $selected_countries = speedy_get_setting( 'availability_specific_countries', [] );
        $selected_countries = is_array( $selected_countries ) ? $selected_countries : [];
        $selected_countries = array_map( static function( $country_code ) {
            return strtoupper( sanitize_text_field( (string) $country_code ) );
        }, $selected_countries );

        $destination = isset( $package['destination'] ) && is_array( $package['destination'] )
            ? $package['destination']
            : [];
        $destination_country = strtoupper( sanitize_text_field( (string) ( $destination['country'] ?? '' ) ) );

        if ( $destination_country === '' && function_exists( 'WC' ) && WC()->customer ) {
            $destination_country = strtoupper( (string) WC()->customer->get_shipping_country() );

            if ( $destination_country === '' ) {
                $destination_country = strtoupper( (string) WC()->customer->get_billing_country() );
            }
        }

        // Keep the method visible until WooCommerce has a destination country to evaluate.
        if ( $destination_country === '' || in_array( $destination_country, $selected_countries, true ) ) {
            return $rates;
        }

        foreach ( $rates as $rate_key => $rate ) {
            if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) && $rate->get_method_id() === 'speedy_shipping' ) {
                unset( $rates[ $rate_key ] );
            }
        }

        return $rates;
    }
}

if( ! function_exists( 'autocomplete_dropdown_styles' ) ) {
    function autocomplete_dropdown_styles() {
        if( ! is_checkout() ) return;
        ?>
            <style>
                .select2-container {
                    width: 100% !important;
                }
                #billing_city_field {
                    position: relative;
                    z-index: 1;
                }
                .ui-helper-hidden-accessible {
                    display: none;
                }
                #billing_city_autocomplete ul {
                    position: absolute !important;
                    background: #fff;
                    list-style: none;
                    margin: 0;
                    padding: 0;
                    -webkit-box-shadow: 0px 0px 5px 0px rgba(0,0,0,0.15);
                    -moz-box-shadow: 0px 0px 5px 0px rgba(0,0,0,0.15);
                    box-shadow: 0px 0px 5px 0px rgba(0,0,0,0.15);
                }
                #billing_city_autocomplete ul li {
                    cursor: pointer;
                    border: 1px solid #E3F4FD;
                    padding: 0.3em 0.6em;
                }

                #billing_address_1_field,
                #billing_postcode_field {
                    display: none !important;
                }
                
                .hidden-first {
                    display: none
                }

                .hidden-first .select2-container {
                    width: 100% !important;
                }

               
                .show-address-fields #billing_postcode_field,
                .show-address-fields #billing_state_field {
                    display: block !important;
                }
                #billing_street_field{
                        width: 273px;
                    float: left;
                }
                #billing_street_number_field{
                        width: 181px;
                }
                .poledrugo{
                        width: 110px!important;
                }
                .poledrugo .optional{
                    display: none!important;
                }
                #billing_neighborhood{
                    margin-left: 18px;
                }
                #billing_street_number_field .optional{
                    display: none!important;
                }
                .woocommerce form .form-row select
                     {
                        cursor: pointer;
                        margin: 0;
                        height: 52px;
                        border-radius: 3px;
                    }
                @media (max-width: 800px){
                     .poledrugo{
                        width: 22%!important;
                    }
                    #billing_street_number_field {
                        width: 75px!important;
                    }
                }
            </style>
        <?php
    }
}

if( ! function_exists( 'reorder_checkout_fields' ) ) {
    function reorder_checkout_fields( $address_fields ) {
        $address_fields['country']['label'] = __( 'Държава', 'speedy-shipping' );

        $address_fields['shipping_type'] = [
            'type'     => 'select',
            'label'    => __( 'Доставка до', 'speedy-shipping' ),
            'required' => true,
            'class'    => ['form-row-first', 'shipping-type'],
            'priority' => 49,
            'options'  => [
                'office2' => __( 'Автомат', 'speedy-shipping' ),
                'office'  => __( 'Офис', 'speedy-shipping' ),
                'address' => __( 'Адрес', 'speedy-shipping' )
            ],
            'autocomplete' => false,
            'default'      => 'office'
        ];

        $address_fields['neighborhood'] = [
            'type'        => 'text',
            'label'       => __( 'Квартал', 'speedy-shipping' ),
            'required'    => false,
            'class'       => ['form-row-first'],
            'priority'    => 51,
            'autocomplete'=> false,
        ];

        $address_fields['street'] = [
            'type'        => 'text',
            'label'       => __( 'Улица', 'speedy-shipping' ),
            'required'    => false,
            'class'       => ['form-row'],
            'priority'    => 52,
            'autocomplete'=> false,
        ];

        // NEW: Адрес (2) - ще го показваме с JS само за foreign + addressType=2
        $address_fields['street2'] = [
                'type'        => 'text',
            'label'       => __( 'Адрес (2)', 'speedy-shipping' ),
                'required'    => false,
                'class'       => ['form-row form-row-wide'],
                'priority'    => 53,
                'autocomplete'=> false,
        ];


        $address_fields['street_number'] = [
            'type'        => 'text',
            'label'       => __( '№:', 'speedy-shipping' ),
            'required'    => false,
            'class'       => ['form-row-first'],
            'priority'    => 53,
        ];

        $address_fields['block'] = [
            'type'        => 'text',
            'label'       => __( 'Бл.', 'speedy-shipping' ),
            'required'    => false,
            'class'       => ['form-row-first poledrugo'],
            'priority'    => 54,
        ];

        $address_fields['entrance'] = [
            'type'        => 'text',
            'label'       => __( 'Вх.', 'speedy-shipping' ),
            'required'    => false,
            'class'       => ['form-row-first poledrugo'],
            'priority'    => 55,
        ];

        $address_fields['floor'] = [
            'type'        => 'text',
            'label'       => __( 'Ет.', 'speedy-shipping' ),
            'required'    => false,
            'class'       => ['form-row-first poledrugo'],
            'priority'    => 56,
        ];

        $address_fields['apartment'] = [
            'type'        => 'text',
            'label'       => __( 'Ап.', 'speedy-shipping' ),
            'required'    => false,
            'class'       => ['form-row-first poledrugo'],
            'priority'    => 57,
        ];

        if( speedy_get_setting('addressonefield') == 'YES' ) {
            $address_fields['addressonefield'] = [
              'type'        => 'hidden',
             'required'    => false,
             'class'       => ['form-row-last'],
             'priority'    => 58,
            ];
        }
        if( speedy_get_setting('autoclose') == 'YES' ) {
            $address_fields['autoclose'] = [
              'type'        => 'hidden',
             'required'    => false,
             'class'       => ['form-row-last'],
             'priority'    => 59,
            ];
        }

       if ( speedy_get_setting('test_before_pay') === 'OPEN' || speedy_get_setting('test_before_pay') === 'TEST' ) {
            $address_fields['testpusnat'] = [
                'type'     => 'hidden',
                'required' => false,
                'class'    => ['form-row-last'],
                'priority' => 60,
            ];
        }



        $address_fields['state']['priority'] = 48;
        $address_fields['state']['class'] = ['form-row-last', 'state-field'];

        $address_fields['postcode']['priority'] = 49;
        unset( $address_fields['postcode'] );

        $address_fields['city']['priority']  = 49;
        $address_fields['city']['class'] = ['form-row-first', 'address-field'];
        $address_fields['city']['autocomplete'] = false;
        $address_fields['city']['value'] = '';
        $address_fields['city']['type']  = 'select';
        $address_fields['city']['options'] = [ __( 'Изберете област', 'speedy-shipping' ) ];

        $address_fields['office'] = [
            'type'     => 'select',
            'label'    => __( 'Офис', 'speedy-shipping' ),
            'required' => false,
            'class'    => ['form-row-last', 'office-type'],
            'priority' => '50',
            'options'  => [ __( 'Изберете офис', 'speedy-shipping' ) ]
        ];

        $address_fields['address_1']['class'] = ['form-row-wide', 'address_1-field'];

        $only_gift_cards = is_only_gift_cards();

        if($only_gift_cards) {
            unset( $address_fields['company'] );
            unset( $address_fields['country'] );
            unset( $address_fields['state'] );
            unset( $address_fields['city'] );
            unset( $address_fields['shipping_type'] );
            unset( $address_fields['office'] );
            unset( $address_fields['address_1'] );
            unset( $address_fields['address_2'] );
        }

        return $address_fields;
    }
}

if( !function_exists( 'speedy_reorder_shipping_fields') ) {
    function speedy_reorder_shipping_fields( $address_fields ) {
        if( !is_checkout() ) return $address_fields;

        // Ensure shipping rates are calculated
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        $shipping_methods = [];

        $fallback_shipping_packages = WC()->shipping->load_shipping_methods();

        $shipping_packages = WC()->cart->get_shipping_packages();
        foreach( array_keys( $shipping_packages ) as $key ) {
            if( $shipping_for_package = WC()->session->get('shipping_for_package_'.$key) ) {
                if( isset($shipping_for_package['rates']) ) {
                    // Loop through customer available shipping methods
                    foreach ( $shipping_for_package['rates'] as $rate_key => $rate ) {
                        $rate_id = $rate->id; // the shipping method rate ID (or $rate_key)
                        $method_id = $rate->method_id; // the shipping method label
                        $instance_id = $rate->instance_id; // The instance ID
                        $cost = $rate->label; // The cost
                        $label = $rate->label; // The label name
                        $taxes = $rate->taxes; // The taxes (array)
                        
                        $shipping_methods[$method_id] = $label;
                    }
                }
            }
        }

        $cnt_step = 1;
        $label_prefix_delivery_type = '';

        if(
            empty( $shipping_methods )
            && array_key_exists( 'econt_shipping', $fallback_shipping_packages )
            && array_key_exists( 'speedy_shipping', $fallback_shipping_packages )
        ) {
            $shipping_methods['econt_shipping'] = $fallback_shipping_packages['econt_shipping']->title;
            $shipping_methods['speedy_shipping'] = $fallback_shipping_packages['speedy_shipping']->title;
        }

        if( !empty( $shipping_methods ) ) {
            $label_prefix_delivery_type = 'Стъпка ' . $cnt_step . ': ';
            $cnt_step++;
        }

        if( array_key_exists( 'shipping_type', $address_fields ) ) {
            $address_fields['shipping_type']['label'] = 'Стъпка ' . $cnt_step . ': ' . $address_fields['shipping_type']['label'];

            $address_fields['shipping_type']['class'] = [ 'form-row-wide', 'shipping-type' ];

            $cnt_step++;
        }

        if( array_key_exists( 'state', $address_fields ) ) {
            $address_fields['state']['required'] = true;

            $address_fields['state']['label'] = 'Стъпка ' . $cnt_step . ': ' . $address_fields['state']['label'];

            $address_fields['state']['class'] = [ 'form-row-wide', 'state-field' ];

            $cnt_step++;
        }

        if( array_key_exists( 'city', $address_fields ) ) {
            $address_fields['city']['label'] = 'Стъпка ' . $cnt_step . ': ' . $address_fields['city']['label'];

            $address_fields['city']['class'] = [ 'form-row-wide', 'address-field' ];

            $cnt_step++;
        }

        if( array_key_exists( 'address_1', $address_fields ) ) {
            $address_fields['address_1']['label'] = 'Стъпка ' . $cnt_step . ': ' . $address_fields['address_1']['label'];

            $address_fields['address_1']['class'] = [ 'form-row-wide', 'address_1-field' ];

            // $cnt_step++; // equal to billing office
        }

        if( array_key_exists( 'office', $address_fields ) ) {
            $address_fields['office']['label'] = 'Стъпка ' . $cnt_step . ': ' . $address_fields['office']['label'];

            $address_fields['office']['class'] = [ 'form-row-wide', 'office-type' ];

            // $cnt_step++; // equal to billing address 1
        }

        if( empty( $shipping_methods) ) return $address_fields;
        
        if( array_key_exists('country', $address_fields) ) $address_fields['country']['class'] = [ 'form-row-wide', 'address-field', 'update_totals_on_change' ];

        $address_fields['delivery_type'] = [
            'type'     => 'select',
            'label'    => $label_prefix_delivery_type . 'Метод на доставка',
            'required' => true,
            'class'    => [ 'form-row-wide', 'delivery-type', 'update_totals_on_change' ],
            'priority' => 40,
            'options'  => $shipping_methods,
            'autocomplete' => false,
            'default'      => 'office'
        ];

        return $address_fields;
    }
}

add_action( 'woocommerce_after_checkout_validation', 'speedy_validate_checkout', 10, 2 );
function speedy_validate_checkout( $data, $errors ) {
    $chosen = WC()->session->get( 'chosen_shipping_methods' );
    if ( empty( $chosen[0] ) || $chosen[0] !== 'speedy_shipping' ) {
        return;
    }

    $has_speedy_error = WC()->session ? (bool) WC()->session->get( 'calculate_shipping_error' ) : false;
    $speedy_error_message = WC()->session ? trim( (string) WC()->session->get( 'shipping_error_message' ) ) : '';

    if ( $has_speedy_error ) {
        $errors->add(
            'speedy_shipping_error',
            $speedy_error_message !== '' ? $speedy_error_message : 'Има грешка при калкулацията на Speedy.'
        );
        return;
    }

    $shipping_type = isset( $data['billing_shipping_type'] ) ? (string) $data['billing_shipping_type'] : '';

    // Синхронизация: foreign office id -> стандартно billing_office
    $office_id = ! empty( $_POST['billing_office'] ) ? absint( $_POST['billing_office'] ) : 0;
    if ( $office_id <= 0 && ! empty( $_POST['billing_abroadoffice_id'] ) ) {
        $office_id = absint( $_POST['billing_abroadoffice_id'] );
        $_POST['billing_office'] = $office_id;
    }

    $billing_country_iso2 = '';
    if ( ! empty( $data['billing_country'] ) ) {
        $billing_country_iso2 = strtoupper( (string) $data['billing_country'] );
    } elseif ( ! empty( $_POST['billing_country'] ) ) {
        $billing_country_iso2 = strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) );
    }

   // Телефонът е задължителен винаги
$billing_phone = '';

if ( ! empty( $_POST['billing_phone'] ) ) {
    $billing_phone = trim( sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) );
} elseif ( ! empty( $data['billing_phone'] ) ) {
    $billing_phone = trim( sanitize_text_field( (string) $data['billing_phone'] ) );
}

if ( $billing_phone === '' ) {
    $errors->add( 'validation', 'Телефонът е задължителен' );
}

    if ( in_array( $shipping_type, [ 'office', 'office2' ], true ) ) {
        // Чужбина: валидираме само да има избран foreign office id
        if ( $billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG' ) {
            if ( $office_id <= 0 ) {
                $errors->add( 'validation', 'Невалиден офис' );
            }
        } else {
            // BG: старата проверка в локалната база
            $office = Speedy_DB::get_office_by_id( $office_id );
            if ( ! $office ) {
                $errors->add( 'validation', 'Невалиден офис' );
            }
        }

        return;
    }

    if ( $shipping_type === 'address' ) {
        // city_id идва от hidden полето city_id (siteId)
        $city_id = ! empty( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;

        // Текстов град (fallback при foreign)
        $city_text = '';
        if ( ! empty( $_POST['billing_city'] ) ) {
            $city_text = trim( sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) );
        } elseif ( ! empty( $data['billing_city'] ) ) {
            $city_text = trim( sanitize_text_field( (string) $data['billing_city'] ) );
        }

        // billing_address_2 е "Забележка към адреса" за BG и пощенски код за част от foreign flow.
        $address_note = '';
        if ( ! empty( $_POST['billing_address_2'] ) ) {
            $address_note = trim( sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) ) );
        } elseif ( ! empty( $data['billing_address_2'] ) ) {
            $address_note = trim( sanitize_text_field( (string) $data['billing_address_2'] ) );
        }

        // Чужбина: допускаме city_id ИЛИ текстов град и изискваме пощенски код.
        if ( $billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG' ) {
            if ( $city_id <= 0 && $city_text === '' ) {
                $errors->add( 'validation', 'Невалиден град' );
            }

            if ( $address_note === '' ) {
                $errors->add( 'validation', 'Пощенски код е задължителен за международна доставка' );
            }

            return;
        }

        $billing_street = '';
        if ( ! empty( $_POST['billing_street'] ) ) {
            $billing_street = trim( sanitize_text_field( wp_unslash( $_POST['billing_street'] ) ) );
        } elseif ( ! empty( $data['billing_street'] ) ) {
            $billing_street = trim( sanitize_text_field( (string) $data['billing_street'] ) );
        }

        $billing_street_number = '';
        if ( ! empty( $_POST['billing_street_number'] ) ) {
            $billing_street_number = trim( sanitize_text_field( wp_unslash( $_POST['billing_street_number'] ) ) );
        } elseif ( ! empty( $data['billing_street_number'] ) ) {
            $billing_street_number = trim( sanitize_text_field( (string) $data['billing_street_number'] ) );
        }

        $billing_block = '';
        if ( ! empty( $_POST['billing_block'] ) ) {
            $billing_block = trim( sanitize_text_field( wp_unslash( $_POST['billing_block'] ) ) );
        } elseif ( ! empty( $data['billing_block'] ) ) {
            $billing_block = trim( sanitize_text_field( (string) $data['billing_block'] ) );
        }

        $billing_neighborhood = '';
        if ( ! empty( $_POST['billing_neighborhood'] ) ) {
            $billing_neighborhood = trim( sanitize_text_field( wp_unslash( $_POST['billing_neighborhood'] ) ) );
        } elseif ( ! empty( $data['billing_neighborhood'] ) ) {
            $billing_neighborhood = trim( sanitize_text_field( (string) $data['billing_neighborhood'] ) );
        }

        $address_one_field_enabled = speedy_get_setting( 'addressonefield' ) === 'YES';
        $has_street_or_neighborhood = ( $billing_street !== '' || $billing_neighborhood !== '' );
        $has_street_number_or_block = ( $billing_street_number !== '' || $billing_block !== '' );
        $has_valid_detailed_address = $has_street_or_neighborhood && $has_street_number_or_block;

        if ( $address_one_field_enabled ) {
            if ( $address_note === '' ) {
                $errors->add( 'validation', 'Моля, попълнете "Забележка към адреса".' );
            }
        } elseif ( ! $has_valid_detailed_address && $address_note === '' ) {
            $errors->add(
                'validation',
                'Моля, попълнете адрес: "Улица" или "Квартал" заедно с "Номер на улица" или "Блок", или попълнете "Забележка към адреса".'
            );
        }

        // BG: трябва валиден city_id от локалната база.
        if ( $city_id <= 0 ) {
            $errors->add( 'validation', 'Невалиден град' );
            return;
        }

        $city = Speedy_DB::get_city_by_id( $city_id );
        if ( ! $city ) {
            $errors->add( 'validation', 'Невалиден град' );
        }
    }
}

add_action( 'woocommerce_checkout_order_processed', 'speedy_add_data_to_order', 10, 3 );
function speedy_add_data_to_order( $order_id, $data, $order ) {
    if( WC()->session->get('chosen_shipping_methods')[0] !== 'speedy_shipping' ) return;

    // NEW: запиши Speedy countryId в поръчката (напр. 300 за GR)
    $speedy_country_id = 0;

    if ( isset($_POST['speedy_country_id']) ) {
        $speedy_country_id = (int) sanitize_text_field( wp_unslash( $_POST['speedy_country_id'] ) );
    }

    if ( $speedy_country_id <= 0 && WC()->session ) {
        $speedy_session = WC()->session->get('shipping_speedy_shipping');
        if ( is_array($speedy_session) && isset($speedy_session['recipient']['addressLocation']['countryId']) ) {
            $speedy_country_id = (int) $speedy_session['recipient']['addressLocation']['countryId'];
        }
    }

    if ( $speedy_country_id > 0 ) {
        $order->update_meta_data( '_shipping_shipping_country', $speedy_country_id );
    }

        if( $data['billing_shipping_type'] === 'office' ) {
            $office_id = !empty($_POST['billing_office']) ? absint($_POST['billing_office']) : 0;
            if ( $office_id <= 0 && !empty($_POST['billing_abroadoffice_id']) ) {
                $office_id = absint($_POST['billing_abroadoffice_id']);
            }

            // Записваме винаги и в стандартното поле
            $order->update_meta_data('_billing_office', $office_id);

            $billing_country_iso2 = !empty($data['billing_country']) ? strtoupper((string)$data['billing_country']) : '';
            if ( $billing_country_iso2 === '' && !empty($_POST['billing_country']) ) {
                $billing_country_iso2 = strtoupper(sanitize_text_field(wp_unslash($_POST['billing_country'])));
            }

            if ( $billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG' ) {
                $abroad_office = !empty($_POST['billing_abroadoffice']) ? sanitize_text_field(wp_unslash($_POST['billing_abroadoffice'])) : '';
                if ( $abroad_office !== '' ) {
                    $order->set_billing_address_1($abroad_office);
                    $order->set_billing_city($abroad_office);
                }
                $order->add_meta_data('shipping_type', 'Офис');
            } else {
                $office = Speedy_DB::get_office_by_id( $office_id );
                if ( $office ) {
                    $order->set_billing_postcode( $office->post_code );
                    $order->set_billing_address_1( $office->address );
                }
                $order->add_meta_data( 'shipping_type', 'Офис' );
            }
        } else {
        $order->add_meta_data( 'shipping_type', 'Адрес' );

        // Вземаме city_id (siteId за чужбина)
       // $city_id = !empty( $_POST['city_id'] )
         //       ? abs( $_POST['city_id'] )
           //     : ( !empty( $_POST['billing_city'] ) ? abs( $_POST['billing_city'] ) : 0 );

              $city_id = ! empty( $_POST['city_id'] )
    ? absint( wp_unslash( $_POST['city_id'] ) )
    : 0;

        // NEW: държава
        $billing_country_iso2 = '';
        if ( ! empty( $data['billing_country'] ) ) {
            $billing_country_iso2 = strtoupper( (string) $data['billing_country'] );
        } elseif ( ! empty( $_POST['billing_country'] ) ) {
            $billing_country_iso2 = strtoupper( (string) $_POST['billing_country'] );
        }

        // NEW: ако е чужбина -> НЕ ползваме Speedy_DB (BG градове), а записваме siteId в meta
        if ( $billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG' ) {
            

            // записваме siteId за по-късно (генериране/калкулации)
            if ( $city_id > 0 ) {
                $order->update_meta_data( '_shipping_city', $city_id );
            }

            // запазваме името на града като текст (от foreign input-а)
            $foreign_city_text = '';
            if ( ! empty( $_POST['billing_city'] ) ) {
                $foreign_city_text = sanitize_text_field( wp_unslash( $_POST['billing_city'] ) );
            } elseif ( ! empty( $data['billing_city'] ) ) {
                $foreign_city_text = sanitize_text_field( $data['billing_city'] );
            }

            if ( $foreign_city_text !== '' ) {
                $order->set_billing_city( $foreign_city_text );
            }

        
            $foreign_postcode = '';
            if ( ! empty( $_POST['billing_address_2'] ) ) {
                $foreign_postcode = sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) );
            } elseif ( ! empty( $data['billing_address_2'] ) ) {
                $foreign_postcode = sanitize_text_field( $data['billing_address_2'] );
            }

            if ( $foreign_postcode !== '' ) {
                $order->set_billing_postcode( $foreign_postcode );
            }

            // по желание: postcode/state празни при чужбина
            $order->save();
            return;
        }

        // BG (както е било)
        $city = Speedy_DB::get_city_by_id( $city_id );

        $order->set_billing_city( $city->type . ' ' . $city->name );
        $order->set_billing_state( $city->region );
        $order->set_billing_postcode( $city->post_code );
    }

    $order->save();
}

// add_action( 'woocommerce_checkout_create_order', 'speedy_add_price_to_order', 10, 2 );
function speedy_add_price_to_order( $order, $data ) {
    if( WC()->session->get('chosen_shipping_methods')[0] !== 'speedy_shipping' ) return;
    
   if (speedy_get_setting('fixed_shipping') === 'yes') {
        if ($data['billing_shipping_type'] === 'office') {
            $cost = speedy_get_setting('fixed_shipping_office');
        } elseif ($data['billing_shipping_type'] === 'office2') {
            $cost = speedy_get_setting('fixed_shipping_automat');
        } else {
            $cost = speedy_get_setting('fixed_shipping_address');
        }
    } else {
        $cost = Speedy_API::calculate_shipping($data);
    }


    $order->set_shipping_total( $cost );
    $order->set_total( $order->get_total( 'edit' ) + $cost );
}

add_action('woocommerce_order_status_on-hold', 'speedy_create_waybill', 10, 1);
add_action('woocommerce_order_status_processing', 'speedy_create_waybill', 10, 1);

function speedy_create_waybill( $order_id ) {
    if ( ! $order_id )
        return;

    $order = wc_get_order( $order_id );

    if( !$order->has_shipping_method('speedy_shipping') ) return;
    
    $waybill = json_decode($order->get_meta('shipping_speedy_waybill', true), true);
    if($waybill != NULL || !WC()->session) return;

    $arr_delivery_data = WC()->session->get( 'shipping_speedy_shipping' );
    $arr_delivery_data['ref1'] = 'Поръчка №' . $order_id;

    $arr_delivery_data['clientSystemId'] = '24122711214';
    
$selected_service_id = (int) $order->get_meta('_speedy_selected_service_id');
if ($selected_service_id > 0) {
    if (!isset($arr_delivery_data['service']) || !is_array($arr_delivery_data['service'])) {
        $arr_delivery_data['service'] = [];
    }
    $arr_delivery_data['service']['serviceId'] = $selected_service_id;
}

    $order->update_meta_data( 'shipping_speedy_shipping', json_encode( $arr_delivery_data ) );

    if( speedy_get_setting( 'generate_waybill' ) != 'yes' )
        return;

    $sender_officeyesno = speedy_get_setting( 'sender_officeyesno' );
    $sender_office_id = (int) speedy_get_setting( 'sender_office' );

    unset($arr_delivery_data['sender']['address']);
    unset($arr_delivery_data['sender']['addressLocation']);
    unset($arr_delivery_data['sender']['pickupOfficeId']);
    unset($arr_delivery_data['sender']['dropoffOfficeId']);

    $arr_delivery_data['sender']['phone1']['number'] = speedy_get_setting( 'sender_phone' );
    $arr_delivery_data['sender']['contactName'] = speedy_get_setting( 'sender_name' );
   $admin_email = sanitize_email((string) get_option('admin_email'));
if ($admin_email === '') {
    $admin_email = sanitize_email((string) get_option('new_admin_email'));
}
    $arr_delivery_data['sender']['email'] = $admin_email !== '' ? $admin_email : speedy_get_setting('sender_email');

    if ( $sender_officeyesno === 'YES' && $sender_office_id > 0 ) {
        $arr_delivery_data['sender']['dropoffOfficeId'] = $sender_office_id;
    }

    $arr_delivery_data['recipient']['phone1']['number'] = $order->get_billing_phone();
    $arr_delivery_data['recipient']['clientName'] = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $arr_delivery_data['recipient']['email'] = $order->get_billing_email();

    $arr_delivery_data['content']['contents'] = "Продукти от " . get_bloginfo( 'name' );
    $arr_delivery_data['content']['package'] = speedy_get_setting( 'opakovka' );

    $billing_shipping_type = trim( (string) $order->get_meta( '_billing_shipping_type' ) );
    if ( $billing_shipping_type === 'Офис' ) {
        $billing_shipping_type = 'office';
    }
    if ( ! in_array( $billing_shipping_type, [ 'office', 'office2', 'address' ], true ) ) {
        $billing_shipping_type = 'address';
    }

    $cenadostavka_mode = (string) speedy_get_setting( 'cenadostavka' );
    $configured_shipping_amount = speedy_get_configured_shipping_amount_by_type( $billing_shipping_type, $order );

    $uses_non_speedy_shipping_price = in_array( $cenadostavka_mode, [ 'fixedprices', 'fileprices' ], true );

    $order->update_meta_data( 'shipping_speedy_waybit_raw_input', json_encode($arr_delivery_data) );

    $order_payment_method = method_exists( $order, 'get_payment_method' )
            ? $order->get_payment_method()
            : '';

    if ( $order_payment_method === 'cod' ) {
        // Взимаме Woo суми
        $order_total    = (float) $order->get_total();          // крайна сума след ваучери/купони
        $shipping_total = (float) $order->get_shipping_total(); // сума за доставка

        // Настройка "Включване на цената за доставката в стойността на НП"
        $includes_shipping_in_cod = ( speedy_get_setting( 'includeshippingprice' ) === 'YES' );

        if ( $uses_non_speedy_shipping_price ) {
            $cod_amount = max( 0, $order_total - $shipping_total ) + $configured_shipping_amount;
        } elseif ( $includes_shipping_in_cod ) {
            // НП = продукти + доставка, но след отстъпки
            $cod_amount = $order_total;
        } else {
            // НП = само продукти (крайна сума минус доставка)
            $cod_amount = max( 0, $order_total - $shipping_total );
        }

        // Уверяваме се, че структурата за COD съществува
        if ( ! isset( $arr_delivery_data['service']['additionalServices']['cod'] ) ) {
            $arr_delivery_data['service']['additionalServices']['cod'] = [];
        }

        $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round( $cod_amount, 2 );

        if ( $includes_shipping_in_cod && ! $uses_non_speedy_shipping_price ) {
            $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] = true;
        } elseif ( isset( $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] ) ) {
            unset( $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] );
        }
    }

    // Add Speedy credentials
    $arr_delivery_data['userName'] = speedy_username();
    $arr_delivery_data['password'] = speedy_password();

    // Ако плащането на поръчката НЕ е COD — същата логика
    $payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '';
    if ($payment_method !== 'cod') {

        // платец на куриерската услуга – изпращач
        $arr_delivery_data['payment']['courierServicePayer'] = 'SENDER';

        // ако все пак държите fiscalReceiptItems да се пращат и при банков превод,
        // НЕ трябва да зануляваме cod.amount и НЕ трябва да трием fiscalReceiptItems.

        if (!isset($arr_delivery_data['service']['additionalServices'])) {
            $arr_delivery_data['service']['additionalServices'] = [];
        }
        if (!isset($arr_delivery_data['service']['additionalServices']['cod'])) {
            $arr_delivery_data['service']['additionalServices']['cod'] = [];
        }

        // При банков превод: стойност = общо (продукти + доставка)
        $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round((float) $order->get_total(), 2);

        // НЕ‑COD: махаме processingType, за да не се третира като наложен платеж
        if (isset($arr_delivery_data['service']['additionalServices']['cod']['processingType'])) {
            unset($arr_delivery_data['service']['additionalServices']['cod']['processingType']);
        }

        // не пипаме fiscalReceiptItems тук!
        // (ако искате да НЕ се пращат при банков превод – тогава тук е мястото да ги unset-нете)
    }


    // FIX: COD amount при shipment
    // - при COD: amount = само продукти (без доставка), освен ако includeshippingprice=YES
    // - ако има fiscalReceiptItems: amount = сбор amountWithVat (най-точно)
    $includes_shipping_in_cod = (speedy_get_setting('includeshippingprice') === 'YES');

    if (!isset($arr_delivery_data['service']['additionalServices'])) {
        $arr_delivery_data['service']['additionalServices'] = [];
    }
    if (!isset($arr_delivery_data['service']['additionalServices']['cod'])) {
        $arr_delivery_data['service']['additionalServices']['cod'] = [];
    }

    if ($payment_method === 'cod') {

        $has_fiscal_items =
                isset($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
                && is_array($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
                && !empty($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems']);

        // ако имаме фискални редове -> сумираме amountWithVat (само продукти)
        if ($has_fiscal_items) {
            $sum_with_vat = 0.0;
            foreach ($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] as $it) {
                if (is_array($it) && isset($it['amountWithVat'])) {
                    $sum_with_vat += (float) $it['amountWithVat'];
                }
            }

            $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($sum_with_vat, 2);

            // IMPORTANT: fiscalReceiptItems описват това, което ще се отчита фискално.
            // Ако доставката трябва да влиза в НП, тя трябва да е отделен фискален ред.
            // Затова тук НЕ включваме автоматично доставка.
            if (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
                unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
            }

        } else {
            // fallback: продукти = total - shipping
            $order_total    = (float) $order->get_total();
            $shipping_total = (float) $order->get_shipping_total();

            $products_only = max(0, $order_total - $shipping_total);
            $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($products_only, 2);

            // ако настройката изисква да включим доставка в НП -> amount = total
            if ($uses_non_speedy_shipping_price) {
                $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($products_only + $configured_shipping_amount, 2);
                if (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
                    unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
                }
            } elseif ($includes_shipping_in_cod) {
                $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round((float) $order->get_total(), 2);
                $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] = true;
            } elseif (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
                unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
            }
        }

        // processingType да не липсва
        if (empty($arr_delivery_data['service']['additionalServices']['cod']['processingType'])) {
           
$arr_delivery_data['service']['additionalServices']['cod']['processingType'] =
    (speedy_get_setting('moneytransfer') === 'YES') ? 'POSTAL_MONEY_TRANSFER' : 'CASH';
        }


    } else {
        // НЕ-COD: amount = 0
        $arr_delivery_data['service']['additionalServices']['cod']['amount'] = 0;

        if (isset($arr_delivery_data['service']['additionalServices']['cod']['processingType'])) {
            unset($arr_delivery_data['service']['additionalServices']['cod']['processingType']);
        }
    }

    /**
     * FIX: fiscalReceiptItems при генериране от ORDER (няма WC()->cart).
     * Ако moneytransfer = fiscal / fiscalone -> правим редове по артикули + ред "Доставка"
     * и изравняваме cod.amount със сбора (за да няма mismatch).
     */
    $mode = speedy_get_setting('moneytransfer');

    if ($payment_method == 'cod' && ($mode === 'fiscal' || $mode === 'fiscalone')) {

        if (!isset($arr_delivery_data['service']['additionalServices'])) {
            $arr_delivery_data['service']['additionalServices'] = [];
        }
        if (!isset($arr_delivery_data['service']['additionalServices']['cod'])) {
            $arr_delivery_data['service']['additionalServices']['cod'] = [];
        }

        $fiscal_items = [];
        $sum_with_vat = 0.0;

        // 1) Продукти
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $qty = (float) $item->get_quantity();
            $name = (string) $item->get_name();

            $tax_class = (string) $product->get_tax_class();
            $vat_group = 'Б';
            $vat_rate  = 0.20;

            if ($tax_class === 'zero-rate') {
                $vat_group = 'А';
                $vat_rate  = 0.00;
            } elseif ($tax_class === 'reduced-rate') {
                $vat_group = 'Г';
                $vat_rate  = 0.09;
            }

            $line_with_vat = (float) $item->get_total() + (float) $item->get_total_tax();
            $line_with_vat = round($line_with_vat, 2);

            $line_ex_vat = ($vat_rate > 0)
                    ? round($line_with_vat / (1 + $vat_rate), 2)
                    : $line_with_vat;

            $fiscal_items[] = [
                    'description'   => mb_substr($name . ' (x' . (int) $qty . ')', 0, 50),
                    'vatGroup'      => $vat_group,
                    'amount'        => $line_ex_vat,
                    'amountWithVat' => $line_with_vat,
            ];

            $sum_with_vat += $line_with_vat;
        }

        $should_include_shipping_in_cod = ! empty( $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] );
        $should_add_configured_shipping = $cenadostavka_mode === 'fixedprices' && $configured_shipping_amount > 0;

        // 2) Доставка като отделен ред при изрично включване в НП или при фиксирана цена по тип
        $shipping_with_vat = $should_add_configured_shipping
            ? round( $configured_shipping_amount, 2 )
            : round((float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2);
        if ( ( $should_include_shipping_in_cod || $should_add_configured_shipping ) && $shipping_with_vat > 0 ) {
            $shipping_ex_vat = round($shipping_with_vat / 1.20, 2);

            $fiscal_items[] = [
                    'description'   => 'Доставка',
                    'vatGroup'      => 'Б',
                    'amount'        => $shipping_ex_vat,
                    'amountWithVat' => $shipping_with_vat,
            ];

            $sum_with_vat += $shipping_with_vat;
        }

        $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;

        // При НЕ‑COD: cod.amount = сбор на касовия бон (продукти + доставка)
        $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($sum_with_vat, 2);

        // При НЕ‑COD не трябва processingType=CASH
      
        if (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
            unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
        }
    }


    // Generate waybill
    $waybill = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'shipment/', $arr_delivery_data );

    $order->update_meta_data( 'shipping_speedy_waybill', json_encode( $waybill ) );

    WC()->session->__unset( 'shipping_speedy_shipping' );

    $order->save();

    return true;
}

add_action('woocommerce_order_status_changed', 'speedy_delete_waybill', 10, 3);
function speedy_delete_waybill($order, $old_status, $new_status){
    if( is_int($order) ) $order = wc_get_order( $order );

    if( !$order->has_shipping_method('speedy_shipping') ) return;
    
    if( $new_status === 'cancelled' ) {
        $data = json_decode($order->get_meta('shipping_speedy_waybill', TRUE), TRUE);

        if(is_array($data)) {
            if( array_key_exists('id', $data) ) {

                $arr_cancel_data = [
                    'userName' => speedy_username(),
                    'password' => speedy_password(),
                    'shipmentId' => $data['id'],
                    'comment' => 'Отказана поръчка',
                ];

                // Delete
                $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'shipment/cancel', $arr_cancel_data ); 
                
                // Update meta
                $order->update_meta_data( 'shipping_speedy_waybill_canceled', json_encode($data) );
                $order->delete_meta_data( 'shipping_speedy_waybill');

                $order->save();
            }
        }
        
        return true;
    }
}

add_action( 'woocommerce_api_shipping_woocommerce_speedy_shipping_print_waybill_cb', 'shipping_woocommerce_speedy_shipping_print_waybill_cb' );


function shipping_woocommerce_speedy_shipping_print_waybill_cb() {
    if( !current_user_can( 'manage_options' ) && !current_user_can( 'manage_woocommerce' ) ) return;
    if( isset($_GET['speedy_waybill']) && $_GET['speedy_waybill'] != '' ) {
        // Determine additionalWaybillSenderCopy value based on the setting
        $additional_copy_setting = speedy_get_setting('additionalcopy');
        $additionalWaybillSenderCopy = ($additional_copy_setting == 'YES') ? 'ON_SAME_PAGE' : 'NONE';

        // When an additional paper copy is enabled, request A4; otherwise use A6.
        $paperSize = ($additional_copy_setting === 'YES') ? 'A4' : 'A6';

        $arr_data = array(
            'userName' => speedy_username(),
            'password' => speedy_password(),
            'paperSize' => $paperSize, // A4, A6, A4_4xA6
            'parcels' => [
                [
                    'parcel' => [
                        'id' => $_GET['speedy_waybill'],
                    ],
                ],
            ],
            'additionalWaybillSenderCopy' => $additionalWaybillSenderCopy,
        );

        // Send request to Speedy API
        $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'print/', $arr_data, false);

        // Output the response as a PDF
        header("Content-type: application/octet-stream");
        header("Content-Type: application/pdf");
        echo $response;
    } 
    else if (isset($_GET['kurier']) && $_GET['kurier'] != '') {

        date_default_timezone_set('Europe/Sofia');

        $now = new DateTime();
        $cutoffHour = 16;

        if ((int)$now->format('H') < $cutoffHour) {
            $pickupDate = $now;
        } else {
            $pickupDate = (clone $now)->modify('+1 day');
        }

        $pickupDate->setTime(15, 15, 0);
        $pickupDateTime = $pickupDate->format('Y-m-d\TH:i:sO');

        $visitEndTime = speedy_get_setting('sender_time');

        $shipmentIdRaw = $_GET['kurier'] ?? '';
        $shipmentId = trim($shipmentIdRaw, "\"'\\ "); 

        $arr_data = array(
            'userName' => speedy_username(),
            'password' => speedy_password(),
            'explicitShipmentIdList' => [$shipmentId],
            'visitEndTime' => $visitEndTime,
            'autoAdjustPickupDate' => true,
        );

        $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'pickup/', $arr_data, false);

        // ✅ Ако е успешно, отбелязваме в базата
        if ($response && empty($response['error'])) {

            // Намираме поръчката по shipment ID
            $orders = wc_get_orders([
                'limit' => -1,
                'status' => array_keys(wc_get_order_statuses()),
                'meta_key' => 'shipping_speedy_waybill',
            ]);

            foreach ($orders as $order) {
                $waybill_json = $order->get_meta('shipping_speedy_waybill');
                if (!$waybill_json) continue;

                $waybill_data = json_decode($waybill_json, true);
                if (!empty($waybill_data['id']) && $waybill_data['id'] == $shipmentId) {
                    $order->update_meta_data('_speedy_courier_requested', 'yes');
                    $order->update_meta_data('_speedy_courier_requested_date', current_time('mysql'));
                    $order->save();
                    break; // намерена е, няма нужда да продължаваме
                }
            }

            echo esc_html__( 'Заявен куриер', 'speedy-shipping' );
            wp_redirect(admin_url('admin.php?page=speedy-orders&kurier=yes&id=' . urlencode($shipmentId)));
            exit;

        } else {
            echo esc_html__( '⚠️ Грешка при заявяване на куриер:', 'speedy-shipping' ) . ' ' . esc_html($response['error']['message'] ?? __( 'неизвестна грешка', 'speedy-shipping' ));
            exit;
        }
    }

    else if( isset($_GET['anulirane']) && $_GET['anulirane'] != '' ) {

       $shipmentIdRaw = $_GET['anulirane'];
        $shipmentId = trim($shipmentIdRaw, "\"'\\ "); 
            $shop_name = get_bloginfo('name');


        $arr_data = array(
            'userName' => speedy_username(),
            'password' => speedy_password(),
            'shipmentId' => $shipmentIdRaw,
            'comment' => 'Cancel order from ' . $shop_name,
        );

        $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'shipment/cancel/', $arr_data, false);

        echo esc_html__( 'Анулирана пратка', 'speedy-shipping' );

         wp_redirect( admin_url( 'admin.php?page=speedy-orders&anulirane=yes&id=' . intval( $_GET['anulirane'] ) ) );
            exit;
    }
    else if( isset($_GET['orderid']) && $_GET['orderid'] != '' ) {
            $order_id = isset($_GET['orderid']) ? absint($_GET['orderid']) : 0;
            if ($order_id <= 0) {
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order || !$order->has_shipping_method('speedy_shipping')) {
                return;
            }

            $complexIdFromGet   = isset($_GET['complexId']) ? absint($_GET['complexId']) : 0;
            $streetIdFromGet    = isset($_GET['streetId']) ? absint($_GET['streetId']) : 0;
            $complexNameFromGet = isset($_GET['complexName']) ? sanitize_text_field(wp_unslash($_GET['complexName'])) : '';
            $streetNameFromGet  = isset($_GET['streetName']) ? sanitize_text_field(wp_unslash($_GET['streetName'])) : '';

            $apartmentNo = isset($_GET['apartmentNo']) ? sanitize_text_field(wp_unslash($_GET['apartmentNo'])) : '';
            $streetNo    = isset($_GET['streetNo']) ? sanitize_text_field(wp_unslash($_GET['streetNo'])) : '';
            $entranceNo  = isset($_GET['entranceNo']) ? sanitize_text_field(wp_unslash($_GET['entranceNo'])) : '';
            $town        = isset($_GET['town']) ? sanitize_text_field(wp_unslash($_GET['town'])) : '';
            $blockNo     = isset($_GET['blockNo']) ? sanitize_text_field(wp_unslash($_GET['blockNo'])) : '';
            $floorNo     = isset($_GET['floorNo']) ? sanitize_text_field(wp_unslash($_GET['floorNo'])) : '';

            $contents = $_GET['contents'];
            $weight = $_GET['weight'];
            $opakovka = $_GET['opakovka'];
            $obekt = $_GET['obekt'];
            $platec = $_GET['platec'];
            $paketi = $_GET['paketi'];
            $nalojen = $_GET['nalojen'];
            $opcii = $_GET['opcii'];
            $obqvena = $_GET['obqvena'];
            $paper = $_GET['paper'];

            $speedy_country_id = (int) $order->get_meta('_shipping_shipping_country');
            $is_foreign_order  = ($speedy_country_id > 0 && $speedy_country_id !== 100);

            $requested_delivery_type = isset($_GET['doofisaddress']) ? (int) $_GET['doofisaddress'] : null;

            if ($is_foreign_order) {
                $drop = (int) $order->get_meta('_billing_abroadoffice_id');
                if ($drop <= 0) {
                    $drop = (int) $order->get_meta('_billing_office');
                }
            } else {
                $drop = (int) $order->get_meta('_billing_office');
            }

            if (isset($_GET['drop']) && $_GET['drop'] !== '') {
                $drop_from_get = (int) $_GET['drop'];
                 if ($drop_from_get > 0) {
                    $drop = $drop_from_get;
                }
            }
        $belejka = $_GET['belejka'];

        $widths = explode(",", $_GET['widths']);
        $lengths = explode(",", $_GET['lengths']);
        $heights = explode(",", $_GET['heights']);
        $weights = explode(",", $_GET['weights']);

        $billing_neighborhood = !empty($complexNameFromGet) ? $complexNameFromGet : $order->get_meta('_billing_neighborhood');
$billing_street       = !empty($streetNameFromGet)  ? $streetNameFromGet  : $order->get_meta('_billing_street');

        
            $waybill = json_decode($order->get_meta('shipping_speedy_waybill', true), true);
            //if($waybill != NULL || !WC()->session) return;

            $arr_delivery_data = WC()->session->get( 'shipping_speedy_shipping' );
            if (!is_array($arr_delivery_data)) {
                $arr_delivery_data = [];
            }

            if (!isset($arr_delivery_data['recipient']) || !is_array($arr_delivery_data['recipient'])) {
                $arr_delivery_data['recipient'] = [];
            }

            // Recipient destination must be rebuilt from order/admin inputs, not from stale checkout session data.
            unset($arr_delivery_data['recipient']['pickupOfficeId']);
            unset($arr_delivery_data['recipient']['address']);
            unset($arr_delivery_data['recipient']['addressLocation']);

            $arr_delivery_data['ref1'] = 'Поръчка №' . $order_id;

            $arr_delivery_data['clientSystemId'] = '24122711214';

            $order->update_meta_data( 'shipping_speedy_shipping', json_encode( $arr_delivery_data ) );

            

            $arr_delivery_data['sender']['phone1']['number'] = speedy_get_setting( 'sender_phone' );
            $arr_delivery_data['sender']['contactName'] = speedy_get_setting( 'sender_name' );
           
$admin_email = sanitize_email((string) get_option('admin_email'));
if ($admin_email === '') {
    $admin_email = sanitize_email((string) get_option('new_admin_email'));
}
$arr_delivery_data['sender']['email'] = $admin_email !== '' ? $admin_email : speedy_get_setting('sender_email');
            $arr_delivery_data['sender']['clientId'] = $obekt;
            

            $arr_delivery_data['recipient']['phone1']['number'] = $order->get_billing_phone();
            $arr_delivery_data['recipient']['clientName'] = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $arr_delivery_data['recipient']['email'] = $order->get_billing_email();

        // NEW: за чужбина (countryId != 100) cod.amount = само продуктите (без shipping/fees)
        $foreignCountryId = 0;
        if (isset($arr_delivery_data['recipient']['address']['countryId'])) {
            $foreignCountryId = (int) $arr_delivery_data['recipient']['address']['countryId'];
        } elseif (isset($arr_delivery_data['recipient']['addressLocation']['countryId'])) {
            $foreignCountryId = (int) $arr_delivery_data['recipient']['addressLocation']['countryId'];
        }

        if ($foreignCountryId > 0 && $foreignCountryId !== 100) {
            $products_only_with_vat = 0.0;

            foreach ($order->get_items('line_item') as $item) {
                $products_only_with_vat += (float) $item->get_total() + (float) $item->get_total_tax();
            }

            if (!isset($arr_delivery_data['service']['additionalServices'])) {
                $arr_delivery_data['service']['additionalServices'] = [];
            }
            if (!isset($arr_delivery_data['service']['additionalServices']['cod'])) {
                $arr_delivery_data['service']['additionalServices']['cod'] = [];
            }

            $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($products_only_with_vat, 2);
        }


        $company = $order->get_billing_company();
            $contactNameCompany = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

            if($company == ''){
                $arr_delivery_data['recipient']['privatePerson'] = true;
            }
            else{
                $arr_delivery_data['recipient']['privatePerson'] = false;
                $arr_delivery_data['recipient']['clientName'] = $company;
                $arr_delivery_data['recipient']['contactName'] = $contactNameCompany;
            }


            $billing_shipping_type  = $order->get_meta('_billing_shipping_type');
            if ($requested_delivery_type === 0) {
                $billing_shipping_type = 'office2';
            } elseif ($requested_delivery_type === 1) {
                $billing_shipping_type = 'office';
            } elseif ($requested_delivery_type === 2) {
                $billing_shipping_type = 'address';
            } else {
                $billing_shipping_type = trim((string) $billing_shipping_type);
                if ($billing_shipping_type === 'Офис') {
                    $billing_shipping_type = 'office';
                }

                if (!in_array($billing_shipping_type, ['office', 'office2', 'address'], true)) {
                    $billing_shipping_type = 'address';
                }

                if ($is_foreign_order && $drop <= 0 && $billing_shipping_type !== 'address') {
                    $billing_shipping_type = 'address';
                }
            }

            $billing_address_index  = $order->get_meta('_billing_address_index');
            $shipping_address_index = $order->get_meta('_shipping_address_index');

       
$billing_neighborhood = !empty($complexNameFromGet) ? $complexNameFromGet : $order->get_meta('_billing_neighborhood');
$billing_street       = !empty($streetNameFromGet)  ? $streetNameFromGet  : $order->get_meta('_billing_street');
        $billing_street_number= !empty($streetNo)     ? $streetNo     : $order->get_meta('_billing_street_number');
        $billing_block        = !empty($blockNo)      ? $blockNo      : $order->get_meta('_billing_block');
        $billing_entrance     = !empty($entranceNo)   ? $entranceNo   : $order->get_meta('_billing_entrance');
        $billing_floor        = !empty($floorNo)      ? $floorNo      : $order->get_meta('_billing_floor');
        $billing_apartment    = !empty($apartmentNo)  ? $apartmentNo  : $order->get_meta('_billing_apartment');
        $billing_city         = !empty($town)         ? $town         : $order->get_meta('_billing_city');


            $address2 = $order->get_billing_address_2();
            $shippingSiteId = null;

            if (preg_match_all('/\s(\d+)(?=\s|$)/', $shipping_address_index, $matches)) {
                $shippingSiteId = end($matches[1]);
            }

           // $town = $complexName = $complexId = $streetId = $streetNo = $blockNo = $entranceNo = $floorNo = $apartmentNo = "";

           /* if (preg_match('/гр\.\s*([^\s\d]+)/u', $billing_address_index, $matches)) {
                $town = trim($matches[1]);
            }
            if (preg_match('/ул\.?\s*([^\d]+)\s*(\d+)?/u', $billing_address_index, $matches)) {
                $streetId = trim($matches[1]);
                $streetNo = isset($matches[2]) ? $matches[2] : "";
            }
            if (preg_match('/ап\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $apartmentNo = $matches[1];
            }
            if (preg_match('/ет\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $floorNo = $matches[1];
            }
            if (preg_match('/(вх\.|вход)\s*([^\s]+)/u', $billing_address_index, $matches)) {
                $entranceNo = $matches[2];
            }
            if (preg_match('/бл\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $blockNo = $matches[1];
            }
            if (preg_match('/(кв\.?|ж\.?к\.?)\s*([^\d,]+)/u', $billing_address_index, $matches)) {
                $complexName = trim($matches[2]);
            }
            */




        if ($billing_shipping_type == 'office' || $billing_shipping_type == 'office2' || $billing_shipping_type == 'Офис') {
            $arr_delivery_data['recipient']['pickupOfficeId'] = $drop;
        } elseif ($billing_shipping_type == 'address') {

            $useAddressNote = empty($billing_street) && empty($billing_street_number) && empty($billing_neighborhood);

            $shipping_address_indexnew = $order->get_meta('_shipping_city');

            // Speedy countryId за поръчката (100=BG, 300=GR и т.н.)
            $speedy_country_id = (int) $order->get_meta('_shipping_shipping_country');
            if ($speedy_country_id <= 0) {
                $speedy_country_id = 100;
            }

            // FIX: При чужбина (countryId != 100) подаваме addressLine1 с целия адрес,
            // + подаваме siteId и siteName (градът) взети от поръчката.
            if ($speedy_country_id !== 100) {

                $siteId = (int) $shipping_address_indexnew;

                $idx = trim((string) $order->get_meta('_billing_address_index'));

                // postCode = числата веднага след "До адрес" (работи и при много интервали)
               
                $postCode = trim((string) $order->get_billing_postcode());

                if ($postCode === '') {
                    $postCode = trim((string) $order->get_meta('_billing_postcode'));
                }

                if ($postCode === '') {
                    $postCode = trim((string) $order->get_billing_address_2());
                }

                if ($postCode === '') {
                    $idx = trim((string) $order->get_meta('_billing_address_index'));
                    if ($idx !== '' && preg_match('/До\s*адрес\s*([A-Z0-9 -]{3,10})/ui', $idx, $m)) {
                        $postCode = trim((string) $m[1]);
                    }
                }

                $foreign_street = trim((string) $order->get_meta('_shipping_street'));
                $foreign_street2 = trim((string) $order->get_meta('_billing_street2'));

                if ($foreign_street === '') {
                    $foreign_street = trim((string) $order->get_billing_address_1());
                }
                if ($foreign_street === '') {
                    $foreign_street = $idx;
                }

           
                $site_name = trim((string) $order->get_billing_city());

                if ($site_name === '') {
                    $site_name = trim((string) $order->get_meta('_billing_city'));
                }

                if ($site_name === '') {
                    $idx_for_site = trim((string) $order->get_meta('_billing_address_index'));
                    if ($idx_for_site !== '' && preg_match('/\b([^\d,]+?)\s+[A-Z]\s+[A-Z]{2}\b/u', $idx_for_site, $m_site)) {
                        $site_name = trim($m_site[1]);
                    }
                }

               
if ($speedy_country_id === 642) {
    $address = [
        'countryId' => $speedy_country_id,
    ];

    if ($siteId > 0) {
        $address['siteId'] = $siteId;
    }

    if ($complexIdFromGet > 0) {
        $address['complexId'] = $complexIdFromGet;
    } elseif (!empty($billing_neighborhood)) {
        $address['complexName'] = $billing_neighborhood;
    }

    if ($streetIdFromGet > 0) {
        $address['streetId'] = $streetIdFromGet;
    }

    if (!empty($streetNameFromGet)) {
        $address['streetName'] = $streetNameFromGet;
    } elseif (!empty($billing_street)) {
        $address['streetName'] = $billing_street;
    }

    if (!empty($streetNo)) {
        $address['streetNo'] = $streetNo;
    }
    if (!empty($blockNo)) {
        $address['blockNo'] = $blockNo;
    }
    if (!empty($entranceNo)) {
        $address['entranceNo'] = $entranceNo;
    }
    if (!empty($floorNo)) {
        $address['floorNo'] = $floorNo;
    }
    if (!empty($apartmentNo)) {
        $address['apartmentNo'] = $apartmentNo;
    }

    // За Румъния при избран siteId изпращаме countryId + siteId, без postCode.
    if (!empty($postCode) && $siteId <= 0) {
        $address['postCode'] = $postCode;
    }

    $line2 = trim((string) $order->get_meta('_billing_street2'));
    if ($line2 !== '') {
        $address['addressNote'] = $line2;
    }

} else {
    $address = [
        'countryId'    => $speedy_country_id,
        'postCode'     => $postCode,
        'addressLine1' => $foreign_street,
    ];

    $has_address_line1 = trim((string) $foreign_street) !== '';
    $has_post_code = trim((string) $postCode) !== '';

    // За чужбина не подаваме siteId, когато имаме postCode + addressLine1.
    if ($siteId > 0 && !($has_post_code && $has_address_line1)) {
        $address['siteId'] = $siteId;
        if (isset($address['siteName'])) {
            unset($address['siteName']);
        }
    } elseif ($site_name !== '') {
        $address['siteName'] = $site_name;
    }

    $line2 = trim((string) $order->get_meta('_billing_street2'));
    if ($line2 !== '') {
        $address['addressLine2'] = $line2;
    }
}

           
                // US-only: взимаме stateId в реално време преди изпращане
           
          
            
            if ( in_array( $speedy_country_id, [840, 124], true ) ) {
                $state_code = strtoupper( trim( (string) $order->get_billing_state() ) );
                if ( $state_code !== '' ) {
                    $state_id = speedy_lookup_state_id( $state_code, $speedy_country_id );
                    if ( $state_id !== '' ) {
                        $address['stateId'] = $state_id;
                    }
                }
            }


            } else {

                // BG: старата логика със siteId и детайлни полета
                if ( speedy_get_setting('addressonefield') == "YES" ) {
                    $address = [
                            "countryId"   => $speedy_country_id,
                            "siteId"      => $shipping_address_indexnew,
                            "addressNote" => $order->get_billing_address_1(),
                    ];
                } else {
                    $address = [
                            "countryId"    => $speedy_country_id,
                            "siteId"       => $shipping_address_indexnew,
                            "streetName"   => $billing_street,
                            "streetNo"     => $billing_street_number,
                            "complexName"  => $billing_neighborhood,
                            "blockNo"      => $billing_block,
                            "entranceNo"   => $billing_entrance,
                            "floorNo"      => $billing_floor,
                            "apartmentNo"  => $billing_apartment,
                            "addressNote"  => $address2
                    ];
                }
            }

            $arr_delivery_data['recipient']['address'] = $address;

            // не пращаме addressLocation като празен стринг
            if (isset($arr_delivery_data['recipient']['addressLocation'])) {
                unset($arr_delivery_data['recipient']['addressLocation']);
            }
        }


        $speedy_country_id = (int) $order->get_meta('_shipping_shipping_country');
        if ($speedy_country_id <= 0) {
            $speedy_country_id = 100;
        }
     
        // Определяме държавата надеждно
        $recipient_country_id = 0;

        if (isset($arr_delivery_data['recipient']['address']['countryId'])) {
            $recipient_country_id = (int) $arr_delivery_data['recipient']['address']['countryId'];
        } elseif (isset($arr_delivery_data['recipient']['addressLocation']['countryId'])) {
            $recipient_country_id = (int) $arr_delivery_data['recipient']['addressLocation']['countryId'];
        }

        $billing_iso2 = strtoupper((string) $order->get_billing_country());

if ($billing_iso2 !== '') {
    $meta_country_id = (int) $order->get_meta('_shipping_shipping_country');
    $recipient_country_id = $meta_country_id > 0 ? $meta_country_id : (($billing_iso2 !== 'BG') ? 300 : 100);
} else {
    $shipping_index_value = (string) $order->get_meta('_billing_shipping_index');
    if ($shipping_index_value === '') {
        $shipping_index_value = (string) $order->get_meta('_billing_address_index');
    }
    if ($shipping_index_value === '') {
        $shipping_index_value = (string) $order->get_meta('_shipping_address_index');
    }

    $shipping_index_value = strtoupper(trim($shipping_index_value));

    if (preg_match('/(?:^|\s)BG(?:\s|$)/', $shipping_index_value)) {
        $recipient_country_id = 100;
    } elseif (preg_match('/(?:^|\s)[A-Z]{2}(?:\s|$)/', $shipping_index_value)) {
        $recipient_country_id = 300;
    } else {
        $recipient_country_id = 100;
    }
}

$serviceId = (int) $order->get_meta('_speedy_selected_service_id');
if ($serviceId <= 0) {
    $serviceId = 505;
}

// Ако е foreign и още сме на локалния default 505, оставяме го да се избере по-късно от destination-allowed логиката
if ($recipient_country_id !== 100 && $serviceId === 505) {
    $serviceId = 0;
}

        $arr_delivery_data['service'] = [
                'serviceId' => $serviceId,
                'additionalServices' => [],
                'autoAdjustPickupDate' => true
        ];

        if( speedy_get_setting('saturdayoption') == 'YES' ) {
                 $arr_delivery_data['service']['saturdayDelivery'] = true;
            }

            $processing_type = (speedy_get_setting('moneytransfer') == 'YES') ? 'POSTAL_MONEY_TRANSFER' : 'CASH';
            $processing_surcharge_with_vat = 0.0;
            if (speedy_get_setting('cenadostavka') === 'nadbavka') {
            $processing_surcharge_with_vat = max(0, (float) speedy_get_setting('suma_nadbavka'));
            }
            $ordersubtotal = wc_format_decimal($order->get_subtotal(), 2);
            $free_shipping_total = speedy_get_order_products_total_with_vat( $order );
            
            $arr_delivery_data['service']['additionalServices']['cod'] = [
                'amount' => $ordersubtotal,
                'processingType' => $processing_type,
            ];





           $free_shipping         = speedy_get_setting('free_shipping');
            $free_shipping_address = floatval(speedy_get_setting('free_shipping_address'));
            $free_shipping_office  = floatval(speedy_get_setting('free_shipping_office'));
            $free_shipping_automat = floatval(speedy_get_setting('free_shipping_automat'));

            $fixed_shipping         = speedy_get_setting('fixed_shipping');
            $fixed_shipping_address = floatval(speedy_get_setting('fixed_shipping_address'));
            $fixed_shipping_office  = floatval(speedy_get_setting('fixed_shipping_office'));
            $fixed_shipping_automat = floatval(speedy_get_setting('fixed_shipping_automat'));

             // Тегло
                    $weight = isset($_GET['weight']) ? (float) str_replace(',', '.', $_GET['weight']) : 0;

                    if ($weight <= 0 && isset($order) && is_object($order) && method_exists($order, 'get_items_weight')) {
                        $weight = (float) $order->get_items_weight();
                    }

                    if ($weight <= 0 && function_exists('WC') && WC()->cart) {
                        $weight = (float) WC()->cart->get_cart_contents_weight();
                    }

                    if ($weight <= 0) {
                        $weight = (float) speedy_get_setting('teglo');
                    }

            // --- FREE SHIPPING ---
            if ($free_shipping == 'yes') {
                $is_free = false;

                if ($billing_shipping_type === 'office' && $free_shipping_total >= $free_shipping_office) {
                    $is_free = true;
                }
                if ($billing_shipping_type === 'address' && $free_shipping_total >= $free_shipping_address) {
                    $is_free = true;
                }
                if ($billing_shipping_type === 'office2' && $free_shipping_total >= $free_shipping_automat) {
                    $is_free = true;
                }

                if ($is_free) {
                    $arr_delivery_data['payment'] = [
                        'courierServicePayer' => 'SENDER'
                    ];
                } else {
                    $arr_delivery_data['payment'] = [
                        'courierServicePayer' => 'RECIPIENT',
                        'declaredValuePayer'  => 'RECIPIENT'
                    ];
                }
            }
            // --- FIXED SHIPPING ---
            elseif ($fixed_shipping === 'yes') {

                $arr_delivery_data['payment'] = [
                    'courierServicePayer' => 'SENDER'
                ];

                // Добавяме COD amount = subtotal + фиксираната доставка според типа
                if ($billing_shipping_type === 'office') {
                    $cod_amount = $ordersubtotal + $fixed_shipping_office;
                } elseif ($billing_shipping_type === 'office2') {
                    $cod_amount = $ordersubtotal + $fixed_shipping_automat;
                } elseif ($billing_shipping_type === 'address') {
                    $cod_amount = $ordersubtotal + $fixed_shipping_address;
                } else {
                    $cod_amount = $ordersubtotal; // fallback
                }

                $arr_delivery_data['service']['additionalServices']['cod'] = [
                    'amount' => $cod_amount
                ];
            }
           elseif (speedy_get_setting('cenadostavka') === 'fileprices') {

    $file_path = get_option('speedy_fileceni_path');
    if ($file_path && file_exists($file_path)) {
        $take_from_office = 0;
        if ($billing_shipping_type === 'office') {
            $take_from_office = 1;
        } elseif ($billing_shipping_type === 'office2') {
            $take_from_office = 2;
        } elseif ($billing_shipping_type === 'address') {
            $take_from_office = 0;
        }

        $ordersubtotal = floatval($order->get_subtotal());
        $matched_price = false;
        $best_fit_order_total = null;

        if (($handle = fopen($file_path, "r")) !== false) {
            fgetcsv($handle);

            while (($data = fgetcsv($handle)) !== false) {
                list($service_id, $csv_take_from_office, $csv_weight, $csv_order_total, $csv_price) = $data;

                if (
                    speedy_normalize_fileprice_delivery_target($csv_take_from_office) === $take_from_office &&
                    $weight <= floatval($csv_weight) &&
                    $ordersubtotal <= floatval($csv_order_total)
                ) {
                    if ($best_fit_order_total === null || floatval($csv_order_total) < $best_fit_order_total) {
                        $best_fit_order_total = floatval($csv_order_total);
                        $matched_price = floatval($csv_price);
                    }
                }
            }
            fclose($handle);
        }

        if ($matched_price !== false) {
           // $cod_amount = $ordersubtotal + $matched_price;
           $cod_amount = (float) $ordersubtotal;
        } else {
            $cod_amount = $ordersubtotal + 1;
        }

        $arr_delivery_data['service']['additionalServices']['cod'] = [
            'amount' => $cod_amount
        ];

        $arr_delivery_data['payment'] = [
            'courierServicePayer' => 'SENDER'
        ];
    }
}
            elseif (speedy_get_setting( 'cenadostavka' ) == 'nadbavka' ) {


                $cod_amount = $ordersubtotal;


                $arr_delivery_data['service']['additionalServices']['cod'] = [
                        'amount' => $cod_amount
                ];
                $arr_delivery_data['payment'] = [
                        'courierServicePayer' => 'RECIPIENT',
                        'declaredValuePayer'  => 'RECIPIENT'
                ];
            }
            // --- DEFAULT ---
            else {
                $arr_delivery_data['payment'] = [
                        'courierServicePayer' => 'RECIPIENT',
                        'declaredValuePayer'  => 'RECIPIENT'
                ];
            }
                        $sender_officeyesno = speedy_get_setting( 'sender_officeyesno' );
                        $sender_office_id = (int) speedy_get_setting( 'sender_office' );

                        unset($arr_delivery_data['sender']['address']);
                        unset($arr_delivery_data['sender']['addressLocation']);
                        unset($arr_delivery_data['sender']['pickupOfficeId']);
                        unset($arr_delivery_data['sender']['dropoffOfficeId']);


        /**
         * === FISCAL / FISCALONE за createShipment (АДМИН) ===
         * Стъпки:
         *  - взимаме финалния COD amount;
         *  - генерираме фискални редове за продуктите;
         *  - добавяме ред "Доставка" само когато тя е изрично включена в НП.
         */
        $mode = speedy_get_setting('moneytransfer');

                        if ( $sender_officeyesno === 'YES' && $sender_office_id > 0 ) {
                            $arr_delivery_data['sender']['dropoffOfficeId'] = $sender_office_id;
                        }

        // Плащане на поръчката
        $order_payment_method = method_exists($order, 'get_payment_method')
                ? $order->get_payment_method()
                : '';

        $is_cash_on_delivery = ($order_payment_method === 'cod');

        // Нужен ни е финалният COD amount
        $cod_amount = isset($arr_delivery_data['service']['additionalServices']['cod']['amount'])
                ? (float) $arr_delivery_data['service']['additionalServices']['cod']['amount']
                : (float) $ordersubtotal;

        if ( in_array($mode, ['fiscal', 'fiscalone'], true) && $is_cash_on_delivery ) {

            $configured_shipping_amount = speedy_get_configured_shipping_amount_by_type( $billing_shipping_type, $order );

            $fiscal_items            = [];
            $products_total_with_vat = 0.0;

            // Помощна функция за група ДДС
            $get_vat_data = function( $tax_class ) {
                switch ( $tax_class ) {
                    case 'zero-rate':
                        return ['group' => 'А', 'rate' => 0.00];
                    case 'reduced-rate':
                        return ['group' => 'Г', 'rate' => 0.09];
                    default:
                        return ['group' => 'Б', 'rate' => 0.20];
                }
            };

            // 1) Продуктови редове
            if ( $mode === 'fiscal' ) {
                foreach ( $order->get_items() as $item ) {

                    $product = $item->get_product();
                    if ( ! $product ) continue;

                    $name = $item->get_name();
                    $qty  = $item->get_quantity();

                    $label_name  = $qty > 1 ? $name . ' x' . $qty : $name;
                    $description = mb_substr( $label_name, 0, 50 );

                    $vat = $get_vat_data( $product->get_tax_class() );

                    // Крайна цена с ДДС за 1 брой (от поръчката)
                    $price_incl_vat = (float) $order->get_item_subtotal( $item, true, false );
                    $price_excl_vat = $vat['rate'] > 0
                            ? $price_incl_vat / (1 + $vat['rate'])
                            : $price_incl_vat;

                    $line_amount_with_vat = round( $price_incl_vat * $qty, 2 );
                    $line_amount_ex_vat   = round( $price_excl_vat * $qty, 2 );

                    $products_total_with_vat += $line_amount_with_vat;

                    $fiscal_items[] = [
                            'description'   => $description,
                            'vatGroup'      => $vat['group'],
                            'amount'        => $line_amount_ex_vat,
                            'amountWithVat' => $line_amount_with_vat,
                    ];
                }
            }

            if ( $mode === 'fiscalone' ) {
                // Групирано по ДДС
                $groups = [
                        'А' => ['ex' => 0, 'in' => 0],
                        'Г' => ['ex' => 0, 'in' => 0],
                        'Б' => ['ex' => 0, 'in' => 0],
                ];

                foreach ( $order->get_items() as $item ) {

                    $product = $item->get_product();
                    if ( ! $product ) continue;

                    $qty = $item->get_quantity();

                    $vat = $get_vat_data( $product->get_tax_class() );

                    $price_incl_vat = (float) $order->get_item_subtotal( $item, true, false );
                    $price_excl_vat = $vat['rate'] > 0
                            ? $price_incl_vat / (1 + $vat['rate'])
                            : $price_incl_vat;

                    $groups[$vat['group']]['ex'] += $price_excl_vat * $qty;
                    $groups[$vat['group']]['in'] += $price_incl_vat * $qty;
                }

                foreach ( $groups as $group_code => $sum ) {
                    if ( $sum['in'] <= 0 ) continue;

                    $products_total_with_vat += $sum['in'];

                    $fiscal_items[] = [
                            'description'   => 'Продукти от поръчка №' . $order->get_id() . " (група $group_code)",
                            'vatGroup'      => $group_code,
                            'amount'        => round($sum['ex'], 2),
                            'amountWithVat' => round($sum['in'], 2),
                    ];
                }
            }

                $should_include_shipping_in_cod = ! empty( $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] );
                $cenadostavka_mode = speedy_get_setting('cenadostavka');
                $should_add_configured_shipping = $cenadostavka_mode === 'fixedprices' && $configured_shipping_amount > 0;

                // 2) Ред "Доставка" при изрично включване в НП или при фиксирана цена по тип
            $shipping_amount_with_vat = $should_add_configured_shipping
                ? $configured_shipping_amount
                : max( 0, $cod_amount - $products_total_with_vat );

            if (
                    ( $should_include_shipping_in_cod || $should_add_configured_shipping )
                    &&
                    $shipping_amount_with_vat > 0 &&
                    in_array($cenadostavka_mode, ['fixedprices', 'fileprices'], true)
            ) {
                // Приемаме 20% ДДС – група "Б"
                $shipping_vat_rate      = 0.20;
                $shipping_amount_ex_vat = $shipping_amount_with_vat / (1 + $shipping_vat_rate);

                $fiscal_items[] = [
                        'description'   => 'Доставка',
                        'vatGroup'      => 'Б',
                        'amount'        => round($shipping_amount_ex_vat, 2),
                        'amountWithVat' => round($shipping_amount_with_vat, 2),
                ];
            }

            // Запис към COD
            $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;
        }

        if (speedy_get_setting('administrative') == 'YES') {
            $arr_delivery_data['payment']['administrativeFee'] = true;
        }

        if (
            speedy_get_setting('includeshippingprice') == 'YES'
            && ! in_array( speedy_get_setting('cenadostavka'), ['fixedprices', 'fileprices'], true )
        ) {
            $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] = true;
            $arr_delivery_data['payment'] = [
                    'courierServicePayer' => 'SENDER'
            ];
        } elseif (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
            unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
        }

        if (speedy_get_setting('obqvena') == 'YES') {
                $order_total = $ordersubtotal;
                $arr_delivery_data['service']['additionalServices']['declaredValue']['amount'] = (double) $ordersubtotal;
               if (speedy_get_setting('chuplivost') == 'YES') {
                 $arr_delivery_data['service']['additionalServices']['declaredValue']['fragile'] = true;
                 $arr_delivery_data['service']['additionalServices']['declaredValue']['amount'] = (double) $ordersubtotal;
               }
               else{
                $arr_delivery_data['service']['additionalServices']['declaredValue']['fragile'] = false;
                $arr_delivery_data['service']['additionalServices']['declaredValue']['amount'] = (double) $ordersubtotal;
               }
            }

          if (
    speedy_get_setting('test_before_pay') == 'OPEN' 
    && ($billing_shipping_type != 'office2' || speedy_get_setting('autoclose') == 'NO')
) {
                  $arr_delivery_data['service']['additionalServices']['obpd'] = [
                   'option' => 'OPEN', 
                   'returnShipmentServiceId' => 505, 
                   'returnShipmentPayer' => speedy_get_setting('testplatec') ?: 'SENDER' // (SENDER, RECIPIENT, THIRD_PARTY). The sender of the returning shipment is the recipient of the primary shipment.
                ];
            }
              if (
    speedy_get_setting('test_before_pay') == 'TEST' 
    && ($billing_shipping_type != 'office2' || speedy_get_setting('autoclose') == 'NO')
) {
                  $arr_delivery_data['service']['additionalServices']['obpd'] = [
                   'option' => 'TEST', 
                   'returnShipmentServiceId' => 505, 
                    'returnShipmentPayer' => speedy_get_setting('testplatec') ?: 'SENDER' // (SENDER, RECIPIENT, THIRD_PARTY). The sender of the returning shipment is the recipient of the primary shipment.
                ];
            }

            if (speedy_get_setting('vaucher') == 'YES') {
                $arr_delivery_data['service']['additionalServices']['returns']['returnVoucher']['serviceId'] = 505;
                $arr_delivery_data['service']['additionalServices']['returns']['returnVoucher']['payer'] = speedy_get_setting('vaucherpayer');
                 $voucherPayerDays = speedy_get_setting('vaucherpayerdays');
                if (!empty($voucherPayerDays)) {
                    $arr_delivery_data['service']['additionalServices']['returns']['returnVoucher']['validityPeriod'] = $voucherPayerDays;
                }
            }

        if (speedy_get_setting('dopalnitelni')) {
            $arr_delivery_data['service']['additionalServices']['specialDeliveryId'] = speedy_get_setting('dopalnitelni');
        }

        



$arr_delivery_data['content']['contents'] = $contents;
$arr_delivery_data['content']['parcelsCount'] = 1;
$arr_delivery_data['content']['package'] = $opakovka;
$arr_delivery_data['content']['totalWeight'] = $weight;

$widths  = isset($_GET['widths']) ? explode(',', $_GET['widths']) : [];
$lengths = isset($_GET['lengths']) ? explode(',', $_GET['lengths']) : [];
$heights = isset($_GET['heights']) ? explode(',', $_GET['heights']) : [];
$weights = isset($_GET['weights']) ? explode(',', $_GET['weights']) : [];

$arr_delivery_data['content']['parcels'] = [];

// 1) Ръчни размери от admin екрана имат приоритет
if (!empty($widths) && !empty($lengths) && !empty($heights) && !empty($weights)) {
    for ($i = 0; $i < count($widths); $i++) {
        if (
            isset($widths[$i], $lengths[$i], $heights[$i], $weights[$i]) &&
            $widths[$i] !== '' &&
            $lengths[$i] !== '' &&
            $heights[$i] !== '' &&
            $weights[$i] !== ''
        ) {
            $arr_delivery_data['content']['parcels'][] = [
                'seqNo' => $i + 1,
                'size' => [
                    'width'  => (float) $widths[$i],
                    'height' => (float) $heights[$i],
                    'depth'  => (float) $lengths[$i],
                ],
                'weight' => (float) $weights[$i],
            ];
        }
    }
}

// 2) Ако няма ръчни размери -> fallback към най-големия продукт (за всички държави)
if (empty($arr_delivery_data['content']['parcels'])) {
    $parcel_width  = 0.0;
    $parcel_depth  = 0.0;
    $parcel_height = 0.0;
    $max_volume    = 0.0;

    $pick_largest_dimensions = static function ($product) use (&$parcel_width, &$parcel_depth, &$parcel_height, &$max_volume) {
        if (!$product || !is_object($product)) {
            return;
        }

        $w = (float) $product->get_width();
        $d = (float) $product->get_length();
        $h = (float) $product->get_height();

        if ($w <= 0 || $d <= 0 || $h <= 0) {
            return;
        }

        $volume = $w * $d * $h;
        if ($volume > $max_volume) {
            $max_volume    = $volume;
            $parcel_width  = $w;
            $parcel_depth  = $d;
            $parcel_height = $h;
        }
    };

    foreach ($order->get_items('line_item') as $item) {
        $product = $item->get_product();
        $pick_largest_dimensions($product);

        if (
            $product &&
            is_object($product) &&
            method_exists($product, 'is_type') &&
            $product->is_type('variation')
        ) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);
                $pick_largest_dimensions($parent);
            }
        }
    }

    if ($parcel_width > 0 && $parcel_depth > 0 && $parcel_height > 0) {
        $arr_delivery_data['content']['parcels'] = [
            [
                'seqNo' => 1,
                'size' => [
                    'width'  => $parcel_width,
                    'depth'  => $parcel_depth,
                    'height' => $parcel_height,
                ],
                'weight' => (float) $weight,
            ],
        ];
    }
}

if (empty($arr_delivery_data['content']['parcels'])) {
    unset($arr_delivery_data['content']['parcels']);
}




         $order->update_meta_data('shipping_speedy_waybit_raw_input', json_encode($arr_delivery_data));

        $order_payment_method = method_exists( $order, 'get_payment_method' )
                ? $order->get_payment_method()
                : '';

      
        if ( $order_payment_method === 'cod' ) {

            // Woo суми
            $order_total    = (float) $order->get_total();
            $shipping_total = (float) $order->get_shipping_total();
            $includes_shipping_in_cod = ( speedy_get_setting( 'includeshippingprice' ) === 'YES' );

            // ⚠️ НЕ преизчисляваме COD при fileprices/fixedprices - вече е установен горе
            $cenadostavka_mode = speedy_get_setting('cenadostavka');
            $should_recalc_cod = !in_array($cenadostavka_mode, ['fileprices', 'fixedprices'], true);

            if ( $should_recalc_cod ) {
                if ( $includes_shipping_in_cod ) {
                    $new_cod = $order_total;
                } else {
                    $new_cod = max( 0, $order_total - $shipping_total );
                }

                // Уверяваме се, че ключът cod съществува
                if ( ! isset( $arr_delivery_data['service']['additionalServices']['cod'] ) ) {
                    $arr_delivery_data['service']['additionalServices']['cod'] = [];
                }

                // Ако вече има стойност и/или фискални редове – скалираме ги пропорционално
                if ( isset( $arr_delivery_data['service']['additionalServices']['cod']['amount'] ) ) {
                    $old_cod = (float) $arr_delivery_data['service']['additionalServices']['cod']['amount'];

                    if ( $old_cod > 0
                            && isset( $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] )
                            && is_array( $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] )
                    ) {
                        $ratio = $new_cod / $old_cod;

                        $items        = $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'];
                        $scaled_total = 0.0;

                        foreach ( $items as $i => $it ) {
                            $old_with_vat = isset( $it['amountWithVat'] ) ? (float) $it['amountWithVat'] : 0.0;
                            $old_ex_vat   = isset( $it['amount'] )        ? (float) $it['amount']        : 0.0;

                            $new_with_vat = round( $old_with_vat * $ratio, 2 );
                            $new_ex_vat   = round( $old_ex_vat   * $ratio, 2 );

                            $items[$i]['amountWithVat'] = $new_with_vat;
                            $items[$i]['amount']        = $new_ex_vat;

                            $scaled_total += $new_with_vat;
                        }

                        // Корекция на последния ред заради закръгляния
                        $diff = round( $new_cod - $scaled_total, 2 );
                        if ( abs( $diff ) >= 0.01 && ! empty( $items ) ) {
                            $last = count( $items ) - 1;
                            $items[$last]['amountWithVat'] = round( $items[$last]['amountWithVat'] + $diff, 2 );

                            $vg = isset( $items[$last]['vatGroup'] ) ? $items[$last]['vatGroup'] : 'Б';
                            $vat_rate = ( $vg === 'А' ) ? 0.00 : ( ( $vg === 'Г' ) ? 0.09 : 0.20 );

                            $items[$last]['amount'] = $vat_rate > 0
                                    ? round( $items[$last]['amountWithVat'] / ( 1 + $vat_rate ), 2 )
                                    : $items[$last]['amountWithVat'];
                        }

                        $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] = $items;
                    }
                }

                // Накрая – реалният НП
                $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round( $new_cod, 2 );

                if ( $includes_shipping_in_cod ) {
                    $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] = true;
                }
            }
        }

        // Add Speedy credentials
        $arr_delivery_data['userName'] = speedy_username();
        $arr_delivery_data['password'] = speedy_password();

        // Ако плащането на поръчката НЕ е COD — същата логика
        $payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '';
        if ($payment_method !== 'cod') {

            // платец на куриерската услуга – изпращач
            $arr_delivery_data['payment']['courierServicePayer'] = 'SENDER';

            // ако все пак държите fiscalReceiptItems да се пращат и при банков превод,
            // НЕ трябва да зануляваме cod.amount и НЕ трябва да трием fiscalReceiptItems.

            if (!isset($arr_delivery_data['service']['additionalServices'])) {
                $arr_delivery_data['service']['additionalServices'] = [];
            }
            if (!isset($arr_delivery_data['service']['additionalServices']['cod'])) {
                $arr_delivery_data['service']['additionalServices']['cod'] = [];
            }

            $products_total = $order->get_subtotal();

            if (isset($arr_delivery_data['service']['additionalServices']['cod'])) {
                unset($arr_delivery_data['service']['additionalServices']['cod']);
            }
            // НЕ‑COD: махаме processingType, за да не се третира като наложен платеж
            if (isset($arr_delivery_data['service']['additionalServices']['cod']['processingType'])) {
                unset($arr_delivery_data['service']['additionalServices']['cod']['processingType']);
            }

             // Ако е чужбина (foreign) → махаме cod и obpd напълно
            $rc = 0;
            if (isset($arr_delivery_data['recipient']['address']['countryId'])) {
                $rc = (int) $arr_delivery_data['recipient']['address']['countryId'];
            } elseif (isset($arr_delivery_data['recipient']['addressLocation']['countryId'])) {
                $rc = (int) $arr_delivery_data['recipient']['addressLocation']['countryId'];
            }
            if ($rc <= 0) {
                $rc = (int) $order->get_meta('_shipping_shipping_country');
            }
            if ($rc <= 0) {
                $billing_iso2_check = strtoupper((string) $order->get_billing_country());
                if ($billing_iso2_check === '') {
                    $idx_check = strtoupper(trim((string) $order->get_meta('_billing_shipping_index')));
                    if ($idx_check === '') {
                        $idx_check = strtoupper(trim((string) $order->get_meta('_billing_address_index')));
                    }
                    $billing_iso2_check = preg_match('/(?:^|\s)([A-Z]{2})(?:\s|$)/', $idx_check, $m2) ? $m2[1] : '';
                }
                $rc = ($billing_iso2_check !== '' && $billing_iso2_check !== 'BG') ? 300 : 100;
            }
            if ($rc !== 100) {
                unset($arr_delivery_data['service']['additionalServices']['cod']);
            }
        }



        // FIX: Синхронизираме cod.amount с fiscalReceiptItems (ако има), иначе смятаме без доставка.
        $includes_shipping_in_cod = (speedy_get_setting('includeshippingprice') === 'YES');
        $configured_shipping_amount = speedy_get_configured_shipping_amount_by_type( $billing_shipping_type, $order );

        if (!isset($arr_delivery_data['service']['additionalServices'])) {
            $arr_delivery_data['service']['additionalServices'] = [];
        }
        if (!isset($arr_delivery_data['service']['additionalServices']['cod'])) {
            $arr_delivery_data['service']['additionalServices']['cod'] = [];
        }

        if ($payment_method === 'cod') {

            $has_fiscal_items =
                    isset($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
                    && is_array($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
                    && !empty($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems']);

            if ($has_fiscal_items && !$includes_shipping_in_cod) {
                $sum_with_vat = 0.0;
                foreach ($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] as $it) {
                    if (is_array($it) && isset($it['amountWithVat'])) {
                        $sum_with_vat += (float) $it['amountWithVat'];
                    }
                }
                $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($sum_with_vat, 2);

                if (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
                    unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
                }

            } else {
                 $order_total    = (float) $order->get_total();
                    $shipping_total = (float) $order->get_shipping_total();

                    $cenadostavka_mode_s2 = speedy_get_setting('cenadostavka');
                    $shipping_in_price = in_array($cenadostavka_mode_s2, ['fixedprices', 'fileprices'], true);

                   $products_only = max(0, $order_total - $shipping_total);

$arr_delivery_data['service']['additionalServices']['cod']['amount'] = round(
    $products_only,
    2
);

if ($shipping_in_price) {
    $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round(
        $products_only + $configured_shipping_amount,
        2
    );

    if (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
        unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
    }
} elseif ($includes_shipping_in_cod) {
    $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] = true;
} else {
    if (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
        unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
    }
}
            }

            // processingType да не липсва
            if (empty($arr_delivery_data['service']['additionalServices']['cod']['processingType'])) {
$arr_delivery_data['service']['additionalServices']['cod']['processingType'] =
    (speedy_get_setting('moneytransfer') === 'YES') ? 'POSTAL_MONEY_TRANSFER' : 'CASH';
            }
        }

        /**
         * FIX: fiscalReceiptItems в ORDER контекст.
         * Ред "Доставка" се генерира само когато е включен в НП.
         */
        $mode = speedy_get_setting('moneytransfer');
        if ($payment_method == 'cod' && ($mode === 'fiscal' || $mode === 'fiscalone')) {

            if (!isset($arr_delivery_data['service']['additionalServices'])) {
                $arr_delivery_data['service']['additionalServices'] = [];
            }
            if (!isset($arr_delivery_data['service']['additionalServices']['cod'])) {
                $arr_delivery_data['service']['additionalServices']['cod'] = [];
            }

            $fiscal_items = [];
            $sum_with_vat = 0.0;

            if ($mode === 'fiscal') {
                // 1) По артикули
                foreach ($order->get_items('line_item') as $item) {
                    $product = $item->get_product();
                    if (!$product) {
                        continue;
                    }

                    $qty  = (int) $item->get_quantity();
                    $name = (string) $item->get_name();

                    $tax_class = (string) $product->get_tax_class();
                    $vat_group = 'Б';
                    $vat_rate  = 0.20;

                    if ($tax_class === 'zero-rate') {
                        $vat_group = 'А';
                        $vat_rate  = 0.00;
                    } elseif ($tax_class === 'reduced-rate') {
                        $vat_group = 'Г';
                        $vat_rate  = 0.09;
                    }

                    $line_with_vat = round((float) $item->get_total() + (float) $item->get_total_tax(), 2);
                    $line_ex_vat   = ($vat_rate > 0) ? round($line_with_vat / (1 + $vat_rate), 2) : $line_with_vat;

                    $fiscal_items[] = [
                            'description'   => mb_substr($name . ' (x' . $qty . ')', 0, 50),
                            'vatGroup'      => $vat_group,
                            'amount'        => $line_ex_vat,
                            'amountWithVat' => $line_with_vat,
                    ];

                    $sum_with_vat += $line_with_vat;
                }
            } else {
                // 2) fiscalone -> групиране по ДДС групи
                $groups = [
                        'А' => ['in' => 0.0],
                        'Г' => ['in' => 0.0],
                        'Б' => ['in' => 0.0],
                ];

                foreach ($order->get_items('line_item') as $item) {
                    $product = $item->get_product();
                    if (!$product) {
                        continue;
                    }

                    $tax_class = (string) $product->get_tax_class();
                    $vat_group = 'Б';
                    $vat_rate  = 0.20;

                    if ($tax_class === 'zero-rate') {
                        $vat_group = 'А';
                        $vat_rate  = 0.00;
                    } elseif ($tax_class === 'reduced-rate') {
                        $vat_group = 'Г';
                        $vat_rate  = 0.09;
                    }

                    $line_with_vat = round((float) $item->get_total() + (float) $item->get_total_tax(), 2);
                    $groups[$vat_group]['in'] += $line_with_vat;
                }

                foreach ($groups as $group => $sum) {
                    if ($sum['in'] <= 0) {
                        continue;
                    }

                    $vat_rate = ($group === 'А') ? 0.00 : (($group === 'Г') ? 0.09 : 0.20);
                    $ex = ($vat_rate > 0) ? round($sum['in'] / (1 + $vat_rate), 2) : round($sum['in'], 2);

                    $fiscal_items[] = [
                            'description'   => 'Продукти (група ' . $group . ')',
                            'vatGroup'      => $group,
                            'amount'        => $ex,
                            'amountWithVat' => round($sum['in'], 2),
                    ];

                    $sum_with_vat += (float) $sum['in'];
                }
            }

            $recipient_country_id = 0;
            if (isset($arr_delivery_data['recipient']['address']['countryId'])) {
                $recipient_country_id = (int) $arr_delivery_data['recipient']['address']['countryId'];
            } elseif (isset($arr_delivery_data['recipient']['addressLocation']['countryId'])) {
                $recipient_country_id = (int) $arr_delivery_data['recipient']['addressLocation']['countryId'];
            }

            if ($recipient_country_id <= 0) {
                $meta_country_id = (int) $order->get_meta('_shipping_shipping_country');
                if ($meta_country_id > 0) {
                    $recipient_country_id = $meta_country_id;
                } else {
                    $billing_iso2 = strtoupper((string) $order->get_billing_country());
                    $recipient_country_id = ($billing_iso2 !== '' && $billing_iso2 !== 'BG') ? 300 : 100;
                }
            }

              $should_include_shipping_in_cod = ! empty( $arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'] );
              $should_add_configured_shipping = speedy_get_setting('cenadostavka') === 'fixedprices' && $configured_shipping_amount > 0;
              $shipping_with_vat = $should_add_configured_shipping
                  ? round( $configured_shipping_amount, 2 )
                  : round((float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2);
            
             if (( $should_include_shipping_in_cod || $should_add_configured_shipping ) && $shipping_with_vat > 0 && $processing_surcharge_with_vat <= 0 && $recipient_country_id === 100) {
                $shipping_ex_vat = round($shipping_with_vat / 1.20, 2);

                $fiscal_items[] = [
                        'description'   => 'Доставка',
                        'vatGroup'      => 'Б',
                        'amount'        => $shipping_ex_vat,
                        'amountWithVat' => $shipping_with_vat,
                ];

                $sum_with_vat += $shipping_with_vat;
            }

            $arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;
            $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($sum_with_vat, 2);

            // НЕ-COD: без processingType
            if (isset($arr_delivery_data['service']['additionalServices']['cod']['processingType'])) {
                //unset($arr_delivery_data['service']['additionalServices']['cod']['processingType']);
            }
        }

                        

        $recipient_country_id = 0; // reset – предишна стойност може да е 100 от ?? 100
        if (isset($arr_delivery_data['recipient']['address']['countryId'])) {
            $recipient_country_id = (int) $arr_delivery_data['recipient']['address']['countryId'];
        } elseif (isset($arr_delivery_data['recipient']['addressLocation']['countryId'])) {
            $recipient_country_id = (int) $arr_delivery_data['recipient']['addressLocation']['countryId'];
        }

        // fallback за foreign до офис (няма address/addressLocation)
        if ($recipient_country_id <= 0) {
            $meta_country_id = (int) $order->get_meta('_shipping_shipping_country');
            if ($meta_country_id > 0) {
                $recipient_country_id = $meta_country_id;
            } else {
                $billing_iso2 = strtoupper((string) $order->get_billing_country());
                $meta_country_id = (int) $order->get_meta('_shipping_shipping_country');
                $recipient_country_id = $meta_country_id > 0 ? $meta_country_id : (($billing_iso2 !== '' && $billing_iso2 !== 'BG') ? 300 : 100);
            }
        }

       
// Финална корекция за foreign пратки
// Финална корекция: foreign ограничения + destination services за всички дестинации
if ($recipient_country_id !== 100) {
    $arr_delivery_data['payment']['courierServicePayer'] = 'SENDER';

    if (isset($arr_delivery_data['payment']['declaredValuePayer'])) {
        unset($arr_delivery_data['payment']['declaredValuePayer']);
    }
}

$preferred_service_ids = array_values(array_filter(array_map(
    'intval',
    explode(',', (string) speedy_get_setting('uslugitext'))
)));

if (empty($preferred_service_ids)) {
    $preferred_service_ids = [202, 302, 306];
}

$destination_recipient = $arr_delivery_data['recipient'];


if (!empty($destination_recipient['pickupOfficeId'])) {
    // До офис: подаваме само pickupOfficeId
    unset($destination_recipient['address']);
    unset($destination_recipient['addressLocation']);
} elseif (!empty($destination_recipient['address']) && is_array($destination_recipient['address'])) {
    // До адрес (BG + foreign): destination services иска addressLocation
    $addr = $destination_recipient['address'];

    $address_location = [
        'countryId' => (int)($addr['countryId'] ?? $recipient_country_id),
    ];

    if (!empty($addr['siteId'])) {
        $address_location['siteId'] = (int)$addr['siteId'];
    }

    // Само за foreign пазим postCode в destination lookup
    if ($recipient_country_id !== 100 && !empty($addr['postCode'])) {
        $address_location['postCode'] = (string)$addr['postCode'];
    }

    $destination_recipient['addressLocation'] = $address_location;

    // Важно: за destination services НЕ пращаме address при до адрес
    unset($destination_recipient['address']);
    unset($destination_recipient['pickupOfficeId']);
}

$destination_request = [
    'userName'  => speedy_username(),
    'password'  => speedy_password(),
    'date'      => date('Y-m-d'),
    'recipient' => $destination_recipient,
];

$destination_response = WS_Speedy_Request::call(
    SPEEDY_API_BASE_URL . 'services/destination',
    $destination_request
);

$allowed_service_ids = [];
$destination_service_policies = speedy_destination_service_policies( $destination_response );

if (
    is_array($destination_response)
    && !empty($destination_response['services'])
    && is_array($destination_response['services'])
) {
    foreach ($destination_response['services'] as $service) {
        $destination_service_id = is_array( $service ) ? speedy_destination_service_id( $service ) : 0;
        if ( $destination_service_id > 0 ) {
            $allowed_service_ids[] = $destination_service_id;
        }
    }
}


$allowed_service_ids = array_values(array_unique(array_filter($allowed_service_ids)));

$selected_service_id = 0;

// Canada: винаги ползваме 302 при създаване на товарителница
if ($recipient_country_id === 124) {
    $selected_service_id = 302;
}

// 0) Ако текущият serviceId вече е позволен - оставяме него
$current_service_id = (int)($arr_delivery_data['service']['serviceId'] ?? 0);
if ($selected_service_id <= 0 && $current_service_id > 0 && in_array($current_service_id, $allowed_service_ids, true)) {
    $selected_service_id = $current_service_id;
}

// 1) Записаният избор от checkout, но само ако е позволен
if ($selected_service_id <= 0) {
    $saved_service_id = (int) $order->get_meta('_speedy_selected_service_id');
    if ($saved_service_id > 0 && in_array($saved_service_id, $allowed_service_ids, true)) {
        $selected_service_id = $saved_service_id;
    }
}

// 2) Fallback към preferred services
if ($selected_service_id <= 0) {
    foreach ($preferred_service_ids as $preferred_service_id) {
        if (in_array($preferred_service_id, $allowed_service_ids, true)) {
            $selected_service_id = $preferred_service_id;
            break;
        }
    }
}

// 3) Последен fallback: първата позволена услуга
if ($selected_service_id <= 0 && !empty($allowed_service_ids)) {
    $selected_service_id = (int) $allowed_service_ids[0];
}

if ($selected_service_id <= 0) {
    $error_message = rawurlencode('Няма позволена услуга за избраната дестинация.');

    wp_redirect(admin_url(
        'admin.php?page=wc-orders&action=edit&id=' . intval($order_id) .
        '&tov=1&error=1&errmsg=' . $error_message
    ));
    exit;
}

if (!isset($arr_delivery_data['service']) || !is_array($arr_delivery_data['service'])) {
    $arr_delivery_data['service'] = [];
}
$arr_delivery_data['service']['serviceId'] = $selected_service_id;

$selected_service_policy = $destination_service_policies[ $selected_service_id ] ?? [
    'cod'  => [ 'allowed' => false, 'forbidden' => false ],
    'obpd' => [ 'allowed' => false, 'forbidden' => false ],
];

if ($order_payment_method === 'cod') {
    $moneytransfer_mode = (string) speedy_get_setting('moneytransfer');

    if (!empty($selected_service_policy['cod']['forbidden'])) {
        $error_message = rawurlencode('Наложеният платеж не е позволен за избраната услуга.');

        wp_redirect(admin_url(
            'admin.php?page=wc-orders&action=edit&id=' . intval($order_id) .
            '&tov=1&error=1&errmsg=' . $error_message
        ));
        exit;
    }

    if (
        isset($arr_delivery_data['service']['additionalServices']['cod'])
        && is_array($arr_delivery_data['service']['additionalServices']['cod'])
    ) {
        $arr_delivery_data['service']['additionalServices']['cod']['processingType'] =
            ($moneytransfer_mode === 'YES' && $recipient_country_id === 100 && !empty($selected_service_policy['cod']['allowed']))
                ? 'POSTAL_MONEY_TRANSFER'
                : 'CASH';
    }
}

if (
    $order_payment_method === 'cod'
    && $recipient_country_id !== 100
    && isset($arr_delivery_data['service']['additionalServices']['cod'])
    && is_array($arr_delivery_data['service']['additionalServices']['cod'])
) {
    $foreign_products_only_with_vat = 0.0;

    foreach ($order->get_items('line_item') as $item) {
        $foreign_products_only_with_vat += (float) $item->get_total() + (float) $item->get_total_tax();
    }

    $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($foreign_products_only_with_vat, 2);

    if (isset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
        unset($arr_delivery_data['service']['additionalServices']['cod']['includeShippingPrice']);
    }
}

if (isset($arr_delivery_data['service']['additionalServices']['obpd'])) {
    $obpd_allowed = !empty($selected_service_policy['obpd']['allowed']) && empty($selected_service_policy['obpd']['forbidden']);

    if (!$obpd_allowed) {
        unset($arr_delivery_data['service']['additionalServices']['obpd']);
    } elseif ($recipient_country_id !== 100) {
        $arr_delivery_data['service']['additionalServices']['obpd']['returnShipmentServiceId'] = $selected_service_id;
    }

    if (isset($arr_delivery_data['service']['additionalServices']) && empty($arr_delivery_data['service']['additionalServices'])) {
        unset($arr_delivery_data['service']['additionalServices']);
    }
}



        // Generate waybill
       // Generate waybill + live debug log
       
$payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '';

if ($payment_method !== 'cod') {
    if (isset($arr_delivery_data['service']['additionalServices']['cod'])) {
        unset($arr_delivery_data['service']['additionalServices']['cod']);
    }

    if (isset($arr_delivery_data['payment']['declaredValuePayer'])) {
        unset($arr_delivery_data['payment']['declaredValuePayer']);
    }

    $arr_delivery_data['payment']['courierServicePayer'] = 'SENDER';

    if (isset($arr_delivery_data['service']['additionalServices']) && empty($arr_delivery_data['service']['additionalServices'])) {
        unset($arr_delivery_data['service']['additionalServices']);
    }
}

$service_id = isset($arr_delivery_data['service']['serviceId'])
    ? (int) $arr_delivery_data['service']['serviceId']
    : 0;


$is_foreign_shipment = ($recipient_country_id !== 100);


$payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '';
$is_foreign_shipment = ($recipient_country_id !== 100);

if (
    $payment_method === 'cod' &&
    $is_foreign_shipment &&
    isset($arr_delivery_data['service']['additionalServices']['cod']['amount'])
) {
    $billing_country_iso2 = strtoupper(trim((string) $order->get_billing_country()));
    $target_currency = '';

    if ($billing_country_iso2 !== '') {
        $country_info = WS_Speedy_Request::call(
            SPEEDY_API_BASE_URL . 'location/country',
            [
                'userName' => speedy_username(),
                'password' => speedy_password(),
                'name'     => $billing_country_iso2,
            ]
        );

        if (
            is_array($country_info) &&
            !empty($country_info['countries']) &&
            is_array($country_info['countries'])
        ) {
            foreach ($country_info['countries'] as $country_item) {
                $iso = strtoupper(trim((string) ($country_item['isoAlpha2'] ?? '')));
                $currency_code = strtoupper(trim((string) ($country_item['currencyCode'] ?? '')));

                if ($iso === $billing_country_iso2 && $currency_code !== '') {
                    $target_currency = $currency_code;
                    break;
                }
            }
        }
    }

    $has_rate = false;
    $rates = speedy_get_setting('currency_rate', []);
    if (is_array($rates) && $target_currency !== '') {
        foreach ($rates as $rate_row) {
            $iso = strtoupper(trim((string) ($rate_row['iso_code'] ?? '')));
            $rate = (float) ($rate_row['rate'] ?? 0);

            if ($iso === $target_currency && $rate > 0) {
                $has_rate = true;
                break;
            }
        }
    }

    if ($target_currency !== '' && $has_rate) {
        $from_currency = strtoupper(trim((string) $order->get_currency()));
        $current_amount = (float) $arr_delivery_data['service']['additionalServices']['cod']['amount'];

        $from_per_eur = 0.0;
        $to_per_eur = 0.0;

        if ($from_currency === 'EUR') {
            $from_per_eur = 1.0;
        } elseif ($from_currency === 'BGN') {
            $from_per_eur = 1.95583;
        } else {
            foreach ((array) speedy_get_setting('currency_rate', []) as $rate_row) {
                $iso = strtoupper(trim((string) ($rate_row['iso_code'] ?? '')));
                $rate = (float) ($rate_row['rate'] ?? 0);
                if ($iso === $from_currency && $rate > 0) {
                    $from_per_eur = $rate;
                    break;
                }
            }
        }

        if ($target_currency === 'EUR') {
            $to_per_eur = 1.0;
        } elseif ($target_currency === 'BGN') {
            $to_per_eur = 1.95583;
        } else {
            foreach ((array) speedy_get_setting('currency_rate', []) as $rate_row) {
                $iso = strtoupper(trim((string) ($rate_row['iso_code'] ?? '')));
                $rate = (float) ($rate_row['rate'] ?? 0);
                if ($iso === $target_currency && $rate > 0) {
                    $to_per_eur = $rate;
                    break;
                }
            }
        }

        if ($from_per_eur > 0 && $to_per_eur > 0) {
            $amount_in_eur = $current_amount / $from_per_eur;
            $converted_amount = $amount_in_eur * $to_per_eur;

            $arr_delivery_data['service']['additionalServices']['cod']['amount'] = round($converted_amount, 2);
        }
    }
}

// За всички международни пратки махаме fiscalReceiptItems/COD extras,
// не само за service 202.
if ($is_foreign_shipment) {
    if (isset($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems'])) {
        unset($arr_delivery_data['service']['additionalServices']['cod']['fiscalReceiptItems']);
    }

    if (isset($arr_delivery_data['payment']['declaredValuePayer'])) {
        unset($arr_delivery_data['payment']['declaredValuePayer']);
    }

    if (isset($arr_delivery_data['service']['additionalServices']['cod']) && empty($arr_delivery_data['service']['additionalServices']['cod'])) {
        unset($arr_delivery_data['service']['additionalServices']['cod']);
    }

    if (isset($arr_delivery_data['service']['additionalServices']) && empty($arr_delivery_data['service']['additionalServices'])) {
        unset($arr_delivery_data['service']['additionalServices']);
    }

    $arr_delivery_data['payment']['courierServicePayer'] = 'SENDER';
}

$log_payload = $arr_delivery_data;
if (isset($log_payload['password'])) {
    $log_payload['password'] = '***';
}
if (isset($log_payload['userName']) && is_string($log_payload['userName']) && strlen($log_payload['userName']) > 2) {
    $log_payload['userName'] = substr($log_payload['userName'], 0, 2) . str_repeat('*', max(strlen($log_payload['userName']) - 2, 0));
}

error_log(
    '[Speedy][shipment][request] order_id=' . intval($order_id) . ' payload=' .
    wp_json_encode($log_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

 $waybill = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'shipment/', $arr_delivery_data);

 $response_for_log = is_string($waybill)
     ? $waybill
     : wp_json_encode($waybill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

 error_log('[Speedy][shipment][response] order_id=' . intval($order_id) . ' response=' . $response_for_log);
        // Save API response
        $order->update_meta_data('shipping_speedy_waybill', json_encode($waybill));
        $order->save();

        // Check for error in response new error log now
        // Strict success criteria: success only when response has a valid waybill id.
$speedy_error_message = '';
if (!is_array($waybill)) {
    $speedy_error_message = 'Невалиден отговор от Speedy API (не е JSON обект).';
} elseif (!empty($waybill['error']['message'])) {
    $speedy_error_message = (string) $waybill['error']['message'];
} elseif (empty($waybill['id'])) {
    $speedy_error_message = 'Липсва ID на товарителница в отговора от Speedy API.';
}

if ($speedy_error_message !== '') {
    error_log('[Speedy][shipment][error] order_id=' . intval($order_id) . ' message=' . $speedy_error_message);

    $error_message = $speedy_error_message;
    $error_message_url = rawurlencode($error_message);

    $order->update_meta_data('shipping_speedy_waybill', '');
    $order->save();

    wp_redirect(admin_url(
        'admin.php?page=wc-orders&action=edit&id=' . intval($order_id) .
        '&tov=1&error=1&errmsg=' . $error_message_url
    ));
    exit;
}

        // Clear session and redirect on success
        WC()->session->__unset('shipping_speedy_shipping');

        wp_redirect(admin_url(
            'admin.php?page=wc-orders&action=edit&id=' . intval($order_id) .
            '&tov=1&success=1'
        ));
        exit;




    }
    else {
        echo 'Wrong location?';    
    }
    die();
}


if( ! function_exists( 'edit_table_fields_thank_you_page' ) ) {
    function edit_table_fields_thank_you_page( $rows, $order ) {
        $shipping_type = $order->get_meta( 'shipping_type', true );

        if( $shipping_type ) {
            $new_rows = [];
            foreach( $rows as $key => $rows ) {
                $new_rows[$key] = $rows;
                if( $key === 'shipping' ) {
                    $new_rows['shipping_type'] = [
                        'label' => 'Доставка до',
                        'value' => $shipping_type
                    ];
                }
            }

            return $new_rows;
        }

        return $rows;
    }
}


if( ! function_exists( 'add_city_id_field' ) ) {
    function add_city_id_field() {
        $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
        $chosen_shipping = $chosen_methods[0];

        $selected_service_id = 0;
        if ( function_exists('WC') && WC()->session ) {
            $selected_service_id = (int) WC()->session->get('shipping_speedy_selected_service_id');
        }
        ?>
            <input type="hidden" name="city_id" id="city_id">
            <input type="hidden" name="chosen_method" id="chosen_method" value="<?= $chosen_shipping ?>">
            <input type="hidden" name="speedy_selected_service_id" id="speedy_selected_service_id" value="<?= esc_attr($selected_service_id) ?>">
        <?php
    }
}

if( ! function_exists( 'default_shipping_type_value' ) ) {
    function default_shipping_type_value( $null, $key ) {
        if( $key === 'billing_shipping_type' )
            return 'address';
        return null;
    }
}

if( ! function_exists( 'speedy_normalize_fileprice_delivery_target' ) ) {
    function speedy_normalize_fileprice_delivery_target( $value ) {
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
}

if( ! function_exists( 'admin_order_meta_fields' ) ) {
    add_filter( 'woocommerce_admin_billing_fields', 'admin_order_meta_fields', 10 );
    function admin_order_meta_fields( $fields ) {
        $order = wc_get_order( get_the_ID(  ) );
        $shipping_type = $order->get_meta( 'shipping_type', true );
        if( $shipping_type ) {
            $fields['shipping_type'] = [
                'label' => 'Доставка до',
                'value' => $shipping_type
            ]; 
        }

        return $fields;
    }
}

if( ! function_exists( 'speedy_fast_order_form_toggle_display' ) ) {
    add_action( 'woocommerce_after_add_to_cart_button', 'speedy_fast_order_form_toggle_display', 31 );
    
    function speedy_fast_order_form_toggle_display() {
        if(speedy_get_setting('fast_checkout') == 'no') return;

        $product  = wc_get_product( get_the_ID() );
        $status   = $product->get_stock_status();
        
        if( $status !== 'instock')
            return;
            
        require SPEEDY_PATH . '/templates/fast-order-toggle.php';
    }
}

if( ! function_exists( 'speedy_fast_order_form_display' ) ) {
    add_action( 'woocommerce_after_add_to_cart_form', 'speedy_fast_order_form_display', 31 );
    
    function speedy_fast_order_form_display() {
        if(speedy_get_setting('fast_checkout') == 'no') return;

        $product  = wc_get_product( get_the_ID() );
        $status   = $product->get_stock_status();
        
        if( $status !== 'instock' || $product->get_type() == 'pw-gift-card' )
            return;
            
        require SPEEDY_PATH . '/templates/fast-order.php';
    }
}

add_filter( 'custom_shipping_methods', function( $methods ) {
    $methods['speedy_shipping'] = speedy_get_setting( 'title' );

    return $methods;
});

if( ! function_exists( 'remove_shipping_session' ) ) {
    add_action( 'woocommerce_checkout_update_order_review', 'remove_shipping_session' );
    function remove_shipping_session() {
        $packages = WC()->cart->get_shipping_packages();
        foreach ($packages as $package_key => $package ) {
            WC()->session->set( 'shipping_for_package_' . $package_key, false ); // Or true
        }
    }
}

// fix Woo translations
if (!function_exists('ws_fix_woo_translations')) {
    add_filter( 'gettext', 'ws_fix_woo_translations', 999, 3 );
    function ws_fix_woo_translations( $translated, $text, $domain ) {
        if ( $domain === 'woocommerce' && $translated === 'София' ) {
            $translated = 'Област София';    
        }

        if ( $domain === 'woocommerce' && $text === 'Sofia District' ) {
            $translated = 'София-Град';
        }

        return $translated;
    }
}

// disable cash on delivery if gift card
if (!function_exists('shipping_unset_gateway_if_only_gift_card')) {
    add_filter( 'woocommerce_available_payment_gateways', 'shipping_unset_gateway_if_only_gift_card' );
    function shipping_unset_gateway_if_only_gift_card( $available_gateways ) {
        if ( ! is_checkout() ) return $available_gateways;

        $only_gift_cards = is_only_gift_cards();

        if($only_gift_cards) {
            unset( $available_gateways['cod'] );
        }

        return $available_gateways;
    }
}
