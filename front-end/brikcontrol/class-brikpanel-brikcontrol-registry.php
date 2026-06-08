<?php
/**
 * BrikPanel — BrikControl Check Registry
 *
 * Holds every Brikpanel_BrikControl_Check instance the runner / page should
 * iterate over. Exposed as a filter (`brikpanel_brikcontrol_checks`) so other
 * plugins (or BrikPanel add-ons) can register their own health checks by
 * extending the base class without touching this file.
 *
 * Lookup is O(1) by id; iteration is sorted by priority for deterministic
 * UI ordering.
 *
 * @package BrikPanel
 * @since   3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Registry {

    /**
     * @var Brikpanel_BrikControl_Check[]|null
     */
    private static $checks = null;

    /**
     * @param Brikpanel_BrikControl_Check $check
     */
    public static function register( Brikpanel_BrikControl_Check $check ) {
        if ( self::$checks === null ) {
            self::$checks = [];
        }
        self::$checks[ $check->get_id() ] = $check;
    }

    /**
     * @return Brikpanel_BrikControl_Check[]
     */
    public static function get_all() {
        if ( self::$checks === null ) {
            self::$checks = [];
        }

        // Filter is applied every call so dynamically added checks (e.g.
        // registered late on a specific admin page) are picked up.
        $filtered = apply_filters( 'brikpanel_brikcontrol_checks', self::$checks );

        // Defensive: filter must return an array of base-class instances.
        $valid = [];
        foreach ( (array) $filtered as $id => $check ) {
            if ( $check instanceof Brikpanel_BrikControl_Check ) {
                $valid[ $check->get_id() ] = $check;
            }
        }

        uasort( $valid, static function ( $a, $b ) {
            return $a->get_priority() <=> $b->get_priority();
        } );

        return $valid;
    }

    /**
     * @param string $id
     * @return Brikpanel_BrikControl_Check|null
     */
    public static function get( $id ) {
        $all = self::get_all();
        return isset( $all[ $id ] ) ? $all[ $id ] : null;
    }
}
