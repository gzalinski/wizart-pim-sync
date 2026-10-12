<?php
/**
 * Schema coloanelor CSV per productTypeCode Wizart.
 *
 * Fiecare tip de produs (wallpaper, tile, ...) are propriul set de coloane,
 * într-o ordine fixă cerută de API. Adăugăm aici manual tipurile confirmate
 * (copiate din răspunsul GET /api/v2.1/data-mappings/default/:code).
 *
 * 'field_map' leagă câmpurile "logice" pe care le putem popula automat din
 * WooCommerce (brand, collection, name, vendor_code, image, description,
 * regular_price, promotional_price, availability, link, width, length)
 * de numele exact al coloanei CSV pentru acel tip. Coloanele fără o intrare
 * în field_map rămân goale, dar pot fi completate prin filtrul
 * 'wizart_pim_row_context' (vezi class-wizart-pim-sync.php).
 */

if (!defined('ABSPATH')) exit;

class Wizart_PIM_Field_Maps {

    /**
     * Tipurile de produs pentru care există mapare pe categorie WooCommerce
     * (multi-select dedicat în Settings) + schemă CSV confirmată mai jos.
     * Sursă unică — folosită atât la randarea Settings, cât și la
     * rezolvarea tipului unui produs (resolve_product_type()).
     */
    const PRODUCT_TYPES = ['wallpaper', 'tile'];

    /**
     * Toate codurile de productType acceptate de Wizart (din documentația
     * API), cu etichetă afișabilă. Folosit pentru dropdown-ul "Fallback
     * Product Type" din Settings — include și tipuri fără multi-select
     * dedicat de categorii (ex. paint, laminate), pentru cazul în care un
     * produs nu se potrivește cu nicio categorie mapată.
     */
    const ALL_TYPE_LABELS = [
        'wallpaper'                => 'Wallpaper',
        'wall_panels'              => 'Wall Panels',
        'wall_mural'               => 'Wall Mural',
        'wall_decals'              => 'Wall Decals',
        'wall_border'              => 'Wall Border',
        'vinyl_flooring'           => 'Vinyl Flooring',
        'tile'                     => 'Tile',
        'rug'                      => 'Rug',
        'plaster'                  => 'Plaster',
        'paint'                    => 'Paint',
        'mural'                    => 'Mural',
        'linoleum'                 => 'Linoleum',
        'laminate'                 => 'Laminate',
        'hardwood_flooring'        => 'Hardwood Flooring',
        'engineered_wood_flooring' => 'Engineered wood flooring',
        'carpet_tile'              => 'Carpet flooring in tiles',
        'carpet_roll'              => 'Carpet flooring in rolls',
        'carpet'                   => 'Carpet',
    ];

    /**
     * Coloane cu listă fixă de opțiuni, configurabilă din Settings (textarea,
     * o valoare pe linie), afișate ca dropdown DOAR pe produsul părinte
     * (nu și pe variații — nu există câmp separat acolo, valoarea de pe
     * părinte se aplică tuturor rândurilor din CSV pentru acel produs).
     */
    const DROPDOWN_FIELDS = ['interior_type', 'style', 'pattern'];

    /**
     * Subset din DROPDOWN_FIELDS care se randează ca <select multiple>
     * (valoare stocată/trimisă ca listă separată prin virgulă).
     */
    const MULTISELECT_DROPDOWN_FIELDS = ['interior_type'];

    /**
     * Determină codul de productType Wizart pentru un produs, pe baza
     * categoriilor WooCommerce mapate în Settings (category_map). Dacă
     * niciuna dintre categoriile produsului nu e mapată la vreun tip din
     * PRODUCT_TYPES, cade pe "Fallback Product Type" din Settings.
     */
    public static function resolve_product_type($product) {
        $term_ids = wc_get_product_term_ids($product->get_id(), 'product_cat');
        $category_map = Wizart_PIM_Settings::get('category_map', []);

        foreach (self::PRODUCT_TYPES as $type) {
            $mapped_ids = array_map('intval', $category_map[$type] ?? []);
            if (array_intersect($term_ids, $mapped_ids)) {
                return $type;
            }
        }

        return Wizart_PIM_Settings::get('fallback_product_type', 'wallpaper');
    }

