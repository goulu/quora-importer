<?php
$json_file = __DIR__ . '/quora_urls.json';
if ( ! file_exists( $json_file ) ) {
    echo "File does not exist: $json_file\n";
    exit;
}
$content = file_get_contents( $json_file );
$decoded = json_decode( $content, true );
$search = 'Boucha';
foreach ( $decoded as $u ) {
    if ( stripos( $u, $search ) !== false ) {
        echo "Found matching URL: $u\n";
    }
}
