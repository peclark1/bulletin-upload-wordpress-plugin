<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tracks trusted internal Bulletin Publisher parser execution.
 *
 * Historical schedule repair stages were originally written for wp-admin
 * requests and therefore gate themselves with is_admin(). Agent/API runs are
 * REST requests, so is_admin() is false even though the authenticated user is
 * an administrator. This context provides a narrow, temporary exception only
 * while the production extraction pipeline is deliberately invoking those
 * stages.
 */
final class CBP_Parser_Context
{
    private static $internal_depth = 0;

    public static function begin_internal_extraction()
    {
        self::$internal_depth++;
    }

    public static function end_internal_extraction()
    {
        self::$internal_depth = max(0, self::$internal_depth - 1);
    }

    public static function internal_extraction_active()
    {
        return self::$internal_depth > 0;
    }

    public static function allows_legacy_postprocess()
    {
        if (! current_user_can('manage_options')) {
            return false;
        }

        return is_admin() || self::internal_extraction_active();
    }
}
