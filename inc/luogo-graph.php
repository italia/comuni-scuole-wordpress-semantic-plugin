<?php
/**
 * Luogo → POI PointOfInterest + CLV Feature + CLV Address
 *
 * Route:
 *   DCI  → GET /wp-json/comuni/v1/graph/luoghi   (profilo _dci_)
 *   DSI  → GET /wp-json/scuole/v1/graph/luoghi    (profilo _dsi_)
 *   both → entrambe le route registrate
 *
 * @package Design_Italia_Semantic
 */
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	if ( desiitse_should_register_route( 'dci' ) ) {
		register_rest_route( 'comuni/v1', '/graph/luoghi', [
			'methods'             => 'GET',
			'callback'            => fn( $r ) => desiitse_cached_response( 'luoghi-dci', fn() => desiitse_build_luogo_nodes( 'dci' ) ),
			'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
		] );
	}
	if ( desiitse_should_register_route( 'dsi' ) ) {
		register_rest_route( 'scuole/v1', '/graph/luoghi', [
			'methods'             => 'GET',
			'callback'            => fn( $r ) => desiitse_cached_response( 'luoghi-dsi', fn() => desiitse_build_luogo_nodes( 'dsi' ) ),
			'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
		] );
	}
} );

function desiitse_luogo_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'luoghi-dci', fn() => desiitse_build_luogo_nodes( 'dci' ) );
}

function desiitse_build_luogo_nodes( string $profile = 'dci' ): array {
	$luoghi = get_posts( [ 'post_type' => 'luogo', 'posts_per_page' => -1, 'post_status' => 'publish', 'no_found_rows' => true ] );
	$nodes  = [];
	foreach ( $luoghi as $l ) {
		$title = desiitse_clean_text( get_the_title( $l ) );
		$addr  = desiitse_clean_text( get_post_meta( $l->ID, '_dci_luogo_indirizzo',        true ) );
		$cap   = desiitse_clean_text( get_post_meta( $l->ID, '_dci_luogo_cap',               true ) );
		$gps   = maybe_unserialize( get_post_meta( $l->ID, '_dci_luogo_posizione_gps', true ) );
		$poi_id = desiitse_node_id( $l ); $feature_id = desiitse_node_id( $l, '#feature' );
		$poi     = [ '@type' => 'poi:PointOfInterest', '@id' => $poi_id,     'dct:title' => $title, 'poi:POIofficialName' => $title, 'owl:sameAs' => [ '@id' => $feature_id ] ];
		$feature = [ '@type' => 'clv:Feature',          '@id' => $feature_id, 'dct:title' => $title, 'owl:sameAs' => [ '@id' => $poi_id ] ];
		desiitse_attach_primary_organization( $poi, $profile );
		if ( $profile === 'dci' ) {
			$descr   = desiitse_clean_text( get_post_meta( $l->ID, '_dci_luogo_descrizione_breve',      true ) );
			$descr_e = desiitse_clean_text( get_post_meta( $l->ID, '_dci_luogo_descrizione_estesa',     true ) );
			$info    = desiitse_clean_text( get_post_meta( $l->ID, '_dci_luogo_ulteriori_informazioni', true ) );
			$accesso = desiitse_clean_text( get_post_meta( $l->ID, '_dci_luogo_modalita_accesso',       true ) );
			if ( $descr !== '' ) { $poi['poi:POIdescription'] = $descr; }
			desiitse_add_descriptions( $poi, [ $descr_e, $info ] );
			if ( $accesso !== '' ) { $aid = desiitse_node_id( $l, '#access-condition' ); $poi['access:hasAccessCondition'] = [ '@id' => $aid ]; $nodes[] = [ '@type' => 'access:AccessCondition', '@id' => $aid, 'l0:description' => $accesso ]; }
			$pid = (int) get_post_meta( $l->ID, '_dci_luogo_childof', true );
			if ( $pid > 0 ) { $poi['poi:isIncludedInPOI'] = [ '@id' => desiitse_node_id( $pid ) ]; }
		}
		if ( $profile === 'dsi' ) {
			$pids = desiitse_meta_ids( $l->ID, '_dsi_luogo_childof' );
			if ( ! empty( $pids ) ) { $poi['poi:isIncludedInPOI'] = array_map( fn( $p ) => [ '@id' => desiitse_node_id( $p ) ], $pids ); }
			$sids = desiitse_meta_ids( $l->ID, '_dsi_luogo_servizi_presenti' );
			if ( ! empty( $sids ) ) { $feature['cpsv:isPhysicalLocationOfService'] = array_map( fn( $s ) => [ '@id' => desiitse_node_id( $s ) ], $sids ); }
			desiitse_attach_primary_organization( $feature, $profile );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( array_merge( $pids, $sids ) ) );
		}
		if ( $addr !== '' || $cap !== '' ) {
			$aid = desiitse_node_id( $l, '#address' );
			$address = [ '@type' => 'clv:Address', '@id' => $aid, 'owl:sameAs' => [ '@id' => $poi_id ] ];
			if ( $addr !== '' ) { $address['clv:officialStreetName'] = $addr; }
			if ( $cap  !== '' ) { $address['clv:postCode']           = $cap;  }
			$feature['clv:hasAddress'] = [ '@id' => $aid ]; $nodes[] = $address;
		}
		if ( is_array( $gps ) && isset( $gps['lat'], $gps['lng'] ) ) { $feature['clv:lat'] = (float) $gps['lat']; $feature['clv:long'] = (float) $gps['lng']; }
		$nodes[] = $poi; $nodes[] = $feature;
	}
	return desiitse_unique_nodes( $nodes );
}
