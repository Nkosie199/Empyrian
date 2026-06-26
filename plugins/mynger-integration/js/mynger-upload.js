/**
 * mynger-upload.js
 * Intercepts the play-block upload form file inputs and routes video/audio
 * files directly to Mynger S3 via a presigned URL, showing a progress bar.
 * Also injects a mandatory cover art picker; uploads image to WP media library
 * and injects the resulting attachment ID as featured_media before form submit.
 */
(function ($) {
    'use strict';

    if (!window.myngerConfig || !myngerConfig.loggedIn) return;

    const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/ogg', 'video/mov', 'video/avi', 'video/mkv'];
    const AUDIO_TYPES = ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/flac', 'audio/aac'];
    const MEDIA_TYPES = [...VIDEO_TYPES, ...AUDIO_TYPES];

    // -----------------------------------------------------------------------
    // Observe DOM for upload forms and inject cover art picker when found
    // -----------------------------------------------------------------------
    function observeForms() {
        function tryInject(root) {
            $(root).find('input[type="file"][name="stream"], input[type="file"][name="file"], input[type="file"][accept*="audio"], input[type="file"][accept*="video"]').each(function () {
                injectCoverArtPicker($(this).closest('form'));
            });
        }

        tryInject(document);

        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) tryInject(node);
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    // -----------------------------------------------------------------------
    // Inject a cover art picker section into an upload form (once only)
    // -----------------------------------------------------------------------
    function injectCoverArtPicker($form) {
        if (!$form.length || $form.find('.mynger-cover-wrap').length) return;

        const $wrap = $(`
            <div class="mynger-cover-wrap" style="margin:16px 0;">
                <label class="mynger-cover-label" style="display:block;margin-bottom:6px;font-size:13px;color:#ccc;">
                    Cover Art <span style="color:#ff4444;">*</span>
                </label>
                <div class="mynger-cover-inner" style="display:flex;align-items:center;gap:12px;">
                    <div class="mynger-cover-thumb" style="width:64px;height:64px;border:1px dashed #444;border-radius:4px;overflow:hidden;background:#1a1a1a;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                        <span class="mynger-cover-placeholder" style="font-size:22px;color:#444;">&#128247;</span>
                    </div>
                    <div style="flex:1;">
                        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="mynger-cover-input" style="font-size:13px;color:#ccc;">
                        <div class="mynger-cover-status" style="font-size:12px;color:#888;margin-top:4px;"></div>
                    </div>
                </div>
                <input type="hidden" name="featured_media" class="mynger-cover-id" value="">
            </div>
        `);

        // Append after the last file input inside the form, or before submit
        const $fileInput = $form.find('input[type="file"]').last();
        if ($fileInput.length) {
            $fileInput.closest('.form-group, .field-wrap, p, div').last().after($wrap);
        } else {
            $form.find('[type="submit"]').first().before($wrap);
        }

        $form.find('.mynger-cover-input').on('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            uploadCoverArt(file, $form);
        });
    }

    // -----------------------------------------------------------------------
    // Upload cover art to WP media library, inject ID into hidden field
    // -----------------------------------------------------------------------
    function uploadCoverArt(file, $form) {
        const $status = $form.find('.mynger-cover-status');
        const $thumb  = $form.find('.mynger-cover-thumb');
        const $idField = $form.find('.mynger-cover-id');

        $status.text('Uploading cover art…').css('color', '#888');
        $idField.val('');

        // Show local preview immediately
        const reader = new FileReader();
        reader.onload = function (e) {
            $thumb.html('<img src="' + escHtml(e.target.result) + '" style="width:100%;height:100%;object-fit:cover;">');
        };
        reader.readAsDataURL(file);

        // Upload to WP media library via REST API
        const formData = new FormData();
        formData.append('file', file, file.name);
        formData.append('title', file.name.replace(/\.[^.]+$/, ''));

        $.ajax({
            url: (window.wpApiSettings && wpApiSettings.root ? wpApiSettings.root : '/wp-json/') + 'wp/v2/media',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', myngerConfig.nonce);
            },
        })
        .then(function (resp) {
            if (!resp || !resp.id) throw new Error('No media ID returned');
            $idField.val(resp.id);
            $status.text('Cover art ready ✓').css('color', '#1ed760');
        })
        .catch(function (err) {
            $status.text('Cover art upload failed — ' + (err.responseJSON && err.responseJSON.message ? err.responseJSON.message : 'check console')).css('color', '#ff4444');
            $thumb.html('<span class="mynger-cover-placeholder" style="font-size:22px;color:#ff4444;">&#10007;</span>');
            console.error('Mynger cover art upload error:', err);
        });
    }

    // -----------------------------------------------------------------------
    // Hook audio/video file inputs → direct S3 upload
    // -----------------------------------------------------------------------
    function hookUploadInputs() {
        $(document).on('change', 'input[type="file"][name="stream"], input[type="file"][name="file"], input[type="file"][accept*="video"], input[type="file"][accept*="audio"]', function (e) {
            const file = this.files && this.files[0];
            if (!file) return;

            const isMedia = MEDIA_TYPES.some(t => file.type === t || file.type.startsWith(t.split('/')[0] + '/'));
            if (!isMedia) return;

            e.preventDefault();
            e.stopPropagation();

            const $input = $(this);
            const $form  = $input.closest('form');

            uploadToMynger(file, $input, $form);
        });
    }

    // -----------------------------------------------------------------------
    // Upload flow: WP REST presign → PUT to S3 → inject S3 URL into form
    // -----------------------------------------------------------------------
    function uploadToMynger(file, $input, $form) {
        const $progress = createProgressUI($input, file.name);

        $.ajax({
            url: myngerConfig.restBase + '/presign',
            method: 'POST',
            contentType: 'application/json',
            beforeSend: xhr => xhr.setRequestHeader('X-WP-Nonce', myngerConfig.nonce),
            data: JSON.stringify({ fileName: file.name, fileType: file.type }),
        })
        .then(resp => {
            if (!resp.presignedUrl) throw new Error('No presigned URL returned');
            $progress.status('Uploading to Mynger…');
            return putToS3(resp.presignedUrl, file, $progress).then(() => resp.s3Url);
        })
        .then(s3Url => {
            $progress.status('Upload complete ✓');
            $progress.finish();
            injectStreamUrl(s3Url, $input, $form);
        })
        .catch(err => {
            console.error('Mynger upload error:', err);
            $progress.error('Upload failed — ' + (err.message || 'check console'));
        });
    }

    function putToS3(presignedUrl, file, $progress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', presignedUrl, true);
            xhr.setRequestHeader('Content-Type', file.type);

            xhr.upload.onprogress = e => {
                if (e.lengthComputable) $progress.pct(Math.round((e.loaded / e.total) * 100));
            };

            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) resolve();
                else reject(new Error('S3 PUT failed: ' + xhr.status));
            };
            xhr.onerror = () => reject(new Error('Network error during S3 upload'));
            xhr.send(file);
        });
    }

    function injectStreamUrl(s3Url, $input, $form) {
        let $stream = $form.find('input[name="stream"]');
        if (!$stream.length) {
            $stream = $('<input type="hidden" name="stream">').appendTo($form);
        }
        $stream.val(s3Url);
        $input.val('');
        $input.closest('.play-upload-file, .upload-file-wrap').addClass('mynger-uploaded');

        const $url_display = $form.find('.stream-url-display, [data-stream-preview]');
        if ($url_display.length) $url_display.val(s3Url).text(s3Url);

        $form.find('input[name="stream_url"], input[placeholder*="URL"], input[placeholder*="stream"]').val(s3Url);
        $form.trigger('mynger:stream-ready', [s3Url]);
    }

    // -----------------------------------------------------------------------
    // Progress UI
    // -----------------------------------------------------------------------
    function createProgressUI($input, fileName) {
        const $wrap = $(`
            <div class="mynger-progress-wrap" style="margin:8px 0;font-size:13px;">
                <div class="mynger-progress-label" style="margin-bottom:4px;color:#aaa;">
                    <span class="mynger-filename">${escHtml(fileName)}</span>
                    <span class="mynger-status" style="margin-left:8px;color:#1ed760;">Preparing…</span>
                </div>
                <div class="mynger-progress-bar-bg" style="background:#2a2a2a;border-radius:3px;height:4px;overflow:hidden;">
                    <div class="mynger-progress-bar" style="height:100%;width:0%;background:#1ed760;transition:width 0.2s;"></div>
                </div>
            </div>
        `);
        $input.after($wrap);

        return {
            status: msg  => $wrap.find('.mynger-status').text(msg),
            pct:    pct  => $wrap.find('.mynger-progress-bar').css('width', pct + '%'),
            finish: ()   => { $wrap.find('.mynger-progress-bar').css('width', '100%'); setTimeout(() => $wrap.fadeOut(), 2000); },
            error:  msg  => {
                $wrap.find('.mynger-status').text(msg).css('color', '#ff4444');
                $wrap.find('.mynger-progress-bar').css('background', '#ff4444');
            },
        };
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // -----------------------------------------------------------------------
    // Form submit validation — block if no cover art or audio not yet uploaded
    // -----------------------------------------------------------------------
    $(document).on('submit', '.play-upload-form', function (e) {
        const $form = $(this);

        // Block if cover art not uploaded yet
        const coverId = $form.find('.mynger-cover-id').val();
        if ($form.find('.mynger-cover-wrap').length && !coverId) {
            e.preventDefault();
            e.stopImmediatePropagation();
            $form.find('.mynger-cover-status').text('Please upload a cover art image before submitting.').css('color', '#ff4444');
            $form.find('.mynger-cover-wrap')[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }

        // Warn if stream is local WP URL
        const streamVal = $form.find('input[name="stream"]').val() || '';
        if (streamVal && streamVal.indexOf(window.location.hostname) !== -1 && streamVal.indexOf('/wp-content/uploads/') !== -1) {
            const sure = confirm('This file will be stored directly on the server. For best performance, upload via the Mynger file selector instead. Continue anyway?');
            if (!sure) { e.preventDefault(); return false; }
        }
    });

    // Boot
    $(document).ready(function () {
        hookUploadInputs();
        observeForms();
    });

})(jQuery);
