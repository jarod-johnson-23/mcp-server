<?php
/**
 * OAuth Discovery Endpoints
 *
 * @package MCP
 */

namespace MCP\OAuth;

/**
 * Handles OAuth 2.0 discovery endpoints per RFC 8414 and RFC 9728.
 *
 * These endpoints MUST be at /.well-known/ (root level), not under /wp-json/.
 */
class DiscoveryController {
	/**
	 * Registers discovery routes using WordPress rewrite rules.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// Add rewrite rules for .well-known endpoints
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource$',
			'index.php?mcp_oauth_discovery=protected-resource',
			'top'
		);

		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server$',
			'index.php?mcp_oauth_discovery=authorization-server',
			'top'
		);

		// Add query var
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );

		// Handle the request
		add_action( 'template_redirect', array( __CLASS__, 'handle_discovery_request' ) );
	}

	/**
	 * Adds custom query vars.
	 *
	 * @param array<string> $vars Query vars.
	 * @return array<string> Modified query vars.
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'mcp_oauth_discovery';
		return $vars;
	}

	/**
	 * Handles OAuth discovery requests.
	 *
	 * @return void
	 */
	public static function handle_discovery_request(): void {
		$discovery_type = get_query_var( 'mcp_oauth_discovery', false );

		if ( ! $discovery_type ) {
			return;
		}

		// Set JSON content type
		header( 'Content-Type: application/json; charset=utf-8' );

		if ( 'protected-resource' === $discovery_type ) {
			echo wp_json_encode( self::get_protected_resource_metadata() );
			exit;
		}

		if ( 'authorization-server' === $discovery_type ) {
			echo wp_json_encode( self::get_authorization_server_metadata() );
			exit;
		}

		// Unknown discovery type
		status_header( 404 );
		exit;
	}

	/**
	 * Returns OAuth Protected Resource Metadata.
	 *
	 * This tells clients where the authorization server is.
	 *
	 * @return array<string, mixed> Metadata array.
	 */
	private static function get_protected_resource_metadata(): array {
		$base_url = get_site_url();

		return array(
			'resource'              => $base_url . '/wp-json/mcp/v1/mcp',
			'authorization_servers' => array( $base_url ),
		);
	}

	/**
	 * Returns OAuth Authorization Server Metadata.
	 *
	 * This tells clients about OAuth capabilities and endpoints.
	 *
	 * @return array<string, mixed> Metadata array.
	 */
	private static function get_authorization_server_metadata(): array {
		$base_url = get_site_url();

		return array(
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
	}
}
