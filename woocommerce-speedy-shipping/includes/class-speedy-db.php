<?php

class Speedy_DB {
    public static function get_city_autocomplete( $city ) {
        global $wpdb;

        $city = $wpdb->_escape($city);

        $table = $wpdb->prefix . 'speedy_cities';

        $results = $wpdb->get_results( "SELECT * FROM $table WHERE `name` LIKE '%$city%'" );

        return $results;
    }

    public static function get_cities_by_region( $region ) {
        global $wpdb;

        $region = $wpdb->_escape($region);

        $table = $wpdb->prefix . 'speedy_cities';

        $prepared = $wpdb->prepare( "SELECT * FROM $table WHERE `region` = %s ORDER BY `name`", [ $region ]);

        $results = $wpdb->get_results( $prepared );

        return $results;
    }

    public static function get_city_id( $name ) {
        global $wpdb;

        $name = $wpdb->_escape($name);

        $table = $wpdb->prefix . 'speedy_cities';

        $result = $wpdb->get_row( "SELECT * FROM $table WHERE `name` = '$name'");

        if( ! $result ) return null;

        return $result->id;
    }

    public static function get_city_by_id( $id ) {
        global $wpdb;

        $id = $wpdb->_escape($id);

        $table = $wpdb->prefix . 'speedy_cities';

        $result = $wpdb->get_row( "SELECT * FROM $table WHERE id = $id" );

        return $result;
    }

    public static function get_offices_by_city( $city ) {
        global $wpdb;

        $city = $wpdb->_escape($city);

        $table   = $wpdb->prefix . 'speedy_offices';

        $results = $wpdb->get_results( "SELECT id, name, address FROM $table WHERE city = '$city'" );

        return $results;
    }

    public static function get_office_by_id( $id ) {
        global $wpdb;

        if($id == '') return;

        $id = $wpdb->_escape($id);

        $table = $wpdb->prefix . 'speedy_offices';

        $result = $wpdb->get_row( "SELECT * FROM $table WHERE id = $id" );

        return $result;
    }

    public static function get_office_by_city_id( $city_id ) {
        $city = self::get_city_by_id( $city_id );

        $offices = self::get_offices_by_city( $city->name );
        
        return $offices;
    }
}