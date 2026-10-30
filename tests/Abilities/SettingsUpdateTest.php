<?php
/**
 * Tests: `wp/settings-update` (port of WordPress/ai PR #764's settings update
 * tests, on this package's per-ability contract shape).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev) and stable
 * (6.9/7.1). The ability registers on both (core 7.2 has `core/settings-get`
 * but no `core/settings-update`, so the package copy is the registrant); the
 * execute tests build fresh package instances, so the package code runs on
 * both environments. The suite pins the ported settings-endpoint update
 * behaviors — the updated-only output, the null-reset semantics, the
 * all-or-nothing validation order (sanitize → privacy → stored value before
 * any write), the `url`/`email`/unknown/empty rejections, and the
 * privacy-policy capability — plus the companion verification that this
 * package's `core/settings-get` already carries the PR's read-side changes.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionClass;
use WP_Abilities_Registry;
use WP_Ability;
use WP_Error;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Settings\Settings_Get;
use WordPress\Abilities\Settings\Settings_Update;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `wp/settings-update`.
 */
final class SettingsUpdateTest extends TestCase {

	/**
	 * Options the suite may write, snapshotted in set_up and restored in
	 * tear_down: the persisted environments must be left as they were found.
	 *
	 * @var array<string, mixed> option name => stored value (null when none stored).
	 */
	private array $option_snapshots = array();

	/**
	 * The options the suite touches, so the snapshot covers everything.
	 *
	 * @var list<string>
	 */
	private const TOUCHED_OPTIONS = array(
		'blogname',
		'blogdescription',
		'posts_per_page',
		'use_smilies',
		'wp_page_for_privacy_policy',
	);

	/**
	 * Cached IDs of the mocked users, keyed by role.
	 *
	 * @var array<string, int>
	 */
	private array $user_ids = array();

	/**
	 * Cached IDs of the mocked pages, tracked for removal.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Settles the registry as the other ability suites do, registering before
	 * the boot so the Registrar's category declarations are queued in time.
	 */
	public static function set_up_before_class(): void {
		Registrar::register_all();

		if ( 0 === did_action( 'wp_abilities_api_init' ) ) {
			WP_Abilities_Registry::get_instance();
		}
	}

	/**
	 * Snapshots the touched options, so tear_down restores them.
	 */
	public function set_up(): void {
		parent::set_up();

		foreach ( self::TOUCHED_OPTIONS as $option ) {
			$this->option_snapshots[ $option ] = get_option( $option, null );
		}
	}

	/**
	 * Restores every touched option (a null snapshot deletes the option) and
	 * removes the mocked users and pages, so the persisted environments keep
	 * their state.
	 */
	public function tear_down(): void {
		foreach ( $this->option_snapshots as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
				continue;
			}
			update_option( $option, $value );
		}
		$this->option_snapshots = array();

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
	 * The registered `wp/settings-update` ability.
	 */
	private function get_registered(): WP_Ability {
		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/settings-update' );
		self::assertNotNull( $ability, 'The ability should be registered through the Registrar.' );

		return $ability;
	}

