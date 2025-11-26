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
				'name'        => 'refresh_elementor_cache',
				'description' => 'Refresh Elementor global cache (CSS/HTML/JS assets) for the site.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [],
					'required'   => [],
				],
				'annotations' => [
					'title'           => 'Refresh Elementor Cache',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'refresh' ],
			],
		];
	}

	/**
	 * Refresh Elementor global cache.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function refresh( array $params ): string {
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

		// Clear global Elementor cache via files manager.
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			$plugin = \Elementor\Plugin::instance();
			if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
				$plugin->files_manager->clear_cache();
				return json_encode( [
					'success' => true,
					'message' => 'Elementor cache cleared.',
				] );
			}
		}

		return json_encode( [
			'success' => false,
			'error'   => 'Failed to clear Elementor cache: files manager not available.',
		] );
	}
}


