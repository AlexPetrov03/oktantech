<?php

if (!defined( 'ABSPATH')) {
    die;
}

class PlusMinus_WooCommerce_Options {
    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;

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

    public function get_options() {
        return get_option( 'plusminus_woocommerce_settings' );
    }

    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        // Set class property
        ?>
        <div class="pm-settings-topbar">
            <div class="pm-settings-logo"><img src="<?php echo PMWOO_PLUGIN_URL . '/assets/pmlogo.webp';?>" class="logo"/></div>
        </div>
        <div class="wrap pm-settings-wrap">
            <form method="post" action="options.php">
            <?php
                global $options;

                // This prints out all hidden setting fields
                settings_fields( 'plusminus_woocommerce_settings_group' );
                do_settings_sections( 'plusminus-settings' );
                submit_button();
            ?>
            </form>

            <?php 
            $nonce = wp_create_nonce( 'plusminus-woocommerce-settings' );
            ?>

            <div class="pm-settings-panel pm-settings-actions">
                <div class="pm-settings-panel-row">
                    <div class="pm-settings-panel-row__item-before">
                    <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path opacity="0.15" d="M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" fill="#001A72"/>
                    <path d="M17.5303 9.53033C17.8232 9.23744 17.8232 8.76256 17.5303 8.46967C17.2374 8.17678 16.7626 8.17678 16.4697 8.46967L17.5303 9.53033ZM9.99998 16L9.46965 16.5304C9.76255 16.8232 10.2374 16.8232 10.5303 16.5304L9.99998 16ZM7.53027 12.4697C7.23737 12.1768 6.7625 12.1768 6.46961 12.4697C6.17671 12.7626 6.17672 13.2374 6.46961 13.5303L7.53027 12.4697ZM16.4697 8.46967L9.46965 15.4697L10.5303 16.5304L17.5303 9.53033L16.4697 8.46967ZM6.46961 13.5303L9.46965 16.5304L10.5303 15.4697L7.53027 12.4697L6.46961 13.5303ZM20.25 12C20.25 16.5563 16.5563 20.25 12 20.25V21.75C17.3848 21.75 21.75 17.3848 21.75 12H20.25ZM12 20.25C7.44365 20.25 3.75 16.5563 3.75 12H2.25C2.25 17.3848 6.61522 21.75 12 21.75V20.25ZM3.75 12C3.75 7.44365 7.44365 3.75 12 3.75V2.25C6.61522 2.25 2.25 6.61522 2.25 12H3.75ZM12 3.75C16.5563 3.75 20.25 7.44365 20.25 12H21.75C21.75 6.61522 17.3848 2.25 12 2.25V3.75Z" fill="#001A72"/>
                    </svg>
                    </div>
                    <div class="pm-settings-panel-row__item-text">
                        <div class="pm-settings-panel-row__item-title"><?php esc_html_e('Connection test', 'plusminus-woocommerce'); ?></div>
                        <div class="pm-settings-panel-row__item-content"><?php esc_html_e('Test the connection between your WooCommerce store and the Plus Minus app.', 'plusminus-woocommerce'); ?></div>
                    </div>
                    <div class="pm-settings-panel-row__item-after">
                        <span class="spinner plusminusWooTestSpinner"></span>
                        <button class="button-secondary" id="plusminusWooTestBtn" data-nonce="<?php echo $nonce; ?>"><?php esc_html_e('Test', 'plusminus-woocommerce'); ?></button>
                    </div>
                </div>

