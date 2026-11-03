<?php
/**
 * The Mass Times page's bulletin-backed Livestream note should link directly
 * to the parish's existing YouTube channel, without losing approved text.
 */
cbp_regression_reset_wordpress_state();
$weekly = CBP_Schedule::weekly_defaults();
$weekly['week_start'] = '2026-11-02';
$weekly['week_end'] = '2026-11-08';
$weekly['livestream'] = array(array(
    'description' => 'Sunday Mass is streamed from St. Peter’s.',
));
$GLOBALS['cbp_regression_options'][CBP_Schedule::WEEKLY_OPTION] = $weekly;

$display = CBP_Site_Displays::instance();
$full = $display->worship_week_shortcode(array('mode' => 'full'));
$link = 'https://www.youtube.com/channel/UCveuE1nStVP36VFAc2MPFgA';

if (substr_count($full, 'class="cbp-site-note cbp-site-note--link"') !== 1
    || strpos($full, 'href="' . $link . '"') === false
    || strpos($full, 'target="_blank" rel="noopener noreferrer"') === false
    || strpos($full, 'Sunday Mass is streamed from St. Peter’s.') === false
    || strpos($full, 'Watch Mass Livestream on YouTube') === false
    || strpos($full, '</a>') === false) {
    fwrite(STDERR, "Livestream card is missing its accessible link or weekly bulletin text.\n");
    exit(1);
}

// No duplicate livestream cards on the compact view, where the note was
// intentionally omitted before the link change.
$compact = $display->worship_week_shortcode(array('mode' => 'compact'));
if (strpos($compact, 'cbp-site-note--link') !== false) {
    fwrite(STDERR, "Livestream card leaked into the compact worship view.\n");
    exit(1);
}

// If there is no livestream notice in an approved bulletin, preserve
// the prior rule: don't show an otherwise empty link card.
$weekly['livestream'] = array();
$GLOBALS['cbp_regression_options'][CBP_Schedule::WEEKLY_OPTION] = $weekly;
$no_notice = $display->worship_week_shortcode(array('mode' => 'full'));
if (strpos($no_notice, 'cbp-site-note--link') !== false) {
    fwrite(STDERR, "Livestream link appeared without an approved notice.\n");
    exit(1);
}
cbp_regression_reset_wordpress_state();
