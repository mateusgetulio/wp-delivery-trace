<?php
/**
 * Configuration read from wp-config.php constants.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace;

/**
 * CRM endpoint, token and demo mode. Secrets stay in wp-config.php, never in the database.
 */
final class Config {

	public const DEMO_HOST = 'crm.example.com';

	/**
	 * CRM webhook URL.
	 *
	 * @var string
	 */
	private string $crm_url;

	/**
	 * Bearer token for the CRM.
	 *
	 * @var string
	 */
	private string $crm_token;

	/**
	 * Whether the built-in fake CRM answers requests to the demo host.
	 *
	 * @var bool
	 */
	private bool $demo;

	/**
	 * Constructor.
	 *
	 * @param string $crm_url   CRM webhook URL.
	 * @param string $crm_token Bearer token for the CRM.
	 * @param bool   $demo      Whether demo mode is on.
	 */
	public function __construct( string $crm_url, string $crm_token, bool $demo ) {
		$this->crm_url   = $crm_url;
		$this->crm_token = $crm_token;
		$this->demo      = $demo;
	}

	/**
	 * Read DELIVERY_TRACE_CRM_URL, DELIVERY_TRACE_CRM_TOKEN and DELIVERY_TRACE_DEMO.
	 *
	 * @return self
	 */
	public static function from_constants(): self {
		return new self(
			defined( 'DELIVERY_TRACE_CRM_URL' ) ? (string) DELIVERY_TRACE_CRM_URL : '',
			defined( 'DELIVERY_TRACE_CRM_TOKEN' ) ? (string) DELIVERY_TRACE_CRM_TOKEN : '',
			defined( 'DELIVERY_TRACE_DEMO' ) && true === DELIVERY_TRACE_DEMO
		);
	}

	/**
	 * CRM webhook URL.
	 *
	 * @return string
	 */
	public function crm_url(): string {
		return $this->crm_url;
	}

	/**
	 * Bearer token for the CRM.
	 *
	 * @return string
	 */
	public function crm_token(): string {
		return $this->crm_token;
	}

	/**
	 * Whether both the URL and the token are set.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return '' !== $this->crm_url && '' !== $this->crm_token;
	}

	/**
	 * Whether demo mode is on.
	 *
	 * @return bool
	 */
	public function is_demo(): bool {
		return $this->demo;
	}

	/**
	 * Whether demo mode is on and the CRM URL points at the fake CRM, so demo leads never reach a real CRM.
	 *
	 * @return bool
	 */
	public function uses_demo_crm(): bool {
		return $this->demo && self::DEMO_HOST === wp_parse_url( $this->crm_url, PHP_URL_HOST );
	}
}
