<?php
defined( 'ABSPATH' ) || exit;

/** Массовый импорт версий из CSV/XLSX: загрузка → предпросмотр → подтверждение → отчёт. */
class RWL_Importer {
	const MAX_BYTES = 5242880; // 5 МБ
	const MAX_ROWS  = 10000;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Импорт версий', 'Импорт версий', 'manage_woocommerce', 'rwl-import', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		echo '<div class="wrap"><h1>Импорт версий товаров</h1>';
		$step = isset( $_POST['rwl_step'] ) ? sanitize_key( wp_unslash( $_POST['rwl_step'] ) ) : ''; // phpcs:ignore
		if ( 'preview' === $step ) {
			self::step_preview();
		} elseif ( 'apply' === $step ) {
			self::step_apply();
		} else {
			self::form();
		}
		echo '</div>';
	}

	private static function notice( $type, $msg ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . wp_kses_post( $msg ) . '</p></div>';
	}

	private static function form() {
		?>
		<p>Экспортируйте товары (Товары → Экспорт), оставьте/добавьте столбцы <code>product_id</code>, <code>revit_version</code>, <code>product_version</code> (допустим также столбец <code>ID</code>), заполните и загрузите CSV или XLSX.
		Пустые ячейки не меняют существующие значения. Другие свойства товаров не затрагиваются, новые товары не создаются.</p>
		<p><strong>Важно:</strong> в Excel версии вида «2.0» могут превратиться в число «2» — задавайте для столбца текстовый формат.</p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'rwl_import_preview' ); ?>
			<input type="hidden" name="rwl_step" value="preview">
			<input type="file" name="rwl_file" accept=".csv,.xlsx" required>
			<?php submit_button( 'Загрузить и показать предпросмотр', 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function step_preview() {
		check_admin_referer( 'rwl_import_preview' );
		$file = isset( $_FILES['rwl_file'] ) ? $_FILES['rwl_file'] : null; // phpcs:ignore
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::notice( 'error', 'Файл не загружен.' );
			return self::form();
		}
		if ( $file['size'] > self::MAX_BYTES ) {
			self::notice( 'error', 'Файл больше 5 МБ.' );
			return self::form();
		}
		$ext = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'xlsx' ), true ) ) {
			self::notice( 'error', 'Допустимы только файлы .csv и .xlsx.' );
			return self::form();
		}

		$table = 'xlsx' === $ext ? RWL_XLSX::read( $file['tmp_name'] ) : self::read_csv( $file['tmp_name'] );
		if ( is_wp_error( $table ) ) {
			self::notice( 'error', esc_html( $table->get_error_message() ) );
			return self::form();
		}
		$plan = self::build_plan( $table );
		if ( is_wp_error( $plan ) ) {
			self::notice( 'error', esc_html( $plan->get_error_message() ) );
			return self::form();
		}

		if ( $plan['errors'] ) {
			echo '<h2>Ошибки (эти строки будут пропущены)</h2><ul style="list-style:disc;margin-left:2em">';
			foreach ( $plan['errors'] as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! $plan['updates'] ) {
			self::notice( 'warning', 'Нет корректных строк для импорта.' );
			return self::form();
		}

		echo '<h2>Предпросмотр изменений</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Товар</th><th>Версия Revit</th><th>Версия товара</th><th>Статус</th></tr></thead><tbody>';
		$changed = 0;
		foreach ( $plan['updates'] as $u ) {
			$changed += $u['changed'] ? 1 : 0;
			echo '<tr><td>' . (int) $u['id'] . '</td><td>' . esc_html( $u['name'] ) . '</td><td>' . self::diff( $u['old_revit'], $u['new_revit'] ) . '</td><td>' . self::diff( $u['old_version'], $u['new_version'] ) . '</td><td>' . ( $u['changed'] ? 'будет обновлён' : 'без изменений' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $changed ) {
			$token = wp_generate_password( 20, false );
			set_transient( 'rwl_import_' . $token, array( 'user' => get_current_user_id(), 'updates' => $plan['updates'] ), HOUR_IN_SECONDS );
			echo '<form method="post" style="margin-top:1em">';
			wp_nonce_field( 'rwl_import_apply' );
			echo '<input type="hidden" name="rwl_step" value="apply"><input type="hidden" name="rwl_token" value="' . esc_attr( $token ) . '">';
			submit_button( 'Подтвердить импорт (' . $changed . ')', 'primary', 'submit', false );
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=rwl-import' ) ) . '">Отмена</a></form>';
		}
	}

	private static function diff( $old, $new ) {
		if ( $new === '' || $new === $old ) {
			return esc_html( $old !== '' ? $old : '—' );
		}
		return '<del>' . esc_html( $old !== '' ? $old : '—' ) . '</del> → <strong>' . esc_html( $new ) . '</strong>';
	}

	private static function step_apply() {
		check_admin_referer( 'rwl_import_apply' );
		$token = isset( $_POST['rwl_token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_POST['rwl_token'] ) ) : ''; // phpcs:ignore
		$data  = $token ? get_transient( 'rwl_import_' . $token ) : false;
		if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
			self::notice( 'error', 'Сессия импорта истекла. Загрузите файл заново.' );
			return self::form();
		}
		delete_transient( 'rwl_import_' . $token );

		$ok = 0; $skip = 0; $err = array();
		foreach ( $data['updates'] as $u ) {
			$id = (int) $u['id'];
			if ( ! current_user_can( 'edit_post', $id ) || ! wc_get_product( $id ) ) {
				$err[] = "ID {$id}: нет прав или товар не найден.";
				continue;
			}
			if ( ! $u['changed'] ) {
				$skip++;
				continue;
			}
			if ( $u['new_revit'] !== '' ) {
				update_post_meta( $id, RWL_Product::META_REVIT, $u['new_revit'] );
			}
			if ( $u['new_version'] !== '' ) {
				update_post_meta( $id, RWL_Product::META_VERSION, $u['new_version'] );
			}
			RWL_Sender::enqueue_version_update( $id );
			$ok++;
		}
		self::notice( 'success', "Обновлено товаров: <strong>{$ok}</strong>. Без изменений: {$skip}. Ошибок: " . count( $err ) . '.' );
		foreach ( $err as $e ) {
			self::notice( 'error', esc_html( $e ) );
		}
		RWL_Logger::info( "Импорт версий: обновлено {$ok}, без изменений {$skip}, ошибок " . count( $err ) . ' (пользователь #' . get_current_user_id() . ').' );
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=rwl-import' ) ) . '">Новый импорт</a></p>';
	}

	/* ---------- разбор и валидация ---------- */

	private static function read_csv( $path ) {
		$c = file_get_contents( $path ); // phpcs:ignore
		if ( false === $c || $c === '' ) {
			return new WP_Error( 'rwl_csv', 'Файл пуст.' );
		}
		$c = preg_replace( '/^\xEF\xBB\xBF/', '', $c );
		if ( ! mb_check_encoding( $c, 'UTF-8' ) ) {
			$c = mb_convert_encoding( $c, 'UTF-8', 'Windows-1251' );
		}
		$first = strtok( $c, "\n" );
		$delim = ',';
		$best  = -1;
		foreach ( array( ',', ';', "\t" ) as $d ) {
			$n = substr_count( (string) $first, $d );
			if ( $n > $best ) {
				$best  = $n;
				$delim = $d;
			}
		}
		$h = fopen( 'php://temp', 'r+' );
		fwrite( $h, $c );
		rewind( $h );
		$rows = array();
		while ( ( $r = fgetcsv( $h, 0, $delim, '"', '\\' ) ) !== false ) {
			$rows[] = array_map( 'trim', $r );
			if ( count( $rows ) > self::MAX_ROWS + 1 ) {
				break;
			}
		}
		fclose( $h );
		return $rows;
	}

	private static function build_plan( array $table ) {
		if ( count( $table ) > self::MAX_ROWS + 1 ) {
			return new WP_Error( 'rwl_rows', 'Слишком много строк (максимум ' . self::MAX_ROWS . ').' );
		}
		if ( ! $table ) {
			return new WP_Error( 'rwl_empty', 'Файл пуст.' );
		}
		$header = array_shift( $table );
		$cols   = array();
		foreach ( $header as $i => $h ) {
			$h = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
			$h = str_replace( array( ' ', '-' ), '_', $h );
			if ( 'id' === $h ) {
				$h = 'product_id';
			}
			if ( ! isset( $cols[ $h ] ) ) {
				$cols[ $h ] = $i;
			}
		}
		if ( ! isset( $cols['product_id'] ) ) {
			return new WP_Error( 'rwl_cols', 'Не найден столбец product_id.' );
		}
		if ( ! isset( $cols['revit_version'] ) && ! isset( $cols['product_version'] ) ) {
			return new WP_Error( 'rwl_cols', 'Нужен хотя бы один из столбцов: revit_version, product_version.' );
		}

		$cell = function ( $row, $name ) use ( $cols ) {
			return isset( $cols[ $name ], $row[ $cols[ $name ] ] ) ? trim( (string) $row[ $cols[ $name ] ] ) : '';
		};

		$updates = array(); $errors = array(); $seen = array();
		foreach ( $table as $n => $row ) {
			$line = $n + 2;
			if ( ! array_filter( $row, 'strlen' ) ) {
				continue;
			}
			$raw = $cell( $row, 'product_id' );
			if ( ! preg_match( '/^\d+(\.0+)?$/', $raw ) || (int) $raw <= 0 ) {
				$errors[] = "Строка {$line}: некорректный product_id «{$raw}».";
				continue;
			}
			$id = (int) $raw;
			if ( isset( $seen[ $id ] ) ) {
				$errors[] = "Строка {$line}: product_id {$id} повторяется (первая строка {$seen[$id]}).";
				continue;
			}
			$seen[ $id ] = $line;

			$product = wc_get_product( $id );
			if ( ! $product ) {
				$errors[] = "Строка {$line}: товар {$id} не найден (новые товары не создаются).";
				continue;
			}
			if ( $product->is_type( 'variation' ) ) {
				$errors[] = "Строка {$line}: {$id} — вариация; укажите ID родительского товара.";
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$errors[] = "Строка {$line}: нет прав на редактирование товара {$id}.";
				continue;
			}

			$revit = RWL_Product::clean_version( $cell( $row, 'revit_version' ) );
			$ver   = RWL_Product::clean_version( $cell( $row, 'product_version' ) );
			if ( $revit !== '' && ! preg_match( '/^[0-9A-Za-z\s.,;\-–—\/+]{1,50}$/u', $revit ) ) {
				$errors[] = "Строка {$line}: недопустимая версия Revit «{$revit}».";
				continue;
			}
			if ( $ver !== '' && ! preg_match( '/^[0-9A-Za-z.\-+_\s]{1,50}$/', $ver ) ) {
				$errors[] = "Строка {$line}: недопустимая версия товара «{$ver}».";
				continue;
			}
			if ( $revit === '' && $ver === '' ) {
				$errors[] = "Строка {$line}: нет данных для обновления (ID {$id}).";
				continue;
			}

			$old_r = (string) get_post_meta( $id, RWL_Product::META_REVIT, true );
			$old_v = (string) get_post_meta( $id, RWL_Product::META_VERSION, true );
			$updates[] = array(
				'id'          => $id,
				'name'        => $product->get_name(),
				'old_revit'   => $old_r,
				'old_version' => $old_v,
				'new_revit'   => $revit,
				'new_version' => $ver,
				'changed'     => ( $revit !== '' && $revit !== $old_r ) || ( $ver !== '' && $ver !== $old_v ),
			);
		}
		return array( 'updates' => $updates, 'errors' => $errors );
	}
}
