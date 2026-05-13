<?php
/** REST: struttura → COV Organization (DSI) — GET /wp-json/scuole/v1/graph/strutture */
defined( 'ABSPATH' ) || exit;
add_action( 'rest_api_init', function () {
	if ( ! desiitse_should_register_route( 'dsi' ) ) { return; }
	register_rest_route( 'scuole/v1', '/graph/strutture', [ 'methods' => 'GET', 'callback' => 'desiitse_struttura_graph_callback', 'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
	] );
} );
function desiitse_struttura_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'strutture', 'desiitse_build_struttura_nodes' );
}
function desiitse_build_struttura_nodes(): array {
	$strutture = get_posts( [ 'post_type' => 'struttura', 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true ] );
	$nodes = [];
	foreach ( $strutture as $s ) {
		$title = desiitse_clean_text( get_the_title( $s ) );
		$node  = [
			'@type'               => 'cov:Organization',
			'@id'                 => desiitse_node_id( $s ),
			'dct:title'           => $title,
			'cov:legalName'       => $title,
			'cov:hasOrganization' => desiitse_school_ref(),
		];
		$pids = desiitse_meta_ids( $s->ID, '_dsi_struttura_childof' );
		if ( ! empty( $pids ) ) { $node['cov:subOrganizationOf'] = array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $pids ); }
		$lids = array_values( array_unique( array_filter( array_merge( desiitse_meta_ids( $s->ID, '_dsi_struttura_sedi' ), desiitse_meta_ids( $s->ID, '_dsi_struttura_link_schede_luoghi' ) ) ) ) );
		if ( ! empty( $lids ) ) {
			$node['dct:spatial'] = array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $lids );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $lids ) );
		}
		$sids = array_values( array_unique( array_filter( array_merge( desiitse_meta_ids( $s->ID, '_dsi_struttura_link_schede_servizi' ), desiitse_meta_ids( $s->ID, '_dsi_struttura_link_servizi_didattici' ) ) ) ) );
		if ( ! empty( $sids ) ) {
			$node['dct:relation'] = array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $sids );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $sids ) );
		}
		$prids = desiitse_meta_ids( $s->ID, '_dsi_struttura_link_schede_progetti' );
		if ( ! empty( $prids ) ) {
			$node['proj:hasParticipantingAgent'] = array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $prids );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $prids ) );
		}
		$nodes[] = $node;
	}
	return desiitse_unique_nodes( $nodes );
}
