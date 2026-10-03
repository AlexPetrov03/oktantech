<?php

/**
 * @wordpress-plugin
 * Plugin Name:       Plus Minus Integration for WooCommerce
 * Plugin URI:        https://plusminus.com
 * Description:       Connect Plus Minus with WooCommerce to synchronize product quantity and price and import orders.
 * Version:           1.1
 * Author:            Plus Minus
 * Author URI:        https://plusminus.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       plusminus-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to: 6.8.2
 * Requires PHP: 7.4
 */

// If this file is called directly, abort.
if (!defined( 'WPINC')) {
    die;
}

define('PMWOO_VERSION', '1.1');
define('PMWOO_PLUGIN_URL', plugins_url () . '/' . basename ( dirname ( __FILE__ ) ) );

class PlusMinus_WooCommerce {
    private static $_instance;

    private function __construct() {

        $this->init();

        add_filter( 'cron_schedules', array($this, 'plugin_update_intervals') );

        add_action( 'pmwoo_automatic_update', array($this, 'plugin_automatic_update_data') );

        // register orders export
        if (PlusMinus_WooCommerce_Helpers::option_number_value('allow_orders_export') == 1) {
            PlusMinus_WooCommerce_Orders_Export::instance()->register_actions();
        }

        add_action( 'init', array($this, 'plugin_load_textdomain' ));

        // Hook for adding admin menus
        add_action( 'admin_menu', array($this, 'settings_page') );
        add_action( 'admin_init', array($this, 'admin_init') );
        add_action( 'admin_enqueue_scripts', array($this, 'load_assets') );
    }

