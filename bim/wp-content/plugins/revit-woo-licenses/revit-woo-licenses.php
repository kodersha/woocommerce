<?php
/**
 * Plugin Name: Revit Woo Licenses
 * Description: Версии Revit/товаров, плагины и файловые товары, лицензии по сроку подписки, передача данных о покупках на внешний сервер, массовый импорт версий из CSV/XLSX.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * Text Domain: rwl
 */

defined( 'ABSPATH' ) || exit;

define( 'RWL_VERSION', '1.0.0' );
define( 'RWL_FILE', __FILE__ );
define( 'RWL_DIR', plugin_dir_path( __FILE__ ) );

// Совместимость с HPOS (High-Performance Order Storage).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RWL_FILE, true );
	}
} );

register_deactivation_hook( RWL_FILE, 'flush_rewrite_rules' );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>Revit Woo Licenses требует активного плагина WooCommerce.</p></div>';
		} );
		return;
	}
	foreach ( array( 'logger', 'settings', 'product', 'license', 'payload', 'sender', 'xlsx', 'importer', 'account' ) as $f ) {
		require_once RWL_DIR . 'includes/class-rwl-' . $f . '.php';
	}
	RWL_Settings::init();
	RWL_Product::init();
	RWL_Sender::init();
	RWL_Importer::init();
	RWL_Account::init();
} );
