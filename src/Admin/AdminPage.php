<?php
/**
 * The Delivery Trace admin screens.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Admin;

use DeliveryTrace\Config;
use DeliveryTrace\Demo\ScriptStore;
use DeliveryTrace\Form\FloorPlans;
use DeliveryTrace\Privacy\Masker;
use DeliveryTrace\Storage\EventRepository;
use DeliveryTrace\Storage\LeadRepository;
use DeliveryTrace\Storage\LeadStatus;

/**
 * Lead list, lead trace and the demo panel.
 */
final class AdminPage {

	public const SLUG = 'delivery-trace';

	public const CAPABILITY = 'manage_options';

	public const LIST_LIMIT = 50;

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
	 * Masks personal data.
	 *
	 * @var Masker
	 */
	private Masker $masker;

	/**
	 * Labels and sentences.
	 *
	 * @var Presenter
	 */
	private Presenter $presenter;

	/**
	 * CRM configuration and demo mode.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Fake CRM state, for the demo panel.
	 *
	 * @var ScriptStore
	 */
	private ScriptStore $store;

	/**
	 * Hook suffix of the admin page.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param LeadRepository  $leads     Lead storage.
	 * @param EventRepository $events    Trace storage.
	 * @param Masker          $masker    Masks personal data.
	 * @param Presenter       $presenter Labels and sentences.
	 * @param Config          $config    CRM configuration and demo mode.
	 * @param ScriptStore     $store     Fake CRM state.
	 */
	public function __construct( LeadRepository $leads, EventRepository $events, Masker $masker, Presenter $presenter, Config $config, ScriptStore $store ) {
		$this->leads     = $leads;
		$this->events    = $events;
		$this->masker    = $masker;
		$this->presenter = $presenter;
		$this->config    = $config;
		$this->store     = $store;
	}

	/**
	 * Register the menu and assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	/**
	 * Drop notice arguments from the address bar after the notice shows.
	 *
	 * @param string[] $args Removable query arguments.
	 * @return string[]
	 */
	public function removable_query_args( $args ): array {
		return array_merge( (array) $args, array( 'delivery_trace_notice', 'delivery_trace_count' ) );
	}

