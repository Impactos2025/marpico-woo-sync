<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Marpico_Sync_Settings {

    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function register_settings() {
        // Registramos la opción (aquí se guardan los datos)
        register_setting(
            'marpico_sync_settings_group',  // Grupo
            'marpico_sync_settings',        // Nombre en la BD (option_name)
            [
                'sanitize_callback' => [ __CLASS__, 'sanitize' ]
            ]
        );

        // Sección general
        add_settings_section(
            'marpico_sync_section',
            'Configuración de la API',
            function() {
                echo '<p>Introduce el endpoint y el token para sincronizar productos.</p>';
            },
            'marpico_sync_settings' // ID de la página
        );

        // Campo: Endpoint
        add_settings_field(
            'api_endpoint',
            'API Endpoint',
            [ __CLASS__, 'field_api_endpoint' ],
            'marpico_sync_settings',
            'marpico_sync_section'
        );

        // Campo: Token
        add_settings_field(
            'api_token',
            'API Token',
            [ __CLASS__, 'field_api_token' ],
            'marpico_sync_settings',
            'marpico_sync_section'
        );
    }

    public static function sanitize( $input ) {
        $output = [];
        $output['api_endpoint'] = esc_url_raw( $input['api_endpoint'] ?? '' );
        $output['api_token']    = sanitize_text_field( $input['api_token'] ?? '' );
        return $output;
    }

    public static function field_api_endpoint() {
        $options = get_option( 'marpico_sync_settings' );
        ?>
        <input type="text" name="marpico_sync_settings[api_endpoint]" value="<?php echo esc_attr( $options['api_endpoint'] ?? '' ); ?>" class="regular-text">
        <?php
    }

    public static function field_api_token() {
        $options = get_option( 'marpico_sync_settings' );
        ?>
        <input type="text" name="marpico_sync_settings[api_token]" value="<?php echo esc_attr( $options['api_token'] ?? '' ); ?>" class="regular-text">
        <?php
    }
}

Marpico_Sync_Settings::init();
