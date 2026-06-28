jQuery(document).ready(function($) {
    
    // State variables
    let sessionId = '';
    let totalPosts = 0;
    let importImages = 1;
    let setFeatured = 1;
    let authorId = 1;
    let minCharsPublish = 500;
    let linkPosition = 'none';
    let linkTemplate = '';
    let enabledTypes = [];
    
    let currentIndex = 0;
    let importedCount = 0;
    let skippedCount = 0;
    let imagesCount = 0;
    let isAborted = false;
    let isImporting = false;
    
    // DOM Elements
    const $container = $('#quora-importer-container');
    const $steps = $('.quora-step');
    const $dropzone = $('#quora-dropzone');
    const $fileInput = $('#quora-file-input');
    const $browseBtn = $('.quora-browse-btn');
    const $loadingMsg = $('#quora-loading-message');
    const $loadingSub = $('#quora-loading-subtext');
    const $statsSummary = $('#quora-import-stats-summary');
    const $checkboxGrid = $('#quora-content-types-checkboxes');
    const $progressBar = $('#quora-import-progress-bar');
    const $progressPercent = $('#quora-progress-percentage');
    const $progressFraction = $('#quora-progress-fraction');
    const $console = $('#quora-console-log');
    
    /* ==========================================================================
       STEP TRANSITIONS
       ========================================================================== */
    function showStep(stepId) {
        $steps.removeClass('active').css({ 'display': 'none', 'opacity': 0 });
        const $target = $('#' + stepId);
        $target.css('display', 'block');
        setTimeout(function() {
            $target.addClass('active').css('opacity', 1);
        }, 50);
    }
    
    /* ==========================================================================
       STEP 1: FILE UPLOAD & DRAG/DROP
       ========================================================================== */
    $browseBtn.on('click', function(e) {
        e.preventDefault();
        $fileInput.trigger('click');
    });
    
    $fileInput.on('change', function() {
        if (this.files.length > 0) {
            handleFileUpload(this.files[0]);
        }
    });
    
    // Drag and drop events
    $dropzone.on('dragenter dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $dropzone.addClass('dragover');
    });
    
    $dropzone.on('dragleave drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $dropzone.removeClass('dragover');
    });
    
    $dropzone.on('drop', function(e) {
        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            handleFileUpload(files[0]);
        }
    });
    
    function handleFileUpload(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (ext !== 'zip' && ext !== 'html') {
            alert(quoraImporter.strings.select_valid_file);
            return;
        }
        
        // Switch to loading step
        $loadingMsg.text(quoraImporter.strings.uploading);
        $loadingSub.text(file.name + ' (' + formatBytes(file.size) + ')');
        showStep('quora-step-loading');
        
        const formData = new FormData();
        formData.append('action', 'quora_upload_file');
        formData.append('nonce', quoraImporter.nonce);
        formData.append('file', file);
        
        // Perform upload AJAX
        $.ajax({
            url: quoraImporter.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                // Track upload progress
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        $loadingMsg.text(quoraImporter.strings.uploading + ' (' + percent + '%)');
                        if (percent >= 100) {
                            $loadingMsg.text(quoraImporter.strings.processing_zip);
                        }
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                if (response.success) {
                    setupOptionsForm(response.data);
                } else {
                    alert(response.data.message || quoraImporter.strings.error_parse);
                    showStep('quora-step-upload');
                }
            },
            error: function() {
                alert(quoraImporter.strings.error_upload);
                showStep('quora-step-upload');
            }
        });
    }
    
    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }
    
    /* ==========================================================================
       STEP 2: SETUP OPTIONS
       ========================================================================== */
    function setupOptionsForm(data) {
        sessionId = data.session_id;
        totalPosts = data.total_posts;
        
        // Fill statistics
        let summaryText = quoraImporter.strings.posts_found.replace('%d', data.total_posts);
        if (data.has_images) {
            summaryText += quoraImporter.strings.images_found;
        } else {
            summaryText += quoraImporter.strings.no_images_found;
        }
        $statsSummary.html(summaryText);
        
        // Try to match guessed author in dropdown
        if (data.guessed_author) {
            let authorMatched = false;
            $('#quora-post-author option').each(function() {
                if ($(this).text().toLowerCase().indexOf(data.guessed_author.toLowerCase()) !== -1) {
                    $(this).prop('selected', true);
                    authorMatched = true;
                }
            });
            if (authorMatched) {
                addLog(quoraImporter.strings.suggested_author.replace('%s', data.guessed_author), 'info');
            }
        }
        
        // Generate content type checkboxes
        $checkboxGrid.empty();
        $.each(data.post_types, function(type, count) {
            const checkboxHtml = `
                <label class="quora-checkbox-card">
                    <input type="checkbox" name="enabled_types[]" value="${type}" checked />
                    <span class="type-label">${type}</span>
                    <span class="type-count">${count}</span>
                </label>
            `;
            $checkboxGrid.append(checkboxHtml);
        });
        
        // Hide image settings if no images
        if (!data.has_images) {
            $('#quora-import-images').prop('checked', false).prop('disabled', true);
            $('#quora-set-featured').prop('checked', false).prop('disabled', true);
            $('#quora-import-images').closest('.quora-form-row').addClass('disabled-row');
            $('#quora-set-featured').closest('.quora-form-row').addClass('disabled-row');
        } else {
            $('#quora-import-images').prop('checked', true).prop('disabled', false);
            $('#quora-set-featured').prop('checked', true).prop('disabled', false);
            $('#quora-import-images').closest('.quora-form-row').removeClass('disabled-row');
            $('#quora-set-featured').closest('.quora-form-row').removeClass('disabled-row');
        }
        
        $('#quora-session-id').val(sessionId);
        showStep('quora-step-options');
    }
    
    // Cancel & Clean up
    $('#quora-cancel-to-upload').on('click', function(e) {
        e.preventDefault();
        cleanupSession(true);
    });
    
    // Toggle link template input based on selection
    $(document).on('change', '#quora-link-position', function() {
        if ($(this).val() === 'none') {
            $('#quora-link-template-row').slideUp(200);
        } else {
            $('#quora-link-template-row').slideDown(200);
        }
    });
    
    /* ==========================================================================
       STEP 3: IMPORTING LOOP
       ========================================================================== */
    $('#quora-import-options-form').on('submit', function(e) {
        e.preventDefault();
        
        // Collect form data
        authorId = $('#quora-post-author').val();
        minCharsPublish = parseInt($('#quora-min-chars-publish').val()) || 500;
        linkPosition = $('#quora-link-position').val();
        linkTemplate = $('#quora-link-template').val();
        importImages = $('#quora-import-images').is(':checked') ? 1 : 0;
        setFeatured = $('#quora-set-featured').is(':checked') ? 1 : 0;
        
        enabledTypes = [];
        $('input[name="enabled_types[]"]:checked').each(function() {
            enabledTypes.push($(this).val());
        });
        
        if (enabledTypes.length === 0) {
            alert(quoraImporter.strings.select_content_type);
            return;
        }
        
        // Reset progress counters
        currentIndex = 0;
        importedCount = 0;
        skippedCount = 0;
        imagesCount = 0;
        isAborted = false;
        isImporting = true;
        
        // Reset elements in case of re-import
        $('#quora-progress-title').text(quoraImporter.strings.importing);
        $('#quora-progress-running-section').show();
        $('#quora-progress-finished-section').hide();
        $('#quora-progress-actions').show();
        $('#quora-finished-actions').hide();
        $('#quora-abort-import').prop('disabled', false).text(quoraImporter.strings.stop_import);
        $('#quora-log-status-indicator').addClass('live pulsing').removeClass('finished').text(quoraImporter.strings.live);
        
        $console.empty();
        $progressBar.css('width', '0%');
        $progressPercent.text('0%');
        $progressFraction.text('0 / ' + totalPosts);
        
        showStep('quora-step-progress');
        addLog(quoraImporter.strings.import_started.replace('%d', totalPosts), 'info');
        
        // Start sequential AJAX loop
        importNextItem();
    });
    
    function importNextItem() {
        if (isAborted) {
            addLog(quoraImporter.strings.import_aborted, 'warning');
            cleanupSession(true);
            return;
        }
        
        if (currentIndex >= totalPosts) {
            // Finished!
            finishImport();
            return;
        }
        
        // Update fraction text
        $progressFraction.text((currentIndex + 1) + ' / ' + totalPosts);
        
        $.ajax({
            url: quoraImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'quora_import_item',
                nonce: quoraImporter.nonce,
                session_id: sessionId,
                item_index: currentIndex,
                author_id: authorId,
                min_chars_publish: minCharsPublish,
                link_position: linkPosition,
                link_template: linkTemplate,
                import_images: importImages,
                set_featured: setFeatured,
                enabled_types: enabledTypes
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    if (data.status === 'imported') {
                        importedCount++;
                        imagesCount += data.images_imported || 0;
                    } else if (data.status === 'skipped') {
                        skippedCount++;
                        // Log skipped items as warnings so the user knows why they were ignored
                        let logMsg = quoraImporter.strings.skipped_log.replace('%d', currentIndex + 1).replace('%s', data.title).replace('%s', data.message);
                        addLog(logMsg, 'warning');
                    }
                } else {
                    let logMsg = quoraImporter.strings.error_log.replace('%d', currentIndex + 1).replace('%s', response.data.message);
                    addLog(logMsg, 'error');
                }
                
                // Update progress bar
                currentIndex++;
                const percent = Math.round((currentIndex / totalPosts) * 100);
                $progressBar.css('width', percent + '%');
                $progressPercent.text(percent + '%');
                
                // Delay slightly to prevent overloading
                setTimeout(importNextItem, 50);
            },
            error: function() {
                addLog(quoraImporter.strings.network_error.replace('%d', currentIndex + 1), 'error');
                currentIndex++;
                setTimeout(importNextItem, 100);
            }
        });
    }
    
    // Abort button
    $('#quora-abort-import').on('click', function(e) {
        e.preventDefault();
        if (confirm(quoraImporter.strings.abort_confirm)) {
            isAborted = true;
            $(this).prop('disabled', true).text(quoraImporter.strings.aborting);
        }
    });
    
    function addLog(message, type = 'info') {
        const time = new Date().toLocaleTimeString();
        const logEntry = `<div class="log-entry ${type}"><span class="log-time">[${time}]</span> ${message}</div>`;
        $console.append(logEntry);
        
        // Auto-scroll to bottom
        $console.scrollTop($console[0].scrollHeight);
    }
    
    /* ==========================================================================
       STEP 4: SUMMARY & CLEANUP
       ========================================================================== */
    function finishImport() {
        isImporting = false;
        let logMsg = quoraImporter.strings.import_finished.replace('%d', importedCount).replace('%d', skippedCount);
        addLog(logMsg, 'info');
        
        cleanupSession(false);
    }
    
    function cleanupSession(redirectAfter = false) {
        if (!sessionId) {
            if (redirectAfter) showStep('quora-step-upload');
            return;
        }
        
        $.ajax({
            url: quoraImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'quora_import_cleanup',
                nonce: quoraImporter.nonce,
                session_id: sessionId
            },
            success: function() {
                sessionId = '';
                if (redirectAfter) {
                    showStep('quora-step-upload');
                } else {
                    // Update stats on Step 3 Summary
                    $('#quora-progress-title').text(quoraImporter.strings.completed);
                    $('#quora-progress-running-section').hide();
                    $('#quora-progress-actions').hide();
                    
                    $('#summary-stat-imported').text(importedCount);
                    $('#summary-stat-skipped').text(skippedCount);
                    $('#summary-stat-images').text(imagesCount);
                    
                    $('#quora-progress-finished-section').show();
                    $('#quora-finished-actions').show();
                    
                    // Stop pulsing and change status indicator to Terminé
                    $('#quora-log-status-indicator')
                        .removeClass('live pulsing')
                        .addClass('finished')
                        .text(quoraImporter.strings.finished);
                }
            },
            error: function() {
                if (redirectAfter) {
                    showStep('quora-step-upload');
                } else {
                    // Fail gracefully but show final screen controls
                    $('#quora-progress-title').text(quoraImporter.strings.completed);
                    $('#quora-progress-running-section').hide();
                    $('#quora-progress-actions').hide();
                    
                    $('#summary-stat-imported').text(importedCount);
                    $('#summary-stat-skipped').text(skippedCount);
                    $('#summary-stat-images').text(imagesCount);
                    
                    $('#quora-progress-finished-section').show();
                    $('#quora-finished-actions').show();
                    
                    $('#quora-log-status-indicator')
                        .removeClass('live pulsing')
                        .addClass('finished')
                        .text(quoraImporter.strings.finished);
                }
            }
        });
    }
});
