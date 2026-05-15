<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_marpico_sync_products', [ $this, 'ajax_sync_products' ] );
        add_action( 'wp_ajax_marpico_sync_products_batch', [ $this, 'ajax_sync_products_batch' ] );
        add_action( 'wp_ajax_marpico_sync_product_individual', [ $this, 'ajax_sync_product_individual' ] );
        /* add_action( 'wp_ajax_marpico_get_sync_stats', [ $this, 'ajax_get_sync_stats' ] ); */

        //Nuevo hook para BestStock
        add_action( 'wp_ajax_get_child_categories', [ $this, 'ajax_get_child_categories' ] );
        add_action( 'wp_ajax_beststock_sync_products', [ $this, 'ajax_beststock_sync_products' ] );
        add_action( 'wp_ajax_get_beststock_categories', [$this, 'ajax_get_beststock_categories'] );
        add_action( 'wp_ajax_beststock_sync_batch', [ $this, 'ajax_beststock_sync_batch' ] );

        add_action( 'wp_ajax_marpico_get_all_product_categories', [ $this, 'ajax_get_all_product_categories' ]);

        add_action( 'wp_ajax_marpico_sync_batch', [$this,'ajax_sync_batch']);

    }   

    public function add_menu() {
        add_menu_page(
            'Marpico Sync',
            'Marpico Sync',
            'manage_options',
            'marpico-sync',
            [ $this, 'settings_page' ],
            //'dashicons-update',
            plugin_dir_url(dirname(__FILE__)) . 'assets/icon-128x128.png',
            56
        );

        add_submenu_page(
            'marpico-sync',
            'Ajuste de precios',
            'Ajuste de precios',
            'manage_options',
            'marpico-sync#price',
            [ $this, 'render_price_adjust_page' ]
        );

    }

    public function register_settings() {
        // Marpico API
        register_setting( 'marpico_sync_settings', 'marpico_api_endpoint', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ] );
        register_setting( 'marpico_sync_settings', 'marpico_api_token', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ] );
        // NUEVAS opciones: proveedor activo y endpoint para BestStock
        register_setting( 'marpico_sync_settings', 'marpico_active_provider', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'marpico',
        ] );
        register_setting( 'marpico_sync_settings', 'beststock_api_endpoint', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ] );
        // CDO API 
        register_setting( 'marpico_sync_settings', 'cdo_api_endpoint', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ] );
        register_setting( 'marpico_sync_settings', 'cdo_api_token', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ] );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_marpico-sync' ) return;
        //wp_enqueue_style( 'marpico-admin', MARPICO_WOO_SYNC_URL . 'assets/css/admin-styles.css' );
        wp_enqueue_style(
            'marpico-admin',
            MARPICO_WOO_SYNC_URL . 'assets/css/admin-styles.css',
            [],
            filemtime(MARPICO_WOO_SYNC_PATH . 'assets/css/admin-styles.css')
        );
        wp_enqueue_script(
            'marpico-admin',
            MARPICO_WOO_SYNC_URL . 'assets/js/admin.js',
            ['jquery'],
            filemtime( MARPICO_WOO_SYNC_PATH . 'assets/js/admin.js' ), // fuerza actualización
            true
        );
        wp_localize_script( 'marpico-admin', 'marpico_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'marpico_sync_nonce' ),
        ] );
    }

    public function settings_page() {
        include_once( MARPICO_WOO_SYNC_PATH . 'admin-interface.html' );
    }

    public function render_price_adjust_page() {
        // Redirige automáticamente al dashboard con el hash #price
        wp_safe_redirect(admin_url('admin.php?page=marpico-sync#price'));
        exit;
    }

    /*** AJAX: sincronizar producto con el código guardado en opciones */
    public function ajax_sync_products() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $sync = new Marpico_Sync();
        $res = $sync->sync_all_products(5);

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $res->get_error_message() );
        }

        wp_send_json_success( 'Producto sincronizado correctamente. Post ID: ' . $res );
    }

    public function ajax_sync_products_batch() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $offset = intval( $_POST['offset'] ?? 0 );
        $batch_size = intval( $_POST['batch_size'] ?? 10 );

        $sync = new Marpico_Sync();
        $res = $sync->sync_all_products( $offset, $batch_size );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $res->get_error_message() );
        }
        //$this->update_sync_stats( $res );
        wp_send_json_success( $res );
    }

    public function ajax_sync_product_individual() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $product_code = sanitize_text_field( $_POST['product_code'] ?? '' );
        
        if ( empty( $product_code ) ) {
            wp_send_json_error( 'Código de producto requerido' );
        }

        $sync = new Marpico_Sync();
        $res = $sync->sync_product_by_family( $product_code );

        if ( is_wp_error( $res ) ) {
            $this->log_sync_event( "Error sincronizando producto {$product_code}: " . $res->get_error_message(), 'error' );
            wp_send_json_error( $res->get_error_message() );
        }

        $this->log_sync_event( "Producto {$product_code} sincronizado exitosamente (ID: {$res})", 'success' );
        //$this->update_individual_sync_stats();

        wp_send_json_success( "Producto sincronizado correctamente. Post ID: {$res}" );
    }

    /** Handler AJAX para obtener todas las categorías de BestStock */
    public function ajax_get_beststock_categories() {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('No permission');
        }
        
        check_ajax_referer('marpico_sync_nonce', 'security');

        $client = new BestStock_Client();
        $categories = $client->get_categories();

        if ( is_wp_error($categories) ) {
            wp_send_json_error($categories->get_error_message());
        }

        // Normalizamos: array de {id, name, subcategorias}
        $result = [];
        foreach ($categories as $cat) {
            $subcats = [];
            if (!empty($cat['subcategorias']) && is_array($cat['subcategorias'])) {
                foreach ($cat['subcategorias'] as $sub) {
                    $subcats[] = [
                        'id'   => $sub['id'] ?? '',
                        'name' => $sub['name'] ?? '',
                    ];
                }
            }

            $result[] = [
                'id'            => $cat['id'] ?? '',
                'name'          => $cat['name'] ?? '',
                'subcategorias' => $subcats,
            ];
        }

        wp_send_json_success($result);
    }

    //función AJAX para obtener categorías hijas
    public function ajax_get_child_categories() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'No permission' );
        }
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $parent_id = intval($_POST['parent_id'] ?? 0);

        $children = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => $parent_id,
        ]);

        $result = [];
        foreach ($children as $child) {
            $result[] = [
                'id'   => $child->term_id,
                'name' => $child->name,
            ];
        }
        wp_send_json_success($result);
    }
    
    public function ajax_beststock_sync_batch() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $offset = intval( $_POST['offset'] ?? 0 );
        $batch_size = intval( $_POST['batch_size'] ?? 1 );
        $category_id = intval( $_POST['category_id'] ?? 0 );

        if ( ! $category_id ) {
            wp_send_json_error('Falta category_id');
        }

        $sync = new BestStock_Sync();
        $res = $sync->beststock_sync_products_batch($category_id, $offset, $batch_size);
        //errror
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $res->get_error_message() );
        }

        wp_send_json_success($res);
    }

    public function ajax_sync_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error('No permission');
        }

        $provider = get_option('marpico_active_provider', 'marpico');

        if ($provider === 'marpico') {
            $sync = new Marpico_Sync();
        }
        elseif ($provider === 'beststock') {
            $sync = new BestStock_Sync();
        }
        elseif ($provider === 'cdo') {
            $sync = new CDO_Sync();
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;

        $result = $sync->sync_all_products($offset, $batch);

        wp_send_json_success([
            'provider' => $provider,
            'result' => $result
        ]);
    }

    private function log_sync_event( $message, $type = 'info' ) {
        $log = get_option( 'marpico_sync_log', [] );
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $log_entry = "[{$timestamp}] {$message}";
        array_unshift( $log, $log_entry );
        if ( count( $log ) > 100 ) {
            $log = array_slice( $log, 0, 100 );
        }
        update_option( 'marpico_sync_log', $log );
    }
}
