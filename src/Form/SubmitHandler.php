<?php
/**
 * Handles tour form submissions.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Form;

use DeliveryTrace\Storage\EventRepository;
use RuntimeException;

/**
 * The HTTP side of the public form: method check, spam checks and redirects.
 */
final class SubmitHandler {

	public const ACTION = 'delivery_trace_submit';

	public const MIN_FILL_MS = 3000;

	public const SPAM_COUNT_OPTION = 'delivery_trace_spam_count';

	/**
	 * Stores the lead and makes the first attempt.
	 *
	 * @var Intake
	 */
	private Intake $intake;

	/**
	 * Constructor.
	 *
	 * @param Intake $intake Stores the lead and makes the first attempt.
	 */
	public function __construct( Intake $intake ) {
		$this->intake = $intake;
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

		$flags = isset( $input['elapsed_ms'] ) && '' !== $input['elapsed_ms'] ? array() : array( 'no_js' );

		try {
			$result = $this->intake->accept( $input, $flags, $received_ms );
		} catch ( RuntimeException $exception ) {
			wp_die( esc_html__( 'Sorry, your request could not be saved. Please try again.', 'delivery-trace' ), '', array( 'response' => 500 ) );
		}

		if ( array() !== $result['errors'] ) {
			$token = strtolower( wp_generate_password( 24, false ) );
			set_transient(
				'delivery_trace_form_' . $token,
				array(
					'values' => $result['values'],
					'errors' => $result['errors'],
				),
				10 * MINUTE_IN_SECONDS
			);
			$this->redirect( array( 'delivery_trace_token' => $token ) );
		}

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
