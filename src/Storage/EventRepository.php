<?php
/**
 * Reads and writes trace events.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the trace must always be read fresh.

/**
 * Append-only trace of what happened to each lead.
 */
final class EventRepository {

	/**
	 * Append one event.
	 *
	 * @param int      $lead_id       Lead ID.
	 * @param string   $step          One of the Step constants.
	 * @param string   $outcome       Outcome name, or empty.
	 * @param int|null $http_status   HTTP status, if a response arrived.
	 * @param int|null $duration_ms   How long the step took.
	 * @param string   $detail        Short, already scrubbed detail.
	 * @param int|null $created_at_ms Unix time in milliseconds, defaults to now.
	 * @return void
	 */
	public function add( int $lead_id, string $step, string $outcome = '', ?int $http_status = null, ?int $duration_ms = null, string $detail = '', ?int $created_at_ms = null ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::events_table(),
			array(
				'lead_id'       => $lead_id,
				'step'          => $step,
				'outcome'       => $outcome,
				'http_status'   => $http_status,
				'duration_ms'   => $duration_ms,
				'detail'        => mb_substr( $detail, 0, 500 ),
				'created_at_ms' => $created_at_ms ?? self::now_ms(),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%d' )
		);
	}

	/**
	 * All events of a lead, oldest first.
	 *
	 * @param int $lead_id Lead ID.
	 * @return array[]
	 */
	public function for_lead( int $lead_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE lead_id = %d ORDER BY id ASC', Schema::events_table(), $lead_id ),
			ARRAY_A
		);
	}

	/**
	 * Current Unix time in milliseconds.
	 *
	 * @return int
	 */
	public static function now_ms(): int {
		return (int) round( microtime( true ) * 1000 );
	}
}
