<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Capa de mapeo de categorías multi-proveedor.
 *
 * Traduce las categorías crudas que entrega cada API (Marpico, CDO, ...) hacia
 * una taxonomía canónica única —la jerarquía de Marpico que alimenta el menú del
 * frontend—, de modo que productos de distintos proveedores caigan en la MISMA
 * categoría de WooCommerce y sean visibles bajo los mismos enlaces del menú.
 *
 * Reglas:
 *  - "canonical": registro de los términos padre canónicos (slug => nombre visible).
 *  - "aliases": sinónimos de cada proveedor -> slug canónico (p.ej. CDO "Hogar"
 *    -> "hogar-y-estilos-de-vida"). Se aplica primero el mapa "_global" y luego el
 *    específico del proveedor.
 *  - "tags": nombres de "colecciones"/campañas de un proveedor (CDO) que NO son
 *    categorías estructurales, sino que se asignan como product_tag.
 *
 * El mapa por defecto (defaults()) se fusiona con la opción persistida
 * 'marpico_category_map', que es editable desde el panel de administración.
 */
class Category_Mapper {

    const OPTION = 'marpico_category_map';

    /** Cache de term_id por "slug" canónico dentro de una misma petición. */
    private static $term_cache = array();

    /** Cache del mapa ya fusionado (defaults + opción). */
    private static $map_cache = null;

    /**
     * Mapa por defecto, sembrado a partir de la estructura real del sitio.
     */
    public static function defaults() {
        return array(

            // Términos padre canónicos (los que están en el menú principal).
            'canonical' => array(
                'bebidas'                          => 'Bebidas',
                'bolsos'                           => 'Bolsos',
                'escritura'                        => 'Escritura',
                'herramientas-linternas-y-llaveros'=> 'Herramientas, Linternas y Llaveros',
                'hogar-y-estilos-de-vida'          => 'Hogar y Estilos de Vida',
                'oficina'                          => 'Oficina',
                'salud-y-belleza'                  => 'Salud y Belleza',
                'tecnologia'                       => 'Tecnología',
                'viajes-recreacion-y-deportes'     => 'Viajes, Recreación y Deportes',
                'bar'                              => 'Bar',
                'gorras'                           => 'Gorras',
                'kits'                             => 'Kits',
                'paraguas-e-impermeables'          => 'Paraguas e Impermeables',
            ),

            // Sinónimos -> slug canónico. "_global" aplica a todos los proveedores.
            'aliases' => array(
                '_global' => array(
                    'viajes'                          => 'viajes-recreacion-y-deportes',
                    'hogar'                           => 'hogar-y-estilos-de-vida',
                    'botellas-mugs-vasos'             => 'bebidas',
                    'escrituras-metalicas'            => 'escritura',
                    'escrituras-plasticas-y-otros'    => 'escritura',
                    'oficina-y-negocios'              => 'oficina',
                    'morrales-maletines-bolsos-bolsas'=> 'bolsos',
                    'paraguas-sombrillas'             => 'paraguas-e-impermeables',
                ),
                'cdo'     => array(),
                'marpico' => array(),
            ),

            // Colecciones / campañas de CDO -> se asignan como product_tag, no como categoría.
            'tags' => array(
                'cdo' => array(
                    'reingresos-super-esperados',
                    'vuelve-con-todo',
                    'precios-mejorados',
                    'masivos',
                    'big-logo',
                    'regreso-escolar',
                    'tiempo-libre',
                    'eco',
                    'nivel-ejecutivo',
                    'home-office',
                    'especial-del-cafe',
                    'mes-rosa',
                    'pocket',
                    'temporada-deportiva',
                    'multiuso',
                    'variedad-de-colores',
                    'automovil',
                    'dia-del-trabajador',
                    'dia-del-padre',
                    'dia-de-la-madre',
                    'dia-del-nino',
                    'dia-de-la-mujer',
                    'halloween',
                    'mundial-2026',
                    'articulos-promocionales',
                    'kits-corporativos',
                    'kits-de-bienvenida',
                    'kits-para-ferias',
                    'kits-promocionales',
                    'sublimables',
                    'gorros',
                    'regalos-ejecutivos',
                    'portapendones',
                ),
            ),
        );
    }

    /**
     * Devuelve el mapa efectivo.
     *
     * Si existe la opción persistida 'marpico_category_map' (editada desde el
     * panel), esa es la FUENTE DE VERDAD: se usa tal cual —sólo se normaliza la
     * estructura— de modo que las entradas eliminadas por el usuario realmente
     * desaparezcan. Si no hay opción guardada, se usan los defaults sembrados.
     */
    public static function map() {
        if ( self::$map_cache !== null ) {
            return self::$map_cache;
        }

        $saved = get_option( self::OPTION );

        if ( is_array( $saved ) && ! empty( $saved ) ) {
            self::$map_cache = self::normalize( $saved );
        } else {
            self::$map_cache = self::defaults();
        }

        return self::$map_cache;
    }

