<?php
/**
 * Documento → foaf:Document
 *
 * Route:
 *   DSI  → GET /wp-json/scuole/v1/graph/documenti
 *
 * @package Design_Italia_Semantic
 */
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	if ( ! desiitse_should_register_route( 'dsi' ) ) { return; }
	register_rest_route( 'scuole/v1', '/graph/documenti', [
		'methods'             => 'GET',
		'callback'            => 'desiitse_documento_graph_callback',
		'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
	] );
} );

function desiitse_documento_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'documenti', 'desiitse_build_documento_nodes' );
}

function desiitse_build_documento_nodes(): array {
	$documenti = get_posts( [ 'post_type' => 'documento', 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true ] );
	$nodes = [];
	foreach ( $documenti as $d ) {
		$doc = [
			'@type'         => 'foaf:Document',
			'@id'           => desiitse_node_id( $d ),
			'dct:title'     => desiitse_clean_text( get_the_title( $d ) ),
			'dct:publisher' => desiitse_school_ref(),
		];
		$exp = desiitse_normalize_datetime( get_post_meta( $d->ID, '_dsi_documento_data_scadenza', true ) );
		if ( $exp !== '' ) { $doc['ti:endTime'] = $exp; }
		$author_ids = desiitse_meta_ids( $d->ID, '_dsi_documento_autori' );
		if ( ! empty( $author_ids ) ) { $refs = array_values( array_filter( array_map( 'desiitse_mixed_entity_ref', $author_ids ) ) ); if ( ! empty( $refs ) ) { $doc['dct:creator'] = $refs; } }
		$sids = array_values( array_unique( array_filter( array_merge( desiitse_meta_ids( $d->ID, '_dsi_documento_servizi_collegati' ), desiitse_meta_ids( $d->ID, '_dsi_documento_link_servizi_didattici' ) ) ) ) );
		if ( ! empty( $sids ) ) {
			$doc['dct:relation'] = array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $sids );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $sids ) );
		}
		$struct_ids = desiitse_meta_ids_any( $d->ID, [
			'_dsi_documento_struttura_responsabile',
			'_dsi_documento_strutture_responsabili',
			'_dsi_documento_link_schede_strutture',
		] );
		if ( ! empty( $struct_ids ) ) {
			desiitse_add_ref_property( $doc, 'dct:publisher', array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $struct_ids ) );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $struct_ids ) );
		}
		$fns = desiitse_parse_file_items( $d->ID, '_dsi_documento_file_documenti' );
		if ( ! empty( $fns ) ) { $doc['dct:hasPart'] = array_map( fn( $fn ) => [ '@id' => $fn['@id'] ], $fns ); foreach ( $fns as $fn ) { $nodes[] = $fn; } }
		$amm = desiitse_clean_text( get_post_meta( $d->ID, '_dsi_documento_amministrazione_trasparente', true ) );
		if ( $amm !== '' ) { $doc['dct:subject'] = $amm; }
		$nodes[] = $doc;
	}
	return desiitse_unique_nodes( $nodes );
}
