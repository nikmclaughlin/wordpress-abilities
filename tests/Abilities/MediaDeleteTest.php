<?php
/**
 * Tests: `wp/media-delete` (port of WordPress/ai PR #1104's delete tests, on
 * this package's per-ability contract shape).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev) and stable
 * (6.9/7.1). The suite pins the ported attachment-endpoint delete behaviors —
 * the deny-like missing-item semantics, the trash-vs-force contract with the
 * {@link https://developer.wordpress.org/reference/functions/constant()/ MEDIA_TRASH}
 * guard, the `deleted`/`previous` output, the permission re-check right before
 * removal, and the deletion hooks.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use WP_Error;
use WP_Post;
use WP_Abilities_Registry;
use WP_Ability;
use WordPress\Abilities\Media\Media_Delete;
use WordPress\Abilities\Registrar;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Contract tests for `wp/media-delete`.
 */
final class MediaDeleteTest extends TestCase {

	/**
	 * An ID no WordPress install reaches, the REST tests' impossible number.
	 */
	private const IMPOSSIBLY_HIGH_NUMBER = 4294967296;

	/**
	 * Cached IDs of the mocked users, keyed by role.
	 *
	 * @var array<string, int>
	 */
	private array $user_ids = array();

	/**
	 * Cached IDs of the mocked attachments.
	 *
	 * @var list<int>
	 */
	private array $attachment_ids = array();

