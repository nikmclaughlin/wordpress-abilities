<?php
/**
 * Tests: `core/settings-get` (HANDOFF §8.1, §10.3, WP7).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev, core registers
 * the ability itself) and stable (6.9/7.1: the real pre-7.2 core API, where
 * the package copy registers and executes). The registered-copy tests branch
 * on provenance; the execute and readiness tests build fresh package
 * instances, so the package code runs on both environments.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionClass;
use ReflectionMethod;
use stdClass;
use WordPress\Abilities\Abstract_Core_Ability;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Settings\Settings_Get;
use WordPress\Abilities\Support\Features;
use WP_Abilities_Registry;
use WP_Ability;
use WP_Error;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `core/settings-get`.
 */
final class SettingsGetTest extends TestCase {

	/**
	 * The settings trunk exposes on a single site, in trunk's registration order.
	 */
	private const TRUNK_EXPOSED = array(
		'title',
		'description',
		'url',
		'email',
		'timezone',
		'date_format',
		'time_format',
		'start_of_week',
		'language',
		'use_smilies',
		'default_category',
		'default_post_format',
		'posts_per_page',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'page_for_privacy_policy',
		'default_ping_status',
		'default_comment_status',
	);

	/**
	 * Cached IDs of the mocked users, keyed by role.
	 *
	 * @var array<string, int>
	 */
	private array $user_ids = array();

	/**
	 * Settings registered by a test: option name => group.
	 *
	 * @var array<string, string>
	 */
	private array $test_settings = array();

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
	 * Removes the test settings, their options, and the mocked users.
	 */
	public function tear_down(): void {
		foreach ( $this->test_settings as $option_name => $group ) {
			unregister_setting( $group, $option_name );
			delete_option( $option_name );
		}
		$this->test_settings = array();

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
		self::$wrong_usage = array();

		parent::tear_down();
	}

	/**
	 * The registered `core/settings-get` instance, or null.
	 */
	private function get_registered(): ?WP_Ability {
		return WP_Abilities_Registry::get_instance()->get_registered( 'core/settings-get' );
	}

	/**
	 * Logs in a mocked user of the given role.
	 */
	private function log_in_as( string $role ): void {
		if ( ! isset( $this->user_ids[ $role ] ) ) {
			$this->user_ids[ $role ] = (int) wp_insert_user(
				array(
					'user_login' => 'sg' . $role,
					'user_pass'  => wp_generate_password(),
					'role'       => $role,
				)
			);
		}
		wp_set_current_user( $this->user_ids[ $role ] );
	}

	/**
	 * Registers a test setting, tracked for removal.
	 *
	 * @param string               $group       The settings group.
	 * @param string               $option_name The option name.
	 * @param array<string, mixed> $args        The registration args.
	 */
	private function add_setting( string $group, string $option_name, array $args ): void {
		register_setting( $group, $option_name, $args );
		$this->test_settings[ $option_name ] = $group;
	}

	/**
	 * Asserts a WP_Error with the exact code.
	 */
	private function assert_wp_error( string $code, $actual ): void {
		self::assertInstanceOf( WP_Error::class, $actual, "Expected WP_Error {$code}." );
		self::assertSame( $code, $actual->get_error_code() );
	}

	/**
	 * Runs a callback with PHP notices silenced, so an expected
	 * _doing_it_wrong() E_USER_NOTICE cannot fail the test; the
	 * doing_it_wrong_run action still fires and is recorded.
	 *
	 * @param callable $callback The function to run.
	 * @return mixed The callback result.
	 */
	private static function silence_notices( callable $callback ) {
		set_error_handler( static fn (): bool => true, E_USER_NOTICE );
		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * The _doing_it_wrong() messages recorded for a function since the last reset.
	 *
	 * @param string $function_name The function the notice names.
	 * @return list<string> The recorded messages.
	 */
	private static function recorded_wrong_usage( string $function_name ): array {
		return self::$wrong_usage[ $function_name ] ?? array();
	}

	/**
	 * Registered exactly once, with core's annotations; provenance branches by env;
	 * repeat registration never double-registers.
	 */
	public function test_registered_once_with_core_annotations(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();

		self::assertSame( 'site', $ability->get_category() );
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
			self::assertNotInstanceOf( Settings_Get::class, $ability );
		} else {
			// Stable env: the package copy registered.
			self::assertInstanceOf( Settings_Get::class, $ability );
			self::assertSame( 'wordpress/abilities', $meta['source'] );
			self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null );
			self::assertSame( Manifest::TIER_CORE, $meta['stability'] ?? null );
		}

