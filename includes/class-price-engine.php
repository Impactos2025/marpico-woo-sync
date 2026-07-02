<?php
/**
 * Motor de ajuste de precios.
 *
 * Config persistida (opción 'marpico_price_config') que se aplica:
 *  - automáticamente al final de cada sincronización (Marpico/CDO/BestStock),
 *  - y bajo demanda ("Aplicar ahora"), que corre como job en segundo plano
 *    reutilizando Marpico_Sync_Job (kind='price').
 *
 * Reglas:
 *  - MONTO fijo: a todas las marcas, salvo categorías excluidas.
 *  - PORCENTAJE: solo a marcas con precio de distribuidor (marcas_porcentaje).
 *  - EXCLUIR MARCAS: productos manuales; nunca se tocan.
 *  - Orden: % primero (valor de venta), luego monto (marcación).
 *
 * Idempotencia: el precio de venta se calcula SIEMPRE desde el precio base del
 * API, guardado por la sync en el meta `_marpico_base_price`. Sin ese meta el
 * producto se considera manual y no se toca.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Price_Engine {

    const OPTION    = 'marpico_price_config';
    const META_BASE = '_marpico_base_price';

    /* ===================== Config ===================== */

    public static function defaults() {
        return [
            'monto'             => 0.0,
            'excluir_cats'      => [],
            'porcentaje'        => 0.0,
            'marcas_porcentaje' => [],
            'excluir_marcas'    => [],
        ];
    }

    public static function get() {
        $c = get_option( self::OPTION, [] );
        return wp_parse_args( is_array( $c ) ? $c : [], self::defaults() );
    }

    public static function sanitize( $raw ) {
        $raw = is_array( $raw ) ? $raw : [];
        return [
            'monto'             => isset( $raw['monto'] ) ? (float) $raw['monto'] : 0.0,
            'porcentaje'        => isset( $raw['porcentaje'] ) ? (float) $raw['porcentaje'] : 0.0,
            'excluir_cats'      => self::int_list( $raw['excluir_cats'] ?? [] ),
            'marcas_porcentaje' => self::int_list( $raw['marcas_porcentaje'] ?? [] ),
            'excluir_marcas'    => self::int_list( $raw['excluir_marcas'] ?? [] ),
        ];
    }

    public static function save( $raw ) {
        $clean = self::sanitize( $raw );
        update_option( self::OPTION, $clean, false );
        return $clean;
    }

    private static function int_list( $v ) {
        if ( ! is_array( $v ) ) {
            $v = ( strlen( (string) $v ) ) ? explode( ',', (string) $v ) : [];
        }
        return array_values( array_unique( array_filter( array_map( 'intval', $v ) ) ) );
    }

    /* ===================== Fórmula (pura) ===================== */

    /**
     * Calcula el precio de venta desde el precio base del API.
     *
     * @param float $base       Precio base (precio API).
     * @param int[] $brand_ids  Marcas (product_brand) del producto.
     * @param int[] $cat_ids    Categorías (product_cat) del producto.
     * @param array $cfg        Config (opcional; por defecto la guardada).
     * @return float
     */
    public static function compute( $base, $brand_ids, $cat_ids, $cfg = null ) {
        $cfg  = $cfg ? $cfg : self::get();
        $base = (float) $base;

        // Marca excluida → producto manual, intacto (no debería llegar aquí).
        if ( array_intersect( $brand_ids, $cfg['excluir_marcas'] ) ) {
            return $base;
        }

        $p = $base;

        // 1) Porcentaje (marcas con precio de distribuidor) → valor de venta.
        if ( (float) $cfg['porcentaje'] != 0.0 && array_intersect( $brand_ids, $cfg['marcas_porcentaje'] ) ) {
            $p = $p * ( 1 + ( (float) $cfg['porcentaje'] / 100 ) );
        }

        // 2) Monto fijo (todas las marcas), salvo categoría excluida → marcación.
        if ( (float) $cfg['monto'] != 0.0 && ! array_intersect( $cat_ids, $cfg['excluir_cats'] ) ) {
            $p = $p + (float) $cfg['monto'];
        }

        return round( $p, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
    }

    /* ===================== Aplicación ===================== */

    /** Guarda el precio base del API en una variación/producto (lo llama la sync). */
    public static function seed_base( $id, $api_price ) {
        update_post_meta( $id, self::META_BASE, (float) $api_price );
    }

    /**
     * Aplica el ajuste a un producto (simple o variable) desde su base guardada.
     * Omite productos de marcas excluidas o sin base (manuales). Idempotente.
     *
     * @return bool true si cambió algún precio.
     */
    public static function apply_to_product( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return false;

        $cfg = self::get();

        $brand_ids = wp_get_post_terms( $product_id, 'product_brand', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $brand_ids ) ) $brand_ids = [];
        // Marca excluida → producto manual: nunca se toca.
        if ( array_intersect( $brand_ids, $cfg['excluir_marcas'] ) ) return false;

        $cat_ids = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $cat_ids ) ) $cat_ids = [];

        $ids     = $product->is_type( 'variable' ) ? $product->get_children() : [ $product_id ];
        $touched = false;

        foreach ( $ids as $vid ) {
            $base = get_post_meta( $vid, self::META_BASE, true );
            if ( $base === '' || $base === null ) continue; // sin base → manual → skip

            $final = self::compute( $base, $brand_ids, $cat_ids, $cfg );
            $obj   = ( $vid == $product_id ) ? $product : wc_get_product( $vid );
            if ( ! $obj ) continue;

            if ( (string) $obj->get_regular_price() !== (string) $final ) {
                $obj->set_regular_price( $final );
                $obj->save();
                $touched = true;
            }
        }

        if ( $touched ) {
            if ( $product->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
                WC_Product_Variable::sync( $product_id );
            }
            wc_delete_product_transients( $product_id );
        }

        return $touched;
    }
}
