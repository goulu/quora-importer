<?php
require_once __DIR__ . '/../../../../wp-load.php';

$posts = get_posts([
    'post_type' => 'post',
    'post_status' => 'any',
    's' => "énergie n'est pas une chose",
    'posts_per_page' => -1
]);

echo "Found " . count($posts) . " posts matching search term.\n";
foreach ($posts as $p) {
    echo "ID: {$p->ID} | Title: '{$p->post_title}' | Status: {$p->post_status}\n";
}
