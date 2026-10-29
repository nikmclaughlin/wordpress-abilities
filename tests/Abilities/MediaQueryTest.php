<?php
/**
 * Tests: `wp/media-query` (port of WordPress/ai PR #1098's media tests, on this
 * package's per-ability contract shape).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev) and stable
 * (6.9/7.1). The `wp/` name is never core-provided, so the package copy
 * registers and executes identically on both; the suite pins the ported
 * attachment endpoint behaviors — permissions, filters, fields, pagination —
 * and the PR's documented REST deviations.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use stdClass;
use WP_Post;
use WP_Abilities_Registry;
use WP_Ability;
use WP_Error;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Media\Media_Query;
use WordPress\Abilities\Registrar;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `wp/media-query`.
 */
final class MediaQueryTest extends TestCase {

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
	 * Uploaded test files, relative to the uploads base directory, for cleanup.
	 *
	 * @var list<string>
	 */
	private array $attached_files = array();

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
	 * The registered `wp/media-query` instance.
	 */
	private function get_registered(): WP_Ability {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/media-query' );
		self::assertNotNull( $ability, 'The ability should be registered through the Registrar.' );

		return $ability;
	}

	/**
	 * Runs the ability through the Abilities API and returns the result.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function execute( array $input = array() ) {
		return wp_get_ability( 'wp/media-query' )->execute( $input );
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
					'user_login' => 'mq_' . $role,
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
	 * @return string The relative path to store in `_wp_attached_file`.
	 */
	private function upload_test_file( string $name ): string {
		static $counter = 0;
		++$counter;

		$relative = 'mq-test-' . $counter . '-' . $name;

		copy( (string) constant( 'DIR_TESTDATA' ) . '/images/' . $name, wp_upload_dir()['basedir'] . '/' . $relative );
		$this->attached_files[] = $relative;

		return $relative;
	}

	/**
	 * Creates a mocked attachment, tracked for removal.
	 *
	 * @param string               $file      The `_wp_attached_file` value, empty for no file.
	 * @param int                  $parent_id The parent post ID, 0 for unattached.
	 * @param array<string, mixed> $args      Optional. Further post fields, e.g. post_status,
	 *                                        post_author, post_excerpt.
	 * @return int The attachment ID.
	 */
	private function create_attachment( string $file = '', int $parent_id = 0, array $args = array() ): int {
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
		if ( '' !== $file ) {
			update_post_meta( $id, '_wp_attached_file', $file );
		}

		$this->attachment_ids[] = $id;

		return $id;
	}

