<?php
/*
Plugin Name: Quora Importer
Plugin URI: https://github.com/goulu/quora-importer
Description: Importez votre blog Quora directement dans WordPress à partir d'une archive ZIP d'exportation ou d'un fichier index.html.
Version: 1.0.0
Author: Philippe Guglielmetti & Antigravity
Author URI: https://www.drgoulu.com
License: GPL2
Text Domain: quora-importer
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'QUORA_IMPORTER_VERSION', '1.0.0' );
define( 'QUORA_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUORA_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

// Load the main importer class
require_once QUORA_IMPORTER_PATH . 'includes/class-quora-importer.php';

// Initialize the plugin
add_action( 'plugins_loaded', array( 'Quora_Importer', 'get_instance' ) );
