<?php
defined( 'ABSPATH' ) || exit;

/** Поля карточки товара и вывод версий на витрине. */
class RWL_Product {
	const META_PLUGIN  = '_rwl_is_plugin';       // yes|no
	const META_REVIT   = '_rwl_revit_version';
	const META_VERSION = '_rwl_product_version';
	const META_TERM = '_rwl_license_term'; // единый срок для товара и всех его вариаций

	/** Фиксированный список допустимых сроков лицензии. */
	public static function license_terms() {
		return array(
			'1_week'   => '1 неделя',
			'2_weeks'  => '2 недели',
			'1_month'  => '1 месяц',
			'3_months' => '3 месяца',
			'6_months' => 'Полгода',
			'12_months'=> '1 год',
			'perpetual'=> 'Бессрочно',
		);
	}


	/** Повторно запускает поиск вариации, если тема меняет select до инициализации WooCommerce. */
	public static function enqueue_variation_fix() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return;
		}
		wp_enqueue_script( 'wc-add-to-cart-variation' );
		$script = "jQuery(function($){var init=function(){ $('.variations_form').each(function(){var form=$(this);var fields=form.find('.variations select');if(fields.length && fields.filter(function(){return !$(this).val();}).length===0){form.trigger('check_variations');}});};window.setTimeout(init,180);});";
		wp_add_inline_script( 'wc-add-to-cart-variation', $script, 'after' );
	}

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_variation_fix' ), 20 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_public' ), 25 );
	}

	public static function is_plugin( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META_PLUGIN, true );
	}

	public static function snapshot( $product_id ) {
		return array(
			'plugin'  => self::is_plugin( $product_id ) ? 'yes' : 'no',
			'revit'   => (string) get_post_meta( $product_id, self::META_REVIT, true ),
			'version' => (string) get_post_meta( $product_id, self::META_VERSION, true ),
		);
	}

	public static function clean_version( $v ) {
		$v = trim( sanitize_text_field( (string) $v ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 50 ) : substr( $v, 0, 50 );
	}

	public static function render_fields() {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox( array(
			'id'          => self::META_PLUGIN,
			'label'       => 'Плагин',
			'description' => 'Товар — плагин с подпиской (лицензией). Без галочки — файловый товар (семейство), без лицензии.',
		) );
		woocommerce_wp_text_input( array(
			'id'          => self::META_REVIT,
			'label'       => 'Версия Revit',
			'placeholder' => '2024–2026',
			'desc_tip'    => true,
			'description' => 'Версия(и) Autodesk Revit, с которыми совместим товар.',
		) );
		woocommerce_wp_text_input( array(
			'id'          => self::META_VERSION,
			'label'       => 'Версия товара',
			'placeholder' => '2.1.0',
			'desc_tip'    => true,
			'description' => 'Версия плагина или семейства.',
		) );
		global $post;
		$post_id = $post instanceof WP_Post ? $post->ID : 0;
		$term = $post_id ? get_post_meta( $post_id, self::META_TERM, true ) : '';
		if ( ! array_key_exists( $term, self::license_terms() ) ) {
			$term = 'perpetual';
		}
		woocommerce_wp_select( array(
			'id'          => self::META_TERM,
			'value'       => $term,
			'label'       => 'Срок лицензии',
			'options'     => self::license_terms(),
			'desc_tip'    => true,
			'description' => 'Единый срок лицензии для товара и всех его вариаций. Месяцы рассчитываются календарно. Для бессрочной лицензии дата окончания не устанавливается.',
		) );
		echo '</div>';
	}

	public static function save( $post_id ) {
		// Nonce и права проверяет WooCommerce до вызова этого хука; дополнительно проверяем право на запись.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$old = self::snapshot( $post_id );

		update_post_meta( $post_id, self::META_PLUGIN, isset( $_POST[ self::META_PLUGIN ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( array( self::META_REVIT, self::META_VERSION ) as $key ) {
			$val = isset( $_POST[ $key ] ) ? self::clean_version( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore
			update_post_meta( $post_id, $key, $val );
		}
		$term = isset( $_POST[ self::META_TERM ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_TERM ] ) ) : 'perpetual'; // phpcs:ignore WordPress.Security.NonceVerification
		$terms = self::license_terms();
		if ( ! array_key_exists( $term, $terms ) ) {
			$term = 'perpetual';
		}
		update_post_meta( $post_id, self::META_TERM, $term );
		// Старые поля больше не участвуют в определении срока.
		delete_post_meta( $post_id, '_rwl_license_term_mode' );
		delete_post_meta( $post_id, '_rwl_license_weeks' );

		if ( $old !== self::snapshot( $post_id ) ) {
			RWL_Sender::enqueue_version_update( $post_id );
		}
	}

	/** Версия программы/актуальная версия и под ней версия Revit — в блоке summary. */
	public static function render_public() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$snap = self::snapshot( $product->get_id() );
		$rows = array();
		if ( $snap['version'] !== '' ) {
			$label = 'yes' === $snap['plugin'] ? 'Версия программы' : 'Актуальная версия';
			$rows[ $label ] = $snap['version'];
		}
		if ( $snap['revit'] !== '' ) {
			$rows['Версия Revit'] = $snap['revit'];
		}
		if ( ! $rows ) {
			return;
		}
		echo '<ul class="rwl-product-info" style="list-style:none;margin:0 0 1em;padding:0">';
		foreach ( $rows as $label => $value ) {
			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
		}
		echo '</ul>';
	}
}
