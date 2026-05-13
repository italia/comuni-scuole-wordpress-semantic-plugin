<?php
/**
 * Controllo disponibilità delle REST API del plugin.
 *
 * Gestisce due scenari in cui le route devono rispondere 503:
 *
 * 1. PLUGIN DISABILITATO via opzione amministrativa
 *    L'amministratore può sospendere l'esposizione delle API senza
 *    disattivare il plugin (utile per manutenzione dei dati, debug, ecc.).
 *
 * 2. SITO IN MODALITÀ MANUTENZIONE (qualsiasi plugin o metodo)
 *
 *    Strategia a tre livelli, dal più veloce al più generale:
 *
 *    a) Core WP: file .maintenance in ABSPATH o costante WP_MAINTENANCE.
 *       Copre gli aggiornamenti automatici nativi di WordPress.
 *
 *    b) Plugin noti: lettura diretta delle opzioni WP dei plugin di
 *       maintenance più diffusi (SeedProd, WP Maintenance Mode,
 *       Maintenance by fruitfulcode, LightStart/Coming Soon).
 *       Zero latenza aggiuntiva — legge solo WP option in object-cache.
 *
 *    c) Self-check HTTP: wp_remote_get() sulla homepage del sito.
 *       Copre qualsiasi altro plugin non in lista, usando la stessa
 *       logica del check esterno: se il frontend risponde 503 oppure
 *       il body contiene keyword di maintenance, blocchiamo le API.
 *       Il risultato è cachato in un transient (60s ok / 30s in maint.)
 *       per evitare una HTTP request ad ogni chiamata REST.
 *
 * In tutti i casi la risposta è un WP_Error con:
 *   - HTTP 503 Service Unavailable
 *   - header Retry-After: 300
 *   - body JSON coerente con lo stile WP REST
 *
 * @package Design_Italia_Semantic
 */

defined( 'ABSPATH' ) || exit;

// ── Costanti ──────────────────────────────────────────────────────────────────

/** Nome dell'opzione WP che indica se le API sono abilitate (default '1'). */
define( 'DESIITSE_ENABLED_OPTION', 'desiitse_api_enabled' );

/** TTL del transient per il self-check HTTP quando il sito è OK (secondi). */
define( 'DESIITSE_MAINT_CHECK_TTL', 60 );

/** Chiave del transient per il self-check HTTP. */
define( 'DESIITSE_MAINT_CHECK_KEY', 'desiitse_maintenance_http_check' );

// ── Helper: toggle admin ──────────────────────────────────────────────────────

/**
 * Indica se le API del plugin sono abilitate dall'amministratore.
 */
function desiitse_api_is_enabled(): bool {
	return (bool) get_option( DESIITSE_ENABLED_OPTION, '1' );
}

// ── Helper: rilevamento maintenance ──────────────────────────────────────────

/**
 * Livello a) — Maintenance nativa di WordPress core.
 */
function desiitse_maint_core(): bool {
	if ( defined( 'WP_MAINTENANCE' ) && WP_MAINTENANCE ) {
		return true;
	}
	return file_exists( ABSPATH . '.maintenance' );
}

/**
 * Livello b) — Plugin di maintenance noti.
 *
 * Ogni check legge una WP option già in object-cache: zero query extra.
 * Aggiungere qui altri check se si usano plugin non in lista.
 */
function desiitse_maint_known_plugins(): bool {

	// ── SeedProd (Coming Soon Page & Maintenance Mode) ────────────────────
	if ( defined( 'SEEDPROD_VERSION' ) || defined( 'SEEDPROD_PRO_VERSION' ) ) {
		$s = get_option( 'seedprod_settings', [] );
		if ( ! empty( $s['enable_coming_soon'] ) || ! empty( $s['enable_maintenance_mode'] ) ) {
			return true;
		}
	}

	// ── WP Maintenance Mode (fruitfulcode) ────────────────────────────────
	// wpmm_settings → general → status: 1 = attivo
	$wpmm = get_option( 'wpmm_settings', [] );
	if ( isset( $wpmm['general']['status'] ) && (int) $wpmm['general']['status'] === 1 ) {
		return true;
	}

	// ── Maintenance (fruitfulcode, versione standalone) ───────────────────
	$mmode = get_option( 'maintenance_mode', '' );
	if ( $mmode === '1' || $mmode === 1 || $mmode === true ) {
		return true;
	}

	// ── LightStart / Coming Soon (WebFactory Ltd) ────────────────────────
	// dt_settings → status: 1 = coming soon, 2 = maintenance
	$dt = get_option( 'dt_settings', [] );
	if ( isset( $dt['status'] ) && in_array( (int) $dt['status'], [ 1, 2 ], true ) ) {
		return true;
	}

	// ── CMP — Coming Soon & Maintenance Plugin (NiteoThemes) ─────────────
	// niteoCS_status = 'publish' quando la pagina è attiva
	if ( get_option( 'niteoCS_status', '' ) === 'publish' ) {
		return true;
	}

	// ── Ultimate Coming Soon Page (Martin Smith) ──────────────────────────
	$ucsp = get_option( 'ultp_coming_soon_settings', [] );
	if ( isset( $ucsp['coming_soon'] ) && $ucsp['coming_soon'] === 'true' ) {
		return true;
	}

	return false;
}

