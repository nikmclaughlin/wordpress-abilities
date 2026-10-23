<?php
/**
 * Canonical WordPress abilities: static ability inventory.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities;


/**
 * Static inventory of every ability shipped in this package.
 *
 * The manifest is the single source of truth for what exists and what tier
 * it is. No logic lives beyond validation-friendly accessors; consumers own
 * when the abilities are on, via the Registrar.
 */
final class Manifest {

	/** Package version; must stay in sync with composer.json. */
	public const VERSION = '0.1.0';

	/** Experimental tier: `wp/` prefix, no promises, iterating. */
	public const TIER_EXPERIMENTAL = 'experimental';

	/** Stable tier: `core/` or `wordpress/` prefix, proposed to core, schema-frozen. */
	public const TIER_STABLE = 'stable';

	/** Core tier: landed in actual WordPress; package copy is a backport. */
	public const TIER_CORE = 'core';

	/**
	 * Static-only class.
	 */
	private function __construct() {}

	/**
	 * Returns the full manifest.
	 *
	 * @return array<string, array<string, mixed>> Ability name => entry.
	 */
	public static function all(): array {
		return array(
			'core/content-query'  => array(
				'name'           => 'core/content-query',
				/*
				 * `::class` resolves to the FQCN without loading the class,
				 * so the manifest may be loaded before the ability classes
				 * exist in a given build of the package.
				 */
				'class'          => Content\Content_Query::class,
				'category'       => 'content',
				'tier'           => self::TIER_CORE,
				'min_wp_version' => '6.9',
				'core_since'     => '7.2',
				'requires'       => array( 'show_in_abilities' ),
				'renamed_from'   => null,
				// WP2-flagged for human confirmation: proposal URL to verify against
				// the landed core work (wordpress-develop PR 12195) before release.
				'proposal'       => 'https://github.com/WordPress/wordpress-develop/pull/12195',
			),
			'core/settings-get'   => array(
				'name'           => 'core/settings-get',
				'class'          => Settings\Settings_Get::class,
				'category'       => 'site',
				'tier'           => self::TIER_CORE,
				'min_wp_version' => '6.9',
				'core_since'     => '7.2',
				'requires'       => array( 'setting_show_in_abilities' ),
				'renamed_from'   => null,
				// Landed in trunk as b6512b1e8c (Trac #64605), developed in this PR.
				'proposal'       => 'https://github.com/WordPress/wordpress-develop/pull/12141',
			),
			'core/users-query'    => array(
				'name'           => 'core/users-query',
				'class'          => Users\Users_Query::class,
				'category'       => 'user',
				'tier'           => self::TIER_CORE,
				'min_wp_version' => '6.9',
				'core_since'     => '7.2',
				'requires'       => array(),
				'renamed_from'   => null,
				// Landed in trunk as 39981fd0fe (Trac #64657), developed in this PR.
				'proposal'       => 'https://github.com/WordPress/wordpress-develop/pull/10775',
			),
			/*
			 * The write trio below is a faithful port of WordPress/ai PR #1025
			 * (author @jorgefilipecosta), on the package's dotted field paths.
			 *
			 * Tier: the PR is a filed core proposal, not landed core, so the
			 * trio is stable (§7.2: a `core/` name is adopted at proposal
			 * filing). The entries flip to core with `core_since` set once
			 * the abilities land in an actual WordPress release.
			 */
			'core/content-create' => array(
				'name'           => 'core/content-create',
				'class'          => Content\Content_Create::class,
				'category'       => 'content',
				'tier'           => self::TIER_STABLE,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1025',
			),
			'core/content-update' => array(
				'name'           => 'core/content-update',
				'class'          => Content\Content_Update::class,
				'category'       => 'content',
				'tier'           => self::TIER_STABLE,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1025',
			),
			'core/content-delete' => array(
				'name'           => 'core/content-delete',
				'class'          => Content\Content_Delete::class,
				'category'       => 'content',
				'tier'           => self::TIER_STABLE,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1025',
			),
		);
	}

	/**
	 * Returns a single manifest entry by ability name.
	 *
	 * @param string $name The ability name, including its tier prefix.
	 * @return array<string, mixed>|null The entry, or null when the name is not in the manifest.
	 */
	public static function get( string $name ): ?array {
		$all = self::all();

		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Returns the manifest ability names, in manifest order.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return array_keys( self::all() );
	}
}
