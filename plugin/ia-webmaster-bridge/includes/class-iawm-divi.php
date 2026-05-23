<?php
/**
 * Capacité Divi 5 — lecture et écriture de layouts.
 *
 * Phase 3.2 (lecture) :
 *  - /divi/page/read : projette le post_content d'une page Divi en arbre
 *    JSON simplifié (sections > rows > columns > modules), avec attributs
 *    normalisés et résumé contenu.
 *
 * Phase 3.2 (écriture) — à venir :
 *  - /divi/page/write : prend un arbre simplifié, le sérialise en blocs
 *    Divi 5 valides et l'écrit dans post_content.
 *
 * Phase 3.3 (Divi Cloud) — à venir.
 *
 * Le format est documenté dans docs/divi5-format.md (rétro-ingénierie
 * faite sur la page de référence locale n°19).
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes Divi 5.
 */
class IAWM_Divi {

	/** Préfixe des blocs Divi 5 dans le balisage Gutenberg. */
	const BLOCK_PREFIX = 'divi/';

	/** Nom du wrapper racine. */
	const ROOT_BLOCK = 'divi/placeholder';

	/** Modules structurels (non-feuilles). */
	const STRUCTURAL_BLOCKS = array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column' );

	/**
	 * Branche l'enregistrement des routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/divi/page/read'  => array( 'handle_page_read', 'guard_read' ),
			'/divi/page/write' => array( 'handle_page_write', 'guard_write' ),
			'/divi/status'     => array( 'handle_status', 'guard_read' ),
		);

		foreach ( $routes as $path => $config ) {
			register_rest_route(
				IAWM_REST_NAMESPACE,
				$path,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $config[0] ),
					'permission_callback' => array( 'IAWM_Auth', $config[1] ),
				)
			);
		}
	}

	/**
	 * POST /divi/status — état de Divi 5 sur le site.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_status( $request ) {
		unset( $request );

		$divi_active = defined( 'ET_BUILDER_VERSION' );
		$version     = $divi_active && defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : null;
		// Couvre aussi le cas où Divi expose sa version via une autre constante.
		if ( null === $version && defined( 'ET_CORE_VERSION' ) ) {
			$version = ET_CORE_VERSION;
		}

		return new WP_REST_Response(
			array(
				'ok'              => true,
				'divi_active'     => $divi_active,
				'divi_version'    => $version,
				'theme_stylesheet' => get_stylesheet(),
				'has_d5_format'   => function_exists( 'parse_blocks' ),
			),
			200
		);
	}

	/**
	 * POST /divi/page/read — lit une page Divi 5 et la projette en arbre.
	 *
	 * Paramètres :
	 *   - post_id (int, requis).
	 *   - mode    (string, optionnel) : "tree" (défaut) | "raw" | "flat".
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_page_read( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$mode    = isset( $params['mode'] ) ? (string) $params['mode'] : 'tree';

		if ( ! in_array( $mode, array( 'tree', 'raw', 'flat' ), true ) ) {
			return IAWM_Support::rest_error( 'invalid_mode', "Mode inconnu : {$mode}.", 400 );
		}
		if ( $post_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_post_id', 'post_id requis.', 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return IAWM_Support::rest_error( 'post_not_found', "Post {$post_id} introuvable.", 404 );
		}

		$uses_builder = 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true );
		$has_d5_marker = false !== strpos( $post->post_content, '<!-- wp:divi/' );

		if ( ! $uses_builder && ! $has_d5_marker ) {
			return IAWM_Support::rest_error(
				'not_a_divi_page',
				"Le post {$post_id} n'est pas une page Divi 5 (ni meta _et_pb_use_builder=on, ni blocs wp:divi/ détectés).",
				400,
				array( 'meta_use_builder' => $uses_builder, 'has_d5_marker' => $has_d5_marker )
			);
		}

		$blocks = parse_blocks( $post->post_content );

		$page = array(
			'id'                   => (int) $post->ID,
			'title'                => $post->post_title,
			'status'               => $post->post_status,
			'slug'                 => $post->post_name,
			'link'                 => get_permalink( $post ),
			'meta_use_builder'     => $uses_builder,
			'built_for_post_type'  => get_post_meta( $post_id, '_et_pb_built_for_post_type', true ),
		);

		// Aller chercher le wrapper placeholder en racine — s'il existe, on
		// descend dedans pour exposer directement les sections.
		$layout_blocks = self::unwrap_placeholder( $blocks );

		switch ( $mode ) {
			case 'raw':
				$output = $blocks;
				break;
			case 'flat':
				$output = self::flatten( $layout_blocks );
				break;
			case 'tree':
			default:
				$output = self::project_tree( $layout_blocks );
				break;
		}

		// Stats utiles (toujours fournies).
		$counts = self::count_block_types( $blocks );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'mode'    => $mode,
				'page'    => $page,
				'stats'   => array(
					'total_blocks'   => array_sum( $counts ),
					'block_counts'   => $counts,
					'section_count'  => isset( $counts['divi/section'] ) ? $counts['divi/section'] : 0,
				),
				'layout'  => $output,
			),
			200
		);
	}

	/**
	 * POST /divi/page/write — écrit un layout Divi 5 dans un post.
	 *
	 * Deux formats d'entrée acceptés :
	 *  - "content" (string) : post_content déjà sérialisé (chaîne avec
	 *    `<!-- wp:divi/... -->`). Passe par parse_blocks+serialize_blocks
	 *    pour normalisation et validation.
	 *  - "blocks" (array) : tableau de blocs au format parse_blocks
	 *    (`{ blockName, attrs, innerBlocks, innerHTML, innerContent }`).
	 *    Sérialisé via serialize_blocks().
	 *
	 * Garantit :
	 *  - le wrapper wp:divi/placeholder racine (ajouté automatiquement
	 *    s'il manque) ;
	 *  - la meta _et_pb_use_builder=on (posée si absente) ;
	 *  - la meta _et_pb_built_for_post_type alignée sur le type du post.
	 *
	 * dry_run=true : valide et décrit ce qui serait écrit sans toucher
	 * au post.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_page_write( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$dry_run = ! empty( $params['dry_run'] );

		if ( $post_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_post_id', 'post_id requis.', 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return IAWM_Support::rest_error( 'post_not_found', "Post {$post_id} introuvable.", 404 );
		}

		// Récupération du contenu à écrire selon le format fourni.
		$blocks = null;
		if ( isset( $params['blocks'] ) && is_array( $params['blocks'] ) ) {
			$blocks = $params['blocks'];
		} elseif ( isset( $params['content'] ) && is_string( $params['content'] ) ) {
			$blocks = parse_blocks( $params['content'] );
		} else {
			return IAWM_Support::rest_error(
				'missing_payload',
				'Fournir "content" (chaîne sérialisée) OU "blocks" (tableau de blocs).',
				400
			);
		}

		// Filtrer les blocs vides issus du parsing (whitespace).
		$real_blocks = array_values( array_filter( $blocks, function( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		if ( empty( $real_blocks ) ) {
			return IAWM_Support::rest_error( 'empty_layout', 'Aucun bloc Divi détecté dans le payload.', 400 );
		}

		// Vérifier qu'on a soit un placeholder racine, soit uniquement des
		// blocs divi/. Si non, refuser.
		$has_placeholder = count( $real_blocks ) === 1 && $real_blocks[0]['blockName'] === self::ROOT_BLOCK;
		$all_divi        = true;
		foreach ( $real_blocks as $b ) {
			if ( 0 !== strpos( $b['blockName'], self::BLOCK_PREFIX ) ) {
				$all_divi = false;
				break;
			}
		}
		if ( ! $all_divi ) {
			return IAWM_Support::rest_error(
				'non_divi_blocks',
				'Le layout contient des blocs hors namespace divi/.',
				400,
				array( 'detected' => array_map( function( $b ) { return $b['blockName']; }, $real_blocks ) )
			);
		}

		// Auto-wrap : si on n'a pas le placeholder racine, on l'ajoute.
		if ( ! $has_placeholder ) {
			$real_blocks = array(
				array(
					'blockName'    => self::ROOT_BLOCK,
					'attrs'        => new stdClass(),
					'innerBlocks'  => $real_blocks,
					'innerHTML'    => '',
					'innerContent' => array( null ),
				),
			);
			$wrapped = true;
		} else {
			$wrapped = false;
		}

		$content      = serialize_blocks( $real_blocks );
		$block_count  = self::count_block_types( $real_blocks );
		$total        = array_sum( $block_count );

		$preview = array(
			'wrapped_with_placeholder' => $wrapped,
			'block_count'              => $block_count,
			'total_blocks'             => $total,
			'content_length'           => strlen( $content ),
		);

		if ( $dry_run ) {
			return new WP_REST_Response(
				array( 'ok' => true, 'dry_run' => true, 'preview' => $preview ),
				200
			);
		}

		IAWM_Support::act_as_agent();

		// wp_update_post applique wp_unslash() en interne (suppose que les
		// données viennent d'un $_POST déjà slashed). On doit donc slasher
		// nous-mêmes pour préserver les backslashes du JSON Divi
		// (", <, etc.) — sans ça, les attributs des blocs sont
		// corrompus à l'écriture.
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return IAWM_Support::rest_error( 'write_failed', $result->get_error_message(), 500 );
		}

		// Garantir les meta Divi.
		if ( 'on' !== get_post_meta( $post_id, '_et_pb_use_builder', true ) ) {
			update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		}
		if ( get_post_meta( $post_id, '_et_pb_built_for_post_type', true ) !== $post->post_type ) {
			update_post_meta( $post_id, '_et_pb_built_for_post_type', $post->post_type );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'written' => true,
				'post_id' => $post_id,
				'preview' => $preview,
			),
			200
		);
	}

	/**
	 * Si la racine contient un (et un seul) wp:divi/placeholder, descend
	 * dedans pour exposer les sections directement.
	 *
	 * @param array $blocks Blocs racine.
	 * @return array
	 */
	protected static function unwrap_placeholder( $blocks ) {
		// Filtrer les blocs vides (espaces blancs entre les commentaires).
		$real = array_values( array_filter( $blocks, function( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		if ( 1 === count( $real ) && self::ROOT_BLOCK === $real[0]['blockName'] ) {
			return $real[0]['innerBlocks'];
		}
		return $real;
	}

	/**
	 * Projette une liste de blocs en arbre simplifié.
	 *
	 * Garde la hiérarchie mais aplatit les wrappers de breakpoints
	 * intermédiaires pour exposer un JSON exploitable directement.
	 *
	 * @param array $blocks Blocs à projeter.
	 * @return array
	 */
	protected static function project_tree( $blocks ) {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$out[] = self::project_block( $block );
		}
		return $out;
	}

	/**
	 * Projette un seul bloc en structure simplifiée.
	 *
	 * @param array $block Bloc Gutenberg.
	 * @return array
	 */
	protected static function project_block( $block ) {
		$name       = $block['blockName'];
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$short_name = self::short_name( $name );

		$node = array(
			'type'           => $short_name,
			'block_name'     => $name,
			'is_structural'  => in_array( $name, self::STRUCTURAL_BLOCKS, true ),
			'builder_version'=> isset( $attrs['builderVersion'] ) ? $attrs['builderVersion'] : null,
		);

		// Champs spécifiques à certains modules : on tire le contenu textuel
		// pour faciliter la lecture par Claude.
		$summary = self::summarize_module( $name, $attrs );
		if ( ! empty( $summary ) ) {
			$node['summary'] = $summary;
		}

		// Style normalisé : on remonte ce qui est généralement intéressant.
		$style = self::summarize_style( $attrs );
		if ( ! empty( $style ) ) {
			$node['style'] = $style;
		}

		// Pour les blocs structurels, on récurse dans innerBlocks.
		if ( in_array( $name, self::STRUCTURAL_BLOCKS, true ) && ! empty( $block['innerBlocks'] ) ) {
			$children = self::project_tree( $block['innerBlocks'] );
			$child_key = self::children_key( $name );
			$node[ $child_key ] = $children;
		} else {
			// Pour les modules feuilles, on garde les attrs bruts pour
			// inspection détaillée (utile en debug).
			$node['attrs'] = $attrs;
		}

		return $node;
	}

	/**
	 * Nom court d'un bloc Divi (sans le préfixe "divi/").
	 *
	 * @param string $block_name Nom complet.
	 * @return string
	 */
	protected static function short_name( $block_name ) {
		if ( 0 === strpos( $block_name, self::BLOCK_PREFIX ) ) {
			return substr( $block_name, strlen( self::BLOCK_PREFIX ) );
		}
		return $block_name;
	}

	/**
	 * Renvoie la clé d'enfants attendue pour un bloc structurel donné.
	 *
	 * @param string $block_name Nom du bloc parent.
	 * @return string
	 */
	protected static function children_key( $block_name ) {
		switch ( $block_name ) {
			case 'divi/placeholder':
			case 'divi/section':
				return 'rows';
			case 'divi/row':
				return 'columns';
			case 'divi/column':
				return 'modules';
			default:
				return 'children';
		}
	}

	/**
	 * Extrait un résumé lisible du contenu d'un module (texte, titre, etc.).
	 *
	 * @param string $name  Nom du bloc.
	 * @param array  $attrs Attributs.
	 * @return array
	 */
	protected static function summarize_module( $name, $attrs ) {
		$summary = array();

		// Helper : récupère innerContent.desktop.value (le HTML/texte par défaut).
		$pick_desktop = function( $field ) use ( $attrs ) {
			if ( ! isset( $attrs[ $field ]['innerContent']['desktop']['value'] ) ) {
				return null;
			}
			return $attrs[ $field ]['innerContent']['desktop']['value'];
		};

		switch ( $name ) {
			case 'divi/text':
				$summary['content_html'] = $pick_desktop( 'content' );
				break;

			case 'divi/blurb':
				$title = $pick_desktop( 'title' );
				if ( is_array( $title ) && isset( $title['text'] ) ) {
					$summary['title'] = $title['text'];
				} elseif ( is_string( $title ) ) {
					$summary['title'] = $title;
				}
				$summary['content_html'] = $pick_desktop( 'content' );
				if ( isset( $attrs['imageIcon']['innerContent']['desktop']['value']['icon']['unicode'] ) ) {
					$summary['icon_unicode'] = $attrs['imageIcon']['innerContent']['desktop']['value']['icon']['unicode'];
				}
				break;

			case 'divi/cta':
				$summary['title']        = $pick_desktop( 'title' );
				$summary['content_html'] = $pick_desktop( 'content' );
				$button = $pick_desktop( 'button' );
				if ( is_array( $button ) ) {
					$summary['button_text'] = isset( $button['text'] ) ? $button['text'] : null;
					$summary['button_url']  = isset( $button['linkUrl'] ) ? $button['linkUrl'] : null;
				}
				break;

			case 'divi/image':
				if ( isset( $attrs['image']['innerContent']['desktop']['value']['src'] ) ) {
					$src = $attrs['image']['innerContent']['desktop']['value']['src'];
					// On tronque les data: URLs pour ne pas polluer l'output.
					$summary['src'] = self::truncate_src( $src );
				}
				break;

			case 'divi/button':
				$button = $pick_desktop( 'button' );
				if ( is_array( $button ) ) {
					$summary['text'] = isset( $button['text'] ) ? $button['text'] : null;
					$summary['url']  = isset( $button['linkUrl'] ) ? $button['linkUrl'] : null;
				}
				break;

			case 'divi/row':
				// Structure de colonnes.
				if ( isset( $attrs['module']['advanced']['columnStructure']['desktop']['value'] ) ) {
					$summary['column_structure'] = $attrs['module']['advanced']['columnStructure']['desktop']['value'];
				}
				break;

			case 'divi/column':
				if ( isset( $attrs['module']['advanced']['type']['desktop']['value'] ) ) {
					$summary['type'] = $attrs['module']['advanced']['type']['desktop']['value'];
				}
				break;
		}

		// Nettoyer les nulls.
		return array_filter( $summary, function( $v ) { return null !== $v && '' !== $v; } );
	}

	/**
	 * Tronque les data: URLs ou très longues URLs pour l'affichage.
	 *
	 * @param string $src URL ou data: URI.
	 * @return string
	 */
	protected static function truncate_src( $src ) {
		if ( ! is_string( $src ) ) {
			return '';
		}
		if ( strlen( $src ) > 200 && 0 === strpos( $src, 'data:' ) ) {
			$mime = substr( $src, 5, strpos( $src, ';' ) - 5 );
			return 'data:' . $mime . ';base64,...(' . strlen( $src ) . ' bytes)';
		}
		return $src;
	}

	/**
	 * Extrait un résumé du style d'un bloc (couleurs, espacement…).
	 *
	 * @param array $attrs Attributs du bloc.
	 * @return array
	 */
	protected static function summarize_style( $attrs ) {
		$style = array();

		if ( ! isset( $attrs['module']['decoration'] ) ) {
			return $style;
		}
		$deco = $attrs['module']['decoration'];

		// Fond.
		if ( isset( $deco['background']['desktop']['value'] ) ) {
			$bg = $deco['background']['desktop']['value'];
			if ( isset( $bg['color'] ) ) {
				$style['background_color'] = self::shorten_variable( $bg['color'] );
			}
			if ( isset( $bg['image']['url'] ) ) {
				$style['background_image'] = $bg['image']['url'];
			}
			if ( isset( $bg['gradient']['enabled'] ) && 'on' === $bg['gradient']['enabled'] ) {
				$style['background_gradient'] = isset( $bg['gradient']['stops'] ) ? $bg['gradient']['stops'] : true;
			}
		}

		// Spacing.
		if ( isset( $deco['spacing']['desktop']['value'] ) ) {
			$spacing = $deco['spacing']['desktop']['value'];
			if ( isset( $spacing['padding'] ) ) {
				$style['padding'] = array_filter(
					array(
						'top'    => isset( $spacing['padding']['top'] )    ? $spacing['padding']['top']    : null,
						'right'  => isset( $spacing['padding']['right'] )  ? $spacing['padding']['right']  : null,
						'bottom' => isset( $spacing['padding']['bottom'] ) ? $spacing['padding']['bottom'] : null,
						'left'   => isset( $spacing['padding']['left'] )   ? $spacing['padding']['left']   : null,
					),
					function( $v ) { return null !== $v; }
				);
			}
			if ( isset( $spacing['margin'] ) ) {
				$style['margin'] = array_filter(
					array(
						'top'    => isset( $spacing['margin']['top'] )    ? $spacing['margin']['top']    : null,
						'right'  => isset( $spacing['margin']['right'] )  ? $spacing['margin']['right']  : null,
						'bottom' => isset( $spacing['margin']['bottom'] ) ? $spacing['margin']['bottom'] : null,
						'left'   => isset( $spacing['margin']['left'] )   ? $spacing['margin']['left']   : null,
					),
					function( $v ) { return null !== $v; }
				);
			}
		}

		return $style;
	}

	/**
	 * Simplifie une référence de variable globale en son nom.
	 * Ex. `$variable({"type":"color","value":{"name":"gcid-foo","settings":{}}})$` → `var:gcid-foo`.
	 *
	 * @param string $color Valeur de couleur (peut contenir une variable ou un code hex).
	 * @return string
	 */
	protected static function shorten_variable( $color ) {
		if ( ! is_string( $color ) ) {
			return '';
		}
		if ( preg_match( '/"name":"([a-zA-Z0-9_\\-]+)"/', $color, $m ) ) {
			return 'var:' . $m[1];
		}
		return $color;
	}

	/**
	 * Produit une liste linéaire (mode flat) des blocs avec chemin.
	 *
	 * @param array  $blocks Liste de blocs racine.
	 * @param string $path   Chemin courant.
	 * @return array
	 */
	protected static function flatten( $blocks, $path = '' ) {
		$out = array();
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$here = $path === '' ? (string) $i : "{$path}.{$i}";
			$node = array(
				'path'       => $here,
				'type'       => self::short_name( $block['blockName'] ),
				'block_name' => $block['blockName'],
			);
			$summary = self::summarize_module( $block['blockName'], isset( $block['attrs'] ) ? $block['attrs'] : array() );
			if ( ! empty( $summary ) ) {
				$node['summary'] = $summary;
			}
			$out[] = $node;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::flatten( $block['innerBlocks'], $here ) );
			}
		}
		return $out;
	}

	/**
	 * Compte les blocs récursivement par type.
	 *
	 * @param array $blocks Blocs.
	 * @param array $acc    Accumulateur.
	 * @return array
	 */
	protected static function count_block_types( $blocks, $acc = array() ) {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$name = $block['blockName'];
			$acc[ $name ] = isset( $acc[ $name ] ) ? $acc[ $name ] + 1 : 1;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$acc = self::count_block_types( $block['innerBlocks'], $acc );
			}
		}
		return $acc;
	}
}
