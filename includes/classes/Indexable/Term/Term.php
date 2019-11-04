<?php
/**
 * Term indexable
 *
 * @since  3.1
 * @package  elasticpress
 */

namespace ElasticPress\Indexable\Term;

use ElasticPress\Indexable as Indexable;
use ElasticPress\Elasticsearch as Elasticsearch;
use \WP_Term_Query as WP_Term_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Term indexable class
 */
class Term extends Indexable {

	/**
	 * Indexable slug
	 *
	 * @var   string
	 * @since 3.1
	 */
	public $slug = 'term';

	/**
	 * Create indexable and initialize dependencies
	 *
	 * @since 3.1
	 */
	public function __construct() {
		$this->labels = [
			'plural'   => esc_html__( 'Terms', 'elasticpress' ),
			'singular' => esc_html__( 'Term', 'elasticpress' ),
		];

		$this->sync_manager      = new SyncManager( $this->slug );
		$this->query_integration = new QueryIntegration();
	}

	/**
	 * Format query vars into ES query
	 *
	 * @param  array $query_vars WP_Term_Query args.
	 * @since  3.1
	 * @return array
	 */
	public function format_args( $query_vars ) {
		/**
		 * Support `number` query var
		 */
		if ( ! empty( $query_vars['number'] ) ) {
			$number = (int) $query_vars['number'];
		} else {
			/**
			 * Set the maximum results window size.
			 *
			 * The request will return a HTTP 500 Internal Error if the size of the
			 * request is larger than the [index.max_result_window] parameter in ES.
			 * See the scroll api for a more efficient way to request large data sets.
			 *
			 * @return int The max results window size.
			 *
			 * @since 2.3.0
			 */
			$number = apply_filters( 'ep_max_results_window', 10000 );
		}

		$formatted_args = [
			'from' => 0,
			'size' => $number,
		];

		/**
		 * Support `offset` query var
		 */
		if ( isset( $query_vars['offset'] ) ) {
			$formatted_args['from'] = (int) $query_vars['offset'];
		}

		/**
		 * Support `order` and `orderby` query vars
		 */

		// Set sort order, default is 'asc'.
		if ( ! empty( $query_vars['order'] ) ) {
			$order = $this->parse_order( $query_vars['order'] );
		} else {
			$order = 'asc';
		}

		// Default sort by name
		if ( empty( $query_vars['orderby'] ) ) {
			$query_vars['orderby'] = 'name';
		}

		// Set sort type.
		$formatted_args['sort'] = $this->parse_orderby( $query_vars['orderby'], $order, $query_vars );

		$filter = [
			'bool' => [
				'must' => [],
			],
		];

		$use_filters = false;

		/**
		 * Support `taxonomy` query var
		 */
		$taxonomy = [];
		if ( ! empty( $query_vars['taxonomy'] ) ) {
			$taxonomy       = (array) $query_vars['taxonomy'];
			$terms_map_name = 'terms';

			if ( count( $taxonomy ) < 2 ) {
				$terms_map_name = 'term';
				$taxonomy       = $taxonomy[0];
			}

			$filter['bool']['must'][] = [
				$terms_map_name => [
					'taxonomy.raw' => $taxonomy,
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `object_ids` query var
		 */
		if ( ! empty( $query_vars['object_ids'] ) ) {
			$filter['bool']['must'][]['bool']['must'][] = [
				'match_phrase' => [
					'object_ids.value' => $query_vars['object_ids'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `get` query var
		 */
		if ( ! empty( $query_vars['get'] ) && 'all' === $query_vars['get'] ) {
			$query_vars['childless']    = false;
			$query_vars['child_of']     = 0;
			$query_vars['hide_empty']   = false;
			$query_vars['hierarchical'] = false;
			$query_vars['pad_counts']   = false;
		}

		/**
		 * Support `hide_empty` query var
		 */
		if ( ! empty( $query_vars['hide_empty'] ) ) {
			$filter['bool']['must'][] = [
				'range' => [
					'count' => [
						'gte' => 1,
					],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `include` query var
		 */
		if ( ! empty( $query_vars['include'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'terms' => [
					'term_id' => array_values( (array) $query_vars['include'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `exclude` query var
		 */
		if ( empty( $query_vars['include'] ) && ! empty( $query_vars['exclude'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'term_id' => array_values( (array) $query_vars['exclude'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `exclude_tree` query var
		 */
		if ( empty( $query_vars['include'] ) && ! empty( $query_vars['exclude_tree'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'term_id' => array_values( (array) $query_vars['exclude_tree'] ),
				],
			];

			$filter['bool']['must'][]['bool']['must_not'] = [
				'terms' => [
					'parent' => array_values( (array) $query_vars['exclude_tree'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `name` query var
		 */
		if ( ! empty( $query_vars['name'] ) ) {
			$filter['bool']['must'][] = [
				'terms' => [
					'name.raw' => (array) $query_vars['name'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `slug` query var
		 */
		if ( ! empty( $query_vars['slug'] ) ) {
			$filter['bool']['must'][] = [
				'terms' => [
					'slug.raw' => (array) $query_vars['slug'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `term_taxonomy_id` query var
		 */
		if ( ! empty( $query_vars['term_taxonomy_id'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'terms' => [
					'term_taxonomy_id' => array_values( (array) $query_vars['term_taxonomy_id'] ),
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `hierarchical` query var
		 */
		if ( ! empty( $query_vars['hierarchical'] ) && false === $query_vars['hierarchical'] ) {
			$filter['bool']['must'][] = [
				'range' => [
					'hierarchy.children.count' => [
						'gte' => 1,
					],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `search`, `name__like` and `description__like` query_vars
		 */
		if ( ! empty( $query_vars['search'] ) || ! empty( $query_vars['name__like'] ) || ! empty( $query_vars['description__like'] ) ) {

			$search        = ! empty( $query_vars['search'] ) ? $query_vars['search'] : '';
			$search_fields = [];

			if ( ! empty( $query_vars['name__like'] ) ) {
				$search          = $query_vars['name__like'];
				$search_fields[] = 'name';
			}

			if ( ! empty( $query_vars['description__like'] ) ) {
				$search          = $query_vars['description__like'];
				$search_fields[] = 'description';
			}

			/**
			 * Allow for search field specification
			 */
			if ( ! empty( $query_vars['search_fields'] ) ) {
				$search_fields = $query_vars['search_fields'];
			}

			if ( ! empty( $search_fields ) ) {
				$prepared_search_fields = [];

				if ( ! empty( $search_fields['meta'] ) ) {
					$metas = (array) $search_fields['meta'];

					foreach ( $metas as $meta ) {
						$prepared_search_fields[] = 'meta.' . $meta . '.value';
					}

					unset( $search_fields['meta'] );
				}

				$prepared_search_fields = array_merge( $search_fields, $prepared_search_fields );
			} else {
				$prepared_search_fields = [
					'name',
					'slug',
					'taxonomy',
					'description',
				];
			}

			$prepared_search_fields = apply_filters( 'ep_term_search_fields', $prepared_search_fields, $query_vars );

			$query = [
				'bool' => [
					'should' => [
						[
							'multi_match' => [
								'query'  => $search,
								'type'   => 'phrase',
								'fields' => $prepared_search_fields,
								'boost'  => apply_filters( 'ep_term_match_phrase_boost', 4, $prepared_search_fields, $query_vars ),
							],
						],
						[
							'multi_match' => [
								'query'     => $search,
								'fields'    => $prepared_search_fields,
								'boost'     => apply_filters( 'ep_term_match_boost', 2, $prepared_search_fields, $query_vars ),
								'fuzziness' => 0,
								'operator'  => 'and',
							],
						],
						[
							'multi_match' => [
								'fields'    => $prepared_search_fields,
								'query'     => $search,
								'fuzziness' => apply_filters( 'ep_term_fuzziness_arg', 1, $prepared_search_fields, $query_vars ),
							],
						],
					],
				],
			];

			$formatted_args['query'] = apply_filters( 'ep_term_formatted_args_query', $query, $query_vars );

		} else {
			$formatted_args['query']['match_all'] = [
				'boost' => 1,
			];
		}

		/**
		 * Support `child_of` query var.
		 */
		if ( ! empty( $query_vars['child_of'] ) && ( is_string( $taxonomy ) || count( $taxonomy ) < 2 ) ) {
			$filter['bool']['must'][]['bool']['must'][] = [
				'match_phrase' => [
					'hierarchy.ancestors.terms' => (int) $query_vars['child_of'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `parent` query var.
		 */
		if ( ! empty( $query_vars['parent'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'parent' => (int) $query_vars['parent'],
				],
			];

			$use_filters = true;
		}

		/**
		 * Support `childless` query var.
		 */
		if ( ! empty( $query_vars['childless'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = [
				'term' => [
					'hierarchy.children.terms' => 0,
				],
			];

			$use_filters = true;
		}

		$meta_queries = [];

		/**
		 * Support `meta_key`, `meta_value`, and `meta_compare` query args
		 */
		if ( ! empty( $query_vars['meta_key'] ) ) {
			$meta_query_array = [
				'key' => $query_vars['meta_key'],
			];

			if ( isset( $query_vars['meta_value'] ) ) {
				$meta_query_array['value'] = $query_vars['meta_value'];
			}

			if ( isset( $query_vars['meta_compare'] ) ) {
				$meta_query_array['compare'] = $query_vars['meta_compare'];
			}

			$meta_queries[] = $meta_query_array;
		}

		/**
		 * Support 'meta_query' query var.
		 */
		if ( ! empty( $query_vars['meta_query'] ) ) {
			$meta_queries = array_merge( $meta_queries, $query_vars['meta_query'] );
		}

		if ( ! empty( $meta_queries ) ) {
			$built_meta_queries = $this->build_meta_query( $meta_queries );

			if ( $built_meta_queries ) {
				$filter['bool']['must'][] = $built_meta_queries;
				$use_filters              = true;
			}
		}

		/**
		 * Support `fields` query var.
		 */
		if ( isset( $query_vars['fields'] ) ) {
			switch ( $query_vars['fields'] ) {
				case 'ids':
					$formatted_args['_source'] = [
						'include' => [
							'term_id',
						],
					];
					break;

				case 'id=>name':
					$formatted_args['_source'] = [
						'include' => [
							'term_id',
							'name',
						],
					];
					break;

				case 'id=>parent':
					$formatted_args['_source'] = [
						'include' => [
							'term_id',
							'parent',
						],
					];
					break;

				case 'id=>slug':
					$formatted_args['_source'] = [
						'include' => [
							'term_id',
							'slug',
						],
					];
					break;

				case 'names':
					$formatted_args['_source'] = [
						'include' => [
							'name',
						],
					];
					break;
				case 'tt_ids':
					$formatted_args['_source'] = [
						'include' => [
							'term_taxonomy_id',
						],
					];
					break;
			}
		}

		if ( $use_filters ) {
			$formatted_args['post_filter'] = $filter;
		}

		return apply_filters( 'ep_term_formatted_args', $formatted_args, $query_vars );
	}

	/**
	 * Put mapping for terms
	 *
	 * @since  3.1
	 * @return boolean
	 */
	public function put_mapping() {
		$es_version = Elasticsearch::factory()->get_elasticsearch_version();

		if ( empty( $es_version ) ) {
			$es_version = apply_filters( 'ep_fallback_elasticsearch_version', '2.0' );
		}

		$mapping_file = 'initial.php';

		if ( version_compare( $es_version, '5.0', '<' ) ) {
			$mapping_file = 'pre-5-0.php';
		}

		$mapping = require apply_filters( 'ep_term_mapping_file', __DIR__ . '/../../../mappings/term/' . $mapping_file );

		$mapping = apply_filters( 'ep_term_mapping', $mapping );

		return Elasticsearch::factory()->put_mapping( $this->get_index_name(), $mapping );
	}

	/**
	 * Prepare a term document for indexing
	 *
	 * @param  int $term_id Term ID
	 * @since  3.1
	 * @return bool|array
	 */
	public function prepare_document( $term_id ) {
		$term = get_term( $term_id );

		if ( ! $term || ! is_a( $term, 'WP_Term' ) ) {
			return false;
		}

		$term_args = [
			'term_id'          => $term->term_id,
			'ID'               => $term->term_id,
			'name'             => $term->name,
			'slug'             => $term->slug,
			'term_group'       => $term->group,
			'term_taxonomy_id' => $term->term_taxonomy_id,
			'taxonomy'         => $term->taxonomy,
			'description'      => $term->description,
			'parent'           => $term->parent,
			'count'            => $term->count,
			'meta'             => $this->prepare_meta_types( $this->prepare_meta( $term->term_id ) ),
			'hierarchy'        => $this->prepare_term_hierarchy( $term->term_id, $term->taxonomy ),
			'object_ids'       => $this->prepare_object_ids( $term->term_id, $term->taxonomy ),
		];

		$term_args = apply_filters( 'ep_term_sync_args', $term_args, $term_id );

		return $term_args;
	}

	/**
	 * Query DB for terms
	 *
	 * @param  array $args Query arguments
	 * @since  3.1
	 * @return array
	 */
	public function query_db( $args ) {
		$all_query = new WP_Term_Query(
			[
				'count'  => true,
				'fields' => 'ids',
			]
		);

		$defaults = [
			'number'     => $this->get_bulk_items_per_page(),
			'offset'     => 0,
			'orderby'    => 'id',
			'order'      => 'desc',
			'taxonomy'   => $this->get_indexable_taxonomies(),
			'hide_empty' => false,
		];

		if ( isset( $args['per_page'] ) ) {
			$args['number'] = $args['per_page'];
		}

		$args = apply_filters( 'ep_term_query_db_args', wp_parse_args( $args, $defaults ) );

		$query = new WP_Term_Query( $args );

		array_walk( $query->terms, array( $this, 'remap_terms' ) );

		return [
			'objects'       => $query->terms,
			'total_objects' => count( $all_query->terms ),
		];
	}

	/**
	 * Returns indexable taxonomies for the current site
	 *
	 * @since  3.1
	 * @return mixed|void
	 */
	public function get_indexable_taxonomies() {
		$taxonomies        = get_taxonomies( [], 'objects' );
		$public_taxonomies = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy->public || $taxonomy->publicly_queryable ) {
				$public_taxonomies[] = $taxonomy->name;
			}
		}

		return apply_filters( 'ep_indexable_taxonomies', $public_taxonomies );
	}

	/**
	 * Rebuild our term object to match the fields we need.
	 *
	 * In particular, result of WP_Term_Query does not
	 * include an "id" field, which our index command
	 * expects.
	 *
	 * @param  object $value Term object
	 * @since  3.1
	 * @return void Returns by reference
	 */
	public function remap_terms( &$value ) {
		$value = (object) array(
			'ID'               => $value->term_id,
			'term_id'          => $value->term_id,
			'name'             => $value->name,
			'slug'             => $value->slug,
			'term_group'       => $value->term_group,
			'term_taxonomy_id' => $value->term_taxonomy_id,
			'taxonomy'         => $value->taxonomy,
			'description'      => $value->description,
			'parent'           => $value->parent,
			'count'            => $value->count,
		);
	}

	/**
	 * Prepare meta to send to ES
	 *
	 * @param  int $term_id Term ID
	 * @since  3.1
	 * @return array
	 */
	public function prepare_meta( $term_id ) {
		$meta = (array) get_term_meta( $term_id );

		if ( empty( $meta ) ) {
			return [];
		}

		$prepared_meta = [];

		/**
		 * Filter index-able private meta
		 *
		 * Allows for specifying private meta keys that may be indexed in the same manner as public meta keys.
		 *
		 * @since 3.1
		 *
		 * @param array        Array of index-able private meta keys.
		 * @param int $term_id Term ID.
		 */
		$allowed_protected_keys = apply_filters( 'ep_prepare_term_meta_allowed_protected_keys', [], $term_id );

		/**
		 * Filter non-indexed public meta
		 *
		 * Allows for specifying public meta keys that should be excluded from the ElasticPress index.
		 *
		 * @since 3.1
		 *
		 * @param array        Array of public meta keys to exclude from index.
		 * @param int $term_id Term ID.
		 */
		$excluded_public_keys = apply_filters(
			'ep_prepare_term_meta_excluded_public_keys',
			[
				'session_tokens',
			],
			$term_id
		);

		foreach ( $meta as $key => $value ) {

			$allow_index = false;

			if ( is_protected_meta( $key ) ) {

				if ( true === $allowed_protected_keys || in_array( $key, $allowed_protected_keys, true ) ) {
					$allow_index = true;
				}
			} else {

				if ( true !== $excluded_public_keys && ! in_array( $key, $excluded_public_keys, true ) ) {
					$allow_index = true;
				}
			}

			if ( true === $allow_index || apply_filters( 'ep_prepare_term_meta_whitelist_key', false, $key, $term_id ) ) {
				$prepared_meta[ $key ] = maybe_unserialize( $value );
			}
		}

		return $prepared_meta;
	}

	/**
	 * Prepare term hierarchy to send to ES
	 *
	 * @param  int    $term_id Term ID.
	 * @param  string $taxonomy Term taxonomy.
	 * @since  3.1
	 * @return array
	 */
	public function prepare_term_hierarchy( $term_id, $taxonomy ) {
		$hierarchy = [];
		$children  = get_term_children( $term_id, $taxonomy );
		$ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );

		if ( ! empty( $children ) && ! is_wp_error( $children ) ) {
			$hierarchy['children']['terms'] = $children;
			$children_count                 = 0;

			foreach ( $children as $child_term_id ) {
				$child_term      = get_term( $child_term_id );
				$children_count += (int) $child_term->count;
			}

			$hierarchy['children']['count'] = $children_count;
		} else {
			$hierarchy['children']['terms'] = 0;
			$hierarchy['children']['count'] = 0;
		}

		if ( ! empty( $ancestors ) ) {
			$hierarchy['ancestors']['terms'] = $ancestors;
		} else {
			$hierarchy['ancestors']['terms'] = 0;
		}

		return $hierarchy;
	}

	/**
	 * Prepare object IDs to send to ES
	 *
	 * @param  int    $term_id Term ID.
	 * @param  string $taxonomy Term taxonomy.
	 * @since  3.1
	 * @return array
	 */
	public function prepare_object_ids( $term_id, $taxonomy ) {
		$ids        = [];
		$object_ids = get_objects_in_term( [ $term_id ], [ $taxonomy ] );

		if ( ! empty( $object_ids ) && ! is_wp_error( $object_ids ) ) {
			$ids['value'] = array_map( 'absint', array_values( $object_ids ) );
		} else {
			$ids['value'] = 0;
		}

		return $ids;
	}

	/**
	 * Parse an 'order' query variable and cast it to ASC or DESC as necessary.
	 *
	 * @access protected
	 *
	 * @param  string $order The 'order' query variable.
	 * @since  3.1
	 * @return string The sanitized 'order' query variable.
	 */
	protected function parse_order( $order ) {
		if ( ! is_string( $order ) || empty( $order ) ) {
			return 'desc';
		}

		if ( 'ASC' === strtoupper( $order ) ) {
			return 'asc';
		} else {
			return 'desc';
		}
	}

	/**
	 * Convert the alias to a properly-prefixed sort value.
	 *
	 * @access protected
	 *
	 * @param  string $orderby Alias or path for the field to order by.
	 * @param  string $order Order direction
	 * @param  array  $args Query args
	 * @since  3.1
	 * @return array
	 */
	protected function parse_orderby( $orderby, $order, $args ) {
		$sort = [];

		if ( ! empty( $orderby ) ) {
			if ( 'name' === $orderby ) {
				$sort[] = array(
					'name.raw' => array(
						'order' => $order,
					),
				);
			} elseif ( 'slug' === $orderby ) {
				$sort[] = array(
					'slug.raw' => array(
						'order' => $order,
					),
				);
			} elseif ( 'term_group' === $orderby ) {
				$sort[] = array(
					'term_group.long' => array(
						'order' => $order,
					),
				);
			} elseif ( 'term_id' === $orderby || 'id' === $orderby ) {
				$sort[] = array(
					'term_id.long' => array(
						'order' => $order,
					),
				);
			} elseif ( 'description' === $orderby ) {
				$sort[] = array(
					'description.raw' => array(
						'order' => $order,
					),
				);
			} elseif ( 'parent' === $orderby ) {
				$sort[] = array(
					'parent.long' => array(
						'order' => $order,
					),
				);
			} elseif ( 'count' === $orderby ) {
				$sort[] = array(
					'count.long' => array(
						'order' => $order,
					),
				);
			} elseif ( 'meta_value' === $orderby ) {
				if ( ! empty( $args['meta_key'] ) ) {
					$sort[] = array(
						'meta.' . $args['meta_key'] . '.value' => array(
							'order' => $order,
						),
					);
				}
			} elseif ( 'meta_value_num' === $orderby ) {
				if ( ! empty( $args['meta_key'] ) ) {
					$sort[] = array(
						'meta.' . $args['meta_key'] . '.long' => array(
							'order' => $order,
						),
					);
				}
			} else {
				$sort[] = array(
					$orderby => array(
						'order' => $order,
					),
				);
			}
		}

		return $sort;
	}

}