<?php
/**
 * Plugin Name: Cleopatra Rentals Smart Chat Widget
 * Description: Embeds Cleopatra Rentals listing assistant widget and connects it to your listing bot API.
 * Version: 1.3.0
 * Author: Cleopatra Rentals
 */

if (!defined('ABSPATH')) {
    exit;
}

function cleo_chat_widget_register_settings() {
    register_setting('cleo_chat_widget_settings', 'cleo_chat_widget_api_base_url');
    register_setting('cleo_chat_widget_settings', 'cleo_chat_widget_title');
    register_setting('cleo_chat_widget_settings', 'cleo_chat_widget_greeting');
}
add_action('admin_init', 'cleo_chat_widget_register_settings');

function cleo_chat_widget_admin_menu() {
    add_options_page(
        'Cleopatra Chat Widget',
        'Cleopatra Chat Widget',
        'manage_options',
        'cleo-chat-widget',
        'cleo_chat_widget_settings_page'
    );
}
add_action('admin_menu', 'cleo_chat_widget_admin_menu');

function cleo_chat_widget_test_connection_ajax() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }

    check_ajax_referer('cleo_chat_widget_test_connection', 'nonce');

    $api_base_url = trim((string) get_option('cleo_chat_widget_api_base_url', ''));
    if ($api_base_url === '') {
        wp_send_json_error(array('message' => 'Please set API Base URL first.'), 400);
    }

    $health_url = untrailingslashit($api_base_url) . '/health';
    $response = wp_remote_get($health_url, array('timeout' => 15));

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => 'Connection failed: ' . $response->get_error_message(),
            'health_url' => $health_url,
        ), 502);
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    if ($status < 200 || $status >= 300) {
        wp_send_json_error(array(
            'message' => 'Health endpoint returned non-2xx response.',
            'status' => $status,
            'health_url' => $health_url,
            'body' => $body,
        ), 502);
    }

    wp_send_json_success(array(
        'message' => 'Connection successful.',
        'status' => $status,
        'health_url' => $health_url,
        'payload' => is_array($decoded) ? $decoded : $body,
    ));
}
add_action('wp_ajax_cleo_chat_widget_test_connection', 'cleo_chat_widget_test_connection_ajax');

function cleo_chat_widget_settings_page() {
    $nonce = wp_create_nonce('cleo_chat_widget_test_connection');
    ?>
    <div class="wrap">
        <h1>Cleopatra Chat Widget</h1>
        <p>This plugin proxies chat requests through WordPress to avoid browser CORS/mixed-content issues.</p>
        <form method="post" action="options.php">
            <?php settings_fields('cleo_chat_widget_settings'); ?>
            <?php do_settings_sections('cleo_chat_widget_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="cleo_chat_widget_api_base_url">API Base URL</label></th>
                    <td>
                        <input type="url" id="cleo_chat_widget_api_base_url" name="cleo_chat_widget_api_base_url" value="<?php echo esc_attr(get_option('cleo_chat_widget_api_base_url', '')); ?>" class="regular-text" placeholder="https://bot.cleopatrarentals.one" />
                        <p class="description">Required. Example: https://bot.cleopatrarentals.one</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cleo_chat_widget_title">Widget Title</label></th>
                    <td>
                        <input type="text" id="cleo_chat_widget_title" name="cleo_chat_widget_title" value="<?php echo esc_attr(get_option('cleo_chat_widget_title', 'Cleopatra Rentals Assistant')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cleo_chat_widget_greeting">Greeting Message</label></th>
                    <td>
                        <textarea id="cleo_chat_widget_greeting" name="cleo_chat_widget_greeting" rows="3" class="large-text"><?php echo esc_textarea(get_option('cleo_chat_widget_greeting', 'Hi 👋 Tell me what kind of rental you need and I will find matching listings.')); ?></textarea>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr />
        <h2>Connection Test</h2>
        <p>Use this button to verify WordPress can reach your backend API health endpoint.</p>
        <button id="cleo-chat-test-btn" class="button button-secondary">Test API Connection</button>
        <pre id="cleo-chat-test-output" style="margin-top:12px;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;max-width:900px;white-space:pre-wrap;"></pre>

        <script>
          (function () {
            const btn = document.getElementById('cleo-chat-test-btn');
            const out = document.getElementById('cleo-chat-test-output');
            if (!btn || !out) return;

            btn.addEventListener('click', async function () {
              out.textContent = 'Testing connection...';
              btn.disabled = true;

              try {
                const params = new URLSearchParams();
                params.append('action', 'cleo_chat_widget_test_connection');
                params.append('nonce', <?php echo wp_json_encode($nonce); ?>);

                const res = await fetch(ajaxurl, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                  body: params.toString(),
                });

                const data = await res.json();
                out.textContent = JSON.stringify(data, null, 2);
              } catch (err) {
                out.textContent = 'Connection test failed: ' + (err && err.message ? err.message : String(err));
              } finally {
                btn.disabled = false;
              }
            });
          })();
        </script>
    </div>
    <?php
}

