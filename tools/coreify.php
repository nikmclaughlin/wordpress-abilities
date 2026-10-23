<?php
/**
 * coreify.php — mechanical package<->core token transform for the wordpress/abilities package.
 *
 * Modes:
 *   (default)  forward: package (WordPress\Abilities\Content\Content_Query) -> core
 *              (wp-includes abilities-api) form.
 *   --reverse: core -> package form, plus a manifest entry skeleton on stdout.
 *   --check:   round-trip acceptance gate: reverse(fixture) -> forward() -> semantic
 *              token diff vs the ORIGINAL fixture (must be clean), plus a semantic
 *              diff of the reverse output vs the hand-port package file (must be
 *              clean; known cosmetic diffs are whitespace/comments and thus ignored).
 *   --help
 *
 * Pure token-based (token_get_all + token_name), single file, no runtime deps.
 * Outputs are written under tools/out/ unless --out is given.
 */

declare( strict_types = 1 );

class CoreifyError extends RuntimeException {}

function fail( string $msg ): void {
	throw new CoreifyError( $msg );
}

/* ---------------------------------------------------------------------
 * Config
 * ------------------------------------------------------------------- */

/** Token classes the semantic diff ignores (cosmetic). */
const SEM_SKIP = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML );

/** The 10 authoring methods of Abstract_Core_Ability (this fixed order). */
const AUTHORING_METHODS = array(
	'ability_name', 'label', 'description', 'category_slug',
	'input_schema', 'output_schema', 'make_execute_callback',
	'make_permission_callback', 'ability_meta', 'required_capabilities',
);

/** Authoring method return types (all return exactly one expression). */
const AUTHORING_TYPES = array(
	'ability_name'           => 'string',
	'label'                  => 'string',
	'description'            => 'string',
	'category_slug'          => 'string',
	'input_schema'           => 'array',
	'output_schema'          => 'array',
	'make_execute_callback'  => 'callable',
	'make_permission_callback' => 'callable',
	'ability_meta'           => 'array',
	'required_capabilities'  => 'array',
);

/** Registration-array entry key per authoring method. */
const AUTHORING_ENTRY = array(
	'label'                  => 'label',
	'description'            => 'description',
	'category_slug'          => 'category',
	'input_schema'           => 'input_schema',
	'output_schema'          => 'output_schema',
	'make_execute_callback'  => 'execute_callback',
	'make_permission_callback' => 'permission_callback',
	'ability_meta'           => 'meta',
	'required_capabilities'  => 'required_capabilities',
);

/** Emission order of registration entries. */
const AUTHORING_DOCS = array(
	'ability_name' => 'The ability name slug.', 'label' => 'The ability label.', 'description' => 'The ability description.', 'category_slug' => 'The ability category slug.', 'input_schema' => 'The JSON Schema for the ability input.', 'output_schema' => 'The JSON Schema for the ability output.', 'make_execute_callback' => 'Builds the execute callback.', 'make_permission_callback' => 'Builds the permission callback.', 'ability_meta' => 'Additional ability metadata.', 'required_capabilities' => 'Capability names required to run the ability.',
);
const REG_KEYS = array(
	'label', 'description', 'category', 'input_schema', 'output_schema',
	'execute_callback', 'permission_callback', 'required_capabilities', 'meta',
);

/**
 * Optional authoring methods: Abstract_Core_Ability defaults them, and an
 * ability overrides them only when core's register() entry point computes
 * state before registering (prepare()) or returns early (should_register()).
 */
const OPTIONAL_METHODS = array( 'prepare', 'should_register' );

/** Helpers re-inlined from Abstract_Core_Ability, in order. */
const INLINE_HELPERS = array( 'parse_filter_int', 'parse_list_input', 'get_input_object' );

/**
 * Per-ability transform profiles, keyed by ability name.
 *
 * - fixture / package: repo-relative paths of the trunk snapshot and the
 *   package port, the --check pair.
 * - splice_after: the register_*() prologue variable after which core's
 *   void early-return guard is spliced (null when core's guard, if any,
 *   lives in register() instead; that one maps to should_register()).
 * - helpers: forward anchors, helper name => member after which the
 *   inherited Abstract_Core_Ability helper is re-inlined.
 * - imports: the fixed `use` block of the package form.
 * - register_doc / register_note: the forward register() docblock summary
 *   and trailing comment (cosmetic; the semantic diff ignores comments).
 * - category / requires / proposal: the reverse manifest entry skeleton.
 */
const PROFILES = array(
	'core/content-query' => array(
		'fixture'       => 'tests/Fixtures/abilities-api/abilities/class-wp-abilities-content.php',
		'package'       => 'src/Content/Content_Query.php',
		'splice_after'  => '$post_types',
		'helpers'       => array(
			'parse_filter_int' => 'check_permission',
			'parse_list_input' => 'parse_filter_int',
			'get_input_object' => 'execute_content_query',
		),
		'imports'       => array( 'DateTimeZone', 'stdClass', 'WP_Error', 'WP_Post', 'WP_Post_Type', 'WP_Query', 'WP_User' ),
		'register_doc'  => 'Registers all content abilities.',
		'register_note' => array(
			'Future write-oriented content abilities can be registered here, reusing',
			'the shared helpers below (get_exposed_post_type(), format_post(),',
			'check_permission()).',
		),
		'category'      => 'content',
		'requires'      => array( 'show_in_abilities' ),
		'proposal'      => 'https://github.com/WordPress/wordpress-develop/pull/12195',
	),
	'core/settings-get'  => array(
		'fixture'       => 'tests/Fixtures/abilities-api/abilities/class-wp-abilities-settings.php',
		'package'       => 'src/Settings/Settings_Get.php',
		'splice_after'  => null,
		'helpers'       => array(),
		'imports'       => array(),
		'register_doc'  => 'Registers all settings abilities.',
		'register_note' => array(),
		'category'      => 'site',
		'requires'      => array( 'setting_show_in_abilities' ),
		'proposal'      => 'https://github.com/WordPress/wordpress-develop/pull/12141',
	),
);

/** Ability whose files the forward and reverse modes default to. */
const DEFAULT_ABILITY = 'core/content-query';

/** The transform profile of an ability name. */
function profile( string $abname ): array {
	isset( PROFILES[ $abname ] ) || fail( 'no coreify profile for ability ' . $abname . ' (add one to PROFILES)' );
	return PROFILES[ $abname ];
}

/* ---------------------------------------------------------------------
 * Token primitives
 * ------------------------------------------------------------------- */

/**
 * Tokenize source into (id, text, line) triples.
 * Single-character tokens get ord(char) as id; lines are tracked manually.
 *
 * @return list<array{int,string,int}>
 */
function tokenize_source( string $src, bool $parse = true ): array {
	$raw = $parse ? token_get_all( $src, TOKEN_PARSE ) : token_get_all( $src );
	$out = array();
	$line = 1;
	foreach ( $raw as $t ) {
		if ( is_array( $t ) ) {
			$out[] = array( $t[0], $t[1], $t[2] );
			$line += substr_count( $t[1], "\n" );
		} else {
			$out[] = array( ord( $t ), $t, $line );
			$line += substr_count( $t, "\n" );
		}
	}
	return $out;
}

function is_skipped( array $t ): bool {
	return in_array( $t[0], SEM_SKIP, true );
}

/**
 * Structural depth delta of one token: brace/paren/bracket +1/-1.
 * String/encapsed/comment tokens contribute 0.
 */
function struct_delta( array $t ): int {
	if ( is_skipped( $t ) ) {
		return 0;
	}
	switch ( $t[0] ) {
		case 123: // {
		case T_CURLY_OPEN:
		case T_DOLLAR_OPEN_CURLY_BRACES:
		case 40: // (
		case 91: // [
			return 1;
		case 125: // }
		case 41: // )
		case 93: // ]
			return -1;
	}
	return 0;
}

/** Index of the token that closes the group opened at $open. */
function match_group( array $tokens, int $open ): int {
	$depth = 0;
	$n = count( $tokens );
	for ( $i = $open; $i < $n; $i++ ) {
		$depth += struct_delta( $tokens[ $i ] );
		if ( 0 === $depth ) {
			return $i;
		}
	}
	fail( 'unbalanced token group' );
}