                <div class="pm-settings-panel-row">
                    <div class="pm-settings-panel-row__item-before">
                        <!-- https://www.svgrepo.com/svg/316565/duotone-arrows-up-circle -->
                    <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path opacity="0.15" d="M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" fill="#001A72"/>
                    <path d="M7.75 7.5C7.75 7.08579 7.41421 6.75 7 6.75C6.58579 6.75 6.25 7.08579 6.25 7.5H7.75ZM7 10H6.25C6.25 10.4142 6.58579 10.75 7 10.75V10ZM10 10.75C10.4142 10.75 10.75 10.4142 10.75 10C10.75 9.58579 10.4142 9.25 10 9.25V10.75ZM16.25 16.5C16.25 16.9142 16.5858 17.25 17 17.25C17.4142 17.25 17.75 16.9142 17.75 16.5H16.25ZM17 14H17.75C17.75 13.5858 17.4142 13.25 17 13.25V14ZM14 13.25C13.5858 13.25 13.25 13.5858 13.25 14C13.25 14.4142 13.5858 14.75 14 14.75V13.25ZM15.3634 10.3966C15.5825 10.7482 16.045 10.8556 16.3966 10.6366C16.7482 10.4175 16.8556 9.95497 16.6366 9.60341L15.3634 10.3966ZM13.9818 8.4222L13.7049 9.1192L13.7049 9.1192L13.9818 8.4222ZM11.0495 8.03299L11.125 8.77919L11.0495 8.03299ZM8.38042 8.98865L8.78697 9.6189C8.79737 9.61219 8.80761 9.60522 8.81767 9.59801L8.38042 8.98865ZM6.59346 9.24882C6.24538 9.47335 6.14522 9.93754 6.36974 10.2856C6.59427 10.6337 7.05846 10.7339 7.40654 10.5093L6.59346 9.24882ZM8.63656 13.6034C8.41753 13.2518 7.95497 13.1444 7.60341 13.3634C7.25184 13.5825 7.1444 14.045 7.36344 14.3966L8.63656 13.6034ZM10.0182 15.5778L10.2951 14.8808L10.2951 14.8808L10.0182 15.5778ZM15.6196 15.0114L15.213 14.3811C15.2026 14.3878 15.1924 14.3948 15.1823 14.402L15.6196 15.0114ZM17.4065 14.7512C17.7546 14.5267 17.8548 14.0625 17.6303 13.7144C17.4057 13.3663 16.9415 13.2661 16.5935 13.4907L17.4065 14.7512ZM6.25 7.5V10H7.75V7.5H6.25ZM7 10.75H10V9.25H7V10.75ZM17.75 16.5V14H16.25V16.5H17.75ZM17 13.25H14V14.75H17V13.25ZM16.6366 9.60341C16.1178 8.77067 15.2673 8.12594 14.2588 7.72521L13.7049 9.1192C14.4821 9.42802 15.0483 9.89079 15.3634 10.3966L16.6366 9.60341ZM14.2588 7.72521C13.2477 7.32347 12.097 7.17323 10.974 7.2868L11.125 8.77919C12.0226 8.68842 12.9302 8.81138 13.7049 9.1192L14.2588 7.72521ZM10.974 7.2868C9.85216 7.40025 8.78622 7.77437 7.94318 8.37928L8.81767 9.59801C9.4187 9.16674 10.2262 8.87007 11.125 8.77919L10.974 7.2868ZM7.97388 8.35839L6.59346 9.24882L7.40654 10.5093L8.78697 9.6189L7.97388 8.35839ZM7.36344 14.3966C7.88225 15.2293 8.73266 15.8741 9.74122 16.2748L10.2951 14.8808C9.51787 14.572 8.95169 14.1092 8.63656 13.6034L7.36344 14.3966ZM9.74122 16.2748C10.7523 16.6765 11.903 16.8268 13.026 16.7132L12.875 15.2208C11.9774 15.3116 11.0698 15.1886 10.2951 14.8808L9.74122 16.2748ZM13.026 16.7132C14.1478 16.5998 15.2138 16.2256 16.0568 15.6207L15.1823 14.402C14.5813 14.8333 13.7738 15.1299 12.875 15.2208L13.026 16.7132ZM16.0261 15.6416L17.4065 14.7512L16.5935 13.4907L15.213 14.3811L16.0261 15.6416ZM20.25 12C20.25 16.5563 16.5563 20.25 12 20.25V21.75C17.3848 21.75 21.75 17.3848 21.75 12H20.25ZM12 20.25C7.44365 20.25 3.75 16.5563 3.75 12H2.25C2.25 17.3848 6.61522 21.75 12 21.75V20.25ZM3.75 12C3.75 7.44365 7.44365 3.75 12 3.75V2.25C6.61522 2.25 2.25 6.61522 2.25 12H3.75ZM12 3.75C16.5563 3.75 20.25 7.44365 20.25 12H21.75C21.75 6.61522 17.3848 2.25 12 2.25V3.75Z" fill="#001A72"/>
                    </svg>
                    </div>
                    <div class="pm-settings-panel-row__item-text">
                        <div class="pm-settings-panel-row__item-title"><?php esc_html_e('Manually update products\' quantity and/or price', 'plusminus-woocommerce'); ?></div>
                        <div class="pm-settings-panel-row__item-content"><?php esc_html_e('Start a manual update of the products\' parameters based on the settings.', 'plusminus-woocommerce'); ?></div>
                    </div>
                    <div class="pm-settings-panel-row__item-after">
                        <span class="spinner plusminusWooUpdateSpinner"></span>
                        <button class="button-primary" id="plusminusWooUpdateBtn" data-nonce="<?php echo $nonce; ?>"><?php esc_html_e('Update', 'plusminus-woocommerce'); ?></button>
                    </div>
                </div>

