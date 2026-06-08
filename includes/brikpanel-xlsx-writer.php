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
 * palette (normal / bold / header / title), per-column widths and an
 * optional frozen header row. That's all the report needs.
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

	/** @var array<int,array{name:string,rows:array,cols:array,freeze:bool}> */
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
		];
	}

	/**
	 * Build the workbook and return the raw .xlsx bytes, or false on failure.
	 *
	 * @return string|false
	 */
	public function build() {
		if ( ! class_exists( 'ZipArchive' ) || empty( $this->sheets ) ) {
			return false;
		}

		$tmp = wp_tempnam( 'brikpanel-xlsx' );
		if ( ! $tmp ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		$count = count( $this->sheets );

		$zip->addFromString( '[Content_Types].xml', $this->content_types( $count ) );
		$zip->addFromString( '_rels/.rels', $this->root_rels() );
		$zip->addFromString( 'xl/workbook.xml', $this->workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels( $count ) );
		$zip->addFromString( 'xl/styles.xml', $this->styles_xml() );

		foreach ( $this->sheets as $i => $sheet ) {
			$zip->addFromString(
				'xl/worksheets/sheet' . ( $i + 1 ) . '.xml',
				$this->sheet_xml( $sheet )
			);
		}

		$zip->close();

		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $bytes;
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
			$sheets .= '<sheet name="' . $this->xml( $sheet['name'] ) . '" sheetId="' . ( $i + 1 ) . '" r:id="rId' . ( $i + 1 ) . '"/>';
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
			. '<cellXfs count="4">'
				. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
				. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
				. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
				. '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	private function sheet_xml( array $sheet ) {
		$cols = '';
		if ( ! empty( $sheet['cols'] ) ) {
			$cols .= '<cols>';
			foreach ( $sheet['cols'] as $idx => $width ) {
				$cols .= '<col min="' . (int) $idx . '" max="' . (int) $idx . '" width="' . (float) $width . '" customWidth="1"/>';
			}
			$cols .= '</cols>';
		}

		$pane = '';
		if ( ! empty( $sheet['freeze'] ) ) {
			// Keep the header row visible while scrolling a long order list.
			$pane = '<sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView>';
		} else {
			$pane = '<sheetView workbookViewId="0"/>';
		}

		$data = '';
		$r    = 0;
		foreach ( $sheet['rows'] as $row ) {
			$r++;
			$cells = '';
			$c     = 0;
			foreach ( $row as $cell ) {
				$c++;
				$cells .= $this->cell_xml( $cell, $this->col_letter( $c ) . $r );
			}
			$data .= '<row r="' . $r . '">' . $cells . '</row>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetViews>' . $pane . '</sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. $cols
			. '<sheetData>' . $data . '</sheetData>'
			. '</worksheet>';
	}

	private function cell_xml( $cell, $ref ) {
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
			. $this->xml( $value ) . '</t></is></c>';
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	private function col_letter( $n ) {
		$s = '';
		while ( $n > 0 ) {
			$m = ( $n - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$n = (int) ( ( $n - $m ) / 26 );
		}
		return $s;
	}

	private function xml( $str ) {
		// ENT_XML1 keeps the output valid 1.0 XML; UTF-8 preserves accented
		// names and non-Latin scripts (Turkish, Cyrillic, CJK…).
		return htmlspecialchars( (string) $str, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private function clean_sheet_name( $name, $fallback_index ) {
		$name = (string) $name;
		// Excel forbids : \ / ? * [ ] in tab names and caps them at 31 chars.
		$name = str_replace( [ ':', '\\', '/', '?', '*', '[', ']' ], ' ', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 31 );
		} else {
			$name = substr( $name, 0, 31 );
		}
		return $name !== '' ? $name : ( 'Sheet' . $fallback_index );
	}
}

endif;
