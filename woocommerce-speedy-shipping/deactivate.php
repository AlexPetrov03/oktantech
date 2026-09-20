<?php
global $wpdb;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}speedy_cities" );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}speedy_offices" );