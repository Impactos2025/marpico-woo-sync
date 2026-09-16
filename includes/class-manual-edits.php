<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Protección de las ediciones manuales frente a la sincronización.
 *
 * El sincronizador registra en un meta el título que él mismo escribió. En la
 * siguiente pasada compara ese registro con el título actual del producto:
 *
 *  - Si coinciden, el título sigue siendo "del proveedor" y puede actualizarse.
 *  - Si difieren, alguien lo editó desde WordPress y NO se toca.
 *
 * En la primera pasada de cada producto todavía no hay registro, así que no se
 * puede distinguir lo manual de lo del proveedor: se aplica el título de la API
 * (el comportamiento de siempre) y se deja el registro sembrado. La protección
 * empieza a partir de la segunda pasada.
 *
 * Misma idea que Category_Mapper::assign_terms_preserving_manual() para las
 * categorías y marcas.
 */
class Manual_Edits {

    /** Meta donde se guarda el último título escrito por el sincronizador. */
    const META_SYNCED_TITLE = '_mws_synced_title';

    /**
     * ¿Puede el sincronizador escribir el título de este producto?
     *
     * @param int $product_id ID del producto (0 o vacío si aún no existe).
     * @return bool true si el título es del proveedor o es un producto nuevo.
     */
    public static function can_update_title( $product_id ) {
        $product_id = intval( $product_id );
        if ( ! $product_id ) {
            return true; // Producto nuevo: no hay nada que respetar.
        }

        $registrado = get_post_meta( $product_id, self::META_SYNCED_TITLE, true );
        if ( ! is_string( $registrado ) || '' === $registrado ) {
            return true; // Primera pasada: sin registro no se puede distinguir.
        }

        $actual = get_post_field( 'post_title', $product_id );

        return (string) $actual === $registrado;
    }

    /**
     * Red de seguridad para el slug (post_name) de un producto.
     *
     * WooCommerce escribe `post_name => $product->get_slug('edit')` en cada
     * guardado. Si el objeto llegara sin slug, se guardaría vacío y WordPress lo
     * regeneraría a partir del título: la URL del producto cambiaría y las
     * antiguas empezarían a dar 404.
     *
     * Esto no debería ocurrir con un objeto leído de la base de datos, pero la
     * comprobación es barata y evita que un cambio futuro rompa las URLs.
     *
     * @param WC_Product $product Producto a punto de guardarse.
     */
    public static function ensure_slug( $product ) {
        if ( ! $product instanceof WC_Product ) return;

        $product_id = $product->get_id();
        if ( ! $product_id ) return; // Producto nuevo: WordPress genera el slug.

        if ( '' !== (string) $product->get_slug( 'edit' ) ) return;

        $slug = get_post_field( 'post_name', $product_id );
        if ( $slug ) {
            $product->set_slug( $slug );
        }
    }

    /**
     * Registra el título que acaba de escribir el sincronizador.
     *
     * Sólo debe llamarse cuando el título se ha aplicado de verdad; si se
     * respetó una edición manual, el registro anterior se mantiene para que el
     * producto siga considerándose "editado a mano".
     *
     * @param int    $product_id ID del producto.
     * @param string $title      Título aplicado.
     */
    public static function record_title( $product_id, $title ) {
        $product_id = intval( $product_id );
        if ( ! $product_id ) return;

        update_post_meta( $product_id, self::META_SYNCED_TITLE, (string) $title );
    }
}
