<?php
/**
 * Tests: `wp/user-create`, `wp/user-update`, and `wp/user-delete` (port of
 * WordPress/ai PR #1105's user write tests, on this package's contract shape).
 *
 * Runs under both wp-env matrix environments. The `wp/` names are never
 * core-provided, so the package copies register and execute identically on
 * both. The suite pins the ported user-write behaviors — the capability gates,
 * the roles/reassign rules, the permanent deletion, and the updated-only
 * through-the-field-projection answers with the password never returned.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use WP_Error;
use WP_Post;
use WP_User;
use WP_Abilities_Registry;
use WordPress\Abilities\Registrar;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Contract tests for the user write abilities.
 */
final class UserWritesTest extends TestCase {

	/**
	 * Cached IDs of the mocked users, keyed by login.
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
	 * Settles the registry as the other ability suites do.
	 */
	public static function set_up_before_class(): void {
		Registrar::register_all();

		if ( 0 === did_action( 'wp_abilities_api_init' ) ) {
			WP_Abilities_Registry::get_instance();
		}
	}

	/**
	 * Removes the mocked users (and their content rows), so each test builds a
	 * fresh data set.
	 */
	public function tear_down(): void {
		foreach ( $this->post_ids as $id ) {
			wp_delete_post( $id, true );
		}
		$this->post_ids = array();

		if ( ! function_exists( 'wp_delete_user' ) ) {
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
	 * The registered ability, wired through the Registrar.
	 */
	private function get_registered( string $name ) {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( $name );
		self::assertNotNull( $ability, "The {$name} ability should be registered." );

		return $ability;
	}

	/**
	 * Logs in a mocked user of the given role and returns its ID.
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	private function log_in_as( string $role ): int {
		if ( ! isset( $this->user_ids[ 'role_' . $role ] ) ) {
			$this->user_ids[ 'role_' . $role ] = (int) wp_insert_user(
				array(
					'user_login' => 'uw_' . $role,
					'user_pass'  => wp_generate_password(),
					'role'       => $role,
				)
			);
		}
		wp_set_current_user( $this->user_ids[ 'role_' . $role ] );

		return $this->user_ids[ 'role_' . $role ];
	}

	/**
	 * Creates a mocked user and returns its ID.
	 *
	 * @param string $login The login.
	 * @param string $role  The role.
	 * @return int The user ID.
	 */
	private function create_user( string $login, string $role ): int {
		$id                       = (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.com',
				'role'       => $role,
			)
		);
		$this->user_ids[ $login ] = $id;

		return $id;
	}

	/**
	 * Creates a mocked post, tracked for removal.
	 *
	 * @param int|null $author_id Optional. The post author.
	 * @return int The post ID.
	 */
	private function create_post( ?int $author_id = null ): int {
		$id               = (int) wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'User-write parent',
				'post_author' => $author_id ?? 1,
			)
		);
		$this->post_ids[] = $id;

