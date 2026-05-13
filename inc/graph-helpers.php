<?php
/**
 * Helpers condivisi per tutti gli esportatori JSON-LD.
 *
 * @package Design_Comuni_Semantic
 */

defined( 'ABSPATH' ) || exit;

function desiitse_context(): array {
	return [
		'owl'      => 'http://www.w3.org/2002/07/owl#',
		'rdf'      => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
		'rdfs'     => 'http://www.w3.org/2000/01/rdf-schema#',
		'dct'      => 'http://purl.org/dc/terms/',
		'dcat'     => 'http://www.w3.org/ns/dcat#',
		'dcatapit' => 'http://dati.gov.it/onto/dcatapit#',
		'l0'       => 'https://w3id.org/italia/onto/l0/',
		'ti'       => 'https://w3id.org/italia/onto/TI/',
		'cpv'      => 'https://w3id.org/italia/onto/CPV/',
		'cov'      => 'https://w3id.org/italia/onto/COV/',
		'cpsv'     => 'https://w3id.org/italia/onto/CPSV/',
		'cpev'     => 'https://w3id.org/italia/onto/CPEV/',
		'poi'      => 'https://w3id.org/italia/onto/POI/',
		'clv'      => 'https://w3id.org/italia/onto/CLV/',
		'proj'     => 'https://w3id.org/italia/onto/Project/',
		'sm'       => 'https://w3id.org/italia/onto/SM/',
		'access'   => 'https://w3id.org/italia/onto/AccessCondition/',
		'foaf'     => 'http://xmlns.com/foaf/0.1/',
	];
}

function desiitse_base_node_uri( $post_or_id ): string {
	$post = is_object( $post_or_id ) ? $post_or_id : get_post( (int) $post_or_id );
	if ( ! $post instanceof WP_Post ) {
		return home_url( '/?p=' . (int) $post_or_id );
	}

	$slug = $post->post_name ?: (string) $post->ID;

	switch ( $post->post_type ) {
		case 'unita_organizzativa':
			return home_url( '/amministrazione/unita_organizzativa/' . $slug . '/' );
		case 'persona_pubblica':
			return home_url( '/persona_pubblica/' . $slug . '/' );
		case 'servizio':
			return home_url( '/servizio/' . $slug . '/' );
		case 'evento':
			return home_url( '/vivere-il-comune/eventi/' . $slug . '/' );
		case 'luogo':
			return home_url( '/vivere-il-comune/luoghi/' . $slug . '/' );
		default:
			$permalink = get_permalink( $post->ID );
			return $permalink ? $permalink : home_url( '/?p=' . $post->ID );
	}
}

function desiitse_node_id( $post_or_id, string $suffix = '' ): string {
	return desiitse_base_node_uri( $post_or_id ) . $suffix;
}

function desiitse_mixed_entity_ref( int $id ): ?array {
	if ( $id <= 0 ) { return null; }
	$post = get_post( $id );
	if ( $post instanceof WP_Post ) { return [ '@id' => desiitse_node_id( $post ) ]; }
	$user = get_userdata( $id );
	if ( $user ) { return [ '@id' => home_url( '/author/' . $user->user_nicename ) ]; }
	return null;
}

function desiitse_clean_text( $value ): string {
	if ( is_array( $value ) ) { $value = implode( ' ', array_map( 'desiitse_clean_text', $value ) ); }
	$value = wp_strip_all_tags( (string) $value, true );
	$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	$value = preg_replace( '/\s+/u', ' ', $value );
	return trim( $value );
}

function desiitse_meta_ids_any( int $post_id, array $meta_keys ): array {
	$ids = [];
	foreach ( $meta_keys as $meta_key ) {
		$ids = array_merge( $ids, desiitse_meta_ids( $post_id, $meta_key ) );
	}
	return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
}

function desiitse_meta_text( int $post_id, string $meta_key ): string {
	$raw = get_post_meta( $post_id, $meta_key, true );
	if ( is_numeric( $raw ) && (int) $raw > 0 ) {
		$linked = get_post( (int) $raw );
		if ( $linked instanceof WP_Post ) {
			$content = desiitse_clean_text( $linked->post_content );
			return $content !== '' ? $content : desiitse_clean_text( $linked->post_title );
		}
	}
	return desiitse_clean_text( $raw );
}

