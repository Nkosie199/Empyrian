<?php
/**
 * Plugin Name: Mynger Integration (Empyrian)
 * Description: Moves audio/video uploads to the uploader's Mynger S3 storage and removes them from S3 when their post is deleted.
 * Version: 1.1.0
 */

defined('ABSPATH') || exit;

define('MYNGER_API_BASE', 'https://api.mynger.com');
define('MYNGER_S3_BASE',  'https://myngerbucket14bd7-main.s3.eu-west-1.amazonaws.com');

/**
 * Reads the Mynger OIDC subject identity and access token stored by the
 * daggerhart-openid-connect-generic plugin after SSO login.
 */
function mynger_get_user_data(int $wp_uid): ?array {
    $subject = get_user_meta($wp_uid, 'openid-connect-generic-subject-identity', true);
    $tok     = mynger_fresh_token_response($wp_uid);
    if (!$subject || !$tok) {
        return null;
    }
    return ['userId' => $subject, 'token' => $tok['access_token']];
}

const MYNGER_TOKEN_OPTION = 'openid-connect-generic-last-token-response';

// Mynger access tokens last 15 minutes but WordPress logins last days, so refresh here rather than rely on the OIDC plugin's per-session refresh.
function mynger_fresh_token_response(int $wp_uid): ?array {
    $tok = maybe_unserialize(get_user_option(MYNGER_TOKEN_OPTION, $wp_uid));
    if (!is_array($tok) || empty($tok['access_token'])) {
        return null;
    }
    if (mynger_token_expires_at($tok['access_token']) > time() + 60) {
        return $tok;
    }
    $lock = 'mynger_token_refresh_' . $wp_uid;
    if (get_transient($lock)) {
        return null;
    }
    set_transient($lock, 1, 30);
    $fresh = mynger_refresh_token_response($tok['refresh_token'] ?? '');
    if ($fresh) {
        update_user_option($wp_uid, MYNGER_TOKEN_OPTION, $fresh);
    }
    delete_transient($lock);
    return $fresh;
}

function mynger_token_expires_at(string $jwt): int {
    $parts   = explode('.', $jwt);
    $payload = isset($parts[1]) ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    return (int) ($payload['exp'] ?? 0);
}

function mynger_refresh_token_response(string $refresh_token): ?array {
    $oidc = (array) get_option('openid_connect_generic_settings', []);
    if (!$refresh_token || empty($oidc['endpoint_token'])) {
        return null;
    }
    $r = wp_remote_post($oidc['endpoint_token'], [
        'body'    => [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $oidc['client_id'] ?? '',
            'client_secret' => $oidc['client_secret'] ?? '',
        ],
        'timeout' => 15,
    ]);
    $json = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
    if (wp_remote_retrieve_response_code($r) !== 200 || empty($json['access_token'])) {
        error_log('Mynger Integration: token refresh failed (' . (is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r)) . ')');
        return null;
    }
    $json['time'] = time();
    return $json;
}

/**
 * Asks Mynger for a one-off upload URL into the user's storage, which Mynger refuses when the file won't fit.
 * Returns ['url' => ...] or ['error' => ..., 'status' => ...].
 */
function mynger_request_upload_url(array $d, string $file_name, string $file_type, ?int $file_size): array {
    $r = wp_remote_post(MYNGER_API_BASE . '/api/files/presigned-url', [
        'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $d['token']],
        'body'    => wp_json_encode([
            'token'    => $d['token'],
            'userId'   => $d['userId'],
            'fileName' => $file_name,
            'fileType' => $file_type,
            'fileSize' => $file_size,
        ]),
        'timeout' => 15,
    ]);

    if (is_wp_error($r)) {
        return ['error' => $r->get_error_message(), 'status' => 502];
    }

    $code = wp_remote_retrieve_response_code($r);
    $json = json_decode(wp_remote_retrieve_body($r), true);

    if ($code === 200 && !empty($json['url'])) {
        return ['url' => $json['url']];
    }

    return ['error' => $json['error'] ?? 'Mynger API error', 'status' => $code ?: 502];
}

// ---------------------------------------------------------------------------
// 2. STORAGE LIMIT — video/audio count against the user's Mynger storage, so a
//    file that won't fit is refused before WordPress keeps it.
// ---------------------------------------------------------------------------
add_filter('wp_handle_upload_prefilter', 'mynger_require_storage_room');

function mynger_require_storage_room(array $file): array {
    if (!mynger_is_media((string) ($file['type'] ?? ''))) {
        return $file;
    }
    $d    = mynger_get_user_data(get_current_user_id());
    $left = $d ? mynger_storage_left_bytes($d['userId']) : null;
    if ($left !== null && (int) $file['size'] > $left) {
        $file['error'] = sprintf('Not enough Mynger storage: %s left. Free up space in WebDocs, then try again.', size_format($left));
    }
    return $file;
}

function mynger_is_media(string $mime): bool {
    return str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/');
}

function mynger_storage_left_bytes(string $user_id): ?int {
    $limit = mynger_storage_megabytes('limit', $user_id);
    $used  = mynger_storage_megabytes('size', $user_id);
    return $limit === null || $used === null ? null : (int) max(0, ($limit - $used) * MB_IN_BYTES);
}

function mynger_storage_megabytes(string $measure, string $user_id): ?float {
    $r = wp_remote_get(MYNGER_API_BASE . '/api/storage/' . $measure . '/' . rawurlencode($user_id), ['timeout' => 10]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
        return null;
    }
    return (float) wp_remote_retrieve_body($r);
}

