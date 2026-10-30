<?php
/**
 * Tests: the term write abilities `wp/term-create`, `wp/term-update`, and
 * `wp/term-delete` (port of WordPress/ai PR #1103's term tests, on this
 * package's per-ability contract shape).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev) and stable
 * (6.9/7.1). The `wp/` names are never core-provided, so the package copies
 * register and execute identically on both; the source PR's terms tests are
 * ported on the harness conventions the terms-query suite established, with
 * the `default_category` self-check carried over (core's `map_meta_cap()`
 * refusal and `wp_delete_term()`'s return-0 guard are asserted directly, as
 * the upstream tests do, instead of via a fixture helper).
 *
 * The role fixtures mirror the upstream `data_term_roles` table: under the
 * default term capabilities, editors and administrators manage terms
 * (assign, edit, delete), authors and contributors can only assign them, and
 * subscribers and logged-out visitors can do neither.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use WP_Abilities_Registry;
use WP_Ability;
use WP_Error;
use WP_Taxonomy;
use WP_Term;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Support\Features;
use WordPress\Abilities\Terms\Term_Create;
use WordPress\Abilities\Terms\Term_Delete;
use WordPress\Abilities\Terms\Term_Update;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for the term write abilities.
 */
final class TermWritesTest extends TestCase {

	/**
	 * An ID no WordPress install reaches, the REST tests' impossible number.
	 */
	private const IMPOSSIBLY_HIGH_NUMBER = 4294967296;

	/**
	 * The term fixtures: slug pairs of ID and taxonomy.
	 *
	 * @var list<array{id: int, taxonomy: string}>
	 */
	private array $fixture_terms = array();

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
	 * Settles the registry as the other ability suites do: registering before
	 * the boot so the Registrar's preload and category declarations are queued
	 * in time; the write entries require polyfilled taxonomy exposure on every
	 * currently supported WordPress.
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
	 * Removes the fixtures, restores the current user and capability filters,
	 * and re-registers the writes against the boot-time taxonomy snapshot, so
	 * each test builds a fresh data set and the schemas never carry a test's
	 * custom taxonomies over.
	 */
	public function tear_down(): void {
		foreach ( $this->fixture_terms as $fixture ) {
			wp_delete_term( $fixture['id'], $fixture['taxonomy'] );
		}
		$this->fixture_terms = array();

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

		remove_all_filters( 'map_meta_cap' );

		if ( Features::is_available( 'taxonomy_show_in_abilities' ) ) {
			$this->re_register_writes();
		}

		parent::tear_down();
	}

