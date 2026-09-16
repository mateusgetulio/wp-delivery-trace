<?php
/**
 * Sends one lead to the CRM and records what happened.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Delivery;

use DeliveryTrace\Config;
use DeliveryTrace\Privacy\Scrubber;
use DeliveryTrace\Storage\EventRepository;
use DeliveryTrace\Storage\LeadRepository;
use DeliveryTrace\Storage\LeadStatus;
use DeliveryTrace\Storage\Step;

/**
 * One delivery attempt: claim, send, classify, record, decide.
 */
final class Deliverer {

	public const TIMEOUT_SECONDS = 3;

	public const LOCK_SECONDS = 60;

	public const RETRY_HOOK = 'delivery_trace_retry';

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
	 * Turns responses into outcomes.
	 *
	 * @var OutcomeClassifier
	 */
	private OutcomeClassifier $classifier;

	/**
	 * Decides what happens after each attempt.
	 *
	 * @var RetryPolicy
	 */
	private RetryPolicy $policy;

	/**
	 * Removes personal data from stored details.
	 *
	 * @var Scrubber
	 */
	private Scrubber $scrubber;

	/**
	 * CRM endpoint and token.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Constructor.
	 *
	 * @param LeadRepository    $leads      Lead storage.
	 * @param EventRepository   $events     Trace storage.
	 * @param OutcomeClassifier $classifier Turns responses into outcomes.
	 * @param RetryPolicy       $policy     Decides what happens after each attempt.
	 * @param Scrubber          $scrubber   Removes personal data from stored details.
	 * @param Config            $config     CRM endpoint and token.
	 */
	public function __construct( LeadRepository $leads, EventRepository $events, OutcomeClassifier $classifier, RetryPolicy $policy, Scrubber $scrubber, Config $config ) {
		$this->leads      = $leads;
		$this->events     = $events;
		$this->classifier = $classifier;
		$this->policy     = $policy;
		$this->scrubber   = $scrubber;
		$this->config     = $config;
	}

	/**
	 * Attempt delivery of one lead.
	 *
	 * @param int  $lead_id Lead ID.
	 * @param bool $manual  Whether a person asked for this attempt.
	 * @return string|null The lead's new status, or null when it could not be claimed.
	 */
	public function attempt( int $lead_id, bool $manual = false ): ?string {
		$statuses = $manual ? LeadStatus::MANUAL_CLAIMABLE : LeadStatus::AUTOMATIC_CLAIMABLE;
		$lead     = $this->leads->claim( $lead_id, $statuses, time(), self::LOCK_SECONDS );

		if ( null === $lead ) {
			return null;
		}

		if ( LeadStatus::DELIVERING === $lead['previous_status'] ) {
			$this->events->add( $lead_id, Step::ATTEMPT_INTERRUPTED, Outcome::UNKNOWN );

			if ( ! $manual && (int) $lead['attempts'] > $this->policy->max_automatic_attempts() ) {
				$this->leads->finish( $lead_id, LeadStatus::NEEDS_ATTENTION, null, null );
				$this->events->add( $lead_id, Step::NEEDS_ATTENTION );

				return LeadStatus::NEEDS_ATTENTION;
			}
		}

		if ( $manual ) {
			$this->events->add( $lead_id, Step::MANUAL_RETRY );
		}

		$payload     = json_decode( (string) $lead['payload'], true );
		$payload     = is_array( $payload ) ? $payload : array();
		$started     = microtime( true );
		$is_demo     = in_array( 'demo', explode( ',', (string) $lead['flags'] ), true );
		$result      = $this->send( $lead['uuid'], $payload, $is_demo );
		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		list( $classification, $detail ) = $result;

		$this->events->add(
			$lead_id,
			Step::CRM_ATTEMPT,
			$classification->outcome(),
			$classification->http_status(),
			$duration_ms,
			$this->scrubber->scrub( $detail, (string) ( $payload['name'] ?? '' ), (string) ( $payload['email'] ?? '' ), (string) ( $payload['phone'] ?? '' ) )
		);

		return $this->apply( $lead_id, $classification, (int) $lead['attempts'] );
	}

	/**
	 * Send the request and classify the result.
	 *
	 * @param string $uuid    Idempotency key.
	 * @param array  $payload Stored form values.
	 * @param bool   $is_demo Whether the lead came from a demo button.
	 * @return array{0: Classification, 1: string} The classification and the raw detail worth keeping, before scrubbing.
	 */
	private function send( string $uuid, array $payload, bool $is_demo ): array {
		if ( ! $this->config->is_complete() ) {
			return array( new Classification( Outcome::MISCONFIGURED ), 'DELIVERY_TRACE_CRM_URL or DELIVERY_TRACE_CRM_TOKEN is not defined.' );
		}

		if ( $is_demo && ! $this->config->uses_demo_crm() ) {
			return array( new Classification( Outcome::MISCONFIGURED ), 'Demo lead not sent: the CRM URL no longer points at the demo CRM.' );
		}

		$response = wp_safe_remote_post(
			$this->config->crm_url(),
			array(
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'    => 'application/json',
					'Idempotency-Key' => $uuid,
					'Authorization'   => 'Bearer ' . $this->config->crm_token(),
				),
				'body'        => wp_json_encode( array( 'idempotency_key' => $uuid ) + $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();

			return array( $this->classifier->from_error( $message ), $message );
		}

		$retry_after    = wp_remote_retrieve_header( $response, 'retry-after' );
		$classification = $this->classifier->from_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			is_array( $retry_after ) ? (string) reset( $retry_after ) : $retry_after,
			time()
		);

		$detail = Outcome::is_permanent_failure( $classification->outcome() ) ? (string) wp_remote_retrieve_body( $response ) : '';

		return array( $classification, $detail );
	}

	/**
	 * Store the decision for the attempt and schedule the next one.
	 *
	 * @param int            $lead_id        Lead ID.
	 * @param Classification $classification Result of the attempt.
	 * @param int            $attempts       Attempts made, including this one.
	 * @return string New status.
	 */
	private function apply( int $lead_id, Classification $classification, int $attempts ): string {
		$now      = time();
		$decision = $this->policy->decide( $classification, $attempts, $now );

		if ( Decision::DONE === $decision->action() ) {
			$this->leads->finish( $lead_id, LeadStatus::DELIVERED, null, $now );
			wp_clear_scheduled_hook( self::RETRY_HOOK, array( $lead_id ) );

			return LeadStatus::DELIVERED;
		}

		if ( Decision::RETRY === $decision->action() ) {
			$retry_at = (int) $decision->retry_at();

			$this->leads->finish( $lead_id, LeadStatus::RETRY_SCHEDULED, $retry_at, null );
			$this->events->add( $lead_id, Step::RETRY_SCHEDULED, '', null, null, gmdate( 'c', $retry_at ) );

			// WP-Cron refuses an identical event within ten minutes, so an older retry for this lead must go first.
			wp_clear_scheduled_hook( self::RETRY_HOOK, array( $lead_id ) );
			wp_schedule_single_event( $retry_at, self::RETRY_HOOK, array( $lead_id ) );

			return LeadStatus::RETRY_SCHEDULED;
		}

		$this->leads->finish( $lead_id, LeadStatus::NEEDS_ATTENTION, null, null );
		$this->events->add( $lead_id, Step::NEEDS_ATTENTION );
		wp_clear_scheduled_hook( self::RETRY_HOOK, array( $lead_id ) );

		return LeadStatus::NEEDS_ATTENTION;
	}
}
