<?php
/**
 * Tests: Registrar behavior (HANDOFF §10.3, §14 WP2).
 *
 * Tests run in declaration order inside one WP boot; registration state is
 * therefore sequenced deliberately. Only one manifest name
 * (`core/content-query`) may itself be core-provided on trunk (the package
 * copy then self-skips before the gates below it run), so the gate paths
 * that need a package-owned, non-public vehicle — the version floor and
 * `public_only` selection — drive the synthetic `test/registrar-synthetic`
 * entry through the engine's own registration funnel. Its `test/` prefix
 * can never collide with real manifest growth (`core/` or `wp/` entries).
 *
 * P8 adds category ownership to the same sequencing: the Registrar must
 * declare the manifest-declared categories itself, at priority 20 on
 * `wp_abilities_api_categories_init` (after core's own category registrar),
 * and the test bootstrap's package-side backfill is gone, so the pre-7.2
 * stable env boots with `content` genuinely missing until the Registrar
 * declares it. The opt-out filter is observed through a deliberately reopened
 * categories window; see the P8 tests at the end of this class.
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionMethod;
use ReflectionProperty;
use WordPress\Abilities\Abstract_Core_Ability;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Registrar contract tests.
 */
final class RegistrarTest extends TestCase {

	/** Synthetic ability name, outside every real manifest tier prefix. */
	private const SYNTHETIC_NAME = 'test/registrar-synthetic';

	/**
	 * Recorded _doing_it_wrong() calls: function => accumulated messages.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $wrong_usage = array();

	/**
	 * Saved original value of $GLOBALS['wp_version'] while the version floor
	 * is applied (null when no floor is active).
	 *
	 * @var string|null
	 */
	private static ?string $version_floor_saved = null;

	/**
	 * Result of registering the synthetic ability inside the reopened init
	 * window (null until the window has run).
	 *
	 * @var bool|null
	 */
	private static ?bool $synthetic_window_result = null;

	/**
	 * What the categories probe recorded on `wp_abilities_api_categories_init`
	 * at priority 10 — after the restored core category registrar (priority 2,
	 * queued by the test bootstrap) and before the Registrar's declaring
	 * callback (priority 20, queued by the P8 contract).
	 *
	 * False on host APIs whose own category registrar lacks the manifest
	 * `content` category (the pre-7.2 stable env, with the P8 bootstrap
	 * backfill removed): there the Registrar's declaring hook is the only code
	 * between the probe and the window's end, so the category being present
	 * right after the window is provably Registrar-declared. True where the
	 * host itself registers `content` at a priority before the probe — the
	 * guarded no-op is then non-attributable by construction and the P8
	 * assertions branch on it.
	 *
	 * @var bool|null
	 */
	private static ?bool $content_before_declaring_priority = null;

	/**
	 * Result of the P8 loud-rejection vehicle's registration inside its
	 * replayed window (null until the window has run); see
	 * test_missing_category_rejection_is_loud().
	 *
	 * @var bool|null
	 */
	private static ?bool $missing_category_window_result = null;

