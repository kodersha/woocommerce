<?php
defined( 'ABSPATH' ) || exit;

/** Логирование через WooCommerce > Статус > Журналы (источник "rwl"). Токены и секреты не пишутся. */
class RWL_Logger {
	public static function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'rwl' ) );
		}
	}
	public static function error( $m ) { self::log( 'error', $m ); }
	public static function info( $m ) { self::log( 'info', $m ); }
}
