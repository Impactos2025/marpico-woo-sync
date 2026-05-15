<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CDO_Client {

    private $endpoint;
    private $token;

    public function __construct() {

        $this->endpoint = get_option('cdo_api_endpoint');
        $this->token = get_option('cdo_api_token');

    }
    /**
     * Obtiene todos los productos (limitados).
     *
     * @param int $limit Número máximo de productos a devolver
     * @return array|WP_Error
     */
    public function get_all_products( $limit = 10 ) {

        if ( empty($this->endpoint) || empty($this->token) ) {
            return new WP_Error('missing_config','Endpoint o token faltante');
        }

        $url = $this->endpoint . '?auth_token=' . $this->token;

        $response = wp_remote_get($url, [
            'timeout' => 120
        ]);

        if ( is_wp_error($response) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'http_error', 'Código HTTP inesperado: ' . $code );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', 'Respuesta no JSON: ' . substr( $body, 0, 200 ) );
        }

        // si la API devuelve algo como ['results' => [...]]
        $results = isset( $data['products'] ) ? $data['products'] : $data;

        return array_slice( $results, 0, $limit );

    }

     /**
     * Obtiene todos los productos sin límite de cantidad
     *
     * @return array|WP_Error
     */
    public function get_all_products_unlimited() {
        
        if ( empty($this->endpoint) || empty($this->token) ) {
            return new WP_Error('missing_config','Endpoint o token faltante');
        }

        $all_products = [];
        $page_number = 1;
        $total_pages = 1;

        do {

            $url = $this->endpoint
                . '?auth_token=' . $this->token
                . '&page_size=100'
                . '&page_number=' . $page_number;

            $response = wp_remote_get($url, [
                'timeout' => 120
            ]);

            if ( is_wp_error($response) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);

            if ( $code !== 200 ) {
                return new WP_Error('http_error','Código HTTP inesperado: ' . $code);
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return new WP_Error('invalid_json','Respuesta no JSON');
            }

            if ( isset($data['products']) ) {
                $all_products = array_merge($all_products, $data['products']);
            }

            // leer total de páginas
            if ( isset($data['meta']['pagination']['total_pages']) ) {
                $total_pages = (int) $data['meta']['pagination']['total_pages'];
            }

            $page_number++;

        } while ( $page_number <= $total_pages );

        return $all_products;
    }

}