	/**
	 * Creates a mocked parent post, tracked for removal.
	 *
	 * @param array<string, mixed> $args Optional. Further post fields, e.g. post_status.
	 * @return int The post ID.
	 */
	private function create_parent_post( array $args = array() ): int {
		$id = (int) wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Parent post',
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
	 * The fields readable without edit access, in the schema's output order.
	 *
	 * @return list<string> The field names.
	 */
	private function get_view_fields(): array {
		return array(
			'id',
			'date',
			'date_gmt',
			'modified',
			'modified_gmt',
			'slug',
			'status',
			'link',
			'title_rendered',
			'author',
			'description_rendered',
			'caption_rendered',
			'alt_text',
			'media_type',
			'mime_type',
			'media_details',
			'post',
			'source_url',
			'filename',
			'filesize',
		);
	}

	/**
	 * Asserts the fields of a returned item against the stored attachment.
	 *
	 * @param WP_Post              $attachment The attachment.
	 * @param array<string, mixed> $data       The returned fields.
	 */
	private function check_post_data( WP_Post $attachment, array $data ): void {
		self::assertSame( $attachment->ID, $data['id'] );
		self::assertSame( $attachment->post_name, $data['slug'] );
		self::assertSame( get_permalink( $attachment->ID ), $data['link'] );
		self::assertSame( mysql_to_rfc3339( $attachment->post_date_gmt ), $data['date_gmt'] );
		self::assertSame( mysql_to_rfc3339( $attachment->post_date ), $data['date'] );
		self::assertSame( mysql_to_rfc3339( $attachment->post_modified_gmt ), $data['modified_gmt'] );
		self::assertSame( mysql_to_rfc3339( $attachment->post_modified ), $data['modified'] );
		self::assertSame( (int) $attachment->post_author, $data['author'] );
		self::assertSame( get_the_title( $attachment->ID ), $data['title_rendered'] );
		self::assertSame( $attachment->post_status, $data['status'] );

		self::assertArrayNotHasKey( 'title_raw', $data );
		self::assertArrayNotHasKey( 'caption_raw', $data );
		self::assertArrayNotHasKey( 'description_raw', $data );

		self::assertSame( get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ), $data['alt_text'] );
		self::assertArrayHasKey( 'media_details', $data );

		if ( $attachment->post_parent ) {
			self::assertSame( $attachment->post_parent, $data['post'] );
		} else {
			self::assertNull( $data['post'] );
		}

		self::assertSame( (string) wp_get_attachment_url( $attachment->ID ), $data['source_url'] );
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

		self::assertSame( 'Media Query', $ability->get_label(), 'The ability should use the PR\'s label.' );
		self::assertSame( 'media', $ability->get_category(), 'The ability should use the package-authored media category.' );
		self::assertTrue( (bool) ( $meta['show_in_rest'] ?? false ), 'The ability should be exposed in REST.' );
		self::assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
				'open_world'  => false,
			),
			$meta['annotations'] ?? null
		);
		self::assertSame( 'experimental', $meta['stability'] ?? null );
		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null );
		self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null );

		Registrar::register_all();
		Registrar::register_all();
		self::assertSame( $ability, $this->get_registered(), 'No double registration: the single instance holds.' );
	}

	/**
	 * The input schema models a single-item mode and a collection mode, each rejecting the
	 * other mode's properties, with defaults left to the ability.
	 */
	public function test_input_schema_models_mutually_exclusive_modes(): void {
		$schema = $this->get_registered()->get_input_schema();

		self::assertSame( 'object', $schema['type'], 'The input should be an object.' );
		self::assertEquals( (object) array(), $schema['default'], 'Omitted input should default to an empty query.' );
		self::assertCount( 2, $schema['oneOf'], 'The input should have two modes.' );

		list( $item, $collection ) = $schema['oneOf'];

		self::assertSame( array( 'id' ), $item['required'], 'The single-item mode should require an ID.' );
		self::assertFalse( $item['additionalProperties'], 'The single-item mode should reject other properties.' );
		self::assertArrayHasKey( 'minimum', $item['properties']['id'], 'The ID should be bounded.' );
		self::assertArrayNotHasKey( 'required', $collection, 'The collection mode should not require anything.' );
		self::assertFalse( $collection['additionalProperties'], 'The collection mode should reject other properties.' );

		$keys = array_keys( $collection['properties'] );
		sort( $keys );
		self::assertSame(
			array(
				'author',
				'exclude',
				'fields',
				'include',
				'media_type',
				'mime_type',
				'order',
				'orderby',
				'page',
				'parent',
				'per_page',
				'search',
				'status',
			),
			$keys,
			'The collection mode should carry the PR\'s parameters.'
		);
		foreach ( $collection['properties'] as $name => $property ) {
			self::assertArrayNotHasKey( 'default', $property, "The {$name} parameter should rely on the ability's defaults." );
		}

		self::assertSame( array( 'id', 'fields' ), array_keys( $item['properties'] ), 'The single-item mode should take an ID and fields.' );
	}

	/**
	 * The collection parameters mirror the attachments endpoint's values.
	 */
	public function test_collection_params_mirror_the_endpoint(): void {
		$params = $this->get_registered()->get_input_schema()['oneOf'][1]['properties'];

		self::assertSame( array( 'asc', 'desc' ), $params['order']['enum'] );
		self::assertSame(
			array( 'author', 'date', 'id', 'include', 'modified', 'parent', 'relevance', 'slug', 'title' ),
			$params['orderby']['enum'],
			'The endpoint also orders by include_slugs, which the ability does not support.'
		);
		self::assertSame( 1, $params['per_page']['minimum'] );
		self::assertSame( 100, $params['per_page']['maximum'] );
		self::assertSame( 1, $params['page']['minimum'] );
		self::assertSame( array( 'inherit', 'private', 'trash' ), $params['status']['items']['enum'] );
		self::assertSame( 'integer', $params['author']['items']['type'] );
		self::assertSame( 'integer', $params['include']['items']['type'] );
		self::assertSame( 'integer', $params['exclude']['items']['type'] );
		self::assertSame( 'integer', $params['parent']['items']['type'] );
		self::assertSame( 'string', $params['search']['type'] );
	}

	/**
	 * The media type enum mirrors the site's allowed MIME types.
	 */
	public function test_media_type_enum_mirrors_allowed_mimes(): void {
		$expected = array();
		foreach ( get_allowed_mime_types() as $mime_type ) {
			$parts                 = explode( '/', $mime_type );
			$expected[ $parts[0] ] = true;
		}

		$items = $this->get_registered()->get_input_schema()['oneOf'][1]['properties']['media_type']['items'];

		self::assertSame( array_keys( $expected ), $items['enum'], 'The media type enum should list the MIME type categories.' );
	}

	/**
	 * A site that allows no uploads has no media types to list, so the schema leaves the enum
	 * out rather than publish an empty one.
	 */
	public function test_media_type_has_no_enum_when_no_mime_types_are_allowed(): void {
		add_filter( 'upload_mimes', '__return_empty_array' ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes -- Allows no types at all.

		try {
			$items = ( new Media_Query() )->get_input_schema()['oneOf'][1]['properties']['media_type']['items'];
		} finally {
			remove_filter( 'upload_mimes', '__return_empty_array' );
		}

		self::assertSame( array( 'type' => 'string' ), $items );
	}

	/**
	 * The output schema describes a single item and a collection. Neither accepts unknown
	 * properties, which keeps the two shapes apart.
	 */
	public function test_output_schema_describes_single_item_and_collection_responses(): void {
		$ability = $this->get_registered();
		$schema  = $ability->get_output_schema();

		list( $item, $collection ) = $schema['oneOf'];

		self::assertSame( 'object', $schema['type'], 'The output should be an object.' );
		self::assertCount( 23, $item['properties'], 'The item schema should describe the PR\'s fields.' );
		self::assertArrayNotHasKey( 'required', $item, 'No item field should be required, since the caller picks them.' );
		self::assertFalse( $item['additionalProperties'], 'Items should reject unknown properties.' );
		self::assertSame(
			array(
				'id',
				'date',
				'date_gmt',
				'modified',
				'modified_gmt',
				'slug',
				'status',
				'link',
				'title_raw',
				'title_rendered',
				'author',
				'description_raw',
				'description_rendered',
				'caption_raw',
				'caption_rendered',
				'alt_text',
				'media_type',
				'mime_type',
				'media_details',
				'post',
				'source_url',
				'filename',
				'filesize',
			),
			array_keys( $item['properties'] ),
			'The item fields should be listed in output order.'
		);
		self::assertFalse( $collection['additionalProperties'], 'The collection should reject unknown properties.' );
		self::assertSame( array( 'media', 'total', 'total_pages' ), $collection['required'], 'The collection should require its list and totals.' );
		self::assertSame( $item, $collection['properties']['media']['items'], 'Collection items should use the item schema.' );
		self::assertSame(
			array_keys( $item['properties'] ),
			$ability->get_input_schema()['oneOf'][0]['properties']['fields']['items']['enum'],
			'The fields enum should list the item properties.'
		);
		self::assertSame( array( 'image', 'file' ), $item['properties']['media_type']['enum'] );
		self::assertSame( array( 'string', 'null' ), $item['properties']['date']['type'] );
		self::assertSame( array( 'integer', 'null' ), $item['properties']['post']['type'] );
	}

	/**
	 * Permission gate: a logged-out visitor is denied in both modes.
	 */
	public function test_logged_out_is_denied_in_both_modes(): void {
		$attachment_id = $this->create_attachment();

		wp_set_current_user( 0 );

		self::assertFalse( $this->get_registered()->check_permissions( array() ), 'A collection without a user should be denied.' );
		$this->assert_error_response( 'ability_invalid_permissions', $this->execute( array() ) );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->execute( array( 'id' => $attachment_id ) ),
			null,
			'A single item without a user should be denied.'
		);
	}

	/**
	 * Permission gate: the media read checks mirror the attachments endpoint.
	 *
	 * Which roles can read an item depends on its status and on its parent: an unattached or
	 * published-parent item is readable by any authenticated user; draft, private, and
	 * trashed contexts need edit access.
	 */
	public function test_visibility_by_role(): void {
		$fixtures = array(
			'unattached'     => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
			'published'      => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
			'draft parent'   => array( 'administrator', 'editor' ),
			'private parent' => array( 'administrator', 'editor' ),
			'private item'   => array( 'administrator', 'editor' ),
			'trashed item'   => array( 'administrator', 'editor' ),
		);

		$ids = array(
			'unattached'     => $this->create_attachment(),
			'published'      => $this->create_attachment( '', $this->create_parent_post( array( 'post_status' => 'publish' ) ) ),
			'draft parent'   => $this->create_attachment( '', $this->create_parent_post( array( 'post_status' => 'draft' ) ) ),
			'private parent' => $this->create_attachment( '', $this->create_parent_post( array( 'post_status' => 'private' ) ) ),
			'private item'   => $this->create_attachment( '', 0, array( 'post_status' => 'private' ) ),
			'trashed item'   => $this->create_attachment( '', 0, array( 'post_status' => 'trash' ) ),
		);

		foreach ( $fixtures as $fixture => $readers ) {
			$id = $ids[ $fixture ];

			foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', '' ) as $role ) {
				if ( '' === $role ) {
					wp_set_current_user( 0 );
					$who = 'a logged-out visitor';
				} else {
					wp_set_current_user( $this->log_in_as( $role ) );
					$who = 'a ' . $role;
				}

				$result = $this->execute( array( 'id' => $id ) );

				if ( in_array( $role, $readers, true ) ) {
					self::assertIsArray( $result, $fixture . ' should be readable to ' . $who . '.' );
					self::assertSame( $id, $result['id'], $fixture . ' should return the item to ' . $who . '.' );
				} else {
					self::assert_error_response(
						'ability_invalid_permissions',
						$result,
						null,
						"$fixture should be denied to $who:"
					);
				}
			}
		}

		wp_set_current_user( 0 );
	}

	/**
	 * Raw fields need edit access: an item the user cannot edit is denied in single mode.
	 */
	public function test_raw_fields_of_an_unwritable_item_are_denied(): void {
		$attachment_id = $this->create_attachment();

		foreach ( array( 'subscriber', 'contributor', 'author' ) as $role ) {
			$this->log_in_as( $role );

			$this->assert_error_response(
				'ability_invalid_permissions',
				$this->execute(
					array(
						'id'     => $attachment_id,
						'fields' => array( 'title_raw' ),
					)
				),
				null,
				"Raw fields of an item a {$role} cannot edit should be denied."
			);
		}
	}

	/**
	 * A collection with raw fields needs permission to edit posts.
	 */
	public function test_raw_fields_in_a_collection_need_edit_posts(): void {
		$this->create_attachment();

		$this->log_in_as( 'subscriber' );
		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->execute( array( 'fields' => array( 'caption_raw' ) ) ),
			null,
			'Raw fields in a collection need permission to edit posts.'
		);

		$this->log_in_as( 'contributor' );
		$collection = $this->execute( array( 'fields' => array( 'caption_raw' ) ) );
		self::assertSame( array(), $collection['media'], 'Items the contributor cannot edit should be left out.' );
		self::assertSame( 1, $collection['total'], 'The total should still count them.' );
	}

	/**
	 * Raw fields are returned to a user who can edit the item, in both modes.
	 */
	public function test_raw_fields_are_returned_to_an_editor(): void {
		$attachment_id = $this->create_attachment(
			'',
			0,
			array(
				'post_title'   => 'Raw title',
				'post_excerpt' => 'Raw caption',
				'post_content' => 'Raw description',
			)
		);

		$this->log_in_as( 'editor' );
		$fields = array( 'title_raw', 'description_raw', 'caption_raw' );

		self::assertSame(
			array(
				'id'              => $attachment_id,
				'title_raw'       => 'Raw title',
				'description_raw' => 'Raw description',
				'caption_raw'     => 'Raw caption',
			),
			$this->execute(
				array(
					'id'     => $attachment_id,
					'fields' => $fields,
				)
			),
			'An editor should get the raw fields of a single item.'
		);
		self::assertSame( 'Raw title', $this->execute( array( 'fields' => $fields ) )['media'][0]['title_raw'], 'An editor should get the raw fields in a collection.' );
	}

	/**
	 * With raw fields, a collection lists only the items the user can edit, while the totals
	 * still count the ones withheld.
	 */
	public function test_raw_fields_limit_a_collection_to_editable_items(): void {
		$others = $this->create_attachment();
		$own    = $this->create_attachment(
			'',
			0,
			array(
				'post_author' => $this->log_in_as( 'author' ),
				'post_title'  => 'Own item',
			)
		);

		wp_set_current_user( $this->user_ids['author'] );
		$view = $this->execute( array( 'fields' => array( 'id' ) ) );
		$edit = $this->execute( array( 'fields' => array( 'title_raw' ) ) );

		self::assertSame( 2, $view['total'], 'Without raw fields, the author should read both items.' );
		self::assertSame(
			array(
				array(
					'id'        => $own,
					'title_raw' => 'Own item',
				),
			),
			$edit['media'],
			'With raw fields, the author should only get their own item.'
		);
		self::assertNotContains( $others, wp_list_pluck( $edit['media'], 'id' ), 'Another user\'s item should be left out.' );
		self::assertSame( 2, $edit['total'], 'The total should count both items.' );
	}

	/**
	 * Without REST exposure of the attachment type, nothing is readable, not even the totals
	 * of a collection (a kept REST-availability quirk of the source PR).
	 */
	public function test_items_are_not_readable_when_attachments_are_hidden_from_rest(): void {
		$attachment_id = $this->create_attachment();

		$this->log_in_as( 'administrator' );
		$post_type               = get_post_type_object( 'attachment' );
		$post_type->show_in_rest = false;

		$single     = $this->execute( array( 'id' => $attachment_id ) );
		$collection = $this->execute( array() );

		$post_type->show_in_rest = true;

		$this->assert_error_response( 'ability_invalid_permissions', $single, null, 'The item should not be readable.' );
		$this->assert_error_response( 'ability_invalid_permissions', $collection, null, 'The collection should be denied.' );
	}

	/**
	 * A missing item is denied like an unreadable one, so IDs cannot be probed.
	 */
	public function test_missing_item_denied_like_an_unreadable_item(): void {
		$unreadable = $this->create_attachment( '', $this->create_parent_post( array( 'post_status' => 'draft' ) ) );
		$post_id    = $this->create_parent_post();

		$this->log_in_as( 'subscriber' );
		$missing     = $this->execute( array( 'id' => self::IMPOSSIBLY_HIGH_NUMBER ) );
		$forbidden   = $this->execute( array( 'id' => $unreadable ) );
		$not_a_media = $this->execute( array( 'id' => $post_id ) );

		$this->assert_error_response( 'ability_invalid_permissions', $missing, null, 'A missing item should be denied.' );
		$this->assert_error_response( 'ability_invalid_permissions', $forbidden, null, 'An unreadable item should be denied.' );
		$this->assert_error_response( 'ability_invalid_permissions', $not_a_media, null, 'A post that is not an attachment should be denied.' );
		self::assertSame( $missing->get_error_message(), $forbidden->get_error_message(), 'Both denials should read the same.' );
	}

	/**
	 * An attachment whose parent no longer exists is readable, like an unattached one; an
	 * auto-draft one is not.
	 */
	public function test_inherit_status_with_missing_parent(): void {
		$inherit    = $this->create_attachment( '', self::IMPOSSIBLY_HIGH_NUMBER );
		$auto_draft = $this->create_attachment(
			'',
			self::IMPOSSIBLY_HIGH_NUMBER,
			array( 'post_status' => 'auto-draft' )
		);

		$this->log_in_as( 'subscriber' );

		$readable = $this->execute( array( 'id' => $inherit ) );
		self::assertIsArray( $readable, 'A missing parent should not deny an inheriting item.' );
		self::assertSame( $inherit, $readable['id'] );

		$this->assert_error_response(
			'ability_invalid_permissions',
			$this->execute( array( 'id' => $auto_draft ) ),
			null,
			'An auto-draft item with a missing parent should be denied.'
		);
	}

	/**
	 * The malformed inputs the oneOf schema rejects, in both modes.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function malformed_inputs(): array {
		return array(
			array(
				'id'       => 1,
				'per_page' => 1,
			),
			array( 'id' => 0 ),
			array( 'id' => -1 ),
			array( 'fields' => array( 'guid' ) ),
			array( 'context' => 'edit' ),
			array( '_fields' => 'id' ),
			array( 'after' => '2020-01-01T00:00:00' ),
			array( 'slug' => 'canola' ),
			array( 'orderby' => 'include_slugs' ),
			array( 'orderby' => 'random' ),
			array( 'order' => 'first' ),
			array( 'status' => 'publish' ),
			array( 'media_type' => 'image,invalid,video' ),
			array( 'per_page' => 0 ),
			array( 'per_page' => 101 ),
			array( 'page' => 0 ),
			array( 'page' => -2 ),
			array( 'unknown' => true ),
		);
	}

	/**
	 * Malformed or unknown input fails closed at schema validation, never widened.
	 */
	public function test_malformed_input_fails_closed(): void {
		$this->log_in_as( 'administrator' );

		foreach ( self::malformed_inputs() as $index => $input ) {
			$this->assert_error_response(
				'ability_invalid_input',
				$this->execute( $input ),
				null,
				'Malformed input #' . $index . ' should fail schema validation:'
			);
		}
	}

	/**
	 * Non-object input fails closed, too.
	 */
	public function test_non_object_input_fails_closed(): void {
		$this->log_in_as( 'administrator' );

		$this->assert_error_response( 'ability_invalid_input', $this->get_registered()->execute( 'title' ), null, 'Non-object input should fail schema validation.' );
	}

	/**
	 * Duplicate fields fail the fields list's uniqueness.
	 */
	public function test_duplicate_fields_fail_validation(): void {
		$this->log_in_as( 'administrator' );

		$this->assert_error_response( 'ability_invalid_input', $this->execute( array( 'fields' => array( 'id', 'id' ) ) ), null, 'Duplicate fields should fail validation.' );
	}

	/**
	 * Called through the API, a single item is returned with the default fields, in order.
	 */
	public function test_execute_single_item_returns_default_fields(): void {
		$attachment_id = $this->create_attachment( '', 0, array( 'post_excerpt' => 'A sample caption' ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Sample alt text' );

		$this->log_in_as( 'subscriber' );
		$data = $this->execute( array( 'id' => $attachment_id ) );

		self::assertIsArray( $data );
		self::assertSame(
			array( 'id', 'date', 'slug', 'title_rendered', 'alt_text', 'media_type', 'mime_type', 'source_url' ),
			array_keys( $data ),
			'The item should have the default fields, in output order.'
		);
		self::assertSame( $attachment_id, $data['id'] );
		self::assertSame( 'image/jpeg', $data['mime_type'] );
		self::assertSame( (string) wp_get_attachment_url( $attachment_id ), $data['source_url'] );
	}

	/**
	 * Without input, the ability lists the readable items with their totals.
	 */
	public function test_execute_omitted_input_lists_collection(): void {
		$attachment_id = $this->create_attachment( '', 0, array( 'post_excerpt' => 'A sample caption' ) );

		$this->log_in_as( 'subscriber' );
		$result = $this->execute( array() );

		self::assertIsArray( $result, 'Omitted input should list the collection.' );
		self::assertSame( array( 'media', 'total', 'total_pages' ), array_keys( $result ), 'The collection should return the list and its totals.' );
		self::assertSame( array( $attachment_id ), wp_list_pluck( $result['media'], 'id' ), 'The collection should list the readable item.' );
		self::assertSame( 1, $result['total'], 'The total should count the item.' );
		self::assertSame( 1, $result['total_pages'], 'One item fits on one page.' );
	}

	/**
	 * Only the requested fields are returned, with the ID always included, in output order.
	 */
	public function test_fields_limit_the_returned_keys_and_always_include_the_id(): void {
		$attachment_id = $this->create_attachment();

		$this->log_in_as( 'subscriber' );

		$single = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'slug' ),
			)
		);
		self::assertSame( array( 'id', 'slug' ), array_keys( $single ), 'A single item should always carry the ID.' );

		$result = $this->execute( array( 'fields' => array( 'post', 'filename' ) ) );
		self::assertSame( array( 'id', 'post', 'filename' ), array_keys( $result['media'][0] ), 'The item should have the ID and the requested fields, in output order.' );
		self::assertNull( $result['media'][0]['post'], 'An unattached item has no post.' );
	}

	/**
	 * The readable fields hold the attachment's values in a collection, too.
	 */
	public function test_collection_items_carry_the_view_fields(): void {
		$attachment_id = $this->create_attachment( '', 0, array( 'post_excerpt' => 'A sample caption' ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Sample alt text' );

		$this->log_in_as( 'subscriber' );
		$attachments = $this->execute( array( 'fields' => $this->get_view_fields() ) )['media'];

		self::assertCount( 1, $attachments );
		$this->check_post_data( get_post( $attachment_id ), $attachments[0] );
	}

	/**
	 * GET requests deliver scalars as strings and lists as CSV strings; both forms work.
	 */
	public function test_string_inputs(): void {
		$this->log_in_as( 'editor' );
		$attached = $this->create_attachment( '', $this->create_parent_post() );
		$video    = $this->create_attachment(
			'',
			0,
			array(
				'post_mime_type' => 'video/mp4',
				'post_author'    => $this->user_ids['editor'],
			)
		);

		wp_set_current_user( $this->user_ids['editor'] );

		self::assertSame( $attached, $this->execute( array( 'id' => (string) $attached ) )['id'], 'A string ID should be read.' );
		self::assertSame( array( 'id', 'post' ), array_keys( $this->execute( array( 'fields' => 'post,id' ) )['media'][0] ), 'A CSV field list should be read.' );

		$page = $this->execute(
			array(
				'per_page' => '1',
				'page'     => '2',
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		self::assertSame( array( $video ), wp_list_pluck( $page['media'], 'id' ), 'String page numbers should be read.' );
		self::assertSame( 2, $page['total_pages'], 'A string page size should be read.' );

		self::assertSame( array( $video ), wp_list_pluck( $this->execute( array( 'parent' => '0' ) )['media'], 'id' ), 'A string parent should be read.' );
		self::assertSame( array( $video ), wp_list_pluck( $this->execute( array( 'author' => (string) $this->user_ids['editor'] ) )['media'], 'id' ), 'A string author should be read.' );
		self::assertEqualsCanonicalizing( array( $attached, $video ), wp_list_pluck( $this->execute( array( 'include' => "{$attached},{$video}" ) )['media'], 'id' ), 'A CSV include list should be read.' );
		self::assertSame( array( $attached ), wp_list_pluck( $this->execute( array( 'exclude' => (string) $video ) )['media'], 'id' ), 'A string exclude list should be read.' ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- The ability's exclude parameter.
	}

	/**
	 * Filters by media type, given as a string or a CSV string or an array.
	 */
	public function test_media_type_filter(): void {
		$image_id = $this->create_attachment();
		$video_id = $this->create_attachment( '', 0, array( 'post_mime_type' => 'video/mp4' ) );
		$audio_id = $this->create_attachment( '', 0, array( 'post_mime_type' => 'audio/mpeg' ) );

		$this->log_in_as( 'subscriber' );

		self::assertSame( 3, $this->execute( array() )['total'], 'All three fixtures should match with no filter.' );

		$data = $this->execute( array( 'media_type' => 'image' ) )['media'];
		self::assertSame( array( $image_id ), wp_list_pluck( $data, 'id' ), 'Images only.' );

		$data = $this->execute( array( 'media_type' => 'video' ) )['media'];
		self::assertSame( array( $video_id ), wp_list_pluck( $data, 'id' ), 'Videos only.' );

		$data = $this->execute( array( 'media_type' => 'image,video' ) )['media'];
		self::assertEqualsCanonicalizing( array( $image_id, $video_id ), wp_list_pluck( $data, 'id' ), 'Images and videos, as a CSV string.' );

		$data = $this->execute( array( 'media_type' => array( 'image', 'video', 'audio' ) ) )['media'];
		self::assertEqualsCanonicalizing( array( $image_id, $video_id, $audio_id ), wp_list_pluck( $data, 'id' ), 'All three, as an array.' );
	}

	/**
	 * Filters by MIME type, given as a string or a CSV string or an array, and a MIME type
	 * the site does not allow is ignored (a kept REST quirk).
	 */
	public function test_mime_type_filter(): void {
		$jpeg_id = $this->create_attachment();
		$png_id  = $this->create_attachment( '', 0, array( 'post_mime_type' => 'image/png' ) );
		$mp4_id  = $this->create_attachment( '', 0, array( 'post_mime_type' => 'video/mp4' ) );

		$this->log_in_as( 'subscriber' );

		$data = $this->execute( array( 'mime_type' => 'image/jpeg' ) )['media'];
		self::assertSame( array( $jpeg_id ), wp_list_pluck( $data, 'id' ), 'JPEGs only.' );

		$data = $this->execute( array( 'mime_type' => 'image/png' ) )['media'];
		self::assertSame( array( $png_id ), wp_list_pluck( $data, 'id' ), 'PNGs only.' );

		$data = $this->execute( array( 'mime_type' => 'image/jpeg,image/png' ) )['media'];
		self::assertEqualsCanonicalizing( array( $jpeg_id, $png_id ), wp_list_pluck( $data, 'id' ), 'JPEGs and PNGs, as a CSV string.' );

		$data = $this->execute( array( 'mime_type' => array( 'image/jpeg', 'video/mp4' ) ) )['media'];
		self::assertEqualsCanonicalizing( array( $jpeg_id, $mp4_id ), wp_list_pluck( $data, 'id' ), 'JPEGs and MP4s, as an array.' );

		$data = $this->execute( array( 'mime_type' => 'application/x-unknown' ) )['media'];
		self::assertCount( 3, $data, 'An unknown MIME type should not narrow the list.' );

		$data = $this->execute(
			array(
				'media_type' => 'audio',
				'mime_type'  => array( 'image/jpeg', 'video/mp4' ),
			)
		)['media'];
		self::assertEqualsCanonicalizing( array( $jpeg_id, $mp4_id ), wp_list_pluck( $data, 'id' ), 'Media type and MIME type filters should add up.' );
	}

	/**
	 * Filters by parent, where 0 selects the unattached items.
	 */
	public function test_parent_filter(): void {
		$post_id       = $this->create_parent_post();
		$attached_id   = $this->create_attachment( '', $post_id );
		$unattached_id = $this->create_attachment();

		$this->log_in_as( 'subscriber' );

		self::assertSame( array( $unattached_id ), wp_list_pluck( $this->execute( array( 'parent' => 0 ) )['media'], 'id' ), 'Parent 0 should select the unattached item.' );
		self::assertSame( array( $attached_id ), wp_list_pluck( $this->execute( array( 'parent' => $post_id ) )['media'], 'id' ), 'The parent filter should select the attached item.' );
		self::assertSame( array(), $this->execute( array( 'parent' => self::IMPOSSIBLY_HIGH_NUMBER ) )['media'], 'An invalid parent should match nothing.' );
	}

	/**
	 * The search matches file names, titles, captions, and descriptions.
	 */
	public function test_search_filters(): void {
		$canola = $this->upload_test_file( 'canola.jpg' );
		$code   = $this->upload_test_file( 'codeispoetry.png' );
		$id1    = $this->create_attachment(
			$canola,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Canola title',
			)
		);
		$id2    = $this->create_attachment(
			$code,
			0,
			array(
				'post_mime_type' => 'image/png',
				'post_excerpt'   => 'Searchable caption',
			)
		);

		$this->log_in_as( 'subscriber' );

		self::assertSame( array( $id2 ), wp_list_pluck( $this->execute( array( 'search' => wp_basename( $code ) ) )['media'], 'id' ), 'The search should match file names.' );
		self::assertSame( array( $id1 ), wp_list_pluck( $this->execute( array( 'search' => 'Canola title' ) )['media'], 'id' ), 'The search should match titles.' );
		self::assertSame( array( $id2 ), wp_list_pluck( $this->execute( array( 'search' => 'Searchable caption' ) )['media'], 'id' ), 'The search should match captions.' );
		self::assertSame( 0, $this->execute( array( 'search' => 'Nowhere to be found' ) )['total'], 'No matches should return zero totals.' );
	}

	/**
	 * The private status needs permission to read private posts or to edit posts; the trashed
	 * status needs permission to edit posts. A status the caller may not query is
	 * `media_invalid_param` (a PR quirk: the execute callback's code, not the ability
	 * framework's permission code).
	 */
	public function test_status_filters(): void {
		$private_id = $this->create_attachment( '', 0, array( 'post_status' => 'private' ) );
		$trash_id   = $this->create_attachment( '', 0, array( 'post_status' => 'trash' ) );

		$this->log_in_as( 'subscriber' );
		$this->assert_error_response( 'media_invalid_param', $this->execute( array( 'status' => 'private' ) ), 400, 'A subscriber should not query the private status.' );
		$this->assert_error_response(
			'media_invalid_param',
			$this->execute(
				array(
					'status' => array( 'private', 'trash' ),
				)
			),
			400,
			'A subscriber should not query several gated statuses.'
		);

		$this->log_in_as( 'editor' );

		self::assertEqualsCanonicalizing( array( $private_id, $trash_id ), wp_list_pluck( $this->execute( array( 'status' => array( 'private', 'trash' ) ) )['media'], 'id' ), 'Editors should query both statuses.' );
		self::assertSame( array( $trash_id ), wp_list_pluck( $this->execute( array( 'status' => 'trash' ) )['media'], 'id' ), 'Editors should query the trash status.' );
	}

	/**
	 * Items are ordered by date, newest first, unless asked otherwise.
	 */
	public function test_order_and_orderby(): void {
		$older = $this->create_attachment(
			'',
			0,
			array(
				'post_date'  => '2020-01-01 00:00:00',
				'post_title' => 'B older',
			)
		);
		$newer = $this->create_attachment(
			'',
			0,
			array(
				'post_date'  => '2021-01-01 00:00:00',
				'post_title' => 'A newer',
			)
		);

		$this->log_in_as( 'subscriber' );

		self::assertSame( array( $newer, $older ), wp_list_pluck( $this->execute( array() )['media'], 'id' ), 'Newest first by default.' );
		self::assertSame( array( $older, $newer ), wp_list_pluck( $this->execute( array( 'order' => 'asc' ) )['media'], 'id' ), 'Oldest first in ascending order.' );
		self::assertSame(
			array( $newer, $older ),
			wp_list_pluck(
				$this->execute(
					array(
						'orderby' => 'title',
						'order'   => 'asc',
					)
				)['media'],
				'id'
			),
			'By title in ascending order.'
		);
		self::assertSame(
			array( $older, $newer ),
			wp_list_pluck(
				$this->execute(
					array(
						'orderby' => 'id',
						'order'   => 'asc',
					)
				)['media'],
				'id'
			),
			'By ID in ascending order.'
		);
	}

	/**
	 * Ordering by relevance needs a search term; ordering by include needs included IDs and
	 * then keeps their order.
	 */
	public function test_orderby_relevance_and_include(): void {
		$first  = $this->create_attachment();
		$second = $this->create_attachment();

		$this->log_in_as( 'subscriber' );

		$this->assert_error_response( 'media_no_search_term_defined', $this->execute( array( 'orderby' => 'relevance' ) ), 400, 'Ordering by relevance needs a search term.' );
		$this->assert_error_response( 'media_orderby_include_missing_include', $this->execute( array( 'orderby' => 'include' ) ), 400, 'Ordering by include needs included IDs.' );

		self::assertSame(
			array( $first, $second ),
			wp_list_pluck(
				$this->execute(
					array(
						'include' => array( $first, $second ),
						'orderby' => 'include',
					)
				)['media'],
				'id'
			),
			'The items should follow the include order.'
		);
	}

	/**
	 * Pagination: a later page carries the next items, and a page past the last one is an
	 * error. A later page of an empty collection reports zero totals rather than an error.
	 */
	public function test_pagination_and_page_bounds(): void {
		$this->create_attachment( '', 0, array( 'post_date' => '2021-01-01 00:00:00' ) );
		$second = $this->create_attachment( '', 0, array( 'post_date' => '2022-01-01 00:00:00' ) );

		$this->log_in_as( 'subscriber' );

		$page = $this->execute(
			array(
				'per_page' => 1,
				'page'     => 2,
				'orderby'  => 'date',
				'order'    => 'asc',
			)
		);
		self::assertSame( array( $second ), wp_list_pluck( $page['media'], 'id' ), 'Page 2 of one item per page should list the second fixture.' );
		self::assertSame( 2, $page['total'] );
		self::assertSame( 2, $page['total_pages'] );

		$this->assert_error_response(
			'media_post_invalid_page_number',
			$this->execute(
				array(
					'per_page' => 1,
					'page'     => 3,
					'orderby'  => 'date',
					'order'    => 'asc',
				)
			),
			400,
			'A page past the last one should be an error.'
		);

		self::assertSame(
			array(
				'media'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			),
			$this->execute(
				array(
					'page'       => 3,
					'media_type' => 'video',
				)
			),
			'An empty collection should report zero totals on any page.'
		);
	}

	/**
	 * A collection leaves out the items the user cannot read, but its totals count them
	 * (a kept REST quirk).
	 */
	public function test_totals_count_items_withheld_from_the_user(): void {
		$readable = $this->create_attachment();
		$this->create_attachment( '', $this->create_parent_post( array( 'post_status' => 'draft' ) ) );
		$this->create_attachment( '', $this->create_parent_post( array( 'post_status' => 'private' ) ) );

		$this->log_in_as( 'subscriber' );
		$result = $this->execute(
			array(
				'per_page' => 2,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);

		self::assertSame( array( $readable ), wp_list_pluck( $result['media'], 'id' ), 'Only the readable item should be listed.' );
		self::assertSame( 3, $result['total'], 'The total should count every item of the query.' );
		self::assertSame( 2, $result['total_pages'], 'The pages should be counted from the total.' );
	}

	/**
	 * The author, include, and exclude filters narrow the collection, and `exclude` is
	 * ignored when `include` is given (a kept REST quirk).
	 */
	public function test_author_include_and_exclude_filters(): void {
		$admin_id = $this->log_in_as( 'administrator' );
		$admins   = $this->create_attachment();
		$editor   = $this->create_attachment( '', 0, array( 'post_author' => $this->log_in_as( 'editor' ) ) );

		$this->log_in_as( 'subscriber' );

		self::assertSame( array( $editor ), wp_list_pluck( $this->execute( array( 'author' => $this->user_ids['editor'] ) )['media'], 'id' ), 'The author filter should select the editor\'s item.' );
		self::assertSame( array( $admins ), wp_list_pluck( $this->execute( array( 'author' => array( $admin_id ) ) )['media'], 'id' ), 'The author filter should accept a list.' );
		self::assertSame( array( $admins ), wp_list_pluck( $this->execute( array( 'include' => array( $admins ) ) )['media'], 'id' ), 'The include filter should select the item.' );
		self::assertSame( array( $editor ), wp_list_pluck( $this->execute( array( 'exclude' => array( $admins ) ) )['media'], 'id' ), 'The exclude filter should drop the item.' ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- The ability's exclude parameter.
		self::assertSame(
			array( $admins ),
			wp_list_pluck(
				$this->execute(
					array(
						'include' => array( $admins ),
						'exclude' => array( $admins ),
					)
				)['media'],
				'id'
			),
			'The exclude filter should be ignored when include is given.'
		); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- The ability's exclude parameter.
	}

	/**
	 * Images and other files are told apart.
	 */
	public function test_media_type_tells_images_from_other_files(): void {
		$image = $this->create_attachment( $this->upload_test_file( 'canola.jpg' ) );
		$file  = $this->create_attachment( '', 0, array( 'post_mime_type' => 'application/rtf' ) );

		$this->log_in_as( 'subscriber' );
		$types = wp_list_pluck( $this->execute( array( 'fields' => array( 'media_type', 'mime_type' ) ) )['media'], 'media_type', 'id' );

		self::assertSame( 'image', $types[ $image ], 'A JPEG should be an image.' );
		self::assertSame( 'file', $types[ $file ], 'An RTF document should be a file.' );
	}

	/**
	 * The rendered title, caption, and description go through their display filters.
	 */
	public function test_rendered_fields_apply_the_display_filters(): void {
		$attachment_id = $this->create_attachment(
			'',
			0,
			array(
				'post_status'  => 'private',
				'post_title'   => 'Private item',
				'post_excerpt' => 'A sample caption',
				'post_content' => 'A sample description',
			)
		);

		$this->log_in_as( 'editor' );
		$data = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'title_rendered', 'caption_rendered', 'description_rendered' ),
			)
		);

		self::assertSame( 'Private item', $data['title_rendered'], 'The private title prefix should be left out.' );
		self::assertSame( "<p>A sample caption</p>\n", $data['caption_rendered'], 'The caption should go through the excerpt filters.' );
		self::assertStringContainsString( '<p class="attachment">', $data['description_rendered'], 'The description should start with the attachment link core adds to the content.' );
		self::assertStringContainsString( '<p>A sample description</p>', $data['description_rendered'], 'The description should go through the content filters.' );
	}

	/**
	 * A kept REST quirk: for a password-protected item, the generated caption stays gated
	 * even for editors, while the rendered description is not.
	 */
	public function test_password_gate_on_generated_captions(): void {
		$attachment_id = $this->create_attachment(
			'',
			0,
			array(
				'post_content'  => 'Secret description',
				'post_password' => 'secret',
			)
		);

		$this->log_in_as( 'editor' );
		$fields     = array( 'caption_raw', 'caption_rendered', 'description_rendered' );
		$single     = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => $fields,
			)
		);
		$collection = $this->execute( array( 'fields' => $fields ) );

		self::assertStringContainsString( 'password-protected', $single['caption_rendered'], 'The single item caption should stay gated.' );
		self::assertStringNotContainsString( 'Secret description', $single['caption_rendered'], 'The single item caption should not reveal the description.' );
		self::assertStringContainsString( 'password-protected', $collection['media'][0]['caption_rendered'], 'The collection item caption should stay gated.' );
		self::assertStringNotContainsString( 'Secret description', $collection['media'][0]['caption_rendered'], 'The collection item caption should not reveal the description.' );

		self::assertStringContainsString( 'Secret description', $single['description_rendered'], 'The rendered description should not be gated, as the PR documents.' );
	}

	/**
	 * A stored `filesize` metadata value that is not a positive number falls back to the
	 * actual file size; a castable one is normalized to an integer.
	 */
	public function test_filesize_normalization(): void {
		$file = $this->upload_test_file( 'canola.jpg' );
		$id   = $this->create_attachment( $file );

		$this->log_in_as( 'subscriber' );

		$attached_file = get_attached_file( $id );
		self::assertIsString( $attached_file );
		$real_size = wp_filesize( $attached_file );

		$castable = array(
			array( '123456', 123456 ),
			array( '123.4', 123 ),
			array( '1e3', 1000 ),
			array( 123.0, 123 ),
		);

		foreach ( $castable as $pair ) {
			list( $stored, $expected ) = $pair;
			$meta                      = wp_get_attachment_metadata( $id );
			$meta                      = is_array( $meta ) ? $meta : array();
			$meta['filesize']          = $stored;
			wp_update_attachment_metadata( $id, $meta );

			$data = $this->execute(
				array(
					'id'     => $id,
					'fields' => array( 'filesize' ),
				)
			);
			self::assertSame( $expected, $data['filesize'], var_export( $stored, true ) . ' should normalize to ' . var_export( $expected, true ) . '.' );
		}

		foreach ( array( 'corrupt', true, 0, '0', -5, '-5' ) as $stored ) {
			$meta             = wp_get_attachment_metadata( $id );
			$meta             = is_array( $meta ) ? $meta : array();
			$meta['filesize'] = $stored;
			wp_update_attachment_metadata( $id, $meta );

			$data = $this->execute(
				array(
					'id'     => $id,
					'fields' => array( 'filesize' ),
				)
			);
			self::assertSame( $real_size, $data['filesize'], 'A stored filesize of ' . var_export( $stored, true ) . ' should fall back to the real file size.' );
		}
	}

	/**
	 * The media details list each image size with its URL and mapped MIME type, plus the full
	 * size; a file without metadata gets an empty object.
	 */
	public function test_media_details_sizes(): void {
		if ( ! function_exists( 'imagejpeg' ) ) {
			self::markTestSkipped( 'GD with JPEG support is not available.' );
		}

		$file = $this->upload_test_file( 'canola.jpg' );
		$id   = $this->create_attachment( $file );
		$noid = $this->create_attachment( '', 0, array( 'post_mime_type' => 'video/mp4' ) );

		$attached_file = get_attached_file( $id );
		self::assertIsString( $attached_file );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $attached_file ) );

		$this->log_in_as( 'subscriber' );

		$details = $this->execute(
			array(
				'id'     => $id,
				'fields' => array( 'media_details' ),
			)
		)['media_details'];

		self::assertIsArray( $details['sizes'], 'Could not retrieve the sizes data.' );
		self::assertArrayHasKey( 'mime_type', $details['sizes']['thumbnail'], 'The size MIME type should be mapped from `mime-type`.' );
		self::assertArrayNotHasKey( 'mime-type', $details['sizes']['thumbnail'], 'The `mime-type` key should be gone.' );
		self::assertSame( 'image/jpeg', $details['sizes']['full']['mime_type'] );
		self::assertSame( wp_get_attachment_image_src( $id, 'full' )[0], $details['sizes']['full']['source_url'] );

		$bare = $this->execute(
			array(
				'id'     => $noid,
				'fields' => array( 'media_details' ),
			)
		)['media_details'];
		self::assertEquals( new stdClass(), $bare, 'A file without metadata should get an empty object.' );
	}

	/**
	 * Collection items are validated against the item schema, like a single item.
	 */
	public function test_output_validation_covers_collection_items(): void {
		$attachment_id = $this->create_attachment( '', 0, array( 'post_content' => 'A sample description' ) );
		add_filter( 'the_content', '__return_null', 99 );

		$this->log_in_as( 'subscriber' );
		try {
			$this->assert_error_response(
				'ability_invalid_output',
				$this->execute(
					array(
						'id'     => $attachment_id,
						'fields' => array( 'description_rendered' ),
					)
				),
				null,
				'An invalid single item should fail validation.'
			);
			$this->assert_error_response( 'ability_invalid_output', $this->execute( array( 'fields' => array( 'description_rendered' ) ) ), null, 'An invalid collection item should fail validation.' );
		} finally {
			remove_filter( 'the_content', '__return_null', 99 );
		}
	}

	/**
	 * The global post, which each prepared item replaces, is restored afterwards.
	 */
	public function test_restores_the_global_post(): void {
		$this->create_attachment( '', 0, array( 'post_content' => 'A sample description' ) );
		$previous = $this->create_parent_post();

		$this->log_in_as( 'subscriber' );
		$GLOBALS['post'] = get_post( $previous ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test sets up a global post.

		$this->execute( array( 'fields' => array( 'description_rendered' ) ) );

		$previous_post = get_post( $previous );
		self::assertNotNull( $previous_post );
		self::assertEquals( $previous_post, $GLOBALS['post'], 'The previous global post should be restored.' );

		unset( $GLOBALS['post'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test clears the global post.
		$this->execute( array( 'fields' => array( 'description_rendered' ) ) );

		self::assertArrayNotHasKey( 'post', $GLOBALS, 'No global post should be left behind.' );
	}

	/**
	 * A title filter that throws does not leave the title formats changed for the rest of the
	 * request.
	 */
	public function test_restores_the_title_formats_when_a_title_filter_throws(): void {
		$attachment_id = $this->create_attachment( '', 0, array( 'post_title' => 'Throwing title' ) );
		$throwing      = static function ( $title ): string {
			if ( '' === (string) $title ) {
				return '';
			}
			throw new \RuntimeException( 'Broken title filter.' );
		};
		add_filter( 'the_title', $throwing );

		$this->log_in_as( 'subscriber' );

		try {
			$result = $this->execute( array( 'id' => $attachment_id ) );
			$this->assert_error_response( 'ability_callback_exception', $result, null, 'A throwing title filter should surface as a callback exception.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Broken title filter.', $e->getMessage(), 'The host API does not wrap callback exceptions; the filter unwind should still happen.' );
		} finally {
			self::assertFalse( has_filter( 'protected_title_format' ), 'The protected title format should be restored.' );
			self::assertFalse( has_filter( 'private_title_format' ), 'The private title format should be restored.' );
			remove_filter( 'the_title', $throwing );
		}
	}
}
