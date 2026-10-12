<?php
/**
 * Settings → Wizart PIM
 * Stochează credențialele API (username, password, client_id, client_secret)
 * și productTypeCode implicit, în wp_options (cheie unică 'wizart_pim_settings').
 */

if (!defined('ABSPATH')) exit;

class Wizart_PIM_Settings {

    const OPTION_KEY = 'wizart_pim_settings';
    const PAGE_SLUG  = 'wizart-pim-sync';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_ajax_wizart_fetch_mapping', [__CLASS__, 'ajax_fetch_mapping']);
    }

    /* ---------------------------------------------------------- */

    public static function add_settings_page() {
        add_options_page(
            __('Wizart PIM Sync', 'wizart-pim-sync'),
            __('Wizart PIM', 'wizart-pim-sync'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting(self::PAGE_SLUG . '_group', self::OPTION_KEY, [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
        ]);

        add_settings_section(
            'wizart_pim_main',
            __('API Credentials', 'wizart-pim-sync'),
            [__CLASS__, 'section_intro'],
            self::PAGE_SLUG
        );

        add_settings_field('username', __('Login', 'wizart-pim-sync'), [__CLASS__, 'field_username'], self::PAGE_SLUG, 'wizart_pim_main');
        add_settings_field('password', __('Password', 'wizart-pim-sync'), [__CLASS__, 'field_password'], self::PAGE_SLUG, 'wizart_pim_main');
        add_settings_field('client_id', __('Client ID', 'wizart-pim-sync'), [__CLASS__, 'field_client_id'], self::PAGE_SLUG, 'wizart_pim_main');
        add_settings_field('client_secret', __('Client Secret', 'wizart-pim-sync'), [__CLASS__, 'field_client_secret'], self::PAGE_SLUG, 'wizart_pim_main');

        add_settings_section(
            'wizart_pim_import',
            __('Product Type Mapping', 'wizart-pim-sync'),
            [__CLASS__, 'section_type_mapping_intro'],
            self::PAGE_SLUG
        );

        foreach (Wizart_PIM_Field_Maps::PRODUCT_TYPES as $type) {
            $label = Wizart_PIM_Field_Maps::ALL_TYPE_LABELS[$type] ?? $type;
            add_settings_field(
                'category_map_' . $type,
                sprintf(__('%s categories', 'wizart-pim-sync'), $label),
                [__CLASS__, 'field_category_map'],
                self::PAGE_SLUG,
                'wizart_pim_import',
                ['type' => $type]
            );
        }

        add_settings_field(
            'fallback_product_type',
            __('Fallback Product Type', 'wizart-pim-sync'),
            [__CLASS__, 'field_fallback_product_type'],
            self::PAGE_SLUG,
            'wizart_pim_import'
        );

        add_settings_section(
            'wizart_pim_dropdowns',
            __('Dropdown Options', 'wizart-pim-sync'),
            [__CLASS__, 'section_dropdowns_intro'],
            self::PAGE_SLUG
        );

        foreach (Wizart_PIM_Field_Maps::DROPDOWN_FIELDS as $key) {
            add_settings_field(
                'dropdown_' . $key,
                ucwords(str_replace('_', ' ', $key)),
                [__CLASS__, 'field_dropdown_options'],
                self::PAGE_SLUG,
                'wizart_pim_dropdowns',
                ['key' => $key]
            );
        }
    }

    public static function section_dropdowns_intro() {
        echo '<p>' . esc_html__('One option per line. These become a dropdown on the parent product page (not on individual variations) for choosing that field\'s value.', 'wizart-pim-sync') . '</p>';
    }

    public static function section_type_mapping_intro() {
        echo '<p>' . esc_html__('Assign each WooCommerce product category to a Wizart product type. A category should only be assigned to one type. Products whose category doesn\'t match any selection below will use the Fallback Product Type.', 'wizart-pim-sync') . '</p>';
    }

    public static function section_intro() {
        echo '<p>' . esc_html__('Credentials used to obtain the OAuth2 Bearer token from the Wizart PIM API. Get these from your Wizart account or contact support@wizart.ai.', 'wizart-pim-sync') . '</p>';
    }

    /* ------------------------- getters ------------------------- */

    public static function get($key, $default = '') {
        $opts = get_option(self::OPTION_KEY, []);
        return isset($opts[$key]) && $opts[$key] !== '' ? $opts[$key] : $default;
    }

    public static function all() {
        return get_option(self::OPTION_KEY, []);
    }

    public static function is_configured() {
        return self::get('username') && self::get('password') && self::get('client_id') && self::get('client_secret');
    }

    /* ------------------------- sanitize ------------------------- */

    public static function sanitize_settings($input) {
        $existing = get_option(self::OPTION_KEY, []);
        $output   = [];

        $output['username']  = sanitize_text_field($input['username'] ?? '');
        $output['client_id'] = sanitize_text_field($input['client_id'] ?? '');

        // câmpurile secrete: dacă utilizatorul lasă gol, păstrăm valoarea existentă
        // (nu re-afișăm parola/secretul în HTML din motive de securitate)
        $output['password']      = !empty($input['password']) ? $input['password'] : ($existing['password'] ?? '');
        $output['client_secret'] = !empty($input['client_secret']) ? $input['client_secret'] : ($existing['client_secret'] ?? '');

        // mapare categorii -> tip Wizart (multi-select per tip din PRODUCT_TYPES)
        $category_map = [];
        foreach (Wizart_PIM_Field_Maps::PRODUCT_TYPES as $type) {
            $ids = $input['category_map'][$type] ?? [];
            $category_map[$type] = array_map('absint', (array) $ids);
        }
        $output['category_map'] = $category_map;

        // fallback: trebuie să fie unul dintre codurile Wizart cunoscute
        $fallback = sanitize_text_field($input['fallback_product_type'] ?? 'wallpaper');
        $output['fallback_product_type'] = array_key_exists($fallback, Wizart_PIM_Field_Maps::ALL_TYPE_LABELS)
            ? $fallback
            : 'wallpaper';

        // opțiuni dropdown (una pe linie -> array curat, fără linii goale)
        $dropdown_options = [];
        foreach (Wizart_PIM_Field_Maps::DROPDOWN_FIELDS as $key) {
            $raw   = str_replace("\r\n", "\n", $input['dropdown_options'][$key] ?? '');
            $lines = array_filter(array_map('trim', explode("\n", $raw)), function ($l) { return $l !== ''; });
            $dropdown_options[$key] = array_values(array_map('sanitize_text_field', $lines));
        }
        $output['dropdown_options'] = $dropdown_options;

        // credențialele s-au putut schimba -> invalidăm tokenul cache-uit
        delete_transient(Wizart_PIM_Auth::TOKEN_TRANSIENT);

        add_settings_error(
            self::OPTION_KEY,
            'wizart_pim_saved',
            __('Wizart PIM settings saved.', 'wizart-pim-sync'),
            'updated'
        );

        return $output;
    }

    /* ------------------------- fields ------------------------- */

    public static function field_username() {
        printf(
            '<input type="text" name="%1$s[username]" value="%2$s" class="regular-text" autocomplete="off" />',
            esc_attr(self::OPTION_KEY),
            esc_attr(self::get('username'))
        );
    }

    public static function field_password() {
        printf(
            '<input type="password" name="%1$s[password]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />',
            esc_attr(self::OPTION_KEY),
            self::get('password') ? esc_attr__('•••••••• (leave blank to keep current)', 'wizart-pim-sync') : esc_attr__('Not set', 'wizart-pim-sync')
        );
    }

    public static function field_client_id() {
        printf(
            '<input type="text" name="%1$s[client_id]" value="%2$s" class="regular-text" autocomplete="off" />',
            esc_attr(self::OPTION_KEY),
            esc_attr(self::get('client_id'))
        );
    }

    public static function field_client_secret() {
        printf(
            '<input type="password" name="%1$s[client_secret]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />',
            esc_attr(self::OPTION_KEY),
            self::get('client_secret') ? esc_attr__('•••••••• (leave blank to keep current)', 'wizart-pim-sync') : esc_attr__('Not set', 'wizart-pim-sync')
        );
    }

    public static function field_category_map($args) {
        $type         = $args['type'];
        $selected     = self::get('category_map', []);
        $selected_ids = array_map('intval', $selected[$type] ?? []);

        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($terms) || empty($terms)) {
            echo '<p class="description">' . esc_html__('No product categories found.', 'wizart-pim-sync') . '</p>';
            return;
        }
        ?>
        <select
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[category_map][<?php echo esc_attr($type); ?>][]"
            multiple="multiple"
            size="6"
            style="min-width:280px;"
        >
            <?php foreach ($terms as $term): ?>
                <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(in_array($term->term_id, $selected_ids, true)); ?>>
                    <?php echo esc_html($term->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public static function field_dropdown_options($args) {
        $key     = $args['key'];
        $options = self::get_dropdown_options($key);
        printf(
            '<textarea name="%1$s[dropdown_options][%2$s]" rows="5" cols="40" class="large-text code">%3$s</textarea>',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            esc_textarea(implode("\n", $options))
        );
        echo '<p class="description">' . esc_html__('One value per line.', 'wizart-pim-sync') . '</p>';
    }

    /**
     * Returnează opțiunile configurate pentru un dropdown field (array de string-uri).
     */
    public static function get_dropdown_options($key) {
        $all = self::get('dropdown_options', []);
        return is_array($all[$key] ?? null) ? $all[$key] : [];
    }

    public static function field_fallback_product_type() {
        $current = self::get('fallback_product_type', 'wallpaper');
        ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[fallback_product_type]">
            <?php foreach (Wizart_PIM_Field_Maps::ALL_TYPE_LABELS as $code => $label): ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($current, $code); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Used when a product\'s category doesn\'t match any of the multi-selects above.', 'wizart-pim-sync'); ?>
        </p>
        <?php
    }

    /* ------------------------- page ------------------------- */

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wizart PIM Sync — Settings', 'wizart-pim-sync'); ?></h1>

            <?php settings_errors(self::OPTION_KEY); ?>

            <?php self::render_status_and_test_button(); ?>

            <?php self::render_mapping_debug_tool(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::PAGE_SLUG . '_group');
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    private static function render_status_and_test_button() {
        $configured = self::is_configured();
        ?>
        <p>
            <strong><?php esc_html_e('Status:', 'wizart-pim-sync'); ?></strong>
            <?php if ($configured): ?>
                <span style="color:#046b04;"><?php esc_html_e('Credentials configured.', 'wizart-pim-sync'); ?></span>
            <?php else: ?>
                <span style="color:#a00;"><?php esc_html_e('Credentials incomplete.', 'wizart-pim-sync'); ?></span>
            <?php endif; ?>
        </p>
        <p>
            <button type="button" class="button" id="wizart-test-connection">
                <?php esc_html_e('Test Connection', 'wizart-pim-sync'); ?>
            </button>
            <span id="wizart-test-connection-result" style="margin-left:8px;"></span>
        </p>
        <script>
        jQuery(function ($) {
            $('#wizart-test-connection').on('click', function () {
                var btn = $(this), result = $('#wizart-test-connection-result');
                btn.prop('disabled', true);
                result.css('color', '').text('<?php echo esc_js(__('Testing…', 'wizart-pim-sync')); ?>');

                $.post(ajaxurl, {
                    action: 'wizart_test_connection',
                    nonce: '<?php echo esc_js(wp_create_nonce('wizart_test_connection')); ?>'
                }).done(function (resp) {
                    result.css('color', resp.success ? 'green' : '#a00').text(resp.data.message);
                }).fail(function () {
                    result.css('color', '#a00').text('<?php echo esc_js(__('Request failed', 'wizart-pim-sync')); ?>');
                }).always(function () {
                    btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Tool de debug: descarcă header-ele CSV de mapping default pentru un
     * productTypeCode direct de la Wizart, ca să vedem exact ce coloane
     * așteaptă API-ul (util când import-ul dă 500/eroare de format).
     */
    private static function render_mapping_debug_tool() {
        ?>
        <div style="border:1px solid #ccd0d4; background:#fff; padding:12px 16px; margin:16px 0; max-width:700px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Debug: Default CSV Mapping', 'wizart-pim-sync'); ?></h2>
            <p class="description">
                <?php esc_html_e('Fetches the exact CSV column headers Wizart expects for a product type. Use this to diagnose import errors (e.g. HTTP 500).', 'wizart-pim-sync'); ?>
            </p>
            <p>
                <input type="text" id="wizart-mapping-code" value="<?php echo esc_attr(self::get('fallback_product_type', 'wallpaper')); ?>" class="regular-text" placeholder="wallpaper" />
                <button type="button" class="button" id="wizart-fetch-mapping"><?php esc_html_e('Fetch Mapping', 'wizart-pim-sync'); ?></button>
            </p>
            <pre id="wizart-mapping-result" style="background:#f6f7f7; padding:10px; max-height:300px; overflow:auto; white-space:pre-wrap;"></pre>
        </div>
        <script>
        jQuery(function ($) {
            $('#wizart-fetch-mapping').on('click', function () {
                var btn = $(this), result = $('#wizart-mapping-result');
                var code = $('#wizart-mapping-code').val();
                btn.prop('disabled', true);
                result.text('<?php echo esc_js(__('Fetching…', 'wizart-pim-sync')); ?>');

                $.post(ajaxurl, {
                    action: 'wizart_fetch_mapping',
                    product_type_code: code,
                    nonce: '<?php echo esc_js(wp_create_nonce('wizart_fetch_mapping')); ?>'
                }).done(function (resp) {
                    if (resp.success) {
                        result.text(resp.data.csv);
                    } else {
                        result.text('Error: ' + resp.data.message);
                    }
                }).fail(function () {
                    result.text('<?php echo esc_js(__('Request failed', 'wizart-pim-sync')); ?>');
                }).always(function () {
                    btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_fetch_mapping() {
        check_ajax_referer('wizart_fetch_mapping', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wizart-pim-sync')]);
        }

        $code = isset($_POST['product_type_code']) ? sanitize_text_field($_POST['product_type_code']) : '';
        if (!$code) {
            wp_send_json_error(['message' => __('Product type code is required', 'wizart-pim-sync')]);
        }

        $token = Wizart_PIM_Auth::get_access_token();
        if (is_wp_error($token)) {
            wp_send_json_error(['message' => $token->get_error_message()]);
        }

        $url = Wizart_PIM_Auth::API_BASE . '/api/v2.1/data-mappings/default/' . rawurlencode($code);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'text/csv',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code_http = wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);

        if ($code_http !== 200) {
            wp_send_json_error(['message' => 'HTTP ' . $code_http . ': ' . $body]);
        }

        wp_send_json_success(['csv' => $body]);
    }

}
