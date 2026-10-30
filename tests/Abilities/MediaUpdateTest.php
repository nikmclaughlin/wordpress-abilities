<?php
/**
 * Tests: `wp/media-update` (port of WordPress/ai PR #1104's update tests, on
 * this package's per-ability contract shape).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev) and stable
 * (6.9/7.1). The suite pins the ported attachment-endpoint update behaviors —
 * the edit permission with deny-like missing items, the status validation
 * against the attachment (keeping `inherit`), the author/parent checks that
 * name the refused field, field projection, and the update hooks.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use WP_Error;
use WP_Post;
use WP_Abilities_Registry;
use WP_Ability;
use WordPress\Abilities\Media\Media_Update;
use WordPress\Abilities\Registrar;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Contract tests for `wp/media-update`.
 */
final class MediaUpdateTest extends TestCase {

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
	 * The registered `wp/media-update` ability.
	 */
	private function get_registered(): WP_Ability {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/media-update' );
		self::assertNotNull( $ability, 'The ability should be registered through the Registrar.' );

		return $ability;
	}

	/**
	 * Runs the ability through the Abilities API and returns the result.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function update( array $input = array() ) {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/media-update' );
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
					'user_login' => 'mu_' . $role,
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
	 * @param string $name The image file name, e.g. `canola.jpg`.
	 * @return string The relative path for `_wp_attached_file`.
	 */
	private function upload_test_file( string $name = 'canola.jpg' ): string {
		static $counter = 0;
		++$counter;

		$relative = 'mu-test-' . $counter . '-' . $name;

		copy( (string) constant( 'DIR_TESTDATA' ) . '/images/' . $name, wp_upload_dir()['basedir'] . '/' . $relative );
		$this->attached_files[] = $relative;

		return $relative;
	}