function desiitse_normalize_datetime( $value ): string {
	if ( is_array( $value ) ) { return ''; }
	$value = trim( (string) $value );
	if ( $value === '' ) { return ''; }
	if ( ctype_digit( $value ) && (int) $value > 0 ) { return gmdate( 'c', (int) $value ); }
	return $value;
}

function desiitse_add_descriptions( array &$node, array $values ): void {
	$values = array_values( array_filter( array_map( 'desiitse_clean_text', $values ) ) );
	if ( empty( $values ) ) { return; }
	$node['l0:description'] = count( $values ) === 1 ? $values[0] : $values;
}

function desiitse_meta_ids( int $post_id, string $meta_key ): array {
	$raw = maybe_unserialize( get_post_meta( $post_id, $meta_key, true ) );
	if ( empty( $raw ) ) { return []; }
	if ( is_numeric( $raw ) ) { return [ (int) $raw ]; }
	if ( is_string( $raw ) ) { $raw = array_filter( preg_split( '/[,\s|;]+/', $raw ) ); }
	if ( ! is_array( $raw ) ) { return []; }
	$ids = [];
	array_walk_recursive( $raw, function ( $item ) use ( &$ids ) {
		if ( is_numeric( $item ) && (int) $item > 0 ) { $ids[] = (int) $item; }
	} );
	return array_values( array_unique( $ids ) );
}

function desiitse_parse_file_items( int $post_id, string $meta_key, string $type = 'foaf:Document' ): array {
	$raw = maybe_unserialize( get_post_meta( $post_id, $meta_key, true ) );
	if ( empty( $raw ) ) { return []; }
	$items = is_array( $raw ) ? array_values( $raw ) : [ $raw ];
	$nodes = [];
	foreach ( $items as $i => $item ) {
		$node = [ '@type' => $type, '@id' => desiitse_node_id( $post_id, '#file-' . ( $i + 1 ) ) ];
		if ( is_string( $item ) ) {
			$url = esc_url_raw( $item );
			$url ? $node['sm:URL'] = $url : $node['dct:title'] = desiitse_clean_text( $item );
		} elseif ( is_array( $item ) ) {
			foreach ( [ 'title','label','name','titolo' ] as $k )           { if ( ! empty( $item[$k] ) ) { $node['dct:title']       = desiitse_clean_text( $item[$k] ); break; } }
			foreach ( [ 'description','descrizione' ] as $k )               { if ( ! empty( $item[$k] ) ) { $node['dct:description']  = desiitse_clean_text( $item[$k] ); break; } }
			foreach ( [ 'format','mime_type','mime','tipo' ] as $k )        { if ( ! empty( $item[$k] ) ) { $node['dct:format']       = desiitse_clean_text( $item[$k] ); break; } }
			foreach ( [ 'url','download_url','file','link','href' ] as $k ) { if ( ! empty( $item[$k] ) ) { $url = esc_url_raw( $item[$k] ); if ( $url ) { $node['sm:URL'] = $url; } break; } }
		}
		if ( count( $node ) > 2 ) { $nodes[] = $node; }
	}
	return $nodes;
}

function desiitse_parse_distributions( int $post_id, string $meta_key ): array {
	$raw = maybe_unserialize( get_post_meta( $post_id, $meta_key, true ) );
	if ( empty( $raw ) ) { return []; }
	$items = is_array( $raw ) ? array_values( $raw ) : [ $raw ];
	$nodes = [];
	foreach ( $items as $i => $item ) {
		$dist = [ '@type' => 'dcatapit:Distribution', '@id' => desiitse_node_id( $post_id, '#distribution-' . ( $i + 1 ) ) ];
		if ( is_string( $item ) ) {
			$url = esc_url_raw( $item );
			$url ? $dist['dcat:downloadURL'] = [ '@id' => $url ] : $dist['dct:title'] = desiitse_clean_text( $item );
		} elseif ( is_array( $item ) ) {
			foreach ( [ 'title','label','name','titolo' ] as $k )           { if ( ! empty( $item[$k] ) ) { $dist['dct:title']       = desiitse_clean_text( $item[$k] ); break; } }
			foreach ( [ 'description','descrizione' ] as $k )               { if ( ! empty( $item[$k] ) ) { $dist['dct:description']  = desiitse_clean_text( $item[$k] ); break; } }
			foreach ( [ 'format','mime_type','mime','tipo' ] as $k )        { if ( ! empty( $item[$k] ) ) { $dist['dct:format']       = desiitse_clean_text( $item[$k] ); break; } }
			foreach ( [ 'url','download_url','file','link','href' ] as $k ) { if ( ! empty( $item[$k] ) ) { $url = esc_url_raw( $item[$k] ); if ( $url ) { $dist['dcat:downloadURL'] = [ '@id' => $url ]; } break; } }
		}
		if ( count( $dist ) > 2 ) { $nodes[] = $dist; }
	}
	return $nodes;
}

