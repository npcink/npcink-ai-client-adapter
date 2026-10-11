<?php
/**
 * Guards consumer-facing documentation against REST route-surface drift.
 *
 * Parses every register_rest_route() call under includes/ into normalized
 * method + path patterns, then verifies that:
 *
 * 1. every Adapter route mentioned in README.md, readme.txt, or docs/*.md
 *    (docs/archive/ snapshots excluded) resolves to a registered route,
 *    unless the mention or its directly preceding lines explicitly describe
 *    an absent, removed, or unregistered route; and
 * 2. every hand-maintained "METHOD /path" string literal in
 *    includes/Rest/Controller.php (the GET /help catalog and guidance
 *    strings) resolves to a registered route.
 *
 * This gate exists because two releases shipped consumer docs describing
 * routes that had already been removed from the code. Route-removal PRs must
 * update the consumer docs in the same change; when a doc intentionally
 * mentions an absent route, keep the explicit "not registered" /
 * "unregistered" / "rest_no_route" wording nearby so the gate can tell it
 * apart from a stale reference.
 *
 * @package NpcinkOpenClawAdapter
 */

$root       = dirname( __DIR__ );
$failures   = array();
$controller = $root . '/includes/Rest/Controller.php';

/**
 * Records a drift failure.
 *
 * @param string $message Failure message with file:line context.
 * @return void
 */
function npcink_adapter_route_drift_fail( string $message ): void {
	global $failures;
	$failures[] = $message;
}

/**
 * Reads a file into a string, or '' when unreadable.
 *
 * @param string $path Absolute file path.
 * @return string
 */
function npcink_adapter_route_drift_read( string $path ): string {
	$contents = is_readable( $path ) ? file_get_contents( $path ) : false;
	return is_string( $contents ) ? $contents : '';
}

/**
 * Maps a WP_REST_Server method constant to an HTTP method name.
 *
 * @param string $constant Method constant name such as READABLE.
 * @return string HTTP method such as GET, or '' when unknown.
 */
function npcink_adapter_route_drift_method( string $constant ): string {
	$map = array(
		'READABLE'  => 'GET',
		'CREATABLE' => 'POST',
		'DELETABLE' => 'DELETE',
		'EDITABLE'  => 'POST',
	);

	return isset( $map[ $constant ] ) ? $map[ $constant ] : '';
}

/**
 * Collects registered routes as HTTP method => list of segment patterns.
 *
 * The file is split at each register_rest_route( call so multi-method
 * registrations (one path with nested GET and POST handler arrays) contribute
 * every declared method. Regex route params like (?P<key_id>...) are
 * normalized to a `*` segment.
 *
 * @param string $controller Path to includes/Rest/Controller.php.
 * @return array<string,array<int,array<int,string>>>
 */
function npcink_adapter_route_drift_registered( string $controller ): array {
	$contents = npcink_adapter_route_drift_read( $controller );
	$routes   = array();

	foreach ( array_slice( explode( 'register_rest_route(', $contents ), 1 ) as $chunk ) {
		if ( ! preg_match( "#\\s*self::NAMESPACE,\\s*'([^']+)'#", $chunk, $path_match ) ) {
			continue;
		}

		if ( ! preg_match_all( "/'methods'\\s*=>\\s*WP_REST_Server::([A-Z]+)/", $chunk, $constant_matches ) ) {
			npcink_adapter_route_drift_fail( 'No HTTP method detected for registered route: ' . $path_match[1] );
			continue;
		}

		$verbs = array();
		foreach ( $constant_matches[1] as $constant ) {
			$http_verb = npcink_adapter_route_drift_method( $constant );
			if ( '' === $http_verb ) {
				npcink_adapter_route_drift_fail( 'Unknown WP_REST_Server method constant ' . $constant . ' for registered route: ' . $path_match[1] );
				continue;
			}
			$verbs[ $http_verb ] = true;
		}

		if ( array() === $verbs ) {
			continue;
		}

		$normalized = preg_replace( '#\\(\\?P<[^>]+>[^)]*\\)#', '*', $path_match[1] );
		$normalized = trim( (string) $normalized, '/' );
		$segments   = array_values( array_filter( explode( '/', $normalized ), 'strlen' ) );

		foreach ( array_keys( $verbs ) as $http_verb ) {
			$routes[ $http_verb ][] = $segments;
		}
	}

	return $routes;
}

/**
 * Checks whether documented segments match a registered segment pattern.
 *
 * @param array<int,string> $doc_segments     Documented path segments ({x} and ALL_CAPS placeholders mapped to `*`).
 * @param array<int,string> $pattern_segments Registered path segments (`*` for params).
 * @return bool True when the segments match.
 */
