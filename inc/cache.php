<?php
/**
 * Cache precomputata a due livelli: transient + WP option.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │  SERVE  (ogni richiesta REST)                                    │
 * │  1. Transient WP  – velocissimo, scade in DESIITSE_CACHE_TTL sec.    │
 * │  2. WP option     – persistente, nessuna scadenza, autoload=no  │
 * │  3. Query live    – solo al primissimo avvio o dopo reset        │
 * ├──────────────────────────────────────────────────────────────────┤
 * │  BUILD  (mai durante la richiesta REST)                          │
 * │  WP-Cron: schedulato quando un post viene modificato.           │
 * │  Scrive su entrambi i livelli. Il redattore non aspetta mai.     │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * Nessun accesso al filesystem. Nessun permesso richiesto.
 * Compatibile con qualsiasi hosting WordPress e con le linee guida wp.org.
 *
 * @package Design_Comuni_Semantic
 */

defined( 'ABSPATH' ) || exit;

// ════════════════════════════════════════════════════════════════════════════
// CONFIGURAZIONE SLUGS
// ════════════════════════════════════════════════════════════════════════════

/**
 * Tutti gli slug gestiti dal plugin.
 *
 * @return string[]
 */
/**
 * Tutti gli slug esistenti — usato solo per invalidazione globale
 * (attivazione/disattivazione plugin).
 */
function desiitse_all_slugs(): array {
	return [
		'comuni-graph', 'scuole-graph',
		'persona-pubblica', 'unita-organizzative', 'dataset',
		'luoghi-dci', 'servizi-dci', 'eventi-dci',
		'documenti', 'strutture',
		'luoghi-dsi', 'servizi-dsi', 'eventi-dsi',
	];
}

/**
 * Slug attivi per il dominio corrente.
 * Usato per il pannello admin e per il cron: evita di mostrare
 * o cercare slug di cache che non verranno mai scritti su questo sito.
 *
 * @return string[]
 */
function desiitse_active_slugs(): array {
	$domain = desiitse_active_domain();

	$dci = [
		'comuni-graph',
		'persona-pubblica', 'unita-organizzative', 'dataset',
		'luoghi-dci', 'servizi-dci', 'eventi-dci',
	];
	$dsi = [
		'scuole-graph',
		'documenti', 'strutture',
		'luoghi-dsi', 'servizi-dsi', 'eventi-dsi',
	];

	if ( $domain === 'dci' )  { return $dci; }
	if ( $domain === 'dsi' )  { return $dsi; }
	if ( $domain === 'both' ) { return array_merge( $dci, $dsi ); }
	return []; // 'none': niente da mostrare
}

/**
 * Mappa CPT WordPress → slug/i da invalidare quando quel CPT cambia.
 *
 * @return array<string, string[]>
 */
function desiitse_cpt_slug_map(): array {
	$domain = desiitse_active_domain();

	// Mappa base: ogni CPT invalida i propri slug + il grafo aggregato.
	// Contiene solo gli slug del dominio attivo: su un sito DCI non ha senso
	// tentare di invalidare 'luoghi-dsi' (non verrà mai scritto).
	switch ( $domain ) {
		case 'dci':
			return [
				'persona_pubblica'    => [ 'persona-pubblica', 'comuni-graph' ],
				'luogo'               => [ 'luoghi-dci',        'comuni-graph' ],
				'servizio'            => [ 'servizi-dci',        'comuni-graph' ],
				'unita_organizzativa' => [ 'unita-organizzative','comuni-graph' ],
				'evento'              => [ 'eventi-dci',         'comuni-graph' ],
				'dataset'             => [ 'dataset',            'comuni-graph' ],
			];

		case 'dsi':
			return [
				'luogo'     => [ 'luoghi-dsi',   'scuole-graph' ],
				'servizio'  => [ 'servizi-dsi',  'scuole-graph' ],
				'evento'    => [ 'eventi-dsi',   'scuole-graph' ],
				'documento' => [ 'documenti',    'scuole-graph' ],
				'struttura' => [ 'strutture',    'scuole-graph' ],
				'circolare' => [ 'scuole-graph' ],
			];

		case 'both':
			return [
				'persona_pubblica'    => [ 'persona-pubblica', 'comuni-graph' ],
				'luogo'               => [ 'luoghi-dci', 'luoghi-dsi', 'comuni-graph', 'scuole-graph' ],
				'servizio'            => [ 'servizi-dci', 'servizi-dsi', 'comuni-graph', 'scuole-graph' ],
				'unita_organizzativa' => [ 'unita-organizzative', 'comuni-graph' ],
				'evento'              => [ 'eventi-dci', 'eventi-dsi', 'comuni-graph', 'scuole-graph' ],
				'dataset'             => [ 'dataset', 'comuni-graph' ],
				'documento'           => [ 'documenti', 'scuole-graph' ],
				'struttura'           => [ 'strutture', 'scuole-graph' ],
				'circolare'           => [ 'scuole-graph' ],
			];

		default: // 'none'
			return [];
	}
}

