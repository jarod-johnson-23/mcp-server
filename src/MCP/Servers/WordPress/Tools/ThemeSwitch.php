<?php

namespace McpWp\MCP\Servers\WordPress\Tools;

use McpWp\MCP\Server;

/**
 * Theme switching tool.
 *
 * Allows activating/switching WordPress themes.
 *
 * @phpstan-import-type ToolDefinition from Server
 */
readonly class ThemeSwitch {
	/**
	 * Returns the theme switching tool definition.
	 *
	 * @return array<int, ToolDefinition> Tools.
	 */
	public function get_tools(): array {
		return [
			[
				'name'        => 'switch_theme',
				'description' => 'Switch/activate a WordPress theme. Provide the theme stylesheet name (folder name) to activate it.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'stylesheet' => [
							'type'        => 'string',
							'description' => 'The theme stylesheet name (folder name) to activate, e.g., "twentytwentyfour"',
						],
					],
					'required'   => [ 'stylesheet' ],
				],
				'annotations' => [
					'title'           => 'Switch WordPress Theme',
					'readOnlyHint'    => false,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'switch_theme' ],
			],
			[
				'name'        => 'get_available_themes',
				'description' => 'Get a list of all available WordPress themes that can be activated.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [],
					'required'   => [],
				],
				'annotations' => [
					'title'           => 'Get Available Themes',
					'readOnlyHint'    => true,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'get_available_themes' ],
			],
			[
				'name'        => 'get_current_theme',
				'description' => 'Get information about the currently active WordPress theme.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [],
					'required'   => [],
				],
				'annotations' => [
					'title'           => 'Get Current Theme',
					'readOnlyHint'    => true,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'get_current_theme' ],
			],
		];
	}

	/**
	 * Switch theme callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with theme details.
	 */
	public function switch_theme( array $params ): string {
		$stylesheet = $params['stylesheet'] ?? null;

		if ( ! $stylesheet ) {
			return json_encode( [
				'error' => 'stylesheet parameter is required',
			] );
		}

		// Check if theme exists
		$theme = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return json_encode( [
				'error'   => 'Theme not found',
				'message' => "Theme with stylesheet '{$stylesheet}' does not exist",
			] );
		}

		// Check if theme is already active
		$current_theme = wp_get_theme();
		if ( $current_theme->get_stylesheet() === $stylesheet ) {
			return json_encode( [
				'success'        => true,
				'message'        => 'Theme is already active',
				'stylesheet'     => $theme->get_stylesheet(),
				'name'           => $theme->get( 'Name' ),
				'version'        => $theme->get( 'Version' ),
				'description'    => $theme->get( 'Description' ),
				'author'         => $theme->get( 'Author' ),
				'already_active' => true,
			] );
		}

		// Check if user has permission to switch themes
		if ( ! current_user_can( 'switch_themes' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to switch themes',
			] );
		}

		// Switch the theme
		switch_theme( $stylesheet );

		// Verify the switch was successful
		$new_current = wp_get_theme();
		if ( $new_current->get_stylesheet() !== $stylesheet ) {
			return json_encode( [
				'error'   => 'Theme switch failed',
				'message' => 'Theme activation did not complete successfully',
			] );
		}

		return json_encode( [
			'success'     => true,
			'message'     => 'Theme activated successfully',
			'stylesheet'  => $theme->get_stylesheet(),
			'name'        => $theme->get( 'Name' ),
			'version'     => $theme->get( 'Version' ),
			'description' => $theme->get( 'Description' ),
			'author'      => $theme->get( 'Author' ),
			'theme_uri'   => $theme->get( 'ThemeURI' ),
		] );
	}

	/**
	 * Get available themes callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with available themes.
	 */
	public function get_available_themes( array $params ): string {
		$themes = wp_get_themes();

		$theme_list = [];
		foreach ( $themes as $stylesheet => $theme ) {
			$theme_list[] = [
				'stylesheet'  => $stylesheet,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => $theme->get( 'Description' ),
				'author'      => $theme->get( 'Author' ),
				'theme_uri'   => $theme->get( 'ThemeURI' ),
				'screenshot'  => $theme->get_screenshot(),
			];
		}

		return json_encode( [
			'success' => true,
			'count'   => count( $theme_list ),
			'themes'  => $theme_list,
		] );
	}

	/**
	 * Get current theme callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with current theme details.
	 */
	public function get_current_theme( array $params ): string {
		$theme = wp_get_theme();

		return json_encode( [
			'success'     => true,
			'stylesheet'  => $theme->get_stylesheet(),
			'name'        => $theme->get( 'Name' ),
			'version'     => $theme->get( 'Version' ),
			'description' => $theme->get( 'Description' ),
			'author'      => $theme->get( 'Author' ),
			'theme_uri'   => $theme->get( 'ThemeURI' ),
			'screenshot'  => $theme->get_screenshot(),
			'parent'      => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
		] );
	}
}
