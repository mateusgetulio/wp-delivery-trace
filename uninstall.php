<?php
/**
 * Removes the plugin's tables, options and scheduled retries.
 *
 * @package DeliveryTrace
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'delivery_trace_events' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'delivery_trace_leads' ) );
// phpcs:enable

delete_option( 'delivery_trace_db_version' );
delete_option( 'delivery_trace_fake_crm' );
delete_option( 'delivery_trace_spam_count' );

wp_unschedule_hook( 'delivery_trace_retry' );
wp_unschedule_hook( 'delivery_trace_sweep' );
