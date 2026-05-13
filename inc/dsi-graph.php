<?php
/**
 * Grafo aggregato DSI — GET /wp-json/scuole/v1/graph
 * Aggrega tutte le entità DSI sotto il namespace /scuole/v1/
 * @package Design_Italia_Semantic
 */
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	if ( ! desiitse_should_register_route( 'dsi' ) ) { return; }
	register_rest_route( 'scuole/v1', '/graph', [
		'methods'             => 'GET',
		'callback'            => 'desiitse_dsi_graph_callback',
		'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
	] );
} );

function desiitse_dsi_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'scuole-graph', 'desiitse_build_dsi_nodes' );
}

function desiitse_build_dsi_nodes(): array {
	$site = [
		'@type'         => 'foaf:Document',
		'@id'           => home_url( '/scuola/' ),
		'dct:title'     => get_bloginfo( 'name' ) . ' - Scuola',
		'dct:publisher' => desiitse_school_ref(),
	];
	return desiitse_unique_nodes( array_merge(
		[ $site, desiitse_build_school_node() ],
		desiitse_build_servizio_nodes( 'dsi' ),
		desiitse_build_struttura_nodes(),
		desiitse_build_luogo_nodes( 'dsi' ),
		desiitse_build_evento_nodes( 'dsi' ),
		desiitse_build_documento_nodes()
	) );
}
