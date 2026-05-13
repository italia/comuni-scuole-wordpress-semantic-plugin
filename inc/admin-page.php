<?php
/**
 * Pagina di amministrazione del plugin.
 * Raggiungibile da: Impostazioni → Semantic Italia
 *
 * @package Design_Comuni_Semantic
 */

defined( 'ABSPATH' ) || exit;

// ── Registrazione voce di menu ────────────────────────────────────────────────

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'Semantic Italia', 'design-italia-semantic' ),
		__( 'Semantic Italia', 'design-italia-semantic' ),
		'manage_options',
		'semantic-italia',
		'desiitse_admin_page_render'
	);
} );

// ── Aggiunge link "Impostazioni" accanto al plugin nell'elenco plugin ─────────

add_filter( 'plugin_action_links_semantic-italia/semantic-italia.php', function ( $links ) {
	$url  = admin_url( 'options-general.php?page=semantic-italia' );
	$link = '<a href="' . esc_url( $url ) . '">' . __( 'Informazioni', 'design-italia-semantic' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
} );

// ── Stili inline per la pagina admin ─────────────────────────────────────────

// ── Stili e script per la pagina admin ───────────────────────────────────────

add_action( 'admin_enqueue_scripts', function ( string $hook ) {
	if ( $hook !== 'settings_page_semantic-italia' ) {
		return;
	}

	// CSS principale della pagina admin (file esterno).
	wp_enqueue_style(
		'desiitse-admin',
		plugin_dir_url( DESIITSE_PLUGIN_FILE ) . 'inc/admin-page.css',
		[],
		DESIITSE_VERSION
	);

	// Script inline per la conferma della modifica del codice IPA.
	// Usa wp_add_inline_script anziché <script> diretto per rispettare le best practice WP.
	// Il codice IPA corrente viene passato come dato inline per evitare l'output PHP nel template.
	wp_register_script( 'desiitse-admin', false, [], DESIITSE_VERSION, true );
	wp_enqueue_script( 'desiitse-admin' );

	$ipa_code_for_js = desiitse_get_ipa_code() ?? '';
	wp_add_inline_script(
		'desiitse-admin',
		'function desiitse_confirm_ipa_save( btn ) {' .
			'var original = ' . wp_json_encode( $ipa_code_for_js ) . ';' .
			'var current = document.getElementById( \'desiitse_ipa_code\' ).value.trim();' .
			'if ( current === original ) return true;' .
			'return confirm(' .
				'\'⚠️ ATTENZIONE\\n\\n\' +' .
				'\'Stai modificando il codice IPA dell\'\' + \'ente.\\n\\n\' +' .
				'\'Il codice IPA è l\'\' + \'identificativo ufficiale nel grafo JSON-LD \' +' .
				'\'(nodo cov:PublicOrganization). Un valore errato può compromettere \' +' .
				'\'l\'\' + \'interoperabilità semantica dei dati.\\n\\n\' +' .
				'\'La responsabilità della modifica è a tuo carico.\\n\\nVuoi continuare?\'' .
			');' .
		'}'
	);
} );


// ── Gestione azioni POST ──────────────────────────────────────────────────────

add_action( 'admin_init', function () {
	if ( ! isset( $_POST['desiitse_action'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'desiitse_cache_action' );

	$action = sanitize_key( $_POST['desiitse_action'] );

	if ( $action === 'flush_all' ) {
		desiitse_invalidate_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'semantic-italia', 'desiitse_msg' => 'flushed' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'rebuild_all' ) {
		desiitse_schedule_rebuild_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'semantic-italia', 'desiitse_msg' => 'scheduled' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'toggle_api' ) {
		$new_state = isset( $_POST['desiitse_api_enabled'] ) ? '1' : '0';
		update_option( DESIITSE_ENABLED_OPTION, $new_state );
		wp_safe_redirect( add_query_arg( [ 'page' => 'semantic-italia', 'desiitse_msg' => 'api_toggled' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'save_ipa_code' ) {
		$new_code = isset( $_POST['desiitse_ipa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['desiitse_ipa_code'] ) ) : '';
		desiitse_save_ipa_code( $new_code );
		// Il @id del nodo PublicOrganization è cambiato: svuota la cache.
		desiitse_invalidate_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'semantic-italia', 'desiitse_msg' => 'ipa_saved' ], admin_url( 'options-general.php' ) ) );
		exit;
	}
} );

// ── Render pagina ─────────────────────────────────────────────────────────────

function desiitse_admin_page_render(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$base     = rest_url();
	$msg      = isset( $_GET['desiitse_msg'] ) ? sanitize_key( wp_unslash( $_GET['desiitse_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter
	$cron_ts  = wp_next_scheduled( DESIITSE_CRON_HOOK );
	$ipa_code = desiitse_get_ipa_code() ?? '';
	$dirty    = (array) get_option( DESIITSE_DIRTY_OPTION, [] );
	$domain_data   = get_option( DESIITSE_DOMAIN_OPTION, [] );
	$active_domain = is_array( $domain_data ) ? ( $domain_data['domain'] ?? 'none' ) : 'none';

	// Stato cache per ogni slug.
	$cache_status = [];
	foreach ( desiitse_active_slugs() as $slug ) {
		$cache_status[ $slug ] = ( desiitse_transient_get( $slug ) !== null || desiitse_option_get( $slug ) !== null );
	}

	$api_rows = [
		// [ metodo, url, dominio-richiesto, descrizione, ontologia ]
		// ── DCI (Comuni) ──────────────────────────────────────────────────
		[ 'GET', $base . 'comuni/v1/graph',                    'dci', 'Grafo completo Comuni (DCI)',              'CPV, COV, CPSV, POI, CPEV, DCAT-AP_IT' ],
		[ 'GET', $base . 'comuni/v1/graph/persona-pubblica',  'dci', 'Persone pubbliche',                       'CPV Person' ],
		[ 'GET', $base . 'comuni/v1/graph/luoghi',             'dci', 'Luoghi dei Comuni',                       'POI, CLV Feature/Address' ],
		[ 'GET', $base . 'comuni/v1/graph/servizi',            'dci', 'Servizi al cittadino',                    'CPSV PublicService' ],
		[ 'GET', $base . 'comuni/v1/graph/unita-organizzative','dci', 'Uffici e strutture organizzative',        'COV Office' ],
		[ 'GET', $base . 'comuni/v1/graph/eventi',             'dci', 'Eventi pubblici',                         'CPEV PublicEvent' ],
		[ 'GET', $base . 'comuni/v1/graph/dataset',            'dci', 'Dataset aperti',                          'DCAT-AP_IT Dataset' ],
		// ── DSI (Scuole) ──────────────────────────────────────────────────
		[ 'GET', $base . 'scuole/v1/graph',                    'dsi', 'Grafo completo Scuole (DSI)',              'COV, CPSV, CPEV, foaf:Document' ],
		[ 'GET', $base . 'scuole/v1/graph/luoghi',             'dsi', 'Luoghi della scuola',                     'POI, CLV Feature/Address' ],
		[ 'GET', $base . 'scuole/v1/graph/servizi',            'dsi', 'Servizi scolastici',                      'CPSV PublicService' ],
		[ 'GET', $base . 'scuole/v1/graph/eventi',             'dsi', 'Eventi scolastici',                       'CPEV PublicEvent' ],
		[ 'GET', $base . 'scuole/v1/graph/documenti',          'dsi', 'Documenti e allegati',                    'foaf:Document' ],
		[ 'GET', $base . 'scuole/v1/graph/strutture',          'dsi', 'Strutture organizzative scolastiche',     'COV Organization' ],
	];

	// Determina se ogni route è attiva sul sito corrente.
	$is_route_active = function( string $required ) use ( $active_domain ): bool {
		if ( $active_domain === 'none' )  { return false; }
		if ( $required === 'any' )        { return true; }  // route condivise, sempre attive
		if ( $active_domain === 'both' )  { return true; }
		return $active_domain === $required;
	};
	$api_enabled = desiitse_api_is_enabled();
	$in_maintenance = desiitse_wp_is_maintenance();
	?>
	<div id="desiitse-admin-wrap">

		<?php if ( $msg === 'flushed' ) : ?>
		<div class="dcs-notice dcs-notice-success">✓ Cache svuotata correttamente. Il rebuild verrà eseguito alla prossima richiesta REST.</div>
		<?php elseif ( $msg === 'scheduled' ) : ?>
		<div class="dcs-notice dcs-notice-info">⏱ Rebuild completo schedulato. WP-Cron lo eseguirà entro 60 secondi.</div>
		<?php elseif ( $msg === 'ipa_saved' ) : ?>
		<div class="dcs-notice dcs-notice-success">✓ Codice IPA salvato. La cache è stata svuotata e verrà ricostruita automaticamente.</div>
		<?php elseif ( $msg === 'api_toggled' ) : ?>
		<div class="dcs-notice <?php echo $api_enabled ? 'dcs-notice-success' : 'dcs-notice-warning'; ?>">
			<?php echo $api_enabled ? '✓ API abilitate. Le route REST rispondono normalmente.' : '⚠ API disabilitate. Le route REST restituiranno HTTP 503.'; ?>
		</div>
		<?php endif; ?>

		<?php if ( $in_maintenance ) : ?>
		<div class="dcs-notice dcs-notice-warning">🔧 Il sito è in modalità manutenzione — le API semantiche restituiscono HTTP 503 finché la manutenzione non termina.</div>
		<?php endif; ?>

		<!-- ── Hero ── -->
		<div class="dcs-hero">
			<div class="dcs-hero-logo">🏛</div>
			<div class="dcs-hero-text">
				<h1>Semantic Italia</h1>
				<p>Esportazione JSON-LD allineata a <strong>schema.gov.it</strong> per i temi Design Comuni Italia (DCI) e Design Scuole Italia (DSI).</p>
			</div>
			<span class="dcs-version-badge">v<?php echo esc_html( DESIITSE_VERSION ); ?></span>

		<!-- ── Disponibilità API ── -->
		<div class="dcs-card" style="margin-top:24px; border-left: 4px solid <?php echo $api_enabled && ! $in_maintenance ? '#2e7d32' : '#c62828'; ?>;">
			<h2><span class="icon"><?php echo $api_enabled && ! $in_maintenance ? '✅' : '🔴'; ?></span> Disponibilità API</h2>
			<p>
				Stato attuale: <strong><?php
					if ( $in_maintenance ) {
						echo '<span style="color:#c62828">⚠ Sito in manutenzione — API bloccate automaticamente (HTTP 503)</span>';
					} elseif ( $api_enabled ) {
						echo '<span style="color:#2e7d32">✓ API attive — le route REST rispondono normalmente</span>';
					} else {
						echo '<span style="color:#c62828">✗ API disabilitate dall\'amministratore (HTTP 503)</span>';
					}
				?></strong>
			</p>
			<p style="font-size:13px; color:#666;">
				Puoi sospendere temporaneamente l'esposizione delle API semantiche senza disattivare il plugin
				(utile durante aggiornamenti dei dati, debug o migrazioni).
				In modalità manutenzione WP le API vengono bloccate automaticamente, indipendentemente da questa impostazione.
			</p>
			<?php if ( ! $in_maintenance ) : ?>
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'desiitse_cache_action' ); ?>
				<label style="display:inline-flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
					<input
						type="checkbox"
						name="desiitse_api_enabled"
						value="1"
						<?php checked( $api_enabled ); ?>
						onchange="this.form.submit()"
						style="width:18px; height:18px; cursor:pointer;"
					>
					API abilitate
				</label>
				<input type="hidden" name="desiitse_action" value="toggle_api">
				<noscript>
					<button type="submit" class="dcs-btn dcs-btn-primary" style="margin-left:12px;">Salva</button>
				</noscript>
			</form>
			<?php else : ?>
			<p style="font-size:12px; color:#999; margin-top:8px;">
				Il toggle è disabilitato finché il sito è in modalità manutenzione.
				Rimuovere il file <code>.maintenance</code> dalla root di WordPress per ripristinare il controllo manuale.
			</p>
			<?php endif; ?>
		</div>

		<!-- ── Codice IPA ── -->
		<div class="dcs-card" style="margin-top:24px;">
			<h2><span class="icon">🏛</span> Codice IPA dell'ente</h2>
			<p>
				Il codice IPA viene usato come <code>@id</code> del nodo <code>cov:PublicOrganization</code> nel grafo JSON-LD,
				al posto dell'URL del sito. Viene rilevato automaticamente all'attivazione del plugin consultando
				l'indice <strong>IndicePA</strong> incluso nel file <code>data/ipa-index.json</code>.
			</p>

			<?php
			$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			?>

			<?php if ( $ipa_code !== '' ) : ?>
			<p style="font-size:12px;color:#2e7d32;background:#e8f5e9;padding:8px 12px;border-radius:6px;border-left:3px solid #4caf50;margin-bottom:16px;">
				✓ Codice rilevato per il dominio <strong><?php echo esc_html( $site_host ); ?></strong>
			</p>
			<?php else : ?>
			<p style="font-size:12px;color:#b45309;background:#fef3c7;padding:8px 12px;border-radius:6px;border-left:3px solid #f59e0b;margin-bottom:16px;">
				⚠️ Il dominio <strong><?php echo esc_html( $site_host ); ?></strong> non è stato trovato nell'indice IPA.
				Puoi inserire il codice manualmente. Se lasci il campo vuoto, verrà usato l'URL del sito come <code>@id</code>.
			</p>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'desiitse_cache_action' ); ?>
				<div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
					<div style="flex:1;min-width:220px;">
						<label for="desiitse_ipa_code" style="display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;">
							Codice IPA
						</label>
						<input
							type="text"
							id="desiitse_ipa_code"
							name="desiitse_ipa_code"
							value="<?php echo esc_attr( $ipa_code ); ?>"
							placeholder="Es. c_a033 &nbsp;·&nbsp; istsc_sric80600c"
							style="width:100%;font-family:monospace;font-size:14px;font-weight:700;color:#0047b3;padding:9px 12px;border:2px solid #c5d8f5;border-radius:6px;background:#f0f6ff;"
						/>
					</div>
					<div style="padding-top:22px;">
						<button
							type="submit"
							name="desiitse_action"
							value="save_ipa_code"
							class="dcs-btn dcs-btn-primary"
							onclick="return desiitse_confirm_ipa_save(this);">
							💾 Salva
						</button>
					</div>
				</div>
			</form>

			<p style="font-size:11px;color:#888;margin-top:12px;">
				Il codice è modificabile: se lo cambi manualmente la cache viene svuotata automaticamente.
				Per aggiornarlo a seguito di un cambio dominio, disattiva e riattiva il plugin.
			</p>
		</div>

		</div>

		<!-- ── Dominio rilevato ── -->
		<?php
		$domain_label = [
			'dci'  => '🏙 Comuni (DCI) — <em>Design Comuni Italia</em>',
			'dsi'  => '🎓 Scuole (DSI) — <em>Design Scuole Italia</em>',
			'both' => '🏙 Comuni (DCI) + 🎓 Scuole (DSI) — sito ibrido',
			'none' => '⚠️ Nessun dominio rilevato — nessuna route REST attiva',
		];
		$domain_color = [
			'dci'  => '#e8f0fb',
			'dsi'  => '#f3e8fb',
			'both' => '#e8f5e9',
			'none' => '#fff3e0',
		];
		$domain_border = [
			'dci'  => '#0066cc',
			'dsi'  => '#6a0dad',
			'both' => '#2e7d32',
			'none' => '#e65100',
		];
		?>
		<div class="dcs-card" style="border-left: 4px solid <?php echo esc_attr( $domain_border[ $active_domain ] ?? '#999' ); ?>; background: <?php echo esc_attr( $domain_color[ $active_domain ] ?? '#f9f9f9' ); ?>;">
			<h2><span class="icon">🔍</span> Dominio attivo rilevato</h2>

			<p style="font-size:15px;font-weight:600;color:#1a1a2e;margin-bottom:8px;">
				<?php echo wp_kses_post( $domain_label[ $active_domain ] ?? $active_domain ); ?>
			</p>

			<?php if ( is_array( $domain_data ) && ! empty( $domain_data['detected'] ) ) : ?>
			<p style="font-size:12px;color:#666;margin-bottom:12px;">
				Ultimo rilevamento: <strong><?php echo esc_html( $domain_data['detected'] ); ?></strong>
				&nbsp;·&nbsp;
				Meta key <code>_dci_*</code> trovate: <strong><?php echo (int) ( $domain_data['dci_keys'] ?? 0 ); ?></strong>
				&nbsp;·&nbsp;
				Meta key <code>_dsi_*</code> trovate: <strong><?php echo (int) ( $domain_data['dsi_keys'] ?? 0 ); ?></strong>
			</p>
			<?php endif; ?>

			<?php if ( $active_domain === 'none' ) : ?>
			<div class="dcs-notice dcs-notice-info" style="margin-bottom:12px;">
				Nessuna meta key <code>_dci_*</code> o <code>_dsi_*</code> trovata nel database.
				Assicurati che il tema <strong>Design Comuni Italia</strong> o <strong>Design Scuole Italia</strong>
				sia attivo e che siano stati creati almeno alcuni contenuti.
				Poi <strong>disattiva e riattiva il plugin</strong> per rieseguire il rilevamento.
			</div>
			<?php endif; ?>

			<p style="font-size:13px;color:#3a3a5c;">
				Il plugin espone solo le route REST del dominio rilevato.
				Su un sito <strong>Comuni</strong> non verranno mai registrate le route DSI (documenti, strutture)
				e viceversa. Se il contenuto del sito cambia (es. viene aggiunto un nuovo tema),
				esegui nuovamente il rilevamento.
			</p>

			<div class="dcs-notice dcs-notice-info" style="margin-top:4px;">
				💡 Il rilevamento avviene automaticamente all'attivazione del plugin e non si ripete.
				Per aggiornarlo dopo un cambio tema (es. da Comuni a Scuole) è sufficiente
				<strong>disattivare e riattivare il plugin</strong> dalla pagina Plugin di WordPress.
			</div>
		</div>

		<!-- ── Cos'è ── -->
		<div class="dcs-card">
			<h2><span class="icon">📖</span> A cosa serve questo plugin</h2>
			<p>
				<strong>Semantic Italia</strong> trasforma i contenuti pubblicati con i temi WordPress ufficiali
				del Dipartimento per la Trasformazione Digitale — <em>Design Comuni Italia</em> (DCI) e
				<em>Design Scuole Italia</em> (DSI) — in dati strutturati nel formato <strong>JSON-LD</strong>,
				allineati alle ontologie del catalogo <a href="https://schema.gov.it" target="_blank" rel="noopener">schema.gov.it</a>.
			</p>
			<p>
				I grafi prodotti sono conformi al framework semantico italiano per la Pubblica Amministrazione:
				ogni entità (servizio, luogo, evento, persona pubblica, documento, struttura organizzativa…)
				viene rappresentata con le classi e le proprietà standardizzate, permettendo
				l'interoperabilità con altri sistemi e la pubblicazione come <strong>Linked Open Data</strong>.
			</p>
			<p>
				Il plugin non modifica i contenuti esistenti, non richiede configurazione e non scrive file
				sul disco. Funziona tramite endpoint REST nativi di WordPress, con cache precomputata
				su due livelli (transient + WP option) e rebuild asincrono via WP-Cron: il sito
				rimane sempre veloce, anche su hosting condivisi.
			</p>
		</div>

		<!-- ── API ── -->
		<div class="dcs-card">
			<h2><span class="icon">🔌</span> Endpoint REST disponibili</h2>
			<p>Tutte le route sono pubbliche (<code>GET</code>, senza autenticazione) e restituiscono
			<code>application/json</code> con struttura <code>@context</code> + <code>@graph</code> JSON-LD.
			Le route <span style="color:#c62828;font-weight:600;">disabilitate</span> non vengono registrate perché il dominio corrispondente non è attivo su questo sito.</p>
			<table class="dcs-table">
				<thead>
					<tr>
						<th>Metodo</th>
						<th>URL endpoint</th>
						<th>Dominio</th>
						<th>Descrizione</th>
						<th>Ontologia</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $api_rows as [ $method, $url, $domain, $desc, $onto ] ) :
					$active = $is_route_active( $domain ); ?>
					<tr style="<?php echo $active ? '' : 'opacity:.45;'; ?>">
						<td><span class="method"><?php echo esc_html( $method ); ?></span></td>
						<td>
							<?php if ( $active ) : ?>
							<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" class="endpoint"><?php echo esc_html( str_replace( $base, '', $url ) ); ?></a>
							<?php else : ?>
							<span class="endpoint" style="color:#aaa;text-decoration:line-through;"><?php echo esc_html( str_replace( $base, '', $url ) ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $domain === 'any' ) : ?>
								<div class="domain-both"><span class="domain-dci">DCI</span><span class="domain-dsi">DSI</span></div>
							<?php elseif ( $domain === 'dci' ) : ?>
								<span class="domain-dci">DCI</span>
							<?php else : ?>
								<span class="domain-dsi">DSI</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $desc ); ?><?php echo $active ? '' : ' <em style="color:#c62828;font-size:11px;">(non attiva)</em>'; ?></td>
						<td><em style="color:#666;font-size:11px;"><?php echo esc_html( $onto ); ?></em></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- ── Cache + Ontologie (2 col) ── -->
		<div class="dcs-grid-2">

			<!-- Cache -->
			<div class="dcs-card">
				<h2><span class="icon">⚡</span> Stato della cache</h2>
				<p>I grafi vengono precomputati e tenuti in cache. Il rebuild avviene automaticamente in background quando un contenuto viene modificato.</p>

				<?php if ( $cron_ts ) : ?>
				<div class="dcs-notice dcs-notice-info">
					⏱ Rebuild schedulato tra circa <?php echo esc_html( max( 0, (int) ( $cron_ts - time() ) ) ); ?> secondi.
					<?php if ( ! empty( $dirty ) ) : ?>
					Slug coinvolti: <?php echo esc_html( implode( ', ', array_keys( $dirty ) ) ); ?>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<div class="dcs-cache-grid">
					<?php foreach ( $cache_status as $slug => $hit ) : ?>
					<div class="dcs-cache-item">
						<div class="slug"><?php echo esc_html( $slug ); ?></div>
						<div class="status <?php echo $hit ? 'hit' : 'miss'; ?>">
							<?php echo $hit ? '✓ In cache' : '○ Non pronta'; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>

				<form method="post" action="">
					<?php wp_nonce_field( 'desiitse_cache_action' ); ?>
					<div class="dcs-actions">
						<button type="submit" name="desiitse_action" value="rebuild_all" class="dcs-btn dcs-btn-primary">
							🔄 Schedula rebuild completo
						</button>
						<button type="submit" name="desiitse_action" value="flush_all" class="dcs-btn dcs-btn-danger"
							onclick="return confirm('Svuotare tutta la cache? Gli endpoint risponderanno con query live fino al prossimo rebuild.');">
							🗑 Svuota cache
						</button>
					</div>
				</form>
			</div>

			<!-- Ontologie -->
			<div class="dcs-card">
				<h2><span class="icon">🧩</span> Ontologie utilizzate</h2>
				<p>Tutti i prefissi sono allineati al catalogo <a href="https://schema.gov.it" target="_blank" rel="noopener">schema.gov.it</a> del Dipartimento per la Trasformazione Digitale.</p>
				<ul class="dcs-onto-list">
					<li><span class="prefix">cpv:</span><span class="uri">w3id.org/italia/onto/CPV/ — Persone</span></li>
					<li><span class="prefix">cov:</span><span class="uri">w3id.org/italia/onto/COV/ — Organizzazioni</span></li>
					<li><span class="prefix">cpsv:</span><span class="uri">w3id.org/italia/onto/CPSV/ — Servizi pubblici</span></li>
					<li><span class="prefix">cpev:</span><span class="uri">w3id.org/italia/onto/CPEV/ — Eventi pubblici</span></li>
					<li><span class="prefix">poi:</span><span class="uri">w3id.org/italia/onto/POI/ — Luoghi</span></li>
					<li><span class="prefix">clv:</span><span class="uri">w3id.org/italia/onto/CLV/ — Indirizzi</span></li>
					<li><span class="prefix">dcatapit:</span><span class="uri">dati.gov.it/onto/dcatapit# — Dataset</span></li>
					<li><span class="prefix">dct:</span><span class="uri">purl.org/dc/terms/ — Dublin Core</span></li>
					<li><span class="prefix">l0:</span><span class="uri">w3id.org/italia/onto/l0/ — Base</span></li>
					<li><span class="prefix">foaf:</span><span class="uri">xmlns.com/foaf/0.1/ — Documenti</span></li>
					<li><span class="prefix">proj:</span><span class="uri">w3id.org/italia/onto/Project/ — Progetti</span></li>
					<li><span class="prefix">ti:</span><span class="uri">w3id.org/italia/onto/TI/ — Tempo</span></li>
				</ul>
			</div>
		</div>

		<!-- ── Protezione rate limiting ── -->
		<div class="dcs-card">
			<h2><span class="icon">🛡</span> Protezione contro richieste massive</h2>
			<p>
				Il plugin include un sistema di <strong>rate limiting</strong> integrato che protegge tutte le route
				<code>/comuni/v1/*</code> e <code>/scuole/v1/*</code> da richieste abusive, senza dipendenze esterne
				e senza accessi al filesystem.
			</p>
			<table class="dcs-table" style="margin-top:12px;">
				<thead>
					<tr><th>Meccanismo</th><th>Valore default</th><th>Filtro per personalizzare</th><th>Descrizione</th></tr>
				</thead>
				<tbody>
					<tr>
						<td><strong>Rate limit per IP</strong></td>
						<td><code><?php echo esc_html( DESIITSE_RL_MAX_REQUESTS ); ?> req / <?php echo esc_html( DESIITSE_RL_WINDOW_SECONDS ); ?>s</code></td>
						<td><code>desiitse_rl_max_requests</code><br><code>desiitse_rl_window_seconds</code></td>
						<td>Ogni IP può chiamare gli endpoint al massimo N volte nella finestra. Superato il limite → <strong>HTTP 429</strong>.</td>
					</tr>
					<tr>
						<td><strong>Global throttle</strong></td>
						<td><code><?php echo esc_html( DESIITSE_RL_GLOBAL_RPS ); ?> req/s</code></td>
						<td><code>desiitse_rl_global_rps</code></td>
						<td>Conta il totale delle richieste al plugin nell'ultimo secondo. Protegge da attacchi distribuiti su molti IP.</td>
					</tr>
					<tr>
						<td><strong>Whitelist IP</strong></td>
						<td><code>127.0.0.1, ::1</code></td>
						<td><code>desiitse_rl_whitelist_ips</code></td>
						<td>IP esenti da tutti i controlli (crawler istituzionali, monitoring interno).</td>
					</tr>
					<tr>
						<td><strong>Proxy / CDN</strong></td>
						<td>Disabilitato</td>
						<td><code>desiitse_rl_trust_proxy_headers</code></td>
						<td>Abilitare se il sito è dietro Cloudflare o un reverse proxy fidato per leggere l'IP reale.</td>
					</tr>
					<tr>
						<td><strong>Header risposta</strong></td>
						<td>Sempre inclusi</td>
						<td>—</td>
						<td><code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code>, <code>X-RateLimit-Reset</code>, <code>Retry-After</code> (solo sul 429).</td>
					</tr>
				</tbody>
			</table>
			<div class="dcs-notice dcs-notice-info" style="margin-top:16px;">
				💡 <strong>Esempio</strong> — per abbassare il limite a 30 req/minuto per IP, aggiungi nel <code>functions.php</code> del tema:<br>
				<code style="display:block;margin-top:6px;padding:8px 12px;background:#fff;border-radius:4px;font-size:12px;">add_filter( 'desiitse_rl_max_requests', fn() => 30 );<br>add_filter( 'desiitse_rl_window_seconds', fn() => 60 );</code>
			</div>
		</div>

		<!-- ── Riferimenti DTD ── -->
		<div class="dcs-card">
			<h2><span class="icon">🇮🇹</span> Riferimenti — Dipartimento per la Trasformazione Digitale</h2>

			<div class="dcs-dtd-block">
				<div class="dtd-icon">🏛</div>
				<div class="dtd-content">
					<h3>Designers Italia</h3>
					<p>Il punto di riferimento per il design dei servizi pubblici digitali italiani. Qui trovi i modelli, le linee guida e le risorse per i temi DCI e DSI.</p>
					<a href="https://designers.italia.it" target="_blank" rel="noopener">designers.italia.it →</a>
				</div>
			</div>

			<div class="dcs-dtd-block">
				<div class="dtd-icon">📐</div>
				<div class="dtd-content">
					<h3>schema.gov.it — Catalogo semantico nazionale</h3>
					<p>Catalogo ufficiale di ontologie, vocabolari controllati e profili applicativi per l'interoperabilità semantica della PA italiana. Questo plugin usa esclusivamente classi e proprietà presenti in questo catalogo.</p>
					<a href="https://schema.gov.it" target="_blank" rel="noopener">schema.gov.it →</a>
				</div>
			</div>

			<div class="dcs-dtd-block">
				<div class="dtd-icon">🏙</div>
				<div class="dtd-content">
					<h3>Tema Design Comuni Italia (DCI)</h3>
					<p>Il tema WordPress ufficiale per i siti dei Comuni italiani. Questo plugin legge i meta campi <code>_dci_*</code> registrati dal tema e li esporta come JSON-LD.</p>
					<a href="https://github.com/italia/design-comuni-wordpress-theme" target="_blank" rel="noopener">GitHub: design-comuni-wordpress-theme →</a>
				</div>
			</div>

			<div class="dcs-dtd-block">
				<div class="dtd-icon">🎓</div>
				<div class="dtd-content">
					<h3>Tema Design Scuole Italia (DSI)</h3>
					<p>Il tema WordPress ufficiale per i siti delle scuole italiane. Questo plugin legge i meta campi <code>_dsi_*</code> registrati dal tema e li esporta come JSON-LD.</p>
					<a href="https://github.com/italia/design-scuole-wordpress-theme" target="_blank" rel="noopener">GitHub: design-scuole-wordpress-theme →</a>
				</div>
			</div>

			<div class="dcs-dtd-block">
				<div class="dtd-icon">📦</div>
				<div class="dtd-content">
					<h3>Developers Italia</h3>
					<p>La community degli sviluppatori che lavorano ai servizi pubblici digitali italiani. Segnala bug, proponi miglioramenti e contribuisci al progetto.</p>
					<a href="https://developers.italia.it" target="_blank" rel="noopener">developers.italia.it →</a>
					&nbsp;·&nbsp;
					<a href="https://github.com/italia" target="_blank" rel="noopener">GitHub: italia →</a>
				</div>
			</div>

			<p style="font-size:12px;color:#888;margin-top:16px;">
				Plugin distribuito sotto licenza <strong>GPL-2.0-or-later</strong>.
				Non è un prodotto ufficiale del Dipartimento per la Trasformazione Digitale,
				ma è realizzato seguendo le linee guida e le ontologie ufficiali.
			</p>
		</div>

	</div>
	<?php
}
