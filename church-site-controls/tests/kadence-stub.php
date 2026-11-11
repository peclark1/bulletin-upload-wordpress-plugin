<?php
namespace Kadence;
function kadence() { return new class {
    function get_option_type() { return $GLOBALS['option_type']; }
    function option($key,$default=null) { return $GLOBALS['mods'][$key] ?? $default; }
}; }