    public static function instance() {
		if ( ! ( self::$_instance instanceof self ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Cloning disabled
	 */
	public function __clone() {
	}

	/**
	 * Serialization disabled
	 */
	public function __sleep() {
	}

	/**
	 * De-serialization disabled
	 */
	public function __wakeup() {
	}
    
    /**
     * Loading required classes
     */
    public function init() {
        spl_autoload_register( function( $class ) {
            $classes = array(
                // includes root
                'PlusMinus_WooCommerce_Options' => 'includes/class-plugin-options.php',                
                'PlusMinus_WooCommerce_Actions' => 'includes/class-plugin-actions.php',
                'PlusMinus_WooCommerce_Helpers' => 'includes/class-plugin-helpers.php',
                'PlusMinus_WooCommerce_Orders_Export' => 'includes/class-plugin-orders-export.php',
            );
        
            // if the file exists, require it
            $path = plugin_dir_path( __FILE__ );
            if ( array_key_exists( $class, $classes ) && file_exists( $path.$classes[$class] ) ) {
                require $path.$classes[$class];
            }
        });

    }
    
    /**
     * Loading static assets
     */
    public function load_assets() {
        wp_enqueue_style( 'plusminus-woocommerce', plugins_url( 'assets/plusminus.css', __FILE__ ), false, PMWOO_VERSION);
        wp_enqueue_script( 'plusminus-woocommerce',  plugins_url( 'assets/plusminus.js', __FILE__ ), array('jquery'), PMWOO_VERSION, true );
        wp_localize_script( 'plusminus-woocommerce', 'pmWooHelper',
		array( 
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'confirm_update' => esc_html('Please confirm update of product parameters from the selected warehouse in Plus Minus app', 'plusminus-woocommerce'),
            'confirm_import' => esc_html('Please confirm that you wish to import products from the Plus Minus app. The import will create all products that have quantity and price but not found by SKU (code) in the WooCommerce', 'plusminus-woocommerce')
		)
	);
    }
    
    /**
     * Initialize the admin settings and actions.
     */
    public function admin_init() {
        // register options
        PlusMinus_WooCommerce_Options::instance()->page_init();

        // register AJAX actions
        PlusMinus_WooCommerce_Actions::instance()->register_actions();
    }
    
    /**
     * Loading translation
     */
    public function plugin_load_textdomain() {
        load_plugin_textdomain( 'plusminus-woocommerce', false, basename( dirname( __FILE__ ) ) . '/languages/' );
    }

    public function settings_page() {
        add_options_page(
            esc_html__( 'Plus Minus','plusminus-woocommerce' ), 
            esc_html__( 'Plus Minus','plusminus-woocommerce' ), 
            'manage_options', 
            'plusminus-settings', 
            array($this, 'plugin_settings')
        );
    }

    public function plugin_settings() {
        PlusMinus_WooCommerce_Options::instance()->create_admin_page();
    }
    
    /**
     * Set custom time intervals for stock and price updates. 
     *
     * @param  array $schedules
     * @return array
     */
    public function plugin_update_intervals($schedules) {
        $schedules['every5min'] = array(
                'interval'  => 60 * 5, // time in seconds
                'display'   => 'Every 5 minutes'
        );

        $schedules['every10min'] = array(
            'interval'  => 60 * 10, // time in seconds
            'display'   => 'Every 10 minutes'
        );

        $schedules['every15min'] = array(
            'interval'  => 60 * 15, // time in seconds
            'display'   => 'Every 15 minutes'
        );

        $schedules['every30min'] = array(
            'interval'  => 60 * 30, // time in seconds
            'display'   => 'Every 30 minutes'
        );

        $schedules['every1h'] = array(
            'interval'  => 60 * 60, // time in seconds
            'display'   => 'Every 1 hour'
        );

        $schedules['every3h'] = array(
            'interval'  => 60 * 60 * 3, // time in seconds
            'display'   => 'Every 3 hours'
        );

        $schedules['every6h'] = array(
            'interval'  => 60 * 60 * 6, // time in seconds
            'display'   => 'Every 6 hours'
        );

        $schedules['every12h'] = array(
            'interval'  => 60 * 60 * 12, // time in seconds
            'display'   => 'Every 12 hours'
        );        

        $schedules['every24h'] = array(
            'interval'  => 60 * 60 * 24, // time in seconds
            'display'   => 'Every 24 hours'
        );

        return $schedules;
    }

    public function plugin_automatic_update_data() {
        if (class_exists('PlusMinus_WooCommerce_Actions')) {
            $result = PlusMinus_WooCommerce_Actions::instance()->automatic_update();
        }
    }
    
    /**
     * Save a log entry after the action is executed.
     *
     * @param  mixed $entry Log content
     * @param  string $mode Writing mode (a - append)
     * @param  string $file Filename prefix
     * @return int
     */
    public static function log( $entry, $mode = 'a', $file = 'pmwoo' ) { 
        // Get WordPress uploads directory.
        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'];
        // If the entry is array, json_encode.
        if ( is_array( $entry ) ) { 
          $entry = json_encode( $entry ); 
        } 
        // Write the log file.

        if(!file_exists($upload_dir . '/pmwoo-logs')) {
            wp_mkdir_p($upload_dir . '/pmwoo-logs');
        }

        $filename = $file . '-' . current_time('Y-m-d');

        $file  = $upload_dir . '/pmwoo-logs/' . $filename . '.log';
        $file  = fopen( $file, $mode );
        $bytes = fwrite( $file, current_time( 'mysql' ) . "::" . $entry . "\n" ); 
        fclose( $file ); 
        return $bytes;
    }
    
    /**
     * Reading the content of a log file
     *
     * @param  string $log_key Log key (date) to return. If left blank, it displays the current date. Format yyyy-mm-dd.
     * @param  string $file Filename prefix
     * @return string
     */
    public static function get_log($log_key = '', $file = 'pmwoo') {
        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'];

        if(!file_exists($upload_dir . '/pmwoo-logs')) {
            wp_mkdir_p($upload_dir . '/pmwoo-logs');
        }

        $filename = $file . '-' . ($log_key != '' ? $log_key : current_time('Y-m-d'));

        $file  = $upload_dir . '/pmwoo-logs/' . $filename . '.log';

        if(file_exists($file)){
            return file_get_contents($file);
        }
        else {
            return '';
        }
    }
}

/**
 * Initialize the plugin
 */
PlusMinus_WooCommerce::instance();


/**
 * Settings link via the plugins screen
 */
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'pmwoo_add_plugin_page_settings_link');
function pmwoo_add_plugin_page_settings_link( $links ) {
	$links[] = '<a href="' .
		admin_url( 'options-general.php?page=plusminus-settings' ) .
		'">' . __('Settings') . '</a>';
	return $links;
}

/**
 * Remove automatic value update on plugin deactivation
 */
register_deactivation_hook( __FILE__, 'pmwoo_deactivation' );
function pmwoo_deactivation() {
    wp_clear_scheduled_hook( 'pmwoo_automatic_update' );
}