<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Price {

    public function __construct() {
        add_action('wp_ajax_marpico_aplicar_aumento', [ $this, 'ajax_aplicar_aumento' ]);
    }

    public function ajax_aplicar_aumento() {
        error_log('Marpico_Price -> AJAX ejecutado correctamente');
        error_log('Marpico_Price::ajax_aplicar_aumento POST=' . print_r($_POST, true));

        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos suficientes.');
        }

        check_ajax_referer('marpico_sync_nonce', 'security');

        $incremento = floatval($_POST['incremento'] ?? 0); // monto a aumentar
        $marca_id   = intval($_POST['marca'] ?? 0);        // marca a la que aplicar
        $excluidas  = isset($_POST['categoriasExcluidas']) ? array_map('intval', (array)$_POST['categoriasExcluidas']) : [];

        if ($incremento <= 0) {
            wp_send_json_error('El incremento debe ser mayor a 0.');
        }

        if ($marca_id === 0) {
            wp_send_json_error('Debes seleccionar una marca.');
        }

        // Tax query para filtrar por marca y excluir categorías
        $tax_query = [
            [
                'taxonomy' => 'product_brand',
                'field'    => 'term_id',
                'terms'    => $marca_id,
                'operator' => 'IN',
            ]
        ];

        if (!empty($excluidas)) {
            $tax_query[] = [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $excluidas,
                'operator'         => 'NOT IN',
                'include_children' => false,
            ];
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => $tax_query,
        ];

        $productos = get_posts($args);
        error_log('Productos encontrados: ' . count($productos));

        foreach ($productos as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            // Revisar categorías explícitamente
            $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            if (array_intersect($product_cats, $excluidas)) {
                error_log("Producto ID {$product_id} excluido por categoría");
                continue;
            }

            // PRODUCTO SIMPLE
            if ($product->is_type('simple')) {
                $precio_actual = (float) $product->get_regular_price() ?: 0;
                $nuevo_precio  = $precio_actual + $incremento; // incremento por monto fijo
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

                    $precio_actual = (float) $variacion->get_regular_price() ?: 0;
                    $nuevo_precio  = $precio_actual + $incremento; // incremento por monto fijo
                    $variacion->set_regular_price($nuevo_precio);
                    $variacion->save();
                    error_log("Variación actualizada: ID {$variacion_id} -> {$nuevo_precio}");
                }

                $product->variable_product_sync();
                $product->save();
                error_log("Producto variable sincronizado: ID {$product_id}");
            }
        }

        wp_send_json_success('Precios actualizados correctamente.');
    }
}
