<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Price_Brand_Percentage {

    public function __construct() {
        add_action('wp_ajax_marpico_aplicar_aumento_marca', [ $this, 'ajax_aplicar_aumento_marca' ]);
    }

    public function ajax_aplicar_aumento_marca() {

        error_log('Marpico_Price_Brand_Percentage -> AJAX ejecutado');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos suficientes.');
        }

        check_ajax_referer('marpico_sync_nonce', 'security');

        $porcentaje = floatval($_POST['porcentaje'] ?? 0);
        $marca      = sanitize_text_field($_POST['marca'] ?? '');

        $excluidas  = isset($_POST['categoriasExcluidas']) ? array_map('intval', (array)$_POST['categoriasExcluidas']) : [];
        $marcas_excluidas = isset($_POST['marcasExcluidas']) ? array_map('intval', (array)$_POST['marcasExcluidas']) : [];

        if ($porcentaje === 0) {
            wp_send_json_error('El porcentaje no puede ser 0.');
        }

        if (empty($marca)) {
            wp_send_json_error('Marca no válida.');
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_brand',
                    'field'    => 'term_id',
                    'terms'    => intval($marca),
                ]
            ],
        ];

        error_log(print_r($args, true));
        error_log("Marca recibida: " . $marca);

        $productos = get_posts($args);

        error_log('Productos encontrados para marca ' . $marca . ': ' . count($productos));

        foreach ($productos as $product_id) {

            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            $product_brands = wp_get_post_terms($product_id, 'product_brand', ['fields' => 'ids']);
            
            // Saltar si pertenece a categoría excluida
            if (!empty($excluidas) && array_intersect($product_cats, $excluidas)) {
                error_log("Producto {$product_id} excluido por categoría");
                continue;
            }

            // Saltar si pertenece a marca excluida
            if (!empty($marcas_excluidas) && array_intersect($product_brands, $marcas_excluidas)) {
                error_log("Producto {$product_id} excluido por marca");
                continue;
            }

            // PRODUCTO SIMPLE
            if ($product->is_type('simple')) {

                $precio_actual = (float) $product->get_regular_price();

                if ($precio_actual <= 0) continue;

                $nuevo_precio = $precio_actual + ($precio_actual * ($porcentaje / 100));

                $product->set_regular_price($nuevo_precio);
                $product->save();

                error_log("Producto simple actualizado: ID {$product_id} -> {$nuevo_precio}");
            }

            // PRODUCTO VARIABLE
            elseif ($product->is_type('variable')) {

                $variaciones = $product->get_children();

                foreach ($variaciones as $variacion_id) {

                    $variacion = wc_get_product($variacion_id);

                    if (!$variacion) continue;

                    $precio_actual = (float) $variacion->get_regular_price();

                    if ($precio_actual <= 0) continue;

                    $nuevo_precio = $precio_actual + ($precio_actual * ($porcentaje / 100));

                    $variacion->set_regular_price($nuevo_precio);
                    $variacion->save();

                    error_log("Variación actualizada: ID {$variacion_id} -> {$nuevo_precio}");
                }

                $product->variable_product_sync();
                $product->save();

                error_log("Producto variable sincronizado: ID {$product_id}");
            }
        }

        wp_send_json_success('Precios actualizados por porcentaje correctamente.');
    }
}