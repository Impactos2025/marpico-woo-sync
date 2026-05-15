<div class="wrap">
    <h1>Configuración de Marpico Sync</h1>
    <form method="post" action="options.php">
        <?php
            settings_fields( 'marpico_sync_settings_group' );
            do_settings_sections( 'marpico_sync_settings' );
            submit_button();
        ?>
    </form>
</div>