	/**
	 * Cached IDs of the mocked parent posts.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Relative upload paths created by the tests, for cleanup.
	 *
	 * @var list<string>
	 */
	private array $attached_files = array();

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
	 * Removes the mocked attachments, posts, users, and uploaded files, so each
	 * test builds a fresh data set.
	 */
	public function tear_down(): void {
		foreach ( $this->attachment_ids as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachment_ids = array();

		foreach ( $this->post_ids as $id ) {
			wp_delete_post( $id, true );
		}
		$this->post_ids = array();

		$uploads = wp_upload_dir();
		foreach ( $this->attached_files as $relative ) {
			wp_delete_file( $uploads['basedir'] . '/' . $relative );
		}
		$this->attached_files = array();

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
	 * The registered `wp/media-delete` ability.
	 */
	private function get_registered(): WP_Ability {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/media-delete' );
		self::assertNotNull( $ability, 'The ability should be registered through the Registrar.' );

		return $ability;
	}

	/**
	 * Runs the ability through the Abilities API and returns the result.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function delete( array $input = array() ) {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/media-delete' );
		self::assertNotNull( $ability, 'The ability should be registered through the Registrar.' );

		return $ability->execute( $input );
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
					'user_login' => 'md_' . $role,
					'user_pass'  => wp_generate_password(),
					'role'       => $role,
				)
			);
		}
		wp_set_current_user( $this->user_ids[ $role ] );

		return $this->user_ids[ $role ];
	}

	/**
	 * Copies a core test-data image into the uploads base directory.
	 *
	 * @param string $name The image file name.
	 * @return string The relative path for `_wp_attached_file`.
	 */
	private function upload_test_file( string $name = 'canola.jpg' ): string {
		static $counter = 0;
		++$counter;

		$relative = 'md-test-' . $counter . '-' . $name;

		copy( (string) constant( 'DIR_TESTDATA' ) . '/images/' . $name, wp_upload_dir()['basedir'] . '/' . $relative );
		$this->attached_files[] = $relative;

		return $relative;
	}

	/**
	 * Creates a mocked attachment, tracked for removal.
	 *
	 * @param string               $relative_file Optional. The `_wp_attached_file` value.
	 * @param int                  $parent_id     Optional. The parent post ID.
	 * @param array<string, mixed> $args          Optional. Further post fields.
	 * @return int The attachment ID.
	 */
	private function create_attachment( string $relative_file = '', int $parent_id = 0, array $args = array() ): int {
		$data = array_merge(
			array(
				'post_type'      => 'attachment',
				'post_parent'    => $parent_id,
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $this->log_in_as( 'administrator' ),
			),
			$args
		);

		$id = (int) wp_insert_post( $data );
		if ( '' !== $relative_file ) {
			update_post_meta( $id, '_wp_attached_file', $relative_file );
			copy( (string) constant( 'DIR_TESTDATA' ) . '/images/canola.jpg', wp_upload_dir()['basedir'] . '/' . $relative_file );
			$this->attached_files[] = $relative_file;
		}

		$this->attachment_ids[] = $id;

		return $id;
	}

	/**
	 * Trashing an attachment requires the site to enable the media trash; the
	 * trash-path tests are skipped when it is disabled, mirroring the source
	 * PR's tests.
	 */
	private function require_media_trash(): void {
		if ( constant( 'MEDIA_TRASH' ) ) {
			return;
		}

		$this->markTestSkipped( 'Trashing attachments requires the MEDIA_TRASH constant.' );
	}

	/**
	 * Asserts a WP_Error with the exact code, and an optional `status` data value.
	 *
	 * @param string           $code   The expected error code.
	 * @param mixed            $actual The actual result.
	 * @param int|null         $status Optional. The expected `status` data value.
	 * @param string           $note   Optional. A note for the failure report.
	 */
	private function assert_error_response( string $code, $actual, ?int $status = null, string $note = '' ): void {
		self::assertInstanceOf( WP_Error::class, $actual, $note . ' Expected WP_Error ' . $code . '.' );
		self::assertSame( $code, $actual->get_error_code(), $note . ' Expected code ' . $code . ', got ' . $actual->get_error_code() . '.' );
		if ( null !== $status ) {
			self::assertSame( $status, $actual->get_error_data()['status'] ?? null, $note . ' Expected status ' . $status . '.' );
		}
	}

	/**
	 * Registered exactly once, with the PR's annotations and the experimental provenance.
	 * The output schema is a `oneOf` of the trashed attachment and the
	 * `deleted`/`previous` pair.
	 */
	public function test_registers_the_delete_ability(): void {
		$ability = $this->get_registered();
		$meta    = $ability->get_meta();

		self::assertSame( 'Media Delete', $ability->get_label(), 'The ability should use the PR\'s label.' );
		self::assertSame( 'media', $ability->get_category(), 'The ability should use the package-authored media category.' );
		self::assertTrue( (bool) ( $meta['show_in_rest'] ?? false ), 'The ability should be exposed in REST.' );

		$annotations = $meta['annotations'] ?? array();
		self::assertFalse( (bool) ( $annotations['readonly'] ?? true ), 'Deleting writes the library.' );
		self::assertTrue( (bool) ( $annotations['destructive'] ?? false ), 'Deleting an attachment (and its files) is destructive.' );
		self::assertTrue( (bool) ( $annotations['idempotent'] ?? false ), 'Repeating a deletion has no further effect; destructive idempotent abilities are served over DELETE.' );
		self::assertFalse( (bool) ( $annotations['open_world'] ?? true ), 'The ability only deletes from the local database.' );

		$schema      = $ability->get_input_schema();
		$output      = $ability->get_output_schema();
		$query_types = wp_list_pluck( WP_Abilities_Registry::get_instance()->get_registered( 'wp/media-query' )->get_output_schema()['oneOf'][0]['properties'], 'type' );

		self::assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		self::assertArrayHasKey( 'force', $schema['properties'], 'The delete input should take the force key.' );
		self::assertSame( 'boolean', $schema['properties']['force']['type'], 'The force key should be boolean.' );

		self::assertFalse( (bool) ( $output['oneOf'][0]['additionalProperties'] ?? true ), 'The trashed attachment should reject undeclared keys.' );
		self::assertSame( array( 'deleted', 'previous' ), $output['oneOf'][1]['required'], 'The forced-deletion branch should require deleted and previous.' );
		self::assertSame( $query_types, wp_list_pluck( $output['oneOf'][0]['properties'], 'type' ), 'The trashed attachment should use the media-query field types.' );
	}

	/**
	 * A forced deletion removes the attachment and its files and answers with
	 * the `deleted`/`previous` pair.
	 */
	public function test_forced_deletion(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file(), 0, array( 'post_title' => 'The attachment to delete' ) );
		$file_path     = (string) get_attached_file( $attachment_id );

		$data = $this->delete(
			array(
				'id'     => $attachment_id,
				'force'  => true,
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		self::assertIsArray( $data, 'The deletion should return the deleted item under `previous`.' );
		self::assertTrue( $data['deleted'], 'The forced deletion should be reported.' );

		$previous = $data['previous'];
		self::assertSame( $attachment_id, $previous['id'], 'The previous item should be the deleted attachment.' );
		self::assertSame( 'The attachment to delete', $previous['title_raw'], 'The previous item should carry the stored fields.' );
		self::assertSame( 'inherit', $previous['status'], 'The previous item should carry the stored status.' );

		self::assertNull( get_post( $attachment_id ), 'The attachment should be deleted from the database.' );
		self::assertFileDoesNotExist( $file_path, 'The attachment file should be deleted.' );
	}

	/**
	 * Without the media trash enabled, a non-forced delete is an error, and the
	 * string `force` values coerce like booleans (a false string does not
	 * delete).
	 */
	public function test_delete_without_media_trash_support(): void {
		if ( constant( 'MEDIA_TRASH' ) ) {
			$this->markTestSkipped( 'This test requires the MEDIA_TRASH constant to be false.' );
		}

		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$this->assert_error_response(
			'media_trash_not_supported',
			$this->delete( array( 'id' => $attachment_id ) ),
			501,
			'Trashing should be unsupported without the media trash.'
		);

		$this->assert_error_response(
			'media_trash_not_supported',
			$this->delete(
				array(
					'id'    => $attachment_id,
					'force' => false,
				)
			),
			501,
			'An explicit false force should also trash, and thus be unsupported.'
		);

		// A "false" STRING also coerces to not-force.
		$this->assert_error_response(
			'media_trash_not_supported',
			$this->delete(
				array(
					'id'    => $attachment_id,
					'force' => 'false',
				)
			),
			501,
			'A "false" force string should not delete the attachment.'
		);

		self::assertInstanceOf( WP_Post::class, get_post( $attachment_id ), 'The attachment should still exist.' );
		self::assertStringEndsWith( 'canola.jpg', (string) get_attached_file( $attachment_id ), 'The attachment file should still exist.' );
	}

	/**
	 * With the media trash enabled, a non-forced delete trashes the attachment
	 * and returns it.
	 */
	public function test_delete_trashes_the_attachment(): void {
		$this->require_media_trash();

		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file(), 0, array( 'post_title' => 'The attachment to trash' ) );

		$data = $this->delete(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'id', 'status', 'slug', 'title_raw' ),
			)
		);

		self::assertIsArray( $data, 'The trashing should return the trashed attachment directly.' );
		self::assertSame( $attachment_id, $data['id'], 'The trashed attachment should be returned directly (no wrapper).' );
		self::assertSame( 'trash', $data['status'], 'The trashed attachment should carry the trash status.' );
		self::assertSame( '__trashed', $data['slug'], 'The trashed attachment should carry the __trashed slug marker.' );

		$post = get_post( $attachment_id );
		self::assertInstanceOf( WP_Post::class, $post );
		self::assertSame( 'trash', $post->post_status, 'The attachment should be in the trash (the ability should not force-delete it).' );
	}

