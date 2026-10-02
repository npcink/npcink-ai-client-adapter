<?php
/**
 * Plugin Name: Npcink AI Client Adapter
 * Description: Thin AI client adapter for Npcink Governance Core and WordPress Abilities execution.
 * Version: 0.4.1
 * Requires PHP: 8.0
 * Requires at least: 7.0
 * Requires Plugins: npcink-abilities-toolkit, npcink-governance-core
 * Author: Npcink
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: npcink-ai-client-adapter
 * Domain Path: /languages
 *
 * @package NpcinkOpenClawAdapter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'NPCINK_OPENCLAW_ADAPTER_FILE' ) ) {
	return;
}

define( 'NPCINK_OPENCLAW_ADAPTER_VERSION', '0.4.1' );
define( 'NPCINK_OPENCLAW_ADAPTER_FILE', __FILE__ );
define( 'NPCINK_OPENCLAW_ADAPTER_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Npcink\\OpenClawAdapter\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = NPCINK_OPENCLAW_ADAPTER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new Npcink\OpenClawAdapter\Plugin();
		$plugin->boot();
	}
);
