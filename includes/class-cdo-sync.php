<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CDO_Sync {

    private $client;

    public function __construct() {
        $this->client = new CDO_Client();
    }

    public function sync_all_products( $offset = 0, $batch_size = 10 ) {

        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 300);

        $products = $this->client->get_all_products_unlimited();
        error_log('TOTAL PRODUCTOS API: ' . count($products));

        if ( is_wp_error($products) ) {
            return $products;
        }

        if ( empty($products) || ! is_array($products) ) {
            return new WP_Error( 'no_data', 'El endpoint no devolvió productos.' );
        }

        $total_products = count($products);

        // SOLO el lote que corresponde
        $subset = array_slice($products, $offset, $batch_size);

        $results = [];
        $processed = 0;
        $successful = 0;
        $failed = 0;
        $errors = [];

        foreach ( $subset as $product ) {

            try {

                $result = $this->sync_single_product($product);

                if ( is_wp_error( $result ) ) {
                    $failed++;
                    $errors[] = $result->get_error_message();
                    $results[] = ['status' => 'error', 'message' => $result->get_error_message()];
                } else {
                    $successful++;
                    $results[] = ['status' => 'success', 'message' => $result ];
                    $this->log( "Producto sincronizado exitosamente (ID: {$result})" );
                }

            } catch ( Exception $e ) {

                $failed++;
                $error_msg = "Error inesperado: " . $e->getMessage();
                $errors[] = $error_msg;
                $results[] = ['status' => 'error', 'message' => $error_msg];
                $this->log( "Error: " . $e->getMessage() );

            }

            $processed++;

        }

        wp_cache_flush();

        return [
            'processed' => $processed,
            'successful' => $successful,
            'failed' => $failed,
            'total' => $total_products,
            'offset' => $offset,
            'batch_size' => $batch_size,
            'has_more' => ($offset + $batch_size) < $total_products,
            'next_offset' => $offset + $batch_size,
            'results' => $results,
            'errors' => $errors,
            'status' => 'sync_completed'
        ];
    }

    private function sync_single_product($product) {

        $code        = $product['code'] ?? '';
        $name        = $product['name'] ?? '';
        $description = $product['description'] ?? '';
        $variants    = $product['variants'] ?? [];

        if (!$code) return;

        $is_variable = !empty($variants);

        $product_id = $this->find_product_by_code($code);

        if ($product_id) {

            $wc_product = wc_get_product($product_id);
            // asegurar que el tipo sea variable si tiene variantes
            if ($is_variable && $wc_product->get_type() !== 'variable') {

                wp_set_object_terms($product_id, 'variable', 'product_type');

                $wc_product = new WC_Product_Variable($product_id);
            }

        } else {

            $wc_product = $is_variable
                ? new WC_Product_Variable()
                : new WC_Product_Simple();

            $wc_product->set_sku($code);
            $wc_product->set_status('publish');
            
        }

        $wc_product->set_name($name);
        $wc_product->set_description($description);

        $product_id = $wc_product->save();

        // ASIGNAR MARCA
        $this->assign_fixed_brand_to_product($product_id);

        // IMAGEN DESTACADA 
        $featured_image = $product['variants'][0]['picture']['original'] ?? ''; 
        if ($featured_image && !get_post_thumbnail_id($product_id)) { 
            $attachment_id = $this->download_and_attach_image($featured_image, $product_id); 
            if ($attachment_id) { 
                set_post_thumbnail($product_id, $attachment_id); 
            } 
        } 
        // GALERIA 
        $gallery_ids = []; 
        $gallery = $product['variants'][0]['other_pictures'] ?? []; 
        foreach ($gallery as $img) { 
            $url = $img['original'] ?? ''; 
            if (!$url) continue; 
            $attachment_id = $this->download_and_attach_image($url, $product_id);
            if ($attachment_id) { 
                $gallery_ids[] = $attachment_id; 
            }
        } 
        if (!empty($gallery_ids)) { 
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids)); 
        } 

        // ASIGNAR CATEGORIA
        $this->sync_product_categories($product_id, $product);

        if ($is_variable) {

            $this->sync_product_variations_cdo(
                $product_id,
                $product,
                $name
            );

            // recalcular stock del producto variable
            wc_delete_product_transients($product_id);
            WC_Product_Variable::sync($product_id);
            
        }
    }

    private function find_product_by_code($code) {

        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_sku' 
                AND meta_value = %s",
                $code
            )
        );

        if (!empty($rows)) {
            return intval($rows[0]);
        }
        return 0;
    }

    private function sync_product_variations_cdo( $product_id, $product, $title ) {

        $attribute_slug = 'pa_color';

        // Crear atributo si no existe
        if ( ! taxonomy_exists( $attribute_slug ) ) {

            wc_create_attribute([
                'slug' => 'color',
                'name' => 'Color',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);

            register_taxonomy(
                $attribute_slug,
                'product',
                [ 'hierarchical' => false, 'label' => 'Color' ]
            );
        }

        // Asignar atributo al producto
        $attributes = [];
        $attributes[$attribute_slug] = [
            'name'         => $attribute_slug,
            'value'        => '',
            'is_visible'   => 1,
            'is_variation' => 1,
            'is_taxonomy'  => 1,
        ];

        update_post_meta( $product_id, '_product_attributes', $attributes );

        $existing_variations = $this->get_existing_variations_data( $product_id );

        $new_variations_data = [];
        $colors_to_keep = [];

        $gallery_ids = get_post_meta( $product_id, '_product_image_gallery', true );
        $gallery_ids = $gallery_ids ? explode(',', $gallery_ids) : [];

        if ( isset($product['variants']) && is_array($product['variants']) ) {

            foreach ( $product['variants'] as $variant ) {

                $color_name = isset($variant['color']['name'])
                    ? trim((string)$variant['color']['name'])
                    : '';

                if ( $color_name === '' ) {
                    error_log("CDO Sync: variante sin color");
                    continue;
                }

                //$colors_to_keep[] = $color_name;

                $variation_data = [
                    'color' => $color_name,
                    'sku'   => $variant['sku'] ?? '',
                    'price' => $variant['net_price'] ?? '0',
                    'stock' => $variant['stock_available'] ?? 0,
                    'image' => $variant['picture']['original'] ?? '',
                ];

                //$new_variations_data[$color_name] = $variation_data;
                $new_variations_data[$variation_data['sku']] = $variation_data;
            }
        }

        // Obtener SKUs de la API
        $skus_to_keep = [];

        foreach ( $new_variations_data as $data ) {
            if ( !empty($data['sku']) ) {
                $skus_to_keep[] = $data['sku'];
            }
        }

        $skus_to_keep = array_flip($skus_to_keep);

        // Eliminar variaciones que ya no existen en la API
        foreach ( $existing_variations as $sku => $existing_data ) {

            if ( !isset($skus_to_keep[$sku]) ) {

                wp_delete_post( $existing_data['variation_id'], true );

                error_log("CDO Sync: Variación eliminada {$sku}");
            }
        }

        foreach ( $new_variations_data as $sku => $variation_data ) {

            $variation_id = null;
            $needs_update = false;

            $color_name = $variation_data['color'];

            if ( isset($existing_variations[$sku]) ) {

                $existing = $existing_variations[$sku];

                $variation_id = $existing['variation_id'];

                if (
                    $existing['price'] !== $variation_data['price'] ||
                    $existing['stock'] !== $variation_data['stock']
                ) {
                    $needs_update = true;
                }

            } else {

                $needs_update = true;
            }

            if ( $needs_update ) {

                $term = term_exists( $color_name, $attribute_slug );

                if ( $term === 0 || $term === null ) {

                    $t = wp_insert_term(
                        $color_name,
                        $attribute_slug,
                        [ 'slug' => sanitize_title($color_name) ]
                    );

                    if ( is_wp_error($t) ) {
                        continue;
                    }

                    $term_id = $t['term_id'];

                } else {

                    $term_id = is_array($term) ? $term['term_id'] : $term;
                }

                wp_set_object_terms( $product_id, intval($term_id), $attribute_slug, true );

                if ( !$variation_id ) {

                    $variation_id = wp_insert_post([
                        'post_title'  => $title . ' - ' . $color_name,
                        'post_status' => 'publish',
                        'post_parent' => $product_id,
                        'post_type'   => 'product_variation',
                    ]);
                }

                update_post_meta(
                    $variation_id,
                    'attribute_' . $attribute_slug,
                    sanitize_title($color_name)
                );

                update_post_meta( $variation_id, '_sku', $variation_data['sku'] );
                update_post_meta( $variation_id, '_regular_price', $variation_data['price'] );
                update_post_meta( $variation_id, '_price', $variation_data['price'] );

                update_post_meta( $variation_id, '_stock', $variation_data['stock'] );
                update_post_meta( $variation_id, '_manage_stock', 'yes' );

                update_post_meta(
                    $variation_id,
                    '_stock_status',
                    $variation_data['stock'] > 0 ? 'instock' : 'outofstock'
                );
            }
        }

        $gallery_ids = array_unique( $gallery_ids );

        update_post_meta(
            $product_id,
            '_product_image_gallery',
            implode(',', $gallery_ids)
        );

        // asegurar estado del producto padre
        update_post_meta($product_id, '_manage_stock', 'no');
        update_post_meta($product_id, '_stock_status', 'instock');

        // limpiar cache
        wc_delete_product_transients($product_id);
        delete_transient('wc_product_children_' . $product_id);

        // sincronizar variaciones  
        WC_Product_Variable::sync($product_id);

    }

    private function get_existing_variations_data( $product_id ) {

        $variations_data = [];

        $args = [
            'post_parent' => $product_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'post_status' => [ 'publish', 'private', 'draft' ],
            'fields'      => 'ids'
        ];

        $variations = get_posts( $args );

        foreach ( $variations as $variation_id ) {

            $sku = get_post_meta( $variation_id, '_sku', true );

            if ( !$sku ) continue;

            $color_slug = get_post_meta( $variation_id, 'attribute_pa_color', true );

            $term = get_term_by( 'slug', $color_slug, 'pa_color' );

            $color_name = $term ? $term->name : $color_slug;

            $image_id = get_post_meta( $variation_id, '_thumbnail_id', true );

            $image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

            $variations_data[$sku] = [
                'variation_id' => $variation_id,
                'color'        => $color_name,
                'sku'          => $sku,
                'price'        => get_post_meta( $variation_id, '_regular_price', true ),
                'stock'        => intval( get_post_meta( $variation_id, '_stock', true ) ),
                'image_id'     => $image_id,
                'image_url'    => $image_url
            ];
        }

        return $variations_data;
    }

    private function sync_product_categories($product_id, $product) {

        $categories = $product['categories'] ?? [];
        if (empty($categories)) return;

        static $category_cache = [];
        $term_ids = [];

        foreach ($categories as $cat) {

            // Obtener nombre de la categoría
            if (is_array($cat) && isset($cat['name'])) {
                $category_name = trim($cat['name']);
            } elseif (is_string($cat)) {
                $category_name = trim($cat);
            } else {
                continue; // si no tiene nombre válido, saltar
            }

            if (!$category_name) continue;

            $category_slug = sanitize_title($category_name);

            // Revisar caché
            if (isset($category_cache[$category_slug])) {
                $term_ids[] = $category_cache[$category_slug];
                continue;
            }

            // Revisar si existe en WP
            $term = get_term_by('slug', $category_slug, 'product_cat');
            if (!$term) {
                $new_term = wp_insert_term($category_name, 'product_cat', ['slug' => $category_slug]);
                if (!is_wp_error($new_term)) {
                    $term_id = $new_term['term_id'];
                } else {
                    continue; // si falla la creación, saltar
                }
            } else {
                $term_id = $term->term_id;
            }

            // Guardar en caché y en lista final
            $category_cache[$category_slug] = $term_id;
            $term_ids[] = $term_id;
        }

        // Asignar todas las categorías al producto
        if (!empty($term_ids)) {
            wp_set_object_terms($product_id, $term_ids, 'product_cat');
        }
    }

    /* private function sync_product_categories($product_id, $product) {

        $categories = $product['categories'] ?? [];

        if ( !empty($categories) ) {

            // Obtener nombre de categoría correctamente
            $category_name = '';

            if (isset($categories[0])) {
                if (is_array($categories[0]) && isset($categories[0]['name'])) {
                    $category_name = trim($categories[0]['name']);
                } elseif (is_string($categories[0])) {
                    $category_name = trim($categories[0]);
                }
            }

            if (!$category_name) return; // si no hay nombre, salir

            $category_slug = sanitize_title($category_name);
            static $category_cache = [];

            if ( isset($category_cache[$category_slug]) ) {

                $term_id = $category_cache[$category_slug];

            } else {

                $term = get_term_by('slug', $category_slug, 'product_cat');

                if ( !$term ) {
                    $new_term = wp_insert_term($category_name, 'product_cat', ['slug'=>$category_slug]);
                    if ( !is_wp_error($new_term) ) {
                        $term_id = $new_term['term_id'];
                    }
                } else {
                    $term_id = $term->term_id;
                }

                $category_cache[$category_slug] = $term_id;
            }

            // Buscar si existe
            $term = get_term_by('slug', $category_slug, 'product_cat');

            if ( !$term ) {

                // Crear categoría si no existe
                $new_term = wp_insert_term(
                    $category_name,
                    'product_cat',
                    [
                        'slug' => $category_slug
                    ]
                );

                if ( !is_wp_error($new_term) ) {
                    $term_id = $new_term['term_id'];
                }

            } else {

                $term_id = $term->term_id;

            }

            // Asignar al producto
            if ( !empty($term_id) ) {
                wp_set_object_terms($product_id, [$term_id], 'product_cat');
            }
        }
    } */

    private function assign_fixed_brand_to_product($product_id) {

        error_log('Marca ejecutándose para producto: '.$product_id);

        if(!$product_id) return;

        $taxonomy = 'product_brand';
        $brand_name = 'CDO';

        // buscar marca
        $term = get_term_by('name', $brand_name, $taxonomy);

        // crear si no existe
        if(!$term){

            $term = wp_insert_term(
                $brand_name,
                $taxonomy
            );

            if(is_wp_error($term)){
                error_log('Error creando marca: '.$term->get_error_message());
                return;
            }

            $term_id = $term['term_id'];

        } else {

            $term_id = $term->term_id;

        }

        // asignar al producto
        wp_set_object_terms(
            $product_id,
            $term_id,
            $taxonomy,
            false
        );

    }

    private function remove_all_variations($product_id) {

        $args = [
            'post_parent' => $product_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'fields'      => 'ids'
        ];

        $variations = get_posts($args);

        foreach ($variations as $variation_id) {
            wp_delete_post($variation_id, true);
        }
    }

    private function download_and_attach_image( $image_url, $product_id ) {

        $existing = get_posts([
            'post_type'  => 'attachment',
            'meta_key'   => '_cdo_image_url',
            'meta_value' => $image_url,
            'fields'     => 'ids',
            'posts_per_page' => 1
        ]);

        if (!empty($existing)) {

            $attachment_id = $existing[0];

            wp_update_post([
                'ID' => $attachment_id,
                'post_parent' => $product_id
            ]);

            return $attachment_id;
        }

        if ( empty( $image_url ) ) return 0;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Descargar archivo temporal
        $tmp = download_url( $image_url );

        if ( is_wp_error( $tmp ) ) {
            error_log("Error descargando imagen: " . $tmp->get_error_message());
            return 0;
        }

        // Preparar archivo
        $file_array = [
            'name'     => basename( parse_url($image_url, PHP_URL_PATH) ),
            'tmp_name' => $tmp
        ];

        // Subir a WordPress
        $attachment_id = media_handle_sideload( $file_array, $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            error_log("Error procesando imagen: " . $attachment_id->get_error_message());
            return 0;
        }
        // GUARDAR URL ORIGINAL PARA REUTILIZAR
        update_post_meta($attachment_id, '_cdo_image_url', $image_url);

        return $attachment_id;
    }

    private function log( $message ) {
        $log = get_option( 'cdo_sync_log', [] );
        $log[] = '[' . current_time('mysql') . '] ' . $message;
        if ( count( $log ) > 200 ) $log = array_slice( $log, -200 );
        update_option( 'cdo_sync_log', $log );
    }
}