// ════════════════════════════════════════════════════════════════════════════
// LIVELLO 1 — TRANSIENT
// Lettura velocissima (memoria object cache se disponibile, altrimenti DB).
// Scade automaticamente dopo DESIITSE_CACHE_TTL secondi.
// Viene riscaldato ogni volta che il livello 2 (option) risponde.
// ════════════════════════════════════════════════════════════════════════════

function desiitse_transient_key( string $slug ): string {
	return 'desiitse_graph_' . sanitize_key( $slug );
}

function desiitse_transient_get( string $slug ): ?array {
	$data = get_transient( desiitse_transient_key( $slug ) );
	return ( is_array( $data ) && ! empty( $data['@graph'] ) ) ? $data : null;
}

function desiitse_transient_set( string $slug, array $data ): void {
	set_transient( desiitse_transient_key( $slug ), $data, DESIITSE_CACHE_TTL );
}

function desiitse_transient_delete( string $slug ): void {
	delete_transient( desiitse_transient_key( $slug ) );
}

// ════════════════════════════════════════════════════════════════════════════
// LIVELLO 2 — WP OPTION
// Persistente, nessuna scadenza, autoload=false (non caricata ad ogni request).
// Sopravvive ai flush dei transient (es. plugin di cache che svuotano i transient).
// ════════════════════════════════════════════════════════════════════════════

function desiitse_option_key( string $slug ): string {
	return 'desiitse_graph_option_' . sanitize_key( $slug );
}

function desiitse_option_get( string $slug ): ?array {
	$data = get_option( desiitse_option_key( $slug ), null );
	return ( is_array( $data ) && ! empty( $data['@graph'] ) ) ? $data : null;
}

function desiitse_option_set( string $slug, array $data ): void {
	update_option( desiitse_option_key( $slug ), $data, false ); // autoload = false
}

function desiitse_option_delete( string $slug ): void {
	delete_option( desiitse_option_key( $slug ) );
}

// ════════════════════════════════════════════════════════════════════════════
// FACADE — usato da tutti gli endpoint REST
// ════════════════════════════════════════════════════════════════════════════

/**
 * Restituisce la risposta REST per uno slug usando la cache a due livelli.
 *
 * L'endpoint REST non interroga mai il database dei contenuti direttamente:
 * legge solo dalla cache precomputata. La query live avviene solo se
 * entrambi i livelli sono vuoti (tipicamente al primissimo avvio).
 *
 * @param string   $slug     Chiave univoca dell'endpoint (es. 'luoghi').
 * @param callable $builder  Funzione che costruisce i nodi JSON-LD live.
 * @return WP_REST_Response
 */
function desiitse_cached_response( string $slug, callable $builder ): WP_REST_Response {

	// ── Livello 1: transient ──────────────────────────────────────────────
	$data = desiitse_transient_get( $slug );
	if ( $data !== null ) {
		return rest_ensure_response( $data );
	}

	// ── Livello 2: WP option ──────────────────────────────────────────────
	$data = desiitse_option_get( $slug );
	if ( $data !== null ) {
		desiitse_transient_set( $slug, $data ); // riscalda il transient
		return rest_ensure_response( $data );
	}

	// ── Livello 3: query live (solo primo avvio o dopo reset completo) ────
	return rest_ensure_response( desiitse_build_and_persist( $slug, $builder ) );
}

