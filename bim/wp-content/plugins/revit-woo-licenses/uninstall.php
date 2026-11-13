<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
// Настройки удаляются, мета-данные товаров и заказов сохраняются намеренно.
delete_option( 'rwl_settings' );