/** First meaningful token at index >= $i and <= $lim (inclusive), or null. */
function next_meaningful_token( array $tokens, int $i, ?int $lim = null ): ?int {
	$n = count( $tokens );
	$lim = null === $lim ? $n - 1 : min( $lim, $n - 1 );
	for ( $j = $i; $j <= $lim; $j++ ) {
		if ( ! is_skipped( $tokens[ $j ] ) ) {
			return $j;
		}
	}
	return null;
}

/** First meaningful token at index <= $i and >= $from (inclusive), or null. */
function prev_meaningful_token( array $tokens, int $from, int $i ): ?int {
	$from = max( 0, $from );
	for ( $j = $i; $j >= $from; $j-- ) {
		if ( ! is_skipped( $tokens[ $j ] ) ) {
			return $j;
		}
	}
	return null;
}

/** Verbatim text of token span [$a..$b] inclusive. */
function span_text( array $tokens, int $a, int $b ): string {
	$s = '';
	for ( $i = $a; $i <= $b; $i++ ) {
		$s .= $tokens[ $i ][1];
	}
	return $s;
}

/** Shrink a span inward to its meaningful bounds. */
function trim_span( array $tokens, int $a, int $b ): array {
	while ( $a < $b && is_skipped( $tokens[ $a ] ) ) {
		$a++;
	}
	while ( $b > $a && is_skipped( $tokens[ $b ] ) ) {
		$b--;
	}
	return array( $a, $b );
}

/** Text of span trimmed to meaningful tokens. */
function span_trim( array $tokens, int $a, int $b ): string {
	list( $a, $b ) = trim_span( $tokens, $a, $b );
	return span_text( $tokens, $a, $b );
}

/**
 * Reindent continuation lines (all lines after the first) by $delta tabs.
 * Negative delta strips at most |delta| leading tabs per line; blank or
 * shallower-indented lines are left untouched.
 */
function reindent( string $text, int $delta ): string {
	if ( 0 === $delta ) {
		return $text;
	}
	$lines = explode( "\n", $text );
	$n = count( $lines );
	for ( $i = 1; $i < $n; $i++ ) {
		if ( $delta > 0 ) {
			$lines[ $i ] = str_repeat( "\t", $delta ) . $lines[ $i ];
			continue;
		}
		if ( '' === $lines[ $i ] ) {
			continue;
		}
		$tabs = strspn( $lines[ $i ], "\t" );
		if ( $tabs >= -$delta ) {
			$lines[ $i ] = substr( $lines[ $i ], -$delta );
		}
	}
	return implode( "\n", $lines );
}

/** Strip one layer of outer quotes (single-quoted inputs only). */
function unquote( string $s ): string {
	$q = strlen( $s ) >= 2 ? $s[0] : '';
	if ( "'" === $q || '"' === $q ) {
		$body = substr( $s, 1, -1 );
		if ( "'" === $q ) {
			$body = str_replace( array( "\\'", '\\\\' ), array( "'", "\\" ), $body );
		}
		return $body;
	}
	return $s;
}

/* ---------------------------------------------------------------------
 * Statement splitting
 * ------------------------------------------------------------------- */

/**
 * Statements of token span [$from..$to]: each ends at a ';' at relative
 * paren/brace/bracket depth 0 (so closure bodies never terminate statements);
 * a trailing span (no closing ';') is kept if it has meaningful tokens.
 * Leading whitespace/comments glue to the FOLLOWING statement span.
 *
 * @return list<array{int,int}> [startToken, endToken] inclusive.
 */
function split_statements( array $tokens, int $from, int $to ): array {
	$stmts = array();
	$depth = 0;
	$start = $from;
	for ( $i = $from; $i <= $to; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_skipped( $t ) ) {
			continue;
		}
		$d = struct_delta( $t );
		if ( 125 === $t[0] && 1 === $depth ) {
			// Closing brace of a brace-terminated statement (if/foreach/while/switch/try).
			// Only if the next meaningful token is not ';' (closures/do-while keep going).
			$next = next_meaningful_token( $tokens, $i + 1 );
			if ( null === $next || 59 !== $tokens[ $next ][0] ) {
				$depth += $d;
				$stmts[] = array( $start, $i );
				$start = $i + 1;
				continue;
			}
		}
		if ( 0 !== $d ) {
			$depth += $d;
			continue;
		}
		if ( 0 === $depth && 59 === $t[0] ) { // ';'
			$stmts[] = array( $start, $i );
			$start = $i + 1;
		}
	}
	if ( $start <= $to ) {
		$stmts[] = array( $start, $to );
	}
	$out = array();
	foreach ( $stmts as $st ) {
		if ( null !== next_meaningful_token( $tokens, $st[0], $st[1] ) ) {
			$out[] = $st;
		}
	}
	return $out;
}

/** Statements strictly between an open { and close } token. */
function body_statements( array $tokens, int $open, int $close ): array {
	return split_statements( $tokens, $open + 1, $close - 1 );
}

/** Index of a statement's first meaningful token. */
function stmt_start( array $tokens, array $st ): int {
	$f = next_meaningful_token( $tokens, $st[0], $st[1] );
	if ( null === $f ) {
		fail( 'empty statement span' );
	}
	return $f;
}

/** Text of a statement span, trimmed to meaningful tokens. */
function stmt_text( array $tokens, array $st ): string {
	return span_trim( $tokens, $st[0], $st[1] );
}

/* ---------------------------------------------------------------------
 * Class/member parsing
 * ------------------------------------------------------------------- */

/**
 * Index of the comment run immediately preceding a declaration start:
 * scan backwards over whitespace/comments, return index of the first
 * (leftmost) comment in the contiguous run, or $start if none.
 */
function doc_start( array $tokens, int $start ): int {
	$i = $start;
	$first = $start;
	while ( $i > 0 ) {
		$i--;
		$id = $tokens[ $i ][0];
		if ( T_WHITESPACE === $id ) {
			continue;
		}
		if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
			$first = $i;
			continue;
		}
		break;
	}
	return $first;
}

/** Parse the single class decl: modifiers back to the leftmost, name, extends, body. */
function parse_class( array $tokens ): array {
	$n = count( $tokens );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_CLASS !== $tokens[ $i ][0] ) {
			continue;
		}
		$prev = prev_meaningful_token( $tokens, 0, $i - 1 );
		if ( null !== $prev && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
			continue; // ::class resolution
		}
		$ni = next_meaningful_token( $tokens, $i + 1 );
		if ( null === $ni ) {
			continue;
		}
		$start = $i;
		$p = $i;
		while ( true ) {
			$pv = prev_meaningful_token( $tokens, 0, $p - 1 );
			if ( null === $pv ) {
				break;
			}
			$pid = $tokens[ $pv ][0];
			if ( T_FINAL === $pid || T_ABSTRACT === $pid || T_READONLY === $pid ) {
				$p = $pv;
			} else {
				break;
			}
		}
		$start = $p;
		$name = $tokens[ $ni ][1];
		$extends = '';
		$open = null;
		$j = $ni + 1;
		while ( true ) {
			$f = next_meaningful_token( $tokens, $j );
			if ( null === $f ) {
				fail( 'unterminated class header' );
			}
			$id = $tokens[ $f ][0];
			if ( T_EXTENDS === $id ) {
				$parent = next_meaningful_token( $tokens, $f + 1 );
				$extends = $tokens[ $parent ][1];
				$j = $parent + 1;
				continue;
			}
			if ( 123 === $id ) {
				$open = $f;
				break;
			}
			if ( T_IMPLEMENTS === $id || T_USE === $id ) {
				fail( 'class forms not supported by coreify: implements/use' );
			}
			$j = $f + 1;
		}
		$close = match_group( $tokens, $open );
		return array(
			'start'   => $start,
			'doc'     => doc_start( $tokens, $start ),
			'name'    => $name,
			'extends' => $extends,
			'open'    => $open,
			'close'   => $close,
		);
	}
	fail( 'no class declaration found' );
}

