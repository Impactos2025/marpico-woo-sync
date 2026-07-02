<?php
/**
 * Motor de sincronización en segundo plano.
 *
 * Orquesta la sincronización por lotes en el servidor mediante una cadena de
 * peticiones loopback no bloqueantes (patrón nativo de WP: wp_remote_post +
 * admin-ajax). Cada lote, al terminar, dispara el siguiente en una nueva
 * petición del servidor; el navegador solo lanza el trabajo y consulta el
 * progreso. Así sobrevive al cierre/cambio de pestaña sin depender de WP-Cron.
 *
 * Estado del trabajo: opción 'marpico_sync_job' (no autoload). Fuente de verdad
 * del progreso, permite reanudar y notificar al terminar.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Sync_Job {

    /** Opción con el registro del trabajo activo/último. */
    const OPTION = 'marpico_sync_job';
    /** Lock para que dos runners no procesen el mismo lote a la vez. */
    const LOCK   = 'marpico_sync_lock';
    /** Señal de control (pause|cancel) que consume el worker. Escritor separado del progreso. */
    const SIGNAL = 'marpico_sync_signal';
    /** Transient con el aviso pendiente para admin_notices. */
    const NOTICE = 'marpico_sync_notice';
    /** Segundos sin avance tras los que se considera "atascado" y se re-dispara. */
    const STALE  = 90;
    /** Reintentos de un mismo lote ante errores transitorios del proveedor. */
    const MAX_RETRIES = 3;

    /** Proveedores soportados y su tamaño de lote por defecto. */
    private static $providers = [
        'marpico'   => 10,
        'cdo'       => 10,
        'beststock' => 1,   // API con rate-limit (usleep entre productos)
    ];

    public static function init() {
        add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
        // Endpoint interno que ejecuta un lote (lo invoca el loopback del servidor,
        // sin cookie de sesión; autenticado por token de un solo uso del trabajo).
        add_action( 'wp_ajax_marpico_sync_run',        [ __CLASS__, 'handle_run' ] );
        add_action( 'wp_ajax_nopriv_marpico_sync_run', [ __CLASS__, 'handle_run' ] );
    }

    /* ===================== API pública (la usan los handlers AJAX) ===================== */

    /**
     * Inicia un trabajo de sincronización para un proveedor.
     *
     * @param string $provider   marpico|cdo|beststock
     * @param array  $args       Parámetros específicos del proveedor (BestStock: category_id, wc_categories).
     * @param int    $batch_size Tamaño de lote opcional; por defecto el del proveedor.
     * @return array|WP_Error    Registro del trabajo o error.
     */
    public static function start( $provider, $args = [], $batch_size = 0 ) {
        $provider = sanitize_key( $provider );
        if ( ! isset( self::$providers[ $provider ] ) ) {
            return new WP_Error( 'bad_provider', 'Proveedor no válido: ' . $provider );
        }

        // Un solo trabajo activo a la vez (anti-duplicados a nivel de job).
        $current = self::get();
        if ( $current && in_array( $current['status'], [ 'queued', 'running', 'paused' ], true ) ) {
            return new WP_Error( 'job_active', 'Ya hay una sincronización en curso (' . $current['provider'] . '). Cancélala antes de iniciar otra.' );
        }

        // Validación de parámetros por proveedor.
        if ( $provider === 'beststock' && empty( $args['category_id'] ) ) {
            return new WP_Error( 'missing_args', 'Falta la categoría de BestStock a sincronizar.' );
        }

        $batch = $batch_size > 0 ? $batch_size : self::$providers[ $provider ];

        $job = [
            'job_id'         => uniqid( 'msync_', true ),
            'token'          => wp_generate_password( 24, false ),
            'kind'           => 'sync',
            'provider'       => $provider,
            'status'         => 'queued',
            'total'          => 0,
            'processed'      => 0,
            'successful'     => 0,
            'failed'         => 0,
            'current_offset' => 0,
            'batch_size'     => $batch,
            'retries'        => 0,
            'args'           => self::sanitize_args( $provider, $args ),
            'started_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
            'last_error'     => '',
            'message'        => 'En cola…',
        ];

        self::save( $job );
        delete_transient( self::NOTICE );
        delete_option( self::SIGNAL );   // descarta señales de control previas
        self::release_lock();            // descarta lock huérfano de un job anterior

        // Estado "running" antes de disparar para que el worker no aborte por status.
        $job['status'] = 'running';
        $job['message'] = 'Iniciando…';
        self::save( $job );

        Marpico_Logger::add( 'Sincronización iniciada', 'info', strtoupper( $provider ) );
        self::kick( $job['job_id'], 0 );

        return $job;
    }

    /** Devuelve el registro del trabajo o null. */
    public static function get() {
        $job = get_option( self::OPTION, null );
        return is_array( $job ) ? $job : null;
    }

    /** Estado para el polling del front (siempre un array, sin el token interno). */
    public static function status() {
        $job = self::get();
        if ( ! $job ) {
            return [ 'status' => 'idle' ];
        }

        // Resiliencia: si la cadena de loopback murió (reinicio, fatal), re-disparar.
        if ( $job['status'] === 'running' && ! self::is_locked() ) {
            $age = time() - (int) mysql2date( 'U', $job['updated_at'], false );
            if ( $age > self::STALE ) {
                self::kick( $job['job_id'], (int) $job['current_offset'] );
            }
        }

        // Reflejar una señal de control aún no consumida por el worker.
        if ( $job['status'] === 'running' ) {
            $sig = get_option( self::SIGNAL, '' );
            if ( $sig === 'cancel' )      $job['message'] = 'Cancelando…';
            elseif ( $sig === 'pause' )   $job['message'] = 'Pausando…';
        }

        unset( $job['token'] );
        $job['percent'] = ( $job['total'] > 0 )
            ? min( 100, (int) round( $job['processed'] / $job['total'] * 100 ) )
            : 0;
        return $job;
    }

    /**
     * Inicia "Aplicar ahora": aplica el ajuste de precios guardado a todo el
     * catálogo, en segundo plano, con el mismo motor de jobs (kind='price').
     * Comparte el único slot: no corre en paralelo con una sincronización.
     */
    public static function start_price_apply() {
        $current = self::get();
        if ( $current && in_array( $current['status'], [ 'queued', 'running', 'paused' ], true ) ) {
            return new WP_Error( 'job_active', 'Ya hay un proceso en curso. Cancélalo antes de aplicar el ajuste de precios.' );
        }

        $job = [
            'job_id'         => uniqid( 'mprice_', true ),
            'token'          => wp_generate_password( 24, false ),
            'kind'           => 'price',
            'provider'       => 'price',
            'status'         => 'queued',
            'total'          => 0,
            'processed'      => 0,
            'successful'     => 0,
            'failed'         => 0,
            'current_offset' => 0,
            'batch_size'     => 40,   // ajuste de precios es liviano (sin descargas)
            'retries'        => 0,
            'args'           => [],
            'started_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
            'last_error'     => '',
            'message'        => 'En cola…',
        ];

        self::save( $job );
        delete_transient( self::NOTICE );
        delete_option( self::SIGNAL );
        self::release_lock();

        $job['status']  = 'running';
        $job['message'] = 'Iniciando ajuste de precios…';
        self::save( $job );

        Marpico_Logger::add( 'Ajuste de precios iniciado', 'info', 'Precios' );
        self::kick( $job['job_id'], 0 );

        return $job;
    }

    /** Etiqueta de contexto para el registro de actividad. */
    private static function ctx( $job ) {
        return ( ( $job['kind'] ?? 'sync' ) === 'price' ) ? 'Precios' : strtoupper( $job['provider'] ?? '' );
    }

    /**
     * Solicita cancelar. Si el worker está activo, deja la señal y este la aplica
     * al terminar el lote en curso (el worker es el único que escribe el progreso,
     * así no hay "lost update"). Si está inactivo, se aplica de inmediato.
     */
    public static function cancel() {
        $job = self::get();
        if ( ! $job ) return false;
        update_option( self::SIGNAL, 'cancel', false );
        if ( ! self::is_locked() ) {
            $job['status']  = 'canceled';
            $job['message'] = 'Cancelada por el usuario.';
            $job['updated_at'] = current_time( 'mysql' );
            self::save( $job );
        }
        return true;
    }

    /** Solicita pausar. Mismo mecanismo de señal que cancelar. */
    public static function pause() {
        $job = self::get();
        if ( ! $job || $job['status'] !== 'running' ) return false;
        update_option( self::SIGNAL, 'pause', false );
        if ( ! self::is_locked() ) {
            $job['status']  = 'paused';
            $job['message'] = 'Pausada.';
            $job['updated_at'] = current_time( 'mysql' );
            self::save( $job );
        }
        return true;
    }

    /** Reanuda desde el offset guardado (tras pausa o fallo). */
    public static function resume() {
        $job = self::get();
        if ( ! $job || ! in_array( $job['status'], [ 'paused', 'failed' ], true ) ) return false;
        delete_option( self::SIGNAL );
        $job['status']  = 'running';
        $job['message'] = 'Reanudando…';
        $job['last_error'] = '';
        $job['retries'] = 0;   // reinicia reintentos al reanudar manualmente
        $job['updated_at'] = current_time( 'mysql' );
        self::save( $job );
        self::kick( $job['job_id'], (int) $job['current_offset'] );
        return true;
    }

    /* ===================== Worker (loopback del servidor) ===================== */

    /**
     * Endpoint interno (admin-ajax) que ejecuta un lote. Lo invoca el loopback
     * no bloqueante del servidor, sin cookie de sesión: se autentica con el
     * token de un solo uso guardado en el trabajo.
     */
    public static function handle_run() {
        if ( function_exists( 'ignore_user_abort' ) ) ignore_user_abort( true );
        @set_time_limit( 0);

        $job_id = isset( $_REQUEST['job'] )    ? sanitize_text_field( wp_unslash( $_REQUEST['job'] ) ) : '';
        $offset = isset( $_REQUEST['offset'] ) ? intval( $_REQUEST['offset'] ) : 0;
        $token  = isset( $_REQUEST['token'] )  ? (string) wp_unslash( $_REQUEST['token'] ) : '';

        $job = self::get();
        if ( ! $job || $job['job_id'] !== $job_id || empty( $job['token'] ) || ! hash_equals( $job['token'], $token ) ) {
            status_header( 403 );
            exit;
        }

        // Responder y cerrar la conexión cuanto antes; seguir procesando en segundo plano.
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            echo 'OK';
            if ( function_exists( 'session_write_close' ) ) @session_write_close();
            fastcgi_finish_request();
        }

        self::process_batch( $job_id, $offset );
        exit;
    }

    /**
     * Procesa UN lote y dispara el siguiente vía loopback.
     *
     * @param string $job_id Identificador del trabajo (descarta peticiones obsoletas).
     * @param int    $offset Offset a procesar.
     */
    public static function process_batch( $job_id, $offset ) {
        $job = self::get();

        // Petición obsoleta (otro trabajo lo reemplazó) → ignorar.
        if ( ! $job || $job['job_id'] !== $job_id ) return;
        // Pausada/cancelada → no procesar ni encadenar.
        if ( $job['status'] !== 'running' ) return;
        // Solo avanza el lote esperado: descarta kicks duplicados/obsoletos y evita
        // que la cadena retroceda o se ramifique.
        if ( (int) $offset !== (int) $job['current_offset'] ) return;

        // Mutex atómico: garantiza un único runner activo (cadena estrictamente lineal).
        if ( ! self::acquire_lock() ) return;

        try {
            // Señal de control llegada antes de este lote → aplicar sin procesar.
            if ( self::apply_signal( $job ) ) return;

            $result = self::run_batch( $job, (int) $offset );

            if ( is_wp_error( $result ) ) {
                // Error transitorio del proveedor (p.ej. API 500): reintentar el MISMO
                // lote con backoff antes de dar el trabajo por fallido.
                $retries = (int) ( $job['retries'] ?? 0 ) + 1;
                if ( $retries <= self::MAX_RETRIES ) {
                    $job['retries']    = $retries;
                    $job['message']    = sprintf( 'Error temporal en offset %d (%s). Reintento %d/%d…',
                        $offset, $result->get_error_message(), $retries, self::MAX_RETRIES );
                    $job['updated_at'] = current_time( 'mysql' );
                    self::save( $job );
                    sleep( min( $retries * 5, 30 ) );     // backoff (lock retenido: nadie más entra)
                    self::release_lock();
                    self::kick( $job_id, (int) $offset ); // re-disparar el mismo offset
                    return;
                }
                self::fail( $result->get_error_message(), (int) $offset );
                return;
            }

            // El worker es el único escritor del progreso: se acumula sobre $job sin recargar.
            $job['retries']        = 0;  // lote correcto → reinicia el contador de reintentos
            $job['processed']     += (int) ( $result['processed'] ?? 0 );
            $job['successful']    += (int) ( $result['successful'] ?? 0 );
            $job['failed']        += (int) ( $result['failed'] ?? 0 );
            $job['total']          = (int) ( $result['total'] ?? $job['total'] );
            $job['current_offset'] = (int) ( $result['next_offset'] ?? ( $offset + $job['batch_size'] ) );
            $job['updated_at']     = current_time( 'mysql' );

            // Errores por-producto de este lote → registro de actividad (señal valiosa).
            if ( ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) {
                foreach ( $result['errors'] as $err ) {
                    Marpico_Logger::add( $err, 'error', self::ctx( $job ) );
                }
            }

            $has_more = ! empty( $result['has_more'] );

            // Señal de control llegada durante el lote → cancelar/pausar (guarda el avance).
            if ( self::apply_signal( $job ) ) return;

            if ( $has_more ) {
                $job['message'] = sprintf( 'Procesados %d de %d…', $job['processed'], $job['total'] );
                self::save( $job );
                self::release_lock();  // liberar antes de encadenar el siguiente
                self::kick( $job_id, (int) $job['current_offset'] );
            } else {
                $job['status']  = 'completed';
                Marpico_Logger::add( '✓ ' . ( ( $job['kind'] ?? 'sync' ) === 'price' ? 'Ajuste de precios completado' : 'Sincronización completada' )
                    . sprintf( ': %d procesados, %d ok, %d con error.', $job['processed'], $job['successful'], $job['failed'] ),
                    'success', self::ctx( $job ) );
                if ( ( $job['kind'] ?? 'sync' ) === 'price' ) {
                    $job['message'] = sprintf( 'Completado: %d productos revisados, %d con precio actualizado.',
                        $job['processed'], $job['successful'] );
                } else {
                    $job['message'] = sprintf( 'Completada: %d productos (%d ok, %d con error).',
                        $job['processed'], $job['successful'], $job['failed'] );
                }
                self::save( $job );
                self::set_notice( $job );
            }
        } finally {
            self::release_lock();
        }
    }

    /**
     * Aplica una señal de control pendiente (pause|cancel) sobre $job y la guarda.
     * Devuelve true si se consumió una señal (el worker debe detenerse).
     */
    private static function apply_signal( $job ) {
        $sig = get_option( self::SIGNAL, '' );
        if ( $sig !== 'pause' && $sig !== 'cancel' ) return false;
        delete_option( self::SIGNAL );
        $job['status']     = ( $sig === 'cancel' ) ? 'canceled' : 'paused';
        $job['message']    = ( $sig === 'cancel' ) ? 'Cancelada por el usuario.' : 'Pausada.';
        $job['updated_at'] = current_time( 'mysql' );
        self::save( $job );
        Marpico_Logger::add( ( $sig === 'cancel' ? '⚠ Cancelada' : '⏸ Pausada' ) . sprintf( ' en %d/%d', $job['processed'], $job['total'] ),
            'warning', self::ctx( $job ) );
        return true;
    }

    /**
     * Mutex entre peticiones basado en add_option (INSERT atómico sobre el índice
     * único de option_name): dos peticiones concurrentes → solo una lo adquiere.
     * Recupera locks obsoletos (runner caído) pasados 15 minutos.
     */
    private static function acquire_lock() {
        if ( add_option( self::LOCK, time(), '', 'no' ) ) return true;
        $ts = (int) get_option( self::LOCK, 0 );
        if ( $ts && ( time() - $ts ) > 15 * MINUTE_IN_SECONDS ) {
            update_option( self::LOCK, time(), false );
            return true;
        }
        return false;
    }
    private static function release_lock() {
        delete_option( self::LOCK );
    }
    private static function is_locked() {
        return (bool) get_option( self::LOCK, false );
    }

    /* ===================== Aviso admin (admin_notices) ===================== */

    public static function render_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $n = get_transient( self::NOTICE );
        if ( ! $n ) return;
        delete_transient( self::NOTICE );

        $class   = ( ! empty( $n['failed'] ) || ( $n['status'] ?? '' ) === 'failed' ) ? 'notice-warning' : 'notice-success';
        $is_price = ( ( $n['kind'] ?? 'sync' ) === 'price' );

        if ( ( $n['status'] ?? '' ) === 'failed' ) {
            $proceso = $is_price ? 'Ajuste de precios' : ( 'Sincronización ' . strtoupper( $n['provider'] ?? '' ) );
            $msg = sprintf( '%s detenido por error: %s (procesados %d).',
                esc_html( $proceso ), esc_html( $n['error'] ?? '' ), (int) ( $n['processed'] ?? 0 ) );
        } elseif ( $is_price ) {
            $msg = sprintf( 'Ajuste de precios completado: %d productos revisados, %d actualizados.',
                (int) ( $n['processed'] ?? 0 ), (int) ( $n['successful'] ?? 0 ) );
        } else {
            $msg = sprintf( 'Sincronización %s completada: %d productos (%d ok, %d con error).',
                esc_html( strtoupper( $n['provider'] ?? '' ) ), (int) ( $n['processed'] ?? 0 ), (int) ( $n['successful'] ?? 0 ), (int) ( $n['failed'] ?? 0 ) );
        }

        printf( '<div class="notice %s is-dismissible"><p><strong>Marpico Woo Sync:</strong> %s</p></div>',
            esc_attr( $class ), $msg );
    }

    /* ===================== Helpers internos ===================== */

    /** Marca el trabajo como fallido y guarda aviso (mantiene offset para reanudar). */
    private static function fail( $message, $offset ) {
        $job = self::get();
        if ( ! $job ) return;
        $job['status']     = 'failed';
        $job['last_error'] = $message;
        $job['message']    = 'Detenida por error en offset ' . $offset . '.';
        $job['updated_at'] = current_time( 'mysql' );
        self::save( $job );
        Marpico_Logger::add( '❌ Detenida por error en offset ' . $offset . ': ' . $message, 'error', self::ctx( $job ) );
        self::set_notice( $job );
    }

    /**
     * Dispara el procesamiento de un lote con una petición loopback no bloqueante
     * al endpoint interno. El servidor encadena así los lotes por sí mismo.
     */
    private static function kick( $job_id, $offset ) {
        $job = self::get();
        if ( ! $job || empty( $job['token'] ) ) return;

        $body = [
            'action' => 'marpico_sync_run',
            'job'    => $job_id,
            'offset' => (int) $offset,
            'token'  => $job['token'],
        ];

        // En mod_php (sin fastcgi_finish_request) WP ejecuta curl_exec de forma
        // síncrona aunque blocking=false; el timeout solo gobierna el envío. El
        // lote sigue corriendo en el servidor gracias a ignore_user_abort(true),
        // así que basta con un timeout que garantice el envío de la petición.
        foreach ( self::loopback_urls() as $url ) {
            wp_remote_post( $url, [
                'timeout'   => 1,
                'blocking'  => false,
                'sslverify' => false,
                'cookies'   => [],
                'body'      => $body,
            ] );
        }
    }

    /**
     * URLs de loopback a probar (no bloqueantes, deduplicadas). En producción es
     * solo admin-ajax. En entornos host-mapped (Docker dev: localhost:8080) el
     * loopback al puerto público no resuelve dentro del contenedor, así que se
     * añade la variante sin puerto (apache interno en :80). Filtrable.
     */
    private static function loopback_urls() {
        $url   = admin_url( 'admin-ajax.php' );
        $urls  = [ $url ];

        $p = wp_parse_url( $url );
        if ( ! empty( $p['host'] ) && ! empty( $p['port'] )
            && in_array( $p['host'], [ 'localhost', '127.0.0.1' ], true ) ) {
            $urls[] = str_replace( $p['host'] . ':' . $p['port'], $p['host'], $url );
        }

        return array_unique( apply_filters( 'marpico_sync_loopback_urls', $urls ) );
    }

    /** Instancia la clase de sync del proveedor. */
    private static function make_sync( $provider ) {
        switch ( $provider ) {
            case 'cdo':       return new CDO_Sync();
            case 'beststock': return new BestStock_Sync();
            case 'marpico':
            default:          return new Marpico_Sync();
        }
    }

    /** Adaptador: ejecuta un lote según el tipo de trabajo; todos devuelven la misma forma. */
    private static function run_batch( $job, $offset ) {
        $batch = (int) $job['batch_size'];

        // Trabajo de ajuste de precios (no es sync de proveedor).
        if ( ( $job['kind'] ?? 'sync' ) === 'price' ) {
            return self::run_price_batch( $offset, $batch );
        }

        $sync = self::make_sync( $job['provider'] );
        switch ( $job['provider'] ) {
            case 'beststock':
                $a = $job['args'];
                return $sync->beststock_sync_products_batch(
                    (int) $a['category_id'], $offset, $batch, $a['wc_categories'] ?? []
                );
            case 'cdo':
            case 'marpico':
            default:
                return $sync->sync_all_products( $offset, $batch );
        }
    }

    /** Lote de "Aplicar ahora": recorre productos por offset y aplica el motor de precios. */
    private static function run_price_batch( $offset, $batch ) {
        $q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => $batch,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => false,
        ] );

        $total     = (int) $q->found_posts;
        $processed = 0;
        $touched   = 0;

        foreach ( $q->posts as $pid ) {
            $processed++;
            if ( Marpico_Price_Engine::apply_to_product( $pid ) ) $touched++;
        }

        return [
            'processed'   => $processed,
            'successful'  => $touched,
            'failed'      => 0,
            'total'       => $total,
            'offset'      => $offset,
            'next_offset' => $offset + $batch,
            'has_more'    => ( $offset + $batch ) < $total,
        ];
    }

    /** Sanea los args específicos del proveedor. */
    private static function sanitize_args( $provider, $args ) {
        if ( $provider !== 'beststock' ) return [];
        $wc = [];
        if ( ! empty( $args['wc_category_parent'] ) ) $wc[] = intval( $args['wc_category_parent'] );
        if ( ! empty( $args['wc_category_child'] ) )  $wc[] = intval( $args['wc_category_child'] );
        return [
            'category_id'  => intval( $args['category_id'] ?? 0 ),
            'wc_categories' => $wc,
        ];
    }

    private static function set_notice( $job ) {
        set_transient( self::NOTICE, [
            'kind'       => $job['kind'] ?? 'sync',
            'provider'   => $job['provider'],
            'processed'  => $job['processed'],
            'successful' => $job['successful'],
            'failed'     => $job['failed'],
            'status'     => $job['status'],
            'error'      => $job['last_error'],
        ], DAY_IN_SECONDS );
    }

    private static function save( $job ) {
        update_option( self::OPTION, $job, false );
    }
}
