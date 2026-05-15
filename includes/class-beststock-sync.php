<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BestStock_Sync {

    private $client;

    public function __construct() {
        $this->client = new Beststock_Client();
    }

    /**
     * Handler AJAX para sincronizar productos.
     */
    public function ajax_beststock_sync_products($categoria_id, $wc_categories = []) {

        // Compatibilidad hacia atrás: si viene el $_POST['wc_category'] viejo
        if (empty($wc_categories)) {
            if (isset($_POST['wc_category'])) {
                $wc_categories = [intval($_POST['wc_category'])];
            } else {
                $wc_categories = [];
            }
        }

        // --- Obtener productos de la categoría ---
        $products = $this->client->get_products_by_category( $categoria_id );
        if ( is_wp_error( $products ) ) {
            error_log("Error al obtener productos: " . $products->get_error_message());
            return $products;
        }

        if ( empty( $products ) || ! is_array( $products ) ) {
            return new WP_Error( 'no_data', 'No se encontraron datos para la categoría ' . $categoria_id );
        }

        $saved_count = 0;

        foreach ($products as $prod) {
            error_log("Procesando producto: " . $prod['name'] . " (ID API: " . $prod['id'] . ")");

            try {
                // --- Verificar si existe el producto por SKU/ID externo ---
                $existing = wc_get_product_id_by_sku($prod['id']);
                if ($existing) {
                    $product = wc_get_product($existing);
                    error_log("Producto existente encontrado: {$prod['name']} (ID Woo: $existing)");
                } else {
                    error_log("Creando nuevo producto: {$prod['name']}");
                }

                // --- Pasamos array de categorías en vez de solo una ---
                $this->create_or_update_product($prod, $wc_categories);
                $saved_count++;

            } catch (Exception $e) {
                error_log("Error guardando producto {$prod['name']}: " . $e->getMessage());
            }
        }


        wp_send_json_success([
            'message' => "Productos sincronizados: $saved_count",
            'data' => $products
        ]);
    }

    //sincronizacion por lotes
    public function beststock_sync_products_batch($category_id, $offset = 0, $batch_size = 1, $wc_categories = []) {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 300);

        $products = $this->client->get_products_by_category($category_id);

        if (is_wp_error($products)) {
            return $products;
        }

        if (empty($products) || !is_array($products)) {
            return new WP_Error('no_data', 'No se encontraron productos para la categoría ' . $category_id);
        }

        $total = count($products);
        $batch = array_slice($products, $offset, $batch_size);

        $processed = 0;
        $successful = 0;
        $failed = 0;
        $results = [];
        $errors = [];

        foreach ($batch as $prod) {
            try {
                $existing = wc_get_product_id_by_sku($prod['id']);
                if ($existing) {
                    $product = wc_get_product($existing);
                    error_log("Producto existente actualizado: {$prod['name']} (Woo ID: $existing)");
                } else {
                    error_log("Creando producto nuevo: {$prod['name']}");
                }

                $res = $this->create_or_update_product($prod, $wc_categories);

                $results[] = [
                    'product_name' => $prod['name'],
                    'status' => 'success',
                    'product_id' => $existing ?: $res
                ];

                $successful++;
                $processed++;

                // Pequeña pausa para evitar "Too Many Requests"
                usleep(1500000); // 1 segundos entre productos

            } catch (Exception $e) {
                $failed++;
                $processed++;
                $errors[] = "Error guardando producto {$prod['name']}: " . $e->getMessage();
                $results[] = [
                    'product_name' => $prod['name'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                error_log("Error guardando producto {$prod['name']}: " . $e->getMessage());
            }
        }

        $next_offset = $offset + $batch_size;
        $has_more = $next_offset < $total;

        return [
            'processed' => $processed,
            'successful' => $successful,
            'failed' => $failed,
            'total' => $total,
            'offset' => $offset,
            'next_offset' => $next_offset,
            'has_more' => $has_more,
            'results' => $results,
            'errors' => $errors
        ];
    }


    private function create_or_update_product($prod) {
        if ( ! class_exists('WC_Product') ) return false;

        // --- Verificar si ya existe producto por ID externo ---
        $existing = get_posts([
            'post_type'  => 'product',
            'meta_key'   => '_beststock_id',
            'meta_value' => $prod['id'],
            'posts_per_page' => 1
        ]);

        if ( $existing ) {
            $product_id = $existing[0]->ID;
            $product = wc_get_product($product_id);
            error_log("Producto existente actualizado: {$prod['name']} (Woo ID: $product_id)");
        } else {
            // Si tiene colores → variable, si no → simple
            if ( !empty($prod['colors']) && is_array($prod['colors']) ) {
                $product = new WC_Product_Variable();
            } else {
                $product = new WC_Product_Simple();
            }
            $product->set_sku($prod['id']);
            error_log("Creando producto nuevo: {$prod['name']}");
        }

        // --- Datos básicos ---
        $product->set_name($prod['name']);
        $product->set_description($prod['description'] ?? '');
        if ( empty($prod['colors']) ) {
            if ( !empty($prod['price_scale'][0]['price']) ) {
                $product->set_regular_price($prod['price_scale'][0]['price']);
            }
        }
        $product_id = $product->save();

        // Guardar ID externo
        update_post_meta($product_id, '_beststock_id', $prod['id']);
        
        $terms = [];

        if (!empty($_POST['wc_category_parent'])) {
            $terms[] = intval($_POST['wc_category_parent']); // padre
        }
        if (!empty($_POST['wc_category_child'])) {
            $terms[] = intval($_POST['wc_category_child']); // hija
        }

        // Log antes de limpiar
        error_log('Categorías recibidas (raw) => parent: ' . ($_POST['wc_category_parent'] ?? 'null') . ' | child: ' . ($_POST['wc_category_child'] ?? 'null'));
        error_log('Terms iniciales => ' . print_r($terms, true));

        $terms = array_unique(array_filter($terms));

        // Quitar la categoría por defecto (Uncategorized)
        $default_cat = get_option('default_product_cat');
        $terms = array_diff($terms, [$default_cat]);

        // Log después de limpiar
        error_log('Default category ID => ' . $default_cat);
        error_log('Terms después de limpiar => ' . print_r($terms, true));

        if (!empty($terms)) {
            // Reemplazar todas las categorías
            wp_set_object_terms($product_id, $terms, 'product_cat', false);
            error_log('Asignadas categorías al producto ' . $product_id . ' => ' . implode(',', $terms));
        } else {
            // Eliminar todas las categorías sin meter Uncategorized
            wp_set_object_terms($product_id, [], 'product_cat', false);
            error_log('Sin categorías válidas, se eliminaron categorías en producto ' . $product_id);
        }

        // --- Imagen destacada (ONLY basic_picture, NO agregarla a la galería) ---
        if ( ! empty($prod['basic_picture']) ) {
            // set_product_image retorna attachment_id y asigna como thumbnail al post pasado
            $thumb_id = $this->set_product_image($product_id, $prod['basic_picture']);
            if ($thumb_id) {
                // Aseguramos que la imagen destacada es la basic_picture (pero NO la añadimos a la galería)
                set_post_thumbnail($product_id, $thumb_id);
            }
        }

        // --- Inicializar galería para acumular imágenes nuevas (solo imágenes de variantes) ---
        $gallery_ids = get_post_meta( $product_id, '_product_image_gallery', true );
        $gallery_ids = $gallery_ids ? explode(',', $gallery_ids) : [];

        // Filtrar solo IDs que existan en la base de datos
        $gallery_ids = array_filter($gallery_ids, function($id) {
            return get_post_status($id) !== false; // Si no existe, lo elimina del array
        });

        // Guardar de nuevo los IDs limpios
        update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));

        // --- Si tiene colores: atributos + variaciones ---
        if ( !empty($prod['colors']) && is_array($prod['colors']) ) {
            $taxonomy = 'pa_color';
            if ( ! taxonomy_exists($taxonomy) ) {
                register_taxonomy(
                    $taxonomy,
                    'product',
                    [
                        'label' => 'Color',
                        'rewrite' => ['slug' => 'color'],
                        'hierarchical' => false,
                    ]
                );
            }

            $names = [];
            foreach ($prod['colors'] as $c) {
                $names[] = sanitize_text_field($c['color']);
            }

            wp_set_object_terms($product_id, $names, $taxonomy);

            $attribute_data[$taxonomy] = [
                'name' => $taxonomy,
                'value' => implode('|', $names),
                'position' => 0,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1
            ];
            update_post_meta($product_id, '_product_attributes', $attribute_data);

            // --- Crear variaciones: pasar $gallery_ids por referencia para acumular ahí las imágenes ---
            foreach ($prod['colors'] as $c) {
                $variation_id = $this->create_or_update_variation($product_id, $taxonomy, $c, $gallery_ids);
                if ($variation_id) {
                    error_log("Variación creada/actualizada: {$c['color']} (ID: $variation_id)");
                }
            }
        }

        // --- Normalizar y guardar la galería final (sin duplicados y sin la featured) ---
        $gallery_ids = array_map('intval', $gallery_ids);
        // Quitar thumbnail si por alguna razón está en la lista (queremos que basic_picture NO esté en la galería)
        $featured = get_post_thumbnail_id($product_id);
        if ($featured) {
            $gallery_ids = array_filter($gallery_ids, function($id) use ($featured) {
                return intval($id) !== intval($featured);
            });
        }
        $gallery_ids = array_unique(array_filter($gallery_ids));
        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        } else {
            delete_post_meta($product_id, '_product_image_gallery');
        }

        return true;
    }

    /**
     * Crear o actualizar variación.
     */
    private function create_or_update_variation($product_id, $taxonomy, $color, &$gallery_ids) {
        $args = [
            'post_type'   => 'product_variation',
            'post_parent' => $product_id,
            'meta_query'  => [
                [
                    'key'   => 'attribute_' . $taxonomy,
                    'value' => sanitize_title($color['color'])
                ]
            ]
        ];
        $existing = get_posts($args);

        if ($existing) {
            $variation_id = $existing[0]->ID;
            $variation = new WC_Product_Variation($variation_id);
        } else {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
        }

        // --- Atributos ---
        $variation->set_attributes([
            $taxonomy => sanitize_title($color['color'])
        ]);

        // --- Precio (dejar siempre en 0, como tu versión original) ---
        $variation->set_regular_price(0);

        // --- Stock ---
        if (isset($color['stock'])) {
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity(intval($color['stock']));
            $variation->set_stock_status((intval($color['stock']) > 0) ? 'instock' : 'outofstock');
        } else {
            $variation->set_manage_stock(false);
            $variation->set_stock_status('instock'); // o 'outofstock', según prefieras
        }

        $variation_id = $variation->save();

        // --- Imagen ---
        if (!empty($color['images'][0])) {
            $image_url = $color['images'][0];

            // Usar la misma lógica de descarga/asignación que en productos
            $image_id = $this->set_product_image($variation_id, $image_url);

            if ($image_id && !is_wp_error($image_id)) {
                set_post_thumbnail($variation_id, $image_id);

                // Agregar a galería padre
                $gallery_ids = array_map('intval', $gallery_ids);
                if (!in_array(intval($image_id), $gallery_ids, true)) {
                    $gallery_ids[] = intval($image_id);
                }
            }
        }

        return $variation_id;
    }


    private function set_product_image($post_id, $url) {
        if (empty($url)) return 0;

        global $wpdb;

        // --- Buscar si ya existe attachment con esa URL ---
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta 
            WHERE meta_key = '_beststock_image' 
            AND meta_value = %s 
            LIMIT 1",
            $url
        ));

        if ($existing_id) {
            set_post_thumbnail($post_id, (int) $existing_id);
            return (int) $existing_id;
        }

        // --- Incluir dependencias ---
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // --- Descargar imagen temporal con reintentos ---
        $max_retries = 3;
        $retry = 0;
        do {
            $tmp = download_url($url);
            if (is_wp_error($tmp)) {
                $error_msg = $tmp->get_error_message();
                if (strpos($error_msg, 'Too Many Requests') !== false) {
                    $retry++;
                    error_log("⚠ Too Many Requests. Reintentando $retry/$max_retries para $url");
                    sleep(2); // esperar 2 segundos antes de reintentar
                } else {
                    error_log("❌ Error descargando imagen: $url → " . $error_msg);
                    return 0;
                }
            }
        } while (is_wp_error($tmp) && $retry < $max_retries);

        if (is_wp_error($tmp)) {
            error_log("❌ No se pudo descargar la imagen después de $max_retries intentos: $url");
            return 0;
        } else {
            error_log("✅ Imagen descargada correctamente en: $tmp (URL: $url)");
        }

        // --- Preparar array del archivo ---
        $file_array = [
            'name'     => basename($url),
            'type'     => mime_content_type($tmp),
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize($tmp),
        ];

        $overrides = ['test_form' => false];
        $results   = wp_handle_sideload($file_array, $overrides);

        if (isset($results['error'])) {
            @unlink($tmp);
            error_log("❌ Error procesando imagen: $url → " . $results['error']);
            return 0;
        } else {
            error_log("✅ Imagen procesada: " . print_r($results, true));
        }

        // --- Crear attachment ---
        $attachment = [
            'post_mime_type' => $results['type'],
            'post_title'     => sanitize_file_name($results['file']),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $url, // guardamos la URL original
        ];

        $attach_id = wp_insert_attachment($attachment, $results['file'], $post_id);

        if (!is_wp_error($attach_id)) {
            $attach_data = wp_generate_attachment_metadata($attach_id, $results['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);

            // --- Asignar como imagen destacada ---
            set_post_thumbnail($post_id, $attach_id);

            // --- Guardar URL de origen ---
            update_post_meta($attach_id, '_beststock_image', $url);

            return $attach_id;
        }

        return 0;
    }

}