	/**
	 * Starts recording _doing_it_wrong() traffic for the whole class.
	 */
	public static function set_up_before_class(): void {
		self::$wrong_usage = array();
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
	 * Runs a callback with PHP notices silenced by a local error handler so
	 * an expected _doing_it_wrong() E_USER_NOTICE cannot fail the test; the
	 * doing_it_wrong_run action still fires and is recorded for assertions.
	 *
	 * @param callable $callback The function to run.
	 * @return mixed The callback result.
	 */
	private static function silence_notices( callable $callback ): mixed {
		set_error_handler( static fn (): bool => true, E_USER_NOTICE );
		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Resets the recording listener between assertion groups.
	 */
	private static function reset_wrong_usage(): void {
		self::$wrong_usage = array();
	}

	/**
	 * Asserts no unexpected _doing_it_wrong() occurred since reset.
	 *
	 * @param string $message Optional context for the failure report.
	 */
	private function assert_no_wrong_usage( string $message = 'Unexpected _doing_it_wrong() traffic.' ): void {
		self::assertSame( array(), self::$wrong_usage, $message );
	}

	/**
	 * Applies a version floor of 5.0 so the package support-floor gate can be
	 * tested against the real get_bloginfo( 'version' ) pipeline. Trunk's
	 * get_bloginfo() reads the $wp_version global directly for this output,
	 * so the spoof point is the global itself: the pre_option_ wp_version
	 * filter never fires for it.
	 */
	private static function apply_version_floor(): void {
		global $wp_version;
		self::$version_floor_saved = $wp_version;
		$GLOBALS['wp_version']     = '5.0';
	}

	/**
	 * Removes the version floor.
	 */
	private static function clear_version_floor(): void {
		if ( null !== self::$version_floor_saved ) {
			$GLOBALS['wp_version']     = self::$version_floor_saved;
			self::$version_floor_saved = null;
		}
	}

	/**
	 * Reopens a fresh wp_abilities_api_init registration window.
	 *
	 * The real API seals registration once the lazy action has fired:
	 * wp_register_ability() requires doing_action( 'wp_abilities_api_init' ),
	 * and the action fires exactly once per boot. Tests that must exercise a
	 * later registration flow therefore replay a pre-init boot: reset the
	 * Registrar's static bookkeeping and the registry singleton, clear the
	 * fired-action counter, then let test code queue hooks and re-fire by
	 * calling Registry::get_instance() again. Only the abilities registry is
	 * reset; the categories registry singleton persists so the restored core
	 * categories are not double-registered.
	 */
	private static function open_fresh_init_window(): void {
		$hooked     = new ReflectionProperty( Registrar::class, 'hooked' );
		$registered = new ReflectionProperty( Registrar::class, 'registered' );
		$hooked->setValue( null, false );
		$registered->setValue( null, array() );

		$instance = new ReflectionProperty( WP_Abilities_Registry::class, 'instance' );
		$instance->setValue( null, null );

		$GLOBALS['wp_actions']['wp_abilities_api_init'] = 0;
	}

	/**
	 * Reopens a fresh wp_abilities_api_categories_init window.
	 *
	 * The P8 counterpart of open_fresh_init_window(): tests that must observe
	 * the Registrar's category-declaration decision INSIDE the categories
	 * action (the opt-out filter) replay a pre-categories-init boot by
	 * resetting the categories registry singleton — one of the deliberate
	 * exceptions to the persistence rule open_fresh_init_window() documents —
	 * together with the fired-action counter. The restored core category
	 * registrar (priority 2) and the Registrar's queued declaring callback
	 * (priority 20) survive the reset and re-run once a categories function is
	 * called again; the tests that reopen the window re-establish a settled
	 * registry with the category declared before returning.
	 */
	private static function open_fresh_categories_window(): void {
		$instance = new ReflectionProperty( WP_Ability_Categories_Registry::class, 'instance' );
		$instance->setValue( null, null );

		$GLOBALS['wp_actions']['wp_abilities_api_categories_init'] = 0;
	}

	/**
	 * Builds the synthetic manifest-shaped entry.
	 *
	 * Same shape as a Manifest::all() entry: experimental tier, no required
	 * features, no lineage. It exercises the Registrar's gate chain and
	 * provenance stamping without claiming a real manifest slot.
	 *
	 * @return array<string, mixed>
	 */
	private static function synthetic_entry(): array {
		static $entry = null;
		if ( null === $entry ) {
			/*
			 * Anonymous on purpose: the sniff rules allow one object
			 * structure per file, and the vehicle never needs a file of its
			 * own. PHP registers anonymous classes in the class table under
			 * a generated name, so the engine's `new $entry['class']()` probe
			 * and registration paths run verbatim. Authors public=false, the
			 * vehicle for the public_only selection gate.
			 */
			$ability = new class() extends Abstract_Core_Ability {

				/**
				 * The full ability name, including its tier prefix. Must
				 * match SYNTHETIC_NAME; the constructor name-match notice
				 * and the suite's no-wrong-usage assertions keep the pair
				 * in sync.
				 */
				protected function ability_name(): string {
					return 'test/registrar-synthetic';
				}

				/**
				 * The human-readable ability label.
				 */
				protected function label(): string {
					return 'Registrar test synthetic';
				}

				/**
				 * A detailed description of what the ability does.
				 */
				protected function description(): string {
					return 'Synthetic non-public ability exercising the Registrar gates in the test suite. Never ships in the manifest.';
				}

				/**
				 * The ability category slug.
				 */
				protected function category_slug(): string {
					return 'content';
				}

				/**
				 * The JSON Schema definition for the ability's input.
				 */
				protected function input_schema(): array {
					return array();
				}

				/**
				 * The JSON Schema definition for the ability's output.
				 */
				protected function output_schema(): array {
					return array();
				}

				/**
				 * Builds the execute callback.
				 */
				protected function make_execute_callback(): callable {
					return static function (): array {
						return array();
					};
				}

				/**
				 * Builds the permission callback.
				 */
				protected function make_permission_callback(): callable {
					return static function (): bool {
						return true;
					};
				}

				/**
				 * The ability meta: authors public=false, the vehicle for
				 * the public_only selection gate.
				 */
				protected function ability_meta(): array {
					return array( 'public' => false );
				}

				/**
				 * The request-level capability floor: the permission
				 * callback fully owns authorization for the synthetic.
				 */
				protected function required_capabilities(): array {
					return array();
				}
			};

			$entry = array(
				'name'           => self::SYNTHETIC_NAME,
				'class'          => get_class( $ability ),
				'category'       => 'content',
				'tier'           => Manifest::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => null,
			);
		}

		return $entry;
	}

	/**
	 * Builds a synthetic entry variant whose category is one that nothing in
	 * this process registers: the deterministic, window-free vehicle for the
	 * loud Gate-8 missing-category rejection, valid on every environment.
	 *
	 * The name also differs from SYNTHETIC_NAME, so gate 1 (in-process
	 * idempotence) and gate 2 (the primary synthetic's registry residency)
	 * cannot pre-resolve it. The registry rejects a missing category before
	 * instantiating the ability class, so the class's constructor name-match
	 * notice never fires for the name/category pair.
	 *
	 * @return array<string, mixed>
	 */
	private static function synthetic_entry_with_missing_category(): array {
		$entry             = self::synthetic_entry();
		$entry['name']     = 'test/registrar-missing-category';
		$entry['category'] = 'test-registrar-missing-category';

		return $entry;
	}

	/**
	 * Drives the synthetic entry through the engine's own registration
	 * funnel — the same private Registrar::try_register() every public entry
	 * point calls — so the gate chain runs exactly as it would for a real
	 * manifest entry.
	 *
	 * @param array<string, mixed> $args Consumer-side selection policy.
	 * @return bool The try_register() result.
	 */
	private static function try_register_synthetic( array $args = array() ): bool {
		return self::try_register_entry( self::SYNTHETIC_NAME, self::synthetic_entry(), $args );
	}

	/**
	 * Drives any manifest-shaped entry through the engine's own registration
	 * funnel, as try_register_synthetic() does for the primary vehicle. Used
	 * by the P8 tests for a vehicle whose entry differs from the synthetic's
	 * (a category nothing registers) without disturbing the primary vehicle's
	 * in-process bookkeeping.
	 *
	 * @param string               $name  The registration name for the entry.
	 * @param array<string, mixed> $entry The manifest-shaped entry.
	 * @param array<string, mixed> $args  Consumer-side selection policy.
	 * @return bool The try_register() result.
	 */
	private static function try_register_entry( string $name, array $entry, array $args = array() ): bool {
		$method = new ReflectionMethod( Registrar::class, 'try_register' );
		$method->setAccessible( true ); // No-op on PHP 8.1+, required on 7.4.
		return (bool) $method->invoke( null, $name, $entry, $args );
	}

	/**
	 * 1. API present; pre-init calls defer (hooked) and return "not yet".
	 */
	public function test_api_present_and_pre_init_calls_defer(): void {
		self::assertTrue( function_exists( 'wp_register_ability' ), 'Abilities API must be present (WordPress core).' );
		self::assertSame( 0, did_action( 'wp_abilities_api_init' ), 'The API init action must not have fired yet.' );

		self::reset_wrong_usage();
		Registrar::register_all();
		Registrar::register_all(); // Second pre-init call: static-hook dedupe.
		self::assert_no_wrong_usage();

		self::assertFalse( Registrar::register( 'core/content-query' ), 'Pre-init registration defers: false.' );
		self::assertFalse( Registrar::is_registered( 'core/content-query' ), 'Nothing is registered before the action fires.' );

		// P6: a repeated pre-init call for the same name defers again without
		// queueing a second closure.
		$before = array_sum( array_map( 'count', $GLOBALS['wp_filter']['wp_abilities_api_init']->callbacks ) );
		self::assertFalse( Registrar::register( 'core/content-query' ), 'Repeat pre-init call defers: false.' );
		$after = array_sum( array_map( 'count', $GLOBALS['wp_filter']['wp_abilities_api_init']->callbacks ) );
		self::assertSame( $before, $after, 'Repeat pre-init call queues no second closure (per-name dedupe).' );

		// P8: the Registrar owns the manifest-declared ability categories. The
		// pre-init calls must have queued the declaring callback at priority
		// 20 on the categories action — the same hook the restored core
		// category registrar (test bootstrap, priority 2) runs on — before
		// that action can ever fire, because wp_register_ability_category() is
		// only valid while doing_action( 'wp_abilities_api_categories_init' ).
		self::assertSame( 0, did_action( 'wp_abilities_api_categories_init' ), 'The categories action must not have fired yet either.' );
		self::assertTrue(
			array_key_exists( 20, $GLOBALS['wp_filter']['wp_abilities_api_categories_init']->callbacks ),
			'P8: the Registrar queues a category declaring callback at priority 20 on wp_abilities_api_categories_init.'
		);

		// P8: probe the categories window between the core category registrar
		// (priority 2, restored by the bootstrap) and the Registrar's declaring
		// priority (20), recording whether the manifest `content` category is
		// registered by anything but the Registrar: false on host APIs whose
		// own category registrar lacks `content` (the pre-7.2 stable env — the
		// P8 bootstrap backfill is gone), true where the host registers it
		// itself (trunk core categories).
		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				self::$content_before_declaring_priority = function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'content' );
			},
			10
		);

