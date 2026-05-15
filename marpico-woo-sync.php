<?php
/**
 * Plugin Name: Marpico Woo Sync
 * Description: Sincroniza productos, categorías y etiquetas desde API´s externas hacia WooCommerce.
 * Version: 1.2.2
 * Author: David Perez
 * Author URI:  https://github.com/davidpezcas
 * Plugin URI:  https://github.com/davidpezcas/marpico-woo-sync
 * Text Domain: marpico-woo-sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Definir constante de versión
if ( ! defined( 'MARPICO_SYNC_VERSION' ) ) {
    define( 'MARPICO_SYNC_VERSION', '1.2.2' );
}

define( 'MARPICO_WOO_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'MARPICO_WOO_SYNC_URL',  plugin_dir_url( __FILE__ ) );

// includes
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-marpico-client.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-marpico-sync.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-beststock-client.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-beststock-sync.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-marpico-admin.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-attribute-helper.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-price.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-price-percentage.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-cdo-client.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-cdo-sync.php';

if ( is_admin() ) {
    require_once MARPICO_WOO_SYNC_PATH . 'includes/github-updater.php';

    // Conectar el plugin a GitHub
    $repo_url = 'https://github.com/davidpezcas/marpico-woo-sync';
    new GitHub_Updater(__FILE__, $repo_url);
}

// init admin
add_action( 'plugins_loaded', function() {
    new Marpico_Admin();
    new Marpico_Price();
    new Marpico_Price_Brand_Percentage();
});

add_action('admin_enqueue_scripts', function() {
    echo '<style>
        #toplevel_page_marpico-sync .wp-menu-image img {
            width:20px;
            height:20px;
            object-fit:contain;
            padding-top:6px;
        }
    </style>';
});

add_action('admin_head', function () {
    ?>
    <style>
        tr[data-slug="marpico-woo-sync"] .plugin-icon {
            background-image: url('<?php echo plugin_dir_url(__FILE__) . 'assets/icon-128x128.png'; ?>') !important;
            background-size: contain !important;
        }
    </style>
    <?php
});


