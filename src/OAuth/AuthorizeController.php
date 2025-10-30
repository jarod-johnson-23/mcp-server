<?php
/**
 * OAuth Authorization Endpoint
 *
 * @package MCP
 */

namespace MCP\OAuth;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles OAuth 2.0 authorization endpoint (user consent).
 */
class AuthorizeController {
	/**
	 * Registers authorization routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// Authorization endpoint (GET and POST)
		register_rest_route(
			'mcp/v1',
			'/oauth/authorize',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'show_consent_page' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_consent' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Shows the OAuth consent page (GET request).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function show_consent_page( WP_REST_Request $request ) {
		// Validate required parameters
		$params = self::validate_authorization_params( $request );
		if ( is_wp_error( $params ) ) {
			return self::error_redirect( $request->get_param( 'redirect_uri' ), $params );
		}

		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			// Redirect to login with return URL
			$login_url = wp_login_url( $request->get_url() . '?' . http_build_query( $request->get_params() ) );
			return new WP_REST_Response(
				array(
					'redirect' => $login_url,
				),
				302,
				array( 'Location' => $login_url )
			);
		}

		$client = ClientRegistry::get_client( $params['client_id'] );

		// Return HTML consent page
		$html = self::render_consent_page( $client, $params );

		return new WP_REST_Response(
			$html,
			200,
			array( 'Content-Type' => 'text/html' )
		);
	}

	/**
	 * Handles user consent submission (POST request).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function handle_consent( WP_REST_Request $request ) {
		// Validate required parameters
		$params = self::validate_authorization_params( $request );
		if ( is_wp_error( $params ) ) {
			return self::error_redirect( $request->get_param( 'redirect_uri' ), $params );
		}

		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'User must be logged in', array( 'status' => 401 ) );
		}

		// Check if user denied access
		if ( $request->get_param( 'action' ) === 'deny' ) {
			return self::error_redirect(
				$params['redirect_uri'],
				new WP_Error( 'access_denied', 'User denied access' ),
				$params['state']
			);
		}

		// User approved - generate authorization code
		$code = TokenStorage::create_authorization_code(
			$params['client_id'],
			get_current_user_id(),
			$params['redirect_uri'],
			$params['code_challenge'],
			$params['code_challenge_method'],
			$params['scope']
		);

		// Redirect back to client with authorization code
		$redirect_url = add_query_arg(
			array(
				'code'  => $code,
				'state' => $params['state'],
			),
			$params['redirect_uri']
		);

		return new WP_REST_Response(
			array( 'redirect' => $redirect_url ),
			302,
			array( 'Location' => $redirect_url )
		);
	}

	/**
	 * Validates authorization request parameters.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error Validated params or error.
	 */
	private static function validate_authorization_params( WP_REST_Request $request ) {
		$client_id     = $request->get_param( 'client_id' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );
		$response_type = $request->get_param( 'response_type' );
		$code_challenge = $request->get_param( 'code_challenge' );
		$code_challenge_method = $request->get_param( 'code_challenge_method' ) ?? 'S256';
		$state         = $request->get_param( 'state' ) ?? '';
		$scope         = $request->get_param( 'scope' ) ?? 'mcp';

		// Validate required parameters
		if ( empty( $client_id ) ) {
			return new WP_Error( 'invalid_request', 'Missing client_id' );
		}

		if ( empty( $redirect_uri ) ) {
			return new WP_Error( 'invalid_request', 'Missing redirect_uri' );
		}

		if ( $response_type !== 'code' ) {
			return new WP_Error( 'unsupported_response_type', 'Only code response type supported' );
		}

		// Validate PKCE (required by MCP spec)
		if ( empty( $code_challenge ) ) {
			return new WP_Error( 'invalid_request', 'Missing code_challenge (PKCE required)' );
		}

		if ( ! in_array( $code_challenge_method, array( 'S256', 'plain' ), true ) ) {
			return new WP_Error( 'invalid_request', 'Invalid code_challenge_method' );
		}

		// Validate client
		if ( ! ClientRegistry::validate_client( $client_id ) ) {
			return new WP_Error( 'invalid_client', 'Invalid client_id' );
		}

		// Validate redirect URI
		if ( ! ClientRegistry::validate_redirect_uri( $client_id, $redirect_uri ) ) {
			return new WP_Error( 'invalid_request', 'Invalid redirect_uri' );
		}

		return array(
			'client_id'              => $client_id,
			'redirect_uri'           => $redirect_uri,
			'response_type'          => $response_type,
			'code_challenge'         => $code_challenge,
			'code_challenge_method'  => $code_challenge_method,
			'state'                  => $state,
			'scope'                  => $scope,
		);
	}

