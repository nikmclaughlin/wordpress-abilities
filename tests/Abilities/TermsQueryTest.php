<?php
/**
 * Tests: `wp/terms-query` (port of WordPress/ai PR #1099's terms tests, on this
 * package's per-ability contract shape).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev) and stable
 * (6.9/7.1). The `wp/` name is never core-provided, so the package copy
 * registers and executes identically on both. The source PR's taxonomy
 * `show_in_abilities` flag is natively absent on every currently supported
 * core (verified: `property_exists( 'WP_Taxonomy', 'show_in_abilities' )` is
 * false on 7.2-beta3 and on stable), so the package
 * `taxonomy_show_in_abilities` polyfill marks `category` and `post_tag` on
 * both environments and no test branches on the environment — the
 * polyfill-vs-native boundary is pinned by the feature test instead.
 *
 * Collection expectations use `get_terms()` with the same arguments as an
 * oracle, so the stock categories of a fresh install are always counted
 * alongside the fixtures.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionClass;
use WP_Error;
use WP_Abilities_Registry;
use WP_Ability;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Support\Features;
use WordPress\Abilities\Terms\Terms_Query;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `wp/terms-query`.
 */
final class TermsQueryTest extends TestCase {

	/**
	 * An ID no WordPress install reaches, the REST tests' impossible number.
	 */
	private const IMPOSSIBLY_HIGH_NUMBER = 4294967296;

	/**
	 * The terms fixture: slug pairs of ID and taxonomy.
	 *
	 * @var list<array{id: int, taxonomy: string}>
	 */
	private array $fixture_terms = array();

	/**
	 * The post fixture: name => ID.
	 *
	 * @var array<string, int>
	 */
	private array $fixture_posts = array();

	/**
	 * Cached IDs of the mocked users, keyed by role.
	 *
	 * @var array<string, int>
	 */
	private array $user_ids = array();

	/**
	 * Taxonomies registered per test, unregistered again in the teardown.
	 *
	 * @var list<string>
	 */
	private array $registered_taxonomies = array();

	/**
	 * The term IDs of the shared data set, keyed by fixture name.
	 *
	 * @var array<string, int>
	 */
	private array $term_ids = array();

	/**
	 * The post IDs of the shared data set, keyed by fixture name.
	 *
	 * @var array<string, int>
	 */
	private array $post_ids = array();

	/**
	 * Settles the registry as the other ability suites do: registering before
	 * the boot so the Registrar's preload and category declarations are queued
	 * in time; the `wp/terms-query` entry requires polyfilled taxonomy exposure
	 * on every currently supported WordPress.
	 */
	public static function set_up_before_class(): void {
		if ( ! Features::is_available( 'taxonomy_show_in_abilities' ) ) {
			Features::load_polyfill( 'taxonomy_show_in_abilities' );
		}

		Registrar::register_all();

		if ( 0 === did_action( 'wp_abilities_api_init' ) ) {
			// Fires a fresh boot; a no-op once a previous suite's boot settled the registry.
			WP_Abilities_Registry::get_instance();
		}
	}

