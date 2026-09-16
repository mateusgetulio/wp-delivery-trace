<?php
/**
 * Classified result of a delivery attempt.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Delivery;

use InvalidArgumentException;

/**
 * What happened on one attempt, reduced to what the retry policy needs.
 */
final class Classification {

	/**
	 * Outcome name, one of the Outcome constants.
	 *
	 * @var string
	 */
	private string $outcome;

	/**
	 * HTTP status, or null when no response arrived.
	 *
	 * @var int|null
	 */
	private ?int $http_status;

	/**
	 * Seconds the server asked us to wait, or null.
	 *
	 * @var int|null
	 */
	private ?int $retry_after;

	/**
	 * Constructor.
	 *
	 * @param string   $outcome     Outcome name.
	 * @param int|null $http_status HTTP status, or null when no response arrived.
	 * @param int|null $retry_after Seconds the server asked us to wait, or null.
	 * @throws InvalidArgumentException When the outcome is not one of the Outcome constants.
	 */
	public function __construct( string $outcome, ?int $http_status = null, ?int $retry_after = null ) {
		if ( ! in_array( $outcome, Outcome::ALL, true ) ) {
			throw new InvalidArgumentException( 'Unknown delivery outcome.' );
		}

		$this->outcome     = $outcome;
		$this->http_status = $http_status;
		$this->retry_after = $retry_after;
	}

	/**
	 * Outcome name.
	 *
	 * @return string
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * HTTP status, or null when no response arrived.
	 *
	 * @return int|null
	 */
	public function http_status(): ?int {
		return $this->http_status;
	}

	/**
	 * Seconds the server asked us to wait, or null.
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}
}
