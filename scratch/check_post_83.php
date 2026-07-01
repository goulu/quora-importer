<?php
require_once __DIR__ . '/../../../../wp-load.php';
$post = get_post(83);
if ($post) {
    echo "Title: " . $post->post_title . "\n";
    echo "Content:\n" . $post->post_content . "\n";
    echo "Meta _quora_url: " . get_post_meta(83, '_quora_url', true) . "\n";
    echo "Meta _quora_url_status: " . get_post_meta(83, '_quora_url_status', true) . "\n";
} else {
    echo "Post 83 not found.\n";
}
