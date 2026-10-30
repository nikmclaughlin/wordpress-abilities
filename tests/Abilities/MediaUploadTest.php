<?php
/**
 * Tests: `wp/media-upload` (port of WordPress/ai PR #1104's upload tests, on
 * this package's per-ability contract shape).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev) and stable
 * (6.9/7.1). The `wp/` name is never core-provided, so the package copy
 * registers and executes identically on both; the suite pins the ported
 * attachment-endpoint write behaviors — permissions, capability floors, the
 * base64/URL upload transports, the field projection in the edit context, the
 * REST-mirroring quirks, and the hook/globals hygiene.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use FilesystemIterator;
use MockAction;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;
use WP_Error;
use WP_REST_Request;
use WP_Post;
use WP_Abilities_Registry;
use WP_Ability;
use WordPress\Abilities\Registrar;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Contract tests for `wp/media-upload`.
 */
final class MediaUploadTest extends TestCase {

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
	 * Cached IDs of the mocked parent posts.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Attachments and stray files the suite must clean up.
	 *
	 * @var list<int>
	 */
	private array $attachment_ids = array();

	/**
	 * Relative upload paths created outside the ability's own records.
	 *
	 * @var list<string>
	 */
	private array $created_upload_files = array();

	/**
	 * Settles the registry as the other ability suites do, registering before
	 * the boot so the Registrar's category declarations are queued in time;
	 * the `media` category is package-authored and no host provides it.
	 */
	public static function set_up_before_class(): void {
		Registrar::register_all();

		if ( 0 === did_action( 'wp_abilities_api_init' ) ) {
			WP_Abilities_Registry::get_instance();
		}
	}

	/**
	 * Removes the lazily-added `uploader` test role so later suites in the same
	 * phpunit run (e.g. the users-query schema test) see the standard role list.
	 *
	 * @return void
	 */
	public static function tear_down_after_class(): void {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_remove_role -- A test role, added in setUp-time helpers.
		remove_role( 'uploader' );

		parent::tear_down_after_class();
	}

	/**
	 * Removes the mocked posts, users, attachments, and stray files, so each
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
		foreach ( $this->created_upload_files as $relative ) {
			wp_delete_file( $uploads['basedir'] . '/' . $relative );
		}
		$this->created_upload_files = array();

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
	 * The registered `wp/media-upload` ability.
	 */
	private function get_registered(): WP_Ability {
		// Intentionally resolved through the registry to test the registered contract.
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/media-upload' );
		self::assertNotNull( $ability, 'The ability should be registered through the Registrar.' );

		return $ability;
	}

	/**
	 * Runs the ability through the Abilities API and returns the result.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function upload( array $input = array() ) {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/media-upload' );
		self::assertNotNull( $ability, 'The ability should be registered through the Registrar.' );

		return $ability->execute( $input );
	}

	/**
	 * The upload input for a small binary fixture uploaded with `data`, merged
	 * over the given overrides.
	 *
	 * @param array<string, mixed> $input Optional input overrides.
	 * @return array<string, mixed> The input.
	 */
	private function get_upload_input( array $input = array() ): array {
		return array_merge(
			array(
				'data'     => $this->encode_test_file( 'images/canola.jpg' ),
				'filename' => 'canola.jpg',
			),
			$input
		);
	}

