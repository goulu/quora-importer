<?php
/*
Plugin Name: Quora Importer
Plugin URI: https://github.com/goulu/quora-importer
Description: Import your Quora blog directly into WordPress from an export ZIP archive or an index.html file.
Version: 1.4.0
Author: Philippe Guglielmetti & Antigravity
Author URI: https://www.drgoulu.com
License: GPL2
Text Domain: quora-importer
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'QUORA_IMPORTER_VERSION', '1.4.0' );
define( 'QUORA_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUORA_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

// Load translations
function quora_importer_load_textdomain() {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
    load_plugin_textdomain( 'quora-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'quora_importer_load_textdomain' );

// Load the main importer class
require_once QUORA_IMPORTER_PATH . 'includes/class-quora-importer.php';

// Load extended features if present
if ( file_exists( QUORA_IMPORTER_PATH . 'includes/extended.php' ) ) {
    require_once QUORA_IMPORTER_PATH . 'includes/extended.php';
    Quora_Importer_Extended::init();
}

// Initialize the plugin
add_action( 'plugins_loaded', array( 'Quora_Importer', 'get_instance' ) );
