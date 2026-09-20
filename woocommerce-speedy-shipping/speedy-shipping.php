<?php
/**
 * Plugin Name:       Speedy Shipping WooCommerce Plugin
 * Description:       Integrates Speedy Shipping with Woocommerce
 * Plugin URI:        https://speedy.bg
 * Version:           2.1.4
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Speedy Tech Lab
 * Text Domain:       speedy-shipping
 * Author URI:        https://speedy.bg
 * Domain Path:       /languages
 */

if( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

add_action( 'plugins_loaded', 'speedy_load_textdomain', 5 );
function speedy_load_textdomain() {
    load_plugin_textdomain( 'speedy-shipping', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

define('SPEEDY_API_BASE_URL', 'https://api.speedy.bg/v1/');
define('SPEEDY_API_COUNTRY_ID', 100);
define('SPEEDY_API_DEFAULT_WEIGHT', 1);

define( 'SPEEDY_DIR', __FILE__ );
define( 'SPEEDY_PATH', __DIR__ );
if ( is_admin() ) {
    if( ! function_exists('get_plugin_data') ) {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    }
    define( 'SPEEDY_SHIPPING_PLUGIN_DATA', get_plugin_data( __FILE__ ) );
}
define( 'SPEEDY_SHIPPING_PLUGIN_NAME', plugin_basename(__FILE__) );


require_once __DIR__ . '/includes/speedy-shipping.php';

register_activation_hook( __FILE__, 'speedy_activate' );
register_deactivation_hook( __FILE__, 'speedy_deactivate' );

define( 'SPEEDY_SCHEMA_VERSION', '1.0.0' );

function speedy_setup_schema() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $cities_table = $wpdb->prefix . 'speedy_cities';
    $offices_table = $wpdb->prefix . 'speedy_offices';

    $sql = "CREATE TABLE IF NOT EXISTS {$cities_table} (
        `id` MEDIUMINT UNSIGNED NULL UNIQUE,
        `name` VARCHAR(255) NULL,
        `post_code` VARCHAR(255) NULL,
        `region` VARCHAR(255) NULL,
        `type` VARCHAR(10) NULL,
        INDEX (`name`)
    ) {$charset_collate}";

    dbDelta( $sql );

    $sql = "CREATE TABLE IF NOT EXISTS {$offices_table} (
        `id` MEDIUMINT UNSIGNED NULL UNIQUE,
        `name` VARCHAR(512) NULL,
        `city` VARCHAR(255) NULL,
        `address` VARCHAR(255) NULL,
        `latitude` VARCHAR(255) NULL,
        `longitude` VARCHAR(255) NULL,
        `office_code` VARCHAR(10) NULL,
        `post_code` VARCHAR(5) NULL,
        `address_details` TEXT NULL,
        `office_details` TEXT NULL,
        `phone` VARCHAR(255) NULL,
        `email` VARCHAR(255) NULL
    ) {$charset_collate}";

    dbDelta( $sql );

    $wpdb->query( "ALTER TABLE {$cities_table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
    $wpdb->query( "ALTER TABLE {$offices_table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

    update_option( 'speedy_schema_version', SPEEDY_SCHEMA_VERSION );
}

function speedy_maybe_upgrade_schema() {
    $installed_version = get_option( 'speedy_schema_version' );

    if ( $installed_version === SPEEDY_SCHEMA_VERSION ) {
        return;
    }

    speedy_setup_schema();
}

add_action( 'plugins_loaded', 'speedy_maybe_upgrade_schema' );

function speedy_disable_automatic_waybills() {
    $settings = get_option( 'woocommerce_speedy_shipping_settings', [] );

    if ( ! is_array( $settings ) ) {
        $settings = [];
    }

    $settings['generate_waybill'] = 'no';
    update_option( 'woocommerce_speedy_shipping_settings', $settings );
}

function speedy_set_default_settings() {
    $settings = get_option( 'woocommerce_speedy_shipping_settings', [] );

    if ( ! is_array( $settings ) ) {
        $settings = [];
    }

    // This legacy option has no settings field anymore. Default it to off on
    // installation, while preserving an explicit value from existing stores.
    if ( ! array_key_exists( 'generate_waybill', $settings ) ) {
        speedy_disable_automatic_waybills();
    }
}

function speedy_activate() {
    speedy_setup_schema();
    speedy_set_default_settings();
}

function speedy_deactivate() {
    require_once __DIR__ . '/deactivate.php';
}

add_filter('site_transient_update_plugins', function ($transient) {

    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_slug = plugin_basename(__FILE__); 

    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_data = get_plugin_data(__FILE__);
    $current_version = $plugin_data['Version'];

    $response = wp_remote_get('https://demo.speedy.bg/wp/speedy-update.json');

    if (is_wp_error($response)) {
        return $transient;
    }

    $data = json_decode(wp_remote_retrieve_body($response));

    if (!$data || empty($data->version)) {
        return $transient;
    }

    if (version_compare($current_version, $data->version, '<')) {

        $transient->response[$plugin_slug] = (object) [
            'slug' => 'woocommerce-speedy-shipping',
            'plugin' => $plugin_slug,
            'new_version' => $data->version,
            'url' => $data->homepage,
            'package' => $data->download_url,
            'tested' => $data->tested ?? '',
            'requires' => $data->requires ?? '',
            'requires_php' => $data->requires_php ?? ''
        ];
    }

    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {

    if ($action !== 'plugin_information') {
        return $result;
    }

    if ($args->slug !== 'woocommerce-speedy-shipping') {
        return $result;
    }

    $response = wp_remote_get('https://demo.speedy.bg/wp/speedy-update.json');

    if (is_wp_error($response)) {
        return $result;
    }

    $data = json_decode(wp_remote_retrieve_body($response));

    if (!$data) {
        return $result;
    }

    return (object) [
        'name' => $data->name,
        'slug' => $data->slug,
        'version' => $data->version,
        'author' => $data->author,
        'homepage' => $data->homepage,
        'sections' => [
            'description' => $data->sections->description ?? '',
            'changelog' => $data->sections->changelog ?? ''
        ]
    ];
}, 10, 3);