                <div class="pm-settings-panel-row">
                    <div class="pm-settings-panel-row__item-before">
                        <!-- https://www.svgrepo.com/svg/316565/duotone-arrows-up-circle -->
                    <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path opacity="0.15" d="M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" fill="#001A72"/>
                    <path d="M12 7L12.5303 6.46967C12.2374 6.17678 11.7626 6.17678 11.4697 6.46967L12 7ZM11.25 17C11.25 17.4142 11.5858 17.75 12 17.75C12.4142 17.75 12.75 17.4142 12.75 17H11.25ZM15.4697 11.5303C15.7626 11.8232 16.2374 11.8232 16.5303 11.5303C16.8232 11.2374 16.8232 10.7626 16.5303 10.4697L15.4697 11.5303ZM7.46967 10.4697C7.17678 10.7626 7.17678 11.2374 7.46967 11.5303C7.76256 11.8232 8.23744 11.8232 8.53033 11.5303L7.46967 10.4697ZM11.25 7V17H12.75V7H11.25ZM16.5303 10.4697L12.5303 6.46967L11.4697 7.53033L15.4697 11.5303L16.5303 10.4697ZM11.4697 6.46967L7.46967 10.4697L8.53033 11.5303L12.5303 7.53033L11.4697 6.46967ZM20.25 12C20.25 16.5563 16.5563 20.25 12 20.25V21.75C17.3848 21.75 21.75 17.3848 21.75 12H20.25ZM12 20.25C7.44365 20.25 3.75 16.5563 3.75 12H2.25C2.25 17.3848 6.61522 21.75 12 21.75V20.25ZM3.75 12C3.75 7.44365 7.44365 3.75 12 3.75V2.25C6.61522 2.25 2.25 6.61522 2.25 12H3.75ZM12 3.75C16.5563 3.75 20.25 7.44365 20.25 12H21.75C21.75 6.61522 17.3848 2.25 12 2.25V3.75Z" fill="#001A72"/>
                    </svg>
                    </div>
                    <div class="pm-settings-panel-row__item-text">
                        <div class="pm-settings-panel-row__item-title"><?php esc_html_e('Import all products from the Plus Minus app into the WooCommerce store', 'plusminus-woocommerce'); ?></div>
                        <div class="pm-settings-panel-row__item-content"><?php esc_html_e('Import all products that are not found by code from the Plus Minus app into the WooCommerce store. Elighable products should have a code, quantity, and price in the Plus Minus app.', 'plusminus-woocommerce'); ?></div>
                    </div>
                    <div class="pm-settings-panel-row__item-after">
                        <span class="spinner plusminusWooImportSpinner"></span>
                        <button class="button-primary" id="plusminusWooImportBtn" data-nonce="<?php echo $nonce; ?>"><?php esc_html_e('Import', 'plusminus-woocommerce'); ?></button>
                    </div>
                </div>

