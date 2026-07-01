<?php
require_once __DIR__ . '/../../../../wp-load.php';
$post = get_post(85);
if ($post) {
    echo "ID: " . $post->ID . "\n";
    echo "Title: '" . $post->post_title . "'\n";
    echo "Status: " . $post->post_status . "\n";
    echo "Quora URL meta (_quora_url): " . get_post_meta($post->ID, '_quora_url', true) . "\n";
    echo "Quora URL Status meta (_quora_url_status): " . get_post_meta($post->ID, '_quora_url_status', true) . "\n";
    echo "Quora Candidates meta: " . print_r(get_post_meta($post->ID, '_quora_candidate_urls', true), true) . "\n";
} else {
    echo "Post 85 not found.\n";
}
