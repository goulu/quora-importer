<?php
/**
 * Standalone test runner for Quora Importer scrapers.
 * Execute by visiting: http://drgoulu.local/wp-content/plugins/quora-importer/tests/test-scrapers.php
 */

header('Content-Type: text/plain; charset=utf-8');

define('QUORA_IMPORTER_PATH', dirname(__DIR__) . '/');
define('ABSPATH', QUORA_IMPORTER_PATH);

// Mock WordPress functions
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
    function wp_safe_remote_get( $url, $args = array() ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_USERAGENT, isset($args['user-agent']) ? $args['user-agent'] : 'Mozilla/5.0' );
        curl_setopt( $ch, CURLOPT_TIMEOUT, isset($args['timeout']) ? $args['timeout'] : 10 );
        $body = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        curl_close( $ch );
        return array( 'body' => $body, 'response' => array( 'code' => $code ) );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return isset( $response['body'] ) ? $response['body'] : '';
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return false;
    }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}
if ( ! function_exists( 'remove_accents' ) ) {
    function remove_accents( $string ) {
        $chars = array(
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'A','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','y'=>'y','Ý'=>'Y','ÿ'=>'y'
        );
        return strtr( $string, $chars );
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}
if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = 'default' ) {
        return ( $number === 1 ) ? $single : $plural;
    }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array( 'basedir' => __DIR__ );
    }
}

// Mock Quora_Importer class
class Quora_Importer {
    private static $instance = null;
    private $last_topic_error = '';

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_python_executable() {
        return 'python3';
    }

    public function set_last_topic_error( $error ) {
        $this->last_topic_error = $error;
    }

    public function get_last_topic_error() {
        return $this->last_topic_error;
    }

    public function validate_quora_url( $url ) {
        if ( empty( $url ) ) {
            return false;
        }
        return (bool) filter_var( $url, FILTER_VALIDATE_URL );
    }

    public function quora_slugify( $title, $replace_apostrophes = false ) {
        if ( empty( $title ) ) {
            return '';
        }
        $title = preg_replace( '/\[\/?math\]/i', '', $title );
        if ( $replace_apostrophes ) {
            $title = str_replace( array( "'", '’', '.', '´', '`' ), ' ', $title );
        } else {
            $title = str_replace( array( "'", '’', '.', '´', '`' ), '', $title );
        }
        $title = str_replace( array( '/', '_', '^', '(', ')', '[', ']', '{', '}' ), ' ', $title );
        $slug = preg_replace( '/[^\p{L}\p{N}\s\-]/u', '', $title );
        $slug = preg_replace( '/[\s\-]+/u', '-', $slug );
        $slug = trim( $slug, '-' );
        if ( strlen( $slug ) > 190 ) {
            $slug = mb_strcut( $slug, 0, 190, 'UTF-8' );
        }
        return str_replace( '%2B', '+', rawurlencode( $slug ) );
    }

    public function build_quora_url_with_slug( $post, $type, $title_slug, $profile_slug, $domain, $is_answer ) {
        if ( $is_answer && ! empty( $profile_slug ) && ! empty( $title_slug ) ) {
            return "https://{$domain}/{$title_slug}/answer/{$profile_slug}";
        }
        if ( ! empty( $title_slug ) ) {
            return "https://{$domain}/{$title_slug}";
        }
        return "https://{$domain}";
    }

    public function generate_quora_url( $post, $extracted_dir, $author_id = 0, $replace_apostrophes = false, $force_slugify = false ) {
        if ( ! $force_slugify ) {
            foreach ( array( 'Answer', 'Question', 'Link', 'url', 'Share url', 'Share URL' ) as $key ) {
                if ( ! empty( $post[$key] ) ) {
                    $val = trim( $post[$key] );
                    if ( $this->validate_quora_url( $val ) ) {
                        return $val;
                    }
                }
            }
        }
        $lang = ! empty( $post['Content language'] ) ? strtolower( $post['Content language'] ) : 'français';
        $domain = ( strpos( $lang, 'fran' ) !== false ) ? 'fr.quora.com' : 'www.quora.com';
        
        $profile_slug = 'Dr-Goulu';
        $type = ! empty( $post['type'] ) ? $post['type'] : '';
        $is_answer = ( strpos( strtolower( $type ), 'répondre' ) !== false || strpos( strtolower( $type ), 'answer' ) !== false );
        
        $title = '';
        if ( ! empty( $post['Question'] ) ) {
            $title = $post['Question'];
        } elseif ( ! empty( $post['Title'] ) ) {
            $title = $post['Title'];
        }
        
        $title_slug = $this->quora_slugify( $title, $replace_apostrophes );
        return $this->build_quora_url_with_slug( $post, $type, $title_slug, $profile_slug, $domain, $is_answer );
    }

