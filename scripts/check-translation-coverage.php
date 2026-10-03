<?php
/**
 * Guards bundled locale coverage against the POT template before release.
 *
 * Every languages/*.po must translate every POT msgid (header msgid excluded)
 * with a non-empty, non-fuzzy msgstr, and every languages/*.mo must be at
 * least as fresh as its .po so the bundled binary translations cannot lag
 * behind a completed catalog.
 *
 * @package NpcinkOpenClawAdapter
 */

$root      = dirname( __DIR__ );
$languages = $root . '/languages';
$failures  = array();

function npcink_openclaw_adapter_i18n_fail( $message ) {
	global $failures;
	$failures[] = (string) $message;
}

/**
 * Parses gettext catalog entries into rows keyed by msgid.
 *
 * @param string $path Catalog path.
 * @return array<int,array{comment:string,msgid:string,msgstr:string,plural:bool}>
 */
function npcink_openclaw_adapter_i18n_entries( $path ) {
	$contents = is_readable( $path ) ? file_get_contents( $path ) : false;
	if ( ! is_string( $contents ) ) {
		return array();
	}

	$entries = array();
	$lines   = explode( "\n", $contents );
	$count   = count( $lines );
	$i       = 0;
	$comment = '';

	while ( $i < $count ) {
		$line = $lines[ $i ];

		if ( 0 === strpos( $line, '#' ) ) {
			$comment .= $line . "\n";
			$i++;
			continue;
		}

		$msgctxt = '';
		if ( 0 === strpos( $line, 'msgctxt ' ) ) {
			$msgctxt = npcink_openclaw_adapter_i18n_glue( $lines, $i, 'msgctxt' );
			$line    = $i < $count ? $lines[ $i ] : '';
		}

		if ( 0 !== strpos( $line, 'msgid ' ) ) {
			$comment = '';
			$i++;
			continue;
		}

		$msgid  = npcink_openclaw_adapter_i18n_glue( $lines, $i, 'msgid' );
		$msgid  = '' !== $msgctxt ? $msgctxt . "\x04" . $msgid : $msgid;
		$plural = $i < $count && 0 === strpos( $lines[ $i ], 'msgid_plural' );
		if ( $plural ) {
			npcink_openclaw_adapter_i18n_glue( $lines, $i, 'msgid_plural' );
		}

		$msgstr = '';
		if ( $i < $count && 0 === strpos( $lines[ $i ], 'msgstr' ) ) {
			$msgstr = npcink_openclaw_adapter_i18n_glue( $lines, $i, 'msgstr(?:\[\d+\])?' );
			while ( $i < $count && 0 === strpos( $lines[ $i ], 'msgstr[' ) ) {
				npcink_openclaw_adapter_i18n_glue( $lines, $i, 'msgstr(?:\[\d+\])?' );
			}
		}

		$entries[] = array(
			'comment' => $comment,
			'msgid'   => $msgid,
			'msgstr'  => $plural ? 'plural' : $msgstr,
			'plural'  => $plural,
		);
		$comment = '';
	}

	return $entries;
}

/**
 * Reads one gettext string (keyword line plus continuation lines), advancing the index.
 *
 * @param array<int,string> $lines          Catalog lines.
 * @param int               $i              Current line index, passed by reference.
 * @param string            $keyword_regex Keyword the first line must start with.
 * @return string
 */
function npcink_openclaw_adapter_i18n_glue( array $lines, &$i, $keyword_regex ) {
	$value   = '';
	$count   = count( $lines );
	$keyword = '/^' . $keyword_regex . '\s+"((?:[^"\\\\]|\\\\.)*)"/';
	while ( $i < $count ) {
		$matched = 1 === preg_match( $keyword, $lines[ $i ], $keyword_match )
			|| ( 0 === strpos( $lines[ $i ], '"' ) && 1 === preg_match( '/^"((?:[^"\\\\]|\\\\.)*)"/', $lines[ $i ], $keyword_match ) );
		if ( ! $matched ) {
			break;
		}
		$value .= $keyword_match[1];
		$i++;
	}
	return str_replace( array( '\\"', '\\n', '\\t', '\\\\' ), array( '"', "\n", "\t", '\\' ), $value );
}

$pot_path = $languages . '/npcink-ai-client-adapter.pot';
$pot      = npcink_openclaw_adapter_i18n_entries( $pot_path );
if ( array() === $pot ) {
	npcink_openclaw_adapter_i18n_fail( 'Missing or unreadable POT template: ' . $pot_path );
}

$template_ids = array();
foreach ( $pot as $entry ) {
	if ( '' !== $entry['msgid'] ) {
		$template_ids[ $entry['msgid'] ] = true;
	}
}

$po_files = glob( $languages . '/*.po' );
$po_files = is_array( $po_files ) ? $po_files : array();
if ( array() === $po_files ) {
	npcink_openclaw_adapter_i18n_fail( 'No bundled .po catalogs found under languages/.' );
}

foreach ( $po_files as $po_path ) {
	$relative_po = 'languages/' . basename( $po_path );
	$translated  = array();

	foreach ( npcink_openclaw_adapter_i18n_entries( $po_path ) as $entry ) {
		if ( '' === $entry['msgid'] || '' === $entry['msgstr'] ) {
			continue;
		}
		if ( false !== strpos( $entry['comment'], '#, fuzzy' ) ) {
			continue;
		}
		$translated[ $entry['msgid'] ] = true;
	}

	$missing = array();
	foreach ( array_keys( $template_ids ) as $msgid ) {
		if ( ! isset( $translated[ $msgid ] ) ) {
			$missing[] = $msgid;
		}
	}

	if ( ! empty( $missing ) ) {
		foreach ( $missing as $msgid ) {
			npcink_openclaw_adapter_i18n_fail(
				$relative_po . ' is missing a reviewed translation for: ' . substr( $msgid, 0, 80 )
			);
		}
	}

	$mo_path = substr( $po_path, 0, -3 ) . '.mo';
	if ( ! is_readable( $mo_path ) ) {
		npcink_openclaw_adapter_i18n_fail( $relative_po . ' has no compiled .mo catalog.' );
		continue;
	}

	if ( filemtime( $mo_path ) < filemtime( $po_path ) ) {
		npcink_openclaw_adapter_i18n_fail( $relative_po . ' is newer than its compiled .mo; recompile with msgfmt before release.' );
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '[fail] ' . $failure . PHP_EOL );
	}
	exit( 1 );
}

echo "Translation coverage guard: ok\n";