/**
 * Members of a class body: constants and functions with docblock spans.
 *
 * Records: kind const|function, name, start (first modifier/const/function
 * token), end, doc (doc_start), for functions open/close (body braces) plus
 * vis/static/final/abstract flags.
 *
 * @return list<array<string,mixed>>
 */
function parse_members( array $tokens, int $open, int $close ): array {
	$members = array();
	$depth = 0;
	$i = $open + 1;
	while ( $i < $close ) {
		$t = $tokens[ $i ];
		if ( is_skipped( $t ) ) {
			$i++;
			continue;
		}
		$d = struct_delta( $t );
		if ( 0 !== $d ) {
			$depth += $d;
			$i++;
			continue;
		}
		if ( 0 !== $depth ) {
			$i++;
			continue;
		}
		$id = $t[0];
		if ( T_VARIABLE === $id ) {
			// Property declaration: modifiers (and an optional type) back to the first one.
			$p = $i;
			while ( true ) {
				$pv = prev_meaningful_token( $tokens, $open + 1, $p - 1 );
				if ( null === $pv ) {
					break;
				}
				$pid = $tokens[ $pv ][0];
				if ( T_PUBLIC === $pid || T_PROTECTED === $pid || T_PRIVATE === $pid
					|| T_STATIC === $pid || T_READONLY === $pid || T_VAR === $pid
					|| T_STRING === $pid || T_ARRAY === $pid || 63 === $pid ) { // 63: '?'
					$p = $pv;
				} else {
					break;
				}
			}
			$end = null;
			$d = 0;
			for ( $scan = $i + 1; $scan <= $close; $scan++ ) {
				$d += struct_delta( $tokens[ $scan ] );
				if ( 0 === $d && 59 === $tokens[ $scan ][0] ) {
					$end = $scan;
					break;
				}
			}
			if ( null === $end ) {
				fail( 'property without terminating semicolon: ' . $t[1] );
			}
			$members[] = array(
				'kind' => 'prop', 'name' => $t[1], 'start' => $p, 'end' => $end,
				'doc' => doc_start( $tokens, $p ),
				'open' => null, 'close' => null, 'vis' => null,
				'static' => false, 'final' => false, 'abstract' => false,
			);
			$i = $end + 1;
			continue;
		}
		if ( T_CONST !== $id && T_FUNCTION !== $id ) {
			$i++;
			continue;
		}
		$prev = prev_meaningful_token( $tokens, $open + 1, $i - 1 );
		if ( null !== $prev && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
			$i++; // Foo::class usage
			continue;
		}
		$p = $i;
		while ( true ) {
			$pv = prev_meaningful_token( $tokens, $open + 1, $p - 1 );
			if ( null === $pv ) {
				break;
			}
			$pid = $tokens[ $pv ][0];
			if ( T_PUBLIC === $pid || T_PROTECTED === $pid || T_PRIVATE === $pid
				|| T_STATIC === $pid || T_FINAL === $pid || T_ABSTRACT === $pid
				|| T_READONLY === $pid ) {
				$p = $pv;
			} else {
				break;
			}
		}
		if ( T_CONST === $id ) {
			$ni = next_meaningful_token( $tokens, $i + 1, $close );
			if ( null === $ni ) {
				fail( 'const without name' );
			}
			$name = $tokens[ $ni ][1];
			$end = null;
			$scan = $ni + 1;
			$d = 0;
			while ( $scan <= $close ) {
				$d += struct_delta( $tokens[ $scan ] );
				if ( 0 === $d && 59 === $tokens[ $scan ][0] ) {
					$end = $scan;
					break;
				}
				$scan++;
			}
			if ( null === $end ) {
				fail( 'const without terminating semicolon: ' . $name );
			}
			$members[] = array(
				'kind' => 'const', 'name' => $name, 'start' => $p, 'end' => $end,
				'doc' => doc_start( $tokens, $p ),
				'open' => null, 'close' => null, 'vis' => null,
				'static' => false, 'final' => false, 'abstract' => false,
			);
			$i = $end + 1;
			continue;
		}
		// function
		$ni = next_meaningful_token( $tokens, $i + 1, $close );
		if ( null === $ni ) {
			fail( 'function without name' );
		}
		if ( 38 === $tokens[ $ni ][0] ) { // '&'
			$ni = next_meaningful_token( $tokens, $ni + 1, $close );
			if ( null === $ni ) {
				fail( 'malformed function reference' );
			}
		}
		if ( T_STRING !== $tokens[ $ni ][0] ) {
			fail( 'unexpected member form near token ' . $i . ' (' . $tokens[ $ni ][1] . ')' );
		}
		$name = $tokens[ $ni ][1];
		$paren = next_meaningful_token( $tokens, $ni + 1, $close );
		if ( null === $paren || 40 !== $tokens[ $paren ][0] ) {
			fail( 'function without parens: ' . $name );
		}
		$pclose = match_group( $tokens, $paren );
		$after = next_meaningful_token( $tokens, $pclose + 1, $close );
		if ( null !== $after && 58 === $tokens[ $after ][0] ) { // ':' return-type seam
			$scan = $after;
			$rtd = 0;
			while ( $scan <= $close ) {
				$t2 = $tokens[ $scan ];
				if ( ! is_skipped( $t2 ) && 0 === $rtd ) {
					$id2 = $t2[0];
					if ( 123 === $id2 || 59 === $id2 ) {
						$after = $scan;
						break;
					}
				}
				$rtd += struct_delta( $t2 );
				$scan++;
			}
			if ( null === $after ) {
				fail( 'unterminated return type after params of ' . $name );
			}
		}
		$end = null;
		$bo = null;
		$bc = null;
		if ( null !== $after && 123 === $tokens[ $after ][0] ) {
			$bo = $after;
			$bc = match_group( $tokens, $bo );
			$end = $bc;
		} else {
			$end = $pclose;
			$e = $pclose;
			while ( $e < $close ) {
				$e++;
				if ( 59 === $tokens[ $e ][0] ) {
					$end = $e;
					break;
				}
			}
			if ( 59 !== $tokens[ $end ][0] ) {
				fail( 'function without terminating semicolon: ' . $name );
			}
		}
		$vis = null;
		$static = false;
		$final = false;
		$abstract = false;
		for ( $q = $p; $q < $ni; $q++ ) {
			$qid = $tokens[ $q ][0];
			if ( T_PUBLIC === $qid ) $vis = 'public';
			elseif ( T_PROTECTED === $qid ) $vis = 'protected';
			elseif ( T_PRIVATE === $qid ) $vis = 'private';
			elseif ( T_STATIC === $qid ) $static = true;
			elseif ( T_FINAL === $qid ) $final = true;
			elseif ( T_ABSTRACT === $qid ) $abstract = true;
		}
		$members[] = array(
			'kind' => 'function', 'name' => $name, 'start' => $p, 'end' => $end,
			'doc' => doc_start( $tokens, $p ),
			'open' => $bo, 'close' => $bc, 'vis' => $vis,
			'static' => $static, 'final' => $final, 'abstract' => $abstract,
		);
		$i = $end + 1;
	}
	if ( array() === $members ) {
		fail( 'class has no members' );
	}
	return $members;
}

/** Verbatim text from docblock to end token. */
function member_text( array $tokens, array $rec ): string {
	return span_text( $tokens, $rec['doc'], $rec['end'] );
}

function index_by_name( array $members ): array {
	$by = array();
	foreach ( $members as $rec ) {
		if ( isset( $by[ $rec['name'] ] ) ) {
			fail( 'duplicate member name: ' . $rec['name'] );
		}
		$by[ $rec['name'] ] = $rec;
	}
	return $by;
}

function find_member( array $members, string $name ): ?array {
	foreach ( $members as $rec ) {
		if ( $rec['name'] === $name ) {
			return $rec;
		}
	}
	return null;
}

