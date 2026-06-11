<?php
/**
 * Plugin Name: Cleopatra Rentals Smart Chat Widget
 * Description: Embeds Cleopatra Rentals listing assistant widget and connects it to your listing bot API.
 * Version: 1.4.0
 * Author: Cleopatra Rentals
 */

if (!defined('ABSPATH')) {
    exit;
}

function cleo_chat_widget_register_settings() {
    register_setting('cleo_chat_widget_settings', 'cleo_chat_widget_api_base_url');
    register_setting('cleo_chat_widget_settings', 'cleo_chat_widget_title');
    register_setting('cleo_chat_widget_settings', 'cleo_chat_widget_greeting');
    register_setting('cleo_chat_widget_settings', 'cleo_chat_widget_use_builtin_engine');
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

function cleo_chat_widget_tokenize($text) {
    $text = strtolower((string) $text);
    $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text);
    $parts = preg_split('/\s+/', trim($text));
    $stop = array('a', 'an', 'and', 'or', 'the', 'to', 'for', 'of', 'in', 'on', 'at', 'is', 'are', 'with', 'i', 'we', 'you', 'it', 'this', 'that', 'about', 'from', 'be', 'as', 'by', 'my', 'me', 'our', 'your', 'can', 'could', 'would', 'should', 'please', 'need', 'want');
    $tokens = array();
    foreach ($parts as $p) {
        if (strlen($p) > 1 && !in_array($p, $stop, true)) {
            $tokens[] = $p;
        }
    }
    return array_values(array_unique($tokens));
}

function cleo_chat_widget_extract_links($html, $base_url) {
    $links = array();
    if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches)) {
        foreach ($matches[1] as $href) {
            if (strpos($href, 'mailto:') === 0 || strpos($href, 'javascript:') === 0) {
                continue;
            }
            if (strpos($href, 'http://') === 0 || strpos($href, 'https://') === 0) {
                $full = $href;
            } else {
                $full = rtrim($base_url, '/') . '/' . ltrim($href, '/');
            }
            if (strpos($full, 'cleopatrarentals.one') !== false) {
                $links[] = preg_replace('/#.*$/', '', $full);
            }
        }
    }
    return array_values(array_unique($links));
}

function cleo_chat_widget_builtin_index_listings($force = false) {
    $cache_key = 'cleo_chat_widget_builtin_listings';
    $cached = get_transient($cache_key);
    if (!$force && is_array($cached) && !empty($cached)) {
        return $cached;
    }

    $base = 'https://cleopatrarentals.one';
    $home = wp_remote_get($base, array('timeout' => 20));
    if (is_wp_error($home)) {
        return array();
    }

    $html = wp_remote_retrieve_body($home);
    $links = cleo_chat_widget_extract_links($html, $base);

    $listings = array();
    foreach ($links as $url) {
        $lower = strtolower($url);
        if (strpos($lower, 'listing') === false && strpos($lower, 'property') === false && strpos($lower, 'rent') === false && strpos($lower, 'apartment') === false && strpos($lower, 'villa') === false && strpos($lower, 'unit') === false) {
            continue;
        }

        $resp = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($resp)) {
            continue;
        }
        $body = wp_remote_retrieve_body($resp);
        if (!$body) {
            continue;
        }

        $title = 'Listing';
        if (preg_match('/<title>(.*?)<\/title>/is', $body, $m)) {
            $title = wp_strip_all_tags($m[1]);
        }

        $text = wp_strip_all_tags($body);
        $text = preg_replace('/\s+/', ' ', $text);
        $desc = mb_substr(trim($text), 0, 1300);

        if (mb_strlen($desc) < 60) {
            continue;
        }

        $listings[] = array(
            'url' => $url,
            'title' => mb_substr(trim($title), 0, 180),
            'description' => $desc,
        );

        if (count($listings) >= 120) {
            break;
        }
    }

    set_transient($cache_key, $listings, 6 * HOUR_IN_SECONDS);
    update_option('cleo_chat_widget_last_builtin_index_utc', gmdate('c'), false);
    return $listings;
}

