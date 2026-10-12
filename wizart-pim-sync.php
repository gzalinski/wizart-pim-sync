<?php
/**
 * Plugin Name:       Wizart PIM Sync
 * Plugin URI:        https://wizart.ai
 * Description:       Sync WooCommerce products to the Wizart PIM (Brand → Collection → Product) via CSV import, with a settings page for API credentials and a per-product Sync button that saves the resulting wizart_uuid.
 * Version:           1.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Enspire Creative
 * Text Domain:       wizart-pim-sync
 *
 * Changelog:
 * 1.2
 * - "tile" products only: replaced the single "Wizart Image" field with a
 *   multi-image gallery ("Wizart Images"), on both the parent product
 *   (simple products) and each variation. Uses WordPress's native gallery
 *   media picker (add/remove thumbnails).
 * - product_image for tile is now a comma-separated list of image
 *   references (no extension), matching Wizart's text/multiselect format
 *   for this field. Each gallery image gets a unique filename in the ZIP.
 * - Variation/product inclusion in the CSV (skip-if-empty logic) now checks
 *   the gallery for tile, and the single Wizart Image field for other types.
 * - wallpaper and other non-tile types are unaffected — still single image.
 *
 * 1.1
 * - Category → Product Type mapping in Settings (multi-select per type: wallpaper, tile),
 *   with a configurable Fallback Product Type dropdown; replaces the old single
 *   "Default Product Type Code" text field.
 * - Confirmed real wallpaper/tile CSV column schemas (via live import validation),
 *   fixing the earlier guessed mapping.
 * - Dedicated per-variation meta fields (Wizart Image, Product Width, Repeat Width,
 *   Pattern Repeat, Pattern Offset, Wallpaper Roll Length / Product Length, Product
 *   Thickness, Pattern Width/Length), each with variation → parent fallback.
 * - UI values now entered in inches, auto-converted to meters at CSV export.
 * - product_width/product_length also fall back to the pa_size attribute
 *   ("WxL", e.g. "12x24") when the dedicated meta field is empty (tile only).
 * - color / material / coating auto-populated from pa_color / pa_material
 *   WooCommerce attributes, with variation → parent fallback.
 * - Configurable dropdown fields (interior_type, style, pattern) — options
 *   editable in Settings, shown only on the parent product.
 * - Fixed-option multiselect fields (currently: layouts, for tile) — options
 *   hardcoded from the Wizart spec, available on both parent and variations
 *   with fallback.
 * - related_skus auto-computed (sibling variations, or WooCommerce Cross-sells
 *   for simple products).
 * - wizart_uuid meta box moved to a dedicated "Wizart PIM" box under Publish;
 *   simple products now also resolve/store a Collection UUID (previously an
 *   Article UUID), unifying behavior with variable products.
 * - Delete flow: mass-removes all Articles in a Collection before deleting the
 *   Collection itself; 404 on delete is treated as already-deleted, not an error.
 * - Sync/Delete buttons now poll live status via AJAX (no manual page refresh
 *   needed), with a "Reset" escape hatch if WP-Cron stalls mid-import.
 * - Debug tool in Settings to fetch Wizart's default CSV mapping per product type.
 *
 * 1.0.0
 * - Initial release: OAuth2 auth, CSV+ZIP import for wallpaper, wizart_uuid
 *   meta field with Sync button.
 */

if (!defined('ABSPATH')) exit;

define('WIZART_PIM_VERSION', '1.2');
define('WIZART_PIM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WIZART_PIM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WIZART_PIM_PLUGIN_DIR . 'includes/class-wizart-pim-settings.php';
require_once WIZART_PIM_PLUGIN_DIR . 'includes/class-wizart-pim-auth.php';
require_once WIZART_PIM_PLUGIN_DIR . 'includes/class-wizart-pim-field-maps.php';
require_once WIZART_PIM_PLUGIN_DIR . 'includes/class-wizart-pim-sync.php';

/**
 * Bootstrap
 */
final class Wizart_PIM_Plugin {

    public static function init() {
        // WooCommerce is required
        add_action('plugins_loaded', [__CLASS__, 'check_dependencies']);

        Wizart_PIM_Settings::init();
        Wizart_PIM_Auth::init();
        Wizart_PIM_Sync::init();
    }

    public static function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                esc_html_e('Wizart PIM Sync requires WooCommerce to be installed and active.', 'wizart-pim-sync');
                echo '</p></div>';
            });
        }
    }

    public static function activate() {
        // valori implicite la activare
        $defaults = [
            'username'               => '',
            'password'               => '',
            'client_id'              => '',
            'client_secret'          => '',
            'category_map'           => [],
            'fallback_product_type'  => 'wallpaper',
            'dropdown_options'       => [],
        ];
        if (!get_option(Wizart_PIM_Settings::OPTION_KEY)) {
            add_option(Wizart_PIM_Settings::OPTION_KEY, $defaults);
        }
    }

    public static function deactivate() {
        delete_transient(Wizart_PIM_Auth::TOKEN_TRANSIENT);
        // dezactivăm eventualele evenimente cron programate
        $timestamp = wp_next_scheduled('wizart_check_import_status_event');
        if ($timestamp) {
            wp_clear_scheduled_hook('wizart_check_import_status_event');
        }
    }
}

register_activation_hook(__FILE__, ['Wizart_PIM_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Wizart_PIM_Plugin', 'deactivate']);

Wizart_PIM_Plugin::init();
