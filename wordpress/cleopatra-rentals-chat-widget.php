<?php
/**
 * Plugin Name: Cleopatra Rentals Smart Chat Widget
 * Description: Embeds Cleopatra Rentals listing assistant widget and connects it to your listing bot API.
 * Version: 1.0.0
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

function cleo_chat_widget_settings_page() {
    ?>
    <div class="wrap">
        <h1>Cleopatra Chat Widget</h1>
        <form method="post" action="options.php">
            <?php settings_fields('cleo_chat_widget_settings'); ?>
            <?php do_settings_sections('cleo_chat_widget_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="cleo_chat_widget_api_base_url">API Base URL</label></th>
                    <td>
                        <input type="url" id="cleo_chat_widget_api_base_url" name="cleo_chat_widget_api_base_url" value="<?php echo esc_attr(get_option('cleo_chat_widget_api_base_url', 'https://YOUR-API-DOMAIN')); ?>" class="regular-text" />
                        <p class="description">Example: https://bot.cleopatrarentals.one</p>
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
    </div>
    <?php
}

function cleo_chat_widget_enqueue_assets() {
    $plugin_url = plugin_dir_url(__FILE__);

    wp_enqueue_style(
        'cleo-chat-widget-style',
        $plugin_url . '../frontend/widget.css',
        array(),
        '1.0.0'
    );

    $config = array(
        'apiBaseUrl' => get_option('cleo_chat_widget_api_base_url', 'https://YOUR-API-DOMAIN'),
        'title' => get_option('cleo_chat_widget_title', 'Cleopatra Rentals Assistant'),
        'greeting' => get_option('cleo_chat_widget_greeting', 'Hi 👋 Tell me what kind of rental you need and I will find matching listings.')
    );

    wp_register_script(
        'cleo-chat-widget-script',
        $plugin_url . '../frontend/widget.js',
        array(),
        '1.0.0',
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
