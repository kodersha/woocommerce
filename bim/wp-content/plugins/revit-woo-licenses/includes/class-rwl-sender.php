<?php
defined( 'ABSPATH' ) || exit;

/**
 * Асинхронная отправка с повторами (Action Scheduler, входит в WooCommerce).
 * Оформление заказа никогда не блокируется: всё уходит в фоновую очередь.
 * Состояние позиции заказа хранится в её мета: _rwl_status = queued|sent|failed|skipped.
 */
class RWL_Sender {
	const HOOK_ITEM = 'rwl_send_item';
	const HOOK_VU   = 'rwl_send_version_update';
	const GROUP     = 'rwl';

	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'enqueue_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'enqueue_order' ) );
		add_action( self::HOOK_ITEM, array( __CLASS__, 'process_item' ), 10, 3 );
		add_action( self::HOOK_VU, array( __CLASS__, 'process_version_update' ), 10, 2 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ) );
		add_action( 'woocommerce_order_action_rwl_resend', array( __CLASS__, 'resend_order' ) );
	}

	/* ---------- постановка в очередь ---------- */

	public static function enqueue_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( in_array( $item->get_meta( '_rwl_status' ), array( 'queued', 'sent', 'skipped' ), true ) ) {
				continue; // защита от дублей при повторном срабатывании хуков статуса
			}
			if ( ! $order->get_user_id() ) {
				$item->update_meta_data( '_rwl_status', 'skipped' );
				$item->save_meta_data();
				RWL_Logger::info( "Заказ #{$order_id}: гостевой заказ, позиция {$item_id} не отправляется." );
				continue;
			}
			$item->update_meta_data( '_rwl_status', 'queued' );
			$item->save_meta_data();
			self::schedule( self::HOOK_ITEM, array( (int) $order_id, (int) $item_id, 0 ) );
		}
	}

	public static function enqueue_version_update( $product_id ) {
		self::schedule( self::HOOK_VU, array( (int) $product_id, 0 ) );
	}

	private static function schedule( $hook, $args, $delay = 0 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return;
			}
			as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
		} else {
			wp_schedule_single_event( time() + max( 1, $delay ), $hook, $args );
		}
	}

	private static function backoff( $attempt ) {
		return (int) min( 6 * HOUR_IN_SECONDS, 60 * pow( 2, max( 0, $attempt - 1 ) ) );
	}

	/* ---------- обработчики ---------- */

	public static function process_item( $order_id, $item_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || 'sent' === $item->get_meta( '_rwl_status' ) ) {
			return;
		}
		$payload = RWL_Payload::for_item( $order, $item );
		if ( is_wp_error( $payload ) ) {
			self::mark_failed( $order, $item, $payload->get_error_message() );
			return;
		}

		$res = self::post( $payload );
		$attempt++;

		if ( 'ok' === $res['status'] ) {
			$item->update_meta_data( '_rwl_status', 'sent' );
			$item->update_meta_data( '_rwl_sent_at', time() );
			$item->update_meta_data( '_rwl_attempts', $attempt );
			$item->delete_meta_data( '_rwl_error' );
			$item->save_meta_data();
			RWL_Logger::info( "Заказ #{$order_id}, позиция {$item_id}: отправлено (попытка {$attempt})." );
			return;
		}

		$item->update_meta_data( '_rwl_attempts', $attempt );
		$item->update_meta_data( '_rwl_error', $res['message'] );
		$item->save_meta_data();

		if ( 'retry' === $res['status'] && $attempt < (int) RWL_Settings::get( 'max_attempts' ) ) {
			RWL_Logger::error( "Заказ #{$order_id}, позиция {$item_id}: {$res['message']} — повтор (попытка {$attempt})." );
			self::schedule( self::HOOK_ITEM, array( (int) $order_id, (int) $item_id, $attempt ), self::backoff( $attempt ) );
			return;
		}
		self::mark_failed( $order, $item, $res['message'] );
	}

	private static function mark_failed( WC_Order $order, WC_Order_Item_Product $item, $message ) {
		$item->update_meta_data( '_rwl_status', 'failed' );
		$item->update_meta_data( '_rwl_error', $message );
		$item->save_meta_data();
		$order->add_order_note( 'Revit Licenses: не удалось отправить «' . $item->get_name() . '»: ' . $message . ' Можно повторить через «Действия с заказом».' );
		RWL_Logger::error( 'Заказ #' . $order->get_id() . ', позиция ' . $item->get_id() . ': окончательная ошибка — ' . $message );
	}

	public static function process_version_update( $product_id, $attempt = 0 ) {
		$payload = RWL_Payload::for_version_update( $product_id );
		if ( is_wp_error( $payload ) ) {
			return;
		}
		$res = self::post( $payload );
		$attempt++;
		if ( 'ok' === $res['status'] ) {
			RWL_Logger::info( "Товар #{$product_id}: обновление версии отправлено." );
			return;
		}
		RWL_Logger::error( "Товар #{$product_id}: версия не отправлена — {$res['message']}" );
		if ( 'retry' === $res['status'] && $attempt < (int) RWL_Settings::get( 'max_attempts' ) ) {
			self::schedule( self::HOOK_VU, array( (int) $product_id, $attempt ), self::backoff( $attempt ) );
		}
	}

	/* ---------- ручной повтор из заказа ---------- */

	public static function order_actions( $actions ) {
		$actions['rwl_resend'] = 'Revit: отправить данные повторно';
		return $actions;
	}

	public static function resend_order( $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && 'sent' !== $item->get_meta( '_rwl_status' ) ) {
				$item->delete_meta_data( '_rwl_status' );
				$item->delete_meta_data( '_rwl_attempts' );
				$item->save_meta_data();
			}
		}
		self::enqueue_order( $order->get_id() );
		$order->add_order_note( 'Revit Licenses: повторная отправка поставлена в очередь.' );
	}

	/* ---------- HTTP ---------- */

	/** @return array{status:string,message:string} status: ok|retry|fail */
	private static function post( array $payload ) {
		$url = (string) RWL_Settings::get( 'api_url' );
		if ( $url === '' ) {
			return array( 'status' => 'fail', 'message' => 'Не задан URL API в настройках.' );
		}
		$body    = wp_json_encode( $payload );
		$headers = array(
			'Content-Type'          => 'application/json; charset=utf-8',
			'Accept'                => 'application/json',
			'X-RWL-Idempotency-Key' => $payload['idempotency_key'],
		);
		$token = (string) RWL_Settings::get( 'api_token' );
		$mode  = RWL_Settings::get( 'auth_mode' );
		if ( $token !== '' && 'bearer' === $mode ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		} elseif ( $token !== '' && 'header' === $mode ) {
			$headers[ RWL_Settings::get( 'auth_header' ) ] = $token;
		}
		$secret = (string) RWL_Settings::get( 'hmac_secret' );
		if ( $secret !== '' ) {
			$ts                         = time();
			$headers['X-RWL-Timestamp'] = (string) $ts;
			$headers['X-RWL-Signature'] = 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret );
		}

		$response = wp_remote_post( $url, array(
			'timeout'     => (int) RWL_Settings::get( 'timeout' ),
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'retry', 'message' => 'Сеть: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( ( $code >= 200 && $code < 300 ) || 409 === $code ) { // 409 = сервер уже получил эту запись
			return array( 'status' => 'ok', 'message' => '' );
		}
		$msg = 'HTTP ' . $code;
		if ( 408 === $code || 429 === $code || $code >= 500 ) {
			return array( 'status' => 'retry', 'message' => $msg );
		}
		return array( 'status' => 'fail', 'message' => $msg . ' (проверьте URL и авторизацию)' );
	}
}
