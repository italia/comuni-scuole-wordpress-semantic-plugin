<?php
/** REST: persona_pubblica → CPV Person (DCI) — GET /wp-json/comuni/v1/graph/persona-pubblica */
defined( 'ABSPATH' ) || exit;
add_action( 'rest_api_init', function () {
	if ( ! desiitse_should_register_route( 'dci' ) ) { return; }
	register_rest_route( 'comuni/v1', '/graph/persona-pubblica', [ 'methods' => 'GET', 'callback' => 'desiitse_persona_graph_callback', 'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
	] );
} );
function desiitse_persona_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'persona-pubblica', 'desiitse_build_persona_nodes' );
}
function desiitse_build_persona_nodes(): array {
	$persone = get_posts( [ 'post_type' => 'persona_pubblica', 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true ] );
	$nodes = [];
	foreach ( $persone as $p ) {
		$nome    = desiitse_clean_text( get_post_meta( $p->ID, '_dci_persona_pubblica_nome',    true ) );
		$cognome = desiitse_clean_text( get_post_meta( $p->ID, '_dci_persona_pubblica_cognome', true ) );
		$node = [ '@type' => 'cpv:Person', '@id' => desiitse_node_id( $p ), 'dct:title' => desiitse_clean_text( get_the_title( $p ) ) ];
		$org_ids = desiitse_meta_ids_any( $p->ID, [
			'_dci_persona_pubblica_unita_organizzativa',
			'_dci_persona_pubblica_unita_organizzative',
			'_dci_persona_pubblica_ufficio',
			'_dci_persona_pubblica_uffici',
			'_dci_persona_pubblica_struttura',
			'_dci_persona_pubblica_strutture',
			'_dci_persona_pubblica_organizzazione',
			'_dci_persona_pubblica_organizzazioni',
		] );
		if ( $nome !== '' )    { $node['cpv:givenName']  = $nome; }
		if ( $cognome !== '' ) { $node['cpv:familyName'] = $cognome; }
		desiitse_add_descriptions( $node, [ desiitse_meta_text( $p->ID, '_dci_persona_pubblica_descrizione_breve' ), desiitse_meta_text( $p->ID, '_dci_persona_pubblica_competenze' ), desiitse_meta_text( $p->ID, '_dci_persona_pubblica_ulteriori_informazioni' ) ] );
		if ( ! empty( $org_ids ) ) {
			desiitse_add_ref_property( $node, 'cov:hasOrganization', array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $org_ids ) );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $org_ids ) );
		} else {
			desiitse_attach_primary_organization( $node, 'dci' );
		}
		$nodes[] = $node;
	}
	return desiitse_unique_nodes( $nodes );
}