function desiitse_comune_id(): string {
	$ipa = desiitse_get_ipa_code();
	return ( $ipa !== null && $ipa !== '' ) ? 'urn:x-italian-pa:' . $ipa : home_url( '/' );
}

function desiitse_comune_ref(): array {
	return [ '@id' => desiitse_comune_id() ];
}

function desiitse_comune_data(): array {
	$data = [
		'title'       => desiitse_clean_text( get_bloginfo( 'name' ) ),
		'legal_name'  => desiitse_clean_text( get_bloginfo( 'name' ) ),
		'tax_code'    => '',
		'acronym'     => '',
	];

	$data = apply_filters( 'desiitse_comune_data', $data );

	return [
		'title'      => desiitse_clean_text( $data['title'] ?? '' ),
		'legal_name' => desiitse_clean_text( $data['legal_name'] ?? '' ),
		'tax_code'   => desiitse_clean_text( $data['tax_code'] ?? '' ),
		'acronym'    => desiitse_clean_text( $data['acronym'] ?? '' ),
	];
}

function desiitse_build_comune_node(): array {
	$data = desiitse_comune_data();
	$node = [
		'@type'         => 'cov:PublicOrganization',
		'@id'           => desiitse_comune_id(),
		'dct:title'     => $data['title'] !== '' ? $data['title'] : $data['legal_name'],
		'cov:legalName' => $data['legal_name'] !== '' ? $data['legal_name'] : $data['title'],
	];

	if ( $data['tax_code'] !== '' ) {
		$node['cov:taxCode'] = $data['tax_code'];
	}

	if ( $data['acronym'] !== '' ) {
		$node['cov:orgAcronym'] = $data['acronym'];
	}

	return $node;
}

function desiitse_school_id(): string {
	$ipa = desiitse_get_ipa_code();
	return ( $ipa !== null && $ipa !== '' ) ? 'urn:x-italian-pa:' . $ipa : home_url( '/scuola/istituto/' );
}

function desiitse_school_ref(): array {
	return [ '@id' => desiitse_school_id() ];
}

function desiitse_primary_org_ref( string $profile = 'dci' ): array {
	return $profile === 'dsi' ? desiitse_school_ref() : desiitse_comune_ref();
}

function desiitse_attach_primary_organization( array &$node, string $profile = 'dci' ): void {
	desiitse_add_ref_property( $node, 'cov:hasOrganization', [ desiitse_primary_org_ref( $profile ) ] );
}

function desiitse_school_data(): array {
	$data = [
		'title'       => desiitse_clean_text( get_bloginfo( 'name' ) ),
		'legal_name'  => desiitse_clean_text( get_bloginfo( 'name' ) ),
		'tax_code'    => '',
		'acronym'     => '',
		'same_as'     => home_url( '/scuola/' ),
	];

	$data = apply_filters( 'desiitse_school_data', $data );

	return [
		'title'      => desiitse_clean_text( $data['title'] ?? '' ),
		'legal_name' => desiitse_clean_text( $data['legal_name'] ?? '' ),
		'tax_code'   => desiitse_clean_text( $data['tax_code'] ?? '' ),
		'acronym'    => desiitse_clean_text( $data['acronym'] ?? '' ),
		'same_as'    => $data['same_as'] ?? '',
	];
}

function desiitse_build_school_node(): array {
	$data = desiitse_school_data();
	$node = [
		'@type'         => 'cov:PublicOrganization',
		'@id'           => desiitse_school_id(),
		'dct:title'     => $data['title'] !== '' ? $data['title'] : $data['legal_name'],
		'cov:legalName' => $data['legal_name'] !== '' ? $data['legal_name'] : $data['title'],
	];

	if ( $data['tax_code'] !== '' ) {
		$node['cov:taxCode'] = $data['tax_code'];
	}

	if ( $data['acronym'] !== '' ) {
		$node['cov:orgAcronym'] = $data['acronym'];
	}

	$same_as = $data['same_as'];
	if ( is_string( $same_as ) ) {
		$same_as = [ $same_as ];
	}

	if ( is_array( $same_as ) ) {
		$refs = [];
		foreach ( $same_as as $uri ) {
			$uri = esc_url_raw( (string) $uri );
			if ( $uri !== '' ) {
				$refs[] = [ '@id' => $uri ];
			}
		}

		if ( ! empty( $refs ) ) {
			$node['owl:sameAs'] = count( $refs ) === 1 ? $refs[0] : $refs;
		}
	}

	return $node;
}

