<?php
/**
 * Protezione delle REST API contro richieste massive (rate limiting).
 *
 * Strategia a tre livelli — tutto via transient WP, zero filesystem:
 *
 * 1. RATE LIMIT per IP
 *    Ogni IP può effettuare al massimo DESIITSE_RL_MAX_REQUESTS richieste
 *    negli ultimi DESIITSE_RL_WINDOW_SECONDS secondi verso le route del plugin.
 *    Superato il limite → HTTP 429 Too Many Requests.
 *
 * 2. GLOBAL THROTTLE (valvola di sicurezza globale)
 *    Conta il totale delle richieste al plugin nell'ultimo secondo.
 *    Se supera DESIITSE_RL_GLOBAL_RPS (requests per second) → HTTP 429.
 *    Protegge il server anche da attacchi distribuiti su molti IP diversi.
 *
 * 3. HEADER INFORMATIVI
 *    Ogni risposta include X-RateLimit-Limit, X-RateLimit-Remaining,
 *    X-RateLimit-Reset e Retry-After (solo sul 429) per consentire
 *    ai client legittimi di adeguarsi automaticamente.
 *
 * Tutti i parametri sono filtrabili senza toccare il codice:
 *   add_filter( 'desiitse_rl_max_requests',      fn() => 30  );
 *   add_filter( 'desiitse_rl_window_seconds',    fn() => 60  );
 *   add_filter( 'desiitse_rl_global_rps',        fn() => 50  );
 *   add_filter( 'desiitse_rl_whitelist_ips',     fn( $ips ) => array_merge( $ips, ['1.2.3.4'] ) );
 *
 * @package Design_Italia_Semantic
 */

defined( 'ABSPATH' ) || exit;

// ── Costanti (valori default, sovrascrivibili via filtro) ─────────────────────

define( 'DESIITSE_RL_MAX_REQUESTS',   (int) apply_filters( 'desiitse_rl_max_requests',   60  ) ); // req per IP per finestra
define( 'DESIITSE_RL_WINDOW_SECONDS', (int) apply_filters( 'desiitse_rl_window_seconds', 60  ) ); // secondi della finestra
define( 'DESIITSE_RL_GLOBAL_RPS',     (int) apply_filters( 'desiitse_rl_global_rps',     100 ) ); // req/s globali massime

// ── Prefissi delle route protette ─────────────────────────────────────────────

/**
 * Namespace REST del plugin da proteggere.
 *
 * @return string[]
 */
function desiitse_rl_protected_namespaces(): array {
	return [ 'comuni/v1', 'scuole/v1' ];
}

// ── Recupero IP del client ─────────────────────────────────────────────────────

/**
 * Restituisce l'IP reale del client, gestendo proxy e CDN comuni.
 * NOTA: X-Forwarded-For e simili sono falsificabili dal client;
 * vengono letti solo se l'hosting è noto per impostarli (filtro dedicato).
 * Di default si usa REMOTE_ADDR, che è l'unico valore affidabile al 100%.
 */
