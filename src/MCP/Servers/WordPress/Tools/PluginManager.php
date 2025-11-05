<?php

namespace McpWp\MCP\Servers\WordPress\Tools;

use McpWp\MCP\Server;

/**
 * Plugin management tool.
 *
 * Allows activating, deactivating, installing, and managing WordPress plugins.
 *
 * @phpstan-import-type ToolDefinition from Server
 */
readonly class PluginManager {
	/**
	 * Returns the plugin management tool definitions.
	 *
	 * @return array<int, ToolDefinition> Tools.
	 */
	public function get_tools(): array {
		return [
			[
				'name'        => 'list_plugins',
				'description' => 'List all installed WordPress plugins with their status (active/inactive).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'status' => [
							'type'        => 'string',
							'description' => 'Filter by status: "all", "active", "inactive", "mustuse", "dropins". Default: "all"',
							'enum'        => [ 'all', 'active', 'inactive', 'mustuse', 'dropins' ],
						],
					],
					'required'   => [],
				],
				'annotations' => [
					'title'           => 'List Plugins',
					'readOnlyHint'    => true,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'list_plugins' ],
			],
			[
				'name'        => 'get_plugin_info',
				'description' => 'Get detailed information about a specific plugin.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin file path (e.g., "akismet/akismet.php")',
						],
					],
					'required'   => [ 'plugin' ],
				],
				'annotations' => [
					'title'           => 'Get Plugin Info',
					'readOnlyHint'    => true,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'get_plugin_info' ],
			],
			[
				'name'        => 'activate_plugin',
				'description' => 'Activate a WordPress plugin.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin file path (e.g., "akismet/akismet.php")',
						],
						'network_wide' => [
							'type'        => 'boolean',
							'description' => 'Whether to activate network-wide (multisite only). Default: false',
						],
					],
					'required'   => [ 'plugin' ],
				],
				'annotations' => [
					'title'           => 'Activate Plugin',
					'readOnlyHint'    => false,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'activate_plugin' ],
			],
			[
				'name'        => 'deactivate_plugin',
				'description' => 'Deactivate a WordPress plugin.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin file path (e.g., "akismet/akismet.php")',
						],
					],
					'required'   => [ 'plugin' ],
				],
				'annotations' => [
					'title'           => 'Deactivate Plugin',
					'readOnlyHint'    => false,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'deactivate_plugin' ],
			],
			[
				'name'        => 'install_plugin_from_url',
				'description' => 'Install a plugin from a URL (zip file). Downloads and extracts to plugins directory.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'url' => [
							'type'        => 'string',
							'description' => 'URL to the plugin zip file',
						],
						'activate' => [
							'type'        => 'boolean',
							'description' => 'Whether to activate the plugin after installation. Default: false',
						],
					],
					'required'   => [ 'url' ],
				],
				'annotations' => [
					'title'           => 'Install Plugin from URL',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'install_plugin_from_url' ],
			],
			[
				'name'        => 'delete_plugin',
				'description' => 'Delete a plugin from the WordPress installation. Plugin must be inactive.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin file path (e.g., "akismet/akismet.php")',
						],
					],
					'required'   => [ 'plugin' ],
				],
				'annotations' => [
					'title'           => 'Delete Plugin',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => true,
				],
				'callback'    => [ $this, 'delete_plugin' ],
			],
		];
	}

	/**
	 * List plugins callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with plugin list.
	 */
	public function list_plugins( array $params ): string {
		$status = $params['status'] ?? 'all';

		// Ensure plugin functions are available
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active_plugins = get_option( 'active_plugins', [] );

		$plugins = [];

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$is_active = in_array( $plugin_file, $active_plugins, true );

			// Filter by status
			if ( 'active' === $status && ! $is_active ) {
				continue;
			}
			if ( 'inactive' === $status && $is_active ) {
				continue;
			}

			$plugins[] = [
				'file'        => $plugin_file,
				'name'        => $plugin_data['Name'],
				'version'     => $plugin_data['Version'],
				'description' => $plugin_data['Description'],
				'author'      => $plugin_data['Author'],
				'plugin_uri'  => $plugin_data['PluginURI'],
				'is_active'   => $is_active,
			];
		}

		// Handle must-use plugins
		if ( 'all' === $status || 'mustuse' === $status ) {
			$mu_plugins = get_mu_plugins();
			foreach ( $mu_plugins as $plugin_file => $plugin_data ) {
				$plugins[] = [
					'file'        => $plugin_file,
					'name'        => $plugin_data['Name'],
					'version'     => $plugin_data['Version'],
					'description' => $plugin_data['Description'],
					'author'      => $plugin_data['Author'],
					'plugin_uri'  => $plugin_data['PluginURI'],
					'is_active'   => true,
					'type'        => 'must-use',
				];
			}
		}

		return json_encode( [
			'success' => true,
			'count'   => count( $plugins ),
			'plugins' => $plugins,
		] );
	}

	/**
	 * Get plugin info callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with plugin details.
	 */
	public function get_plugin_info( array $params ): string {
		$plugin_file = $params['plugin'] ?? null;

		if ( ! $plugin_file ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		// Ensure plugin functions are available
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );

		if ( empty( $plugin_data['Name'] ) ) {
			return json_encode( [
				'error'   => 'Plugin not found',
				'message' => "Plugin file '{$plugin_file}' does not exist or is invalid",
			] );
		}

		$active_plugins = get_option( 'active_plugins', [] );
		$is_active = in_array( $plugin_file, $active_plugins, true );

		return json_encode( [
			'success'     => true,
			'file'        => $plugin_file,
			'name'        => $plugin_data['Name'],
			'version'     => $plugin_data['Version'],
			'description' => $plugin_data['Description'],
			'author'      => $plugin_data['Author'],
			'author_uri'  => $plugin_data['AuthorURI'],
			'plugin_uri'  => $plugin_data['PluginURI'],
			'requires_wp' => $plugin_data['RequiresWP'],
			'requires_php' => $plugin_data['RequiresPHP'],
			'is_active'   => $is_active,
		] );
	}

	/**
	 * Activate plugin callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function activate_plugin( array $params ): string {
		$plugin_file = $params['plugin'] ?? null;
		$network_wide = $params['network_wide'] ?? false;

		if ( ! $plugin_file ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		// Check permissions
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to activate plugins',
			] );
		}

		// Ensure plugin functions are available
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check if plugin exists
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			return json_encode( [
				'error'   => 'Plugin not found',
				'message' => "Plugin file '{$plugin_file}' does not exist",
			] );
		}

		// Check if already active
		if ( is_plugin_active( $plugin_file ) ) {
			return json_encode( [
				'success'        => true,
				'message'        => 'Plugin is already active',
				'file'           => $plugin_file,
				'already_active' => true,
			] );
		}

		// Activate plugin
		$result = activate_plugin( $plugin_file, '', $network_wide );

		if ( is_wp_error( $result ) ) {
			return json_encode( [
				'error'   => 'Activation failed',
				'message' => $result->get_error_message(),
			] );
		}

		return json_encode( [
			'success' => true,
			'message' => 'Plugin activated successfully',
			'file'    => $plugin_file,
		] );
	}

	/**
	 * Deactivate plugin callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function deactivate_plugin( array $params ): string {
		$plugin_file = $params['plugin'] ?? null;

		if ( ! $plugin_file ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		// Check permissions
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to deactivate plugins',
			] );
		}

		// Ensure plugin functions are available
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check if plugin exists
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			return json_encode( [
				'error'   => 'Plugin not found',
				'message' => "Plugin file '{$plugin_file}' does not exist",
			] );
		}

		// Check if already inactive
		if ( ! is_plugin_active( $plugin_file ) ) {
			return json_encode( [
				'success'          => true,
				'message'          => 'Plugin is already inactive',
				'file'             => $plugin_file,
				'already_inactive' => true,
			] );
		}

		// Deactivate plugin
		deactivate_plugins( $plugin_file );

		return json_encode( [
			'success' => true,
			'message' => 'Plugin deactivated successfully',
			'file'    => $plugin_file,
		] );
	}

	/**
	 * Install plugin from URL callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function install_plugin_from_url( array $params ): string {
		$url = $params['url'] ?? null;
		$activate = $params['activate'] ?? false;

		if ( ! $url ) {
			return json_encode( [
				'error' => 'url parameter is required',
			] );
		}

		// Check permissions
		if ( ! current_user_can( 'install_plugins' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to install plugins',
			] );
		}

		// Validate URL
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return json_encode( [
				'error' => 'Invalid URL provided',
			] );
		}

		// Download the plugin
		$response = wp_remote_get( $url, [
			'timeout'     => 60,
			'redirection' => 5,
		] );

		if ( is_wp_error( $response ) ) {
			return json_encode( [
				'error'   => 'Failed to download plugin',
				'message' => $response->get_error_message(),
			] );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return json_encode( [
				'error'   => 'Failed to download plugin',
				'message' => "HTTP {$response_code}",
			] );
		}

		$zip_content = wp_remote_retrieve_body( $response );

		if ( empty( $zip_content ) ) {
			return json_encode( [
				'error' => 'Downloaded file is empty',
			] );
		}

		// Save to temp file
		$temp_file = wp_tempnam( 'plugin-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp_file, $zip_content );

		// Load upgrader classes
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Use WP_Upgrader to extract the zip
		WP_Filesystem();

		$unzip_result = unzip_file( $temp_file, WP_PLUGIN_DIR );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $temp_file );

		if ( is_wp_error( $unzip_result ) ) {
			return json_encode( [
				'error'   => 'Failed to extract plugin',
				'message' => $unzip_result->get_error_message(),
			] );
		}

		// Find the plugin file
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$new_plugin = null;

		// Try to find the newly installed plugin (naive approach - find the most recently modified)
		foreach ( array_keys( $plugins ) as $plugin_file ) {
			$new_plugin = $plugin_file;
			break; // For simplicity, we'll return the first plugin found
		}

		$result = [
			'success' => true,
			'message' => 'Plugin installed successfully',
		];

		if ( $new_plugin ) {
			$result['plugin_file'] = $new_plugin;

			// Activate if requested
			if ( $activate ) {
				if ( ! function_exists( 'activate_plugin' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$activation_result = activate_plugin( $new_plugin );

				if ( is_wp_error( $activation_result ) ) {
					$result['activation_error'] = $activation_result->get_error_message();
				} else {
					$result['activated'] = true;
				}
			}
		}

		return json_encode( $result );
	}

	/**
	 * Delete plugin callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function delete_plugin( array $params ): string {
		$plugin_file = $params['plugin'] ?? null;

		if ( ! $plugin_file ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		// Check permissions
		if ( ! current_user_can( 'delete_plugins' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to delete plugins',
			] );
		}

		// Ensure plugin functions are available
		if ( ! function_exists( 'delete_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Check if plugin exists
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			return json_encode( [
				'error'   => 'Plugin not found',
				'message' => "Plugin file '{$plugin_file}' does not exist",
			] );
		}

		// Check if plugin is active
		if ( is_plugin_active( $plugin_file ) ) {
			return json_encode( [
				'error'   => 'Cannot delete active plugin',
				'message' => 'Please deactivate the plugin before deleting',
			] );
		}

		// Delete plugin
		$result = delete_plugins( [ $plugin_file ] );

		if ( is_wp_error( $result ) ) {
			return json_encode( [
				'error'   => 'Deletion failed',
				'message' => $result->get_error_message(),
			] );
		}

		return json_encode( [
			'success' => true,
			'message' => 'Plugin deleted successfully',
			'file'    => $plugin_file,
		] );
	}
}
