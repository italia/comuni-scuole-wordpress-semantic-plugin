<?php
/** REST: unita_organizzativa → COV Office (DCI) — GET /wp-json/comuni/v1/graph/unita-organizzative */
defined( 'ABSPATH' ) || exit;
add_action( 'rest_api_init', function () {
	if ( ! desiitse_should_register_route( 'dci' ) ) { return; }
	register_rest_route( 'comuni/v1', '/graph/unita-organizzative', [ 'methods' => 'GET', 'callback' => 'desiitse_unita_organizzativa_graph_callback', 'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
	] );
} );
function desiitse_unita_organizzativa_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'unita-organizzative', 'desiitse_build_unita_nodes' );
}
function desiitse_build_unita_nodes(): array {
	$uffici = get_posts( [ 'post_type' => 'unita_organizzativa', 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true ] );
	$nodes  = [];
	foreach ( $uffici as $u ) {
		$title = desiitse_clean_text( get_the_title( $u ) );
		$comp  = desiitse_meta_text( $u->ID, '_dci_unita_organizzativa_competenze' );
		$node  = [
			'@type'               => 'cov:Office',
			'@id'                 => desiitse_node_id( $u ),
			'dct:title'           => $title,
			'cov:legalName'       => $title,
			'cov:hasOrganization' => desiitse_comune_ref(),
		];
		if ( $comp !== '' ) { $node['cov:mainFunction'] = $comp; }
		desiitse_add_descriptions( $node, [ desiitse_meta_text( $u->ID, '_dci_unita_organizzativa_descrizione_breve' ), desiitse_meta_text( $u->ID, '_dci_unita_organizzativa_ulteriori_informazioni' ) ] );
		$nodes[] = $node;
	}
	return $nodes;
}
