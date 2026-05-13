<?php
/**
 * Rebuild asincrono dei grafi JSON-LD via WP-Cron.
 *
 * Flusso:
 *   1. Un post viene salvato → desiitse_invalidate_for_post() svuota la cache
 *      e chiama desiitse_mark_dirty() per gli slug interessati.
 *   2. desiitse_mark_dirty() aggiunge lo slug a una WP option "dirty list"
 *      e schedula un singolo evento cron (se non già in coda).
 *   3. WP-Cron esegue desiitse_rebuild_dirty_graphs(): legge la dirty list,
 *      ricostruisce solo gli slug marcati, salva su option + transient.
 *   4. La dirty list viene svuotata.
 *
 * Se vengono salvati 10 post in pochi secondi: un solo cron,
 * un solo rebuild. Il redattore non aspetta mai.
 *
 * @package Design_Comuni_Semantic
 */

defined( 'ABSPATH' ) || exit;

/** Ritardo cron in secondi dopo una modifica (default 60s). */
define( 'DESIITSE_CRON_DELAY', (int) apply_filters( 'desiitse_cron_delay', 60 ) );

/** Option key per la dirty list. */
define( 'DESIITSE_DIRTY_OPTION', 'desiitse_dirty_slugs' );

/** Nome dell'hook cron. */
define( 'DESIITSE_CRON_HOOK', 'desiitse_rebuild_dirty' );

// ════════════════════════════════════════════════════════════════════════════
// MAPPA SLUG → BUILDER
// ════════════════════════════════════════════════════════════════════════════

/**
 * Associa ogni slug al callable che costruisce i suoi nodi.
 *
 * @return array<string, callable>
 */
function desiitse_slug_builder_map(): array {
	return [
		// DCI
		'comuni-graph'        => 'desiitse_build_dci_nodes',
		'persona-pubblica'    => 'desiitse_build_persona_nodes',
		'unita-organizzative' => 'desiitse_build_unita_nodes',
		'dataset'             => 'desiitse_build_dataset_nodes',
		'luoghi-dci'          => fn() => desiitse_build_luogo_nodes( 'dci' ),
		'servizi-dci'         => fn() => desiitse_build_servizio_nodes( 'dci' ),
		'eventi-dci'          => fn() => desiitse_build_evento_nodes( 'dci' ),
		// DSI
		'scuole-graph'        => 'desiitse_build_dsi_nodes',
		'documenti'           => 'desiitse_build_documento_nodes',
		'strutture'           => 'desiitse_build_struttura_nodes',
		'luoghi-dsi'          => fn() => desiitse_build_luogo_nodes( 'dsi' ),
		'servizi-dsi'         => fn() => desiitse_build_servizio_nodes( 'dsi' ),
		'eventi-dsi'          => fn() => desiitse_build_evento_nodes( 'dsi' ),
	];
}

// ════════════════════════════════════════════════════════════════════════════
// DIRTY FLAG
// ════════════════════════════════════════════════════════════════════════════

/**
 * Aggiunge uno slug alla dirty list e schedula il cron se necessario.
 */
function desiitse_mark_dirty( string $slug ): void {
	$dirty            = (array) get_option( DESIITSE_DIRTY_OPTION, [] );
	$dirty[ $slug ]   = true;
	update_option( DESIITSE_DIRTY_OPTION, $dirty, false );

	if ( ! wp_next_scheduled( DESIITSE_CRON_HOOK ) ) {
		wp_schedule_single_event( time() + DESIITSE_CRON_DELAY, DESIITSE_CRON_HOOK );
	}
}

/**
 * Marca tutti gli slug come dirty e schedula un rebuild completo.
 * Usato all'attivazione del plugin.
 */
function desiitse_schedule_rebuild_all(): void {
	$all = array_fill_keys( desiitse_active_slugs(), true ); // solo slug del dominio attivo
	update_option( DESIITSE_DIRTY_OPTION, $all, false );

	if ( ! wp_next_scheduled( DESIITSE_CRON_HOOK ) ) {
		wp_schedule_single_event( time() + 10, DESIITSE_CRON_HOOK ); // 10s: primo avvio rapido
	}
}

// ════════════════════════════════════════════════════════════════════════════
// ESECUZIONE CRON
// ════════════════════════════════════════════════════════════════════════════

/**
 * Ricostruisce tutti gli slug nella dirty list.
 * Chiamato da WP-Cron, mai durante una richiesta REST o una page view.
 */
function desiitse_rebuild_dirty_graphs(): void {
	$dirty = (array) get_option( DESIITSE_DIRTY_OPTION, [] );
	if ( empty( $dirty ) ) {
		return;
	}

	// Svuota subito la dirty list: eventuali modifiche durante il rebuild
	// creeranno una nuova entry e scheduleranno un nuovo cron.
	update_option( DESIITSE_DIRTY_OPTION, [], false );

	$map = desiitse_slug_builder_map();

	foreach ( array_keys( $dirty ) as $slug ) {
		if ( isset( $map[ $slug ] ) && is_callable( $map[ $slug ] ) ) {
			desiitse_build_and_persist( $slug, $map[ $slug ] );
		}
	}
}
add_action( DESIITSE_CRON_HOOK, 'desiitse_rebuild_dirty_graphs' );

// ════════════════════════════════════════════════════════════════════════════
// PULIZIA ALLA DISATTIVAZIONE
// ════════════════════════════════════════════════════════════════════════════

register_deactivation_hook(
	DESIITSE_DIR . 'design-comuni-semantic.php',
	function () {
		$timestamp = wp_next_scheduled( DESIITSE_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, DESIITSE_CRON_HOOK );
		}
		delete_option( DESIITSE_DIRTY_OPTION );
	}
);
