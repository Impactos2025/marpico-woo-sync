<?php
/**
 * Programación de sincronizaciones automáticas.
 *
 * Se apoya en WP-Cron (portable: en hosting compartido dispara con el tráfico
 * del sitio o con un cron de cPanel que pegue a wp-cron.php). El evento cron
 * solo ARRANCA el sync a la hora programada; el resto (lotes, precios, reintentos)
 * lo maneja Marpico_Sync_Job.
 *
 * Config persistida en la opción 'marpico_sync_schedules'. BestStock queda fuera
 * (sincroniza por categoría, no encaja en "catálogo completo").
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Scheduler {

    const OPTION = 'marpico_sync_schedules';
    const HOOK   = 'marpico_run_scheduled_sync';

    /** Proveedores programables (catálogo completo). */
    public static function providers() {
        return [ 'marpico', 'cdo' ];
    }

    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_intervals' ] );
        add_action( self::HOOK, [ __CLASS__, 'run' ], 10, 1 );
        // Fix de spawn de WP-Cron en entornos host-mapped (localhost:puerto);
        // no-op en hosting real. Portable.
        add_filter( 'cron_request', [ __CLASS__, 'fix_cron_request' ] );
    }

    /* ===================== Config ===================== */

    public static function defaults() {
        $d = [];
        foreach ( self::providers() as $p ) {
            $d[ $p ] = [
                'enabled'     => false,
                'frequency'   => 'daily',   // hourly|every6h|every12h|daily|weekly
                'hour'        => 3,         // 0-23 (daily/weekly)
                'weekday'     => 1,         // 0=Dom..6=Sáb (weekly)
                'last_run'    => '',
                'last_status' => '',
            ];
        }
        return $d;
    }

    public static function get() {
        $c = get_option( self::OPTION, [] );
        $c = is_array( $c ) ? $c : [];
        $out = self::defaults();
        foreach ( self::providers() as $p ) {
            if ( isset( $c[ $p ] ) && is_array( $c[ $p ] ) ) {
                $out[ $p ] = wp_parse_args( $c[ $p ], $out[ $p ] );
            }
        }
        return $out;
    }

    public static function sanitize( $raw ) {
        $raw   = is_array( $raw ) ? $raw : [];
        $freqs = [ 'hourly', 'every6h', 'every12h', 'daily', 'weekly' ];
        $prev  = self::get();
        $out   = self::defaults();

        foreach ( self::providers() as $p ) {
            $r = isset( $raw[ $p ] ) && is_array( $raw[ $p ] ) ? $raw[ $p ] : [];
            $out[ $p ]['enabled']   = ! empty( $r['enabled'] );
            $out[ $p ]['frequency'] = in_array( ( $r['frequency'] ?? '' ), $freqs, true ) ? $r['frequency'] : 'daily';
            $out[ $p ]['hour']      = max( 0, min( 23, intval( $r['hour'] ?? 3 ) ) );
            $out[ $p ]['weekday']   = max( 0, min( 6, intval( $r['weekday'] ?? 1 ) ) );
            // Conservar historial de ejecución.
            $out[ $p ]['last_run']    = $prev[ $p ]['last_run'] ?? '';
            $out[ $p ]['last_status'] = $prev[ $p ]['last_status'] ?? '';
        }
        return $out;
    }

    public static function save( $raw ) {
        $clean = self::sanitize( $raw );
        update_option( self::OPTION, $clean, false );
        self::reschedule();
        return $clean;
    }

    /* ===================== Registro de eventos WP-Cron ===================== */

    /** Intervalos custom para las frecuencias que WP no trae de fábrica. */
    public static function add_intervals( $s ) {
        $s['marpico_every6h']  = [ 'interval' => 6 * HOUR_IN_SECONDS,  'display' => 'Cada 6 horas' ];
        $s['marpico_every12h'] = [ 'interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Cada 12 horas' ];
        return $s;
    }

    private static function recurrence( $freq ) {
        switch ( $freq ) {
            case 'hourly':   return 'hourly';
            case 'every6h':  return 'marpico_every6h';
            case 'every12h': return 'marpico_every12h';
            case 'daily':    return 'daily';
            case 'weekly':   return 'weekly';
        }
        return '';
    }

    /** Rehace todos los eventos según la config guardada (idempotente). */
    public static function reschedule() {
        $cfg = self::get();
        foreach ( self::providers() as $p ) {
            wp_clear_scheduled_hook( self::HOOK, [ $p ] );
            $s = $cfg[ $p ];
            if ( empty( $s['enabled'] ) ) continue;
            $recurrence = self::recurrence( $s['frequency'] );
            if ( ! $recurrence ) continue;
            wp_schedule_event( self::first_run( $s ), $recurrence, self::HOOK, [ $p ] );
        }
    }

    /** Timestamp (UTC) del primer disparo según la frecuencia y la hora local. */
    private static function first_run( $s ) {
        $now  = time();
        $freq = $s['frequency'];
        $hour = intval( $s['hour'] );
        $tz   = wp_timezone();

        if ( $freq === 'daily' ) {
            $dt = new DateTime( 'now', $tz );
            $dt->setTime( $hour, 0, 0 );
            $ts = $dt->getTimestamp();
            if ( $ts <= $now ) $ts += DAY_IN_SECONDS;
            return $ts;
        }

        if ( $freq === 'weekly' ) {
            $weekday = intval( $s['weekday'] );
            $dt = new DateTime( 'now', $tz );
            $dt->setTime( $hour, 0, 0 );
            $cur  = (int) $dt->format( 'w' ); // 0=Dom..6=Sáb
            $diff = ( $weekday - $cur + 7 ) % 7;
            $ts   = $dt->getTimestamp() + $diff * DAY_IN_SECONDS;
            if ( $ts <= $now ) $ts += 7 * DAY_IN_SECONDS;
            return $ts;
        }

        // hourly / every6h / every12h → empezar en breve.
        return $now + MINUTE_IN_SECONDS;
    }

    /** Próximo disparo (timestamp UTC) o 0. */
    public static function next_run( $provider ) {
        return (int) wp_next_scheduled( self::HOOK, [ $provider ] );
    }

    /* ===================== Ejecución ===================== */

    /** Callback del cron: arranca el sync del proveedor (respeta el lock único). */
    public static function run( $provider ) {
        if ( ! in_array( $provider, self::providers(), true ) ) return;

        $cfg = self::get();

        // Evento huérfano (config deshabilitada): WP-Cron pudo reprogramar un
        // recurrente al dispararlo. Limpiarlo y no arrancar nada.
        if ( empty( $cfg[ $provider ]['enabled'] ) ) {
            wp_clear_scheduled_hook( self::HOOK, [ $provider ] );
            return;
        }

        $res = Marpico_Sync_Job::start( $provider );

        if ( is_wp_error( $res ) ) {
            Marpico_Logger::add( 'Sincronización programada omitida: ' . $res->get_error_message(), 'warning', strtoupper( $provider ) );
        } else {
            Marpico_Logger::add( 'Sincronización programada arrancada', 'info', strtoupper( $provider ) );
        }

        $cfg = self::get();
        $cfg[ $provider ]['last_run']    = current_time( 'mysql' );
        $cfg[ $provider ]['last_status'] = is_wp_error( $res )
            ? ( 'omitido: ' . $res->get_error_message() )
            : 'iniciado';
        update_option( self::OPTION, $cfg, false );
    }

    /* ===================== WP-Cron loopback (dev host-mapped) ===================== */

    public static function fix_cron_request( $cron ) {
        if ( empty( $cron['url'] ) ) return $cron;
        $p = wp_parse_url( $cron['url'] );
        if ( ! empty( $p['host'] ) && ! empty( $p['port'] )
            && in_array( $p['host'], [ 'localhost', '127.0.0.1' ], true ) ) {
            $cron['url'] = str_replace( $p['host'] . ':' . $p['port'], $p['host'], $cron['url'] );
        }
        return $cron;
    }
}
