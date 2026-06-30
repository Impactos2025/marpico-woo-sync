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
        add_action( 'wp_ajax_marpico_get_sync_stats', [ $this, 'ajax_get_sync_stats' ] );

        //Nuevo hook para BestStock
        add_action( 'wp_ajax_get_child_categories', [ $this, 'ajax_get_child_categories' ] );
        add_action( 'wp_ajax_beststock_sync_products', [ $this, 'ajax_beststock_sync_products' ] );
        add_action( 'wp_ajax_get_beststock_categories', [$this, 'ajax_get_beststock_categories'] );
        add_action( 'wp_ajax_beststock_sync_batch', [ $this, 'ajax_beststock_sync_batch' ] );

        add_action( 'wp_ajax_marpico_get_all_product_categories', [ $this, 'ajax_get_all_product_categories' ]);

        add_action( 'wp_ajax_marpico_sync_batch', [$this,'ajax_sync_batch']);

        // Sincronización en segundo plano (Action Scheduler)
        add_action( 'wp_ajax_marpico_sync_start',  [ $this, 'ajax_sync_start' ] );
        add_action( 'wp_ajax_marpico_sync_status', [ $this, 'ajax_sync_status' ] );
        add_action( 'wp_ajax_marpico_sync_cancel', [ $this, 'ajax_sync_cancel' ] );
        add_action( 'wp_ajax_marpico_sync_pause',  [ $this, 'ajax_sync_pause' ] );
        add_action( 'wp_ajax_marpico_sync_resume', [ $this, 'ajax_sync_resume' ] );

        // Reconciliación de categorías (mapeo multi-proveedor)
        add_action( 'wp_ajax_marpico_category_reconcile', [ $this, 'ajax_category_reconcile' ] );
        add_action( 'wp_ajax_marpico_category_map_save', [ $this, 'ajax_category_map_save' ] );

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

        add_submenu_page(
            'marpico-sync',
            'Mapeo de categorías',
            'Mapeo de categorías',
            'manage_options',
            'marpico-categories',
            [ $this, 'render_categories_page' ]
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

    /**
     * Página de "Mapeo de categorías": muestra los alias/colecciones del
     * Category_Mapper y permite reconciliar (mover) los productos ya
     * sincronizados desde los términos huérfanos hacia los canónicos.
     */
    public function render_categories_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $map     = Category_Mapper::map();
        $nonce   = wp_create_nonce( 'marpico_sync_nonce' );
        $terms   = Category_Mapper::all_category_terms();
        ?>
        <div class="wrap">
            <h1>Mapeo de categorías multi-proveedor</h1>
            <p>Unifica las categorías de cada proveedor (CDO, Marpico, …) en la taxonomía
               <strong>canónica</strong> que alimenta el menú. Configura las reglas con los
               desplegables, <strong>guarda</strong>, y luego usa <strong>vista previa / aplicar</strong>
               para reubicar los productos ya sincronizados.</p>

            <datalist id="mp-terms"><?php
                foreach ( $terms as $t ) {
                    printf( '<option value="%s">%s</option>',
                        esc_attr( $t['slug'] ),
                        esc_attr( $t['name'] . ' (' . $t['count'] . ')' ) );
                }
            ?></datalist>

            <h2>1. Categorías canónicas (destinos del menú)</h2>
            <p class="description">Son las categorías hacia las que se mapea todo. <code>slug</code> debe coincidir con la categoría real de WooCommerce.</p>
            <table class="widefat striped" style="max-width:720px">
                <thead><tr><th style="width:45%">Slug</th><th>Nombre visible</th><th style="width:60px"></th></tr></thead>
                <tbody id="mp-canon-tbody"></tbody>
            </table>
            <p><button class="button" id="mp-canon-add">+ Añadir categoría canónica</button></p>

            <h2 style="margin-top:1.5em">2. Reglas de mapeo (origen &rarr; canónica)</h2>
            <p class="description">Por proveedor. <em>Todos los proveedores</em> aplica a cualquiera (ámbito global).</p>
            <p>
                <label>Proveedor:
                    <select id="mp-scope" style="min-width:200px"></select>
                </label>
            </p>
            <table class="widefat striped" style="max-width:840px">
                <thead><tr><th style="width:42%">Categoría origen (proveedor)</th><th style="width:42%">&rarr; Categoría canónica</th><th style="width:60px"></th></tr></thead>
                <tbody id="mp-alias-tbody"></tbody>
            </table>
            <p><button class="button" id="mp-alias-add">+ Añadir regla</button></p>

            <h2 style="margin-top:1.5em">3. Colecciones &rarr; etiqueta</h2>
            <p class="description">Categorías de campaña/marketing que NO son categorías de producto; se convierten en etiqueta (<code>product_tag</code>).</p>
            <p>
                <label>Proveedor:
                    <select id="mp-tagscope" style="min-width:200px"></select>
                </label>
            </p>
            <table class="widefat striped" style="max-width:600px">
                <thead><tr><th>Categoría origen (slug)</th><th style="width:60px"></th></tr></thead>
                <tbody id="mp-tag-tbody"></tbody>
            </table>
            <p><button class="button" id="mp-tag-add">+ Añadir colección</button></p>

            <p style="margin-top:1.5em">
                <button class="button button-primary" id="mp-cat-save">Guardar configuración</button>
                <button class="button button-link-delete" id="mp-cat-restore">Restaurar valores por defecto</button>
            </p>
            <div id="mp-cat-save-result" style="margin-bottom:1em"></div>

            <details style="margin:1em 0;max-width:880px">
                <summary style="cursor:pointer">Avanzado: ver JSON generado</summary>
                <textarea id="mp-cat-json" rows="16" readonly
                    style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12px;white-space:pre;overflow:auto;margin-top:.5em"></textarea>
            </details>

            <hr>
            <h2>Reconciliar datos ya sincronizados</h2>
            <p style="margin-top:.5em">
                <button class="button button-secondary" id="mp-cat-preview">Vista previa</button>
                <button class="button button-primary" id="mp-cat-apply" disabled>Aplicar reconciliación</button>
                <label style="margin-left:1em"><input type="checkbox" id="mp-cat-delete"> eliminar términos huérfanos vacíos</label>
            </p>
            <div id="mp-cat-result" style="margin-top:1em"></div>
        </div>
        <script>
        (function(){
            var ajaxurl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce      = <?php echo wp_json_encode( $nonce ); ?>;
            var MAP        = <?php echo wp_json_encode( $map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
            var defaultMap = <?php echo wp_json_encode( Category_Mapper::defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
            var box        = document.getElementById('mp-cat-result');
            var saveBox    = document.getElementById('mp-cat-save-result');

            function notice(el, type, msg){
                el.innerHTML = '<div class="notice notice-'+type+'" style="margin:.5em 0;padding:.5em 1em"><p>'+msg+'</p></div>';
            }
            function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
            function scopeLabel(s){ return s==='_global' ? 'Todos los proveedores' : s; }

            // ---- Estado de trabajo (arrays editables) ----
            var canon  = [];  // [{slug,name}]
            var aliases= {};  // { scope: [{from,to}] }
            var tags   = {};  // { scope: [slug] }

            function loadFrom(map){
                canon = Object.keys(map.canonical||{}).map(function(k){ return {slug:k, name:map.canonical[k]}; });
                aliases = {};
                var al = map.aliases||{}; Object.keys(al).forEach(function(sc){
                    aliases[sc] = Object.keys(al[sc]||{}).map(function(f){ return {from:f, to:al[sc][f]}; });
                });
                if(!aliases['_global']) aliases['_global']=[];
                tags = {};
                var tg = map.tags||{}; Object.keys(tg).forEach(function(sc){ tags[sc] = (tg[sc]||[]).slice(); });
            }

            function canonOptions(sel){
                return '<option value="">— elegir —</option>' + canon.filter(function(r){return r.slug;}).map(function(r){
                    return '<option value="'+esc(r.slug)+'"'+(r.slug===sel?' selected':'')+'>'+esc(r.name||r.slug)+' ('+esc(r.slug)+')</option>';
                }).join('');
            }
            function scopeOptions(obj, sel){
                var keys = Object.keys(obj);
                if(keys.indexOf('_global')<0) keys.unshift('_global');
                return keys.map(function(k){ return '<option value="'+esc(k)+'"'+(k===sel?' selected':'')+'>'+esc(scopeLabel(k))+'</option>'; }).join('')
                    + '<option value="__add__">➕ Añadir proveedor…</option>';
            }

            // ---- Render ----
            function renderCanon(){
                document.getElementById('mp-canon-tbody').innerHTML = canon.map(function(r,i){
                    return '<tr>'
                        + '<td><input type="text" class="regular-text" style="width:100%" data-t="canon" data-k="slug" data-i="'+i+'" value="'+esc(r.slug)+'" placeholder="slug-canonico"></td>'
                        + '<td><input type="text" style="width:100%" data-t="canon" data-k="name" data-i="'+i+'" value="'+esc(r.name)+'" placeholder="Nombre visible"></td>'
                        + '<td><button class="button button-small mp-del" data-t="canon" data-i="'+i+'">✕</button></td>'
                        + '</tr>';
                }).join('') || '<tr><td colspan="3"><em>Sin categorías canónicas.</em></td></tr>';
            }
            function currentScope(){ return document.getElementById('mp-scope').value; }
            function currentTagScope(){ return document.getElementById('mp-tagscope').value; }

            function renderAlias(){
                var sc = currentScope(); var rows = aliases[sc]||[];
                document.getElementById('mp-alias-tbody').innerHTML = rows.map(function(r,i){
                    return '<tr>'
                        + '<td><input type="text" list="mp-terms" style="width:100%" data-t="alias" data-k="from" data-i="'+i+'" value="'+esc(r.from)+'" placeholder="categoria-origen"></td>'
                        + '<td><select style="width:100%" data-t="alias" data-k="to" data-i="'+i+'">'+canonOptions(r.to)+'</select></td>'
                        + '<td><button class="button button-small mp-del" data-t="alias" data-i="'+i+'">✕</button></td>'
                        + '</tr>';
                }).join('') || '<tr><td colspan="3"><em>Sin reglas para este proveedor.</em></td></tr>';
            }
            function renderTags(){
                var sc = currentTagScope(); var rows = tags[sc]||[];
                document.getElementById('mp-tag-tbody').innerHTML = rows.map(function(slug,i){
                    return '<tr>'
                        + '<td><input type="text" list="mp-terms" style="width:100%" data-t="tag" data-i="'+i+'" value="'+esc(slug)+'" placeholder="categoria-coleccion"></td>'
                        + '<td><button class="button button-small mp-del" data-t="tag" data-i="'+i+'">✕</button></td>'
                        + '</tr>';
                }).join('') || '<tr><td colspan="2"><em>Sin colecciones para este proveedor.</em></td></tr>';
            }
            function renderScopes(){
                document.getElementById('mp-scope').innerHTML    = scopeOptions(aliases, currentScopeMem);
                document.getElementById('mp-tagscope').innerHTML = scopeOptions(tags, currentTagScopeMem);
            }
            var currentScopeMem='_global', currentTagScopeMem='cdo';

            function renderAll(){ renderCanon(); renderScopes(); renderAlias(); renderTags(); updateJson(); }

            function buildMap(){
                var m = {canonical:{}, aliases:{}, tags:{}};
                canon.forEach(function(r){ if(r.slug) m.canonical[r.slug]=r.name||r.slug; });
                Object.keys(aliases).forEach(function(sc){
                    m.aliases[sc]={}; (aliases[sc]||[]).forEach(function(r){ if(r.from && r.to) m.aliases[sc][r.from]=r.to; });
                });
                Object.keys(tags).forEach(function(sc){
                    var seen={}; m.tags[sc]=[]; (tags[sc]||[]).forEach(function(s){ if(s && !seen[s]){ seen[s]=1; m.tags[sc].push(s); } });
                });
                return m;
            }
            function updateJson(){ document.getElementById('mp-cat-json').value = JSON.stringify(buildMap(), null, 4); }

            // ---- Eventos de edición (delegación) ----
            document.addEventListener('input', function(e){
                var el = e.target; var t = el.getAttribute && el.getAttribute('data-t'); if(!t) return;
                var i = parseInt(el.getAttribute('data-i'),10);
                if(t==='canon'){ canon[i][el.getAttribute('data-k')] = el.value; }
                else if(t==='alias'){ aliases[currentScope()][i][el.getAttribute('data-k')] = el.value; }
                else if(t==='tag'){ tags[currentTagScope()][i] = el.value; }
                updateJson();
            });
            document.addEventListener('change', function(e){
                var el = e.target;
                if(el.id==='mp-scope'){
                    if(el.value==='__add__'){ var k=addProvider(aliases); currentScopeMem=k; } else { currentScopeMem=el.value; }
                    renderScopes(); renderAlias(); return;
                }
                if(el.id==='mp-tagscope'){
                    if(el.value==='__add__'){ var k2=addProvider(tags); currentTagScopeMem=k2; } else { currentTagScopeMem=el.value; }
                    renderScopes(); renderTags(); return;
                }
                if(el.getAttribute && el.getAttribute('data-t')==='alias' && el.getAttribute('data-k')==='to'){
                    aliases[currentScope()][parseInt(el.getAttribute('data-i'),10)].to = el.value; updateJson();
                }
            });
            function addProvider(obj){
                var k = (prompt('Slug del nuevo proveedor (ej: beststock):')||'').toLowerCase().replace(/[^a-z0-9_-]/g,'');
                if(!k){ return currentScopeMem; }
                if(!obj[k]) obj[k] = (obj===aliases)?[]:[];
                return k;
            }
            document.addEventListener('click', function(e){
                var el = e.target;
                if(el.classList && el.classList.contains('mp-del')){
                    e.preventDefault();
                    var t=el.getAttribute('data-t'), i=parseInt(el.getAttribute('data-i'),10);
                    if(t==='canon'){ canon.splice(i,1); renderCanon(); }
                    else if(t==='alias'){ aliases[currentScope()].splice(i,1); renderAlias(); }
                    else if(t==='tag'){ tags[currentTagScope()].splice(i,1); renderTags(); }
                    updateJson();
                }
            });
            document.getElementById('mp-canon-add').addEventListener('click', function(e){ e.preventDefault(); canon.push({slug:'',name:''}); renderCanon(); updateJson(); });
            document.getElementById('mp-alias-add').addEventListener('click', function(e){ e.preventDefault(); var sc=currentScope(); (aliases[sc]=aliases[sc]||[]).push({from:'',to:''}); renderAlias(); updateJson(); });
            document.getElementById('mp-tag-add').addEventListener('click', function(e){ e.preventDefault(); var sc=currentTagScope(); (tags[sc]=tags[sc]||[]).push(''); renderTags(); updateJson(); });

            // ---- Guardar ----
            document.getElementById('mp-cat-save').addEventListener('click', function(e){
                e.preventDefault();
                var data = new URLSearchParams();
                data.append('action','marpico_category_map_save');
                data.append('security', nonce);
                data.append('map', JSON.stringify(buildMap()));
                saveBox.innerHTML = 'Guardando…';
                fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(!res || !res.success){ notice(saveBox,'error','Error: '+(res && res.data ? res.data : 'desconocido')); return; }
                        loadFrom(res.data.map); renderAll();
                        notice(saveBox,'success', res.data.message);
                    })
                    .catch(function(err){ notice(saveBox,'error', String(err)); });
            });
            document.getElementById('mp-cat-restore').addEventListener('click', function(e){
                e.preventDefault();
                if(!confirm('¿Cargar los valores por defecto? Deberás pulsar Guardar para aplicarlos.')) return;
                loadFrom(defaultMap); currentScopeMem='_global'; currentTagScopeMem='cdo'; renderAll();
                notice(saveBox,'info','Valores por defecto cargados. Pulsa "Guardar configuración" para aplicarlos.');
            });

            // Init
            loadFrom(MAP);
            if(Object.keys(tags).indexOf('cdo')<0){ var firstTag=Object.keys(tags)[0]; if(firstTag) currentTagScopeMem=firstTag; }
            renderAll();

            var reconcileRunning = false;
            var btnPrev  = document.getElementById('mp-cat-preview');
            var btnApply = document.getElementById('mp-cat-apply');

            function run(apply){
                if(reconcileRunning) return;            // evita reentrada / doble disparo
                reconcileRunning = true;
                // Deshabilita ambos botones de inmediato (antes del fetch).
                btnPrev.disabled = true; btnApply.disabled = true;
                var del = document.getElementById('mp-cat-delete').checked ? 1 : 0;
                box.innerHTML = 'Procesando…';
                var data = new URLSearchParams();
                data.append('action','marpico_category_reconcile');
                data.append('security', nonce);
                data.append('apply', apply ? 1 : 0);
                data.append('delete_empty', del);
                fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(!res || !res.success){ box.innerHTML = '<div class="notice notice-error"><p>Error: '+(res && res.data ? res.data : 'desconocido')+'</p></div>'; return; }
                        var d = res.data, html = '';
                        html += '<p><strong>'+(d.applied ? 'Aplicado' : 'Vista previa')+'</strong> — '+d.total_products+' productos afectados.</p>';
                        if(d.moved.length){
                            html += '<h3>Movimientos de categoría</h3><ul>';
                            d.moved.forEach(function(m){ html += '<li>'+m.from+' &rarr; '+m.to+' ('+m.count+')</li>'; });
                            html += '</ul>';
                        }
                        if(d.tagged.length){
                            html += '<h3>A etiqueta</h3><ul>';
                            d.tagged.forEach(function(t){ html += '<li>'+t.name+' ('+t.count+')</li>'; });
                            html += '</ul>';
                        }
                        if(!d.moved.length && !d.tagged.length){ html += '<p>Nada que reconciliar.</p>'; }
                        box.innerHTML = html;
                    })
                    .catch(function(e){ box.innerHTML = '<div class="notice notice-error"><p>'+e+'</p></div>'; })
                    .finally(function(){
                        reconcileRunning = false;
                        btnPrev.disabled = false;
                        // Tras aplicar, deja "Aplicar" deshabilitado (hay que volver a previsualizar).
                        btnApply.disabled = apply;
                    });
            }
            btnPrev.addEventListener('click', function(e){ e.preventDefault(); run(false); });
            btnApply.addEventListener('click', function(e){ e.preventDefault(); if(reconcileRunning) return; if(confirm('¿Aplicar la reconciliación? Esto reasigna categorías de productos.')) run(true); });
        })();
        </script>
        <?php
    }

    /** AJAX: ejecuta la reconciliación de categorías (vista previa o aplicar). */
    public function ajax_category_reconcile() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes', 403 );
        }
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $apply        = ! empty( $_POST['apply'] );
        $delete_empty = ! empty( $_POST['delete_empty'] );

        // Lock para que dos peticiones concurrentes no se solapen al APLICAR.
        // La vista previa (dry-run) es de sólo lectura y no necesita lock.
        $lock = 'marpico_reconcile_lock';
        if ( $apply ) {
            if ( get_transient( $lock ) ) {
                wp_send_json_error( 'Ya hay una reconciliación en curso. Espera a que termine.' );
            }
            set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
        }

        try {
            $summary = Category_Mapper::reconcile( ! $apply, $delete_empty );
        } finally {
            if ( $apply ) {
                delete_transient( $lock );
            }
        }

        $summary['applied'] = $apply;

        wp_send_json_success( $summary );
    }

    /** AJAX: valida y guarda el mapa de categorías editado en JSON. */
    public function ajax_category_map_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes', 403 );
        }
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $raw     = isset( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : '';
        $decoded = json_decode( $raw, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( 'JSON inválido: ' . json_last_error_msg() );
        }

        $clean = Category_Mapper::sanitize_map( $decoded );
        if ( is_wp_error( $clean ) ) {
            wp_send_json_error( $clean->get_error_message() );
        }

        update_option( Category_Mapper::OPTION, $clean );
        Category_Mapper::flush_cache();

        wp_send_json_success( array(
            'map'     => $clean,
            'pretty'  => wp_json_encode( $clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'message' => 'Mapa guardado correctamente.',
        ) );
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

    /** AJAX: estadísticas básicas para el panel (total productos, último sync, etc.). */
    public function ajax_get_sync_stats() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $counts = wp_count_posts( 'product' );
        $total  = isset( $counts->publish ) ? (int) $counts->publish : 0;

        $job = Marpico_Sync_Job::status();
        $running = in_array( $job['status'] ?? 'idle', [ 'running', 'queued' ], true );

        wp_send_json_success( [
            'total_products' => $total,
            'last_sync'      => $job['updated_at'] ?? 'Nunca',
            'sync_errors'    => (int) ( $job['failed'] ?? 0 ),
            'api_status'     => ( ( $job['status'] ?? '' ) === 'failed' ) ? 'Error' : ( $running ? 'Sincronizando' : 'OK' ),
        ] );
    }

    /* ===================== Sincronización en segundo plano ===================== */

    /** AJAX: inicia un trabajo de sync en background para el proveedor indicado. */
    public function ajax_sync_start() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $provider = sanitize_key( $_POST['provider'] ?? '' );
        $args = [
            'category_id'        => intval( $_POST['category_id'] ?? 0 ),
            'wc_category_parent' => intval( $_POST['wc_category_parent'] ?? 0 ),
            'wc_category_child'  => intval( $_POST['wc_category_child'] ?? 0 ),
        ];
        $batch_size = intval( $_POST['batch_size'] ?? 0 );

        $job = Marpico_Sync_Job::start( $provider, $args, $batch_size );
        if ( is_wp_error( $job ) ) {
            wp_send_json_error( $job->get_error_message() );
        }
        wp_send_json_success( Marpico_Sync_Job::status() );
    }

    /** AJAX: devuelve el estado actual del trabajo (para el polling). */
    public function ajax_sync_status() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );
        wp_send_json_success( Marpico_Sync_Job::status() );
    }

    /** AJAX: cancela el trabajo en curso. */
    public function ajax_sync_cancel() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );
        Marpico_Sync_Job::cancel();
        wp_send_json_success( Marpico_Sync_Job::status() );
    }

    /** AJAX: pausa el trabajo en curso. */
    public function ajax_sync_pause() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );
        Marpico_Sync_Job::pause();
        wp_send_json_success( Marpico_Sync_Job::status() );
    }

    /** AJAX: reanuda un trabajo pausado o fallido desde el offset guardado. */
    public function ajax_sync_resume() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );
        Marpico_Sync_Job::resume();
        wp_send_json_success( Marpico_Sync_Job::status() );
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
