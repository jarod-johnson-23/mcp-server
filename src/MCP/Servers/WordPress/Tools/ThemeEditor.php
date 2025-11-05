<?php

namespace McpWp\MCP\Servers\WordPress\Tools;

use McpWp\MCP\Server;

/**
 * Theme file editing tool.
 *
 * Allows reading, editing, and managing WordPress theme files.
 *
 * @phpstan-import-type ToolDefinition from Server
 */
readonly class ThemeEditor {
	/**
	 * Returns the theme editor tool definitions.
	 *
	 * @return array<int, ToolDefinition> Tools.
	 */
	public function get_tools(): array {
		return [
			[
				'name'        => 'list_theme_files',
				'description' => 'List all files in a WordPress theme directory. If no stylesheet is provided, lists files from the current active theme.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'stylesheet' => [
							'type'        => 'string',
							'description' => 'The theme stylesheet name (folder name). Omit to use current theme.',
						],
						'recursive' => [
							'type'        => 'boolean',
							'description' => 'Whether to list files recursively in subdirectories. Default: true',
						],
					],
					'required'   => [],
				],
				'annotations' => [
					'title'           => 'List Theme Files',
					'readOnlyHint'    => true,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'list_theme_files' ],
			],
			[
				'name'        => 'get_theme_file',
				'description' => 'Read the content of a specific theme file. Returns the file content as text.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'stylesheet' => [
							'type'        => 'string',
							'description' => 'The theme stylesheet name (folder name). Omit to use current theme.',
						],
						'file_path' => [
							'type'        => 'string',
							'description' => 'The relative path to the file within the theme directory (e.g., "style.css", "templates/header.php")',
						],
					],
					'required'   => [ 'file_path' ],
				],
				'annotations' => [
					'title'           => 'Get Theme File Content',
					'readOnlyHint'    => true,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'get_theme_file' ],
			],
			[
				'name'        => 'edit_theme_file',
				'description' => 'Edit/update the content of a theme file. Creates the file if it doesn\'t exist.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'stylesheet' => [
							'type'        => 'string',
							'description' => 'The theme stylesheet name (folder name). Omit to use current theme.',
						],
						'file_path' => [
							'type'        => 'string',
							'description' => 'The relative path to the file within the theme directory (e.g., "style.css", "templates/header.php")',
						],
						'content' => [
							'type'        => 'string',
							'description' => 'The new content for the file',
						],
					],
					'required'   => [ 'file_path', 'content' ],
				],
				'annotations' => [
					'title'           => 'Edit Theme File',
					'readOnlyHint'    => false,
					'idempotentHint'  => true,
					'destructiveHint' => true,
				],
				'callback'    => [ $this, 'edit_theme_file' ],
			],
			[
				'name'        => 'create_theme_directory',
				'description' => 'Create a new directory within a theme (e.g., for templates, partials, etc.).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'stylesheet' => [
							'type'        => 'string',
							'description' => 'The theme stylesheet name (folder name). Omit to use current theme.',
						],
						'directory_path' => [
							'type'        => 'string',
							'description' => 'The relative path to the directory within the theme (e.g., "templates/partials")',
						],
					],
					'required'   => [ 'directory_path' ],
				],
				'annotations' => [
					'title'           => 'Create Theme Directory',
					'readOnlyHint'    => false,
					'idempotentHint'  => true,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'create_theme_directory' ],
			],
			[
				'name'        => 'delete_theme_file',
				'description' => 'Delete a file from a theme directory. Use with caution.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'stylesheet' => [
							'type'        => 'string',
							'description' => 'The theme stylesheet name (folder name). Omit to use current theme.',
						],
						'file_path' => [
							'type'        => 'string',
							'description' => 'The relative path to the file within the theme directory',
						],
					],
					'required'   => [ 'file_path' ],
				],
				'annotations' => [
					'title'           => 'Delete Theme File',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => true,
				],
				'callback'    => [ $this, 'delete_theme_file' ],
			],
			[
				'name'        => 'create_new_theme',
				'description' => 'Create a new WordPress theme from scratch with basic structure (style.css, index.php, functions.php).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => 'Theme directory slug (e.g., "my-custom-theme")',
						],
						'name' => [
							'type'        => 'string',
							'description' => 'Theme name (e.g., "My Custom Theme")',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'Theme description',
						],
						'author' => [
							'type'        => 'string',
							'description' => 'Theme author name',
						],
						'version' => [
							'type'        => 'string',
							'description' => 'Initial version (default: "1.0.0")',
						],
					],
					'required'   => [ 'slug', 'name' ],
				],
				'annotations' => [
					'title'           => 'Create New Theme',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'create_new_theme' ],
			],
		];
	}

	/**
	 * List theme files callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with file list.
	 */
	public function list_theme_files( array $params ): string {
		$stylesheet = $params['stylesheet'] ?? null;
		$recursive = $params['recursive'] ?? true;

		// Get theme
		$theme = $stylesheet ? wp_get_theme( $stylesheet ) : wp_get_theme();

		if ( ! $theme->exists() ) {
			return json_encode( [
				'error'   => 'Theme not found',
				'message' => $stylesheet ? "Theme with stylesheet '{$stylesheet}' does not exist" : 'Current theme does not exist',
			] );
		}

		$theme_root = $theme->get_stylesheet_directory();

		if ( ! is_dir( $theme_root ) ) {
			return json_encode( [
				'error'   => 'Theme directory not found',
				'message' => "Directory does not exist: {$theme_root}",
			] );
		}

		$files = $this->scan_directory( $theme_root, $theme_root, $recursive );

		return json_encode( [
			'success'    => true,
			'theme_name' => $theme->get( 'Name' ),
			'stylesheet' => $theme->get_stylesheet(),
			'theme_root' => $theme_root,
			'file_count' => count( $files ),
			'files'      => $files,
		] );
	}

	/**
	 * Get theme file content callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with file content.
	 */
	public function get_theme_file( array $params ): string {
		$stylesheet = $params['stylesheet'] ?? null;
		$file_path = $params['file_path'] ?? null;

		if ( ! $file_path ) {
			return json_encode( [
				'error' => 'file_path parameter is required',
			] );
		}

		// Get theme
		$theme = $stylesheet ? wp_get_theme( $stylesheet ) : wp_get_theme();

		if ( ! $theme->exists() ) {
			return json_encode( [
				'error'   => 'Theme not found',
				'message' => $stylesheet ? "Theme with stylesheet '{$stylesheet}' does not exist" : 'Current theme does not exist',
			] );
		}

		// Validate and get full path
		$full_path = $this->validate_theme_file_path( $theme, $file_path );

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
			'success'    => true,
			'theme_name' => $theme->get( 'Name' ),
			'stylesheet' => $theme->get_stylesheet(),
			'file_path'  => $file_path,
			'full_path'  => $full_path,
			'size'       => filesize( $full_path ),
			'modified'   => date( 'Y-m-d H:i:s', filemtime( $full_path ) ),
			'content'    => $content,
		] );
	}

	/**
	 * Edit theme file callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function edit_theme_file( array $params ): string {
		$stylesheet = $params['stylesheet'] ?? null;
		$file_path = $params['file_path'] ?? null;
		$content = $params['content'] ?? null;

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
		if ( ! current_user_can( 'edit_themes' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to edit themes',
			] );
		}

		// Get theme
		$theme = $stylesheet ? wp_get_theme( $stylesheet ) : wp_get_theme();

		if ( ! $theme->exists() ) {
			return json_encode( [
				'error'   => 'Theme not found',
				'message' => $stylesheet ? "Theme with stylesheet '{$stylesheet}' does not exist" : 'Current theme does not exist',
			] );
		}

		// Validate and get full path
		$full_path = $this->validate_theme_file_path( $theme, $file_path );

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
			'success'    => true,
			'message'    => 'File updated successfully',
			'theme_name' => $theme->get( 'Name' ),
			'stylesheet' => $theme->get_stylesheet(),
			'file_path'  => $file_path,
			'full_path'  => $full_path,
			'bytes'      => strlen( $content ),
			'method'     => 'WP_Filesystem',
		] );
	}

	/**
	 * Create theme directory callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function create_theme_directory( array $params ): string {
		$stylesheet = $params['stylesheet'] ?? null;
		$directory_path = $params['directory_path'] ?? null;

		if ( ! $directory_path ) {
			return json_encode( [
				'error' => 'directory_path parameter is required',
			] );
		}

		// Check user permissions
		if ( ! current_user_can( 'edit_themes' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to edit themes',
			] );
		}

		// Get theme
		$theme = $stylesheet ? wp_get_theme( $stylesheet ) : wp_get_theme();

		if ( ! $theme->exists() ) {
			return json_encode( [
				'error'   => 'Theme not found',
				'message' => $stylesheet ? "Theme with stylesheet '{$stylesheet}' does not exist" : 'Current theme does not exist',
			] );
		}

		// Validate and get full path
		$full_path = $this->validate_theme_file_path( $theme, $directory_path );

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
			'success'    => true,
			'message'    => 'Directory created successfully',
			'theme_name' => $theme->get( 'Name' ),
			'stylesheet' => $theme->get_stylesheet(),
			'path'       => $directory_path,
			'full_path'  => $full_path,
		] );
	}

	/**
	 * Delete theme file callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function delete_theme_file( array $params ): string {
		$stylesheet = $params['stylesheet'] ?? null;
		$file_path = $params['file_path'] ?? null;

		if ( ! $file_path ) {
			return json_encode( [
				'error' => 'file_path parameter is required',
			] );
		}

		// Check user permissions
		if ( ! current_user_can( 'edit_themes' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to edit themes',
			] );
		}

		// Get theme
		$theme = $stylesheet ? wp_get_theme( $stylesheet ) : wp_get_theme();

		if ( ! $theme->exists() ) {
			return json_encode( [
				'error'   => 'Theme not found',
				'message' => $stylesheet ? "Theme with stylesheet '{$stylesheet}' does not exist" : 'Current theme does not exist',
			] );
		}

		// Validate and get full path
		$full_path = $this->validate_theme_file_path( $theme, $file_path );

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
			'success'    => true,
			'message'    => 'File deleted successfully',
			'theme_name' => $theme->get( 'Name' ),
			'stylesheet' => $theme->get_stylesheet(),
			'file_path'  => $file_path,
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
	 * Validate theme file path to prevent directory traversal.
	 *
	 * @param \WP_Theme $theme     Theme object.
	 * @param string    $file_path Relative file path.
	 * @return string|array<string, string> Full path or error array.
	 */
	private function validate_theme_file_path( \WP_Theme $theme, string $file_path ) {
		$theme_root = $theme->get_stylesheet_directory();

		// Remove any leading slashes
		$file_path = ltrim( $file_path, '/' );

		// Build full path
		$full_path = $theme_root . '/' . $file_path;

		// Resolve path (removes .. and .)
		$real_theme_root = realpath( $theme_root );
		$resolved_path = $full_path; // Don't use realpath yet as file might not exist

		// Check for directory traversal attempts
		if ( false !== strpos( $file_path, '..' ) ) {
			return [
				'error'   => 'Invalid path',
				'message' => 'Path contains invalid characters (..)',
			];
		}

		// Ensure the path is within the theme directory
		if ( false === $real_theme_root || 0 !== strpos( $resolved_path, $real_theme_root ) ) {
			return [
				'error'   => 'Invalid path',
				'message' => 'Path is outside theme directory',
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

	/**
	 * Create new theme callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response.
	 */
	public function create_new_theme( array $params ): string {
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
		if ( ! current_user_can( 'install_themes' ) ) {
			return json_encode( [
				'error'   => 'Permission denied',
				'message' => 'User does not have permission to install themes',
			] );
		}

		// Sanitize slug
		$slug = sanitize_title( $slug );

		$theme_dir = get_theme_root() . '/' . $slug;

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

		if ( $wp_filesystem->is_dir( $theme_dir ) ) {
			return json_encode( [
				'error'   => 'Theme already exists',
				'message' => "Theme directory '{$slug}' already exists",
			] );
		}

		// Create theme directory using WordPress filesystem
		if ( ! wp_mkdir_p( $theme_dir ) ) {
			return json_encode( [
				'error'   => 'Failed to create theme directory',
				'message' => "Could not create directory: {$slug}",
			] );
		}

		// Create style.css with theme headers
		$style_css = "/*\n";
		$style_css .= "Theme Name: {$name}\n";
		if ( $description ) {
			$style_css .= "Description: {$description}\n";
		}
		if ( $author ) {
			$style_css .= "Author: {$author}\n";
		}
		$style_css .= "Version: {$version}\n";
		$style_css .= "Requires at least: 5.0\n";
		$style_css .= "Tested up to: 6.7\n";
		$style_css .= "Requires PHP: 7.4\n";
		$style_css .= "License: GNU General Public License v2 or later\n";
		$style_css .= "License URI: http://www.gnu.org/licenses/gpl-2.0.html\n";
		$style_css .= "Text Domain: {$slug}\n";
		$style_css .= "*/\n\n";
		$style_css .= "/* Theme styles go here */\n";

		if ( false === $wp_filesystem->put_contents( $theme_dir . '/style.css', $style_css, FS_CHMOD_FILE ) ) {
			return json_encode( [
				'error'   => 'Failed to create style.css',
				'message' => 'Could not write style.css file',
				'debug'   => [
					'file' => $theme_dir . '/style.css',
					'dir_writable' => $wp_filesystem->is_writable( $theme_dir ),
				],
			] );
		}

		// Create index.php
		$index_php = "<?php\n";
		$index_php .= "/**\n";
		$index_php .= " * Main template file\n";
		$index_php .= " *\n";
		$index_php .= " * @package {$name}\n";
		$index_php .= " */\n\n";
		$index_php .= "get_header();\n\n";
		$index_php .= "if ( have_posts() ) :\n";
		$index_php .= "\twhile ( have_posts() ) :\n";
		$index_php .= "\t\tthe_post();\n";
		$index_php .= "\t\tthe_content();\n";
		$index_php .= "\tendwhile;\n";
		$index_php .= "else :\n";
		$index_php .= "\techo '<p>No content found</p>';\n";
		$index_php .= "endif;\n\n";
		$index_php .= "get_footer();\n";

		if ( false === $wp_filesystem->put_contents( $theme_dir . '/index.php', $index_php, FS_CHMOD_FILE ) ) {
			return json_encode( [
				'error'   => 'Failed to create index.php',
				'message' => 'Could not write index.php file. Your hosting may block programmatic PHP file creation.',
				'debug'   => [
					'file' => $theme_dir . '/index.php',
					'hosting_restriction' => 'PHP files may be blocked for security',
				],
			] );
		}

		// Create functions.php
		$functions_php = "<?php\n";
		$functions_php .= "/**\n";
		$functions_php .= " * Theme functions and definitions\n";
		$functions_php .= " *\n";
		$functions_php .= " * @package {$name}\n";
		$functions_php .= " */\n\n";
		$functions_php .= "if ( ! defined( 'ABSPATH' ) ) {\n";
		$functions_php .= "\texit;\n";
		$functions_php .= "}\n\n";
		$functions_php .= "/**\n";
		$functions_php .= " * Theme setup\n";
		$functions_php .= " */\n";
		$functions_php .= "function {$slug}_setup() {\n";
		$functions_php .= "\t// Add theme support\n";
		$functions_php .= "\tadd_theme_support( 'title-tag' );\n";
		$functions_php .= "\tadd_theme_support( 'post-thumbnails' );\n";
		$functions_php .= "\tadd_theme_support( 'html5', array(\n";
		$functions_php .= "\t\t'search-form',\n";
		$functions_php .= "\t\t'comment-form',\n";
		$functions_php .= "\t\t'comment-list',\n";
		$functions_php .= "\t\t'gallery',\n";
		$functions_php .= "\t\t'caption',\n";
		$functions_php .= "\t) );\n\n";
		$functions_php .= "\t// Register navigation menus\n";
		$functions_php .= "\tregister_nav_menus( array(\n";
		$functions_php .= "\t\t'primary' => __( 'Primary Menu', '{$slug}' ),\n";
		$functions_php .= "\t) );\n";
		$functions_php .= "}\n";
		$functions_php .= "add_action( 'after_setup_theme', '{$slug}_setup' );\n";

		if ( false === $wp_filesystem->put_contents( $theme_dir . '/functions.php', $functions_php, FS_CHMOD_FILE ) ) {
			return json_encode( [
				'error'   => 'Failed to create functions.php',
				'message' => 'Could not write functions.php file. Your hosting may block programmatic PHP file creation.',
				'debug'   => [
					'file' => $theme_dir . '/functions.php',
					'hosting_restriction' => 'PHP files may be blocked for security',
				],
			] );
		}

		// Create header.php
		$header_php = "<!DOCTYPE html>\n";
		$header_php .= "<html <?php language_attributes(); ?>>\n";
		$header_php .= "<head>\n";
		$header_php .= "\t<meta charset=\"<?php bloginfo( 'charset' ); ?>\">\n";
		$header_php .= "\t<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
		$header_php .= "\t<?php wp_head(); ?>\n";
		$header_php .= "</head>\n";
		$header_php .= "<body <?php body_class(); ?>>\n";
		$header_php .= "<?php wp_body_open(); ?>\n";
		$header_php .= "<header>\n";
		$header_php .= "\t<h1><?php bloginfo( 'name' ); ?></h1>\n";
		$header_php .= "\t<p><?php bloginfo( 'description' ); ?></p>\n";
		$header_php .= "</header>\n";
		$header_php .= "<main>\n";

		if ( false === $wp_filesystem->put_contents( $theme_dir . '/header.php', $header_php, FS_CHMOD_FILE ) ) {
			return json_encode( [
				'error'   => 'Failed to create header.php',
				'message' => 'Could not write header.php file. Your hosting may block programmatic PHP file creation.',
				'debug'   => [
					'file' => $theme_dir . '/header.php',
					'hosting_restriction' => 'PHP files may be blocked for security',
				],
			] );
		}

		// Create footer.php
		$footer_php = "</main>\n";
		$footer_php .= "<footer>\n";
		$footer_php .= "\t<p>&copy; <?php echo date( 'Y' ); ?> <?php bloginfo( 'name' ); ?></p>\n";
		$footer_php .= "</footer>\n";
		$footer_php .= "<?php wp_footer(); ?>\n";
		$footer_php .= "</body>\n";
		$footer_php .= "</html>\n";

		if ( false === $wp_filesystem->put_contents( $theme_dir . '/footer.php', $footer_php, FS_CHMOD_FILE ) ) {
			return json_encode( [
				'error'   => 'Failed to create footer.php',
				'message' => 'Could not write footer.php file. Your hosting may block programmatic PHP file creation.',
				'debug'   => [
					'file' => $theme_dir . '/footer.php',
					'hosting_restriction' => 'PHP files may be blocked for security',
				],
			] );
		}

		return json_encode( [
			'success'    => true,
			'message'    => 'Theme created successfully',
			'slug'       => $slug,
			'stylesheet' => $slug,
			'theme_dir'  => $theme_dir,
			'files'      => [
				'style.css',
				'index.php',
				'functions.php',
				'header.php',
				'footer.php',
			],
		] );
	}
}
