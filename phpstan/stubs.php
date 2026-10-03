<?php
/**
 * PHPStan bootstrap stubs for runtime symbols not discoverable from the
 * analysed paths or php-stubs/wordpress-stubs.
 *
 * @package NpcinkOpenClawAdapter
 */

// WordPress defines ARRAY_A as a global constant at runtime; the generated
// stubs only carry the wpdb class constant.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Plugin constants defined in the guarded bootstrap at the repository root;
// PHPStan's collector does not see past the early-return guard.
if ( ! defined( 'NPCINK_OPENCLAW_ADAPTER_VERSION' ) ) {
	define( 'NPCINK_OPENCLAW_ADAPTER_VERSION', '0.4.1' );
}
if ( ! defined( 'NPCINK_OPENCLAW_ADAPTER_FILE' ) ) {
	define( 'NPCINK_OPENCLAW_ADAPTER_FILE', __FILE__ );
}
if ( ! defined( 'NPCINK_OPENCLAW_ADAPTER_DIR' ) ) {
	define( 'NPCINK_OPENCLAW_ADAPTER_DIR', __DIR__ . '/../' );
}
