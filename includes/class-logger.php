<?php
/**
 * Registro de actividad estructurado del plugin.
 *
 * Entradas con { t: fecha, level: info|success|warning|error, msg, ctx }.
 * Reemplaza los logs de texto plano dispersos. Compatible hacia atrás: normaliza
 * las entradas antiguas (strings "[fecha] mensaje") al leer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Logger {

    const OPTION = 'marpico_sync_log';
    const MAX    = 300;

    /** Añade una entrada. $level='auto' deduce el nivel del mensaje. */
    public static function add( $message, $level = 'auto', $context = '' ) {
        $level = ( $level === 'auto' ) ? self::detect( $message ) : $level;
        if ( ! in_array( $level, [ 'info', 'success', 'warning', 'error' ], true ) ) {
            $level = 'info';
        }

        $log = get_option( self::OPTION, [] );
        if ( ! is_array( $log ) ) $log = [];

        $log[] = [
            't'     => current_time( 'mysql' ),
            'level' => $level,
            'msg'   => (string) $message,
            'ctx'   => (string) $context,
        ];

        if ( count( $log ) > self::MAX ) {
            $log = array_slice( $log, -self::MAX );
        }
        update_option( self::OPTION, $log, false );
    }

    private static function detect( $m ) {
        $m = (string) $m;
        if ( strpos( $m, '✓' ) !== false || stripos( $m, 'exitosa' ) !== false || stripos( $m, 'completad' ) !== false ) {
            return 'success';
        }
        if ( strpos( $m, '✗' ) !== false || strpos( $m, '❌' ) !== false || stripos( $m, 'error' ) !== false || stripos( $m, 'fall' ) !== false ) {
            return 'error';
        }
        if ( stripos( $m, 'aviso' ) !== false || stripos( $m, 'omitid' ) !== false || stripos( $m, 'pausad' ) !== false || stripos( $m, 'cancelad' ) !== false ) {
            return 'warning';
        }
        return 'info';
    }

    /** Entradas normalizadas, más reciente primero. */
    public static function all( $limit = 300 ) {
        $log = get_option( self::OPTION, [] );
        if ( ! is_array( $log ) ) return [];

        $out = [];
        foreach ( $log as $e ) {
            if ( is_array( $e ) ) {
                $out[] = [
                    't'     => $e['t'] ?? '',
                    'level' => $e['level'] ?? 'info',
                    'msg'   => $e['msg'] ?? '',
                    'ctx'   => $e['ctx'] ?? '',
                ];
            } else {
                // Entrada antigua: "[fecha] mensaje".
                $s = (string) $e; $t = ''; $msg = $s;
                if ( preg_match( '/^\[([^\]]+)\]\s*(.*)$/s', $s, $mm ) ) {
                    $t = $mm[1]; $msg = $mm[2];
                }
                $out[] = [ 't' => $t, 'level' => self::detect( $msg ), 'msg' => $msg, 'ctx' => '' ];
            }
        }

        $out = array_reverse( $out ); // más reciente primero
        return array_slice( $out, 0, max( 1, (int) $limit ) );
    }

    /** Conteo por nivel sobre todo el buffer. */
    public static function counts() {
        $c = [ 'total' => 0, 'info' => 0, 'success' => 0, 'warning' => 0, 'error' => 0 ];
        foreach ( self::all( self::MAX ) as $e ) {
            $c['total']++;
            $lvl = $e['level'];
            if ( isset( $c[ $lvl ] ) ) $c[ $lvl ]++;
        }
        return $c;
    }

    public static function clear() {
        update_option( self::OPTION, [], false );
    }
}
