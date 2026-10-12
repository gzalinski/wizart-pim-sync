<?php
/**
 * Meta box "Wizart PIM" (poziționat sub Publish, context 'side') cu:
 *  - câmp readonly "wizart_uuid"
 *  - buton "Sync to Wizart"
 *
 * Comportament la prima sincronizare (wizart_uuid gol):
 *  - Produs SIMPLU  -> 1 rând în CSV (Collection = Product, același nume).
 *                      wizart_uuid salvat = UUID-ul Articolului (Product) din PIM.
 *  - Produs VARIABIL -> Collection = numele produsului părinte; fiecare
 *                      VARIAȚIE devine câte un rând (Article) în CSV, sub
 *                      acea Collection. wizart_uuid salvat pe produsul
 *                      PĂRINTE = UUID-ul Collection-ului (nu al unui articol).
 */

if (!defined('ABSPATH')) exit;

class Wizart_PIM_Sync {

    const CSV_SUBDIR        = 'wizart-csv';
    const MAX_POLL_ATTEMPTS = 10;
    const POLL_INTERVAL     = 20; // seconds
    const META_BOX_ID       = 'wizart_pim_sync_box';
    const META_PENDING      = '_wizart_pending_resolution';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register_meta_box']);
        add_action('save_post_product', [__CLASS__, 'save_extra_fields']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_media_scripts']);
        add_action('woocommerce_product_after_variable_attributes', [__CLASS__, 'render_variation_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [__CLASS__, 'save_variation_fields'], 10, 2);
        add_action('admin_footer-post.php', [__CLASS__, 'print_variation_media_js']);
        add_action('admin_footer-post-new.php', [__CLASS__, 'print_variation_media_js']);
        add_action('wp_ajax_wizart_sync_product', [__CLASS__, 'ajax_sync_product']);
        add_action('wp_ajax_wizart_delete_product', [__CLASS__, 'ajax_delete_product']);
        add_action('wp_ajax_wizart_check_sync_status', [__CLASS__, 'ajax_check_sync_status']);
        add_action('wp_ajax_wizart_check_deletion_cleared', [__CLASS__, 'ajax_check_deletion_cleared']);
        add_action('wp_ajax_wizart_reset_sync_status', [__CLASS__, 'ajax_reset_sync_status']);
        add_action('wizart_check_import_status_event', [__CLASS__, 'check_import_status'], 10, 3);
    }

    public static function enqueue_media_scripts($hook) {
        global $post;
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (!$post || $post->post_type !== 'product') return;
        wp_enqueue_media();
    }

    /* ==================================================================
     * UI — meta box sub Publish
     * ================================================================== */

    public static function register_meta_box() {
        // priority 'default' se randează după submitdiv (care e 'core'),
        // deci apare imediat sub caseta Publish.
        add_meta_box(
            self::META_BOX_ID,
            __('Wizart PIM', 'wizart-pim-sync'),
            [__CLASS__, 'render_meta_box'],
            'product',
            'side',
            'default'
        );
    }

    public static function render_meta_box($post) {
        $product = wc_get_product($post->ID);
        if (!$product) return;

        $uuid        = get_post_meta($post->ID, 'wizart_uuid', true);
        $status      = get_post_meta($post->ID, '_wizart_sync_status', true);
        $is_variable = $product->is_type('variable');

        $product_type_code = self::get_product_type_code($product);
        $extra_fields       = Wizart_PIM_Field_Maps::get_extra_fields($product_type_code);

        wp_nonce_field('wizart_sync_nonce', 'wizart_sync_nonce_field');
        wp_nonce_field('wizart_extra_fields_nonce', 'wizart_extra_fields_nonce_field');
        ?>
        <div id="wizart-pim-box">
            <p>
                <label for="wizart_uuid" style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php esc_html_e('Wizart UUID (Collection)', 'wizart-pim-sync'); ?>
                </label>
                <input
                    type="text"
                    id="wizart_uuid"
                    value="<?php echo esc_attr($uuid); ?>"
                    disabled="disabled"
                    readonly="readonly"
                    style="width:100%;"
                    placeholder="<?php esc_attr_e('Not synced yet', 'wizart-pim-sync'); ?>"
                />
            </p>

            <?php if ($is_variable): ?>
                <p class="description" style="margin-top:-6px;">
                    <?php esc_html_e('Variable product: this UUID identifies the Collection. Each variation is synced as a separate Article inside it.', 'wizart-pim-sync'); ?>
                </p>
            <?php elseif ($product_type_code === 'tile'):
                $gallery_csv = get_post_meta($post->ID, '_wizart_gallery_ids', true);
                ?>
                <p class="form-field">
                    <label style="display:block; font-weight:600; margin-bottom:4px;">
                        <?php esc_html_e('Wizart Images (Gallery)', 'wizart-pim-sync'); ?>
                    </label>
                    <?php self::render_gallery_field('_wizart_gallery_ids', $gallery_csv); ?>
                    <span class="description">
                        <?php esc_html_e('Tile products support multiple images per SKU.', 'wizart-pim-sync'); ?>
                    </span>
                </p>
            <?php else:
                $image_id  = get_post_meta($post->ID, '_wizart_image_id', true);
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
                ?>
                <p class="form-field">
                    <label style="display:block; font-weight:600; margin-bottom:4px;">
                        <?php esc_html_e('Wizart Image', 'wizart-pim-sync'); ?>
                    </label>
                    <span class="wizart-variation-image-wrap" style="display:flex; align-items:center; gap:10px;">
                        <img
                            class="wizart-variation-image-preview"
                            src="<?php echo esc_url($image_url ?: wc_placeholder_img_src()); ?>"
                            style="width:64px; height:64px; object-fit:cover; border:1px solid #ddd;"
                        />
                        <input
                            type="hidden"
                            class="wizart-variation-image-id"
                            name="_wizart_image_id"
                            value="<?php echo esc_attr($image_id); ?>"
                        />
                        <button type="button" class="button wizart-upload-image-btn">
                            <?php esc_html_e('Upload/Change', 'wizart-pim-sync'); ?>
                        </button>
                        <button type="button" class="button wizart-remove-image-btn">
                            <?php esc_html_e('Remove', 'wizart-pim-sync'); ?>
                        </button>
                    </span>
                    <span class="description">
                        <?php esc_html_e('Falls back to the featured image if left empty.', 'wizart-pim-sync'); ?>
                    </span>
                </p>
            <?php endif; ?>

            <?php if (!empty($extra_fields)): ?>
                <hr style="margin:12px 0;" />
                <p class="description" style="margin-top:0;">
                    <?php esc_html_e('Additional fields required by Wizart for this product type:', 'wizart-pim-sync'); ?>
                </p>
                <?php foreach ($extra_fields as $column => $def):
                    $meta_key = '_wizart_field_' . $column;
                    $value    = get_post_meta($post->ID, $meta_key, true);
                    $has_size_fallback = in_array($column, ['product_width', 'product_length'], true);
                    ?>
                    <p class="form-field">
                        <label for="wizart_extra_<?php echo esc_attr($column); ?>" style="display:block; font-weight:600; margin-bottom:4px;">
                            <?php echo esc_html($def['label']); ?>
                            <?php if (!empty($def['required']) && !$has_size_fallback): ?>
                                <span style="color:#a00;">*</span>
                            <?php endif; ?>
                            <?php if ($has_size_fallback): ?>
                                <span style="font-weight:normal; color:#888; font-size:11px;">
                                    (<?php esc_html_e('or derived from pa_size attribute if empty', 'wizart-pim-sync'); ?>)
                                </span>
                            <?php endif; ?>
                        </label>
                        <input
                            type="<?php echo esc_attr($def['type'] ?? 'text'); ?>"
                            id="wizart_extra_<?php echo esc_attr($column); ?>"
                            name="wizart_extra_fields[<?php echo esc_attr($column); ?>]"
                            value="<?php echo esc_attr($value); ?>"
                            placeholder="<?php echo esc_attr($def['placeholder'] ?? ''); ?>"
                            <?php if (isset($def['min'])): ?>min="<?php echo esc_attr($def['min']); ?>"<?php endif; ?>
                            <?php if (isset($def['max'])): ?>max="<?php echo esc_attr($def['max']); ?>"<?php endif; ?>
                            <?php if (isset($def['step'])): ?>step="<?php echo esc_attr($def['step']); ?>"<?php endif; ?>
                            style="width:100%;"
                        />
                    </p>
                <?php endforeach; ?>
                <hr style="margin:12px 0;" />
            <?php endif; ?>

            <?php
            $dropdown_options_present = false;
            foreach (Wizart_PIM_Field_Maps::DROPDOWN_FIELDS as $dd_key) {
                if (!empty(Wizart_PIM_Settings::get_dropdown_options($dd_key))) {
                    $dropdown_options_present = true;
                    break;
                }
            }
            ?>
            <?php if ($dropdown_options_present): ?>
                <?php foreach (Wizart_PIM_Field_Maps::DROPDOWN_FIELDS as $dd_key):
                    $dd_options = Wizart_PIM_Settings::get_dropdown_options($dd_key);
                    if (empty($dd_options)) continue;
                    $is_multi   = in_array($dd_key, Wizart_PIM_Field_Maps::MULTISELECT_DROPDOWN_FIELDS, true);
                    $dd_value   = get_post_meta($post->ID, '_wizart_field_' . $dd_key, true);
                    $dd_selected = $is_multi
                        ? array_filter(array_map('trim', explode(',', $dd_value)))
                        : [$dd_value];
                    ?>
                    <p class="form-field">
                        <label for="wizart_dd_<?php echo esc_attr($dd_key); ?>" style="display:block; font-weight:600; margin-bottom:4px;">
                            <?php echo esc_html(ucwords(str_replace('_', ' ', $dd_key))); ?>
                        </label>
                        <select
                            id="wizart_dd_<?php echo esc_attr($dd_key); ?>"
                            name="wizart_extra_fields[<?php echo esc_attr($dd_key); ?>]<?php echo $is_multi ? '[]' : ''; ?>"
                            style="width:100%;"
                            <?php if ($is_multi): ?>multiple="multiple" size="5"<?php endif; ?>
                        >
                            <?php if (!$is_multi): ?>
                                <option value="">— <?php esc_html_e('none', 'wizart-pim-sync'); ?> —</option>
                            <?php endif; ?>
                            <?php foreach ($dd_options as $dd_option): ?>
                                <option value="<?php echo esc_attr($dd_option); ?>" <?php selected(in_array($dd_option, $dd_selected, true)); ?>>
                                    <?php echo esc_html($dd_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($is_multi): ?>
                            <span class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple.', 'wizart-pim-sync'); ?></span>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
                <hr style="margin:12px 0;" />
            <?php endif; ?>

            <?php
            $fixed_selects = Wizart_PIM_Field_Maps::get_fixed_select_fields($product_type_code);
            ?>
            <?php if (!empty($fixed_selects)): ?>
                <?php foreach ($fixed_selects as $fs_key => $fs_def):
                    $fs_value    = get_post_meta($post->ID, '_wizart_field_' . $fs_key, true);
                    $fs_multiple = !empty($fs_def['multiple']);
                    $fs_selected = $fs_multiple
                        ? array_filter(array_map('trim', explode(',', $fs_value)))
                        : [$fs_value];
                    ?>
                    <p class="form-field">
                        <label for="wizart_fs_<?php echo esc_attr($fs_key); ?>" style="display:block; font-weight:600; margin-bottom:4px;">
                            <?php echo esc_html($fs_def['label']); ?>
                        </label>
                        <select
                            id="wizart_fs_<?php echo esc_attr($fs_key); ?>"
                            name="wizart_extra_fields[<?php echo esc_attr($fs_key); ?>]<?php echo $fs_multiple ? '[]' : ''; ?>"
                            style="width:100%;"
                            <?php if ($fs_multiple): ?>multiple="multiple" size="7"<?php endif; ?>
                        >
                            <?php if (!$fs_multiple): ?>
                                <option value="">— <?php esc_html_e('none', 'wizart-pim-sync'); ?> —</option>
                            <?php endif; ?>
                            <?php foreach ($fs_def['options'] as $fs_option): ?>
                                <option value="<?php echo esc_attr($fs_option); ?>" <?php selected(in_array($fs_option, $fs_selected, true)); ?>>
                                    <?php echo esc_html($fs_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($fs_multiple): ?>
                            <span class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple.', 'wizart-pim-sync'); ?></span>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
                <hr style="margin:12px 0;" />
            <?php endif; ?>

            <p>
                <button
                    type="button"
                    class="button button-primary"
                    id="wizart-sync-btn"
                    data-product-id="<?php echo esc_attr($post->ID); ?>"
                    style="width:100%; <?php echo $uuid ? 'display:none;' : ''; ?>"
                >
                    <?php esc_html_e('Sync to Wizart', 'wizart-pim-sync'); ?>
                </button>
                <button
                    type="button"
                    class="button"
                    id="wizart-delete-btn"
                    data-product-id="<?php echo esc_attr($post->ID); ?>"
                    style="width:100%; color:#a00; border-color:#a00; <?php echo $uuid ? '' : 'display:none;'; ?>"
                >
                    <?php esc_html_e('Delete from Wizart', 'wizart-pim-sync'); ?>
                </button>
            </p>
            <p style="<?php echo (!$uuid && $status) ? '' : 'display:none;'; ?>" id="wizart-reset-wrap">
                <button
                    type="button"
                    class="button-link"
                    id="wizart-reset-btn"
                    data-product-id="<?php echo esc_attr($post->ID); ?>"
                    style="color:#999; font-size:12px;"
                >
                    <?php esc_html_e('Stuck? Reset and try again', 'wizart-pim-sync'); ?>
                </button>
            </p>
            <p id="wizart-sync-status" style="font-style:italic; color:#666;">
                <?php echo esc_html($status); ?>
            </p>
        </div>

        <script>
        jQuery(function ($) {
            var syncBtn   = $('#wizart-sync-btn');
            var deleteBtn = $('#wizart-delete-btn');
            var resetBtn  = $('#wizart-reset-btn');
            var resetWrap = $('#wizart-reset-wrap');
            var status    = $('#wizart-sync-status');
            var uuidField = $('#wizart_uuid');

            var pollTimer = null;
            var deleteCheckTimer = null;

            function stopPolling() {
                if (pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
            }

            function stopDeleteCheck() {
                if (deleteCheckTimer) {
                    clearInterval(deleteCheckTimer);
                    deleteCheckTimer = null;
                }
            }

            function startDeleteCooldown(productId) {
                // Verificare ACTIVĂ (nu ghicit): întrebăm Wizart, la fiecare
                // 3s, dacă SKU-urile acestui produs mai există încă acolo.
                // Dacă nu mai există -> reactivăm Sync imediat. Dacă trece
                // pragul de 30s fără confirmare, reactivăm oricum (cu avertisment).
                var elapsed  = 0;
                var maxWait  = 30; // secunde
                var interval = 3000; // ms

                stopDeleteCheck();
                syncBtn.prop('disabled', true);
                status.text('<?php echo esc_js(__('Deleted. Waiting for Wizart to finish cleanup…', 'wizart-pim-sync')); ?>');

                deleteCheckTimer = setInterval(function () {
                    elapsed += interval / 1000;

                    $.post(ajaxurl, {
                        action: 'wizart_check_deletion_cleared',
                        product_id: productId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wizart_sync_nonce')); ?>'
                    }).done(function (resp) {
                        // dacă între timp s-a pornit un Sync (sau alt Delete),
                        // acest polling e depășit — nu mai atinge UI-ul
                        if (!deleteCheckTimer) return;

                        if (resp.success && resp.data.cleared) {
                            stopDeleteCheck();
                            status.text('<?php echo esc_js(__('Deleted. You can sync again now.', 'wizart-pim-sync')); ?>');
                            syncBtn.prop('disabled', false);
                            return;
                        }
                        if (elapsed >= maxWait) {
                            stopDeleteCheck();
                            status.text('<?php echo esc_js(__('Wizart is still cleaning up after 30s — you can try syncing, but it may skip rows as duplicates.', 'wizart-pim-sync')); ?>');
                            syncBtn.prop('disabled', false);
                            return;
                        }
                        status.text('<?php echo esc_js(__('Deleted. Waiting for Wizart to finish cleanup…', 'wizart-pim-sync')); ?> (' + elapsed + 's)');
                    }).fail(function () {
                        if (!deleteCheckTimer) return;
                        if (elapsed >= maxWait) {
                            stopDeleteCheck();
                            syncBtn.prop('disabled', false);
                        }
                    });
                }, interval);
            }

            function startPolling(productId) {
                stopPolling();
                pollTimer = setInterval(function () {
                    $.post(ajaxurl, {
                        action: 'wizart_check_sync_status',
                        product_id: productId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wizart_sync_nonce')); ?>'
                    }).done(function (resp) {
                        if (!resp.success) return;
                        var data = resp.data;

                        if (data.uuid) {
                            stopPolling();
                            status.text('<?php echo esc_js(__('Synced.', 'wizart-pim-sync')); ?>');
                            uuidField.val(data.uuid);
                            syncBtn.hide();
                            deleteBtn.prop('disabled', false).show();
                            return;
                        }

                        if (data.status === 'failed' || (data.status && data.status.indexOf('timeout_') === 0)) {
                            stopPolling();
                            status.text('<?php echo esc_js(__('Import failed:', 'wizart-pim-sync')); ?> ' + data.status);
                            syncBtn.prop('disabled', false);
                            return;
                        }

                        if (data.status) {
                            status.text('<?php echo esc_js(__('Status:', 'wizart-pim-sync')); ?> ' + data.status + '…');
                        }
                    });
                }, 5000);
            }

            syncBtn.on('click', function () {
                var btn = $(this);
                if (btn.prop('disabled')) return;

                stopDeleteCheck();

                var productId = btn.data('product-id');

                btn.prop('disabled', true);
                status.text('<?php echo esc_js(__('Syncing…', 'wizart-pim-sync')); ?>');

                $.post(ajaxurl, {
                    action: 'wizart_sync_product',
                    product_id: productId,
                    nonce: '<?php echo esc_js(wp_create_nonce('wizart_sync_nonce')); ?>'
                }).done(function (resp) {
                    if (resp.success) {
                        status.text(resp.data.message);
                        if (resp.data.uuid) {
                            uuidField.val(resp.data.uuid);
                            syncBtn.hide();
                            deleteBtn.show();
                        } else {
                            // importul a pornit în fundal (WP-Cron) — verificăm periodic, fără refresh
                            startPolling(productId);
                        }
                    } else {
                        status.text('<?php echo esc_js(__('Error:', 'wizart-pim-sync')); ?> ' + resp.data.message);
                        btn.prop('disabled', false);
                    }
                }).fail(function () {
                    status.text('<?php echo esc_js(__('Request failed', 'wizart-pim-sync')); ?>');
                    btn.prop('disabled', false);
                });
            });

            deleteBtn.on('click', function () {
                var btn = $(this);
                if (btn.prop('disabled')) return;

                if (!window.confirm('<?php echo esc_js(__('Delete this from Wizart PIM? This cannot be undone.', 'wizart-pim-sync')); ?>')) {
                    return;
                }

                stopPolling();

                var productId = btn.data('product-id');

                btn.prop('disabled', true);
                status.text('<?php echo esc_js(__('Deleting…', 'wizart-pim-sync')); ?>');

                $.post(ajaxurl, {
                    action: 'wizart_delete_product',
                    product_id: productId,
                    nonce: '<?php echo esc_js(wp_create_nonce('wizart_sync_nonce')); ?>'
                }).done(function (resp) {
                    btn.prop('disabled', false);
                    if (resp.success) {
                        uuidField.val('');
                        deleteBtn.hide();
                        syncBtn.show();
                        startDeleteCooldown(productId);
                    } else {
                        status.text('<?php echo esc_js(__('Error:', 'wizart-pim-sync')); ?> ' + resp.data.message);
                    }
                }).fail(function () {
                    btn.prop('disabled', false);
                    status.text('<?php echo esc_js(__('Request failed', 'wizart-pim-sync')); ?>');
                });
            });

            resetBtn.on('click', function () {
                var btn = $(this);
                if (btn.prop('disabled')) return;

                var productId = btn.data('product-id');

                stopPolling();
                stopDeleteCheck();
                btn.prop('disabled', true);
                status.text('<?php echo esc_js(__('Resetting…', 'wizart-pim-sync')); ?>');

                $.post(ajaxurl, {
                    action: 'wizart_reset_sync_status',
                    product_id: productId,
                    nonce: '<?php echo esc_js(wp_create_nonce('wizart_sync_nonce')); ?>'
                }).done(function (resp) {
                    btn.prop('disabled', false);
                    if (resp.success) {
                        status.text(resp.data.message);
                        resetWrap.hide();
                        syncBtn.prop('disabled', false).show();
                        deleteBtn.hide();
                    } else {
                        status.text('<?php echo esc_js(__('Error:', 'wizart-pim-sync')); ?> ' + resp.data.message);
                    }
                }).fail(function () {
                    btn.prop('disabled', false);
                    status.text('<?php echo esc_js(__('Request failed', 'wizart-pim-sync')); ?>');
                });
            });

            <?php
            // dacă la încărcarea paginii importul era încă în desfășurare
            // (ex. utilizatorul a dat refresh manual), reluăm polling-ul automat
            $in_progress = !$uuid && $status && $status !== 'failed' && strpos($status, 'timeout_') !== 0;
            if ($in_progress):
            ?>
            startPolling(<?php echo (int) $post->ID; ?>);
            syncBtn.prop('disabled', true);
            <?php endif; ?>
        });
        </script>
        <?php
    }

    /**
     * Salvează valorile câmpurilor extra (ex. repeat_width) ca post meta,
     * la fiecare Update/Publish al produsului.
     */
    public static function save_extra_fields($post_id) {
        if (!isset($_POST['wizart_extra_fields_nonce_field']) ||
            !wp_verify_nonce($_POST['wizart_extra_fields_nonce_field'], 'wizart_extra_fields_nonce')) {
            return;
        }
        if (!current_user_can('edit_product', $post_id)) return;

        $values = isset($_POST['wizart_extra_fields']) && is_array($_POST['wizart_extra_fields'])
            ? $_POST['wizart_extra_fields']
            : [];

        // salvăm orice a fost trimis (validarea de "required" se face abia la Sync,
        // ca să nu blocăm salvarea normală a produsului dacă lipsește un câmp)
        foreach ($values as $column => $value) {
            $column = sanitize_key($column);
            if (is_array($value)) {
                // multiselect (ex. interior_type) -> listă unită prin virgulă
                $value = implode(',', array_map('sanitize_text_field', $value));
            } else {
                $value = sanitize_text_field($value);
            }
            update_post_meta($post_id, '_wizart_field_' . $column, $value);
        }

        // "Wizart Image" pe produsul simplu (câmp simplu, nu array ca la variații)
        if (isset($_POST['_wizart_image_id']) && !is_array($_POST['_wizart_image_id'])) {
            $image_id = absint($_POST['_wizart_image_id']);
            if ($image_id) {
                update_post_meta($post_id, '_wizart_image_id', $image_id);
            } else {
                delete_post_meta($post_id, '_wizart_image_id');
            }
        }

        // "Wizart Images (Gallery)" pe produsul simplu (tile) — listă de ID-uri
        // separate prin virgulă, trimisă ca string simplu (nu array)
        if (isset($_POST['_wizart_gallery_ids']) && !is_array($_POST['_wizart_gallery_ids'])) {
            $ids = array_filter(array_map('absint', explode(',', $_POST['_wizart_gallery_ids'])));
            if (!empty($ids)) {
                update_post_meta($post_id, '_wizart_gallery_ids', implode(',', $ids));
            } else {
                delete_post_meta($post_id, '_wizart_gallery_ids');
            }
        }
    }

    /**
     * Randează, în panoul fiecărei variații (tab Variations), câmpul de
     * imagine dedicată ("Wizart Image") + cele 5 câmpuri numerice definite
     * pentru tipul de produs curent.
     */
    public static function render_variation_fields($loop, $variation_data, $variation) {
        $parent_id = $variation->post_parent;
        $parent    = wc_get_product($parent_id);
        if (!$parent) return;

        $product_type_code = self::get_product_type_code($parent);
        $extra_fields       = Wizart_PIM_Field_Maps::get_extra_fields($product_type_code);
        ?>
        <div class="wizart-variation-fields" style="clear:both; padding-top:10px; border-top:1px dashed #ddd; margin-top:10px;">
            <p class="form-row form-row-full">
                <strong><?php esc_html_e('Wizart PIM fields', 'wizart-pim-sync'); ?></strong>
            </p>

            <?php if ($product_type_code === 'tile'):
                $gallery_csv = get_post_meta($variation->ID, '_wizart_gallery_ids', true);
                ?>
                <p class="form-row form-row-full">
                    <label><?php esc_html_e('Wizart Images (Gallery)', 'wizart-pim-sync'); ?></label>
                    <?php self::render_gallery_field('_wizart_gallery_ids[' . $loop . ']', $gallery_csv); ?>
                </p>
            <?php else:
                $image_id  = get_post_meta($variation->ID, '_wizart_image_id', true);
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
                ?>
                <p class="form-row form-row-full">
                    <label><?php esc_html_e('Wizart Image', 'wizart-pim-sync'); ?></label>
                    <span class="wizart-variation-image-wrap" style="display:flex; align-items:center; gap:10px;">
                        <img
                            class="wizart-variation-image-preview"
                            src="<?php echo esc_url($image_url ?: wc_placeholder_img_src()); ?>"
                            style="width:64px; height:64px; object-fit:cover; border:1px solid #ddd;"
                        />
                        <input
                            type="hidden"
                            class="wizart-variation-image-id"
                            name="_wizart_image_id[<?php echo esc_attr($loop); ?>]"
                            value="<?php echo esc_attr($image_id); ?>"
                        />
                        <button type="button" class="button wizart-upload-image-btn" data-loop="<?php echo esc_attr($loop); ?>">
                            <?php esc_html_e('Upload/Change', 'wizart-pim-sync'); ?>
                        </button>
                        <button type="button" class="button wizart-remove-image-btn" data-loop="<?php echo esc_attr($loop); ?>">
                            <?php esc_html_e('Remove', 'wizart-pim-sync'); ?>
                        </button>
                    </span>
                </p>
            <?php endif; ?>

            <?php foreach ($extra_fields as $column => $def):
                $value = get_post_meta($variation->ID, '_wizart_field_' . $column, true);
                $has_size_fallback = in_array($column, ['product_width', 'product_length'], true);
                ?>
                <p class="form-row form-row-half">
                    <label>
                        <?php echo esc_html($def['label']); ?>
                        <span style="font-weight:normal; color:#888; font-size:11px;">
                            <?php if ($has_size_fallback): ?>
                                (<?php esc_html_e('inherits from pa_size or parent if empty', 'wizart-pim-sync'); ?>)
                            <?php else: ?>
                                (<?php esc_html_e('inherits from parent if empty', 'wizart-pim-sync'); ?>)
                            <?php endif; ?>
                        </span>
                    </label>
                    <input
                        type="<?php echo esc_attr($def['type'] ?? 'text'); ?>"
                        name="_wizart_field_<?php echo esc_attr($column); ?>[<?php echo esc_attr($loop); ?>]"
                        value="<?php echo esc_attr($value); ?>"
                        placeholder="<?php echo esc_attr($def['placeholder'] ?? ''); ?>"
                        <?php if (isset($def['step'])): ?>step="<?php echo esc_attr($def['step']); ?>"<?php endif; ?>
                        style="width:100%;"
                    />
                </p>
            <?php endforeach; ?>

            <?php foreach (Wizart_PIM_Field_Maps::get_fixed_select_fields($product_type_code) as $fs_key => $fs_def):
                $fs_value    = get_post_meta($variation->ID, '_wizart_field_' . $fs_key, true);
                $fs_multiple = !empty($fs_def['multiple']);
                $fs_selected = $fs_multiple
                    ? array_filter(array_map('trim', explode(',', $fs_value)))
                    : [$fs_value];
                ?>
                <p class="form-row form-row-half">
                    <label>
                        <?php echo esc_html($fs_def['label']); ?>
                        <span style="font-weight:normal; color:#888; font-size:11px;">
                            (<?php esc_html_e('inherits from parent if empty', 'wizart-pim-sync'); ?>)
                        </span>
                    </label>
                    <select
                        name="_wizart_field_<?php echo esc_attr($fs_key); ?>[<?php echo esc_attr($loop); ?>]<?php echo $fs_multiple ? '[]' : ''; ?>"
                        style="width:100%;"
                        <?php if ($fs_multiple): ?>multiple="multiple" size="5"<?php endif; ?>
                    >
                        <?php if (!$fs_multiple): ?>
                            <option value="">— <?php esc_html_e('none', 'wizart-pim-sync'); ?> —</option>
                        <?php endif; ?>
                        <?php foreach ($fs_def['options'] as $fs_option): ?>
                            <option value="<?php echo esc_attr($fs_option); ?>" <?php selected(in_array($fs_option, $fs_selected, true)); ?>>
                                <?php echo esc_html($fs_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Salvează câmpurile de variație (imagine + numerice), la fiecare
     * Save changes din tab-ul Variations.
     */
    public static function save_variation_fields($variation_id, $i) {
        // $i primit de la WooCommerce se poate desincroniza de poziția reală
        // (paginare în tab-ul Variations, variații încărcate ulterior via
        // AJAX, etc). Recalculăm indexul corect căutând variation_id direct
        // în array-ul trimis de WooCommerce, ca să nu depindem de $i "orb".
        if (isset($_POST['variable_post_id']) && is_array($_POST['variable_post_id'])) {
            $found = array_search($variation_id, $_POST['variable_post_id']);
            if ($found !== false) {
                $i = $found;
            }
        }

        if (isset($_POST['_wizart_image_id'][$i])) {
            $image_id = absint($_POST['_wizart_image_id'][$i]);
            if ($image_id) {
                update_post_meta($variation_id, '_wizart_image_id', $image_id);
            } else {
                delete_post_meta($variation_id, '_wizart_image_id');
            }
        }

        if (isset($_POST['_wizart_gallery_ids'][$i])) {
            $ids = array_filter(array_map('absint', explode(',', $_POST['_wizart_gallery_ids'][$i])));
            if (!empty($ids)) {
                update_post_meta($variation_id, '_wizart_gallery_ids', implode(',', $ids));
            } else {
                delete_post_meta($variation_id, '_wizart_gallery_ids');
            }
        }

        $parent_id = wp_get_post_parent_id($variation_id);
        $parent    = $parent_id ? wc_get_product($parent_id) : null;
        if (!$parent) return;

        $product_type_code = self::get_product_type_code($parent);
        $extra_fields       = Wizart_PIM_Field_Maps::get_extra_fields($product_type_code);

        foreach ($extra_fields as $column => $def) {
            $field_name = '_wizart_field_' . $column;
            if (isset($_POST[$field_name][$i])) {
                update_post_meta($variation_id, $field_name, sanitize_text_field($_POST[$field_name][$i]));
            }
        }

        foreach (Wizart_PIM_Field_Maps::get_fixed_select_fields($product_type_code) as $fs_key => $fs_def) {
            $field_name = '_wizart_field_' . $fs_key;
            if (isset($_POST[$field_name][$i])) {
                $value = $_POST[$field_name][$i];
                if (is_array($value)) {
                    $value = implode(',', array_map('sanitize_text_field', $value));
                } else {
                    $value = sanitize_text_field($value);
                }
                update_post_meta($variation_id, $field_name, $value);
            }
        }
    }

    /**
     * JS pentru butoanele de imagine din panourile de variații — folosește
     * event delegation (document.on) ca să funcționeze și pe variațiile
     * încărcate ulterior via AJAX de WooCommerce (tab-ul Variations).
     */
    public static function print_variation_media_js() {
        global $post;
        if (!$post || $post->post_type !== 'product') return;
        ?>
        <script>
        jQuery(function ($) {
            var frame;

            $(document).on('click', '.wizart-upload-image-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var wrap = btn.closest('.wizart-variation-image-wrap');

                frame = wp.media({
                    title: '<?php echo esc_js(__('Select Wizart Image', 'wizart-pim-sync')); ?>',
                    multiple: false
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var url = (attachment.sizes && attachment.sizes.thumbnail)
                        ? attachment.sizes.thumbnail.url
                        : attachment.url;
                    wrap.find('.wizart-variation-image-preview').attr('src', url);
                    wrap.find('.wizart-variation-image-id').val(attachment.id).trigger('change');
                });

                frame.open();
            });

            $(document).on('click', '.wizart-remove-image-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var wrap = btn.closest('.wizart-variation-image-wrap');
                wrap.find('.wizart-variation-image-preview').attr('src', '<?php echo esc_js(wc_placeholder_img_src()); ?>');
                wrap.find('.wizart-variation-image-id').val('').trigger('change');
            });

            var galleryFrame;

            $(document).on('click', '.wizart-gallery-add-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var wrap = btn.closest('.wizart-gallery-wrap');
                var hidden = wrap.find('.wizart-gallery-ids');
                var thumbs = wrap.find('.wizart-gallery-thumbs');

                galleryFrame = wp.media({
                    title: '<?php echo esc_js(__('Select Tile Images', 'wizart-pim-sync')); ?>',
                    multiple: true
                });

                galleryFrame.on('select', function () {
                    var selection = galleryFrame.state().get('selection');
                    var existingIds = hidden.val() ? hidden.val().split(',').filter(Boolean) : [];

                    selection.each(function (attachment) {
                        var json = attachment.toJSON();
                        var idStr = String(json.id);
                        if (existingIds.indexOf(idStr) === -1) {
                            existingIds.push(idStr);
                            var thumbUrl = (json.sizes && json.sizes.thumbnail) ? json.sizes.thumbnail.url : json.url;
                            var thumb = $(
                                '<span class="wizart-gallery-thumb" data-id="' + idStr + '" style="position:relative; display:inline-block;">' +
                                '<img src="' + thumbUrl + '" style="width:60px; height:60px; object-fit:cover; border:1px solid #ddd;" />' +
                                '<button type="button" class="wizart-gallery-remove-thumb" style="position:absolute; top:-6px; right:-6px; background:#a00; color:#fff; border:none; border-radius:50%; width:18px; height:18px; line-height:16px; cursor:pointer; padding:0; font-size:12px;">×</button>' +
                                '</span>'
                            );
                            thumbs.append(thumb);
                        }
                    });

                    hidden.val(existingIds.join(',')).trigger('change');
                });

                galleryFrame.open();
            });

            $(document).on('click', '.wizart-gallery-remove-thumb', function (e) {
                e.preventDefault();
                var thumb = $(this).closest('.wizart-gallery-thumb');
                var wrap = thumb.closest('.wizart-gallery-wrap');
                var hidden = wrap.find('.wizart-gallery-ids');
                var id = String(thumb.data('id'));

                var ids = hidden.val() ? hidden.val().split(',').filter(Boolean) : [];
                ids = ids.filter(function (x) { return x !== id; });
                hidden.val(ids.join(',')).trigger('change');

                thumb.remove();
            });
        });
        </script>
        <?php
    }

    /**
     * Citește un câmp Wizart de pe un item (variație SAU produs simplu):
     * întâi de pe item-ul însuși, iar dacă lipsește, cade pe produsul
     * părinte (fallback). Pentru produse simple, $item_id === $parent_id,
     * deci practic citește o singură dată, direct.
     */
    private static function get_spec_value($item_id, $parent_id, $column) {
        $value = get_post_meta($item_id, '_wizart_field_' . $column, true);
        if ($value === '' && (int) $item_id !== (int) $parent_id) {
            $value = get_post_meta($parent_id, '_wizart_field_' . $column, true);
        }
        return $value;
    }

    /**
     * Interfața (meta box + panoul de variații) lucrează în inch — clientul
     * introduce/vede valorile în inch. Wizart cere metri în CSV, deci
     * convertim aici, o singură dată, exact înainte de scriere.
     * 1 in = 0.0254 m.
     */
    private static function convert_extra_value($value, $def) {
        if ($value === '' || $value === null) return $value;
        if (($def['unit'] ?? '') !== 'in') return $value;

        return round(((float) $value) * 0.0254, 4);
    }

    /**
     * Construiește intrările ZIP pentru o galerie de imagini (tile) și
     * întoarce lista de referințe (FĂRĂ extensie, separate prin virgulă)
     * pentru coloana product_image. Adaugă intrările direct în $images
     * (referință), câte una per imagine din galerie.
     */
    private static function build_gallery_zip_entries($gallery_ids, $vendor_code, &$images) {
        $refs  = [];
        $index = 0;

        foreach ($gallery_ids as $attachment_id) {
            $path = get_attached_file($attachment_id);
            if (!$path || !file_exists($path)) continue;

            $index++;
            $zip_name = $vendor_code . '-' . $index . '-' . basename($path);
            $images[] = ['path' => $path, 'zip_name' => $zip_name];
            $refs[]   = pathinfo($zip_name, PATHINFO_FILENAME);
        }

        return implode(',', $refs);
    }

    private static function get_gallery_ids($post_id) {
        $csv = get_post_meta($post_id, '_wizart_gallery_ids', true);
        if (!$csv) return [];
        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }

    /**
     * Randează UI-ul de galerie (multi-imagine) — folosit doar la "tile",
     * atât pe produsul părinte (simplu), cât și pe fiecare variație.
     * $field_name = "_wizart_gallery_ids" (produs simplu) sau
     * "_wizart_gallery_ids[{$loop}]" (variație).
     */
    private static function render_gallery_field($field_name, $current_csv) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $current_csv))));
        ?>
        <div class="wizart-gallery-wrap">
            <input
                type="hidden"
                class="wizart-gallery-ids"
                name="<?php echo esc_attr($field_name); ?>"
                value="<?php echo esc_attr(implode(',', $ids)); ?>"
            />
            <div class="wizart-gallery-thumbs" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;">
                <?php foreach ($ids as $id):
                    $url = wp_get_attachment_image_url($id, 'thumbnail');
                    if (!$url) continue;
                    ?>
                    <span class="wizart-gallery-thumb" data-id="<?php echo esc_attr($id); ?>" style="position:relative; display:inline-block;">
                        <img src="<?php echo esc_url($url); ?>" style="width:60px; height:60px; object-fit:cover; border:1px solid #ddd;" />
                        <button
                            type="button"
                            class="wizart-gallery-remove-thumb"
                            style="position:absolute; top:-6px; right:-6px; background:#a00; color:#fff; border:none; border-radius:50%; width:18px; height:18px; line-height:16px; cursor:pointer; padding:0; font-size:12px;"
                        >×</button>
                    </span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button wizart-gallery-add-btn">
                <?php esc_html_e('Add Images', 'wizart-pim-sync'); ?>
            </button>
        </div>
        <?php
    }

    private static function get_size_attribute_value($item) {
        return $item->get_attribute('pa_size');
    }

    /**
     * Parsează "6x6", "3x6", "12x24" (prima valoare = width, a doua = length).
     * pa_size e mereu o singură valoare (nu listă) — atât pe variație, cât
     * și pe produsul părinte. Întoarce '' dacă nu poate fi parsat.
     */
    private static function parse_size_dimension($size_string, $index) {
        if (!$size_string) return '';

        $parts = preg_split('/\s*x\s*/i', trim($size_string));

        if (count($parts) < 2 || !isset($parts[$index])) return '';

        $value = trim($parts[$index]);
        return is_numeric($value) ? $value : '';
    }

    /**
     * Rezolvă product_width/product_length cu ordinea corectă de prioritate:
     *   1. meta field-ul dedicat de pe item (variație sau produs simplu)
     *   2. pa_size de pe item (variație/produs)
     *   3. meta field-ul dedicat de pe produsul părinte
     *   4. pa_size de pe produsul părinte
     * Pentru produs simplu, item === parent, deci pașii 1+3 și 2+4 coincid.
     */
    private static function resolve_width_or_length($item_id, $parent_id, $item, $parent, $column, $def) {
        $index = ($column === 'product_width') ? 0 : 1;

        // 1. meta pe item
        $value = get_post_meta($item_id, '_wizart_field_' . $column, true);
        if ($value !== '') return self::convert_extra_value($value, $def);

        // 2. pa_size pe item
        $size = self::get_size_attribute_value($item);
        if ($size !== '') {
            $parsed = self::parse_size_dimension($size, $index);
            if ($parsed !== '') return self::convert_extra_value($parsed, $def);
        }

        if ((int) $item_id !== (int) $parent_id) {
            // 3. meta pe părinte
            $value = get_post_meta($parent_id, '_wizart_field_' . $column, true);
            if ($value !== '') return self::convert_extra_value($value, $def);

            // 4. pa_size pe părinte
            $size = self::get_size_attribute_value($parent);
            if ($size !== '') {
                $parsed = self::parse_size_dimension($size, $index);
                if ($parsed !== '') return self::convert_extra_value($parsed, $def);
            }
        }

        return '';
    }

    /* ==================================================================
     * AJAX entry point
     * ================================================================== */

    public static function ajax_sync_product() {
        check_ajax_referer('wizart_sync_nonce', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied', 'wizart-pim-sync')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $product    = wc_get_product($product_id);

        if (!$product) {
            wp_send_json_error(['message' => __('Product not found', 'wizart-pim-sync')]);
        }

        if ($product->is_type('variation')) {
            wp_send_json_error(['message' => __('Please sync from the parent (variable) product screen', 'wizart-pim-sync')]);
        }

        // deja sincronizat -> nu re-creăm (fluxul curent ține doar de INSERT)
        $existing_uuid = get_post_meta($product_id, 'wizart_uuid', true);
        if ($existing_uuid) {
            wp_send_json_success([
                'message' => __('Already synced', 'wizart-pim-sync'),
                'uuid'    => $existing_uuid,
            ]);
        }

        // 1) Autentificare
        $token = Wizart_PIM_Auth::get_access_token();
        if (is_wp_error($token)) {
            wp_send_json_error(['message' => 'Auth failed: ' . $token->get_error_message()]);
        }

        $product_type_code = self::get_product_type_code($product);

        // 2) Rezolvăm schema de coloane CSV pentru acest productTypeCode
        $schema = Wizart_PIM_Field_Maps::resolve($product_type_code, $token);
        if (is_wp_error($schema)) {
            wp_send_json_error(['message' => $schema->get_error_message()]);
        }

        // 1b) Validare minimă a datelor produsului, ÎNAINTE de a trimite orice
        // la Wizart — un brand/SKU/câmp obligatoriu lipsă e o cauză probabilă
        // fie de 500 opac, fie de rând respins la import.
        $validation = self::validate_product($product, $product_type_code, $schema);
        if (is_wp_error($validation)) {
            wp_send_json_error(['message' => $validation->get_error_message()]);
        }

        // 2) Construim CSV (1 rând pt. simplu, N rânduri pt. variabil) + lista de imagini + info rezoluție
        $build = self::build_csv($product, $schema, $product_type_code);
        if (is_wp_error($build)) {
            wp_send_json_error(['message' => $build->get_error_message()]);
        }
        [$csv_path, $images, $resolution] = $build;

        // 4) ZIP cu toate imaginile referite în CSV
        $zip_path = self::build_zip($product, $images);
        if (is_wp_error($zip_path)) {
            wp_send_json_error(['message' => $zip_path->get_error_message()]);
        }

        // 5) Upload import
        $import = self::upload_import($token, $product_type_code, $csv_path, $zip_path);
        if (is_wp_error($import)) {
            wp_send_json_error(['message' => $import->get_error_message()]);
        }

        update_post_meta($product_id, '_wizart_import_id', $import['import_id']);
        update_post_meta($product_id, '_wizart_sync_status', 'importing');
        update_post_meta($product_id, self::META_PENDING, wp_json_encode($resolution));

        // 5) programăm verificarea asincronă a statusului
        wp_schedule_single_event(
            time() + self::POLL_INTERVAL,
            'wizart_check_import_status_event',
            [$product_id, $import['import_id'], 1]
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Import started (ID: %s). UUID will be saved automatically once processing finishes.', 'wizart-pim-sync'),
                $import['import_id']
            ),
        ]);
    }

    /**
     * Folosit de JS pentru polling — verifică statusul curent fără refresh
     * de pagină, cât timp importul rulează în fundal (WP-Cron).
     */
    public static function ajax_check_sync_status() {
        check_ajax_referer('wizart_sync_nonce', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied', 'wizart-pim-sync')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product', 'wizart-pim-sync')]);
        }

        wp_send_json_success([
            'uuid'   => get_post_meta($product_id, 'wizart_uuid', true),
            'status' => get_post_meta($product_id, '_wizart_sync_status', true),
        ]);
    }

    /**
     * Supapă de siguranță: dacă WP-Cron nu mai avansează (site cu trafic
     * redus, retry-urile de rezoluție UUID nu se mai declanșează), produsul
     * poate rămâne blocat cu status setat dar fără wizart_uuid — Sync
     * dezactivat, fără Delete disponibil (nu există UUID de șters). Acest
     * reset curăță local starea, ca utilizatorul să poată încerca din nou.
     * NU atinge wizart_uuid — dacă există deja, nu-l ștergem aici.
     */
    public static function ajax_reset_sync_status() {
        check_ajax_referer('wizart_sync_nonce', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied', 'wizart-pim-sync')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product', 'wizart-pim-sync')]);
        }

        delete_post_meta($product_id, '_wizart_sync_status');
        delete_post_meta($product_id, '_wizart_import_id');
        delete_post_meta($product_id, self::META_PENDING);

        wp_send_json_success(['message' => __('Reset. You can sync again.', 'wizart-pim-sync')]);
    }

    /**
     * Verificare activă (nu ghicit): caută pe Wizart, după vendor_code,
     * dacă articolele acestui produs mai există încă — folosit ca să știm
     * cu certitudine când a terminat Wizart cleanup-ul după un Delete,
     * în loc de un timer fix.
     */
    public static function ajax_check_deletion_cleared() {
        check_ajax_referer('wizart_sync_nonce', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied', 'wizart-pim-sync')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $product    = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found', 'wizart-pim-sync')]);
        }

        $token = Wizart_PIM_Auth::get_access_token();
        if (is_wp_error($token)) {
            wp_send_json_error(['message' => $token->get_error_message()]);
        }

        $skus = [];
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $vid) {
                $v = wc_get_product($vid);
                if ($v && $v->get_sku()) $skus[] = $v->get_sku();
            }
        } elseif ($product->get_sku()) {
            $skus[] = $product->get_sku();
        }

        foreach ($skus as $sku) {
            if (self::find_article_uuid_by_vendor_code($token, $sku)) {
                wp_send_json_success(['cleared' => false]);
            }
        }

        wp_send_json_success(['cleared' => true]);
    }

    /* ==================================================================
     * Delete (Collection sau Article) + reset local, ca să poată fi
     * re-sincronizat de la zero
     * ================================================================== */

    public static function ajax_delete_product() {
        check_ajax_referer('wizart_sync_nonce', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied', 'wizart-pim-sync')]);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $product    = wc_get_product($product_id);

        if (!$product) {
            wp_send_json_error(['message' => __('Product not found', 'wizart-pim-sync')]);
        }

        $uuid = get_post_meta($product_id, 'wizart_uuid', true);
        if (!$uuid) {
            wp_send_json_error(['message' => __('No Wizart UUID stored for this product', 'wizart-pim-sync')]);
        }

        $token = Wizart_PIM_Auth::get_access_token();
        if (is_wp_error($token)) {
            wp_send_json_error(['message' => $token->get_error_message()]);
        }

        // wizart_uuid e mereu UUID-ul Collection-ului (și la produs simplu,
        // și la variabil). DELETE pe collection nu face cascade pe articole —
        // le ștergem explicit întâi (mass removal), altfel rămân "vii" și un
        // import ulterior le sare ca "already exist".
        $categories = wc_get_product_terms($product_id, 'product_cat', ['fields' => 'names']);
        $brand_name = !empty($categories) ? $categories[0] : '';
        $brand_uuid = self::find_brand_uuid_by_name($token, $brand_name);

        if ($brand_uuid) {
            $article_uuids = self::get_collection_articles($token, $brand_uuid, $uuid);
            if (!empty($article_uuids)) {
                $mass_result = self::mass_delete_articles($token, $uuid, $article_uuids);
                if (is_wp_error($mass_result)) {
                    wp_send_json_error(['message' => $mass_result->get_error_message()]);
                }
            }
        }

        $result = self::delete_collection($token, $uuid);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // reset local complet, ca produsul să poată fi re-sincronizat de la zero
        delete_post_meta($product_id, 'wizart_uuid');
        delete_post_meta($product_id, '_wizart_sync_status');
        delete_post_meta($product_id, '_wizart_import_id');
        delete_post_meta($product_id, self::META_PENDING);

        wp_send_json_success([
            'message' => __('Deleted from Wizart. You can sync it again.', 'wizart-pim-sync'),
        ]);
    }

    /**
     * Aduce toate articolele (products) dintr-o Collection — paginat —
     * folosit înainte de a șterge Collection-ul, ca să ștergem explicit
     * articolele mai întâi (DELETE pe collection nu pare să facă cascade).
     */
    private static function get_collection_articles($token, $brand_uuid, $collection_uuid) {
        $uuids = [];
        $page  = 1;

        do {
            $url = Wizart_PIM_Auth::API_BASE
                . '/api/customer/brands/' . rawurlencode($brand_uuid)
                . '/collections/' . rawurlencode($collection_uuid)
                . '/articles?page=' . $page;

            $body = self::api_get($token, $url);
            if (is_wp_error($body) || empty($body['data'])) break;

            foreach ($body['data'] as $article) {
                if (!empty($article['uuid'])) {
                    $uuids[] = $article['uuid'];
                }
            }

            $has_next = !empty($body['links']['next']);
            $page++;
        } while ($has_next && $page <= 20);

        return $uuids;
    }

    /**
     * Mass removal de articole dintr-o Collection (toate trebuie să fie
     * din aceeași Collection, conform documentației Wizart).
     */
    private static function mass_delete_articles($token, $collection_uuid, $article_uuids) {
        if (empty($article_uuids)) return true;

        $url = Wizart_PIM_Auth::API_BASE . '/api/v2/collection/' . rawurlencode($collection_uuid) . '/articles';

        $data = array_map(function ($uuid) {
            return ['type' => 'products', 'id' => $uuid];
        }, $article_uuids);

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode(['data' => $data]),
            'timeout' => 30,
        ]);

        return self::handle_delete_response($response);
    }

    private static function delete_collection($token, $collection_uuid) {
        $url = Wizart_PIM_Auth::API_BASE . '/api/v2.1/collections/' . rawurlencode($collection_uuid) . '/';

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'data' => ['type' => 'collections', 'id' => $collection_uuid],
            ]),
            'timeout' => 30,
        ]);

        return self::handle_delete_response($response);
    }

    private static function delete_article($token, $collection_uuid, $article_uuid) {
        $url = Wizart_PIM_Auth::API_BASE . '/api/v2/collection/' . rawurlencode($collection_uuid) . '/articles';

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'data' => ['type' => 'products', 'id' => $article_uuid],
            ]),
            'timeout' => 30,
        ]);

        return self::handle_delete_response($response);
    }

    private static function handle_delete_response($response) {
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);

        // 404 = deja nu mai există (probabil șters prin cascade după mass
        // removal de articole) — scopul nostru era "să nu mai existe",
        // deci e deja îndeplinit, nu un eșec real.
        if ($code === 404) {
            return true;
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'wizart_delete_failed',
                'Delete failed (HTTP ' . $code . '): ' . wp_remote_retrieve_body($response)
            );
        }

        return true;
    }

    /**
     * Verificări minime înainte de a trimite orice la Wizart.
     * Un brand (categorie) sau SKU lipsă e o cauză probabilă de 500 opac
     * pe partea lor (câmp obligatoriu gol -> excepție nehandled).
     */
    private static function validate_product($product, $product_type_code, $schema) {
        $categories = wc_get_product_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        if (empty($categories)) {
            return new WP_Error(
                'wizart_missing_category',
                __('Please assign this product to a Category first — it maps to the Wizart Brand and cannot be empty.', 'wizart-pim-sync')
            );
        }

        $required_logical  = $schema['required_logical'] ?? [];
        $extra_field_defs  = Wizart_PIM_Field_Maps::get_extra_fields($product_type_code);

        if ($product->is_type('variable')) {
            $variation_ids = $product->get_children();
            if (empty($variation_ids)) {
                return new WP_Error('wizart_no_variations', __('This variable product has no variations to sync.', 'wizart-pim-sync'));
            }

            $missing_sku    = [];
            $missing_extra  = []; // label => [nume variații]
            $synced_count   = 0;

            foreach ($variation_ids as $vid) {
                $variation = wc_get_product($vid);
                if (!$variation || !$variation->exists()) continue;

                // fără imagine -> variația e omisă din CSV, deci nu are
                // sens s-o mai validăm pe celelalte câmpuri. La "tile"
                // verificăm galeria; la restul, "Wizart Image" unică.
                $has_image = ($product_type_code === 'tile')
                    ? !empty(self::get_gallery_ids($vid))
                    : (bool) get_post_meta($vid, '_wizart_image_id', true);

                if (in_array('image', $required_logical, true) && !$has_image) {
                    continue;
                }

                $synced_count++;

                if (!$variation->get_sku()) {
                    $missing_sku[] = $variation->get_name();
                }

                foreach ($extra_field_defs as $column => $def) {
                    if (empty($def['required'])) continue;
                    $value = self::get_spec_value($vid, $product->get_id(), $column);
                    if (($value === '' || $value === null) && in_array($column, ['product_width', 'product_length'], true)) {
                        // fallback pa_size (tile) — variație, apoi părinte
                        $size = self::get_size_attribute_value($variation);
                        if ($size === '') $size = self::get_size_attribute_value($product);
                        $index = ($column === 'product_width') ? 0 : 1;
                        if ($size !== '' && self::parse_size_dimension($size, $index) !== '') {
                            continue; // se completează la generarea CSV, nu e lipsă reală
                        }
                    }
                    if ($value === '' || $value === null) {
                        $missing_extra[$def['label']][] = $variation->get_name();
                    }
                }
            }

            if ($synced_count === 0) {
                return new WP_Error(
                    'wizart_no_variation_images',
                    __('No variation has an image set — nothing to sync. Add at least one image in the Variations tab.', 'wizart-pim-sync')
                );
            }
            if (!empty($missing_sku)) {
                return new WP_Error(
                    'wizart_missing_variation_sku',
                    sprintf(
                        __('These variations have no SKU set (required as unique_sku_id): %s', 'wizart-pim-sync'),
                        implode(', ', $missing_sku)
                    )
                );
            }
            if (!empty($missing_extra)) {
                $lines = [];
                foreach ($missing_extra as $label => $names) {
                    $lines[] = $label . ' (' . implode(', ', $names) . ')';
                }
                return new WP_Error(
                    'wizart_missing_extra_fields',
                    sprintf(
                        __('Please fill in the following required field(s), either on the variation or on the parent product: %s', 'wizart-pim-sync'),
                        implode('; ', $lines)
                    )
                );
            }
        } else {
            if (!$product->get_sku()) {
                return new WP_Error(
                    'wizart_missing_sku',
                    __('Please set a SKU for this product first — it maps to Wizart\'s unique_sku_id and cannot be empty.', 'wizart-pim-sync')
                );
            }
            $has_image = ($product_type_code === 'tile')
                ? !empty(self::get_gallery_ids($product->get_id()))
                : (get_post_meta($product->get_id(), '_wizart_image_id', true) || $product->get_image_id());
            if (in_array('image', $required_logical, true) && !$has_image) {
                return new WP_Error(
                    'wizart_missing_image',
                    ($product_type_code === 'tile')
                        ? __('Please add at least one image to the Wizart Images gallery for this product.', 'wizart-pim-sync')
                        : __('Please set a Wizart Image (or a featured image) for this product first — it maps to Wizart\'s product_image and cannot be empty.', 'wizart-pim-sync')
                );
            }

            $missing_extra = [];
            foreach ($extra_field_defs as $column => $def) {
                if (empty($def['required'])) continue;
                $value = self::get_spec_value($product->get_id(), $product->get_id(), $column);
                if (($value === '' || $value === null) && in_array($column, ['product_width', 'product_length'], true)) {
                    $size = self::get_size_attribute_value($product);
                    $index = ($column === 'product_width') ? 0 : 1;
                    if ($size !== '' && self::parse_size_dimension($size, $index) !== '') {
                        continue;
                    }
                }
                if ($value === '' || $value === null) {
                    $missing_extra[] = $def['label'];
                }
            }
            if (!empty($missing_extra)) {
                return new WP_Error(
                    'wizart_missing_extra_fields',
                    sprintf(
                        __('Please fill in the following required field(s) in the Wizart PIM box before syncing: %s', 'wizart-pim-sync'),
                        implode(', ', $missing_extra)
                    )
                );
            }
        }

        return true;
    }

    /**
     * Extrage valoarea atributului "Culoare" de pe un produs/variație,
     * încercând mai multe variante uzuale de nume (global sau custom).
     * WC_Product::get_attribute() întoarce deja valorile unite prin virgulă
     * dacă produsul are mai multe culori setate.
     */
    private static function get_color_value($item) {
        return $item->get_attribute('pa_color');
    }

    private static function get_material_value($item) {
        return $item->get_attribute('pa_material');
    }

    /**
     * Toate atributele setate pe o variație (indiferent care), cu valori
     * "traduse" (pentru taxonomii globale ca pa_color, ia numele termenului,
     * nu slug-ul). Ignoră atributele lăsate pe "Any" (valoare goală).
     * Folosit doar ca fallback pentru numele variației, nu pentru coloane
     * specifice (acelea rămân pe get_color_value/get_material_value).
     */
    private static function get_all_variation_attribute_values($variation) {
        $attributes = $variation->get_attributes();
        if (empty($attributes)) return [];

        $values = [];
        foreach ($attributes as $taxonomy_or_name => $value) {
            if ($value === '') continue; // "Any ..." -> nimic ales

            if (taxonomy_exists($taxonomy_or_name)) {
                $term = get_term_by('slug', $value, $taxonomy_or_name);
                $values[] = $term ? $term->name : $value;
            } else {
                $values[] = $value;
            }
        }
        return $values;
    }

    /* ==================================================================
     * CSV / ZIP builders — mapare conform logic.md
     * ================================================================== */

    /**
     * Returns [csv_path, images[vendor_code => ['path'=>..,'zip_name'=>..]], resolution]
     * resolution = ['type'=>'collection', 'brand'=>.., 'collection'=>..]
     *            | ['type'=>'article', 'vendor_code'=>..]
     *
     * $schema = ['columns' => [...ordered CSV headers...], 'field_map' => [logical => column]]
     */
    private static function build_csv($product, $schema, $product_type_code) {
        $dir = self::get_csv_dir();
        if (is_wp_error($dir)) return $dir;

        $file_path = trailingslashit($dir) . 'product-' . $product->get_id() . '.csv';

        $categories = wc_get_product_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        $brand_name = !empty($categories) ? $categories[0] : '';

        // valori fixe (hardcodate), aplicate identic pe fiecare rând
        $defaults = Wizart_PIM_Field_Maps::get_defaults($product_type_code);
        $extra_field_defs = Wizart_PIM_Field_Maps::get_extra_fields($product_type_code);
        $fixed_select_defs = Wizart_PIM_Field_Maps::get_fixed_select_fields($product_type_code);

        // dropdown-uri (interior_type, style, pattern) — completate DOAR pe
        // produsul părinte, aceeași valoare pe fiecare rând (simplu sau variații)
        $dropdown_values = [];
        foreach (Wizart_PIM_Field_Maps::DROPDOWN_FIELDS as $dd_key) {
            $dropdown_values[$dd_key] = get_post_meta($product->get_id(), '_wizart_field_' . $dd_key, true);
        }

        $rows       = [];
        $images     = [];
        $resolution = [];

        if ($product->is_type('variable')) {
            $collection_name = $product->get_name();
            $variation_ids   = $product->get_children();

            // includem doar variațiile care au imaginea completată — cele
            // fără imagine sunt omise complet din CSV. La "tile" verificăm
            // galeria (multi-imagine); la restul, "Wizart Image" (unică).
            $included_ids = [];
            foreach ($variation_ids as $vid) {
                $v = wc_get_product($vid);
                if (!$v || !$v->exists()) continue;

                $has_image = ($product_type_code === 'tile')
                    ? !empty(self::get_gallery_ids($vid))
                    : (bool) get_post_meta($vid, '_wizart_image_id', true);

                if ($has_image) {
                    $included_ids[] = $vid;
                }
            }

            // SKU-urile variațiilor incluse, pentru calculul related_skus per rând
            $all_variation_skus = [];
            foreach ($included_ids as $vid) {
                $v = wc_get_product($vid);
                if ($v && $v->get_sku()) {
                    $all_variation_skus[] = $v->get_sku();
                }
            }

            foreach ($included_ids as $vid) {
                $variation = wc_get_product($vid);
                if (!$variation || !$variation->exists()) continue;

                $vendor_code = $variation->get_sku() ?: ('VAR-' . $vid);

                // câmpuri numerice: product_width/length au ordine specială
                // (item -> pa_size item -> părinte -> pa_size părinte);
                // restul: item -> fallback simplu pe părinte
                $row_extra_values = [];
                foreach ($extra_field_defs as $column => $def) {
                    if (in_array($column, ['product_width', 'product_length'], true)) {
                        $row_extra_values[$column] = self::resolve_width_or_length(
                            $vid, $product->get_id(), $variation, $product, $column, $def
                        );
                    } else {
                        $row_extra_values[$column] = self::convert_extra_value(
                            self::get_spec_value($vid, $product->get_id(), $column),
                            $def
                        );
                    }
                }

                // câmpuri select/multiselect cu opțiuni fixe (ex. layouts) —
                // același fallback variație -> părinte, apoi default (dacă e definit)
                foreach ($fixed_select_defs as $fs_key => $fs_def) {
                    $value = self::get_spec_value($vid, $product->get_id(), $fs_key);
                    if ($value === '' && isset($fs_def['default'])) {
                        $value = $fs_def['default'];
                    }
                    $row_extra_values[$fs_key] = $value;
                }

                // imagine(i) — la "tile" e o galerie (multi-imagine), la
                // restul rămâne "Wizart Image" unică (garantat setată, filtrat mai sus)
                if ($product_type_code === 'tile') {
                    $gallery_ids = self::get_gallery_ids($vid);
                    $image_ref   = self::build_gallery_zip_entries($gallery_ids, $vendor_code, $images);
                } else {
                    $image_id   = get_post_meta($vid, '_wizart_image_id', true);
                    $image_path = $image_id ? get_attached_file($image_id) : '';
                    $image_ref  = ''; // valoarea din coloana CSV — FĂRĂ extensie
                    if ($image_path && file_exists($image_path)) {
                        $zip_name  = $vendor_code . '-' . basename($image_path);
                        $image_ref = pathinfo($zip_name, PATHINFO_FILENAME);
                        $images[]  = ['path' => $image_path, 'zip_name' => $zip_name];
                    }
                }

                $related_skus = array_diff($all_variation_skus, [$vendor_code]);
                $related_skus = array_slice(array_values($related_skus), 0, 10); // limita din spec: max 10

                $color = self::get_color_value($variation);
                if ($color === '') $color = self::get_color_value($product);

                $material = self::get_material_value($variation);
                if ($material === '') $material = self::get_material_value($product);

                // dacă WooCommerce n-a reușit să diferențieze numele variației
                // (get_name() a ieșit identic cu numele produsului părinte —
                // de obicei semn că atributele variației lipsesc/sunt "Any"),
                // completăm manual cu TOATE atributele setate pe variație
                $variation_name = $variation->get_name();
                if (trim($variation_name) === trim($product->get_name())) {
                    $suffix = self::get_all_variation_attribute_values($variation);
                    if (!empty($suffix)) {
                        $variation_name .= ' - ' . implode(', ', $suffix);
                    }
                }

                $context = [
                    'brand'              => $brand_name,
                    'collection'         => $collection_name,
                    'name'               => $variation_name,
                    'vendor_code'        => $vendor_code,
                    'custom_sku'         => $vendor_code,
                    'image'              => $image_ref,
                    'description'        => wp_strip_all_tags($variation->get_description() ?: $product->get_description()),
                    'regular_price'      => $variation->get_regular_price() ?: $product->get_regular_price(),
                    'promotional_price'  => $variation->get_sale_price() ?: $product->get_sale_price(),
                    'availability'       => $variation->is_in_stock() ? 'in_stock' : 'out_of_stock',
                    'link'               => get_permalink($product->get_id()),
                    'is_visible'         => ($product->get_status() === 'publish') ? 1 : 0,
                    'product_type_code'  => $product_type_code,
                    'related_skus'       => implode(',', $related_skus),
                    'color'              => $color,
                    'material'           => $material,
                    'coating'            => $material,
                ];

                $rows[] = self::map_context_to_row($context + $row_extra_values + $dropdown_values + $defaults, $schema, $variation, $product);
            }

            if (empty($rows)) {
                return new WP_Error('no_variations', __('Variable product has no variations to sync', 'wizart-pim-sync'));
            }

            $resolution = [
                'type'       => 'collection',
                'brand'      => $brand_name,
                'collection' => $collection_name,
            ];
        } else {
            // simple product: Collection = Product (regula 2 din logic.md)
            $collection_name = $product->get_name();
            $vendor_code     = $product->get_sku();

            // pentru produs simplu, item = parent -> citește direct propriile valori
            $row_extra_values = [];
            foreach ($extra_field_defs as $column => $def) {
                if (in_array($column, ['product_width', 'product_length'], true)) {
                    $row_extra_values[$column] = self::resolve_width_or_length(
                        $product->get_id(), $product->get_id(), $product, $product, $column, $def
                    );
                } else {
                    $row_extra_values[$column] = self::convert_extra_value(
                        self::get_spec_value($product->get_id(), $product->get_id(), $column),
                        $def
                    );
                }
            }

            // câmpuri select/multiselect cu opțiuni fixe (ex. layouts) —
            // idem: valoare proprie, apoi default (dacă e definit)
            foreach ($fixed_select_defs as $fs_key => $fs_def) {
                $value = self::get_spec_value($product->get_id(), $product->get_id(), $fs_key);
                if ($value === '' && isset($fs_def['default'])) {
                    $value = $fs_def['default'];
                }
                $row_extra_values[$fs_key] = $value;
            }

            // imagine(i) — la "tile" e o galerie (multi-imagine), la restul
            // rămâne "Wizart Image" unică, cu fallback pe imaginea nativă WC
            if ($product_type_code === 'tile') {
                $gallery_ids = self::get_gallery_ids($product->get_id());
                $image_ref   = self::build_gallery_zip_entries($gallery_ids, $vendor_code, $images);
            } else {
                $image_id = get_post_meta($product->get_id(), '_wizart_image_id', true);
                if (!$image_id) {
                    $image_id = $product->get_image_id();
                }
                $image_path = $image_id ? get_attached_file($image_id) : '';
                $image_ref  = ''; // valoarea din coloana CSV — FĂRĂ extensie
                if ($image_path && file_exists($image_path)) {
                    $zip_name  = $vendor_code . '-' . basename($image_path);
                    $image_ref = pathinfo($zip_name, PATHINFO_FILENAME);
                    $images[]  = ['path' => $image_path, 'zip_name' => $zip_name];
                }
            }

            $cross_sell_skus = [];
            foreach ($product->get_cross_sell_ids() as $cs_id) {
                $cs = wc_get_product($cs_id);
                if ($cs && $cs->get_sku()) $cross_sell_skus[] = $cs->get_sku();
            }
            $cross_sell_skus = array_slice($cross_sell_skus, 0, 10); // limita din spec: max 10

            $context = [
                'brand'              => $brand_name,
                'collection'         => $collection_name,
                'name'               => $product->get_name(),
                'vendor_code'        => $vendor_code,
                'custom_sku'         => $vendor_code,
                'image'              => $image_ref,
                'description'        => wp_strip_all_tags($product->get_description()),
                'regular_price'      => $product->get_regular_price(),
                'promotional_price'  => $product->get_sale_price(),
                'availability'       => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
                'link'               => get_permalink($product->get_id()),
                'is_visible'         => ($product->get_status() === 'publish') ? 1 : 0,
                'product_type_code'  => $product_type_code,
                'related_skus'       => implode(',', $cross_sell_skus),
                'color'              => self::get_color_value($product),
                'material'           => self::get_material_value($product),
                'coating'            => self::get_material_value($product),
            ];

            $rows[] = self::map_context_to_row($context + $row_extra_values + $dropdown_values + $defaults, $schema, $product, $product);

            $resolution = [
                'type'       => 'collection',
                'brand'      => $brand_name,
                'collection' => $collection_name,
            ];
        }

        $fp = @fopen($file_path, 'w');
        if (!$fp) {
            return new WP_Error('csv_write_failed', 'Could not write CSV file at ' . $file_path);
        }
        // format confirmat din exportul real Wizart: BOM UTF-8 + CSV clasic
        // (virgulă, câmpuri între ghilimele) — NU tab-delimitat.
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $schema['columns']);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return [$file_path, $images, $resolution];
    }

    /**
     * Transformă un context "logic" (brand, collection, name, vendor_code, ...)
     * într-un rând CSV aliniat cu $schema['columns'], folosind $schema['field_map']
     * pentru câmpurile cunoscute. Coloanele nemapate rămân goale, dar pot fi
     * completate prin filtrul 'wizart_pim_row_context' înainte de mapare
     * (util pentru coloane specifice tipului: color, material, style, etc.)
     */
    private static function map_context_to_row($context, $schema, $item, $parent_product) {
        // permite completarea altor coloane (culoare, material, stil, etc.)
        // din atribute WooCommerce / meta custom, fără a modifica acest fișier
        $context = apply_filters('wizart_pim_row_context', $context, $schema, $item, $parent_product);

        $row = array_fill_keys($schema['columns'], '');

        foreach ($schema['field_map'] as $logical => $column) {
            if (isset($context[$logical]) && isset($row[$column])) {
                $row[$column] = $context[$logical];
            } elseif (array_key_exists($logical, $context) && array_key_exists($column, $row)) {
                $row[$column] = $context[$logical];
            }
        }

        // orice cheie din $context care se potrivește direct cu numele unei
        // coloane (ex. filtrul a adăugat 'color' și există coloana 'color')
        foreach ($context as $key => $value) {
            if (array_key_exists($key, $row) && $row[$key] === '') {
                $row[$key] = $value;
            }
        }

        return array_values($row);
    }

    private static function build_zip($product, $images) {
        $dir = self::get_csv_dir();
        if (is_wp_error($dir)) return $dir;

        $zip_path = trailingslashit($dir) . 'product-' . $product->get_id() . '.zip';

        if (empty($images)) {
            return new WP_Error('no_images', __('No product images found to include in the archive', 'wizart-pim-sync'));
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_failed', 'Could not create ZIP archive');
        }
        foreach ($images as $entry) {
            $zip->addFile($entry['path'], $entry['zip_name']);
        }
        $zip->close();

        return $zip_path;
    }

    private static function get_csv_dir() {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('upload_dir_error', $upload_dir['error']);
        }
        $dir = trailingslashit($upload_dir['basedir']) . self::CSV_SUBDIR;
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            // blochează accesul public direct la fișierele CSV/ZIP
            file_put_contents(trailingslashit($dir) . '.htaccess', "Deny from all\n");
            file_put_contents(trailingslashit($dir) . 'index.php', "<?php // silence is golden\n");
        }
        return $dir;
    }

    /**
     * Mapare categorie WooCommerce -> productTypeCode Wizart, pe baza
     * multi-select-urilor per tip din Settings (category_map), cu fallback
     * la "Fallback Product Type" dacă nicio categorie a produsului nu e
     * mapată. Filtrul de mai jos rămâne disponibil pentru suprascriere manuală.
     */
    public static function get_product_type_code($product) {
        $resolved = Wizart_PIM_Field_Maps::resolve_product_type($product);
        return apply_filters('wizart_product_type_code', $resolved, $product);
    }

    /* ==================================================================
     * Import upload
     * ================================================================== */

    private static function upload_import($token, $product_type_code, $csv_path, $zip_path) {
        $url = Wizart_PIM_Auth::API_BASE . '/api/v2.1/product-type/' . rawurlencode($product_type_code) . '/import';

        $boundary = wp_generate_password(24, false);
        $payload  = '';

        $files = [
            'data'    => ['path' => $csv_path, 'type' => 'text/csv'],
            'archive' => ['path' => $zip_path, 'type' => 'application/zip'],
        ];
        foreach ($files as $field => $info) {
            $payload .= '--' . $boundary . "\r\n";
            $payload .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . basename($info['path']) . '"' . "\r\n";
            $payload .= 'Content-Type: ' . $info['type'] . "\r\n\r\n";
            $payload .= file_get_contents($info['path']) . "\r\n";
        }
        $payload .= '--' . $boundary . '--';

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body'    => $payload,
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 && $code !== 201) {
            return new WP_Error(
                'wizart_import_failed',
                'Import request failed (HTTP ' . $code . '): ' . wp_remote_retrieve_body($response)
            );
        }

        $import_id = $body['data']['id'] ?? null;
        if (!$import_id) {
            return new WP_Error('wizart_import_no_id', 'Import started but no import ID was returned');
        }

        return ['import_id' => $import_id];
    }

    /* ==================================================================
     * Polling import status + salvare wizart_uuid
     * ================================================================== */

    public static function check_import_status($product_id, $import_id, $attempt = 1) {
        $token = Wizart_PIM_Auth::get_access_token();
        if (is_wp_error($token)) {
            update_post_meta($product_id, '_wizart_sync_status', 'auth_error');
            return;
        }

        $response = wp_remote_get(Wizart_PIM_Auth::API_BASE . '/api/v2.1/imports/' . rawurlencode($import_id), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            self::reschedule_or_fail($product_id, $import_id, $attempt, 'network_error');
            return;
        }

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $status = $body['data']['attributes']['status'] ?? null;

        $in_progress = [
            'uploading_files', 'validating_csv', 'unpacking_archive',
            'checking_images', 'waiting_queue', 'importing_data',
        ];

        if (in_array($status, $in_progress, true)) {
            self::reschedule_or_fail($product_id, $import_id, $attempt, 'importing');
            return;
        }

        if ($status === 'failure') {
            update_post_meta($product_id, '_wizart_sync_status', 'failed');
            delete_post_meta($product_id, self::META_PENDING);
            return;
        }

        if (in_array($status, ['success', 'warning'], true)) {
            $resolution = json_decode(get_post_meta($product_id, self::META_PENDING, true), true);
            $uuid = null;

            if (is_array($resolution) && ($resolution['type'] ?? '') === 'collection') {
                $uuid = self::find_collection_uuid($token, $resolution['brand'], $resolution['collection']);
            } elseif (is_array($resolution) && ($resolution['type'] ?? '') === 'article') {
                $uuid = self::find_article_uuid_by_vendor_code($token, $resolution['vendor_code']);
            }

            if ($uuid) {
                update_post_meta($product_id, 'wizart_uuid', $uuid);
                update_post_meta($product_id, '_wizart_sync_status', 'synced');
                delete_post_meta($product_id, self::META_PENDING);
            } else {
                self::reschedule_or_fail($product_id, $import_id, $attempt, 'uuid_not_found_yet');
            }
            return;
        }

        self::reschedule_or_fail($product_id, $import_id, $attempt, 'unknown_status');
    }

    private static function reschedule_or_fail($product_id, $import_id, $attempt, $reason) {
        if ($attempt >= self::MAX_POLL_ATTEMPTS) {
            update_post_meta($product_id, '_wizart_sync_status', 'timeout_' . $reason);
            return;
        }
        update_post_meta($product_id, '_wizart_sync_status', $reason);
        wp_schedule_single_event(
            time() + self::POLL_INTERVAL,
            'wizart_check_import_status_event',
            [$product_id, $import_id, $attempt + 1]
        );
    }

    /* ------------------- rezolvare UUID după import ------------------- */

    private static function find_article_uuid_by_vendor_code($token, $sku) {
        if (!$sku) return null;

        $query = wp_json_encode(['$like' => ['vendor_code', $sku]]);
        $url   = Wizart_PIM_Auth::API_BASE . '/api/articles/search?' . http_build_query(['q' => $query]);

        $body = self::api_get($token, $url);
        if (is_wp_error($body)) return null;

        return $body['data'][0]['uuid'] ?? null;
    }

    private static function find_collection_uuid($token, $brand_name, $collection_name) {
        $brand_uuid = self::find_brand_uuid_by_name($token, $brand_name);
        if (!$brand_uuid) return null;

        $page = 1;
        do {
            $url  = Wizart_PIM_Auth::API_BASE . '/api/customer/brands/' . rawurlencode($brand_uuid) . '/collections?page=' . $page;
            $body = self::api_get($token, $url);
            if (is_wp_error($body) || empty($body['data'])) break;

            foreach ($body['data'] as $collection) {
                if (isset($collection['name']) && self::names_match($collection['name'], $collection_name)) {
                    return $collection['uuid'] ?? null;
                }
            }

            $has_next = !empty($body['links']['next']);
            $page++;
        } while ($has_next && $page <= 20);

        return null;
    }

    private static function find_brand_uuid_by_name($token, $brand_name) {
        if (!$brand_name) return null;

        $page = 1;
        do {
            $url  = Wizart_PIM_Auth::API_BASE . '/api/customer/brands?page=' . $page;
            $body = self::api_get($token, $url);
            if (is_wp_error($body) || empty($body['data'])) break;

            foreach ($body['data'] as $brand) {
                if (isset($brand['name']) && self::names_match($brand['name'], $brand_name)) {
                    return $brand['uuid'] ?? null;
                }
            }

            $has_next = !empty($body['links']['next']);
            $page++;
        } while ($has_next && $page <= 20);

        return null;
    }

    private static function names_match($a, $b) {
        return trim(mb_strtolower($a)) === trim(mb_strtolower($b));
    }

    private static function api_get($token, $url) {
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('wizart_api_error', 'API error (HTTP ' . $code . ') at ' . $url);
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}
