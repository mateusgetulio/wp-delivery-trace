<?php
/**
 * Turns form input into a stored lead and its first delivery attempt.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Form;

use DeliveryTrace\Delivery\Deliverer;
use DeliveryTrace\Storage\EventRepository;
use DeliveryTrace\Storage\LeadRepository;
use DeliveryTrace\Storage\Step;
use RuntimeException;

/**
 * Sanitize, validate, store with its trace, then make the first attempt.
 *
 * Shared by the public form and the demo buttons so both produce the same trace.
 */
final class Intake {

	/**
	 * Lead storage.
	 *
	 * @var LeadRepository
	 */
	private LeadRepository $leads;

	/**
	 * Trace storage.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Makes the first delivery attempt.
	 *
	 * @var Deliverer
	 */
	private Deliverer $deliverer;

	/**
	 * Constructor.
	 *
	 * @param LeadRepository  $leads     Lead storage.
	 * @param EventRepository $events    Trace storage.
	 * @param Deliverer       $deliverer Makes the first delivery attempt.
	 */
	public function __construct( LeadRepository $leads, EventRepository $events, Deliverer $deliverer ) {
		$this->leads     = $leads;
		$this->events    = $events;
		$this->deliverer = $deliverer;
	}

	/**
	 * Accept raw input.
	 *
	 * @param array    $input       Raw, unslashed form input.
	 * @param string[] $flags       Flags to store on the lead.
	 * @param int      $received_ms When the request arrived, in Unix milliseconds.
	 * @return array{lead_id: int, values: array<string, string>, errors: array<string, string>}
	 * @throws RuntimeException When the lead could not be stored.
	 */
	public function accept( array $input, array $flags, int $received_ms ): array {
		$values       = Submission::sanitize( $input );
		$sanitized_ms = EventRepository::now_ms();
		$errors       = Submission::validate( $values, Submission::today() );
		$validated_ms = EventRepository::now_ms();

		if ( array() !== $errors ) {
			return array(
				'lead_id' => 0,
				'values'  => $values,
				'errors'  => $errors,
			);
		}

		$lead_id = $this->leads->create( wp_generate_uuid4(), $values, $flags, time() );

		if ( 0 === $lead_id ) {
			throw new RuntimeException( 'The lead could not be stored.' );
		}

		$stored_ms = EventRepository::now_ms();

		$this->events->add( $lead_id, Step::RECEIVED, '', null, null, '', $received_ms );
		$this->events->add( $lead_id, Step::SANITIZED, '', null, $sanitized_ms - $received_ms, '', $sanitized_ms );
		$this->events->add( $lead_id, Step::VALIDATED, '', null, $validated_ms - $sanitized_ms, '', $validated_ms );
		$this->events->add( $lead_id, Step::STORED, '', null, $stored_ms - $validated_ms, '', $stored_ms );

		$this->deliverer->attempt( $lead_id );

		return array(
			'lead_id' => $lead_id,
			'values'  => $values,
			'errors'  => array(),
		);
	}
}