/** The single `return <expr> ;` expression of a method body. */
function return_expr( array $tokens, array $rec ): string {
	$rec['kind'] === 'function' || fail( 'not a function: ' . $rec['name'] );
	$rec['open'] !== null || fail( 'abstract method has no body: ' . $rec['name'] );
	$stmts = body_statements( $tokens, $rec['open'], $rec['close'] );
	$expr = null;
	foreach ( $stmts as $st ) {
		$f = stmt_start( $tokens, $st );
		if ( T_RETURN === $tokens[ $f ][0] ) {
			$expr === null || fail( 'multiple return statements in ' . $rec['name'] );
			$expr = span_trim( $tokens, $f + 1, $st[1] - 1 );
		}
	}
	if ( null === $expr ) {
		fail( 'no return statement in ' . $rec['name'] );
	}
	return $expr;
}

/** True if expr is exactly `array()`. */
function expr_is_empty_array( string $expr ): bool {
	$raw = token_get_all( '<?php ' . $expr );
	$sem = array();
	foreach ( $raw as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], SEM_SKIP, true ) ) {
				continue;
			}
			$sem[] = array( $t[0], $t[1] );
		} else {
			$sem[] = array( ord( $t ), $t );
		}
	}
	return 3 === count( $sem ) && T_ARRAY === $sem[0][0] && 40 === $sem[1][0] && 41 === $sem[2][0];
}

/* ---------------------------------------------------------------------
 * Semantic diff
 * ------------------------------------------------------------------- */

/** Tokens minus cosmetic classes (whitespace/comments), for semantic diff. */
function sem_tokens( array $tokens ): array {
	$out = array();
	foreach ( $tokens as $t ) {
		if ( is_skipped( $t ) ) {
			continue;
		}
		$out[] = array( $t[0], $t[1], $t[2] );
	}
	return $out;
}

/** Semantic diff of two sources: null on equality, else first mismatch info. */
function semdiff( string $aText, string $bText ): ?array {
	$A = sem_tokens( tokenize_source( $aText ) );
	$B = sem_tokens( tokenize_source( $bText ) );
	$lenA = count( $A );
	$lenB = count( $B );
	$n = min( $lenA, $lenB );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( $A[ $i ][0] !== $B[ $i ][0] || $A[ $i ][1] !== $B[ $i ][1] ) {
			return array( 'index' => $i, 'a' => $A[ $i ], 'b' => $B[ $i ], 'lenA' => $lenA, 'lenB' => $lenB );
		}
	}
	if ( $lenA !== $lenB ) {
		$tokA = $lenA > $n ? $A[ $n ] : array( 0, '<eof>', 0 );
		$tokB = $lenB > $n ? $B[ $n ] : array( 0, '<eof>', 0 );
		return array( 'index' => $n, 'a' => $tokA, 'b' => $tokB, 'lenA' => $lenA, 'lenB' => $lenB );
	}
	return null;
}

function token_repr( array $t ): string {
	$label = token_name( $t[0] );
	return $label . '(' . substr( $t[1], 0, 48 ) . ')';
}

function print_mismatch( string $label, array $m ): void {
	fwrite( STDERR, $label . " first mismatch at token index " . $m['index'] . "\n" );
	fwrite( STDERR, "  A: line " . $m['a'][2] . "  " . token_repr( $m['a'] ) . "\n" );
	fwrite( STDERR, "  B: line " . $m['b'][2] . "  " . token_repr( $m['b'] ) . "\n" );
	fwrite( STDERR, "  (A tokens: " . $m['lenA'] . ", B tokens: " . $m['lenB'] . ")\n" );
}

/* ---------------------------------------------------------------------
 * Naming
 * ------------------------------------------------------------------- */

/**
 * Derive names from an ability name like 'core/content-query':
 * [0] dir 'Content', [1] package class 'Content_Query', [2] core class
 * 'WP_Abilities_Content', [3] register method 'register_content_query',
 * [4] category 'content'.
 *
 * @return array{string,string,string,string,string}
 */
function slug_parts( string $abname ): array {
	$slug = $abname;
	$slash = strpos( $slug, '/' );
	if ( false !== $slash ) {
		$slug = substr( $slug, $slash + 1 );
	}
	$raw = explode( '-', $slug );
	$words = array_map( static function ( string $p ): string {
		return str_replace( ' ', '', ucwords( $p ) );
	}, $raw );
	$dir = $words[0];
	$pkgClass = implode( '_', $words );
	$coreClass = 'WP_Abilities_' . $words[0];
	$regMethod = 'register_' . implode( '_', $raw );
	$category = $raw[0];
	return array( $dir, $pkgClass, $coreClass, $regMethod, $category );
}

/* ---------------------------------------------------------------------
 * Abstract_Core_Ability helpers
 * ------------------------------------------------------------------- */

/**
 * Helper declaration texts from Abstract_Core_Ability, with protected
 * converted to private (token-level), docblock included.
 *
 * @return array<string,string>
 */
