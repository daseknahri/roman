<?php
/**
 * Uninstall routine for Automation Hamri (WP Automator Pro).
 *
 * Runs ONLY when the plugin is deleted from the WordPress admin
 * (Plugins → Delete) — never on deactivate.
 *
 * IMPORTANT — DATA IS PRESERVED ON PURPOSE.
 * This install is maintained as a series of separate plugin folders (each
 * version installs alongside the previous one). Deleting an old version to
 * declutter must NOT wipe the shared Distribution Hub table or the settings,
 * or every version switch would erase the Hub listing and all ad codes.
 *
 * Therefore this uninstall keeps:
 *   • the wpap_generated_posts table (the Distribution Hub listing),
 *   • all settings/ad/automation/UTM/IndexNow options.
 * It clears only disposable license transients. The recurring automation cron is
 * intentionally left to the deactivation hook (see wpap_deactivate in the main
 * file) and deliberately NOT touched here (see the note below).
 *
 * Published posts and their scheduled publish events are — and always were —
 * left completely intact. Deleting the plugin never touches your content.
 *
 * If you ever want a TRUE clean wipe (drop the table + delete all options, INCLUDING the
 * stored API keys + license), set  define( 'WPAP_UNINSTALL_PURGE', true );  in wp-config.php
 * before deleting. It is safe on a SINGLE-COPY install (an in-place upgrade or a Docker-vendored
 * deploy), which has no parallel-folder constraint; it stays OFF by default so the multi-folder
 * model above is never harmed.
 */

/* Guard: only ever run inside WordPress's uninstall context. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/* NOTE: we deliberately do NOT clear the wpap_automation_cron hook here. Because
   this plugin is distributed as multiple parallel version folders, deleting an
   OLD folder would otherwise unschedule the cron that the ACTIVE version relies
   on. The deactivation hook already clears the cron for the version actually
   being turned off; a dangling hook after a true full-uninstall is a harmless
   no-op (it fires with no listener). */

/* Disposable license transients (safe to drop; they auto-regenerate if needed). */
delete_transient( 'wpap_license_cache' );
delete_transient( 'wpap_revoke_notice' );

/* OPT-IN full clean wipe (default OFF). The DROP TABLE + delete_option() calls stay omitted on a
   normal delete so removing one installed version never erases the shared Hub or settings that a
   parallel ACTIVE version still uses. But a single-copy install has no such constraint and may want
   a true clean removal — critically, the stored API keys + license, which otherwise linger in
   wp_options after the plugin is gone (a later DB dump / migration / site sale then exposes them).
   Enable with  define( 'WPAP_UNINSTALL_PURGE', true );  in wp-config.php. Published posts and their
   scheduled events are ALWAYS left intact. Option list mirrors the passive build's uninstall. */
if ( defined( 'WPAP_UNINSTALL_PURGE' ) && WPAP_UNINSTALL_PURGE ) {
    global $wpdb;
    $wpap_table = $wpdb->prefix . 'wpap_generated_posts';   /* WPAP_TABLE literal */
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpap_table}`" );

    foreach ( array(
        'wpap_settings',                 /* API keys / config      */
        'wpap_license_data',             /* license user + key     */
        'wpap_license_last_check',
        'wpap_db_version',               /* so a reinstall recreates the just-dropped table */
        'wpap_ads_txt',
        'wpap_ads_inject',
        'wpap_content_opts',
        'wpap_utm',
        'wpap_indexnow',
        'wpap_indexnow_key',
        'wpap_indexnow_last',
        'wpap_automation',
        'wpap_automation_seen',
        'wpap_automation_count',
        'wpap_automation_status',
        'wpap_automation_fails',
        'wpap_automation_deleted_keys',
        'wpap_automation_giveup_keys',
        'wpap_automation_lock',
        'wpap_automation_alert_sent',
    ) as $wpap_opt ) {
        delete_option( $wpap_opt );
    }
    wp_clear_scheduled_hook( 'wpap_automation_cron' );
}
