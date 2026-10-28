<?php
/**
 * Standalone test harness for the optional multi-copy arbitration loader.
 *
 * Plain PHP CLI: no WordPress, no PHPUnit, not part of any autoload or test
 * suite (it lives outside phpcs/phpunit scope in tools/). Run it directly:
 *
 *     php tools/loader-spike/test-loader.php
 *
 * Exit code 0 when every check passes, 1 otherwise. The wp-env suites are
 * deliberately not touched: this harness covers the loader contract.
 *
 * HOW IT PROVES THE CONTRACT
 *
 * The version string passed to wordpress_abilities_register() is
 * consumer-declared — the loader never reads the package files to learn
 * versions. The harness therefore fakes versions at the source: it copies
 * the real src/ tree into two temp directories and patches each copy's
 * Manifest VERSION const to '0.0.1' (old) and '9.9.9' (new), then registers
 * the copies with those same strings, acting as the consumer. Resolving
 * Manifest::VERSION afterwards proves WHICH copy's files the arbitrator
 * chose to serve (the registered version string selects the winner; the
 * loaded const proves the winner's files are what PHP actually loads).
 *
 * Each arbitration scenario runs in its own php child process via
 * self-invocation ( `--scenario=<name>` ): class definitions, the frozen
 * loader functions, and their internal static state are all process-global,
 * so a process that has already booted the package cannot be un-booted. One
 * process per scenario keeps every arbitration independent. The child
 * prints exactly one JSON line of observations; the parent evaluates it and
 * prints the PASS/FAIL report.
 *
 * Scenarios: newest-wins both registration orders, equal-version
 * first-registration tie-break, single copy, late registration after boot,
 * and an invalid package root. Non-loader PHP errors (warnings, deprecations)
 * are captured and excluded from the notice assertions so the checks test
 * only what the loader emits.
 *
 * This is a SPIKE artifact: it lives on spike/loader-arbitration and lands
 * only if the human approves the loader.
 */

error_reporting( E_ALL );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This harness is CLI-only.\n" );
	exit( 1 );
}

/**
 * Reads a `--name=value` argument from the process argv.
 *
 * @param string $name Argument name without the leading `--`.
 * @return string|null The argument value, or null when absent.
 */
function spike_arg( string $name ): ?string {
	$needle = '--' . $name . '=';

	foreach ( $_SERVER['argv'] as $argument ) {
		if ( is_string( $argument ) && 0 === strpos( $argument, $needle ) ) {
			return substr( $argument, strlen( $needle ) );
		}
	}

	return null;
}

/**
 * The package root of the checkout this harness is part of.
 *
 * @return string Absolute path to the package root (contains the loader and src/).
 */
function spike_package_root(): string {
	return dirname( __DIR__, 2 );
}

/**
 * Copies a directory tree recursively.
 *
 * @param string $source      Source directory.
 * @param string $destination Destination directory (created as needed).
 * @return bool Whether the whole tree was copied.
 */