    /**
     * Definiții statice, confirmate manual per tip.
     */
    private static function static_maps() {
        return [

            // Confirmat prin eroarea de validare a API-ului (câmpurile lipsă
            // enumerate corespund exact acestei liste) — aceasta e schema
            // REALĂ folosită de endpointul de import, obținută din
            // GET /api/v2.1/data-mappings/default/wallpaper
            'wallpaper' => [
                'columns' => [
                    'brand_name', 'collection_name', 'product_name', 'unique_sku_id', 'product_image',
                    'product_width', 'repeat_width', 'pattern_repeat', 'pattern_offset', 'related_skus',
                    'product_link', 'wallpaper_roll_length', 'color', 'base', 'coating', 'interior_type',
                    'style', 'pattern', 'product_description', 'texture', 'country_of_origin', 'removal_type',
                    'product_transparency', 'reverse', 'mural', 'product_availability', 'product_regular_price',
                    'product_promotional_price', 'product_layout', 'application_surface', 'product_context',
                    'context_currency', 'features', 'pattern_scale', 'installation_method', 'additional_data',
                ],
                'field_map' => [
                    'brand'              => 'brand_name',
                    'collection'         => 'collection_name',
                    'name'               => 'product_name',
                    'vendor_code'        => 'unique_sku_id',
                    'image'              => 'product_image',
                    'description'        => 'product_description',
                    'regular_price'      => 'product_regular_price',
                    'promotional_price'  => 'product_promotional_price',
                    'availability'       => 'product_availability',
                    'link'               => 'product_link',
                    // 'width' NU mai vine din dimensiunile WooCommerce — vezi
                    // extra_fields['product_width'] mai jos (meta field dedicat)
                ],
                // conform specificației oficiale a câmpurilor: coloanele
                // marcate REQUIRED = yes (nu doar "context"). 'width' e
                // validat separat, ca extra_field required (vezi mai jos).
                'required_logical' => ['brand', 'collection', 'name', 'vendor_code', 'image'],
                // valori fixe, aplicate identic pe toate rândurile de tip wallpaper
                'defaults' => [
                    'product_context'  => 'en',
                    'context_currency' => '$',
                ],
                // meta fields dedicate (NU mai vin din atribute/dimensiuni
                // WooCommerce). Pentru variații: se citesc întâi de pe
                // variație, iar dacă lipsesc, de pe produsul părinte
                // (fallback). Coloana CSV = cheia array-ului (potrivire
                // directă, fără nevoie de intrare în field_map).
                'extra_fields' => [
                    'product_width' => [
                        'label'       => 'Product Width (in)',
                        'required'    => true,
                        'type'        => 'number',
                        'placeholder' => '41.73',
                        'step'        => 0.01,
                        'unit'        => 'in', // convertit în metri la generarea CSV
                    ],
                    'repeat_width' => [
                        'label'       => 'Repeat Width (in)',
                        'required'    => true,
                        'type'        => 'number',
                        'placeholder' => '41.73',
                        'step'        => 0.01,
                        'unit'        => 'in',
                    ],
                    'pattern_repeat' => [
                        'label'       => 'Pattern Repeat (in)',
                        'required'    => false,
                        'type'        => 'number',
                        'placeholder' => '25.2',
                        'step'        => 0.01,
                        'unit'        => 'in',
                    ],
                    'pattern_offset' => [
                        'label'       => 'Pattern Offset (in)',
                        'required'    => false,
                        'type'        => 'number',
                        'placeholder' => '12.6',
                        'step'        => 0.01,
                        'unit'        => 'in',
                    ],
                    'wallpaper_roll_length' => [
                        'label'       => 'Wallpaper Roll Length (in)',
                        'required'    => false,
                        'type'        => 'number',
                        'placeholder' => '393.7',
                        'step'        => 0.01,
                        'unit'        => 'in',
                    ],
                ],
            ],

            // Confirmat prin documentația oficială de câmpuri Wizart pentru
            // tile (tabelul FIELDS/DESCRIPTION/REQUIRED/CONTEXT/FILTER).
            // REQUIRED = yes: brand_name, collection_name, product_name,
            // unique_sku_id, product_image, product_width, product_length.
            'tile' => [
                'columns' => [
                    'brand_name', 'collection_name', 'product_name', 'unique_sku_id', 'product_image',
                    'product_width', 'product_length', 'product_thickness', 'pattern_width', 'pattern_length',
                    'layouts', 'related_skus', 'product_link', 'grout_line_selection', 'grout_line_color',
                    'seam_color', 'seam_thickness', 'product_description', 'package_quantity', 'package_coverage',
                    'resistance', 'country_of_origin', 'material', 'color', 'surface_finish',
                    'flooring_product_shape', 'texture', 'interior_type', 'style', 'pattern',
                    'application_surface', 'installation_method', 'subfloor', 'product_availability',
                    'price_per_m2', 'promotional_price_per_m2', 'price_per_package', 'promotional_price_per_package',
                    'product_context', 'context_currency', 'gloss', 'shade', 'lifestyle', 'warranty', 'usage',
                    'additional_data',
                ],
                'field_map' => [
                    'brand'              => 'brand_name',
                    'collection'         => 'collection_name',
                    'name'               => 'product_name',
                    'vendor_code'        => 'unique_sku_id',
                    'image'              => 'product_image',
                    'description'        => 'product_description',
                    'regular_price'      => 'price_per_m2',
                    'promotional_price'  => 'promotional_price_per_m2',
                    'availability'       => 'product_availability',
                    'link'               => 'product_link',
                    // 'width'/'length' NU mai vin din dimensiunile WooCommerce
                    // — vezi extra_fields mai jos (meta fields dedicate)
                ],
                'required_logical' => ['brand', 'collection', 'name', 'vendor_code', 'image'],
                // meta fields dedicate (variație -> fallback pe produsul
                // părinte), la fel ca la wallpaper
                'extra_fields' => [
                    'product_width' => [
                        'label'       => 'Product Width (in)',
                        'required'    => true,
                        'type'        => 'number',
                        'placeholder' => '11.81',
                        'step'        => 0.01,
                        'unit'        => 'in',
                    ],
                    'product_length' => [
                        'label'       => 'Product Length (in)',
                        'required'    => true,
                        'type'        => 'number',
                        'placeholder' => '23.62',
                        'step'        => 0.01,
                        'unit'        => 'in',
                    ],
                    'product_thickness' => [
                        'label'       => 'Product Thickness (in)',
                        'required'    => false,
                        'type'        => 'number',
                        'placeholder' => '0.59',
                        'step'        => 0.001,
                        'unit'        => 'in',
                    ],
                    'pattern_width' => [
                        'label'       => 'Pattern Width (in)',
                        'required'    => false,
                        'type'        => 'number',
                        'step'        => 0.01,
                        'unit'        => 'in',
                    ],
                    'pattern_length' => [
                        'label'       => 'Pattern Length (in)',
                        'required'    => false,
                        'type'        => 'number',
                        'step'        => 0.01,
                        'unit'        => 'in',
                    ],
                ],
                // câmpuri multiselect cu opțiuni FIXE (din documentația
                // Wizart, nu configurabile din Settings ca la DROPDOWN_FIELDS)
                'fixed_select_fields' => [
                    'layouts' => [
                        'label'    => 'Layouts',
                        'multiple' => true,
                        'options'  => [
                            '1_2_offset', '1_3_offset', 'no_offset', 'random_offset',
                            'herringbone', 'chevron', 'basket_weave',
                        ],
                        // aplicat la Sync, dacă rămâne gol după variație -> părinte
                        'default'  => 'no_offset',
                    ],
                ],
            ],

            // wall_mural: NECONFIRMAT încă — folosește tool-ul "Fetch Mapping"
            // din Settings → Wizart PIM pentru a obține schema reală, apoi
            // adaug-o aici. Până atunci, fetch_dynamic() de mai jos preia
            // automat mapping-ul direct de la API ca fallback.

        ];
    }

