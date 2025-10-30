<?php
/**
 * OAuth Dynamic Client Registration
 *
 * @package MCP
 */

namespace MCP\OAuth;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles OAuth 2.0 Dynamic Client Registration (RFC 7591).
 */
class RegistrationController {
	/**
	 * Registers registration routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'mcp/v1',
			'/oauth/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'register_client' ),
				'permission_callback' => '__return_true', // Public endpoint for client registration
			)
		);
	}

	/**
	 * Registers a new OAuth client dynamically.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function register_client( WP_REST_Request $request ) {
		// Get registration parameters
		$client_name    = $request->get_param( 'client_name' );
		$redirect_uris  = $request->get_param( 'redirect_uris' );
		$token_endpoint_auth_method = $request->get_param( 'token_endpoint_auth_method' ) ?? 'none';

		// Validate required parameters
		if ( empty( $client_name ) ) {
			return new WP_Error(
				'invalid_client_metadata',
				'client_name is required',
				array( 'status' => 400 )
			);
		}

		if ( empty( $redirect_uris ) || ! is_array( $redirect_uris ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				'redirect_uris must be a non-empty array',
				array( 'status' => 400 )
			);
		}

		// Validate redirect URIs
		foreach ( $redirect_uris as $uri ) {
			if ( ! self::is_valid_redirect_uri( $uri ) ) {
				return new WP_Error(
					'invalid_redirect_uri',
					'Invalid redirect URI: ' . $uri,
					array( 'status' => 400 )
				);
			}
		}

		// Determine if this is a confidential client
		$is_confidential = ( $token_endpoint_auth_method !== 'none' );

		// Register the client
		$credentials = ClientRegistry::register_client(
			$client_name,
			$redirect_uris,
			$is_confidential
		);

		// Build response per RFC 7591
		$response = array(
			'client_id'                  => $credentials['client_id'],
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $token_endpoint_auth_method,
		);

		// Include client_secret only for confidential clients
		if ( $is_confidential && $credentials['client_secret'] ) {
			$response['client_secret'] = $credentials['client_secret'];
		}

		return new WP_REST_Response( $response, 201 );
	}

	/**
	 * Validates a redirect URI.
	 *
	 * @param string $uri URI to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private static function is_valid_redirect_uri( string $uri ): bool {
		// Must be a valid URL
		$parsed = wp_parse_url( $uri );
		if ( ! $parsed || ! isset( $parsed['scheme'] ) ) {
			return false;
		}

		// For security, only allow https or http://localhost for development
		if ( $parsed['scheme'] === 'https' ) {
			return true;
		}

		if ( $parsed['scheme'] === 'http' && isset( $parsed['host'] ) ) {
			$host = $parsed['host'];
			// Allow localhost, 127.0.0.1, and [::1] for development
			return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
		}

		return false;
	}
}
