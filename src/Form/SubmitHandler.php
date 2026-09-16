<?php
/**
 * Handles tour form submissions.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Form;

use DeliveryTrace\Delivery\Deliverer;
use DeliveryTrace\Storage\EventRepository;
use DeliveryTrace\Storage\LeadRepository;
use DeliveryTrace\Storage\Step;

/**
 * Receives the form, stores the lead before any network call, then makes the first attempt.
 */
final class SubmitHandler {

	public const ACTION = 'delivery_trace_submit';

	public const MIN_FILL_MS = 3000;

	public const SPAM_COUNT_OPTION = 'delivery_trace_spam_count';

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
	 * Register the admin-post handlers for visitors and logged-in users.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handle one submission and redirect back to the form.
	 *
	 * @return void
	 */
	public function handle(): void {
		$received_ms = EventRepository::now_ms();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' !== $method ) {
			$this->redirect( array() );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public form served from full-page cache; no authenticated action. See README.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in Submission::sanitize().
		$input = isset( $_POST['delivery_trace'] ) && is_array( $_POST['delivery_trace'] ) ? wp_unslash( $_POST['delivery_trace'] ) : array();
		// phpcs:enable

		if ( $this->looks_like_spam( $input ) ) {
			update_option( self::SPAM_COUNT_OPTION, (int) get_option( self::SPAM_COUNT_OPTION, 0 ) + 1, false );
			$this->redirect( array( 'delivery_trace' => 'received' ) );
		}

		$values       = Submission::sanitize( $input );
		$sanitized_ms = EventRepository::now_ms();
		$errors       = Submission::validate( $values, Submission::today() );
		$validated_ms = EventRepository::now_ms();

		if ( array() !== $errors ) {
			$token = strtolower( wp_generate_password( 24, false ) );
			set_transient( 'delivery_trace_form_' . $token, compact( 'values', 'errors' ), 10 * MINUTE_IN_SECONDS );
			$this->redirect( array( 'delivery_trace_token' => $token ) );
		}

		$flags   = isset( $input['elapsed_ms'] ) && '' !== $input['elapsed_ms'] ? array() : array( 'no_js' );
		$lead_id = $this->leads->create( wp_generate_uuid4(), $values, $flags, time() );

		if ( 0 === $lead_id ) {
			wp_die( esc_html__( 'Sorry, your request could not be saved. Please try again.', 'delivery-trace' ), '', array( 'response' => 500 ) );
		}

		$stored_ms = EventRepository::now_ms();

		$this->events->add( $lead_id, Step::RECEIVED, '', null, null, '', $received_ms );
		$this->events->add( $lead_id, Step::SANITIZED, '', null, $sanitized_ms - $received_ms, '', $sanitized_ms );
		$this->events->add( $lead_id, Step::VALIDATED, '', null, $validated_ms - $sanitized_ms, '', $validated_ms );
		$this->events->add( $lead_id, Step::STORED, '', null, $stored_ms - $validated_ms, '', $stored_ms );

		$this->deliverer->attempt( $lead_id );

		$this->redirect( array( 'delivery_trace' => 'received' ) );
	}

	/**
	 * Honeypot filled, or submitted faster than a person can type.
	 *
	 * A missing elapsed time means JavaScript did not run, which is allowed
	 * and only flagged on the lead. The time check is skipped when the form
	 * was re-rendered with errors, because the fields are already filled.
	 *
	 * @param array $input Raw input.
	 * @return bool
	 */
	private function looks_like_spam( array $input ): bool {
		$honeypot = $input['website'] ?? '';

		if ( ! is_scalar( $honeypot ) || '' !== trim( (string) $honeypot ) ) {
			return true;
		}

		if ( ! empty( $input['corrected'] ) ) {
			return false;
		}

		$elapsed = $input['elapsed_ms'] ?? '';

		return is_scalar( $elapsed ) && ctype_digit( (string) $elapsed ) && (int) $elapsed < self::MIN_FILL_MS;
	}

	/**
	 * Redirect back to the page the form was on, then stop.
	 *
	 * @param array<string, string> $args Query arguments to add.
	 * @return void
	 */
	private function redirect( array $args ): void {
		$referer = wp_get_referer();
		$target  = remove_query_arg( array( 'delivery_trace', 'delivery_trace_token' ), false === $referer ? home_url( '/' ) : $referer );

		wp_safe_redirect( add_query_arg( $args, $target ) . '#delivery-trace-form', 303 );
		exit;
	}
}
