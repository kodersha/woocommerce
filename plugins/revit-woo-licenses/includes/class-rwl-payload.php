<?php
defined( 'ABSPATH' ) || exit;

/** Формирование данных для внешнего сервера. */
class RWL_Payload {
	private static function image_url( WC_Product $p ) {
		$id = $p->get_image_id();
		$url = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
		return $url ? esc_url_raw( $url ) : '';
	}
	private static function iso( $ts ) {
		return gmdate( 'Y-m-d\\TH:i:s\\Z', (int) $ts );
	}

	/** @return array|WP_Error */
	public static function for_item( WC_Order $order, WC_Order_Item_Product $item ) {
		$pid = $item->get_product_id();
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return new WP_Error( 'rwl_no_product', 'Товар #' . $pid . ' не найден.' );
		}
		$snap = RWL_Product::snapshot( $pid );
		$base = array(
			'event' => 'purchase',
			'idempotency_key' => 'order-' . $order->get_id() . '-item-' . $item->get_id(),
			'order_id' => $order->get_id(),
		);

		if ( 'yes' === $snap['plugin'] ) {
			$user_id = (int) $order->get_user_id();
			$licenses = $user_id ? RWL_License::aggregate_user( $user_id ) : array();
			if ( empty( $licenses[ $pid ] ) ) {
				return new WP_Error( 'rwl_no_term', 'Не удалось рассчитать срок лицензии для «' . $product->get_name() . '». Проверьте атрибут срока и duration_map.' );
			}
			$license = $licenses[ $pid ];
			if ( $license['perpetual'] && 'yes' !== RWL_Settings::get( 'api_supports_perpetual' ) ) {
				return new WP_Error( 'rwl_perpetual_unsupported', 'Лицензия бессрочная, но поддержка license_perpetual ещё не включена для внешнего API. Обновите сервер и включите настройку в WooCommerce → Revit Licenses.' );
			}
			$payload = $base + array(
				'type' => 'plugin',
				'plugin_id' => $pid,
				'name' => $product->get_name(),
				'image' => self::image_url( $product ),
				'license_start' => self::iso( $license['start'] ),
				'license_end' => $license['end'] ? self::iso( $license['end'] ) : null,
				'license_perpetual' => (bool) $license['perpetual'],
				'user_id' => $user_id,
				'revit_version' => $snap['revit'],
				'product_version' => $snap['version'],
			);
			return $payload;
		}

		return $base + array(
			'type' => 'file',
			'product_id' => $pid,
			'name' => $product->get_name(),
			'image' => self::image_url( $product ),
			'user_id' => $order->get_user_id(),
			'revit_version' => $snap['revit'],
			'product_version' => $snap['version'],
		);
	}

	public static function for_version_update( $pid ) {
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return new WP_Error( 'rwl_no_product', 'Товар #' . $pid . ' не найден.' );
		}
		$snap = RWL_Product::snapshot( $pid );
		return array(
			'event' => 'version_update',
			'idempotency_key' => 'vu-' . $pid . '-' . md5( implode( '|', $snap ) ),
			'type' => 'yes' === $snap['plugin'] ? 'plugin' : 'file',
			( 'yes' === $snap['plugin'] ? 'plugin_id' : 'product_id' ) => (int) $pid,
			'name' => $product->get_name(),
			'image' => self::image_url( $product ),
			'revit_version' => $snap['revit'],
			'product_version' => $snap['version'],
		);
	}
}
