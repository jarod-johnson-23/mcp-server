<?php

namespace McpWp\MCP\Servers\WordPress\Tools;

use McpWp\MCP\Server;

/**
 * Elementor cache refresh tool.
 *
 * Clears and regenerates Elementor CSS (and related) cache for a given page/post ID.
 *
 * @phpstan-import-type ToolDefinition from Server
 */
readonly class ElementorCacheRefresh {
	/**
	 * Returns tool definition(s).
	 *
	 * @return array<int, ToolDefinition> Tools.
	 */
	public function get_tools(): array {
		return [
			[
				'name'        => 'refresh_elementor_page_cache',
				'description' => 'Refresh Elementor page cache (CSS) for a given page/post ID.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'page_id' => [
							'type'        => 'integer',
							'description' => 'The page or post ID to refresh Elementor cache for.',
						],
					],
					'required'   => [ 'page_id' ],
				],
				'annotations' => [
					'title'           => 'Refresh Elementor Page Cache',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'refresh' ],
			],
		];
	}

	/**
	 * Refresh Elementor cache for a given page/post ID.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function refresh( array $params ): string {
		$page_id = isset( $params['page_id'] ) ? (int) $params['page_id'] : 0;

		if ( $page_id <= 0 ) {
			return json_encode( [
				'success' => false,
				'error'   => 'Invalid or missing page_id',
			] );
		}

		if ( ! did_action( 'elementor/loaded' ) ) {
			return json_encode( [
				'success' => false,
				'error'   => 'Elementor is not loaded.',
			] );
		}

		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return json_encode( [
				'success' => false,
				'error'   => 'Elementor Plugin class missing.',
			] );
		}

		$elementor = \Elementor\Plugin::instance();

		// Refresh CSS cache for this page.
		$css_file = $elementor->files_manager->get_css_file(
			[
				'post_id' => $page_id,
			]
		);

		if ( ! $css_file ) {
			return json_encode( [
				'success' => false,
				'error'   => 'No CSS file found for this post.',
			] );
		}

		$css_file->clear_cache();
		$css_file->update();

		return json_encode( [
			'success' => true,
			'page_id' => $page_id,
			'message' => 'Elementor CSS cache cleared and regenerated.',
		] );
	}
}


