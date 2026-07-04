<?php
/**
 * Standalone test script to re-test URLs from quora-404.log
 */

// Load WordPress
$wp_load_path = dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/wp-load.php';
if ( ! file_exists( $wp_load_path ) ) {
    die( "Error: wp-load.php not found at: $wp_load_path\n" );
}
require_once $wp_load_path;

// Path to log files
$plugin_dir = dirname( dirname( __FILE__ ) ) . '/';
$log_file = $plugin_dir . 'quora-404.log';
$updated_log_file = $plugin_dir . 'quora-404-updated.log';

if ( ! file_exists( $log_file ) ) {
    die( "Error: quora-404.log not found at: $log_file\n" );
}

// Clear updated log file
file_put_contents( $updated_log_file, '' );

// Read log file lines
$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
echo "Loaded " . count( $lines ) . " entries from quora-404.log\n";

$importer = Quora_Importer::get_instance();
$reflector = new ReflectionClass( 'Quora_Importer' );

$get_candidate_urls_method = $reflector->getMethod( 'get_candidate_urls' );
$get_candidate_urls_method->setAccessible( true );

$test_url_http_status_method = $reflector->getMethod( 'test_url_http_status' );
$test_url_http_status_method->setAccessible( true );

$fixed_count = 0;
$still_failing_count = 0;

foreach ( $lines as $index => $line ) {
    // Parse line
    if ( ! preg_match( '/^\[[^\]]+\] Title: "(.*?)" \| Tested URL: (.*?)$/', $line, $matches ) ) {
        continue;
    }
    
    $title = $matches[1];
    $original_url = $matches[2];
    
    echo "\n[" . ($index + 1) . "] Testing: \"$title\"\n";
    echo "  Original URL: $original_url\n";
    
    // Construct fake post data
    $is_answer = ( strpos( $original_url, '/answer/' ) !== false );
    $post_data = array(
        'Question' => $title,
        'Title'    => $title,
        'type'     => $is_answer ? 'Answer' : 'Question',
        'url'      => $original_url,
    );
    
    // Extracted directory path to fallback to Dr-Goulu if no author nickname is found
    $extracted_dir = '/home/goulu/Documents/develop/quora2wordpress/content/Contenu_Dr-Goulu';
    
    // Get candidate URLs using reflection
    $candidates = $get_candidate_urls_method->invokeArgs( $importer, array( $post_data, $extracted_dir, 0 ) );
    
    $valid_url = '';
    foreach ( $candidates as $candidate_url ) {
        echo "  Testing candidate: $candidate_url ... ";
        $status_code = $test_url_http_status_method->invokeArgs( $importer, array( $candidate_url ) );
        echo "Status: $status_code\n";
        
        if ( $status_code === 200 || ( $status_code > 0 && $status_code !== 404 ) ) {
            $valid_url = $candidate_url;
            break;
        }
    }
    
    if ( ! empty( $valid_url ) ) {
        echo "  --> FIXED! Found valid URL: $valid_url\n";
        $fixed_count++;
    } else {
        echo "  --> STILL FAILING.\n";
        $still_failing_count++;
        
        // Write to updated log file
        $timestamp = date( 'Y-m-d H:i:s' );
        $first_candidate = isset( $candidates[0] ) ? $candidates[0] : $original_url;
        $log_message = sprintf( "[%s] Title: \"%s\" | Tested URL: %s\n", $timestamp, $title, $first_candidate );
        file_put_contents( $updated_log_file, $log_message, FILE_APPEND );
    }
}

echo "\nSummary:\n";
echo "Total processed: " . ($fixed_count + $still_failing_count) . "\n";
echo "Fixed: $fixed_count\n";
echo "Still failing: $still_failing_count (logged to quora-404-updated.log)\n";
