<?php
defined( 'ABSPATH' ) || exit;

/** Определение срока лицензии. Возвращает тип и величину, а не только недели. */
class RWL_License {

	/**
	 * @return array{perpetual:bool,unit:string,value:int}|null
	 */
	public static function term_for_item( WC_Order_Item_Product $item ) {
		$product_id = $item->get_product_id();
		$term_key = get_post_meta( $product_id, RWL_Product::META_TERM, true );
		$terms = array(
			'1_week'    => array( 'perpetual' => false, 'unit' => 'weeks',  'value' => 1 ),
			'2_weeks'   => array( 'perpetual' => false, 'unit' => 'weeks',  'value' => 2 ),
			'1_month'   => array( 'perpetual' => false, 'unit' => 'months', 'value' => 1 ),
			'3_months'  => array( 'perpetual' => false, 'unit' => 'months', 'value' => 3 ),
			'6_months'  => array( 'perpetual' => false, 'unit' => 'months', 'value' => 6 ),
			'12_months' => array( 'perpetual' => false, 'unit' => 'months', 'value' => 12 ),
			'perpetual' => array( 'perpetual' => true,  'unit' => 'none',   'value' => 0 ),
		);

		// Для старых товаров без нового поля безопасное значение — бессрочно.
		if ( '' === $term_key ) {
			$term_key = 'perpetual';
		}
		return isset( $terms[ $term_key ] ) ? $terms[ $term_key ] : null;
	}

	/** Рассчитывает окончание срока; месяцы добавляются календарно с ограничением последним днём месяца. */
	public static function end_timestamp( $start, array $term ) {
		$date = new DateTimeImmutable( '@' . (int) $start );
		if ( 'months' === $term['unit'] ) {
			$year = (int) $date->format( 'Y' );
			$month = (int) $date->format( 'n' ) + (int) $term['value'];
			$year += (int) floor( ( $month - 1 ) / 12 );
			$month = ( ( $month - 1 ) % 12 ) + 1;
			$day = min( (int) $date->format( 'j' ), (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->modify( 'last day of this month' )->format( 'j' ) );
			return $date->setDate( $year, $month, $day )->getTimestamp();
		}
		return $date->modify( '+' . (int) $term['value'] . ' ' . $term['unit'] )->getTimestamp();
	}

	/** Legacy-обёртка для стороннего кода, ожидающего срок в неделях. */
	public static function weeks_for_item( WC_Order_Item_Product $item ) {
		$term = self::term_for_item( $item );
		if ( ! $term || $term['perpetual'] ) {
			return null;
		}
		if ( 'weeks' === $term['unit'] ) {
			return $term['value'];
		}
		return null;
	}

	private static function attribute_value( WC_Order_Item_Product $item, $slug ) {
		$key  = sanitize_title( $slug );
		$keys = array_unique( array( $key, 0 === strpos( $key, 'pa_' ) ? $key : 'pa_' . $key ) );

		$variation_id = $item->get_variation_id();
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$attrs = $variation->get_attributes();
				foreach ( $keys as $k ) {
					if ( isset( $attrs[ $k ] ) && '' !== (string) $attrs[ $k ] ) {
						return self::term_name( $k, (string) $attrs[ $k ] );
					}
				}
			}
		}

		$product = wc_get_product( $item->get_product_id() );
		if ( $product && ! $product->is_type( 'variable' ) ) {
			foreach ( $keys as $k ) {
				$value = $product->get_attribute( $k );
				if ( '' !== trim( (string) $value ) ) {
					$values = array_map( 'trim', explode( ',', (string) $value ) );
					if ( 1 === count( $values ) ) {
						return $values[0];
					}
				}
			}
		}

		foreach ( $keys as $k ) {
			$meta = $item->get_meta( $k );
			if ( '' !== (string) $meta ) {
				return self::term_name( $k, (string) $meta );
			}
		}
		return '';
	}

	private static function term_name( $taxonomy, $value ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return $value;
	}

	/** Форматы справочника: «31 день=days:31», «Полгода=months:6», legacy «1 год=52». */
	private static function parse_term( $raw ) {
		$raw = trim( (string) $raw );
		$map = preg_split( '/\R/', (string) RWL_Settings::get( 'duration_map' ) );
		foreach ( $map as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $label, $definition ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' === $label || mb_strtolower( $label ) !== mb_strtolower( $raw ) ) {
				continue;
			}
			if ( preg_match( '/^(days|weeks|months):(\d+)$/i', $definition, $m ) && (int) $m[2] > 0 ) {
				return array( 'perpetual' => false, 'unit' => strtolower( $m[1] ), 'value' => (int) $m[2] );
			}
			// Старый формат: число означает недели.
			if ( ctype_digit( $definition ) && (int) $definition > 0 ) {
				return array( 'perpetual' => false, 'unit' => 'weeks', 'value' => (int) $definition );
			}
			return null;
		}
		// Не извлекаем числа из произвольной подписи: это могло бы скрыть ошибку настройки.
		return null;
	}
}