function helper_texts( string $absText ): array {
	$tokens = tokenize_source( $absText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$out = array();
	foreach ( INLINE_HELPERS as $h ) {
		$rec = find_member( $members, $h );
		if ( null === $rec ) {
			fail( 'helper not found in Abstract_Core_Ability: ' . $h );
		}
		if ( ! $rec['static'] ) {
			fail( 'helper is not static: ' . $h );
		}
		$skipWs = false;
		$text = '';
		for ( $i = $rec['doc']; $i <= $rec['end']; $i++ ) {
			$t = $tokens[ $i ];
			if ( $skipWs ) {
				$skipWs = false;
				if ( T_WHITESPACE === $t[0] ) {
					continue;
				}
			}
			if ( T_STATIC === $t[0] ) {
				// Trunk inlines the helpers as plain private methods; drop 'static'.
				$skipWs = true;
				continue;
			}
			if ( T_PROTECTED === $t[0] ) {
				$t = array( T_PROTECTED, 'private', $t[2] );
			}
			$text .= $t[1];
		}
		$out[ $h ] = $text;
	}
	return $out;
}

/**
 * Semantic token lists of the helper bodies (open..close braces exclusive),
 * for the reverse-mode drop guard.
 *
 * @return array<string,list<list<scalar>>>
 */
function helper_bodies( string $absText ): array {
	$tokens = tokenize_source( $absText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$out = array();
	foreach ( INLINE_HELPERS as $h ) {
		$rec = find_member( $members, $h );
		if ( null === $rec ) {
			fail( 'helper not found in Abstract_Core_Ability: ' . $h );
		}
		// Build full semantic token list of body span (including structure tokens).
		$list = array();
		for ( $i = $rec['open'] + 1; $i <= $rec['close'] - 1; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_skipped( $t ) ) {
				continue;
			}
			$list[] = array( $t[0], $t[1] );
		}
		$out[ $h ] = $list;
	}
	return $out;
}

/* ---------------------------------------------------------------------
 * FORWARD transform
 * ------------------------------------------------------------------- */

/**
 * Build the wp_register_ability(...) call from lifted authoring expressions.
 * $vals: entry key => expression text (multi-line continuation lines carry
 * their own indentation, reindented +2 at the seam).
 */
function build_registration( string $nameLit, array $vals ): string {
	$keys = array();
	foreach ( REG_KEYS as $k ) {
		if ( isset( $vals[ $k ] ) ) {
			$keys[] = $k;
		}
	}
	$maxlen = 0;
	foreach ( $keys as $k ) {
		$maxlen = max( $maxlen, strlen( $k ) + 2 );
	}
	$out = "\t\twp_register_ability(\n";
	$out .= "\t\t\t" . $nameLit . ",\n";
	$out .= "\t\t\tarray(\n";
	foreach ( $keys as $k ) {
		$pad = str_repeat( ' ', $maxlen - ( strlen( $k ) + 2 ) + 1 );
		$out .= "\t\t\t\t'" . $k . "'" . $pad . '=> ' . reindent( $vals[ $k ], 2 ) . ",\n";
	}
	$out .= "\t\t\t)\n";
	$out .= "\t\t);\n";
	return $out;
}

/**
 * The guard condition of a should_register() return expression: the
 * expression with its leading `!` negation removed, unwrapped from one
 * outer pair of parentheses when they enclose all of it.
 */
function guard_condition( string $expr ): string {
	$expr = trim( $expr );
	'!' === substr( $expr, 0, 1 ) || fail( 'should_register() must return a negated condition, `! <condition>`' );
	$cond = trim( substr( $expr, 1 ) );
	if ( '(' === substr( $cond, 0, 1 ) ) {
		$tokens = tokenize_source( '<?php ' . $cond, false );
		$open   = next_meaningful_token( $tokens, 1 );
		$last   = prev_meaningful_token( $tokens, 0, count( $tokens ) - 1 );
		if ( match_group( $tokens, $open ) === $last ) {
			$cond = span_trim( $tokens, $open + 1, $last - 1 );
		}
	}
	return $cond;
}

/**
 * Forward transform: package class text -> core class text.
 *
 * @param string $absText Raw text of src/Abstract_Core_Ability.php.
 */
function transform_forward( string $pkgText, string $absText ): string {
	$tokens = tokenize_source( $pkgText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$by = index_by_name( $members );

	$abname = unquote( return_expr( $tokens, $by['ability_name'] ) );
	$prof = profile( $abname );
	list( $dir, $pkgClass, $coreClass, $regMethod ) = slug_parts( $abname );
	if ( $cls['name'] !== $pkgClass ) {
		fail( 'unexpected class name ' . $cls['name'] . ' for ability ' . $abname . ' (expected ' . $pkgClass . ')' );
	}
	if ( isset( $by['register'] ) || isset( $by[ $regMethod ] ) ) {
		fail( 'package class must not declare register()/' . $regMethod . '()' );
	}
	foreach ( INLINE_HELPERS as $h ) {
		if ( isset( $by[ $h ] ) ) {
			fail( 'package class must not declare helper ' . $h . ' (inherited from Abstract_Core_Ability)' );
		}
	}

	/* --- authoring values ------------------------------------------------ */
	$vals = array();
	foreach ( AUTHORING_ENTRY as $m => $entry ) {
		$rec = $by[ $m ] ?? null;
		if ( null === $rec ) {
			fail( 'authoring method missing in package class: ' . $m );
		}
		$vals[ $entry ] = return_expr( $tokens, $rec );
	}
	$nameLit = "'" . $abname . "'";

	if ( isset( $vals['required_capabilities'] ) && expr_is_empty_array( $vals['required_capabilities'] ) ) {
		unset( $vals['required_capabilities'] );
	}
	// A null permission callback means the required capabilities own authorization: core omits the entry.
	if ( 'null' === strtolower( trim( $vals['permission_callback'] ) ) ) {
		unset( $vals['permission_callback'] );
	}

	/* --- register() prologue and guard from prepare()/should_register() --- */
	$prepared = array();
	if ( isset( $by['prepare'] ) ) {
		$pRec = $by['prepare'];
		foreach ( body_statements( $tokens, $pRec['open'], $pRec['close'] ) as $st ) {
			$prepared[] = stmt_text( $tokens, $st );
		}
	}
	$guard = isset( $by['should_register'] ) ? guard_condition( return_expr( $tokens, $by['should_register'] ) ) : null;

	/* --- register_*() prologue from input_schema() ------------------------- */
	$inRec = $by['input_schema'];
	$stmts = body_statements( $tokens, $inRec['open'], $inRec['close'] );
	$assigns = array();
	$spliceAt = -1;
	foreach ( $stmts as $idx => $st ) {
		$f = stmt_start( $tokens, $st );
		$id = $tokens[ $f ][0];
		if ( T_RETURN === $id ) {
			continue; // the schema return itself becomes the registration entry
		}
		if ( T_IF === $id ) {
			fail( 'input_schema() must not contain the void early-return splice' );
		}
		if ( T_VARIABLE !== $id ) {
			fail( 'unexpected statement in input_schema(): ' . token_repr( $tokens[ $f ] ) );
		}
		if ( null !== $prof['splice_after'] && $prof['splice_after'] === $tokens[ $f ][1] ) {
			$spliceAt = count( $assigns );
		}
		$assigns[] = $st;
	}
	if ( null !== $prof['splice_after'] && $spliceAt < 0 ) {
		fail( 'input_schema() missing a ' . $prof['splice_after'] . ' assignment' );
	}

	/* --- assemble register_*() --------------------------------------------- */
	$regCq = "\tprivate function " . $regMethod . "(): void {\n";
	foreach ( $assigns as $idx => $st ) {
		$regCq .= "\t\t" . stmt_text( $tokens, $st ) . "\n";
		if ( $spliceAt === $idx ) {
			$regCq .= "\t\tif ( empty( " . $prof['splice_after'] . " ) ) {\n";
			$regCq .= "\t\t\treturn;\n";
			$regCq .= "\t\t}\n\n";
		}
	}
	$regCq .= "\n" . build_registration( $nameLit, $vals );
	$regCq .= "\t}\n";

	/* --- assemble register() ------------------------------------------------ */
	$registerFn = "\t/**\n"
		. "\t * " . $prof['register_doc'] . "\n"
		. "\t *\n"
		. "\t * Must run on the `wp_abilities_api_init` hook.\n"
		. "\t *\n"
		. "\t * @since 7.2.0\n"
		. "\t */\n"
		. "\tpublic function register(): void {\n";
	foreach ( $prepared as $line ) {
		$registerFn .= "\t\t" . reindent( $line, 0 ) . "\n";
	}
	if ( null !== $guard ) {
		$registerFn .= "\t\tif ( " . $guard . " ) {\n"
			. "\t\t\treturn;\n"
			. "\t\t}\n\n";
	}
	$registerFn .= "\t\t\$this->" . $regMethod . "();\n";
	if ( array() !== $prof['register_note'] ) {
		$registerFn .= "\n\t\t/*\n";
		foreach ( $prof['register_note'] as $line ) {
			$registerFn .= "\t\t * " . $line . "\n";
		}
		$registerFn .= "\t\t */\n";
	}
	$registerFn .= "\t}\n";

	/* --- verbatim constants, properties and methods, with helper re-inline -- */
	$pending = $prof['helpers'];
	$helpTexts = helper_texts( $absText );

	$placeHelpers = static function ( string $anchor ) use ( &$methodChunks, &$pending, $helpTexts ) {
		while ( true ) {
			$placed = false;
			foreach ( INLINE_HELPERS as $h ) {
				if ( isset( $pending[ $h ] ) && $pending[ $h ] === $anchor ) {
					$methodChunks[] = "\t" . $helpTexts[ $h ] . "\n";
					unset( $pending[ $h ] );
					$anchor = $h;
					$placed = true;
				}
			}
			if ( ! $placed ) {
				break;
			}
		}
	};

	$constChunk = '';
	$propChunk = '';
	$methodChunks = array();
	foreach ( $members as $rec ) {
		$name = $rec['name'];
		if ( 'const' === $rec['kind'] ) {
			$constChunk .= "\t" . member_text( $tokens, $rec ) . "\n";
			continue;
		}
		if ( 'prop' === $rec['kind'] ) {
			$propChunk .= "\n\t" . member_text( $tokens, $rec ) . "\n";
			continue;
		}
		if ( in_array( $name, AUTHORING_METHODS, true ) || in_array( $name, OPTIONAL_METHODS, true ) ) {
			continue; // consumed by the registration rebuild
		}
		$methodChunks[] = "\t" . member_text( $tokens, $rec ) . "\n";
		$placeHelpers( $name );
	}
	if ( $pending !== array() ) {
		fail( 'helpers without anchor in package order: ' . implode( ', ', array_keys( $pending ) ) );
	}

	$body = $constChunk . $propChunk . "\n" . $registerFn . "\n" . $regCq . "\n" . implode( "\n", $methodChunks );

	$header = "<?php\n"
		. "/**\n"
		. ' * Abilities API: ' . $coreClass . " class.\n"
		. " *\n"
		. " * @package WordPress\n"
		. " * @subpackage Abilities API\n"
		. " * @since 7.2.0\n"
		. " */\n\n"
		. "declare( strict_types = 1 );\n\n"
		. 'final class ' . $coreClass . " {\n\n";

	return $header . $body . "}\n";
}

/* ---------------------------------------------------------------------
 * REVERSE transform
 * ------------------------------------------------------------------- */

/**
 * Extract the registration entries of the wp_register_ability(...) statement.
 *
 * @return array{string,array<string,string>} [ability name literal without quotes, entries key => expr text]
 */
function parse_registration( array $tokens, array $stmt ): array {
	list( $a, $b ) = $stmt;
	$f = stmt_start( $tokens, $stmt );
	'wp_register_ability' === $tokens[ $f ][1] || fail( 'not a wp_register_ability() call' );
	$paren = next_meaningful_token( $tokens, $f + 1, $b );
	$paren !== null && 40 === $tokens[ $paren ][0] || fail( 'wp_register_ability( missing ( at line ' . $tokens[ $f ][2] );
	$arg1 = next_meaningful_token( $tokens, $paren + 1, $b );
	$arg1 !== null || fail( 'wp_register_ability() first argument missing' );
	$abname = unquote( $tokens[ $arg1 ][1] );
	$comma = next_meaningful_token( $tokens, $arg1 + 1, $b );
	$comma !== null && 44 === $tokens[ $comma ][0] || fail( 'wp_register_ability() entries not a comma-separated array' );
	$arrTok = next_meaningful_token( $tokens, $comma + 1, $b );
	$arrTok !== null && T_ARRAY === $tokens[ $arrTok ][0] || fail( 'registration entries must be array( ... )' );
	$paren2 = next_meaningful_token( $tokens, $arrTok + 1, $b );
	$paren2 !== null && 40 === $tokens[ $paren2 ][0] || fail( 'array( without ( in registration' );
	$close = match_group( $tokens, $paren2 );

	$entries = array();
	$i = $paren2 + 1;
	while ( $i < $close ) {
		$f2 = next_meaningful_token( $tokens, $i, $close );
		if ( null === $f2 ) {
			break;
		}
		if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $f2 ][0] ) {
			break; // end of entries: trailing comma precedes the array close
		}
		$key = unquote( $tokens[ $f2 ][1] );
		$arrow = next_meaningful_token( $tokens, $f2 + 1, $close );
		$arrow !== null && T_DOUBLE_ARROW === $tokens[ $arrow ][0] || fail( "entry '$key' missing =>" );
		$d = 0;
		$vend = null;
		$found = false;
		for ( $j = $arrow + 1; $j <= $close; $j++ ) {
			$d += struct_delta( $tokens[ $j ] );
			if ( 0 === $d && 44 === $tokens[ $j ][0] ) {
				$vend = $j - 1;
				$found = true;
				break;
			}
			if ( $j === $close ) {
				$vend = $j - 1;
				$found = true;
			}
		}
		if ( ! $found || null === $vend || $vend < $arrow + 1 ) {
			fail( "entry '$key' without value" );
		}
		$entries[ $key ] = span_trim( $tokens, $arrow + 1, $vend );
		$i = $vend + 2; // skip ','
	}
	return array( $abname, $entries );
}

