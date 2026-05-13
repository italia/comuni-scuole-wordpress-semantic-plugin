<?php
/** REST: dataset → DCAT-AP_IT Dataset (DCI) — GET /wp-json/comuni/v1/graph/dataset */
defined( 'ABSPATH' ) || exit;
add_action( 'rest_api_init', function () {
	if ( ! desiitse_should_register_route( 'dci' ) ) { return; }
	register_rest_route( 'comuni/v1', '/graph/dataset', [ 'methods' => 'GET', 'callback' => 'desiitse_dataset_graph_callback', 'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
	] );
} );
function desiitse_dataset_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'dataset', 'desiitse_build_dataset_nodes' );
}
function desiitse_build_dataset_nodes(): array {
	$datasets = get_posts( [ 'post_type' => 'dataset', 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true ] );
	$nodes    = [];
	foreach ( $datasets as $d ) {
		$node  = [ '@type' => 'dcatapit:Dataset', '@id' => desiitse_node_id( $d ), 'dct:title' => desiitse_clean_text( get_the_title( $d ) ) ];
		desiitse_attach_primary_organization( $node, 'dci' );
		$descr = desiitse_meta_text( $d->ID, '_dci_dataset_descrizione_breve' );
		if ( $descr !== '' ) { $node['dct:description'] = $descr; }
		$dists = desiitse_parse_distributions( $d->ID, '_dci_dataset_distribuzione' );
		if ( ! empty( $dists ) ) {
			$node['dcat:distribution'] = array_map( fn( $dist ) => [ '@id' => $dist['@id'] ], $dists );
			foreach ( $dists as $dist ) { $nodes[] = $dist; }
		}
		$nodes[] = $node;
	}
	return desiitse_unique_nodes( $nodes );
}
