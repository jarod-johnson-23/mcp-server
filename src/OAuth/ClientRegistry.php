<?php
/**
 * OAuth Client Registry
 *
 * @package MCP
 */

namespace MCP\OAuth;

/**
 * Manages OAuth client registration and validation.
 */
class ClientRegistry {
	/**
	 * Registers a new OAuth client.
	 *
	 * @param string   $client_name      Human-readable client name.
	 * @param string[] $redirect_uris    Array of allowed redirect URIs.
	 * @param bool     $is_confidential  Whether this is a confidential client (has secret).
	 * @return array{client_id: string, client_secret: string|null} Client credentials.
	 */
	public static function register_client( string $client_name, array $redirect_uris, bool $is_confidential = false ): array {
		global $wpdb;

		$client_id = self::generate_client_id();
		$client_secret = $is_confidential ? self::generate_client_secret() : null;

		$table = $wpdb->prefix . Database::CLIENTS_TABLE;

		$wpdb->insert(
			$table,
			array(
				'client_id'       => $client_id,
				'client_secret'   => $client_secret ? password_hash( $client_secret, PASSWORD_BCRYPT ) : null,
				'client_name'     => $client_name,
				'redirect_uris'   => wp_json_encode( $redirect_uris ),
				'is_confidential' => $is_confidential ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}

	/**
	 * Validates OAuth client credentials.
	 *
	 * @param string      $client_id     Client ID.
	 * @param string|null $client_secret Client secret (if confidential client).
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_client( string $client_id, ?string $client_secret = null ): bool {
		global $wpdb;

		$table = $wpdb->prefix . Database::CLIENTS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE client_id = %s",
				$client_id
			)
		);

		if ( ! $client ) {
			return false;
		}

		// If client has a secret, validate it
		if ( $client->client_secret ) {
			if ( ! $client_secret ) {
				return false;
			}
			return password_verify( $client_secret, $client->client_secret );
		}

		// Public client (no secret)
		return true;
	}

	/**
	 * Validates that a redirect URI is registered for a client.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $redirect_uri Redirect URI to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_redirect_uri( string $client_id, string $redirect_uri ): bool {
		global $wpdb;

		$table = $wpdb->prefix . Database::CLIENTS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT redirect_uris FROM $table WHERE client_id = %s",
				$client_id
			)
		);

		if ( ! $client ) {
			return false;
		}

		$allowed_uris = json_decode( $client->redirect_uris, true );
		if ( ! is_array( $allowed_uris ) ) {
			return false;
		}

		// Exact match required for security
		return in_array( $redirect_uri, $allowed_uris, true );
	}

	/**
	 * Gets client information by client ID.
	 *
	 * @param string $client_id Client ID.
	 * @return object|null Client object or null if not found.
	 */
	public static function get_client( string $client_id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . Database::CLIENTS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE client_id = %s",
				$client_id
			)
		);

		return $client ?: null;
	}

	/**
	 * Generates a cryptographically secure client ID.
	 *
	 * @return string Client ID.
	 */
	private static function generate_client_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Generates a cryptographically secure client secret.
	 *
	 * @return string Client secret.
	 */
	private static function generate_client_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
