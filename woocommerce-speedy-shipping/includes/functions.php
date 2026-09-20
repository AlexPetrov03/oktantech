<?php

defined( 'ABSPATH' ) or exit;

function speedy_get_setting( $key, $default = null ) {
    $settings = get_option( 'woocommerce_speedy_shipping_settings' );

    return isset( $settings[$key] ) ? $settings[$key] : $default;
}

function speedy_get_configured_shipping_amount_by_type( $shipping_type, $order = null ) {
    $shipping_type = trim( (string) $shipping_type );

    if ( $shipping_type === 'Офис' ) {
        $shipping_type = 'office';
    }

    if ( ! in_array( $shipping_type, [ 'office', 'office2', 'address' ], true ) ) {
        $shipping_type = 'address';
    }

    $cenadostavka_mode = (string) speedy_get_setting( 'cenadostavka' );

    if ( $cenadostavka_mode === 'fixedprices' ) {
        if ( $shipping_type === 'office' ) {
            return (float) speedy_get_setting( 'fixed_shipping_office' );
        }

        if ( $shipping_type === 'office2' ) {
            return (float) speedy_get_setting( 'fixed_shipping_automat' );
        }

        return (float) speedy_get_setting( 'fixed_shipping_address' );
    }

    if ( $cenadostavka_mode === 'fileprices' && is_object( $order ) && method_exists( $order, 'get_shipping_total' ) ) {
        return (float) $order->get_shipping_total();
    }

    return 0.0;
}

function speedy_get_cart_products_total_with_vat() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return 0.0;
    }

    $products_total_with_vat = 0.0;

    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $products_total_with_vat += (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
    }

    if ( $products_total_with_vat > 0 ) {
        return round( $products_total_with_vat, 2 );
    }

    $totals = WC()->cart->get_totals();
    $shipping_with_vat = (float) ( $totals['shipping_total'] ?? 0 ) + (float) ( $totals['shipping_tax'] ?? 0 );

    return round( max( 0, (float) ( $totals['total'] ?? 0 ) - $shipping_with_vat ), 2 );
}

function speedy_get_order_products_total_with_vat( $order ) {
    if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
        return 0.0;
    }

    $products_total_with_vat = 0.0;

    foreach ( $order->get_items( 'line_item' ) as $item ) {
        $products_total_with_vat += (float) $item->get_total() + (float) $item->get_total_tax();
    }

    return round( $products_total_with_vat, 2 );
}

function speedy_password() {
    return speedy_get_setting( 'speedy_password' );
}

function speedy_username() {
    return speedy_get_setting( 'speedy_name' );
}

function speedy_get_final_tracking_statuses() {
    return [
        '-14' => [
            'label'   => 'Доставена',
            'english' => 'Delivered',
        ],
        '124' => [
            'label'   => 'Доставена обратно на получателя',
            'english' => 'Delivered Back to Sender',
        ],
        '125' => [
            'label'   => 'Унищожена',
            'english' => 'Destroyed',
        ],
        '127' => [
            'label'   => 'Кражба',
            'english' => 'Theft/Burglary',
        ],
        '128' => [
            'label'   => 'Анулирана',
            'english' => 'Canceled',
        ],
        '129' => [
            'label'   => 'Служебно закрита',
            'english' => 'Administrative Closure',
        ],
    ];
}

function speedy_get_final_tracking_status_label( $code ) {
    $statuses = speedy_get_final_tracking_statuses();
    $code = (string) $code;

    if ( ! isset( $statuses[ $code ] ) ) {
        return $code;
    }

    return sprintf(
        '%s (%s, %s)',
        $statuses[ $code ]['label'],
        $code,
        $statuses[ $code ]['english']
    );
}

function speedy_csv_to_arr( $file_name ) {
    $arr = [];

    $file = fopen( $file_name, 'r' );

    $keys = fgetcsv( $file );
    if ( is_array( $keys ) ) {
        $keys = array_map( 'speedy_normalize_csv_value', $keys );
    }

    while(($data = fgetcsv( $file )) !== false ) {
        $data = array_map( 'speedy_normalize_csv_value', $data );
        $arr[] = array_combine( $keys, $data );
    }

    fclose( $file );

    return $arr;
}

function speedy_normalize_csv_value( $value ) {
    if ( ! is_string( $value ) ) {
        return $value;
    }

    $value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );

    if ( $value === '' ) {
        return $value;
    }

    if ( mb_check_encoding( $value, 'UTF-8' ) ) {
        return $value;
    }

    $encodings = [ 'Windows-1251', 'CP1251', 'ISO-8859-1' ];

    foreach ( $encodings as $encoding ) {
        $converted = @mb_convert_encoding( $value, 'UTF-8', $encoding );

        if ( is_string( $converted ) && $converted !== '' && mb_check_encoding( $converted, 'UTF-8' ) ) {
            return $converted;
        }
    }

    return $value;
}

if(!function_exists('is_only_gift_cards')) {
    function is_only_gift_cards() {
        $only_gift_cards = TRUE;
        
        if(!WC()->cart) return FALSE;

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if(!array_key_exists('gift-card-amount', $cart_item['data']->get_attributes())) {
                $only_gift_cards = FALSE;
            }
        
        }
        
        return $only_gift_cards;
    }
}