// ---------------------------------------------------------------------------
// 3. SERVER-SIDE HOOK
//    After any video/audio attachment is created, immediately upload it to
//    Mynger S3, update the attachment record to point to the S3 URL, and
//    delete the local copy. Falls back silently if no SSO session is found.
// ---------------------------------------------------------------------------
add_action('add_attachment', 'mynger_redirect_to_s3');
add_filter('wp_get_attachment_url', 'mynger_s3_attachment_url', 10, 2);
add_filter('wp_generate_attachment_metadata', 'mynger_keep_media_metadata', 10, 2);

// The attached file is an S3 URL, which WordPress would otherwise prefix with the uploads folder.
function mynger_s3_attachment_url(string $url, int $att_id): string {
    return (string) get_post_meta($att_id, 'mynger_s3_url', true) ?: $url;
}

function mynger_redirect_to_s3(int $att_id): void {
    $mime = (string) get_post_mime_type($att_id);
    if (!mynger_is_media($mime)) {
        return;
    }

    $local = get_attached_file($att_id);
    if (!$local || !file_exists($local)) {
        return;
    }

    $d = mynger_get_user_data(get_current_user_id());
    if (!$d) {
        error_log('Mynger Integration: no SSO session for WP user ' . get_current_user_id() . ' — file kept local: ' . $local);
        return;
    }

    $filename = mynger_s3_file_name($att_id, $local);
    $s3_url   = mynger_s3_upload($local, $filename, $mime, $d);

    if (!$s3_url) {
        error_log('Mynger Integration: S3 upload failed for ' . $filename . ' — file kept local');
        return;
    }

    update_attached_file($att_id, $s3_url);
    wp_update_post(['ID' => $att_id, 'guid' => $s3_url]);
    update_post_meta($att_id, 'mynger_s3_url', $s3_url);
    update_post_meta($att_id, 'mynger_user_id', $d['userId']);
    mynger_save_media_metadata($att_id, $local, $mime);
    @unlink($local);

    error_log('Mynger Integration: uploaded ' . $filename . ' -> ' . $s3_url);
}

// The attachment ID keeps S3 keys unique: WordPress only de-duplicates names against files still on disk, and these are deleted once on S3.
function mynger_s3_file_name(int $att_id, string $local): string {
    $info = pathinfo($local);
    return $info['filename'] . '-' . $att_id . (isset($info['extension']) ? '.' . $info['extension'] : '');
}

// The site caps outgoing requests at 10 seconds, too short to send a large video to S3.
function mynger_s3_put_timeout(array $args): array {
    $args['timeout'] = 300;
    return $args;
}

/**
 * Gets a presigned PUT URL from Mynger then PUTs the file to S3.
 * Returns the permanent S3 URL on success, null on failure.
 */
function mynger_s3_upload(string $local_path, string $file_name, string $mime, array $d): ?string {
    // Step 1 — get presigned PUT URL
    $put_url = mynger_request_upload_url($d, $file_name, $mime, (int) filesize($local_path))['url'] ?? null;
    if (!$put_url) {
        return null;
    }

    // Step 2 — PUT file to S3
    $contents = file_get_contents($local_path);
    if ($contents === false) {
        return null;
    }

    add_filter('http_request_args', 'mynger_s3_put_timeout', 1000);
    $pr = wp_remote_request($put_url, [
        'method'  => 'PUT',
        'headers' => ['Content-Type' => $mime, 'Content-Length' => strlen($contents)],
        'body'    => $contents,
    ]);
    remove_filter('http_request_args', 'mynger_s3_put_timeout', 1000);

    if (is_wp_error($pr)) {
        return null;
    }

    $code = wp_remote_retrieve_response_code($pr);
    if ($code < 200 || $code >= 300) {
        return null;
    }

    return MYNGER_S3_BASE . '/' . $d['userId'] . '/' . $file_name;
}

// ---------------------------------------------------------------------------
// 4. S3 DELETE on post/attachment removal
// ---------------------------------------------------------------------------
add_action('before_delete_post', 'mynger_s3_delete_on_remove', 5, 2);
add_action('delete_attachment', 'mynger_s3_delete_on_remove', 5, 2);

function mynger_s3_delete_on_remove(int $post_id, WP_Post $post): void {
    $key    = $post->post_type === 'attachment' ? 'mynger_s3_url' : 'stream';
    $s3_url = (string) get_post_meta($post_id, $key, true);
    if (!$s3_url) {
        return;
    }

    $d = mynger_get_user_data((int) $post->post_author);
    // Only the author's own folder: a post can link to someone else's file.
    $own_folder = $d ? MYNGER_S3_BASE . '/' . $d['userId'] . '/' : '';
    if (!$d || !str_starts_with($s3_url, $own_folder)) {
        return;
    }
    $file_name = substr($s3_url, strlen($own_folder));

    wp_remote_post(MYNGER_API_BASE . '/api/files/delete', [
        'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $d['token']],
        'body'    => wp_json_encode([
            'token'    => $d['token'],
            'userId'   => $d['userId'],
            'fileName' => $file_name,
        ]),
        'timeout' => 10,
    ]);
}

// The local file is gone once it is on S3, so WordPress can no longer read its length; read it first and keep it.
function mynger_save_media_metadata(int $att_id, string $local, string $mime): void {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    $meta = str_starts_with($mime, 'video/') ? wp_read_video_metadata($local) : wp_read_audio_metadata($local);
    if ($meta) {
        update_post_meta($att_id, 'mynger_media_metadata', $meta);
        wp_update_attachment_metadata($att_id, $meta);
    }
}

function mynger_keep_media_metadata(array $metadata, int $att_id): array {
    $saved = get_post_meta($att_id, 'mynger_media_metadata', true);
    return is_array($saved) ? array_merge($saved, array_filter($metadata)) : $metadata;
}
