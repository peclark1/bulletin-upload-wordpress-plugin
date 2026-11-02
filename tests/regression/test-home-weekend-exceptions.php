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
