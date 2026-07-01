<?php
require_once __DIR__ . '/../../../../wp-load.php';
require_once __DIR__ . '/../includes/class-quora-importer.php';
$importer = Quora_Importer::get_instance();
$post = get_post(62);
$ref = new ReflectionMethod('Quora_Importer', 'quora_slugify');
$ref->setAccessible(true);

echo "Title: " . $post->post_title . "\n";
echo "Slug A: " . $ref->invoke($importer, $post->post_title, true) . "\n";
echo "Slug B: " . $ref->invoke($importer, $post->post_title, false) . "\n";
