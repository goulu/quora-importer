<?php
/**
 * Standalone test script to re-test URLs from quora-404.log
 */

if ( ! defined( 'ABSPATH' ) ) {
	// If not running in CLI SAPI, exit to prevent direct web/HTTP access.
	if ( php_sapi_name() !== 'cli' ) {
		exit;
	}
}

/**
 * Execute the 404 URL retesting logic.
 */
function quora_importer_retest_404() {
	// Load WordPress
	$wp_load_path = dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/wp-load.php';
	if ( ! file_exists( $wp_load_path ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		die( esc_html( "Error: wp-load.php not found at: $wp_load_path\n" ) );
	}
	require_once $wp_load_path;

	// Load Quora Importer if not already loaded
	if ( ! class_exists( 'Quora_Importer' ) ) {
		require_once dirname( dirname( __FILE__ ) ) . '/includes/class-quora-importer.php';
	}

	// Path to log files in the uploads directory
	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		die( esc_html( "Error: Unable to retrieve WordPress uploads directory: " . $upload_dir['error'] . "\n" ) );
	}

	$log_dir          = $upload_dir['basedir'] . '/';
	$log_file         = $log_dir . 'quora-404.log';
	$updated_log_file = $log_dir . 'quora-404-updated.log';

	if ( ! file_exists( $log_file ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		die( esc_html( "Error: quora-404.log not found at: $log_file\n" ) );
	}

	// Clear updated log file
	file_put_contents( $updated_log_file, '' );

	// Read log file lines
	$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	echo "Loaded " . (int) count( $lines ) . " entries from quora-404.log\n";

	$importer  = Quora_Importer::get_instance();
	$reflector = new ReflectionClass( 'Quora_Importer' );

	$get_candidate_urls_method = $reflector->getMethod( 'get_candidate_urls' );
	$get_candidate_urls_method->setAccessible( true );

	$test_url_http_status_method = $reflector->getMethod( 'test_url_http_status' );
	$test_url_http_status_method->setAccessible( true );

	$fixed_count         = 0;
	$still_failing_count = 0;

	foreach ( $lines as $index => $line ) {
		// Parse line
		if ( ! preg_match( '/^\[[^\]]+\] Title: "(.*?)" \| Tested URL: (.*?)$/', $line, $matches ) ) {
			continue;
		}

		$title        = $matches[1];
		$original_url = $matches[2];

		echo "\n[" . (int) ($index + 1) . "] Testing: \"" . esc_html( $title ) . "\"\n";
		echo "  Original URL: " . esc_url( $original_url ) . "\n";

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
			echo "  Testing candidate: " . esc_url( $candidate_url ) . " ... ";
			$status_code = $test_url_http_status_method->invokeArgs( $importer, array( $candidate_url ) );
			echo "Status: " . (int) $status_code . "\n";

			if ( $status_code === 200 || ( $status_code > 0 && $status_code !== 404 ) ) {
				$valid_url = $candidate_url;
				break;
			}
		}

		if ( ! empty( $valid_url ) ) {
			echo "  --> FIXED! Found valid URL: " . esc_url( $valid_url ) . "\n";
			$fixed_count++;
		} else {
			echo "  --> STILL FAILING.\n";
			$still_failing_count++;

			// Write to updated log file
			$timestamp       = gmdate( 'Y-m-d H:i:s' );
			$first_candidate = isset( $candidates[0] ) ? $candidates[0] : $original_url;
			$log_message     = sprintf( "[%s] Title: \"%s\" | Tested URL: %s\n", $timestamp, $title, $first_candidate );
			file_put_contents( $updated_log_file, $log_message, FILE_APPEND );
		}
	}

	echo "\nSummary:\n";
	echo "Total processed: " . (int) ($fixed_count + $still_failing_count) . "\n";
	echo "Fixed: " . (int) $fixed_count . "\n";
	echo "Still failing: " . (int) $still_failing_count . " (logged to quora-404-updated.log)\n";
}

quora_importer_retest_404();