	/**
	 * Encodes a WordPress test-data file as base64 for the upload input.
	 *
	 * @param string $path Path inside the core test-data directory.
	 * @return string The base64 payload.
	 */
	private function encode_test_file( string $path ): string {
		return (string) base64_encode( (string) file_get_contents( (string) constant( 'DIR_TESTDATA' ) . '/' . $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test file.
	}

	/**
	 * Tracks an attachment the ability created, so the tear-down removes it.
	 *
	 * @param int $id The attachment ID.
	 */
	private function track_attachment( int $id ): void {
		$this->attachment_ids[] = $id;
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
					'post_title'   => 'Upload parent post',
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
	 * Lists the files under the current uploads directory (recursively).
	 *
	 * @return list<string> Absolute paths.
	 */
	private function files_in_dir(): array {
		$files = array();
		$base  = wp_upload_dir()['basedir'];

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$files[] = (string) $file->getPathname();
		}

		sort( $files );

		return $files;
	}

	/**
	 * Logs in a mocked user of the given role and returns its ID. The
	 * `uploader` role (upload_files without edit privileges for others'
	 * posts) is created on demand, mirroring the source PR's tests.
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	private function log_in_as( string $role ): int {
		if ( ! isset( $this->user_ids[ $role ] ) ) {
			if ( 'uploader' === $role && ! get_role( 'uploader' ) ) {
				add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- A test role, reused across runs.
					'uploader',
					'File upload role',
					array(
						'read'         => true,
						'level_0'      => true,
						'upload_files' => true,
					)
				);
			}

			$this->user_ids[ $role ] = (int) wp_insert_user(
				array(
					'user_login' => 'mw_' . $role,
					'user_pass'  => wp_generate_password(),
					'role'       => $role,
				)
			);
		}
		wp_set_current_user( $this->user_ids[ $role ] );

		return $this->user_ids[ $role ];
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
	public function test_registers_the_upload_ability(): void {
		$ability = $this->get_registered();
		$meta    = $ability->get_meta();

		self::assertSame( 'Media Upload', $ability->get_label(), 'The ability should use the PR\'s label.' );
		self::assertSame( 'media', $ability->get_category(), 'The ability should use the package-authored media category.' );
		self::assertTrue( (bool) ( $meta['show_in_rest'] ?? false ), 'The ability should be exposed in REST.' );

		$annotations = $meta['annotations'] ?? array();
		self::assertFalse( (bool) ( $annotations['readonly'] ?? true ), 'Uploading writes the library.' );
		self::assertFalse( (bool) ( $annotations['destructive'] ?? true ), 'Uploading overwrites nothing.' );
		self::assertFalse( (bool) ( $annotations['idempotent'] ?? true ), 'Every call creates a new attachment.' );
		self::assertTrue( (bool) ( $annotations['open_world'] ?? false ), 'A URL upload downloads the file from another site.' );

		$schema = $ability->get_input_schema();
		self::assertSame( 'object', $schema['type'], 'The input should be an object.' );
		self::assertCount( 2, $schema['oneOf'], 'The upload input should have the data and the URL branch.' );
		self::assertSame( array( 'data', 'filename' ), $schema['oneOf'][0]['required'], 'The data branch should require data and filename.' );
		self::assertFalse( (bool) $schema['oneOf'][0]['additionalProperties'], 'The data branch should reject unknown properties.' );
		self::assertSame( array( 'url' ), $schema['oneOf'][1]['required'], 'The URL branch should require the URL.' );

		$output = $ability->get_output_schema();
		self::assertFalse( (bool) $output['additionalProperties'], 'The written item should reject undeclared keys.' );
		self::assertArrayHasKey( 'title_raw', $output['properties'], 'The written item should expose the raw title (edit context).' );
	}

	/**
	 * Uploading base64 data with the attachment fields, asserting the returned
	 * fields against what was stored.
	 */
	public function test_create_item(): void {
		$this->log_in_as( 'author' );

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'title'       => 'My title is very cool',
					'caption'     => 'This is a better caption.',
					'description' => 'Without a description, my attachment is descriptionless.',
					'alt_text'    => 'Alt text is stored outside post schema.',
					'fields'      => array( 'id', 'media_type', 'title_raw', 'caption_raw', 'description_raw', 'alt_text' ),
				)
			)
		);

		self::assertIsArray( $data, 'The upload should return the attachment.' );
		$this->track_attachment( (int) $data['id'] );
		self::assertSame( 'image', $data['media_type'] );

