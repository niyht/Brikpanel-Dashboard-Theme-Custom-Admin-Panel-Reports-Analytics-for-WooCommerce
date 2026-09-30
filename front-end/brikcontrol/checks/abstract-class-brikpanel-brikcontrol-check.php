<?php
/**
 * BrikPanel: BrikControl Abstract Check
 *
 * Base class every health check extends. The runner only knows about this
 * contract — concrete checks plug into the registry and the rest of the
 * pipeline (storage, batching, page rendering) treats them uniformly.
 *
 * Why a shared base instead of ad-hoc callbacks: the result shape needs to
 * stay strictly stable so the topbar JSON / page renderer / dismissal logic
 * never have to special-case individual checks. Extending this class makes
 * that contract explicit.
 *
 * @package BrikPanel
 * @since   3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Brikpanel_BrikControl_Check {

    /**
     * Stable identifier. Used as the storage key + dismissal key + DOM id.
     * Convention: snake_case, no namespace prefix.
     *
     * @return string
     */
    abstract public function get_id();

    /**
     * Translatable display label.
     *
     * @return string
     */
    abstract public function get_label();

    /**
     * Logical bucket for grouping in the UI: media | seo | security |
     * performance | content | other.
     *
     * @return string
     */
    public function get_category() {
        return 'other';
    }

    /**
     * Whether this check produces too much work for a single request and
     * needs to be split across Action Scheduler batches. Checks that finish
     * in < 200ms can return false and run inline.
     *
     * @return bool
     */
    public function supports_batching() {
        return false;
    }

    /**
     * Items per batch when supports_batching() is true.
     *
     * @return int
     */
    public function get_batch_size() {
        return 200;
    }

    /**
     * Sort weight for the page renderer (lower = first).
     *
     * @return int
     */
    public function get_priority() {
        return 50;
    }

    /**
     * Run the check (or a single batch slice). The runner passes the current
     * cursor state in $state['cursor'] and the check returns a CheckResult
     * matching the schema documented in the plan file.
     *
     * @param array $state { cursor?: int, partial?: array, ... }
     * @return array CheckResult
     */
    abstract public function run( array $state = [] );

    /**
     * Whether this check can repair what it reports.
     *
     * Non-abstract with a false default on purpose: every check that existed
     * before this contract, and any third-party check registered through the
     * `brikpanel_brikcontrol_checks` filter, keeps working untouched. Consumers
     * still probe with method_exists() before calling, so a check built against
     * the older abstract cannot fatal either.
     *
     * @since 3.2.76
     * @return bool
     */
    public function supports_fix() {
        return false;
    }

    /**
     * Translatable label for the repair button.
     *
     * @since 3.2.76
     * @return string
     */
    public function get_fix_label() {
        return __( 'Clean up', 'brikpanel' );
    }

    /**
     * Perform the repair.
     *
     * Implementations must be idempotent and bounded: cap the work at whatever
     * fits comfortably in one request and report `has_more` so the caller can
     * invoke it again instead of risking a timeout.
     *
     * @since 3.2.76
     *
     * @param array $args Optional caller arguments.
     * @return array { removed: int, has_more: bool, message: string }
     */
    public function run_fix( array $args = [] ) {
        return [
            'removed'  => 0,
            'has_more' => false,
            'message'  => '',
        ];
    }

    /**
     * Version of the stored result shape.
     *
     * 1: the scan stored finished sentences, in the language of whatever ran
     *    it (usually the Action Scheduler worker, so the site language), and
     *    every viewer read them in that language (field test E1: a Turkish
     *    admin saw "Product SKU Index" in English).
     * 2: the scan stores figures only, under `facts`; bc_present() writes the
     *    sentences when the result is shown, in the viewer's language.
     *
     * Named with a prefix and not abstract, so a check registered through the
     * `brikpanel_brikcontrol_checks` filter cannot collide with it and keeps
     * working unchanged.
     *
     * @since 3.3.25
     * @return int
     */
    public function bc_schema() {
        return 1;
    }

    /**
     * Turn a stored result into what the card, the topbar and the AJAX
     * responses show, in the current language.
     *
     * This default serves results written before schema 2 and checks that
     * never adopted it: their sentences were stored at scan time and cannot be
     * translated again, but the label is the check's own, live, and the score
     * always agrees with the status badge.
     *
     * @since 3.3.25
     *
     * @param array  $r       Stored result.
     * @param string $context 'page' (everything the card shows) or 'summary'
     *                        (label, status, score and the one-line summary).
     * @return array
     */
    public function bc_present( array $r, $context = 'page' ) {
        $r['label'] = $this->get_label();
        $r['score'] = self::bc_band_score( isset( $r['status'] ) ? (string) $r['status'] : 'unknown', $r['score'] ?? null );
        return $r;
    }

    /**
     * Keep a score inside the band its status stands for, so the badge and
     * the number never disagree (field test E6: "Critical" beside 68/100).
     * OK is 80 to 100, Warning 50 to 79, Critical 0 to 49; a pending check
     * or a check with nothing to grade shows no score at all.
     *
     * @since 3.3.25
     *
     * @param string         $status Result status.
     * @param int|float|null $score  Raw score, or null for none.
     * @return int|null
     */
    public static function bc_band_score( $status, $score ) {
        $bands = [
            'ok'       => [ 80, 100 ],
            'warning'  => [ 50, 79 ],
            'critical' => [ 0, 49 ],
        ];
        if ( null === $score || ! is_numeric( $score ) || ! isset( $bands[ $status ] ) ) {
            return null;
        }
        return (int) max( $bands[ $status ][0], min( $bands[ $status ][1], (int) round( (float) $score ) ) );
    }

    /**
     * What the card says after run_fix() finished, in the viewer's language.
     *
     * @since 3.3.25
     *
     * @param array $outcome run_fix() result.
     * @return string
     */
    public function bc_fix_done( array $outcome ) {
        $removed = (int) ( $outcome['removed'] ?? 0 );
        return brikpanel_safe_sprintf(
            /* translators: %s: number of items a Store Health cleanup removed. */
            _n( '%s item cleaned up.', '%s items cleaned up.', $removed, 'brikpanel' ),
            brikpanel_number( $removed )
        );
    }

    /**
     * What the card says after run_undo() finished, in the viewer's language.
     *
     * @since 3.3.25
     *
     * @param array $outcome run_undo() result.
     * @return string
     */
    public function bc_undo_done( array $outcome ) {
        $restored = (int) ( $outcome['restored'] ?? 0 );
        return brikpanel_safe_sprintf(
            /* translators: %s: number of database rows put back. */
            _n( '%s row restored.', '%s rows restored.', $restored, 'brikpanel' ),
            brikpanel_number( $restored )
        );
    }

    /**
     * Helper: build a CheckResult skeleton with the check's static metadata
     * already populated. Concrete checks fill status / score and either
     * `facts` (schema 2) or summary / recommendations / metadata (schema 1).
     *
     * A score of null means "no score"; bc_band_score() keeps any other value
     * inside its status band when the result is shown.
     *
     * @return array
     */
    protected function make_result_skeleton() {
        return [
            'id'              => $this->get_id(),
            'label'           => $this->get_label(),
            'category'        => $this->get_category(),
            'schema'          => $this->bc_schema(),
            'status'          => 'unknown',
            'score'           => null,
            'summary'         => '',
            'message'         => '',
            'recommendations' => [],
            'metadata'        => [],
            'facts'           => [],
            'scanned_at'      => time(),
            'duration_ms'     => 0,
        ];
    }
}
