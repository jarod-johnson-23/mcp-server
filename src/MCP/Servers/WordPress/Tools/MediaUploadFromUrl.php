<?php

namespace McpWp\MCP\Servers\WordPress\Tools;

use McpWp\MCP\Server;

/**
 * Media upload from URL tool.
 *
 * Fetches media from a URL (e.g., Figma) and uploads it to WordPress media library.
 *
 * @phpstan-import-type ToolDefinition from Server
 */
readonly class MediaUploadFromUrl {
	/**
	 * Returns the media upload from URL tool definition.
	 *
	 * @return array<int, ToolDefinition> Tools.
	 */
	public function get_tools(): array {
		return [
			[
				'name'        => 'upload_media_from_url',
				'description' => 'Upload media to WordPress media library from a URL (e.g., Figma export URL). Fetches the file from the URL and creates a media library entry.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'url'         => [
							'type'        => 'string',
							'description' => 'The URL of the image/file to upload (e.g., Figma export URL)',
						],
						'title'       => [
							'type'        => 'string',
							'description' => 'The title for the attachment',
						],
						'alt_text'    => [
							'type'        => 'string',
							'description' => 'Alternative text to display when attachment is not displayed',
						],
						'caption'     => [
							'type'        => 'string',
							'description' => 'The attachment caption',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'The attachment description',
						],
						'post'        => [
							'type'        => 'integer',
							'description' => 'The ID of the associated post',
						],
						'author'      => [
							'type'        => 'integer',
							'description' => 'The ID of the attachment author',
						],
					],
					'required'   => [ 'url' ],
				],
				'annotations' => [
					'title'           => 'Upload Media from URL',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'upload_from_url' ],
			],
		];
	}

	/**
	 * Upload media from URL callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with media details.
	 */
	public function upload_from_url( array $params ): string {
		$url = $params['url'] ?? null;

		if ( ! $url ) {
			return json_encode( [
				'error' => 'URL parameter is required',
			] );
		}

		// Validate URL
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return json_encode( [
				'error' => 'Invalid URL provided',
			] );
		}

		// Fetch the file from URL
		$response = wp_remote_get( $url, [
			'timeout'     => 30,
			'redirection' => 5,
		] );

		if ( is_wp_error( $response ) ) {
			return json_encode( [
				'error'   => 'Failed to fetch file from URL',
				'message' => $response->get_error_message(),
			] );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code !== 200 ) {
			return json_encode( [
				'error'   => 'Failed to fetch file from URL',
				'message' => "HTTP {$response_code}",
			] );
		}

		$file_content = wp_remote_retrieve_body( $response );
		if ( empty( $file_content ) ) {
			return json_encode( [
				'error' => 'File content is empty',
			] );
		}

		// Get content type from response
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Compress PNG/JPEG images using TinyPNG
		$file_content = $this->compress_image( $file_content, $content_type );

		// Determine file extension from content type or URL
		$extension = $this->get_file_extension( $url, $content_type );

		// Generate a unique filename
		$filename = wp_unique_filename(
			wp_upload_dir()['path'],
			$this->generate_filename( $url, $extension, $params )
		);

		// Save to temp file
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$saved = file_put_contents( $file_path, $file_content );

		if ( false === $saved ) {
			return json_encode( [
				'error' => 'Failed to save file to uploads directory',
			] );
		}

		// Prepare attachment data
		$attachment_data = [
			'post_mime_type' => $content_type ?: $this->get_mime_type( $file_path ),
			'post_title'     => $params['title'] ?? sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => $params['description'] ?? '',
			'post_excerpt'   => $params['caption'] ?? '',
			'post_status'    => 'inherit',
		];

		if ( isset( $params['post'] ) ) {
			$attachment_data['post_parent'] = absint( $params['post'] );
		}

		if ( isset( $params['author'] ) ) {
			$attachment_data['post_author'] = absint( $params['author'] );
		}

		// Insert the attachment
		$attachment_id = wp_insert_attachment( $attachment_data, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			// Clean up file on error
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $file_path );

			return json_encode( [
				'error'   => 'Failed to create attachment',
				'message' => $attachment_id->get_error_message(),
			] );
		}

		// Generate attachment metadata
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

		// Update alt text if provided
		if ( isset( $params['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt_text'] ) );
		}

		// Get attachment details
		$attachment_url = wp_get_attachment_url( $attachment_id );
		$attachment     = get_post( $attachment_id );

		return json_encode( [
			'success'         => true,
			'id'              => $attachment_id,
			'url'             => $attachment_url,
			'media_type'      => wp_attachment_is_image( $attachment_id ) ? 'image' : 'file',
			'mime_type'       => get_post_mime_type( $attachment_id ),
			'title'           => $attachment->post_title,
			'alt_text'        => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'         => $attachment->post_excerpt,
			'description'     => $attachment->post_content,
			'source_url'      => $url,
			'date'            => $attachment->post_date,
			'modified'        => $attachment->post_modified,
		] );
	}

	/**
	 * Get file extension from URL and content type.
	 *
	 * @param string $url          Source URL.
	 * @param string $content_type Content-Type header.
	 * @return string File extension.
	 */
	private function get_file_extension( string $url, string $content_type ): string {
		// Try to get extension from URL first
		$path = parse_url( $url, PHP_URL_PATH );
		if ( $path ) {
			$extension = pathinfo( $path, PATHINFO_EXTENSION );
			if ( $extension ) {
				return strtolower( $extension );
			}
		}

		// Fall back to content type
		$mime_to_ext = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/svg+xml' => 'svg',
			'application/pdf' => 'pdf',
		];

		return $mime_to_ext[ $content_type ] ?? 'jpg';
	}

	/**
	 * Generate filename from URL and parameters.
	 *
	 * @param string               $url       Source URL.
	 * @param string               $extension File extension.
	 * @param array<string, mixed> $params    Tool parameters.
	 * @return string Generated filename.
	 */
	private function generate_filename( string $url, string $extension, array $params ): string {
		// Use title if provided
		if ( ! empty( $params['title'] ) ) {
			return sanitize_file_name( $params['title'] ) . '.' . $extension;
		}

		// Try to get filename from URL
		$path = parse_url( $url, PHP_URL_PATH );
		if ( $path ) {
			$basename = basename( $path );
			// Remove existing extension and add our determined one
			$basename = pathinfo( $basename, PATHINFO_FILENAME );
			if ( $basename && $basename !== '.' && $basename !== '' ) {
				return sanitize_file_name( $basename ) . '.' . $extension;
			}
		}

		// Generate generic name with timestamp
		return 'media-' . time() . '.' . $extension;
	}

	/**
	 * Get MIME type from file path.
	 *
	 * @param string $file_path File path.
	 * @return string MIME type.
	 */
	private function get_mime_type( string $file_path ): string {
		$filetype = wp_check_filetype( $file_path );
		return $filetype['type'] ?: 'application/octet-stream';
	}

	/**
	 * Compress image using TinyPNG API.
	 *
	 * Compresses PNG and JPEG images to reduce file size while maintaining quality.
	 * Falls back to original image if compression fails.
	 *
	 * @param string $file_content Original file content.
	 * @param string $content_type MIME type of the file.
	 * @return string Compressed file content or original if compression fails/not applicable.
	 */
	private function compress_image( string $file_content, string $content_type ): string {
		// Load configuration
		$config = require __DIR__ . '/../../../../config.php';

		// Check if TinyPNG is enabled and configured
		if ( empty( $config['tinypng']['enabled'] ) || empty( $config['tinypng']['api_key'] ) ) {
			return $file_content; // Return original if not configured
		}

		// Only compress PNG and JPEG images
		$compressible_types = [ 'image/png', 'image/jpeg', 'image/jpg' ];
		if ( ! in_array( $content_type, $compressible_types, true ) ) {
			return $file_content; // Return original for non-compressible types
		}

		$api_key = $config['tinypng']['api_key'];

		// Validate API key is not the placeholder
		if ( $api_key === 'YOUR_TINYPNG_API_KEY_HERE' ) {
			return $file_content; // Return original if API key not set
		}

		try {
			// Send image to TinyPNG API for compression
			$response = wp_remote_post(
				'https://api.tinify.com/shrink',
				[
					'timeout' => 30,
					'headers' => [
						'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ),
					],
					'body'    => $file_content,
				]
			);

			// Check for errors
			if ( is_wp_error( $response ) ) {
				// Fallback to original on error
				return $file_content;
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			// TinyPNG returns 201 on success
			if ( $response_code !== 201 ) {
				// Fallback to original on non-201 response
				return $file_content;
			}

			// Get the URL of the compressed image from response headers
			$compressed_url = wp_remote_retrieve_header( $response, 'location' );

			if ( empty( $compressed_url ) ) {
				// Fallback to original if no location header
				return $file_content;
			}

			// Download the compressed image
			$compressed_response = wp_remote_get(
				$compressed_url,
				[
					'timeout' => 30,
				]
			);

			if ( is_wp_error( $compressed_response ) ) {
				// Fallback to original on error
				return $file_content;
			}

			$compressed_code = wp_remote_retrieve_response_code( $compressed_response );
			if ( $compressed_code !== 200 ) {
				// Fallback to original on non-200 response
				return $file_content;
			}

			$compressed_content = wp_remote_retrieve_body( $compressed_response );

			if ( empty( $compressed_content ) ) {
				// Fallback to original if compressed content is empty
				return $file_content;
			}

			// Return compressed image
			return $compressed_content;

		} catch ( \Exception $e ) {
			// Fallback to original on any exception
			return $file_content;
		}
	}
}