/**
 * Livello c) — Self-check HTTP sulla homepage.
 *
 * Esegue wp_remote_get() verso home_url('/') con User-Agent neutro
 * (non "WordPress" per evitare whitelist bot-based di alcuni plugin).
 * Il risultato è cachato in un transient per non fare una HTTP request
 * ad ogni chiamata REST.
 *
 * Keyword cercate nel body (case-insensitive, IT + EN):
 *   maintenance, manutenzione, coming soon, under construction,
 *   briefly unavailable, presto disponibile, lavori in corso
 *
 * @return bool  true se il frontend risulta in maintenance.
 */
function desiitse_maint_http_selfcheck(): bool {
	// Legge dalla cache per evitare HTTP request ad ogni REST call.
	$cached = get_transient( DESIITSE_MAINT_CHECK_KEY );
	if ( $cached !== false ) {
		return $cached === '1';
	}

	$response = wp_remote_get(
		home_url( '/' ),
		[
			'timeout'    => 5,
			'user-agent' => 'DIS-AvailabilityCheck/1.0',
			'sslverify'  => false, // permette cert self-signed in locale
		]
	);

	$is_maintenance = false;

	if ( ! is_wp_error( $response ) ) {
		$status = (int) wp_remote_retrieve_response_code( $response );

		// 503 è il segnale diretto di maintenance per la maggior parte dei plugin.
		if ( $status === 503 ) {
			$is_maintenance = true;
		}

		// Scansione keyword nel body (solo se non già identificato via status).
		if ( ! $is_maintenance ) {
			$body     = mb_strtolower( wp_remote_retrieve_body( $response ) );
			$keywords = [
				'maintenance',
				'manutenzione',
				'coming soon',
				'under construction',
				'briefly unavailable',
				'presto disponibile',
				'lavori in corso',
			];
			foreach ( $keywords as $kw ) {
				if ( str_contains( $body, $kw ) ) {
					$is_maintenance = true;
					break;
				}
			}
		}
	}

	// TTL breve (30s) se in manutenzione → rileva ripristino velocemente.
	// TTL pieno (60s) se tutto ok → riduce overhead delle HTTP request.
	set_transient(
		DESIITSE_MAINT_CHECK_KEY,
		$is_maintenance ? '1' : '0',
		$is_maintenance ? 30 : DESIITSE_MAINT_CHECK_TTL
	);

	return $is_maintenance;
}

/**
 * Controlla tutti i livelli in sequenza con short-circuit al primo match.
 */
function desiitse_wp_is_maintenance(): bool {
	return desiitse_maint_core()
		|| desiitse_maint_known_plugins()
		|| desiitse_maint_http_selfcheck();
}

/**
 * Restituisce true se le route del plugin devono essere bloccate.
 */
function desiitse_api_should_block(): bool {
	return ! desiitse_api_is_enabled() || desiitse_wp_is_maintenance();
}

// ── Invalidazione transient al cambio di opzioni ──────────────────────────────

/**
 * Quando l'admin modifica le impostazioni di un plugin di maintenance,
 * svuota subito il transient del self-check per non aspettare il TTL.
 */
add_action( 'updated_option', function ( string $option ) {
	$watched = [
		'seedprod_settings',
		'wpmm_settings',
		'maintenance_mode',
		'dt_settings',
		'niteoCS_status',
		'ultp_coming_soon_settings',
		DESIITSE_ENABLED_OPTION,
	];
	if ( in_array( $option, $watched, true ) ) {
		delete_transient( DESIITSE_MAINT_CHECK_KEY );
	}
} );

// ── Intercettazione REST ──────────────────────────────────────────────────────

/**
 * Intercetta le richieste verso le route del plugin prima dell'elaborazione.
 * Priorità 5: prima del rate-limiter (priorità 10).
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, WP_REST_Request $request ) {

	$route = ltrim( $request->get_route(), '/' );
	$ours  = false;
	foreach ( [ 'comuni/v1', 'scuole/v1' ] as $ns ) {
		if ( str_starts_with( $route, $ns ) ) {
			$ours = true;
			break;
		}
	}

	if ( ! $ours ) {
		return $result;
	}

	if ( ! desiitse_api_should_block() ) {
		return $result;
	}

	// Messaggio differenziato per causa del blocco.
	if ( ! desiitse_api_is_enabled() ) {
		$code    = 'desiitse_api_disabled';
		$message = __(
			"Le API semantiche sono temporaneamente disabilitate dall'amministratore.",
			'design-italia-semantic'
		);
	} else {
		$code    = 'desiitse_maintenance';
		$message = __(
			'Il sito è in modalità manutenzione. Le API semantiche saranno disponibili al termine.',
			'design-italia-semantic'
		);
	}

	add_filter( 'rest_post_dispatch', function ( WP_REST_Response $response ) {
		$response->set_status( 503 );
		$response->header( 'Retry-After', '300' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	} );

	return new WP_Error( $code, $message, [ 'status' => 503 ] );

}, 5, 3 );
