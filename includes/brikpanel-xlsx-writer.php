<?php
/**
 * BrikPanel — Minimal XLSX Writer
 *
 * A tiny, dependency-free Office Open XML (.xlsx) writer. An .xlsx file is
 * just a ZIP of XML parts, so we assemble the handful of parts Excel /
 * Google Sheets require by hand — no PhpSpreadsheet, no Composer, no bloat.
 *
 * Why it exists: the dashboard report needs *several distinct tables*
 * (report header, KPI summary, order status, top products, the full order
 * list). Forced into one CSV they stack vertically with mismatched column
 * counts and look like a mess — and a comma-delimited CSV opens in a single
 * column under non-US spreadsheet locales. A real workbook puts each table
 * on its own tab, keeps numbers as real numbers, and opens cleanly
 * everywhere.
 *
 * Scope on purpose: inline strings + numeric cells, a small fixed style
 * palette (normal / bold / header / title / date), per-column widths and an
 * optional frozen header row. That's all the reports need.
 *
 * Two ways to fill a sheet:
 *   - add_sheet(): the rows in memory, for small reports;
 *   - add_sheet_file(): a sheet whose XML a caller wrote to a file a piece at
 *     a time (sheet_head_xml(), row_xml() per row, sheet_tail_xml()), for
 *     lists too long to hold in memory, such as the orders list export
 *     (front-end/orders/brikpanel-order-export.php). The file goes into the
 *     ZIP as it is, so building the workbook never holds it in memory.
 *
 * @package BrikPanel
 * @since   3.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Brikpanel_XLSX_Writer' ) ) :

class Brikpanel_XLSX_Writer {

	/** Style indices — map to <cellXfs> entries in styles.xml (order matters). */
	const S_NORMAL = 0;
	const S_BOLD   = 1;
	const S_HEADER = 2; // bold + light-grey fill — table column headers
	const S_TITLE  = 3; // larger bold — section titles
	const S_DATE   = 4; // date and time, shown in the reader's own regional format

	/** @var array<int,array{name:string,rows:array,cols:array,freeze:bool,file:string}> */
	private $sheets = [];

	/**
	 * Append a worksheet.
	 *
	 * @param string $name   Tab name (sanitised: ≤31 chars, no []*?:/\).
	 * @param array  $rows   List of rows. Each cell is either a scalar
	 *                        (string|int|float) or [ value, styleIndex ].
	 * @param array  $cols   Optional 1-based column widths, e.g. [1=>28, 2=>14].
	 * @param bool   $freeze Freeze the first row (set on the header row).
	 */
	public function add_sheet( $name, array $rows, array $cols = [], $freeze = false ) {
		$this->sheets[] = [
			'name'   => $this->clean_sheet_name( $name, count( $this->sheets ) + 1 ),
			'rows'   => $rows,
			'cols'   => $cols,
			'freeze' => (bool) $freeze,
			'file'   => '',
		];
	}

	/**
	 * Append a worksheet whose complete XML is already in a file: what
	 * sheet_head_xml(), row_xml() for each row and sheet_tail_xml() wrote.
	 *
	 * @param string $name Tab name.
	 * @param string $path Readable file holding the worksheet XML.
	 */
	public function add_sheet_file( $name, $path ) {
		$this->sheets[] = [
			'name'   => $this->clean_sheet_name( $name, count( $this->sheets ) + 1 ),
			'rows'   => [],
			'cols'   => [],
			'freeze' => false,
			'file'   => (string) $path,
		];
	}

	/**
	 * Build the workbook and return the raw .xlsx bytes, or false on failure.
	 *
	 * @return string|false
	 */
	public function build() {
		$tmp = wp_tempnam( 'brikpanel-xlsx' );
		if ( ! $tmp ) {
			return false;
		}

		if ( ! $this->build_to_file( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $bytes;
	}

	/**
	 * Build the workbook into $path (overwritten).
	 *
	 * @param string $path Destination file.
	 * @return bool
	 */
	public function build_to_file( $path ) {
		if ( ! class_exists( 'ZipArchive' ) || empty( $this->sheets ) ) {
			return false;
		}
		foreach ( $this->sheets as $sheet ) {
			if ( '' !== $sheet['file'] && ! is_readable( $sheet['file'] ) ) {
				return false;
			}
		}

		$zip = new ZipArchive();
		if ( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			return false;
		}

		$count = count( $this->sheets );

		$zip->addFromString( '[Content_Types].xml', $this->content_types( $count ) );
		$zip->addFromString( '_rels/.rels', $this->root_rels() );
		$zip->addFromString( 'xl/workbook.xml', $this->workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels( $count ) );
		$zip->addFromString( 'xl/styles.xml', $this->styles_xml() );

		foreach ( $this->sheets as $i => $sheet ) {
			$entry = 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml';
			if ( '' !== $sheet['file'] ) {
				// Read by ZipArchive when the archive is closed, a block at a
				// time: a sheet of 100,000 orders never sits in memory.
				$zip->addFile( $sheet['file'], $entry );
			} else {
				$zip->addFromString( $entry, $this->sheet_xml( $sheet ) );
			}
		}

		return $zip->close();
	}

	// =========================================================================
	// STREAMING PIECES (a sheet written to a file a part at a time)
	// =========================================================================

	/**
	 * Everything of a worksheet before its first row.
	 *
	 * @param array $cols   1-based column widths.
	 * @param bool  $freeze Freeze the first row.
	 * @return string
	 */
	public static function sheet_head_xml( array $cols = [], $freeze = false ) {
		$col_xml = '';
		if ( ! empty( $cols ) ) {
			$col_xml .= '<cols>';
			foreach ( $cols as $idx => $width ) {
				$col_xml .= '<col min="' . (int) $idx . '" max="' . (int) $idx . '" width="' . (float) $width . '" customWidth="1"/>';
			}
			$col_xml .= '</cols>';
		}

		if ( $freeze ) {
			// Keep the header row visible while scrolling a long order list.
			$pane = '<sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView>';
		} else {
			$pane = '<sheetView workbookViewId="0"/>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetViews>' . $pane . '</sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. $col_xml
			. '<sheetData>';
	}

	/**
	 * One row, numbered $r (1-based).
	 *
	 * @param array $row Cells: a scalar or [ value, styleIndex ].
	 * @param int   $r   Row number.
	 * @return string
	 */
	public static function row_xml( array $row, $r ) {
		$r     = (int) $r;
		$cells = '';
		$c     = 0;
		foreach ( $row as $cell ) {
			$c++;
			$cells .= self::cell_xml( $cell, self::col_letter( $c ) . $r );
		}
		return '<row r="' . $r . '">' . $cells . '</row>';
	}

	/**
	 * Everything of a worksheet after its last row.
	 *
	 * @return string
	 */
	public static function sheet_tail_xml() {
		return '</sheetData></worksheet>';
	}

	/**
	 * A spreadsheet date: days since 1899-12-30, read as the wall clock it was
	 * written in. Pass the local time as a Unix-style count (a WC_DateTime's
	 * getOffsetTimestamp()), so the cell shows the store's own time.
	 *
	 * @param int $local_timestamp Seconds, already shifted to local time.
	 * @return float
	 */
	public static function date_serial( $local_timestamp ) {
		return round( ( (int) $local_timestamp ) / DAY_IN_SECONDS + 25569, 6 );
	}

	// =========================================================================
	// XML PARTS
	// =========================================================================

	private function content_types( $count ) {
		$overrides = '';
		for ( $i = 1; $i <= $count; $i++ ) {
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. $overrides
			. '</Types>';
	}

	private function root_rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private function workbook_xml() {
		$sheets = '';
		foreach ( $this->sheets as $i => $sheet ) {
			$sheets .= '<sheet name="' . self::xml( $sheet['name'] ) . '" sheetId="' . ( $i + 1 ) . '" r:id="rId' . ( $i + 1 ) . '"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>' . $sheets . '</sheets>'
			. '</workbook>';
	}

	private function workbook_rels( $count ) {
		$rels = '';
		for ( $i = 1; $i <= $count; $i++ ) {
			$rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		}
		// Styles relationship gets an id past the sheets so it never collides.
		$rels .= '<Relationship Id="rId' . ( $count + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. $rels
			. '</Relationships>';
	}

	/**
	 * Fixed monochrome style palette, in step with the BrikPanel design
	 * system (near-black text, light-grey header fill — no bright colour).
	 *
	 * The date style uses built-in number format 22, which every spreadsheet
	 * shows in its reader's own regional date and time format.
	 */
	private function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="3">'
				. '<font><sz val="11"/><color rgb="FF303030"/><name val="Calibri"/></font>'
				. '<font><b/><sz val="11"/><color rgb="FF303030"/><name val="Calibri"/></font>'
				. '<font><b/><sz val="14"/><color rgb="FF303030"/><name val="Calibri"/></font>'
			. '</fonts>'
			. '<fills count="3">'
				. '<fill><patternFill patternType="none"/></fill>'
				. '<fill><patternFill patternType="gray125"/></fill>'
				. '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F1F1"/><bgColor indexed="64"/></patternFill></fill>'
			. '</fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="5">'
				. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
				. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
				. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
				. '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
				. '<xf numFmtId="22" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	private function sheet_xml( array $sheet ) {
		$data = '';
		$r    = 0;
		foreach ( $sheet['rows'] as $row ) {
			$r++;
			$data .= self::row_xml( $row, $r );
		}

		return self::sheet_head_xml( $sheet['cols'], ! empty( $sheet['freeze'] ) )
			. $data
			. self::sheet_tail_xml();
	}

	private static function cell_xml( $cell, $ref ) {
		$style = self::S_NORMAL;
		$value = $cell;

		if ( is_array( $cell ) ) {
			$value = $cell[0] ?? '';
			$style = isset( $cell[1] ) ? (int) $cell[1] : self::S_NORMAL;
		}

		$s = $style > 0 ? ' s="' . $style . '"' : '';

		// Real numbers only — strings that *look* numeric (order numbers,
		// SKUs) stay text so leading zeros / prefixes survive.
		if ( is_int( $value ) || is_float( $value ) ) {
			return '<c r="' . $ref . '"' . $s . '><v>' . ( $value + 0 ) . '</v></c>';
		}

		$value = (string) $value;
		if ( $value === '' ) {
			return '<c r="' . $ref . '"' . $s . '/>';
		}

		return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
			. self::xml( $value ) . '</t></is></c>';
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	private static function col_letter( $n ) {
		$s = '';
		while ( $n > 0 ) {
			$m = ( $n - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$n = (int) ( ( $n - $m ) / 26 );
		}
		return $s;
	}

	private static function xml( $str ) {
		$str = (string) $str;
		// A customer note or an imported name can carry bytes XML 1.0 does not
		// allow (control characters, broken UTF-8). One of them in a cell and
		// Excel refuses the whole file, so they are dropped here.
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$str = wp_check_invalid_utf8( $str, true );
		}
		$str = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $str );
		// ENT_XML1 keeps the output valid 1.0 XML; UTF-8 preserves accented
		// names and non-Latin scripts (Turkish, Cyrillic, CJK…).
		return htmlspecialchars( $str, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private function clean_sheet_name( $name, $fallback_index ) {
		$name = (string) $name;
		// Excel forbids : \ / ? * [ ] in tab names and caps them at 31 chars.
		$name = str_replace( [ ':', '\\', '/', '?', '*', '[', ']' ], ' ', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		$name = brikpanel_substr( $name, 0, 31 );
		return $name !== '' ? $name : ( 'Sheet' . $fallback_index );
	}
}

endif;
