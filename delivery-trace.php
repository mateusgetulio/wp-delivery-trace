<?php
/**
 * Plugin Name:       Delivery Trace
 * Description:       Stores form leads first, delivers them to a CRM webhook, and traces every attempt.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Mateus Getulio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       delivery-trace
 *
 * @package DeliveryTrace
 */

defined( 'ABSPATH' ) || exit;

define( 'DELIVERY_TRACE_VERSION', '0.1.0' );
define( 'DELIVERY_TRACE_FILE', __FILE__ );

// Runtime classes load without Composer; vendor/ only holds development tools.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'DeliveryTrace\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( DeliveryTrace\Storage\Schema::class, 'install' ) );

add_action( 'plugins_loaded', array( DeliveryTrace\Plugin::class, 'boot' ) );