function cleo_chat_widget_builtin_answer($message) {
    $listings = cleo_chat_widget_builtin_index_listings(false);
    if (empty($listings)) {
        return array(
            'reply' => 'I could not load listings right now. Please try again in a moment.',
            'matches' => array(),
            'source' => 'wordpress_builtin',
        );
    }

    $query_tokens = cleo_chat_widget_tokenize($message);
    $scored = array();

    foreach ($listings as $listing) {
        $blob = strtolower($listing['title'] . ' ' . $listing['description']);
        $score = 0;
        foreach ($query_tokens as $t) {
            if (strpos($blob, $t) !== false) {
                $score += 1;
            }
        }
        $listing['score'] = $score;
        $scored[] = $listing;
    }

    usort($scored, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    $top = array_slice($scored, 0, 3);
    if (empty($top)) {
        return array(
            'reply' => 'I could not find a close match yet. Please share area, budget, and bedrooms.',
            'matches' => array(),
            'source' => 'wordpress_builtin',
        );
    }

    $first = $top[0];
    $lines = array();
    foreach ($top as $row) {
        $lines[] = '- ' . $row['title'] . ' (' . $row['url'] . ')';
    }

    $reply = "Great question! Based on your request, the best match right now is **{$first['title']}**.\n";
    $reply .= "Here are similar options you can review:\n" . implode("\n", $lines) . "\n\n";
    $reply .= "Tell me your budget, area, and bedrooms and I will refine recommendations.";

    return array(
        'reply' => $reply,
        'matches' => $top,
        'source' => 'wordpress_builtin',
    );
}

function cleo_chat_widget_test_connection_ajax() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }

    check_ajax_referer('cleo_chat_widget_test_connection', 'nonce');

    $use_builtin = get_option('cleo_chat_widget_use_builtin_engine', '1') === '1';
    if ($use_builtin) {
        $rows = cleo_chat_widget_builtin_index_listings(true);
        wp_send_json_success(array(
            'message' => 'Built-in engine is active and ready.',
            'indexed' => count($rows),
            'last_index_utc' => get_option('cleo_chat_widget_last_builtin_index_utc', null),
        ));
    }

    $api_base_url = trim((string) get_option('cleo_chat_widget_api_base_url', ''));
    if ($api_base_url === '') {
        wp_send_json_error(array('message' => 'Please set API Base URL first or enable built-in engine.'), 400);
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
        <p>Choose built-in mode if you only have shared hosting and cannot run a Python backend.</p>
        <form method="post" action="options.php">
            <?php settings_fields('cleo_chat_widget_settings'); ?>
            <?php do_settings_sections('cleo_chat_widget_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Engine Mode</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cleo_chat_widget_use_builtin_engine" value="1" <?php checked(get_option('cleo_chat_widget_use_builtin_engine', '1'), '1'); ?> />
                            Use built-in WordPress engine (recommended for shared hosting)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cleo_chat_widget_api_base_url">API Base URL</label></th>
                    <td>
                        <input type="url" id="cleo_chat_widget_api_base_url" name="cleo_chat_widget_api_base_url" value="<?php echo esc_attr(get_option('cleo_chat_widget_api_base_url', '')); ?>" class="regular-text" placeholder="https://bot.cleopatrarentals.one" />
                        <p class="description">Optional when built-in engine is enabled. Required only for external backend mode.</p>
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
        <p>Click to test your selected mode (built-in index or external API health endpoint).</p>
        <button id="cleo-chat-test-btn" class="button button-secondary">Run Connection Test</button>
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
    $body = $request->get_json_params();
    $message = isset($body['message']) ? trim((string) $body['message']) : '';
    $session_id = isset($body['session_id']) ? trim((string) $body['session_id']) : 'wp-visitor';

    if ($message === '') {
        return new WP_REST_Response(array('error' => 'message is required'), 400);
    }

    $use_builtin = get_option('cleo_chat_widget_use_builtin_engine', '1') === '1';
    if ($use_builtin) {
        return new WP_REST_Response(cleo_chat_widget_builtin_answer($message), 200);
    }

    $api_base_url = trim((string) get_option('cleo_chat_widget_api_base_url', ''));
    if ($api_base_url === '') {
        return new WP_REST_Response(array(
            'error' => 'Plugin not configured. Enable built-in engine or set API Base URL in Settings > Cleopatra Chat Widget.'
        ), 500);
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
        '1.4.0'
    );

    wp_register_script(
        'cleo-chat-widget-script',
        plugin_dir_url(__FILE__) . 'assets/widget.js',
        array(),
        '1.4.0',
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
