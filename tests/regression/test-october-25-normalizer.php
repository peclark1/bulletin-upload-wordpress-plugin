<?php
/**
 * October 25, 2026 regression: Memorial Prayer Service is not a Sunday Mass.
 *
 * Based on the reviewed seven-page bulletin (three bulletin pages, two-page
 * All Souls insert, plus site covers) and the actual pending parser review.
 */
$review = array(
    'bulletin_date' => '2026-10-25',
    'week_start' => '2026-10-26',
    'week_end' => '2026-11-01',
    'candidates' => array('st_peter_sunday' => '9:00 AM'),
    'weekly' => array(
        'masses' => array(
            array('date' => '2026-10-31', 'time' => '5:00 PM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => '† Deceased Christensen Family'),
            array('date' => '2026-11-01', 'time' => '9:00 AM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => '† Ed Hrubsky'),
            array('date' => '2026-11-01', 'time' => '3:00 PM', 'location' => 'St. Peter', 'title' => 'Memorial Service', 'description' => 'Memorial Prayer'),
        ),
        'devotions' => array(),
        'events' => array(
            array('date' => '2026-11-01', 'time' => '', 'location' => '', 'title' => 'for a Memorial Service that will be prayed on Sunday, November', 'description' => ''),
            array('date' => '2026-11-01', 'time' => '', 'location' => '', 'title' => 'who have had a loved one die and their funeral was celebrated', 'description' => ''),
            array('date' => '2026-11-01', 'time' => '', 'location' => 'St. Peter', 'title' => 'Coffee & Rolls St. Vincent de Paul Society', 'description' => ''),
        ),
        'livestream' => array(),
    ),
    'warnings' => array(
        array('type' => 'schedule_change', 'message' => 'False Memorial change', 'source' => '2026-11-01 • 3:00 PM • St. Peter • Memorial Prayer Service'),
        array('type' => 'schedule_change', 'message' => 'Unrelated genuine change', 'source' => '2026-10-31 • 6:00 PM • St. Peter • Mass'),
    ),
);

$raw_text = "Saturday, October 31st, 5:00 p.m.\n"
    . "St. Peter’s, + Deceased Christensen Family\n"
    . "the first commandment as well\n"
    . "Members                               your relationship with God\n"
    . "Sunday, November 1st, 3:00 p.m.\n"
    . "St. Peter’s, Memorial Prayer Service\n";

$fixed = CBP_Weekly_Normalizer::normalize($review, $raw_text);
$masses = $fixed['weekly']['masses'];
$events = $fixed['weekly']['events'];
$warnings = $fixed['warnings'];

if (count($masses) !== 2
    || ($masses[0]['description'] ?? '') !== '† Deceased Christensen Family Members'
    || ($masses[1]['title'] ?? '') !== 'Mass') {
    fwrite(STDERR, "Oct 25 regression: false Mass not removed or wrapped intention lost.\n");
    exit(1);
}
$memorials = array_values(array_filter($events, function ($row) {
    return ($row['date'] ?? '') === '2026-11-01' && ($row['title'] ?? '') === 'Memorial Prayer Service'
        && ($row['time'] ?? '') === '3:00 PM' && ($row['location'] ?? '') === 'St. Peter';
}));
if (count($events) !== 2 || count($memorials) !== 1) {
    fwrite(STDERR, "Oct 25 regression: Memorial Service not recovered exactly once as event or fragments survived.\n");
    exit(1);
}
if (count($warnings) !== 1 || ($warnings[0]['message'] ?? '') !== 'Unrelated genuine change'
    || ($fixed['candidates']['st_peter_sunday'] ?? '') !== '9:00 AM') {
    fwrite(STDERR, "Oct 25 regression: false Memorial schedule warning retained or legitimate schedule changed.\n");
    exit(1);
}

$negative = CBP_Weekly_Normalizer::normalize(array(
    'bulletin_date' => '2026-10-25',
    'weekly' => array(
        'masses' => array(
            array('date' => '2026-11-01', 'time' => '3:00 PM', 'location' => 'St. Peter', 'title' => 'Mass', 'description' => '† Memorial Service Benefactor'),
        ),
        'events' => array(),
    ),
), '');
if (count($negative['weekly']['masses']) !== 1 || ! empty($negative['weekly']['events'])) {
    fwrite(STDERR, "Oct 25 regression: an actual Mass was reclassified based only on its intention.\n");
    exit(1);
}
