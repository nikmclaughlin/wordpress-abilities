<?php
/**
 * Tests: `core/users-query` (HANDOFF §8.1, §10.3, WP7).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev, core registers
 * the ability itself) and stable (6.9/7.1: the real pre-7.2 core API, where
 * the package copy registers and executes). The tests read whichever copy the
 * registry holds, so trunk pins core's behavior and stable pins the package's
 * against the same assertions.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionClass;
use ReflectionMethod;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Users\Users_Query;
use WP_Abilities_Registry;
use WP_Ability;
use WP_Error;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `core/users-query`.
 */
final class UsersQueryTest extends TestCase {

	/**
	 * The field names, in trunk's get_user_properties() order.
	 */
	private const TRUNK_FIELDS = array(
		'id',
		'name',
		'description',
		'url',
		'link',
		'slug',
		'avatar_urls',
		'username',
		'email',
		'first_name',
		'last_name',
		'nickname',
		'locale',
		'registered_date',
		'roles',
	);

	/**
	 * Cached IDs of the mocked users, keyed by fixture name.
	 *
	 * @var array<string, int>
	 */
	private array $user_ids = array();

	/**
	 * Cached IDs of the mocked posts.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Recorded _doing_it_wrong() calls: function => messages.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $wrong_usage = array();

	/**
	 * Settles the registry as the other ability suites do, and records
	 * _doing_it_wrong() traffic for the class.
	 */
	public static function set_up_before_class(): void {
		if ( 0 === did_action( 'wp_abilities_api_init' ) ) {
			WP_Abilities_Registry::get_instance();
		}
		Registrar::register_all();

		add_action(
			'doing_it_wrong_run',
			static function ( string $function_name, string $message ): void {
				self::$wrong_usage[ $function_name ][] = $message;
			},
			10,
			2
		);
	}

	/**
	 * Removes the mocked posts and users so each test builds a fresh data set.
	 */
	public function tear_down(): void {
		foreach ( $this->post_ids as $id ) {
			wp_delete_post( $id, true );
		}
		if ( ! empty( $this->user_ids ) && ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->user_ids as $id ) {
			if ( $id > 1 ) {
				wp_delete_user( $id );
			}
		}
		$this->user_ids = array();
		$this->post_ids = array();
		wp_set_current_user( 0 );
		self::$wrong_usage = array();

		parent::tear_down();
	}

	/**
	 * The registered `core/users-query` instance, or null.
	 */
	private function get_registered(): ?WP_Ability {
		return WP_Abilities_Registry::get_instance()->get_registered( 'core/users-query' );
	}

	/**
	 * Runs the execute callback without input validation or permission checks,
	 * as ungated transports and direct callers can, on whichever copy is registered.
	 *
	 * @param mixed $input The raw ability input.
	 * @return mixed The callback result.
	 */
	private function execute_raw( $input ) {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$method = new ReflectionMethod( WP_Ability::class, 'do_execute' );
		$method->setAccessible( true ); // No-op on PHP 8.1+, required on 7.4.

		return $method->invoke( $ability, $input );
	}

	/**
	 * Asserts a WP_Error with the exact code.
	 */
	private function assert_wp_error( string $code, $actual ): void {
		self::assertInstanceOf( WP_Error::class, $actual, "Expected WP_Error {$code}." );
		self::assertSame( $code, $actual->get_error_code() );
	}

