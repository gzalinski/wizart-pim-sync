<?php
// Se rulează doar când pluginul este șters (nu la simpla dezactivare) din admin.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('wizart_pim_settings');
delete_transient('wizart_pim_access_token');

// Notă: nu ștergem meta 'wizart_uuid' / '_wizart_sync_status' / '_wizart_import_id'
// de pe produse, ca să nu pierzi legătura cu PIM dacă reinstalezi pluginul.
