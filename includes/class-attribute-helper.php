<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente
}

class Attributes_Handler {

    // Verifica si existe el atributo, si no lo crea
    public static function ensure_attribute_exists( $attribute_name, $attribute_label ) {
        global $wpdb;

        $attribute_name = wc_sanitize_taxonomy_name( $attribute_name );
        $attribute_label = ucfirst( $attribute_label );

        // Verificar si ya existe
        $attribute = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
                $attribute_name
            )
        );

        if ( ! $attribute ) {
            // Crear atributo
            $wpdb->insert(
                "{$wpdb->prefix}woocommerce_attribute_taxonomies",
                [
                    'attribute_label'   => $attribute_label,
                    'attribute_name'    => $attribute_name,
                    'attribute_type'    => 'select',
                    'attribute_orderby' => 'menu_order',
                    'attribute_public'  => 0,
                ]
            );

            // Actualizar transients
            delete_transient( 'wc_attribute_taxonomies' );
            wc_create_attribute_taxonomies();
        }

        return wc_attribute_taxonomy_name( $attribute_name );
    }

    // Asegura que un término exista dentro de un atributo
    public static function ensure_term_exists( $taxonomy, $term_name ) {
        if ( ! term_exists( $term_name, $taxonomy ) ) {
            wp_insert_term( $term_name, $taxonomy );
        }
    }
}
