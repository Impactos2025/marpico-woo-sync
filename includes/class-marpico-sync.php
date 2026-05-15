<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Sync {

    private $client;

    public function __construct() {
        $this->client = new Marpico_Client();
    }

    public function sync_product_by_family( $family_code ) {
        $res = $this->client->get_product_by_family( $family_code );
        if ( is_wp_error( $res ) ) return $res;

        if ( empty( $res ) || ! is_array( $res ) ) {
            return new WP_Error( 'no_data', 'No se encontraron datos para la familia ' . $family_code );
        }

        $materials = $res;
        $first = $materials[0];

        $family   = $first['familia'] ?? $family_code;
        $title    = $first['descripcion_comercial'] ?? 'Producto ' . $family;
        $content  = $first['descripcion_larga'] ?? '';
        $category = $first['subcategoria_1']['nombre_categoria'] ?? '';
        $gallery_urls = $first['imagenes'] ?? [];

        // Mapeo de códigos a nombres de temas
        $temas_map = [
            '1'  => 'Día de San Valentín',
            '2'  => 'Día de la mujer',
            '3'  => 'Dia del hombre',
            '4'  => 'Mes de los niños',
            '5'  => 'Dia de la tierra',
            '6'  => 'Mes de la madre',
            '7'  => 'Mes del padre',
            '8'  => 'Independencia',
            '9'  => 'Amor y Amistad',
            '10' => 'Halloween',
            '11' => 'Navidad',
            '13' => 'Elecciones',
            '14' => 'Mundial',
        ];

        $etiquetas_map = [
            '4'  => 'Netos',
            '37'  => 'Outlet Color',
            '35'  => 'Outlet',
        ];

        // Buscar producto existente por meta
        $product_id = $this->find_product_by_family( $family );

        if ( $product_id ) {
            wp_update_post( [
                'ID'           => $product_id,
                'post_title'   => wp_strip_all_tags( $title ),
                'post_content' => wp_kses_post( $content ),
            ] );
            $product = wc_get_product( $product_id );
        } else {
            $post_id = wp_insert_post( [
                'post_title'   => wp_strip_all_tags( $title ),
                'post_content' => wp_kses_post( $content ),
                'post_status'  => 'publish',
                'post_type'    => 'product',
            ] );
            if ( ! $post_id || is_wp_error( $post_id ) ) {
                return new WP_Error( 'insert_failed', 'No se pudo crear el producto padre.' );
            }
            $product = wc_get_product( $post_id );
            update_post_meta( $post_id, '_external_family', $family );
            update_post_meta( $post_id, '_sku', $family );
            $product_id = $post_id;
        }

        $parent_id = $product->get_id();

        // === GUARDAR ESPECIFICACIONES DEL PRODUCTO ===
        // Lista de claves que quieres sincronizar
        $spec_keys = [
            'material',
            'empaque_individual',
            'empaque_unds_caja',
            'empaque_unds_medida',
            'empaque_largo',
            'empaque_ancho',
            'empaque_alto',
            'empaque_peso_neto',
            'empaque_peso_bruto',
            'area_impresion',
            'medidas_largo',
            'medidas_ancho',
            'medidas_alto',
            'medidas_diametro',
            'medidas_peso_neto',
            'tecnica_marca_codigo',
            'tecnica_marca_tecnica',
            'tecnica_marca_precio',
            'tecnica_marca_num_tintas',
            'tecnica_marca_descripcion'
        ];

        foreach ( $spec_keys as $key ) {
            if ( array_key_exists( $key, $first ) ) {

                $value = $first[ $key ];

                if ( $value === null || $value === '' ) {
                    $value = 'N/A';
                }

                update_post_meta( $product_id, "_marpico_{$key}", $value );
                $this->log( "GUARDADO: _marpico_{$key} => {$value}" );
            } else {

                $this->log( "CLAVE AUSENTE: {$key} (no se sobrescribe)" );
            }
        }


        // Images: subir solo la primera como featured (solo si no existe)
        if ( ! empty( $gallery_urls ) && is_array( $gallery_urls ) ) {
            $first_url = $gallery_urls[0]; // tomamos solo la primera

            // Si el producto no tiene imagen destacada, intentamos asignarla
            if ( ! get_post_thumbnail_id( $product_id ) ) {
                global $wpdb;
                $existing_att = $wpdb->get_var( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s LIMIT 1",
                    $first_url
                ) );

                if ( $existing_att ) {
                    // Reutilizar attachment existente
                    set_post_thumbnail( $product_id, intval( $existing_att ) );
                } else {
                    $att_id = $this->download_and_attach_image( $first_url, $product_id );
                    if ( $att_id ) {
                        set_post_thumbnail( $product_id, $att_id );
                    }
                }
            } else {
                $this->log( "Producto {$product_id} ya tiene imagen destacada, no se reemplaza." );
            }
        }

        // Asignar categoría (padre e hijo)
        if ( $category ) {
            $term_id = 0;

            // Padre
            $parent_slug = sanitize_title( $category );
            $parent_term = get_term_by( 'slug', $parent_slug, 'product_cat' );
            if ( ! $parent_term ) {
                $newt = wp_insert_term( $category, 'product_cat', [ 'slug' => $parent_slug ] );
                if ( ! is_wp_error( $newt ) && isset( $newt['term_id'] ) ) {
                    $parent_id = intval( $newt['term_id'] );
                }
            } else {
                $parent_id = intval( $parent_term->term_id );
            }

            // Hija
            $child_name = $first['subcategoria_1']['nombre'] ?? '';
            if ( $child_name ) {
                $child_slug = sanitize_title( $child_name );
                $child_term = get_term_by( 'slug', $child_slug, 'product_cat' );
                if ( ! $child_term ) {
                    $child_insert = wp_insert_term( $child_name, 'product_cat', [ 'slug' => $child_slug, 'parent' => ( $parent_id ?? 0 ) ] );
                    if ( ! is_wp_error( $child_insert ) && isset( $child_insert['term_id'] ) ) {
                        $term_id = intval( $child_insert['term_id'] );
                    }
                } else {
                    $term_id = intval( $child_term->term_id );
                    // Asegurar que la relación padre -> hijo sea correcta
                    if ( isset( $parent_id ) && $child_term->parent != $parent_id ) {
                        wp_update_term( $term_id, 'product_cat', [ 'parent' => $parent_id ] );
                    }
                }
            } else {
                // Si no hay hija, asignar solo padre
                if ( isset( $parent_id ) ) $term_id = $parent_id;
            }

            if ( ! empty( $term_id ) ) {
                $assign_ids = [$term_id];

                // Si hay padre y no es el mismo que la hija, también lo agregamos
                if ( ! empty( $parent_id ) && $parent_id !== $term_id ) {
                    $assign_ids[] = $parent_id;
                }

                wp_set_object_terms( $product_id, $assign_ids, 'product_cat' );
            }
        }

        // Asignar etiquetas (product_tag) desde "temas"
        if ( !empty($first['temas']) && is_array($first['temas']) ) {
            $temas_raw = $first['temas'][0]; // viene como "2|3|6|7|9|10|11"
            $temas_ids = explode('|', $temas_raw);

            $tag_names = [];
            foreach ( $temas_ids as $tema_id ) {
                $tema_id = trim($tema_id);
                if ( isset($temas_map[$tema_id]) ) {
                    $tag_names[] = $temas_map[$tema_id];
                }
            }

            if ( !empty($tag_names) ) {
                // Filtrar duplicados
                $tag_names = array_unique($tag_names);

                // Asegurarnos de que las etiquetas existan (si no, crearlas)
                foreach ( $tag_names as $tag_name ) {
                    if ( !term_exists( $tag_name, 'product_tag' ) ) {
                        wp_insert_term( $tag_name, 'product_tag' );
                    }
                }

                // Asignar etiquetas al producto
                wp_set_object_terms( $product_id, $tag_names, 'product_tag', true );
                $this->log( "Etiquetas asignadas al producto {$product_id}: " . implode(', ', $tag_names) );
            }
        }

        // Asignar etiquetas desde "etiquetas"
        if ( !empty($first['etiquetas']) && is_array($first['etiquetas']) ) {
            $etiquetas_ids = array_column($first['etiquetas'], 'id');

            $tag_names_etiquetas = [];
            foreach ( $etiquetas_ids as $etiqueta_id ) {
                $etiqueta_id = trim($etiqueta_id);
                if ( isset($etiquetas_map[$etiqueta_id]) ) {
                    $tag_names_etiquetas[] = $etiquetas_map[$etiqueta_id];
                }
            }

            if ( !empty($tag_names_etiquetas) ) {
                // Filtrar duplicados
                $tag_names_etiquetas = array_unique($tag_names_etiquetas);

                // Asegurarnos de que las etiquetas existan (si no, crearlas)
                foreach ( $tag_names_etiquetas as $tag_name_etiqueta ) {
                    if ( !term_exists( $tag_name_etiqueta, 'product_tag' ) ) {
                        wp_insert_term( $tag_name_etiqueta, 'product_tag' );
                    }
                }

                // Asignar etiquetas al producto
                wp_set_object_terms( $product_id, $tag_names_etiquetas, 'product_tag', true );
                $this->log( "Etiquetas asignadas al producto {$product_id}: " . implode(', ', $tag_names_etiquetas) );
            }
        }

        // Marcar como variable
        wp_set_object_terms( $product_id, 'variable', 'product_type' );

        // Asignar marca fija
        $this->assign_fixed_brand_to_product($product_id);

        $this->sync_product_variations_optimized( $product_id, $first, $title );

        wc_delete_product_transients( $product_id );

        $this->log( "Sincronizado producto familia {$family} (post_id: {$product_id})" );
        return $product_id;
    }

    private function sync_product_variations_optimized( $product_id, $first, $title ) {
        // ---- CREAR ATRIBUTO GLOBAL pa_color ----
        $attribute_slug = 'pa_color';
        if ( ! taxonomy_exists( $attribute_slug ) ) {
            error_log("Creando atributo global 'Color'...");
            wc_create_attribute( [
                'slug'         => 'color',
                'name'         => 'Color',
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ] );
            register_taxonomy(
                $attribute_slug,
                'product',
                [ 'hierarchical' => false, 'label' => 'Color' ]
            );
        }

        // Asignar atributo al producto padre
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

        //Inicializar galería para acumular imágenes nuevas
        $gallery_ids = get_post_meta( $product_id, '_product_image_gallery', true );
        $gallery_ids = $gallery_ids ? explode(',', $gallery_ids) : [];

        // Procesar datos de la API
        if ( isset($first['materiales']) && is_array($first['materiales']) ) {
            foreach ( $first['materiales'] as $mat ) {
                $color_name = isset($mat['color_nombre']) ? trim((string)$mat['color_nombre']) : '';
                
                if ( $color_name === '' ) {
                    error_log("Marpico Sync: una variación no tiene 'color_nombre' — se omite.");
                    continue;
                }

                $colors_to_keep[] = $color_name;
                
                // Datos de la nueva variación
                $variation_data = [
                    'color' => $color_name,
                    'sku' => isset($mat['codigo']) ? sanitize_text_field((string)$mat['codigo']) : '',
                    'price' => isset($mat['precio']) ? (string)$mat['precio'] : '0',
                    'stock' => isset($mat['inventario_almacen'][0]['cantidad']) ? intval($mat['inventario_almacen'][0]['cantidad']) : 0,
                    'image' => ( ! empty($mat['imagenes']) && is_array($mat['imagenes']) ) ? $mat['imagenes'][0] : '',
                ];
                
                $new_variations_data[$color_name] = $variation_data;
            }
        }

        foreach ( $existing_variations as $color => $existing_data ) {
            if ( !in_array($color, $colors_to_keep) ) {
                wp_delete_post( $existing_data['variation_id'], true );
                error_log("Marpico Sync: Eliminada variación obsoleta: {$color}");
            }
        }

        foreach ( $new_variations_data as $color_name => $variation_data ) {
            $needs_update = false;
            $variation_id = null;

            // Verificar si la variación existe y si ha cambiado
            if ( isset($existing_variations[$color_name]) ) {
                $existing = $existing_variations[$color_name];
                $variation_id = $existing['variation_id'];
                
                // Comparar datos para ver si necesita actualización
                if ( $existing['sku'] !== $variation_data['sku'] ||
                     $existing['price'] !== $variation_data['price'] ||
                     $existing['stock'] !== $variation_data['stock'] ||
                    ( ! empty($variation_data['image']) && $existing['image_url'] !== $variation_data['image'] )
                    ) {
                    $needs_update = true;
                }
            } else {
                // Variación nueva, necesita crearse
                $needs_update = true;
            }

            if ( $needs_update ) {
                // Crear/actualizar término de color
                $term = term_exists( $color_name, $attribute_slug );
                if ( $term === 0 || $term === null ) {
                    $t = wp_insert_term( $color_name, $attribute_slug, [ 'slug' => sanitize_title( $color_name ) ] );
                    if ( is_wp_error( $t ) ) {
                        error_log("Marpico Sync: error creando término color: " . $t->get_error_message());
                        continue;
                    } else {
                        $term_id = is_array( $t ) && isset( $t['term_id'] ) ? intval( $t['term_id'] ) : intval( $t );
                    }
                } else {
                    $term_id = is_array( $term ) && isset( $term['term_id'] ) ? intval( $term['term_id'] ) : intval( $term );
                }

                if ( $term_id ) {
                    wp_set_object_terms( $product_id, intval( $term_id ), $attribute_slug, true );
                }

                if ( !$variation_id ) {
                    // Crear nueva variación
                    $variation_id = wp_insert_post( [
                        'post_title'   => $title . ' - ' . $color_name,
                        'post_name'    => 'product-' . $product_id . '-variation-' . sanitize_title($color_name),
                        'post_status'  => 'publish',
                        'post_parent'  => $product_id,
                        'post_type'    => 'product_variation',
                        'menu_order'   => 0,
                    ] );
                    
                    if ( is_wp_error( $variation_id ) ) {
                        error_log("Marpico Sync: Error creando variación: " . $variation_id->get_error_message());
                        continue;
                    }
                    
                    error_log("Marpico Sync: Nueva variación creada ID {$variation_id} con color {$color_name}");
                } else {
                    error_log("Marpico Sync: Actualizando variación existente ID {$variation_id} con color {$color_name}");
                }

                // Actualizar meta datos de la variación
                update_post_meta( $variation_id, 'attribute_' . $attribute_slug, sanitize_title( $color_name ) );
                update_post_meta( $variation_id, '_sku', $variation_data['sku'] );
                update_post_meta( $variation_id, '_regular_price', $variation_data['price'] );
                update_post_meta( $variation_id, '_price', $variation_data['price'] );
                update_post_meta( $variation_id, '_stock', $variation_data['stock'] );
                update_post_meta( $variation_id, '_manage_stock', 'yes' );
                update_post_meta( $variation_id, '_stock_status', ( $variation_data['stock'] > 0 ? 'instock' : 'outofstock' ) );
                
                if ( ! empty( $variation_data['image'] ) ) {
                    // Solo asignar imagen si la variación NO tiene _thumbnail_id
                    $current_thumb = get_post_meta( $variation_id, '_thumbnail_id', true );
                    if ( ! $current_thumb ) {
                        global $wpdb;
                        $existing_att = $wpdb->get_var( $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s LIMIT 1",
                            $variation_data['image']
                        ) );

                        if ( $existing_att ) {
                            update_post_meta( $variation_id, '_thumbnail_id', intval( $existing_att ) );
                            if ( ! in_array( intval( $existing_att ), $gallery_ids ) ) {
                                $gallery_ids[] = intval( $existing_att );
                            }
                        } else {
                            $attachment_id = $this->download_and_attach_image( $variation_data['image'], $variation_id );
                            if ( $attachment_id ) {
                                update_post_meta( $variation_id, '_thumbnail_id', $attachment_id );
                                if ( ! in_array( $attachment_id, $gallery_ids ) ) {
                                    $gallery_ids[] = $attachment_id;
                                }
                            }
                        }
                    } else {
                        $this->log( "Marpico Sync: Variación {$variation_id} ya tiene imagen, no se reemplaza." );
                    }
                }

            } else {
                error_log("Marpico Sync: Variación {$color_name} sin cambios, se mantiene igual");
            }
        }
        // Guardar la galería una sola vez, sin duplicados
        $gallery_ids = array_unique( $gallery_ids );
        update_post_meta( $product_id, '_product_image_gallery', implode(',', $gallery_ids) );
        
    }

    private function get_existing_variations_data( $product_id ) {
        $variations_data = [];
        
        $args = [
            'post_parent' => $product_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'post_status' => [ 'publish', 'private', 'draft' ],
        ];
        
        $variations = get_posts( $args );
        
        foreach ( $variations as $variation ) {
            $color_slug = get_post_meta( $variation->ID, 'attribute_pa_color', true );
            if ( $color_slug ) {
                // Obtener el nombre del color desde el término
                $term = get_term_by( 'slug', $color_slug, 'pa_color' );
                $color_name = $term ? $term->name : $color_slug;

                $image_id = get_post_meta( $variation->ID, '_thumbnail_id', true );
                
                $image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

                $variations_data[$color_name] = [
                    'variation_id' => $variation->ID,
                    'sku' => get_post_meta( $variation->ID, '_sku', true ),
                    'price' => get_post_meta( $variation->ID, '_regular_price', true ),
                    'stock' => intval( get_post_meta( $variation->ID, '_stock', true ) ),
                    'image_id'  => $image_id,
                ];
            }
        }
        
        return $variations_data;
    }

    public function sync_all_products( $offset = 0, $batch_size = 20 ) {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 300);
        
        $res = $this->client->get_all_products_unlimited();
        if ( is_wp_error( $res ) ) return $res;

        if ( empty($res) || ! is_array($res) ) {
            return new WP_Error( 'no_data', 'El endpoint no devolvió productos.' );
        }

        $total_products = count($res);
        $subset = array_slice($res, $offset, $batch_size);

        $results = [];
        $processed = 0;
        $successful = 0;
        $failed = 0;
        $errors = [];
        
        foreach ( $subset as $prod ) {
            $family_code = $prod['familia'] ?? null;
            if ( ! $family_code ) {
                $failed++;
                $errors[] = "Producto sin código de familia";
                continue;
            }

            try {
                $result = $this->sync_product_by_family( $family_code );
                
                if ( is_wp_error( $result ) ) {
                    $failed++;
                    $errors[] = "Familia {$family_code}: " . $result->get_error_message();
                    $results[] = ['family' => $family_code, 'status' => 'error', 'message' => $result->get_error_message()];
                } else {
                    $successful++;
                    $results[] = ['family' => $family_code, 'status' => 'success', 'product_id' => $result];
                    $this->log( "✓ Producto familia {$family_code} sincronizado exitosamente (ID: {$result})" );
                }
                
            } catch ( Exception $e ) {
                $failed++;
                $error_msg = "Error inesperado en familia {$family_code}: " . $e->getMessage();
                $errors[] = $error_msg;
                $results[] = ['family' => $family_code, 'status' => 'error', 'message' => $error_msg];
                $this->log( "✗ Error en familia {$family_code}: " . $e->getMessage() );
            }
            
            $processed++;
        }

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
            'errors' => $errors
        ];
    }

    private function find_product_by_family( $family ) {
        global $wpdb;

        // Buscar por _external_family
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_external_family' AND meta_value = %s",
            $family
        ) );
        if ( ! empty( $rows ) ) {
            return intval( $rows[0] );
        }

        // Si no existe, buscar por SKU
        $product_id = wc_get_product_id_by_sku( $family );
        if ( $product_id ) {
            return $product_id;
        }

        return 0;
    }

    private function assign_fixed_brand_to_product($product_id) {

        error_log('Marca ejecutándose para producto: '.$product_id);

        if(!$product_id) return;

        $taxonomy = 'product_brand';
        $brand_name = 'Marpico';

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

    private function remove_product_variations( $product_id ) {
        $args = [
            'post_parent' => $product_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'post_status' => [ 'publish', 'private', 'draft' ],
        ];
        $variations = get_posts( $args );
        foreach ( $variations as $v ) {
            wp_delete_post( $v->ID, true );
        }
    }

    private function sideload_image_from_url($image_url, $post_id) {
        if ( ! function_exists('media_sideload_image') ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Sideload
        $media = media_sideload_image($image_url, $post_id, null, 'id');

        if ( is_wp_error($media) ) {
            error_log("Marpico Sync: error al sideload de imagen {$image_url} -> " . $media->get_error_message());
            return false;
        }

        return $media; // attachment_id
    }

    private function download_and_attach_image( $image_url, $parent_id ) {
        if ( empty( $image_url ) ) return 0;

        global $wpdb;

        // Buscar si ya existe un attachment con esa URL en la biblioteca
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts 
            WHERE post_type = 'attachment' 
            AND guid = %s 
            LIMIT 1",
            $image_url
        ) );

        if ( $existing_id ) {
            wp_update_post([
                'ID'          => (int) $existing_id,
                'post_parent' => $parent_id,
            ]);
            return (int) $existing_id; // Reutilizar attachment existente
        }

        // --- Si no existe, descargar y crear ---
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Descarga temporal
        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            error_log("Error descargando imagen: " . $tmp->get_error_message());
            return 0;
        }

        // Preparar array para wp_handle_sideload
        $file = [
            'name'     => basename( $image_url ),
            'type'     => mime_content_type( $tmp ),
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize( $tmp ),
        ];

        $overrides = [ 'test_form' => false ];
        $results   = wp_handle_sideload( $file, $overrides );

        if ( isset( $results['error'] ) ) {
            @unlink( $tmp );
            error_log("Error procesando imagen: " . $results['error']);
            return 0;
        }

        // Crear attachment en WP
        $attachment = [
            'post_mime_type' => $results['type'],
            'post_title'     => sanitize_file_name( $results['file'] ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $image_url, //Guardamos la URL original en el guid
        ];

        $attach_id = wp_insert_attachment( $attachment, $results['file'], $parent_id );
        if ( ! is_wp_error( $attach_id ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata( $attach_id, $results['file'] );
            wp_update_attachment_metadata( $attach_id, $attach_data );
            return $attach_id;
        }

        return 0;
    }
    
    private function log( $message ) {
        $log = get_option( 'marpico_sync_log', [] );
        $log[] = '[' . current_time('mysql') . '] ' . $message;
        if ( count( $log ) > 200 ) $log = array_slice( $log, -200 );
        update_option( 'marpico_sync_log', $log );
    }
}
