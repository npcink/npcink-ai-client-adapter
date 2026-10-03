<?php
/**
 * Adapter plan-to-proposal bridge allowlist.
 *
 * @package NpcinkOpenClawAdapter
 */

namespace Npcink\OpenClawAdapter\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists planning abilities implemented by the adapter plan-to-proposal bridge.
 */
final class Supported_Plan_Abilities {
	/**
	 * Returns all supported planning ability ids.
	 *
	 * @return list<string>
	 */
	public static function ids(): array {
		return array_keys( self::map() );
	}

	/**
	 * Checks whether a planning ability is implemented by this bridge.
	 *
	 * Core remains the governance truth; this adapter-side registry describes
	 * the concrete plan payload shapes implemented by this plugin.
	 *
	 * @param string $ability_id Ability id.
	 * @return bool
	 */
	public static function contains( string $ability_id ): bool {
		return isset( self::map()[ $ability_id ] );
	}

	/**
	 * Returns the supported ability map.
	 *
	 * @return array<string,bool>
	 */
	private static function map(): array {
		return array(
			'npcink-abilities-toolkit/build-content-inventory-fix-plan' => true,
			'npcink-abilities-toolkit/build-nonproduction-content-cleanup-plan' => true,
			'npcink-abilities-toolkit/build-media-inventory-fix-plan' => true,
			'npcink-abilities-toolkit/build-media-reference-repair-plan' => true,
			'npcink-abilities-toolkit/build-media-settings-reference-repair-plan' => true,
			'npcink-abilities-toolkit/build-media-optimization-plan' => true,
			'npcink-abilities-toolkit/build-media-adoption-enhancement-plan' => true,
			'npcink-abilities-toolkit/build-media-rename-plan' => true,
			'npcink-abilities-toolkit/build-content-metadata-apply-plan' => true,
			'npcink-abilities-toolkit/build-article-audio-adoption-plan' => true,
			'npcink-abilities-toolkit/build-article-optimization-apply-plan' => true,
			'npcink-abilities-toolkit/build-media-alt-apply-plan' => true,
			'npcink-abilities-toolkit/build-article-block-plan' => true,
			'npcink-abilities-toolkit/build-block-theme-site-plan' => true,
			'npcink-abilities-toolkit/build-pattern-page-plan' => true,
			'npcink-toolbox/build-article-write-plan' => true,
			'npcink-toolbox/build-article-batch-write-plan' => true,
			'npcink-toolbox/build-article-media-batch-write-plan' => true,
			'npcink-toolbox/build-image-candidate-adoption-plan' => true,
			'npcink-toolbox/build-site-knowledge-review-plan' => true,
			'npcink-toolbox/build-nightly-inspection-review-plan' => true,
		);
	}
}
