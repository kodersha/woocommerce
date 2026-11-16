<?php
/**
 * Plugin Name: License Auth Tokens
 * Description: Выдаёт access/refresh токены по логину (или email) и паролю WordPress для сторонних приложений.
 *
 * Эндпоинты (namespace license-auth/v1):
 *   POST /token, /refresh, /revoke
 *   GET  /me, /me/licenses, /jwks, /openid-configuration
 *   GET  /service/jwks, /service/openid-configuration
 *
 * Access token — JWT (RS256), короткоживущий, проверяется без обращения к БД.
 * Refresh token — случайная строка; в БД хранится только её SHA-256 хеш.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class License_Auth
{
    private const NAMESPACE = 'license-auth/v1';
    private const ACCESS_TTL = 15 * MINUTE_IN_SECONDS;
    private const REFRESH_TTL = 30 * DAY_IN_SECONDS;
    private const META_KEY = '_license_auth_refresh_tokens';
    private const DEFAULT_AUDIENCE = 'muratovbim-license-api';
    private const USER_KEYS = 'user';
    private const SERVICE_KEYS = 'service';
    private const DB_VERSION = '2';
    private const LOGIN_LIMIT = 5;
    private const LOGIN_WINDOW = 15 * MINUTE_IN_SECONDS;

    /** @var array<string, array<string, OpenSSLAsymmetricKey>> */
    private static array $keys = [];
    private static ?WP_Error $bearer_error = null;
    private static int $auth_depth = 0;

    /**
     * Temporary authentication diagnostics. The log is written outside the web root;
     * never include tokens, claims, user data, or key material in this log.
     */
    private static function auth_debug(string $stage): void
    {
        $file = dirname(ABSPATH) . '/license-auth-debug.log';
        $line = gmdate('Y-m-d H:i:s') . ' ' . $stage . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function init(): void
    {
        add_action('init', [self::class, 'ensure_schema'], 1);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_post_dispatch', [self::class, 'set_private_api_cache_headers'], 10, 3);
        add_filter('determine_current_user', [self::class, 'authenticate_bearer'], 20);
        add_filter('rest_authentication_errors', [self::class, 'report_bearer_error']);

        add_action('after_password_reset', static function ($user): void {
            self::revoke_all_refresh_tokens((int) $user->ID);
        });
        add_action('profile_update', static function ($user_id, $old_user): void {
            $user = get_userdata($user_id);
            if ($user && $old_user && $user->user_pass !== $old_user->user_pass) {
                self::revoke_all_refresh_tokens((int) $user_id);
            }
        }, 10, 2);
    }

    /** Create the refresh-token table without relying on an activation hook (this is a MU plugin). */
    public static function ensure_schema(): void
    {
        if (get_option('license_auth_db_version') === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::refresh_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            token_hash char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            created_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset_collate};";
        dbDelta($sql);

        $attempts_table = self::login_attempts_table();
        $attempts_sql = "CREATE TABLE {$attempts_table} (
            bucket_key varchar(96) NOT NULL,
            window_started bigint(20) unsigned NOT NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (bucket_key),
            KEY window_started (window_started)
        ) {$charset_collate};";
        dbDelta($attempts_sql);

        if (!self::migrate_legacy_refresh_tokens()) {
            // Retry migration on the next request rather than marking an incomplete schema as ready.
            return;
        }
        update_option('license_auth_db_version', self::DB_VERSION, false);
    }

    /**
     * Preserve still-valid refresh tokens issued by the previous user-meta implementation.
     * Migration is idempotent; old metadata is deleted only after all rows have been copied.
     */
    private static function migrate_legacy_refresh_tokens(): bool
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, meta_value FROM ' . $wpdb->usermeta . ' WHERE meta_key = %s',
            self::META_KEY
        ));
        if ($rows === null) {
            return false;
        }

        $now = time();
        $table = self::refresh_table();
        foreach ($rows as $row) {
            $tokens = maybe_unserialize($row->meta_value);
            if (!is_array($tokens)) {
                continue;
            }

            foreach ($tokens as $hash => $expires_at) {
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || (int) $expires_at <= $now) {
                    continue;
                }

                $result = $wpdb->query($wpdb->prepare(
                    'INSERT IGNORE INTO ' . $table . ' (token_hash, user_id, expires_at, created_at) VALUES (%s, %d, %d, %d)',
                    $hash,
                    (int) $row->user_id,
                    (int) $expires_at,
                    $now
                ));
                if ($result === false) {
                    return false;
                }
            }
        }

        foreach ($rows as $row) {
            delete_user_meta((int) $row->user_id, self::META_KEY);
        }
        return true;
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'issue_token'],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => ['type' => 'string', 'required' => true],
                'password' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/refresh', [
            'methods' => 'POST',
            'callback' => [self::class, 'refresh_token'],
            'permission_callback' => '__return_true',
            'args' => ['refresh_token' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/revoke', [
            'methods' => 'POST',
            'callback' => [self::class, 'revoke_token'],
            'permission_callback' => '__return_true',
            'args' => ['refresh_token' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/me', [
            'methods' => 'GET',
            'callback' => static fn() => rest_ensure_response(self::user_payload(wp_get_current_user())),
            'permission_callback' => static fn() => is_user_logged_in()
                ?: new WP_Error('license_auth_unauthorized', 'Требуется авторизация.', ['status' => 401]),
        ]);

        register_rest_route(self::NAMESPACE, '/me/licenses', [
            'methods' => 'GET',
            'callback' => [self::class, 'user_licenses'],
            'permission_callback' => static fn() => is_user_logged_in()
                ?: new WP_Error('license_auth_unauthorized', 'Требуется авторизация.', ['status' => 401]),
        ]);

        foreach ([self::USER_KEYS => '', self::SERVICE_KEYS => '/service'] as $ring => $prefix) {
            register_rest_route(self::NAMESPACE, $prefix . '/jwks', [
                'methods' => 'GET',
                'callback' => static fn() => self::jwks($ring),
                'permission_callback' => '__return_true',
            ]);

            register_rest_route(self::NAMESPACE, $prefix . '/openid-configuration', [
                'methods' => 'GET',
                'callback' => static fn() => rest_ensure_response([
                    'issuer' => self::issuer(),
                    'jwks_uri' => rest_url(self::NAMESPACE . $prefix . '/jwks'),
                    'id_token_signing_alg_values_supported' => ['RS256'],
                ]),
                'permission_callback' => '__return_true',
            ]);
        }
    }

    /**
     * Return all license purchases belonging to the authenticated WordPress user.
     * The user ID is taken only from the identity established by the verified Bearer token.
     */
    public static function user_licenses()
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error(
                'license_auth_unauthorized',
                'Требуется авторизация.',
                ['status' => 401]
            );
        }

        // The WooCommerce license plugin is the single source of truth.
        if (
            !function_exists('wc_get_orders')
            || !class_exists('RWL_Product')
            || !class_exists('RWL_License')
        ) {
            return new WP_Error(
                'license_auth_woocommerce_unavailable',
                'Модуль лицензий WooCommerce недоступен.',
                ['status' => 503]
            );
        }

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status'      => ['wc-processing', 'wc-completed'],
            'limit'       => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'return'      => 'objects',
        ]);

        if (!is_array($orders)) {
            return new WP_Error(
                'license_auth_licenses_unavailable',
                'Не удалось получить заказы WooCommerce.',
                ['status' => 500]
            );
        }

        $now = time();
        $aggregated = RWL_License::aggregate_orders($orders);
        $licenses = [];

        foreach ($aggregated as $row) {
            $perpetual = !empty($row['perpetual']);
            $end = $row['end'];
            $licenses[] = [
                'plugin_id'         => (int) $row['plugin_id'],
                'name'              => $row['name'],
                'license_start'     => gmdate('Y-m-d\\TH:i:s\\Z', $row['start']),
                'license_end'       => $end ? gmdate('Y-m-d\\TH:i:s\\Z', $end) : null,
                'license_perpetual' => $perpetual,
                'status'            => $perpetual || $end > $now ? 'active' : 'expired',
            ];
        }

        return rest_ensure_response(array_values($licenses));
    }

    private static function utc_datetime_to_iso(string $datetime): ?string
    {
        $timestamp = strtotime($datetime . ' UTC');
        return $timestamp === false ? null : gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
    }

    public static function service_token(string $audience, string $scope, int $ttl = MINUTE_IN_SECONDS): string
    {
        $now = time();

        return self::jwt_encode([
            'iss' => self::issuer(),
            'aud' => $audience,
            'sub' => 'wordpress',
            'scope' => $scope,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => wp_generate_uuid4(),
        ], self::SERVICE_KEYS);
    }

    public static function jwks(string $ring = self::USER_KEYS): WP_REST_Response
    {
        $public_keys = [];
        foreach (self::keys($ring) as $kid => $key) {
            $details = openssl_pkey_get_details($key);
            if (!$details || !isset($details['rsa'])) {
                continue;
            }
            $rsa = $details['rsa'];
            $public_keys[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $kid,
                'n' => self::b64url_encode($rsa['n']),
                'e' => self::b64url_encode($rsa['e']),
            ];
        }

        $response = rest_ensure_response(['keys' => $public_keys]);
        $response->header('Cache-Control', 'public, max-age=' . ($ring === self::SERVICE_KEYS ? 60 : 300));
        return $response;
    }

    public static function issue_token(WP_REST_Request $request)
    {
        $username = trim((string) $request['username']);
        $ip = self::client_ip();

        if (self::login_is_limited($username, $ip)) {
            return new WP_Error(
                'license_auth_rate_limited',
                'Слишком много неудачных попыток входа. Повторите позже.',
                ['status' => 429, 'retry_after' => self::LOGIN_WINDOW]
            );
        }

        // Match WordPress form authentication behavior for passwords containing quotes or backslashes.
        $user = wp_authenticate(wp_slash($username), wp_slash((string) $request['password']));
        if (is_wp_error($user)) {
            self::record_failed_login($username, $ip);
            return new WP_Error('license_auth_invalid_credentials', 'Неверный логин или пароль.', ['status' => 401]);
        }

        return self::token_response($user);
    }

    public static function refresh_token(WP_REST_Request $request)
    {
        $user = self::consume_refresh_token((string) $request['refresh_token']);
        if (!$user) {
            return new WP_Error('license_auth_invalid_refresh_token', 'Refresh token недействителен или истёк.', ['status' => 401]);
        }

        return self::token_response($user);
    }

    public static function revoke_token(WP_REST_Request $request)
    {
        self::consume_refresh_token((string) $request['refresh_token']);
        return rest_ensure_response(['revoked' => true]);
    }

    public static function authenticate_bearer($user_id)
    {
        // WordPress may re-enter determine_current_user while a filter used during
        // JWT validation is resolving site settings. Let the outer authentication
        // attempt finish instead of recursively validating the same Bearer token.
        if (self::$auth_depth > 0) {
            return $user_id;
        }

        // When an Authorization header is present, its identity must take
        // precedence over a browser cookie for the license API.
        $token = self::bearer_token();
        if ($token === null) {
            return $user_id;
        }

        self::$auth_depth++;
        self::auth_debug('A: bearer detected; depth=' . self::$auth_depth);
        self::auth_debug('B: before jwt_decode');
        $claims = self::jwt_decode($token);
        self::auth_debug('C: after jwt_decode; claims=' . ($claims ? 'yes' : 'no') . '; depth=' . self::$auth_depth);

        $subject = $claims['sub'] ?? '';
        self::auth_debug('D: before get_userdata; depth=' . self::$auth_depth);
        $user = $claims && is_string($subject) && ctype_digit($subject)
            ? get_userdata((int) $subject)
            : false;
        self::auth_debug('E: after get_userdata; user=' . ($user ? 'yes' : 'no') . '; depth=' . self::$auth_depth);

        if (!$user) {
            self::$bearer_error = new WP_Error(
                'license_auth_invalid_token',
                'Access token недействителен или истёк.',
                ['status' => 401]
            );
            self::$auth_depth--;
            self::auth_debug('G: returning unauthorized; depth=' . self::$auth_depth);
            return 0;
        }

        self::auth_debug('F: authentication successful; depth=' . self::$auth_depth);
        self::$auth_depth--;
        return $user->ID;
    }

    public static function report_bearer_error($result)
    {
        return $result ?? self::$bearer_error;
    }

    /** Prevent browsers, proxies, and compliant CDNs from caching token or user responses. */
    public static function set_private_api_cache_headers($response, $server, $request)
    {
        if (!$response instanceof WP_REST_Response) {
            return $response;
        }

        $route = $request->get_route();
        if (strpos($route, '/' . self::NAMESPACE . '/') !== 0) {
            return $response;
        }

        // Public key material and discovery metadata are the only cacheable API responses.
        if (preg_match('#^/' . preg_quote(self::NAMESPACE, '#') . '/(?:jwks|openid-configuration|service/jwks|service/openid-configuration)$#', $route)) {
            return $response;
        }

        $response->header('Cache-Control', 'no-store, private, max-age=0');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    private static function token_response(WP_User $user): WP_REST_Response
    {
        $now = time();
        $access_token = self::jwt_encode([
            'iss' => self::issuer(),
            'aud' => self::audience(),
            'sub' => (string) $user->ID,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL,
            'jti' => wp_generate_uuid4(),
            'email' => $user->user_email,
            'roles' => array_values($user->roles),
        ]);

        return rest_ensure_response([
            'token_type' => 'Bearer',
            'access_token' => $access_token,
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => self::create_refresh_token($user->ID),
            'refresh_expires_in' => self::REFRESH_TTL,
            'user' => self::user_payload($user),
        ]);
    }

    private static function user_payload(WP_User $user): array
    {
        return [
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => array_values($user->roles),
        ];
    }

    // ---- Refresh tokens ------------------------------------------------------

    private static function refresh_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'license_auth_refresh_tokens';
    }

    private static function create_refresh_token(int $user_id): string
    {
        global $wpdb;
        $secret = bin2hex(random_bytes(32));
        $hash = hash('sha256', $secret);
        $now = time();
        $inserted = $wpdb->insert(
            self::refresh_table(),
            [
                'token_hash' => $hash,
                'user_id' => $user_id,
                'expires_at' => $now + self::REFRESH_TTL,
                'created_at' => $now,
            ],
            ['%s', '%d', '%d', '%d']
        );

        if ($inserted !== 1) {
            throw new RuntimeException('Не удалось сохранить refresh token.');
        }

        return $user_id . '.' . $secret;
    }

    /** Atomically consumes a refresh token. Only one concurrent request can delete its row. */
    private static function consume_refresh_token(string $token): ?WP_User
    {
        global $wpdb;
        if (!preg_match('/^([1-9][0-9]*)\.([a-f0-9]{64})$/D', $token, $matches)) {
            return null;
        }

        $user_id = (int) $matches[1];
        $hash = hash('sha256', $matches[2]);
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::refresh_table() . ' WHERE token_hash = %s AND user_id = %d AND expires_at > %d',
            $hash,
            $user_id,
            time()
        ));

        if ($deleted !== 1) {
            return null;
        }

        return get_userdata($user_id) ?: null;
    }

    private static function revoke_all_refresh_tokens(int $user_id): void
    {
        global $wpdb;
        if ($user_id > 0) {
            $wpdb->delete(self::refresh_table(), ['user_id' => $user_id], ['%d']);
            // Remove tokens issued by the previous implementation during deployment.
            delete_user_meta($user_id, self::META_KEY);
        }
    }

    // ---- Login rate limiting -------------------------------------------------

    private static function client_ip(): string
    {
        // Do not trust X-Forwarded-For here; it is client-controlled unless a trusted proxy normalizes it.
        return sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    private static function login_bucket_keys(string $username, string $ip): array
    {
        $normalized = strtolower(trim($username));
        $identity = hash('sha256', $normalized);
        $ip_hash = hash('sha256', $ip);

        return [
            'license_auth_login_ip_' . $ip_hash,
            'license_auth_login_user_' . $identity,
        ];
    }

    private static function login_attempts_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'license_auth_login_attempts';
    }

    private static function login_is_limited(string $username, string $ip): bool
    {
        global $wpdb;
        $cutoff = time() - self::LOGIN_WINDOW;
        foreach (self::login_bucket_keys($username, $ip) as $key) {
            $attempts = $wpdb->get_var($wpdb->prepare(
                'SELECT attempts FROM ' . self::login_attempts_table() . ' WHERE bucket_key = %s AND window_started > %d',
                $key,
                $cutoff
            ));
            if ((int) $attempts >= self::LOGIN_LIMIT) {
                return true;
            }
        }
        return false;
    }

    private static function record_failed_login(string $username, string $ip): void
    {
        global $wpdb;
        $now = time();
        $cutoff = $now - self::LOGIN_WINDOW;
        $table = self::login_attempts_table();

        foreach (self::login_bucket_keys($username, $ip) as $key) {
            // Atomic upsert prevents concurrent failed requests from losing increments.
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $table . ' (bucket_key, window_started, attempts) VALUES (%s, %d, 1)
                 ON DUPLICATE KEY UPDATE
                    attempts = IF(window_started <= %d, 1, attempts + 1),
                    window_started = IF(window_started <= %d, %d, window_started)',
                $key,
                $now,
                $cutoff,
                $cutoff,
                $now
            ));
        }

        // Opportunistic cleanup keeps this table bounded.
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $table . ' WHERE window_started <= %d',
            $cutoff
        ));
    }

    // ---- JWT (RS256) ---------------------------------------------------------

    private static function jwt_encode(array $claims, string $ring = self::USER_KEYS): string
    {
        $kid = self::active_kid($ring);
        $key = self::keys($ring)[$kid] ?? null;
        if (!$key) {
            throw new RuntimeException("Ключ подписи '$kid' не найден в " . self::keys_dir($ring));
        }

        $header = self::b64url_encode(wp_json_encode(['alg' => 'RS256', 'typ' => 'at+jwt', 'kid' => $kid]));
        $payload = self::b64url_encode(wp_json_encode($claims));
        if (!openssl_sign("$header.$payload", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Не удалось подписать JWT.');
        }

        return "$header.$payload." . self::b64url_encode($signature);
    }

    private static function jwt_decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $head = json_decode(self::b64url_decode($header), true);
        $claims = json_decode(self::b64url_decode($payload), true);
        if (!is_array($head) || !is_array($claims)) {
            return null;
        }

        if (($head['alg'] ?? null) !== 'RS256'
            || ($head['typ'] ?? null) !== 'at+jwt'
            || !is_string($head['kid'] ?? null)
            || $head['kid'] === '') {
            return null;
        }

        self::auth_debug('J1: before keys');
        $key = self::keys(self::USER_KEYS)[$head['kid']] ?? null;
        self::auth_debug('J2: after keys; key=' . ($key ? 'yes' : 'no'));
        if (!$key) {
            return null;
        }

        self::auth_debug('J3: before openssl_pkey_get_details');
        $details = openssl_pkey_get_details($key);
        self::auth_debug('J4: after openssl_pkey_get_details');
        if (!$details || !isset($details['key'])) {
            return null;
        }

        self::auth_debug('J5: before openssl_pkey_get_public');
        $public = openssl_pkey_get_public($details['key']);
        self::auth_debug('J6: after openssl_pkey_get_public');
        if (!$public) {
            return null;
        }

        self::auth_debug('J7: before openssl_verify');
        $verified = openssl_verify("$header.$payload", self::b64url_decode($signature), $public, OPENSSL_ALGO_SHA256);
        self::auth_debug('J8: after openssl_verify; result=' . $verified);
        if ($verified !== 1) {
            return null;
        }

        self::auth_debug('J9: before required claims');
        $required = ['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'email', 'roles'];
        foreach ($required as $claim) {
            if (!array_key_exists($claim, $claims)) {
                self::auth_debug('J10: missing required claim');
                return null;
            }
        }
        self::auth_debug('J11: required claims present');

        $aud = $claims['aud'];
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);

        self::auth_debug('J12: before issuer()');
        $expected_issuer = self::issuer();
        self::auth_debug('J13: after issuer()');

        self::auth_debug('J14: before audience()');
        $expected_audience = self::audience();
        self::auth_debug('J15: after audience()');

        self::auth_debug('J16: before claim validation');
        if (!is_string($claims['iss'])
            || $claims['iss'] !== $expected_issuer
            || !in_array($expected_audience, $audiences, true)
            || !is_string($claims['sub'])
            || !ctype_digit($claims['sub'])
            || !is_int($claims['iat']) && !ctype_digit((string) $claims['iat'])
            || !is_int($claims['exp']) && !ctype_digit((string) $claims['exp'])
            || (int) $claims['exp'] <= time()
            || (int) $claims['iat'] > time() + 60
            || !is_string($claims['jti'])
            || $claims['jti'] === ''
            || !is_string($claims['email'])
            || !is_array($claims['roles'])) {
            self::auth_debug('J17: claim validation failed');
            return null;
        }
        self::auth_debug('J18: claim validation passed');

        foreach ($claims['roles'] as $role) {
            if (!is_string($role)) {
                self::auth_debug('J19: invalid role type');
                return null;
            }
        }

        self::auth_debug('J20: jwt_decode returning claims');
        return $claims;
    }

    /** @return array<string, OpenSSLAsymmetricKey> */
    private static function keys(string $ring): array
    {
        if (isset(self::$keys[$ring])) {
            return self::$keys[$ring];
        }

        self::$keys[$ring] = [];
        foreach (glob(self::keys_dir($ring) . '/*.pem') ?: [] as $file) {
            $key = openssl_pkey_get_private((string) file_get_contents($file));
            if (!$key) {
                continue;
            }
            $details = openssl_pkey_get_details($key);
            // Refuse weak or non-RSA private keys instead of publishing/signing with them.
            if (!$details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 3072) {
                continue;
            }
            self::$keys[$ring][basename($file, '.pem')] = $key;
        }

        return self::$keys[$ring];
    }

    private static function active_kid(string $ring): string
    {
        $file = self::keys_dir($ring) . '/active';
        return is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }

    private static function keys_dir(string $ring): string
    {
        if ($ring === self::SERVICE_KEYS) {
            return defined('LICENSE_AUTH_SERVICE_KEYS_DIR') ? LICENSE_AUTH_SERVICE_KEYS_DIR : dirname(ABSPATH) . '/service-keys';
        }
        return defined('LICENSE_AUTH_KEYS_DIR') ? LICENSE_AUTH_KEYS_DIR : dirname(ABSPATH) . '/keys';
    }

    private static function issuer(): string
    {
        // Configure this constant in wp-config.php to keep JWT validation
        // independent of WordPress URL filters and their authentication hooks.
        if (defined('LICENSE_AUTH_ISSUER') && is_string(LICENSE_AUTH_ISSUER) && LICENSE_AUTH_ISSUER !== '') {
            return untrailingslashit(LICENSE_AUTH_ISSUER);
        }

        // Backward-compatible fallback for installations that have not yet
        // configured LICENSE_AUTH_ISSUER.
        return untrailingslashit(get_site_url());
    }

    private static function audience(): string
    {
        return defined('LICENSE_AUTH_AUDIENCE') ? LICENSE_AUTH_AUDIENCE : self::DEFAULT_AUDIENCE;
    }

    private static function bearer_token(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = array_change_key_case(apache_request_headers());
            $header = $headers['authorization'] ?? '';
        }

        return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : null;
    }

    private static function b64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}

License_Auth::init();