	/**
	 * Add the top-level menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Delivery Trace', 'delivery-trace' ),
			__( 'Delivery Trace', 'delivery-trace' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-randomize',
			58
		);
	}

	/**
	 * Load the admin stylesheet on this page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( $hook_suffix === $this->hook_suffix ) {
			wp_enqueue_style( 'delivery-trace-admin', plugins_url( 'assets/admin.css', DELIVERY_TRACE_FILE ), array(), DELIVERY_TRACE_VERSION );
		}
	}

	/**
	 * URL of the list, or of one lead's trace.
	 *
	 * @param int $lead_id Lead ID, or 0 for the list.
	 * @return string
	 */
	public static function url( int $lead_id = 0 ): string {
		$args = array( 'page' => self::SLUG );

		if ( $lead_id > 0 ) {
			$args['lead'] = $lead_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Render the list or a lead's trace.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'delivery-trace' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$lead_id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		$lead    = $lead_id > 0 ? $this->leads->find( $lead_id ) : null;

		echo '<div class="wrap delivery-trace-admin">';
		$this->render_notices();

		if ( $lead_id > 0 && null === $lead ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Lead not found.', 'delivery-trace' ) );
		}

		if ( null !== $lead ) {
			$this->render_trace( $lead );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Configuration problems and results of the last action.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		if ( ! $this->config->is_complete() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Define DELIVERY_TRACE_CRM_URL and DELIVERY_TRACE_CRM_TOKEN in wp-config.php. Until then every attempt is recorded as not configured.', 'delivery-trace' )
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice after a redirect.
		$notice = isset( $_GET['delivery_trace_notice'] ) ? sanitize_key( wp_unslash( $_GET['delivery_trace_notice'] ) ) : '';
		$count  = isset( $_GET['delivery_trace_count'] ) ? absint( $_GET['delivery_trace_count'] ) : 0;
		// phpcs:enable

		$messages = array(
			'not_claimable' => array( 'warning', __( 'That lead is already delivered or another attempt is running.', 'delivery-trace' ) ),
			/* translators: %d: number of leads. */
			'ran_due'       => array( 'success', sprintf( _n( 'Attempted %d due lead.', 'Attempted %d due leads.', $count, 'delivery-trace' ), $count ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);
		}
	}

	/**
	 * The lead list with the demo panel.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$leads = $this->leads->latest( self::LIST_LIMIT );
		$plans = FloorPlans::all();
		?>
		<h1><?php esc_html_e( 'Delivery Trace', 'delivery-trace' ); ?></h1>
		<hr class="wp-header-end">

		<?php if ( $this->config->uses_demo_crm() ) : ?>
			<div class="delivery-trace-demo">
				<h2><?php esc_html_e( 'Demo', 'delivery-trace' ); ?></h2>
				<p>
					<?php esc_html_e( 'Each button submits a sample lead and tells the demo CRM how to answer. The delivery code is the same one real leads use.', 'delivery-trace' ); ?>
					<strong>
						<?php
						/* translators: %d: number of leads. */
						echo esc_html( sprintf( _n( 'The demo CRM holds %d lead.', 'The demo CRM holds %d leads.', $this->store->accepted_count(), 'delivery-trace' ), $this->store->accepted_count() ) );
						?>
					</strong>
				</p>
				<div class="delivery-trace-demo-buttons">
					<?php
					foreach ( Actions::demo_scenarios() as $scenario => $definition ) {
						$this->action_button( Actions::DEMO, $definition['label'], array( 'scenario' => $scenario ), 'secondary' );
					}
					?>
				</div>
			</div>
		<?php endif; ?>

		<div class="delivery-trace-toolbar">
			<?php $this->action_button( Actions::RUN_DUE, __( 'Run due retries now', 'delivery-trace' ) ); ?>
		</div>

		<table class="wp-list-table widefat striped delivery-trace-leads">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Lead', 'delivery-trace' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Received', 'delivery-trace' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'delivery-trace' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Floor plan', 'delivery-trace' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'delivery-trace' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Attempts', 'delivery-trace' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Next attempt', 'delivery-trace' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $leads ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No leads yet.', 'delivery-trace' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $leads as $lead ) : ?>
					<?php $payload = $this->payload( $lead ); ?>
					<tr>
						<th scope="row"><a href="<?php echo esc_url( self::url( (int) $lead['id'] ) ); ?>">#<?php echo esc_html( $lead['id'] ); ?></a></th>
						<td><?php echo esc_html( $this->local_time( (string) $lead['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
						<td><?php echo esc_html( $this->masker->name( $payload['name'] ) ); ?></td>
						<td><?php echo esc_html( $plans[ $payload['floor_plan'] ] ?? '' ); ?></td>
						<td><?php $this->status_badge( (string) $lead['status'] ); ?></td>
						<td><?php echo esc_html( $lead['attempts'] ); ?></td>
						<td><?php echo esc_html( LeadStatus::RETRY_SCHEDULED === $lead['status'] ? $this->local_time( (string) $lead['next_attempt_at'], 'H:i:s' ) : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One lead's trace.
	 *
	 * @param array $lead Lead row.
	 * @return void
	 */
	private function render_trace( array $lead ): void {
		$lead_id = (int) $lead['id'];
		$payload = $this->payload( $lead );
		$events  = $this->events->for_lead( $lead_id );
		$plans   = FloorPlans::all();
		$flags   = array_filter( explode( ',', (string) $lead['flags'] ) );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All leads', 'delivery-trace' ); ?></a></p>

		<h1>
			<?php
			/* translators: %d: lead ID. */
			echo esc_html( sprintf( __( 'Lead #%d', 'delivery-trace' ), $lead_id ) );
			?>
			<?php $this->status_badge( (string) $lead['status'] ); ?>
			<?php foreach ( $flags as $flag ) : ?>
				<span class="delivery-trace-badge is-flag"><?php echo esc_html( 'demo' === $flag ? __( 'Demo', 'delivery-trace' ) : $flag ); ?></span>
			<?php endforeach; ?>
		</h1>
		<hr class="wp-header-end">

		<p class="delivery-trace-meta">
			<?php
			echo esc_html(
				implode(
					' · ',
					array_filter(
						array(
							$plans[ $payload['floor_plan'] ] ?? '',
							$this->masker->name( $payload['name'] ),
							$this->masker->email( $payload['email'] ),
							$this->masker->phone( $payload['phone'] ),
						)
					)
				)
			);
			?>
		</p>
		<p class="delivery-trace-meta"><?php esc_html_e( 'Delivery ID', 'delivery-trace' ); ?> <code title="<?php echo esc_attr( $lead['uuid'] ); ?>"><?php echo esc_html( $this->presenter->short_id( (string) $lead['uuid'] ) ); ?></code></p>

		<table class="widefat delivery-trace-timeline">
			<thead>
				<tr>
					<th scope="col" class="column-glyph"><span class="screen-reader-text"><?php esc_html_e( 'Result', 'delivery-trace' ); ?></span></th>
					<th scope="col" class="column-step"><?php esc_html_e( 'Step', 'delivery-trace' ); ?></th>
					<th scope="col" class="column-time"><?php esc_html_e( 'Time', 'delivery-trace' ); ?></th>
					<th scope="col" class="column-duration"><?php esc_html_e( 'Duration', 'delivery-trace' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Outcome', 'delivery-trace' ); ?></th>
					<th scope="col" class="column-delivery-id"><?php esc_html_e( 'Delivery ID', 'delivery-trace' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $this->presenter->rows( $events, (string) $lead['uuid'] ) as $row ) : ?>
					<tr class="is-<?php echo esc_attr( $row['tone'] ); ?>">
						<td class="column-glyph"><span aria-hidden="true"><?php echo esc_html( $row['glyph'] ); ?></span><span class="screen-reader-text"><?php echo esc_html( $row['tone_label'] ); ?></span></td>
						<td class="column-step"><?php echo esc_html( $row['label'] ); ?></td>
						<td class="column-time"><?php echo esc_html( $row['time'] ); ?></td>
						<td class="column-duration"><?php echo esc_html( $row['duration'] ); ?></td>
						<td>
							<?php echo esc_html( $row['result'] ); ?>
							<?php if ( '' !== $row['detail'] ) : ?>
								<code class="delivery-trace-detail"><?php echo esc_html( $row['detail'] ); ?></code>
							<?php endif; ?>
						</td>
						<td class="column-delivery-id">
							<?php if ( '' !== $row['delivery_id'] ) : ?>
								<code><?php echo esc_html( $row['delivery_id'] ); ?></code>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="delivery-trace-summary"><?php echo esc_html( $this->presenter->summary( $lead, $events ) ); ?></p>

		<?php if ( $this->config->uses_demo_crm() && LeadStatus::DELIVERED === $lead['status'] && $this->store->has_accepted( (string) $lead['uuid'] ) ) : ?>
			<p class="delivery-trace-summary"><?php esc_html_e( 'Demo CRM view: exactly one copy of this lead is stored.', 'delivery-trace' ); ?></p>
		<?php endif; ?>

		<?php if ( $this->can_retry( $lead ) ) : ?>
			<?php $this->action_button( Actions::RETRY_NOW, __( 'Retry now', 'delivery-trace' ), array( 'lead' => $lead_id ) ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * A one-button form posting to admin-post.php with a nonce.
	 *
	 * @param string $action     Admin-post action.
	 * @param string $label      Button label.
	 * @param array  $fields     Hidden fields.
	 * @param string $style      Button style: primary or secondary.
	 * @return void
	 */
	private function action_button( string $action, string $label, array $fields = array(), string $style = 'primary' ): void {
		$nonce_action = Actions::nonce_action( $action, $fields );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="delivery-trace-action">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( $nonce_action ); ?>
			<?php submit_button( $label, $style, '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Whether Retry now applies: not delivered, and not held by a running attempt.
	 *
	 * @param array $lead Lead row.
	 * @return bool
	 */
	private function can_retry( array $lead ): bool {
		if ( in_array( $lead['status'], LeadStatus::MANUAL_CLAIMABLE, true ) ) {
			return true;
		}

		return LeadStatus::DELIVERING === $lead['status']
			&& null !== $lead['locked_until']
			&& (int) strtotime( $lead['locked_until'] . ' UTC' ) < time();
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Lead status.
	 * @return void
	 */
	private function status_badge( string $status ): void {
		printf(
			'<span class="delivery-trace-badge is-%1$s">%2$s</span>',
			esc_attr( str_replace( '_', '-', $status ) ),
			esc_html( $this->presenter->status_label( $status ) )
		);
	}

	/**
	 * Stored payload with every expected key present.
	 *
	 * @param array $lead Lead row.
	 * @return array<string, string>
	 */
	private function payload( array $lead ): array {
		$payload = json_decode( (string) $lead['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();

		return array_map(
			'strval',
			wp_parse_args(
				$payload,
				array(
					'name'       => '',
					'email'      => '',
					'phone'      => '',
					'floor_plan' => '',
				)
			)
		);
	}

	/**
	 * A stored UTC datetime shown in the site timezone.
	 *
	 * @param string $utc    UTC datetime from the database.
	 * @param string $format Date format.
	 * @return string
	 */
	private function local_time( string $utc, string $format ): string {
		$timestamp = strtotime( $utc . ' UTC' );

		return false === $timestamp || '' === $utc ? '' : wp_date( $format, $timestamp );
	}
}