/** Build the manifest entry skeleton print (reverse mode). */
function build_manifest( string $abname ): string {
	$prof = profile( $abname );
	list( $dir, $pkgClass ) = slug_parts( $abname );
	$requires = array();
	foreach ( $prof['requires'] as $feature ) {
		$requires[] = "'" . $feature . "'";
	}
	$lines = array(
		'=== Manifest entry skeleton (human-verify before applying to src/Manifest.php) ===',
		"'" . $abname . "' => array(",
		"\t'name'           => '" . $abname . "',",
		"\t'class'          => '" . $dir . '\\' . $pkgClass . "',",
		"\t'category'       => '" . $prof['category'] . "',",
		"\t'tier'           => 'core',",
		"\t'min_wp_version' => '6.9',",
		"\t'core_since'     => '7.2',",
		"\t'requires'       => array( " . implode( ', ', $requires ) . ' ),',
		"\t'renamed_from'   => null,",
		"\t'proposal'       => '" . $prof['proposal'] . "',",
		"),",
		'',
		'Note: tier=core, core_since=7.2 per settled design; category, requires and',
		'proposal come from the coreify profile and must match src/Manifest.php.',
	);
	return implode( "\n", $lines );
}

/**
 * The core form's registration method: the register_*() member holding the
 * wp_register_ability(...) statement.
 *
 * @return array{array<string,mixed>,list<array{int,int}>,int} [member record, its statements, registration statement index]
 */
function core_registration_method( array $tokens, array $members ): array {
	foreach ( $members as $rec ) {
		if ( 'function' !== $rec['kind'] || 'register' === $rec['name'] || 0 !== strpos( $rec['name'], 'register_' ) || null === $rec['open'] ) {
			continue;
		}
		$stmts = body_statements( $tokens, $rec['open'], $rec['close'] );
		foreach ( $stmts as $k => $st ) {
			$f = stmt_start( $tokens, $st );
			if ( T_STRING === $tokens[ $f ][0] && 'wp_register_ability' === $tokens[ $f ][1] ) {
				return array( $rec, $stmts, $k );
			}
		}
	}
	fail( 'no register_*() method with a wp_register_ability(...) statement in core form' );
}

/**
 * Parse core's register(): the state assignments before an optional void
 * early-return guard, then the single call into the registration method.
 *
 * @return array{list<string>,?string} [prepare() statement texts, guard condition or null]
 */
function parse_core_register( array $tokens, array $rec, string $regMethod ): array {
	$prepared = array();
	$guard = null;
	$called = false;
	foreach ( body_statements( $tokens, $rec['open'], $rec['close'] ) as $st ) {
		$f = stmt_start( $tokens, $st );
		$id = $tokens[ $f ][0];
		if ( $called ) {
			fail( 'unexpected statement after $this->' . $regMethod . '() in register()' );
		}
		if ( T_IF === $id ) {
			null === $guard || fail( 'expected at most one early-return guard in register()' );
			$paren = next_meaningful_token( $tokens, $f + 1, $st[1] );
			$paren !== null && 40 === $tokens[ $paren ][0] || fail( 'register() guard without a condition' );
			$pclose = match_group( $tokens, $paren );
			$brace = next_meaningful_token( $tokens, $pclose + 1, $st[1] );
			$brace !== null && 123 === $tokens[ $brace ][0] || fail( 'register() guard without a block' );
			$body = array();
			foreach ( body_statements( $tokens, $brace, match_group( $tokens, $brace ) ) as $inner ) {
				$body[] = stmt_text( $tokens, $inner );
			}
			array( 'return;' ) === $body || fail( 'register() guard must only `return;`' );
			$guard = span_trim( $tokens, $paren + 1, $pclose - 1 );
			continue;
		}
		if ( T_VARIABLE === $id && '$this' === $tokens[ $f ][1] && '$this->' . $regMethod . '();' === preg_replace( '/\s+/', '', stmt_text( $tokens, $st ) ) ) {
			$called = true;
			continue;
		}
		if ( T_VARIABLE === $id && null === $guard ) {
			$prepared[] = stmt_text( $tokens, $st );
			continue;
		}
		fail( 'unexpected statement in register(): ' . token_repr( $tokens[ $f ] ) );
	}
	$called || fail( 'register() never calls $this->' . $regMethod . '()' );
	return array( $prepared, $guard );
}

/** Negate a guard condition for should_register(): `! cond`, parenthesized unless a single call. */
function negate_condition( string $cond ): string {
	$tokens = tokenize_source( '<?php ' . $cond, false );
	$first = next_meaningful_token( $tokens, 1 );
	$last = prev_meaningful_token( $tokens, 0, count( $tokens ) - 1 );
	$paren = null === $first ? null : next_meaningful_token( $tokens, $first + 1 );
	$isCall = null !== $paren
		&& in_array( $tokens[ $first ][0], array( T_STRING, T_EMPTY, T_ISSET ), true )
		&& 40 === $tokens[ $paren ][0]
		&& match_group( $tokens, $paren ) === $last;
	return $isCall ? '! ' . $cond : '! ( ' . $cond . ' )';
}

/**
 * Reverse transform: core class text -> package class text.
 */