		Registrar::register_all();
		Registrar::register_all();
		self::assertSame( $ability, $this->get_registered(), 'No double registration: the single instance holds.' );
	}

	/**
	 * Trunk's `manage_options` floor, read back from the host or the package copy.
	 */
	public function test_required_capabilities_mirror_trunk(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		if ( method_exists( $ability, 'get_required_capabilities' ) ) {
			// 7.2+ hosts (trunk core): the floor is stored and read back.
			self::assertSame( array( 'manage_options' ), $ability->get_required_capabilities() );
		} else {
			// Host API predates 7.2: assert the package copy's authored floor reflectively.
			self::assertSame(
				array( 'manage_options' ),
				( new ReflectionClass( $ability ) )->getMethod( 'required_capabilities' )->invoke( $ability )
			);
		}
	}

	/**
	 * The registered copy exposes exactly trunk's core settings, in trunk's order,
	 * and the input and output schemas are built from them.
	 */
	public function test_schemas_expose_trunk_core_settings(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$input = $ability->get_input_schema();
		self::assertSame( 'object', $input['type'] );
		self::assertSame( array(), $input['default'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'group', 'fields' ), array_keys( $input['properties'] ) );
		self::assertSame( array( 'general', 'writing', 'reading', 'discussion' ), $input['properties']['group']['enum'] );
		self::assertTrue( $input['properties']['fields']['uniqueItems'] );
		self::assertSame( self::TRUNK_EXPOSED, $input['properties']['fields']['items']['enum'] );

		$output = $ability->get_output_schema();
		self::assertSame( 'object', $output['type'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertSame( self::TRUNK_EXPOSED, array_keys( $output['properties'] ) );
		self::assertSame( array( 'open', 'closed' ), $output['properties']['default_comment_status']['enum'] );
		self::assertSame( 'email', $output['properties']['email']['format'] );
		self::assertSame( 'integer', $output['properties']['page_for_privacy_policy']['type'] );
		self::assertSame(
			array(
				'type'        => 'string',
				'description' => 'The URL where WordPress core files are served. May differ from the public site URL.',
				'format'      => 'uri',
			),
			$output['properties']['url'],
			"Trunk's 7.2 siteurl schema, key order included."
		);
	}

	/**
	 * Permission gate: logged out, subscriber, and editor are denied (negative);
	 * an administrator is allowed (positive). On stable this runs through the
	 * capability floor the package folds into the permission callback.
	 */
	public function test_permission_negative_and_positive(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		wp_set_current_user( 0 );
		self::assertFalse( $ability->check_permissions( array() ) );
		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( array() ) );

		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			$this->log_in_as( $role );
			self::assertFalse( $ability->check_permissions( array() ), "A {$role} lacks manage_options." );
		}

		$this->log_in_as( 'administrator' );
		self::assertTrue( $ability->check_permissions( array() ) );
		$result = $ability->execute( array( 'fields' => array( 'title' ) ) );
		self::assertSame( array( 'title' => get_option( 'blogname' ) ), $result );
	}

	/**
	 * Malformed or unknown input fails closed at schema validation, never widened.
	 */
	public function test_execute_fail_closed_malformed_input(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->log_in_as( 'administrator' );

		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( array( 'group' => 'no-such-group' ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( array( 'fields' => array( 'no_such_setting' ) ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( array( 'fields' => array( 'title', 'title' ) ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( array( 'unknown' => true ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( 'title' ) );

		$all = $ability->execute( array() );
		self::assertIsArray( $all );
		self::assertSame( array_intersect( self::TRUNK_EXPOSED, array_keys( $all ) ), array_keys( $all ), 'No input returns exposed settings only, in order.' );
	}

	/**
	 * Execute against mocked settings: exposure names, defaults, the boolean and
	 * object reads, schema-invalid values, and the group/fields filters.
	 */
	public function test_execute_mocked_settings(): void {
		$this->add_setting(
			'sg_a',
			'sg_string',
			array(
				'type'              => 'string',
				'show_in_abilities' => true,
				'default'           => 'fallback',
			)
		);
		$this->add_setting(
			'sg_a',
			'sg_no_default',
			array(
				'type'              => 'integer',
				'show_in_abilities' => true,
			)
		);
		$this->add_setting(
			'sg_b',
			'sg_bool',
			array(
				'type'              => 'boolean',
				'show_in_abilities' => true,
			)
		);
		$this->add_setting(
			'sg_b',
			'sg_invalid',
			array(
				'type'              => 'integer',
				'show_in_abilities' => true,
			)
		);
		$this->add_setting(
			'sg_b',
			'sg_object',
			array(
				'type'              => 'object',
				'show_in_abilities' => array(
					'schema' => array(
						'properties' => array(
							'a' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
		$this->add_setting(
			'sg_b',
			'sg_rest_named',
			array(
				'type'              => 'string',
				'show_in_rest'      => array( 'name' => 'sg_rest_name' ),
				'show_in_abilities' => true,
			)
		);
		$this->add_setting(
			'sg_b',
			'sg_abilities_named',
			array(
				'type'              => 'string',
				'show_in_rest'      => array( 'name' => 'sg_ignored_rest_name' ),
				'show_in_abilities' => array( 'name' => 'sg_abilities_name' ),
			)
		);
		$this->add_setting(
			'sg_b',
			'sg_hidden',
			array(
				'type'              => 'string',
				'show_in_abilities' => false,
			)
		);
		// update_option() skips a missing option whose new value is false; add_option() stores it as ''.
		add_option( 'sg_bool', false );
		update_option( 'sg_invalid', 'not-a-number' );
		update_option( 'sg_object', array( 'a' => 'x' ) );
		update_option( 'sg_rest_named', 'rest' );
		update_option( 'sg_abilities_named', 'abilities' );
		update_option( 'sg_hidden', 'hidden' );

		$this->log_in_as( 'administrator' );
		$ability = new Settings_Get();
		self::assertTrue( $ability->should_register() );

		$result = $ability->execute( array( 'group' => 'sg_a' ) );
		self::assertSame( array( 'sg_string' => 'fallback' ), $result, 'An unsaved setting reads its default, or is left out without one.' );

		$result = $ability->execute( array( 'group' => 'sg_b' ) );
		self::assertIsArray( $result );
		self::assertSame( array( 'sg_bool', 'sg_object', 'sg_rest_name', 'sg_abilities_name' ), array_keys( $result ) );
		self::assertFalse( $result['sg_bool'], 'A stored false reads as false, not the empty string.' );
		self::assertInstanceOf( stdClass::class, $result['sg_object'] );
		self::assertSame( 'x', $result['sg_object']->a );
		self::assertSame( 'rest', $result['sg_rest_name'], 'show_in_abilities: true uses the show_in_rest name.' );
		self::assertSame( 'abilities', $result['sg_abilities_name'], 'A show_in_abilities array is used instead of show_in_rest.' );
		self::assertArrayNotHasKey( 'sg_invalid', $result, 'A value its schema rejects is left out.' );

		self::assertSame( array( 'sg_bool' => false ), $ability->execute( array( 'fields' => array( 'sg_bool' ) ) ) );
		self::assertSame(
			array(),
			$ability->execute(
				array(
					'group'  => 'sg_a',
					'fields' => array( 'sg_bool' ),
				)
			),
			'Both filters narrow to their intersection.'
		);

		$fields = $ability->get_input_schema()['properties']['fields']['items']['enum'];
		self::assertNotContains( 'sg_hidden', $fields );
		self::assertNotContains( 'sg_ignored_rest_name', $fields );
		self::assertSame( 'string', $ability->get_output_schema()['properties']['sg_string']['type'] );
	}

	/**
	 * Two settings exposed under the same name: a notice, and the later one wins.
	 */
	public function test_duplicate_exposed_name_is_reported(): void {
		$this->add_setting(
			'sg_dup',
			'sg_dup_first',
			array(
				'type'              => 'string',
				'show_in_abilities' => array( 'name' => 'sg_dup' ),
			)
		);
		$this->add_setting(
			'sg_dup',
			'sg_dup_second',
			array(
				'type'              => 'string',
				'show_in_abilities' => array( 'name' => 'sg_dup' ),
			)
		);
		update_option( 'sg_dup_first', 'first' );
		update_option( 'sg_dup_second', 'second' );

		self::$wrong_usage = array();
		$ability           = self::silence_notices(
			static function (): Settings_Get {
				return new Settings_Get();
			}
		);

		$notices = self::recorded_wrong_usage( Settings_Get::class . '::get_exposed_settings' );
		self::assertCount( 1, $notices );
		self::assertStringContainsString( 'sg_dup_second', $notices[0] );

		$this->log_in_as( 'administrator' );
		self::assertSame( array( 'sg_dup' => 'second' ), $ability->execute( array( 'fields' => array( 'sg_dup' ) ) ) );
	}

	/**
	 * should_register() mirrors core's early return when no setting is exposed.
	 */
	public function test_should_register_only_with_exposed_settings(): void {
		$backup = $GLOBALS['wp_registered_settings'];

		try {
			$GLOBALS['wp_registered_settings'] = array();
			$ability                           = new Settings_Get();
			self::assertFalse( $ability->should_register() );
		} finally {
			$GLOBALS['wp_registered_settings'] = $backup;
		}

		self::assertTrue( ( new Settings_Get() )->should_register() );
	}

	/**
	 * Constructing the package copy raises no notice on either host: the
	 * `required_capabilities` argument only reaches a WP_Ability that declares it.
	 */
	public function test_construction_raises_no_wrong_usage(): void {
		self::$wrong_usage = array();
		new Settings_Get();
		self::assertSame( array(), self::$wrong_usage );
	}

	/**
	 * The capability floor the package folds into the permission callback on
	 * hosts older than 7.2, mirroring WP_Ability::check_permissions() on 7.2+.
	 */
	public function test_capability_floor_mirrors_core_check_order(): void {
		$fold = new ReflectionMethod( Abstract_Core_Ability::class, 'with_capability_floor' );
		$fold->setAccessible( true ); // No-op on PHP 8.1+, required on 7.4.

		$calls    = array();
		$callback = static function ( $input = null ) use ( &$calls ): bool {
			$calls[] = $input;
			return 'allow' === $input;
		};

		self::assertSame( $callback, $fold->invoke( null, array(), $callback ), 'No floor: the callback is kept as-is.' );
		self::assertFalse( $fold->invoke( null, array(), null )(), 'No floor and no callback fails closed.' );

		$floor_only = $fold->invoke( null, array( 'manage_options' ), null );
		$combined   = $fold->invoke( null, array( 'manage_options' ), $callback );

		$this->log_in_as( 'subscriber' );
		self::assertFalse( $floor_only() );
		self::assertFalse( $combined( 'allow' ) );
		self::assertSame( array(), $calls, 'The callback never runs when the floor fails.' );

		$this->log_in_as( 'administrator' );
		self::assertTrue( $floor_only() );
		self::assertTrue( $combined( 'allow' ) );
		self::assertFalse( $combined( 'deny' ) );
		self::assertSame( array( 'allow', 'deny' ), $calls, 'The callback runs after the floor, with the input forwarded.' );
	}

	/**
	 * The support feature: native on 7.2+, polyfilled on 6.9-7.1.
	 */
	public function test_setting_show_in_abilities_feature(): void {
		self::assertTrue( Features::is_available( 'setting_show_in_abilities' ) );

		$loaded = ( new ReflectionClass( Features::class ) )->getProperty( 'loaded' );
		$loaded->setAccessible( true ); // No-op on PHP 8.1+, required on 7.4.
		$native = function_exists( '_wp_register_initial_settings_for_abilities' );
		self::assertSame( ! $native, isset( $loaded->getValue()['setting_show_in_abilities'] ), 'The polyfill loads only where core lacks the feature.' );
	}

	/**
	 * The polyfill (6.9-7.1 only): trunk's default for core settings, explicit
	 * values kept, the privacy page setting registered for abilities only, and
	 * the options screens' allowed list left alone.
	 */
	public function test_polyfill_mirrors_trunk_defaults(): void {
		if ( function_exists( '_wp_register_initial_settings_for_abilities' ) ) {
			self::markTestSkipped( 'Core provides the setting argument; the polyfill never loads here.' );
		}

		$filtered = apply_filters( 'register_setting_args', array( 'type' => 'string' ), array(), 'general', 'blogname' );
		self::assertTrue( $filtered['show_in_abilities'] );

		$explicit = apply_filters(
			'register_setting_args',
			array(
				'type'              => 'string',
				'show_in_abilities' => false,
			),
			array(),
			'general',
			'blogname'
		);
		self::assertFalse( $explicit['show_in_abilities'], 'An explicit value is never overridden.' );

		$other = apply_filters( 'register_setting_args', array( 'type' => 'string' ), array(), 'general', 'sg_not_core' );
		self::assertArrayNotHasKey( 'show_in_abilities', $other, 'Only the settings trunk exposes get the default.' );

		$privacy = get_registered_settings()['wp_page_for_privacy_policy'] ?? null;
		self::assertIsArray( $privacy );
		self::assertFalse( $privacy['show_in_rest'], 'The REST settings endpoint of the host is unchanged.' );
		self::assertSame( array( 'name' => 'page_for_privacy_policy' ), $privacy['show_in_abilities'] );

		self::assertNotContains(
			'wp_page_for_privacy_policy',
			$GLOBALS['new_allowed_options']['reading'] ?? array(),
			'Registering for abilities does not add the setting to an options screen.'
		);
	}
}
