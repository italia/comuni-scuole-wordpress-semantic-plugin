<?php
/**
 * Grafo aggregato DCI — GET /wp-json/comuni/v1/graph
 * Aggrega tutte le entità DCI sotto il namespace /comuni/v1/
 * @package Design_Italia_Semantic
 */
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	if ( ! desiitse_should_register_route( 'dci' ) ) { return; }
	register_rest_route( 'comuni/v1', '/graph', [
		'methods'             => 'GET',
		'callback'            => 'desiitse_dci_graph_callback',
		'permission_callback' => '__return_true', // Intentional public endpoint: serves already-public site data
	] );
} );

function desiitse_dci_graph_callback( WP_REST_Request $request ): WP_REST_Response {
	return desiitse_cached_response( 'comuni-graph', 'desiitse_build_dci_nodes' );
}

function desiitse_build_dci_nodes(): array {
	$site = [
		'@type'         => 'foaf:Document',
		'@id'           => home_url( '/' ),
		'dct:title'     => get_bloginfo( 'name' ),
		'dct:publisher' => desiitse_comune_ref(),
	];
	$logo = get_theme_mod( 'custom_logo' );
	if ( $logo ) { $url = wp_get_attachment_image_url( (int) $logo, 'full' ); if ( $url ) { $site['sm:hasImage'] = [ '@id' => $url ]; } }
	return desiitse_unique_nodes( array_merge(
		[ $site, desiitse_build_comune_node() ],
		desiitse_build_servizio_nodes( 'dci' ),
		desiitse_build_unita_nodes(),
		desiitse_build_luogo_nodes( 'dci' ),
		desiitse_build_evento_nodes( 'dci' ),
		desiitse_build_dataset_nodes(),
		desiitse_build_persona_nodes()
	) );
}
