<?php
/**
 * OAuth Discovery Endpoints
 *
 * @package MCP
 */

namespace MCP\OAuth;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles OAuth 2.0 discovery endpoints per RFC 8414 and RFC 9728.
 */
class DiscoveryController {
	/**
	 * Registers discovery routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// Protected Resource Metadata (RFC 9728)
		register_rest_route(
			'',
			'/.well-known/oauth-protected-resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'protected_resource_metadata' ),
				'permission_callback' => '__return_true', // Public endpoint
			)
		);

		// Authorization Server Metadata (RFC 8414)
		register_rest_route(
			'',
			'/.well-known/oauth-authorization-server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'authorization_server_metadata' ),
				'permission_callback' => '__return_true', // Public endpoint
			)
		);
	}

	/**
	 * Returns OAuth Protected Resource Metadata.
	 *
	 * This tells clients where the authorization server is.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function protected_resource_metadata( WP_REST_Request $request ): WP_REST_Response {
		$base_url = get_site_url();

		$metadata = array(
			'resource'              => $base_url . '/wp-json/mcp/v1/mcp',
			'authorization_servers' => array( $base_url ),
		);

		return new WP_REST_Response( $metadata, 200 );
	}

	/**
	 * Returns OAuth Authorization Server Metadata.
	 *
	 * This tells clients about OAuth capabilities and endpoints.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function authorization_server_metadata( WP_REST_Request $request ): WP_REST_Response {
		$base_url = get_site_url();

		$metadata = array(
			'issuer'                                => $base_url,
			'authorization_endpoint'                => $base_url . '/wp-json/mcp/v1/oauth/authorize',
			'token_endpoint'                        => $base_url . '/wp-json/mcp/v1/oauth/token',
			'registration_endpoint'                 => $base_url . '/wp-json/mcp/v1/oauth/register',
			'scopes_supported'                      => array( 'mcp' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
			'code_challenge_methods_supported'      => array( 'S256', 'plain' ),
			'resource_indicators_supported'         => true,
		);

		return new WP_REST_Response( $metadata, 200 );
	}
}
