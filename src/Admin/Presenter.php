<?php
/**
 * Turns stored leads and events into what the admin screens show.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Admin;

use DeliveryTrace\Delivery\Outcome;
use DeliveryTrace\Storage\LeadStatus;
use DeliveryTrace\Storage\Step;

/**
 * Labels, glyphs and sentences for the trace. Returns plain text; callers escape.
 */
final class Presenter {

	/**
	 * Human label for a lead status.
	 *
	 * @param string $status Lead status.
	 * @return string
	 */
	public function status_label( string $status ): string {
		$labels = array(
			LeadStatus::PENDING         => __( 'Pending', 'delivery-trace' ),
			LeadStatus::DELIVERING      => __( 'Delivering', 'delivery-trace' ),
			LeadStatus::DELIVERED       => __( 'Delivered', 'delivery-trace' ),
			LeadStatus::RETRY_SCHEDULED => __( 'Retry scheduled', 'delivery-trace' ),
			LeadStatus::NEEDS_ATTENTION => __( 'Needs attention', 'delivery-trace' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Delivery ID shortened to its first eight and last four characters.
	 *
	 * @param string $uuid Idempotency key.
	 * @return string
	 */
	public function short_id( string $uuid ): string {
		return substr( $uuid, 0, 8 ) . '…' . substr( $uuid, -4 );
	}

	/**
	 * Time of day with milliseconds, in the site timezone.
	 *
	 * @param int $unix_ms Unix time in milliseconds.
	 * @return string
	 */
	public function time_ms( int $unix_ms ): string {
		return wp_date( 'H:i:s', intdiv( $unix_ms, 1000 ) ) . '.' . sprintf( '%03d', $unix_ms % 1000 );
	}

	/**
	 * One display row per event.
	 *
	 * @param array[] $events Events, oldest first.
	 * @param string  $uuid   The lead's delivery ID.
	 * @return array[] Rows with glyph, tone, label, time, duration, result, delivery_id and detail.
	 */
	public function rows( array $events, string $uuid ): array {
		$rows     = array();
		$attempts = 0;

		foreach ( $events as $event ) {
			$step           = (string) $event['step'];
			$outcome        = (string) $event['outcome'];
			$is_try         = Step::CRM_ATTEMPT === $step;
			$is_interrupted = Step::ATTEMPT_INTERRUPTED === $step;

			if ( $is_try || $is_interrupted ) {
				++$attempts;
			}

			if ( $is_try ) {
				/* translators: %d: attempt number. */
				$label = sprintf( __( 'CRM attempt %d', 'delivery-trace' ), $attempts );
			} elseif ( $is_interrupted ) {
				/* translators: %d: attempt number. */
				$label = sprintf( __( 'CRM attempt %d interrupted', 'delivery-trace' ), $attempts );
			} else {
				$label = $this->step_label( $step );
			}

			$rows[] = array(
				'glyph'       => $this->glyph( $step, $outcome ),
				'tone'        => $this->tone( $step, $outcome ),
				'tone_label'  => $this->tone_label( $step, $outcome ),
				'label'       => $label,
				'time'        => $this->time_ms( (int) $event['created_at_ms'] ),
				/* translators: %s: duration in milliseconds. */
				'duration'    => null === $event['duration_ms'] ? '' : sprintf( __( '%s ms', 'delivery-trace' ), number_format_i18n( (int) $event['duration_ms'] ) ),
				'result'      => $this->result( $step, $outcome, null === $event['http_status'] ? null : (int) $event['http_status'], (string) $event['detail'] ),
				'delivery_id' => $is_try || $is_interrupted ? $this->short_id( $uuid ) : '',
				'detail'      => $is_try ? (string) $event['detail'] : '',
			);
		}

		return $rows;
	}

	/**
	 * One sentence about where the lead stands.
	 *
	 * @param array   $lead   Lead row.
	 * @param array[] $events Events, oldest first.
	 * @return string
	 */
	public function summary( array $lead, array $events ): string {
		switch ( $lead['status'] ) {
			case LeadStatus::DELIVERED:
				$received  = $this->first_ms( $events, Step::RECEIVED );
				$delivered = $this->last_delivered_ms( $events );

				if ( null === $received || null === $delivered ) {
					return __( 'Delivered.', 'delivery-trace' );
				}

				/* translators: %s: elapsed time, for example "12 ms" or "61.3 s". */
				return sprintf( __( 'Delivered %s after it was received.', 'delivery-trace' ), $this->elapsed( $delivered - $received ) );

			case LeadStatus::RETRY_SCHEDULED:
				$next = (int) strtotime( (string) $lead['next_attempt_at'] . ' UTC' );

				if ( $next <= time() ) {
					/* translators: %s: time of day. */
					return sprintf( __( 'Automatic attempt was due at %s and will run on the next cron run. Retry now runs it immediately.', 'delivery-trace' ), wp_date( 'H:i:s', $next ) );
				}

				/* translators: 1: time of day, 2: human time difference. */
				return sprintf( __( 'Next automatic attempt at %1$s (in %2$s). Normally cron runs it; Retry now runs it immediately.', 'delivery-trace' ), wp_date( 'H:i:s', $next ), human_time_diff( time(), $next ) );

			case LeadStatus::NEEDS_ATTENTION:
				return __( 'Automatic retries stopped. Fix the cause, then use Retry now.', 'delivery-trace' );

			default:
				return __( 'Delivery in progress.', 'delivery-trace' );
		}
	}

	/**
	 * Elapsed time in the unit that reads best.
	 *
	 * @param int $milliseconds Elapsed milliseconds.
	 * @return string
	 */
	private function elapsed( int $milliseconds ): string {
		if ( $milliseconds < 1000 ) {
			/* translators: %s: milliseconds. */
			return sprintf( __( '%s ms', 'delivery-trace' ), number_format_i18n( $milliseconds ) );
		}

		if ( $milliseconds < 60000 ) {
			/* translators: %s: seconds with one decimal. */
			return sprintf( __( '%s s', 'delivery-trace' ), number_format_i18n( $milliseconds / 1000, 1 ) );
		}

		/* translators: %s: minutes with one decimal. */
		return sprintf( __( '%s min', 'delivery-trace' ), number_format_i18n( $milliseconds / 60000, 1 ) );
	}

	/**
	 * Words for the row's glyph, for screen readers.
	 *
	 * @param string $step    Step name.
	 * @param string $outcome Outcome name.
	 * @return string
	 */
	private function tone_label( string $step, string $outcome ): string {
		$labels = array(
			'ok'      => __( 'Succeeded', 'delivery-trace' ),
			'fail'    => __( 'Failed', 'delivery-trace' ),
			'unknown' => __( 'Unknown', 'delivery-trace' ),
			'wait'    => __( 'Waiting', 'delivery-trace' ),
			'info'    => __( 'Action', 'delivery-trace' ),
		);

		return $labels[ $this->tone( $step, $outcome ) ];
	}

	/**
	 * Label for a non-attempt step.
	 *
	 * @param string $step Step name.
	 * @return string
	 */
	private function step_label( string $step ): string {
		$labels = array(
			Step::RECEIVED            => __( 'Received', 'delivery-trace' ),
			Step::SANITIZED           => __( 'Sanitized', 'delivery-trace' ),
			Step::VALIDATED           => __( 'Validated', 'delivery-trace' ),
			Step::STORED              => __( 'Stored', 'delivery-trace' ),
			Step::MANUAL_RETRY        => __( 'Retry now', 'delivery-trace' ),
			Step::ATTEMPT_INTERRUPTED => __( 'Attempt interrupted', 'delivery-trace' ),
			Step::RETRY_SCHEDULED     => __( 'Retry scheduled', 'delivery-trace' ),
			Step::NEEDS_ATTENTION     => __( 'Needs attention', 'delivery-trace' ),
		);

		return $labels[ $step ] ?? $step;
	}

	/**
	 * What happened, in words.
	 *
	 * @param string   $step        Step name.
	 * @param string   $outcome     Outcome name.
	 * @param int|null $http_status HTTP status.
	 * @param string   $detail      Stored detail.
	 * @return string
	 */
	private function result( string $step, string $outcome, ?int $http_status, string $detail ): string {
		$status = null === $http_status ? '' : trim( $http_status . ' ' . get_status_header_desc( $http_status ) );

		switch ( $step ) {
			case Step::CRM_ATTEMPT:
				return $this->attempt_result( $outcome, $status );

			case Step::RETRY_SCHEDULED:
				/* translators: %s: time of day. */
				return sprintf( __( 'Automatic retry at %s', 'delivery-trace' ), wp_date( 'H:i:s', (int) strtotime( $detail ) ) );

			case Step::NEEDS_ATTENTION:
				return __( 'No more automatic retries', 'delivery-trace' );

			case Step::MANUAL_RETRY:
				return __( 'Requested by an admin', 'delivery-trace' );

			case Step::ATTEMPT_INTERRUPTED:
				return __( 'Stopped midway, outcome unknown; the CRM may have received it', 'delivery-trace' );

			default:
				return '';
		}
	}

	/**
	 * What a CRM attempt returned, in words.
	 *
	 * @param string $outcome Outcome name.
	 * @param string $status  HTTP status with its description, or empty.
	 * @return string
	 */
	private function attempt_result( string $outcome, string $status ): string {
		switch ( $outcome ) {
			case Outcome::DELIVERED:
				return $status;
			case Outcome::DELIVERED_DUPLICATE:
				return __( 'Already received (duplicate)', 'delivery-trace' );
			case Outcome::RETRYABLE:
				return $status;
			case Outcome::UNKNOWN:
				return __( 'Timed out, the CRM may have received it', 'delivery-trace' );
			case Outcome::UNREACHABLE:
				return __( 'Could not reach the CRM', 'delivery-trace' );
			case Outcome::AUTH_FAILED:
				/* translators: %s: HTTP status. */
				return sprintf( __( '%s, check the token', 'delivery-trace' ), $status );
			case Outcome::REJECTED:
				/* translators: %s: HTTP status. */
				return sprintf( __( '%s, payload rejected', 'delivery-trace' ), $status );
			case Outcome::MISCONFIGURED:
				return __( 'CRM URL or token is not configured', 'delivery-trace' );
			default:
				return $outcome;
		}
	}

	/**
	 * Glyph for the row.
	 *
	 * @param string $step    Step name.
	 * @param string $outcome Outcome name.
	 * @return string
	 */
	private function glyph( string $step, string $outcome ): string {
		$glyphs = array(
			'ok'      => '✓',
			'fail'    => '✕',
			'unknown' => '?',
			'wait'    => '↻',
			'info'    => '→',
		);

		return $glyphs[ $this->tone( $step, $outcome ) ];
	}

	/**
	 * Visual tone for the row.
	 *
	 * @param string $step    Step name.
	 * @param string $outcome Outcome name.
	 * @return string One of ok, fail, unknown, wait, info.
	 */
	private function tone( string $step, string $outcome ): string {
		switch ( $step ) {
			case Step::CRM_ATTEMPT:
				if ( Outcome::is_delivered( $outcome ) ) {
					return 'ok';
				}

				return Outcome::UNKNOWN === $outcome ? 'unknown' : 'fail';

			case Step::ATTEMPT_INTERRUPTED:
				return 'unknown';

			case Step::RETRY_SCHEDULED:
				return 'wait';

			case Step::NEEDS_ATTENTION:
				return 'fail';

			case Step::MANUAL_RETRY:
				return 'info';

			default:
				return 'ok';
		}
	}

	/**
	 * Time of the first event with the given step.
	 *
	 * @param array[] $events Events.
	 * @param string  $step   Step name.
	 * @return int|null
	 */
	private function first_ms( array $events, string $step ): ?int {
		foreach ( $events as $event ) {
			if ( $step === $event['step'] ) {
				return (int) $event['created_at_ms'];
			}
		}

		return null;
	}

	/**
	 * Time of the last successful CRM attempt.
	 *
	 * @param array[] $events Events.
	 * @return int|null
	 */
	private function last_delivered_ms( array $events ): ?int {
		$found = null;

		foreach ( $events as $event ) {
			if ( Step::CRM_ATTEMPT === $event['step'] && Outcome::is_delivered( (string) $event['outcome'] ) ) {
				$found = (int) $event['created_at_ms'];
			}
		}

		return $found;
	}
}