    /**
     * Normaliza un mapa garantizando las claves estructurales y sus tipos.
     * Una clave de primer nivel ausente por completo se rellena desde defaults
     * (robustez); pero dentro de cada bloque se respeta lo provisto (permite
     * eliminar entradas individuales).
     */
    public static function normalize( $map ) {
        $defaults = self::defaults();
        $out = array();

        $out['canonical'] = ( isset( $map['canonical'] ) && is_array( $map['canonical'] ) )
            ? $map['canonical'] : $defaults['canonical'];

        // aliases: se conserva cualquier ámbito/proveedor presente (genérico).
        $out['aliases'] = array();
        if ( isset( $map['aliases'] ) && is_array( $map['aliases'] ) ) {
            foreach ( $map['aliases'] as $scope => $pairs ) {
                if ( is_array( $pairs ) ) $out['aliases'][ $scope ] = $pairs;
            }
        } else {
            $out['aliases'] = $defaults['aliases'];
        }
        if ( ! isset( $out['aliases']['_global'] ) ) $out['aliases']['_global'] = array();

        // tags: igualmente por ámbito/proveedor (genérico).
        $out['tags'] = array();
        if ( isset( $map['tags'] ) && is_array( $map['tags'] ) ) {
            foreach ( $map['tags'] as $scope => $list ) {
                if ( is_array( $list ) ) $out['tags'][ $scope ] = array_values( array_unique( $list ) );
            }
        } else {
            $out['tags'] = $defaults['tags'];
        }

        return $out;
    }

    /**
     * Valida y sanea un mapa recibido desde el editor JSON del panel.
     * Devuelve el mapa saneado (array) o un WP_Error con el motivo.
     */
    public static function sanitize_map( $map ) {
        if ( ! is_array( $map ) ) {
            return new WP_Error( 'invalid', 'El JSON debe ser un objeto.' );
        }

        $clean = array( 'canonical' => array(), 'aliases' => array( '_global' => array() ), 'tags' => array() );

        // canonical: { slug => Nombre }
        if ( isset( $map['canonical'] ) ) {
            if ( ! is_array( $map['canonical'] ) ) {
                return new WP_Error( 'invalid', '"canonical" debe ser un objeto { slug: "Nombre" }.' );
            }
            foreach ( $map['canonical'] as $slug => $name ) {
                if ( ! is_string( $name ) ) {
                    return new WP_Error( 'invalid', 'El nombre de la categoría "' . $slug . '" debe ser texto.' );
                }
                $s = sanitize_title( $slug );
                if ( $s === '' ) continue;
                $clean['canonical'][ $s ] = sanitize_text_field( $name );
            }
        }

        // aliases: { <proveedor> => { slug_origen => slug_destino } } (cualquier proveedor)
        if ( isset( $map['aliases'] ) ) {
            if ( ! is_array( $map['aliases'] ) ) {
                return new WP_Error( 'invalid', '"aliases" debe ser un objeto.' );
            }
            foreach ( $map['aliases'] as $scope => $pairs ) {
                $sc = sanitize_key( $scope );
                if ( $sc === '' ) continue;
                if ( ! is_array( $pairs ) ) {
                    return new WP_Error( 'invalid', '"aliases.' . $scope . '" debe ser un objeto { origen: destino }.' );
                }
                if ( ! isset( $clean['aliases'][ $sc ] ) ) $clean['aliases'][ $sc ] = array();
                foreach ( $pairs as $from => $to ) {
                    if ( ! is_string( $to ) ) {
                        return new WP_Error( 'invalid', 'El destino del alias "' . $from . '" debe ser un slug de texto.' );
                    }
                    $f = sanitize_title( $from );
                    $t = sanitize_title( $to );
                    if ( $f === '' || $t === '' ) continue;
                    $clean['aliases'][ $sc ][ $f ] = $t;
                }
            }
        }
        if ( ! isset( $clean['aliases']['_global'] ) ) $clean['aliases']['_global'] = array();

        // tags: { <proveedor> => [ slug, ... ] } (cualquier proveedor)
        if ( isset( $map['tags'] ) ) {
            if ( ! is_array( $map['tags'] ) ) {
                return new WP_Error( 'invalid', '"tags" debe ser un objeto.' );
            }
            foreach ( $map['tags'] as $scope => $list ) {
                $sc = sanitize_key( $scope );
                if ( $sc === '' ) continue;
                if ( ! is_array( $list ) ) {
                    return new WP_Error( 'invalid', '"tags.' . $scope . '" debe ser una lista de slugs.' );
                }
                $acc = array();
                foreach ( $list as $slug ) {
                    if ( ! is_string( $slug ) ) {
                        return new WP_Error( 'invalid', 'Cada entrada de "tags.' . $scope . '" debe ser texto.' );
                    }
                    $s = sanitize_title( $slug );
                    if ( $s !== '' ) $acc[] = $s;
                }
                $clean['tags'][ $sc ] = array_values( array_unique( $acc ) );
            }
        }

        return $clean;
    }