/**
 * Esegue il builder, persiste su entrambi i livelli e restituisce i dati.
 * Usato dal facade e dal cron.
 *
 * @param string   $slug
 * @param callable $builder
 * @return array
 */
function desiitse_build_and_persist( string $slug, callable $builder ): array {
	$nodes = array_values( array_filter( $builder() ) );
	$data  = [
		'@context' => desiitse_context(),
		'@graph'   => $nodes,
	];

	desiitse_option_set( $slug, $data );
	desiitse_transient_set( $slug, $data );

	return $data;
}

// ════════════════════════════════════════════════════════════════════════════
// INVALIDAZIONE
// ════════════════════════════════════════════════════════════════════════════

/**
 * Invalida entrambi i livelli per uno slug.
 * Non ricostruisce: il rebuild è delegato al cron.
 */
function desiitse_invalidate( string $slug ): void {
	desiitse_transient_delete( $slug );
	desiitse_option_delete( $slug );
}

/**
 * Invalida tutti gli slug del plugin.
 */
function desiitse_invalidate_all(): void {
	foreach ( desiitse_all_slugs() as $slug ) {
		desiitse_invalidate( $slug );
	}
}

/**
 * Invalida la cache degli slug legati al CPT di un dato post
 * e li marca come "dirty" per il rebuild asincrono.
 */
function desiitse_invalidate_for_post( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$map = desiitse_cpt_slug_map();
	if ( ! isset( $map[ $post->post_type ] ) ) {
		return;
	}

	foreach ( $map[ $post->post_type ] as $slug ) {
		desiitse_invalidate( $slug );
		desiitse_mark_dirty( $slug );
	}
}

// ════════════════════════════════════════════════════════════════════════════
// HOOK DI INVALIDAZIONE
// ════════════════════════════════════════════════════════════════════════════

// Attivazione: reset + primo build schedulato.
register_activation_hook(
	DESIITSE_DIR . 'design-comuni-semantic.php',
	function () {
		desiitse_invalidate_all();
		desiitse_schedule_rebuild_all();
	}
);

// Disattivazione: pulizia completa.
register_deactivation_hook(
	DESIITSE_DIR . 'design-comuni-semantic.php',
	'desiitse_invalidate_all'
);

// Salvataggio post (esclude revisioni e auto-save).
add_action( 'save_post', function ( int $post_id, WP_Post $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	desiitse_invalidate_for_post( $post_id );
}, 10, 2 );

// Cestino ed eliminazione definitiva.
add_action( 'wp_trash_post',      'desiitse_invalidate_for_post' );
add_action( 'before_delete_post', 'desiitse_invalidate_for_post' );

// Modifica meta senza save_post (es. aggiornamenti via REST o script).
add_action( 'added_post_meta',   function ( $meta_id, int $post_id ) { desiitse_invalidate_for_post( $post_id ); }, 10, 2 );
add_action( 'updated_post_meta', function ( $meta_id, int $post_id ) { desiitse_invalidate_for_post( $post_id ); }, 10, 2 );
add_action( 'deleted_post_meta', function ( $meta_id, int $post_id ) { desiitse_invalidate_for_post( $post_id ); }, 10, 2 );

// Modifica utenti WP (persona_pubblica e autori documenti DSI).
$desiitse_user_invalidate = function () {
	foreach ( [ 'persona-pubblica', 'documenti', 'comuni-graph', 'scuole-graph' ] as $slug ) {
		desiitse_invalidate( $slug );
		desiitse_mark_dirty( $slug );
	}
};
add_action( 'profile_update',  $desiitse_user_invalidate );
add_action( 'user_register',   $desiitse_user_invalidate );
add_action( 'deleted_user',    $desiitse_user_invalidate );
unset( $desiitse_user_invalidate );
