<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Client {

    private $endpoint;
    private $token;

    public function __construct() {
        $this->endpoint = rtrim( get_option( 'marpico_api_endpoint', '' ), '/' );
        $this->token    = trim( get_option( 'marpico_api_token', '' ) );
    }

    /**
     * Llama al endpoint para obtener un producto por familia/código.
     * Construye: {endpoint}/materialesAPIByProducto?producto={codigo}
     */
    public function get_product_by_family( $family_code ) {
        if ( empty( $this->endpoint ) || empty( $family_code ) ) {
            return new WP_Error( 'missing_params', 'Falta endpoint o código de producto' );
        }

        $url = $this->endpoint . '/materialesAPIByProducto?producto=' . urlencode( $family_code );

        error_log("URL: " . $url);

        $args = [
            'headers' => [
                'Authorization' => 'Api-Key ' . $this->token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 40,
        ];

        $res = wp_remote_get( $url, $args );
        error_log("Respuesta: " . print_r($res, true));

        if ( is_wp_error( $res ) ) {
            error_log("Error wp_remote_get: " . $res->get_error_message());
            return $res;
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = wp_remote_retrieve_body( $res );

        $json = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', 'Respuesta no JSON: ' . substr( $body, 0, 200 ) );
        }

        return $json; // normalmente un array con 1 item (según tu ejemplo)
    }

    /**
     * Obtiene todos los productos (limitados).
     *
     * @param int $limit Número máximo de productos a devolver
     * @return array|WP_Error
     */
    public function get_all_products( $limit = 5 ) {
        if ( empty( $this->endpoint ) ) {
            return new WP_Error( 'missing_endpoint', 'No se configuró el endpoint' );
        }

        $url = $this->endpoint . '/materialesAPI';

        $args = [
            'headers' => [
                'Authorization' => 'Api-Key ' . $this->token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 120,
        ];

        $res = wp_remote_get( $url, $args );
        if ( is_wp_error( $res ) ) return $res;

        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) {
            return new WP_Error( 'http_error', 'Código HTTP inesperado: ' . $code );
        }

        $body = wp_remote_retrieve_body( $res );
        $json = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', 'Respuesta no JSON: ' . substr( $body, 0, 200 ) );
        }

        // si la API devuelve algo como ['results' => [...]]
        $results = isset( $json['results'] ) ? $json['results'] : $json;

        return array_slice( $results, 0, $limit );
    }

    /**
     * Obtiene todos los productos sin límite de cantidad
     *
     * @return array|WP_Error
     */
    public function get_all_products_unlimited() {
        if ( empty( $this->endpoint ) ) {
            return new WP_Error( 'missing_endpoint', 'No se configuró el endpoint' );
        }

        $url = $this->endpoint . '/materialesAPI';

        $args = [
            'headers' => [
                'Authorization' => 'Api-Key ' . $this->token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 120, // 2 minutos para manejar muchos productos
        ];

        $res = wp_remote_get( $url, $args );
        if ( is_wp_error( $res ) ) return $res;

        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) {
            return new WP_Error( 'http_error', 'Código HTTP inesperado: ' . $code );
        }

        $body = wp_remote_retrieve_body( $res );
        $json = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', 'Respuesta no JSON: ' . substr( $body, 0, 200 ) );
        }

        // si la API devuelve algo como ['results' => [...]]
        $results = isset( $json['results'] ) ? $json['results'] : $json;

        return $results; // Devolver todos sin límite
    }
}