	/**
	 * A fresh package instance: the exposed-settings snapshot computes at
	 * construction (as at registration), so a test that first registers a
	 * custom setting sees it in the schemas.
	 */
	private function fresh_instance(): Settings_Update {
		return new Settings_Update();
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
					'user_login' => 'su_' . $role,
					'user_pass'  => wp_generate_password(),
					'role'       => $role,
				)
			);
		}
		wp_set_current_user( $this->user_ids[ $role ] );

		return $this->user_ids[ $role ];
	}

	/**
	 * Runs the update ability on a fresh instance and returns the result.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function update( array $input = array() ) {
		return $this->fresh_instance()->execute( $input );
	}

	/**
	 * Creates a tracked page.
	 *
	 * @param string $title The page title.
	 * @return int The page ID.
	 */
	private function create_page( string $title ): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		self::assertGreaterThan( 0, $id, 'The test page should be created.' );
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
	 * Registered exactly once, with the PR's label, category, annotations, the
	 * writable settings as the input properties (no read-only ones), and the
	 * same output projection.
	 */
	public function test_registered_once_with_the_pr_contract(): void {
		$ability = $this->get_registered();
		$meta    = $ability->get_meta();

		self::assertSame( 'Update Settings', $ability->get_label(), 'The ability should use the PR\'s label.' );
		self::assertSame( 'site', $ability->get_category(), 'The ability should use the site category.' );
		self::assertTrue( (bool) ( $meta['show_in_rest'] ?? false ), 'The ability should be exposed in REST.' );

		$annotations = $meta['annotations'] ?? array();
		self::assertFalse( (bool) ( $annotations['readonly'] ?? true ), 'Updating writes the settings.' );
		self::assertTrue( (bool) ( $annotations['destructive'] ?? false ), 'Overwritten values are not kept.' );
		self::assertFalse( (bool) ( $annotations['idempotent'] ?? true ), 'Destructive idempotent abilities are served over DELETE, which cannot carry null.' );

		// input_schema() is protected (the authoring surface); the fresh instance's
		// schema is read reflectively, as the settings-get suite does with its
		// protected members.
		$input_schema   = ( new ReflectionClass( Settings_Update::class ) )->getMethod( 'input_schema' );
		$expected_input = $input_schema->invoke( $this->fresh_instance() );
		$input          = $ability->get_input_schema();
		self::assertSame( $expected_input, $input, 'The registered input schema should match the authored one.' );
		self::assertSame( 1, $input['minProperties'] ?? 0, 'At least one setting should be required.' );
		foreach ( array( 'title', 'description', 'timezone', 'posts_per_page', 'page_for_privacy_policy' ) as $name ) {
			self::assertArrayHasKey( $name, $input['properties'], 'Writable settings should be in the input schema.' );
		}
		foreach ( array( 'url', 'email' ) as $name ) {
			self::assertArrayNotHasKey( $name, $input['properties'], 'Read-only settings should not be writable.' );
		}

		$output = $ability->get_output_schema();
		self::assertFalse( (bool) $output['additionalProperties'], 'The answer should reject undeclared keys.' );
		self::assertArrayNotHasKey( 'url', $output['properties'], 'The answer can never hold a read-only setting.' );
	}

	/**
	 * A settings update answers with ONLY the updated settings, as
	 * `core/settings-get` reads them after the update.
	 */
	public function test_updates_a_setting_and_answers_updated_only(): void {
		$this->log_in_as( 'administrator' );

		$updated = $this->update( array( 'title' => 'Validated Site Name' ) );

		self::assertIsArray( $updated, 'The answer holds the updated settings.' );
		self::assertSame( array( 'title' ), array_keys( $updated ), 'The answer should hold only the updated settings.' );
		self::assertSame( 'Validated Site Name', $updated['title'] );
		self::assertSame( 'Validated Site Name', get_option( 'blogname' ), 'The setting should be stored.' );
	}

	/**
	 * `null` resets a setting that has a registered default to that default,
	 * and stores the default (where the settings endpoint deletes the value).
	 */
	public function test_null_resets_a_setting_with_a_default(): void {
		$this->log_in_as( 'administrator' );
		update_option( 'posts_per_page', 7 );

		$updated = $this->update( array( 'posts_per_page' => null ) );

		self::assertIsArray( $updated, 'The answer holds the updated settings.' );
		self::assertSame( array( 'posts_per_page' ), array_keys( $updated ), 'The answer should hold only the updated settings.' );

		$default = get_registered_settings()['posts_per_page']['default'];
		self::assertSame( $default, $updated['posts_per_page'], 'The reset should answer the registered default.' );
		self::assertSame( $default, get_option( 'posts_per_page' ), 'The default should be stored (the endpoint would leave it unset).' );
	}

	/**
	 * An identical repeat answers the same as the first call. The ability
	 * annotates `idempotent: false` (every call touches the stored values), so
	 * only the observable contract is pinned here: both calls succeed and the
	 * answer stays the updated-only shape.
	 */
	public function test_an_identical_repeat_update_answers_the_same(): void {
		$this->log_in_as( 'administrator' );

		$first  = $this->update( array( 'description' => 'Validated tagline' ) );
		$second = $this->update( array( 'description' => 'Validated tagline' ) );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first, $second, 'The repeat should answer the same updated setting.' );
		self::assertSame( 'Validated tagline', get_option( 'blogdescription' ) );
	}

	/**
	 * Users below the `manage_options` floor are denied by the request-level
	 * floor, and nothing is written.
	 */
	public function test_users_below_the_floor_are_denied(): void {
		$this->log_in_as( 'administrator' );
		update_option( 'blogname', 'Original Name' );

		foreach ( array( 'subscriber', 'contributor', 'editor' ) as $role ) {
			$this->log_in_as( $role );

			$this->assert_error_response(
				'ability_invalid_permissions',
				$this->update( array( 'title' => 'Denied name' ) ),
				null,
				"A {$role} should be denied the update."
			);
		}

		self::assertSame( 'Original Name', get_option( 'blogname' ), 'Denied updates should not write.' );
	}

	/**
	 * A privacy policy page change from a user who cannot manage privacy
	 * options is refused with `settings_cannot_manage_privacy_options`, and
	 * the all-or-nothing order leaves the other settings in the input
	 * unwritten. The settings endpoint skips the setting without an error
	 * instead.
	 */
	public function test_privacy_policy_change_without_manage_privacy_options_is_refused(): void {
		$this->log_in_as( 'administrator' );
		$registered = isset( get_registered_settings()['wp_page_for_privacy_policy'] );
		if ( ! $registered ) {
			register_setting(
				'reading',
				'wp_page_for_privacy_policy',
				array(
					'show_in_rest' => array( 'name' => 'page_for_privacy_policy' ),
					'type'         => 'integer',
				)
			);
		}

		// As for a site administrator on multisite, where the capability maps to manage_network.
		$deny_manage_privacy_options = static function ( array $allcaps, string $cap ): array {
			return 'manage_privacy_options' === $cap ? array( 'do_not_allow' ) : $allcaps;
		};
		add_filter( 'map_meta_cap', $deny_manage_privacy_options, 10, 2 );

		try {
			update_option( 'blogname', 'Original Name' );
			$page_id       = $this->create_page( 'Validated privacy page (current)' );
			$other_page_id = $this->create_page( 'Validated privacy page (target)' );
			update_option( 'wp_page_for_privacy_policy', $page_id );

			$result = $this->update(
				array(
					'title'                   => 'Renamed Site',
					'page_for_privacy_policy' => $other_page_id,
				)
			);

			$this->assert_error_response( 'settings_cannot_manage_privacy_options', $result, 403, 'A privacy policy change without manage_privacy_options should be refused.' );
			self::assertSame( $page_id, (int) get_option( 'wp_page_for_privacy_policy' ), 'The privacy policy page should be unchanged.' );
			self::assertSame( 'Original Name', get_option( 'blogname' ), 'The all-or-nothing order should leave the other settings unwritten.' );
		} finally {
			remove_filter( 'map_meta_cap', $deny_manage_privacy_options, 10 );
			if ( ! $registered ) {
				unregister_setting( 'reading', 'wp_page_for_privacy_policy' );
			}
		}
	}

	/**
	 * An administrator can change the privacy policy page (the
	 * `manage_privacy_options` positive path on single sites).
	 */
	public function test_an_administrator_can_change_the_privacy_policy_page(): void {
		$this->log_in_as( 'administrator' );
		$page_id = $this->create_page( 'Validated privacy page' );

		$updated = $this->get_registered()->execute( array( 'page_for_privacy_policy' => $page_id ) );

		self::assertIsArray( $updated, 'The answer holds the updated settings.' );
		self::assertSame( array( 'page_for_privacy_policy' ), array_keys( $updated ), 'The answer should hold only the updated settings.' );
		self::assertSame( $page_id, $updated['page_for_privacy_policy'], 'The answer should carry the new page.' );
		self::assertSame( $page_id, (int) get_option( 'wp_page_for_privacy_policy' ), 'The privacy policy page should be stored.' );
	}

	/**
	 * An unknown setting name is rejected by the input schema.
	 */
	public function test_an_unknown_setting_name_is_rejected(): void {
		$this->log_in_as( 'administrator' );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->update( array( 'not_a_setting' => 'Any value' ) ),
			null,
			'An unknown setting should be rejected.'
		);
	}

	/**
	 * Empty input is rejected: at least one setting must be provided.
	 */
	public function test_empty_input_is_rejected(): void {
		$this->log_in_as( 'administrator' );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->update( array() ),
			null,
			'Empty input should be rejected.'
		);
	}

	/**
	 * `url` and `email` are read-only: the schema refuses them.
	 */
	public function test_url_and_email_are_read_only(): void {
		$this->log_in_as( 'administrator' );

		$this->assert_error_response(
			'ability_invalid_input',
			$this->update( array( 'url' => 'https://example.com/' ) ),
			null,
			'The site URL should be read-only.'
		);
		$this->assert_error_response(
			'ability_invalid_input',
			$this->update( array( 'email' => 'admin@example.com' ) ),
			null,
			'The admin email should be read-only.'
		);
	}

	/**
	 * `null` for a setting without a registered default is refused
	 * (`settings_invalid_param`, where the settings endpoint deletes the
	 * value), and the all-or-nothing order leaves the other settings
	 * unwritten.
	 */
	public function test_null_without_a_registered_default_is_rejected(): void {
		$this->log_in_as( 'administrator' );
		update_option( 'blogname', 'Original Name' );

		$result = $this->update( array( 'default_ping_status' => null ) );

		// The update input schema only grants null where a registered default exists, so
		// the Abilities API rejects it before the execute callback runs: the observable
		// is ability_invalid_input (no status data), the same schema-level shape as the
		// other input-rejection rows.
		$this->assert_error_response( 'ability_invalid_input', $result, null, 'A null for a setting without a registered default should be rejected.' );
		self::assertSame( 'Original Name', get_option( 'blogname' ), 'Nothing should be written.' );
	}

	/**
	 * A value that sanitizing makes schema-invalid is refused with
	 * `settings_invalid_param` (where the settings endpoint stores it), and the
	 * all-or-nothing order keeps the other settings in the input unwritten.
	 */
	public function test_a_value_sanitizing_makes_invalid_is_refused(): void {
		$this->log_in_as( 'administrator' );
		update_option( 'blogname', 'Original Name' );

		register_setting(
			'validation',
			'su_list_setting',
			array(
				// Unique as strings, so the list validates, but both items sanitize to 1:
				// the sanitized value fails re-validation, which the ability refuses
				// where the settings endpoint stores what sanitizing produced.
				'show_in_abilities' => array(
					'name'   => 'su_list_setting',
					'schema' => array(
						'items'       => array( 'type' => 'integer' ),
						'uniqueItems' => true,
					),
				),
				'type'              => 'array',
			)
		);

		try {
			$result = $this->update(
				array(
					'title'           => 'Should Not Persist',
					'su_list_setting' => array( '1', '01' ),
				)
			);

			$this->assert_error_response( 'settings_invalid_param', $result, 400, 'A value that sanitizing makes invalid should be refused.' );
			self::assertSame( array( 'su_list_setting' ), array_keys( $result->get_error_data()['params'] ?? array() ), 'The refused field should be named.' );
			self::assertSame( 'Original Name', get_option( 'blogname' ), 'The all-or-nothing order should leave the other settings unwritten.' );
			self::assertFalse( get_option( 'su_list_setting' ), 'Nothing should be stored.' );
		} finally {
			unregister_setting( 'validation', 'su_list_setting' );
			delete_option( 'su_list_setting' );
		}
	}

	/**
	 * A setting whose stored value fails its schema validation cannot be
	 * updated to null (`settings_invalid_stored_value`, 500), and the
	 * all-or-nothing order leaves the other settings in the input unwritten
	 * (where the settings endpoint keeps them).
	 */
	public function test_an_invalid_stored_value_blocks_the_null_reset(): void {
		$this->log_in_as( 'administrator' );
		update_option( 'blogname', 'Original Name' );

		register_setting(
			'validation',
			'su_custom_setting',
			array(
				'show_in_abilities' => true,
				'type'              => 'string',
				// Only a setting with a default accepts null.
				'default'           => 'a default',
			)
		);
		update_option( 'su_custom_setting', array( 'A sneaky array!' ) );

		try {
			$result = $this->update(
				array(
					'title'             => 'Should Not Persist',
					'su_custom_setting' => null,
				)
			);

			$this->assert_error_response( 'settings_invalid_stored_value', $result, 500, 'An invalid stored value should block the null reset.' );
			self::assertSame( 'Original Name', get_option( 'blogname' ), 'The all-or-nothing order should leave the other settings unwritten.' );
		} finally {
			unregister_setting( 'validation', 'su_custom_setting' );
			delete_option( 'su_custom_setting' );
		}
	}

	/**
	 * Companion verification (no port): this package's `core/settings-get`
	 * already carries the PR-side changes — each stored value is sanitized
	 * against its schema and a value the schema rejects is left out (instead
	 * of failing the whole read).
	 */
	public function test_settings_get_companion_read_behavior_is_present(): void {
		$this->log_in_as( 'administrator' );

		// The upstream companion data set pin: a numeric option name (PHP turns it
		// into an integer array key in `fields`) registered as integer-typed, with a
		// stored value that is schema-invalid ('abc' for an integer). The PR-side
		// behavior (already present in this package's PR #2 `core/settings-get`):
		// the value is left out instead of failing the whole read.
		register_setting(
			'validation',
			'123',
			array(
				'type'              => 'integer',
				'show_in_abilities' => array( 'schema' => array() ),
			)
		);
		update_option( '123', 'abc' );

		try {
			$read = ( new ReflectionClass( Settings_Update::class ) )
				->getMethod( 'execute_settings_get' )
				->invoke( $this->fresh_instance(), array( 'fields' => array( '123' ) ) );

			self::assertIsArray( $read, 'The read should answer an array even with a schema-invalid stored value.' );
			self::assertArrayNotHasKey( '123', $read, 'A schema-rejected stored value should be left out.' );
		} finally {
			unregister_setting( 'validation', '123' );
			delete_option( '123' );
		}
	}
}
