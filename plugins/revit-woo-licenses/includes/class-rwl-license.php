<?php
defined( 'ABSPATH' ) || exit;

/** Определение и накопительный расчёт срока лицензии. */
class RWL_License {

	/** Подключает срок лицензии к сроку доступа к скачиванию WooCommerce. */
	public static function init() {
		add_filter( 'woocommerce_downloadable_file_permission', array( __CLASS__, 'set_download_permission_expiry' ), 10, 5 );
		add_filter( 'woocommerce_order_get_downloadable_items', array( __CLASS__, 'filter_order_download_expiry' ), 10, 2 );
	}

	/** Устанавливает фактическое окончание доступа при создании разрешения на скачивание. */
	public static function set_download_permission_expiry( $download, $product, $order, $qty, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! self::is_plugin_item( $item ) ) {
			return $download;
		}
		$license = self::license_for_order_item( $item, $order );
		if ( $license ) {
			$download->set_access_expires( $license['perpetual'] ? null : $license['end'] );
		}
		return $download;
	}

	/** Показывает рассчитанную дату и для разрешений, созданных до установки этого исправления. */
	public static function filter_order_download_expiry( $downloads, $order ) {
		if ( ! $order instanceof WC_Order || empty( $downloads ) ) {
			return $downloads;
		}
		$items = $order->get_items( 'line_item' );
		foreach ( $downloads as &$row ) {
			foreach ( $items as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product || ! self::is_plugin_item( $item ) ) {
					continue;
				}
				$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				if ( (int) $row['product_id'] !== (int) $product_id ) {
					continue;
				}
				$license = self::license_for_order_item( $item, $order );
				if ( $license ) {
					$row['access_expires'] = $license['perpetual'] ? null : wp_date( 'Y-m-d', $license['end'] );
				}
				break;
			}
		}
		unset( $row );
		return $downloads;
	}

	private static function is_plugin_item( $item ) {
		return $item instanceof WC_Order_Item_Product && RWL_Product::is_plugin( (int) $item->get_product_id() );
	}

	private static function license_for_order_item( $item, $order ) {
		$product_id = (int) $item->get_product_id();
		$user_id = (int) $order->get_customer_id();
		$licenses = $user_id ? self::aggregate_user( $user_id ) : self::aggregate_orders( array( $order ) );
		return isset( $licenses[ $product_id ] ) ? $licenses[ $product_id ] : null;
	}

	public static function term_for_item( WC_Order_Item_Product $item ) {
		$attribute_slug = trim( (string) RWL_Settings::get( 'attribute_slug' ) );
		if ( '' !== $attribute_slug ) {
			$value = self::attribute_value( $item, $attribute_slug );
			// Атрибут включён, но у позиции нет выбранного значения: по правилам магазина это бессрочная лицензия.
			if ( '' === $value ) {
				return array( 'perpetual' => true, 'unit' => 'none', 'value' => 0 );
			}
			$term = self::parse_term( $value );
			if ( $term ) {
				return $term;
			}
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'Неизвестное значение срока «%s» для позиции заказа #%d. Лицензия не рассчитана; проверьте duration_map.', $value, $item->get_id() ),
					array( 'source' => 'rwl' )
				);
			}
			return null;
		}

		$product_id = $item->get_product_id();
		$key = get_post_meta( $product_id, RWL_Product::META_TERM, true );
		$terms = array(
			'1_week' => array( 'perpetual' => false, 'unit' => 'weeks', 'value' => 1 ),
			'2_weeks' => array( 'perpetual' => false, 'unit' => 'weeks', 'value' => 2 ),
			'1_month' => array( 'perpetual' => false, 'unit' => 'months', 'value' => 1 ),
			'3_months' => array( 'perpetual' => false, 'unit' => 'months', 'value' => 3 ),
			'6_months' => array( 'perpetual' => false, 'unit' => 'months', 'value' => 6 ),
			'12_months' => array( 'perpetual' => false, 'unit' => 'months', 'value' => 12 ),
			'perpetual' => array( 'perpetual' => true, 'unit' => 'none', 'value' => 0 ),
		);
		if ( '' === $key ) {
			$key = 'perpetual';
		}
		return isset( $terms[ $key ] ) ? $terms[ $key ] : null;
	}

	public static function end_timestamp( $start, array $term ) {
		$date = new DateTimeImmutable( '@' . (int) $start );
		if ( 'months' === $term['unit'] ) {
			$year = (int) $date->format( 'Y' );
			$month = (int) $date->format( 'n' ) + (int) $term['value'];
			$year += (int) floor( ( $month - 1 ) / 12 );
			$month = ( ( $month - 1 ) % 12 ) + 1;
			$first = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), new DateTimeZone( 'UTC' ) );
			$day = min( (int) $date->format( 'j' ), (int) $first->modify( 'last day of this month' )->format( 'j' ) );
			return $date->setDate( $year, $month, $day )->getTimestamp();
		}
		return $date->modify( '+' . (int) $term['value'] . ' ' . $term['unit'] )->getTimestamp();
	}

	public static function weeks_for_item( WC_Order_Item_Product $item ) {
		$term = self::term_for_item( $item );
		if ( ! $term || $term['perpetual'] || 'weeks' !== $term['unit'] ) {
			return null;
		}
		return $term['value'];
	}

	private static function attribute_value( WC_Order_Item_Product $item, $slug ) {
		$key = sanitize_title( $slug );
		$keys = array_unique( array( $key, 0 === strpos( $key, 'pa_' ) ? substr( $key, 3 ) : 'pa_' . $key ) );
		$variation_id = $item->get_variation_id();

		// В первую очередь читаем фактический атрибут выбранной вариации.
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				foreach ( $variation->get_attributes() as $attribute_key => $value ) {
					$attribute_key = sanitize_title( $attribute_key );
					if ( in_array( $attribute_key, $keys, true ) && '' !== (string) $value ) {
						return self::term_name( $attribute_key, (string) $value );
					}
				}
			}
		}

		// WooCommerce сохраняет выбранный атрибут в метаданных позиции заказа.
		foreach ( $item->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$meta_key = isset( $data['key'] ) ? (string) $data['key'] : '';
			$normalized = 0 === strpos( $meta_key, 'attribute_' ) ? substr( $meta_key, 10 ) : $meta_key;
			$normalized = sanitize_title( $normalized );
			if ( in_array( $normalized, $keys, true ) && isset( $data['value'] ) && '' !== (string) $data['value'] ) {
				return self::term_name( $normalized, (string) $data['value'] );
			}
		}
		return '';
	}

	private static function term_name( $taxonomy, $value ) {
		$taxonomy = sanitize_title( $taxonomy );
		if ( 0 !== strpos( $taxonomy, 'pa_' ) ) {
			$taxonomy = 'pa_' . $taxonomy;
		}
		if ( taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return rawurldecode( (string) $value );
	}

	private static function parse_term( $raw ) {
		$normalize = static function ( $value ) {
			$value = html_entity_decode( trim( (string) $value ), ENT_QUOTES, 'UTF-8' );
			$value = preg_replace( '/\s+/u', ' ', $value );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		};
		$raw_normalized = $normalize( $raw );
		$map = preg_split( '/\R/', (string) RWL_Settings::get( 'duration_map' ) );
		foreach ( $map as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $label, $definition ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' === $label || $normalize( $label ) !== $raw_normalized ) {
				if ( sanitize_title( $label ) !== sanitize_title( $raw ) ) {
					continue;
				}
				continue;
			}
			if ( preg_match( '/^(days|weeks|months):(\d+)$/i', $definition, $m ) && (int) $m[2] > 0 ) {
				return array( 'perpetual' => false, 'unit' => strtolower( $m[1] ), 'value' => (int) $m[2] );
			}
			if ( ctype_digit( $definition ) && (int) $definition > 0 ) {
				return array( 'perpetual' => false, 'unit' => 'weeks', 'value' => (int) $definition );
			}
			return null;
		}
		return null;
	}

	/** Возвращает накопленную лицензию по каждому плагину из оплаченных заказов. */
	public static function aggregate_orders( $orders ) {
		$purchases = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$date = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
			$timestamp = $date ? $date->getTimestamp() : 0;
			if ( ! $timestamp ) {
				continue;
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$product_id = (int) $item->get_product_id();
				if ( $product_id <= 0 || ! RWL_Product::is_plugin( $product_id ) ) {
					continue;
				}
				$quantity = max( 0, (int) $item->get_quantity() );
				if ( method_exists( $order, 'get_qty_refunded_for_item' ) ) {
					$quantity -= absint( $order->get_qty_refunded_for_item( $item->get_id() ) );
				}
				if ( $quantity <= 0 ) {
					continue;
				}
				$term = self::term_for_item( $item );
				if ( ! $term ) {
					continue;
				}
				$purchases[ $product_id ][] = array(
					'start' => $timestamp,
					'term' => $term,
					'quantity' => $quantity,
					'order' => $order,
					'item' => $item,
				);
			}
		}

		$licenses = array();
		foreach ( $purchases as $product_id => $rows ) {
			usort( $rows, static function ( $a, $b ) {
				if ( $a['start'] === $b['start'] ) {
					return $a['item']->get_id() <=> $b['item']->get_id();
				}
				return $a['start'] <=> $b['start'];
			} );
			$first_start = $rows[0]['start'];
			$cursor = $first_start;
			$perpetual = false;
			$latest = end( $rows );
			foreach ( $rows as $row ) {
				if ( ! empty( $row['term']['perpetual'] ) ) {
					$perpetual = true;
					break;
				}
				for ( $i = 0; $i < $row['quantity']; $i++ ) {
					$period_start = max( $row['start'], $cursor );
					$cursor = self::end_timestamp( $period_start, $row['term'] );
				}
			}
			$product = wc_get_product( $product_id );
			$licenses[ $product_id ] = array(
				'plugin_id' => (int) $product_id,
				'name' => $product ? $product->get_name() : $latest['item']->get_name(),
				'start' => $first_start,
				'end' => $perpetual ? null : $cursor,
				'perpetual' => $perpetual,
				'latest_order' => $latest['order'],
				'latest_item' => $latest['item'],
				'latest_purchase' => $latest['start'],
			);
		}
		return $licenses;
	}

	public static function aggregate_user( $user_id ) {
		$orders = wc_get_orders( array(
			'customer_id' => absint( $user_id ),
			'status' => array( 'wc-processing', 'wc-completed' ),
			'limit' => -1,
			'orderby' => 'date',
			'order' => 'ASC',
			'return' => 'objects',
		) );
		return self::aggregate_orders( $orders );
	}
}