    /** Limpia las cachés internas (útil tras guardar el mapa en admin). */
    public static function flush_cache() {
        self::$map_cache  = null;
        self::$term_cache = array();
    }

    /**
     * Lista las categorías product_cat existentes (para poblar los desplegables
     * del editor). Devuelve [ ['slug'=>..., 'name'=>..., 'count'=>int], ... ].
     */
    public static function all_category_terms() {
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );
        $out = array();
        if ( is_array( $terms ) ) {
            foreach ( $terms as $t ) {
                $out[] = array(
                    'slug'  => $t->slug,
                    'name'  => $t->name,
                    'count' => intval( $t->count ),
                );
            }
        }
        return $out;
    }

    /**
     * Aplica el mapa de alias a un slug crudo para un proveedor dado.
     * Devuelve el slug canónico resultante.
     */
    public static function resolve_alias( $provider, $slug ) {
        $map = self::map();
        if ( isset( $map['aliases']['_global'][ $slug ] ) ) {
            $slug = $map['aliases']['_global'][ $slug ];
        }
        if ( isset( $map['aliases'][ $provider ][ $slug ] ) ) {
            $slug = $map['aliases'][ $provider ][ $slug ];
        }
        return $slug;
    }

    /** ¿Este slug crudo debe tratarse como etiqueta (colección) en este proveedor? */
    public static function is_tag( $provider, $slug ) {
        $map = self::map();
        return isset( $map['tags'][ $provider ] ) && in_array( $slug, $map['tags'][ $provider ], true );
    }

    /**
     * Garantiza la existencia de un término product_cat por slug y devuelve su term_id.
     * Si está registrado como canónico usa su nombre; si se indica $parent_slug crea
     * la relación jerárquica.
     */
    public static function ensure_term( $slug, $name = '', $parent_slug = '' ) {
        if ( ! $slug ) return 0;

        if ( isset( self::$term_cache[ $slug ] ) ) {
            return self::$term_cache[ $slug ];
        }

        $map = self::map();
        if ( ! $name ) {
            $name = isset( $map['canonical'][ $slug ] ) ? $map['canonical'][ $slug ] : $slug;
        }

        $parent_id = 0;
        if ( $parent_slug ) {
            $parent_id = self::ensure_term( $parent_slug );
        }

        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( $term ) {
            $term_id = intval( $term->term_id );
            // Corregir jerarquía si difiere del padre esperado.
            if ( $parent_id && intval( $term->parent ) !== $parent_id ) {
                wp_update_term( $term_id, 'product_cat', array( 'parent' => $parent_id ) );
            }
        } else {
            $args = array( 'slug' => $slug );
            if ( $parent_id ) $args['parent'] = $parent_id;
            $new = wp_insert_term( $name, 'product_cat', $args );
            if ( is_wp_error( $new ) || ! isset( $new['term_id'] ) ) {
                return 0;
            }
            $term_id = intval( $new['term_id'] );
        }

        self::$term_cache[ $slug ] = $term_id;
        return $term_id;
    }

    /**
     * Resuelve y ASIGNA las categorías de un producto Marpico.
     * Espera el primer material (array) con la clave 'subcategoria_1'.
     */
    public static function assign_marpico_categories( $product_id, $first ) {
        $parent_name = $first['subcategoria_1']['nombre_categoria'] ?? '';
        $child_name  = $first['subcategoria_1']['nombre'] ?? '';
        if ( ! $parent_name ) return;

        $parent_slug = self::resolve_alias( 'marpico', sanitize_title( $parent_name ) );
        $parent_id   = self::ensure_term( $parent_slug, $parent_name );
        if ( ! $parent_id ) return;

        $assign = array( $parent_id );

        if ( $child_name && sanitize_title( $child_name ) !== $parent_slug ) {
            $child_id = self::ensure_term( sanitize_title( $child_name ), $child_name, $parent_slug );
            if ( $child_id ) $assign[] = $child_id;
        }

        wp_set_object_terms( $product_id, $assign, 'product_cat' );
    }

    /**
     * Resuelve y ASIGNA las categorías de un producto CDO (array plano de categorías).
     * Las colecciones de marketing se asignan como product_tag (append).
     */
    public static function assign_cdo_categories( $product_id, $categories ) {
        if ( empty( $categories ) || ! is_array( $categories ) ) return;

        $cat_ids   = array();
        $tag_names = array();

        foreach ( $categories as $cat ) {
            if ( is_array( $cat ) && isset( $cat['name'] ) ) {
                $name = trim( $cat['name'] );
            } elseif ( is_string( $cat ) ) {
                $name = trim( $cat );
            } else {
                continue;
            }
            if ( ! $name ) continue;

            $raw_slug = sanitize_title( $name );

            // Colección de marketing -> etiqueta.
            if ( self::is_tag( 'cdo', $raw_slug ) ) {
                $tag_names[] = $name;
                continue;
            }

            $slug    = self::resolve_alias( 'cdo', $raw_slug );
            $term_id = self::ensure_term( $slug, ( $slug === $raw_slug ? $name : '' ) );
            if ( $term_id ) $cat_ids[] = $term_id;
        }

        if ( ! empty( $cat_ids ) ) {
            wp_set_object_terms( $product_id, array_values( array_unique( $cat_ids ) ), 'product_cat' );
        }

        if ( ! empty( $tag_names ) ) {
            foreach ( $tag_names as $tn ) {
                if ( ! term_exists( $tn, 'product_tag' ) ) {
                    wp_insert_term( $tn, 'product_tag' );
                }
            }
            wp_set_object_terms( $product_id, $tag_names, 'product_tag', true );
        }
    }

    /**
     * Reconcilia datos YA sincronizados: mueve los productos de los términos
     * "huérfanos" (sinónimos y colecciones) hacia el término canónico / etiqueta,
     * sin re-sincronizar. Idempotente.
     *
     * @param bool $dry_run Si es true, sólo cuenta y NO modifica nada.
     * @param bool $delete_empty Si es true, elimina el término origen ya vaciado.
     * @return array Resumen de la operación.
     */
    public static function reconcile( $dry_run = true, $delete_empty = false ) {
        $map     = self::map();
        $summary = array( 'moved' => array(), 'tagged' => array(), 'total_products' => 0 );

        // 1) Sinónimos de categoría -> categoría canónica (todos los proveedores).
        $alias_pairs = array();
        foreach ( $map['aliases'] as $pairs ) {
            if ( ! is_array( $pairs ) ) continue;
            foreach ( $pairs as $src => $dst ) {
                $alias_pairs[ $src ] = $dst;
            }
        }

        foreach ( $alias_pairs as $src_slug => $dst_slug ) {
            $src = get_term_by( 'slug', $src_slug, 'product_cat' );
            if ( ! $src ) continue;

            $product_ids = self::products_in_term( intval( $src->term_id ) );
            if ( empty( $product_ids ) ) continue;

            $summary['moved'][] = array(
                'from'  => $src_slug,
                'to'    => $dst_slug,
                'count' => count( $product_ids ),
            );
            $summary['total_products'] += count( $product_ids );

            if ( $dry_run ) continue;

            $dst_id = self::ensure_term( $dst_slug );
            if ( ! $dst_id ) continue;

            foreach ( $product_ids as $pid ) {
                wp_set_object_terms( $pid, array( $dst_id ), 'product_cat', true );      // añade canónica
                wp_remove_object_terms( $pid, array( intval( $src->term_id ) ), 'product_cat' ); // quita huérfana
            }

            if ( $delete_empty ) {
                wp_delete_term( intval( $src->term_id ), 'product_cat' );
            }
        }

        // 2) Colecciones (product_cat) -> product_tag (todos los proveedores).
        $tag_slugs = array();
        foreach ( $map['tags'] as $list ) {
            if ( is_array( $list ) ) $tag_slugs = array_merge( $tag_slugs, $list );
        }
        foreach ( array_unique( $tag_slugs ) as $tag_slug ) {
            $src = get_term_by( 'slug', $tag_slug, 'product_cat' );
            if ( ! $src ) continue;

            $product_ids = self::products_in_term( intval( $src->term_id ) );
            if ( empty( $product_ids ) ) continue;

            $summary['tagged'][] = array( 'name' => $src->name, 'count' => count( $product_ids ) );

            if ( $dry_run ) continue;

            if ( ! term_exists( $src->name, 'product_tag' ) ) {
                wp_insert_term( $src->name, 'product_tag' );
            }
            foreach ( $product_ids as $pid ) {
                wp_set_object_terms( $pid, array( $src->name ), 'product_tag', true );
                wp_remove_object_terms( $pid, array( intval( $src->term_id ) ), 'product_cat' );
            }

            if ( $delete_empty ) {
                wp_delete_term( intval( $src->term_id ), 'product_cat' );
            }
        }

        return $summary;
    }

    /** IDs de productos asignados a un término product_cat. */
    private static function products_in_term( $term_id ) {
        $q = new WP_Query( array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'tax_query'      => array( array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $term_id,
                'include_children' => false,
            ) ),
        ) );
        return $q->posts;
    }
}