	/**
	 * Removes the fixtures, so each test builds a fresh data set.
	 */
	public function tear_down(): void {
		foreach ( $this->fixture_terms as $fixture ) {
			wp_delete_term( $fixture['id'], $fixture['taxonomy'] );
		}
		$this->fixture_terms = array();
		$this->term_ids      = array();

		foreach ( $this->fixture_posts as $id ) {
			wp_delete_post( $id, true );
		}
		$this->fixture_posts = array();
		$this->post_ids      = array();

		foreach ( $this->registered_taxonomies as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
		}
		$this->registered_taxonomies = array();

		if ( ! empty( $this->user_ids ) && ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->user_ids as $id ) {
			if ( $id > 1 ) {
				wp_delete_user( $id );
			}
		}
		$this->user_ids = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The registered `wp/terms-query` instance.
	 */
	private function get_registered(): WP_Ability {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/terms-query' );
		self::assertNotNull( $ability, 'The ability should be registered through the Registrar.' );

		return $ability;
	}

	/**
	 * Runs the ability through the Abilities API and returns the result.
	 *
	 * The validated transport, as the run route serves it: schema-validated
	 * input, permission check, then the execute callback.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function query_terms( array $input = array() ) {
		return wp_get_ability( 'wp/terms-query' )->execute( $input );
	}

	/**
	 * Logs in a mocked user of the given role and returns its ID.
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	private function log_in_as( string $role ): int {
		if ( ! isset( $this->user_ids[ $role ] ) ) {
			$this->user_ids[ $role ] = (int) wp_insert_user(
				array(
					'user_login' => 'tq_' . $role,
					'user_pass'  => wp_generate_password(),
					'role'       => $role,
				)
			);
		}
		wp_set_current_user( $this->user_ids[ $role ] );

		return $this->user_ids[ $role ];
	}

	/**
	 * Invokes a private method on an ability (the unvalidated transport the
	 * source PR's direct-call tests use).
	 *
	 * @param Terms_Query $ability The ability instance.
	 * @param string      $method  The private method name.
	 * @param mixed       ...$args The method arguments.
	 * @return mixed The method result.
	 */
	private function invoke_private( Terms_Query $ability, string $method, ...$args ) {
		$method_object = ( new ReflectionClass( $ability ) )->getMethod( $method );
		$method_object->setAccessible( true );

		return $method_object->invoke( $ability, ...$args );
	}

	/**
	 * Executes the underlying callback directly (the unvalidated transport path).
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The callback result.
	 */
	private function execute_raw( array $input ) {
		return $this->invoke_private( new Terms_Query(), 'execute_terms_query', $input );
	}

	/**
	 * Checks the permission callback directly (the unvalidated transport path).
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return bool The permission callback result.
	 */
	private function permission_raw( array $input ): bool {
		return $this->invoke_private( new Terms_Query(), 'check_permission', $input );
	}

	/**
	 * Creates a mocked term, tracked for removal.
	 *
	 * @param string               $name     The term name.
	 * @param string               $slug     The term slug.
	 * @param string               $taxonomy The taxonomy.
	 * @param array<string, mixed> $args     Optional. Further term arguments, e.g. description.
	 * @return int The term ID.
	 */
	private function create_test_term( string $name, string $slug, string $taxonomy, array $args = array() ): int {
		$created = wp_insert_term( $name, $taxonomy, array_merge( array( 'slug' => $slug ), $args ) );
		self::assertNotInstanceOf( WP_Error::class, $created, 'The test term should be created.' );

		$id = (int) $created['term_id'];
		self::assertGreaterThan( 0, $id, 'The test term should be created.' );

		$this->fixture_terms[] = array(
			'id'       => $id,
			'taxonomy' => $taxonomy,
		);

		return $id;
	}

	/**
	 * Moves a term under a parent term, and tracks the fixture.
	 *
	 * @param int $term_id        The term to move.
	 * @param int $parent_term_id The parent term ID.
	 * @return int The term ID.
	 */
	private function term_parent( int $term_id, int $parent_term_id ): int {
		$updated = wp_update_term( $term_id, 'category', array( 'parent' => $parent_term_id ) );
		self::assertNotInstanceOf( WP_Error::class, $updated, 'The test term should get a parent.' );

		return $term_id;
	}

	/**
	 * Creates a mocked post, tracked for removal.
	 *
	 * @param string $title   The post title.
	 * @param string $status  The post status. Default 'publish'.
	 * @param int    $author  The author user ID. Default 0: no author.
	 * @param string $type    The post type. Default 'post'.
	 * @return int The post ID.
	 */
	private function create_test_post( string $title, string $status = 'publish', int $author = 0, string $type = 'post' ): int {
		$post_id = (int) wp_insert_post(
			array(
				'post_title'   => $title,
				'post_status'  => $status,
				'post_content' => 'Post content',
				'post_author'  => $author,
				'post_type'    => $type,
			)
		);
		self::assertGreaterThan( 0, $post_id, 'The test post should be created.' );

		$this->fixture_posts[] = $post_id;

		return $post_id;
	}

	/**
	 * Creates the shared data set: hierarchical categories and flat tags.
	 *
	 * Categories: Fruit (no posts) with the children Apple (one published post)
	 * and Citrus (no posts), and Empty (no posts, no parent). Tags: Red (the
	 * same published post) and Green (no posts). The stock `uncategorized`
	 * category with no posts comes with every fresh install.
	 *
	 * @return array{terms: array<string, int>, posts: array<string, int>} The fixture IDs.
	 */
	private function create_data_set(): array {
		if ( ! empty( $this->term_ids ) ) {
			return array(
				'terms' => $this->term_ids,
				'posts' => $this->post_ids,
			);
		}

		$this->log_in_as( 'administrator' );

		$fruit  = $this->create_test_term( 'Fruit', 'fruit', 'category' );
		$apple  = $this->create_test_term( 'Apple', 'apple', 'category', array( 'description' => 'A <em>crisp</em> fruit.' ) );
		$citrus = $this->create_test_term( 'Citrus', 'citrus', 'category' );
		$empty  = $this->create_test_term( 'Empty', 'empty', 'category' );
		$red    = $this->create_test_term( 'Red', 'red', 'post_tag' );
		$green  = $this->create_test_term( 'Green', 'green', 'post_tag' );

		$this->term_parent( $apple, $fruit );
		$this->term_parent( $citrus, $fruit );

		$published = $this->create_test_post( 'Apple post', 'publish', $this->user_ids['administrator'] );
		wp_set_object_terms( $published, array( $apple ), 'category' );
		wp_set_object_terms( $published, array( $red ), 'post_tag' );

		$this->term_ids = array(
			'apple'  => $apple,
			'fruit'  => $fruit,
			'citrus' => $citrus,
			'empty'  => $empty,
			'red'    => $red,
			'green'  => $green,
		);
		$this->post_ids = array( 'published' => $published );

		return array(
			'terms' => $this->term_ids,
			'posts' => $this->post_ids,
		);
	}

	/**
	 * Asserts a WP_Error with the exact code, and an optional `status` data value.
	 *
	 * @param string   $code   The expected error code.
	 * @param mixed    $actual The actual result.
	 * @param int|null $status Optional. The expected `status` data value.
	 * @param string   $note   Optional. A note for the failure report.
	 */
	private function assert_error_response( string $code, $actual, ?int $status = null, string $note = '' ): void {
		self::assertInstanceOf( WP_Error::class, $actual, $note . ' Expected WP_Error ' . $code . '.' );
		self::assertSame( $code, $actual->get_error_code(), $note . ' Expected code ' . $code . ', got ' . $actual->get_error_code() . '.' );
		if ( null !== $status ) {
			self::assertSame( $status, $actual->get_error_data()['status'] ?? null, $note . ' Expected status ' . $status . '.' );
		}
	}

	/**
	 * The exposed taxonomy keys, as the ability's registration-time snapshot reads them.
	 *
	 * @return list<string> The exposed taxonomy names.
	 */
	private function exposed_taxonomies(): array {
		return array_values( get_taxonomies( array( 'show_in_abilities' => true ) ) );
	}

	/**
	 * Registered exactly once, with the PR's annotations and the experimental provenance.
	 *
	 * The `wp/` name is never core-provided, so the package copy registers on both
	 * environments and the provenance does not branch.
	 */
	public function test_registered_once_with_annotations_and_provenance(): void {
		$ability = $this->get_registered();
		$meta    = $ability->get_meta();

		self::assertSame( 'Terms Query', $ability->get_label(), 'The ability should use the PR\'s label.' );
		self::assertSame( 'content', $ability->get_category(), 'The ability should use the content category.' );
		self::assertTrue( (bool) ( $meta['show_in_rest'] ?? false ), 'The ability should be exposed in REST.' );
		self::assertSame(
			array( 'public' => true ),
			$meta['mcp'] ?? null,
			'The mcp meta opts the ability in for MCP-tool exposure; the high-level public meta is unchanged.'
		);
		self::assertFalse( $ability->get_meta_item( 'public', false ), 'The high-level public meta is unchanged.' );
		self::assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
				'open_world'  => false,
			),
			$meta['annotations'] ?? null,
			'The ability should carry the PR\'s annotations.'
		);
		self::assertSame( 'experimental', $meta['stability'] ?? null );
		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null );
		self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null );
		self::assertArrayNotHasKey( 'renamed_from', $meta, 'The port carries no renamed_from lineage.' );

		Registrar::register_all();
		Registrar::register_all();
		self::assertSame( $ability, $this->get_registered(), 'No double registration: the single instance holds.' );
	}

	/**
	 * The ability is not registered when no taxonomy is exposed: the schema would
	 * carry an invalid, empty taxonomy enum otherwise.
	 */
	public function test_should_register_requires_exposed_taxonomies(): void {
		$flagged = array();
		foreach ( $this->exposed_taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object instanceof \WP_Taxonomy ) {
				continue;
			}
			$flagged[ $taxonomy ] = $object->show_in_abilities ?? null;
			unset( $object->show_in_abilities );
		}

		try {
			self::assertSame( array(), $this->exposed_taxonomies(), 'Precondition: no taxonomy is exposed anymore.' );

			$ability = new Terms_Query();
			self::assertFalse( $ability->should_register(), 'The ability should not register without an exposed taxonomy.' );
		} finally {
			foreach ( $flagged as $taxonomy => $value ) {
				$object = get_taxonomy( $taxonomy );
				if ( ! $object instanceof \WP_Taxonomy ) {
					continue;
				}
				$object->show_in_abilities = $value; // @phpstan-ignore property.notFound (restores the dynamic flag the polyfill set)
			}
		}

		self::assertNotEmpty( $this->exposed_taxonomies(), 'Precondition restore: the taxonomies are exposed again.' );
		self::assertTrue( ( new Terms_Query() )->should_register(), 'The ability should register with exposed taxonomies.' );
	}

	/**
	 * The input schema models the three mutually exclusive modes, each rejecting the other
	 * modes' properties and accepting only the exposed taxonomies.
	 */
	public function test_input_schema_models_three_mutually_exclusive_modes(): void {
		$schema = $this->get_registered()->get_input_schema();

		self::assertSame( 'object', $schema['type'], 'The input should be an object.' );
		self::assertCount( 3, $schema['oneOf'], 'The input should have three modes.' );

		list( $by_id, $by_slug, $query ) = $schema['oneOf'];

		self::assertSame( array( 'id' ), $by_id['required'], 'The by-ID mode should require an ID.' );
		self::assertSame( array( 'taxonomy', 'slug' ), $by_slug['required'], 'The slug mode should require a taxonomy and a slug.' );
		self::assertSame( array( 'taxonomy' ), $query['required'], 'The query mode should require a taxonomy.' );
		self::assertSame( array( 'id', 'taxonomy', 'fields' ), array_keys( $by_id['properties'] ), 'The by-ID mode should take an ID, a taxonomy guard, and fields.' );
		self::assertSame( array( 'taxonomy', 'slug', 'fields' ), array_keys( $by_slug['properties'] ), 'The slug mode should take a taxonomy, a slug, and fields.' );
		self::assertArrayNotHasKey( 'slug', $query['properties'], 'The query mode should not take a slug; a slug names a single term.' );
		self::assertArrayHasKey( 'minimum', $by_id['properties']['id'], 'The ID should be bounded.' );
		self::assertArrayHasKey( 'minLength', $by_slug['properties']['slug'], 'The slug should be bounded.' );

		foreach ( $schema['oneOf'] as $mode ) {
			self::assertFalse( $mode['additionalProperties'], "The {$mode['title']} mode should reject unrelated properties." );
			self::assertSame( $this->exposed_taxonomies(), $mode['properties']['taxonomy']['enum'], "The {$mode['title']} mode should take only the exposed taxonomies." );
			self::assertSame(
				array_keys( $this->get_registered()->get_output_schema()['oneOf'][0]['properties'] ),
				$mode['properties']['fields']['items']['enum'],
				"The {$mode['title']} mode should take the fields of the term schema."
			);
		}

		$query_keys = array_keys( $query['properties'] );
		sort( $query_keys );
		self::assertSame(
			array(
				'exclude',
				'fields',
				'hide_empty',
				'include',
				'order',
				'orderby',
				'page',
				'parent',
				'per_page',
				'post',
				'search',
				'taxonomy',
			),
			$query_keys,
			'The query mode should carry the PR\'s collection parameters.'
		);

		foreach ( $schema['oneOf'] as $mode ) {
			foreach ( $mode['properties'] as $name => $property ) {
				self::assertArrayNotHasKey( 'default', $property, "The {$name} property of the {$mode['title']} mode should rely on runtime defaults." );
			}
		}
	}

	/**
	 * The collection parameters mirror the terms endpoint's values.
	 */
	public function test_collection_params_mirror_the_endpoint(): void {
		$params = $this->get_registered()->get_input_schema()['oneOf'][2]['properties'];

		self::assertSame( array( 'asc', 'desc' ), $params['order']['enum'] );
		self::assertSame(
			array( 'id', 'include', 'name', 'slug', 'term_group', 'description', 'count' ),
			$params['orderby']['enum'],
			'The collection orderings of the term endpoint; include_slugs is left out.'
		);
		self::assertSame( 1, $params['page']['minimum'] );
		self::assertSame( 1, $params['per_page']['minimum'] );
		self::assertSame( 100, $params['per_page']['maximum'], 'A page size above 100 should fail validation.' );
		self::assertSame( 'integer', $params['include']['items']['type'] );
		self::assertSame( 'integer', $params['exclude']['items']['type'] );
		self::assertSame( 'integer', $params['parent']['type'] );
		self::assertSame( 'integer', $params['post']['type'] );
		self::assertSame( 'boolean', $params['hide_empty']['type'] );
		self::assertSame( 'string', $params['search']['type'] );
	}

	/**
	 * The output schema describes single-term and query response shapes.
	 */
	public function test_output_schema_describes_single_term_and_query_responses(): void {
		$schema = $this->get_registered()->get_output_schema();

		self::assertSame( 'object', $schema['type'], 'The output should be an object.' );
		self::assertCount( 2, $schema['oneOf'], 'The output should describe single-term and query responses.' );

		list( $term, $query ) = $schema['oneOf'];

		self::assertSame(
			array( 'id', 'count', 'description', 'link', 'name', 'slug', 'taxonomy', 'parent' ),
			array_keys( $term['properties'] ),
			'The term fields should be listed in output order.'
		);
		self::assertArrayNotHasKey( 'required', $term, 'No term field should be required, since the caller picks them.' );
		self::assertFalse( $term['additionalProperties'], 'Returned terms should reject unknown properties.' );
		self::assertSame(
			array( 'terms', 'total', 'total_pages' ),
			$query['required'],
			'The query wrapper should require its list and totals.'
		);
		self::assertFalse( $query['additionalProperties'], 'The query wrapper should reject unknown properties.' );
		self::assertSame( $term, $query['properties']['terms']['items'], 'The query wrapper should list terms with the term schema.' );
	}

	/**
	 * A term is returned directly by ID, without a taxonomy.
	 */
	public function test_single_term_by_id_without_a_taxonomy(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		self::assertSame(
			array(
				'id'       => $fixture['terms']['apple'],
				'count'    => 1,
				'name'     => 'Apple',
				'slug'     => 'apple',
				'taxonomy' => 'category',
				'parent'   => $fixture['terms']['fruit'],
			),
			$this->query_terms( array( 'id' => $fixture['terms']['apple'] ) ),
			'The term should be returned directly.'
		);
	}

	/**
	 * `taxonomy` is accepted alongside `id` as a guard that the term must match.
	 */
	public function test_id_mode_accepts_the_taxonomy_guard(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$matching = $this->query_terms(
			array(
				'id'       => $fixture['terms']['apple'],
				'taxonomy' => 'category',
			)
		);
		self::assertIsArray( $matching, 'A matching taxonomy guard should return the term.' );
		self::assertSame( $fixture['terms']['apple'], $matching['id'], 'A matching taxonomy guard should return the term.' );

		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->query_terms(
				array(
					'id'       => $fixture['terms']['apple'],
					'taxonomy' => 'post_tag',
				)
			),
			null,
			'A mismatched taxonomy guard should deny the term.'
		);
	}

	/**
	 * A slug is looked up in the requested taxonomy only.
	 */
	public function test_term_by_slug_is_scoped_to_the_taxonomy(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$category = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'slug'     => 'apple',
			)
		);
		self::assertIsArray( $category, 'The category with the slug should be returned.' );
		self::assertSame( $fixture['terms']['apple'], $category['id'], 'The category with the slug should be returned.' );

		$this->create_test_term( 'Tagged', 'the-tagged-one', 'post_tag' );
		$tagged = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'slug'     => 'the-tagged-one',
			)
		);
		self::assertIsArray( $tagged, 'The tag with its own slug should be returned.' );
		self::assertSame( 'Tagged', $tagged['name'], 'Only the requested taxonomy should name the slug.' );

		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'slug'     => 'the-tagged-one',
				)
			),
			null,
			'A slug the requested taxonomy does not have should be denied.'
		);
	}

	/**
	 * A missing slug is denied.
	 */
	public function test_term_by_missing_slug_is_denied(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'slug'     => 'not-any-slug',
				)
			),
			null,
			'A missing slug should be denied.'
		);
	}

	/**
	 * Without `fields`, terms carry a lean default set, and `parent` only for hierarchical
	 * taxonomies.
	 */
	public function test_default_fields(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$category_fields = array( 'id', 'count', 'name', 'slug', 'taxonomy', 'parent' );
		$apples          = $this->query_terms( array( 'id' => $fixture['terms']['apple'] ) );
		self::assertIsArray( $apples, 'A category should be returned.' );
		self::assertSame( $category_fields, array_keys( $apples ), 'A category should have the default fields.' );

		$queried = $this->query_terms( array( 'taxonomy' => 'category' ) );
		self::assertIsArray( $queried, 'The categories should be returned.' );
		self::assertSame( $category_fields, array_keys( (array) $queried['terms'][0] ), 'A queried category should have the default fields.' );

		$tags = $this->query_terms( array( 'id' => $fixture['terms']['red'] ) );
		self::assertIsArray( $tags, 'A tag should be returned.' );
		self::assertSame( array( 'id', 'count', 'name', 'slug', 'taxonomy' ), array_keys( $tags ), 'A tag should have the default fields except the parent.' );

		self::assertArrayNotHasKey( 'description', $apples, 'The default set should omit the description.' );
		self::assertArrayNotHasKey( 'link', $apples, 'The default set should omit the link.' );
	}

	/**
	 * `fields` limits every term to the requested fields, always with the ID.
	 */
	public function test_fields_always_include_the_id(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		self::assertSame(
			array(
				'id'   => $this->term_ids['apple'],
				'name' => 'Apple',
			),
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'slug'     => 'apple',
					'fields'   => array( 'name' ),
				)
			),
			'A single term should carry the requested fields and its ID.'
		);

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'fields'   => array( 'slug' ),
			)
		);
		self::assertIsArray( $result, 'The query should succeed.' );
		foreach ( (array) $result['terms'] as $term ) {
			self::assertSame( array( 'id', 'slug' ), array_keys( (array) $term ), 'Each queried term should carry the requested fields and its ID.' );
		}
	}

	/**
	 * The description is returned as stored, and the link is the term's archive link.
	 */
	public function test_description_and_link_fields(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$result = $this->query_terms(
			array(
				'id'     => $this->term_ids['apple'],
				'fields' => array( 'description', 'link' ),
			)
		);
		self::assertIsArray( $result, 'The term should be returned.' );
		self::assertSame( 'A <em>crisp</em> fruit.', $result['description'], 'The description should be returned as stored.' );
		self::assertSame( get_term_link( $this->term_ids['apple'], 'category' ), $result['link'], 'The link should be the term archive link.' );
	}

	/**
	 * Terms of a non-hierarchical taxonomy have no parent, even when it is requested.
	 */
	public function test_parent_field_is_omitted_for_non_hierarchical_taxonomies(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$result = $this->query_terms(
			array(
				'id'     => $this->term_ids['red'],
				'fields' => array( 'parent' ),
			)
		);
		self::assertSame( array( 'id' => $this->term_ids['red'] ), $result, 'A tag should have no parent.' );
	}

	/**
	 * The collection returns the terms of the taxonomy with totals and pages.
	 */
	public function test_collection_returns_terms_totals_and_pages(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$result = $this->query_terms( array( 'taxonomy' => 'category' ) );
		self::assertIsArray( $result, 'The query should succeed.' );
		self::assertSame( array( 'terms', 'total', 'total_pages' ), array_keys( $result ), 'The collection should carry the wrapper shape.' );

		$expected = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		self::assertIsArray( $expected );
		self::assertSame( count( $expected ), $result['total'], 'The total should count every category.' );
		self::assertSame( 1, $result['total_pages'], 'A full page should be the only page.' );
		self::assertSame(
			wp_list_pluck( $expected, 'term_id' ),
			wp_list_pluck( $result['terms'], 'id' ),
			'The categories should be listed, in the order `get_terms()` returns them.'
		);

		$tags = $this->query_terms( array( 'taxonomy' => 'post_tag' ) );
		self::assertIsArray( $tags, 'The tag query should succeed.' );
		self::assertSame(
			wp_list_pluck(
				get_terms(
					array(
						'taxonomy'   => 'post_tag',
						'hide_empty' => false,
					)
				),
				'term_id'
			),
			wp_list_pluck( $tags['terms'], 'id' ),
			'The tags should be listed.'
		);
	}

	/**
	 * Collection pagination limits the page and counts the pages.
	 */
	public function test_collection_pagination_shape(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$page = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'per_page' => 2,
				'page'     => 2,
				'orderby'  => 'id',
				'order'    => 'desc',
			)
		);
		self::assertIsArray( $page, 'The paginated query should succeed.' );

		$all = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'id',
				'order'      => 'desc',
			)
		);
		self::assertIsArray( $all );

		$ids = wp_list_pluck( $all, 'term_id' );

		self::assertSame( array_slice( $ids, 2, 2 ), wp_list_pluck( $page['terms'], 'id' ), 'The second page of two should hold the next two terms.' );
		self::assertSame( count( $all ), $page['total'], 'The total should span all the pages.' );
		self::assertSame( (int) ceil( count( $all ) / 2 ), $page['total_pages'], 'The page total should be computed from the page size.' );
	}

	/**
	 * `include` limits and orders the collection; `exclude` removes IDs.
	 */
	public function test_include_and_exclude_filters(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$included = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'include'  => array( $fixture['terms']['citrus'], $fixture['terms']['fruit'] ),
				'orderby'  => 'include',
			)
		);
		self::assertIsArray( $included, 'The include query should succeed.' );
		self::assertSame(
			array( $fixture['terms']['citrus'], $fixture['terms']['fruit'] ),
			wp_list_pluck( $included['terms'], 'id' ),
			'The include order should be kept.'
		);
		self::assertSame( 2, $included['total'], 'The total should count the included terms.' );

		$excluded = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'exclude'  => array( $fixture['terms']['red'] ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
			)
		);
		self::assertIsArray( $excluded, 'The exclude query should succeed.' );
		self::assertNotContains( $fixture['terms']['red'], wp_list_pluck( $excluded['terms'], 'id' ), 'The excluded tag should be withheld.' );
	}

	/**
	 * `search` limits the collection to matching names and slugs. A match in a
	 * description alone does not count: term search covers the name and the slug,
	 * exactly like the REST terms controller.
	 */
	public function test_search_filter(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'search'   => 'Red',
			)
		);
		self::assertIsArray( $result, 'The search query should succeed.' );
		self::assertSame( array( $this->term_ids['red'] ), wp_list_pluck( $result['terms'], 'id' ), 'The search should match the name.' );

		$slugs = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'search'   => 'cit',
			)
		);
		self::assertIsArray( $slugs, 'The search query should succeed.' );
		self::assertSame( array( $this->term_ids['citrus'] ), wp_list_pluck( $slugs['terms'], 'id' ), 'The search should match the slug.' );

		$descriptions = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'search'   => 'crisp',
			)
		);
		self::assertIsArray( $descriptions, 'The search query should succeed.' );
		self::assertSame( array(), $descriptions['terms'], 'A description-only match should not count.' );
		self::assertSame( 0, $descriptions['total'], 'A description-only match should not count in the total.' );
	}

	/**
	 * `hide_empty` keeps an empty parent of a term with posts, but the total leaves it out.
	 */
	public function test_hide_empty_keeps_empty_parents_of_non_empty_terms(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$result = $this->query_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
			)
		);
		self::assertIsArray( $result, 'The hide_empty query should succeed.' );
		self::assertSame(
			array( $fixture['terms']['apple'], $fixture['terms']['fruit'] ),
			wp_list_pluck( $result['terms'], 'id' ),
			'The category with posts and its empty parent should be returned, and the empty categories withheld.'
		);
		self::assertSame( 1, $result['total'], 'The total should count only the category with posts.' );
	}

	/**
	 * `parent` limits a hierarchical collection to a parent's children, or to top-level terms.
	 */
	public function test_parent_filter(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$children = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'parent'   => $fixture['terms']['fruit'],
			)
		);
		self::assertIsArray( $children, 'The parent query should succeed.' );
		self::assertSame(
			array( $fixture['terms']['apple'], $fixture['terms']['citrus'] ),
			wp_list_pluck( $children['terms'], 'id' ),
			'The children of the parent should be returned.'
		);
		self::assertSame( 2, $children['total'], 'The total should count the children.' );

		$top_level = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'parent'   => 0,
			)
		);
		self::assertIsArray( $top_level, 'The top-level query should succeed.' );
		self::assertNotContains( $fixture['terms']['apple'], wp_list_pluck( $top_level['terms'], 'id' ), 'A child should not be a top-level term.' );
		self::assertContains( $fixture['terms']['fruit'], wp_list_pluck( $top_level['terms'], 'id' ), 'A parent should be a top-level term.' );
	}

	/**
	 * `parent` is ignored for taxonomies that are not hierarchical, like REST.
	 */
	public function test_parent_filter_is_ignored_for_non_hierarchical_taxonomies(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		self::assertSame(
			$this->query_terms( array( 'taxonomy' => 'post_tag' ) ),
			$this->query_terms(
				array(
					'taxonomy' => 'post_tag',
					'parent'   => $fixture['terms']['fruit'],
				)
			),
			'A parent filter should not change the tags returned.'
		);
	}

	/**
	 * `post` limits the collection to the terms assigned to a readable post.
	 */
	public function test_post_filter(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => $fixture['posts']['published'],
			)
		);
		self::assertIsArray( $result, 'The post-filtered query should succeed.' );
		self::assertSame( array( $fixture['terms']['apple'] ), wp_list_pluck( $result['terms'], 'id' ), 'The assigned category should be returned.' );
		self::assertSame( 1, $result['total'], 'The total should count the assigned category.' );

		$tags = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'post'     => $fixture['posts']['published'],
			)
		);
		self::assertIsArray( $tags, 'The post-filtered tag query should succeed.' );
		self::assertSame( array( $fixture['terms']['red'] ), wp_list_pluck( $tags['terms'], 'id' ), 'The assigned tag should be returned.' );
		self::assertArrayNotHasKey( 'parent', (array) $tags['terms'][0], 'A tag has no parent field.' );
	}

	/**
	 * The terms of a private post or a draft need read access to the post.
	 */
	public function test_post_filter_requires_read_access_to_the_post(): void {
		$fixture = $this->create_data_set();

		$admin   = $this->log_in_as( 'administrator' );
		$private = $this->create_test_post( 'Private fixture', 'private' );
		$draft   = $this->create_test_post( 'Draft fixture', 'draft', $admin );
		wp_set_object_terms( $private, array( $fixture['terms']['apple'] ), 'category' );
		wp_set_object_terms( $draft, array( $fixture['terms']['fruit'] ), 'category' );

		$this->log_in_as( 'subscriber' );
		$this->assert_error_response(
			'terms_forbidden_context',
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'post'     => $private,
				)
			),
			403,
			'A subscriber should not read the terms of a private post.'
		);
		$this->assert_error_response(
			'terms_forbidden_context',
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'post'     => $draft,
				)
			),
			403,
			'A subscriber should not read the terms of another user\'s draft.'
		);

		$this->log_in_as( 'editor' );
		$private_result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => $private,
			)
		);
		self::assertIsArray( $private_result, 'An editor should read the terms of a private post.' );
		self::assertSame( array( $fixture['terms']['apple'] ), wp_list_pluck( $private_result['terms'], 'id' ), 'An editor should read the terms of a private post.' );

		$draft_result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => $draft,
			)
		);
		self::assertIsArray( $draft_result, 'An editor should read the terms of the draft.' );
		self::assertSame( array( $fixture['terms']['fruit'] ), wp_list_pluck( $draft_result['terms'], 'id' ), 'An editor should read the terms of the draft.' );
	}

	/**
	 * The terms of a post whose type does not use the taxonomy are forbidden.
	 */
	public function test_post_filter_on_a_post_without_the_taxonomy_is_forbidden(): void {
		$this->log_in_as( 'administrator' );
		$this->create_data_set();

		$page_id = $this->create_test_post( 'A page', 'publish', 0, 'page' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => $page_id,
			)
		);
		$this->assert_error_response( 'terms_forbidden_context', $result, 403, 'Pages do not use categories.' );
	}

	/**
	 * A missing post is reported as invalid; like REST, the post's own read access counts,
	 * and the permission callback leaves a post error to the execute callback.
	 */
	public function test_post_filter_on_a_missing_post_is_invalid(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => self::IMPOSSIBLY_HIGH_NUMBER,
			)
		);
		$this->assert_error_response( 'terms_post_invalid_id', $result, 400, 'A missing post should be reported as invalid.' );

		self::assertTrue(
			$this->permission_raw(
				array(
					'taxonomy' => 'category',
					'post'     => self::IMPOSSIBLY_HIGH_NUMBER,
				)
			),
			'The permission callback should leave a post error to the execute callback.'
		);
	}

	/**
	 * Every logged-in role can read terms in every mode, and a logged-out visitor cannot.
	 */
	public function test_roles_reading_terms(): void {
		$fixture = $this->create_data_set();

		$inputs = array(
			'by ID'   => array( 'id' => $fixture['terms']['apple'] ),
			'by slug' => array(
				'taxonomy' => 'category',
				'slug'     => 'apple',
			),
			'query'   => array( 'taxonomy' => 'post_tag' ),
		);

		foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
			$this->log_in_as( $role );
			foreach ( $inputs as $mode => $input ) {
				self::assertIsArray( $this->query_terms( $input ), "A {$role} should read terms {$mode}." );
			}
		}

		wp_set_current_user( 0 );
		foreach ( $inputs as $mode => $input ) {
			$this->assert_error_response( 'ability_invalid_permissions', $this->query_terms( $input ), null, "A logged-out visitor should not read terms {$mode}." );
		}
	}

	/**
	 * The permission callback requires a logged-in user before anything else.
	 */
	public function test_logged_out_denied_before_the_term_is_resolved(): void {
		$fixture = $this->create_data_set();

		wp_set_current_user( 0 );

		$this->assert_error_response( 'ability_invalid_permissions', $this->query_terms( array( 'id' => $fixture['terms']['apple'] ) ), null, 'A logged-out visitor should be denied.' );
		self::assertFalse(
			$this->permission_raw( array( 'id' => $fixture['terms']['apple'] ) ),
			'The permission callback should deny a logged-out visitor.'
		);
	}

	/**
	 * A taxonomy that does not opt in is not exposed: its collection is rejected by the
	 * schema, and its terms are denied like missing ones.
	 */
	public function test_unexposed_taxonomy_is_denied(): void {
		$this->create_data_set();

		$this->log_in_as( 'administrator' );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->query_terms( array( 'taxonomy' => 'nav_menu' ) ),
			null,
			'Menus should not be queryable.'
		);

		$menu_id = wp_create_nav_menu( 'Main' );
		self::assertNotInstanceOf( WP_Error::class, $menu_id );

		$result = $this->query_terms( array( 'id' => (int) $menu_id ) );
		$this->assert_error_response( 'ability_invalid_permissions', $result, null, 'A menu term should be denied.' );

		$direct = $this->execute_raw( array( 'taxonomy' => 'nav_menu' ) );
		$this->assert_error_response( 'terms_forbidden', $direct, 403, 'A direct call for a hidden taxonomy should fail closed.' );

		wp_delete_nav_menu( (int) $menu_id );
	}

	/**
	 * A term of a hidden taxonomy is denied exactly like a missing term, so its existence
	 * cannot be probed.
	 */
	public function test_hidden_and_missing_terms_are_denied_alike(): void {
		$this->create_data_set();

		register_taxonomy( 'tq_secret', 'post', array( 'show_in_rest' => true ) );
		$this->registered_taxonomies[] = 'tq_secret';
		$hidden                        = $this->create_test_term( 'Whisper', 'whisper', 'tq_secret' );

		$this->log_in_as( 'administrator' );

		$missing_result = $this->query_terms( array( 'id' => self::IMPOSSIBLY_HIGH_NUMBER ) );
		$this->assert_error_response( 'ability_invalid_permissions', $missing_result, null, 'A missing term should be denied.' );

		$direct_missing = $this->execute_raw( array( 'id' => self::IMPOSSIBLY_HIGH_NUMBER ) );
		$direct_hidden  = $this->execute_raw( array( 'id' => $hidden ) );
		$this->assert_error_response( 'terms_term_invalid', $direct_missing, 404, 'A direct call should report a missing term as invalid.' );
		self::assertEquals(
			$direct_missing,
			$direct_hidden,
			'A direct call should report a hidden term exactly like a missing one.'
		);
	}

	/**
	 * A taxonomy that opts in is exposed even when it is not shown in REST; a non-public
	 * taxonomy can opt in; and a `show_in_rest`-only, or explicitly opted-out, taxonomy stays
	 * out.
	 */
	public function test_taxonomy_opt_in_and_opt_out(): void {
		$this->log_in_as( 'subscriber' );

		register_taxonomy(
			'tq_genre',
			'post',
			array(
				'show_in_abilities' => true,
				'show_in_rest'      => false,
			)
		);
		register_taxonomy( 'tq_secret', 'post', array( 'show_in_rest' => true ) );
		register_taxonomy(
			'tq_optout',
			'post',
			array(
				'show_in_rest'      => true,
				'show_in_abilities' => false,
			)
		);
		$this->registered_taxonomies = array( 'tq_genre', 'tq_secret', 'tq_optout' );

		$genre_term  = $this->create_test_term( 'Noir', 'noir', 'tq_genre' );
		$secret_term = $this->create_test_term( 'Whisper', 'whisper', 'tq_secret' );

		$enum = ( new Terms_Query() )->get_input_schema()['oneOf'][0]['properties']['taxonomy']['enum'];
		self::assertContains( 'tq_genre', $enum, 'An opt-in taxonomy should be exposed even without REST.' );
		self::assertNotContains( 'tq_secret', $enum, 'A show_in_rest-only taxonomy should not be exposed.' );
		self::assertNotContains( 'tq_optout', $enum, 'An opted-out taxonomy should not be exposed.' );

		/*
		 * The taxonomy was registered after this boot, so the registry's schema
		 * snapshot (the validated transport's enum) predates it; the direct
		 * transport of this test file exercises the runtime path instead, and
		 * the enum above pins the fresh registration-time snapshot.
		 */
		$result = $this->execute_raw( array( 'taxonomy' => 'tq_genre' ) );
		self::assertIsArray( $result, 'The opted-in taxonomy should be queryable.' );
		self::assertSame( array( $genre_term ), wp_list_pluck( $result['terms'], 'id' ), 'The terms of the taxonomy should be returned.' );

		$by_id = $this->query_terms( array( 'id' => $genre_term ) );
		self::assertIsArray( $by_id, 'A term of the taxonomy should be returned by ID.' );
		self::assertSame( 'tq_genre', $by_id['taxonomy'], 'A term of the taxonomy should be returned by ID.' );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->query_terms( array( 'taxonomy' => 'tq_secret' ) ),
			null,
			'A show_in_rest-only taxonomy should not be accepted.'
		);
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->query_terms( array( 'id' => $secret_term ) ),
			null,
			'A term of an unexposed taxonomy should be denied.'
		);
	}

	/**
	 * The ability names a taxonomy by its key, whatever its REST base and namespace.
	 */
	public function test_taxonomy_is_named_by_its_key_not_its_rest_base(): void {
		$this->log_in_as( 'subscriber' );

		register_taxonomy(
			'tq_genre',
			'post',
			array(
				'show_in_abilities' => true,
				'show_in_rest'      => true,
				'rest_base'         => 'genres',
				'rest_namespace'    => 'tq/v1',
			)
		);
		$this->registered_taxonomies[] = 'tq_genre';

		$term = $this->create_test_term( 'Noir', 'noir', 'tq_genre' );

		$enum = ( new Terms_Query() )->get_input_schema()['oneOf'][0]['properties']['taxonomy']['enum'];
		self::assertContains( 'tq_genre', $enum, 'The taxonomy key should be in the enum.' );

		// The taxonomy registered after this boot: the direct transport exercises the runtime path.
		$result = $this->execute_raw( array( 'taxonomy' => 'tq_genre' ) );
		self::assertIsArray( $result, 'The taxonomy key should query the terms.' );
		self::assertSame( array( $term ), wp_list_pluck( $result['terms'], 'id' ), 'The taxonomy key should query the terms.' );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->query_terms( array( 'taxonomy' => 'genres' ) ),
			null,
			'The REST base should not name the taxonomy.'
		);
	}

	/**
	 * `per_page` is limited to 100, like REST.
	 */
	public function test_per_page_is_limited_to_100(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		self::assertIsArray(
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'per_page' => 100,
				)
			),
			'A page size of 100 should be accepted.'
		);
		$this->assert_error_response(
			'ability_invalid_input',
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'per_page' => 101,
				)
			),
			null,
			'A page size above 100 should fail validation.'
		);
	}

	/**
	 * String inputs, as a GET request delivers them, give the same results as typed inputs.
	 */
	public function test_string_inputs_match_typed_inputs(): void {
		$fixture = $this->create_data_set();

		$apple = $fixture['terms']['apple'];
		$fruit = $fixture['terms']['fruit'];
		$tag   = $fixture['terms']['red'];
		$post  = $fixture['posts']['published'];

		$cases = array(
			'id'            => array( array( 'id' => (string) $apple ), array( 'id' => $apple ) ),
			'taxonomy'      => array(
				array(
					'id'       => (string) $apple,
					'taxonomy' => 'category',
				),
				array(
					'id'       => $apple,
					'taxonomy' => 'category',
				),
			),
			'page and size' => array(
				array(
					'taxonomy' => 'category',
					'page'     => '1',
					'per_page' => '1',
				),
				array(
					'taxonomy' => 'category',
					'page'     => 1,
					'per_page' => 1,
				),
			),
			'hide_empty 1'  => array(
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => 'true',
				),
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => true,
				),
			),
			'hide_empty 0'  => array(
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => 'false',
				),
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => false,
				),
			),
			'include'       => array(
				array(
					'taxonomy' => 'category',
					'include'  => $fruit . ',' . $apple,
					'orderby'  => 'include',
				),
				array(
					'taxonomy' => 'category',
					'include'  => array( $fruit, $apple ),
					'orderby'  => 'include',
				),
			),
			'exclude'       => array(
				array(
					'taxonomy' => 'post_tag',
					'exclude'  => (string) $tag, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
				),
				array(
					'taxonomy' => 'post_tag',
					'exclude'  => array( $tag ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
				),
			),
			'parent'        => array(
				array(
					'taxonomy' => 'category',
					'parent'   => (string) $fruit,
				),
				array(
					'taxonomy' => 'category',
					'parent'   => $fruit,
				),
			),
			'post'          => array(
				array(
					'taxonomy' => 'category',
					'post'     => (string) $post,
				),
				array(
					'taxonomy' => 'category',
					'post'     => $post,
				),
			),
			'fields'        => array(
				array(
					'id'     => $apple,
					'fields' => 'id,name',
				),
				array(
					'id'     => $apple,
					'fields' => array( 'id', 'name' ),
				),
			),
		);

		foreach ( $cases as $name => $pair ) {
			list( $string_input, $typed_input ) = $pair;

			$expected = $this->query_terms( $typed_input );
			self::assertIsArray( $expected, "The typed {$name} input should succeed." );
			self::assertSame( $expected, $this->query_terms( $string_input ), "The string {$name} input should give the same result as the typed input." );
		}

		// The string inputs take effect, rather than matching an unfiltered query.
		$include = $this->query_terms( $cases['include'][0] );
		self::assertIsArray( $include, 'The string include should succeed.' );
		self::assertSame( array( $fruit, $apple ), wp_list_pluck( $include['terms'], 'id' ), 'The string include should limit and order the terms.' );
	}

	/**
	 * The query mode rejects REST parameters the ability leaves out, by schema.
	 */
	public function test_rest_only_params_are_rejected(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$rejected = array(
			'context'      => array( 'context' => 'edit' ),
			'offset'       => array( 'offset' => 1 ),
			'_fields'      => array( '_fields' => 'id' ),
			'slug list'    => array( 'slug' => array( 'apple' ) ),
			'meta field'   => array( 'fields' => array( 'meta' ) ),
			'id and query' => array(
				'id'     => $this->term_ids['apple'],
				'search' => 'App',
			),
		);

		foreach ( $rejected as $note => $params ) {
			$this->assert_error_response(
				'ability_invalid_input',
				$this->query_terms( array_merge( array( 'taxonomy' => 'category' ), $params ) ),
				null,
				"A REST-only parameter should fail validation ({$note})."
			);
		}
	}

	/**
	 * Modes cannot be mixed: an ID and a slug, an ID with collection parameters, a slug
	 * without a taxonomy, and a query without a taxonomy all fail validation.
	 */
	public function test_mode_mixes_fail_validation(): void {
		$fixture = $this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$cannot_validate = array(
			'id and slug'    => array(
				'id'       => $fixture['terms']['apple'],
				'taxonomy' => 'category',
				'slug'     => 'apple',
			),
			'id with size'   => array(
				'id'       => $fixture['terms']['apple'],
				'per_page' => 5,
			),
			'slug with size' => array(
				'taxonomy' => 'category',
				'slug'     => 'apple',
				'per_page' => 2,
			),
			'slug alone'     => array( 'slug' => 'apple' ),
			'empty input'    => array(),
			'search alone'   => array( 'search' => 'App' ),
			'fields alone'   => array( 'fields' => array( 'id' ) ),
		);

		foreach ( $cannot_validate as $mode => $input ) {
			$this->assert_error_response( 'ability_invalid_input', $this->query_terms( $input ), null, "The {$mode} mix should fail validation." );
		}
	}

	/**
	 * Malformed input fails closed through the schema.
	 */
	public function test_malformed_input_fails_closed(): void {
		$this->log_in_as( 'administrator' );

		$invalid = array(
			'a list ID'              => array( 'id' => array( 1 ) ),
			'a zero ID'              => array( 'id' => 0 ),
			'an object slug'         => array(
				'taxonomy' => 'category',
				'slug'     => array( 'not', 'a', 'slug' ),
			),
			'an unknown taxonomy'    => array( 'taxonomy' => 'tq_unknown' ),
			'an unknown field'       => array(
				'id'     => 1,
				'fields' => array( 'bogus' ),
			),
			'a repeated field'       => array(
				'id'     => 1,
				'fields' => array( 'name', 'name' ),
			),
			'an invalid order'       => array(
				'taxonomy' => 'category',
				'order'    => 'ascending',
			),
			'an invalid orderby'     => array(
				'taxonomy' => 'category',
				'orderby'  => 'title',
			),
			'an invalid hide_empty'  => array(
				'taxonomy'   => 'category',
				'hide_empty' => 'sometimes',
			),
			'an invalid page'        => array(
				'taxonomy' => 'category',
				'page'     => 0,
			),
			'an invalid post filter' => array(
				'taxonomy' => 'category',
				'post'     => array( 1 ),
			),
			'a list search'          => array(
				'taxonomy' => 'category',
				'search'   => array( 'a' ),
			),
		);

		foreach ( $invalid as $note => $input ) {
			$this->assert_error_response( 'ability_invalid_input', $this->query_terms( $input ), null, "Malformed input ({$note}) should fail schema validation." );
		}
	}

	/**
	 * Non-object input fails closed, too.
	 */
	public function test_non_object_input_fails_closed(): void {
		$this->log_in_as( 'administrator' );

		$this->assert_error_response( 'ability_invalid_input', $this->get_registered()->execute( 'taxonomy=category' ), null, 'Non-object input should fail schema validation.' );
	}

	/**
	 * The taxonomy exposure support feature: the polyfill marks the curated taxonomies on
	 * every host that lacks the flag (all currently supported ones), and the feature
	 * boundary flips natively once a core declares the flag on WP_Taxonomy.
	 */
	public function test_taxonomy_exposure_feature(): void {
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			self::assertNotEmpty( $object->show_in_abilities ?? false, "The {$taxonomy} taxonomy should be exposed to abilities." );
		}

		self::assertTrue(
			Features::is_available( 'taxonomy_show_in_abilities' ),
			'After the polyfill loaded, the feature should report available.'
		);

		if ( property_exists( 'WP_Taxonomy', 'show_in_abilities' ) ) {
			// A future core declares the flag natively: the detection needs no polyfill.
			self::assertTrue( Features::is_available( 'taxonomy_show_in_abilities' ), 'A host declaring the flag natively does not need the polyfill.' );
			return;
		}

		// The catch-up marks the built-in taxonomies that registered before the polyfill loaded.
		self::assertContains( 'category', $this->exposed_taxonomies(), 'The catch-up should expose the built-in taxonomy.' );
		self::assertContains( 'post_tag', $this->exposed_taxonomies(), 'The catch-up should expose the built-in taxonomy.' );
	}

	/**
	 * The run endpoint takes the input from the query string of a GET request, and rejects
	 * other methods.
	 */
	public function test_run_endpoint_takes_query_string_input(): void {
		$this->create_data_set();

		$this->log_in_as( 'subscriber' );

		$route   = '/wp-abilities/v1/abilities/wp/terms-query/run';
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_query_params(
			array(
				'input' => array(
					'taxonomy' => 'post_tag',
					'per_page' => '1',
					'orderby'  => 'id',
					'order'    => 'asc',
					'fields'   => 'id,name',
				),
			)
		);
		$response = rest_get_server()->dispatch( $request );

		self::assertSame( 200, $response->get_status(), 'A GET request should run the ability.' );
		$data = $response->get_data();
		self::assertIsArray( $data, 'The response should carry the collection.' );
		self::assertSame( 1, count( $data['terms'] ?? array() ), 'The query string input should be applied.' );
		self::assertArrayHasKey( 'total', $data, 'The query string totals should be present.' );
		self::assertArrayHasKey( 'total_pages', $data, 'The query string totals should be present.' );
		self::assertSame( array( 'id', 'name' ), array_keys( (array) $data['terms'][0] ), 'The CSV fields should be applied.' );

		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_body_params( array( 'input' => array( 'taxonomy' => 'category' ) ) );
		$response = rest_get_server()->dispatch( $request );

		self::assertSame( 405, $response->get_status(), 'A POST request should be rejected.' );
		$error = $response->as_error();
		self::assertInstanceOf( WP_Error::class, $error, 'A POST request should be rejected as an error.' );
		self::assertSame( 'rest_ability_invalid_method', $error->get_error_code(), 'A read-only ability should require GET.' );
	}
}
