<?php
defined( 'ABSPATH' ) || exit;

/** Минимальный читатель XLSX (первый лист) без внешних библиотек. Требует ZipArchive. */
class RWL_XLSX {
	const MAX_ENTRY = 20971520; // 20 МБ на распакованный файл внутри архива

	/** @return array|WP_Error Массив строк (массив значений) */
	public static function read( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'rwl_zip', 'На сервере не установлено расширение ZipArchive — загрузите CSV.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'rwl_xlsx', 'Не удалось открыть XLSX.' );
		}

		$shared = array();
		$ss     = self::entry( $zip, 'xl/sharedStrings.xml' );
		if ( $ss ) {
			$x = simplexml_load_string( $ss, 'SimpleXMLElement', LIBXML_NONET );
			if ( $x ) {
				foreach ( $x->si as $si ) {
					$t = '';
					if ( isset( $si->t ) ) {
						$t = (string) $si->t;
					} else {
						foreach ( $si->r as $r ) {
							$t .= (string) $r->t;
						}
					}
					$shared[] = $t;
				}
			}
		}

		// Первый лист: sheetN.xml с минимальным N.
		$sheets = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( preg_match( '#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m ) ) {
				$sheets[ (int) $m[1] ] = $name;
			}
		}
		if ( ! $sheets ) {
			$zip->close();
			return new WP_Error( 'rwl_xlsx', 'В XLSX не найден лист.' );
		}
		ksort( $sheets );
		$xml = self::entry( $zip, reset( $sheets ) );
		$zip->close();
		$sx = $xml ? simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET ) : false;
		if ( ! $sx ) {
			return new WP_Error( 'rwl_xlsx', 'Не удалось разобрать лист XLSX.' );
		}

		$rows = array();
		foreach ( $sx->sheetData->row as $row ) {
			$cells = array();
			$max   = -1;
			foreach ( $row->c as $c ) {
				$col  = self::col_index( (string) $c['r'] );
				$type = (string) $c['t'];
				if ( 's' === $type ) {
					$val = isset( $shared[ (int) $c->v ] ) ? $shared[ (int) $c->v ] : '';
				} elseif ( 'inlineStr' === $type ) {
					$val = '';
					if ( isset( $c->is->t ) ) {
						$val = (string) $c->is->t;
					} else {
						foreach ( $c->is->r as $r ) {
							$val .= (string) $r->t;
						}
					}
				} else {
					$val = (string) $c->v;
				}
				$cells[ $col ] = trim( $val );
				$max           = max( $max, $col );
			}
			$line = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$line[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
			}
			$rows[] = $line;
		}
		return $rows;
	}

	private static function entry( ZipArchive $zip, $name ) {
		$idx = $zip->locateName( $name );
		if ( false === $idx ) {
			return null;
		}
		$st = $zip->statIndex( $idx );
		if ( ! $st || $st['size'] > self::MAX_ENTRY ) {
			return null;
		}
		return $zip->getFromIndex( $idx );
	}

	private static function col_index( $ref ) {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $ref ) );
		$n       = 0;
		for ( $i = 0, $l = strlen( $letters ); $i < $l; $i++ ) {
			$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return max( 0, $n - 1 );
	}
}
