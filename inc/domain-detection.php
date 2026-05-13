<?php
/**
 * Rilevamento automatico del dominio attivo (DCI / DSI / entrambi).
 *
 * Il rilevamento avviene UNA SOLA VOLTA: all'attivazione del plugin.
 * Conta le meta key distinte con prefisso '_dci_' e '_dsi_' in postmeta
 * e salva il risultato in una WP option permanente.
 *
 * Da quel momento in poi tutte le letture vengono dall'option — zero query.
 *
 * Risultati possibili:
 *   'dci'  → solo Comuni  → espone /comuni/v1/*
 *   'dsi'  → solo Scuole  → espone /scuole/v1/* + route condivise
 *   'both' → entrambi     → espone tutte le route
 *   'none' → nessun dato  → nessuna route registrata, avviso in admin
 *
 * Per resettare il rilevamento (es. dopo migrazione a un nuovo tema)
 * è sufficiente disattivare e riattivare il plugin.
 *
 * @package Design_Italia_Semantic
 */

defined( 'ABSPATH' ) || exit;

define( 'DESIITSE_DOMAIN_OPTION', 'desiitse_active_domain' );

// ════════════════════════════════════════════════════════════════════════════
// RILEVAMENTO (chiamato solo all'attivazione)
// ════════════════════════════════════════════════════════════════════════════

/**
 * Conta le meta key distinte con un dato prefisso in postmeta.
 *
 * @param string $prefix  Es. '_dci_' oppure '_dsi_'
 * @return int
 */
function desiitse_count_meta_prefix( string $prefix ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eseguita solo all'attivazione del plugin, una tantum, caching non applicabile.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT meta_key)
			 FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
}

/**
 * Esegue il rilevamento e salva il risultato.
 * Chiamato solo da register_activation_hook().
 *
 * @return string  'dci' | 'dsi' | 'both' | 'none'
 */
function desiitse_detect_and_save_domain(): string {
	$dci_count = desiitse_count_meta_prefix( '_dci_' );
	$dsi_count = desiitse_count_meta_prefix( '_dsi_' );

	if ( $dci_count > 0 && $dsi_count > 0 ) {
		$domain = 'both';
	} elseif ( $dci_count > 0 ) {
		$domain = 'dci';
	} elseif ( $dsi_count > 0 ) {
		$domain = 'dsi';
	} else {
		$domain = 'none';
	}

	update_option( DESIITSE_DOMAIN_OPTION, [
		'domain'   => $domain,
		'dci_keys' => $dci_count,
		'dsi_keys' => $dsi_count,
		'detected' => current_time( 'mysql' ),
	], false );

	return $domain;
}

// ════════════════════════════════════════════════════════════════════════════
// LETTURA (usata ad ogni request, legge solo dall'option — zero query)
// ════════════════════════════════════════════════════════════════════════════

/**
 * Restituisce il dominio attivo salvato all'attivazione.
 *
 * @return string  'dci' | 'dsi' | 'both' | 'none'
 */
function desiitse_active_domain(): string {
	$data = get_option( DESIITSE_DOMAIN_OPTION, [] );
	return ( is_array( $data ) && ! empty( $data['domain'] ) ) ? $data['domain'] : 'none';
}

/**
 * Controlla se un dato dominio è attivo.
 *
 * @param 'dci'|'dsi' $domain
 * @return bool
 */
function desiitse_domain_active( string $domain ): bool {
	$active = desiitse_active_domain();
	return $active === $domain || $active === 'both';
}

/**
 * Determina se una route REST deve essere registrata.
 *
 * @param 'dci'|'dsi'|'any' $required
 *   'dci' → solo se dominio DCI attivo
 *   'dsi' → solo se dominio DSI attivo
 *   'any' → se almeno un dominio è attivo (route condivise: luoghi, servizi, eventi)
 * @return bool
 */
function desiitse_should_register_route( string $required ): bool {
	$active = desiitse_active_domain();

	if ( $active === 'none' ) {
		return false;
	}

	if ( $required === 'any' ) {
		return true;
	}

	return $active === $required || $active === 'both';
}

// ════════════════════════════════════════════════════════════════════════════
// HOOK DI ATTIVAZIONE / DISATTIVAZIONE
// ════════════════════════════════════════════════════════════════════════════

register_activation_hook(
	DESIITSE_PLUGIN_FILE,
	'desiitse_detect_and_save_domain'
);

register_deactivation_hook(
	DESIITSE_PLUGIN_FILE,
	function () {
		// Rimuove il risultato: alla prossima attivazione il check riparte da zero.
		// Utile dopo un cambio tema (da Comuni a Scuole o viceversa).
		delete_option( DESIITSE_DOMAIN_OPTION );
	}
);
