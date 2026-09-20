<?php

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}speedy_cities ( 
    `id` MEDIUMINT UNSIGNED NULL UNIQUE ,
    `name` VARCHAR(255) NULL ,
    `post_code` VARCHAR(255) NULL ,
    `region` VARCHAR(255) NULL ,
    `type` VARCHAR(10) NULL ,
    INDEX (`name`)
)";

dbDelta( $sql );

$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}speedy_offices (
    `id` MEDIUMINT UNSIGNED NULL UNIQUE ,
    `name` VARCHAR(512) NULL ,
    `city` VARCHAR(255) NULL ,
    `address` VARCHAR(255) NULL ,
    `latitude` VARCHAR(255) NULL ,
    `longitude` VARCHAR(255) NULL ,
    `office_code` VARCHAR(10) NULL ,
    `post_code` VARCHAR(5) NULL ,
    `address_details` TEXT NULL ,
    `office_details` TEXT NULL ,
    `phone` VARCHAR(255) NULL ,
    `email` VARCHAR(255) NULL
)";

dbDelta( $sql );