	/**
	 * Creates the mocked users: an administrator, an editor, a subscriber, and
	 * three authors, of whom one has a published post, one a published page,
	 * and one only a draft. The post author's slug differs from their username,
	 * so the slug and username filters cannot be mistaken for each other.
	 */
	private function create_mocked_users(): void {
		foreach ( array( 'administrator', 'editor', 'subscriber' ) as $role ) {
			$this->user_ids[ $role ] = (int) wp_insert_user(
				array(
					'user_login' => 'uq' . $role,
					'user_pass'  => wp_generate_password(),
					'user_email' => 'uq' . $role . '@example.com',
					'role'       => $role,
				)
			);
		}

		foreach ( array( 'post_author', 'page_author', 'draft_author' ) as $name ) {
			$login                   = 'uq' . str_replace( '_', '', $name );
			$this->user_ids[ $name ] = (int) wp_insert_user(
				array(
					'user_login'    => $login,
					'user_nicename' => 'post_author' === $name ? 'uq-post-slug' : $login,
					'user_pass'     => wp_generate_password(),
					'user_email'    => $login . '@example.com',
					'first_name'    => 'First',
					'role'          => 'author',
				)
			);
		}

		$posts = array(
			'post_author'  => array( 'post', 'publish' ),
			'page_author'  => array( 'page', 'publish' ),
			'draft_author' => array( 'post', 'draft' ),
		);
		foreach ( $posts as $author => $post ) {
			$this->post_ids[] = (int) wp_insert_post(
				array(
					'post_title'  => 'Mocked ' . $author,
					'post_type'   => $post[0],
					'post_status' => $post[1],
					'post_author' => $this->user_ids[ $author ],
				)
			);
		}
	}

	/**
	 * The IDs of the mocked authors.
	 *
	 * @return list<int>
	 */
	private function author_ids(): array {
		return array( $this->user_ids['post_author'], $this->user_ids['page_author'], $this->user_ids['draft_author'] );
	}

	/**
	 * Creates more subscribers than the default page size holds.
	 *
	 * @param int $count How many to create.
	 * @return list<int> Their IDs.
	 */
	private function create_extra_users( int $count ): array {
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[]                          = (int) wp_insert_user(
				array(
					'user_login' => 'uqextra' . $i,
					'user_pass'  => wp_generate_password(),
					'user_email' => 'uqextra' . $i . '@example.com',
					'role'       => 'subscriber',
				)
			);
			$this->user_ids[ 'extra' . $i ] = end( $ids );
		}

