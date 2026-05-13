<?php
/**
 * Plugin Name:       Semantic Italia
 * Plugin URI:        https://github.com/italia/design-comuni-wordpress-theme
 * Description:       Esportazione JSON-LD semantica allineata a schema.gov.it per Design Comuni (DCI) e Design Scuole (DSI). Cache precomputata in WP option + transient, rebuild asincrono via WP-Cron, protezione integrata contro richieste massive.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Developers Italia
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       design-italia-semantic
 */

defined( 'ABSPATH' ) || exit;

// ── Output buffer durante l'attivazione ───────────────────────────────────────
// Alcuni plugin di terze parti (es. members) caricano le traduzioni prima
// dell'hook init generando Notice HTML che WordPress interpreta come "output
// inatteso". Il buffer cattura quegli output durante la fase di boot
// e li scarta prima che possano interferire con gli header HTTP.
if ( defined( 'WP_ADMIN' ) && isset( $_GET['action'] ) && $_GET['action'] === 'activate' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	ob_start();
	add_action( 'activated_plugin', function () {
		ob_end_clean();
	}, PHP_INT_MAX );
}

// Costante privata del plugin: sempre corretta, usa __FILE__ direttamente.
// Non dipende da DESIITSE_DIR che potrebbe essere già definita da un'altra versione attiva.
define( 'DESIITSE_PLUGIN_FILE', __FILE__ );
define( 'DESIITSE_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );

// Compatibilità con codice che usa DESIITSE_DIR / DESIITSE_CACHE_TTL.
if ( ! defined( 'DESIITSE_VERSION' ) )   { define( 'DESIITSE_VERSION',  '1.0.0' ); }
if ( ! defined( 'DESIITSE_DIR' ) )       { define( 'DESIITSE_DIR',      DESIITSE_PLUGIN_DIR ); }
if ( ! defined( 'DESIITSE_CACHE_TTL' ) ) { define( 'DESIITSE_CACHE_TTL', (int) apply_filters( 'desiitse_cache_ttl', 3600 ) ); }

// ── Disponibilità API (deve caricare PRIMA del rate-limiter) ─────────────────
// Gestisce: plugin disabilitato via opzione admin + sito in manutenzione WP.
require_once DESIITSE_PLUGIN_DIR . 'inc/availability.php';

// ── Rate limiter (deve caricare prima di tutto il resto) ──────────────────────
require_once DESIITSE_PLUGIN_DIR . 'inc/rate-limit.php';

// ── Rilevamento dominio attivo (DCI / DSI / entrambi) ────────────────────────
// Deve caricare PRIMA dei file graph, che usano desiitse_should_register_route().
require_once DESIITSE_PLUGIN_DIR . 'inc/domain-detection.php';

// ── Lookup codice IPA (deve caricare prima di graph-helpers) ─────────────────
require_once DESIITSE_PLUGIN_DIR . 'inc/ipa-lookup.php';

// ── Helpers condivisi ─────────────────────────────────────────────────────────
require_once DESIITSE_PLUGIN_DIR . 'inc/graph-helpers.php';

// ── Esportatori per singola entità ────────────────────────────────────────────
require_once DESIITSE_PLUGIN_DIR . 'inc/persona-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/luogo-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/servizio-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/unita-organizzativa-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/evento-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/dataset-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/documento-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/struttura-graph.php';

// ── Grafi aggregati per dominio ────────────────────────────────────────────────
require_once DESIITSE_PLUGIN_DIR . 'inc/dci-graph.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/dsi-graph.php';

// ── Cache (option + transient) e rebuild asincrono (WP-Cron) ─────────────────
require_once DESIITSE_PLUGIN_DIR . 'inc/cache.php';
require_once DESIITSE_PLUGIN_DIR . 'inc/cron.php';

// ── Pagina di amministrazione (solo backend) ──────────────────────────────────
if ( is_admin() ) {
	require_once DESIITSE_PLUGIN_DIR . 'inc/admin-page.php';
}

// ── Espone CPT persona_pubblica nelle REST API ─────────────────────────────────
add_filter( 'register_post_type_args', function ( $args, $post_type ) {
	if ( $post_type === 'persona_pubblica' ) {
		$args['show_in_rest']          = true;
		$args['rest_base']             = 'persona-pubblica';
		$args['rest_controller_class'] = 'WP_REST_Posts_Controller';
	}
	return $args;
}, 10, 2 );
