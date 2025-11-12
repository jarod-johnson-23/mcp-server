<?php

namespace McpWp\MCP\Servers\WordPress\Tools;

use McpWp\MCP\Server;

/**
 * Elementor page creation from URL tool.
 *
 * Downloads Elementor JSON from a URL and creates a WordPress page with that content.
 *
 * @phpstan-import-type ToolDefinition from Server
 */
readonly class ElementorPageFromUrl {
	/**
	 * Returns the Elementor page from URL tool definition.
	 *
	 * @return array<int, ToolDefinition> Tools.
	 */
	public function get_tools(): array {
		return [
			[
				'name'        => 'create_elementor_page_from_url',
				'description' => 'Create an Elementor page from a JSON file URL. Downloads the Elementor JSON structure from the provided URL and creates a WordPress page with that content.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'url'         => [
							'type'        => 'string',
							'description' => 'The URL to the Elementor JSON file',
						],
						'title'       => [
							'type'        => 'string',
							'description' => 'The page title',
						],
						'status'      => [
							'type'        => 'string',
							'description' => 'Page status (publish, draft, pending, private)',
							'enum'        => [ 'publish', 'draft', 'pending', 'private' ],
						],
						'page_id'     => [
							'type'        => 'integer',
							'description' => 'Optional page ID to update an existing page instead of creating a new one',
						],
						'slug'        => [
							'type'        => 'string',
							'description' => 'URL slug for the page',
						],
						'author'      => [
							'type'        => 'integer',
							'description' => 'The ID for the author of the page',
						],
						'parent'      => [
							'type'        => 'integer',
							'description' => 'The ID for the parent of the page',
						],
					],
					'required'   => [ 'url', 'title' ],
				],
				'annotations' => [
					'title'           => 'Create Elementor Page from URL',
					'readOnlyHint'    => false,
					'idempotentHint'  => false,
					'destructiveHint' => false,
				],
				'callback'    => [ $this, 'create_page_from_url' ],
			],
		];
	}

	/**
	 * Create Elementor page from URL callback.
	 *
	 * @param array<string, mixed> $params Tool parameters.
	 * @return string JSON response with page details.
	 */
	public function create_page_from_url( array $params ): string {
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

		// Fetch the JSON file from URL
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

		$json_content = wp_remote_retrieve_body( $response );
		if ( empty( $json_content ) ) {
			return json_encode( [
				'error' => 'File content is empty',
			] );
		}

		// Validate JSON
		$elementor_data = json_decode( $json_content, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return json_encode( [
				'error'   => 'Invalid JSON content',
				'message' => json_last_error_msg(),
			] );
		}

		// Determine if we're creating or updating
		$page_id = $params['page_id'] ?? null;
		$is_update = ! empty( $page_id );

		// Prepare page data
		$page_data = [
			'post_title'   => $params['title'],
			'post_status'  => $params['status'] ?? 'draft',
			'post_type'    => 'page',
		];

		if ( isset( $params['slug'] ) ) {
			$page_data['post_name'] = sanitize_title( $params['slug'] );
		}

		if ( isset( $params['author'] ) ) {
			$page_data['post_author'] = absint( $params['author'] );
		}

		if ( isset( $params['parent'] ) ) {
			$page_data['post_parent'] = absint( $params['parent'] );
		}

		// Create or update the page
		if ( $is_update ) {
			$page_data['ID'] = absint( $page_id );
			$result = wp_update_post( $page_data, true );
		} else {
			$result = wp_insert_post( $page_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return json_encode( [
				'error'   => $is_update ? 'Failed to update page' : 'Failed to create page',
				'message' => $result->get_error_message(),
			] );
		}

		$page_id = $result;

		// Set Elementor meta data
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $page_id, '_elementor_version', '3.0.0' ); // You can adjust this version
		update_post_meta( $page_id, '_elementor_data', wp_slash( $json_content ) );

		// Mark page as built with Elementor
		update_post_meta( $page_id, '_elementor_page_settings', [] );

		// Get page details
		$page = get_post( $page_id );
		$page_url = get_permalink( $page_id );

		return json_encode( [
			'success'    => true,
			'action'     => $is_update ? 'updated' : 'created',
			'id'         => $page_id,
			'url'        => $page_url,
			'edit_url'   => admin_url( "post.php?post={$page_id}&action=elementor" ),
			'title'      => $page->post_title,
			'status'     => $page->post_status,
			'slug'       => $page->post_name,
			'source_url' => $url,
			'date'       => $page->post_date,
			'modified'   => $page->post_modified,
		] );
	}
}