function cleo_chat_widget_register_rest_routes() {
    register_rest_route('cleo-chat/v1', '/chat', array(
        'methods' => 'POST',
        'callback' => 'cleo_chat_widget_proxy_chat',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'cleo_chat_widget_register_rest_routes');

function cleo_chat_widget_proxy_chat(WP_REST_Request $request) {
    $api_base_url = trim((string) get_option('cleo_chat_widget_api_base_url', ''));
    if ($api_base_url === '') {
        return new WP_REST_Response(array(
            'error' => 'Plugin not configured. Set API Base URL in Settings > Cleopatra Chat Widget.'
        ), 500);
    }

    $body = $request->get_json_params();
    $message = isset($body['message']) ? trim((string) $body['message']) : '';
    $session_id = isset($body['session_id']) ? trim((string) $body['session_id']) : 'wp-visitor';

    if ($message === '') {
        return new WP_REST_Response(array('error' => 'message is required'), 400);
    }

    $target_url = untrailingslashit($api_base_url) . '/api/chat';

    $response = wp_remote_post($target_url, array(
        'timeout' => 25,
        'headers' => array('Content-Type' => 'application/json'),
        'body' => wp_json_encode(array(
            'message' => $message,
            'session_id' => $session_id,
        )),
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'error' => 'Could not reach listings API: ' . $response->get_error_message(),
        ), 502);
    }

    $status = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);

    if (!is_array($decoded)) {
        return new WP_REST_Response(array(
            'error' => 'Listings API returned invalid JSON',
            'raw' => $raw_body,
        ), 502);
    }

    return new WP_REST_Response($decoded, $status ?: 200);
}

function cleo_chat_widget_enqueue_assets() {
    $config = array(
        'apiBaseUrl' => get_option('cleo_chat_widget_api_base_url', ''),
        'directChatUrl' => esc_url_raw(rest_url('cleo-chat/v1/chat')),
        'title' => get_option('cleo_chat_widget_title', 'Cleopatra Rentals Assistant'),
        'greeting' => get_option('cleo_chat_widget_greeting', 'Hi 👋 Tell me what kind of rental you need and I will find matching listings.')
    );

    wp_enqueue_style(
        'cleo-chat-widget-style',
        plugin_dir_url(__FILE__) . 'assets/widget.css',
        array(),
        '1.3.0'
    );

    wp_register_script(
        'cleo-chat-widget-script',
        plugin_dir_url(__FILE__) . 'assets/widget.js',
        array(),
        '1.3.0',
        true
    );

    wp_add_inline_script(
        'cleo-chat-widget-script',
        'window.CLEO_WIDGET_CONFIG = ' . wp_json_encode($config) . ';',
        'before'
    );

    wp_enqueue_script('cleo-chat-widget-script');
}
add_action('wp_enqueue_scripts', 'cleo_chat_widget_enqueue_assets');
