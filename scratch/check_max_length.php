<?php
$json_file = __DIR__ . '/quora_urls.json';
$decoded = json_decode( file_get_contents( $json_file ), true );
$lengths = array();
$by_length = array();
foreach ( $decoded as $u ) {
    $parsed = parse_url($u);
    if ( ! empty($parsed['path']) ) {
        $path = trim($parsed['path'], '/');
        $slug = $path;
        if ( preg_match('/^([^\/]+)\/answer\//i', $path, $matches) ) {
            $slug = $matches[1];
        }
        $unencoded = rawurldecode($slug);
        $len = mb_strlen($unencoded, 'UTF-8');
        $lengths[] = $len;
        $by_length[$len][] = $unencoded;
    }
}
rsort($lengths);
echo "Max slug length (unencoded): " . $lengths[0] . " characters\n";
echo "Average slug length: " . (array_sum($lengths)/count($lengths)) . " characters\n";
echo "Top 10 longest slugs:\n";
krsort($by_length);
$count = 0;
foreach ($by_length as $len => $slugs) {
    foreach ($slugs as $s) {
        echo "Length $len: $s\n";
        $count++;
        if ($count >= 10) break 2;
    }
}
