<?php
/**
 * Standalone script to process existing Quora posts in the database,
 * fix their titles/contents, and reconstruct/verify their Quora URLs.
 */

// Define WordPress load path
$wp_load = __DIR__ . '/../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    die( "Error: wp-load.php not found at $wp_load\n" );
}

// Bootstrap WordPress
define( 'WP_USE_THEMES', false );
require_once $wp_load;

// Verify class exists
$class_file = __DIR__ . '/../includes/class-quora-importer.php';
if ( ! file_exists( $class_file ) ) {
    die( "Error: class-quora-importer.php not found at $class_file\n" );
}
require_once $class_file;

if ( ! class_exists( 'Quora_Importer' ) ) {
    die( "Error: Quora_Importer class could not be loaded.\n" );
}

$importer = Quora_Importer::get_instance();
$importer->run_database_updates();