function spike_copy_directory( string $source, string $destination ): bool {
	if ( ! is_dir( $source ) ) {
		return false;
	}

	if ( ! is_dir( $destination ) && ! mkdir( $destination, 0777, true ) ) {
		return false;
	}

	$entries = scandir( $source );

	if ( false === $entries ) {
		return false;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$from = $source . '/' . $entry;
		$to   = $destination . '/' . $entry;

		if ( is_dir( $from ) ) {
			if ( ! spike_copy_directory( $from, $to ) ) {
				return false;
			}
		} elseif ( ! copy( $from, $to ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Patches a copied Manifest.php so its VERSION const reads a fake version.
 *
 * Used on temp copies only. The replacement assumes a plain single-quoted
 * version value (digits and dots, as in this package), which holds for the
 * faked versions this harness uses.
 *
 * @param string $manifest_path Path to the copied src/Manifest.php.
 * @param string $version       Fake version to write in.
 * @return bool Whether the const was found exactly once and the file written.
 */
function spike_patch_manifest_version( string $manifest_path, string $version ): bool {
	$contents = file_get_contents( $manifest_path );

	if ( false === $contents ) {
		return false;
	}

	$patched = preg_replace(
		"/(const\s+VERSION\s*=\s*)'[^']*'/",
		'$1\'' . $version . '\'',
		$contents,
		1,
		$count
	);

	if ( null === $patched ) {
		return false;
	}

	if ( 1 !== $count ) {
		return false;
	}

	return false !== file_put_contents( $manifest_path, $patched );
}

/**
 * Removes a directory tree (temp cleanup).
 *
 * @param string $directory Directory to remove.
 * @return bool Whether the directory is gone afterwards.
 */
function spike_remove_directory( string $directory ): bool {
	if ( ! is_dir( $directory ) ) {
		return true;
	}

	$entries = scandir( $directory );

	if ( false === $entries ) {
		return false;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $directory . '/' . $entry;

		if ( is_dir( $path ) && ! is_link( $path ) ) {
			if ( ! spike_remove_directory( $path ) ) {
				return false;
			}
		} elseif ( ! unlink( $path ) ) {
			return false;
		}
	}

	return rmdir( $directory );
}

/**
 * The label Categories::definition() serves for the manifest's category.
 *
 * Exercises a second class (a different file of the winning copy) so the
 * harness proves the full `WordPress\Abilities\` prefix mapping, not just
 * Manifest.
 *
 * @return string|null The category label, or null if it could not be loaded.
 */
function spike_category_label(): ?string {
	$definition = \WordPress\Abilities\Categories::definition( 'content' );

	return is_array( $definition ) && isset( $definition['label'] ) ? $definition['label'] : null;
}

/*
 * ---------------------------------------------------------------------------
 * Scenario runners: child-process side.
 *
 * Each function registers what the scenario needs, then collects
 * observations. The harness runner requires the loader ONCE per process;
 * scenarios must never assume state from another scenario.
 *
 * ---------------------------------------------------------------------------
 */

/**
 * Old copy registered first, new copy second: newest must still win.
 *
 * @param string $old Temp root of the copy faked to 0.0.1.
 * @param string $new Temp root of the copy faked to 9.9.9.
 * @return array Observations.
 */
function spike_scenario_newest_wins_old_first( string $old, string $new ): array {
	wordpress_abilities_register( '0.0.1', $old );
	wordpress_abilities_register( '9.9.9', $new );

	return array(
		'pre'      => wordpress_abilities_loaded_version(), // Null: registered, no class used yet.
		'manifest' => \WordPress\Abilities\Manifest::VERSION,                    // First class use: arbitrates.
		'post'     => wordpress_abilities_loaded_version(),
		'names'    => \WordPress\Abilities\Manifest::names(),
		'category' => spike_category_label(),
	);
}

/**
 * New copy registered first, old copy second: newest must still win.
 *
 * @param string $old Temp root of the copy faked to 0.0.1.
 * @param string $new Temp root of the copy faked to 9.9.9.
 * @return array Observations.
 */
function spike_scenario_newest_wins_new_first( string $old, string $new ): array {
	wordpress_abilities_register( '9.9.9', $new );
	wordpress_abilities_register( '0.0.1', $old );

	return array(
		'pre'      => wordpress_abilities_loaded_version(),
		'manifest' => \WordPress\Abilities\Manifest::VERSION,
		'post'     => wordpress_abilities_loaded_version(),
		'names'    => \WordPress\Abilities\Manifest::names(),
		'category' => spike_category_label(),
	);
}

/**
 * Equal versions from two copies: the FIRST registration wins the tie.
 *
 * Both copies declare '9.9.9'. The earliest registration is the 0.0.1-faked
 * copy, so its files serve the prefix and the loaded VERSION is their own
 * const ('0.0.1') — proving first-registration tie-breaking on equal
 * declared versions.
 *
 * @param string $old Temp root of the copy faked to 0.0.1.
 * @param string $new Temp root of the copy faked to 9.9.9.
 * @return array Observations.
 */
function spike_scenario_same_version_tiebreak( string $old, string $new ): array {
	wordpress_abilities_register( '9.9.9', $old );
	wordpress_abilities_register( '9.9.9', $new );

	return array(
		'pre'      => wordpress_abilities_loaded_version(),
		'manifest' => \WordPress\Abilities\Manifest::VERSION, // '0.0.1': the first-registered copy serves, despite equal declared versions.
		'post'     => wordpress_abilities_loaded_version(),
	);
}

/**
 * A single registered copy loads normally.
 *
 * @param string $old Temp root of the copy faked to 0.0.1.
 * @param string $new Temp root of the copy faked to 9.9.9 (unused; the scenario registers only one copy).
 * @return array Observations.
 */
function spike_scenario_single_copy( string $old, string $new ): array {
	wordpress_abilities_register( '0.0.1', $old );

	return array(
		'pre'      => wordpress_abilities_loaded_version(),
		'manifest' => \WordPress\Abilities\Manifest::VERSION,
		'post'     => wordpress_abilities_loaded_version(),
		'names'    => \WordPress\Abilities\Manifest::names(),
		'category' => spike_category_label(),
	);
}

/**
 * A valid registration after the first class resolution is ignored.
 *
 * The winner keeps serving: the previously chosen version stays loaded and
 * reported, a diagnostic notice fires, and other classes under the prefix
 * still resolve from the winner.
 *
 * @param string $old Temp root of the copy faked to 0.0.1.
 * @param string $new Temp root of the copy faked to 9.9.9.
 * @return array Observations.
 */
function spike_scenario_late_registration( string $old, string $new ): array {
	wordpress_abilities_register( '9.9.9', $new );

	$observed = array(
		'manifest' => \WordPress\Abilities\Manifest::VERSION, // Boots the arbitrator.
		'post'     => wordpress_abilities_loaded_version(),
	);

	// Late registration with a perfectly valid path: must be ignored.
	wordpress_abilities_register( '0.0.1', $old );

	$observed['manifest2'] = \WordPress\Abilities\Manifest::VERSION;
	$observed['post2']     = wordpress_abilities_loaded_version();
	$observed['category']  = spike_category_label();

	return $observed;
}

/**
 * An invalid package root is skipped with a notice; other candidates survive.
 *
 * The invalid candidate declares '5.5.5' — strictly newer than the valid
 * 0.0.1 candidate — so if it leaked into arbitration it would win (and the
 * winner's unusable src would break loading). The loaded const proves it
 * was truly skipped.
 *
 * @param string $old Temp root of the copy faked to 0.0.1.
 * @param string $new Temp root of the copy faked to 9.9.9 (its subdir is given the invalid path).
 * @return array Observations.
 */
function spike_scenario_invalid_path( string $old, string $new ): array {
	wordpress_abilities_register( '5.5.5', $new . '/missing' );
	wordpress_abilities_register( '0.0.1', $old );

	return array(
		'pre'      => wordpress_abilities_loaded_version(),
		'manifest' => \WordPress\Abilities\Manifest::VERSION,
		'post'     => wordpress_abilities_loaded_version(),
		'names'    => \WordPress\Abilities\Manifest::names(),
	);
}

/**
 * Runs one arbitration scenario and prints exactly one JSON line.
 *
 * @param string $scenario Scenario name (dash-separated; maps to spike_scenario_<name>).
 * @return int Exit code: 0 when the scenario ran to completion without a fatal, 1 otherwise.
 */
function spike_run_scenario_child( string $scenario ): int {
	$loader = spike_arg( 'loader' );
	$old    = spike_arg( 'old' );
	$new    = spike_arg( 'new' );

	$observed = array(
		'pre'       => null,
		'manifest'  => null,
		'manifest2' => null,
		'post'      => null,
		'post2'     => null,
		'names'     => null,
		'category'  => null,
		'notices'   => array(),
		'fatal'     => null,
	);

	if ( null === $loader || null === $old || null === $new ) {
		$observed['fatal'] = 'Missing --loader, --old, or --new argument.';

		echo json_encode( $observed ), "\n";

		return 1;
	}

	set_error_handler( static function ( $errno, $errstr ) use ( &$observed ): bool {
		$observed['notices'][] = array(
			'errno'   => $errno,
			'message' => $errstr,
		);

		return true; // Captured; do not run PHP's internal handler.
	} );

	if ( ! is_file( $loader ) ) {
		$observed['fatal'] = 'Loader file not found: ' . $loader . '.';

		echo json_encode( $observed ), "\n";

		return 1;
	}

	// ONCE per process — the guard makes any further include a no-op.
	require_once $loader;

	$runner = 'spike_scenario_' . str_replace( '-', '_', $scenario );

	try {
		if ( function_exists( $runner ) ) {
			// Two statements on purpose: the scenario runs while the error
			// handler captures into the live $observed, and only then is
			// the (already-updated) base array merged with its output. A
			// single array_merge( $observed, call_user_func( ... ) ) would
			// snapshot $observed BEFORE the scenario ran and lose any
			// notices captured during it.
			$scenario_output = call_user_func( $runner, $old, $new );
			$observed        = array_merge( $observed, $scenario_output );
		} else {
			$observed['fatal'] = 'No scenario implementation named ' . $runner . '.';
		}
	} catch ( Throwable $t ) {
		$observed['fatal'] = get_class( $t ) . ': ' . $t->getMessage();
	}

	echo json_encode( $observed ), "\n";

	return null === $observed['fatal'] ? 0 : 1;
}

/**
 * Runs one scenario in a fresh php child process and returns its JSON.
 *
 * @param string $scenario Scenario name.
 * @param string $loader   Loader file path.
 * @param string $tmp      Temp directory holding copy-old/ and copy-new/.
 * @return array The child's JSON output, plus 'child_exit'; a parse failure surfaces as a 'fatal' entry.
 */
function spike_run_child_scenario( string $scenario, string $loader, string $tmp ): array {
	$command = sprintf(
		'%s %s --scenario=%s --loader=%s --old=%s --new=%s 2>&1',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( __FILE__ ),
		escapeshellarg( $scenario ),
		escapeshellarg( $loader ),
		escapeshellarg( $tmp . '/copy-old' ),
		escapeshellarg( $tmp . '/copy-new' )
	);

	exec( $command, $lines, $exit );

	$json = null;

	for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
		$decoded = json_decode( $lines[ $i ], true );

		if ( is_array( $decoded ) ) {
			$json = $decoded;
			break;
		}
	}

	if ( null === $json ) {
		return array(
			'fatal' => 'No JSON line from child scenario (exit ' . $exit . '). Output: '
				. implode( ' | ', array_slice( $lines, 0, 20 ) ),
		);
	}

	$json['child_exit'] = $exit;

	return $json;
}

/**
 * Filters captured notices down to the loader's own diagnostics, by content.
 *
 * @param array  $observed Child JSON output.
 * @param string $contains Substring that identifies the loader's notice.
 * @return array The matching notice entries.
 */
function spike_loader_notices( array $observed, string $contains ): array {
	$matching = array();

	foreach ( ( $observed['notices'] ?? array() ) as $notice ) {
		if ( is_array( $notice ) && false !== strpos( (string) ( $notice['message'] ?? '' ), $contains ) ) {
			$matching[] = $notice;
		}
	}

	return $matching;
}

/*
 * ---------------------------------------------------------------------------
 * Orchestrator: parent side.
 *
 * ---------------------------------------------------------------------------
 */

/**
 * Records one check result.
 *
 * @param array  $results Result rows, by reference.
 * @param string $name    Check name.
 * @param bool   $ok      Whether the check passed.
 * @param string $detail  Human-readable detail line.
 * @return void
 */
function spike_record( array &$results, string $name, bool $ok, string $detail ): void {
	$results[] = array(
		'name'   => $name,
		'pass'   => $ok,
		'detail' => $detail,
	);
}

/**
 * Prints the PASS/FAIL report and returns the process exit code.
 *
 * @param array $results Result rows.
 * @return int 0 when every check passed, 1 otherwise.
 */
function spike_report( array $results ): int {
	$failed = 0;

	foreach ( $results as $row ) {
		if ( ! $row['pass'] ) {
			++$failed;
		}

		fwrite( STDOUT, sprintf(
			"[%s] %s\n      %s\n",
			$row['pass'] ? 'PASS' : 'FAIL',
			$row['name'],
			$row['detail']
		) );
	}

	fwrite( STDOUT, sprintf( "\n== RESULT: %d passed, %d failed ==\n", count( $results ) - $failed, $failed ) );

	return 0 === $failed ? 0 : 1;
}

/**
 * Orchestrator: sets up faked copies, runs the child scenarios, reports.
 *
 * @return int Process exit code.
 */
function spike_main(): int {
	$results = array();

	fwrite( STDOUT, "wordpress/abilities loader spike harness (plain PHP, no WordPress)\n\n" );

	$loader_path = spike_package_root() . '/wordpress-abilities-loader.php';

	// 1. The loader exists, defines the frozen contract, and its guard makes
	//    a second include a no-op (mirrors several bundled copies including
	//    the same file). An unguarded definition would fatal right here.
	if ( ! is_file( $loader_path ) ) {
		spike_record( $results, 'loader present at package root', false, 'not found: ' . $loader_path );

		return spike_report( $results );
	}

	require $loader_path;
	require $loader_path; // No-op through the function_exists guard.

	$guard_ok = function_exists( 'wordpress_abilities_register' )
		&& function_exists( 'wordpress_abilities_loaded_version' )
		&& null === wordpress_abilities_loaded_version();

	spike_record(
		$results,
		'loader included twice; guard no-ops; no side effects; loaded_version() null before any registration',
		$guard_ok,
		'loaded_version() observed: ' . json_encode( wordpress_abilities_loaded_version() )
	);

	// 2. Two temp copies of src/, with faked Manifest VERSION consts.
	$tmp = sys_get_temp_dir() . '/wordpress-abilities-loader-spike-'
		. getmypid() . '-' . bin2hex( random_bytes( 4 ) );

	$set_up = spike_copy_directory( spike_package_root() . '/src', $tmp . '/copy-old/src' )
		&& spike_patch_manifest_version( $tmp . '/copy-old/src/Manifest.php', '0.0.1' )
		&& spike_copy_directory( spike_package_root() . '/src', $tmp . '/copy-new/src' )
		&& spike_patch_manifest_version( $tmp . '/copy-new/src/Manifest.php', '9.9.9' );

	spike_record(
		$results,
		'temp copies created and patched to 0.0.1 / 9.9.9',
		$set_up,
		$tmp
	);

	if ( ! $set_up ) {
		return spike_report( $results );
	}

	// 3. Scenarios (fresh process each), in the brief's order.
	$scenarios = array(
		'newest_wins_old_first' => static function ( array $observed ) use ( &$results ): void {
			$ok = null === ( $observed['pre'] ?? null )
				&& '9.9.9' === ( $observed['manifest'] ?? null )
				&& '9.9.9' === ( $observed['post'] ?? null )
				&& is_array( $observed['names'] ?? null )
				&& count( $observed['names'] ?? array() ) > 0
				&& 'Content' === ( $observed['category'] ?? null )
				&& null === ( $observed['fatal'] ?? null );

			spike_record(
				$results,
				'arbitration: old (0.0.1) registered first, new (9.9.9) second -> newest wins',
				$ok,
				'observed: ' . json_encode( $observed )
			);
		},
		'newest_wins_new_first' => static function ( array $observed ) use ( &$results ): void {
			$ok = null === ( $observed['pre'] ?? null )
				&& '9.9.9' === ( $observed['manifest'] ?? null )
				&& '9.9.9' === ( $observed['post'] ?? null )
				&& is_array( $observed['names'] ?? null )
				&& count( $observed['names'] ?? array() ) > 0
				&& 'Content' === ( $observed['category'] ?? null )
				&& null === ( $observed['fatal'] ?? null );

			spike_record(
				$results,
				'arbitration: new (9.9.9) registered first, old (0.0.1) second -> newest still wins',
				$ok,
				'observed: ' . json_encode( $observed )
			);
		},
		'same_version_tiebreak' => static function ( array $observed ) use ( &$results ): void {
			$ok = null === ( $observed['pre'] ?? null )
				&& '0.0.1' === ( $observed['manifest'] ?? null )
				&& '9.9.9' === ( $observed['post'] ?? null )
				&& null === ( $observed['fatal'] ?? null )
				&& 0 === ( $observed['child_exit'] ?? 1 );

			spike_record(
				$results,
				'tie-break: equal declared versions (9.9.9, 9.9.9) -> first-registered copy serves',
				$ok,
				'observed: ' . json_encode( $observed )
			);
		},
		'single_copy' => static function ( array $observed ) use ( &$results ): void {
			$ok = null === ( $observed['pre'] ?? null )
				&& '0.0.1' === ( $observed['manifest'] ?? null )
				&& '0.0.1' === ( $observed['post'] ?? null )
				&& 'Content' === ( $observed['category'] ?? null )
				&& null === ( $observed['fatal'] ?? null )
				&& 0 === ( $observed['child_exit'] ?? 1 );

			spike_record(
				$results,
				'single-copy registration loads normally (the 0.0.1-faked copy)',
				$ok,
				'observed: ' . json_encode( $observed )
			);
		},
		'late_registration' => static function ( array $observed ) use ( &$results ): void {
			$notices = spike_loader_notices( $observed, 'already booted' );

			$ok = '9.9.9' === ( $observed['manifest'] ?? null )
				&& '9.9.9' === ( $observed['post'] ?? null )
				&& '9.9.9' === ( $observed['manifest2'] ?? null )
				&& '9.9.9' === ( $observed['post2'] ?? null )
				&& 'Content' === ( $observed['category'] ?? null )
				&& null === ( $observed['fatal'] ?? null )
				&& 0 === ( $observed['child_exit'] ?? 1 )
				&& 1 === count( $notices )
				&& 1 === preg_match(
					'/package already booted from version 9\.9\.9; the version 0\.0\.1 registration was ignored\./',
					(string) ( $notices[0]['message'] ?? '' )
				);

			spike_record(
				$results,
				'registration after boot ignored with a notice; the chosen version keeps serving',
				$ok,
				'observed: ' . json_encode( $observed )
			);
		},
		'invalid_path' => static function ( array $observed ) use ( &$results ): void {
			$notices = spike_loader_notices( $observed, 'was skipped' );

			$ok = null === ( $observed['pre'] ?? null )
				&& '0.0.1' === ( $observed['manifest'] ?? null )
				&& '0.0.1' === ( $observed['post'] ?? null )
				&& is_array( $observed['names'] ?? null )
				&& count( $observed['names'] ?? array() ) > 0
				&& null === ( $observed['fatal'] ?? null )
				&& 0 === ( $observed['child_exit'] ?? 1 )
				&& 1 === count( $notices )
				&& 1 === preg_match(
					'/registration for version 5\.5\.5 was skipped/',
					(string) ( $notices[0]['message'] ?? '' )
				);

			spike_record(
				$results,
				'invalid package root skipped with notice; 5.5.5 did not leak into arbitration',
				$ok,
				'observed: ' . json_encode( $observed )
			);
		},
	);

	$scenario_labels = array(
		'newest_wins_old_first' => 'newest-wins-old-first',
		'newest_wins_new_first' => 'newest-wins-new-first',
		'same_version_tiebreak' => 'same-version-tiebreak',
		'single_copy'           => 'single-copy',
		'late_registration'     => 'late-registration',
		'invalid_path'          => 'invalid-path',
	);

	foreach ( $scenario_labels as $key => $label ) {
		$observed = spike_run_child_scenario( $label, $loader_path, $tmp );
		$scenarios[ $key ]( $observed );
	}

	// 4. Cleanup, wherever the run went.
	$cleaned = spike_remove_directory( $tmp );

	spike_record(
		$results,
		'temp spike copies cleaned up',
		$cleaned,
		$tmp
	);

	return spike_report( $results );
}

/*
 * Entry point.
 *
 * A --scenario=<name> argument flips this file into a child-process scenario
 * runner; without it, the file is the orchestrator that drives every
 * scenario in fresh child processes and prints the report.
 */
$spike_scenario = spike_arg( 'scenario' );

if ( null !== $spike_scenario ) {
	exit( spike_run_scenario_child( $spike_scenario ) );
}

exit( spike_main() );