<?php
/**
 * Decides when a failed delivery is tried again.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Delivery;

use InvalidArgumentException;

/**
 * Retry schedule with a hard cap on attempts and on server-requested delays.
 */
final class RetryPolicy {

	public const MAX_RETRIES = 3;

	/**
	 * Seconds to wait before each retry, in order.
	 *
	 * @var int[]
	 */
	private array $delays;

	/**
	 * Longest Retry-After the policy will honour, in seconds.
	 *
	 * @var int
	 */
	private int $max_retry_after;

	/**
	 * Constructor.
	 *
	 * @param array $delays          Seconds to wait before each retry, in order. At most MAX_RETRIES entries are used.
	 * @param int   $max_retry_after Longest Retry-After honoured, in seconds.
	 * @throws InvalidArgumentException When the schedule is empty or has a delay that is not a non-negative integer.
	 */
	public function __construct( array $delays = array( 60, 300, 900 ), int $max_retry_after = 900 ) {
		$delays = array_slice( array_values( $delays ), 0, self::MAX_RETRIES );

		if ( array() === $delays ) {
			throw new InvalidArgumentException( 'The retry schedule needs at least one delay.' );
		}

		foreach ( $delays as $delay ) {
			if ( ! is_int( $delay ) || $delay < 0 ) {
				throw new InvalidArgumentException( 'Retry delays must be non-negative integers.' );
			}
		}

		$this->delays          = $delays;
		$this->max_retry_after = $max_retry_after;
	}

	/**
	 * Decide what happens after an attempt.
	 *
	 * @param Classification $classification Result of the attempt.
	 * @param int            $attempts_made  Every attempt recorded for the lead so far, including this one, whether automatic, manual or interrupted.
	 * @param int            $now            Current Unix time.
	 * @return Decision
	 * @throws InvalidArgumentException When no attempt has been made.
	 */
	public function decide( Classification $classification, int $attempts_made, int $now ): Decision {
		if ( $attempts_made < 1 ) {
			throw new InvalidArgumentException( 'A decision needs at least one attempt.' );
		}

		$outcome = $classification->outcome();

		if ( Outcome::is_delivered( $outcome ) ) {
			return Decision::done();
		}

		if ( Outcome::is_permanent_failure( $outcome ) || $attempts_made >= $this->max_automatic_attempts() ) {
			return Decision::needs_attention();
		}

		$delay       = $this->delays[ $attempts_made - 1 ];
		$retry_after = $classification->retry_after();

		if ( null !== $retry_after ) {
			$delay = max( $delay, min( $retry_after, $this->max_retry_after ) );
		}

		return Decision::retry( $now + $delay );
	}

	/**
	 * Most attempts a lead gets before a human is needed.
	 *
	 * @return int
	 */
	public function max_automatic_attempts(): int {
		return count( $this->delays ) + 1;
	}
}
