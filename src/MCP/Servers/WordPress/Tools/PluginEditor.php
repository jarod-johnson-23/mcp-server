<?php

namespace McpWp\MCP\Servers\WordPress\Tools;

use McpWp\MCP\Server;

/**
 * Plugin file editing tool.
 *
 * Allows reading, editing, and managing WordPress plugin files.
 *
 * @phpstan-import-type ToolDefinition from Server
 */
readonly class PluginEditor {
	/**
	 * Returns the plugin editor tool definitions.
	 *
	 * @return array<int, ToolDefinition> Tools.
	 */
	public function get_tools(): array {
		return [
			[
				'name'        => 'list_plugin_files',
				'description' => 'List all files in a WordPress plugin directory.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin directory name (e.g., "akismet" from "akismet/akismet.php")',
						],
						'recursive' => [
							'type'        => 'boolean',
							'description' => 'Whether to list files recursively in subdirectories. Default: true',
						],
					],
					'required'   => [ 'plugin' ],
				],
				'annotations' => [
					'title'           => 'List Plugin Files',
					'readOnlyHint'    => true,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'list_plugin_files' ],
			],
			[
				'name'        => 'get_plugin_file',
				'description' => 'Read the content of a specific plugin file.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin directory name (e.g., "akismet" from "akismet/akismet.php")',
						],
						'file_path' => [
							'type'        => 'string',
							'description' => 'The relative path to the file within the plugin directory (e.g., "includes/class-api.php")',
						],
					],
					'required'   => [ 'plugin', 'file_path' ],
				],
				'annotations' => [
					'title'           => 'Get Plugin File Content',
					'readOnlyHint'    => true,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'get_plugin_file' ],
			],
			[
				'name'        => 'edit_plugin_file',
				'description' => 'Edit/update the content of a plugin file. Creates the file if it doesn\'t exist.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin directory name (e.g., "akismet" from "akismet/akismet.php")',
						],
						'file_path' => [
							'type'        => 'string',
							'description' => 'The relative path to the file within the plugin directory',
						],
						'content' => [
							'type'        => 'string',
							'description' => 'The new content for the file',
						],
					],
					'required'   => [ 'plugin', 'file_path', 'content' ],
				],
				'annotations' => [
					'title'           => 'Edit Plugin File',
					'readOnlyHint'    => false,
					'idempotentHint'  => true,
					'destructiveHint' => true,
				],
				'callback'    => [ $this, 'edit_plugin_file' ],
			],
			[
				'name'        => 'create_plugin_directory',
				'description' => 'Create a new directory within a plugin.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin directory name',
						],
						'directory_path' => [
							'type'        => 'string',
							'description' => 'The relative path to the directory within the plugin (e.g., "includes/api")',
						],
					],
					'required'   => [ 'plugin', 'directory_path' ],
				],
				'annotations' => [
					'title'           => 'Create Plugin Directory',
					'readOnlyHint'    => false,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'create_plugin_directory' ],
			],
			[
				'name'        => 'delete_plugin_file',
				'description' => 'Delete a file from a plugin directory. Use with caution.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'plugin' => [
							'type'        => 'string',
							'description' => 'Plugin directory name',
						],
						'file_path' => [
							'type'        => 'string',
							'description' => 'The relative path to the file within the plugin directory',
						],
					],
					'required'   => [ 'plugin', 'file_path' ],
				],
				'annotations' => [
					'title'           => 'Delete Plugin File',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => true,
				],
				'callback'    => [ $this, 'delete_plugin_file' ],
			],
			[
				'name'        => 'create_new_plugin',
				'description' => 'Create a new plugin with basic structure (main file, readme, etc).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => 'Plugin directory slug (e.g., "my-custom-plugin")',
						],
						'name' => [
							'type'        => 'string',
							'description' => 'Plugin name (e.g., "My Custom Plugin")',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'Plugin description',
						],
						'author' => [
							'type'        => 'string',
							'description' => 'Plugin author name',
						],
						'version' => [
							'type'        => 'string',
							'description' => 'Initial version (default: "1.0.0")',
						],
					],
					'required'   => [ 'slug', 'name' ],
				],
				'annotations' => [
					'title'           => 'Create New Plugin',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'create_new_plugin' ],
			],
		];
	}

	/**
	 * List plugin files callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with file list.
	 */
	public function list_plugin_files( array $params ): string {
		$plugin = $params['plugin'] ?? null;
		$recursive = $params['recursive'] ?? true;

		if ( ! $plugin ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin;

		if ( ! is_dir( $plugin_dir ) ) {
			return json_encode( [
				'error'   => 'Plugin directory not found',
				'message' => "Plugin directory '{$plugin}' does not exist",
			] );
		}

		$files = $this->scan_directory( $plugin_dir, $plugin_dir, $recursive );

		return json_encode( [
			'success'    => true,
			'plugin'     => $plugin,
			'plugin_dir' => $plugin_dir,
			'file_count' => count( $files ),
			'files'      => $files,
		] );
	}

	/**
	 * Get plugin file content callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with file content.
	 */
	public function get_plugin_file( array $params ): string {
		$plugin = $params['plugin'] ?? null;
		$file_path = $params['file_path'] ?? null;

		if ( ! $plugin ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		if ( ! $file_path ) {
			return json_encode( [
				'error' => 'file_path parameter is required',
			] );
		}

		// Validate and get full path
		$full_path = $this->validate_plugin_file_path( $plugin, $file_path );

		if ( is_array( $full_path ) && isset( $full_path['error'] ) ) {
			return json_encode( $full_path );
		}

		if ( ! file_exists( $full_path ) ) {
			return json_encode( [
				'error'   => 'File not found',
				'message' => "File does not exist: {$file_path}",
			] );
		}

		if ( ! is_readable( $full_path ) ) {
			return json_encode( [
				'error'   => 'File not readable',
				'message' => "Cannot read file: {$file_path}",
			] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $full_path );

		if ( false === $content ) {
			return json_encode( [
				'error'   => 'Failed to read file',
				'message' => "Could not read file content: {$file_path}",
			] );
		}

		return json_encode( [
			'success'   => true,
			'plugin'    => $plugin,
			'file_path' => $file_path,
			'full_path' => $full_path,
			'size'      => filesize( $full_path ),
			'modified'  => date( 'Y-m-d H:i:s', filemtime( $full_path ) ),
			'content'   => $content,
		] );
	}

	/**
	 * Edit plugin file callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function edit_plugin_file( array $params ): string {
		$plugin = $params['plugin'] ?? null;
		$file_path = $params['file_path'] ?? null;
		$content = $params['content'] ?? null;

		if ( ! $plugin ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		if ( ! $file_path ) {
			return json_encode( [
				'error' => 'file_path parameter is required',
			] );
		}

		if ( null === $content ) {
			return json_encode( [
				'error' => 'content parameter is required',
			] );
		}

		// Check user permissions
		if ( ! current_user_can( 'edit_plugins' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to edit plugins',
			] );
		}

		// Validate and get full path
		$full_path = $this->validate_plugin_file_path( $plugin, $file_path );

		if ( is_array( $full_path ) && isset( $full_path['error'] ) ) {
			return json_encode( $full_path );
		}

		// Initialize WordPress Filesystem API
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Initialize with direct method (no FTP needed)
		$fs_init = WP_Filesystem();

		if ( ! $fs_init ) {
			return json_encode( [
				'error'   => 'Filesystem initialization failed',
				'message' => 'Could not initialize WordPress filesystem',
				'debug'   => [
					'wp_filesystem_exists' => isset( $wp_filesystem ),
					'wp_filesystem_type'   => isset( $wp_filesystem ) ? get_class( $wp_filesystem ) : 'not set',
				],
			] );
		}

		// Get filesystem method being used
		$fs_method = method_exists( $wp_filesystem, 'method' ) ? $wp_filesystem->method :
					( isset( $wp_filesystem->method ) ? $wp_filesystem->method : 'unknown' );

		// Check if directory exists, create if needed
		$dir = dirname( $full_path );
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return json_encode( [
					'error'   => 'Failed to create directory',
					'message' => "Could not create directory: {$dir}",
					'debug'   => [
						'filesystem_method' => $fs_method,
					],
				] );
			}
		}

		// Check if we can actually write (test with temp file first)
		$test_file = $dir . '/.mcp_test_' . time() . '.tmp';
		$test_write = $wp_filesystem->put_contents( $test_file, 'test', FS_CHMOD_FILE );

		if ( false === $test_write ) {
			return json_encode( [
				'error'   => 'Directory is not writable',
				'message' => "Cannot write to directory using {$fs_method} filesystem method",
				'debug'   => [
					'dir'               => $dir,
					'filesystem_method' => $fs_method,
					'filesystem_class'  => get_class( $wp_filesystem ),
					'dir_exists'        => $wp_filesystem->is_dir( $dir ),
					'dir_readable'      => $wp_filesystem->is_readable( $dir ),
					'dir_writable'      => $wp_filesystem->is_writable( $dir ),
					'test_file'         => $test_file,
				],
			] );
		}

		// Clean up test file
		$wp_filesystem->delete( $test_file );

		// Write file using WordPress Filesystem API
		$result = $wp_filesystem->put_contents( $full_path, $content, FS_CHMOD_FILE );

		if ( false === $result ) {
			// Get any WordPress errors
			$wp_errors = [];
			if ( function_exists( 'get_settings_errors' ) ) {
				$wp_errors = get_settings_errors();
			}

			return json_encode( [
				'error'   => 'Failed to write file',
				'message' => "Could not write to file: {$file_path}",
				'debug'   => [
					'file'              => $full_path,
					'dir'               => $dir,
					'filesystem_method' => $fs_method,
					'filesystem_class'  => get_class( $wp_filesystem ),
					'dir_exists'        => $wp_filesystem->is_dir( $dir ),
					'dir_writable'      => $wp_filesystem->is_writable( $dir ),
					'file_exists'       => $wp_filesystem->exists( $full_path ),
					'wp_errors'         => $wp_errors,
					'content_length'    => strlen( $content ),
				],
			] );
		}

		return json_encode( [
			'success'   => true,
			'message'   => 'File updated successfully',
			'plugin'    => $plugin,
			'file_path' => $file_path,
			'full_path' => $full_path,
			'bytes'     => strlen( $content ),
			'method'    => 'WP_Filesystem',
		] );
	}

	/**
	 * Create plugin directory callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function create_plugin_directory( array $params ): string {
		$plugin = $params['plugin'] ?? null;
		$directory_path = $params['directory_path'] ?? null;

		if ( ! $plugin ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		if ( ! $directory_path ) {
			return json_encode( [
				'error' => 'directory_path parameter is required',
			] );
		}

		// Check user permissions
		if ( ! current_user_can( 'edit_plugins' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to edit plugins',
			] );
		}

		// Validate and get full path
		$full_path = $this->validate_plugin_file_path( $plugin, $directory_path );

		if ( is_array( $full_path ) && isset( $full_path['error'] ) ) {
			return json_encode( $full_path );
		}

		if ( is_dir( $full_path ) ) {
			return json_encode( [
				'success' => true,
				'message' => 'Directory already exists',
				'path'    => $directory_path,
			] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		if ( ! mkdir( $full_path, 0755, true ) ) {
			return json_encode( [
				'error'   => 'Failed to create directory',
				'message' => "Could not create directory: {$directory_path}",
			] );
		}

		return json_encode( [
			'success'   => true,
			'message'   => 'Directory created successfully',
			'plugin'    => $plugin,
			'path'      => $directory_path,
			'full_path' => $full_path,
		] );
	}

	/**
	 * Delete plugin file callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function delete_plugin_file( array $params ): string {
		$plugin = $params['plugin'] ?? null;
		$file_path = $params['file_path'] ?? null;

		if ( ! $plugin ) {
			return json_encode( [
				'error' => 'plugin parameter is required',
			] );
		}

		if ( ! $file_path ) {
			return json_encode( [
				'error' => 'file_path parameter is required',
			] );
		}

		// Check user permissions
		if ( ! current_user_can( 'edit_plugins' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to edit plugins',
			] );
		}

		// Validate and get full path
		$full_path = $this->validate_plugin_file_path( $plugin, $file_path );

		if ( is_array( $full_path ) && isset( $full_path['error'] ) ) {
			return json_encode( $full_path );
		}

		if ( ! file_exists( $full_path ) ) {
			return json_encode( [
				'error'   => 'File not found',
				'message' => "File does not exist: {$file_path}",
			] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! unlink( $full_path ) ) {
			return json_encode( [
				'error'   => 'Failed to delete file',
				'message' => "Could not delete file: {$file_path}",
			] );
		}

		return json_encode( [
			'success'   => true,
			'message'   => 'File deleted successfully',
			'plugin'    => $plugin,
			'file_path' => $file_path,
		] );
	}

	/**
	 * Create new plugin callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function create_new_plugin( array $params ): string {
		$slug = $params['slug'] ?? null;
		$name = $params['name'] ?? null;
		$description = $params['description'] ?? '';
		$author = $params['author'] ?? '';
		$version = $params['version'] ?? '1.0.0';

		if ( ! $slug ) {
			return json_encode( [
				'error' => 'slug parameter is required',
			] );
		}

		if ( ! $name ) {
			return json_encode( [
				'error' => 'name parameter is required',
			] );
		}

		// Check user permissions
		if ( ! current_user_can( 'install_plugins' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to install plugins',
			] );
		}

		// Sanitize slug
		$slug = sanitize_title( $slug );

		$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

		// Initialize WordPress Filesystem API
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return json_encode( [
				'error'   => 'Filesystem initialization failed',
				'message' => 'Could not initialize WordPress filesystem',
			] );
		}

		if ( $wp_filesystem->is_dir( $plugin_dir ) ) {
			return json_encode( [
				'error'   => 'Plugin already exists',
				'message' => "Plugin directory '{$slug}' already exists",
			] );
		}

		// Create plugin directory using WordPress filesystem
		if ( ! wp_mkdir_p( $plugin_dir ) ) {
			return json_encode( [
				'error'   => 'Failed to create plugin directory',
				'message' => "Could not create directory: {$slug}",
			] );
		}

		// Create main plugin file
		$main_file = $plugin_dir . '/' . $slug . '.php';
		$plugin_header = "<?php\n";
		$plugin_header .= "/**\n";
		$plugin_header .= " * Plugin Name: {$name}\n";
		if ( $description ) {
			$plugin_header .= " * Description: {$description}\n";
		}
		$plugin_header .= " * Version: {$version}\n";
		if ( $author ) {
			$plugin_header .= " * Author: {$author}\n";
		}
		$plugin_header .= " */\n\n";
		$plugin_header .= "// If this file is called directly, abort.\n";
		$plugin_header .= "if ( ! defined( 'WPINC' ) ) {\n";
		$plugin_header .= "\tdie;\n";
		$plugin_header .= "}\n\n";
		$plugin_header .= "// Plugin code goes here\n";

		if ( false === $wp_filesystem->put_contents( $main_file, $plugin_header, FS_CHMOD_FILE ) ) {
			return json_encode( [
				'error'   => 'Failed to create main plugin file',
				'message' => 'Could not write main plugin file. Your hosting may block programmatic PHP file creation.',
				'debug'   => [
					'file' => $main_file,
					'hosting_restriction' => 'PHP files may be blocked for security',
				],
			] );
		}

		// Create readme.txt
		$readme_file = $plugin_dir . '/readme.txt';
		$readme_content = "=== {$name} ===\n";
		if ( $author ) {
			$readme_content .= "Contributors: {$author}\n";
		}
		$readme_content .= "Stable tag: {$version}\n";
		$readme_content .= "Requires at least: 5.0\n";
		$readme_content .= "Tested up to: 6.7\n";
		$readme_content .= "Requires PHP: 7.4\n";
		$readme_content .= "License: GPLv2 or later\n\n";
		if ( $description ) {
			$readme_content .= "{$description}\n\n";
		}
		$readme_content .= "== Description ==\n\n";
		$readme_content .= "Plugin description goes here.\n\n";
		$readme_content .= "== Installation ==\n\n";
		$readme_content .= "1. Upload the plugin files to the `/wp-content/plugins/{$slug}` directory\n";
		$readme_content .= "2. Activate the plugin through the 'Plugins' screen in WordPress\n\n";
		$readme_content .= "== Changelog ==\n\n";
		$readme_content .= "= {$version} =\n";
		$readme_content .= "* Initial release\n";

		if ( false === $wp_filesystem->put_contents( $readme_file, $readme_content, FS_CHMOD_FILE ) ) {
			return json_encode( [
				'error'   => 'Failed to create readme.txt',
				'message' => 'Could not write readme.txt file',
				'debug'   => [
					'file' => $readme_file,
				],
			] );
		}

		return json_encode( [
			'success'     => true,
			'message'     => 'Plugin created successfully',
			'slug'        => $slug,
			'plugin_file' => "{$slug}/{$slug}.php",
			'plugin_dir'  => $plugin_dir,
			'files'       => [
				"{$slug}.php",
				'readme.txt',
			],
		] );
	}

	/**
	 * Scan directory recursively.
	 *
	 * @param string $dir       Directory to scan.
	 * @param string $base_dir  Base directory for relative paths.
	 * @param bool   $recursive Whether to scan recursively.
	 * @return array<int, array<string, mixed>> List of files.
	 */
	private function scan_directory( string $dir, string $base_dir, bool $recursive = true ): array {
		$files = [];

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir
		$handle = opendir( $dir );

		if ( ! $handle ) {
			return $files;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readdir
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$full_path = $dir . '/' . $entry;
			$relative_path = str_replace( $base_dir . '/', '', $full_path );

			if ( is_dir( $full_path ) ) {
				if ( $recursive ) {
					$files = array_merge( $files, $this->scan_directory( $full_path, $base_dir, $recursive ) );
				}
			} else {
				$files[] = [
					'path'     => $relative_path,
					'name'     => basename( $full_path ),
					'size'     => filesize( $full_path ),
					'modified' => date( 'Y-m-d H:i:s', filemtime( $full_path ) ),
					'type'     => $this->get_file_type( $full_path ),
				];
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir
		closedir( $handle );

		return $files;
	}

	/**
	 * Validate plugin file path to prevent directory traversal.
	 *
	 * @param string $plugin    Plugin directory name.
	 * @param string $file_path Relative file path.
	 * @return string|array<string, string> Full path or error array.
	 */
	private function validate_plugin_file_path( string $plugin, string $file_path ) {
		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin;

		if ( ! is_dir( $plugin_dir ) ) {
			return [
				'error'   => 'Plugin directory not found',
				'message' => "Plugin directory '{$plugin}' does not exist",
			];
		}

		// Remove any leading slashes
		$file_path = ltrim( $file_path, '/' );

		// Build full path
		$full_path = $plugin_dir . '/' . $file_path;

		// Resolve path
		$real_plugin_dir = realpath( $plugin_dir );
		$resolved_path = $full_path;

		// Check for directory traversal attempts
		if ( false !== strpos( $file_path, '..' ) ) {
			return [
				'error'   => 'Invalid path',
				'message' => 'Path contains invalid characters (..)',
			];
		}

		// Ensure the path is within the plugin directory
		if ( false === $real_plugin_dir || 0 !== strpos( $resolved_path, $real_plugin_dir ) ) {
			return [
				'error'   => 'Invalid path',
				'message' => 'Path is outside plugin directory',
			];
		}

		return $full_path;
	}

	/**
	 * Get file type based on extension.
	 *
	 * @param string $file_path File path.
	 * @return string File type.
	 */
	private function get_file_type( string $file_path ): string {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		$types = [
			'php'  => 'PHP',
			'css'  => 'CSS',
			'js'   => 'JavaScript',
			'json' => 'JSON',
			'html' => 'HTML',
			'htm'  => 'HTML',
			'xml'  => 'XML',
			'svg'  => 'SVG',
			'png'  => 'Image',
			'jpg'  => 'Image',
			'jpeg' => 'Image',
			'gif'  => 'Image',
			'webp' => 'Image',
			'txt'  => 'Text',
			'md'   => 'Markdown',
		];

		return $types[ $extension ] ?? 'Other';
	}
}
