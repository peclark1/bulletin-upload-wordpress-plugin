#!/usr/bin/env php
<?php
/**
 * Golden-fixture regression runner for Church Bulletin Publisher.
 *
 * Each fixture contains the original bulletin PDF and a hand-reviewed
 * expected.json. The runner executes the same PDF extraction/parser layers
 * used by the plugin, then compares semantic website data rather than noisy
 * debug/source-line diagnostics.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__, 2);
require $root . '/tests/regression/wp-stubs.php';
require $root . '/vendor/autoload.php';
require $root . '/includes/class-cbp-schedule.php';
require $root . '/includes/class-cbp-weekly-normalizer.php';
require $root . '/includes/class-cbp-site-displays.php';
require $root . '/includes/class-cbp-home-schedule.php';

// Smoke-test the front-end shortcode class structurally. PHP lint alone will
// not catch a method call whose helper was accidentally removed from the class.
$site_display_methods = array(
    'worship_week_shortcode',
    'parish_events_shortcode',
    'weekly',
    'has_week',
    'week_heading',
    'render_rows',
    'worship_location_name',
    'has_mass_intention',
    'event_note',
    'upcoming_rows',
    'enqueue_assets',
    'empty_message',
    'valid_date',
    'format_date',
);
$site_display_reflection = new ReflectionClass('CBP_Site_Displays');
foreach ($site_display_methods as $method) {
    if (! $site_display_reflection->hasMethod($method)) {
        fwrite(STDERR, "CBP_Site_Displays is missing required method: {$method}\\n");
        exit(1);
    }
}

$version_files = glob($root . '/includes/class-cbp-schedule-v*.php');
usort($version_files, function ($a, $b) {
    preg_match('/-v(\d+)\.php$/', $a, $am);
    preg_match('/-v(\d+)\.php$/', $b, $bm);
    return ((int) $am[1]) <=> ((int) $bm[1]);
});
foreach ($version_files as $file) {
    require_once $file;
}

// Targeted guards for the September 27 follow-up fixes that are not fully
// represented by parser-only fixture comparisons.
if (class_exists('CBP_Schedule_V26')) {
    $v26 = CBP_Schedule_V26::instance();
    $historical_events = new ReflectionMethod($v26, 'historical_event_rows');
    $historical_events->setAccessible(true);

    $october_rows = $historical_events->invoke($v26, array(
        'Saturday, October 10',
        'Heritage Living Center Rosary- Please join us in praying the Rosary at Heritage Center on the 4th Monday of the month at',
        '10:00 am. We will meet Monday, October 26.',
        'Sunday, October 11',
    ), '2026-10-04');
    foreach ($october_rows as $row) {
        if (stripos((string) ($row['title'] ?? ''), 'Heritage Living') !== false) {
            fwrite(STDERR, "V26 regression: future Heritage announcement inherited the October 10 calendar heading.\n");
            exit(1);
        }
    }

    $april_rows = $historical_events->invoke($v26, array(
        'Monday, April 27',
        'Heritage Living Center: 10:00 am Pray the Rosary',
        'Tuesday, April 28',
    ), '2026-04-26');
    $found_heritage = false;
    foreach ($april_rows as $row) {
        if (($row['date'] ?? '') === '2026-04-27'
            && stripos((string) ($row['title'] ?? ''), 'Heritage Living') !== false) {
            $found_heritage = true;
            break;
        }
    }
    if (! $found_heritage) {
        fwrite(STDERR, "V26 regression: legitimate current-week Heritage Rosary was lost.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V29')) {
    $v29 = CBP_Schedule_V29::instance();

    $leak_method = new ReflectionMethod($v29, 'is_dated_prose_leak');
    $leak_method->setAccessible(true);
    $leak = $leak_method->invoke($v29, array(
        'date' => '2026-10-04',
        'time' => '',
        'location' => '',
        'title' => 'September 28 - Come Pray the Rosary at Heritage Living Center',
    ), '2026-09-27');
    if (! $leak) {
        fwrite(STDERR, "V29 regression: a mismatched dated prose reminder was not rejected.\n");
        exit(1);
    }

    $first_communion_method = new ReflectionMethod($v29, 'is_first_communion_announcement');
    $first_communion_method->setAccessible(true);
    if (! $first_communion_method->invoke($v29, array('time' => '', 'title' => 'First Communion, Selene and Kelsi Scholz'))) {
        fwrite(STDERR, "V29 regression: First Communion prose announcement was not rejected.\n");
        exit(1);
    }

    $dedupe_method = new ReflectionMethod($v29, 'dedupe_rows');
    $dedupe_method->setAccessible(true);
    $deduped = $dedupe_method->invoke($v29, array(
        array('date' => '2026-09-28', 'time' => '6:00 PM–8:30 PM', 'location' => 'St. Peter', 'title' => 'Civil Air Patrol Meeting', 'description' => ''),
        array('date' => '2026-09-28', 'time' => '6:00 PM', 'location' => 'St. Peter', 'title' => 'Civil Air Patrol Meeting', 'description' => ''),
    ));
    if (count($deduped) !== 1 || ($deduped[0]['time'] ?? '') !== '6:00 PM–8:30 PM') {
        fwrite(STDERR, "V29 regression: Civil Air Patrol duplicate cleanup did not keep the best range row.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V9')) {
    $offsite = array(
        array('date' => '2026-09-28', 'time' => '10:00 AM', 'location' => 'Heritage Living Center', 'title' => 'Pray the Rosary', 'description' => ''),
        array('date' => '2026-09-29', 'time' => '5:00 PM', 'location' => 'St. Peter', 'title' => 'Rosary', 'description' => ''),
    );
    $filtered = CBP_Schedule_V9::filter_worship_fragments($offsite);
    if (count($filtered) !== 1 || ($filtered[0]['location'] ?? '') !== 'Heritage Living Center') {
        fwrite(STDERR, "V9 regression: off-site Rosary events must survive while parish worship rows stay filtered.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V30')) {
    $rows = array(
        array('date' => '2026-10-01', 'time' => '6:00 PM', 'location' => '', 'title' => 'Men’s Burger & Beer'),
        array('date' => '2026-10-01', 'time' => '9:00 AM', 'location' => 'St. Peter', 'title' => 'Men’s Bible Study'),
        array('date' => '2026-10-01', 'time' => '', 'location' => '', 'title' => 'NO Ladies lunch'),
    );
    $rows = CBP_Schedule_V30::sort_calendar_rows($rows);
    $titles = array_column($rows, 'title');
    if ($titles !== array('NO Ladies lunch', 'Men’s Bible Study', 'Men’s Burger & Beer')) {
        fwrite(STDERR, "V30 regression: weekly rows are not sorted by actual clock time.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V31')) {
    $v31 = CBP_Schedule_V31::instance();

    $mass_records = new ReflectionMethod($v31, 'mass_records');
    $mass_records->setAccessible(true);
    $records = $mass_records->invoke($v31, array(
        'Wednesday, October 7th',
        ', 10:30 a.m.',
        'Crystal Brook Senior Living Center,',
        'Bishop Balke',
        'Thursday, October 8th, 9:00 a.m.',
        "St. Mary's, Healing for Grant & Lillian Schmaus",
    ), '2026-10-04');
    $key = strtolower('2026-10-07|10:30 AM|Crystal Brook Senior Living Center');
    if (empty($records[$key])
        || count($records[$key]) !== 1
        || ($records[$key][0]['description'] ?? '') !== 'Bishop Balke') {
        fwrite(STDERR, "V31 regression: split Crystal Brook location/intention was not recovered.\n");
        exit(1);
    }

    $prose_leak = new ReflectionMethod($v31, 'is_mismatched_dated_prose');
    $prose_leak->setAccessible(true);
    $leaked = $prose_leak->invoke($v31, array(
        'date' => '2026-10-10',
        'time' => '10:00 AM',
        'location' => '',
        'title' => 'Heritage Living Center Rosary - We will meet Monday, October 26.',
        'description' => 'Heritage Living Center Rosary - We will meet Monday, October 26.',
    ), '2026-10-04');
    if (! $leaked) {
        fwrite(STDERR, "V31 regression: future dated prose leak was not rejected.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V32')) {
    $v32 = CBP_Schedule_V32::instance();
    $live_shape = new ReflectionMethod($v32, 'is_mismatched_dated_event');
    $live_shape->setAccessible(true);

    $leaked = $live_shape->invoke($v32, array(
        'date' => '2026-10-10',
        'time' => '',
        'location' => '',
        'title' => 'Heritage Living Center',
        'description' => 'Heritage Living Center Rosary- Please join us in praying the Rosary at Heritage Center on the 4th Monday of the month at 10:00 am. We will meet Monday, October 26.',
    ), '2026-10-04');
    if (! $leaked) {
        fwrite(STDERR, "V32 regression: normalized live Heritage row was not rejected.\n");
        exit(1);
    }

    $legitimate = $live_shape->invoke($v32, array(
        'date' => '2026-10-10',
        'time' => '4:30 PM–7:00 PM',
        'location' => 'St. Mary’s',
        'title' => 'St. Mary’s Annual Dinner & Silent Auction',
        'description' => '4:30 – 7:00 pm St. Mary’s Annual Dinner & Silent Auction',
    ), '2026-10-04');
    if ($legitimate) {
        fwrite(STDERR, "V32 regression: legitimate dated weekly event was rejected.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V33')) {
    $v33 = CBP_Schedule_V33::instance();

    $source_lines = new ReflectionMethod($v33, 'raw_lines');
    $source_lines->setAccessible(true);
    $stored_sources = $source_lines->invoke($v33, array(
        'source_lines' => array(
            'Heritage Living Center Rosary- Please join us in praying the Rosary at Heritage Center on the 4th Monday of the month at 10:00 am. We will meet Monday,',
            'October 26.',
            '[v32] Removed normalized weekly rows whose prose states a conflicting date.',
        ),
    ));
    if (count($stored_sources) !== 2 || strpos($stored_sources[0], 'Heritage Living Center') === false) {
        fwrite(STDERR, "V33 regression: ordinary stored source lines were not available for future-event matching.\n");
        exit(1);
    }

    $future_windows = new ReflectionMethod($v33, 'future_dated_windows');
    $future_windows->setAccessible(true);
    $windows = $future_windows->invoke($v33, array(
        'Heritage Living Center Rosary- Please join us in praying the Rosary at Heritage Center on the 4th Monday of the month at 10:00 am. We will meet Monday,',
        'October 26.',
        'Friday Rosary, Divine Mercy Chaplet and Prayers- Join us for prayer and fellowship on Fridays immediately following 9:00 am Mass at St. Peter\'s in the church.',
    ), '2026-10-04');

    $matches_future = new ReflectionMethod($v33, 'matches_future_window');
    $matches_future->setAccessible(true);
    $leaked = $matches_future->invoke($v33, array(
        'date' => '2026-10-10',
        'time' => '',
        'location' => '',
        'title' => 'Heritage Living Center',
        'description' => 'Heritage Living Center Rosary- Please join us in praying the Rosary at Heritage Center',
    ), $windows);
    if (! $leaked) {
        fwrite(STDERR, "V33 regression: split-line future Heritage announcement was not rejected.\n");
        exit(1);
    }

    $legitimate = $matches_future->invoke($v33, array(
        'date' => '2026-10-08',
        'time' => '6:00 PM',
        'location' => '',
        'title' => 'Men’s Burger & Beer',
        'description' => '6:00 pm Men’s Burger & Beer-All Men are welcome',
    ), $windows);
    if ($legitimate) {
        fwrite(STDERR, "V33 regression: unrelated blank-location weekly event was rejected.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V34')) {
    $v34 = CBP_Schedule_V34::instance();

    $normalize_liturgy = new ReflectionMethod($v34, 'normalize_liturgy_mass_rows');
    $normalize_liturgy->setAccessible(true);
    $normalized = $normalize_liturgy->invoke($v34, array(
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => 'Heritage Senior Living Center', 'title' => 'Mass', 'description' => 'Liturgy of the Word'),
    ));
    if (($normalized[0]['title'] ?? '') !== 'Liturgy of the Word' || ($normalized[0]['description'] ?? '') !== '') {
        fwrite(STDERR, "V34 regression: Liturgy of the Word Mass row was not reclassified.\n");
        exit(1);
    }

    $liturgy_from_calendar = new ReflectionMethod($v34, 'liturgy_events_from_calendar');
    $liturgy_from_calendar->setAccessible(true);
    $canonical = $liturgy_from_calendar->invoke($v34, array(
        'Wednesday, October 14',
        'Heritage Living Center: 10:00 am',
        'Liturgy of the Word',
        'SP: 1:00 pm Faith Formation Grades 1 - 6',
    ), '2026-10-11');
    if (count($canonical) !== 1
        || ($canonical[0]['date'] ?? '') !== '2026-10-14'
        || ($canonical[0]['time'] ?? '') !== '10:00 AM'
        || ($canonical[0]['location'] ?? '') !== 'Heritage Living Center'
        || ($canonical[0]['title'] ?? '') !== 'Liturgy of the Word') {
        fwrite(STDERR, "V34 regression: canonical Heritage Liturgy of the Word was not recovered.\n");
        exit(1);
    }

    $merge_liturgy = new ReflectionMethod($v34, 'merge_canonical_liturgy_events');
    $merge_liturgy->setAccessible(true);
    $merged = $merge_liturgy->invoke($v34, array(
        array('date' => '2026-10-14', 'time' => '', 'location' => '', 'title' => 'There will be Liturgy of the Word at Heritage Living Center', 'description' => 'There will be Liturgy of the Word at Heritage Living Center on Wednesday, October 14.'),
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => '', 'title' => 'Heritage Living Center', 'description' => 'Heritage Living Center: 10:00 am'),
        array('date' => '2026-10-14', 'time' => '1:00 PM', 'location' => 'St. Peter', 'title' => 'Faith Formation Grades 1 - 6', 'description' => ''),
    ), $canonical);
    $heritage_rows = array_values(array_filter($merged, function ($row) {
        return is_array($row)
            && ($row['date'] ?? '') === '2026-10-14'
            && (($row['title'] ?? '') === 'Liturgy of the Word' || stripos((string) ($row['title'] ?? ''), 'Heritage') !== false);
    }));
    if (count($merged) !== 2 || count($heritage_rows) !== 1 || ($heritage_rows[0]['location'] ?? '') !== 'Heritage Living Center') {
        fwrite(STDERR, "V34 regression: duplicate/malformed Heritage rows were not consolidated.\n");
        exit(1);
    }

    $recover_after_mass = new ReflectionMethod($v34, 'recover_after_mass_events');
    $recover_after_mass->setAccessible(true);
    $after_mass = $recover_after_mass->invoke($v34, array(
        array('date' => '2026-10-18', 'time' => '', 'location' => 'St. Peter', 'title' => 'Coffee & Rolls Team 3', 'description' => ''),
    ), array(
        'Sunday, October 18',
        'SP: Coffee & Rolls Team 3',
        'SM: Soup & Sandwich after Mass',
    ), '2026-10-11');
    $found_soup = false;
    foreach ($after_mass as $row) {
        if (($row['date'] ?? '') === '2026-10-18'
            && ($row['time'] ?? '') === 'After Mass'
            && ($row['location'] ?? '') === 'St. Mary’s'
            && ($row['title'] ?? '') === 'Soup & Sandwich') {
            $found_soup = true;
            break;
        }
    }
    if (! $found_soup) {
        fwrite(STDERR, "V34 regression: St. Mary's after-Mass event was not recovered.\n");
        exit(1);
    }

    cbp_regression_reset_wordpress_state();
    $GLOBALS['cbp_regression_transients']['cbp_schedule_review_1'] = array(
        'bulletin_date' => '2026-10-11',
        'candidates' => array('st_peter_sunday' => '9:00 AM'),
        'weekly' => array(
            'masses' => array(),
            'devotions' => array(),
            'events' => array(
                array('date' => '2026-10-18', 'time' => '', 'location' => 'St. Peter', 'title' => 'Coffee & Rolls Team 3', 'description' => ''),
            ),
            'livestream' => array(),
        ),
    );
    $payload = $v34->ability_get_review();
    $revision = is_array($payload) ? ($payload['revision'] ?? '') : '';
    if ($revision === '') {
        fwrite(STDERR, "V34 regression: review ability did not return a revision token.\n");
        exit(1);
    }
    $changed = $v34->ability_update_review_candidate(array(
        'expected_revision' => $revision,
        'key' => 'st_peter_sunday',
        'value' => '8:30 AM',
    ));
    if (! is_array($changed) || ($changed['candidates']['st_peter_sunday'] ?? '') !== '8:30 AM') {
        fwrite(STDERR, "V34 regression: recurring candidate ability did not update pending review data.\n");
        exit(1);
    }
    $stale = $v34->ability_update_review_candidate(array(
        'expected_revision' => $revision,
        'key' => 'st_peter_sunday',
        'value' => '9:00 AM',
    ));
    if (! is_wp_error($stale)) {
        fwrite(STDERR, "V34 regression: stale review revision was not rejected.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V35')) {
    $v35 = CBP_Schedule_V35::instance();

    $canonicalize = new ReflectionMethod($v35, 'canonicalize_liturgy_events');
    $canonicalize->setAccessible(true);
    $live_events = array(
        array('date' => '2026-10-12', 'time' => '10:00 AM', 'location' => 'Heritage Living Center', 'title' => 'Liturgy of the Word', 'description' => ''),
        array('date' => '2026-10-14', 'time' => '', 'location' => '', 'title' => 'There will be Liturgy of the Word at Heritage Living Center', 'description' => 'There will be Liturgy of the Word at Heritage Living Center on Wednesday, October 14.'),
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => '', 'title' => 'Heritage Living Center', 'description' => ''),
        array('date' => '2026-10-18', 'time' => '', 'location' => 'St. Peter', 'title' => 'Coffee & Rolls Team 3', 'description' => ''),
    );
    $live_masses = array(
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => 'Heritage Senior Living Center', 'title' => 'Liturgy of the Word', 'description' => ''),
        array('date' => '2026-10-18', 'time' => '11:00 AM', 'location' => 'St. Mary’s', 'title' => 'Mass', 'description' => '† Kimberly Wettels'),
    );
    $fixed_liturgy = $canonicalize->invoke($v35, $live_events, $live_masses);
    $liturgy_rows = array_values(array_filter($fixed_liturgy, function ($row) {
        $text = strtolower((string) ($row['title'] ?? '') . ' ' . (string) ($row['description'] ?? '') . ' ' . (string) ($row['location'] ?? ''));
        return strpos($text, 'heritage') !== false || strpos($text, 'liturgy of the word') !== false;
    }));
    if (count($liturgy_rows) !== 1
        || ($liturgy_rows[0]['date'] ?? '') !== '2026-10-14'
        || ($liturgy_rows[0]['time'] ?? '') !== '10:00 AM'
        || ($liturgy_rows[0]['location'] ?? '') !== 'Heritage Living Center'
        || ($liturgy_rows[0]['title'] ?? '') !== 'Liturgy of the Word') {
        fwrite(STDERR, "V35 regression: live Heritage rows were not reconciled to the authoritative October 14 Liturgy.\n");
        exit(1);
    }

    $resolve_after_mass = new ReflectionMethod($v35, 'resolve_after_mass_dates');
    $resolve_after_mass->setAccessible(true);
    $after_mass_events = array(
        array('date' => '2026-10-12', 'time' => 'After Mass', 'location' => 'St. Mary’s', 'title' => 'Soup & Sandwich', 'description' => ''),
        array('date' => '2026-10-18', 'time' => 'After Mass', 'location' => 'St. Mary’s', 'title' => 'Soup & Sandwich', 'description' => ''),
    );
    $fixed_after_mass = $resolve_after_mass->invoke($v35, $after_mass_events, $live_masses);
    if (count($fixed_after_mass) !== 1
        || ($fixed_after_mass[0]['date'] ?? '') !== '2026-10-18'
        || ($fixed_after_mass[0]['location'] ?? '') !== 'St. Mary’s'
        || ($fixed_after_mass[0]['title'] ?? '') !== 'Soup & Sandwich') {
        fwrite(STDERR, "V35 regression: wrong-date Soup & Sandwich duplicate was not removed.\n");
        exit(1);
    }

    $wrong_only = $resolve_after_mass->invoke($v35, array(
        array('date' => '2026-10-12', 'time' => 'After Mass', 'location' => 'St. Mary’s', 'title' => 'Soup & Sandwich', 'description' => ''),
    ), $live_masses);
    if (count($wrong_only) !== 1 || ($wrong_only[0]['date'] ?? '') !== '2026-10-18') {
        fwrite(STDERR, "V35 regression: unambiguous after-Mass event was not moved to the parish's only actual Mass date.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V36')) {
    $v36 = CBP_Schedule_V36::instance();
    $dedupe_location_title = new ReflectionMethod($v36, 'remove_location_title_duplicates');
    $dedupe_location_title->setAccessible(true);

    $rows = $dedupe_location_title->invoke($v36, array(
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => '', 'title' => 'Heritage Living Center', 'description' => ''),
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => 'Heritage Living Center', 'title' => 'Liturgy of the Word', 'description' => ''),
        array('date' => '2026-10-14', 'time' => '1:00 PM', 'location' => 'St. Peter', 'title' => 'Faith Formation Grades 1 - 6', 'description' => ''),
    ));
    if (count($rows) !== 2) {
        fwrite(STDERR, "V36 regression: blank-location location-as-title duplicate was not removed.\n");
        exit(1);
    }
    foreach ($rows as $row) {
        if (($row['date'] ?? '') === '2026-10-14'
            && ($row['time'] ?? '') === '10:00 AM'
            && ($row['location'] ?? '') === ''
            && ($row['title'] ?? '') === 'Heritage Living Center') {
            fwrite(STDERR, "V36 regression: malformed Heritage companion row survived.\n");
            exit(1);
        }
    }

    $preserved = $dedupe_location_title->invoke($v36, array(
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => '', 'title' => 'Heritage Living Center', 'description' => 'Independent outreach meeting'),
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => 'Heritage Living Center', 'title' => 'Liturgy of the Word', 'description' => ''),
    ));
    if (count($preserved) !== 2) {
        fwrite(STDERR, "V36 regression: a same-name row with independent details was removed too aggressively.\n");
        exit(1);
    }
}

if (class_exists('CBP_Schedule_V37')) {
    $v37 = CBP_Schedule_V37::instance();
    $dedupe_location_time = new ReflectionMethod($v37, 'remove_location_time_detail_duplicates');
    $dedupe_location_time->setAccessible(true);

    $rows = $dedupe_location_time->invoke($v37, array(
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => '', 'title' => 'Heritage Living Center', 'description' => 'Heritage Living Center: 10:00 am'),
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => 'Heritage Living Center', 'title' => 'Liturgy of the Word', 'description' => ''),
        array('date' => '2026-10-14', 'time' => '1:00 PM', 'location' => 'St. Peter', 'title' => 'Faith Formation Grades 1 - 6', 'description' => ''),
    ));
    if (count($rows) !== 2) {
        fwrite(STDERR, "V37 regression: location/time-only companion row was not removed.\n");
        exit(1);
    }
    foreach ($rows as $row) {
        if (($row['date'] ?? '') === '2026-10-14'
            && ($row['time'] ?? '') === '10:00 AM'
            && ($row['location'] ?? '') === ''
            && ($row['title'] ?? '') === 'Heritage Living Center') {
            fwrite(STDERR, "V37 regression: live Heritage location/time detail duplicate survived.\n");
            exit(1);
        }
    }

    $preserved = $dedupe_location_time->invoke($v37, array(
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => '', 'title' => 'Heritage Living Center', 'description' => 'Heritage Living Center: 10:00 am - volunteers meet in lobby'),
        array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => 'Heritage Living Center', 'title' => 'Liturgy of the Word', 'description' => ''),
    ));
    if (count($preserved) !== 2) {
        fwrite(STDERR, "V37 regression: companion row with independent descriptive content was removed.\n");
        exit(1);
    }
}

// Guard the architectural test66 path directly: the complete review must be
// clean before any transient is saved.
$normalizer_input = array(
    'bulletin_date' => '2026-10-11',
    'week_start' => '2026-10-12',
    'week_end' => '2026-10-18',
    'weekly' => array(
        'masses' => array(
            array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => 'Heritage Senior Living Center', 'title' => 'Mass', 'description' => 'Liturgy of the Word'),
            array('date' => '2026-10-18', 'time' => '11:00 AM', 'location' => 'St. Mary’s', 'title' => 'Mass', 'description' => '† Kimberly Wettels'),
        ),
        'devotions' => array(),
        'events' => array(
            array('date' => '2026-10-14', 'time' => '', 'location' => '', 'title' => 'There will be Liturgy of the Word at Heritage Living Center', 'description' => 'There will be Liturgy of the Word at Heritage Living Center on Wednesday, October 14.'),
            array('date' => '2026-10-14', 'time' => '10:00 AM', 'location' => '', 'title' => 'Heritage Living Center', 'description' => 'Heritage Living Center: 10:00 am'),
        ),
        'livestream' => array(),
    ),
    'source_lines' => array(),
);
$normalizer_text = implode("\n", array(
    'Wednesday, October 14',
    'Heritage Living Center: 10:00 am',
    'Liturgy of the Word',
    'Sunday, October 18',
    'SM: Soup & Sandwich after Mass',
));
$normalizer_output = CBP_Weekly_Normalizer::normalize($normalizer_input, $normalizer_text);
$normalizer_masses = $normalizer_output['weekly']['masses'] ?? array();
$normalizer_events = $normalizer_output['weekly']['events'] ?? array();

if (($normalizer_masses[0]['title'] ?? '') !== 'Liturgy of the Word') {
    fwrite(STDERR, "Pre-save normalizer regression: off-site Liturgy was not reclassified before review save.\n");
    exit(1);
}

$heritage = array_values(array_filter($normalizer_events, function ($row) {
    $text = strtolower((string) ($row['title'] ?? '') . ' ' . (string) ($row['description'] ?? '') . ' ' . (string) ($row['location'] ?? ''));
    return strpos($text, 'heritage') !== false || strpos($text, 'liturgy of the word') !== false;
}));
if (count($heritage) !== 1
    || ($heritage[0]['date'] ?? '') !== '2026-10-14'
    || ($heritage[0]['time'] ?? '') !== '10:00 AM'
    || ($heritage[0]['location'] ?? '') !== 'Heritage Living Center'
    || ($heritage[0]['title'] ?? '') !== 'Liturgy of the Word') {
    fwrite(STDERR, "Pre-save normalizer regression: Heritage duplicate was not collapsed before review save.\n");
    exit(1);
}

$soup = array_values(array_filter($normalizer_events, function ($row) {
    return ($row['title'] ?? '') === 'Soup & Sandwich';
}));
if (count($soup) !== 1
    || ($soup[0]['date'] ?? '') !== '2026-10-18'
    || ($soup[0]['time'] ?? '') !== 'After Mass'
    || ($soup[0]['location'] ?? '') !== 'St. Mary’s') {
    fwrite(STDERR, "Pre-save normalizer regression: St. Mary's after-Mass event was not recovered before review save.\n");
    exit(1);
}

$display_reflection = new ReflectionClass('CBP_Site_Displays');
$display = $display_reflection->getMethod('instance')->invoke(null);
$event_note = $display_reflection->getMethod('event_note');
$event_note->setAccessible(true);
$detail = $event_note->invoke($display, 'Men’s Burger & Beer-All Men are welcome (Call office before 4:00 pm for location)');
if ($detail !== 'All Men are welcome (Call office before 4:00 pm for location)') {
    fwrite(STDERR, "Site display regression: authoritative Burger & Beer detail was not preserved.\n");
    exit(1);
}

// The homepage should show the regular Saturday vigil on First Saturday even
// though the detailed weekly calendar correctly contains both Masses.
$home_schedule = CBP_Home_Schedule::instance();
$home_reflection = new ReflectionClass('CBP_Home_Schedule');
$apply_weekend = $home_reflection->getMethod('apply_upcoming_weekend_masses');
$apply_weekend->setAccessible(true);

$month_start = new DateTimeImmutable('first day of next month', new DateTimeZone('UTC'));
$days_to_saturday = (6 - (int) $month_start->format('N') + 7) % 7;
$first_saturday = $month_start->modify('+' . $days_to_saturday . ' days');
$ordinary_saturday = $first_saturday->modify('+7 days');
$standing = wp_parse_args(array('st_peter_saturday' => '5:00 PM'), CBP_Schedule::defaults());
$stale_standing = wp_parse_args(array('st_peter_saturday' => '9:00 AM'), CBP_Schedule::defaults());

cbp_regression_reset_wordpress_state();
$GLOBALS['cbp_regression_options'][CBP_Schedule::WEEKLY_OPTION] = wp_parse_args(array(
    'masses' => array(
        array('date' => $first_saturday->format('Y-m-d'), 'time' => '9:00 AM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => 'First Saturday intention'),
        array('date' => $first_saturday->format('Y-m-d'), 'time' => '5:00 PM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => 'Regular vigil intention'),
    ),
), CBP_Schedule::weekly_defaults());
$first_saturday_result = $apply_weekend->invoke($home_schedule, $stale_standing);
if (($first_saturday_result['st_peter_saturday'] ?? '') !== '5:00 PM') {
    fwrite(STDERR, "Homepage regression: First Saturday did not select the later regular vigil independently of the stored standing time.\n");
    exit(1);
}

cbp_regression_reset_wordpress_state();
$GLOBALS['cbp_regression_options'][CBP_Schedule::WEEKLY_OPTION] = wp_parse_args(array(
    'masses' => array(
        array('date' => $first_saturday->format('Y-m-d'), 'time' => '9:00 AM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => 'First Saturday intention'),
    ),
), CBP_Schedule::weekly_defaults());
$first_saturday_only_result = $apply_weekend->invoke($home_schedule, $standing);
if (($first_saturday_only_result['st_peter_saturday'] ?? '') !== '5:00 PM') {
    fwrite(STDERR, "Homepage regression: First Saturday extra Mass was substituted when the regular vigil was absent.\n");
    exit(1);
}

cbp_regression_reset_wordpress_state();
$GLOBALS['cbp_regression_options'][CBP_Schedule::WEEKLY_OPTION] = wp_parse_args(array(
    'masses' => array(
        array('date' => $ordinary_saturday->format('Y-m-d'), 'time' => '4:30 PM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => 'One-time Saturday schedule'),
    ),
), CBP_Schedule::weekly_defaults());
$ordinary_saturday_result = $apply_weekend->invoke($home_schedule, $standing);
if (($ordinary_saturday_result['st_peter_saturday'] ?? '') !== '4:30 PM') {
    fwrite(STDERR, "Homepage regression: ordinary Saturday dated Mass no longer overrides the standing time.\n");
    exit(1);
}

/**
 * Build a tiny deterministic PDF from a UTF-8 text fixture.
 *
 * Original bulletin.pdf fixtures are preferred. This connector-friendly
 * fallback still exercises the real PDF library and parser while keeping the
 * human-reviewed fixture source in text form.
 */
