<?php
/**
 * Reads and writes leads.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; delivery state must always be read fresh.

/**
 * Lead rows and the atomic claim that guards every delivery attempt.
 */
final class LeadRepository {

	/**
	 * Insert a new pending lead.
	 *
	 * @param string   $uuid    Idempotency key.
	 * @param array    $payload Sanitized form values.
	 * @param string[] $flags   Flags such as no_js or demo.
	 * @param int      $now     Current Unix time.
	 * @return int Lead ID, or 0 when the insert failed.
	 */
	public function create( string $uuid, array $payload, array $flags, int $now ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::leads_table(),
			array(
				'uuid'       => $uuid,
				'status'     => LeadStatus::PENDING,
				'payload'    => wp_json_encode( $payload ),
				'flags'      => implode( ',', $flags ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * One lead as an associative array.
	 *
	 * @param int $id Lead ID.
	 * @return array|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::leads_table(), $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The most recent leads, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array[]
	 */
	public function latest( int $limit ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Schema::leads_table(), $limit ),
			ARRAY_A
		);
	}

	/**
	 * Leads an automatic runner should attempt now.
	 *
	 * Due retries, leads whose attempt died with the lock expired, and
	 * pending leads whose first attempt never started.
	 *
	 * @param int $now          Current Unix time.
	 * @param int $grace_period Seconds a pending lead may wait for its first attempt.
	 * @param int $limit        Maximum rows.
	 * @return int[]
	 */
	public function due_ids( int $now, int $grace_period, int $limit ): array {
		return $this->select_due( $now, $grace_period, $limit, 0 );
	}

	/**
	 * Whether one lead is due for an automatic attempt.
	 *
	 * @param int $id           Lead ID.
	 * @param int $now          Current Unix time.
	 * @param int $grace_period Seconds a pending lead may wait for its first attempt.
	 * @return bool
	 */
	public function is_due( int $id, int $now, int $grace_period ): bool {
		return array( $id ) === $this->select_due( $now, $grace_period, 1, $id );
	}

	/**
	 * Due lead IDs, optionally narrowed to one lead.
	 *
	 * One query serves both the sweep and the single-lead check: when
	 * $only_id is 0 the ID condition is always true.
	 *
	 * @param int $now          Current Unix time.
	 * @param int $grace_period Seconds a pending lead may wait for its first attempt.
	 * @param int $limit        Maximum rows.
	 * @param int $only_id      Lead ID to check, or 0 for any.
	 * @return int[]
	 */
	private function select_due( int $now, int $grace_period, int $limit, int $only_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i
				WHERE ( %d = 0 OR id = %d )
				AND (
					( status = %s AND next_attempt_at <= %s )
					OR ( status = %s AND locked_until < %s )
					OR ( status = %s AND created_at < %s )
				)
				ORDER BY id ASC
				LIMIT %d',
				Schema::leads_table(),
				$only_id,
				$only_id,
				LeadStatus::RETRY_SCHEDULED,
				gmdate( 'Y-m-d H:i:s', $now ),
				LeadStatus::DELIVERING,
				gmdate( 'Y-m-d H:i:s', $now ),
				LeadStatus::PENDING,
				gmdate( 'Y-m-d H:i:s', $now - $grace_period ),
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Take ownership of a lead for one attempt.
	 *
	 * The update only succeeds if the status, attempt count and lock are
	 * still what was read, so two runners can never send the same lead at
	 * once or work from a stale attempt count. A lead
	 * left in delivering with an expired lock belonged to a process that
	 * died mid-attempt, so it is claimable again.
	 *
	 * @param int      $id           Lead ID.
	 * @param string[] $statuses     Statuses this caller may claim from.
	 * @param int      $now          Current Unix time.
	 * @param int      $lock_seconds How long the claim holds.
	 * @return array|null The lead after the claim, with previous_status, or null when not claimed.
	 */
	public function claim( int $id, array $statuses, int $now, int $lock_seconds ): ?array {
		global $wpdb;

		$lead = $this->find( $id );

		if ( null === $lead ) {
			return null;
		}

		$status = $lead['status'];

		if ( LeadStatus::DELIVERING !== $status && ! in_array( $status, $statuses, true ) ) {
			return null;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, locked_until = %s, attempts = attempts + 1
				WHERE id = %d AND status = %s AND attempts = %d AND ( locked_until IS NULL OR locked_until < %s )',
				Schema::leads_table(),
				LeadStatus::DELIVERING,
				gmdate( 'Y-m-d H:i:s', $now + $lock_seconds ),
				$id,
				$status,
				(int) $lead['attempts'],
				gmdate( 'Y-m-d H:i:s', $now )
			)
		);

		if ( 1 !== $updated ) {
			return null;
		}

		$lead['previous_status'] = $status;
		$lead['status']          = LeadStatus::DELIVERING;
		$lead['attempts']        = (int) $lead['attempts'] + 1;

		return $lead;
	}

	/**
	 * Store the result of an attempt and release the claim held by this attempt.
	 *
	 * @param int      $id              Lead ID.
	 * @param string   $status          New status.
	 * @param int|null $next_attempt_at Unix time of the next automatic attempt.
	 * @param int|null $delivered_at    Unix time of delivery.
	 * @return void
	 */
	public function finish( int $id, string $status, ?int $next_attempt_at, ?int $delivered_at ): void {
		global $wpdb;

		$wpdb->update(
			Schema::leads_table(),
			array(
				'status'          => $status,
				'next_attempt_at' => null === $next_attempt_at ? null : gmdate( 'Y-m-d H:i:s', $next_attempt_at ),
				'delivered_at'    => null === $delivered_at ? null : gmdate( 'Y-m-d H:i:s', $delivered_at ),
				'locked_until'    => null,
			),
			array(
				'id'     => $id,
				'status' => LeadStatus::DELIVERING,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}
}
