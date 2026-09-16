<?php
/**
 * Renders the tour form.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Form;

/**
 * The [delivery_trace_tour_form] shortcode.
 */
final class Shortcode {

	public const TAG = 'delivery_trace_tour_form';

	/**
	 * Register the shortcode and its assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register the form's script and style, enqueued only where the form renders.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		$base = plugins_url( 'assets/', DELIVERY_TRACE_FILE );

		wp_register_style( 'delivery-trace-form', $base . 'form.css', array(), DELIVERY_TRACE_VERSION );
		wp_register_script(
			'delivery-trace-form',
			$base . 'form.js',
			array(),
			DELIVERY_TRACE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Render the form, its field errors, or the confirmation.
	 *
	 * @return string
	 */
	public function render(): string {
		wp_enqueue_style( 'delivery-trace-form' );
		wp_enqueue_script( 'delivery-trace-form' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display state after the redirect.
		$state = isset( $_GET['delivery_trace'] ) ? sanitize_key( wp_unslash( $_GET['delivery_trace'] ) ) : '';
		$token = isset( $_GET['delivery_trace_token'] ) ? sanitize_key( wp_unslash( $_GET['delivery_trace_token'] ) ) : '';
		// phpcs:enable

		if ( 'received' === $state ) {
			return '<div id="delivery-trace-form" class="delivery-trace-notice" role="status">'
				. esc_html__( 'Thanks, we received your request.', 'delivery-trace' )
				. '</div>';
		}

		$values = array();
		$errors = array();

		if ( '' !== $token ) {
			$saved = get_transient( 'delivery_trace_form_' . $token );
			delete_transient( 'delivery_trace_form_' . $token );

			if ( is_array( $saved ) ) {
				$values = (array) ( $saved['values'] ?? array() );
				$errors = (array) ( $saved['errors'] ?? array() );
			}
		}

		$today = Submission::today();

		ob_start();
		?>
		<form id="delivery-trace-form" class="delivery-trace-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
			<?php if ( array() !== $errors ) : ?>
				<p class="delivery-trace-error-summary" role="alert" tabindex="-1"><?php esc_html_e( 'Please fix the highlighted fields.', 'delivery-trace' ); ?></p>
			<?php endif; ?>

			<input type="hidden" name="action" value="<?php echo esc_attr( SubmitHandler::ACTION ); ?>">
			<input type="hidden" name="delivery_trace[elapsed_ms]" value="">
			<?php wp_referer_field(); ?>
			<?php if ( array() !== $errors ) : ?>
				<input type="hidden" name="delivery_trace[corrected]" value="1">
			<?php endif; ?>

			<div class="delivery-trace-hp" aria-hidden="true">
				<label for="delivery-trace-website"><?php esc_html_e( 'Leave this field empty', 'delivery-trace' ); ?></label>
				<input type="text" id="delivery-trace-website" name="delivery_trace[website]" value="" tabindex="-1" autocomplete="off">
			</div>

			<?php
			$this->field( 'name', __( 'Name', 'delivery-trace' ), 'text', $values, $errors, array( 'autocomplete' => 'name' ) );
			$this->field( 'email', __( 'Email', 'delivery-trace' ), 'email', $values, $errors, array( 'autocomplete' => 'email' ) );
			$this->field( 'phone', __( 'Phone', 'delivery-trace' ), 'tel', $values, $errors, array( 'autocomplete' => 'tel' ) );
			$this->floor_plan_field( $values, $errors );
			$this->field(
				'preferred_date',
				__( 'Preferred date', 'delivery-trace' ),
				'date',
				$values,
				$errors,
				array(
					'min' => $today->format( 'Y-m-d' ),
					'max' => $today->modify( '+' . Submission::MAX_DAYS_AHEAD . ' days' )->format( 'Y-m-d' ),
				)
			);
			?>

			<button type="submit" class="wp-element-button"><?php esc_html_e( 'Schedule Tour', 'delivery-trace' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Print one input with its label and error.
	 *
	 * @param string $key        Field name.
	 * @param string $label      Visible label.
	 * @param string $type       Input type.
	 * @param array  $values     Sticky values.
	 * @param array  $errors     Errors keyed by field.
	 * @param array  $attributes Extra attributes.
	 * @return void
	 */
	private function field( string $key, string $label, string $type, array $values, array $errors, array $attributes = array() ): void {
		$id    = 'delivery-trace-' . str_replace( '_', '-', $key );
		$error = $errors[ $key ] ?? '';
		?>
		<p class="delivery-trace-field<?php echo '' !== $error ? ' has-error' : ''; ?>">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				id="<?php echo esc_attr( $id ); ?>"
				name="delivery_trace[<?php echo esc_attr( $key ); ?>]"
				value="<?php echo esc_attr( (string) ( $values[ $key ] ?? '' ) ); ?>"
				required
				<?php foreach ( $attributes as $attribute => $value ) : ?>
					<?php echo esc_attr( $attribute ); ?>="<?php echo esc_attr( $value ); ?>"
				<?php endforeach; ?>
				<?php if ( '' !== $error ) : ?>
					aria-invalid="true" aria-describedby="<?php echo esc_attr( $id ); ?>-error"
				<?php endif; ?>
			>
			<?php if ( '' !== $error ) : ?>
				<span class="delivery-trace-error" id="<?php echo esc_attr( $id ); ?>-error"><?php echo esc_html( $error ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Print the floor plan select.
	 *
	 * @param array $values Sticky values.
	 * @param array $errors Errors keyed by field.
	 * @return void
	 */
	private function floor_plan_field( array $values, array $errors ): void {
		$error    = $errors['floor_plan'] ?? '';
		$selected = (string) ( $values['floor_plan'] ?? '' );
		?>
		<p class="delivery-trace-field<?php echo '' !== $error ? ' has-error' : ''; ?>">
			<label for="delivery-trace-floor-plan"><?php esc_html_e( 'Desired floor plan', 'delivery-trace' ); ?></label>
			<select
				id="delivery-trace-floor-plan"
				name="delivery_trace[floor_plan]"
				required
				<?php if ( '' !== $error ) : ?>
					aria-invalid="true" aria-describedby="delivery-trace-floor-plan-error"
				<?php endif; ?>
			>
				<option value=""><?php esc_html_e( 'Choose a floor plan', 'delivery-trace' ); ?></option>
				<?php foreach ( FloorPlans::all() as $plan_key => $plan_label ) : ?>
					<option value="<?php echo esc_attr( $plan_key ); ?>" <?php selected( $selected, $plan_key ); ?>><?php echo esc_html( $plan_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $error ) : ?>
				<span class="delivery-trace-error" id="delivery-trace-floor-plan-error"><?php echo esc_html( $error ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}
}
