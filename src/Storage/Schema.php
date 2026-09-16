<?php
/**
 * Creates and upgrades the plugin's tables.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Storage;

/**
 * Two tables: one row per lead, and one row per step of its journey.
 */
final class Schema {

	public const VERSION = '1';

	public const VERSION_OPTION = 'delivery_trace_db_version';

	/**
	 * Leads table name with the site prefix.
	 *
	 * @return string
	 */
	public static function leads_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'delivery_trace_leads';
	}

	/**
	 * Events table name with the site prefix.
	 *
	 * @return string
	 */
	public static function events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'delivery_trace_events';
	}

	/**
	 * Create or update both tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$leads           = self::leads_table();
		$events          = self::events_table();

		dbDelta(
			"CREATE TABLE {$leads} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				status varchar(20) NOT NULL,
				payload longtext NULL,
				flags varchar(191) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_attempt_at datetime NULL,
				locked_until datetime NULL,
				delivered_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY status_next_attempt (status,next_attempt_at)
			) {$charset_collate};
			CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL,
				step varchar(40) NOT NULL,
				outcome varchar(30) NOT NULL DEFAULT '',
				http_status smallint(5) unsigned NULL,
				duration_ms int(10) unsigned NULL,
				detail varchar(500) NOT NULL DEFAULT '',
				created_at_ms bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				KEY lead_id (lead_id,id)
			) {$charset_collate};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Install when the stored schema version is missing or old.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}
}
