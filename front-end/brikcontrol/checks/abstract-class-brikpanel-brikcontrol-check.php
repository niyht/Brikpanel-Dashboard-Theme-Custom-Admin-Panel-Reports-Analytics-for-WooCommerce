<?php
/**
 * BrikPanel — BrikControl Abstract Check
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
     * Helper: build a CheckResult skeleton with the check's static metadata
     * already populated. Concrete checks fill status / score / summary /
     * recommendations / metadata.
     *
     * @return array
     */
    protected function make_result_skeleton() {
        return [
            'id'              => $this->get_id(),
            'label'           => $this->get_label(),
            'category'        => $this->get_category(),
            'status'          => 'unknown',
            'score'           => 0,
            'summary'         => '',
            'message'         => '',
            'recommendations' => [],
            'metadata'        => [],
            'scanned_at'      => time(),
            'duration_ms'     => 0,
        ];
    }
}