function transform_reverse( string $coreText ): string {
	$tokens = tokenize_source( $coreText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$by = index_by_name( $members );

	list( $cqRec, $stmts, $regIdx ) = core_registration_method( $tokens, $members );
	list( $abname, $entries ) = parse_registration( $tokens, $stmts[ $regIdx ] );
	$prof = profile( $abname );
	list( $dir, $pkgClass, $coreClass, $regMethod ) = slug_parts( $abname );
	if ( $cls['name'] !== $coreClass ) {
		fail( 'core class name ' . $cls['name'] . ' does not match derived ' . $coreClass . ' for ' . $abname );
	}
	$cqRec['name'] === $regMethod || fail( 'registration method ' . $cqRec['name'] . '() does not match derived ' . $regMethod . '()' );
	isset( $by['register'] ) || fail( 'no register() in core form' );
	list( $prepared, $guard ) = parse_core_register( $tokens, $by['register'], $regMethod );

	/* prologue assignments before wp_register_ability(...), splice dropped */
	$guardCount = 0;
	$assigns = array();
	for ( $k = 0; $k < $regIdx; $k++ ) {
		$f = stmt_start( $tokens, $stmts[ $k ] );
		$id = $tokens[ $f ][0];
		if ( T_IF === $id ) {
			$guardCount++;
			continue;
		}
		if ( T_VARIABLE === $id ) {
			$assigns[] = $stmts[ $k ];
			continue;
		}
		fail( 'unexpected statement before wp_register_ability(): ' . token_repr( $tokens[ $f ] ) );
	}
	$expectGuards = null === $prof['splice_after'] ? 0 : 1;
	$expectGuards === $guardCount || fail( 'expected ' . $expectGuards . ' void early-return guard(s) in ' . $regMethod . '(), found ' . $guardCount );

	/* helper drop with body-equality guard */
	$absPath = default_abstract_path();
	$base = helper_bodies( read_file( $absPath ) );

	/* authoring method bodies */
	$authors = array();
	$types = AUTHORING_TYPES;

	$nameLit = "'" . $abname . "'";
	$authors['ability_name'] = 'return ' . $nameLit . ';';
	$mkret = static function ( string $entry ) use ( $entries ): string {
		isset( $entries[ $entry ] ) || fail( 'registration entry missing: ' . $entry );
		return 'return ' . reindent( $entries[ $entry ], -2 ) . ';';
	};
	$authors['label'] = $mkret( 'label' );
	$authors['description'] = $mkret( 'description' );
	$authors['category_slug'] = $mkret( 'category' );
	$authors['output_schema'] = $mkret( 'output_schema' );
	$authors['make_execute_callback'] = $mkret( 'execute_callback' );
	if ( isset( $entries['permission_callback'] ) ) {
		$authors['make_permission_callback'] = $mkret( 'permission_callback' );
	} else {
		// Core omits the callback when the required capabilities own authorization.
		isset( $entries['required_capabilities'] ) || fail( 'registration has neither permission_callback nor required_capabilities' );
		$authors['make_permission_callback'] = 'return null;';
		$types['make_permission_callback'] = '?callable';
	}
	$authors['ability_meta'] = $mkret( 'meta' );

	$rc = 'return array();';
	if ( isset( $entries['required_capabilities'] ) ) {
		$rc = 'return ' . reindent( $entries['required_capabilities'], -2 ) . ';';
	}
	$authors['required_capabilities'] = $rc;

	$prologue = '';
	foreach ( $assigns as $st ) {
		$prologue .= "\t\t" . stmt_text( $tokens, $st ) . "\n\n";
	}
	$authors['input_schema'] = $prologue . "\t\t" . 'return ' . reindent( $entries['input_schema'] ?? '', -2 ) . ';' . "\n";

	/* optional authoring methods from core's register() */
	$optional = array();
	if ( array() !== $prepared ) {
		$optional['prepare'] = array(
			'sig'  => 'protected function prepare(): void',
			'doc'  => 'Computes the state core computes in register() before registering.',
			'body' => "\t\t" . implode( "\n\t\t", $prepared ),
		);
	}
	if ( null !== $guard ) {
		$optional['should_register'] = array(
			'sig'  => 'public function should_register(): bool',
			'doc'  => 'Whether core registers the ability, the negation of its register() early return.',
			'body' => "\t\treturn " . negate_condition( $guard ) . ';',
		);
	}

	/* assemble */
	$out = "<?php\n"
		. "/**\n"
		. ' * Canonical WordPress abilities: `' . $abname . "` ability (tier core).\n"
		. " *\n"
		. ' * Mechanical port of wp-includes/abilities/class-' . str_replace( '_', '-', strtolower( $coreClass ) ) . ".php,\n"
		. " * generated by tools/coreify.php --reverse; hand-finish header docblock.\n"
		. " *\n"
		. " * Deviations from trunk (authoring surface only): the registration entry\n"
		. " * points are decomposed into Abstract_Core_Ability authoring methods;\n"
		. " * any of parse_filter_int(), parse_list_input() and get_input_object()\n"
		. " * core declares are inherited from Abstract_Core_Ability instead.\n"
		. " */\n\n"
		. "declare( strict_types = 1 );\n\n"
		. 'namespace WordPress\\Abilities\\' . $dir . ";\n\n";
	foreach ( $prof['imports'] as $imp ) {
		$out .= 'use ' . $imp . ";\n";
	}
	$out .= ( array() === $prof['imports'] ? '' : "\n" ) . "use WordPress\\Abilities\\Abstract_Core_Ability;\n\n";
	$out .= '/**' . "\n"
		. ' * Canonical WordPress abilities: `' . $abname . "` ability.\n"
		. " *\n"
		. " * @generated by tools/coreify.php --reverse. Hand-finish this docblock.\n"
		. " */\n"
		. 'final class ' . $pkgClass . " extends Abstract_Core_Ability {\n\n";

	$sections = array();
	$constSections = array();
	$propSections = array();
	foreach ( $members as $rec ) {
		$name = $rec['name'];
		if ( 'const' === $rec['kind'] ) {
			$constSections[] = "\t" . member_text( $tokens, $rec ) . "\n";
			continue;
		}
		if ( 'prop' === $rec['kind'] ) {
			$propSections[] = "\t" . member_text( $tokens, $rec ) . "\n";
			continue;
		}
		if ( 'register' === $name || $regMethod === $name ) {
			continue; // consumed by the authoring rebuild
		}
		if ( in_array( $name, INLINE_HELPERS, true ) ) {
			/* body-equality guard: only drop when identical to the base-class helper */
			$mismatch = null;
			for ( $i = $rec['open'] + 1; $i <= $rec['close'] - 1; $i++ ) {
				$t = $tokens[ $i ];
				if ( is_skipped( $t ) ) {
					continue;
				}
				$mismatch[] = array( $t[0], $t[1] );
			}
			if ( $mismatch !== $base[ $name ] ) {
				fail( 'core helper ' . $name . ' differs from Abstract_Core_Ability; keep it or refresh the abstract class' );
			}
			continue; // drop
		}
		$sections[] = "\t" . member_text( $tokens, $rec ) . "\n";
	}

	/* authoring methods, in fixed order, after constants and properties */
	$authorChunks = array();
	foreach ( AUTHORING_METHODS as $m ) {
		$sig = $types[ $m ];
		$lead = ( "\t\t" === substr( $authors[ $m ], 0, 2 ) ) ? '' : "\t\t";
		$authorChunks[] = "\t/**\n\t * " . AUTHORING_DOCS[ $m ] . "\n\t */\n"
			. "\tprotected function " . $m . '(): ' . $sig . " {\n"
			. $lead . $authors[ $m ] . "\n"
			. "\t}\n";
	}
	foreach ( OPTIONAL_METHODS as $m ) {
		if ( ! isset( $optional[ $m ] ) ) {
			continue;
		}
		$authorChunks[] = "\t/**\n\t * " . $optional[ $m ]['doc'] . "\n\t */\n"
			. "\t" . $optional[ $m ]['sig'] . " {\n"
			. $optional[ $m ]['body'] . "\n"
			. "\t}\n";
	}

	$out .= implode( "\n", $constSections ) . implode( "\n", $propSections ) . implode( "\n", $authorChunks ) . "\n" . implode( "\n", $sections ) . "}\n";

	return $out;
}

/* ---------------------------------------------------------------------
 * CLI
 * ------------------------------------------------------------------- */

function default_root(): string {
	return dirname( __DIR__ );
}

function default_abstract_path(): string {
	return default_root() . '/src/Abstract_Core_Ability.php';
}

function usage(): void {
	echo <<<'TXT'
coreify.php — mechanical package<->core token transform for wordpress/abilities.

Usage:
  php coreify.php [--ability <name>] [--in <path>] [--out <path>]
  php coreify.php --reverse [--ability <name>] [--in <path>] [--out <path>]
  php coreify.php --check [--ability <name>] [--in <path>] [--pkg <path>] [--abs <path>]
  php coreify.php --help

Abilities with a transform profile: core/content-query, core/settings-get.

Modes:
  (default)  forward: package -> core form. Input defaults to the ability's
             package file (src/Content/Content_Query.php for the default
             core/content-query). Writes the fixture-shaped class to
             tools/out/class-wp-abilities-<area>.php (unless --out).
  --reverse  core -> package form (+ manifest entry skeleton on stdout).
             Input defaults to the ability's trunk fixture under
             tests/Fixtures/abilities-api/abilities/. Writes
             tools/out/<Area>/<Class>.php (unless --out).
  --check    acceptance gate: reverse(fixture) -> forward() -> semantic token
             diff vs the ORIGINAL fixture (hard gate, prints first mismatch).
             Also diffs the reverse output vs the hand-port package file.
             Runs every profiled ability unless --ability is given. Exits
             non-zero on divergence.

Options:
  --ability <name>  the profiled ability whose default paths to use
                    (default core/content-query; --check: all)
  --in <path>       input file (per-mode default as above; the same path serves
                    as the fixture input for --check and reverse)
  --out <path>      output file (default tools/out/...)
  --abs <path>      path to src/Abstract_Core_Ability.php (default derived from
                    the repo root)
  --pkg <path>      --check only: package reference file for the second diff
                    (default the ability's package file)
  --help            this help

Exit codes: 0 success; 1 transform/semantic-diff failure; 2 usage error.
TXT;
}

function parse_args( array $argv ): array {
	$o = array(
		'mode' => 'forward',
		'ability' => null,
		'in' => null,
		'out' => null,
		'abs' => null,
		'pkg' => null,
		'help' => false,
	);
	$n = count( $argv );
	for ( $i = 1; $i < $n; $i++ ) {
		$arg = $argv[ $i ];
		switch ( true ) {
			case $arg === '--reverse':
				$o['mode'] = 'reverse';
				break;
			case $arg === '--check':
				$o['mode'] = 'check';
				break;
			case $arg === '--help':
			case $arg === '-h':
				$o['help'] = true;
				break;
			case $arg === '--ability':
				$o['ability'] = $argv[ ++$i ] ?? fail( '--ability requires a name' );
				profile( $o['ability'] );
				break;
			case $arg === '--in':
				$o['in'] = $argv[ ++$i ] ?? fail( '--in requires a path' );
				break;
			case $arg === '--out':
				$o['out'] = $argv[ ++$i ] ?? fail( '--out requires a path' );
				break;
			case $arg === '--abs':
				$o['abs'] = $argv[ ++$i ] ?? fail( '--abs requires a path' );
				break;
			case $arg === '--pkg':
				$o['pkg'] = $argv[ ++$i ] ?? fail( '--pkg requires a path' );
				break;
			default:
				fail( 'unknown argument: ' . $arg . ' (try --help)' );
		}
	}
	return $o;
}

function read_file( string $path ): string {
	$text = file_get_contents( $path );
	if ( false === $text ) {
		fail( 'cannot read: ' . $path );
	}
	return $text;
}

function write_out( string $path, string $text ): void {
	$dir = dirname( $path );
	if ( '' !== $dir && ! is_dir( $dir ) ) {
		if ( ! @mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			fail( 'cannot create directory: ' . $dir );
		}
	}
	if ( false === file_put_contents( $path, $text ) ) {
		fail( 'cannot write: ' . $path );
	}
	echo '[coreify] wrote ' . $path . " (" . strlen( $text ) . " bytes)\n";
}

/** Default tools/out paths of an ability: [reverse (package form), forward (core form)]. */
function out_paths( string $root, string $abname ): array {
	list( $dir, $pkgClass, $coreClass ) = slug_parts( $abname );
	return array(
		$root . '/tools/out/' . $dir . '/' . $pkgClass . '.php',
		$root . '/tools/out/class-' . str_replace( '_', '-', strtolower( $coreClass ) ) . '.php',
	);
}

/** Round-trip check of one fixture/package pair. */
function check_pair( string $root, string $in, string $pkgPath, string $absText ): bool {
	$fixture = read_file( $in );
	$handport = read_file( $pkgPath );
	list( $revOut, $fwdOut ) = out_paths( $root, reverse_ability_name( $fixture ) );

	$pkgText = transform_reverse( $fixture );
	write_out( $revOut, $pkgText );
	$coreText = transform_forward( $pkgText, $absText );
	write_out( $fwdOut, $coreText );

	$ok = true;
	$mA = semdiff( $coreText, $fixture );
	if ( null !== $mA ) {
		$ok = false;
		fwrite( STDERR, "[check] FAIL: round-trip forward output vs ORIGINAL fixture\n" );
		print_mismatch( 'roundtrip-vs-fixture', $mA );
	} else {
		echo "[check] PASS: round-trip forward output vs ORIGINAL fixture (0 semantic differences)\n";
	}
	$mB = semdiff( $pkgText, $handport );
	if ( null !== $mB ) {
		$ok = false;
		fwrite( STDERR, "[check] FAIL: reverse output vs hand-port package file\n" );
		print_mismatch( 'reverse-vs-handport', $mB );
	} else {
		echo "[check] PASS: reverse output vs hand-port package file (0 semantic differences)\n";
	}
	return $ok;
}

function run( array $o ): int {
	$root = default_root();
	$absPath = $o['abs'] ?? default_abstract_path();
	$abname = $o['ability'] ?? DEFAULT_ABILITY;
	$prof = profile( $abname );

	if ( 'forward' === $o['mode'] ) {
		$in = $o['in'] ?? $root . '/' . $prof['package'];
		$result = transform_forward( read_file( $in ), read_file( $absPath ) );
		$out = $o['out'] ?? out_paths( $root, $abname )[1];
		write_out( $out, $result );
		echo "[coreify] forward OK: {$in} -> {$out}\n";
		return 0;
	}

	if ( 'reverse' === $o['mode'] ) {
		$in = $o['in'] ?? $root . '/' . $prof['fixture'];
		$coreText = read_file( $in );
		$result = transform_reverse( $coreText );
		$abname = reverse_ability_name( $coreText );
		$out = $o['out'] ?? out_paths( $root, $abname )[0];
		write_out( $out, $result );
		echo "[coreify] reverse OK: {$in} -> {$out}\n";
		echo build_manifest( $abname ) . "\n";
		return 0;
	}

	/* --- check -------------------------------------------------------------- */
	$absText = read_file( $absPath );
	$names = ( null === $o['ability'] && null === $o['in'] && null === $o['pkg'] ) ? array_keys( PROFILES ) : array( $abname );
	$ok = true;
	foreach ( $names as $name ) {
		$p = profile( $name );
		echo "[check] {$name}\n";
		$ok = check_pair( $root, $o['in'] ?? $root . '/' . $p['fixture'], $o['pkg'] ?? $root . '/' . $p['package'], $absText ) && $ok;
	}
	if ( ! $ok ) {
		return 1;
	}
	echo "[check] OK: round-trip invariant holds.\n";
	return 0;
}

/** Ability name alone (for default out paths). */
function reverse_ability_name( string $coreText ): string {
	$tokens = tokenize_source( $coreText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	list( , $stmts, $idx ) = core_registration_method( $tokens, $members );
	list( $abname ) = parse_registration( $tokens, $stmts[ $idx ] );
	return $abname;
}

/* ---- main ---- */
$o = parse_args( $argv );
if ( $o['help'] ) {
	usage();
	exit( 0 );
}
try {
	exit( run( $o ) );
} catch ( CoreifyError $e ) {
	fwrite( STDERR, 'coreify: ' . $e->getMessage() . "\n" );
	exit( 1 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'coreify: unexpected ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" );
	exit( 1 );
}