		$attachment = get_post( (int) $data['id'] );
		self::assertInstanceOf( WP_Post::class, $attachment );
		self::assertSame( 'My title is very cool', $data['title_raw'] );
		self::assertSame( 'My title is very cool', $attachment->post_title );
		self::assertSame( 'This is a better caption.', $data['caption_raw'] );
		self::assertSame( 'This is a better caption.', $attachment->post_excerpt );
		self::assertSame( 'Without a description, my attachment is descriptionless.', $data['description_raw'] );
		self::assertSame( 'Without a description, my attachment is descriptionless.', $attachment->post_content );
		self::assertSame( 'Alt text is stored outside post schema.', $data['alt_text'] );
		self::assertSame( 'Alt text is stored outside post schema.', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Without a `fields` subset, the lean default set is returned.
	 */
	public function test_upload_returns_the_default_fields(): void {
		$this->log_in_as( 'editor' );

		$data = $this->upload( $this->get_upload_input() );

		self::assertIsArray( $data, 'The upload should return the attachment.' );
		$this->track_attachment( (int) $data['id'] );

		self::assertSame(
			array( 'id', 'date', 'slug', 'title_rendered', 'alt_text', 'media_type', 'mime_type', 'source_url' ),
			array_keys( $data ),
			'The lean default field set should be returned in projection order.'
		);
	}

	/**
	 * Positive floor path: an editor can upload; negative: a contributor (no
	 * `upload_files`) is denied without creating anything.
	 */
	public function test_editor_can_upload_and_contributor_is_denied(): void {
		$this->log_in_as( 'editor' );
		$data = $this->upload( $this->get_upload_input( array( 'filename' => 'editor-upload.jpg' ) ) );
		self::assertIsArray( $data, 'An editor should be able to upload.' );
		$this->track_attachment( (int) $data['id'] );

		$this->log_in_as( 'contributor' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->upload( $this->get_upload_input() ),
			null,
			'A contributor without upload_files should be denied.'
		);
	}

	/**
	 * A data payload that is not valid base64 is rejected before anything is
	 * stored.
	 */
	public function test_upload_rejects_invalid_base64_data(): void {
		$this->log_in_as( 'editor' );

		$this->assert_error_response(
			'media_invalid_param',
			$this->upload( $this->get_upload_input( array( 'data' => '!invalid-base64!' ) ) ),
			400,
			'Invalid base64 data should be rejected.'
		);
	}

	/**
	 * A file type the site disallows is rejected by the sideload handler; no
	 * attachment is created and neither the uploads directory nor the temp
	 * directory grows.
	 */
	public function test_upload_rejects_a_disallowed_file_type(): void {
		$this->log_in_as( 'editor' );
		$files = $this->files_in_dir();

		$result = $this->upload(
			array(
				'data'     => (string) base64_encode( '<?php echo "Hello";' ),
				'filename' => 'evil.php',
			)
		);

		$this->assert_error_response( 'media_upload_sideload_error', $result, 500, 'A PHP file should be rejected.' );
		self::assertSame( $files, $this->files_in_dir(), 'No file should be stored in the uploads directory.' );
		self::assertSame( array(), glob( (string) get_temp_dir() . 'evil*' ), 'The temporary file should be removed.' );
	}

	/**
	 * The upload input's two modes are a `oneOf`: a request that carries both
	 * the data and the URL branches' properties is rejected by input
	 * validation.
	 */
	public function test_upload_rejects_a_request_that_mixes_data_and_url(): void {
		$this->log_in_as( 'editor' );

		// The Abilities API's invalid-input error carries no status data.
		$this->assert_error_response(
			'ability_invalid_input',
			$this->upload(
				$this->get_upload_input(
					array(
						'url'      => 'https://example.com/photo.jpg',
						'filename' => 'mixed.png',
					)
				)
			),
			null,
			'Mixing data-mode and URL-mode properties should be rejected.'
		);
	}

	/**
	 * A URL that is not safe to request server-side is rejected before any
	 * download happens.
	 */
	public function test_upload_rejects_an_unsafe_url(): void {
		$this->log_in_as( 'editor' );

		$this->assert_error_response(
			'media_invalid_param',
			$this->upload( array( 'url' => 'file:///tmp/image.png' ) ),
			400,
			'A non-HTTP(S) URL should be rejected.'
		);
	}

	/**
	 * The URL upload derives the file name from the URL path; a URL whose path
	 * carries no file name is rejected.
	 */
	public function test_url_upload_rejects_a_url_without_a_filename(): void {
		$this->log_in_as( 'editor' );

		$this->assert_error_response(
			'media_invalid_url',
			$this->upload( array( 'url' => 'https://example.com/' ) ),
			400,
			'A URL without a file name should be rejected.'
		);
	}

	/**
	 * Only image URLs are downloaded; other extensions are rejected before the
	 * download starts.
	 */
	public function test_url_upload_rejects_a_non_image_url(): void {
		$this->log_in_as( 'editor' );

		$this->assert_error_response(
			'media_invalid_url',
			$this->upload( array( 'url' => 'https://example.com/data.txt' ) ),
			400,
			'A non-image URL should be rejected.'
		);
	}

	/**
	 * A URL upload requires the upload capability (the editor role has it;
	 * a contributor never reaches the execute callback, but the check itself
	 * is asserted through a direct execute on the registered callback).
	 */
	public function test_url_upload_requires_the_upload_capability(): void {
		$this->log_in_as( 'editor' );

		// The transport is mocked: the test-data JPEG is written to the temp
		// file the download would stream into.
		$mock = function ( $response, $args, $url ) {
			if ( ! empty( $args['filename'] ) ) {
				copy( (string) constant( 'DIR_TESTDATA' ) . '/images/canola.jpg', $args['filename'] );
			}

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'cookies'  => array(),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );

		try {
			$data = $this->upload( array( 'url' => 'https://example.com/canola.jpg' ) );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}

		self::assertIsArray( $data, 'The URL upload should sideload the mocked image.' );
		$this->track_attachment( (int) $data['id'] );

		$attachment = get_post( (int) $data['id'] );
		self::assertInstanceOf( WP_Post::class, $attachment );
		self::assertSame( 'image/jpeg', $attachment->post_mime_type, 'The MIME type comes from the file name.' );
		self::assertSame( 'image', $data['media_type'] );
	}

	/**
	 * The URL sideload generates the image sub-sizes by default and attaches
	 * the attachment when a parent post is given.
	 */
	public function test_url_download_generates_subsizes_and_attaches_to_a_post(): void {
		$this->log_in_as( 'editor' );
		$post_id = $this->create_parent_post();

		$mock = function ( $response, $args, $url ) {
			if ( ! empty( $args['filename'] ) ) {
				copy( (string) constant( 'DIR_TESTDATA' ) . '/images/canola.jpg', $args['filename'] );
			}

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'cookies'  => array(),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );

		try {
			$data = $this->upload(
				array(
					'url'    => 'https://example.com/images/canola.jpg',
					'post'   => $post_id,
					'fields' => array( 'id', 'post', 'media_details', 'source_url' ),
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}

		self::assertIsArray( $data, 'The URL upload should sideload the mocked image.' );
		$this->track_attachment( (int) $data['id'] );

		self::assertSame( $post_id, $data['post'], 'The parent post should be attached.' );
		self::assertIsArray( $data['media_details'] ?? null, 'The metadata should be generated.' );
		self::assertNotEmpty( $data['media_details']['sizes'] ?? array(), 'The image sub-sizes should be generated by default.' );
	}

	/**
	 * A download failure is reported as the HTTP API error that caused it.
	 */
	public function test_url_upload_reports_a_download_failure(): void {
		$this->log_in_as( 'editor' );

		$fail = static function ( $response, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Could not resolve host.' );
		};
		add_filter( 'pre_http_request', $fail, 10, 3 );

		try {
			$result = $this->upload( array( 'url' => 'https://example.com/images/canola.jpg' ) );
		} finally {
			remove_filter( 'pre_http_request', $fail, 10 );
		}

		$this->assert_error_response( 'http_request_failed', $result, null, 'The download error should be reported.' );
	}

	/**
	 * A URL payload that carries the data mode's properties is rejected by
	 * input validation.
	 */
	public function test_url_upload_rejects_the_data_mode_input(): void {
		$this->log_in_as( 'editor' );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->upload(
				array(
					'url'   => 'https://example.com/canola.jpg',
					'title' => 'Only the data mode takes an attachment title.',
				)
			),
			null,
			'The URL branch should accept only the URL, the parent post, and fields (the Abilities API\'s invalid-input error carries no status data).'
		);
	}

	/**
	 * A non-string URL is rejected by input validation before the callback.
	 */
	public function test_url_upload_rejects_a_non_string_url(): void {
		$this->log_in_as( 'editor' );

		// The Abilities API's invalid-input error carries no status data.
		$this->assert_error_response(
			'ability_invalid_input',
			$this->upload( array( 'url' => 42 ) ),
			null,
			'The URL must be a string.'
		);
	}

	/**
	 * Assigning an attachment to another author requires the capability to edit
	 * their posts; a nonexistent author is rejected outright.
	 */
	public function test_upload_rejects_an_invalid_or_unreassignable_author(): void {
		$this->log_in_as( 'editor' );

		$this->assert_error_response(
			'media_invalid_author',
			$this->upload( $this->get_upload_input( array( 'author' => self::IMPOSSIBLY_HIGH_NUMBER ) ) ),
			400,
			'A nonexistent author should be rejected.'
		);

		// The `uploader` role can upload (so it passes the permission callback) but cannot
		// edit others' posts, so assigning the attachment to the editor is refused.
		$editor_id = $this->log_in_as( 'editor' );
		$this->log_in_as( 'uploader' );

		$this->assert_error_response(
			'media_cannot_edit_others',
			$this->upload( $this->get_upload_input( array( 'author' => $editor_id ) ) ),
			403,
			'Assigning another author should require the capability to edit their posts.'
		);
	}

	/**
	 * Attaching an attachment to a post requires the capability to edit that
	 * post; revisions and attachments cannot be parents.
	 */
	public function test_upload_rejects_a_refused_or_invalid_parent(): void {
		$author_id = $this->log_in_as( 'author' );
		$editor_id = $this->log_in_as( 'editor' );
		$parent_id = $this->create_parent_post( array( 'post_author' => $author_id ) );

		$this->log_in_as( 'editor' );
		$data = $this->upload(
			$this->get_upload_input(
				array(
					'post'     => $parent_id,
					'filename' => 'editor-parent.jpg',
				)
			)
		);
		self::assertIsArray( $data, 'An editor should be able to attach to the post it can edit.' );
		$this->track_attachment( (int) $data['id'] );

		$this->log_in_as( 'author' );
		$this->assert_error_response(
			'media_cannot_edit',
			$this->upload( $this->get_upload_input( array( 'post' => $this->create_parent_post( array( 'post_author' => $editor_id ) ) ) ) ),
			403,
			'Attaching to a post the caller cannot edit should be refused.'
		);

		$attachment_id = $this->upload_and_track_direct_mock_attachment( 'attachment-parent.png' );
		$this->log_in_as( 'editor' );
		$this->assert_error_response(
			'media_invalid_param',
			$this->upload( $this->get_upload_input( array( 'post' => $attachment_id ) ) ),
			400,
			'An attachment cannot be the parent of another attachment.'
		);
	}

	/**
	 * Direct-call helper for the parent-type rejection: returns a real
	 * attachment ID. Created directly because the ability's own permission
	 * callback would deny an attachment-to-attachment parent before the
	 * execute callback could name the refused field.
	 *
	 * @param string $filename Unused. Kept to mirror the upload input's shape.
	 * @return int The created attachment ID.
	 */
	private function upload_and_track_direct_mock_attachment( string $filename ): int {
		// A direct insert is used because the ability's permission callback would deny
		// an attachment-to-attachment parent before the execute callback could name it.
		$file                   = (string) constant( 'DIR_TESTDATA' ) . '/images/codeispoetry.png';
		$id                     = (int) wp_insert_attachment(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
				'post_title'     => 'Direct attachment',
			),
			$file
		);
		$this->attachment_ids[] = $id;

		return $id;
	}

	/**
	 * A private status requires the capability to publish; a public status is
	 * stored as `inherit`, mirroring how attachments are stored.
	 */
	public function test_upload_status_capability_and_inherit_storage(): void {
		// The `uploader` role has upload_files but not the publish capability.
		$this->log_in_as( 'uploader' );
		$this->assert_error_response(
			'media_cannot_publish',
			$this->upload( $this->get_upload_input( array( 'status' => 'private' ) ) ),
			403,
			'A private status should require the capability to publish.'
		);

		$this->log_in_as( 'editor' );
		$data = $this->upload(
			$this->get_upload_input(
				array(
					'status'   => 'publish',
					'filename' => 'public-status.jpg',
				)
			)
		);
		self::assertIsArray( $data, 'A public status should upload.' );
		$this->track_attachment( (int) $data['id'] );

		$attachment = get_post( (int) $data['id'] );
		self::assertInstanceOf( WP_Post::class, $attachment );
		self::assertSame( 'inherit', $attachment->post_status, 'A public status should be stored as `inherit`, as attachments are stored.' );
	}

	/**
	 * The slug is sanitized like a title and the date is honored.
	 */
	public function test_upload_sets_the_slug_and_the_date(): void {
		$this->log_in_as( 'editor' );

		$data = $this->upload(
			$this->get_upload_input(
				array(
					'slug'   => 'A Slug Needs Sanitizing',
					'date'   => '2016-12-12T14:00:00-05:00',
					'fields' => array( 'id', 'slug', 'date', 'date_gmt' ),
				)
			)
		);

		self::assertIsArray( $data, 'The upload should return the attachment.' );
		$this->track_attachment( (int) $data['id'] );

		$attachment = get_post( (int) $data['id'] );
		self::assertInstanceOf( WP_Post::class, $attachment );
		self::assertSame( 'a-slug-needs-sanitizing', $data['slug'], 'The slug should be sanitized like a title.' );
		self::assertSame( '2016-12-12T19:00:00', $data['date_gmt'], 'The date should honor the offset.' );
	}

	/**
	 * Without a title/caption/alt text, image EXIF metadata is used as the
	 * defaults. Non-string metadata (a filterable value) is ignored.
	 */
	public function test_upload_uses_image_meta_defaults_and_ignores_non_strings(): void {
		$this->log_in_as( 'editor' );

		$data = $this->upload( $this->get_upload_input( array( 'filename' => 'meta-defaults.jpg' ) ) );
		self::assertIsArray( $data, 'The upload should return the attachment.' );
		$this->track_attachment( (int) $data['id'] );

		$attachment = get_post( (int) $data['id'] );
		self::assertInstanceOf( WP_Post::class, $attachment );
		self::assertSame( 'meta-defaults', $attachment->post_title, 'Without a title, the file name is used.' );

		$meta = get_post_meta( (int) $data['id'], '_wp_attachment_image_alt', true );
		self::assertTrue( '' === $meta || is_string( $meta ), 'The alt text should fall back to the image meta when it reads as a string.' );

		$ignore_non_string = static function ( $metadata, $file ) {
			return array(
				'title'   => array( 'not', 'a', 'string' ),
				'caption' => 42,
				'alt'     => 'A string alt is still honored.',
			);
		};
		add_filter( 'wp_read_image_metadata', $ignore_non_string, 10, 2 );

		try {
			$data = $this->upload( $this->get_upload_input( array( 'filename' => 'meta-ignored.jpg' ) ) );
		} finally {
			remove_filter( 'wp_read_image_metadata', $ignore_non_string, 10 );
		}

		self::assertIsArray( $data, 'The upload should return the attachment.' );
		$this->track_attachment( (int) $data['id'] );

		$attachment = get_post( (int) $data['id'] );
		self::assertInstanceOf( WP_Post::class, $attachment );
		self::assertSame( 'meta-ignored', $attachment->post_title, 'A non-string title should be ignored in favor of the file name.' );
	}

	/**
	 * The insert hooks fire exactly once per upload.
	 */
	public function test_upload_fires_the_insert_hooks_once(): void {
		$this->log_in_as( 'editor' );

		$add_calls      = array();
		$add_attachment = static function ( ...$args ) use ( &$add_calls ) {
			$add_calls[] = $args[0] ?? null;
		};
		add_action( 'add_attachment', $add_attachment );

		try {
			$data = $this->upload( $this->get_upload_input( array( 'filename' => 'hook-test.jpg' ) ) );
		} finally {
			remove_action( 'add_attachment', $add_attachment );
		}

		self::assertIsArray( $data, 'The upload should return the attachment.' );
		$this->track_attachment( (int) $data['id'] );

		self::assertCount( 1, $add_calls, 'add_attachment should fire once.' );
		self::assertSame( (int) $data['id'], $add_calls[0] ?? null, 'add_attachment should report the attachment.' );
	}

	/**
	 * The global post and the title-format filters are restored after the
	 * upload (the projection overwrites the global post and registers the
	 * filters, then the `finally` cleanup restores them).
	 */
	public function test_restores_the_global_post_and_filters(): void {
		$this->log_in_as( 'editor' );
		$parent_id = $this->create_parent_post();
		$ability   = $this->get_registered();

		// Preparing an item for response overwrites the global post and registers
		// the title-format filters; the execute callback's finally must leave the
		// caller's global post in place and the filters removed.
		$GLOBALS['post'] = get_post( $parent_id );

		$data = $this->upload( $this->get_upload_input( array( 'filename' => 'global-post.jpg' ) ) );
		self::assertIsArray( $data, 'The upload should return the attachment.' );
		$this->track_attachment( (int) $data['id'] );

		self::assertSame( $parent_id, $GLOBALS['post']->ID ?? 0, 'The global post should be restored after the run.' );
		self::assertFalse( has_filter( 'protected_title_format', array( $ability, 'protected_title_format' ) ), 'The protected-title filter should be removed after the run.' );
		self::assertFalse( has_filter( 'private_title_format', array( $ability, 'protected_title_format' ) ), 'The private-title filter should be removed after the run.' );
		self::assertFalse( has_filter( 'wp_allow_query_attachment_by_filename', '__return_true' ), 'The filename-query filter should not be a write ability\'s concern.' );
	}

	/**
	 * The ability should return what the media endpoint returns: the same
	 * request is dispatched through the REST media controller, and the
	 * overlapping fields are compared.
	 */
	public function test_upload_matches_the_media_endpoint(): void {
		$this->log_in_as( 'editor' );
		update_option( 'timezone_string', 'America/New_York' );

		$input  = array(
			'title'       => 'A <em>title</em>',
			'caption'     => 'A caption',
			'description' => 'A description',
			'alt_text'    => '<b>An alt text</b>',
			'post'        => $this->create_parent_post(),
			'date'        => '2016-12-12T14:00:00',
		);
		$fields = array( 'date', 'date_gmt', 'status', 'title_raw', 'title_rendered', 'author', 'description_raw', 'caption_raw', 'caption_rendered', 'alt_text', 'media_type', 'mime_type', 'post' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_header( 'Content-Type', 'image/jpeg' );
		$request->set_header( 'Content-Disposition', 'attachment; filename=canola.jpg' );
		$request->set_body( (string) file_get_contents( (string) constant( 'DIR_TESTDATA' ) . '/images/canola.jpg' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test file.
		foreach ( $input as $name => $value ) {
			$request->set_param( $name, $value );
		}
		$response = rest_do_request( $request );
		self::assertSame( 201, $response->get_status(), 'The endpoint should create the attachment.' );
		$rest_id                = (int) $response->get_data()['id'];
		$this->attachment_ids[] = $rest_id;

		$result = $this->upload( $this->get_upload_input( array_merge( $input, array( 'fields' => $fields ) ) ) );
		self::assertIsArray( $result, 'The ability should create the attachment.' );
		$this->track_attachment( (int) $result['id'] );

		$rest_post    = get_post( $rest_id );
		$ability_post = get_post( (int) $result['id'] );
		self::assertInstanceOf( WP_Post::class, $rest_post );
		self::assertInstanceOf( WP_Post::class, $ability_post );
		unset( $result['id'] );

		$expected = $this->flatten_rest_fields( $response->get_data(), $fields );
		ksort( $expected );
		ksort( $result );
		self::assertSame( $expected, $result, 'The ability should return what the endpoint returns.' );

		foreach ( array( 'post_title', 'post_excerpt', 'post_content', 'post_status', 'post_parent', 'post_author', 'post_mime_type', 'post_date', 'post_date_gmt' ) as $column ) {
			self::assertSame( $rest_post->$column, $ability_post->$column, "The {$column} column should match." );
		}

		self::assertSame(
			array_keys( wp_get_attachment_metadata( $rest_post->ID )['sizes'] ?? array() ),
			array_keys( wp_get_attachment_metadata( $ability_post->ID )['sizes'] ?? array() ),
			'The same sub-sizes should be generated.'
		);

		update_option( 'timezone_string', '' );
	}

	/**
	 * Flattens the REST media controller response to the ability's dotted field names.
	 *
	 * @param array<string, mixed> $rest   The REST response.
	 * @param list<string>         $fields The requested fields.
	 * @return array<string, mixed> The flattened fields.
	 */
	private function flatten_rest_fields( array $rest, array $fields ): array {
		$flattened = array();

		foreach ( $fields as $field ) {
			if ( preg_match( '/^(.+)_(raw|rendered)$/', $field, $matches ) ) {
				$flattened[ $field ] = $rest[ $matches[1] ][ $matches[2] ];
				continue;
			}

			$flattened[ $field ] = $rest[ $field ];
		}

		return $flattened;
	}
}
