<?php
/**
 * Homepage regression: St. Mary's Hunter's Mass replaces Sunday Mass
 * for the week of November 2–8, 2026, without changing future weeks.
 *
 * The fixture is based on the manually approved production weekly rows.
 * This file is included by run.php after the WordPress stubs and classes.
 */
$home = CBP_Home_Schedule::instance();
$weekend_lines = new ReflectionMethod($home, 'st_mary_weekend_lines');
$weekend_lines->setAccessible(true);

$week = array(
    'week_start' => '2026-11-02',
    'week_end' => '2026-11-08',
    'masses' => array(
        array('date' => '2026-11-07', 'time' => '9:00 AM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => '† Katie Doris'),
        array('date' => '2026-11-07', 'time' => '5:00 PM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => 'For the People'),
        array('date' => '2026-11-07', 'time' => '8:00 PM', 'location' => 'St. Mary’s', 'title' => 'Mass', 'description' => '† Scott W. Gartner'),
        array('date' => '2026-11-08', 'time' => '', 'location' => 'St. Mary’s', 'title' => 'No Mass', 'description' => 'Sunday, November 8th NO MASS at St. Mary’s'),
        array('date' => '2026-11-08', 'time' => '9:00 AM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => '† Jeanne Hanson'),
    ),
);

$expected = array(
    array('day' => 'Saturday', 'time' => '8:00 PM'),
    array('day' => 'Sunday', 'time' => 'No Mass'),
);

foreach (array('2026-11-02', '2026-11-07', '2026-11-08') as $day) {
    $actual = $weekend_lines->invoke($home, $week, $day);
    if ($actual !== $expected) {
        fwrite(STDERR, "St. Mary's homepage exception regression on {$day}: " . json_encode($actual) . "\n");
        exit(1);
    }
}

foreach (array('2026-11-01', '2026-11-09') as $day) {
    if ($weekend_lines->invoke($home, $week, $day) !== array()) {
        fwrite(STDERR, "St. Mary's dated exception leaked outside the approved week on {$day}.\n");
        exit(1);
    }
}

// A future week should use its own approved Sunday Mass, not November's
// Saturday exception or an outdated "No Mass" cancellation.
$next_week = array(
    'week_start' => '2026-11-09',
    'week_end' => '2026-11-15',
    'masses' => array(
        array('date' => '2026-11-15', 'time' => '11:00 AM', 'location' => 'St. Mary’s', 'title' => 'Mass', 'description' => ''),
    ),
);
$actual = $weekend_lines->invoke($home, $next_week, '2026-11-09');
if ($actual !== array(array('day' => 'Sunday', 'time' => '11:00 AM'))) {
    fwrite(STDERR, "St. Mary's normal Sunday schedule did not resume in the next approved week.\n");
    exit(1);
}

// An explicit cancellation wins even if a stale/duplicated normal Sunday
// Mass row is present. Do not display two contradictory Sunday Mass notices.
$with_stale_sunday = $week;
$with_stale_sunday['masses'][] = array(
    'date' => '2026-11-08', 'time' => '11:00 AM', 'location' => 'St. Mary’s',
    'title' => 'Mass', 'description' => '',
);
if ($weekend_lines->invoke($home, $with_stale_sunday, '2026-11-02') !== $expected) {
    fwrite(STDERR, "St. Mary's explicit Sunday cancellation did not override stale Sunday Mass.\n");
    exit(1);
}

// A week containing only St. Peter's Masses must fall back to the standing
// St. Mary's Sunday schedule, rather than claiming St. Mary's has no Mass.
$other_parish_only = $week;
$other_parish_only['masses'] = array($week['masses'][0], $week['masses'][1]);
if ($weekend_lines->invoke($home, $other_parish_only, '2026-11-02') !== array()) {
    fwrite(STDERR, "Missing St. Mary's data incorrectly replaced recurring Sunday schedule.\n");
    exit(1);
}

// Date labels must match the active WordPress-local calendar week, not a
// hard-coded seasonal schedule or the most recently imported bulletin date.
$date_method = new ReflectionMethod($home, 'weekend_dates_for');
$date_method->setAccessible(true);
$date_cases = array(
    '2026-11-02' => array('saturday' => '2026-11-07', 'sunday' => '2026-11-08'),
    '2026-11-07' => array('saturday' => '2026-11-07', 'sunday' => '2026-11-08'),
    '2026-11-08' => array('saturday' => '2026-11-07', 'sunday' => '2026-11-08'),
    '2026-11-09' => array('saturday' => '2026-11-14', 'sunday' => '2026-11-15'),
    '2026-12-31' => array('saturday' => '2027-01-02', 'sunday' => '2027-01-03'),
);
foreach ($date_cases as $today => $expected_dates) {
    $actual_dates = $date_method->invoke($home, $today);
    if ($actual_dates !== $expected_dates) {
        fwrite(STDERR, "Homepage weekend dates incorrect for {$today}: " . json_encode($actual_dates) . "\n");
        exit(1);
    }
}
if ($date_method->invoke($home, 'invalid') !== array('saturday' => '', 'sunday' => '')) {
    fwrite(STDERR, "Homepage accepted an invalid local date.\n");
    exit(1);
}

$format_method = new ReflectionMethod($home, 'format_mass_date');
$format_method->setAccessible(true);
if ($format_method->invoke($home, '2026-11-07') !== 'Saturday, Nov 7'
    || $format_method->invoke($home, '2026-11-08') !== 'Sunday, Nov 8') {
    fwrite(STDERR, "Homepage Mass dates are not formatted with the correct weekday.\n");
    exit(1);
}

// An already-approved bulletin for NEXT week must not change this week's
// homepage times prematurely, even if the next Saturday is in the future.
$this_weekend = $date_method->invoke($home, wp_date('Y-m-d'));
$next_saturday = (new DateTimeImmutable($this_weekend['saturday']))->modify('+7 days')->format('Y-m-d');
$GLOBALS['cbp_regression_options'][CBP_Schedule::WEEKLY_OPTION] = array(
    'week_start' => (new DateTimeImmutable($next_saturday))->modify('-5 days')->format('Y-m-d'),
    'week_end' => (new DateTimeImmutable($next_saturday))->modify('+1 day')->format('Y-m-d'),
    'masses' => array(
        array('date' => $next_saturday, 'time' => '7:15 PM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => ''),
    ),
);
$apply_method = new ReflectionMethod($home, 'apply_upcoming_weekend_masses');
$apply_method->setAccessible(true);
$standing = CBP_Schedule::defaults();
$standing['st_peter_saturday'] = '5:00 PM';
$not_early = $apply_method->invoke($home, $standing);
if ($not_early['st_peter_saturday'] !== '5:00 PM') {
    fwrite(STDERR, "Next week's approved Saturday time replaced this week's Mass prematurely.\n");
    exit(1);
}

// Test the actual homepage HTML with a current-week exception, including
// a Sunday cancellation, rather than only exercising the date helper.
if (! defined('CBP_VERSION')) {
    define('CBP_VERSION', 'regression');
}
if (! defined('CBP_URL')) {
    define('CBP_URL', 'https://example.test/wp-content/plugins/church-bulletin-publisher/');
}
$now_dates = $date_method->invoke($home, wp_date('Y-m-d'));
$week_start = (new DateTimeImmutable($now_dates['saturday']))->modify('-5 days')->format('Y-m-d');
$GLOBALS['cbp_regression_options'][CBP_Schedule::WEEKLY_OPTION] = array(
    'week_start' => $week_start,
    'week_end' => $now_dates['sunday'],
    'masses' => array(
        array('date' => $now_dates['saturday'], 'time' => '5:00 PM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => ''),
        array('date' => $now_dates['saturday'], 'time' => '8:00 PM', 'location' => 'St. Mary’s', 'title' => 'Mass', 'description' => ''),
        array('date' => $now_dates['sunday'], 'time' => '9:00 AM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => ''),
        array('date' => $now_dates['sunday'], 'time' => '', 'location' => 'St. Mary’s', 'title' => 'No Mass', 'description' => ''),
    ),
);
$GLOBALS['cbp_regression_options'][CBP_Schedule::OPTION] = $standing;
$html = $home->shortcode(array('mode' => 'home'));
if (substr_count($html, 'class="cbp-home-mass-date"') !== 4
    || substr_count($html, 'class="cbp-home-mass-entry"') !== 4
    || substr_count($html, 'class="cbp-home-mass-card') !== 2
    || strpos($html, '<strong>8:00 PM</strong>') === false
    || strpos($html, '<strong>No Mass</strong>') === false) {
    fwrite(STDERR, "Homepage did not render four date-labeled entries with the special St. Mary's schedule.\n");
    exit(1);
}
$formatted_saturday = $format_method->invoke($home, $now_dates['saturday']);
$formatted_sunday = $format_method->invoke($home, $now_dates['sunday']);
if (substr_count($html, '>' . $formatted_saturday . '</span>') !== 2
    || substr_count($html, '>' . $formatted_sunday . '</span>') !== 2
    || strpos($html, '>' . $formatted_saturday . '</span>\n                                <strong>8:00 PM</strong>') === false) {
    fwrite(STDERR, "Homepage dated Mass entries do not align with their actual times.\n");
    exit(1);
}
cbp_regression_reset_wordpress_state();