	/**
	 * Renders the consent page HTML.
	 *
	 * @param object $client Client information.
	 * @param array  $params Authorization parameters.
	 * @return string HTML content.
	 */
	private static function render_consent_page( $client, array $params ): string {
		$user         = wp_get_current_user();
		$site_name    = get_bloginfo( 'name' );
		$client_name  = esc_html( $client->client_name );
		$scope_description = 'access to your WordPress site via MCP';

		ob_start();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>Authorize <?php echo $client_name; ?> - <?php echo esc_html( $site_name ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f5f5f5; padding: 20px; }
				.container { max-width: 500px; margin: 50px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
				h1 { margin-top: 0; color: #333; }
				.client-info { background: #f9f9f9; padding: 15px; border-radius: 4px; margin: 20px 0; }
				.user-info { color: #666; margin: 15px 0; }
				.permissions { margin: 20px 0; }
				.permissions ul { list-style: none; padding: 0; }
				.permissions li { padding: 8px 0; border-bottom: 1px solid #eee; }
				.buttons { display: flex; gap: 10px; margin-top: 30px; }
				button { flex: 1; padding: 12px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
				.approve { background: #2271b1; color: white; }
				.approve:hover { background: #135e96; }
				.deny { background: #ddd; color: #333; }
				.deny:hover { background: #ccc; }
			</style>
		</head>
		<body>
			<div class="container">
				<h1>Authorize Access</h1>

				<div class="client-info">
					<strong><?php echo $client_name; ?></strong> wants to access your WordPress site
				</div>

				<div class="user-info">
					Logged in as: <strong><?php echo esc_html( $user->display_name ); ?></strong>
				</div>

				<div class="permissions">
					<h3>This will allow <?php echo $client_name; ?> to:</h3>
					<ul>
						<li>✓ Read and manage posts, pages, and media</li>
						<li>✓ Access WordPress REST API on your behalf</li>
						<li>✓ Perform actions as your user account</li>
					</ul>
				</div>

				<form method="POST" action="<?php echo esc_url( rest_url( 'mcp/v1/oauth/authorize' ) ); ?>">
					<input type="hidden" name="client_id" value="<?php echo esc_attr( $params['client_id'] ); ?>">
					<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $params['redirect_uri'] ); ?>">
					<input type="hidden" name="response_type" value="<?php echo esc_attr( $params['response_type'] ); ?>">
					<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $params['code_challenge'] ); ?>">
					<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $params['code_challenge_method'] ); ?>">
					<input type="hidden" name="state" value="<?php echo esc_attr( $params['state'] ); ?>">
					<input type="hidden" name="scope" value="<?php echo esc_attr( $params['scope'] ); ?>">

					<div class="buttons">
						<button type="submit" name="action" value="deny" class="deny">Deny</button>
						<button type="submit" name="action" value="approve" class="approve">Authorize</button>
					</div>
				</form>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Redirects to client with error.
	 *
	 * @param string      $redirect_uri Redirect URI.
	 * @param WP_Error    $error        Error object.
	 * @param string|null $state        State parameter.
	 * @return WP_REST_Response Response object.
	 */
	private static function error_redirect( $redirect_uri, WP_Error $error, ?string $state = null ): WP_REST_Response {
		$params = array(
			'error'             => $error->get_error_code(),
			'error_description' => $error->get_error_message(),
		);

		if ( $state ) {
			$params['state'] = $state;
		}

		$redirect_url = add_query_arg( $params, $redirect_uri );

		return new WP_REST_Response(
			array( 'redirect' => $redirect_url ),
			302,
			array( 'Location' => $redirect_url )
		);
	}
}
