/**
 * mynger-upload.js
 *
 * Two responsibilities:
 *  1. Intercept audio/video file inputs → upload directly to Mynger S3 via
 *     presigned URL, inject the S3 URL back into the play-block form field.
 *  2. Inject a mandatory cover art picker into the play-block upload div
 *     (#mup-form). After the user selects an image it is uploaded to the WP
 *     media library. When play-block's XHR to /wp-json/play/upload completes
 *     the new post is immediately PATCHed with the cover art attachment ID.
 */
(function ($) {
    'use strict';

    if (!window.myngerConfig || !myngerConfig.loggedIn) return;

    const VIDEO_TYPES = ['video/mp4','video/webm','video/ogg','video/mov','video/avi','video/mkv'];
    const AUDIO_TYPES = ['audio/mpeg','audio/mp3','audio/ogg','audio/wav','audio/flac','audio/aac'];
    const MEDIA_TYPES = [...VIDEO_TYPES, ...AUDIO_TYPES];

    // Shared state: cover art attachment ID, set after successful media upload
    let pendingCoverId = null;

    // -----------------------------------------------------------------------
    // 1. XHR interceptor — runs immediately so it wraps play-block's upload
    // -----------------------------------------------------------------------
    (function patchXHR() {
        const origOpen = XMLHttpRequest.prototype.open;
        const origSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            this._murl = String(url);
            origOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function () {
            if (this._murl && this._murl.includes('/play/upload') && pendingCoverId) {
                const coverId = pendingCoverId;
                this.addEventListener('load', function () {
                    try {
                        const resp = JSON.parse(this.responseText);
                        console.log('[Mynger] play/upload response:', resp);
                        const postId = extractPostId(resp);
                        if (postId && coverId) {
                            pendingCoverId = null;
                            setCoverOnPost(postId, coverId);
                        } else {
                            console.warn('[Mynger] could not extract post ID from upload response — cover art not set. Response keys:', Object.keys(resp));
                        }
                    } catch (e) { /* non-JSON response */ }
                });
            }
            origSend.apply(this, arguments);
        };
    }());

    // Also intercept fetch (some WP REST calls use it)
    (function patchFetch() {
        const origFetch = window.fetch;
        window.fetch = function (resource, init) {
            const url = typeof resource === 'string' ? resource : (resource && resource.url) || '';
            const p = origFetch.apply(this, arguments);
            if (url.includes('/play/upload') && pendingCoverId) {
                const coverId = pendingCoverId;
                p.then(resp => resp.clone().json().then(data => {
                    console.log('[Mynger] play/upload fetch response:', data);
                    const postId = extractPostId(data);
                    if (postId && coverId) {
                        pendingCoverId = null;
                        setCoverOnPost(postId, coverId);
                    }
                }).catch(() => {})).catch(() => {});
            }
            return p;
        };
    }());

    // Extract post ID from any play-block upload response shape
    function extractPostId(resp) {
        if (!resp || typeof resp !== 'object') return null;
        // Direct: {id}, {post_id}, {ID}
        if (resp.id)      return resp.id;
        if (resp.post_id) return resp.post_id;
        if (resp.ID)      return resp.ID;
        // Nested: {data:{id}}, {post:{id}}, {song:{id}}, {track:{id}}
        for (const key of ['data', 'post', 'song', 'track', 'result']) {
            if (resp[key] && resp[key].id) return resp[key].id;
        }
        return null;
    }

    function setCoverOnPost(postId, coverId) {
        fetch('/wp-json/wp/v2/posts/' + postId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': myngerConfig.nonce,
            },
            body: JSON.stringify({ featured_media: coverId }),
        }).catch(e => console.error('Mynger: failed to set cover art on post', postId, e));
    }

    // -----------------------------------------------------------------------
    // 2. Observe DOM for #mup-form and inject cover art picker + validation
    // -----------------------------------------------------------------------
    function observeForms() {
        tryInjectMupForm();
        new MutationObserver(function () { tryInjectMupForm(); })
            .observe(document.body, { childList: true, subtree: true });
    }

    function tryInjectMupForm() {
        const mupForm = document.getElementById('mup-form');
        if (mupForm && !mupForm.querySelector('.mynger-cover-wrap')) {
            injectCoverArtPicker($(mupForm));
            hookMupBtnValidation(mupForm);
        }
    }

    function injectCoverArtPicker($mupForm) {
        const $wrap = $(`
            <div class="mynger-cover-wrap" style="margin:16px 0;">
                <label class="mynger-cover-label" style="display:block;margin-bottom:6px;font-size:13px;color:#ccc;">
                    Cover Art <span style="color:#ff4444;font-weight:bold;">*</span>
                </label>
                <div class="mynger-cover-inner" style="display:flex;align-items:center;gap:12px;">
                    <div class="mynger-cover-thumb" style="width:64px;height:64px;border:1px dashed #444;border-radius:4px;overflow:hidden;background:#1a1a1a;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                        <span style="font-size:22px;color:#444;">&#128247;</span>
                    </div>
                    <div style="flex:1;">
                        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="mynger-cover-input" style="font-size:13px;color:#ccc;display:block;width:100%;">
                        <div class="mynger-cover-status" style="font-size:12px;color:#888;margin-top:4px;min-height:16px;"></div>
                    </div>
                </div>
            </div>
        `);

        // Insert before the submit button
        const $btn = $mupForm.find('#mup-btn');
        if ($btn.length) $btn.before($wrap);
        else $mupForm.append($wrap);

        $mupForm.find('.mynger-cover-input').on('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            uploadCoverArt(file, $mupForm);
        });
    }

    function hookMupBtnValidation(mupForm) {
        const btn = mupForm.querySelector('#mup-btn');
        if (!btn) return;
        // Use capture=true so this fires before play-block's listener
        btn.addEventListener('click', function (e) {
            const wrap = mupForm.querySelector('.mynger-cover-wrap');
            if (wrap && !pendingCoverId) {
                e.stopImmediatePropagation();
                e.preventDefault();
                const status = mupForm.querySelector('.mynger-cover-status');
                if (status) {
                    status.textContent = 'Cover art is required before uploading.';
                    status.style.color = '#ff4444';
                    wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }, true /* capture */);
    }

    // -----------------------------------------------------------------------
    // 3. Upload cover art image to WP media library
    // -----------------------------------------------------------------------
    function uploadCoverArt(file, $mupForm) {
        const $status = $mupForm.find('.mynger-cover-status');
        const $thumb  = $mupForm.find('.mynger-cover-thumb');

        pendingCoverId = null;
        $status.text('Uploading cover art…').css('color', '#888');

        // Local preview
        const reader = new FileReader();
        reader.onload = e => $thumb.html(
            '<img src="' + escHtml(e.target.result) + '" style="width:100%;height:100%;object-fit:cover;">'
        );
        reader.readAsDataURL(file);

        const formData = new FormData();
        formData.append('file', file, file.name);
        formData.append('title', file.name.replace(/\.[^.]+$/, ''));

        $.ajax({
            url: (window.wpApiSettings && wpApiSettings.root ? wpApiSettings.root : '/wp-json/') + 'wp/v2/media',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: xhr => xhr.setRequestHeader('X-WP-Nonce', myngerConfig.nonce),
        })
        .then(resp => {
            if (!resp || !resp.id) throw new Error('No media ID returned');
            pendingCoverId = resp.id;
            $status.text('Cover art ready ✓').css('color', '#1ed760');
        })
        .catch(err => {
            $status.text('Cover art upload failed — ' + (err.responseJSON && err.responseJSON.message ? err.responseJSON.message : 'check console')).css('color', '#ff4444');
            $thumb.html('<span style="font-size:22px;color:#ff4444;">&#10007;</span>');
            console.error('Mynger cover art upload error:', err);
        });
    }

    // -----------------------------------------------------------------------
    // 4. Intercept audio/video file inputs → direct S3 upload
    // -----------------------------------------------------------------------
    function hookUploadInputs() {
        $(document).on('change', 'input[type="file"][accept*="audio"], input[type="file"][accept*="video"], input[type="file"][name="stream"], input[type="file"][name="file"]', function (e) {
            const file = this.files && this.files[0];
            if (!file) return;
            const isMedia = MEDIA_TYPES.some(t => file.type === t || file.type.startsWith(t.split('/')[0] + '/'));
            if (!isMedia) return;

            e.preventDefault();
            e.stopPropagation();
            uploadToMynger(file, $(this));
        });
    }

    function uploadToMynger(file, $input) {
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
            injectS3Url(s3Url, $input);
        })
        .catch(err => {
            console.error('Mynger upload error:', err);
            $progress.error('Upload failed — ' + (err.message || 'check console'));
        });
    }

    function putToS3(presignedUrl, file, $progress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            // Use the un-patched open/send to avoid our interceptor catching S3 PUT
            xhr._murl = '';
            xhr.open('PUT', presignedUrl, true);
            xhr.setRequestHeader('Content-Type', file.type);
            xhr.upload.onprogress = e => {
                if (e.lengthComputable) $progress.pct(Math.round((e.loaded / e.total) * 100));
            };
            xhr.onload = () => (xhr.status >= 200 && xhr.status < 300) ? resolve() : reject(new Error('S3 PUT failed: ' + xhr.status));
            xhr.onerror = () => reject(new Error('Network error during S3 upload'));
            xhr.send(file);
        });
    }

    function injectS3Url(s3Url, $input) {
        // play-block reads from the file input itself after the change event;
        // we replace the value in the #mup-form's hidden stream field if present,
        // or store on the input's data for play-block to pick up.
        const $mupForm = $input.closest('#mup-form, form');
        let $stream = $mupForm.find('input[name="stream"]');
        if (!$stream.length) $stream = $('<input type="hidden" name="stream">').appendTo($mupForm);
        $stream.val(s3Url);

        $input.val('');
        $mupForm.find('input[name="stream_url"], input[placeholder*="URL"]').val(s3Url);
        $mupForm.trigger('mynger:stream-ready', [s3Url]);
    }

    // -----------------------------------------------------------------------
    // 5. Progress UI
    // -----------------------------------------------------------------------
    function createProgressUI($input, fileName) {
        const $wrap = $(`
            <div class="mynger-progress-wrap" style="margin:8px 0;font-size:13px;">
                <div style="margin-bottom:4px;color:#aaa;">
                    <span>${escHtml(fileName)}</span>
                    <span class="mynger-status" style="margin-left:8px;color:#1ed760;">Preparing…</span>
                </div>
                <div style="background:#2a2a2a;border-radius:3px;height:4px;overflow:hidden;">
                    <div class="mynger-progress-bar" style="height:100%;width:0%;background:#1ed760;transition:width 0.2s;"></div>
                </div>
            </div>
        `);
        $input.after($wrap);
        return {
            status: msg => $wrap.find('.mynger-status').text(msg),
            pct:    pct => $wrap.find('.mynger-progress-bar').css('width', pct + '%'),
            finish: ()  => { $wrap.find('.mynger-progress-bar').css('width', '100%'); setTimeout(() => $wrap.fadeOut(), 2000); },
            error:  msg => { $wrap.find('.mynger-status').text(msg).css('color', '#ff4444'); $wrap.find('.mynger-progress-bar').css('background', '#ff4444'); },
        };
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Boot
    $(document).ready(function () {
        hookUploadInputs();
        observeForms();
    });

})(jQuery);
