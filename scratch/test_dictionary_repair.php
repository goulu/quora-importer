<?php
require_once __DIR__ . '/../../../../wp-load.php';
require_once __DIR__ . '/../includes/class-quora-importer.php';

$export_dir = '/home/goulu/Documents/develop/quora2wordpress/content';
if ( ! is_dir( $export_dir ) ) {
    die( "Error: Quora export directory not found at $export_dir\n" );
}

$importer = Quora_Importer::get_instance();

// Reflection helpers
$ref_get_post_title = new ReflectionMethod( 'Quora_Importer', 'get_post_title' );
$ref_get_post_title->setAccessible( true );

$ref_parse_quora_date = new ReflectionMethod( 'Quora_Importer', 'parse_quora_date' );
$ref_parse_quora_date->setAccessible( true );

$ref_get_candidate_urls = new ReflectionMethod( 'Quora_Importer', 'get_candidate_urls' );
$ref_get_candidate_urls->setAccessible( true );

$ref_parse_html_file = new ReflectionMethod( 'Quora_Importer', 'parse_html_file' );
$ref_parse_html_file->setAccessible( true );

// Find all index.html files
$directory_iterator = new RecursiveDirectoryIterator( $export_dir );
$iterator = new RecursiveIteratorIterator( $directory_iterator );
$html_files = array();
foreach ( $iterator as $file ) {
    if ( $file->isFile() && $file->getFilename() === 'index.html' ) {
        $html_files[] = $file->getPathname();
    }
}

// Parse all posts from exports
$export_posts = array();
foreach ( $html_files as $html_file ) {
    $parsed = $ref_parse_html_file->invoke( $importer, $html_file );
    if ( ! empty( $parsed ) ) {
        foreach ( $parsed as $p ) {
            $p['dir'] = dirname( $html_file );
            $export_posts[] = $p;
        }
    }
}
echo "Parsed " . count( $export_posts ) . " posts from export HTML files.\n";

// Load all WP posts
$wp_posts = get_posts( array(
    'post_type'      => 'post',
    'post_status'    => 'any',
    'posts_per_page' => -1,
) );
echo "Loaded " . count( $wp_posts ) . " posts from WordPress.\n\n";

function normalize_str( $str ) {
    $s = strtolower( remove_accents( $str ) );
    $s = preg_replace( '/[^a-z0-9]/', '', $s );
    return $s;
}

$matched_count = 0;
$unmatched_wp = array();

foreach ( $wp_posts as $wp_post ) {
    $wp_title_norm = normalize_str( $wp_post->post_title );
    $wp_time = strtotime( $wp_post->post_date );
    $wp_gmt_time = strtotime( $wp_post->post_date_gmt );
    
    $best_match = null;
    $match_reason = '';
    
    foreach ( $export_posts as $ep ) {
        $type = ! empty( $ep['type'] ) ? $ep['type'] : '';
        $ep_title = $ref_get_post_title->invoke( $importer, $ep, $type );
        $ep_title = preg_replace( '/\[\/?math\]/i', '$', $ep_title );
        $ep_title_norm = normalize_str( $ep_title );
        
        $raw_date = ! empty( $ep['Creation time'] ) ? $ep['Creation time'] : '';
        $ep_time = $ref_parse_quora_date->invoke( $importer, $raw_date );
        
        // Match 1: Exact normalized title match
        if ( ! empty( $wp_title_norm ) && $wp_title_norm === $ep_title_norm ) {
            $best_match = $ep;
            $match_reason = 'Exact title match';
            break;
        }
        
        if ( $ep_time ) {
            $diff_local = abs( $wp_time - $ep_time );
            $diff_gmt = abs( $wp_gmt_time - $ep_time );
            $time_close = ( $diff_local <= 43200 || $diff_gmt <= 43200 );
            
            // Match 2: Date is close AND titles have substring relationship
            if ( $time_close && ! empty( $wp_title_norm ) && ! empty( $ep_title_norm ) ) {
                if ( false !== strpos( $ep_title_norm, $wp_title_norm ) || false !== strpos( $wp_title_norm, $ep_title_norm ) ) {
                    $best_match = $ep;
                    $match_reason = 'Time close + Title substring match';
                    break;
                }
            }
            
            // Match 3: Time is extremely close (within 5 minutes)
            if ( $diff_local <= 300 || $diff_gmt <= 300 ) {
                $best_match = $ep;
                $match_reason = 'Time extremely close';
                break;
            }
        }
    }
    
    if ( $best_match ) {
        $matched_count++;
        $ep_type = ! empty( $best_match['type'] ) ? $best_match['type'] : '';
        $ep_title = $ref_get_post_title->invoke( $importer, $best_match, $ep_type );
        echo "WP ID: {$wp_post->ID} | WP Title: '{$wp_post->post_title}'\n";
        echo "  Matched via: {$match_reason}\n";
        echo "  Export Title: '{$ep_title}'\n";
        
        // Reconstruct candidate URLs
        $candidates = $ref_get_candidate_urls->invoke( $importer, $best_match, $best_match['dir'], $wp_post->post_author );
        echo "  Candidates: " . implode( ', ', $candidates ) . "\n\n";
    } else {
        $unmatched_wp[] = $wp_post;
    }
}

echo "Matched $matched_count / " . count( $wp_posts ) . " posts.\n";
echo "Unmatched count: " . count( $unmatched_wp ) . "\n";
if ( ! empty( $unmatched_wp ) ) {
    echo "\nUnmatched WP Posts:\n";
    foreach ( $unmatched_wp as $up ) {
        echo "  ID: {$up->ID} | Title: '{$up->post_title}' | Date: {$up->post_date}\n";
    }
}