    /**
     * Returnează valorile hardcodate ('defaults') pentru un productTypeCode,
     * aplicate identic pe fiecare rând din CSV (ex. style, pattern, interior_type).
     */
    public static function get_defaults($product_type_code) {
        $static = self::static_maps();
        return $static[$product_type_code]['defaults'] ?? [];
    }

    /**
     * Returnează doar 'extra_fields' pentru un productTypeCode, fără apel API
     * (folosit la randarea UI-ului, unde nu vrem un request de rețea la fiecare
     * încărcare a paginii de produs). Tipurile rezolvate doar dinamic (fără
     * intrare în static_maps) nu au extra_fields definite momentan.
     */
    public static function get_extra_fields($product_type_code) {
        $static = self::static_maps();
        return $static[$product_type_code]['extra_fields'] ?? [];
    }

    /**
     * Câmpuri select/multiselect cu opțiuni fixe, definite în cod (nu
     * configurabile din Settings), specifice unui productTypeCode.
     */
    public static function get_fixed_select_fields($product_type_code) {
        $static = self::static_maps();
        return $static[$product_type_code]['fixed_select_fields'] ?? [];
    }

    /**
     * Returnează schema pentru un productTypeCode:
     *  - dacă e definit static mai sus, îl folosește direct
     *  - altfel, îl ia dinamic de la API (Step 2) și face un best-effort de
     *    mapare a coloanelor cunoscute după nume (fallback, de verificat manual)
     *
     * @return array{columns: string[], field_map: array<string,string>}|WP_Error
     */
    public static function resolve($product_type_code, $token) {
        $static = self::static_maps();
        if (isset($static[$product_type_code])) {
            return $static[$product_type_code];
        }

        return self::fetch_dynamic($product_type_code, $token);
    }