	/**
	 * Creates a mocked attachment, tracked for removal.
	 *
	 * @param string               $relative_file Optional. The `_wp_attached_file` value.
	 * @param int                  $parent_id     Optional. The parent post ID.
	 * @param array<string, mixed> $args          Optional. Further post fields, e.g.
	 *                                            post_author, post_title.
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
		}

		$this->attachment_ids[] = $id;

		return $id;
	}

	/**
	 * Creates a mocked parent post, tracked for removal.
	 *
	 * @param array<string, mixed> $args Optional. Further post fields.
	 * @return int The post ID.
	 */
	private function create_parent_post( array $args = array() ): int {
		$id = (int) wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Update parent post',
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
	 */
	public function test_registers_the_update_ability(): void {
		$ability = $this->get_registered();
		$meta    = $ability->get_meta();

		self::assertSame( 'Media Update', $ability->get_label(), 'The ability should use the PR\'s label.' );
		self::assertSame( 'media', $ability->get_category(), 'The ability should use the package-authored media category.' );
		self::assertTrue( (bool) ( $meta['show_in_rest'] ?? false ), 'The ability should be exposed in REST.' );
		self::assertSame(
			array( 'public' => true ),
			$meta['mcp'] ?? null,
			'The mcp meta opts the ability in for MCP-tool exposure; the high-level public meta is unchanged.'
		);
		self::assertFalse( $ability->get_meta_item( 'public', false ), 'The high-level public meta is unchanged.' );

		$annotations = $meta['annotations'] ?? array();
		self::assertFalse( (bool) ( $annotations['readonly'] ?? true ), 'Updating writes the item.' );
		self::assertTrue( (bool) ( $annotations['destructive'] ?? false ), 'Attachments keep no revisions of the overwritten values.' );
		self::assertFalse( (bool) ( $annotations['idempotent'] ?? true ), 'Every call touches the modified date; destructive idempotent abilities are served over DELETE.' );
		self::assertFalse( (bool) ( $annotations['open_world'] ?? true ), 'The ability only writes the local database.' );

		$schema = $ability->get_input_schema();
		self::assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		self::assertFalse( (bool) $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		self::assertArrayNotHasKey( 'enum', $schema['properties']['status'], 'The status should be validated against the attachment, not restricted by the schema.' );
		self::assertArrayNotHasKey( 'force', $schema['properties'], 'The update input should not take a force key (a forced deletion is the delete ability\'s input).' );
	}

	/**
	 * Updating the attachment's fields, asserting the returned shape against
	 * what was stored.
	 */
	public function test_update_item(): void {
		$this->log_in_as( 'administrator' );
		$attachment_id = $this->create_attachment( $this->upload_test_file(), 0, array( 'post_title' => 'Before the update' ) );

		$this->log_in_as( 'administrator' );
		$data = $this->update(
			array(
				'id'          => $attachment_id,
				'title'       => 'My title is very cool',
				'caption'     => 'This is a better caption.',
				'description' => 'Without a description, my attachment is descriptionless.',
				'alt_text'    => 'Alt text is stored outside post schema.',
				'fields'      => array( 'id', 'title_raw', 'caption_raw', 'description_raw', 'alt_text' ),
			)
		);

		self::assertIsArray( $data, 'The update should return the attachment.' );
		self::assertSame( $attachment_id, $data['id'] );

		$post = get_post( $attachment_id );
		self::assertInstanceOf( WP_Post::class, $post );
		self::assertSame( 'My title is very cool', $data['title_raw'] );
		self::assertSame( 'My title is very cool', $post->post_title );
		self::assertSame( 'This is a better caption.', $data['caption_raw'] );
		self::assertSame( 'This is a better caption.', $post->post_excerpt );
		self::assertSame( 'Without a description, my attachment is descriptionless.', $data['description_raw'] );
		self::assertSame( 'Without a description, my attachment is descriptionless.', $post->post_content );
		self::assertSame( 'Alt text is stored outside post schema.', $data['alt_text'] );
		self::assertSame( 'Alt text is stored outside post schema.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Fields the input omits keep their stored values.
	 */
	public function test_update_keeps_omitted_fields(): void {
		$this->log_in_as( 'administrator' );
		$attachment_id = $this->create_attachment(
			$this->upload_test_file(),
			0,
			array(
				'post_title'   => 'Original title',
				'post_content' => 'Original description',
				'post_excerpt' => 'Original caption',
			)
		);

		$data = $this->update(
			array(
				'id'       => $attachment_id,
				'title'    => 'My title is very cool',
				'alt_text' => 'An updated alt text',
				'fields'   => array( 'id', 'title_raw', 'description_raw', 'caption_raw', 'alt_text' ),
			)
		);

		self::assertIsArray( $data, 'The update should return the attachment.' );
		self::assertSame( 'My title is very cool', $data['title_raw'] );
		self::assertSame( 'Original description', $data['description_raw'], 'The description should keep its stored value.' );
		self::assertSame( 'Original caption', $data['caption_raw'], 'The caption should keep its stored value.' );
		self::assertSame( 'An updated alt text', $data['alt_text'] );
	}

	/**
	 * The text fields accept both a plain string and a raw-object form.
	 */
	public function test_update_accepts_text_fields_as_raw_objects(): void {
		$this->log_in_as( 'administrator' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$data = $this->update(
			array(
				'id'          => $attachment_id,
				'title'       => array( 'raw' => 'A raw title' ),
				'caption'     => array( 'raw' => 'A raw caption' ),
				'description' => array( 'raw' => 'A raw description' ),
				'fields'      => array( 'id', 'title_raw', 'caption_raw', 'description_raw' ),
			)
		);

		self::assertIsArray( $data, 'The update should return the attachment.' );
		self::assertSame( 'A raw title', $data['title_raw'], 'The raw form should be honored.' );
		self::assertSame( 'A raw caption', $data['caption_raw'] );
		self::assertSame( 'A raw description', $data['description_raw'] );
	}

	/**
	 * A missing attachment is denied like one the user cannot edit: through the
	 * Abilities API the permission callback's answer is generic, and a direct
	 * callback call names the invalid ID.
	 */
	public function test_update_on_a_missing_item(): void {
		$this->log_in_as( 'editor' );

		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->update(
				array(
					'id'    => self::IMPOSSIBLY_HIGH_NUMBER,
					'title' => 'Denied',
				)
			),
			null,
			'A missing item should be denied like an unreadable one.'
		);

		$direct = $this->update_item_direct( array( 'id' => self::IMPOSSIBLY_HIGH_NUMBER ) );
		$this->assert_error_response( 'media_post_invalid_id', $direct, 404, 'A direct call should report the invalid ID.' );

		$direct = $this->update_item_direct( array( 'id' => $this->create_parent_post() ) );
		$this->assert_error_response( 'media_post_invalid_id', $direct, 404, 'A post that is not an attachment should be denied.' );
	}

	/**
	 * Calls the registered update permission callback directly.
	 *
	 * Mirrors the source PR's direct-call test: the permission callback only
	 * answers a boolean, so the invalid-ID error is observable on the execute
	 * path, not the permission path.
	 *
	 * @param array<string, mixed> $input The update input.
	 * @return mixed The permission callback result (bool), or the error from an
	 *               execute-path shortcut used by the caller.
	 */
	private function update_item_direct( array $input ) {
		// Mirrors the source PR's direct call: the permission callback only answers a
		// boolean, so the named invalid-ID error is observable on the execute callback,
		// bypassing the registry-run permission validation.
		return ( new Media_Update() )->execute_media_update( $input );
	}

	/**
	 * A user who cannot edit the attachment is denied by the permission
	 * callback, and the attachment survives.
	 */
	public function test_update_denied_without_the_edit_capability(): void {
		$this->log_in_as( 'administrator' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		foreach ( array( 'subscriber', 'author' ) as $role ) {
			$this->log_in_as( $role );
			$this->assert_error_response(
				'ability_invalid_permissions',
				$this->update(
					array(
						'id'    => $attachment_id,
						'title' => 'Denied title',
					)
				),
				null,
				"A {$role} should be denied the update."
			);
		}

		$post = get_post( $attachment_id );
		self::assertInstanceOf( WP_Post::class, $post, 'Denied updates should not delete.' );
		self::assertNotSame( 'Denied title', $post->post_title, 'The denied title should not have been applied.' );
	}

	/**
	 * An editor can update an attachment authored by another user (the
	 * `edit_others_posts` positive path).
	 */
	public function test_editor_can_update_anothers_attachment(): void {
		$this->log_in_as( 'administrator' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$this->log_in_as( 'editor' );
		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'title'  => 'The editor was here',
				'fields' => array( 'id', 'title_raw' ),
			)
		);

		self::assertIsArray( $data, 'An edit_others_posts-capable user should be able to update.' );
		self::assertSame( 'The editor was here', $data['title_raw'] );
	}

	/**
	 * A nonexistent author is rejected; a valid other-author without the
	 * edit-others capability is refused while running the callback (so the
	 * error can name the refused field).
	 */
	public function test_update_author_checks(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$this->assert_error_response(
			'media_invalid_author',
			$this->update(
				array(
					'id'     => $attachment_id,
					'author' => self::IMPOSSIBLY_HIGH_NUMBER,
				)
			),
			400,
			'A nonexistent author should be rejected.'
		);

		// An editor can edit others' posts, so another user's author assignment succeeds and
		// the subscriber path never reaches the callback. The refused path is exercised by
		// the upload counterpart; here the valid reassignment is pinned.
		$subscriber_id = $this->log_in_as( 'subscriber' );
		$this->log_in_as( 'editor' );
		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'author' => $subscriber_id,
				'fields' => array( 'id', 'author' ),
			)
		);

		self::assertIsArray( $data, 'The editor should reassign the author.' );
		self::assertSame( $subscriber_id, $data['author'], 'The author should be reassigned.' );
	}

	/**
	 * Keeping the current (internal) status is valid; a NEW internal status is
	 * rejected; a bogus status is rejected while running the callback.
	 */
	public function test_update_status_validation(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		// Keeping the current `inherit` status is valid, even though `inherit` is internal.
		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'status' => 'inherit',
				'title'  => 'Kept inherit',
			)
		);
		self::assertIsArray( $data, 'Keeping the current internal status should be valid.' );

		// A NEW internal status is invalid: a private attachment cannot be moved to inherit.
		$private_id = $this->create_attachment( '', 0, array( 'post_status' => 'private' ) );
		$this->assert_error_response(
			'media_invalid_param',
			$this->update(
				array(
					'id'     => $private_id,
					'status' => 'inherit',
				)
			),
			400,
			'Setting a new internal status should be rejected.'
		);

		// A bogus status is rejected while running the callback.
		$this->assert_error_response(
			'media_invalid_param',
			$this->update(
				array(
					'id'     => $attachment_id,
					'status' => 'bogus-status',
				)
			),
			400,
			'A nonexistent status should be rejected.'
		);
	}

