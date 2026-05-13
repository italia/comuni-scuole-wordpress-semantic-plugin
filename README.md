<<<<<<< HEAD
# Plugin Wordpress per la pubblicazione semantica dei siti di Comuni e Scuole

Il plugin permette di generare, a partire dai dati che sono presenti nei siti di Comuni e Scuole realizzati utilizzando i temi wordpress:

* [Design Comuni Wordpress Theme](https://github.com/italia/design-comuni-wordpress-theme)
* [Design Scuole Wordpress Theme](https://github.com/italia/design-scuole-wordpress-theme)

Dopo l'installazione, all'indirizzo `https://{base_url}/wp-json/comuni/v1/graph` o `https://{base_url}/wp-json/scuole/v1/graph` sarà disponibile il grafo in formato JSON-LD con tutto il contenuto informativo del sito.
=======
# comuni-scuole-wordpress-semantic-plugin
Plugin di generazione di un grafo JSON-LD a partire dai dati dei temi Wordpress per comuni e scuole

=== Semantic Italia ===
Contributors: dtditalia
Tags: json-ld, linked-data, semantic-web, open-data, rest-api
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exports content from Design Comuni Italia (DCI) and Design Scuole Italia (DSI) WordPress themes as JSON-LD graphs aligned to schema.gov.it.

== Description ==

**Semantic Italia** transforms content published with the official WordPress themes of the Italian Department for Digital Transformation — *Design Comuni Italia* (DCI) and *Design Scuole Italia* (DSI) — into structured data in **JSON-LD** format, aligned with the ontologies of the [schema.gov.it](https://schema.gov.it) catalog.

The generated graphs conform to the Italian Public Administration semantic framework: every entity (service, place, event, public official, document, organizational unit) is represented using standardized classes and properties, enabling interoperability with other systems and publication as **Linked Open Data**.

= Main Features =

* JSON-LD export via REST API (`/wp-json/comuni/v1/graph`, `/wp-json/scuole/v1/graph`)
* Automatic detection of the active domain (DCI / DSI / both)
* Official IPA identifier (`urn:x-italian-pa:code`) for the `cov:PublicOrganization` node
* Two-level precomputed cache (WP transient + WP option) with asynchronous rebuild via WP-Cron
* Built-in protection against excessive requests (per-IP and global rate limiting)
* Admin page with cache status panel and editable IPA code field

= REST Endpoints =

**DCI (Municipalities)**

* `GET /wp-json/comuni/v1/graph` — Full graph
* `GET /wp-json/comuni/v1/graph/persona-pubblica`
* `GET /wp-json/comuni/v1/graph/luoghi`
* `GET /wp-json/comuni/v1/graph/servizi`
* `GET /wp-json/comuni/v1/graph/unita-organizzative`
* `GET /wp-json/comuni/v1/graph/eventi`
* `GET /wp-json/comuni/v1/graph/dataset`

**DSI (Schools)**

* `GET /wp-json/scuole/v1/graph` — Full graph
* `GET /wp-json/scuole/v1/graph/luoghi`
* `GET /wp-json/scuole/v1/graph/servizi`
* `GET /wp-json/scuole/v1/graph/eventi`
* `GET /wp-json/scuole/v1/graph/documenti`
* `GET /wp-json/scuole/v1/graph/strutture`

= Ontologies Used =

All classes and properties are aligned with the [schema.gov.it](https://schema.gov.it) catalog:
`cpv:`, `cov:`, `cpsv:`, `cpev:`, `poi:`, `clv:`, `dcatapit:`, `dct:`, `l0:`, `foaf:`, `proj:`, `ti:`

= Requirements =

This plugin requires one of the following official WordPress themes:

* [Design Comuni Italia](https://github.com/italia/design-comuni-wordpress-theme)
* [Design Scuole Italia](https://github.com/italia/design-scuole-wordpress-theme)

== Installation ==

1. Upload the `semantic-italia` folder to the `/wp-content/plugins/` directory
2. Activate the plugin from the WordPress *Plugins* page
3. Upon activation, the plugin automatically detects the active domain (DCI/DSI) and the IPA code
4. Go to *Settings → Semantic Italia* to verify the configuration

== Frequently Asked Questions ==

= Does the plugin work without the official themes? =

No. The plugin reads the `_dci_*` (Design Comuni Italia) and `_dsi_*` (Design Scuole Italia) meta fields registered by the official themes. Without an active theme the endpoints will return an empty graph.

= How is the IPA code determined? =

Upon activation, the plugin matches the `home_url()` domain against the IPA index included in `data/ipa-index.json`. If the domain is found, the code is saved and used as the `@id` of the `cov:PublicOrganization` node in the format `urn:x-italian-pa:code`. If not found automatically, it can be entered manually from the administration page.

= How do I update the IPA code? =

Go to *Settings → Semantic Italia* and edit the **IPA Code** field. Saving automatically clears the cache.

= How does the cache work? =

The plugin precomputes JSON-LD graphs and stores them on two levels: a WordPress transient (expires after 1 hour, configurable) and a WP option (persistent). Rebuild happens automatically in the background via WP-Cron when content is modified, without slowing down post saves.

= How do I configure rate limiting? =

Parameters are configurable via WordPress filters in the theme's `functions.php`:

`add_filter( 'desiitse_rl_max_requests', fn() => 30 );`
`add_filter( 'desiitse_rl_window_seconds', fn() => 60 );`
`add_filter( 'desiitse_rl_global_rps', fn() => 50 );`

== Screenshots ==

1. Administration page showing cache status and IPA code field
2. Example JSON-LD response for the DCI graph

== Changelog ==

= 1.0.0 =
* Initial public release

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
>>>>>>> fc174c0 (migrate from svn of wordpress.org)
