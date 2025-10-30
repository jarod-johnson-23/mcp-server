<?php
/**
 * OAuth Token Endpoint
 *
 * @package MCP
 */

namespace MCP\OAuth;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles OAuth 2.0 token endpoint (exchange code for token).
 */
class TokenController {
	/**
	 * Registers token routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'mcp/v1',
			'/oauth/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'exchange_code_for_token' ),
				'permission_callback' => '__return_true', // Public endpoint (validates credentials in handler)
			)
		);
	}

	/**
	 * Exchanges authorization code for access token.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function exchange_code_for_token( WP_REST_Request $request ) {
		// Get parameters (may be in body or query)
		$grant_type    = $request->get_param( 'grant_type' );
		$code          = $request->get_param( 'code' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );
		$client_id     = $request->get_param( 'client_id' );
		$client_secret = $request->get_param( 'client_secret' );
		$code_verifier = $request->get_param( 'code_verifier' );

		// Validate grant type
		if ( $grant_type !== 'authorization_code' ) {
			return new WP_Error(
				'unsupported_grant_type',
				'Only authorization_code grant type is supported',
				array( 'status' => 400 )
			);
		}

		// Validate required parameters
		if ( empty( $code ) || empty( $redirect_uri ) || empty( $client_id ) || empty( $code_verifier ) ) {
			return new WP_Error(
				'invalid_request',
				'Missing required parameters',
				array( 'status' => 400 )
			);
		}

		// Validate client credentials
		if ( ! ClientRegistry::validate_client( $client_id, $client_secret ) ) {
			return new WP_Error(
				'invalid_client',
				'Invalid client credentials',
				array( 'status' => 401 )
			);
		}

		// Validate and consume authorization code
		$code_data = TokenStorage::validate_authorization_code(
			$code,
			$client_id,
			$redirect_uri,
			$code_verifier
		);

		if ( ! $code_data ) {
			return new WP_Error(
				'invalid_grant',
				'Invalid or expired authorization code',
				array( 'status' => 400 )
			);
		}

		// Create access token
		$access_token = TokenStorage::create_access_token(
			$client_id,
			$code_data['user_id'],
			$code_data['scope']
		);

		// Return token response
		return new WP_REST_Response(
			array(
				'access_token' => $access_token,
				'token_type'   => 'Bearer',
				'expires_in'   => TokenStorage::TOKEN_LIFETIME,
				'scope'        => $code_data['scope'],
			),
			200
		);
	}
}
