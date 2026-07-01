<?php
$wp_load = __DIR__ . '/../../../../wp-load.php';
require_once $wp_load;

$json_file = __DIR__ . '/quora_urls.json';
$decoded = json_decode( file_get_contents( $json_file ), true );
$dict_urls = array();
foreach ( $decoded as $u ) {
    $dict_urls[strtolower($u)] = $u;
}

$wp_posts = get_posts( array(
    'post_type'      => 'post',
    'post_status'    => 'any',
    'posts_per_page' => -1,
) );

echo "Total WordPress posts: " . count($wp_posts) . "\n";
$matched_count = 0;
foreach ( $wp_posts as $post ) {
    $candidates = get_post_meta( $post->ID, '_quora_candidate_urls', true );
    if ( ! is_array($candidates) ) {
        $candidates = array();
    }
    $existing = get_post_meta( $post->ID, '_quora_url', true );
    if ( $existing ) {
        $candidates[] = $existing;
    }
    $candidates = array_unique(array_filter($candidates));
    
    $found = false;
    foreach ( $candidates as $cand ) {
        if ( isset($dict_urls[strtolower($cand)]) ) {
            $found = true;
            $matched_count++;
            echo "Post ID {$post->ID} ('{$post->post_title}'): MATCHED {$cand}\n";
            break;
        }
    }
    if ( ! $found ) {
        echo "Post ID {$post->ID} ('{$post->post_title}'): NOT MATCHED (Candidates: " . implode(', ', $candidates) . ")\n";
    }
}
echo "Total matched via dictionary: $matched_count / " . count($wp_posts) . "\n";