                <?php if ($this->orders_export_is_enabled()) { ?>

                <div class="pm-settings-panel-row">
                    <div class="pm-settings-panel-row__item-before">
                    <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path opacity="0.15" d="M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" fill="#001A72"/>
                        <path d="M12 17L11.4697 17.5303C11.7626 17.8232 12.2374 17.8232 12.5303 17.5303L12 17ZM12.75 7C12.75 6.58579 12.4142 6.25 12 6.25C11.5858 6.25 11.25 6.58579 11.25 7L12.75 7ZM8.53033 12.4697C8.23744 12.1768 7.76256 12.1768 7.46967 12.4697C7.17678 12.7626 7.17678 13.2374 7.46967 13.5303L8.53033 12.4697ZM16.5303 13.5303C16.8232 13.2374 16.8232 12.7626 16.5303 12.4697C16.2374 12.1768 15.7626 12.1768 15.4697 12.4697L16.5303 13.5303ZM12.75 17L12.75 7L11.25 7L11.25 17L12.75 17ZM7.46967 13.5303L11.4697 17.5303L12.5303 16.4697L8.53033 12.4697L7.46967 13.5303ZM12.5303 17.5303L16.5303 13.5303L15.4697 12.4697L11.4697 16.4697L12.5303 17.5303ZM20.25 12C20.25 16.5563 16.5563 20.25 12 20.25V21.75C17.3848 21.75 21.75 17.3848 21.75 12H20.25ZM12 20.25C7.44365 20.25 3.75 16.5563 3.75 12H2.25C2.25 17.3848 6.61522 21.75 12 21.75V20.25ZM3.75 12C3.75 7.44365 7.44365 3.75 12 3.75V2.25C6.61522 2.25 2.25 6.61522 2.25 12H3.75ZM12 3.75C16.5563 3.75 20.25 7.44365 20.25 12H21.75C21.75 6.61522 17.3848 2.25 12 2.25V3.75Z" fill="#001A72"/>
                    </svg>                    
                    </div>
                    <div class="pm-settings-panel-row__item-text">
                        <div class="pm-settings-panel-row__item-title"><?php esc_html_e('Download orders as a file', 'plusminus-woocommerce'); ?></div>
                        <div class="pm-settings-panel-row__item-content"><?php esc_html_e('Download all orders that are not marked as exported as a file that can be imported in the Plus Minus app manually.', 'plusminus-woocommerce'); ?></div>
                    </div>
                    <div class="pm-settings-panel-row__item-after">
                        <a href="<?php echo $this->orders_download_url(); ?>" target="_blank" class="button" id="plusminusWooDownloadBtn" data-nonce="<?php echo $nonce; ?>"><?php esc_html_e('Download', 'plusminus-woocommerce'); ?></a>
                    </div>
                </div>                    

                <?php } ?>
            </div>

