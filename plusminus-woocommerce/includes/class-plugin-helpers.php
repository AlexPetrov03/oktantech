<?php

if (!defined( 'ABSPATH')) {
    die;
}

/**
 * PlusMinus_WooCommerce_Helpers
 */
class PlusMinus_WooCommerce_Helpers {
    
        
    /**
     * Reading plugin option value
     *
     * @param  string $param
     * @return mixed
     */
    public static function option_value($param = '') {
        $options = PlusMinus_WooCommerce_Options::instance()->get_options();
        return self::object_value($options, $param);
    }
    
    /**
     * Reading plugin option number value
     *
     * @param  string $param
     * @return int
     */
    public static function option_number_value($param = '') {
        $options = PlusMinus_WooCommerce_Options::instance()->get_options();
        return self::object_number_value($options, $param);
    }
    
    /**
     * Return a value from an object or array based on the key. 
     *
     * @param  object|array $object
     * @param  string $param
     * @return mixed
     */
    public static function object_value($object, $param) {
        $r = isset($object[$param]) ? $object[$param] : '';

        return $r;
    }
    
    /**
     * Return a number value from an object or array based on the key. 
     *
     * @param  object|array $object
     * @param  string $param
     * @return int
     */
    public static function object_number_value($object, $param = '') {
        return absint(self::object_value($object, $param));
    }
    
    /**
     * Check if the key value in the object or array is empty.
     *
     * @param  object|array $object
     * @param  string $param
     * @return boolean
     */
    public static function is_empty_object_value($object, $param) {
        $value = self::object_value($object, $param);

        return is_empty($value);
    }
    
    /**
     * Generate the price and quantity update setup
     *
     * @param  array $object Current saved settings
     * @return array<string|string>
     */
    public static function get_update_setup($object) {
        $result = array('update_quantity' => false, 'update_price' => false, 'price_parameter' => 'retailPrice');

        if (isset($object['update_price']) && $object['update_price'] == 1) {
            $result['update_price'] = true;
        }

        if (isset($object['update_quantity']) && $object['update_quantity'] == 1) {
            $result['update_quantity'] = true;
        }

        if (isset($object['price_parameter']) && !empty($object['price_parameter'])) {
            $result['price_parameter'] = $object['price_parameter'];
        }

        return $result;
    }
    
    /**
     * Convert the current saved settings period to the related cron job interval.
     *
     * @param  string $period
     * @return string
     */
    public static function settings_period_to_cron_interval($period) {
        $map = array(
            '5m' => 'every5min',
            '10m' => 'every10min',
            '15m' => 'every15min',
            '30m' => 'every30min',
            '1h' => 'every1h',
            '3h' => 'every3h',
            '6h' => 'every6h',
            '12h' => 'every12h',
            '24h' => 'every24h',
        );

        return isset($map[$period]) ? $map[$period] : '';
    }
}