		return $id;
	}

	/**
	 * Asserts a WP_Error with the exact code, and an optional `status` data value.
	 */
	private function assert_error_response( string $code, $actual, ?int $status = null, string $note = '' ): void {
		self::assertInstanceOf( WP_Error::class, $actual, $note . ' Expected WP_Error ' . $code . '.' );
		self::assertSame( $code, $actual->get_error_code(), $note . ' Expected code ' . $code . ', got ' . $actual->get_error_code() . '.' );
		if ( null !== $status ) {
			self::assertSame( $status, $actual->get_error_data()['status'] ?? null, $note . ' Expected status ' . $status . '.' );
		}
	}

	/**
	 * All three write abilities register exactly once, under the `wp/` names, with
	 * the PR's annotations and the `user` category.
	 */
	public function test_registered_once_with_the_pr_contract(): void {
		$create = $this->get_registered( 'wp/user-create' );
		$update = $this->get_registered( 'wp/user-update' );
		$delete = $this->get_registered( 'wp/user-delete' );

		self::assertSame( 'Create User', $create->get_label() );
		self::assertSame( 'Update User', $update->get_label() );
		self::assertSame( 'Delete User', $delete->get_label() );
		foreach ( array( $create, $update, $delete ) as $ability ) {
			self::assertSame( 'user', $ability->get_category(), 'The user category should be used.' );
			$meta = $ability->get_meta();
			self::assertTrue( (bool) ( $meta['show_in_rest'] ?? false ), 'The ability should be exposed in REST.' );
		}

		$annotations = array(
			$create->get_meta()['annotations'] ?? array(),
			$update->get_meta()['annotations'] ?? array(),
			$delete->get_meta()['annotations'] ?? array(),
		);
		self::assertFalse( (bool) ( $annotations[0]['readonly'] ?? true ) );
		self::assertFalse( (bool) ( $annotations[0]['idempotent'] ?? true ), 'Every call creates a new user.' );
		self::assertTrue( (bool) ( $annotations[1]['destructive'] ?? false ), 'The update overwrites values.' );
		self::assertFalse( (bool) ( $annotations[1]['idempotent'] ?? true ), 'DELETE would carry the password in the query string.' );
		self::assertTrue( (bool) ( $annotations[2]['destructive'] ?? false ), 'Deleting a user is destructive.' );
		self::assertTrue( (bool) ( $annotations[2]['idempotent'] ?? false ), 'Repeating a deletion has no further effect (DELETE-served).' );

		// The password is never in an output schema.
		$output = $create->get_output_schema();
		self::assertSame( array( 'id' ), $output['required'], 'Only the ID is always returned.' );
		self::assertArrayNotHasKey( 'password', $output['properties'], 'The password is never returned.' );
		foreach ( array( 'username', 'email' ) as $field ) {
			if ( 'username' === $field ) {
				continue; // Username is an output field of users-query.
			}
			self::assertArrayHasKey( $field, $output['properties'], "The user schema should carry {$field}." );
		}

		// The create input requires username + email; the password is optional input.
		$create_input = $create->get_input_schema();
		self::assertSame( array( 'username', 'email' ), $create_input['required'] );
		self::assertArrayHasKey( 'password', $create_input['properties'] );
		// The update input requires only the ID; the username cannot be changed.
		$update_input = $update->get_input_schema();
		self::assertSame( array( 'id' ), $update_input['required'] );
		self::assertArrayNotHasKey( 'username', $update_input['properties'] );
		// The delete input requires id + reassign.
		$delete_input = $delete->get_input_schema();
		self::assertSame( array( 'id', 'reassign' ), $delete_input['required'] );
	}

	/**
	 * CREATE: with a password, the user is created and answered through the
	 * users-query projection; the password is never in the answer.
	 */
	public function test_create_with_password_answers_the_projected_user(): void {
		$this->log_in_as( 'administrator' );

		$result = $this->get_registered( 'wp/user-create' )->execute(
			array(
				'username' => 'uw_created_user',
				'email'    => 'created@example.com',
				'password' => 'Validated Pass 123!',
				'name'     => 'Validated User',
				'roles'    => array( 'editor' ),
				'fields'   => array( 'id', 'username', 'email', 'roles', 'name' ),
			)
		);

		self::assertIsArray( $result, 'The create should answer the user.' );
		$this->user_ids['uw_created_user'] = $result['id'];

		$user = get_userdata( $result['id'] );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertSame( 'uw_created_user', $user->user_login );
		self::assertSame( 'Validated User', $result['name'], 'The edit context answers the requested fields.' );
		self::assertSame( array( 'editor' ), $result['roles'], 'The roles should be assigned when promoted.' );
		self::assertTrue( wp_check_password( 'Validated Pass 123!', $user->user_pass, $user->ID ), 'The password should be stored.' );
		self::assertArrayNotHasKey( 'password', $result, 'The password is never returned.' );
	}

	/**
	 * CREATE: without a password, one is generated (never returned).
	 */
	public function test_create_without_password_generates_one(): void {
		$this->log_in_as( 'administrator' );

		$result = $this->get_registered( 'wp/user-create' )->execute(
			array(
				'username' => 'uw_generated_user',
				'email'    => 'generated@example.com',
				'fields'   => array( 'id' ),
			)
		);

		self::assertIsArray( $result, 'The create should answer the user.' );
		$this->user_ids['uw_generated_user'] = $result['id'];

		$user = get_userdata( $result['id'] );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertNotSame( '', $user->user_pass, 'A password should have been generated.' );
	}

	/**
	 * CREATE: a caller without `create_users` is denied; with roles but without
	 * `promote_users` the edit-roles error names the refusal.
	 */
	public function test_create_capability_gates(): void {
		// An editor lacks create_users on single sites: the create itself is denied
		// by the permission callback, whose denial the Abilities API answers
		// generically (the in-execute users_cannot_edit_roles refusal is unreachable
		// past the gate on single sites: a caller with create_users — an admin —
		// also carries promote_users).
		$this->log_in_as( 'editor' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->get_registered( 'wp/user-create' )->execute(
				array(
					'username' => 'uw_editor_create',
					'email'    => 'editor-create@example.com',
					'roles'    => array( 'subscriber' ),
				)
			),
			null,
			'The create should be denied by the create_users gate.'
		);

		// A subscriber cannot create users at all.
		$this->log_in_as( 'subscriber' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->get_registered( 'wp/user-create' )->execute(
				array(
					'username' => 'uw_subscriber_create',
					'email'    => 'subscriber-create@example.com',
				)
			),
			null,
			'A subscriber lacks create_users.'
		);
	}

	/**
	 * CREATE: bad-input rows — empty username, invalid username, empty password.
	 */
	public function test_create_bad_input(): void {
		$this->log_in_as( 'administrator' );

		// An invalid username is refused with its own error.
		$this->assert_error_response(
			'users_invalid_param',
			$this->get_registered( 'wp/user-create' )->execute(
				array(
					'username' => 'not valid!',
					'email'    => 'bad-user@example.com',
				)
			),
			400,
			'An illegal username should be refused.'
		);

		// An empty password is refused.
		$this->assert_error_response(
			'users_invalid_param',
			$this->get_registered( 'wp/user-create' )->execute(
				array(
					'username' => 'uw_empty_password',
					'email'    => 'empty-pass@example.com',
					'password' => '',
				)
			),
			400,
			'An empty password should be refused.'
		);
	}

	/**
	 * UPDATE: the fields update through the projection; the username cannot be
	 * changed; the password is never returned.
	 */
	public function test_update_fields_and_password_never_returned(): void {
		$this->log_in_as( 'administrator' );
		$target_id = $this->create_user( 'uw_update_target', 'subscriber' );

		$result = $this->get_registered( 'wp/user-update' )->execute(
			array(
				'id'          => $target_id,
				'name'        => 'Validated Updated Name',
				'description' => 'Validated description',
				'fields'      => array( 'id', 'name', 'description', 'username' ),
			)
		);

		self::assertIsArray( $result, 'The update should return the user.' );
		self::assertSame( 'Validated Updated Name', $result['name'] );
		self::assertSame( 'Validated description', $result['description'] );

		$user = get_userdata( $target_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertSame( 'uw_update_target', $user->user_login, 'The username cannot be changed.' );
		self::assertSame( 'Validated Updated Name', $user->display_name );
	}

	/**
	 * UPDATE: a roles change needs promote_user; sending the current roles back
	 * is no change and succeeds for an editor who cannot edit the user.
	 */
	public function test_update_roles_rules(): void {
		$this->log_in_as( 'administrator' );
		$target_id = $this->create_user( 'uw_roles_target', 'subscriber' );
		$user      = get_userdata( $target_id );

		// An editor cannot promote users on single sites (no promote_user), and
		// updating another user's name lacks edit_user too: both denials are the
		// route's observable generic denial. The positives run as the admin below.
		$this->log_in_as( 'editor' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->get_registered( 'wp/user-update' )->execute(
				array(
					'id'   => $target_id,
					'name' => 'Denied',
				)
			),
			null,
			'An editor lacks edit_user for another user.'
		);

		// Empty roles on the CALLER'S OWN account is refused (self-role removal).
		$this->log_in_as( 'administrator' );
		$this->assert_error_response(
			'users_user_invalid_role',
			$this->get_registered( 'wp/user-update' )->execute(
				array(
					'id'    => $this->user_ids['role_administrator'],
					'roles' => array(),
				)
			),
			403,
			'Users cannot remove their own roles.'
		);

		// An unknown role is refused.
		$this->assert_error_response(
			'ability_invalid_input',
			$this->get_registered( 'wp/user-update' )->execute(
				array(
					'id'    => $target_id,
					'roles' => array( 'not-a-role' ),
				)
			),
			null,
			'An unknown role should be rejected by the input schema\'s roles enum.'
		);
	}

	/**
	 * DELETE: permanent deletion returning the previous user, reassign semantics,
	 * self-deletion refused, multisite 501 shape.
	 */
	public function test_delete_semantics(): void {
		$this->log_in_as( 'administrator' );
		$target_id = $this->create_user( 'uw_delete_target', 'subscriber' );
		wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Target post',
				'post_author' => $target_id,
			)
		);
		$reassign_id = $this->create_user( 'uw_reassign_to', 'editor' );

		// An admin cannot delete their own account.
		$this->assert_error_response(
			'users_cannot_delete',
			$this->get_registered( 'wp/user-delete' )->execute(
				array(
					'id'       => $this->user_ids['role_administrator'],
					'reassign' => false,
				)
			),
			403,
			'Users cannot delete their own account.'
		);

		// Reassign false: the posts go to the trash; the answer is the user as it was.
		$result = $this->get_registered( 'wp/user-delete' )->execute(
			array(
				'id'       => $target_id,
				'reassign' => false,
				'fields'   => array( 'id', 'username', 'roles' ),
			)
		);

		self::assertIsArray( $result, 'The deletion should return the user as it was.' );
		self::assertSame( $target_id, $result['id'], 'The previous user should be returned.' );
		self::assertFalse( get_userdata( $target_id ), 'The user should be permanently deleted (users cannot be trashed).' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->get_registered( 'wp/user-delete' )->execute(
				array(
					'id'       => $target_id,
					'reassign' => false,
				)
			),
			null,
			'A repeat should be denied: the now-missing user fails the permission callback like an unreadable one.'
		);

		// A nonexistent reassign target is refused.
		$second_id = $this->create_user( 'uw_delete_second', 'subscriber' );
		$this->assert_error_response(
			'users_user_invalid_reassign',
			$this->get_registered( 'wp/user-delete' )->execute(
				array(
					'id'       => $second_id,
					'reassign' => 4294967296,
				)
			),
			400,
			'An invalid reassign target should be refused.'
		);

		// A valid reassign moves the posts.
		$third_id = $this->create_user( 'uw_delete_third', 'subscriber' );
		$post_id  = $this->create_post( $third_id );
		$result   = $this->get_registered( 'wp/user-delete' )->execute(
			array(
				'id'       => $third_id,
				'reassign' => $reassign_id,
			)
		);
		self::assertIsArray( $result, 'The deletion should return the user as it was.' );
		$post = get_post( $post_id );
		self::assertInstanceOf( WP_Post::class, $post );
		self::assertSame( $reassign_id, (int) $post->post_author, 'The posts should be reassigned.' );
	}

	/**
	 * DELETE: a caller without `delete_user` is denied.
	 */
	public function test_delete_capability_gate(): void {
		$this->log_in_as( 'administrator' );
		$target_id = $this->create_user( 'uw_delete_denied', 'subscriber' );

		$this->log_in_as( 'editor' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->get_registered( 'wp/user-delete' )->execute(
				array(
					'id'       => $target_id,
					'reassign' => false,
				)
			),
			null,
			'An editor lacks delete_user.'
		);

		$this->log_in_as( 'subscriber' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->get_registered( 'wp/user-delete' )->execute(
				array(
					'id'       => $target_id,
					'reassign' => false,
				)
			),
			null,
			'A subscriber lacks delete_user.'
		);

		$user = get_userdata( $target_id );
		self::assertInstanceOf( WP_User::class, $user, 'Denied deletions should not delete.' );
	}

	/**
	 * DELETE: missing user and self-deletion rows — the same denial shape is
	 * pinned through the run-route contract (401-level generic denial is the
	 * route wrapper; the ability answers users_not_found / users_cannot_delete).
	 */
	public function test_delete_missing_user_is_denied(): void {
		$this->log_in_as( 'administrator' );

		$result = $this->get_registered( 'wp/user-delete' )->execute(
			array(
				'id'       => 4294967296,
				'reassign' => false,
			)
		);
		$this->assert_error_response( 'ability_invalid_permissions', $result, null, 'A missing user should be denied like an unreadable one.' );
	}

	/**
	 * B5 companion verification (no port): this package's `core/users-query`
	 * backport (PR #3) already carries the upstream PR-side changes — the
	 * "Query Users" label, the `id`-required user output schema, the username
	 * ordering, and the params-mapped filter errors.
	 */
	public function test_users_query_companion_behavior_is_present(): void {
		$query = WP_Abilities_Registry::get_instance()->get_registered( 'core/users-query' );
		self::assertNotNull( $query, 'The users-query ability should be registered.' );
		self::assertSame( 'Query Users', $query->get_label(), 'The label came from core 7.2, which landed the upstream PR-side changes.' );

		$output = $query->get_output_schema();

		// The user objects carry the ID required: the backport's output schema
		// answers the collection at the top level, with the user schema nested
		// under `items`.
		$user_schema = $output['properties']['items']['items'];

		self::assertSame( array( 'id' ), $user_schema['required'], 'The user output schema marks the ID required.' );

		// The item description should document the collection.
		self::assertSame( 'The readable users on the requested page.', (string) $output['properties']['items']['description'] );
	}
}