    public function get_candidate_urls( $post, $extracted_dir, $author_id = 0 ) {
        $direct_url = $this->generate_quora_url( $post, $extracted_dir, $author_id, false, false );
        $url_a = $this->generate_quora_url( $post, $extracted_dir, $author_id, true, true );
        $url_b = $this->generate_quora_url( $post, $extracted_dir, $author_id, false, true );
        
        $urls = array();
        foreach ( array( $direct_url, $url_a, $url_b ) as $base_url ) {
            if ( ! empty( $base_url ) && $this->validate_quora_url( $base_url ) ) {
                if ( ! in_array( $base_url, $urls ) ) $urls[] = $base_url;
                $url_1 = $base_url . '-1';
                if ( ! in_array( $url_1, $urls ) ) $urls[] = $url_1;
                $url_2 = $base_url . '-2';
                if ( ! in_array( $url_2, $urls ) ) $urls[] = $url_2;
            }
        }
        return $urls;
    }
}

// Load extended features code
require_once QUORA_IMPORTER_PATH . 'includes/extended.php';

// Parse index.html file
$index_path = __DIR__ . '/index.html';
if ( ! file_exists( $index_path ) ) {
    die("Error: index.html not found in tests/ directory.\n");
}

echo "=== Parsing tests/index.html ===\n";
$html_content = file_get_contents( $index_path );
$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html_content . '</body></html>' );
libxml_clear_errors();

$h2s = $dom->getElementsByTagName( 'h2' );
$posts = array();
foreach ( $h2s as $h2 ) {
    $type = trim( $h2->textContent );
    if ( ! in_array( $type, array( 'Répondre', 'Answer', 'Brouillon de réponse', 'Answer Draft' ) ) ) {
        continue;
    }
    $post_data = array(
        'type' => $type,
        'Question' => '',
        'Content' => '',
        'Creation time' => '',
        'Content language' => '',
    );
    $curr = $h2->nextSibling;
    while ( $curr && $curr->nodeName !== 'h2' ) {
        if ( $curr->nodeType === 1 && $curr->nodeName === 'div' ) {
            $strongs = $curr->getElementsByTagName( 'strong' );
            if ( $strongs->length > 0 ) {
                $label = trim( rtrim( trim( $strongs->item( 0 )->textContent ), ':' ) );
                $spans = $curr->getElementsByTagName( 'span' );
                if ( $spans->length > 0 ) {
                    $val = trim( $spans->item( 0 )->textContent );
                    if ( $label === 'Question' ) $post_data['Question'] = $val;
                    if ( $label === 'Content' || $label === 'Post content' ) $post_data['Content'] = $val;
                    if ( $label === 'Creation time' || $label === 'Time' ) $post_data['Creation time'] = $val;
                    if ( $label === 'Content language' ) $post_data['Content language'] = $val;
                }
            }
        }
        $curr = $curr->nextSibling;
    }
    $posts[] = $post_data;
    if ( count( $posts ) >= 2 ) break; // Test first 2 posts
}

echo "Found " . count($posts) . " posts to test.\n\n";

$importer = Quora_Importer::get_instance();

