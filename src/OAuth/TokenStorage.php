<?php
/**
 * OAuth Token Storage
 *
 * @package MCP
 */

namespace MCP\OAuth;

/**
 * Manages OAuth authorization codes and access tokens.
 */
class TokenStorage {
	/**
	 * Authorization code lifetime in seconds (10 minutes).
	 */
	const CODE_LIFETIME = 600;

	/**
	 * Access token lifetime in seconds (1 hour).
	 */
	const TOKEN_LIFETIME = 3600;

	/**
	 * Creates a new authorization code.
	 *
	 * @param string $client_id            Client ID.
	 * @param int    $user_id              WordPress user ID.
	 * @param string $redirect_uri         Redirect URI.
	 * @param string $code_challenge       PKCE code challenge.
	 * @param string $code_challenge_method PKCE method (S256 or plain).
	 * @param string $scope                OAuth scope.
	 * @return string Authorization code.
	 */
	public static function create_authorization_code(
		string $client_id,
		int $user_id,
		string $redirect_uri,
		string $code_challenge,
		string $code_challenge_method = 'S256',
		string $scope = ''
	): string {
		global $wpdb;

		$code       = self::generate_code();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::CODE_LIFETIME );

		$table = $wpdb->prefix . Database::CODES_TABLE;

		$wpdb->insert(
			$table,
			array(
				'code'                   => $code,
				'client_id'              => $client_id,
				'user_id'                => $user_id,
				'redirect_uri'           => $redirect_uri,
				'code_challenge'         => $code_challenge,
				'code_challenge_method'  => $code_challenge_method,
				'scope'                  => $scope,
				'expires_at'             => $expires_at,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $code;
	}

	/**
	 * Validates and consumes an authorization code.
	 *
	 * @param string $code          Authorization code.
	 * @param string $client_id     Client ID.
	 * @param string $redirect_uri  Redirect URI.
	 * @param string $code_verifier PKCE code verifier.
	 * @return array{user_id: int, scope: string}|null Code data if valid, null otherwise.
	 */
	public static function validate_authorization_code(
		string $code,
		string $client_id,
		string $redirect_uri,
		string $code_verifier
	): ?array {
		global $wpdb;

		$table = $wpdb->prefix . Database::CODES_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$code_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE code = %s AND client_id = %s",
				$code,
				$client_id
			)
		);

		if ( ! $code_data ) {
			return null;
		}

		// Check expiration
		if ( strtotime( $code_data->expires_at ) < time() ) {
			self::delete_authorization_code( $code );
			return null;
		}

		// Validate redirect URI
		if ( $code_data->redirect_uri !== $redirect_uri ) {
			return null;
		}

		// Validate PKCE
		if ( ! self::validate_pkce( $code_verifier, $code_data->code_challenge, $code_data->code_challenge_method ) ) {
			return null;
		}

		// Code is valid - delete it (one-time use)
		self::delete_authorization_code( $code );

		return array(
			'user_id' => (int) $code_data->user_id,
			'scope'   => $code_data->scope,
		);
	}

	/**
	 * Creates a new access token.
	 *
	 * @param string $client_id Client ID.
	 * @param int    $user_id   WordPress user ID.
	 * @param string $scope     OAuth scope.
	 * @return string Access token.
	 */
	public static function create_access_token( string $client_id, int $user_id, string $scope = '' ): string {
		global $wpdb;

		$token      = self::generate_token();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_LIFETIME );

		$table = $wpdb->prefix . Database::TOKENS_TABLE;

		$wpdb->insert(
			$table,
			array(
				'token'      => $token,
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scope'      => $scope,
				'expires_at' => $expires_at,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		return $token;
	}

	/**
	 * Validates an access token and returns associated data.
	 *
	 * @param string $token Access token.
	 * @return array{user_id: int, client_id: string, scope: string}|null Token data if valid, null otherwise.
	 */
	public static function validate_access_token( string $token ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . Database::TOKENS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$token_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE token = %s",
				$token
			)
		);

		if ( ! $token_data ) {
			return null;
		}

		// Check expiration
		if ( strtotime( $token_data->expires_at ) < time() ) {
			self::delete_access_token( $token );
			return null;
		}

		return array(
			'user_id'   => (int) $token_data->user_id,
			'client_id' => $token_data->client_id,
			'scope'     => $token_data->scope,
		);
	}

	/**
	 * Validates PKCE code verifier against challenge.
	 *
	 * @param string $verifier PKCE code verifier.
	 * @param string $challenge PKCE code challenge.
	 * @param string $method PKCE method (S256 or plain).
	 * @return bool True if valid, false otherwise.
	 */
	private static function validate_pkce( string $verifier, string $challenge, string $method ): bool {
		if ( 'S256' === $method ) {
			$computed_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			return hash_equals( $challenge, $computed_challenge );
		} elseif ( 'plain' === $method ) {
			return hash_equals( $challenge, $verifier );
		}

		return false;
	}

	/**
	 * Deletes an authorization code.
	 *
	 * @param string $code Authorization code.
	 * @return void
	 */
	private static function delete_authorization_code( string $code ): void {
		global $wpdb;

		$table = $wpdb->prefix . Database::CODES_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete(
			$table,
			array( 'code' => $code ),
			array( '%s' )
		);
	}

	/**
	 * Deletes an access token.
	 *
	 * @param string $token Access token.
	 * @return void
	 */
	private static function delete_access_token( string $token ): void {
		global $wpdb;

		$table = $wpdb->prefix . Database::TOKENS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete(
			$table,
			array( 'token' => $token ),
			array( '%s' )
		);
	}

	/**
	 * Generates a cryptographically secure authorization code.
	 *
	 * @return string Authorization code.
	 */
	private static function generate_code(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Generates a cryptographically secure access token.
	 *
	 * @return string Access token.
	 */
	private static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
