<?php
/**
 * Plugin Name: License Shop (mock)
 * Description: Мок покупки лицензий: шорткод [license_shop] с кнопкой «Купить», запись покупки в БД сайта
 *              и отправка лицензии на сервер лицензий. Оплаты нет — покупка выдаётся сразу.
 *
 *   API /me/licenses читает покупки текущего пользователя из таблицы license_purchases.
 *   Выдача и синхронизация покупок с сервером лицензий выполняются независимо от этого API.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class License_Shop
{
    private const DB_VERSION = '1';
    private const ACTION = 'license_purchase';
    private const DELETE_ACTION = 'license_purchase_delete';
    private const WRITE_SCOPE = 'licenses:write';
    private const DEFAULT_SERVICE_AUDIENCE = 'muratovbim-license-admin';
    private const DEFAULT_DURATION_DAYS = 365;
    private const MAX_DURATION_DAYS = 3650;

    public static function init(): void
    {
        add_action('init', [self::class, 'install']);
        add_shortcode('license_shop', [self::class, 'render']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle_purchase']);
        add_action('admin_post_' . self::DELETE_ACTION, [self::class, 'handle_delete']);

        $to_login = static function () {
            wp_safe_redirect(wp_login_url(wp_get_referer() ?: home_url()));
            exit;
        };
        add_action('admin_post_nopriv_' . self::ACTION, $to_login);
        add_action('admin_post_nopriv_' . self::DELETE_ACTION, $to_login);
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'license_purchases';
    }

    public static function install(): void
    {
        if (get_option('license_shop_db_version') === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Время в UTC; synced_at/sync_error — результат последней отправки на сервер лицензий
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            plugin_id int(10) unsigned NOT NULL,
            starts_at datetime NOT NULL,
            ends_at datetime NOT NULL,
            created_at datetime NOT NULL,
            synced_at datetime DEFAULT NULL,
            sync_error text DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) {$wpdb->get_charset_collate()};");

        update_option('license_shop_db_version', self::DB_VERSION);
    }

    public static function handle_purchase(): void
    {
        check_admin_referer(self::ACTION);

        $user_id = get_current_user_id();
        $plugin_id = absint($_POST['plugin_id'] ?? 0);
        $back = wp_get_referer() ?: home_url();

        if ($plugin_id <= 0) {
            wp_safe_redirect(add_query_arg('license_error', 'plugin', $back));
            exit;
        }

        // Срок задаёт покупатель (сайт технический — так удобно проверять истечение лицензии в Revit).
        // Поля не пришли (старая форма) — срок по умолчанию
        $days = isset($_POST['duration_days']) ? absint($_POST['duration_days']) : self::default_days();
        $hours = isset($_POST['duration_hours']) ? absint($_POST['duration_hours']) : 0;
        $duration = $days * DAY_IN_SECONDS + $hours * HOUR_IN_SECONDS;
        if ($duration <= 0 || $days > self::MAX_DURATION_DAYS || $hours > 23) {
            wp_safe_redirect(add_query_arg('license_error', 'duration', $back));
            exit;
        }

        // Мок оплаты: покупка выдаётся сразу
        global $wpdb;
        $now = time();
        $wpdb->insert(self::table(), [
            'user_id' => $user_id,
            'plugin_id' => $plugin_id,
            'starts_at' => gmdate('Y-m-d H:i:s', $now),
            'ends_at' => gmdate('Y-m-d H:i:s', $now + $duration),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
        $purchase_id = (int) $wpdb->insert_id;

        // Если отправка не удалась, сервер лицензий всё равно получит покупку при следующем открытии сессии
        self::push($purchase_id);

        wp_safe_redirect(add_query_arg('license_purchased', $purchase_id, remove_query_arg(['license_error', 'license_purchased'], $back)));
        exit;
    }

    /**
     * Удаляет свою покупку (мок возврата). Сервер лицензий отдельно не уведомляется: он узнаёт об удалении
     * при ближайшей сверке (open/ping из Revit) и удаляет лицензию сам.
     */
    public static function handle_delete(): void
    {
        $purchase_id = absint($_POST['purchase_id'] ?? 0);
        check_admin_referer(self::DELETE_ACTION . '_' . $purchase_id);

        global $wpdb;
        // Только свою покупку: user_id в условии не даёт удалить чужую, подставив другой id
        $deleted = $wpdb->delete(self::table(), ['id' => $purchase_id, 'user_id' => get_current_user_id()]);

        $back = remove_query_arg(['license_error', 'license_purchased', 'license_deleted'], wp_get_referer() ?: home_url());
        wp_safe_redirect($deleted
            ? add_query_arg('license_deleted', $purchase_id, $back)
            : add_query_arg('license_error', 'not_found', $back));
        exit;
    }

    /** Отправляет покупку на сервер лицензий и запоминает результат. */
    public static function push(int $purchase_id): bool
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $purchase_id));
        if (!$row) {
            return false;
        }

        $error = null;
        if (!defined('LICENSE_BACKEND_URL')) {
            $error = 'Не задан LICENSE_BACKEND_URL';
        } else {
            $audience = defined('LICENSE_SERVICE_AUDIENCE') ? LICENSE_SERVICE_AUDIENCE : self::DEFAULT_SERVICE_AUDIENCE;
            $api = self::to_api($row);
            $response = wp_remote_post(trailingslashit(LICENSE_BACKEND_URL) . 'api/licenses', [
                'timeout' => 5,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . License_Auth::service_token($audience, self::WRITE_SCOPE),
                ],
                'body' => wp_json_encode([
                    'externalId' => 'wp:' . $row->id,
                    'userId' => (int) $row->user_id,
                    'pluginId' => $api['plugin_id'],
                    'startsAt' => $api['starts_at'],
                    'endsAt' => $api['ends_at'],
                ]),
            ]);

            if (is_wp_error($response)) {
                $error = $response->get_error_message();
            } elseif (wp_remote_retrieve_response_code($response) !== 200) {
                $error = 'HTTP ' . wp_remote_retrieve_response_code($response) . ': ' . wp_remote_retrieve_body($response);
            }
        }

        $wpdb->update(self::table(), [
            'synced_at' => $error === null ? gmdate('Y-m-d H:i:s') : null,
            'sync_error' => $error === null ? null : mb_substr($error, 0, 1000),
        ], ['id' => $purchase_id]);

        return $error === null;
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return sprintf('<p><a href="%s">Войдите</a>, чтобы купить лицензию.</p>', esc_url(wp_login_url(get_permalink())));
        }

        ob_start();

        if (isset($_GET['license_purchased'])) {
            $row = self::purchase((int) $_GET['license_purchased'], get_current_user_id());
            if ($row) {
                $synced = $row->synced_at !== null;
                printf(
                    '<p style="padding:8px 12px;background:%s">Лицензия на плагин %d куплена до %s. %s</p>',
                    $synced ? '#e7f6ec' : '#fff4e5',
                    (int) $row->plugin_id,
                    esc_html(self::local_date($row->ends_at)),
                    $synced
                        ? 'Сервер лицензий обновлён.'
                        : 'Сервер лицензий пока не ответил — лицензия подтянется при следующем запуске плагина.'
                );
            }
        }

        if (isset($_GET['license_deleted'])) {
            echo '<p style="padding:8px 12px;background:#e7f6ec">Покупка удалена. Сервер лицензий уберёт лицензию '
                . 'при ближайшей проверке из Revit — у запущенного Revit это не дольше нескольких минут.</p>';
        }

        if (($_GET['license_error'] ?? '') === 'plugin') {
            echo '<p style="padding:8px 12px;background:#fdecea">Укажите номер плагина — целое число больше нуля.</p>';
        }

        if (($_GET['license_error'] ?? '') === 'duration') {
            printf(
                '<p style="padding:8px 12px;background:#fdecea">Укажите срок: от 1 часа до %d дней, часы — от 0 до 23.</p>',
                self::MAX_DURATION_DAYS
            );
        }

        if (($_GET['license_error'] ?? '') === 'not_found') {
            echo '<p style="padding:8px 12px;background:#fdecea">Покупка не найдена — возможно, она уже удалена.</p>';
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?php wp_nonce_field(self::ACTION); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <label for="license-plugin-id">ID плагина</label>
            <input id="license-plugin-id" type="number" name="plugin_id" min="1" step="1" value="1" required style="width:6em">
            <label for="license-days">Срок: дней</label>
            <input id="license-days" type="number" name="duration_days" min="0" max="<?php echo (int) self::MAX_DURATION_DAYS; ?>" step="1"
                   value="<?php echo (int) self::default_days(); ?>" required style="width:6em">
            <label for="license-hours">часов</label>
            <input id="license-hours" type="number" name="duration_hours" min="0" max="23" step="1" value="0" required style="width:4em">
            <button type="submit">Купить</button>
        </form>
        <?php

        $purchases = self::purchases(get_current_user_id());
        if ($purchases) {
            echo '<h3>Мои лицензии</h3><table><thead><tr><th>Плагин</th><th>Начало</th><th>Окончание</th><th>Сервер лицензий</th><th></th></tr></thead><tbody>';
            foreach ($purchases as $row) {
                printf(
                    '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    (int) $row->plugin_id,
                    esc_html(self::local_date($row->starts_at)),
                    esc_html(self::local_date($row->ends_at)),
                    $row->synced_at !== null ? 'передана' : 'ожидает: ' . esc_html((string) $row->sync_error),
                    self::delete_button((int) $row->id, (int) $row->plugin_id)
                );
            }
            echo '</tbody></table>';
        }

        return (string) ob_get_clean();
    }

    private static function default_days(): int
    {
        return defined('LICENSE_DURATION_DAYS') ? (int) LICENSE_DURATION_DAYS : self::DEFAULT_DURATION_DAYS;
    }

    private static function delete_button(int $purchase_id, int $plugin_id): string
    {
        // Отдельный nonce на каждую покупку: ссылку на удаление одной покупки нельзя переиспользовать для другой
        return sprintf(
            '<form method="post" action="%s" style="margin:0" onsubmit="return confirm(%s)">%s'
            . '<input type="hidden" name="action" value="%s"><input type="hidden" name="purchase_id" value="%d">'
            . '<button type="submit">Удалить покупку</button></form>',
            esc_url(admin_url('admin-post.php')),
            esc_attr(wp_json_encode("Удалить покупку лицензии на плагин {$plugin_id}?")),
            wp_nonce_field(self::DELETE_ACTION . '_' . $purchase_id, '_wpnonce', true, false),
            esc_attr(self::DELETE_ACTION),
            $purchase_id
        );
    }

    /** @return object[] */
    private static function purchases(int $user_id): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC',
            $user_id
        ));
    }

    private static function purchase(int $id, int $user_id): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d AND user_id = %d',
            $id,
            $user_id
        )) ?: null;
    }

    private static function to_api(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'plugin_id' => (int) $row->plugin_id,
            'starts_at' => self::iso($row->starts_at),
            'ends_at' => self::iso($row->ends_at),
        ];
    }

    private static function iso(string $utc): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($utc . ' UTC'));
    }

    private static function local_date(string $utc): string
    {
        return wp_date('d.m.Y H:i', strtotime($utc . ' UTC'));
    }
}

License_Shop::init();
