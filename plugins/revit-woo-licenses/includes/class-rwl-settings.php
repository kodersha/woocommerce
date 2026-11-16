<?php
defined( 'ABSPATH' ) || exit;

class RWL_Settings {
	const OPTION = 'rwl_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function defaults() {
		return array(
			'api_url'        => '',
			'auth_mode'      => 'bearer', // none | bearer | header
			'auth_header'    => 'X-API-Key',
			'api_token'      => '',
			'hmac_secret'    => '',
			'timeout'        => 15,
			'max_attempts'   => 8,
			'attribute_slug' => '',
			'duration_map'   => '',
			'api_supports_perpetual' => 'no',
		);
	}

	public static function all() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function get( $key ) {
		$a = self::all();
		return isset( $a[ $key ] ) ? $a[ $key ] : null;
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Revit Licenses', 'Revit Licenses', 'manage_woocommerce', 'rwl-settings', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting( 'rwl_settings_group', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$old = self::all();
		$out = self::defaults();
		$in  = is_array( $in ) ? wp_unslash( $in ) : array();

		$url = isset( $in['api_url'] ) ? trim( $in['api_url'] ) : '';
		if ( $url !== '' ) {
			$url    = esc_url_raw( $url );
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				add_settings_error( self::OPTION, 'rwl_url', 'Некорректный URL API.' );
				$url = '';
			} elseif ( 'https' !== $scheme && 'production' === wp_get_environment_type() ) {
				add_settings_error( self::OPTION, 'rwl_https', 'На production разрешён только HTTPS.' );
				$url = '';
			}
		}
		$out['api_url'] = $url;

		$mode             = isset( $in['auth_mode'] ) ? $in['auth_mode'] : 'bearer';
		$out['auth_mode'] = in_array( $mode, array( 'none', 'bearer', 'header' ), true ) ? $mode : 'bearer';
		$out['auth_header'] = preg_replace( '/[^A-Za-z0-9\-]/', '', isset( $in['auth_header'] ) ? $in['auth_header'] : 'X-API-Key' );
		if ( $out['auth_header'] === '' ) {
			$out['auth_header'] = 'X-API-Key';
		}
		// Пустое поле секрета = оставить прежнее значение.
		$out['api_token']   = ! empty( $in['api_token'] ) ? sanitize_text_field( $in['api_token'] ) : $old['api_token'];
		$out['hmac_secret'] = ! empty( $in['hmac_secret'] ) ? sanitize_text_field( $in['hmac_secret'] ) : $old['hmac_secret'];
		if ( ! empty( $in['clear_secrets'] ) ) {
			$out['api_token']   = '';
			$out['hmac_secret'] = '';
		}
		$out['timeout']        = max( 5, min( 60, (int) ( isset( $in['timeout'] ) ? $in['timeout'] : 15 ) ) );
		$out['max_attempts']   = max( 1, min( 20, (int) ( isset( $in['max_attempts'] ) ? $in['max_attempts'] : 8 ) ) );
		$out['attribute_slug'] = sanitize_title( isset( $in['attribute_slug'] ) ? $in['attribute_slug'] : '' );
		$out['duration_map']   = sanitize_textarea_field( isset( $in['duration_map'] ) ? $in['duration_map'] : '' );
		$out['api_supports_perpetual'] = ! empty( $in['api_supports_perpetual'] ) ? 'yes' : 'no';
		return $out;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		$s = self::all();
		$n = self::OPTION;
		?>
		<div class="wrap">
			<h1>Revit Licenses — настройки</h1>
			<?php settings_errors( self::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'rwl_settings_group' ); ?>
				<h2>Внешний сервер</h2>
				<table class="form-table" role="presentation">
					<tr><th>URL API</th><td><input type="url" class="regular-text" name="<?php echo $n; ?>[api_url]" value="<?php echo esc_attr( $s['api_url'] ); ?>" placeholder="https://example.com/wp-json/revit/v1/purchases">
						<p class="description">На этот адрес POST-запросом уходит JSON (по одному запросу на позицию заказа).</p></td></tr>
					<tr><th>Авторизация</th><td>
						<select name="<?php echo $n; ?>[auth_mode]">
							<option value="bearer" <?php selected( $s['auth_mode'], 'bearer' ); ?>>Authorization: Bearer &lt;токен&gt;</option>
							<option value="header" <?php selected( $s['auth_mode'], 'header' ); ?>>Свой заголовок</option>
							<option value="none" <?php selected( $s['auth_mode'], 'none' ); ?>>Без авторизации</option>
						</select>
						Имя заголовка: <input type="text" name="<?php echo $n; ?>[auth_header]" value="<?php echo esc_attr( $s['auth_header'] ); ?>" size="16"></td></tr>
					<tr><th>Токен / ключ API</th><td><input type="password" class="regular-text" autocomplete="new-password" name="<?php echo $n; ?>[api_token]" placeholder="<?php echo $s['api_token'] ? '•••••• (сохранён, оставьте пустым чтобы не менять)' : ''; ?>"></td></tr>
					<tr><th>HMAC-секрет (необязательно)</th><td><input type="password" class="regular-text" autocomplete="new-password" name="<?php echo $n; ?>[hmac_secret]" placeholder="<?php echo $s['hmac_secret'] ? '•••••• (сохранён)' : ''; ?>">
						<p class="description">Если задан, добавляются заголовки X-RWL-Timestamp и X-RWL-Signature = sha256 HMAC от «timestamp.тело».</p>
						<label><input type="checkbox" name="<?php echo $n; ?>[clear_secrets]" value="1"> Удалить сохранённые токен и секрет</label></td></tr>
						<tr><th>Таймаут, сек</th><td><input type="number" min="5" max="60" name="<?php echo $n; ?>[timeout]" value="<?php echo (int) $s['timeout']; ?>"></td></tr>
					<tr><th>Макс. попыток отправки</th><td><input type="number" min="1" max="20" name="<?php echo $n; ?>[max_attempts]" value="<?php echo (int) $s['max_attempts']; ?>">
						<p class="description">Повторы с нарастающей паузой (1 мин, 2, 4 … до 6 ч).</p></td></tr>
				</table>
				<h2>Срок лицензии по атрибуту товара (необязательно)</h2>
				<table class="form-table" role="presentation">
					<tr><th>Атрибут срока</th><td><input type="text" class="regular-text" name="<?php echo $n; ?>[attribute_slug]" value="<?php echo esc_attr( $s['attribute_slug'] ); ?>" placeholder="srok или pa_srok">
						<p class="description">Укажите slug глобального атрибута WooCommerce (без префикса pa_ или с ним). Срок определяется по фактическому значению выбранной вариации.</p></td></tr>
					<tr><th>Соответствие значений срокам</th><td><textarea class="large-text code" rows="6" name="<?php echo $n; ?>[duration_map]"><?php echo esc_textarea( $s['duration_map'] ); ?></textarea>
						<p class="description">По одной записи на строку: <code>Название значения=единица:число</code>. Например:<br><code>31 день=days:31</code><br><code>Полгода=months:6</code><br><code>1 год=52</code> (старый формат — недели).</p></td></tr>
				</table>
				<p class="description">Если атрибут настроен, пустое значение означает бессрочную лицензию; неизвестное значение блокирует расчёт и записывается в журнал. Срок из карточки товара используется только когда атрибут срока не настроен.</p>
				<h2>Бессрочные лицензии</h2>
				<table class="form-table" role="presentation"><tr><th>Поддержка внешним API</th><td><label><input type="checkbox" name="<?php echo $n; ?>[api_supports_perpetual]" value="1" <?php checked( $s['api_supports_perpetual'], 'yes' ); ?>> Внешний сервер принимает <code>license_perpetual: true</code> и <code>license_end: null</code></label><p class="description">Включайте только после добавления поддержки на сервере лицензий. Пока настройка выключена, отправка бессрочной лицензии будет остановлена с понятной ошибкой.</p></td></tr></table>
				<?php submit_button(); ?>
			</form>
			<p>Журнал отправки: WooCommerce → Статус → Журналы → источник <code>rwl</code>. Повторная отправка заказа: в карточке заказа → «Действия с заказом» → «Revit: отправить данные повторно».</p>
		</div>
		<?php
	}
}