		self::apply_version_floor(); // Active for the deferred callbacks' first run.
	}

	/**
	 * 2. The lazily-fired init action runs the deferred hooks; the package
	 * support-floor gate blocks registration under an old WP version, while
	 * a core-provided name still (skip) resolves.
	 */
	public function test_init_fires_and_min_wp_version_blocks(): void {
		WP_Abilities_Registry::get_instance(); // Fires wp_abilities_api_init (lazy).
		self::assertSame( 1, did_action( 'wp_abilities_api_init' ) );

		// P8: the first categories window has just fired as part of the same
		// registry boot (the abilities registry boots the categories registry
		// first). The Registrar's queued declaring callback must have produced
		// the manifest-declared category: where the host does not already
		// provide it (the pre-7.2 stable env, with the test bootstrap's
		// package-side backfill removed) the Registrar is the only source, and
		// where the host provides it the guarded no-op kept the declaration a
		// silent no-op. Either way the category must be present, and must
		// carry the package's single-sourced definition.
		self::assertTrue( wp_has_ability_category( 'content' ), 'P8: the manifest `content` category must be registered once the first categories window has fired (Registrar-owned).' );
		self::assertNotNull( self::$content_before_declaring_priority, 'P8: the categories probe ran inside the window.' );
		if ( function_exists( 'wp_get_ability_category' ) ) {
			$declared = wp_get_ability_category( 'content' );
			self::assertNotNull( $declared, 'P8: the manifest category is retrievable.' );
			self::assertSame( 'Content', $declared->get_label(), 'P8: the declaration carries the package single-sourced label.' );
			self::assertSame( 'Abilities that retrieve or manage posts and other content.', $declared->get_description(), 'P8: the declaration carries the package single-sourced description.' );
		}

		/*
		 * Floor gate via the synthetic entry: unambiguous on both envs. The
		 * manifest name cannot carry this assertion on trunk, where core may
		 * provide core/content-query during the action, so the package copy
		 * resolves via the wp_has_ability() gate before the version floor is
		 * ever consulted for it.
		 */
		self::assertFalse( self::try_register_synthetic(), 'Support floor (5.0 < 6.9) skipped the synthetic ability.' );
		self::assertFalse( wp_has_ability( self::SYNTHETIC_NAME ), 'The floor-blocked ability never reached the registry.' );
		self::assertFalse( Registrar::is_registered( self::SYNTHETIC_NAME ) );

		self::clear_version_floor();

		// Core-provided skip: on trunk envs with show_in_abilities post
		// types, core registers core/content-query itself and the package
		// copy self-skips (wp_has_ability true). On the stable env core
		// cannot register it and the deferred copy was floor-blocked above;
		// our copy then registers (next tests).
		$core_provides = wp_has_ability( 'core/content-query' );
		if ( $core_provides ) {
			self::assertTrue( Registrar::is_registered( 'core/content-query' ), 'Core-provided name resolves as registered.' );
		} else {
			self::assertFalse( wp_has_ability( 'core/content-query' ), 'Stable env: core skipped it entirely (show_in_abilities unsupported).' );
			self::assertFalse( Registrar::is_registered( 'core/content-query' ), 'Stable env: the floor-blocked copy is not registered.' );
		}
	}

	/**
	 * 3. Post-init negative gates: public_only selection and the consumer
	 * 'filter' callable (which receives name + manifest entry). Per the
	 * HANDOFF §6.3 gate order, the core-provided skip precedes consumer
	 * selection: a filter can never veto a core-provided name, and is
	 * never even consulted for one.
	 */
	public function test_public_only_and_consumer_filter_gates(): void {
		// The synthetic ability authors public=false.
		self::assertFalse( self::try_register_synthetic( array( 'public_only' => true ) ), 'public_only skipped the non-public ability.' );
		self::assertFalse( wp_has_ability( self::SYNTHETIC_NAME ) );

		$captured = array();
		$filter   = static function ( string $name, array $entry ) use ( &$captured ): bool {
			$captured[] = array( $name, $entry );
			return false;
		};

		/*
		 * The consumer filter veto, driven through a name core never
		 * provides: only the tier-core backports (`core/content-query`,
		 * `core/settings-get`, `core/users-query`) can be core-provided (on
		 * trunk), so this path is deterministic on both envs — and the
		 * first init window ran under the version floor, so the name is
		 * not in-process registered here either.
		 */
		self::assertFalse( Registrar::register( 'core/content-create', array( 'filter' => $filter ) ), 'Consumer filter returning false skips.' );
		self::assertCount( 1, $captured, 'The filter receives exactly the attempted ability.' );
		self::assertSame( 'core/content-create', $captured[0][0] );
		self::assertSame( Manifest::get( 'core/content-create' ), $captured[0][1], 'The filter receives the manifest entry.' );

		/*
		 * P7 core-skip precedence: on a host where core itself provides
		 * `core/content-query` (trunk), the skip gate resolves the name
		 * before consumer selection runs — the veto filter above is never
		 * consulted for it.
		 */
		if ( wp_has_ability( 'core/content-query' ) ) {
			self::assertTrue( Registrar::register( 'core/content-query', array( 'filter' => $filter ) ), 'A consumer filter cannot veto a core-provided name.' );
			self::assertCount( 1, $captured, 'The filter is never consulted for a core-provided name.' );
		}
		self::reset_wrong_usage();

		// Reopen the init window; drive a full positive registration of the
		// synthetic through the same funnel the deferred manifest hooks use.
		self::open_fresh_init_window();
		add_action(
			'wp_abilities_api_init',
			static function (): void {
				self::$synthetic_window_result = self::try_register_synthetic();
			}
		);
		Registrar::register_all(); // Defers: did_action() reads 0 again.
		WP_Abilities_Registry::get_instance(); // Re-fires wp_abilities_api_init.
		self::assertTrue( wp_has_ability( 'core/content-query' ), 'Default policy after the filtered call resolves the name one way or another.' );
	}

	/**
	 * 4. Positive registration with real floor cleared; provenance meta is
	 * stamped with source, package_version, stability and renamed_from.
	 */
	public function test_positive_registration_and_provenance_meta(): void {
		self::assertNotNull( self::$synthetic_window_result, 'The reopened init window ran the synthetic registration.' );
		self::assertTrue( self::$synthetic_window_result, 'The synthetic ability registered inside the init window.' );
		self::assertTrue( wp_has_ability( self::SYNTHETIC_NAME ) );
		self::assertTrue( Registrar::is_registered( self::SYNTHETIC_NAME ) );

		$ability = WP_Abilities_Registry::get_instance()->get_registered( self::SYNTHETIC_NAME );
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();
		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null, 'Provenance: source.' );
		self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null, 'Provenance: package_version.' );
		self::assertSame( Manifest::TIER_EXPERIMENTAL, $meta['stability'] ?? null, 'Provenance: stability tier.' );
		self::assertArrayNotHasKey( 'renamed_from', $meta, 'Provenance: no renamed_from key when entry value is null (synthetic).' );

		foreach ( Manifest::all() as $name => $entry ) {
			$instance = WP_Abilities_Registry::get_instance()->get_registered( $name );
			if ( null === $instance ) {
				continue; // Gate-blocked name: skip behavior covered above.
			}
			$instance_meta = $instance->get_meta();
			if ( null !== $entry['renamed_from'] ) {
				self::assertSame( $entry['renamed_from'], $instance_meta['renamed_from'] ?? null, "Provenance: renamed_from of {$name}." );
			} else {
				self::assertArrayNotHasKey( 'renamed_from', $instance_meta, "Provenance: no renamed_from key when entry value is null ({$name})." );
			}
		}

		self::assertTrue( Registrar::register( 'core/content-query' ), 'core/content-query resolves (ours or core-provided).' );
	}

	/**
	 * 5. Idempotence: repeated registration (fresh-consumer style via a
	 * reflection reset of the in-process bookkeeping) never re-registers,
	 * logs nothing, and leaves the single registry intact.
	 */
	public function test_idempotent_double_registration(): void {
		self::reset_wrong_usage();

		self::assertTrue( self::try_register_synthetic(), 'In-process idempotence: gate 1.' );
		self::assertTrue( Registrar::register( 'core/content-query' ), 'Manifest-name idempotence: gate 1 or core-provided.' );
		Registrar::register_all();

		// Simulate another consumer calling again in-process.
		$state = new ReflectionProperty( Registrar::class, 'registered' );
		$state->setValue( null, array() );
		Registrar::register_all();

		self::assert_no_wrong_usage();

		$names = array_keys( WP_Abilities_Registry::get_instance()->get_all_registered() );
		self::assertSame( $names, array_values( array_unique( $names ) ), 'No duplicate registry entries after repeat registration.' );
		self::assertContains( self::SYNTHETIC_NAME, $names );
		self::assertContains( 'core/content-query', $names );
	}

	/**
	 * 6. P7 core-skip precedence (HANDOFF §6.3 bullet 3): the
	 * core-provided skip gate precedes consumer selection, so a `filter`
	 * can never veto a name the registry already provides. Simulates the
	 * WP 7.2+ host state (core registered `core/content-query`; the
	 * package's in-process bookkeeping is empty, fresh-consumer style as
	 * in test 5): the name must resolve as available and the filter must
	 * never be consulted.
	 */
	public function test_consumer_filter_cannot_veto_core_provided_name(): void {
		self::assertTrue( wp_has_ability( 'core/content-query' ), 'Precondition: the name is registry-resident (registered earlier in this boot).' );

		// Fresh-consumer bookkeeping: registry-resident, not in-process.
		$state = new ReflectionProperty( Registrar::class, 'registered' );
		$state->setValue( null, array() );

		self::reset_wrong_usage();

		$captured = array();
		$filter   = static function ( string $name, array $entry ) use ( &$captured ): bool {
			$captured[] = array( $name, $entry );
			return false;
		};

		self::assertTrue( Registrar::register( 'core/content-query', array( 'filter' => $filter ) ), 'A consumer filter cannot veto a name the registry already provides.' );
		self::assertCount( 0, $captured, 'The filter is never consulted for a registry-resident name.' );
		self::assertTrue( Registrar::is_registered( 'core/content-query' ), 'The core-provided name resolves as available (availability, not authorship).' );

		self::assert_no_wrong_usage();
	}

	/**
	 * 7. P8: the Gate-8 rejection for a missing ability category is LOUD. When
	 * `wp_register_ability()` returns null because the ability's category is
	 * not registered, the Registrar must fire `_doing_it_wrong()` naming that
	 * category instead of returning silently.
	 *
	 * Deterministic on every environment: the vehicle entry declares a
	 * category nothing ever registers, so the registry rejects it for the
	 * missing-category reason on 7.1.x hosts and 7.2+ alike, regardless of
	 * what the host's category registrar provides for the
	 * manifest's own categories. The funnel is driven from inside a replayed
	 * abilities window (open_fresh_init_window(), categories singleton left
	 * alone so the declarations made there persist): a registration — and its
	 * Gate-8 rejection — only happens while wp_abilities_api_init is firing.
	 */
	public function test_missing_category_rejection_is_loud(): void {
		self::reset_wrong_usage();
		self::open_fresh_init_window();

		$vehicle_hook = static function (): void {
			self::$missing_category_window_result = self::try_register_entry(
				'test/registrar-missing-category',
				self::synthetic_entry_with_missing_category()
			);
		};
		add_action( 'wp_abilities_api_init', $vehicle_hook );

		// Fires the replayed window; the registry's category rejection and the
		// Registrar's Gate-8 notice both run within it and are recorded.
		self::silence_notices(
			static function (): void {
				WP_Abilities_Registry::get_instance();
			}
		);

		self::assertFalse( self::$missing_category_window_result, 'P8: the entry whose category is missing did not register.' );

		// Unhook the vehicle so the windows this test leaves reopened for the
		// following suites (see the P8 opt-out test) never announce its
		// rejection again; its result has already been consumed above.
		remove_action( 'wp_abilities_api_init', $vehicle_hook );

		// Precondition: the registry itself rejected the registration for the
		// missing category (its own notice names the slug), so the null the
		// Registrar received IS the missing-category case, not another
		// rejection the package must stay quiet about.
		self::assertArrayHasKey( 'WP_Abilities_Registry::register', self::$wrong_usage, 'P8 precondition: the registry rejected the entry for the missing category.' );

		$messages = self::$wrong_usage['WordPress\Abilities\Registrar::try_register'] ?? array();
		self::assertNotEmpty(
			$messages,
			'P8: a Gate-8 rejection whose reason is the missing category must fire _doing_it_wrong() instead of returning silently.'
		);

		$named = null;
		foreach ( $messages as $message ) {
			if ( strpos( $message, 'test-registrar-missing-category' ) !== false ) {
				$named = $message;
				break;
			}
		}
		self::assertNotNull( $named, 'P8: the loud Gate-8 notice must name the missing category.' );

		// The rest of the replayed window must have stayed quiet: the manifest
		// category and the other registrations resolve without wrong-usage
		// traffic on every environment.
		self::assertTrue( wp_has_ability_category( 'content' ), 'P8: the manifest category is registered after the replayed window.' );
		self::assertTrue( wp_has_ability( 'core/content-create' ), 'P8: the manifest abilities register in the replayed window.' );
		self::reset_wrong_usage();
		self::assert_no_wrong_usage();
	}

	/**
	 * 8. P8: the consumer opt-out filter. Declaring the manifest-declared
	 * categories is the first behavior in which the package WRITES into the
	 * core registry (decision D2), so a consumer must be able to decline it.
	 * With the filter on, the Registrar must not declare the category, and —
	 * where nothing else registers it — the resulting Gate-8 rejections must
	 * be loud, naming the missing category.
	 *
	 * Runs through deliberately replayed windows (open_fresh_categories_window()
	 * resets the categories registry singleton, unlike the persistence rule
	 * open_fresh_init_window() documents; that is the point of this test) and
	 * branches on the OBSERVED post-window category state rather than on
	 * version sniffing:
	 *
	 * - Hosts whose own category registrar re-registers `content` inside the
	 *   window (trunk core): the package's
	 *   declaration is a guarded no-op behind the opt-out, the filter's effect
	 *   is indistinguishable on the content slug, and the loud path cannot fire
	 *   for a category the host provides. The host-owned state must stay
	 *   settled and silent.
	 * - Hosts whose category registrar lacks `content` (WP 7.1.x stable, with
	 * the P8 bootstrap backfill removed): the Registrar's declaration was the
	 * only would-be source, so with the filter on the category must stay
	 * absent and every Gate-8 rejection must name it; removing the filter in
	 * a following window must restore the Registrar-owned declaration and a
	 * settled registry for the suites that read this boot afterwards.
	 */
	public function test_opt_out_filter_declines_registrar_category_declarations(): void {
		add_filter( 'wordpress_abilities_declare_categories', '__return_false' );

		self::open_fresh_categories_window();
		self::open_fresh_init_window();
		self::reset_wrong_usage();

		Registrar::register_all(); // Re-queues the abilities hook for the replayed windows; the declaring hook is already queued and consults the filter at run time.

		self::silence_notices(
			static function (): void {
				WP_Abilities_Registry::get_instance(); // Fires the replayed categories window, then the replayed abilities window.
			}
		);

		if ( wp_has_ability_category( 'content' ) ) {
			// Host-owned category source (see above): the content slug cannot
			// witness the opt-out; assert the settled, silent state instead.
			remove_filter( 'wordpress_abilities_declare_categories', '__return_false' );
			self::assertTrue( wp_has_ability( 'core/content-create' ), 'P8: manifest abilities register where the host registers the category itself.' );
			self::assert_no_wrong_usage( 'P8: the opted-out declaration is silent on a host whose own registrar provides the category.' );
		} else {
			self::assertFalse( wp_has_ability_category( 'content' ), 'P8: with the opt-out filter on, the Registrar must not declare the manifest category.' );

			$messages = self::$wrong_usage['WordPress\Abilities\Registrar::try_register'] ?? array();
			self::assertNotEmpty(
				$messages,
				'P8: with the opt-out filter the manifest abilities are Gate-8 rejected; the rejection must be loud, not silent.'
			);
			$named = null;
			foreach ( $messages as $message ) {
				if ( strpos( $message, 'content' ) !== false ) {
					$named = $message;
					break;
				}
			}
			self::assertNotNull( $named, 'P8: the loud Gate-8 notices must name the missing manifest category.' );

			// Restore the boot for the suites that read it after this class:
			// remove the opt-out, replay both windows, let the Registrar declare.
			remove_filter( 'wordpress_abilities_declare_categories', '__return_false' );
			self::reset_wrong_usage();
			self::open_fresh_categories_window();
			self::open_fresh_init_window();

			Registrar::register_all();
			WP_Abilities_Registry::get_instance();

			self::assertTrue( wp_has_ability_category( 'content' ), 'P8: restored — the Registrar declares the manifest category again once the filter is removed.' );
			self::assertTrue( wp_has_ability( 'core/content-create' ), 'P8: restored — the manifest abilities register again for the following suites.' );

			// The restored boot must have registered quietly from the Registrar's
			// side: no Gate-8 rejection, no category complaints. Only the
			// Registrar's own traffic is asserted here; the abilities' own
			// construction is covered by SettingsGetTest.
			$registrar_traffic = array_intersect_key(
				self::$wrong_usage,
				array_fill_keys(
					array(
						'WordPress\Abilities\Registrar::register_all',
						'WordPress\Abilities\Registrar::register',
						'WordPress\Abilities\Registrar::try_register',
					),
					true
				)
			);
			self::assertSame( array(), $registrar_traffic, 'P8: the restored boot registers without any Registrar wrong-usage traffic.' );
		}
	}

	/**
	 * 9. P8 timing: the FIRST Registrar contact arriving during
	 * wp_abilities_api_init — after the categories window has already
	 * fired — cannot queue category declarations, so the outcome is
	 * host-dependent, per the consuming guidance in the README: where the
	 * host provides the manifest categories, late registration is safe and
	 * silent; where it does not (pre-7.2 host APIs), nothing can register
	 * and every Gate-8 rejection must be loud, naming the category.
	 *
	 * Simulates the consumer pattern that cannot work on category-less
	 * hosts (registering from a wp_abilities_api_init callback): the
	 * queued declaring callback is evicted for one replayed categories
	 * window — priority 20 is the Registrar's alone on that action — so the
	 * window fires with no Registrar participation at all, and the
	 * categories-hook flag is reset so only the fired action stands
	 * between the Registrar and a declaration. The boot is restored
	 * afterwards for the suites that follow (test 8's restore discipline).
	 */
	public function test_first_contact_during_abilities_init_window(): void {
		// Evict the declaring callback: this replayed categories window must
		// fire as if the process had never contacted the Registrar pre-boot.
		$categories_hook_obj = $GLOBALS['wp_filter']['wp_abilities_api_categories_init'];
		$saved_declarer      = $categories_hook_obj->callbacks[20] ?? array();
		unset( $categories_hook_obj->callbacks[20] );

		// Fresh-consumer Registrar state: nothing registered, nothing queued;
		// the fired categories action alone must prevent a new declaration.
		$registered_state = new ReflectionProperty( Registrar::class, 'registered' );
		$registered_state->setValue( null, array() );
		$categories_hooked_state = new ReflectionProperty( Registrar::class, 'categories_hooked' );
		$categories_hooked_state->setValue( null, false );

		self::open_fresh_categories_window();
		self::open_fresh_init_window();
		self::reset_wrong_usage();

		// The late first contact: a consumer registering from inside the
		// abilities window.
		$late_contact = static function (): void {
			Registrar::register_all();
		};
		add_action( 'wp_abilities_api_init', $late_contact, 25 );

		self::silence_notices(
			static function (): void {
				WP_Abilities_Registry::get_instance(); // Replays the categories window (no Registrar participation), then the abilities window with the late first contact.
			}
		);

		remove_action( 'wp_abilities_api_init', $late_contact, 25 );

		// The timing no-op, on every environment: a declaration cannot queue
		// once the categories action fired, and none did.
		self::assertArrayNotHasKey( 20, $GLOBALS['wp_filter']['wp_abilities_api_categories_init']->callbacks, 'P8: no new declaring callback was queued by the late first contact.' );

		if ( wp_has_ability_category( 'content' ) ) {
			// Host-provided category (7.2+ core, or a consumer plugin): the
			// late first contact is safe and silent.
			self::assertTrue( wp_has_ability( 'core/content-create' ), 'P8: a late first contact registers where the host provides the manifest category.' );
			self::assert_no_wrong_usage( 'P8: a late first contact is silent where the host provides the manifest category.' );
		} else {
			// Category-less host (pre-7.2): nothing registered, and every
			// rejection named the category loudly.
			self::assertFalse( wp_has_ability_category( 'content' ), 'P8 precondition: the manifest category is genuinely absent on this host.' );
			self::assertFalse( wp_has_ability( 'core/content-create' ), 'P8: nothing registers when the first contact is late on a category-less host.' );

			$messages = self::$wrong_usage['WordPress\Abilities\Registrar::try_register'] ?? array();
			self::assertNotEmpty( $messages, 'P8: the late first contact on a category-less host is Gate-8 rejected loudly, not silently.' );
			foreach ( $messages as $message ) {
				self::assertStringContainsString( 'content', $message, 'P8: each loud rejection names the missing manifest category.' );
			}
		}

		/*
		 * Restore the boot for the suites that follow: the declaring
		 * callback returns, a pre-window register_all() re-registers
		 * everything through the deferred path, and the replayed windows
		 * settle the registry (test 8's restore discipline).
		 */
		if ( array() !== $saved_declarer ) {
			$categories_hook_obj->callbacks[20] = $saved_declarer;
		}
		$categories_hooked_state->setValue( null, true );

		self::open_fresh_categories_window();
		self::open_fresh_init_window();
		self::reset_wrong_usage();

		Registrar::register_all(); // Pre-window: defers; the restored declarer declares in the replayed categories window.
		self::silence_notices(
			static function (): void {
				WP_Abilities_Registry::get_instance();
			}
		);

		self::assertTrue( wp_has_ability_category( 'content' ), 'P8: restored — the Registrar declares the manifest category again.' );
		self::assertTrue( wp_has_ability( 'core/content-create' ), 'P8: restored — the manifest abilities register again for the following suites.' );

		$registrar_traffic = array_intersect_key(
			self::$wrong_usage,
			array_fill_keys(
				array(
					'WordPress\Abilities\Registrar::register_all',
					'WordPress\Abilities\Registrar::register',
					'WordPress\Abilities\Registrar::try_register',
				),
				true
			)
		);
		self::assertSame( array(), $registrar_traffic, 'P8: the restored boot registers without any Registrar wrong-usage traffic.' );
		self::reset_wrong_usage();
	}

	/**
	 * 10. Gate 7 readiness: an ability whose should_register() declines (core's
	 * register() early return, e.g. nothing to expose) is skipped silently and
	 * never reaches the registry, once its support-surface features pass.
	 */
	public function test_readiness_gate_skips_declining_ability(): void {
		$declining = new class() extends Abstract_Core_Ability {

			/**
			 * The full ability name, including its tier prefix.
			 */
			protected function ability_name(): string {
				return 'test/registrar-declining';
			}

			/**
			 * The human-readable ability label.
			 */
			protected function label(): string {
				return 'Registrar test declining ability';
			}

			/**
			 * A detailed description of what the ability does.
			 */
			protected function description(): string {
				return 'Synthetic ability whose readiness check declines registration. Never ships in the manifest.';
			}

			/**
			 * The ability category slug.
			 */
			protected function category_slug(): string {
				return 'content';
			}

			/**
			 * The JSON Schema definition for the ability's input.
			 */
			protected function input_schema(): array {
				return array();
			}

			/**
			 * The JSON Schema definition for the ability's output.
			 */
			protected function output_schema(): array {
				return array();
			}

			/**
			 * Builds the execute callback.
			 */
			protected function make_execute_callback(): callable {
				return static function (): array {
					return array();
				};
			}

			/**
			 * No permission callback: the floor owns authorization.
			 */
			protected function make_permission_callback(): ?callable {
				return null;
			}

			/**
			 * The ability meta.
			 */
			protected function ability_meta(): array {
				return array( 'public' => true );
			}

			/**
			 * The request-level capability floor.
			 */
			protected function required_capabilities(): array {
				return array( 'manage_options' );
			}

			/**
			 * Declines registration, as core's register() returns early.
			 */
			public function should_register(): bool {
				return false;
			}
		};

		$entry          = self::synthetic_entry();
		$entry['name']  = 'test/registrar-declining';
		$entry['class'] = get_class( $declining );

		self::reset_wrong_usage();
		self::assertFalse( self::try_register_entry( 'test/registrar-declining', $entry ), 'A declining ability is skipped.' );
		self::assertFalse( wp_has_ability( 'test/registrar-declining' ), 'A declining ability never reaches the registry.' );
		self::assert_no_wrong_usage( 'The readiness skip is silent.' );

		$overrides = new ReflectionMethod( Registrar::class, 'overrides_readiness' );
		$overrides->setAccessible( true ); // No-op on PHP 8.1+, required on 7.4.
		self::assertTrue( $overrides->invoke( null, get_class( $declining ) ), 'A class declaring should_register() is probed.' );
		self::assertFalse( $overrides->invoke( null, Manifest::get( 'core/content-query' )['class'] ), 'A class keeping the default is not probed.' );
	}

	/**
	 * 11. Deferred registrations preload their support features at priority 1
	 * of `wp_abilities_api_init`, where core prepares its own support surface
	 * on 7.2+, so a site's changes at priorities 2-9 are not undone.
	 */
	public function test_deferred_registration_preloads_features_at_priority_one(): void {
		$preload_hooked = new ReflectionProperty( Registrar::class, 'preload_hooked' );
		$preloads       = new ReflectionProperty( Registrar::class, 'preloads' );
		$preload_hooked->setAccessible( true ); // No-op on PHP 8.1+, required on 7.4.
		$preloads->setAccessible( true );

		self::open_fresh_init_window();
		$preload_hooked->setValue( null, false );
		$preloads->setValue( null, array() );

		$hook   = $GLOBALS['wp_filter']['wp_abilities_api_init'] ?? null;
		$before = $hook ? count( $hook->callbacks[1] ?? array() ) : 0;

		$vetoed = static function ( string $name ): bool {
			return 'core/content-create' !== $name;
		};
		Registrar::register_all( array( 'filter' => $vetoed ) );
		Registrar::register( 'core/settings-get' );

		self::assertCount( $before + 1, $GLOBALS['wp_filter']['wp_abilities_api_init']->callbacks[1], 'One preload callback is queued at priority 1.' );
		self::assertSame( Manifest::names(), array_keys( $preloads->getValue() ), 'Every deferred name is queued once.' );
		self::assertSame( array( 'filter' => $vetoed ), $preloads->getValue()['core/settings-get'], 'The first queued selection policy is kept.' );

		self::reset_wrong_usage();
		self::silence_notices(
			static function (): void {
				WP_Abilities_Registry::get_instance(); // Re-fires wp_abilities_api_init: preload, then the deferred registrations.
			}
		);
		self::assertTrue( wp_has_ability( 'core/settings-get' ), 'The deferred registration runs after the preload.' );

		// Restore the settled registry for the following suites.
		self::open_fresh_init_window();
		Registrar::register_all();
		self::silence_notices(
			static function (): void {
				WP_Abilities_Registry::get_instance();
			}
		);
		self::assertTrue( wp_has_ability( 'core/content-create' ), 'Restored: the manifest abilities register again for the following suites.' );
		self::reset_wrong_usage();
	}
}
