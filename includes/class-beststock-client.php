<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BestStock_Client {

    private $endpoint;

    public function __construct() {
        // Usamos el endpoint guardado en la configuración
        $this->endpoint = rtrim( get_option( 'beststock_api_endpoint', '' ), '/' );
    }

    /**
     * Obtener productos de una categoría específica.
     * @param int $categoria_id
     * @return array|WP_Error
     */
    public function get_products_by_category( $categoria_id ) {
        if ( empty( $this->endpoint ) || empty( $categoria_id ) ) {
            return new WP_Error( 'missing_params', 'Falta endpoint o categoría ID' );
        }

        $url = $this->endpoint . '?task=productos&categoria_id=' . urlencode( $categoria_id );

        $args = [
            'headers' => [ 'Accept' => 'application/json' ],
            'timeout' => 120,
        ];

        $res = wp_remote_get( $url, $args );
        error_log("Respuesta: " . print_r($res, true));
        
        if ( is_wp_error( $res ) ) {
            error_log("Error wp_remote_get: " . $res->get_error_message());
            return $res;
        }

        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) {
            return new WP_Error( 'http_error', 'Código HTTP inesperado: ' . $code );
        }

        $body = wp_remote_retrieve_body( $res );
        $json = json_decode( $body, true );

         if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', 'Respuesta no JSON: ' . substr( $body, 0, 200 ) );
        }

        return $json; // normalmente un array con 1 item (según tu ejemplo)
    }

    /**
     * Obtener todas las categorías de BestStock.
     * @return array|WP_Error
     */
    public function get_categories() {
        if ( empty( $this->endpoint ) ) {
            return new WP_Error( 'missing_endpoint', 'Falta el endpoint de BestStock' );
        }

        $url = $this->endpoint . '?task=categorias';

        $args = [
            'headers' => [ 'Accept' => 'application/json' ],
            'timeout' => 60,
        ];

        $res = wp_remote_get( $url, $args );
        //error_log("Respuesta categorias: " . print_r($res, true));

        if ( is_wp_error( $res ) ) {
            //error_log("Error wp_remote_get categorias: " . $res->get_error_message());
            return $res;
        }

        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) {
            return new WP_Error( 'http_error', 'Código HTTP inesperado: ' . $code );
        }

        $body = wp_remote_retrieve_body( $res );
        $json = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', 'Respuesta no JSON: ' . substr( $body, 0, 200 ) );
        }

        return $json; // array de categorías
    }

}
