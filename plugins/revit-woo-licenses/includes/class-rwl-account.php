<?php
defined( 'ABSPATH' ) || exit;

/** Лицензии покупателя в личном кабинете WooCommerce. */
class RWL_Account {
	const ENDPOINT = 'licenses';
	const REWRITE_VERSION = '1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'flush_rewrite_rules_once' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
	}
	public static function add_endpoint() { add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES ); }
	public static function flush_rewrite_rules_once() {
		if ( get_option( 'rwl_account_rewrite_version' ) === self::REWRITE_VERSION ) return;
		flush_rewrite_rules();
		update_option( 'rwl_account_rewrite_version', self::REWRITE_VERSION, false );
	}
	public static function query_vars( $vars ) { $vars[] = self::ENDPOINT; return $vars; }
	public static function menu_item( $items ) {
		$result = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) $result[ self::ENDPOINT ] = 'Мои лицензии';
			$result[ $key ] = $label;
		}
		if ( ! isset( $result[ self::ENDPOINT ] ) ) $result[ self::ENDPOINT ] = 'Мои лицензии';
		return $result;
	}
	public static function render() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) { echo '<p>Войдите в аккаунт, чтобы посмотреть лицензии.</p>'; return; }
		$licenses = self::get_user_licenses( $user_id );
		echo '<h2>Мои лицензии</h2>';
		if ( ! $licenses ) { echo '<p>Пока нет приобретённых лицензий.</p>'; return; }
		echo '<div class="rwl-account-licenses" style="overflow-x:auto"><table class="shop_table shop_table_responsive my_account_orders"><thead><tr><th>Продукт</th><th>Заказ</th><th>Начало</th><th>Действует до</th><th>Статус</th></tr></thead><tbody>';
		foreach ( $licenses as $license ) {
			echo '<tr><td data-title="Продукт">' . esc_html( $license['name'] ) . '</td>';
			echo '<td data-title="Заказ"><a href="' . esc_url( $license['order_url'] ) . '">#' . esc_html( $license['order_number'] ) . '</a></td>';
			echo '<td data-title="Начало">' . esc_html( $license['starts_at'] ) . '</td>';
			echo '<td data-title="Действует до">' . esc_html( $license['ends_at'] ) . '</td>';
			echo '<td data-title="Статус">' . esc_html( $license['active'] ? 'Активна' : 'Истекла' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	private static function get_user_licenses( $user_id ) {
		$orders = wc_get_orders( array( 'customer_id' => absint( $user_id ), 'status' => array( 'wc-processing', 'wc-completed' ), 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) );
		$licenses = array(); $now = time();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) continue;
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) continue;
				$product_id = $item->get_product_id();
				if ( ! RWL_Product::is_plugin( $product_id ) || isset( $licenses[ $product_id ] ) ) continue;
				$term = RWL_License::term_for_item( $item );
				if ( ! $term ) continue;
				$date = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
				$start = $date ? $date->getTimestamp() : 0;
				if ( ! $start ) continue;
				$perpetual = ! empty( $term['perpetual'] );
				$end = null;
				if ( ! $perpetual ) $end = RWL_License::end_timestamp( $start, $term );
				$product = wc_get_product( $product_id );
				$licenses[ $product_id ] = array(
					'name' => $product ? $product->get_name() : $item->get_name(),
					'order_number' => $order->get_order_number(),
					'order_url' => $order->get_view_order_url(),
					'starts_at' => wp_date( 'd.m.Y H:i', $start ),
					'ends_at' => $perpetual ? 'Бессрочно' : wp_date( 'd.m.Y H:i', $end ),
					'active' => $perpetual || $end > $now,
					'sort' => $start,
				);
			}
		}
		$licenses = array_values( $licenses );
		usort( $licenses, static function ( $a, $b ) { return $b['sort'] <=> $a['sort']; } );
		return $licenses;
	}
}
