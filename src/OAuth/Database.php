<?php
/**
 * OAuth Database Schema
 *
 * @package MCP
 */

namespace MCP\OAuth;

/**
 * Manages OAuth database tables and schema.
 */
class Database {
	/**
	 * Table name for OAuth clients.
	 */
	const CLIENTS_TABLE = 'mcp_oauth_clients';

	/**
	 * Table name for OAuth authorization codes.
	 */
	const CODES_TABLE = 'mcp_oauth_codes';

	/**
	 * Table name for OAuth access tokens.
	 */
	const TOKENS_TABLE = 'mcp_oauth_tokens';

	/**
	 * Creates all OAuth database tables.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// OAuth Clients Table
		$clients_table = $wpdb->prefix . self::CLIENTS_TABLE;
		$clients_sql   = "CREATE TABLE IF NOT EXISTS $clients_table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id VARCHAR(255) NOT NULL,
			client_secret VARCHAR(255) DEFAULT NULL,
			client_name VARCHAR(255) NOT NULL,
			redirect_uris TEXT NOT NULL,
			is_confidential TINYINT(1) DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY client_id (client_id)
		) $charset_collate;";

		// OAuth Authorization Codes Table
		$codes_table = $wpdb->prefix . self::CODES_TABLE;
		$codes_sql   = "CREATE TABLE IF NOT EXISTS $codes_table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(255) NOT NULL,
			client_id VARCHAR(255) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			redirect_uri TEXT NOT NULL,
			code_challenge VARCHAR(255) DEFAULT NULL,
			code_challenge_method VARCHAR(10) DEFAULT 'S256',
			scope TEXT DEFAULT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY code (code),
			KEY client_id (client_id),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) $charset_collate;";

		// OAuth Access Tokens Table
		$tokens_table = $wpdb->prefix . self::TOKENS_TABLE;
		$tokens_sql   = "CREATE TABLE IF NOT EXISTS $tokens_table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token VARCHAR(255) NOT NULL,
			client_id VARCHAR(255) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			scope TEXT DEFAULT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY token (token),
			KEY client_id (client_id),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $clients_sql );
		dbDelta( $codes_sql );
		dbDelta( $tokens_sql );
	}

	/**
	 * Drops all OAuth database tables.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$clients_table = $wpdb->prefix . self::CLIENTS_TABLE;
		$codes_table   = $wpdb->prefix . self::CODES_TABLE;
		$tokens_table  = $wpdb->prefix . self::TOKENS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS $tokens_table" );
		$wpdb->query( "DROP TABLE IF EXISTS $codes_table" );
		$wpdb->query( "DROP TABLE IF EXISTS $clients_table" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Cleans up expired authorization codes and access tokens.
	 *
	 * @return void
	 */
	public static function cleanup_expired(): void {
		global $wpdb;

		$codes_table  = $wpdb->prefix . self::CODES_TABLE;
		$tokens_table = $wpdb->prefix . self::TOKENS_TABLE;

		// Get expired tokens before deleting to clean up WordPress App Passwords
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$expired_tokens = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, token FROM $tokens_table WHERE expires_at < %s",
				current_time( 'mysql' )
			)
		);

		// Delete corresponding WordPress Application Passwords
		foreach ( $expired_tokens as $token_data ) {
			// Token field contains the App Password UUID
			\WP_Application_Passwords::delete_application_password( $token_data->user_id, $token_data->token );
		}

		// Delete expired authorization codes
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $codes_table WHERE expires_at < %s",
				current_time( 'mysql' )
			)
		);

		// Delete expired tokens
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $tokens_table WHERE expires_at < %s",
				current_time( 'mysql' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
