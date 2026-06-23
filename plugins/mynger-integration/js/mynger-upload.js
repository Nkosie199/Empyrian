/**
 * mynger-upload.js
 * Intercepts the play-block upload form file inputs and routes video/audio
 * files directly to Mynger S3 via a presigned URL, showing a progress bar.
 * Falls back to the normal WordPress upload flow for non-media files.
 */
(function ($) {
    'use strict';

    if (!window.myngerConfig || !myngerConfig.loggedIn) return;

    const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/ogg', 'video/mov', 'video/avi', 'video/mkv'];
    const AUDIO_TYPES = ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/flac', 'audio/aac'];
    const MEDIA_TYPES = [...VIDEO_TYPES, ...AUDIO_TYPES];

    // -----------------------------------------------------------------------
    // Wait for the play-block upload form to appear, then hook file inputs
    // -----------------------------------------------------------------------
    function hookUploadInputs() {
        // Target both direct file inputs and dynamically rendered upload forms
        $(document).on('change', 'input[type="file"][name="stream"], input[type="file"][name="file"], input[type="file"][accept*="video"], input[type="file"][accept*="audio"]', function (e) {
            const file = this.files && this.files[0];
            if (!file) return;

            const isMedia = MEDIA_TYPES.some(t => file.type === t || file.type.startsWith(t.split('/')[0] + '/'));
            if (!isMedia) return;

            // Prevent the default form submission from also uploading this file
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

        // Step 1: get presigned URL from WP REST proxy
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

            // Inject the S3 URL as the stream field value
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
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    $progress.pct(pct);
                }
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
        // Find or create a hidden input named 'stream' (what play-block expects)
        let $stream = $form.find('input[name="stream"]');
        if (!$stream.length) {
            $stream = $('<input type="hidden" name="stream">').appendTo($form);
        }
        $stream.val(s3Url);

        // Clear the file input so it doesn't re-upload via normal WP flow
        $input.val('');
        $input.closest('.play-upload-file, .upload-file-wrap').addClass('mynger-uploaded');

        // Show the S3 URL to the user
        const $url_display = $form.find('.stream-url-display, [data-stream-preview]');
        if ($url_display.length) {
            $url_display.val(s3Url).text(s3Url);
        }

        // If the form has an existing stream URL text field, populate it
        $form.find('input[name="stream_url"], input[placeholder*="URL"], input[placeholder*="stream"]').val(s3Url);

        // Trigger the play-block form to acknowledge the stream is set
        $form.trigger('mynger:stream-ready', [s3Url]);
    }

    // -----------------------------------------------------------------------
    // Progress UI — injects a progress bar below the file input
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
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // -----------------------------------------------------------------------
    // Also handle the play-block AJAX upload_stream action — if the server-
    // side hook is not triggered (e.g. admin uploads via media library modal),
    // show a notice that files should be uploaded via the Mynger flow.
    // -----------------------------------------------------------------------
    $(document).on('submit', '.play-upload-form', function () {
        const $form = $(this);
        const $stream = $form.find('input[name="stream"]');
        const streamVal = $stream.val() || '';

        // If stream looks like a local WP upload URL, warn
        if (streamVal && streamVal.indexOf(window.location.hostname) !== -1 && streamVal.indexOf('/wp-content/uploads/') !== -1) {
            const sure = confirm('This video will be stored directly on the server. For best performance, upload via the Mynger file selector instead. Continue anyway?');
            if (!sure) return false;
        }
    });

    // Boot
    $(document).ready(hookUploadInputs);

})(jQuery);
