<?php
require_once '../../../wp-load.php';

$query = new WP_Query(array(
    'post_type' => 'post',
    'posts_per_page' => 10
));

echo "<h2>Imported Posts:</h2><ul>";
foreach($query->posts as $p) {
    $quora_url = get_post_meta($p->ID, '_quora_url', true);
    echo "<li>ID: {$p->ID} | Title: {$p->post_title} | Quora URL: {$quora_url}</li>";
}
echo "</ul>";