    private static function fetch_dynamic($product_type_code, $token) {
        $url = Wizart_PIM_Auth::API_BASE . '/api/v2.1/data-mappings/default/' . rawurlencode($product_type_code);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'text/csv',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error(
                'wizart_mapping_fetch_failed',
                sprintf(
                    __('Could not fetch default CSV mapping for "%s" (HTTP %d). Add it manually in Wizart_PIM_Field_Maps::static_maps().', 'wizart-pim-sync'),
                    $product_type_code,
                    $code
                )
            );
        }

        $body = wp_remote_retrieve_body($response);
        // Wizart exportă CSV cu BOM UTF-8 + delimitator virgulă + câmpuri între ghilimele
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $line = strtok($body, "\r\n");
        $columns = array_map('trim', str_getcsv($line, ","));

        // fallback, în caz că acest tip particular chiar e tab-delimitat
        if (count($columns) <= 1) {
            $columns = array_map('trim', str_getcsv($line, "\t"));
        }

        return [
            'columns'   => $columns,
            'field_map' => self::guess_field_map($columns),
        ];
    }

    /**
     * Best-effort: potrivește coloane necunoscute cu câmpurile logice pe
     * baza unor tipare de nume uzuale. Rezultatul trebuie verificat manual
     * și, ideal, promovat în static_maps() odată confirmat.
     */
    private static function guess_field_map($columns) {
        $patterns = [
            'brand'             => ['brand_name', 'brand'],
            'collection'        => ['collection_name', 'collection'],
            'name'              => ['product_name', 'article_name', 'name'],
            'vendor_code'       => ['unique_sku_id', 'vendor_code', 'sku'],
            'custom_sku'        => ['custom_sku_id'],
            'image'             => ['product_image', 'image_name', 'image'],
            'description'       => ['product_description', 'description'],
            'regular_price'     => ['product_regular_price', 'regular_price', 'price_per_m2', 'price'],
            'promotional_price' => ['product_promotional_price', 'promotional_price', 'promotional_price_per_m2'],
            'availability'      => ['product_availability', 'availability'],
            'link'              => ['product_link', 'link'],
            'width'             => ['product_width', 'width'],
            'length'            => ['product_length', 'wallpaper_roll_length', 'length'],
            'is_visible'        => ['is_visible'],
            'product_type_code' => ['product_type'],
        ];

        $map = [];
        foreach ($patterns as $logical => $candidates) {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $map[$logical] = $candidate;
                    break;
                }
            }
        }
        return $map;
    }
}