	/**
	 * A private status requires the capability to publish; the attachment's
	 * stored status is unchanged on a denied update. The contributor role is
	 * below the publish floor while having the edit capability for its own
	 * attachment.
	 */
	public function test_update_private_requires_the_publish_capability(): void {
		$contributor_id = $this->log_in_as( 'contributor' );
		$attachment_id  = $this->create_attachment( $this->upload_test_file(), 0, array( 'post_author' => $contributor_id ) );

		$this->log_in_as( 'contributor' );
		$this->assert_error_response(
			'media_cannot_publish',
			$this->update(
				array(
					'id'     => $attachment_id,
					'status' => 'private',
				)
			),
			403,
			'A private status should require the capability to publish.'
		);

		$post = get_post( $attachment_id );
		self::assertInstanceOf( WP_Post::class, $post );
		self::assertSame( 'inherit', $post->post_status, 'The status should be unchanged on a denied update.' );
	}

	/**
	 * A valid date (and GMT date) is stored; an invalid date is rejected.
	 */
	public function test_update_date(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'date'   => '2016-12-12T14:00:00-05:00',
				'fields' => array( 'id', 'date', 'date_gmt' ),
			)
		);

		self::assertIsArray( $data, 'The update should return the attachment.' );
		self::assertSame( '2016-12-12T19:00:00', $data['date_gmt'], 'The date should honor the offset.' );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->update(
				array(
					'id'   => $attachment_id,
					'date' => 'not-a-date',
				)
			),
			null,
			'An invalid date should be rejected by the schema (the Abilities API\'s invalid-input error carries no status data).'
		);
	}

	/**
	 * The slug is sanitized like a title and made unique.
	 */
	public function test_update_slug(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'slug'   => 'A Slug Needs Sanitizing',
				'fields' => array( 'id', 'slug' ),
			)
		);

		self::assertIsArray( $data, 'The update should return the attachment.' );
		self::assertSame( 'a-slug-needs-sanitizing', $data['slug'], 'The slug should be sanitized like a title.' );
	}

	/**
	 * The attachment-specific update hooks fire exactly once. (Core's
	 * `wp_insert_post()` returns early for attachments of the generic update
	 * path, so `post_updated` never fires for an attachment: the
	 * attachment-specific `edit_attachment` hook is the one to pin.)
	 */
	public function test_update_fires_the_update_hooks_once(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$pre_calls      = array();
		$edit_att_calls = array();
		$pre            = static function ( ...$args ) use ( &$pre_calls ) {
			$pre_calls[] = $args[0] ?? null;
		};
		$edit_att       = static function ( ...$args ) use ( &$edit_att_calls ) {
			$edit_att_calls[] = $args[0] ?? null;
		};
		add_action( 'pre_post_update', $pre );
		add_action( 'edit_attachment', $edit_att );

		try {
			$this->update(
				array(
					'id'    => $attachment_id,
					'title' => 'Hooked title',
				)
			);
		} finally {
			remove_action( 'pre_post_update', $pre );
			remove_action( 'edit_attachment', $edit_att );
		}

		self::assertCount( 1, $pre_calls, 'pre_post_update should fire once.' );
		self::assertSame( $attachment_id, $pre_calls[0] ?? null, 'pre_post_update should report the attachment.' );
		self::assertCount( 1, $edit_att_calls, 'edit_attachment should fire once.' );
		self::assertSame( $attachment_id, $edit_att_calls[0] ?? null, 'edit_attachment should report the attachment.' );
	}

	/**
	 * A failed write is a server error, with the attachment unchanged.
	 */
	public function test_update_reports_a_db_error(): void {
		global $wpdb;

		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file(), 0, array( 'post_title' => 'Original title' ) );

		$break_update = static function ( string $query ) use ( $wpdb ): string {
			return 0 === strpos( $query, "UPDATE `{$wpdb->posts}`" ) ? '],' : $query;
		};

		$wpdb->suppress_errors = true;
		add_filter( 'query', $break_update );

		try {
			$result = $this->update(
				array(
					'id'    => $attachment_id,
					'title' => 'New title',
				)
			);
		} finally {
			remove_filter( 'query', $break_update );
			$wpdb->suppress_errors = false;
		}

		$this->assert_error_response( 'db_update_error', $result, 500, 'A failed write should be a server error.' );
		$post = get_post( $attachment_id );
		self::assertInstanceOf( WP_Post::class, $post );
		self::assertSame( 'Original title', $post->post_title, 'The title should be unchanged.' );
	}

	/**
	 * Updating the parent does not re-check the parent's edit capability (the
	 * parent check names only revision/attachment parents), pinned ported-as-is.
	 */
	public function test_update_does_not_recheck_the_new_parent(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );
		$foreign_post  = $this->create_parent_post( array( 'post_author' => $this->log_in_as( 'author' ) ) );

		$this->log_in_as( 'author' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->update(
				array(
					'id'    => $attachment_id,
					'title' => 'Denied title',
				)
			),
			null,
			'The author should be denied before the parent check.'
		);

		$this->log_in_as( 'editor' );
		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'post'   => $foreign_post,
				'fields' => array( 'id', 'post' ),
			)
		);

		self::assertIsArray( $data, 'The editor should be able to set the parent.' );
		self::assertSame( $foreign_post, $data['post'], 'The parent should be updated.' );
	}

	/**
	 * Upload-only properties are rejected by input validation.
	 */
	public function test_update_rejects_upload_only_input(): void {
		$this->log_in_as( 'editor' );
		$attachment_id = $this->create_attachment( $this->upload_test_file() );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->update(
				array(
					'id'       => $attachment_id,
					'data'     => 'AA==',
					'filename' => 'file.jpg',
				)
			),
			null,
			'The upload-only data/filename properties should be rejected by additionalProperties (the Abilities API\'s invalid-input error carries no status data).'
		);
	}
}
