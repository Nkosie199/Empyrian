<?php
/**
 * Plugin Name: Mynger Integration (Empyrian)
 * Description: Routes all audio uploads to Mynger S3. Provides presigned-URL REST proxy and client-side direct upload JS.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

define('MYNGER_API_BASE', 'https://api.mynger.com');
define('MYNGER_S3_BASE',  'https://myngerbucket14bd7-main.s3.eu-west-1.amazonaws.com');

// ---------------------------------------------------------------------------
// 1. REST ENDPOINTS
// ---------------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('mynger/v1', '/token', [
        'methods'             => 'GET',
        'callback'            => 'mynger_rest_get_token',
        'permission_callback' => 'is_user_logged_in',
    ]);
    register_rest_route('mynger/v1', '/presign', [
        'methods'             => 'POST',
        'callback'            => 'mynger_rest_presign',
        'permission_callback' => 'is_user_logged_in',
    ]);
});

function mynger_get_user_data(int $wp_uid): ?array {
    $subject = get_user_meta($wp_uid, 'openid-connect-generic-subject-identity', true);
    $raw     = get_user_meta($wp_uid, 'wp_openid-connect-generic-last-token-response', true);
    if (!$subject || !$raw) return null;
    $tok = maybe_unserialize($raw);
    $access_token = is_array($tok) ? ($tok['access_token'] ?? '') : '';
    if (!$access_token) return null;
    return ['userId' => $subject, 'token' => $access_token];
}

function mynger_rest_get_token(WP_REST_Request $req): WP_REST_Response {
    $d = mynger_get_user_data(get_current_user_id());
    if (!$d) return new WP_REST_Response(['error' => 'No Mynger SSO session. Please log in via Mynger.'], 403);
    return new WP_REST_Response($d, 200);
}

function mynger_rest_presign(WP_REST_Request $req): WP_REST_Response {
    $body = $req->get_json_params();
    $d    = mynger_get_user_data(get_current_user_id());
    if (!$d) return new WP_REST_Response(['error' => 'No Mynger session'], 403);

    $file_name = sanitize_file_name($body['fileName'] ?? '');
    $file_type = sanitize_text_field($body['fileType'] ?? 'audio/mpeg');
    if (!$file_name) return new WP_REST_Response(['error' => 'fileName required'], 400);

    $r = wp_remote_post(MYNGER_API_BASE . '/api/files/presigned-url', [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'token'    => $d['token'],
            'userId'   => $d['userId'],
            'fileName' => $file_name,
            'fileType' => $file_type,
        ]),
        'timeout' => 15,
    ]);

    if (is_wp_error($r)) return new WP_REST_Response(['error' => $r->get_error_message()], 502);
    $code = wp_remote_retrieve_response_code($r);
    $json = json_decode(wp_remote_retrieve_body($r), true);
    if ($code === 200 && !empty($json['url'])) {
        return new WP_REST_Response([
            'presignedUrl' => $json['url'],
            's3Url'        => MYNGER_S3_BASE . '/' . $d['userId'] . '/' . $file_name,
            'userId'       => $d['userId'],
        ], 200);
    }
    return new WP_REST_Response(['error' => 'Mynger API error', 'code' => $code], $code ?: 502);
}

// ---------------------------------------------------------------------------
// 2. SERVER-SIDE HOOK — re-upload audio to Mynger S3 after WP saves locally
// ---------------------------------------------------------------------------
add_action('add_attachment', 'mynger_redirect_to_s3');

function mynger_redirect_to_s3(int $att_id): void {
    $mime = (string) get_post_mime_type($att_id);
    if (!str_starts_with($mime, 'audio/') && !str_starts_with($mime, 'video/')) return;

    $local = get_attached_file($att_id);
    if (!$local || !file_exists($local)) return;

    $d = mynger_get_user_data(get_current_user_id());
    if (!$d) {
        error_log('Mynger Integration (Empyrian): no SSO session for WP user ' . get_current_user_id());
        return;
    }

    $filename = basename($local);
    $s3_url   = mynger_s3_upload($local, $filename, $mime, $d);
    if (!$s3_url) { error_log('Mynger Integration (Empyrian): S3 upload failed for ' . $filename); return; }

    update_attached_file($att_id, $s3_url);
    wp_update_post(['ID' => $att_id, 'guid' => $s3_url]);
    update_post_meta($att_id, 'mynger_s3_url', $s3_url);
    update_post_meta($att_id, 'mynger_user_id', $d['userId']);
    @unlink($local);
    error_log('Mynger Integration (Empyrian): ' . $filename . ' -> ' . $s3_url);
}

function mynger_s3_upload(string $local_path, string $file_name, string $mime, array $d): ?string {
    $r = wp_remote_post(MYNGER_API_BASE . '/api/files/presigned-url', [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['token' => $d['token'], 'userId' => $d['userId'], 'fileName' => $file_name, 'fileType' => $mime]),
        'timeout' => 15,
    ]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return null;
    $put_url = json_decode(wp_remote_retrieve_body($r), true)['url'] ?? null;
    if (!$put_url) return null;

    $contents = file_get_contents($local_path);
    if ($contents === false) return null;

    $pr = wp_remote_request($put_url, [
        'method'  => 'PUT',
        'headers' => ['Content-Type' => $mime, 'Content-Length' => strlen($contents)],
        'body'    => $contents,
        'timeout' => 300,
    ]);
    if (is_wp_error($pr)) return null;
    $code = wp_remote_retrieve_response_code($pr);
    if ($code < 200 || $code >= 300) return null;
    return MYNGER_S3_BASE . '/' . $d['userId'] . '/' . $file_name;
}

// ---------------------------------------------------------------------------
// 3. S3 DELETE on attachment removal
// ---------------------------------------------------------------------------
add_action('before_delete_post', 'mynger_s3_delete_on_remove', 5, 2);

function mynger_s3_delete_on_remove(int $post_id, WP_Post $post): void {
    $s3_url = '';
    // Cover both play-block station posts and raw WP attachments
    foreach (['stream', 'file', 'mynger_s3_url'] as $key) {
        $val = (string) get_post_meta($post_id, $key, true);
        if ($val && strpos($val, 'amazonaws.com') !== false) { $s3_url = $val; break; }
    }
    if (!$s3_url) return;

    $path  = str_replace(MYNGER_S3_BASE . '/', '', $s3_url);
    $parts = explode('/', $path, 2);
    if (count($parts) < 2 || !$parts[1]) return;

    $d = mynger_get_user_data((int) $post->post_author);
    if (!$d) return;

    wp_remote_post(MYNGER_API_BASE . '/api/files/delete', [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['token' => $d['token'], 'userId' => $d['userId'], 'fileName' => $parts[1]]),
        'timeout' => 10,
    ]);
}

// ---------------------------------------------------------------------------
// 4. SET FEATURED IMAGE after play-block post save
//    The upload form injects a hidden `featured_media` field containing the
//    WP attachment ID of the cover art uploaded to the media library.
// ---------------------------------------------------------------------------
add_action('save_post', function (int $post_id): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (!isset($_POST['featured_media'])) return;

    $media_id = (int) $_POST['featured_media'];
    if ($media_id <= 0) return;

    // Only act if the attachment belongs to this site
    if (get_post_type($media_id) !== 'attachment') return;

    set_post_thumbnail($post_id, $media_id);
});

// ---------------------------------------------------------------------------
// 5. ENQUEUE CLIENT-SIDE JS
// ---------------------------------------------------------------------------
add_action('wp_enqueue_scripts', function () {
    if (!is_user_logged_in()) return;
    wp_enqueue_script('mynger-upload', plugin_dir_url(__FILE__) . 'js/mynger-upload.js', ['jquery'], '1.0.0', true);
    wp_localize_script('mynger-upload', 'myngerConfig', [
        'restBase' => rest_url('mynger/v1'),
        'nonce'    => wp_create_nonce('wp_rest'),
        's3Base'   => MYNGER_S3_BASE,
        'loggedIn' => true,
        'platform' => 'empyrian',
    ]);
});
