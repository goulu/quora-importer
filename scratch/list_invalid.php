<?php
require_once __DIR__ . '/../../../../wp-load.php';
$posts = get_posts([
    'post_type' => 'post',
    'post_status' => 'any',
    'posts_per_page' => -1,
    'meta_query' => [
        'relation' => 'OR',
        [
            'key' => '_quora_url_status',
            'value' => 'valid',
            'compare' => '!='
        ],
        [
            'key' => '_quora_url_status',
            'compare' => 'NOT EXISTS'
        ]
    ]
]);
foreach ($posts as $p) {
    echo "ID: " . $p->ID . " | Title: " . $p->post_title . " | URL: " . get_post_meta($p->ID, '_quora_url', true) . "\n";
}
