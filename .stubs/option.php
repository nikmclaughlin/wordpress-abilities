<?php
/**
 * PHPStan stubs for the 7.2 Settings API additions.
 *
 * phpstan-wordpress describes get_registered_settings() as it was before 7.2,
 * without the `show_in_abilities` argument that the settings abilities read.
 * This file is dev tooling only: referenced from phpstan.neon.dist stubFiles,
 * never autoloaded, never shipped or listed in composer.json autoload entries.
 * Once phpstan-wordpress describes the 7.2 shape, delete this file and its
 * stubFiles entry.
 *
 * @package WordPress\Abilities
 */

namespace {

	/**
	 * Retrieves an array of registered settings.
	 *
	 * Mirrors trunk's wp-includes/option.php docblock. register_setting() fills
	 * every key from its defaults except `default`, which is only present when
	 * the setting was registered with one; `show_in_abilities` is a default
	 * since 7.2 and, on older hosts, present only when the registration passed it.
	 * `group` is always filled too, but stays optional here because the trunk code
	 * this package ports verbatim reads it defensively (`$args['group'] ?? ''`).
	 *
	 * @since 4.7.0
	 * @since 7.2.0 Registered setting data includes the `show_in_abilities` argument.
	 *
	 * @phpstan-return array<int|string, array{
	 *   type: string,
	 *   group?: string,
	 *   label: string,
	 *   description: string,
	 *   sanitize_callback: callable|null,
	 *   show_in_rest: bool|array<string, mixed>,
	 *   show_in_abilities?: bool|array<string, mixed>,
	 *   default?: mixed,
	 * }>
	 */
	function get_registered_settings() {
		return array();
	}
}
