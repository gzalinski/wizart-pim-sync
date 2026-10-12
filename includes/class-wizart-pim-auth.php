<?php
/**
 * OAuth2 authentication against the Wizart PIM API.
 * Token is cached in a transient (Wizart API tokens are long-lived, ~1 year,
 * but we still refresh a bit before actual expiry).
 */

if (!defined('ABSPATH')) exit;

class Wizart_PIM_Auth {

    const TOKEN_TRANSIENT = 'wizart_pim_access_token';
    const API_BASE         = 'https://pim-client.wizart.ai';

    public static function init() {
        add_action('wp_ajax_wizart_test_connection', [__CLASS__, 'ajax_test_connection']);
    }

    /**
     * Returns a valid Bearer token string, or a WP_Error.
     */
    public static function get_access_token($force_refresh = false) {
        if (!$force_refresh) {
            $cached = get_transient(self::TOKEN_TRANSIENT);
            if ($cached) {
                return $cached;
            }
        }

        if (!Wizart_PIM_Settings::is_configured()) {
            return new WP_Error(
                'wizart_missing_credentials',
                __('Wizart API credentials are not configured. Go to Settings → Wizart PIM.', 'wizart-pim-sync')
            );
        }

        $response = wp_remote_post(self::API_BASE . '/oauth/token', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode([
                'username'      => Wizart_PIM_Settings::get('username'),
                'password'      => Wizart_PIM_Settings::get('password'),
                'client_secret' => Wizart_PIM_Settings::get('client_secret'),
                'client_id'     => Wizart_PIM_Settings::get('client_id'),
                'grant_type'    => 'password',
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            return new WP_Error(
                'wizart_auth_failed',
                sprintf(__('Authentication failed (HTTP %d). Check your credentials.', 'wizart-pim-sync'), $code)
            );
        }

        $token      = $body['access_token'];
        $expires_in = isset($body['expires_in']) ? intval($body['expires_in']) : 3600;

        set_transient(self::TOKEN_TRANSIENT, $token, max(60, $expires_in - 60));

        return $token;
    }

    /**
     * AJAX handler for the "Test Connection" button on the settings page.
     */
    public static function ajax_test_connection() {
        check_ajax_referer('wizart_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wizart-pim-sync')]);
        }

        $token = self::get_access_token(true);

        if (is_wp_error($token)) {
            wp_send_json_error(['message' => $token->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Connected successfully — token obtained.', 'wizart-pim-sync')]);
    }
}
