<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Quora_Importer {
    
    private static $instance = null;
    private $last_topic_error = '';
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action( 'admin_init', array( $this, 'register_wordpress_importer' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_assets' ) );
        
        // AJAX endpoints
        add_action( 'wp_ajax_quora_upload_file', array( $this, 'ajax_upload_file' ) );
        add_action( 'wp_ajax_quora_import_item', array( $this, 'ajax_import_item' ) );
        add_action( 'wp_ajax_quora_import_cleanup', array( $this, 'ajax_import_cleanup' ) );
    }
    
    /**
     * Register the importer in Outils > Importer
     */
    public function register_wordpress_importer() {
        register_importer(
            'quora',
            'Quora',
            __( 'Import a Quora blog from a ZIP or index.html file.', 'quora-importer' ),
            array( $this, 'dispatch' )
        );
    }
    
    /**
     * Enqueue CSS and JS for the importer page
     */
    public function admin_enqueue_assets( $hook ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter used only to target enqueuing assets on the specific admin page.
        if ( 'admin.php' === $hook && isset( $_GET['import'] ) && 'quora' === $_GET['import'] ) {
            wp_enqueue_style( 'quora-importer-style', QUORA_IMPORTER_URL . 'assets/css/style.css', array(), QUORA_IMPORTER_VERSION );
            wp_enqueue_script( 'quora-importer-script', QUORA_IMPORTER_URL . 'assets/js/import.js', array( 'jquery' ), QUORA_IMPORTER_VERSION, true );
            
            wp_localize_script( 'quora-importer-script', 'quoraImporter', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'quora-import-nonce' ),
                'strings'  => array(
                    'error_upload'        => __( 'Error uploading file.', 'quora-importer' ),
                    'error_parse'         => __( 'Error parsing file.', 'quora-importer' ),
                    'importing'           => __( 'Importing...', 'quora-importer' ),
                    'completed'           => __( 'Import completed successfully!', 'quora-importer' ),
                    'uploading'           => __( 'Uploading...', 'quora-importer' ),
                    'processing_zip'      => __( 'Extracting archive and parsing content...', 'quora-importer' ),
                    'select_valid_file'   => __( 'Please select a valid .zip or .html file.', 'quora-importer' ),
                    // translators: %d: number of posts.
                    'posts_found'         => __( 'We found <strong>%d posts</strong> in your export file.', 'quora-importer' ),
                    'images_found'        => __( ' Associated images were detected and will be imported.', 'quora-importer' ),
                    'no_images_found'     => __( ' No image folder detected (text import only).', 'quora-importer' ),
                    // translators: %s: author name.
                    'suggested_author'    => __( 'Suggested author "%s" pre-selected.', 'quora-importer' ),
                    'select_content_type' => __( 'Please select at least one content type to import.', 'quora-importer' ),
                    'abort_confirm'       => __( 'Are you sure you want to stop the current import?', 'quora-importer' ),
                    'aborting'            => __( 'Stopping...', 'quora-importer' ),
                    // translators: %d: number of posts.
                    'import_started'      => __( 'Starting import of %d posts...', 'quora-importer' ),
                    'import_aborted'      => __( 'Import stopped by user.', 'quora-importer' ),
                    // translators: 1: post index, 2: post title, 3: skip reason.
                    'skipped_log'         => __( '[SKIPPED] #%1$d: "%2$s" - %3$s', 'quora-importer' ),
                    // translators: 1: post index, 2: error message.
                    'error_log'           => __( '[ERROR] #%1$d: %2$s', 'quora-importer' ),
                    // translators: %d: post index.
                    'network_error'       => __( '[ERROR] Network failure during import of post #%d. Retrying...', 'quora-importer' ),
                    // translators: 1: number of posts imported, 2: number of posts skipped.
                    'import_finished'     => __( 'Import finished! %1$d posts imported, %2$d skipped. Cleaning up...', 'quora-importer' ),
                    'live'                => __( 'Live', 'quora-importer' ),
                    'finished'            => __( 'Finished', 'quora-importer' ),
                    'stop_import'         => __( 'Stop Import', 'quora-importer' ),
                )
            ) );
        }
    }
    
    /**
     * Main route dispatcher for the importer page
     */
    public function dispatch() {
        $this->render_header();
        $this->render_body();
        $this->render_footer();
    }
    
    private function render_header() {
        ?>
        <div class="wrap quora-importer-wrap">
            <header class="quora-importer-header">
                <div class="quora-logo-badge">Q</div>
                <h1><?php esc_html_e( 'Quora Blog Importer', 'quora-importer' ); ?></h1>
                <p class="description"><?php esc_html_e( 'Easily migrate your Quora answers, drafts, and space posts to WordPress.', 'quora-importer' ); ?></p>
            </header>
            
            <div class="quora-importer-card" id="quora-importer-container">
        <?php
    }
    
    private function render_footer() {
        ?>
            </div> <!-- .quora-importer-card -->
            <footer class="quora-importer-footer-note">
                <p><?php
                // translators: %s: plugin version.
                echo wp_kses_post( sprintf( __( 'Quora Importer Plugin &bull; Version %s &bull; By Philippe Guglielmetti & Antigravity', 'quora-importer' ), esc_html( QUORA_IMPORTER_VERSION ) ) );
                ?></p>
            </footer>
        </div> <!-- .wrap -->
        <?php
    }
    
    private function render_body() {
        ?>
        <!-- STEP 1: UPLOAD SCREEN -->
        <div id="quora-step-upload" class="quora-step active">
            <div class="quora-dropzone" id="quora-dropzone">
                <div class="quora-dropzone-inner">
                    <span class="dashicons dashicons-upload quora-upload-icon"></span>
                    <h3><?php esc_html_e( 'Upload your export file', 'quora-importer' ); ?></h3>
                    <p><?php esc_html_e( 'Drag & drop your Quora export ZIP file (or index.html) here, or click to browse.', 'quora-importer' ); ?></p>
                    <button class="button button-primary button-hero quora-browse-btn" type="button"><?php esc_html_e( 'Select File', 'quora-importer' ); ?></button>
                    <input type="file" id="quora-file-input" accept=".zip,.html" style="display: none;" />
                </div>
            </div>
            
            <div class="quora-help-card">
                <h4><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'How to get your Quora export file?', 'quora-importer' ); ?></h4>
                <ol>
                    <li><?php echo wp_kses_post( __( 'Log in to Quora and go to your <strong>Account Settings</strong>.', 'quora-importer' ) ); ?></li>
                    <li><?php echo wp_kses_post( __( 'Under the <strong>Privacy</strong> tab, click <strong>Download your data (Request Archive)</strong>.', 'quora-importer' ) ); ?></li>
                    <li><?php esc_html_e( 'You will receive an email from Quora containing a link to download your ZIP archive.', 'quora-importer' ); ?></li>
                    <li><?php esc_html_e( 'Upload this ZIP archive directly here to preserve all your answers and images!', 'quora-importer' ); ?></li>
                </ol>
            </div>
        </div>
        
        <!-- LOADING SPINNER / STATUS -->
        <div id="quora-step-loading" class="quora-step">
            <div class="quora-loading-spinner">
                <div class="quora-spinner-ring"></div>
                <h3 id="quora-loading-message"><?php esc_html_e( 'Uploading...', 'quora-importer' ); ?></h3>
                <p id="quora-loading-subtext"><?php esc_html_e( 'Please wait while the file is being processed.', 'quora-importer' ); ?></p>
            </div>
        </div>

        <!-- STEP 2: CONFIGURATION OPTIONS SCREEN -->
        <div id="quora-step-options" class="quora-step">
            <h3 class="step-title"><?php esc_html_e( 'Import Configuration', 'quora-importer' ); ?></h3>
            <p class="step-intro" id="quora-import-stats-summary"></p>
            
            <form id="quora-import-options-form">
                <input type="hidden" id="quora-session-id" name="session_id" value="" />
                
                <div class="quora-form-section">
                    <h4><?php esc_html_e( '1. Post Settings', 'quora-importer' ); ?></h4>
                    <div class="quora-form-row">
                        <label for="quora-post-author"><?php esc_html_e( 'Author for imported posts:', 'quora-importer' ); ?></label>
                        <select name="author_id" id="quora-post-author">
                            <?php 
                            $users = get_users();
                            $current_user_id = get_current_user_id();
                            foreach ( $users as $user ) {
                                $nickname = get_user_meta( $user->ID, 'nickname', true );
                                $display_text = ! empty( $nickname ) ? $nickname : $user->display_name;
                                ?>
                                <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $user->ID, $current_user_id ); ?>>
                                    <?php echo esc_html( $display_text ); ?>
                                </option>
                                <?php
                            }
                            ?>
                        </select>
                    </div>
                    <div class="quora-form-row">
                        <label for="quora-min-chars-publish"><?php esc_html_e( 'Publish if text is longer than:', 'quora-importer' ); ?></label>
                        <div>
                            <input type="number" id="quora-min-chars-publish" name="min_chars_publish" value="500" min="0" />
                            <span class="help-desc" style="display: block; margin-top: 4px;"><?php esc_html_e( 'characters (shorter published originals, or drafts, will be imported as Draft).', 'quora-importer' ); ?></span>
                        </div>
                    </div>
                    
                    <div class="quora-form-row">
                        <label for="quora-link-position"><?php esc_html_e( 'Link to Quora:', 'quora-importer' ); ?></label>
                        <select name="link_position" id="quora-link-position">
                            <option value="none" selected><?php esc_html_e( 'No', 'quora-importer' ); ?></option>
                            <option value="top"><?php esc_html_e( 'At the top of the post', 'quora-importer' ); ?></option>
                            <option value="bottom"><?php esc_html_e( 'At the bottom of the post', 'quora-importer' ); ?></option>
                        </select>
                    </div>
                    
                    <div class="quora-form-row" id="quora-link-template-row" style="display: none;">
                        <label for="quora-link-template"><?php esc_html_e( 'Link Template (HTML):', 'quora-importer' ); ?></label>
                        <input type="text" id="quora-link-template" name="link_template" value='<a href="$link$" target="_blank">voir sur Quora </a >' style="width: 100%; max-width: 400px;" />
                    </div>
                </div>
                
                <div class="quora-form-section">
                    <h4><?php esc_html_e( '2. Content Types to Import', 'quora-importer' ); ?></h4>
                    <div id="quora-content-types-checkboxes" class="quora-checkbox-grid">
                        <!-- Filled dynamically by JavaScript -->
                    </div>
                </div>
                
                <div class="quora-form-section">
                    <h4><?php esc_html_e( '3. Media & Images', 'quora-importer' ); ?></h4>
                    <div class="quora-form-row checkbox-row">
                        <input type="checkbox" name="import_images" id="quora-import-images" value="1" checked />
                        <label for="quora-import-images">
                            <strong><?php esc_html_e( 'Upload images to the WordPress Media Library', 'quora-importer' ); ?></strong>
                            <span class="help-desc"><?php esc_html_e( 'Extracts local images from the ZIP archive and inserts them cleanly into your media library.', 'quora-importer' ); ?></span>
                        </label>
                    </div>
                    
                    <div class="quora-form-row checkbox-row">
                        <input type="checkbox" name="set_featured" id="quora-set-featured" value="1" checked />
                        <label for="quora-set-featured">
                            <strong><?php esc_html_e( 'Set first image as featured image', 'quora-importer' ); ?></strong>
                            <span class="help-desc"><?php esc_html_e( 'Automatically assigns the first image found in the post as the thumbnail.', 'quora-importer' ); ?></span>
                        </label>
                    </div>
                </div>
                
                <div class="quora-form-section">
                    <h4><?php esc_html_e( '4. Tag Settings', 'quora-importer' ); ?></h4>
                    <div class="quora-form-row checkbox-row">
                        <input type="checkbox" name="extract_topics" id="quora-extract-topics" value="1" />
                        <label for="quora-extract-topics">
                            <strong><?php esc_html_e( 'Extract Quora topic labels', 'quora-importer' ); ?></strong>
                            <span class="help-desc"><?php esc_html_e( 'Visits the corresponding Quora link for each post to extract its original topic tags (e.g. "Sociologie", "Exemples") and apply them as WordPress tags.', 'quora-importer' ); ?></span>
                        </label>
                    </div>
                </div>
                
                <div class="quora-form-actions">
                    <button type="button" class="button button-secondary button-large" id="quora-cancel-to-upload"><?php esc_html_e( 'Cancel', 'quora-importer' ); ?></button>
                    <button type="submit" class="button button-primary button-large" id="quora-submit-import"><?php esc_html_e( 'Start Import', 'quora-importer' ); ?></button>
                </div>
            </form>
        </div>
        
        <!-- STEP 3: PROGRESS SCREEN -->
        <div id="quora-step-progress" class="quora-step">
            <h3 class="step-title" id="quora-progress-title"><?php esc_html_e( 'Importing...', 'quora-importer' ); ?></h3>
            
            <!-- Running status elements -->
            <div id="quora-progress-running-section">
                <div class="quora-progress-wrapper">
                    <div class="quora-progress-bar-container">
                        <div class="quora-progress-bar" id="quora-import-progress-bar" style="width: 0%;"></div>
                    </div>
                    <div class="quora-progress-stats">
                        <span id="quora-progress-percentage">0%</span>
                        <span id="quora-progress-fraction">0 / 0</span>
                    </div>
                </div>
            </div>
            
            <!-- Summary status elements (shown when finished) -->
            <div id="quora-progress-finished-section" style="display: none;">
                <div class="quora-summary-success-icon" style="margin: 10px 0 20px 0; text-align: center;">
                    <span class="dashicons dashicons-saved" style="font-size: 60px; width: 60px; height: 60px;"></span>
                </div>
                <p class="step-intro" style="text-align: center; margin-bottom: 20px;"><?php esc_html_e( 'Your Quora blog has been successfully imported. Here is the summary:', 'quora-importer' ); ?></p>
                
                <div class="quora-summary-stats-grid" style="margin-bottom: 25px;">
                    <div class="summary-stat-card">
                        <span class="stat-num" id="summary-stat-imported">0</span>
                        <span class="stat-label"><?php esc_html_e( 'Imported Posts', 'quora-importer' ); ?></span>
                    </div>
                    <div class="summary-stat-card">
                        <span class="stat-num" id="summary-stat-skipped">0</span>
                        <span class="stat-label"><?php esc_html_e( 'Skipped Posts', 'quora-importer' ); ?></span>
                    </div>
                    <div class="summary-stat-card">
                        <span class="stat-num" id="summary-stat-images">0</span>
                        <span class="stat-label"><?php esc_html_e( 'Uploaded Images', 'quora-importer' ); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="quora-console-log-header">
                <span><?php esc_html_e( 'Import Log', 'quora-importer' ); ?></span>
                <span class="status-indicator live pulsing" id="quora-log-status-indicator"><?php esc_html_e( 'Live', 'quora-importer' ); ?></span>
            </div>
            <div class="quora-console-log" id="quora-console-log">
                <!-- Dynamically populated by AJAX logs -->
            </div>
            
            <div class="quora-form-actions" id="quora-progress-actions">
                <button type="button" class="button button-link-delete" id="quora-abort-import"><?php esc_html_e( 'Stop Import', 'quora-importer' ); ?></button>
            </div>
            
            <div class="quora-form-actions" id="quora-finished-actions" style="display: none; justify-content: center; gap: 15px; margin-top: 20px;">
                <a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="button button-primary button-large"><?php esc_html_e( 'View All Posts', 'quora-importer' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?import=quora' ) ); ?>" class="button button-secondary button-large"><?php esc_html_e( 'Import Another File', 'quora-importer' ); ?></a>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX action to upload and extract ZIP / HTML
     */
    public function ajax_upload_file() {
        check_ajax_referer( 'quora-import-nonce', 'nonce' );
        
        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'quora-importer' ) ) );
        }
        
        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No file received.', 'quora-importer' ) ) );
        }
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is validated below via checking file extension and using WordPress APIs.
        $file = $_FILES['file'];
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        
        if ( ! in_array( $ext, array( 'zip', 'html' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid file extension. Please use .zip or .html.', 'quora-importer' ) ) );
        }
        
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // Setup temp folder inside WordPress uploads
        $upload_dir = wp_upload_dir();
        $temp_root = $upload_dir['basedir'] . '/quora-importer-temp';
        
        if ( ! $wp_filesystem->exists( $temp_root ) ) {
            wp_mkdir_p( $temp_root );
            // Security: add index.php
            $wp_filesystem->put_contents( $temp_root . '/index.php', '<?php // Silence is golden' );
        }
        
        $session_id = uniqid( 'session_' );
        $session_dir = $temp_root . '/' . $session_id;
        
        if ( ! wp_mkdir_p( $session_dir ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not create temporary directory.', 'quora-importer' ) ) );
        }
        
        // Copy uploaded file to session dir
        $uploaded_file_path = $session_dir . '/' . basename( $file['name'] );
        if ( ! $wp_filesystem->copy( $file['tmp_name'], $uploaded_file_path ) ) {
            wp_send_json_error( array( 'message' => __( 'Failed to move uploaded file.', 'quora-importer' ) ) );
        }
        
        $has_images = false;
        $extracted_dir = $session_dir;
        $index_html_path = '';
        
        if ( 'zip' === $ext ) {
            // Unzip the file
            $unzip_result = unzip_file( $uploaded_file_path, $session_dir );
            
            // Delete the zip to save space
            wp_delete_file( $uploaded_file_path );
            
            if ( is_wp_error( $unzip_result ) ) {
                $this->recursive_rmdir( $session_dir );
                // translators: %s: error message.
                wp_send_json_error( array( 'message' => __( 'ZIP extraction failed: ', 'quora-importer' ) . $unzip_result->get_error_message() ) );
            }
            
            // Search for index.html recursively in extracted folder
            $index_html_path = $this->find_file_recursive( $session_dir, 'index.html' );
            if ( $index_html_path ) {
                $extracted_dir = dirname( $index_html_path );
                if ( $wp_filesystem->exists( $extracted_dir . '/images' ) && $wp_filesystem->is_dir( $extracted_dir . '/images' ) ) {
                    $has_images = true;
                }
            } else {
                $this->recursive_rmdir( $session_dir );
                wp_send_json_error( array( 'message' => __( 'Could not find index.html inside the ZIP archive.', 'quora-importer' ) ) );
            }
        } else {
            $index_html_path = $uploaded_file_path;
        }
        
        // Parse the index.html
        $posts = $this->parse_html_file( $index_html_path );
        if ( false === $posts || empty( $posts ) ) {
            $this->recursive_rmdir( $session_dir );
            wp_send_json_error( array( 'message' => __( 'No eligible posts found inside index.html.', 'quora-importer' ) ) );
        }
        
        // Get statistics
        $types_count = array();
        foreach ( $posts as $post ) {
            $type = $post['type'];
            if ( ! isset( $types_count[$type] ) ) {
                $types_count[$type] = 0;
            }
            $types_count[$type]++;
        }
        
        // Try to guess default author name from folder
        $guessed_author = $this->extract_author_from_folder( basename( $extracted_dir ) );
        
        // Save manifest JSON
        $manifest = array(
            'session_id'    => $session_id,
            'extracted_dir' => $extracted_dir,
            'has_images'    => $has_images,
            'posts'         => $posts
        );
        
        $wp_filesystem->put_contents( $session_dir . '/manifest.json', wp_json_encode( $manifest ) );
        
        wp_send_json_success( array(
            'session_id'     => $session_id,
            'total_posts'    => count( $posts ),
            'post_types'     => $types_count,
            'guessed_author' => $guessed_author,
            'has_images'     => $has_images
        ) );
    }
    
    /**
     * AJAX action to import a single post item by index
     */
    public function ajax_import_item() {
        ignore_user_abort( true );
        check_ajax_referer( 'quora-import-nonce', 'nonce' );
        
        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'quora-importer' ) ) );
        }
        
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $item_index = isset( $_POST['item_index'] ) ? intval( wp_unslash( $_POST['item_index'] ) ) : -1;
        $author_id = isset( $_POST['author_id'] ) ? intval( wp_unslash( $_POST['author_id'] ) ) : get_current_user_id();
        $min_chars_publish = isset( $_POST['min_chars_publish'] ) ? intval( wp_unslash( $_POST['min_chars_publish'] ) ) : 500;
        $import_images = ! empty( $_POST['import_images'] );
        $set_featured = ! empty( $_POST['set_featured'] );
        $extract_topics = ! empty( $_POST['extract_topics'] );
        $link_position = isset( $_POST['link_position'] ) ? sanitize_text_field( wp_unslash( $_POST['link_position'] ) ) : 'none';
        $link_template = isset( $_POST['link_template'] ) ? wp_kses_post( wp_unslash( $_POST['link_template'] ) ) : '';
        
        $enabled_types = isset( $_POST['enabled_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_types'] ) ) : array();
        
        if ( empty( $session_id ) || $item_index < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid import parameters.', 'quora-importer' ) ) );
        }
        
        $upload_dir = wp_upload_dir();
        $session_dir = $upload_dir['basedir'] . '/quora-importer-temp/' . $session_id;
        $manifest_file = $session_dir . '/manifest.json';
        
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if ( ! $wp_filesystem->exists( $manifest_file ) ) {
            wp_send_json_error( array( 'message' => __( 'Import session expired or not found.', 'quora-importer' ) ) );
        }
        
        $manifest = json_decode( $wp_filesystem->get_contents( $manifest_file ), true );
        if ( ! isset( $manifest['posts'][$item_index] ) ) {
            wp_send_json_error( array( 'message' => __( 'Post not found in session.', 'quora-importer' ) ) );
        }
        
        $post = $manifest['posts'][$item_index];
        $type = $post['type'];
        
        // Check if type is enabled
        if ( ! in_array( $type, $enabled_types ) ) {
            wp_send_json_success( array(
                'status'   => 'skipped',
                'title'    => $this->get_post_title( $post, $type ),
                // translators: %s: post type.
                'message'  => sprintf( __( 'Post type "%s" skipped based on your filters.', 'quora-importer' ), $type ),
                'log_type' => 'info'
            ) );
        }
        
        // Prepare title
        $title = $this->get_post_title( $post, $type );
        $title = preg_replace( '/\[\/?math\]/i', '$', $title );
        
        // Check if post already exists (avoid duplicates) using WP_Query
        $existing_query = new WP_Query( array(
            'post_type'              => 'post',
            'title'                  => $title,
            'post_status'            => 'any',
            'posts_per_page'         => 1,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ) );
        $existing = $existing_query->have_posts() ? $existing_query->posts[0] : null;
        
        if ( $existing ) {
            if ( $extract_topics ) {
                $quora_url = $this->generate_quora_url( $post, $manifest['extracted_dir'], $author_id );
                if ( ! empty( $quora_url ) ) {
                    $extracted_topics = $this->extract_quora_topics( $quora_url );
                    if ( ! empty( $extracted_topics ) ) {
                        $current_tags = wp_get_post_tags( $existing->ID, array( 'fields' => 'names' ) );
                        if ( is_wp_error( $current_tags ) ) {
                            $current_tags = array();
                        }
                        $new_tags = array_unique( array_merge( $current_tags, $extracted_topics ) );
                        wp_set_post_tags( $existing->ID, $new_tags, false );
                        
                        // translators: 1: post ID, 2: number of topics updated.
                        $msg = sprintf( __( 'Post already exists. Updated topics/tags (ID: %1$d, topics: %2$d).', 'quora-importer' ), $existing->ID, count( $extracted_topics ) );
                        wp_send_json_success( array(
                            'status'          => 'imported',
                            'title'           => $title,
                            'post_id'         => $existing->ID,
                            'images_imported' => 0,
                            'message'         => $msg,
                            'log_type'        => 'info'
                        ) );
                    } elseif ( ! empty( $this->last_topic_error ) ) {
                        // translators: %s: error details.
                        $msg = sprintf( __( 'This post already exists in WordPress. Warning: Topic extraction failed (%s).', 'quora-importer' ), $this->last_topic_error );
                        wp_send_json_success( array(
                            'status'   => 'skipped',
                            'title'    => $title,
                            'post_id'  => $existing->ID,
                            'message'  => $msg,
                            'log_type' => 'warning'
                        ) );
                    }
                }
            }
            wp_send_json_success( array(
                'status'   => 'skipped',
                'title'    => $title,
                'post_id'  => $existing->ID,
                'message'  => __( 'This post already exists in WordPress.', 'quora-importer' ),
                'log_type' => 'info'
            ) );
        }
        
        // Date parsing
        $raw_date = ! empty( $post['Creation time'] ) ? $post['Creation time'] : '';
        $timestamp = $this->parse_quora_date( $raw_date );
        
        $post_date = wp_date( 'Y-m-d H:i:s', $timestamp );
        $post_date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
        
        // Clean URL redirects in HTML
        $content = ! empty( $post['Content'] ) ? $post['Content'] : '';
        $content = preg_replace( '/\[\/?math\]/i', '$', $content );
        $content = $this->clean_html_newlines( $content );
        $content = $this->process_html_links( $content );
        
        // Set post status based on condition
        $status = 'draft'; // Default to draft
        $is_original_draft = ( strpos( strtolower( $type ), 'brouillon' ) !== false || strpos( strtolower( $type ), 'draft' ) !== false );
        
        if ( ! $is_original_draft ) {
            $plain_text = wp_strip_all_tags( $content );
            $char_count = mb_strlen( trim( $plain_text ), 'UTF-8' );
            if ( $char_count > $min_chars_publish ) {
                $status = 'publish';
            }
        }
        
        // Sideload images if requested
        $images_imported = 0;
        $featured_image_id = 0;
        
        if ( $import_images && $manifest['has_images'] ) {
            $sideload_result = $this->sideload_content_images( $content, $manifest['extracted_dir'], $set_featured, $post_date );
            $content = $sideload_result['content'];
            $images_imported = $sideload_result['count'];
            $featured_image_id = $sideload_result['featured_id'];
        }
        
        // Generate Quora URL if link is requested or topics extraction is enabled
        $quora_url = '';
        if ( $extract_topics || ( $link_position !== 'none' && ! empty( $link_template ) ) ) {
            $quora_url = $this->generate_quora_url( $post, $manifest['extracted_dir'], $author_id );
        }

        // Extract Quora topics/labels if requested
        $extracted_topics = array();
        if ( $extract_topics && ! empty( $quora_url ) ) {
            $extracted_topics = $this->extract_quora_topics( $quora_url );
        }

        // Add link to Quora if requested
        if ( $link_position !== 'none' && ! empty( $link_template ) && ! empty( $quora_url ) ) {
            $link_html = str_replace( '$link$', $quora_url, $link_template );
            if ( $link_position === 'top' ) {
                $content = $link_html . "\n\n" . $content;
            } elseif ( $link_position === 'bottom' ) {
                $content = $content . "\n\n" . $link_html;
            }
        }
        
        // Prepare categories & tags before creating the post for database atomicity
        $categories = array();
        $tags = array();
        
        // Map Categories based on type
        if ( $type === 'Répondre' || $type === 'Answer' ) {
            $categories[] = $this->get_or_create_term( 'Quora Answers', 'category' );
        } elseif ( $type === 'Brouillon de réponse' || $type === 'Answer Draft' ) {
            $categories[] = $this->get_or_create_term( 'Quora Drafts', 'category' );
        } elseif ( in_array( $type, array( "Envoi d'espace", "Élément d'espace", 'Space post', 'Space share' ) ) ) {
            $categories[] = $this->get_or_create_term( 'Quora Space Posts', 'category' );
        } else {
            $categories[] = $this->get_or_create_term( 'Quora Export', 'category' );
        }
        
        // Category for Space Name
        if ( ! empty( $post['Space name'] ) ) {
            $categories[] = $this->get_or_create_term( $post['Space name'], 'category' );
        }
        
        // Tag for language
        if ( ! empty( $post['Content language'] ) ) {
            $tags[] = $post['Content language'];
        }
        
        $tags[] = 'Quora';

        if ( ! empty( $extracted_topics ) ) {
            foreach ( $extracted_topics as $topic ) {
                $tags[] = $topic;
            }
        }
        
        // Build post data
        $post_data = array(
            'post_title'     => wp_slash( $title ),
            'post_content'   => wp_slash( $content ),
            'post_status'    => $status,
            'post_author'    => $author_id,
            'post_date'      => $post_date,
            'post_date_gmt'  => $post_date_gmt,
            'post_type'      => 'post',
            'post_category'  => $categories,
            'tags_input'     => $tags,
        );
        
        $post_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $post_id ) ) {
            // translators: %s: error message.
            wp_send_json_error( array( 'message' => sprintf( __( 'Failed to create post: %s', 'quora-importer' ), $post_id->get_error_message() ) ) );
        }
        
        // Associate attachments with the post
        if ( ! empty( $sideload_result['attachment_ids'] ) ) {
            foreach ( $sideload_result['attachment_ids'] as $attach_id ) {
                wp_update_post( array(
                    'ID'          => $attach_id,
                    'post_parent' => $post_id
                ) );
            }
        }
        
        // Set featured image
        if ( $featured_image_id > 0 ) {
            set_post_thumbnail( $post_id, $featured_image_id );
        } else {
            $default_image_id = $this->get_default_quora_image_id();
            if ( $default_image_id > 0 ) {
                set_post_thumbnail( $post_id, $default_image_id );
            }
        }
        
        $log_type = 'info';
        if ( ! empty( $extracted_topics ) ) {
            // translators: 1: post ID, 2: number of images imported, 3: number of topics extracted.
            $msg = sprintf( __( 'Post imported successfully (ID: %1$d, images: %2$d, topics: %3$d).', 'quora-importer' ), $post_id, $images_imported, count( $extracted_topics ) );
        } else {
            if ( $extract_topics && ! empty( $this->last_topic_error ) ) {
                // translators: 1: post ID, 2: number of images imported, 3: topic error details.
                $msg = sprintf( __( 'Post imported successfully (ID: %1$d, images: %2$d). Warning: Topic extraction failed (%3$s).', 'quora-importer' ), $post_id, $images_imported, $this->last_topic_error );
                $log_type = 'warning';
            } else {
                // translators: 1: post ID, 2: number of images imported.
                $msg = sprintf( __( 'Post imported successfully (ID: %1$d, images: %2$d).', 'quora-importer' ), $post_id, $images_imported );
            }
        }

        wp_send_json_success( array(
            'status'          => 'imported',
            'title'           => $title,
            'post_id'         => $post_id,
            'images_imported' => $images_imported,
            'message'         => $msg,
            'log_type'        => $log_type,
        ) );
    }
    
    /**
     * AJAX action to clean up temporary unzipped files
     */
    public function ajax_import_cleanup() {
        check_ajax_referer( 'quora-import-nonce', 'nonce' );
        
        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'quora-importer' ) ) );
        }
        
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        
        if ( empty( $session_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid import session.', 'quora-importer' ) ) );
        }
        
        $upload_dir = wp_upload_dir();
        $session_dir = $upload_dir['basedir'] . '/quora-importer-temp/' . $session_id;
        
        if ( file_exists( $session_dir ) ) {
            $this->recursive_rmdir( $session_dir );
            wp_send_json_success( array( 'message' => __( 'Cleanup completed successfully.', 'quora-importer' ) ) );
        } else {
            wp_send_json_success( array( 'message' => __( 'No folder to clean up.', 'quora-importer' ) ) );
        }
    }
    
    /* ==========================================================================
       HELPERS & PARSING LOGIC
       ========================================================================== */
    
    /**
     * Recursively find a file with a specific name in a folder
     */
    private function find_file_recursive( $dir, $filename ) {
        $it = new RecursiveDirectoryIterator( $dir );
        $display = new RecursiveIteratorIterator( $it );
        foreach ( $display as $file ) {
            if ( $file->getFilename() === $filename ) {
                return $file->getPathname();
            }
        }
        return false;
    }
    
    /**
     * Parses the Quora index.html and returns structured post array
     */
    public function parse_html_file( $file_path ) {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if ( ! $wp_filesystem->exists( $file_path ) ) {
            return false;
        }
        
        $html = $wp_filesystem->get_contents( $file_path );
        
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        
        // Force UTF-8 encoding
        $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>' );
        libxml_clear_errors();
        
        $h2s = $dom->getElementsByTagName( 'h2' );
        $posts = array();
        
        $target_types = array(
            'Répondre', 'Brouillon de réponse', "Envoi d'espace", "Élément d'espace", 'Brouillon de publication',
            'Answer', 'Answer Draft', 'Space post', 'Space share', 'Post Draft', 'Draft'
        );
        
        foreach ( $h2s as $h2 ) {
            $type = trim( $h2->textContent );
            
            $is_target = false;
            foreach ( $target_types as $target ) {
                if ( strcasecmp( $type, $target ) === 0 || html_entity_decode( $type ) === $target ) {
                    $is_target = true;
                    break;
                }
            }
            
            if ( ! $is_target ) {
                continue;
            }
            
            $post_data = array(
                'type'             => $type,
                'Question'         => '',
                'Title'            => '',
                'Content'          => '',
                'Creation time'    => '',
                'Content language' => '',
                'Space name'       => '',
            );
            
            $curr = $h2->nextSibling;
            while ( $curr && $curr->nodeName !== 'h2' ) {
                if ( $curr->nodeName === 'div' ) {
                    $strongs = $curr->getElementsByTagName( 'strong' );
                    if ( $strongs->length > 0 ) {
                        $strong = $strongs->item( 0 );
                        $label = trim( rtrim( trim( $strong->textContent ), ':' ) );
                        
                        if ( $label === 'Post content' ) {
                            $label = 'Content';
                        }
                        if ( $label === 'Post title' || $label === 'Title' ) {
                            $label = 'Title';
                        }
                        if ( $label === 'Last updated' || $label === 'Time' || $label === 'Creation time' ) {
                            $label = 'Creation time';
                        }
                        
                        $spans = $curr->getElementsByTagName( 'span' );
                        if ( $spans->length > 0 ) {
                            $span = $spans->item( 0 );
                            if ( $label === 'Content' ) {
                                $value = '';
                                foreach ( $span->childNodes as $child ) {
                                    $value .= $dom->saveHTML( $child );
                                }
                            } else {
                                // Reconstruct link to target URL instead of anchor text in titles/questions
                                $anchors = $span->getElementsByTagName( 'a' );
                                if ( $anchors->length > 0 ) {
                                    $anchor_list = array();
                                    foreach ( $anchors as $a ) {
                                        $anchor_list[] = $a;
                                    }
                                    foreach ( $anchor_list as $a ) {
                                        $href = $a->getAttribute( 'href' );
                                        $target_url = '';
                                        if ( ! empty( $href ) ) {
                                            if ( strpos( $href, 'quora.com/_/redirect' ) !== false ) {
                                                $query = wp_parse_url( $href, PHP_URL_QUERY );
                                                if ( $query ) {
                                                    $params = array();
                                                    parse_str( $query, $params );
                                                    if ( ! empty( $params['url'] ) ) {
                                                        $target_url = urldecode( $params['url'] );
                                                    } elseif ( ! empty( $params['to'] ) ) {
                                                        $target_url = urldecode( $params['to'] );
                                                    }
                                                }
                                            }
                                            if ( empty( $target_url ) && filter_var( $href, FILTER_VALIDATE_URL ) ) {
                                                $target_url = $href;
                                            }
                                        }
                                        if ( ! empty( $target_url ) ) {
                                            $a->nodeValue = htmlspecialchars( $target_url, ENT_QUOTES, 'UTF-8' );
                                        }
                                    }
                                }
                                $value = trim( $span->textContent );
                            }
                        } else {
                            // Reconstruct link to target URL in parent div if no span is present
                            $anchors = $curr->getElementsByTagName( 'a' );
                            if ( $anchors->length > 0 ) {
                                $anchor_list = array();
                                foreach ( $anchors as $a ) {
                                    $anchor_list[] = $a;
                                }
                                foreach ( $anchor_list as $a ) {
                                    $href = $a->getAttribute( 'href' );
                                    $target_url = '';
                                    if ( ! empty( $href ) ) {
                                        if ( strpos( $href, 'quora.com/_/redirect' ) !== false ) {
                                            $query = wp_parse_url( $href, PHP_URL_QUERY );
                                            if ( $query ) {
                                                $params = array();
                                                parse_str( $query, $params );
                                                if ( ! empty( $params['url'] ) ) {
                                                    $target_url = urldecode( $params['url'] );
                                                } elseif ( ! empty( $params['to'] ) ) {
                                                    $target_url = urldecode( $params['to'] );
                                                }
                                            }
                                        }
                                        if ( empty( $target_url ) && filter_var( $href, FILTER_VALIDATE_URL ) ) {
                                            $target_url = $href;
                                        }
                                    }
                                    if ( ! empty( $target_url ) ) {
                                        $a->nodeValue = htmlspecialchars( $target_url, ENT_QUOTES, 'UTF-8' );
                                    }
                                }
                            }
                            $value = trim( str_replace( $strong->textContent, '', $curr->textContent ) );
                        }
                        $post_data[$label] = $value;
                    }
                }
                $curr = $curr->nextSibling;
            }
            
            $posts[] = $post_data;
        }
        
        return $posts;
    }
    
    /**
     * Get or generate a clean title for the post
     */
    private function get_post_title( $post, $type ) {
        foreach ( array( 'Question', 'Title' ) as $key ) {
            if ( ! empty( $post[$key] ) ) {
                $title = trim( $post[$key] );
                $title = preg_replace( '/\s+/', ' ', $title );
                return $title;
            }
        }
        
        if ( ! empty( $post['Content'] ) ) {
            $content = $post['Content'];
            
            $doc = new DOMDocument();
            libxml_use_internal_errors( true );
            $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
            libxml_clear_errors();
            
            $anchors = $doc->getElementsByTagName( 'a' );
            if ( $anchors->length > 0 ) {
                $first_a = $anchors->item( 0 );
                $anchor_text = trim( $first_a->textContent );
                if ( ! empty( $anchor_text ) ) {
                    if ( strpos( $content, $doc->saveHTML( $first_a ) ) < 50 ) {
                        return $anchor_text;
                    }
                }
            }
            
            $text = trim( wp_strip_all_tags( $content ) );
            $text = preg_replace( '/\s+/', ' ', $text );
            if ( mb_strlen( $text ) > 80 ) {
                return mb_substr( $text, 0, 80 ) . '...';
            }
            if ( ! empty( $text ) ) {
                return $text;
            }
        }
        
        return 'Untitled ' . $type;
    }
    
    /**
     * Parse Quora export date string into a Unix timestamp
     */
    private function parse_quora_date( $date_str ) {
        $date_str = trim( $date_str );
        if ( empty( $date_str ) ) {
            return time();
        }
        
        $date_str = preg_replace( '/\s+/', ' ', $date_str );
        $timestamp = strtotime( $date_str );
        
        if ( ! $timestamp ) {
            // Fallback for custom layouts
            return time();
        }
        
        return $timestamp;
    }
    
    /**
     * Clean Quora redirect wrappers
     */
    private function process_html_links( $content ) {
        if ( empty( $content ) ) {
            return '';
        }
        
        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
        libxml_clear_errors();
        
        $anchors = $doc->getElementsByTagName( 'a' );
        $changed = false;
        
        foreach ( $anchors as $a ) {
            $href = $a->getAttribute( 'href' );
            if ( ! empty( $href ) && strpos( $href, 'quora.com/_/redirect' ) !== false ) {
                $query = wp_parse_url( $href, PHP_URL_QUERY );
                if ( $query ) {
                    $params = array();
                    parse_str( $query, $params );
                    if ( ! empty( $params['url'] ) ) {
                        $clean_url = urldecode( $params['url'] );
                        $a->setAttribute( 'href', $clean_url );
                        $changed = true;
                    } elseif ( ! empty( $params['to'] ) ) {
                        $clean_url = urldecode( $params['to'] );
                        $a->setAttribute( 'href', $clean_url );
                        $changed = true;
                    }
                }
            }
        }
        
        if ( $changed ) {
            // Return only body contents to prevent html wrapper tags
            $body = $doc->getElementsByTagName( 'body' )->item( 0 );
            $new_content = '';
            foreach ( $body->childNodes as $child ) {
                $new_content .= $doc->saveHTML( $child );
            }
            return $new_content;
        }
        
        return $content;
    }
    
    /**
     * Sideload images from content html and replace local sources with WordPress URLs
     */
    private function sideload_content_images( $content, $extracted_dir, $set_featured, $post_date ) {
        $result = array(
            'content'        => $content,
            'count'          => 0,
            'featured_id'    => 0,
            'attachment_ids' => array()
        );
        
        if ( empty( $content ) ) {
            return $result;
        }
        
        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
        libxml_clear_errors();
        
        $imgs = $doc->getElementsByTagName( 'img' );
        if ( $imgs->length === 0 ) {
            return $result;
        }
        
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        
        $images_folder = $extracted_dir . '/images/';
        $count = 0;
        $featured_id = 0;
        $changed = false;
        
        // Cache to prevent duplicate uploads in the same post/session
        static $session_images_cache = array();
        
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            WP_Filesystem();
        }
        
        foreach ( $imgs as $img ) {
            $src = $img->getAttribute( 'src' );
            if ( empty( $src ) ) {
                continue;
            }
            
            // Extract file name
            $filename = basename( $src );
            
            // Check if it's a local reference (starts with images/ or contains qimg-)
            $is_local = false;
            $local_path = '';
            
            if ( strpos( $src, 'images/' ) === 0 ) {
                $is_local = true;
                $local_path = $extracted_dir . '/' . $src;
            } elseif ( strpos( $src, 'qimg-' ) !== false ) {
                $is_local = true;
                $local_path = $images_folder . $filename;
            }
            
            if ( ! $is_local || ! file_exists( $local_path ) ) {
                continue;
            }
            
            $attachment_id = 0;
            $attachment_url = '';
            
            if ( isset( $session_images_cache[$local_path] ) ) {
                $attachment_id = $session_images_cache[$local_path]['id'];
                $attachment_url = $session_images_cache[$local_path]['url'];
            } else {
                // Get correct image extension (since Quora export images have no extension)
                $ext = $this->get_image_extension( $local_path );
                
                // Sideload the image
                // 1. Copy file to temp location because media_handle_sideload moves/deletes it
                $tmp_dir = get_temp_dir();
                $tmp_file = $tmp_dir . uniqid( 'quora_img_' ) . '_' . $filename . $ext;
                
                if ( $wp_filesystem->copy( $local_path, $tmp_file ) ) {
                    $file_array = array(
                        'name'     => $filename . $ext,
                        'tmp_name' => $tmp_file,
                    );
                    
                    // Filter to customize the upload directory using the post date
                    $upload_dir_filter = function( $uploads ) use ( $post_date ) {
                        $timestamp = strtotime( $post_date );
                        if ( $timestamp ) {
                            $year = wp_date( 'Y', $timestamp );
                            $month = wp_date( 'm', $timestamp );
                            $uploads['subdir'] = '/' . $year . '/' . $month;
                            $uploads['path']   = $uploads['basedir'] . $uploads['subdir'];
                            $uploads['url']    = $uploads['baseurl'] . $uploads['subdir'];
                        }
                        return $uploads;
                    };
                    
                    add_filter( 'upload_dir', $upload_dir_filter );
                    
                    // Sideload the attachment (unassociated with post_id first, we assign manually after post is created)
                    $attachment_id = media_handle_sideload( $file_array, 0 );
                    
                    remove_filter( 'upload_dir', $upload_dir_filter );
                    
                    if ( ! is_wp_error( $attachment_id ) ) {
                        // Update the attachment's date in database to match the post date
                        wp_update_post( array(
                            'ID'            => $attachment_id,
                            'post_date'     => $post_date,
                            'post_date_gmt' => get_gmt_from_date( $post_date )
                        ) );
                        
                        $attachment_url = wp_get_attachment_url( $attachment_id );
                        $session_images_cache[$local_path] = array(
                            'id'  => $attachment_id,
                            'url' => $attachment_url
                        );
                        $count++;
                    } else {
                        $wp_filesystem->delete( $tmp_file );
                    }
                }
            }
            
            if ( $attachment_id > 0 && ! empty( $attachment_url ) ) {
                $result['attachment_ids'][] = $attachment_id;
                $img->setAttribute( 'src', $attachment_url );
                $changed = true;
                
                // Add wp-image class to help WordPress styling
                $classes = $img->getAttribute( 'class' );
                $classes = trim( $classes . ' aligncenter size-full wp-image-' . $attachment_id );
                $img->setAttribute( 'class', $classes );
                
                // Track first image for featured
                if ( $set_featured && 0 === $featured_id ) {
                    $featured_id = $attachment_id;
                }
            }
        }
        
        if ( $changed ) {
            $body = $doc->getElementsByTagName( 'body' )->item( 0 );
            $new_content = '';
            foreach ( $body->childNodes as $child ) {
                $new_content .= $doc->saveHTML( $child );
            }
            $result['content'] = $new_content;
        }
        
        $result['count'] = $count;
        $result['featured_id'] = $featured_id;
        
        return $result;
    }
    
    /**
     * Detect the correct image file extension based on mime type or file signature.
     */
    private function get_image_extension( $path ) {
        if ( function_exists( 'mime_content_type' ) ) {
            $mime = @mime_content_type( $path );
            if ( $mime ) {
                $map = array(
                    'image/jpeg'    => '.jpg',
                    'image/jpg'     => '.jpg',
                    'image/png'     => '.png',
                    'image/gif'     => '.gif',
                    'image/webp'    => '.webp',
                    'image/svg+xml' => '.svg',
                );
                if ( isset( $map[$mime] ) ) {
                    return $map[$mime];
                }
            }
        }
        
        // Fallback: check file signature (magic numbers) using WP_Filesystem
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $bytes = $wp_filesystem->get_contents( $path );
        if ( $bytes ) {
            if ( strpos( $bytes, "\xff\xd8\xff" ) === 0 ) {
                return '.jpg';
            }
            if ( strpos( $bytes, "\x89PNG\r\n\x1a\n" ) === 0 ) {
                return '.png';
            }
            if ( strpos( $bytes, 'GIF87a' ) === 0 || strpos( $bytes, 'GIF89a' ) === 0 ) {
                return '.gif';
            }
            if ( strpos( $bytes, 'RIFF' ) === 0 && strpos( substr( $bytes, 8, 4 ), 'WEBP' ) === 0 ) {
                return '.webp';
            }
        }
        
        return '.png'; // Default fallback
    }
    
    /**
     * Get or create a term (category/tag) and return its ID
     */
    private function get_or_create_term( $name, $taxonomy ) {
        $term = get_term_by( 'name', $name, $taxonomy );
        if ( $term ) {
            return (int) $term->term_id;
        }
        
        $new_term = wp_insert_term( $name, $taxonomy );
        if ( ! is_wp_error( $new_term ) ) {
            return (int) $new_term['term_id'];
        }
        
        return 0;
    }
    
    /**
     * Helper to extract author name from a folder name (e.g. "Contenu_Philippe_Guglielmetti_11")
     */
    private function extract_author_from_folder( $folder_name ) {
        if ( strpos( $folder_name, 'Contenu_' ) === 0 ) {
            $name = substr( $folder_name, 8 );
            $name = preg_replace( '/_\d+$/', '', $name ); // Strip trailing digits
            $name_parts = explode( '_', $name );
            return implode( ' ', $name_parts );
        }
        return '';
    }
    
    /**
     * Helper to generate the original Quora URL of a post
     */
    private function generate_quora_url( $post, $extracted_dir, $author_id = 0 ) {
        // First, check if there is a direct URL already parsed from the HTML export
        foreach ( array( 'Answer', 'Question', 'Link', 'url' ) as $key ) {
            if ( ! empty( $post[$key] ) ) {
                $val = trim( $post[$key] );
                if ( filter_var( $val, FILTER_VALIDATE_URL ) ) {
                    return $val;
                }
            }
        }

        // Base domain based on language
        $lang = ! empty( $post['Content language'] ) ? strtolower( $post['Content language'] ) : 'français';
        $domain = ( strpos( $lang, 'fran' ) !== false ) ? 'fr.quora.com' : 'www.quora.com';
        
        // Author profile name
        $profile_slug = '';
        
        // Try getting nickname from selected WP author
        if ( ! empty( $author_id ) ) {
            $user_data = get_userdata( $author_id );
            if ( $user_data ) {
                $nickname = get_user_meta( $author_id, 'nickname', true );
                if ( empty( $nickname ) ) {
                    $nickname = $user_data->display_name;
                }
                if ( ! empty( $nickname ) ) {
                    $normalized = remove_accents( $nickname );
                    $cleaned_name = preg_replace( '/[^A-Za-z0-9_\-\s]/', '', $normalized );
                    $profile_slug = str_replace( array( ' ', '_' ), '-', $cleaned_name );
                    $profile_slug = preg_replace( '/-+/', '-', $profile_slug );
                }
            }
        }
        
        // Fallback to folder name
        if ( empty( $profile_slug ) ) {
            $folder_name = basename( $extracted_dir );
            if ( strpos( $folder_name, 'Contenu_' ) === 0 ) {
                $name = substr( $folder_name, 8 );
                $name = preg_replace( '/_\d+$/', '', $name ); // Strip trailing digits
                $profile_slug = str_replace( '_', '-', $name );
            }
        }
        
        // Check post type
        $type = ! empty( $post['type'] ) ? $post['type'] : '';
        $is_answer = ( strpos( strtolower( $type ), 'répondre' ) !== false || strpos( strtolower( $type ), 'answer' ) !== false );
        
        // Get question or title
        $title = '';
        if ( ! empty( $post['Question'] ) ) {
            $title = $post['Question'];
        } elseif ( ! empty( $post['Title'] ) ) {
            $title = $post['Title'];
        }
        
        if ( empty( $title ) && ! empty( $post['Content'] ) ) {
            $title = $this->get_post_title( $post, $type );
        }
        
        $title_slug = $this->quora_slugify( $title );
        
        if ( $is_answer && ! empty( $profile_slug ) && ! empty( $title_slug ) ) {
            return "https://{$domain}/{$title_slug}/answer/{$profile_slug}";
        }
        
        // Space posts
        if ( ! empty( $post['Space name'] ) ) {
            $space_slug = sanitize_title( $post['Space name'] );
            if ( ! empty( $title_slug ) ) {
                return "https://{$space_slug}.quora.com/{$title_slug}";
            }
            return "https://{$space_slug}.quora.com";
        }
        
        // General fallback
        if ( ! empty( $title_slug ) ) {
            return "https://{$domain}/{$title_slug}";
        }
        
        return "https://{$domain}";
    }

    /**
     * Reconstruct a Quora-compatible slug that preserves case and accents.
     */
    private function quora_slugify( $title ) {
        if ( empty( $title ) ) {
            return '';
        }
        
        // Strip [math] and [/math] tags if present
        $title = preg_replace( '/\[\/?math\]/i', '', $title );

        // Replace slashes and underscores with spaces to prevent word merging
        $title = str_replace( array( '/', '_' ), ' ', $title );
        
        // Strip everything except letters, numbers, spaces, and hyphens (preserving accents and case)
        $slug = preg_replace( '/[^\p{L}\p{N}\s\-]/u', '', $title );
        
        // Replace spaces/tabs and consecutive hyphens with a single hyphen
        $slug = preg_replace( '/[\s\-]+/u', '-', $slug );
        $slug = trim( $slug, '-' );
        
        // Truncate slug to 200 UTF-8 characters to match Quora answer URLs
        if ( mb_strlen( $slug, 'UTF-8' ) > 200 ) {
            $truncated = mb_substr( $slug, 0, 200, 'UTF-8' );
            $next_char = mb_substr( $slug, 200, 1, 'UTF-8' );
            if ( $next_char === '-' || $next_char === '' ) {
                $slug = $truncated;
            } else {
                $last_hyphen = mb_strrpos( $truncated, '-', 0, 'UTF-8' );
                if ( $last_hyphen !== false ) {
                    $slug = mb_substr( $truncated, 0, $last_hyphen, 'UTF-8' );
                } else {
                    $slug = $truncated;
                }
            }
        }

        // Enforce URL-encoded length limit of 215 characters
        $encoded = rawurlencode( $slug );
        if ( strlen( $encoded ) > 215 ) {
            while ( strlen( rawurlencode( $slug ) ) > 215 && mb_strlen( $slug, 'UTF-8' ) > 0 ) {
                $slug = mb_substr( $slug, 0, -1, 'UTF-8' );
            }
            $last_hyphen = mb_strrpos( $slug, '-', 0, 'UTF-8' );
            if ( $last_hyphen !== false ) {
                $slug = mb_substr( $slug, 0, $last_hyphen, 'UTF-8' );
            }
        }
        
        // URL-encode all accented and special characters while preserving hyphens
        return rawurlencode( $slug );
    }

    /**
     * Get or upload the default Quora image and return its attachment ID.
     */
    private function get_default_quora_image_id() {
        // Check if we already have it cached in an option to avoid DB searches
        $cached_id = get_option( 'quora_importer_default_image_id' );
        if ( $cached_id ) {
            // Verify the attachment still exists
            $post_status = get_post_status( $cached_id );
            if ( $post_status === 'inherit' ) {
                return (int) $cached_id;
            }
        }

        // Check if the attachment already exists in the media library by title
        $query = new WP_Query( array(
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'title'       => 'Quora Default',
            'posts_per_page' => 1,
        ) );

        if ( $query->have_posts() ) {
            $attachment_id = $query->posts[0]->ID;
            update_option( 'quora_importer_default_image_id', $attachment_id );
            return (int) $attachment_id;
        }

        // Upload the image since it does not exist
        $plugin_dir = plugin_dir_path( dirname( __FILE__ ) );
        $image_path = $plugin_dir . 'assets/quora.png';

        if ( ! file_exists( $image_path ) ) {
            return 0;
        }

        // Sideload/copy to upload directory
        $upload_dir = wp_upload_dir();
        $filename = 'quora-logo.png';
        
        // If a file with the same name exists, get a unique filename
        $destination_filename = wp_unique_filename( $upload_dir['path'], $filename );
        $destination = $upload_dir['path'] . '/' . $destination_filename;

        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( ! $wp_filesystem->copy( $image_path, $destination ) ) {
            return 0;
        }

        $filetype = wp_check_filetype( basename( $destination ), null );

        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => 'Quora Default',
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attachment_id = wp_insert_attachment( $attachment, $destination );

        if ( ! is_wp_error( $attachment_id ) ) {
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $destination );
            wp_update_attachment_metadata( $attachment_id, $attach_data );
            update_option( 'quora_importer_default_image_id', $attachment_id );
            return (int) $attachment_id;
        }

        return 0;
    }

    /**
     * Clean newlines inside HTML text nodes, converting them to single spaces.
     */
    private function clean_html_newlines( $content ) {
        if ( empty( $content ) ) {
            return '';
        }
        
        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        // Use XML encoding header to prevent character encoding issues with loadHTML
        $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
        libxml_clear_errors();
        
        // Find all text nodes
        $xpath = new DOMXPath( $doc );
        $text_nodes = $xpath->query( '//text()' );
        $changed = false;
        
        foreach ( $text_nodes as $node ) {
            // Skip text nodes inside <pre> or <code> tags
            $parent = $node->parentNode;
            if ( $parent && in_array( strtolower( $parent->nodeName ), array( 'pre', 'code' ) ) ) {
                continue;
            }
            
            $text = $node->nodeValue;
            if ( ! empty( $text ) ) {
                // Replace newlines and any surrounding whitespace with a single space
                $new_text = preg_replace( '/\s*\n\s*/u', ' ', $text );
                if ( $new_text !== $text ) {
                    $node->nodeValue = $new_text;
                    $changed = true;
                }
            }
        }
        
        if ( $changed ) {
            $body = $doc->getElementsByTagName( 'body' )->item( 0 );
            $new_content = '';
            foreach ( $body->childNodes as $child ) {
                $new_content .= $doc->saveHTML( $child );
            }
            return $new_content;
        }
        
        return $content;
    }

    /**
     * Helper to delete a folder and all its contents recursively
     */
    private function recursive_rmdir( $dir ) {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( is_dir( $dir ) ) {
            $wp_filesystem->delete( $dir, true );
        }
    }

    /**
     * Visit Quora URL and extract topic labels
     *
     * @param string $url The Quora URL.
     * @return array Array of topic names.
     */
    private function extract_quora_topics( $url ) {
        $this->last_topic_error = '';
        if ( empty( $url ) ) {
            return array();
        }

        // Try running the Python scraper helper which bypasses Cloudflare
        $python_script = plugin_dir_path( __FILE__ ) . 'scrape-topics.py';
        if ( file_exists( $python_script ) ) {
            $python_executable = 'python3';
            $possible_paths = array(
                '/usr/bin/python3',
                '/usr/local/bin/python3',
                '/bin/python3',
                '/usr/bin/python',
            );
            foreach ( $possible_paths as $path ) {
                if ( @is_executable( $path ) ) {
                    $python_executable = $path;
                    break;
                }
            }
            $cmd = escapeshellcmd( $python_executable ) . ' ' . escapeshellarg( $python_script ) . ' ' . escapeshellarg( $url );
            $output = array();
            $retval = null;
            exec( $cmd, $output, $retval );
            if ( 0 === $retval && ! empty( $output ) ) {
                $response_data = json_decode( implode( '', $output ), true );
                if ( is_array( $response_data ) && isset( $response_data['success'] ) ) {
                    if ( $response_data['success'] ) {
                        return $response_data['topics'];
                    } else {
                        $this->last_topic_error = $response_data['error'];
                        return array();
                    }
                } else {
                    $topics = json_decode( implode( '', $output ), true );
                    if ( is_array( $topics ) && ! empty( $topics ) ) {
                        return $topics;
                    }
                }
            } else {
                // translators: %d: exit code.
                $this->last_topic_error = sprintf( __( 'Python scraper failed with exit code %d', 'quora-importer' ), $retval );
            }
        }

        // Fallback: Set realistic User-Agent and headers
        $args = array(
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => array(
                'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ),
        );

        $response = wp_safe_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->last_topic_error = $response->get_error_message();
            return array();
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $http_code ) {
            // translators: %d: HTTP code.
            $this->last_topic_error = sprintf( __( 'HTTP error %d', 'quora-importer' ), $http_code );
            return array();
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            $this->last_topic_error = __( 'Empty response body', 'quora-importer' );
            return array();
        }

        $topics = array();

        // 1. Try matching JSON format
        // E.g., \"url\":\"https://fr.quora.com/topic/Sociologie\",\"name\":\"Sociologie\"
        if ( preg_match_all( '/\\\\*"url\\\\*":\\\\*"(https?:\\/\\/[^\\"/]+\\/topic\\/(?:[^\\"\\\]|\\\\.)*?)\\\\*",\\\\*"name\\\\*":\\\\*"((?:[^\\"\\\]|\\\\.)*?)\\\\*"/is', $html, $matches_json ) ) {
            if ( ! empty( $matches_json[2] ) ) {
                foreach ( $matches_json[2] as $label ) {
                    $label = str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), $label );
                    // Decode unicode escapes like \u00e9 or \\u00e9
                    $label = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', function( $match ) {
                        return mb_convert_encoding( pack( 'H*', $match[1] ), 'UTF-8', 'UCS-2BE' );
                    }, $label );
                    $label = trim( strip_tags( $label ) );
                    $label = html_entity_decode( $label, ENT_QUOTES, 'UTF-8' );
                    if ( ! empty( $label ) && ! in_array( $label, $topics, true ) ) {
                        if ( strlen( $label ) < 50 ) {
                            $topics[] = $label;
                        }
                    }
                }
            }
        }

        // 2. Try matching standard HTML link format
        if ( empty( $topics ) && preg_match_all( '/href=["\'][^"\']*\/topic\/([^"\'\/]+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches_html ) ) {
            if ( ! empty( $matches_html[2] ) ) {
                foreach ( $matches_html[2] as $label ) {
                    $label = trim( strip_tags( $label ) );
                    $label = html_entity_decode( $label, ENT_QUOTES, 'UTF-8' );
                    if ( ! empty( $label ) && ! in_array( $label, $topics, true ) ) {
                        if ( strlen( $label ) < 50 ) {
                            $topics[] = $label;
                        }
                    }
                }
            }
        }

        return $topics;
    }
}

