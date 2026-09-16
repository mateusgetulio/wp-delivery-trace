<?php
/**
 * What to do with a lead after an attempt.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Delivery;

/**
 * The retry policy's answer: done, retry at a time, or ask a human.
 */
final class Decision {

	public const DONE            = 'done';
	public const RETRY           = 'retry';
	public const NEEDS_ATTENTION = 'needs_attention';

	/**
	 * One of the action constants.
	 *
	 * @var string
	 */
	private string $action;

	/**
	 * Unix time of the next attempt, only for retries.
	 *
	 * @var int|null
	 */
	private ?int $retry_at;

	/**
	 * Constructor.
	 *
	 * @param string   $action   One of the action constants.
	 * @param int|null $retry_at Unix time of the next attempt, only for retries.
	 */
	private function __construct( string $action, ?int $retry_at = null ) {
		$this->action   = $action;
		$this->retry_at = $retry_at;
	}

	/**
	 * The lead reached the CRM.
	 *
	 * @return self
	 */
	public static function done(): self {
		return new self( self::DONE );
	}

	/**
	 * Try again at the given time.
	 *
	 * @param int $retry_at Unix time of the next attempt.
	 * @return self
	 */
	public static function retry( int $retry_at ): self {
		return new self( self::RETRY, $retry_at );
	}

	/**
	 * Stop retrying automatically.
	 *
	 * @return self
	 */
	public static function needs_attention(): self {
		return new self( self::NEEDS_ATTENTION );
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}

	/**
	 * Unix time of the next attempt, only for retries.
	 *
	 * @return int|null
	 */
	public function retry_at(): ?int {
		return $this->retry_at;
	}
}