function desiitse_rl_client_ip(): string {
	// Consenti di fidarsi degli header proxy solo se esplicitamente abilitato.
	$trust_proxy = (bool) apply_filters( 'desiitse_rl_trust_proxy_headers', false );

	if ( $trust_proxy ) {
		$headers = [
			'HTTP_CF_CONNECTING_IP',   // Cloudflare
			'HTTP_X_REAL_IP',          // Nginx proxy
			'HTTP_X_FORWARDED_FOR',    // Generic proxy (prende solo il primo IP)
		];
		foreach ( $headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = sanitize_text_field( wp_unslash( (string) $_SERVER[ $h ] ) );
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
	}

	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
}

// ── Whitelist ─────────────────────────────────────────────────────────────────

/**
 * IP esenti dal rate limiting (es. crawler istituzionali, monitoraggio).
 * Aggiungere via filtro, non modificare direttamente.
 *
 * @return string[]
 */
function desiitse_rl_whitelist(): array {
	return (array) apply_filters( 'desiitse_rl_whitelist_ips', [
		'127.0.0.1',
		'::1',
	] );
}

// ── Rate limit per IP ─────────────────────────────────────────────────────────

/**
 * Chiave transient per il contatore di un dato IP.
 */
function desiitse_rl_ip_key( string $ip ): string {
	return 'desiitse_rl_ip_' . md5( $ip );
}

/**
 * Incrementa il contatore per l'IP e restituisce i dati correnti.
 *
 * @return array{ count: int, reset: int }
 */
function desiitse_rl_ip_increment( string $ip ): array {
	$key  = desiitse_rl_ip_key( $ip );
	$data = get_transient( $key );

	if ( ! is_array( $data ) ) {
		$data = [ 'count' => 0, 'reset' => time() + DESIITSE_RL_WINDOW_SECONDS ];
	}

	$data['count']++;
	set_transient( $key, $data, DESIITSE_RL_WINDOW_SECONDS );

	return $data;
}

// ── Global throttle ───────────────────────────────────────────────────────────

/**
 * Incrementa il contatore globale delle richieste al secondo.
 * Usa una finestra scorrevole di 1 secondo.
 *
 * @return int  Numero di richieste nell'ultimo secondo.
 */
function desiitse_rl_global_increment(): int {
	$key  = 'desiitse_rl_global_' . (string) time(); // chiave per il secondo corrente
	$data = get_transient( $key );
	$count = is_numeric( $data ) ? (int) $data + 1 : 1;
	set_transient( $key, $count, 5 ); // TTL 5s: pulizia automatica
	return $count;
}

// ── Risposta 429 ─────────────────────────────────────────────────────────────

/**
 * Termina la richiesta con HTTP 429 Too Many Requests.
 *
 * @param int    $retry_after  Secondi prima di riprovare.
 * @param string $reason       'ip' | 'global'
 */
function desiitse_rl_abort( int $retry_after, string $reason = 'ip' ): void {
	$message = $reason === 'global'
		? 'Il server sta ricevendo troppe richieste. Riprova tra qualche secondo.'
		: 'Hai superato il limite di richieste consentite. Riprova tra ' . $retry_after . ' secondi.';

	status_header( 429 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Retry-After: ' . $retry_after );
	header( 'X-RateLimit-Reason: ' . $reason );
	echo wp_json_encode( [
		'code'    => 'too_many_requests',
		'message' => $message,
		'data'    => [ 'status' => 429, 'retry_after' => $retry_after ],
	] );
	exit;
}

// ── Hook principale ───────────────────────────────────────────────────────────

/**
 * Intercetta le richieste REST prima che vengano elaborate.
 * Agganciato su rest_pre_dispatch per massima efficienza:
 * viene chiamato dopo l'autenticazione WP ma prima del routing,
 * quindi non spreca risorse se la richiesta non è destinata al plugin.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, WP_REST_Request $request ) {
	$route = $request->get_route();

	// Controlla se la route appartiene ai namespace protetti.
	$protected = false;
	foreach ( desiitse_rl_protected_namespaces() as $ns ) {
		if ( str_starts_with( ltrim( $route, '/' ), $ns ) ) {
			$protected = true;
			break;
		}
	}

	if ( ! $protected ) {
		return $result; // Route non nostra: passa attraverso senza controlli.
	}

	$ip = desiitse_rl_client_ip();

	// Whitelist: skip per IP esenti.
	if ( in_array( $ip, desiitse_rl_whitelist(), true ) ) {
		return $result;
	}

	// ── 1. Global throttle ────────────────────────────────────────────────
	$global_count = desiitse_rl_global_increment();
	if ( $global_count > DESIITSE_RL_GLOBAL_RPS ) {
		desiitse_rl_abort( 2, 'global' ); // suggerisci di riprovare tra 2 secondi
	}

	// ── 2. Per-IP rate limit ──────────────────────────────────────────────
	$data      = desiitse_rl_ip_increment( $ip );
	$count     = $data['count'];
	$reset     = $data['reset'];
	$remaining = max( 0, DESIITSE_RL_MAX_REQUESTS - $count );

	// Aggiunge header informativi a tutte le risposte (anche quelle ok).
	add_filter( 'rest_post_dispatch', function ( WP_REST_Response $response ) use ( $count, $remaining, $reset ) {
		$response->header( 'X-RateLimit-Limit',     (string) DESIITSE_RL_MAX_REQUESTS );
		$response->header( 'X-RateLimit-Remaining', (string) $remaining );
		$response->header( 'X-RateLimit-Reset',     (string) $reset );
		return $response;
	} );

	if ( $count > DESIITSE_RL_MAX_REQUESTS ) {
		$retry = max( 1, $reset - time() );
		desiitse_rl_abort( $retry, 'ip' );
	}

	return $result;
}, 10, 3 );

// ── Protezione header HTTP aggiuntivi ─────────────────────────────────────────

/**
 * Aggiunge header di sicurezza standard a tutte le risposte REST del plugin.
 * Non influenzano le altre route REST di WordPress.
 */
add_filter( 'rest_post_dispatch', function ( WP_REST_Response $response, $server, WP_REST_Request $request ) {
	$route = $request->get_route();

	$protected = false;
	foreach ( desiitse_rl_protected_namespaces() as $ns ) {
		if ( str_starts_with( ltrim( $route, '/' ), $ns ) ) {
			$protected = true;
			break;
		}
	}

	if ( ! $protected ) {
		return $response;
	}

	// Impedisce la cache aggressiva da parte di proxy intermedi non configurati.
	// I client legittimi che vogliono caching devono usare la cache WP integrata.
	$response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=60' );
	$response->header( 'Vary',          'Accept-Encoding' );

	// Impedisce l'embedding in iframe (clickjacking).
	$response->header( 'X-Frame-Options', 'DENY' );

	// Impedisce lo sniffing del content-type.
	$response->header( 'X-Content-Type-Options', 'nosniff' );

	return $response;
}, 20, 3 );