	/**
	 * Re-registers the three write abilities against the current taxonomy
	 * snapshot, as the source PR's tests re-register after registering
	 * taxonomies: the input schemas' taxonomy enums hold registration time.
	 *
	 * @return void
	 */
	private function re_register_writes(): void {
		/*
		 * `wp_register_ability()` is only valid within the `wp_abilities_api_init`
		 * action, so the re-registration fakes the action context as the source
		 * PR's tests do, restoring the filter stack when done. The classes are
		 * registered directly through the `ability_class` mechanism the
		 * Registrar's Gate 8 uses (the Registrar would report its boot-time
		 * bookkeeping instead of re-registering on a replaced name).
		 */
		$entries = array(
			'wp/term-create' => Term_Create::class,
			'wp/term-update' => Term_Update::class,
			'wp/term-delete' => Term_Delete::class,
		);

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			foreach ( $entries as $ability_name => $class ) {
				if ( null !== WP_Abilities_Registry::get_instance()->get_registered( $ability_name ) ) {
					wp_unregister_ability( $ability_name );
				}

				wp_register_ability(
					$ability_name,
					array(
						'ability_class' => $class,
						'category'      => 'content',
						'meta'          => array( 'stability' => 'experimental' ),
					)
				);
				self::assertNotNull(
					WP_Abilities_Registry::get_instance()->get_registered( $ability_name ),
					"The {$ability_name} ability should be registered."
				);
			}
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * The curated taxonomies the write abilities cover, as the term endpoints do.
	 *
	 * @return list<string> The taxonomy names.
	 */
	private function core_taxonomies(): array {
		return array( 'category', 'post_tag' );
	}

	/**
	 * The roles from administrator to a logged-out visitor, with the term
	 * capabilities they have under the default taxonomy capabilities.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool}> The role, or an empty string for a logged-out visitor, whether it can manage terms, and whether it can assign them.
	 */
	private function term_roles(): array {
		return array(
			'administrator' => array( 'administrator', true, true ),
			'editor'        => array( 'editor', true, true ),
			'author'        => array( 'author', false, true ),
			'contributor'   => array( 'contributor', false, true ),
			'subscriber'    => array( 'subscriber', false, false ),
			'logged out'    => array( '', false, false ),
		);
	}

	/**
	 * The registered write ability instance.
	 *
	 * @param string $name The ability name.
	 * @return WP_Ability The registered ability.
	 */
	private function get_registered( string $name ): WP_Ability {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( $name );
		self::assertNotNull( $ability, "The {$name} ability should be registered through the Registrar." );

		return $ability;
	}

	/**
	 * Runs a registered ability through the Abilities API and returns the result.
	 *
	 * The validated transport, as the run route serves it: schema-validated
	 * input, permission check, then the execute callback.
	 *
	 * @param string               $name  The ability name.
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function execute( string $name, array $input = array() ) {
		return wp_get_ability( $name )->execute( $input );
	}

	/**
	 * Calls a write ability's execute callback directly (the unvalidated
	 * transport the source PR's direct-call tests use).
	 *
	 * @param string               $ability_name The ability name.
	 * @param array<string, mixed> $input         The ability input.
	 * @return mixed The callback result.
	 */
	private function execute_raw( string $ability_name, array $input = array() ) {
		return $this->get_registered( $ability_name )->{$this->execute_method_for( $ability_name )}( $input );
	}

	/**
	 * The execute callback method of a write ability.
	 *
	 * @param string $ability_name The ability name.
	 * @return string The method name.
	 */
	private function execute_method_for( string $ability_name ): string {
		$methods = array(
			'wp/term-create' => 'execute_term_create',
			'wp/term-update' => 'execute_term_update',
			'wp/term-delete' => 'execute_term_delete',
		);

		return $methods[ $ability_name ];
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
					'user_login' => 'tw_' . $role,
					'user_pass'  => wp_generate_password(),
					'role'       => $role,
				)
			);
		}
		wp_set_current_user( $this->user_ids[ $role ] );

		return $this->user_ids[ $role ];
	}

	/**
	 * Creates a mocked term, tracked for removal.
	 *
	 * @param string               $taxonomy The taxonomy.
	 * @param string               $name     The term name.
	 * @param array<string, mixed> $args     Optional. Term arguments, e.g. slug, parent.
	 * @return int The term ID.
	 */
	private function create_test_term( string $taxonomy, string $name, array $args = array() ): int {
		$created = wp_insert_term( $name, $taxonomy, $args );
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
	 * Tracks a term ID for the teardown.
	 *
	 * @param int    $id       The term ID.
	 * @param string $taxonomy The taxonomy.
	 * @return void
	 */
	private function track_term( int $id, string $taxonomy ): void {
		$this->fixture_terms[] = array(
			'id'       => $id,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Registers the custom taxonomies of the write tests and re-registers the
	 * writes so the schema enums include them, matching the source PR's test
	 * harness — the teardown then tracks them.
	 *
	 * `wpai_genre` is exposed and hierarchical, `wpai_mood` is exposed and flat,
	 * and `wpai_secret` is shown in REST but not exposed to abilities. All three
	 * use the default term capabilities.
	 *
	 * @return void
	 */
	private function register_write_test_taxonomies(): void {
		register_taxonomy(
			'wpai_genre',
			'post',
			array(
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_in_abilities' => true,
			)
		);
		register_taxonomy(
			'wpai_mood',
			'post',
			array(
				'show_in_rest'      => true,
				'show_in_abilities' => true,
			)
		);
		register_taxonomy(
			'wpai_secret',
			'post',
			array(
				'hierarchical' => true,
				'show_in_rest' => true,
			)
		);

		$this->registered_taxonomies = array( 'wpai_genre', 'wpai_mood', 'wpai_secret' );
		$this->re_register_writes();
	}

	/**
	 * Asserts the generic ability denial the validated transport reports.
	 *
	 * @param mixed  $actual The result.
	 * @param string $note   A note for the failure report.
	 * @return void
	 */
	private function assert_denied( $actual, string $note = '' ): void {
		self::assertInstanceOf( WP_Error::class, $actual, $note . ' Expected the generic permission denial.' );
		self::assertSame(
			'ability_invalid_permissions',
			$actual->get_error_code(),
			$note . ' Expected the generic permission denial code.'
		);
	}

	/**
	 * Asserts a WP_Error with the exact code, and an optional `status` data value.
	 *
	 * @param mixed    $actual The actual result.
	 * @param string   $code   The expected error code.
	 * @param int|null $status Optional. The expected `status` data value.
	 * @param string   $note   Optional. A note for the failure report.
	 * @return void
	 */
	private function assert_error( $actual, string $code, ?int $status = null, string $note = '' ): void {
		self::assertInstanceOf( WP_Error::class, $actual, $note . ' Expected WP_Error ' . $code . '.' );
		self::assertSame( $code, $actual->get_error_code(), $note . ' Expected code ' . $code . ', got ' . $actual->get_error_code() . '.' );
		if ( null !== $status ) {
			self::assertSame( $status, $actual->get_error_data()['status'] ?? null, $note . ' Expected status ' . $status . '.' );
		}
	}

	/**
	 * The three abilities are registered exactly as the source PR does: the
	 * labels, the content category, the exposure, the annotations (the POST
	 * semantics of create and update, the DELETE semantics of delete), the
	 * schemas, and the answer shape of the query the writes answer through.
	 */
	public function test_registered_once_with_the_pr_contract(): void {
		$create = $this->get_registered( 'wp/term-create' );
		$update = $this->get_registered( 'wp/term-update' );
		$delete = $this->get_registered( 'wp/term-delete' );

		self::assertSame( 'Term Create', $create->get_label() );
		self::assertSame( 'Term Update', $update->get_label() );
		self::assertSame( 'Term Delete', $delete->get_label() );

		foreach ( array( $create, $update, $delete ) as $ability ) {
			self::assertSame( 'content', $ability->get_category(), 'The write abilities share the query\'s content category.' );
			self::assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The write abilities should be exposed in REST.' );
			self::assertSame( array( 'public' => true ), $ability->get_meta_item( 'mcp', array() ), 'The mcp meta opts the write abilities in for MCP-tool exposure.' );
			self::assertFalse( $ability->get_meta_item( 'public', false ), 'The write abilities\' high-level public meta is unchanged.' );
		}

		$create_annotations = $create->get_meta_item( 'annotations', array() );
		self::assertFalse( $create_annotations['readonly'], 'Create is a write.' );
		self::assertFalse( $create_annotations['destructive'], 'Creating a term overwrites nothing.' );
		self::assertFalse( $create_annotations['idempotent'], 'Every call creates a new term, so create is served over POST.' );
		self::assertFalse( $create_annotations['open_world'], 'The ability only writes to the local database.' );

		$update_annotations = $update->get_meta_item( 'annotations', array() );
		self::assertTrue( $update_annotations['destructive'], 'Overwritten values are not kept.' );
		self::assertFalse( $update_annotations['idempotent'], 'Destructive idempotent abilities are served over DELETE, which carries the input in the query string, so the update stays on POST.' );
		self::assertFalse( $update_annotations['open_world'], 'The ability only writes to the local database.' );

		$delete_annotations = $delete->get_meta_item( 'annotations', array() );
		self::assertTrue( $delete_annotations['destructive'], 'Delete is destructive.' );
		self::assertTrue( $delete_annotations['idempotent'], 'Repeating a deletion has no further effect; the Abilities API serves destructive idempotent abilities over the DELETE method.' );
		self::assertFalse( $delete_annotations['open_world'], 'The ability only writes to the local database.' );

		$create_schema = $create->get_input_schema();
		self::assertSame( array( 'taxonomy', 'name' ), $create_schema['required'], 'The taxonomy and the name should be required.' );
		self::assertFalse( $create_schema['additionalProperties'], 'Unknown properties should be rejected.' );
		self::assertSame( array( 'taxonomy', 'name', 'slug', 'description', 'parent', 'fields' ), array_keys( $create_schema['properties'] ), 'The input should take the taxonomy, the term fields, and the field selection.' );
		self::assertSame( array( 'category', 'post_tag' ), $create_schema['properties']['taxonomy']['enum'], 'Only the exposed taxonomies should be accepted.' );

		$update_schema = $update->get_input_schema();
		self::assertSame( array( 'id' ), $update_schema['required'], 'Only the ID should be required.' );
		self::assertFalse( $update_schema['additionalProperties'], 'Unknown properties should be rejected.' );
		self::assertSame( array( 'id', 'taxonomy', 'name', 'slug', 'description', 'parent', 'fields' ), array_keys( $update_schema['properties'] ), 'The update takes the ID, the guard, the term fields, and the field selection.' );
		self::assertSame( 1, $update_schema['properties']['id']['minimum'], 'The ID should be positive.' );
		self::assertSame( 'Optional. Update the term only if it belongs to this taxonomy.', $update_schema['properties']['taxonomy']['description'], 'The taxonomy should be an optional guard on update.' );

		$delete_schema = $delete->get_input_schema();
		self::assertSame( array( 'id' ), $delete_schema['required'], 'Only the ID should be required.' );
		self::assertFalse( $delete_schema['additionalProperties'], 'Unknown properties should be rejected.' );
		self::assertSame( array( 'id', 'taxonomy', 'force', 'fields' ), array_keys( $delete_schema['properties'] ), 'The delete takes the ID, the guard, the force flag, and the field selection.' );
		self::assertSame( 'boolean', $delete_schema['properties']['force']['type'], 'The force flag should be a boolean.' );
		self::assertSame( 'Optional. Delete the term only if it belongs to this taxonomy.', $delete_schema['properties']['taxonomy']['description'], 'The taxonomy should be an optional guard on delete.' );

		$term_shape = $this->get_registered( 'wp/terms-query' )->get_output_schema()['oneOf'][0];
		self::assertSame( $term_shape, $create->get_output_schema(), 'The created term should have the shape of a queried term.' );
		self::assertSame( $term_shape, $update->get_output_schema(), 'The updated term should have the shape of a queried term.' );

		$delete_output = $delete->get_output_schema();
		self::assertSame( array( 'deleted', 'previous' ), $delete_output['required'], 'A delete should answer with the deleted flag and the previous term.' );
		self::assertSame( $term_shape, $delete_output['properties']['previous'], 'The previous term should have the shape of a queried term.' );
	}

	/**
	 * The write abilities are not registered when no taxonomies are exposed to them.
	 */
	public function test_does_not_register_term_write_abilities_without_exposed_taxonomies(): void {
		// The flags hold per-object state on both environments, so the test
		// restores them, as the source PR's teardown does.
		$flagged = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$taxonomy_obj = get_taxonomy( $taxonomy );
			self::assertInstanceOf( WP_Taxonomy::class, $taxonomy_obj, 'The taxonomy should be registered.' );
			$flagged[ $taxonomy ]            = $taxonomy_obj;
			$taxonomy_obj->show_in_abilities = false; // @phpstan-ignore property.notFound (the property is declared on trunk hosts, polyfilled here)
		}

		try {
			foreach ( array( new Term_Create(), new Term_Update(), new Term_Delete() ) as $ability ) {
				self::assertFalse( $ability->should_register(), 'No write ability should register without any exposed taxonomies.' );
			}
		} finally {
			foreach ( $flagged as $taxonomy_obj ) {
				$taxonomy_obj->show_in_abilities = true; // @phpstan-ignore property.notFound (the property is declared on trunk hosts, polyfilled here)
			}
		}
	}

	/**
	 * A term is created with its name, description, and slug (both core taxonomies).
	 *
	 */
	public function test_create_item(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'administrator' );

			$result = $this->execute(
				'wp/term-create',
				array(
					'taxonomy'    => $taxonomy,
					'name'        => 'My Awesome Term',
					'description' => 'This term is so awesome.',
					'slug'        => 'so-awesome',
					'fields'      => array( 'name', 'description', 'slug', 'taxonomy' ),
				)
			);

			self::assertIsArray( $result, 'The term should be created.' );
			$this->track_term( (int) $result['id'], $taxonomy );
			self::assertSame( 'My Awesome Term', $result['name'], 'The name should be stored.' );
			self::assertSame( 'This term is so awesome.', $result['description'], 'The description should be stored.' );
			self::assertSame( 'so-awesome', $result['slug'], 'The slug should be stored.' );
			$term = get_term( $result['id'] );
			self::assertInstanceOf( WP_Term::class, $term, 'The created term should exist.' );
			self::assertSame( $taxonomy, $term->taxonomy, 'The term should be created in the taxonomy.' );
		}
	}

	/**
	 * Without `fields`, the created term carries the lean default set, with the
	 * ID always included and `parent` only for hierarchical taxonomies.
	 *
	 */
	public function test_created_term_defaults(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'administrator' );

			$result = $this->execute(
				'wp/term-create',
				array(
					'taxonomy' => $taxonomy,
					'name'     => 'Default fields ' . $taxonomy,
				)
			);

			$expected = array( 'id', 'count', 'name', 'slug', 'taxonomy' );
			if ( is_taxonomy_hierarchical( $taxonomy ) ) {
				$expected[] = 'parent';
			}

			self::assertIsArray( $result, 'The term should be created.' );
			$this->track_term( (int) $result['id'], $taxonomy );
			self::assertSame( $expected, array_keys( $result ), 'The default field set should match the query\'s projection.' );
			self::assertSame( 0, $result['count'], 'A new term has no published posts.' );
		}
	}

	/**
	 * The ID is always included, even under a field selection asking for one
	 * detail, and the stored term reads back the same through `wp/terms-query`.
	 */
	public function test_created_term_reads_back_the_same_through_the_query(): void {
		$this->log_in_as( 'administrator' );

		$created = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'Backreading',
				'fields'   => array( 'description' ),
			)
		);
		self::assertIsArray( $created, 'The term should be created.' );
		$this->track_term( (int) $created['id'], 'category' );
		self::assertSame( array( 'id', 'description' ), array_keys( $created ), 'The ID should always be included.' );

		$read = $this->execute(
			'wp/terms-query',
			array(
				'id'     => $created['id'],
				'fields' => array( 'name', 'description', 'taxonomy' ),
			)
		);
		self::assertIsArray( $read, 'The created term should be readable through the query.' );
		self::assertSame( 'Backreading', $read['name'], 'The name should read back the same.' );
		self::assertSame( $created['id'], $read['id'], 'The ID should read back the same.' );
	}

	/**
	 * A name already taken under the same parent is reported with the ID of the
	 * existing term.
	 */
	public function test_create_item_term_already_exists(): void {
		$this->log_in_as( 'administrator' );

		$existing_id = $this->create_test_term( 'category', 'Existing' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'Existing',
			)
		);

		$this->assert_error( $result, 'term_exists', 400, 'A taken name should be reported.' );
		self::assertSame( $existing_id, (int) $result->get_error_data()['term_id'], 'The existing term should be identified.' );
	}

	/**
	 * A taxonomy that does not exist is rejected by the schema.
	 */
	public function test_create_item_invalid_taxonomy(): void {
		$this->log_in_as( 'administrator' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'invalid-taxonomy',
				'name'     => 'Invalid Taxonomy',
			)
		);

		$this->assert_error( $result, 'ability_invalid_input', null, 'An unknown taxonomy should fail validation.' );
	}

	/**
	 * A name is required.
	 *
	 */
	public function test_create_item_missing_name(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'administrator' );

			$result = $this->execute( 'wp/term-create', array( 'taxonomy' => $taxonomy ) );

			$this->assert_error( $result, 'ability_invalid_input', null, 'A term without a name should fail validation.' );
		}
	}

	/**
	 * Unknown inputs are rejected instead of dropped, so passing an `id` cannot
	 * silently create a term.
	 */
	public function test_create_item_unknown_input_rejected(): void {
		$this->log_in_as( 'administrator' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Blue',
				'meta'     => array(),
			)
		);

		$this->assert_error( $result, 'ability_invalid_input', null, 'Unknown properties should be rejected.' );
	}

	/**
	 * A subscriber cannot create terms of either core taxonomy, and no term is
	 * created on the denial.
	 *
	 */
	public function test_create_item_incorrect_permissions(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'subscriber' );

			$result = $this->execute(
				'wp/term-create',
				array(
					'taxonomy' => $taxonomy,
					'name'     => 'Incorrect permissions',
				)
			);

			$this->assert_denied( $result, 'A subscriber should not create terms.' );
			self::assertFalse( get_term_by( 'name', 'Incorrect permissions', $taxonomy ), 'No term should be created.' );
		}
	}

	/**
	 * A contributor cannot create categories, which needs the capability to
	 * edit the terms.
	 */
	public function test_create_item_incorrect_permissions_contributor(): void {
		$this->log_in_as( 'contributor' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'Incorrect permissions',
			)
		);

		$this->assert_denied( $result, 'A contributor should not create categories.' );
	}

	/**
	 * A contributor can create tags, which only needs the capability to assign them.
	 */
	public function test_create_item_contributor(): void {
		$this->log_in_as( 'contributor' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy'    => 'post_tag',
				'name'        => 'My Awesome Term',
				'description' => 'This term is so awesome.',
				'slug'        => 'so-awesome',
				'fields'      => array( 'name', 'description', 'slug' ),
			)
		);

		self::assertIsArray( $result, 'A contributor should create a tag.' );
		$this->track_term( (int) $result['id'], 'post_tag' );
		self::assertSame( 'My Awesome Term', $result['name'], 'The name should be stored.' );
		self::assertSame( 'This term is so awesome.', $result['description'], 'The description should be stored.' );
		self::assertSame( 'so-awesome', $result['slug'], 'The slug should be stored.' );
	}

	/**
	 * A role's reach is the flat-vs-hierarchical floor: creating a term needs
	 * `edit_terms` in a hierarchical taxonomy, or `assign_terms` in a flat one.
	 *
	 */
	public function test_roles_creating_terms(): void {
		foreach ( $this->term_roles() as $role => $reach ) {
			[ $role, $can_manage, $can_assign ] = $reach;
			$this->register_write_test_taxonomies();

			if ( '' !== $role ) {
				$this->log_in_as( $role );
			}

			$allowed_by_taxonomy = array(
				'category'   => $can_manage,
				'post_tag'   => $can_assign,
				'wpai_genre' => $can_manage,
				'wpai_mood'  => $can_assign,
			);

			$name = "Created by a role ({$role})";

			foreach ( $allowed_by_taxonomy as $taxonomy => $allowed ) {
				$result = $this->execute(
					'wp/term-create',
					array(
						'taxonomy' => $taxonomy,
						'name'     => $name,
						'fields'   => array( 'taxonomy' ),
					)
				);

				if ( $allowed ) {
					self::assertIsArray( $result, "The role should create a {$taxonomy} term." );
					self::assertSame( $taxonomy, $result['taxonomy'], "The term should be created in {$taxonomy}." );

					// Track the created term for the teardown.
					$this->track_term( (int) $result['id'], $taxonomy );
				} else {
					$this->assert_denied( $result, "The role should not create a {$taxonomy} term." );
					self::assertFalse( get_term_by( 'name', $name, $taxonomy ), "No {$taxonomy} term should be created." );
				}
			}
		}
	}

	/**
	 * A category is created under a parent.
	 */
	public function test_create_item_with_parent(): void {
		$this->log_in_as( 'administrator' );

		$parent = $this->create_test_term( 'category', 'test-category' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'My Awesome Term',
				'parent'   => $parent,
				'fields'   => array( 'parent' ),
			)
		);

		self::assertIsArray( $result, 'The category should be created.' );
		$this->track_term( (int) $result['id'], 'category' );
		self::assertSame( $parent, $result['parent'], 'The category should have the parent.' );
	}

	/**
	 * A parent that does not exist is reported, and no term is created.
	 */
	public function test_create_item_invalid_parent(): void {
		$this->log_in_as( 'administrator' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'My Awesome Term',
				'parent'   => self::IMPOSSIBLY_HIGH_NUMBER,
			)
		);

		$this->assert_error( $result, 'terms_term_invalid', 400, 'A missing parent should be reported.' );
		self::assertFalse( get_term_by( 'name', 'My Awesome Term', 'category' ), 'No term should be created.' );
	}

	/**
	 * A parent of 0 creates a top-level category.
	 */
	public function test_create_item_with_no_parent(): void {
		$this->log_in_as( 'administrator' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'My Awesome Term',
				'parent'   => 0,
				'fields'   => array( 'parent' ),
			)
		);

		self::assertIsArray( $result, 'The category should be created.' );
		$this->track_term( (int) $result['id'], 'category' );
		self::assertSame( 0, $result['parent'], 'The category should be top-level.' );
	}

	/**
	 * A parent cannot be set in a taxonomy that is not hierarchical.
	 */
	public function test_create_item_parent_non_hierarchical_taxonomy(): void {
		$this->log_in_as( 'administrator' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'My Awesome Term',
				'parent'   => self::IMPOSSIBLY_HIGH_NUMBER,
			)
		);

		$this->assert_error( $result, 'terms_taxonomy_not_hierarchical', 400, 'A parent should not be set on a tag.' );
	}

	/**
	 * A category name is taken only under the same parent: under another parent
	 * or at the top level the name is accepted with a unique slug, and under
	 * the same parent with a slug of its own.
	 */
	public function test_create_term_name_is_unique_under_its_parent(): void {
		$this->log_in_as( 'administrator' );

		$fruit  = $this->create_test_term( 'category', 'Fruit' );
		$veg    = $this->create_test_term( 'category', 'Veg' );
		$apple  = $this->create_test_term(
			'category',
			'Apple',
			array(
				'parent' => $fruit,
				'slug'   => 'fruit-apple',
			)
		);
		$fields = array( 'name', 'slug', 'parent' );

		$taken = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'parent'   => $fruit,
			)
		);
		$this->assert_error( $taken, 'term_exists', null, 'A name taken under the same parent should be reported.' );
		self::assertSame(
			array(
				'status'  => 400,
				'term_id' => $apple,
			),
			$taken->get_error_data(),
			'The existing term should be identified.'
		);

		$under_veg = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'parent'   => $veg,
				'fields'   => $fields,
			)
		);
		self::assertIsArray( $under_veg, 'The term under the other parent should be created.' );
		$this->track_term( (int) $under_veg['id'], 'category' );
		self::assertSame( $veg, $under_veg['parent'], 'The term should be created under the other parent.' );

		$top_level = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'fields'   => $fields,
			)
		);
		self::assertIsArray( $top_level, 'The top-level term should be created.' );
		$this->track_term( (int) $top_level['id'], 'category' );
		self::assertSame( 0, $top_level['parent'], 'The term should be created at the top level.' );

		$own_slug = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'slug'     => 'green-apple',
				'parent'   => $fruit,
				'fields'   => $fields,
			)
		);
		self::assertIsArray( $own_slug, 'The term with its own slug should be created.' );
		$this->track_term( (int) $own_slug['id'], 'category' );
		self::assertSame( 'green-apple', $own_slug['slug'], 'Under the same parent the name should be accepted with a slug of its own.' );
		self::assertSame( $fruit, $own_slug['parent'], 'The term should be created under the same parent.' );
	}

	/**
	 * A tag name is taken anywhere in its taxonomy.
	 */
	public function test_create_tag_with_a_taken_name_is_reported(): void {
		$this->log_in_as( 'administrator' );

		$red = $this->create_test_term( 'post_tag', 'Red' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Red',
			)
		);

		$this->assert_error( $result, 'term_exists', 400, 'A taken tag name should be reported.' );
		self::assertSame( 'A term with the name provided already exists in this taxonomy.', $result->get_error_message(), 'The whole taxonomy should be named.' );
		self::assertSame( $red, (int) $result->get_error_data()['term_id'], 'The existing tag should be identified.' );
	}

	/**
	 * A name that is empty once sanitized is refused like an empty name.
	 */
	public function test_create_item_with_an_empty_name(): void {
		$this->log_in_as( 'editor' );

		foreach ( array( '', '<b></b>' ) as $name ) {
			$result = $this->execute(
				'wp/term-create',
				array(
					'taxonomy' => 'post_tag',
					'name'     => $name,
				)
			);
			$this->assert_error( $result, 'empty_term_name', null, "The name '{$name}' should be refused." );
		}
	}

	/**
	 * A term is not created in a taxonomy the exposure leaves out, and the
	 * execute callback's own re-check names it.
	 */
	public function test_create_item_outside_the_exposure_is_denied_like_a_missing_capability(): void {
		$this->register_write_test_taxonomies();
		$this->log_in_as( 'administrator' );

		$result = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'wpai_secret',
				'name'     => 'Secret term',
			)
		);

		$this->assert_error( $result, 'ability_invalid_input', null, 'The schema should refuse an unexposed taxonomy.' );

		$direct = $this->execute_raw(
			'wp/term-create',
			array(
				'taxonomy' => 'wpai_secret',
				'name'     => 'Secret term',
			)
		);
		$this->assert_error( $direct, 'terms_forbidden', 403, 'The execute callback should refuse an unexposed taxonomy.' );
	}

	/**
	 * A term is updated with the fields it gets, and kept the way it was for
	 * the fields left out. The answer is stored the way it read back.
	 *
	 */
	public function test_update_item(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'administrator' );

			$term_id = $this->create_test_term(
				$taxonomy,
				'Original',
				array(
					'slug'        => 'original',
					'description' => 'Original description.',
				)
			);

			$result = $this->execute(
				'wp/term-update',
				array(
					'id'     => $term_id,
					'name'   => 'Renamed',
					'fields' => array( 'name', 'slug', 'description' ),
				)
			);

			self::assertIsArray( $result, 'The term should be updated.' );
			self::assertSame( 'Renamed', $result['name'], 'The name should be stored.' );
			self::assertSame( 'Original description.', $result['description'], 'The description should be kept.' );
			self::assertSame( 'original', $result['slug'], 'The slug should be kept.' );

			$term = get_term( $term_id, $taxonomy );
			self::assertInstanceOf( WP_Term::class, $term, 'The updated term should exist.' );
			self::assertSame( 'Renamed', $term->name, 'The name should be stored.' );
		}
	}

	/**
	 * Renaming keeps the slug until it is cleared, as the PR documents.
	 */
	public function test_renaming_keeps_the_slug_until_it_is_cleared(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Green', array( 'slug' => 'green' ) );

		$renamed = $this->execute(
			'wp/term-update',
			array(
				'id'     => $tag_id,
				'name'   => 'Lime green',
				'fields' => array( 'name', 'slug' ),
			)
		);
		self::assertIsArray( $renamed, 'The tag should be updated.' );
		self::assertSame( 'green', $renamed['slug'], 'Renaming should keep the slug.' );

		$cleared = $this->execute(
			'wp/term-update',
			array(
				'id'     => $tag_id,
				'slug'   => 'lime-green',
				'fields' => array( 'name', 'slug' ),
			)
		);
		self::assertIsArray( $cleared, 'The tag should be updated.' );
		self::assertSame( 'lime-green', $cleared['slug'], 'An explicit slug should be stored.' );
	}

	/**
	 * A category's parent is updated, and 0 removes it.
	 */
	public function test_update_item_parent_and_removal(): void {
		$this->log_in_as( 'administrator' );

		$fruit = $this->create_test_term( 'category', 'Fruit' );
		$veg   = $this->create_test_term( 'category', 'Veg' );
		$apple = $this->create_test_term( 'category', 'Apple', array( 'parent' => $fruit ) );

		$moved = $this->execute(
			'wp/term-update',
			array(
				'id'     => $apple,
				'parent' => $veg,
				'fields' => array( 'parent' ),
			)
		);
		self::assertIsArray( $moved, 'The term should be updated.' );
		self::assertSame( $veg, $moved['parent'], 'The term should move under the new parent.' );

		$top = $this->execute(
			'wp/term-update',
			array(
				'id'     => $apple,
				'parent' => 0,
				'fields' => array( 'parent' ),
			)
		);
		self::assertIsArray( $top, 'The term should be updated.' );
		self::assertSame( 0, $top['parent'], 'A parent of 0 should make the term top-level.' );
	}

	/**
	 * A update without changes still succeeds and answers the term.
	 */
	public function test_update_saves_the_term_even_without_changes(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'No change', array( 'slug' => 'no-change' ) );

		$result = $this->execute(
			'wp/term-update',
			array(
				'id'     => $tag_id,
				'fields' => array( 'name', 'slug', 'count' ),
			)
		);

		self::assertIsArray( $result, 'An update without changes should succeed.' );
		self::assertSame( 'No change', $result['name'], 'The term should answer unchanged.' );
	}

	/**
	 * A missing parent is reported on update, and a parent cannot be set in a
	 * taxonomy that is not hierarchical.
	 */
	public function test_update_item_parent_invalid_and_non_hierarchical(): void {
		$this->log_in_as( 'administrator' );

		$category_id = $this->create_test_term( 'category', 'Parentless' );
		$tag_id      = $this->create_test_term( 'post_tag', 'Parentless tag' );

		$invalid = $this->execute(
			'wp/term-update',
			array(
				'id'     => $category_id,
				'parent' => self::IMPOSSIBLY_HIGH_NUMBER,
			)
		);
		$this->assert_error( $invalid, 'terms_term_invalid', 400, 'A missing parent should be reported.' );

		$flat = $this->execute(
			'wp/term-update',
			array(
				'id'     => $tag_id,
				'parent' => $category_id,
			)
		);
		$this->assert_error( $flat, 'terms_taxonomy_not_hierarchical', 400, 'A parent should not be set on a tag.' );
	}

	/**
	 * `taxonomy` is an optional guard on update: a term outside it is denied
	 * like a missing one — a generic denial through the validated transport,
	 * and the missing-term error on a direct call.
	 */
	public function test_update_taxonomy_guard_mismatch_denies_like_a_missing_term(): void {
		$this->log_in_as( 'administrator' );

		$category_id = $this->create_test_term( 'category', 'The category' );

		$result = $this->execute(
			'wp/term-update',
			array(
				'id'       => $category_id,
				'taxonomy' => 'post_tag',
				'name'     => 'Lemon',
			)
		);

		$this->assert_denied( $result, 'A guard mismatch should deny the update.' );

		$direct = $this->execute_raw(
			'wp/term-update',
			array(
				'id'       => $category_id,
				'taxonomy' => 'post_tag',
				'name'     => 'Lemon',
			)
		);
		$this->assert_error( $direct, 'terms_term_invalid', 404, 'The guarded term should be reported as a missing one.' );
		self::assertStringContainsString( 'does not exist', $direct->get_error_message(), 'The missing-term message should be reused.' );
	}

	/**
	 * Hidden and missing terms are denied alike on update, so terms cannot be
	 * probed: both through the validated transport and on a direct call.
	 */
	public function test_hidden_and_missing_terms_are_denied_alike(): void {
		$this->register_write_test_taxonomies();
		$this->log_in_as( 'administrator' );

		$hidden_id = $this->create_test_term( 'wpai_secret', 'Hidden genre', array( 'slug' => 'hidden-genre' ) );

		$hidden  = $this->execute(
			'wp/term-update',
			array(
				'id'   => $hidden_id,
				'name' => 'Renamed',
			)
		);
		$missing = $this->execute(
			'wp/term-update',
			array(
				'id'   => self::IMPOSSIBLY_HIGH_NUMBER,
				'name' => 'Renamed',
			)
		);

		$this->assert_denied( $hidden, 'A hidden term should be denied.' );
		$this->assert_denied( $missing, 'A missing term should be denied the same way.' );
	}

	/**
	 * A direct update with a malformed lookup fails closed: an ID that is not a
	 * whole number matches no term, so a malformed value cannot be cast onto
	 * another term.
	 */
	public function test_direct_update_with_a_malformed_lookup_fails_closed(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Guarded tag' );

		foreach ( array( 'Not a number', 1.5 ) as $malformed_id ) {
			$direct = $this->execute_raw(
				'wp/term-update',
				array(
					'id'   => $malformed_id,
					'name' => 'Hijacked',
				)
			);
			$this->assert_error( $direct, 'terms_term_invalid', null, 'A malformed ID should name no term.' );
		}

		$term = get_term( $tag_id, 'post_tag' );
		self::assertInstanceOf( WP_Term::class, $term, 'The tag should be untouched.' );
		self::assertSame( 'Guarded tag', $term->name, 'The name should be unchanged.' );
	}

	/**
	 * The update floors: a subscriber and a contributor cannot update terms,
	 * an editor can update both core taxonomies, and no term is changed on a
	 * denial.
	 *
	 */
	public function test_update_item_capability_floors(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'administrator' );

			$term_id = $this->create_test_term( $taxonomy, 'Unguarded ' . $taxonomy );

			$this->log_in_as( 'subscriber' );
			$subscriber = $this->execute(
				'wp/term-update',
				array(
					'id'   => $term_id,
					'name' => 'Renamed by a subscriber',
				)
			);
			$this->assert_denied( $subscriber, 'A subscriber should not update terms.' );

			$this->log_in_as( 'contributor' );
			$contributor = $this->execute(
				'wp/term-update',
				array(
					'id'   => $term_id,
					'name' => 'Renamed by a contributor',
				)
			);
			$this->assert_denied( $contributor, 'A contributor should not update terms.' );

			$this->log_in_as( 'editor' );
			$editor = $this->execute(
				'wp/term-update',
				array(
					'id'     => $term_id,
					'name'   => 'Renamed by an editor',
					'fields' => array( 'name' ),
				)
			);
			self::assertIsArray( $editor, 'An editor should update terms (edit_terms/edit_term).' );
			self::assertSame( 'Renamed by an editor', $editor['name'], 'The name should be stored.' );

			$term = get_term( $term_id, $taxonomy );
			self::assertInstanceOf( WP_Term::class, $term, 'The term should exist.' );
			self::assertSame( 'Renamed by an editor', $term->name, 'The name should be stored.' );
		}
	}

	/**
	 * A logged-out visitor cannot update terms.
	 */
	public function test_update_item_logged_out_denied(): void {
		$this->log_in_as( 'administrator' );

		$term_id = $this->create_test_term( 'category', 'Logged out target' );

		wp_set_current_user( 0 );

		$result = $this->execute(
			'wp/term-update',
			array(
				'id'   => $term_id,
				'name' => 'Renamed by nobody',
			)
		);

		$this->assert_denied( $result, 'A logged-out visitor should not update terms.' );
	}

	/**
	 * A user granted `edit_term` for a term they otherwise cannot reach can
	 * update it, and the update stores against the flat-assign floor: an
	 * author who can create tags cannot rename them, since that needs
	 * `edit_terms` under the default capabilities.
	 */
	public function test_update_item_with_edit_term_cap_granted(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Granted tag' );

		$this->log_in_as( 'subscriber' );
		$subscriber = $this->execute(
			'wp/term-update',
			array(
				'id'   => $tag_id,
				'name' => 'Renamed by a subscriber',
			)
		);
		$this->assert_denied( $subscriber, 'A subscriber should not update terms.' );

		$granted = static function ( $caps, $cap ) {
			if ( 'edit_term' === $cap ) {
				return array( 'read' );
			}

			return $caps;
		};

		add_filter( 'map_meta_cap', $granted, 10, 2 );

		try {
			$result = $this->execute(
				'wp/term-update',
				array(
					'id'     => $tag_id,
					'name'   => 'Renamed under a granted cap',
					'fields' => array( 'name' ),
				)
			);
			self::assertIsArray( $result, 'A granted edit_term should allow the update.' );
			self::assertSame( 'Renamed under a granted cap', $result['name'], 'The name should be stored.' );
		} finally {
			remove_filter( 'map_meta_cap', $granted, 10 );
		}

		$term = get_term( $tag_id, 'post_tag' );
		self::assertInstanceOf( WP_Term::class, $term, 'The tag should exist.' );
		self::assertSame( 'Renamed under a granted cap', $term->name, 'The name should be stored.' );
	}

	/**
	 * A forced delete answers `deleted` with the term it deleted under
	 * `previous`, limited to the requested field selection with the ID pinned.
	 *
	 */
	public function test_delete_item(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'administrator' );

			$term_id = $this->create_test_term(
				$taxonomy,
				'Doomed ' . $taxonomy,
				array(
					'slug'        => 'doomed-' . $taxonomy,
					'description' => 'To be deleted.',
				)
			);

			$result = $this->execute(
				'wp/term-delete',
				array(
					'id'     => $term_id,
					'force'  => true,
					'fields' => array( 'name', 'slug', 'description' ),
				)
			);

			self::assertIsArray( $result, 'The term should be deleted.' );
			self::assertTrue( $result['deleted'], 'The term should be reported as deleted.' );
			self::assertSame( 'Doomed ' . $taxonomy, $result['previous']['name'], 'The deleted term should be reported under `previous`.' );
			self::assertSame( 'doomed-' . $taxonomy, $result['previous']['slug'], 'The deleted slug should be reported.' );
			self::assertSame( 'To be deleted.', $result['previous']['description'], 'The deleted description should be reported.' );
			self::assertNull( get_term( $term_id, $taxonomy ), 'The term should be gone.' );
		}
	}

	/**
	 * The previous term matches the term as last read through the query.
	 */
	public function test_deleted_term_matches_its_last_read(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Last read', array( 'slug' => 'last-read' ) );

		$fields = array( 'name', 'slug', 'count' );
		$read   = $this->execute(
			'wp/terms-query',
			array(
				'id'     => $tag_id,
				'fields' => $fields,
			)
		);
		self::assertIsArray( $read, 'The term should be readable before its deletion.' );

		$deleted = $this->execute(
			'wp/term-delete',
			array(
				'id'     => $tag_id,
				'force'  => true,
				'fields' => $fields,
			)
		);
		self::assertIsArray( $deleted, 'The term should be deleted.' );
		self::assertSame( $read, $deleted['previous'], 'The previous term should match the last read.' );
	}

	/**
	 * A deleted term can no longer be read through the query that the writes
	 * answer with.
	 */
	public function test_deleted_term_is_no_longer_readable(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Unreadable after delete' );

		$deleted = $this->execute(
			'wp/term-delete',
			array(
				'id'    => $tag_id,
				'force' => true,
			)
		);
		self::assertIsArray( $deleted, 'The tag should be deleted.' );

		$read = $this->execute( 'wp/terms-query', array( 'id' => $tag_id ) );
		$this->assert_denied( $read, 'A deleted term should be unreadable.' );
	}

	/**
	 * Terms cannot be trashed: without a true `force`, nothing is deleted and
	 * the refusal names the gate.
	 *
	 */
	public function test_delete_item_no_trash(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'administrator' );

			$term_id = $this->create_test_term( $taxonomy, 'Not doomed ' . $taxonomy );

			$without_force = $this->execute( 'wp/term-delete', array( 'id' => $term_id ) );
			$this->assert_error( $without_force, 'terms_trash_not_supported', 501, 'A delete without force should be refused.' );

			$false_force = $this->execute(
				'wp/term-delete',
				array(
					'id'    => $term_id,
					'force' => false,
				)
			);
			$this->assert_error( $false_force, 'terms_trash_not_supported', 501, 'A delete with force false should be refused.' );

			self::assertInstanceOf( WP_Term::class, get_term( $term_id, $taxonomy ), 'The term should still exist.' );
		}
	}

	/**
	 * A missing term is denied like a missing capability on delete; a term
	 * outside the optional `taxonomy` guard is denied the same way.
	 */
	public function test_delete_item_guard_and_missing_denied_alike(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Guarded tag' );

		$missing = $this->execute(
			'wp/term-delete',
			array(
				'id'    => self::IMPOSSIBLY_HIGH_NUMBER,
				'force' => true,
			)
		);
		$this->assert_denied( $missing, 'A missing term should be denied.' );

		$guarded = $this->execute(
			'wp/term-delete',
			array(
				'id'       => $tag_id,
				'taxonomy' => 'category',
				'force'    => true,
			)
		);
		$this->assert_denied( $guarded, 'A guard mismatch should deny the delete.' );

		$direct = $this->execute_raw(
			'wp/term-delete',
			array(
				'id'    => self::IMPOSSIBLY_HIGH_NUMBER,
				'force' => true,
			)
		);
		$this->assert_error( $direct, 'terms_term_invalid', 404, 'A direct call should report the missing term.' );
	}

	/**
	 * A direct delete with a malformed lookup fails closed, and nothing is
	 * removed.
	 */
	public function test_direct_delete_with_a_malformed_lookup_fails_closed(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Fails closed tag' );

		foreach ( array( 'Not a number', 0, 1.5 ) as $malformed_id ) {
			$direct = $this->execute_raw(
				'wp/term-delete',
				array(
					'id'    => $malformed_id,
					'force' => true,
				)
			);
			$this->assert_error( $direct, 'terms_term_invalid', null, 'A malformed ID should name no term.' );
			self::assertInstanceOf( WP_Term::class, get_term( $tag_id, 'post_tag' ), 'The tag should not be deleted.' );
		}
	}

	/**
	 * A user with the delete gate but a false `force` still hits the trash
	 * refusal on a direct call: the force gate is checked before anything is
	 * removed.
	 */
	public function test_direct_delete_rechecks_the_force_gate(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Unforced tag' );

		$direct = $this->execute_raw( 'wp/term-delete', array( 'id' => $tag_id ) );
		$this->assert_error( $direct, 'terms_trash_not_supported', 501, 'A direct call should refuse a delete without force.' );

		$coerced = $this->execute_raw(
			'wp/term-delete',
			array(
				'id'    => $tag_id,
				'force' => 'false',
			)
		);
		$this->assert_error( $coerced, 'terms_trash_not_supported', 501, 'The string "false" should not coerce into a delete.' );

		$term = get_term( $tag_id, 'post_tag' );
		self::assertInstanceOf( WP_Term::class, $term, 'The term should still exist.' );
		self::assertSame( 'Unforced tag', $term->name, 'The term should be unchanged.' );
	}

	/**
	 * A subscriber cannot delete terms, no term is deleted on the denial, and
	 * a logged-out visitor is denied before anything else.
	 *
	 */
	public function test_delete_item_incorrect_permissions(): void {
		foreach ( $this->core_taxonomies() as $taxonomy ) {
			$this->log_in_as( 'administrator' );

			$term_id = $this->create_test_term( $taxonomy, 'Preserved ' . $taxonomy );

			$this->log_in_as( 'subscriber' );
			$result = $this->execute(
				'wp/term-delete',
				array(
					'id'    => $term_id,
					'force' => true,
				)
			);

			$this->assert_denied( $result, 'A subscriber should not delete terms.' );
			self::assertInstanceOf( WP_Term::class, get_term( $term_id, $taxonomy ), 'The term should still exist.' );

			wp_set_current_user( 0 );
			$logged_out = $this->execute(
				'wp/term-delete',
				array(
					'id'    => $term_id,
					'force' => true,
				)
			);
			$this->assert_denied( $logged_out, 'A logged-out visitor should not delete terms.' );
		}
	}

	/**
	 * Deleting needs the management level: a contributor can create a tag but
	 * cannot delete it, while an editor and an administrator can delete both
	 * of the core taxonomies' terms.
	 *
	 */
	public function test_roles_deleting_terms(): void {
		foreach ( $this->term_roles() as $role => $reach ) {
			[ $role, $can_manage, $can_assign ] = $reach;
			$this->register_write_test_taxonomies();

			if ( '' !== $role ) {
				$this->log_in_as( $role );
			}

			// The terms are created by an administrator first, so only the delete
			// gate is under test; the names are role-scoped, because every role's
			// pass creates its own set.
			$this->log_in_as( 'administrator' );
			$term_ids = array();
			foreach ( array( 'category', 'post_tag', 'wpai_genre', 'wpai_mood' ) as $taxonomy ) {
				$term_ids[ $taxonomy ] = $this->create_test_term( $taxonomy, "{$taxonomy} to delete ({$role})" );
			}

			if ( '' !== $role ) {
				$this->log_in_as( $role );
			} else {
				wp_set_current_user( 0 );
			}

			foreach ( $term_ids as $taxonomy => $term_id ) {
				$result = $this->execute(
					'wp/term-delete',
					array(
						'id'    => $term_id,
						'force' => true,
					)
				);

				if ( $can_manage ) {
						self::assertIsArray( $result, "The role should delete a {$taxonomy} term." );
						self::assertTrue( $result['deleted'], "The {$taxonomy} term should be deleted." );
				} else {
					$this->assert_denied( $result, "The role should not delete a {$taxonomy} term." );
					self::assertInstanceOf( WP_Term::class, get_term( $term_id, $taxonomy ), "The {$taxonomy} term should still exist." );
				}
			}
		}
	}

	/**
	 * The assign-only floors of the create ability do not reach the deletes:
	 * a contributor that can create tags cannot delete the tag an
	 * administrator left behind.
	 */
	public function test_contributor_can_create_but_not_delete_tags(): void {
		$this->log_in_as( 'administrator' );

		$tag_id = $this->create_test_term( 'post_tag', 'Contributor doomed tag' );

		$this->log_in_as( 'contributor' );

		$created = $this->execute(
			'wp/term-create',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Contributor tag',
			)
		);
		self::assertIsArray( $created, 'A contributor should create a tag.' );
		$this->track_term( (int) $created['id'], 'post_tag' );

		$deleted = $this->execute(
			'wp/term-delete',
			array(
				'id'    => $tag_id,
				'force' => true,
			)
		);
		$this->assert_denied( $deleted, 'A contributor should not delete terms.' );
		self::assertInstanceOf( WP_Term::class, get_term( $tag_id, 'post_tag' ), 'The tag should still exist.' );
	}

	/**
	 * The default category cannot be deleted. No ability code protects it:
	 * core's `map_meta_cap()` answers `do_not_allow` for `delete_term`
	 * against the default category, so the permission callback denies it like
	 * a missing capability; a direct call is refused by the delete
	 * re-check (`terms_cannot_delete`, 403); and with a filter granting the
	 * capability anyway, `wp_delete_term()` returns 0 and the refusal becomes
	 * `terms_cannot_delete` (500). The category is never deleted.
	 */
	public function test_default_category_cannot_be_deleted(): void {
		$this->log_in_as( 'administrator' );

		$default = (int) get_option( 'default_category' );
		$input   = array(
			'id'    => $default,
			'force' => true,
		);

		$this->assert_denied( $this->execute( 'wp/term-delete', $input ), 'The default category should not be deleted.' );

		$direct = $this->execute_raw( 'wp/term-delete', $input );
		$this->assert_error( $direct, 'terms_cannot_delete', 403, 'A direct call should not delete the default category either.' );

		$granted = static function ( $caps, $cap ) {
			if ( 'delete_term' === $cap ) {
				return array( 'read' );
			}

			return $caps;
		};
		add_filter( 'map_meta_cap', $granted, 10, 2 );

		try {
			$forced = $this->execute_raw( 'wp/term-delete', $input );
			$this->assert_error( $forced, 'terms_cannot_delete', 500, 'A granted capability should still not delete the default category.' );
		} finally {
			remove_filter( 'map_meta_cap', $granted, 10 );
		}

		self::assertInstanceOf( WP_Term::class, get_term( $default, 'category' ), 'The default category should still exist.' );
	}

	/**
	 * Deleting a category moves its children under its own parent, or to the
	 * top level when the deleted term had none.
	 */
	public function test_deleting_a_parent_moves_its_children_up(): void {
		$this->log_in_as( 'administrator' );

		$grandparent = $this->create_test_term( 'category', 'Grandparent' );
		$parent      = $this->create_test_term( 'category', 'Parent to delete', array( 'parent' => $grandparent ) );
		$child       = $this->create_test_term( 'category', 'Child', array( 'parent' => $parent ) );

		$deleted = $this->execute(
			'wp/term-delete',
			array(
				'id'    => $parent,
				'force' => true,
			)
		);
		self::assertIsArray( $deleted, 'The parent should be deleted.' );

		$child_term = get_term( $child, 'category' );
		self::assertInstanceOf( WP_Term::class, $child_term, 'The child should still exist.' );
		self::assertSame( $grandparent, (int) $child_term->parent, 'The child should be reparented to its grandparent.' );
	}
}
