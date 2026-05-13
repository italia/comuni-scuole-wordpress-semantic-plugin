<?php
/**
 * Lookup codice IPA dal dominio del sito.
 *
 * Al primo caricamento legge data/ipa-index.json (chiave = dominio, valore = codice IPA),
 * trova il codice corrispondente a home_url() e lo salva nella WP option
 * 'desiitse_ipa_code'. Nelle richieste successive legge solo dall'option — zero I/O su disco.
 *
 * L'amministratore può sovrascrivere il codice dalla pagina wp-admin del plugin.
 * In quel caso l'option contiene il valore manuale e questo file non modifica più nulla.
 *
 * @package Design_Italia_Semantic
 */

defined( 'ABSPATH' ) || exit;

/** Chiave WP option dove viene salvato il codice IPA. */
define( 'DESIITSE_IPA_OPTION', 'desiitse_ipa_code' );

// ── Lookup dal JSON ────────────────────────────────────────────────────────────

/**
 * Carica l'indice IPA dal file JSON.
 * Usa static per leggere il file una sola volta per request.
 *
 * @return array<string,string>  [ 'dominio' => 'codice_ipa', ... ]
 */
function desiitse_ipa_index(): array {
	static $index = null;

	if ( $index === null ) {
		$path = DESIITSE_DIR . 'data/ipa-index.json';

		if ( ! file_exists( $path ) ) {
			$index = [];
			return $index;
		}

		$raw   = file_get_contents( $path );
		$index = $raw !== false ? (array) json_decode( $raw, true ) : [];
	}

	return $index;
}

/**
 * Cerca il codice IPA per un dato hostname.
 * Prova più varianti (con/senza www, con/senza slash finale).
 *
 * @param  string $host  Es. "comune.acquaformosa.cs.it"
 * @return string|null
 */
function desiitse_lookup_ipa_for_host( string $host ): ?string {
	$index = desiitse_ipa_index();

	if ( empty( $index ) ) {
		return null;
	}

	// Normalizza: minuscolo, senza www.
	$host = strtolower( $host );
	$bare = preg_replace( '/^www\./', '', $host );

	$candidates = array_unique( array_filter( [
		$bare,
		$host,
		$bare . '/',
		$host . '/',
	] ) );

	foreach ( $candidates as $candidate ) {
		if ( isset( $index[ $candidate ] ) ) {
			return (string) $index[ $candidate ];
		}
	}

	return null;
}

// ── API pubblica ───────────────────────────────────────────────────────────────

/**
 * Restituisce il codice IPA del sito corrente.
 *
 * Priorità:
 *   1. WP option 'desiitse_ipa_code' (può essere impostata manualmente dall'admin)
 *   2. Lookup automatico dal JSON tramite home_url()
 *   3. null se non trovato
 *
 * @return string|null
 */
function desiitse_get_ipa_code(): ?string {
	// L'option viene creata al momento dell'attivazione (vedi sotto).
	// '' = cercato ma non trovato; stringa = codice valido.
	$saved = get_option( DESIITSE_IPA_OPTION, null );

	// Se l'option non esiste ancora (es. primo caricamento senza attivazione formale)
	// eseguiamo il lookup al volo e salviamo.
	if ( $saved === null ) {
		return desiitse_detect_and_save_ipa();
	}

	return $saved !== '' ? $saved : null;
}

/**
 * Esegue il lookup dal JSON e salva il risultato nell'option.
 * Chiamato all'attivazione del plugin e come fallback.
 *
 * @return string|null  Codice IPA trovato, oppure null.
 */
function desiitse_detect_and_save_ipa(): ?string {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$code = $host !== '' ? desiitse_lookup_ipa_for_host( $host ) : null;

	// Salva '' se non trovato: evita il lookup ad ogni request.
	update_option( DESIITSE_IPA_OPTION, $code ?? '', false );

	return $code;
}

/**
 * Salva manualmente un codice IPA (usato dal form admin).
 * Stringa vuota = nessun codice (il nodo userà l'URL come @id).
 *
 * @param string $code
 */
function desiitse_save_ipa_code( string $code ): void {
	update_option( DESIITSE_IPA_OPTION, sanitize_text_field( trim( $code ) ), false );
}

/**
 * Rimuove l'option (usato alla disattivazione del plugin).
 */
function desiitse_clear_ipa_code(): void {
	delete_option( DESIITSE_IPA_OPTION );
}

// ── Hook attivazione / disattivazione ─────────────────────────────────────────

register_activation_hook(
	DESIITSE_PLUGIN_FILE,
	function () {
		// Esegue il lookup automatico al momento dell'attivazione.
		// Se l'option esiste già (es. riattivazione) non la sovrascrive.
		if ( get_option( DESIITSE_IPA_OPTION, null ) === null ) {
			desiitse_detect_and_save_ipa();
		}
	}
);

register_deactivation_hook(
	DESIITSE_PLUGIN_FILE,
	'desiitse_clear_ipa_code'
);
