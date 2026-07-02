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
        
        // Meta Box for Quora URL on post edit pages
        add_action( 'add_meta_boxes', array( $this, 'add_quora_url_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_quora_url_meta_box_data' ) );
        
        // AJAX endpoint to test Quora URL validation
        add_action( 'wp_ajax_quora_test_url', array( $this, 'ajax_test_quora_url' ) );
        
        // AJAX endpoints for manual tag/comment updates from Meta Box
        add_action( 'wp_ajax_quora_import_post_tags', array( $this, 'ajax_import_post_tags' ) );
        add_action( 'wp_ajax_quora_update_post_comments', array( $this, 'ajax_update_post_comments' ) );
        
        // Deferred comments extraction hooks
        add_action( 'template_redirect', array( $this, 'maybe_schedule_deferred_comments' ) );
        add_action( 'quora_import_deferred_comments', array( $this, 'cron_import_comments' ) );
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
                    'pause'               => __( 'Pause', 'quora-importer' ),
                    'resume'              => __( 'Continue', 'quora-importer' ),
                    'import_paused'       => __( 'Import paused.', 'quora-importer' ),
                    'import_resumed'      => __( 'Import resumed.', 'quora-importer' ),
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
                    <h3><?php esc_html_e( 'Upload your export file(s)', 'quora-importer' ); ?></h3>
                    <p><?php esc_html_e( 'Drag & drop your Quora export ZIP file(s) (or index.html) here, or click to browse.', 'quora-importer' ); ?></p>
                    <button class="button button-primary button-hero quora-browse-btn" type="button"><?php esc_html_e( 'Select File(s)', 'quora-importer' ); ?></button>
                    <input type="file" id="quora-file-input" accept=".zip,.html" style="display: none;" multiple />
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
                            <option value="none"><?php esc_html_e( 'No', 'quora-importer' ); ?></option>
                            <option value="top" selected><?php esc_html_e( 'At the top of the post', 'quora-importer' ); ?></option>
                            <option value="bottom"><?php esc_html_e( 'At the bottom of the post', 'quora-importer' ); ?></option>
                        </select>
                    </div>
                    
                    <div class="quora-form-row" id="quora-link-template-row">
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
                        <input type="checkbox" name="extract_topics" id="quora-extract-topics" value="1" checked />
                        <label for="quora-extract-topics">
                            <strong><?php esc_html_e( 'Extract Quora topic labels', 'quora-importer' ); ?></strong>
                            <span class="help-desc"><?php esc_html_e( 'Visits the corresponding Quora link for each post to extract its original topic tags (e.g. "Sociologie", "Exemples") and apply them as WordPress tags.', 'quora-importer' ); ?></span>
                        </label>
                    </div>
                </div>
                
                <div class="quora-form-section">
                    <h4><?php esc_html_e( '5. Wikipedia / Reference 2 Wiki', 'quora-importer' ); ?></h4>
                    <div class="quora-form-row checkbox-row">
                        <?php
                        if ( ! function_exists( 'is_plugin_active' ) ) {
                            include_once ABSPATH . 'wp-admin/includes/plugin.php';
                        }
                        $r2w_active = is_plugin_active( 'reference-2-wiki/reference2wiki.php' );
                        ?>
                        <input type="checkbox" name="r2w_support" id="quora-r2w-support" value="1" <?php checked( $r2w_active ); ?> />
                        <label for="quora-r2w-support">
                            <strong><?php esc_html_e( 'Support for "Reference 2 Wiki"', 'quora-importer' ); ?></strong>
                            <span class="help-desc"><?php esc_html_e( 'Automatically convert Wikipedia links in imported articles and comments to [[lang|article|text]] syntax.', 'quora-importer' ); ?></span>
                        </label>
                    </div>
                </div>

                <div class="quora-form-section">
                    <h4><?php esc_html_e( '6. Comment Settings', 'quora-importer' ); ?></h4>
                    <div class="quora-form-row">
                        <label for="quora-import-comments">
                            <strong><?php esc_html_e( 'Import comments from Quora', 'quora-importer' ); ?></strong>
                        </label>
                        <select name="import_comments" id="quora-import-comments" style="display: block; margin-top: 5px; min-width: 250px;">
                            <option value="none"><?php esc_html_e( "No import (Pas d'importation)", 'quora-importer' ); ?></option>
                            <option value="direct"><?php esc_html_e( 'Direct import (Importation directe)', 'quora-importer' ); ?></option>
                            <option value="deferred" selected="selected"><?php esc_html_e( 'Deferred import (Importation différée)', 'quora-importer' ); ?></option>
                        </select>
                        <span class="help-desc"><?php esc_html_e( 'Choose when and how to import comments. Direct imports comments during the import process. Deferred schedules comment scraping asynchronously via WordPress CRON on demand when the post is first viewed on the site.', 'quora-importer' ); ?></span>
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
                <button type="button" class="button button-secondary" id="quora-pause-import"><?php esc_html_e( 'Pause', 'quora-importer' ); ?></button>
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
        
        if ( empty( $_FILES['files'] ) && empty( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No files received.', 'quora-importer' ) ) );
        }
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is validated below via checking file extension and using WordPress APIs.
        $uploaded_files = array();
        if ( ! empty( $_FILES['files'] ) && is_array( $_FILES['files']['name'] ) ) {
            $files_data = $_FILES['files'];
            $count = count( $files_data['name'] );
            for ( $i = 0; $i < $count; $i++ ) {
                $uploaded_files[] = array(
                    'name'     => $files_data['name'][$i],
                    'type'     => $files_data['type'][$i],
                    'tmp_name' => $files_data['tmp_name'][$i],
                    'error'    => $files_data['error'][$i],
                    'size'     => $files_data['size'][$i],
                );
            }
        } elseif ( ! empty( $_FILES['file'] ) ) {
            $uploaded_files[] = $_FILES['file'];
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
        
        $all_posts = array();
        $has_images = false;
        $types_count = array();
        
        foreach ( $uploaded_files as $idx => $file ) {
            $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, array( 'zip', 'html' ) ) ) {
                $this->recursive_rmdir( $session_dir );
                wp_send_json_error( array( 'message' => sprintf( __( 'Invalid file extension for %s. Please use .zip or .html.', 'quora-importer' ), esc_html( $file['name'] ) ) ) );
            }
            
            $file_dir = $session_dir . '/file_' . $idx;
            if ( ! wp_mkdir_p( $file_dir ) ) {
                $this->recursive_rmdir( $session_dir );
                wp_send_json_error( array( 'message' => __( 'Could not create temporary subdirectory.', 'quora-importer' ) ) );
            }
            
            $uploaded_file_path = $file_dir . '/' . basename( $file['name'] );
            if ( ! $wp_filesystem->copy( $file['tmp_name'], $uploaded_file_path ) ) {
                $this->recursive_rmdir( $session_dir );
                wp_send_json_error( array( 'message' => sprintf( __( 'Failed to move uploaded file %s.', 'quora-importer' ), esc_html( $file['name'] ) ) ) );
            }
            
            $extracted_dir = $file_dir;
            $index_html_path = '';
            $file_has_images = false;
            
            if ( 'zip' === $ext ) {
                $unzip_result = unzip_file( $uploaded_file_path, $file_dir );
                wp_delete_file( $uploaded_file_path );
                
                if ( is_wp_error( $unzip_result ) ) {
                    $this->recursive_rmdir( $session_dir );
                    // translators: %1$s: filename, %2$s: error message.
                    wp_send_json_error( array( 'message' => sprintf( __( 'ZIP extraction failed for %1$s: %2$s', 'quora-importer' ), esc_html( $file['name'] ), $unzip_result->get_error_message() ) ) );
                }
                
                $index_html_path = $this->find_file_recursive( $file_dir, 'index.html' );
                if ( $index_html_path ) {
                    $extracted_dir = dirname( $index_html_path );
                    if ( $wp_filesystem->exists( $extracted_dir . '/images' ) && $wp_filesystem->is_dir( $extracted_dir . '/images' ) ) {
                        $file_has_images = true;
                    }
                } else {
                    $this->recursive_rmdir( $session_dir );
                    wp_send_json_error( array( 'message' => sprintf( __( 'Could not find index.html inside the ZIP archive %s.', 'quora-importer' ), esc_html( $file['name'] ) ) ) );
                }
            } else {
                $index_html_path = $uploaded_file_path;
            }
            
            $posts = $this->parse_html_file( $index_html_path );
            if ( false === $posts || empty( $posts ) ) {
                $this->recursive_rmdir( $session_dir );
                wp_send_json_error( array( 'message' => sprintf( __( 'No eligible posts found inside %s.', 'quora-importer' ), esc_html( $file['name'] ) ) ) );
            }
            
            foreach ( $posts as $post ) {
                $post['extracted_dir'] = $extracted_dir;
                $all_posts[] = $post;
                
                $type = $post['type'];
                if ( ! isset( $types_count[$type] ) ) {
                    $types_count[$type] = 0;
                }
                $types_count[$type]++;
            }
            
            if ( $file_has_images ) {
                $has_images = true;
            }
        }
        
        // Try to guess default author name from folders
        $guessed_author = '';
        foreach ( $all_posts as $post ) {
            if ( ! empty( $post['extracted_dir'] ) ) {
                $guessed = $this->extract_author_from_folder( basename( $post['extracted_dir'] ) );
                if ( ! empty( $guessed ) ) {
                    $guessed_author = $guessed;
                    break;
                }
            }
        }
        
        // Save manifest JSON
        $manifest = array(
            'session_id'    => $session_id,
            'extracted_dir' => $session_dir, // Fallback root
            'has_images'    => $has_images,
            'posts'         => $all_posts
        );
        
        $wp_filesystem->put_contents( $session_dir . '/manifest.json', wp_json_encode( $manifest ) );
        
        wp_send_json_success( array(
            'session_id'     => $session_id,
            'total_posts'    => count( $all_posts ),
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
        $r2w_support = ! empty( $_POST['r2w_support'] );
        update_option( 'quora_importer_r2w_support', $r2w_support ? '1' : '0' );
        $import_comments = isset( $_POST['import_comments'] ) ? sanitize_text_field( wp_unslash( $_POST['import_comments'] ) ) : 'none';
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
        $extracted_dir = ! empty( $post['extracted_dir'] ) ? $post['extracted_dir'] : ( ! empty( $manifest['extracted_dir'] ) ? $manifest['extracted_dir'] : $session_dir );
        
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

        // Date parsing
        $raw_date = ! empty( $post['Creation time'] ) ? $post['Creation time'] : '';
        $timestamp = $this->parse_quora_date( $raw_date );
        
        $post_date = wp_date( 'Y-m-d H:i:s', $timestamp );
        $post_date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
        
        // Clean HTML content to calculate length
        $content = ! empty( $post['Content'] ) ? $post['Content'] : '';
        $content = preg_replace( '/\[\/?math\]/i', '$', $content );
        $content = $this->clean_html_newlines( $content );
        $content = $this->process_html_links( $content );
        if ( $r2w_support ) {
            $content = $this->format_wikipedia_links_to_r2w( $content );
        }
        $content = $this->maybe_remove_bold_title( $content, $title, $post );
        
        // Set post status based on condition
        $status = 'draft'; // Default to draft
        $is_original_draft = ( strpos( strtolower( $type ), 'brouillon' ) !== false || strpos( strtolower( $type ), 'draft' ) !== false );
        $is_published = false;
        
        if ( ! $is_original_draft ) {
            $plain_text = wp_strip_all_tags( $content );
            $char_count = mb_strlen( trim( $plain_text ), 'UTF-8' );
            if ( $char_count > $min_chars_publish ) {
                $status = 'publish';
                $is_published = true;
            }
        }
        
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
        
        if ( ! $existing && $timestamp > 0 ) {
            $existing_query = new WP_Query( array(
                'post_type'           => 'post',
                'post_status'         => 'any',
                'date_query'          => array(
                    array(
                        'year'   => wp_date( 'Y', $timestamp ),
                        'month'  => wp_date( 'm', $timestamp ),
                        'day'    => wp_date( 'd', $timestamp ),
                        'hour'   => wp_date( 'H', $timestamp ),
                        'minute' => wp_date( 'i', $timestamp ),
                        'second' => wp_date( 's', $timestamp ),
                    ),
                ),
                'posts_per_page'      => 1,
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
            ) );
            $existing = $existing_query->have_posts() ? $existing_query->posts[0] : null;
        }
        
        if ( $existing ) {
            // Update title and content if they differ (e.g. due to clean title/text extraction)
            $update_data = array( 'ID' => $existing->ID );
            $needs_update = false;
            
            if ( $existing->post_title !== $title ) {
                $update_data['post_title'] = $title;
                $needs_update = true;
            }
            if ( $existing->post_content !== $content ) {
                $update_data['post_content'] = $content;
                $needs_update = true;
            }
            if ( $needs_update ) {
                wp_update_post( $update_data );
            }

            // Update categories
            $categories = array();
            if ( $type === 'Répondre' || $type === 'Answer' ) {
                $categories[] = $this->get_or_create_term( 'Quora Answers', 'category' );
            } elseif ( $type === 'Brouillon de réponse' || $type === 'Answer Draft' ) {
                $categories[] = $this->get_or_create_term( 'Quora Drafts', 'category' );
            } elseif ( in_array( $type, array( "Envoi d'espace", "Élément d'espace", 'Space post', 'Space share' ) ) ) {
                $categories[] = $this->get_or_create_term( 'Quora Space Posts', 'category' );
            } else {
                $categories[] = $this->get_or_create_term( 'Quora Export', 'category' );
            }
            if ( ! empty( $post['Space name'] ) ) {
                $categories[] = $this->get_or_create_term( $post['Space name'], 'category' );
            }
            if ( ! empty( $categories ) ) {
                wp_set_post_categories( $existing->ID, $categories );
            }

            // Always update candidates and URL
            $candidate_urls = $this->get_candidate_urls( $post, $extracted_dir, $author_id );
            update_post_meta( $existing->ID, '_quora_candidate_urls', $candidate_urls );
            
            $quora_url = '';
            if ( ! empty( $candidate_urls ) ) {
                $quora_url = $candidate_urls[0];
            }
            
            // If topic extraction is requested, validate the URL
            $extracted_topics = array();
            if ( $is_published && $extract_topics ) {
                $valid_url = '';
                foreach ( $candidate_urls as $candidate ) {
                    $topics = $this->extract_quora_topics( $candidate );
                    if ( ! empty( $topics ) ) {
                        $extracted_topics = $topics;
                        $valid_url = $candidate;
                        break;
                    }
                }
                
                if ( ! empty( $extracted_topics ) ) {
                    update_post_meta( $existing->ID, '_quora_url', $valid_url );
                    update_post_meta( $existing->ID, '_quora_url_status', 'valid' );
                    $current_tags = wp_get_post_tags( $existing->ID, array( 'fields' => 'names' ) );
                    if ( is_wp_error( $current_tags ) ) {
                        $current_tags = array();
                    }
                    $new_tags = array_unique( array_merge( $current_tags, $extracted_topics ) );
                    wp_set_post_tags( $existing->ID, $new_tags, false );
                } else {
                    update_post_meta( $existing->ID, '_quora_url_status', 'invalid' );
                    if ( ! empty( $quora_url ) ) {
                        update_post_meta( $existing->ID, '_quora_url', $quora_url );
                    }
                }
            } else {
                // If not testing topics, update the URL and mark it as untested
                if ( ! empty( $quora_url ) ) {
                    update_post_meta( $existing->ID, '_quora_url', $quora_url );
                    update_post_meta( $existing->ID, '_quora_url_status', 'untested' );
                }
            }
            
            wp_send_json_success( array(
                'status'   => 'skipped',
                'title'    => $title,
                'post_id'  => $existing->ID,
                'message'  => __( 'This post already exists in WordPress (updated).', 'quora-importer' ),
                'log_type' => 'info'
            ) );
        }
        
        // Sideload images if requested
        $images_imported = 0;
        $featured_image_id = 0;
        
        if ( $import_images && $manifest['has_images'] ) {
            $sideload_result = $this->sideload_content_images( $content, $extracted_dir, $set_featured, $post_date );
            $content = $sideload_result['content'];
            $images_imported = $sideload_result['count'];
            $featured_image_id = $sideload_result['featured_id'];
        }
        
        // Generate Quora URL if link is requested or topics extraction is enabled (topics only if published)
        $quora_url = '';
        $extracted_topics = array();
        $candidate_urls = $this->get_candidate_urls( $post, $extracted_dir, $author_id );
        
        if ( $is_published && $extract_topics ) {
            foreach ( $candidate_urls as $candidate ) {
                $topics = $this->extract_quora_topics( $candidate );
                if ( ! empty( $topics ) ) {
                    $extracted_topics = $topics;
                    $quora_url = $candidate;
                    break;
                }
            }
        }
        
        if ( empty( $quora_url ) && ! empty( $candidate_urls ) ) {
            $quora_url = $candidate_urls[0];
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
        
        // Always generate and store Quora URL candidates
        $quora_urls = $candidate_urls;
        
        if ( ! empty( $quora_urls ) ) {
            $initial_url = $quora_url;
            $initial_status = 'untested';
            if ( $extract_topics && $is_published ) {
                if ( ! empty( $extracted_topics ) ) {
                    $initial_status = 'valid';
                } else {
                    $initial_status = 'invalid';
                }
            }
            update_post_meta( $post_id, '_quora_url', $initial_url );
            update_post_meta( $post_id, '_quora_candidate_urls', $quora_urls );
            update_post_meta( $post_id, '_quora_url_status', $initial_status );
        }

        // Import comments if requested (only for published posts)
        $comments_imported = 0;
        $comments_warning = '';
        
        if ( $is_published && 'none' !== $import_comments ) {
            if ( 'direct' === $import_comments ) {
                if ( ! empty( $quora_urls ) ) {
                    $comments_res = $this->import_quora_comments( $post_id, $quora_urls, $post_date );
                    if ( $comments_res['success'] ) {
                        $comments_imported = $comments_res['count'];
                    } else {
                        $comments_warning = $comments_res['error'];
                    }
                }
            } elseif ( 'deferred' === $import_comments ) {
                update_post_meta( $post_id, '_quora_comments_imported', '0' );
            }
        }

        $log_type = 'info';
        $warnings = array();
        if ( $extract_topics && ! empty( $this->last_topic_error ) ) {
            $warnings[] = sprintf( __( 'Topic extraction failed (%s)', 'quora-importer' ), $this->last_topic_error );
        }
        if ( ! empty( $comments_warning ) ) {
            $warnings[] = sprintf( __( 'Comment import failed (%s)', 'quora-importer' ), $comments_warning );
        }
        
        $msg_parts = array();
        $msg_parts[] = sprintf( __( 'Post imported successfully (ID: %d)', 'quora-importer' ), $post_id );
        if ( $images_imported > 0 ) {
            $msg_parts[] = sprintf( _n( '%d image', '%d images', $images_imported, 'quora-importer' ), $images_imported );
        }
        if ( ! empty( $extracted_topics ) ) {
            $msg_parts[] = sprintf( _n( '%d topic', '%d topics', count( $extracted_topics ), 'quora-importer' ), count( $extracted_topics ) );
        }
        if ( $comments_imported > 0 ) {
            $msg_parts[] = sprintf( _n( '%d comment', '%d comments', $comments_imported, 'quora-importer' ), $comments_imported );
        }
        
        $msg = implode( ', ', $msg_parts ) . '.';
        
        if ( ! empty( $warnings ) ) {
            $msg .= ' Warning: ' . implode( '; ', $warnings ) . '.';
            $log_type = 'warning';
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
                                            if ( empty( $target_url ) && $this->validate_quora_url( $href ) ) {
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
                                        if ( empty( $target_url ) && $this->validate_quora_url( $href ) ) {
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
     * Validates a URL, supporting international/accented characters.
     */
    private function validate_quora_url( $url ) {
        if ( empty( $url ) ) {
            return false;
        }
        if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return true;
        }
        $encoded_url = preg_replace_callback( '/[^\x21-\x7E]/', function( $matches ) {
            return rawurlencode( $matches[0] );
        }, $url );
        return (bool) filter_var( $encoded_url, FILTER_VALIDATE_URL );
    }
    
    /**
     * Helper to check if a post is a space post
     */
    private function is_space_post( $post, $type ) {
        if ( ! empty( $post['Space name'] ) ) {
            return true;
        }
        
        $space_types = array(
            'Space post',
            'Space share',
            "Envoi d'espace",
            "Élément d'espace",
            'Billet',
            'Publication',
            'Post',
            'Partage',
            'Lien partagé'
        );
        foreach ( $space_types as $st ) {
            if ( strcasecmp( $type, $st ) === 0 || html_entity_decode( $type ) === $st ) {
                return true;
            }
        }
        
        // Check if direct URL has a space subdomain
        foreach ( array( 'Answer', 'Question', 'Link', 'url', 'Share url', 'Share URL' ) as $key ) {
            if ( ! empty( $post[$key] ) ) {
                $val = trim( $post[$key] );
                if ( $this->validate_quora_url( $val ) ) {
                    $host = wp_parse_url( $val, PHP_URL_HOST );
                    if ( $host && preg_match( '/^([^.]+)\.quora\.com$/i', $host, $matches ) ) {
                        $sub = strtolower( $matches[1] );
                        if ( ! in_array( $sub, array( 'www', 'fr', 'es', 'de', 'it', 'en' ) ) ) {
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Get or generate a clean title for the post
     */
    private function get_post_title( $post, $type ) {
        $is_space_post = $this->is_space_post( $post, $type );
        
        // 1. Try to reconstruct title from the URL slug if available (especially for space posts without a Question/Title field)
        $url_slug = '';
        foreach ( array( 'Answer', 'Question', 'Link', 'url', 'Share url', 'Share URL' ) as $key ) {
            if ( ! empty( $post[$key] ) ) {
                $val = trim( $post[$key] );
                if ( $this->validate_quora_url( $val ) ) {
                    $path = wp_parse_url( $val, PHP_URL_PATH );
                    if ( $path ) {
                        $path = trim( $path, '/' );
                        if ( preg_match( '/^([^\/]+)\/answer\//i', $path, $matches ) ) {
                            $url_slug = urldecode( $matches[1] );
                        } else {
                            $url_slug = urldecode( $path );
                        }
                        if ( ! empty( $url_slug ) ) {
                            break;
                        }
                    }
                }
            }
        }
        
        if ( ! empty( $url_slug ) && ! empty( $post['Content'] ) ) {
            $content_text = trim( wp_strip_all_tags( $post['Content'] ) );
            $content_text = preg_replace( '/\s+/', ' ', $content_text );
            
            // Clean up slug into words
            $slug_words = explode( '-', $url_slug );
            $slug_words = array_filter( array_map( function( $w ) {
                return mb_strtolower( preg_replace( '/[^\p{L}\p{N}]/u', '', remove_accents( $w ) ) );
            }, $slug_words ) );
            
            if ( ! empty( $slug_words ) ) {
                $normalized_text = mb_strtolower( remove_accents( $content_text ) );
                $pos = 0;
                $matched_all = true;
                foreach ( $slug_words as $word ) {
                    $word_pos = mb_strpos( $normalized_text, $word, $pos );
                    if ( $word_pos !== false && $word_pos - $pos < 100 ) {
                        $pos = $word_pos + mb_strlen( $word );
                    } else {
                        $matched_all = false;
                        break;
                    }
                }
                
                if ( $matched_all && $pos > 0 ) {
                    $matched_title = mb_substr( $content_text, 0, $pos );
                    $matched_title = rtrim( $matched_title, " ,.:;!?-()[]{}'’\"" );
                    $matched_title = preg_replace( '/\b([LdNsCjtm])[\'’]\s+/i', '$1\'', $matched_title );
                    $matched_title = str_replace( '’', "'", $matched_title );
                    
                    if ( ! empty( $matched_title ) && mb_strlen( $matched_title ) < 200 ) {
                        return $matched_title;
                    }
                }
            }
        }
        
        if ( $is_space_post && ! empty( $post['Content'] ) ) {
            $content = $post['Content'];
            
            $doc = new DOMDocument();
            libxml_use_internal_errors( true );
            $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
            libxml_clear_errors();
            
            $bold_tags = array();
            foreach ( array( 'b', 'strong' ) as $tag_name ) {
                $tags = $doc->getElementsByTagName( $tag_name );
                if ( $tags->length > 0 ) {
                    $bold_tags[] = $tags->item( 0 );
                }
            }
            
            if ( ! empty( $bold_tags ) ) {
                $first_bold = null;
                $min_pos = -1;
                foreach ( $bold_tags as $tag ) {
                    $html_tag = $doc->saveHTML( $tag );
                    $pos = strpos( $content, $html_tag );
                    if ( $pos !== false && ( $min_pos === -1 || $pos < $min_pos ) ) {
                        $min_pos = $pos;
                        $first_bold = $tag;
                    }
                }
                
                if ( $first_bold ) {
                    $bold_text = trim( $first_bold->textContent );
                    if ( ! empty( $bold_text ) ) {
                        $text_before = trim( wp_strip_all_tags( substr( $content, 0, $min_pos ) ) );
                        if ( strlen( $text_before ) < 100 && mb_strlen( $bold_text ) < 250 ) {
                            $bold_text = preg_replace( '/\s+/', ' ', $bold_text );
                            return trim( $bold_text );
                        }
                    }
                }
            }
        }

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
     * Maybe remove the bold title from the content of a space post
     */
    private function maybe_remove_bold_title( $content, $title, $post ) {
        $type = ! empty( $post['type'] ) ? $post['type'] : '';
        $is_space_post = $this->is_space_post( $post, $type );
        if ( ! $is_space_post || empty( $content ) || empty( $title ) ) {
            return $content;
        }

        // Parse with DOMDocument to find the first bold tag at the beginning
        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
        libxml_clear_errors();
        
        $bold_tags = array();
        foreach ( array( 'b', 'strong' ) as $tag_name ) {
            $tags = $doc->getElementsByTagName( $tag_name );
            if ( $tags->length > 0 ) {
                $bold_tags[] = $tags->item( 0 );
            }
        }
        
        if ( ! empty( $bold_tags ) ) {
            $first_bold = null;
            $min_pos = -1;
            foreach ( $bold_tags as $tag ) {
                $html_tag = $doc->saveHTML( $tag );
                $pos = strpos( $content, $html_tag );
                if ( $pos !== false && ( $min_pos === -1 || $pos < $min_pos ) ) {
                    $min_pos = $pos;
                    $first_bold = $tag;
                }
            }
            
            if ( $first_bold ) {
                $bold_text = trim( $first_bold->textContent );
                $bold_text_clean = preg_replace( '/\s+/', ' ', $bold_text );
                
                // Compare with title
                if ( strcasecmp( trim( $title ), trim( $bold_text_clean ) ) === 0 ) {
                    $text_before = trim( wp_strip_all_tags( substr( $content, 0, $min_pos ) ) );
                    if ( strlen( $text_before ) < 100 ) {
                        $parent = $first_bold->parentNode;
                        if ( $parent && ( $parent->nodeName === 'p' || $parent->nodeName === 'div' ) ) {
                            $parent_text = trim( $parent->textContent );
                            if ( strcasecmp( $parent_text, $bold_text ) === 0 ) {
                                $parent->parentNode->removeChild( $parent );
                            } else {
                                $parent->removeChild( $first_bold );
                            }
                        } else {
                            $first_bold->parentNode->removeChild( $first_bold );
                        }
                        
                        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
                        $new_content = '';
                        foreach ( $body->childNodes as $child ) {
                            $new_content .= $doc->saveHTML( $child );
                        }
                        return trim( $new_content );
                    }
                }
            }
        }
        
        return $content;
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
     * Converts Wikipedia <a> tags to Reference 2 Wiki syntax [[lang|article|text]].
     */
    public function format_wikipedia_links_to_r2w( $content ) {
        if ( empty( $content ) ) {
            return '';
        }

        $pattern = '/<a\s+[^>]*href=["\'](https?:\/\/[a-z0-9\-]+\.wikipedia\.org\/wiki\/[^"\']+)["\'][^>]*>(.*?)<\/a>/is';

        return preg_replace_callback( $pattern, function( $matches ) {
            $url = html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $link_text = $matches[2];

            if ( preg_match( '/^https?:\/\/([a-z0-9\-]+)\.wikipedia\.org\/wiki\/([^?#]+)/i', $url, $url_matches ) ) {
                $lang = $url_matches[1];
                $slug = rawurldecode( $url_matches[2] );
                $clean_text = trim( wp_strip_all_tags( $link_text ) );
                
                return '[[' . $lang . '|' . $slug . '|' . $clean_text . ']]';
            }

            return $matches[0];
        }, $content );
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
    private function generate_quora_url( $post, $extracted_dir, $author_id = 0, $replace_apostrophes = false, $force_slugify = false ) {
        if ( ! $force_slugify ) {
            // First, check if there is a direct URL already parsed from the HTML export
            foreach ( array( 'Answer', 'Question', 'Link', 'url', 'Share url', 'Share URL' ) as $key ) {
                if ( ! empty( $post[$key] ) ) {
                    $val = trim( $post[$key] );
                    if ( $this->validate_quora_url( $val ) ) {
                        return $val;
                    }
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
        
        $has_apostrophes = ( false !== strpos( $title, "'" ) || false !== strpos( $title, "’" ) );
        
        if ( $has_apostrophes ) {
            $title_slug = $this->quora_slugify( $title, $replace_apostrophes );
            return $this->build_quora_url_with_slug( $post, $type, $title_slug, $profile_slug, $domain, $is_answer );
        } else {
            $title_slug = $this->quora_slugify( $title, false );
            return $this->build_quora_url_with_slug( $post, $type, $title_slug, $profile_slug, $domain, $is_answer );
        }
    }

    /**
     * Get candidate Quora URLs based on title containing apostrophes or not
     */
    private function get_candidate_urls( $post, $extracted_dir, $author_id = 0 ) {
        $title = '';
        if ( ! empty( $post['Question'] ) ) {
            $title = $post['Question'];
        } elseif ( ! empty( $post['Title'] ) ) {
            $title = $post['Title'];
        }
        if ( empty( $title ) && ! empty( $post['Content'] ) ) {
            $title = $this->get_post_title( $post, ! empty( $post['type'] ) ? $post['type'] : '' );
        }
        
        $has_apostrophes = ( false !== strpos( $title, "'" ) || false !== strpos( $title, "’" ) );
        if ( $has_apostrophes ) {
            $url_a = $this->generate_quora_url( $post, $extracted_dir, $author_id, true, true );
            $url_b = $this->generate_quora_url( $post, $extracted_dir, $author_id, false, true );
            $direct_url = $this->generate_quora_url( $post, $extracted_dir, $author_id, false, false );
            
            $urls = array();
            if ( ! empty( $direct_url ) && $this->validate_quora_url( $direct_url ) ) {
                $urls[] = $direct_url;
            }
            if ( ! empty( $url_a ) && $this->validate_quora_url( $url_a ) && ! in_array( $url_a, $urls ) ) {
                $urls[] = $url_a;
            }
            if ( ! empty( $url_b ) && $this->validate_quora_url( $url_b ) && ! in_array( $url_b, $urls ) ) {
                $urls[] = $url_b;
            }
            return $urls;
        }
        
        $quora_url = $this->generate_quora_url( $post, $extracted_dir, $author_id, false, false );
        return array( $quora_url );
    }

    /**
     * Decode a JSON string from a shell output containing possible prepended/appended warnings.
     */
    private function decode_shell_json( $output_array ) {
        $output_str = implode( '', $output_array );
        $start_pos = strpos( $output_str, '{' );
        $end_pos = strrpos( $output_str, '}' );
        if ( false !== $start_pos && false !== $end_pos && $end_pos > $start_pos ) {
            $json_str = substr( $output_str, $start_pos, $end_pos - $start_pos + 1 );
            return json_decode( $json_str, true );
        }
        return json_decode( $output_str, true );
    }

    /**
     * Build the actual Quora URL structure using the provided slug
     */
    private function build_quora_url_with_slug( $post, $type, $title_slug, $profile_slug, $domain, $is_answer ) {
        if ( $is_answer && ! empty( $profile_slug ) && ! empty( $title_slug ) ) {
            return "https://{$domain}/{$title_slug}/answer/{$profile_slug}";
        }
        
        // Extract space subdomain
        $space_subdomain = '';
        
        // 1. Try to extract it from the direct URL (which is always the most accurate source)
        foreach ( array( 'Answer', 'Question', 'Link', 'url', 'Share url', 'Share URL' ) as $key ) {
            if ( ! empty( $post[$key] ) ) {
                $val = trim( $post[$key] );
                if ( $this->validate_quora_url( $val ) ) {
                    $host = wp_parse_url( $val, PHP_URL_HOST );
                    if ( $host && preg_match( '/^([^.]+)\.quora\.com$/i', $host, $matches ) ) {
                        $sub = strtolower( $matches[1] );
                        if ( ! in_array( $sub, array( 'www', 'fr', 'es', 'de', 'it', 'en' ) ) ) {
                            $space_subdomain = $sub;
                            break;
                        }
                    }
                }
            }
        }
        
        // 2. Fall back to Space name if not found in direct URL
        if ( empty( $space_subdomain ) && ! empty( $post['Space name'] ) ) {
            $normalized = remove_accents( $post['Space name'] );
            $space_subdomain = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $normalized ) );
        }
        
        if ( ! empty( $space_subdomain ) ) {
            if ( ! empty( $title_slug ) ) {
                return "https://{$space_subdomain}.quora.com/{$title_slug}";
            }
            return "https://{$space_subdomain}.quora.com";
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
    private function quora_slugify( $title, $replace_apostrophes = false ) {
        if ( empty( $title ) ) {
            return '';
        }
        
        // Strip [math] and [/math] tags if present
        $title = preg_replace( '/\[\/?math\]/i', '', $title );

        if ( $replace_apostrophes ) {
            // Option A: replace apostrophes with spaces (which become hyphens)
            $title = str_replace( array( "'", '’' ), ' ', $title );
        } else {
            // Option B: delete all apostrophes
            $title = str_replace( array( "'", '’' ), '', $title );
        }

        // Replace slashes, underscores, carets, parentheses, brackets, and braces with spaces to prevent word merging
        $title = str_replace( array( '/', '_', '^', '(', ')', '[', ']', '{', '}' ), ' ', $title );


        
        // Strip everything except letters, numbers, spaces, hyphens, and plus signs (preserving accents and case)
        $slug = preg_replace( '/[^\p{L}\p{N}\s\-\+]/u', '', $title );
        
        // Replace spaces/tabs and consecutive hyphens with a single hyphen
        $slug = preg_replace( '/[\s\-]+/u', '-', $slug );
        $slug = trim( $slug, '-' );
        
        // Truncate slug to 190 UTF-8 characters to match Quora answer URLs
        if ( mb_strlen( $slug, 'UTF-8' ) > 190 ) {
            $truncated = mb_substr( $slug, 0, 190, 'UTF-8' );
            $next_char = mb_substr( $slug, 190, 1, 'UTF-8' );
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

        // Enforce URL-encoded length limit of 255 characters
        $encoded = rawurlencode( $slug );
        if ( strlen( $encoded ) > 255 ) {
            while ( strlen( rawurlencode( $slug ) ) > 255 && mb_strlen( $slug, 'UTF-8' ) > 0 ) {
                $slug = mb_substr( $slug, 0, -1, 'UTF-8' );
            }
            $last_hyphen = mb_strrpos( $slug, '-', 0, 'UTF-8' );
            if ( $last_hyphen !== false ) {
                $slug = mb_substr( $slug, 0, $last_hyphen, 'UTF-8' );
            }
        }
        
        // URL-encode all accented and special characters while preserving hyphens and plus signs
        return str_replace( '%2B', '+', rawurlencode( $slug ) );
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
                $response_data = $this->decode_shell_json( $output );
                if ( is_array( $response_data ) && isset( $response_data['success'] ) ) {
                    if ( $response_data['success'] ) {
                        return $response_data['topics'];
                    } else {
                        $this->last_topic_error = $response_data['error'];
                        return array();
                    }
                } else {
                    $topics = $this->decode_shell_json( $output );
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

    /**
     * Scrape and import comments for a specific post
     */
    private function import_quora_comments( $post_id, $quora_url, $post_date ) {
        $python_script = plugin_dir_path( __FILE__ ) . 'scrape_comments.py';
        if ( ! file_exists( $python_script ) ) {
            return array( 'success' => false, 'error' => __( 'Comments scraper script not found.', 'quora-importer' ) );
        }
        
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
        
        $url_args = '';
        if ( is_array( $quora_url ) ) {
            foreach ( $quora_url as $u ) {
                $url_args .= ' ' . escapeshellarg( $u );
            }
        } else {
            $url_args = ' ' . escapeshellarg( $quora_url );
        }
        
        $cmd = escapeshellcmd( $python_executable ) . ' ' . escapeshellarg( $python_script ) . $url_args;
        $output = array();
        $retval = null;
        exec( $cmd, $output, $retval );
        
        if ( 0 !== $retval || empty( $output ) ) {
            return array( 'success' => false, 'error' => __( 'Failed to execute comments scraper.', 'quora-importer' ) );
        }
        
        $response_data = $this->decode_shell_json( $output );
        if ( ! is_array( $response_data ) || ! isset( $response_data['success'] ) ) {
            return array( 'success' => false, 'error' => __( 'Invalid scraper response format.', 'quora-importer' ) );
        }
        
        if ( ! $response_data['success'] ) {
            if ( false !== strpos( $response_data['error'], 'could not be loaded' ) ) {
                update_post_meta( $post_id, '_quora_url_status', 'invalid' );
            }
            return array( 'success' => false, 'error' => $response_data['error'] );
        }
        
        update_post_meta( $post_id, '_quora_url_status', 'valid' );
        
        if ( ! empty( $response_data['resolved_url'] ) ) {
            $old_url = get_post_meta( $post_id, '_quora_url', true );
            $resolved_url = $response_data['resolved_url'];
            
            if ( $old_url !== $resolved_url ) {
                update_post_meta( $post_id, '_quora_url', $resolved_url );
                
                if ( ! empty( $old_url ) ) {
                    $post_obj = get_post( $post_id );
                    if ( $post_obj ) {
                        $new_content = str_replace( 
                            array(
                                $old_url, 
                                esc_url( $old_url ), 
                                rawurlencode( $old_url ),
                                esc_attr( $old_url ),
                                rawurldecode( $old_url )
                            ), 
                            $resolved_url, 
                            $post_obj->post_content 
                        );
                        if ( $new_content !== $post_obj->post_content ) {
                            if ( has_action( 'save_post', array( $this, 'save_quora_url_meta_box_data' ) ) ) {
                                remove_action( 'save_post', array( $this, 'save_quora_url_meta_box_data' ) );
                                wp_update_post( array(
                                    'ID'           => $post_id,
                                    'post_content' => $new_content
                                ) );
                                add_action( 'save_post', array( $this, 'save_quora_url_meta_box_data' ) );
                            } else {
                                wp_update_post( array(
                                    'ID'           => $post_id,
                                    'post_content' => $new_content
                                ) );
                            }
                        }
                    }
                }
            }
        }
        
        $comments = isset( $response_data['comments'] ) ? $response_data['comments'] : array();
        if ( empty( $comments ) ) {
            return array( 'success' => true, 'count' => 0 );
        }
        
        $count = $this->import_quora_comments_to_post( $post_id, $comments, $post_date );
        return array( 'success' => true, 'count' => $count );
    }

    /**
     * Recursive comment importer supporting hierarchy mapping
     */
    private function import_quora_comments_to_post( $post_id, $comments, $post_date ) {
        $id_map = array();
        $imported_comments_count = 0;
        
        foreach ( $comments as $c ) {
            $quora_id = isset( $c['id'] ) ? $c['id'] : '';
            $author = isset( $c['author'] ) ? $c['author'] : 'Anonyme';
            $profile_url = isset( $c['profile_url'] ) ? $c['profile_url'] : '';
            $text = isset( $c['text'] ) ? $c['text'] : '';
            if ( get_option( 'quora_importer_r2w_support' ) === '1' ) {
                $text = $this->format_wikipedia_links_to_r2w( $text );
            }
            $date_text = isset( $c['date'] ) ? $c['date'] : '';
            $parent_quora_id = isset( $c['parent_id'] ) ? $c['parent_id'] : '';
            
            $comment_parent_wp_id = 0;
            if ( ! empty( $parent_quora_id ) && isset( $id_map[$parent_quora_id] ) ) {
                $comment_parent_wp_id = $id_map[$parent_quora_id];
            }
            
            $parsed_date = $this->parse_relative_date( $date_text, $post_date );
            $parsed_date_gmt = get_gmt_from_date( $parsed_date );
            
            $commentdata = array(
               'comment_post_ID'      => $post_id,
               'comment_author'       => $author,
               'comment_author_url'   => $profile_url,
               'comment_content'      => $text,
               'comment_parent'       => $comment_parent_wp_id,
               'comment_type'         => 'comment',
               'comment_date'         => $parsed_date,
               'comment_date_gmt'     => $parsed_date_gmt,
               'comment_approved'     => 1,
            );
            
            $wp_comment_id = wp_insert_comment( $commentdata );
            if ( $wp_comment_id ) {
                $id_map[$quora_id] = $wp_comment_id;
                $imported_comments_count++;
            }
        }
        return $imported_comments_count;
    }

    /**
     * Parse relative or absolute date from Quora and return standard MySQL format
     */
    private function parse_relative_date( $date_str, $post_date_str ) {
        $now = time();
        $post_time = strtotime( $post_date_str );
        if ( ! $post_time ) {
            $post_time = $now;
        }

        $date_str = trim( $date_str );
        if ( empty( $date_str ) ) {
            return date( 'Y-m-d H:i:s', $post_time + 86400 );
        }

        $french_months = array(
            'janvier'   => 'January',
            'février'   => 'February',
            'mars'      => 'March',
            'avril'     => 'April',
            'mai'       => 'May',
            'juin'      => 'June',
            'juillet'   => 'July',
            'août'      => 'August',
            'septembre' => 'September',
            'octobre'   => 'October',
            'novembre'  => 'November',
            'décembre'  => 'December'
        );

        $normalized_date = str_replace( array_keys( $french_months ), array_values( $french_months ), strtolower( $date_str ) );
        $parsed_time = strtotime( $normalized_date );
        if ( $parsed_time !== false && $parsed_time > 0 ) {
            return date( 'Y-m-d H:i:s', $parsed_time );
        }

        if ( preg_match( '/^(\d+)\s*(ans?|y|yrs?)/iu', $date_str, $matches ) ) {
            $val = (int) $matches[1];
            $computed = strtotime( "-{$val} years", $now );
        } elseif ( preg_match( '/^(\d+)\s*(mois|mo|mos?)/iu', $date_str, $matches ) ) {
            $val = (int) $matches[1];
            $computed = strtotime( "-{$val} months", $now );
        } elseif ( preg_match( '/^(\d+)\s*(sem\.?|w|weeks?)/iu', $date_str, $matches ) ) {
            $val = (int) $matches[1];
            $computed = strtotime( "-{$val} weeks", $now );
        } elseif ( preg_match( '/^(\d+)\s*(j|d|days?)/iu', $date_str, $matches ) ) {
            $val = (int) $matches[1];
            $computed = strtotime( "-{$val} days", $now );
        } elseif ( preg_match( '/^(\d+)\s*(h|hours?)/iu', $date_str, $matches ) ) {
            $val = (int) $matches[1];
            $computed = strtotime( "-{$val} hours", $now );
        } elseif ( preg_match( '/^(\d+)\s*(min|m|minutes?)/iu', $date_str, $matches ) ) {
            $val = (int) $matches[1];
            $computed = strtotime( "-{$val} minutes", $now );
        } elseif ( preg_match( '/^(\d+)\s*(s|seconds?)/iu', $date_str, $matches ) ) {
            $val = (int) $matches[1];
            $computed = strtotime( "-{$val} seconds", $now );
        } else {
            $computed = false;
        }

        if ( $computed !== false ) {
            if ( $computed < $post_time ) {
                $computed = $post_time + 86400;
            }
            return date( 'Y-m-d H:i:s', $computed );
        }

        return date( 'Y-m-d H:i:s', $post_time + 86400 );
    }

    /**
     * Schedules the deferred comments scraping when a single post page is visited.
     */
    public function maybe_schedule_deferred_comments() {
        if ( ! is_single() ) {
            return;
        }
        
        $post_id = get_the_ID();
        $imported = get_post_meta( $post_id, '_quora_comments_imported', true );
        
        if ( '0' === $imported ) {
            if ( ! wp_next_scheduled( 'quora_import_deferred_comments', array( $post_id ) ) ) {
                wp_schedule_single_event( time(), 'quora_import_deferred_comments', array( $post_id ) );
            }
        }
    }

    /**
     * Cron action handler to fetch comments for a post in the background.
     */
    public function cron_import_comments( $post_id ) {
        $status = get_post_meta( $post_id, '_quora_comments_imported', true );
        if ( '0' !== $status ) {
            return; // Already imported or currently scraping
        }
        
        // Lock the process
        update_post_meta( $post_id, '_quora_comments_imported', 'scraping' );
        
        $quora_urls = get_post_meta( $post_id, '_quora_candidate_urls', true );
        if ( empty( $quora_urls ) ) {
            $quora_url = get_post_meta( $post_id, '_quora_url', true );
            if ( ! empty( $quora_url ) ) {
                $quora_urls = array( $quora_url );
            }
        }
        
        if ( empty( $quora_urls ) ) {
            update_post_meta( $post_id, '_quora_comments_imported', 'failed_no_url' );
            return;
        }
        
        $post = get_post( $post_id );
        $post_date = $post ? $post->post_date : current_time( 'mysql' );
        
        $res = $this->import_quora_comments( $post_id, $quora_urls, $post_date );
        
        if ( $res['success'] ) {
            update_post_meta( $post_id, '_quora_comments_imported', '1' );
        } else {
            // Revert status to 0 so it can be retried on subsequent visits
            update_post_meta( $post_id, '_quora_comments_imported', '0' );
        }
    }

    /**
     * Add Custom Meta Box for editing and validating Quora URL
     */
    public function add_quora_url_meta_box() {
        add_meta_box(
            'quora-url-meta-box',
            __( 'Quora Importer - URL de l\'article', 'quora-importer' ),
            array( $this, 'render_quora_url_meta_box' ),
            'post',
            'side',
            'high'
        );
    }

    /**
     * Render Quora URL Meta Box content
     */
    public function render_quora_url_meta_box( $post ) {
        wp_nonce_field( 'quora_url_meta_box', 'quora_url_meta_box_nonce' );

        $quora_url = get_post_meta( $post->ID, '_quora_url', true );
        $quora_status = get_post_meta( $post->ID, '_quora_url_status', true );
        $quora_override = get_post_meta( $post->ID, '_quora_url_override', true );
        if ( empty( $quora_status ) ) {
            $quora_status = 'untested';
        }

        $quora_url = esc_url( $quora_url );

        $badge_color = '#72777c';
        $status_label = __( 'Non testé', 'quora-importer' );
        if ( $quora_status === 'valid' ) {
            $badge_color = '#46b450';
            $status_label = __( 'Valide', 'quora-importer' );
        } elseif ( $quora_status === 'invalid' ) {
            $badge_color = '#dc3232';
            $status_label = __( 'Invalide', 'quora-importer' );
        }
        ?>
        <div class="quora-meta-box-container" style="font-family: -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
            <p>
                <label for="quora_url_input" style="font-weight: 600; display: block; margin-bottom: 5px;"><?php _e( 'URL de la réponse Quora :', 'quora-importer' ); ?></label>
                <input type="url" id="quora_url_input" name="quora_url" value="<?php echo esc_attr( $quora_url ); ?>" style="width: 100%; box-sizing: border-box;" placeholder="https://fr.quora.com/... /answer/..." />
            </p>
            <p style="margin-top: 10px; margin-bottom: 10px;">
                <input type="checkbox" id="quora_url_override_input" name="quora_url_override" value="1" <?php checked( $quora_override, '1' ); ?> />
                <label for="quora_url_override_input" style="font-weight: 500; font-size: 13px; color: #555;"><?php _e( 'Verrouiller cette URL (ne pas écraser)', 'quora-importer' ); ?></label>
            </p>
            
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 15px; margin-bottom: 10px;">
                <div style="display: flex; align-items: center;">
                    <span id="quora-status-dot" style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background-color: <?php echo esc_attr( $badge_color ); ?>; margin-right: 8px; transition: background-color 0.3s ease;"></span>
                    <span id="quora-status-text" style="font-size: 13px; font-weight: 500; color: #555;"><?php echo esc_html( $status_label ); ?></span>
                </div>
                <button type="button" id="quora-test-url-btn" class="button button-secondary" style="height: 28px; line-height: 26px; display: inline-flex; align-items: center; justify-content: center;">
                    <span class="spinner" style="float: none; margin: 0 5px 0 0; display: none; vertical-align: middle; visibility: visible;"></span>
                    <span class="btn-text"><?php _e( 'Tester', 'quora-importer' ); ?></span>
                </button>
            </div>
            
            <div id="quora-test-msg" style="margin-top: 8px; font-size: 12px; font-style: italic; display: none;"></div>

            <div id="quora-action-buttons" style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 12px; display: <?php echo ($quora_status === 'valid') ? 'block' : 'none'; ?>;">
                <button type="button" id="quora-import-tags-btn" class="button button-secondary" style="width: 100%; margin-bottom: 8px; justify-content: center; display: inline-flex; align-items: center; text-align: center;">
                    <span class="spinner" style="float: none; margin: 0 5px 0 0; display: none; vertical-align: middle; visibility: visible;"></span>
                    <span class="btn-text"><?php _e( 'Importer les étiquettes', 'quora-importer' ); ?></span>
                </button>
                <button type="button" id="quora-update-comments-btn" class="button button-secondary" style="width: 100%; justify-content: center; display: inline-flex; align-items: center; text-align: center;">
                    <span class="spinner" style="float: none; margin: 0 5px 0 0; display: none; vertical-align: middle; visibility: visible;"></span>
                    <span class="btn-text"><?php _e( 'Mettre à jour les commentaires', 'quora-importer' ); ?></span>
                </button>
            </div>

            <style>
                #quora-test-url-btn:disabled, #quora-import-tags-btn:disabled, #quora-update-comments-btn:disabled {
                    opacity: 0.7;
                    cursor: not-allowed;
                }
                #quora-status-dot {
                    box-shadow: 0 0 5px rgba(0,0,0,0.1);
                }
                #quora-status-dot[style*="background-color: #46b450"] {
                    box-shadow: 0 0 8px rgba(70,180,80,0.5);
                }
                #quora-status-dot[style*="background-color: #dc3232"] {
                    box-shadow: 0 0 8px rgba(220,50,50,0.5);
                }
            </style>

            <script>
                jQuery(document).ready(function($) {
                    $('#quora-test-url-btn').on('click', function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        var $spinner = $btn.find('.spinner');
                        var $btnText = $btn.find('.btn-text');
                        var $dot = $('#quora-status-dot');
                        var $text = $('#quora-status-text');
                        var $msg = $('#quora-test-msg');
                        var urlVal = $('#quora_url_input').val();
                        
                        $btn.prop('disabled', true);
                        $spinner.show();
                        $btnText.text('<?php echo esc_js( __( 'Validation...', 'quora-importer' ) ); ?>');
                        $msg.hide();
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'quora_test_url',
                                url: urlVal,
                                post_id: <?php echo intval( $post->ID ); ?>,
                                security: '<?php echo wp_create_nonce( "quora-test-url-nonce" ); ?>'
                            },
                            success: function(response) {
                                $btn.prop('disabled', false);
                                $spinner.hide();
                                $btnText.text('<?php echo esc_js( __( 'Tester', 'quora-importer' ) ); ?>');
                                
                                if (response.success) {
                                    var status = response.data.status;
                                    var msg = response.data.message;
                                    
                                    if (status === 'valid') {
                                        $dot.css('background-color', '#46b450');
                                        $text.text('<?php echo esc_js( __( 'Valide', 'quora-importer' ) ); ?>');
                                        $msg.css('color', '#46b450').text(msg).fadeIn();
                                        $('#quora-action-buttons').slideDown();
                                    } else {
                                        $dot.css('background-color', '#dc3232');
                                        $text.text('<?php echo esc_js( __( 'Invalide', 'quora-importer' ) ); ?>');
                                        $msg.css('color', '#dc3232').text(msg).fadeIn();
                                        $('#quora-action-buttons').slideUp();
                                    }
                                } else {
                                    var errMsg = response.data ? response.data.message : '<?php echo esc_js( __( 'Erreur de connexion.', 'quora-importer' ) ); ?>';
                                    $dot.css('background-color', '#72777c');
                                    $text.text('<?php echo esc_js( __( 'Non testé', 'quora-importer' ) ); ?>');
                                    $msg.css('color', '#dc3232').text(errMsg).fadeIn();
                                    $('#quora-action-buttons').slideUp();
                                }
                            },
                            error: function() {
                                $btn.prop('disabled', false);
                                $spinner.hide();
                                $btnText.text('<?php echo esc_js( __( 'Tester', 'quora-importer' ) ); ?>');
                                $dot.css('background-color', '#72777c');
                                $text.text('<?php echo esc_js( __( 'Non testé', 'quora-importer' ) ); ?>');
                                $msg.css('color', '#dc3232').text('<?php echo esc_js( __( 'Erreur de communication.', 'quora-importer' ) ); ?>').fadeIn();
                                $('#quora-action-buttons').slideUp();
                            }
                        });
                    });

                    // Import tags action
                    $('#quora-import-tags-btn').on('click', function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        var $spinner = $btn.find('.spinner');
                        var $btnText = $btn.find('.btn-text');
                        var $msg = $('#quora-test-msg');
                        var urlVal = $('#quora_url_input').val();
                        
                        $btn.prop('disabled', true);
                        $spinner.show();
                        $btnText.text('<?php echo esc_js( __( 'Importation...', 'quora-importer' ) ); ?>');
                        $msg.hide();
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'quora_import_post_tags',
                                url: urlVal,
                                post_id: <?php echo intval( $post->ID ); ?>,
                                security: '<?php echo wp_create_nonce( "quora-import-tags-nonce" ); ?>'
                            },
                            success: function(response) {
                                $btn.prop('disabled', false);
                                $spinner.hide();
                                $btnText.text('<?php echo esc_js( __( 'Importer les étiquettes', 'quora-importer' ) ); ?>');
                                if (response.success) {
                                    $msg.css('color', '#46b450').text(response.data.message).fadeIn();
                                } else {
                                    $msg.css('color', '#dc3232').text(response.data.message).fadeIn();
                                }
                            },
                            error: function() {
                                $btn.prop('disabled', false);
                                $spinner.hide();
                                $btnText.text('<?php echo esc_js( __( 'Importer les étiquettes', 'quora-importer' ) ); ?>');
                                $msg.css('color', '#dc3232').text('<?php echo esc_js( __( 'Erreur de communication.', 'quora-importer' ) ); ?>').fadeIn();
                            }
                        });
                    });

                    // Update comments action
                    $('#quora-update-comments-btn').on('click', function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        var $spinner = $btn.find('.spinner');
                        var $btnText = $btn.find('.btn-text');
                        var $msg = $('#quora-test-msg');
                        var urlVal = $('#quora_url_input').val();
                        
                        $btn.prop('disabled', true);
                        $spinner.show();
                        $btnText.text('<?php echo esc_js( __( 'Mise à jour...', 'quora-importer' ) ); ?>');
                        $msg.hide();
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'quora_update_post_comments',
                                url: urlVal,
                                post_id: <?php echo intval( $post->ID ); ?>,
                                security: '<?php echo wp_create_nonce( "quora-update-comments-nonce" ); ?>'
                            },
                            success: function(response) {
                                $btn.prop('disabled', false);
                                $spinner.hide();
                                $btnText.text('<?php echo esc_js( __( 'Mettre à jour les commentaires', 'quora-importer' ) ); ?>');
                                if (response.success) {
                                    $msg.css('color', '#46b450').text(response.data.message).fadeIn();
                                } else {
                                    $msg.css('color', '#dc3232').text(response.data.message).fadeIn();
                                }
                            },
                            error: function() {
                                $btn.prop('disabled', false);
                                $spinner.hide();
                                $btnText.text('<?php echo esc_js( __( 'Mettre à jour les commentaires', 'quora-importer' ) ); ?>');
                                $msg.css('color', '#dc3232').text('<?php echo esc_js( __( 'Erreur de communication.', 'quora-importer' ) ); ?>').fadeIn();
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }

    /**
     * Save Quora URL Meta Box data
     */
    public function save_quora_url_meta_box_data( $post_id ) {
        if ( ! isset( $_POST['quora_url_meta_box_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['quora_url_meta_box_nonce'], 'quora_url_meta_box' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        $override_val = isset( $_POST['quora_url_override'] ) ? '1' : '0';
        update_post_meta( $post_id, '_quora_url_override', $override_val );

        if ( isset( $_POST['quora_url'] ) ) {
            $new_url = esc_url_raw( trim( $_POST['quora_url'] ) );
            $old_url = get_post_meta( $post_id, '_quora_url', true );

            if ( $new_url !== $old_url ) {
                update_post_meta( $post_id, '_quora_url', $new_url );
                update_post_meta( $post_id, '_quora_url_status', 'untested' );

                if ( ! empty( $old_url ) && ! empty( $new_url ) ) {
                    $post = get_post( $post_id );
                    if ( $post ) {
                        $new_content = str_replace( 
                            array(
                                $old_url, 
                                esc_url( $old_url ), 
                                rawurlencode( $old_url ),
                                esc_attr( $old_url ),
                                rawurldecode( $old_url )
                            ), 
                            $new_url, 
                            $post->post_content 
                        );
                        if ( $new_content !== $post->post_content ) {
                            if ( has_action( 'save_post', array( $this, 'save_quora_url_meta_box_data' ) ) ) {
                                remove_action( 'save_post', array( $this, 'save_quora_url_meta_box_data' ) );
                                wp_update_post( array(
                                    'ID'           => $post_id,
                                    'post_content' => $new_content
                                ) );
                                add_action( 'save_post', array( $this, 'save_quora_url_meta_box_data' ) );
                            } else {
                                wp_update_post( array(
                                    'ID'           => $post_id,
                                    'post_content' => $new_content
                                ) );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * AJAX endpoint to test Quora URL validation via cloudscraper
     */
    public function ajax_test_quora_url() {
        check_ajax_referer( 'quora-test-url-nonce', 'security' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'quora-importer' ) ) );
        }
        
        $url = isset( $_POST['url'] ) ? esc_url_raw( trim( $_POST['url'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        
        if ( empty( $url ) || ! $this->validate_quora_url( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'Veuillez saisir une URL valide.', 'quora-importer' ) ) );
        }
        
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
        
        $py_cmd = 'import sys, cloudscraper; s = cloudscraper.create_scraper(); r = s.get(sys.argv[1], timeout=5); print(r.status_code)';
        $cmd = escapeshellcmd( $python_executable ) . ' -c ' . escapeshellarg( $py_cmd ) . ' ' . escapeshellarg( $url );
        
        $output = array();
        $return_var = 0;
        exec( $cmd, $output, $return_var );
        
        $status_code = isset( $output[0] ) ? intval( trim( $output[0] ) ) : 0;
        
        $status = 'untested';
        if ( $status_code === 200 ) {
            $status = 'valid';
        } elseif ( $status_code === 404 ) {
            $status = 'invalid';
        } else {
            $status = ( $status_code > 0 && $status_code !== 404 ) ? 'valid' : 'invalid';
        }
        
        if ( $post_id > 0 ) {
            update_post_meta( $post_id, '_quora_url_status', $status );
            if ( $status === 'valid' ) {
                update_post_meta( $post_id, '_quora_url', $url );
            }
        }
        
        wp_send_json_success( array(
            'status'      => $status,
            'status_code' => $status_code,
            'message'     => $status === 'valid' ? __( 'URL Valide', 'quora-importer' ) : __( 'URL Invalide (Page non trouvée)', 'quora-importer' )
        ) );
    }

    /**
     * AJAX action to import post tags manually from Meta Box
     */
    public function ajax_import_post_tags() {
        check_ajax_referer( 'quora-import-tags-nonce', 'security' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'quora-importer' ) ) );
        }
        
        $url = isset( $_POST['url'] ) ? esc_url_raw( trim( $_POST['url'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        
        if ( empty( $url ) || ! $this->validate_quora_url( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'Veuillez saisir une URL valide.', 'quora-importer' ) ) );
        }
        
        $extracted_topics = $this->extract_quora_topics( $url );
        
        if ( ! empty( $extracted_topics ) ) {
            if ( $post_id > 0 ) {
                update_post_meta( $post_id, '_quora_url', $url );
                update_post_meta( $post_id, '_quora_url_status', 'valid' );
                
                $current_tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
                if ( is_wp_error( $current_tags ) ) {
                    $current_tags = array();
                }
                $new_tags = array_unique( array_merge( $current_tags, $extracted_topics ) );
                wp_set_post_tags( $post_id, $new_tags, false );
            }
            
            wp_send_json_success( array(
                'message' => sprintf( _n( '%d étiquette importée avec succès.', '%d étiquettes importées avec succès.', count( $extracted_topics ), 'quora-importer' ), count( $extracted_topics ) )
            ) );
        } else {
            if ( $post_id > 0 ) {
                update_post_meta( $post_id, '_quora_url_status', 'invalid' );
            }
            $error_msg = ! empty( $this->last_topic_error ) ? $this->last_topic_error : __( 'Aucune étiquette trouvée ou échec de l\'extraction.', 'quora-importer' );
            wp_send_json_error( array(
                'message' => sprintf( __( 'Échec de l\'importation : %s', 'quora-importer' ), $error_msg )
            ) );
        }
    }

    /**
     * AJAX action to update post comments manually from Meta Box
     */
    public function ajax_update_post_comments() {
        check_ajax_referer( 'quora-update-comments-nonce', 'security' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'quora-importer' ) ) );
        }
        
        $url = isset( $_POST['url'] ) ? esc_url_raw( trim( $_POST['url'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        
        if ( empty( $url ) || ! $this->validate_quora_url( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'Veuillez saisir une URL valide.', 'quora-importer' ) ) );
        }
        
        $post = get_post( $post_id );
        $post_date = $post ? $post->post_date : current_time( 'mysql' );
        
        // Force the comments import status to 0 to enable re-import/update
        update_post_meta( $post_id, '_quora_comments_imported', '0' );
        
        $comments_res = $this->import_quora_comments( $post_id, array( $url ), $post_date );
        
        if ( $comments_res['success'] ) {
            update_post_meta( $post_id, '_quora_comments_imported', '1' );
            wp_send_json_success( array(
                'message' => sprintf( _n( '%d commentaire importé ou mis à jour.', '%d commentaires importés ou mis à jour.', $comments_res['count'], 'quora-importer' ), $comments_res['count'] )
            ) );
        } else {
            // Revert status to 0 so it can be retried
            update_post_meta( $post_id, '_quora_comments_imported', '0' );
            wp_send_json_error( array(
                'message' => sprintf( __( 'Échec de la mise à jour : %s', 'quora-importer' ), $comments_res['error'] )
            ) );
        }
    }

    /**
     * Debug parsing of an HTML file.
     */
    public function debug_parse_file( $html_file ) {
        $parsed_posts = $this->parse_html_file( $html_file );
        echo "Parsed " . count( $parsed_posts ) . " posts from file.\n";
        foreach ( $parsed_posts as $post ) {
            if ( stripos( serialize($post), 'Noether' ) !== false ) {
                echo "--> FOUND Noether post!\n";
                print_r($post);
            }
        }
    }

    /**
     * Run database updates to synchronize titles, content, categories, tags, and Quora URLs.
     */
    public function run_database_updates() {
        echo "Starting Quora posts update...\n";

        // Path to Quora exports
        $export_dir = '/home/goulu/Documents/develop/quora2wordpress/content';
        if ( ! is_dir( $export_dir ) ) {
            die( "Error: Quora export directory not found at $export_dir\n" );
        }

        // Find all index.html files in export dir
        echo "Scanning for exports in $export_dir...\n";
        $directory_iterator = new RecursiveDirectoryIterator( $export_dir );
        $iterator = new RecursiveIteratorIterator( $directory_iterator );
        $html_files = array();

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && $file->getFilename() === 'index.html' ) {
                $html_files[] = $file->getPathname();
            }
        }

        echo "Found " . count( $html_files ) . " index.html files.\n";

        // Load all WordPress posts
        echo "Loading WordPress posts...\n";
        $wp_posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => -1,
        ) );
        echo "Found " . count( $wp_posts ) . " posts in database.\n";

        // Load quora_urls.json dictionary
        $json_file = dirname( dirname( __FILE__ ) ) . '/scratch/quora_urls.json';
        $dict_urls = array();
        $slug_dict = array();
        if ( file_exists( $json_file ) ) {
            $decoded = json_decode( file_get_contents( $json_file ), true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $u ) {
                    $dict_urls[strtolower($u)] = $u;
                    
                    // Index by slug
                    $parsed = wp_parse_url( $u );
                    if ( ! empty( $parsed['path'] ) ) {
                        $path = trim( $parsed['path'], '/' );
                        $slug = $path;
                        if ( preg_match( '/^([^\/]+)\/answer\//i', $path, $matches ) ) {
                            $slug = $matches[1];
                        }
                        $clean_slug = strtolower( remove_accents( $slug ) );
                        $clean_slug = preg_replace( '/[^a-z0-9]/', '', $clean_slug );
                        if ( ! empty( $clean_slug ) ) {
                            $slug_dict[$clean_slug][] = $u;
                        }
                    }
                }
            }
        }
        echo "Loaded " . count( $dict_urls ) . " URLs and " . count( $slug_dict ) . " slugs from dictionary.\n";

        // Find python executable for validation
        $python_executable = 'python3';
        foreach ( array( '/usr/bin/python3', '/usr/local/bin/python3', '/bin/python3', '/usr/bin/python' ) as $path ) {
            if ( @is_executable( $path ) ) {
                $python_executable = $path;
                break;
            }
        }
        echo "Using Python executable: $python_executable\n";

        // Helper to test URL status code
        $test_url_status = function( $url ) use ( $python_executable ) {
            $py_cmd = 'import sys, cloudscraper; s = cloudscraper.create_scraper(); r = s.get(sys.argv[1], timeout=5); print(r.status_code)';
            $cmd = escapeshellcmd( $python_executable ) . ' -c ' . escapeshellarg( $py_cmd ) . ' ' . escapeshellarg( $url );
            $output = array();
            $return_var = 0;
            exec( $cmd, $output, $return_var );
            $status_code = isset( $output[0] ) ? intval( trim( $output[0] ) ) : 0;
            return $status_code;
        };

        // Process each html file
        $matched_count = 0;
        foreach ( $html_files as $html_file ) {
            $dir = dirname( $html_file );
            echo "\nProcessing file: $html_file\n";
            
            // Parse the html file
            $parsed_posts = $this->parse_html_file( $html_file );
            if ( empty( $parsed_posts ) ) {
                echo "No posts parsed from this file.\n";
                continue;
            }
            
            foreach ( $parsed_posts as $post ) {
                $type = ! empty( $post['type'] ) ? $post['type'] : '';
                $raw_date = ! empty( $post['Creation time'] ) ? $post['Creation time'] : '';
                $timestamp = $this->parse_quora_date( $raw_date );
                if ( ! $timestamp ) {
                    continue;
                }
                
                $formatted_date = wp_date( 'Y-m-d H:i:s', $timestamp );
                $clean_title = $this->get_post_title( $post, $type );
                $clean_title = preg_replace( '/\[\/?math\]/i', '$', $clean_title );
                
                // Find matching WordPress post using resilient logic
                $matched_post = null;
                foreach ( $wp_posts as $wp_post ) {
                    $wp_title_norm = strtolower( remove_accents( $wp_post->post_title ) );
                    $wp_title_norm = preg_replace( '/[^a-z0-9]/', '', $wp_title_norm );
                    
                    $clean_title_norm = strtolower( remove_accents( $clean_title ) );
                    $clean_title_norm = preg_replace( '/[^a-z0-9]/', '', $clean_title_norm );
                    
                    // Match 1: Exact title match
                    if ( ! empty( $wp_title_norm ) && $wp_title_norm === $clean_title_norm ) {
                        $matched_post = $wp_post;
                        break;
                    }
                    
                    // Match by date timezone flexible + title overlap
                    $wp_time = strtotime( $wp_post->post_date );
                    $wp_gmt_time = strtotime( $wp_post->post_date_gmt );
                    if ( $timestamp ) {
                        $diff_local = abs( $wp_time - $timestamp );
                        $diff_gmt = abs( $wp_gmt_time - $timestamp );
                        $time_close = ( $diff_local <= 43200 || $diff_gmt <= 43200 );
                        
                        if ( $time_close ) {
                            if ( ! empty( $wp_title_norm ) && ! empty( $clean_title_norm ) ) {
                                if ( false !== strpos( $clean_title_norm, $wp_title_norm ) || false !== strpos( $wp_title_norm, $clean_title_norm ) ) {
                                    $matched_post = $wp_post;
                                    break;
                                }
                            }
                            // Or extremely close time (within 5 mins)
                            if ( $diff_local <= 300 || $diff_gmt <= 300 ) {
                                $matched_post = $wp_post;
                                break;
                            }
                        }
                    }
                }
                
                if ( $matched_post ) {
                    // Check manual override
                    $is_overridden = get_post_meta( $matched_post->ID, '_quora_url_override', true );
                    if ( $is_overridden === '1' ) {
                        echo "Matched WordPress Post ID: {$matched_post->ID} (URL locked - skipping updates)\n";
                        continue;
                    }

                    // Check if it's a draft
                    $is_draft = ( strpos( strtolower( $post['type'] ), 'brouillon' ) !== false || strpos( strtolower( $post['type'] ), 'draft' ) !== false );
                    if ( $is_draft ) {
                        echo "Matched WordPress Post ID: {$matched_post->ID} (Draft - skipping URL validation)\n";
                        update_post_meta( $matched_post->ID, '_quora_url_status', 'valid' );
                        delete_post_meta( $matched_post->ID, '_quora_url' );
                        delete_post_meta( $matched_post->ID, '_quora_candidate_urls' );
                        continue;
                    }

                    $matched_count++;
                    echo "Matched WordPress Post ID: {$matched_post->ID}\n";
                    echo "  Old Title: '{$matched_post->post_title}'\n";
                    echo "  New Title: '{$clean_title}'\n";
                    
                    // Clean/process content
                    $content = ! empty( $post['Content'] ) ? $post['Content'] : '';
                    $content = preg_replace( '/\[\/?math\]/i', '$', $content );
                    $content = $this->clean_html_newlines( $content );
                    $content = $this->process_html_links( $content );
                    if ( get_option( 'quora_importer_r2w_support' ) === '1' ) {
                        $content = $this->format_wikipedia_links_to_r2w( $content );
                    }
                    $content = $this->maybe_remove_bold_title( $content, $clean_title, $post );
                    
                    // Update title and content if changed
                    $update_data = array( 'ID' => $matched_post->ID );
                    $needs_update = false;
                    if ( $matched_post->post_title !== $clean_title ) {
                        $update_data['post_title'] = $clean_title;
                        $needs_update = true;
                    }
                    if ( $matched_post->post_content !== $content ) {
                        $update_data['post_content'] = $content;
                        $needs_update = true;
                    }
                    if ( $needs_update ) {
                        wp_update_post( $update_data );
                        echo "  -> Updated post title/content.\n";
                    }
                    
                    // Generate candidate URLs
                    $candidate_urls = $this->get_candidate_urls( $post, $dir, $matched_post->post_author );
                    
                    // Look up candidates in slug dictionary to find exact match if any
                    $dict_matches = array();
                    foreach ( $candidate_urls as $cand ) {
                        $cand_lower = strtolower( $cand );
                        if ( isset( $dict_urls[$cand_lower] ) ) {
                            $dict_matches[] = $dict_urls[$cand_lower];
                        }
                        $parsed = wp_parse_url( $cand );
                        if ( ! empty( $parsed['path'] ) ) {
                            $path = trim( $parsed['path'], '/' );
                            $slug = $path;
                            if ( preg_match( '/^([^\/]+)\/answer\//i', $path, $matches ) ) {
                                $slug = $matches[1];
                            }
                            $clean_slug = strtolower( remove_accents( $slug ) );
                            $clean_slug = preg_replace( '/[^a-z0-9]/', '', $clean_slug );
                            if ( isset( $slug_dict[$clean_slug] ) ) {
                                foreach ( $slug_dict[$clean_slug] as $matched_url ) {
                                    $dict_matches[] = $matched_url;
                                }
                            }
                        }
                    }
                    $dict_matches = array_unique( $dict_matches );
                    if ( ! empty( $dict_matches ) ) {
                        $candidate_urls = array_values( array_unique( array_merge( $dict_matches, $candidate_urls ) ) );
                    }
                    
                    update_post_meta( $matched_post->ID, '_quora_candidate_urls', $candidate_urls );
                    echo "  Candidates: " . implode( ', ', $candidate_urls ) . "\n";
                    
                    // Validate the first candidate
                    if ( ! empty( $candidate_urls ) ) {
                        $url_to_test = $candidate_urls[0];
                        $is_valid_dict = false;
                        foreach ( $candidate_urls as $cand ) {
                            $cand_lower = strtolower( $cand );
                            if ( isset( $dict_urls[$cand_lower] ) ) {
                                $url_to_test = $dict_urls[$cand_lower];
                                $is_valid_dict = true;
                                break;
                            }
                        }
                        
                        if ( $is_valid_dict ) {
                            $status = 'valid';
                            echo "  Dictionary validation: SUCCESS ($url_to_test)\n";
                        } else {
                            echo "  Testing URL via scraper: $url_to_test\n";
                            $status_code = $test_url_status( $url_to_test );
                            echo "  Status Code: $status_code\n";
                            
                            $status = 'untested';
                            if ( $status_code === 200 ) {
                                $status = 'valid';
                            } elseif ( $status_code === 404 ) {
                                $status = 'invalid';
                            } else {
                                $status = ( $status_code > 0 && $status_code !== 404 ) ? 'valid' : 'invalid';
                            }
                        }
                        
                        update_post_meta( $matched_post->ID, '_quora_url', $url_to_test );
                        update_post_meta( $matched_post->ID, '_quora_url_status', $status );
                        echo "  -> Updated _quora_url = $url_to_test, _quora_url_status = $status\n";
                    }
                }
            }
        }

        // Second Pass: Align and repair any remaining invalid/unmatched database posts directly
        echo "\nStarting Second Pass: Repairing unmatched/invalid posts directly in database...\n";
        $invalid_posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_quora_url_status',
                    'value'   => 'valid',
                    'compare' => '!=',
                ),
                array(
                    'key'     => '_quora_url_status',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        ) );

        echo "Found " . count( $invalid_posts ) . " posts with invalid or missing Quora URL status.\n";

        foreach ( $invalid_posts as $db_post ) {
            // Check manual override
            $is_overridden = get_post_meta( $db_post->ID, '_quora_url_override', true );
            if ( $is_overridden === '1' ) {
                echo "\nPost ID: {$db_post->ID} | Title: '{$db_post->post_title}' (URL locked - skipping updates)\n";
                continue;
            }

            echo "\nRepairing Post ID: {$db_post->ID} | Current Title: '{$db_post->post_title}'\n";
            
            // Try to find a valid URL using candidates
            $candidates = array();
            
            // 1. Get existing URL if any
            $existing_url = get_post_meta( $db_post->ID, '_quora_url', true );
            if ( $existing_url && $this->validate_quora_url( $existing_url ) ) {
                $candidates[] = $existing_url;
                
                // If it's a subdomain/space post, we can try to extract domain and reconstruct slug
                $parsed = wp_parse_url( $existing_url );
                if ( ! empty( $parsed['host'] ) ) {
                    $domain = $parsed['host'];
                    
                    // Try slug variants:
                    // e.g. replacing 'nest' with 'n-est', 'l' with 'l-e', 'd' with 'd-e' etc.
                    $path = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';
                    if ( $path && stripos( $domain, 'quora.com' ) !== false ) {
                        // Check if path has /answer/
                        $is_answer = false;
                        $slug = $path;
                        if ( preg_match( '/^([^\/]+)\/answer\//i', $path, $matches ) ) {
                            $is_answer = true;
                            $slug = $matches[1];
                        }
                        
                        // Try substituting nest with n-est
                        if ( stripos( $slug, 'nest' ) !== false ) {
                            $new_slug = str_ireplace( 'nest', 'n-est', $slug );
                            $new_url = "https://{$domain}/{$new_slug}";
                            if ( $is_answer ) {
                                $new_url .= "/answer/Philippe-Guglielmetti";
                            }
                            $candidates[] = $new_url;
                            
                            $new_url_dg = "https://{$domain}/{$new_slug}";
                            if ( $is_answer ) {
                                $new_url_dg .= "/answer/Dr-Goulu";
                            }
                            $candidates[] = $new_url_dg;
                        }
                    }
                }
            }
            
            // 2. Generate candidates from post title
            $title = $db_post->post_title;
            // Clean title first
            $title = preg_replace( '/\[\/?math\]/i', '', $title );
            
            // Reconstruct profiles slug
            $profile_slugs = array( 'Philippe-Guglielmetti', 'Dr-Goulu' );
            $nickname = get_user_meta( $db_post->post_author, 'nickname', true );
            if ( ! empty( $nickname ) ) {
                $normalized = remove_accents( $nickname );
                $cleaned_name = preg_replace( '/[^A-Za-z0-9_\-\s]/', '', $normalized );
                $ps = str_replace( array( ' ', '_' ), '-', $cleaned_name );
                $ps = preg_replace( '/-+/', '-', $ps );
                if ( ! in_array( $ps, $profile_slugs ) ) {
                    array_unshift( $profile_slugs, $ps );
                }
            }
            
            // Check domain
            $domain = 'fr.quora.com';
            if ( ! empty( $existing_url ) ) {
                $parsed = wp_parse_url( $existing_url );
                if ( ! empty( $parsed['host'] ) ) {
                    $domain = $parsed['host'];
                }
            }
            
            // Helper to add candidate permutations
            $add_slug_candidates = function( $t ) use ( &$candidates, $domain, $profile_slugs ) {
                foreach ( array( true, false ) as $replace_apostrophes ) {
                    $slug = $this->quora_slugify( $t, $replace_apostrophes );
                    if ( empty( $slug ) ) {
                        continue;
                    }
                    
                    // Generate accented slug
                    $slugs_to_try = array( $slug );
                    
                    // Generate unaccented slug
                    $unaccented = remove_accents( $slug );
                    if ( $unaccented !== $slug ) {
                        $slugs_to_try[] = $unaccented;
                    }
                    
                    foreach ( $slugs_to_try as $s ) {
                        if ( stripos( $domain, 'quora.com' ) !== false && stripos( $domain, 'fr.quora.com' ) === false && stripos( $domain, 'www.quora.com' ) === false ) {
                            // Space post (subdomain space)
                            $candidates[] = "https://{$domain}/{$s}";
                        } else {
                            // Standard post (can be question or answer)
                            $candidates[] = "https://{$domain}/{$s}";
                            foreach ( $profile_slugs as $ps ) {
                                $candidates[] = "https://{$domain}/{$s}/answer/{$ps}";
                            }
                        }
                    }
                }
            };
            
            // If the title is NOT truncated, add candidates based on full title
            if ( substr( $title, -3 ) !== '...' ) {
                $add_slug_candidates( $title );
                
                // Also try truncating at punctuation marks (like colon, period, question mark)
                $punctuation_marks = array( ':', '.', '?', '!', '(' );
                foreach ( $punctuation_marks as $punc ) {
                    $pos = strpos( $title, $punc );
                    if ( $pos !== false && $pos > 10 ) {
                        $truncated_title = trim( substr( $title, 0, $pos ) );
                        $add_slug_candidates( $truncated_title );
                    }
                }
            }
            
            // Try word prefixes of the title if the title is long
            $title_words = explode( ' ', $title );
            if ( count( $title_words ) > 10 ) {
                foreach ( array( 10, 15, 20, 25, 30 ) as $word_count ) {
                    if ( count( $title_words ) >= $word_count ) {
                        $prefix_title = implode( ' ', array_slice( $title_words, 0, $word_count ) );
                        $add_slug_candidates( $prefix_title );
                    }
                }
            }
            
            // If title IS truncated or as fallback, try prefixes of post_content
            $content_text = trim( wp_strip_all_tags( $db_post->post_content ) );
            $content_text = preg_replace( '/\s+/', ' ', $content_text );
            $words = explode( ' ', $content_text );
            
            if ( count( $words ) > 1 ) {
                // Try first 4, 6, 8, 10, 12, 14 words
                foreach ( array( 4, 6, 8, 10, 12, 14 ) as $word_count ) {
                    if ( count( $words ) >= $word_count ) {
                        $prefix_title = implode( ' ', array_slice( $words, 0, $word_count ) );
                        $add_slug_candidates( $prefix_title );
                    }
                }
            }
            
            // Clean up and unique candidates
            $candidates = array_unique( array_filter( $candidates ) );
            
            // Validate candidates sequentially and stop at the first success
            $valid_url = '';
            
            // First check candidates against dictionary
            foreach ( $candidates as $candidate_url ) {
                $cand_lower = strtolower( $candidate_url );
                if ( isset( $dict_urls[$cand_lower] ) ) {
                    $valid_url = $dict_urls[$cand_lower];
                    echo "  Dictionary validation: SUCCESS ($valid_url)\n";
                    break;
                }
                
                // Also check slug matching
                $parsed = wp_parse_url( $candidate_url );
                if ( ! empty( $parsed['path'] ) ) {
                    $path = trim( $parsed['path'], '/' );
                    $slug = $path;
                    if ( preg_match( '/^([^\/]+)\/answer\//i', $path, $matches ) ) {
                        $slug = $matches[1];
                    }
                    $clean_slug = strtolower( remove_accents( $slug ) );
                    $clean_slug = preg_replace( '/[^a-z0-9]/', '', $clean_slug );
                    if ( isset( $slug_dict[$clean_slug] ) ) {
                        $valid_url = $slug_dict[$clean_slug][0];
                        echo "  Dictionary slug validation: SUCCESS ($valid_url)\n";
                        break;
                    }
                }
            }
            
            if ( ! $valid_url ) {
                foreach ( $candidates as $candidate_url ) {
                    echo "  Testing direct candidate: $candidate_url\n";
                    $status_code = $test_url_status( $candidate_url );
                    echo "  Status Code: $status_code\n";
                    
                    if ( $status_code === 200 || ( $status_code > 0 && $status_code !== 404 ) ) {
                        $valid_url = $candidate_url;
                        break;
                    }
                }
            }
            
            if ( $valid_url ) {
                // Recover correct title if the candidate URL matched a different slug/title
                $new_title = $db_post->post_title;
                if ( substr( $db_post->post_title, -3 ) === '...' ) {
                    $mock_post = array(
                        'Content' => $db_post->post_content,
                        'type' => $is_answer ? 'Répondre' : 'Question',
                        'Content language' => ( strpos( $domain, 'fr.quora' ) !== false || strpos( $domain, 'reponsesfrequentes' ) !== false ) ? 'Français' : 'English',
                        'url' => $valid_url,
                    );
                    $recovered_title = $this->get_post_title( $mock_post, $mock_post['type'] );
                    if ( $recovered_title && substr( $recovered_title, -3 ) !== '...' ) {
                        $new_title = $recovered_title;
                    }
                }
                
                $update_data = array( 'ID' => $db_post->ID );
                $needs_update = false;
                if ( $db_post->post_title !== $new_title ) {
                    $update_data['post_title'] = $new_title;
                    $needs_update = true;
                }
                if ( $needs_update ) {
                    wp_update_post( $update_data );
                    echo "  -> Corrected Title to: '{$new_title}'\n";
                }
                
                update_post_meta( $db_post->ID, '_quora_url', $valid_url );
                update_post_meta( $db_post->ID, '_quora_url_status', 'valid' );
                echo "  -> FOUND and set valid _quora_url = $valid_url\n";
            } else {
                echo "  -> Could not find a valid URL candidate. Post remains invalid.\n";
            }
        }

        echo "\nUpdate complete! Matched and processed $matched_count posts.\n";
    }
}