function npcink_adapter_route_drift_matches( array $doc_segments, array $pattern_segments ): bool {
	if ( count( $doc_segments ) !== count( $pattern_segments ) ) {
		return false;
	}

	foreach ( $pattern_segments as $index => $segment ) {
		if ( '*' === $segment ) {
			continue;
		}
		if ( $segment !== $doc_segments[ $index ] ) {
			return false;
		}
	}

	return true;
}

/**
 * Returns true when segments match any of the given patterns.
 *
 * @param array<int,string>               $segments Documented path segments.
 * @param array<int,array<int,string>>    $patterns Registered patterns.
 * @return bool
 */
function npcink_adapter_route_drift_matches_any( array $segments, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		if ( npcink_adapter_route_drift_matches( $segments, $pattern ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns true when a documented route exists with any method.
 *
 * @param array<int,string>                     $doc_segments Documented path segments.
 * @param array<string,array<int,array<int,string>>> $registered Registered routes.
 * @return bool
 */
function npcink_adapter_route_drift_path_exists( array $doc_segments, array $registered ): bool {
	foreach ( $registered as $patterns ) {
		if ( npcink_adapter_route_drift_matches_any( $doc_segments, $patterns ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Converts a documented path into normalized segments.
 *
 * @param string $path Documented path such as /proposals/{proposal_id}/execute.
 * @return array<int,string>
 */
function npcink_adapter_route_drift_doc_segments( string $path ): array {
	$path     = trim( $path, '/' );
	$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );

	foreach ( $segments as $index => $segment ) {
		if ( preg_match( '/^\{.*\}$/', $segment ) || preg_match( '/^[A-Z][A-Z0-9_]*$/', $segment ) ) {
			$segments[ $index ] = '*';
		}
	}

	return $segments;
}

/**
 * Strips an optional /wp-json/<namespace>/ prefix from a mentioned path.
 *
 * @param string $path             Documented path, possibly namespace-prefixed.
 * @param string $rest_namespace   REST namespace such as npcink-openclaw-adapter/v1.
 * @return string Path relative to the namespace.
 */
function npcink_adapter_route_drift_strip_namespace( string $path, string $rest_namespace ): string {
	$marker   = '/' . $rest_namespace . '/';
	$position = strpos( $path, $marker );
	if ( false !== $position ) {
		return substr( $path, $position + strlen( $marker ) - 1 );
	}
	return $path;
}

$registered = npcink_adapter_route_drift_registered( $controller );

if ( array() === $registered ) {
	fwrite( STDERR, "[fail] No register_rest_route() calls parsed from includes/Rest/Controller.php; the drift gate cannot run.\n" );
	exit( 1 );
}

if ( ! preg_match( "/const NAMESPACE\\s*=\\s*'([^']+)';/", npcink_adapter_route_drift_read( $controller ), $namespace_match ) ) {
	fwrite( STDERR, "[fail] Could not parse the NAMESPACE constant from includes/Rest/Controller.php.\n" );
	exit( 1 );
}

$namespace = $namespace_match[1];
$top_level = array();
foreach ( $registered as $patterns ) {
	foreach ( $patterns as $pattern ) {
		if ( isset( $pattern[0] ) ) {
			$top_level[ $pattern[0] ] = true;
		}
	}
}

// Gate 1: the hand-maintained "METHOD /path" catalog labels inside the two
// help builder methods must stay registered.
$controller_contents = npcink_adapter_route_drift_read( $controller );
$help_catalog        = '';
foreach ( array( 'help_route_groups', 'help_route_purpose' ) as $help_method ) {
	$start = strpos( $controller_contents, 'private function ' . $help_method );
	if ( false === $start ) {
		npcink_adapter_route_drift_fail( 'includes/Rest/Controller.php: missing ' . $help_method . '(); the help catalog cannot be gated.' );
		continue;
	}
	$end           = strpos( $controller_contents, "\n\tprivate function", $start + 10 );
	$end_public    = strpos( $controller_contents, "\n\tpublic function", $start + 10 );
	$candidates    = array_filter( array( $end, $end_public ), 'is_int' );
	$help_catalog .= substr( $controller_contents, $start, ( $candidates ? min( $candidates ) : strlen( $controller_contents ) ) - $start );
}

if ( preg_match_all( "#'(GET|POST|DELETE)\\s+(/[A-Za-z0-9_{}./-]+)'#", $help_catalog, $label_matches, PREG_SET_ORDER ) ) {
	$seen_labels = array();
	foreach ( $label_matches as $label ) {
		$method    = $label[1];
		$path      = trim( $label[2], '/' );
		$cache_key = $method . ' /' . $path;

		if ( isset( $seen_labels[ $cache_key ] ) ) {
			continue;
		}
		$seen_labels[ $cache_key ] = true;

		$segments = npcink_adapter_route_drift_doc_segments( $label[2] );

		if ( ! isset( $registered[ $method ] ) || ! npcink_adapter_route_drift_matches_any( $segments, $registered[ $method ] ) ) {
			npcink_adapter_route_drift_fail( 'includes/Rest/Controller.php: GET /help catalog label `' . $cache_key . '` does not match a registered route.' );
		}
	}
}

// Gate 2: consumer docs must not reference unregistered routes.
$doc_files = array( $root . '/README.md', $root . '/readme.txt' );
$docs_dir  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/docs', FilesystemIterator::SKIP_DOTS ) );
foreach ( $docs_dir as $doc_file ) {
	$path_name = $doc_file->getPathname();
	if ( '.md' !== substr( $path_name, -3 ) ) {
		continue;
	}
	if ( false !== strpos( $path_name, '/archive/' ) ) {
		continue;
	}
	$doc_files[] = $path_name;
}

$absent_cues = '#(?:no Adapter-owned|not\s+(?:being\s+)?registered|unregistered|does not expose|do not expose|must not expose|no standalone|rest_no_route|were removed|has been removed|shortcut routes such as|stale|Core\s+`)#i';

foreach ( $doc_files as $doc_file ) {
	$relative = str_replace( $root . '/', '', $doc_file );
	$lines    = explode( "\n", npcink_adapter_route_drift_read( $doc_file ) );
	$seen     = array();

	foreach ( $lines as $line_number => $line ) {
		// A mention counts as intentional when an absent-route cue appears
		// near it (same line or within five lines in either direction), so a
		// "not registered" sentence can introduce a fenced route list.
		$context_window = implode( "\n", array_slice( $lines, max( 0, $line_number - 5 ), 11 ) );
		if ( preg_match( $absent_cues, $line ) || preg_match( $absent_cues, $context_window ) ) {
			continue;
		}

		$mentions = array();

		if ( preg_match_all( '#' . preg_quote( $namespace, '#' ) . '(/[\w{}./-]+)#', $line, $url_matches ) ) {
			foreach ( $url_matches[1] as $path ) {
				// Namespace-anchored URLs are unambiguous Adapter routes.
				$mentions[] = array( '', $path, true );
			}
		}

		if ( preg_match_all( '#(?:GET|POST|PUT|PATCH|DELETE) (/[\w{}./-]+)#', $line, $method_matches, PREG_SET_ORDER ) ) {
			foreach ( $method_matches as $method_match ) {
				// "METHOD /path" mentions are unambiguous route references,
				// even when the path's first segment is not registered yet.
				$mentions[] = array( strtok( $method_match[0], ' ' ), $method_match[1], true );
			}
		}

		if ( preg_match_all( '#`(/[\w{}./-]+)`#', $line, $bare_matches ) ) {
			foreach ( $bare_matches[1] as $path ) {
				$mentions[] = array( '', $path, false );
			}
		}

		foreach ( $mentions as $mention ) {
			$method   = (string) $mention[0];
			$path     = npcink_adapter_route_drift_strip_namespace( $mention[1], $namespace );
			$strict   = (bool) $mention[2];
			$segments = npcink_adapter_route_drift_doc_segments( $path );

			if ( array() === $segments ) {
				continue;
			}

			// Foreign REST namespaces (Core, Abilities API, wp/v2) are outside
			// this gate; only Adapter-owned route mentions are checked.
			$foreign_prefixes = array( 'wp-json/', 'npcink-governance-core/', 'wp-abilities/', 'wp/v2/' );
			$trimmed_path     = ltrim( $path, '/' );
			foreach ( $foreign_prefixes as $foreign_prefix ) {
				if ( 0 === strpos( $trimmed_path, $foreign_prefix ) ) {
					continue 2;
				}
			}

			// Bare paths are only gated when their first segment is an
			// Adapter route family, so WordPress and repository paths do not
			// create noise. Strict mentions are always gated; this is what
			// catches removed top-level routes such as GET /active-plugins-detail.
			if ( ! $strict && ! isset( $top_level[ $segments[0] ] ) ) {
				continue;
			}

			$cache_key = $relative . ':' . ( $line_number + 1 ) . ':' . $method . ' ' . $path;
			if ( isset( $seen[ $cache_key ] ) ) {
				continue;
			}
			$seen[ $cache_key ] = true;

			if ( '' === $method ) {
				if ( npcink_adapter_route_drift_path_exists( $segments, $registered ) ) {
					continue;
				}
				npcink_adapter_route_drift_fail( $relative . ':' . ( $line_number + 1 ) . ': route `' . $path . '` does not match a registered Adapter route.' );
				continue;
			}

			if ( ! isset( $registered[ $method ] ) || ! npcink_adapter_route_drift_matches_any( $segments, $registered[ $method ] ) ) {
				npcink_adapter_route_drift_fail( $relative . ':' . ( $line_number + 1 ) . ': route `' . $method . ' ' . $path . '` does not match a registered Adapter route.' );
			}
		}
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '[fail] ' . $failure . PHP_EOL );
	}
	exit( 1 );
}

echo "Doc route drift: ok\n";