function cbp_pdf_from_text_fixture($text_path)
{
    $text = file_get_contents($text_path);
    if ($text === false) {
        throw new RuntimeException('Could not read text fixture: ' . $text_path);
    }
    $lines = preg_split('/\R/u', $text);
    if (! is_array($lines)) {
        $lines = array($text);
    }

    $pages = array_chunk($lines, 67);
    $objects = array();
    $page_ids = array();
    $next_id = 3;
    foreach ($pages as $page_lines) {
        $page_id = $next_id++;
        $content_id = $next_id++;
        $page_ids[] = $page_id;
        $stream = "BT\n/F1 8 Tf\n10 TL\n36 756 Td\n";
        foreach ($page_lines as $line) {
            $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $line);
            if ($encoded === false) {
                $encoded = (string) $line;
            }
            $encoded = str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $encoded);
            $stream .= '(' . $encoded . ") Tj\nT*\n";
        }
        $stream .= "ET\n";
        $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 99 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
        $objects[$content_id] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }

    $kids = implode(' ', array_map(function ($id) { return $id . ' 0 R'; }, $page_ids));
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($page_ids) . ' >>';
    $objects[99] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    ksort($objects, SORT_NUMERIC);

    $max_id = max(array_keys($objects));
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = array(0 => 0);
    for ($id = 1; $id <= $max_id; $id++) {
        if (! isset($objects[$id])) {
            continue;
        }
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($max_id + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $max_id; $id++) {
        $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 00000 f \n";
    }
    $pdf .= "trailer\n<< /Size " . ($max_id + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

    $tmp = tempnam(sys_get_temp_dir(), 'cbp-regression-');
    if ($tmp === false || file_put_contents($tmp, $pdf) === false) {
        throw new RuntimeException('Could not create temporary PDF fixture.');
    }
    return $tmp;
}

function cbp_invoke_private($object, $method, array $args = array())
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

function cbp_extract_fixture($pdf, $bulletin_date, array $current_schedule)
{
    cbp_regression_reset_wordpress_state();

    $preview_key = 'cbp_preview_1';
    $review_key = 'cbp_schedule_review_1';
    $GLOBALS['cbp_regression_transients'][$preview_key] = array(
        'path' => $pdf,
        'date' => $bulletin_date,
    );
    $GLOBALS['cbp_regression_options'][CBP_Schedule::OPTION] = wp_parse_args(
        $current_schedule,
        CBP_Schedule::defaults()
    );

    // V5 is the latest class that owns the primary extraction request. Mirror
    // its extraction path without the HTTP redirect/exit used in wp-admin.
    $v4 = CBP_Schedule_V4::instance();
    $extracted = cbp_invoke_private($v4, 'pdf_text_by_page', array($pdf));
    if (is_wp_error($extracted)) {
        throw new RuntimeException($extracted->get_error_message());
    }

    $v2 = CBP_Schedule_V2::instance();
    $parsed = cbp_invoke_private($v2, 'parse', array($extracted['text'], $bulletin_date));

    $v5 = CBP_Schedule_V5::instance();
    $parsed = cbp_invoke_private($v5, 'fix_ordinal_spacing', array($parsed));
    $parsed = cbp_invoke_private($v5, 'recover_parish_events', array($parsed, $extracted['text'], $bulletin_date));

    $debug = cbp_invoke_private($v4, 'debug_lines', array($extracted));
    $existing = isset($parsed['source_lines']) && is_array($parsed['source_lines'])
        ? $parsed['source_lines']
        : array();
    $parsed['source_lines'] = array_values(array_unique(array_merge($debug, $existing)));

    // Match production test66: reconcile the complete weekly result before
    // saving the review transient.
    $parsed = CBP_Weekly_Normalizer::normalize($parsed, $extracted['text']);
    $GLOBALS['cbp_regression_transients'][$review_key] = $parsed;

    // V6-V33 remain historical shutdown post-processors. V34 parser mutation
    // and V35-V37 late cleanup are retired in production; V34 remains loaded
    // only for the review-edit abilities.
    $_REQUEST['action'] = 'cbp_extract_schedule';
    foreach (range(6, 33) as $version) {
        $class = 'CBP_Schedule_V' . $version;
        if (! class_exists($class) || ! method_exists($class, 'postprocess_review')) {
            continue;
        }
        $class::instance()->postprocess_review();
    }

    $review = get_transient($review_key);
    if (! is_array($review)) {
        throw new RuntimeException('Parser did not leave review data in the expected transient.');
    }
    return $review;
}

function cbp_value($row, $key)
{
    return isset($row[$key]) ? (string) $row[$key] : '';
}

function cbp_row_identity(array $row)
{
    return implode('|', array(
        cbp_value($row, 'date'),
        cbp_value($row, 'time'),
        cbp_value($row, 'location'),
        cbp_value($row, 'title'),
        cbp_value($row, 'description'),
    ));
}

function cbp_sorted_rows(array $rows)
{
    $normalized = array();
    foreach ($rows as $row) {
        if (! is_array($row)) {
            continue;
        }
        $normalized[] = array(
            'date' => cbp_value($row, 'date'),
            'time' => cbp_value($row, 'time'),
            'location' => cbp_value($row, 'location'),
            'title' => cbp_value($row, 'title'),
            'description' => cbp_value($row, 'description'),
        );
    }
    usort($normalized, function ($a, $b) {
        return strcmp(cbp_row_identity($a), cbp_row_identity($b));
    });
    return $normalized;
}

function cbp_rule_matches_row(array $rule, array $row)
{
    foreach ($rule as $key => $expected) {
        if (substr($key, -9) === '_contains') {
            $field = substr($key, 0, -9);
            if (stripos(cbp_value($row, $field), (string) $expected) === false) {
                return false;
            }
            continue;
        }
        if (cbp_value($row, $key) !== (string) $expected) {
            return false;
        }
    }
    return true;
}

function cbp_compare_fixture(array $expected, array $actual)
{
    $errors = array();
    $expect = isset($expected['expected']) && is_array($expected['expected'])
        ? $expected['expected']
        : array();

    if (! empty($expect['candidates']) && is_array($expect['candidates'])) {
        $actual_candidates = isset($actual['candidates']) && is_array($actual['candidates'])
            ? $actual['candidates']
            : array();
        foreach ($expect['candidates'] as $key => $value) {
            $got = isset($actual_candidates[$key]) ? (string) $actual_candidates[$key] : '';
            if ($got !== (string) $value) {
                $errors[] = "candidate {$key}: expected " . json_encode($value, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($got, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    $actual_weekly = isset($actual['weekly']) && is_array($actual['weekly']) ? $actual['weekly'] : array();

    if (isset($expect['masses']) && is_array($expect['masses'])) {
        $wanted = cbp_sorted_rows($expect['masses']);
        $got = cbp_sorted_rows(isset($actual_weekly['masses']) && is_array($actual_weekly['masses']) ? $actual_weekly['masses'] : array());
        if ($wanted !== $got) {
            $errors[] = "Mass rows differ.\n    expected: " . json_encode($wanted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n    actual:   " . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    foreach (array('devotions_required' => 'devotions', 'events_required' => 'events') as $expect_key => $actual_key) {
        if (empty($expect[$expect_key]) || ! is_array($expect[$expect_key])) {
            continue;
        }
        $rows = isset($actual_weekly[$actual_key]) && is_array($actual_weekly[$actual_key]) ? $actual_weekly[$actual_key] : array();
        foreach ($expect[$expect_key] as $rule) {
            $matched = false;
            foreach ($rows as $row) {
                if (is_array($row) && cbp_rule_matches_row($rule, $row)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $errors[] = $actual_key . ' missing required row: ' . json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }

    foreach (array('devotions_forbidden' => 'devotions', 'events_forbidden' => 'events') as $expect_key => $actual_key) {
        if (empty($expect[$expect_key]) || ! is_array($expect[$expect_key])) {
            continue;
        }
        $rows = isset($actual_weekly[$actual_key]) && is_array($actual_weekly[$actual_key]) ? $actual_weekly[$actual_key] : array();
        foreach ($expect[$expect_key] as $rule) {
            foreach ($rows as $row) {
                if (is_array($row) && cbp_rule_matches_row($rule, $row)) {
                    $errors[] = $actual_key . ' contains forbidden row: '
                        . json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        . ' matched '
                        . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    break;
                }
            }
        }
    }

    if (! empty($expect['counts']) && is_array($expect['counts'])) {
        foreach ($expect['counts'] as $key => $count) {
            $got = isset($actual_weekly[$key]) && is_array($actual_weekly[$key]) ? count($actual_weekly[$key]) : 0;
            if ($got !== (int) $count) {
                $errors[] = "{$key} count: expected {$count}, got {$got}";
            }
        }
    }

    if (! empty($expect['livestream_contains'])) {
        $livestream = isset($actual_weekly['livestream']) && is_array($actual_weekly['livestream']) ? $actual_weekly['livestream'] : array();
        $text = '';
        foreach ($livestream as $row) {
            $text .= ' ' . (is_array($row) ? cbp_value($row, 'description') : (string) $row);
        }
        foreach ((array) $expect['livestream_contains'] as $needle) {
            if (stripos($text, (string) $needle) === false) {
                $errors[] = 'livestream missing text: ' . json_encode($needle, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    if (! empty($expect['warning_contains'])) {
        $warning_text = '';
        foreach ((array) ($actual['warnings'] ?? array()) as $warning) {
            if (is_array($warning)) {
                $warning_text .= ' ' . ($warning['message'] ?? '') . ' ' . ($warning['source'] ?? '');
            } else {
                $warning_text .= ' ' . (string) $warning;
            }
        }
        foreach ((array) $expect['warning_contains'] as $needle) {
            if (stripos($warning_text, (string) $needle) === false) {
                $errors[] = 'warnings missing text: ' . json_encode($needle, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    return $errors;
}

$fixture_root = $root . '/tests/fixtures';
$fixture_dirs = array_values(array_filter(glob($fixture_root . '/*'), 'is_dir'));
sort($fixture_dirs, SORT_STRING);

if (empty($fixture_dirs)) {
    fwrite(STDERR, "No regression fixtures found under tests/fixtures.\n");
    exit(2);
}

$failures = 0;
$passes = 0;
foreach ($fixture_dirs as $dir) {
    $json_path = $dir . '/expected.json';
    $pdf_path = $dir . '/bulletin.pdf';
    $text_path = $dir . '/bulletin.txt';
    $name = basename($dir);
    $temp_pdf = '';

    if (! is_readable($json_path)) {
        echo $name . "  FAIL\n";
        echo "  fixture must contain expected.json\n";
        $failures++;
        continue;
    }

    if (! is_readable($pdf_path)) {
        if (is_readable($text_path)) {
            try {
                $temp_pdf = cbp_pdf_from_text_fixture($text_path);
                $pdf_path = $temp_pdf;
            } catch (Throwable $e) {
                echo $name . "  FAIL\n";
                echo "  could not build text-backed PDF fixture: " . $e->getMessage() . "\n";
                $failures++;
                continue;
            }
        } else {
            echo $name . "  FAIL\n";
            echo "  fixture must contain bulletin.pdf (preferred) or bulletin.txt plus expected.json\n";
            $failures++;
            continue;
        }
    }

    $expected = json_decode(file_get_contents($json_path), true);
    if (! is_array($expected)) {
        echo $name . "  FAIL\n";
        echo "  expected.json is not valid JSON\n";
        $failures++;
        continue;
    }

    try {
        $actual = cbp_extract_fixture(
            $pdf_path,
            (string) ($expected['bulletin_date'] ?? ''),
            isset($expected['current_schedule']) && is_array($expected['current_schedule']) ? $expected['current_schedule'] : array()
        );
        $errors = cbp_compare_fixture($expected, $actual);
    } catch (Throwable $e) {
        $errors = array($e->getMessage());
    }

    if (empty($errors)) {
        echo $name . "  PASS\n";
        $passes++;
    } else {
        echo $name . "  FAIL\n";
        foreach ($errors as $error) {
            foreach (explode("\n", $error) as $line) {
                echo '  ' . $line . "\n";
            }
        }
        $failures++;
    }

    if ($temp_pdf !== '' && is_file($temp_pdf)) {
        @unlink($temp_pdf);
    }
}

echo "\n" . ($passes + $failures) . " fixture(s): {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