            <?php $this->should_display_log(); ?>
        </div>
        <?php

    }

    /**
     * Register and add settings
     */
    public function page_init() {        
        // dd('a');
        $this->options = get_option( 'plusminus_woocommerce_settings' );
        register_setting(
            'plusminus_woocommerce_settings_group', // Option group
            'plusminus_woocommerce_settings', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'setting_section_export', // ID
            esc_html__('Export Orders to Plus Minus', 'plusminus-woocommerce'), // Title
            '', // Callback
            'plusminus-settings' // Page
        ); 

        add_settings_field(
            'allow_orders_export',
            esc_html__('Allow orders export', 'plusminus-woocommerce'),
            array($this, 'allow_orders_export_checkbox_callback'),
            'plusminus-settings',
            'setting_section_export'
        ); 

        add_settings_field(
            'export_token', 
            esc_html__('Security key', 'plusminus-woocommerce'), 
            array( $this, 'export_token_callback' ), 
            'plusminus-settings', 
            'setting_section_export'
        );   

        add_settings_field(
            'export_order_status',
            esc_html__('Only orders with status', 'plusminus-woocommerce'),
            array($this, 'export_order_status_callback'),
            'plusminus-settings',
            'setting_section_export'
        ); 

        add_settings_field(
            'ignore_incomplete_orders',
            esc_html__('Don\'t export Cancelled, Draft and Failed orders', 'plusminus-woocommerce'),
            array($this, 'ignore_incomplete_orders_checkbox_callback'),
            'plusminus-settings',
            'setting_section_export'
        ); 

        add_settings_section(
            'setting_section_id', // ID
            esc_html__('Plus Minus Server Settings', 'plusminus-woocommerce'), // Title
            array( $this, 'print_section_info' ), // Callback
            'plusminus-settings' // Page
        );  

        add_settings_field(
            'serial_number', // ID
            esc_html__('Serial Number', 'plusmius-woocommerce'), // Title 
            array( $this, 'serial_number_callback' ), // Callback
            'plusminus-settings', // Page
            'setting_section_id' // Section
        );      

        add_settings_field(
            'private_key', 
            esc_html__('Token', 'plusminus-woocommerce'), 
            array( $this, 'token_callback' ), 
            'plusminus-settings', 
            'setting_section_id'
        );   

        add_settings_field(
            'company_name', 
            esc_html__('Company Name', 'plusminus-woocommerce'), 
            array( $this, 'company_name_callback' ), 
            'plusminus-settings', 
            'setting_section_id'
        );    
        
        add_settings_field(
            'store_name', 
            esc_html__('Store Name', 'plusminus-woocommerce'), 
            array( $this, 'store_name_callback' ), 
            'plusminus-settings', 
            'setting_section_id'
        );     
        
        add_settings_field(
            'update_quantity',
            esc_html__('Update available quantity', 'plusminus-woocommerce'),
            array($this, 'update_quantity_checkbox_callback'),
            'plusminus-settings',
            'setting_section_id'
        );

        add_settings_field(
            'update_price',
            esc_html__('Update regular price', 'plusminus-woocommerce'),
            array($this, 'update_price_checkbox_callback'),
            'plusminus-settings',
            'setting_section_id'
        );        

        add_settings_field(
            'price_parameter',
            esc_html__('Price parameter', 'plusminus-woocommerce'),
            array($this, 'price_parameter_callback'),
            'plusminus-settings',
            'setting_section_id'
        ); 
        //price_parameter

        add_settings_field(
            'automatic_update',
            esc_html__('Automatic update values', 'plusminus-woocommerce'),
            array($this, 'automatic_update_callback'),
            'plusminus-settings',
            'setting_section_id'
        );          
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
        $new_input = array();
        if( isset( $input['serial_number'] ) )
            $new_input['serial_number'] = sanitize_text_field( $input['serial_number'] );

        if( isset( $input['private_key'] ) )
            $new_input['private_key'] = sanitize_text_field( $input['private_key'] );
        
        if( isset( $input['store_name'] ) )
            $new_input['store_name'] = sanitize_text_field( $input['store_name'] );        
            
        if( isset( $input['company_name'] ) )
            $new_input['company_name'] = sanitize_text_field( $input['company_name'] );            
        
        if( isset( $input['update_quantity'] ) )
            $new_input['update_quantity'] = absint( $input['update_quantity'] );

        if( isset( $input['update_price'] ) )
            $new_input['update_price'] = absint( $input['update_price'] );

        if ( isset( $input['allow_orders_export'] ) )
            $new_input['allow_orders_export'] = absint( $input['allow_orders_export'] );

        if( isset( $input['automatic_update'] ) )
            $new_input['automatic_update'] = sanitize_text_field( $input['automatic_update'] );            
        
        if( isset( $input['price_parameter'] ) )
            $new_input['price_parameter'] = sanitize_text_field( $input['price_parameter'] );            
          
        if( isset( $input['export_token'] ) )
            $new_input['export_token'] = sanitize_text_field( $input['export_token'] );        
            
        // generating a security token when activating the option to allow export
        if (!isset($input['export_token']) || empty($input['export_token'])) {
            if (isset($new_input['allow_orders_export']) && $new_input['allow_orders_export'] == 1) {
                $new_input['export_token'] = bin2hex(random_bytes(32));
            }
        }

        if ( isset( $input['ignore_incomplete_orders'] ) )
            $new_input['ignore_incomplete_orders'] = absint( $input['ignore_incomplete_orders'] );

        //export_order_status
        if( isset( $input['export_order_status'] ) )
            $new_input['export_order_status'] = sanitize_text_field( $input['export_order_status'] );    

        // clearing cron
        wp_clear_scheduled_hook( 'pmwoo_automatic_update' );
        if (isset( $input['automatic_update'] )) {
            $cron_period = PlusMinus_WooCommerce_Helpers::settings_period_to_cron_interval($input['automatic_update']);
            if (!empty($cron_period)) {
                if ( ! wp_next_scheduled( 'pmwoo_automatic_update' ) ) {
                    wp_schedule_event( time(), $cron_period, 'pmwoo_automatic_update' );
                }
            }
        }

        return $new_input;
    }

    /** 
     * Print the Section text
     */
    public function print_section_info()
    {
        esc_html_e('Enter authorization information to allow the automatic update of price and quantity for the existing WooCommerce products. During the import, products in the WooCommerce store are located by the SKU code that you have in the product settings and the code or barcode fields in the Plus Minus store.', 'plusminus-woocommerce');
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function serial_number_callback()
    {        
        printf(
            '<input type="text" id="serial_number" name="plusminus_woocommerce_settings[serial_number]" value="%s" />',
            isset( $this->options['serial_number'] ) ? esc_attr( $this->options['serial_number']) : ''
        );
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function token_callback() {
        printf(
            '<input type="password" id="private_key" name="plusminus_woocommerce_settings[private_key]" value="%s" class="pm-settings-field-stretched" />',
            isset( $this->options['private_key'] ) ? esc_attr( $this->options['private_key']) : ''
        );
    }

    public function export_token_callback() {
        printf(
            '<input type="text" id="export_token" name="plusminus_woocommerce_settings[export_token]" value="%s" class="pm-settings-field-stretched" />',
            isset( $this->options['export_token'] ) ? esc_attr( $this->options['export_token']) : ''
        );

        print '<p class="description">'.esc_html__('This field contains the security key you need to enter in the Plus Minus settings to import new orders. When the option to allow orders export is enabled, a new key will be generated automatically when the settings are saved.', 'plusminus-woocommerce') .'</p>';
    }

    public function store_name_callback() {
        printf(
            '<input type="text" id="store_name" name="plusminus_woocommerce_settings[store_name]" value="%s" class="pm-settings-field-stretched" />',
            isset( $this->options['store_name'] ) ? esc_attr( $this->options['store_name']) : ''
        );

        print '<p class="description">'.esc_html__('Enter the name of the store in the Plus Minus app where products are located. The name should be exactly as it appears in the Plus Minus app.', 'plusminus-woocommerce') .'</p>';
    }

    public function company_name_callback() {
        printf(
            '<input type="text" id="company_name" name="plusminus_woocommerce_settings[company_name]" value="%s" class="pm-settings-field-stretched" />',
            isset( $this->options['company_name'] ) ? esc_attr( $this->options['company_name']) : ''
        );
        print '<p class="description">'.esc_html__('Enter the name of the company in the Plus Minus app where products are located. The comapny name should be exactly as it appears in the Plus Minus app.', 'plusminus-woocommerce') .'</p>';
    }

    function update_quantity_checkbox_callback() {
        if (!isset($this->options['update_quantity'])) {
            $this->options['update_quantity'] = 0;
        }

        printf(
            '<!-- Here we are comparing stored value with 1. Stored value is 1 if user checks the checkbox otherwise empty string. -->
            <input type="checkbox" name="plusminus_woocommerce_settings[update_quantity]" value="1" %s />',
            checked(1, $this->options['update_quantity'], false)
        );

        print '<p class="description">'.esc_html__('Update the products\' stock quantity with the one from the Plus Minus app.', 'plusminus-woocommerce') .'</p>';
    }

    function update_price_checkbox_callback() {
        if (!isset($this->options['update_price'])) {
            $this->options['update_price'] = 0;
        }

        printf(
            '<!-- Here we are comparing stored value with 1. Stored value is 1 if user checks the checkbox otherwise empty string. -->
            <input type="checkbox" name="plusminus_woocommerce_settings[update_price]" value="1" %s />',
            checked(1, $this->options['update_price'], false)
        );

        print '<p class="description">'.esc_html__('Update the products\' regular price with the one from the Plus Minus app.', 'plusminus-woocommerce') .'</p>';
    }    

    function allow_orders_export_checkbox_callback() {
        if (!isset($this->options['allow_orders_export'])) {
            $this->options['allow_orders_export'] = 0;
        }

        printf(
            '<!-- Here we are comparing stored value with 1. Stored value is 1 if user checks the checkbox otherwise empty string. -->
            <input type="checkbox" name="plusminus_woocommerce_settings[allow_orders_export]" value="1" %s />',
            checked(1, $this->options['allow_orders_export'], false)
        );

        print '<p class="description">' . esc_html__('Enable the option to export and import orders in Plus Minus.', 'plusminus-woocommerce') . '</p>';
    }   

    function ignore_incomplete_orders_checkbox_callback() {
        if (!isset($this->options['ignore_incomplete_orders'])) {
            $this->options['ignore_incomplete_orders'] = 0;
        }

        printf(
            '<!-- Here we are comparing stored value with 1. Stored value is 1 if user checks the checkbox otherwise empty string. -->
            <input type="checkbox" name="plusminus_woocommerce_settings[ignore_incomplete_orders]" value="1" %s />',
            checked(1, $this->options['ignore_incomplete_orders'], false)
        );
    }   

    function automatic_update_callback() {
        $current_value = isset($this->options['automatic_update']) ? $this->options['automatic_update'] : '';

        print '<select id="automatic_update" name="plusminus_woocommerce_settings[automatic_update]">';
        printf ('<option value="no" %s>%s</option>', selected( $current_value, 'no'), esc_html__('No', 'plusminus-woocommerce'));
        printf ('<option value="5m" %s>%s</option>', selected( $current_value, '5m'), esc_html__('5 minutes', 'plusminus-woocommerce'));
        printf ('<option value="10m" %s>%s</option>', selected( $current_value, '10m'), esc_html__('10 minutes', 'plusminus-woocommerce'));
        printf ('<option value="15m" %s>%s</option>', selected( $current_value, '15m'), esc_html__('15 minutes', 'plusminus-woocommerce'));
        printf ('<option value="30m" %s>%s</option>', selected( $current_value, '30m'), esc_html__('30 minutes', 'plusminus-woocommerce'));
        printf ('<option value="1h" %s>%s</option>', selected( $current_value, '1h'), esc_html__('1 hour', 'plusminus-woocommerce'));
        printf ('<option value="3h" %s>%s</option>', selected( $current_value, '3h'), esc_html__('3 hours', 'plusminus-woocommerce'));
        printf ('<option value="6h" %s>%s</option>', selected( $current_value, '6h'), esc_html__('6 hours', 'plusminus-woocommerce'));
        printf ('<option value="12h" %s>%s</option>', selected( $current_value, '12h'), esc_html__('12 hours', 'plusminus-woocommerce'));
        printf ('<option value="24h" %s>%s</option>', selected( $current_value, '24h'), esc_html__('24 hours', 'plusminus-woocommerce'));
        print '</select>';

        print '<p class="description">'.esc_html__('Enable automatic updating of the selected parameters (price and/or quantity). Leave it at "No" if you will update only manually.', 'plusminus-woocommerce') .'</p>';
    }

    function price_parameter_callback() {
        $current_value = isset($this->options['price_parameter']) ? $this->options['price_parameter'] : '';

        print '<select id="price_parameter" name="plusminus_woocommerce_settings[price_parameter]">';
        printf ('<option value="retailPrice" %s>%s</option>', selected( $current_value, 'retailPrice'), esc_html__('Retail Price', 'plusminus-woocommerce'));
        printf ('<option value="retailPriceVAT" %s>%s</option>', selected( $current_value, 'retailPriceVAT'), esc_html__('Retail Price with VAT', 'plusminus-woocommerce'));
        printf ('<option value="wholesalePrice" %s>%s</option>', selected( $current_value, 'wholesalePrice'), esc_html__('Wholesale Price', 'plusminus-woocommerce'));
        printf ('<option value="price0" %s>%s</option>', selected( $current_value, 'price0'), esc_html__('Price 0', 'plusminus-woocommerce'));
        printf ('<option value="price1" %s>%s</option>', selected( $current_value, 'price1'), esc_html__('Price 1', 'plusminus-woocommerce'));
        printf ('<option value="price2" %s>%s</option>', selected( $current_value, 'price2'), esc_html__('Price 2', 'plusminus-woocommerce'));
        printf ('<option value="price3" %s>%s</option>', selected( $current_value, 'price3'), esc_html__('Price 3', 'plusminus-woocommerce'));
        printf ('<option value="price4" %s>%s</option>', selected( $current_value, 'price4'), esc_html__('Price 4', 'plusminus-woocommerce'));
        printf ('<option value="price5" %s>%s</option>', selected( $current_value, 'price5'), esc_html__('Price 5', 'plusminus-woocommerce'));
        printf ('<option value="price6" %s>%s</option>', selected( $current_value, 'price6'), esc_html__('Price 6', 'plusminus-woocommerce'));
        printf ('<option value="price7" %s>%s</option>', selected( $current_value, 'price7'), esc_html__('Price 7', 'plusminus-woocommerce'));
        printf ('<option value="price8" %s>%s</option>', selected( $current_value, 'price8'), esc_html__('Price 8', 'plusminus-woocommerce'));
        printf ('<option value="price9" %s>%s</option>', selected( $current_value, 'price9'), esc_html__('Price 9', 'plusminus-woocommerce'));
        print '</select>';

        print '<p class="description">'.esc_html__('The price parameter that should be used to read from the selected store in the Plus Minus app.', 'plusminus-woocommerce') .'</p>';
    }

    //wc_get_order_statuses  export_order_status
    function export_order_status_callback() {
        $current_value = isset($this->options['export_order_status']) ? $this->options['export_order_status'] : '';
        $current_status_list = wc_get_order_statuses();

        print '<select id="export_order_status" name="plusminus_woocommerce_settings[export_order_status]">';
        printf ('<option value="" %s>%s</option>', selected( $current_value, ''), esc_html__('All', 'plusminus-woocommerce'));
        foreach ($current_status_list as $key => $text) {
            printf ('<option value="%s" %s>%s</option>', $key, selected( $current_value, $key), $text);
        }

        print '</select>';

        print '<p class="description">'.esc_html__('Only export orders with a specific status (for example, Completed). ', 'plusminus-woocommerce') .'</p>';

    }

    function should_display_log() {
        $log = isset($_REQUEST['show_log']) ? $_REQUEST['show_log'] : '';
        $log_key = isset($_REQUEST['log_key']) ? $_REQUEST['log_key'] : '';

        if ($log == 'true') {
            print '<div class="pm-settings-panel pm-settings-log">';
            print '<textarea class="pm-log-field">';
            print PlusMinus_WooCommerce::get_log($log_key);
            print '</textarea>';
            print '</div>';
        }
    }

    function orders_export_is_enabled() {
        if (!isset($this->options['allow_orders_export'])) {
            $this->options['allow_orders_export'] = 0;
        }

        return checked(1, $this->options['allow_orders_export'], false);
    }

    function orders_download_url() {
        return get_rest_url(null, 'plusminus-woo/v1/get-orders') . '?token=' . (isset($this->options['export_token']) ? $this->options['export_token'] : '').'&download=true';
    }
}