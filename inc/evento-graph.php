<?php
/**
 * Evento → CPEV PublicEvent
 *
 * Route:
 *   DCI  → GET /wp-json/comuni/v1/graph/eventi
 *   DSI  → GET /wp-json/scuole/v1/graph/eventi
 *
 * @package Design_Italia_Semantic
 */
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	if ( desiitse_should_register_route( 'dci' ) ) {
		register_rest_route( 'comuni/v1', '/graph/eventi', [
			'methods'             => 'GET',
			'callback'            => fn( $r ) => desiitse_cached_response( 'eventi-dci', fn() => desiitse_build_evento_nodes( 'dci' ) ),
			'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
		] );
	}
	if ( desiitse_should_register_route( 'dsi' ) ) {
		register_rest_route( 'scuole/v1', '/graph/eventi', [
			'methods'             => 'GET',
			'callback'            => fn( $r ) => desiitse_cached_response( 'eventi-dsi', fn() => desiitse_build_evento_nodes( 'dsi' ) ),
			'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
		] );
	}
} );

function desiitse_evento_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'eventi-dci', fn() => desiitse_build_evento_nodes( 'dci' ) );
}

function desiitse_build_evento_nodes( string $profile = 'dci' ): array {
	$eventi = get_posts( [ 'post_type' => 'evento', 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true ] );
	$nodes  = [];
	foreach ( $eventi as $e ) {
		$title = desiitse_clean_text( get_the_title( $e ) );
		$node  = [ '@type' => 'cpev:PublicEvent', '@id' => desiitse_node_id( $e ), 'dct:title' => $title, 'cpev:eventTitle' => $title ];
		$start = ''; $end = '';
		$place_ids = [];
		if ( $profile === 'dci' ) {
			$ev = desiitse_clean_text( get_post_meta( $e->ID, '_dci_evento_nome', true ) );
			if ( $ev !== '' ) { $node['cpev:eventTitle'] = $ev; }
			$start    = desiitse_normalize_datetime( get_post_meta( $e->ID, '_dci_evento_data_orario_inizio', true ) );
			$end      = desiitse_normalize_datetime( get_post_meta( $e->ID, '_dci_evento_data_orario_fine',   true ) );
			$url      = esc_url_raw( get_post_meta( $e->ID, '_dci_evento_url', true ) );
			$abstract = desiitse_meta_text( $e->ID, '_dci_evento_descrizione_breve' );
			$target   = desiitse_meta_text( $e->ID, '_dci_evento_a_chi_e_rivolto' );
			$place_ids = desiitse_meta_ids_any( $e->ID, [
				'_dci_evento_luogo',
				'_dci_evento_luoghi',
				'_dci_evento_sede',
				'_dci_evento_sedi',
				'_dci_evento_link_schede_luoghi',
			] );
			if ( $url !== '' )      { $node['sm:URL']             = $url; }
			if ( $abstract !== '' ) { $node['cpev:eventAbstract'] = $abstract; }
			if ( $target !== '' )   { $aid = desiitse_node_id( $e, '#audience' ); $node['cpev:targetAudience'] = [ '@id' => $aid ]; $nodes[] = [ '@type' => 'cpev:Audience', '@id' => $aid, 'l0:description' => $target ]; }
			desiitse_attach_primary_organization( $node, $profile );
			desiitse_add_descriptions( $node, [ desiitse_meta_text( $e->ID, '_dci_evento_descrizione_completa' ), desiitse_meta_text( $e->ID, '_dci_evento_ulteriori_informazioni' ) ] );
		}
		if ( $profile === 'dsi' ) {
			$start = desiitse_normalize_datetime( get_post_meta( $e->ID, '_dsi_evento_timestamp_inizio', true ) );
			$end   = desiitse_normalize_datetime( get_post_meta( $e->ID, '_dsi_evento_timestamp_fine',   true ) );
			$node['dct:publisher'] = desiitse_school_ref();
			desiitse_attach_primary_organization( $node, $profile );
			$place_ids = desiitse_meta_ids_any( $e->ID, [
				'_dsi_evento_luogo',
				'_dsi_evento_luoghi',
				'_dsi_evento_sede',
				'_dsi_evento_sedi',
				'_dsi_evento_link_schede_luoghi',
			] );
		}
		if ( $start !== '' ) { $node['ti:startTime'] = $start; }
		if ( $end   !== '' ) { $node['ti:endTime']   = $end;   }
		if ( ! empty( $place_ids ) ) {
			$node['cpev:takesPlaceIn'] = array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $place_ids );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $place_ids ) );
		}
		$nodes[] = $node;
	}
	return desiitse_unique_nodes( $nodes );
}