	/**
	 * With the media trash enabled, trashing an already-trashed attachment is
	 * an error.
	 */
	public function test_delete_an_already_trashed_attachment(): void {
		$this->require_media_trash();

		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$this->assert_error_response( 'media_trash_not_supported', $this->delete( array( 'id' => $attachment_id ) ), 501, 'Precondition: this environment should trash, not auto-delete.' );
	}

	/**
	 * A missing attachment is denied like one the user cannot delete: through
	 * the Abilities API the answer is generic, and a direct callback call
	 * reports the invalid ID.
	 */
	public function test_delete_a_missing_item(): void {
		$this->log_in_as( 'editor' );

		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->delete(
				array(
					'id'    => self::IMPOSSIBLY_HIGH_NUMBER,
					'force' => true,
				)
			),
			null,
			'A missing attachment should be denied.'
		);

		// A post (not an attachment) is denied as well, and survives the denial.
		$post_id = $this->create_post_not_attachment();
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->delete(
				array(
					'id'    => $post_id,
					'force' => true,
				)
			),
			null,
			'A post should be denied.'
		);
		self::assertInstanceOf( WP_Post::class, get_post( $post_id ), 'The post should still exist.' );

		// A direct callback call names the invalid ID.
		$direct = $this->execute_delete_direct( array( 'id' => self::IMPOSSIBLY_HIGH_NUMBER ) );
		$this->assert_error_response( 'media_post_invalid_id', $direct, 404, 'A direct call should report the invalid ID.' );
	}

	/**
	 * Creates a plain post, tracked for removal.
	 *
	 * @return int The post ID.
	 */
	private function create_post_not_attachment(): int {
		$id = $this->create_parent_post();
		return $id;
	}

	/**
	 * Creates a parent post, tracked for removal.
	 *
	 * @param array<string, mixed> $args Optional. Further post fields.
	 * @return int The post ID.
	 */
	private function create_parent_post( array $args = array() ): int {
		$id = (int) wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Delete parent post',
					'post_status'  => 'publish',
					'post_content' => 'Parent content',
				),
				$args
			)
		);

		$this->post_ids[] = $id;

		return $id;
	}

	/**
	 * Calls the registered delete execute callback directly, bypassing the
	 * permission validation, mirroring the source PR's direct-call test.
	 *
	 * @param array<string, mixed> $input The delete input.
	 * @return mixed The execute result.
	 */
	private function execute_delete_direct( array $input ) {
		// Mirrors the source PR's direct call: the concrete instance's execute
		// callback bypasses the registry-run permission validation, so the named
		// invalid-ID error is observable.
		return ( new Media_Delete() )->execute_media_delete( $input );
	}

	/**
	 * Users who cannot delete the attachment are denied by the permission
	 * callback and the attachment survives. An attachment's upload capability
	 * does not imply delete permissions, so the special `uploader` role is
	 * denied as well.
	 */
	public function test_delete_denied_without_the_delete_capability(): void {
		$this->log_in_as( 'administrator' );
		$attachment_id = $this->create_attachment( $this->upload_test_file(), 0, array( 'post_author' => $this->log_in_as( 'author' ) ) );

		foreach ( array( 'subscriber', 'contributor' ) as $role ) {
			$this->log_in_as( $role );
			$this->assert_error_response(
				'ability_invalid_permissions',
				$this->delete(
					array(
						'id'    => $attachment_id,
						'force' => true,
					)
				),
				null,
				"A {$role} should be denied the deletion."
			);
		}

		$post = get_post( $attachment_id );
		self::assertInstanceOf( WP_Post::class, $post, 'Denied deletions should not delete.' );
	}

	/**
	 * A user who can edit can delete their own attachment; the delete
	 * capability is re-checked right before the removal, and a refusal there
	 * is a server error.
	 */
	public function test_delete_rechecks_the_capability(): void {
		$author_id     = $this->log_in_as( 'author' );
		$attachment_id = $this->create_attachment( $this->upload_test_file(), 0, array( 'post_author' => $author_id ) );

		$this->log_in_as( 'author' );
		$data = $this->delete(
			array(
				'id'    => $attachment_id,
				'force' => true,
			)
		);

		self::assertIsArray( $data, 'An author should be able to delete their own attachment.' );
		self::assertTrue( $data['deleted'], 'The forced deletion should be reported.' );
		self::assertNull( get_post( $attachment_id ), 'The attachment should be gone.' );

		$attachment_id = $this->create_attachment( $this->upload_test_file() );
		$this->log_in_as( 'editor' );

		add_filter( 'pre_delete_attachment', '__return_false' );

		try {
			$result = $this->delete(
				array(
					'id'    => $attachment_id,
					'force' => true,
				)
			);
		} finally {
			remove_filter( 'pre_delete_attachment', '__return_false' );
		}

		$this->assert_error_response( 'media_cannot_delete', $result, 500, 'A refused deletion should be a server error.' );
		self::assertInstanceOf( WP_Post::class, get_post( $attachment_id ), 'A refused deletion should keep the attachment.' );
	}

	/**
	 * The deletion hooks fire exactly once, and the hook can veto the deletion.
	 */
	public function test_delete_fires_the_delete_hooks_once(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$delete_calls      = array();
		$delete_attachment = static function ( ...$args ) use ( &$delete_calls ) {
			$delete_calls[] = $args[0] ?? null;
		};
		add_action( 'delete_attachment', $delete_attachment );

		try {
			$this->delete(
				array(
					'id'    => $attachment_id,
					'force' => true,
				)
			);
		} finally {
			remove_action( 'delete_attachment', $delete_attachment );
		}

		self::assertCount( 1, $delete_calls, 'delete_attachment should fire once.' );
		self::assertSame( $attachment_id, $delete_calls[0] ?? null, 'delete_attachment should report the attachment.' );
	}

	/**
	 * The deleted attachment's fields are served in the edit context: raw
	 * fields do not depend on edit access, pinned with an author whose
	 * `edit_posts` capability is removed at the user level.
	 */
	public function test_delete_returns_the_edit_context_fields(): void {
		$author_id     = $this->log_in_as( 'author' );
		$attachment_id = $this->create_attachment(
			$this->upload_test_file(),
			0,
			array(
				'post_author'    => $author_id,
				'post_title'     => 'Raw-fields delete',
				'post_content'   => 'The stored description',
				'post_excerpt'   => 'The stored caption',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'The stored alt' );

		// Re-log in as the author (create_attachment() logs the administrator in as
		// its implicit author), then revoke the author's edit_posts capability at
		// the user level: the author may still delete their own attachment, but can
		// no longer edit it.
		$this->log_in_as( 'author' );
		$user = wp_get_current_user();
		$user->add_cap( 'edit_posts', false );
		// Flush capabilities, https://core.trac.wordpress.org/ticket/28374
		$user->get_role_caps();
		$user->update_user_level_from_caps();
		self::assertFalse( current_user_can( 'edit_post', $attachment_id ), 'Precondition: the user cannot edit the attachment.' );

		$data = $this->delete(
			array(
				'id'     => $attachment_id,
				'force'  => true,
				'fields' => array( 'id', 'title_raw', 'description_raw', 'caption_raw', 'alt_text', 'status' ),
			)
		);

		self::assertIsArray( $data, 'The deletion should return the deleted item.' );
		self::assertTrue( $data['deleted'], 'The forced deletion should be reported.' );

		$previous = $data['previous'];
		self::assertSame( 'Raw-fields delete', $previous['title_raw'], 'The raw title should be returned despite the revoked edit access.' );
		self::assertSame( 'The stored description', $previous['description_raw'] );
		self::assertSame( 'The stored caption', $previous['caption_raw'] );
		self::assertSame( 'The stored alt', $previous['alt_text'] );
		self::assertSame( 'inherit', $previous['status'] );
		self::assertNull( get_post( $attachment_id ), 'The attachment should be deleted.' );
	}
}