function desiitse_add_ref_property( array &$node, string $property, array $refs ): void {
	$normalized = [];
	foreach ( $refs as $ref ) {
		if ( is_array( $ref ) && ! empty( $ref['@id'] ) ) {
			$normalized[ $ref['@id'] ] = [ '@id' => $ref['@id'] ];
		}
	}

	if ( empty( $normalized ) ) {
		return;
	}

	$existing = $node[ $property ] ?? [];
	if ( isset( $existing['@id'] ) ) {
		$existing = [ $existing ];
	} elseif ( ! is_array( $existing ) ) {
		$existing = [];
	}

	foreach ( $existing as $ref ) {
		if ( is_array( $ref ) && ! empty( $ref['@id'] ) ) {
			$normalized[ $ref['@id'] ] = [ '@id' => $ref['@id'] ];
		}
	}

	$refs = array_values( $normalized );
	$node[ $property ] = count( $refs ) === 1 ? $refs[0] : $refs;
}

function desiitse_minimal_related_node( int $post_id ): ?array {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	$title = desiitse_clean_text( get_the_title( $post ) );

	switch ( $post->post_type ) {
		case 'unita_organizzativa':
			return [
				'@type'         => 'cov:Office',
				'@id'           => desiitse_node_id( $post ),
				'dct:title'     => $title,
				'cov:legalName' => $title,
				'cov:hasOrganization' => desiitse_comune_ref(),
			];
		case 'luogo':
			return [
				'@type'               => 'poi:PointOfInterest',
				'@id'                 => desiitse_node_id( $post ),
				'dct:title'           => $title,
				'poi:POIofficialName' => $title,
			];
		case 'persona_pubblica':
			return [
				'@type'     => 'cpv:Person',
				'@id'       => desiitse_node_id( $post ),
				'dct:title' => $title,
			];
		case 'struttura':
			return [
				'@type'         => 'cov:Organization',
				'@id'           => desiitse_node_id( $post ),
				'dct:title'     => $title,
				'cov:legalName' => $title,
				'cov:hasOrganization' => desiitse_school_ref(),
			];
		case 'servizio':
			return [
				'@type'     => 'cpsv:PublicService',
				'@id'       => desiitse_node_id( $post ),
				'dct:title' => $title,
			];
		case 'documento':
			return [
				'@type'         => 'foaf:Document',
				'@id'           => desiitse_node_id( $post ),
				'dct:title'     => $title,
				'dct:publisher' => desiitse_school_ref(),
			];
		case 'progetto':
			return [
				'@type'     => 'proj:Project',
				'@id'       => desiitse_node_id( $post ),
				'dct:title' => $title,
			];
		default:
			return null;
	}
}

function desiitse_minimal_related_nodes( array $post_ids ): array {
	$nodes = [];
	foreach ( array_values( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
		$node = desiitse_minimal_related_node( $post_id );
		if ( $node ) {
			$nodes[] = $node;
		}
	}
	return $nodes;
}

function desiitse_unique_nodes( array $nodes ): array {
	$indexed = [];
	$ordered = [];

	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) || empty( $node['@id'] ) ) {
			$ordered[] = $node;
			continue;
		}

		$id = $node['@id'];
		if ( isset( $indexed[ $id ] ) ) {
			$indexed[ $id ] = array_replace_recursive( $indexed[ $id ], $node );
			continue;
		}

		$indexed[ $id ] = $node;
		$ordered[]      = $id;
	}

	$result = [];
	foreach ( $ordered as $item ) {
		if ( is_string( $item ) && isset( $indexed[ $item ] ) ) {
			$result[] = $indexed[ $item ];
			unset( $indexed[ $item ] );
		} elseif ( is_array( $item ) ) {
			$result[] = $item;
		}
	}

	return $result;
}
