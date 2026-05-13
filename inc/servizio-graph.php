<?php
/**
 * Servizio → CPSV PublicService
 *
 * Route:
 *   DCI  → GET /wp-json/comuni/v1/graph/servizi
 *   DSI  → GET /wp-json/scuole/v1/graph/servizi
 *
 * @package Design_Italia_Semantic
 */
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	if ( desiitse_should_register_route( 'dci' ) ) {
		register_rest_route( 'comuni/v1', '/graph/servizi', [
			'methods'             => 'GET',
			'callback'            => fn( $r ) => desiitse_cached_response( 'servizi-dci', fn() => desiitse_build_servizio_nodes( 'dci' ) ),
			'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
		] );
	}
	if ( desiitse_should_register_route( 'dsi' ) ) {
		register_rest_route( 'scuole/v1', '/graph/servizi', [
			'methods'             => 'GET',
			'callback'            => fn( $r ) => desiitse_cached_response( 'servizi-dsi', fn() => desiitse_build_servizio_nodes( 'dsi' ) ),
			'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
		] );
	}
} );

function desiitse_servizio_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'servizi-dci', fn() => desiitse_build_servizio_nodes( 'dci' ) );
}

function desiitse_build_servizio_nodes( string $profile = 'dci' ): array {
	$servizi = get_posts( [ 'post_type' => 'servizio', 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true ] );
	$nodes   = [];
	foreach ( $servizi as $s ) {
		$node = [ '@type' => 'cpsv:PublicService', '@id' => desiitse_node_id( $s ), 'dct:title' => desiitse_clean_text( get_the_title( $s ) ) ];
		$luogo_ids = [];
		$office_ids = [];
		if ( $profile === 'dci' ) {
			$db = desiitse_meta_text( $s->ID, '_dci_servizio_descrizione_breve' );
			$de = desiitse_meta_text( $s->ID, '_dci_servizio_descrizione_estesa' );
			$dest = desiitse_meta_text( $s->ID, '_dci_servizio_a_chi_e_rivolto' );
			$come = desiitse_meta_text( $s->ID, '_dci_servizio_come_fare' );
			$cosa = desiitse_meta_text( $s->ID, '_dci_servizio_cosa_serve_introduzione' );
			$out  = desiitse_meta_text( $s->ID, '_dci_servizio_output' );
			$temp = desiitse_meta_text( $s->ID, '_dci_servizio_tempi_text' );
			$info = desiitse_meta_text( $s->ID, '_dci_servizio_ulteriori_informazioni' );
			$procs = desiitse_meta_ids( $s->ID, '_dci_servizio_procedure_collegate' );
			$luogo_ids = desiitse_meta_ids( $s->ID, '_dci_servizio_luoghi' );
			$office_ids = desiitse_meta_ids_any( $s->ID, [
				'_dci_servizio_unita_organizzativa',
				'_dci_servizio_unita_organizzative',
				'_dci_servizio_ufficio_responsabile',
				'_dci_servizio_uffici_responsabili',
				'_dci_servizio_struttura_responsabile',
				'_dci_servizio_strutture_responsabili',
				'_dci_servizio_link_schede_unita_organizzative',
			] );
			if ( $db !== '' ) { $node['dct:description'] = $db; }
			$node['dct:publisher'] = desiitse_comune_ref();
			desiitse_add_descriptions( $node, [ $de, $dest, $info ] );
			if ( $come !== '' ) { $node['cpsv:referenceDoc'] = $come; }
			if ( $cosa !== '' ) { $iid = desiitse_node_id( $s, '#input' );           $node['cpsv:hasInput']          = [ '@id' => $iid ]; $nodes[] = [ '@type' => 'cpsv:Input',                  '@id' => $iid, 'l0:description' => $cosa ]; }
			if ( $out  !== '' ) { $oid = desiitse_node_id( $s, '#output' );          $node['cpsv:producesOutput']    = [ '@id' => $oid ]; $nodes[] = [ '@type' => 'cpsv:Output',                 '@id' => $oid, 'l0:description' => $out  ]; }
			if ( $temp !== '' ) { $tid = desiitse_node_id( $s, '#processing-time' ); $node['cpsv:hasProcessingTime'] = [ '@id' => $tid ]; $nodes[] = [ '@type' => 'cpsv:ServiceProcessingTime', '@id' => $tid, 'l0:description' => $temp ]; }
			if ( ! empty( $procs ) ) { $node['cpsv:relationService'] = array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $procs ); }
			if ( ! empty( $office_ids ) ) {
				desiitse_add_ref_property( $node, 'dct:relation', array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $office_ids ) );
				$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $office_ids ) );
			}
		}
		if ( $profile === 'dsi' ) {
			$luogo_ids  = desiitse_meta_ids( $s->ID, '_dsi_servizio_luoghi' );
			$struct_ids = desiitse_meta_ids( $s->ID, '_dsi_servizio_struttura_responsabile' );
			$doc_ids    = desiitse_meta_ids( $s->ID, '_dsi_servizio_link_schede_documenti' );
			$proj_ids   = desiitse_meta_ids( $s->ID, '_dsi_servizio_link_schede_progetti' );
			$node['dct:publisher'] = desiitse_school_ref();
			if ( ! empty( $struct_ids ) ) {
				desiitse_add_ref_property( $node, 'dct:publisher', array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $struct_ids ) );
				$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $struct_ids ) );
			}
			$rel = array_merge( array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $doc_ids ), array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $proj_ids ) );
			if ( ! empty( $rel ) ) { $node['dct:relation'] = $rel; }
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( array_merge( $doc_ids, $proj_ids ) ) );
		}
		$luogo_ids = array_values( array_unique( array_filter( $luogo_ids ) ) );
		if ( ! empty( $luogo_ids ) ) {
			$node['cpsv:isPhysicallyAvailableAt'] = array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id, '#feature' ) ], $luogo_ids );
			if ( in_array( $profile, [ 'dci', 'dsi' ], true ) ) {
				$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $luogo_ids ) );
			}
		}
		$nodes[] = $node;
	}
	return desiitse_unique_nodes( $nodes );
}
