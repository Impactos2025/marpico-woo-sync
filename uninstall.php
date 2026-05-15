<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'marpico_api_endpoint' );
delete_option( 'marpico_api_token' );
delete_option( 'marpico_api_product_code' );
delete_option( 'marpico_sync_log' );