foreach ( $posts as $index => $post ) {
    echo "========================================\n";
    echo "TEST POST #" . ($index + 1) . "\n";
    echo "Question: " . $post['Question'] . "\n";
    echo "Type: " . $post['type'] . "\n";
    echo "Language: " . $post['Content language'] . "\n";
    echo "========================================\n";

    // 1) Test URL generation and HTTP status verification
    echo "1. Generating Candidate URLs and testing HTTP status:\n";
    $candidates = $importer->get_candidate_urls( $post, 'Contenu_Dr_Goulu' );
    $successful_url = null;

    foreach ( $candidates as $url ) {
        echo "  Testing: $url ... ";
        $status = Quora_Importer_Extended::test_url_status( $url );
        echo "HTTP Status: $status\n";
        if ( 200 === $status && null === $successful_url ) {
            $successful_url = $url;
        }
    }

    if ( ! $successful_url ) {
        echo "WARNING: No URL returned status 200. Using first candidate for scraper tests.\n";
        $successful_url = isset($candidates[0]) ? $candidates[0] : null;
    }

    if ( ! $successful_url ) {
        echo "ERROR: No candidate URLs generated. Skipping scraper tests.\n\n";
        continue;
    }

    echo "Using target URL: $successful_url\n\n";

    // 2) Test Topic Extraction
    echo "2. Testing Topic Extraction:\n";
    $topics = Quora_Importer_Extended::extract_topics( $successful_url );
    if ( ! empty( $topics ) ) {
        echo "  Successfully extracted " . count($topics) . " topics:\n";
        foreach ( $topics as $t ) {
            echo "    - $t\n";
        }
    } else {
        echo "  FAILED to extract topics. Error details: " . $importer->get_last_topic_error() . "\n";
    }
    echo "\n";

    // 3) Test Comments Extraction
    echo "3. Testing Comments Extraction:\n";
    $comments_res = Quora_Importer_Extended::scrape_comments_from_urls( array( $successful_url ) );
    if ( $comments_res['success'] && ! empty( $comments_res['comments'] ) ) {
        echo "  Successfully extracted " . count($comments_res['comments']) . " comments:\n";
        foreach ( array_slice($comments_res['comments'], 0, 5) as $c ) {
            echo "    Author: " . $c['author'] . "\n";
            echo "    ID: " . $c['id'] . "\n";
            echo "    Nesting: " . $c['nesting'] . " (Parent: " . ($c['parent_id'] ? $c['parent_id'] : 'None') . ")\n";
            echo "    Date: " . $c['date'] . "\n";
            echo "    Text snippet: " . substr(strip_tags($c['text']), 0, 100) . "...\n";
            echo "    ------------------------------------\n";
        }
        if ( count($comments_res['comments']) > 5 ) {
            echo "    ... and " . (count($comments_res['comments']) - 5) . " more comments.\n";
        }
    } else {
        $err = isset($comments_res['error']) ? $comments_res['error'] : (isset($comments_res['warning']) ? $comments_res['warning'] : 'Unknown error');
        echo "  FAILED to extract comments. Error details: $err\n";
    }
    echo "\n";
}

echo "========================================\n";
echo "TEST POST #3 (MOCK COMMENTS URL TEST)\n";
echo "========================================\n";
$mock_url = 'https://fr.quora.com/mock-comments';
echo "Using target URL: $mock_url\n\n";

echo "Testing Comments Extraction:\n";
$comments_res = Quora_Importer_Extended::scrape_comments_from_urls( array( $mock_url ) );
if ( $comments_res['success'] && ! empty( $comments_res['comments'] ) ) {
    echo "  Successfully extracted " . count($comments_res['comments']) . " comments:\n";
    foreach ( $comments_res['comments'] as $c ) {
        echo "    Author: " . $c['author'] . "\n";
        echo "    ID: " . $c['id'] . "\n";
        echo "    Nesting: " . $c['nesting'] . " (Parent: " . ($c['parent_id'] ? $c['parent_id'] : 'None') . ")\n";
        echo "    Date: " . $c['date'] . "\n";
        echo "    Text snippet: " . substr(strip_tags($c['text']), 0, 100) . "...\n";
        echo "    ------------------------------------\n";
    }
} else {
    $err = isset($comments_res['error']) ? $comments_res['error'] : (isset($comments_res['warning']) ? $comments_res['warning'] : 'Unknown error');
    echo "  FAILED to extract comments. Error details: $err\n";
}
echo "\n";

