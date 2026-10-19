<?php
/**
 * Plugin Name: Mynger Integration (Empyrian)
 * Description: Routes all audio uploads to Mynger S3. Provides presigned-URL REST proxy and client-side direct upload JS.
 * Version: 1.0.2
 */

defined('ABSPATH') || exit;

define('MYNGER_API_BASE', 'https://api.mynger.com');
define('MYNGER_S3_BASE',  'https://myngerbucket14bd7-main.s3.eu-west-1.amazonaws.com');

// ---------------------------------------------------------------------------
// 1. REST ENDPOINTS
// ---------------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('mynger/v1', '/presign', [
        'methods'             => 'POST',
        'callback'            => 'mynger_rest_presign',
        'permission_callback' => 'is_user_logged_in',
    ]);
});

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
add_filter('wp_get_attachment_url', 'mynger_s3_attachment_url', 10, 2);

// The attached file is an S3 URL, which WordPress would otherwise prefix with the uploads folder.
function mynger_s3_attachment_url(string $url, int $att_id): string {
    return (string) get_post_meta($att_id, 'mynger_s3_url', true) ?: $url;
}

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

    $filename  = basename($local);
    $file_size = filesize($local);
    if ($file_size === false || $file_size > 20 * 1024 * 1024) {
        error_log('Mynger Integration (Empyrian): skipping server-side S3 upload for ' . $filename . ' — file exceeds 20 MB limit (' . $file_size . ' bytes). Upload via the upload page instead.');
        return;
    }

    $s3_url = mynger_s3_upload($local, $filename, $mime, $d);
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
        'timeout' => 60,
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
// 4. ENQUEUE CLIENT-SIDE JS
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
