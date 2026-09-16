<?php
/**
 * State of the fake CRM.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Demo;

/**
 * The queue of scripted outcomes and the idempotency keys the fake CRM has accepted.
 *
 * Read-modify-write on one option is not atomic; that is acceptable for a
 * single person clicking demo buttons.
 */
final class ScriptStore {

	public const OPTION = 'delivery_trace_fake_crm';

	public const OK = 'ok';

	public const HTTP_500 = 'http_500';

	public const TIMEOUT_AFTER_ACCEPT = 'timeout_after_accept';

	public const MAX_ACCEPTED = 200;

	/**
	 * Replace the queue of outcomes for the next requests.
	 *
	 * @param string[] $outcomes Outcomes, consumed one per request.
	 * @return void
	 */
	public function set_script( array $outcomes ): void {
		$state           = $this->state();
		$state['script'] = array_values( $outcomes );
		$this->save( $state );
	}

	/**
	 * Take the next scripted outcome, or ok when the queue is empty.
	 *
	 * @return string
	 */
	public function next_outcome(): string {
		$state   = $this->state();
		$outcome = array_shift( $state['script'] );
		$this->save( $state );

		return is_string( $outcome ) ? $outcome : self::OK;
	}

	/**
	 * Whether this key was already accepted.
	 *
	 * @param string $key Idempotency key.
	 * @return bool
	 */
	public function has_accepted( string $key ): bool {
		return in_array( $key, $this->state()['accepted'], true );
	}

	/**
	 * Remember an accepted key, keeping only the most recent ones.
	 *
	 * @param string $key Idempotency key.
	 * @return void
	 */
	public function accept( string $key ): void {
		$state               = $this->state();
		$state['accepted'][] = $key;
		$state['accepted']   = array_slice( $state['accepted'], -self::MAX_ACCEPTED );
		$this->save( $state );
	}

	/**
	 * How many distinct leads the fake CRM holds.
	 *
	 * @return int
	 */
	public function accepted_count(): int {
		return count( $this->state()['accepted'] );
	}

	/**
	 * Stored state with defaults.
	 *
	 * @return array{script: string[], accepted: string[]}
	 */
	private function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'script'   => array_values( (array) ( $state['script'] ?? array() ) ),
			'accepted' => array_values( (array) ( $state['accepted'] ?? array() ) ),
		);
	}

	/**
	 * Save state without autoloading it.
	 *
	 * @param array $state State to save.
	 * @return void
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