		return $ids;
	}

	/**
	 * The IDs of a result's items.
	 *
	 * @param mixed $result The ability result.
	 * @return list<int>
	 */
	private static function item_ids( $result ): array {
		self::assertIsArray( $result );
		$ids = array_column( $result['items'], 'id' );
		sort( $ids );

		return $ids;
	}

	/**
	 * Registered exactly once, with core's annotations; provenance branches by env;
	 * repeat registration never double-registers.
	 */
	public function test_registered_once_with_core_annotations(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();

		self::assertSame( 'user', $ability->get_category() );
		self::assertSame( 'Query Users', $ability->get_label() );
		self::assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$meta['annotations'] ?? null
		);
		self::assertTrue( (bool) ( $meta['public'] ?? false ) );

		if ( empty( $meta['source'] ) ) {
			// Trunk env: core provides the name; the package copy must have skipped.
			self::assertNotInstanceOf( Users_Query::class, $ability );
		} else {
			// Stable env: the package copy registered.
			self::assertInstanceOf( Users_Query::class, $ability );
			self::assertSame( 'wordpress/abilities', $meta['source'] );
			self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null );
			self::assertSame( Manifest::TIER_CORE, $meta['stability'] ?? null );
		}

		Registrar::register_all();
		Registrar::register_all();
		self::assertSame( $ability, $this->get_registered(), 'No double registration: the single instance holds.' );
	}

	/**
	 * Trunk registers no required_capabilities: the permission callback owns authorization.
	 */
	public function test_required_capabilities_mirror_trunk(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		if ( method_exists( $ability, 'get_required_capabilities' ) ) {
			// 7.2+ hosts (trunk core): the floor is stored and read back.
			self::assertSame( array(), $ability->get_required_capabilities() );
		} else {
			// Host API predates 7.2: assert the package copy's authored floor reflectively.
			self::assertSame(
				array(),
				( new ReflectionClass( $ability ) )->getMethod( 'required_capabilities' )->invoke( $ability )
			);
		}
	}

	/**
	 * The input and output schemas mirror trunk at their dynamic seams.
	 */
	public function test_schemas_match_trunk(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$input = $ability->get_input_schema();
		self::assertSame( 'object', $input['type'] );
		self::assertSame( array(), $input['default'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'where', 'fields', 'pagination' ), array_keys( $input['properties'] ) );

		$where = $input['properties']['where']['properties'];
		self::assertSame( array( 'ids', 'slugs', 'usernames', 'roles', 'has_published_posts' ), array_keys( $where ) );
		self::assertSame( 100, $where['ids']['maxItems'] );
		self::assertSame( array_keys( wp_roles()->roles ), $where['roles']['items']['enum'] );
		self::assertSame(
			array_values( array_filter( get_post_types(), 'is_post_type_viewable' ) ),
			$where['has_published_posts']['anyOf'][1]['items']['enum']
		);
		self::assertSame( self::TRUNK_FIELDS, $input['properties']['fields']['items']['enum'] );
		self::assertSame( 100, $input['properties']['pagination']['properties']['per_page']['maximum'] );

		$output = $ability->get_output_schema();
		self::assertSame( array( 'items', 'pagination' ), $output['required'] );
		self::assertSame( self::TRUNK_FIELDS, array_keys( $output['properties']['items']['items']['properties'] ) );
		self::assertSame( array( 'id' ), $output['properties']['items']['items']['required'] );
		self::assertSame( array( 'total', 'total_pages' ), array_keys( $output['properties']['pagination']['properties'] ) );
	}

	/**
	 * Permission gate: logged out is denied (negative); any logged-in user may
	 * query (positive), but only users who can list users may filter by role or
	 * username (negative for an editor, positive for an administrator).
	 */
	public function test_permission_negative_and_positive(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_users();

		wp_set_current_user( 0 );
		self::assertFalse( $ability->check_permissions( array() ) );
		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( array() ) );

		wp_set_current_user( $this->user_ids['subscriber'] );
		self::assertTrue( $ability->check_permissions( array() ) );

		wp_set_current_user( $this->user_ids['editor'] );
		self::assertFalse( $ability->check_permissions( array( 'where' => array( 'roles' => array( 'author' ) ) ) ) );
		self::assertFalse( $ability->check_permissions( array( 'where' => array( 'usernames' => array( 'uqpostauthor' ) ) ) ) );

		wp_set_current_user( $this->user_ids['administrator'] );
		self::assertTrue( $ability->check_permissions( array( 'where' => array( 'roles' => array( 'author' ) ) ) ) );
		self::assertTrue( $ability->check_permissions( array( 'where' => array( 'usernames' => array( 'uqpostauthor' ) ) ) ) );
	}

	/**
	 * Row visibility: callers who cannot list users only see public authors;
	 * callers who can see every matching user.
	 */
	public function test_execute_row_visibility(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_users();
		$input = array( 'where' => array( 'ids' => $this->author_ids() ) );

		wp_set_current_user( $this->user_ids['subscriber'] );
		self::assertSame(
			array( $this->user_ids['post_author'], $this->user_ids['page_author'] ),
			self::item_ids( $ability->execute( $input ) ),
			'Without list_users, only authors with published posts are visible.'
		);

		wp_set_current_user( $this->user_ids['administrator'] );
		self::assertSame( $this->author_ids(), self::item_ids( $ability->execute( $input ) ) );

		$pages_only = $ability->execute(
			array(
				'where' => array(
					'ids'                 => $this->author_ids(),
					'has_published_posts' => array( 'page' ),
				),
			)
		);
		self::assertSame( array( $this->user_ids['page_author'] ), self::item_ids( $pages_only ) );

		foreach ( array( true, 'true' ) as $any ) {
			$public_authors = $this->execute_raw(
				array(
					'where' => array(
						'ids'                 => $this->author_ids(),
						'has_published_posts' => $any,
					),
				)
			);
			self::assertSame(
				array( $this->user_ids['post_author'], $this->user_ids['page_author'] ),
				self::item_ids( $public_authors ),
				'has_published_posts true matches authors of any publicly viewable post type.'
			);
		}
	}

	/**
	 * The identifier and role filters select the matching users, by the right
	 * user property each.
	 */
	public function test_execute_identifier_and_role_filters(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_users();
		wp_set_current_user( $this->user_ids['administrator'] );

		self::assertSame( array( $this->user_ids['post_author'] ), self::item_ids( $ability->execute( array( 'where' => array( 'slugs' => array( 'uq-post-slug' ) ) ) ) ) );
		self::assertSame( array(), self::item_ids( $ability->execute( array( 'where' => array( 'slugs' => array( 'uqpostauthor' ) ) ) ) ), 'Slugs match the user slug, not the username.' );
		self::assertSame( array( $this->user_ids['post_author'] ), self::item_ids( $ability->execute( array( 'where' => array( 'usernames' => array( 'uqpostauthor' ) ) ) ) ) );
		self::assertSame( array(), self::item_ids( $ability->execute( array( 'where' => array( 'usernames' => array( 'uq-post-slug' ) ) ) ) ), 'Usernames match the username, not the user slug.' );

		$mocked  = array_values( $this->user_ids );
		$authors = $ability->execute(
			array(
				'where' => array(
					'ids'   => $mocked,
					'roles' => array( 'author' ),
				),
			)
		);
		self::assertSame( $this->author_ids(), self::item_ids( $authors ), 'The roles filter keeps the authors and leaves out the other roles.' );
	}

	/**
	 * Field visibility: sensitive fields only for the user themself or users
	 * who can edit them; `id` is always included; the default field set.
	 */
	public function test_execute_field_visibility(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_users();
		$input = array(
			'where'  => array( 'ids' => array( $this->user_ids['post_author'] ) ),
			'fields' => array( 'username', 'email', 'first_name', 'registered_date', 'roles' ),
		);

		wp_set_current_user( $this->user_ids['subscriber'] );
		$result = $ability->execute( $input );
		self::assertIsArray( $result );
		self::assertSame( array( array( 'id' => $this->user_ids['post_author'] ) ), $result['items'], 'Sensitive fields are omitted, and id is always included.' );

		wp_set_current_user( $this->user_ids['post_author'] );
		$self = $ability->execute( $input )['items'][0];
		self::assertSame( 'uqpostauthor', $self['username'] );
		self::assertSame( 'uqpostauthor@example.com', $self['email'] );
		self::assertSame( 'First', $self['first_name'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $self['registered_date'] );
		self::assertSame( array( 'author' ), $self['roles'] );

		wp_set_current_user( $this->user_ids['administrator'] );
		$admin_view = $ability->execute( $input )['items'][0];
		self::assertSame( 'uqpostauthor', $admin_view['username'] );
		self::assertSame( 'uqpostauthor@example.com', $admin_view['email'] );

		$default  = $ability->execute( array( 'where' => array( 'ids' => array( $this->user_ids['post_author'] ) ) ) )['items'][0];
		$expected = get_option( 'show_avatars' ) ? array( 'id', 'name', 'link', 'slug', 'avatar_urls' ) : array( 'id', 'name', 'link', 'slug' );
		self::assertSame( $expected, array_keys( $default ) );
	}

	/**
	 * Pagination: totals, the default page sizes (10, or every listed user for
	 * an identifier list), and a page past the end.
	 */
	public function test_execute_pagination(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_users();
		wp_set_current_user( $this->user_ids['administrator'] );

		$page = $ability->execute(
			array(
				'where'      => array( 'ids' => $this->author_ids() ),
				'pagination' => array( 'per_page' => 2 ),
			)
		);
		self::assertIsArray( $page );
		self::assertCount( 2, $page['items'] );
		self::assertSame(
			array(
				'total'       => 3,
				'total_pages' => 2,
			),
			$page['pagination']
		);

		$listed = array_merge( $this->author_ids(), $this->create_extra_users( 9 ) );
		$all    = $ability->execute( array( 'where' => array( 'ids' => $listed ) ) );
		self::assertIsArray( $all );
		self::assertCount( 12, $all['items'], 'An ID list returns every match on the first page by default, past the plain default of 10.' );
		self::assertSame( 1, $all['pagination']['total_pages'] );

		$plain = $ability->execute( array() );
		self::assertIsArray( $plain );
		self::assertCount( 10, $plain['items'], 'Without an identifier list, a page holds 10 users by default.' );
		self::assertSame( (int) ceil( $plain['pagination']['total'] / 10 ), $plain['pagination']['total_pages'] );

		$this->assert_wp_error(
			'users_invalid_page_number',
			$ability->execute(
				array(
					'where'      => array( 'ids' => $this->author_ids() ),
					'pagination' => array(
						'page'     => 3,
						'per_page' => 2,
					),
				)
			)
		);
	}

	/**
	 * Malformed or unknown input fails closed, never widened: at schema validation
	 * through execute(), and in the execute callback for unvalidated callers.
	 */
	public function test_execute_fail_closed_malformed_input(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_users();

		wp_set_current_user( $this->user_ids['administrator'] );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( array( 'where' => 'nope' ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( array( 'where' => array( 'ids' => array() ) ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( array( 'fields' => array( 'password' ) ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( array( 'unknown' => true ) ) );

		$not_object = $this->execute_raw( array( 'where' => 'nope' ) );
		$this->assert_wp_error( 'users_invalid_filter', $not_object );
		self::assertSame(
			array(
				'status' => 400,
				'params' => array( 'where' => 'The where filter must be an object.' ),
			),
			$not_object->get_error_data()
		);
		$this->assert_wp_error( 'users_invalid_filter', $this->execute_raw( array( 'where' => array( 'ids' => array( 'abc', 0 ) ) ) ) );
		$this->assert_wp_error( 'users_invalid_filter', $this->execute_raw( array( 'where' => array( 'roles' => array() ) ) ) );
		$this->assert_wp_error( 'users_invalid_filter', $this->execute_raw( array( 'where' => array( 'has_published_posts' => false ) ) ) );

		// A where that is not an object passes the permission check, so execute can report it.
		wp_set_current_user( $this->user_ids['subscriber'] );
		self::assertTrue( $ability->check_permissions( array( 'where' => 'nope' ) ) );

		// A direct call skips the permission callback; the role and username filters still fail closed.
		wp_set_current_user( $this->user_ids['editor'] );
		$this->assert_wp_error( 'users_cannot_filter_by_role', $this->execute_raw( array( 'where' => array( 'roles' => array( 'author' ) ) ) ) );
		$this->assert_wp_error( 'users_cannot_filter_by_username', $this->execute_raw( array( 'where' => array( 'usernames' => array( 'uqpostauthor' ) ) ) ) );

		$none = $this->execute_raw(
			array(
				'where' => array(
					'ids'                 => $this->author_ids(),
					'has_published_posts' => array( 'not_a_post_type' ),
				),
			)
		);
		self::assertSame(
			array(
				'items'      => array(),
				'pagination' => array(
					'total'       => 0,
					'total_pages' => 0,
				),
			),
			$none,
			'Post types that are not publicly viewable match nobody, rather than every author.'
		);
	}

	/**
	 * Constructing the package copy raises no notice on either host, and keeps
	 * the default readiness, so the Registrar never probes it.
	 */
	public function test_construction_raises_no_wrong_usage(): void {
		self::$wrong_usage = array();
		$ability           = new Users_Query();
		self::assertSame( array(), self::$wrong_usage );
		self::assertTrue( $ability->should_register() );

		$overrides = new ReflectionMethod( Registrar::class, 'overrides_readiness' );
		$overrides->setAccessible( true ); // No-op on PHP 8.1+, required on 7.4.
		self::assertFalse( $overrides->invoke( null, Users_Query::class ) );
	}
}
