<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Front-end bulletin list renderer.
 *
 * This intentionally re-registers the existing church_bulletins shortcode so
 * the public list can support responsive, balanced columns without requiring
 * theme CSS or a site-wide Customizer change.
 */
final class CBP_Bulletin_List
{
    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode('church_bulletins', array($this, 'render'));

        // Early staging versions placed the two-column CSS in a Gutenberg
        // Custom HTML block without <style> tags, causing the CSS itself to be
        // printed above the bulletin list. The shortcode now handles its own
        // layout, so suppress that known legacy block if it is still present.
        add_filter('render_block', array($this, 'strip_legacy_column_css_block'), 10, 2);
    }

    public function strip_legacy_column_css_block($block_content, $block)
    {
        if (
            isset($block['blockName'])
            && $block['blockName'] === 'core/html'
            && strpos($block_content, '.bulletin-two-columns{columns:2 280px;column-gap:42px;column-fill:balance}') !== false
        ) {
            return '';
        }

        return $block_content;
    }

    public function render($attributes)
    {
        $attributes = shortcode_atts(
            array(
                'mode' => 'live',
                'limit' => 60,
                'columns' => 2,
                'featured' => 'yes',
            ),
            $attributes,
            'church_bulletins'
        );

        $test_mode = $attributes['mode'] === 'test';
        $limit = max(1, min(200, absint($attributes['limit'])));
        $columns = max(1, min(4, absint($attributes['columns'])));
        $show_featured = strtolower((string) $attributes['featured']) !== 'no';
        $root = CBP_Storage::published_root($test_mode);
        $url = CBP_Storage::published_url($test_mode);
        $files = glob(trailingslashit($root) . '[0-9][0-9][0-9][0-9]/[0-9][0-9][0-9][0-9][0-9][0-9]bulletin.pdf');

        if (! $files) {
            return '<p>' . esc_html__('No bulletins have been published yet.', 'church-bulletin-publisher') . '</p>';
        }

        rsort($files, SORT_STRING);
        $files = array_slice($files, 0, $limit);

        $items = array();
        foreach ($files as $file) {
            $year = basename(dirname($file));
            $name = basename($file);
            $date = DateTimeImmutable::createFromFormat('!mdy', substr($name, 0, 6));
            if (! $date) {
                continue;
            }

            $href = trailingslashit($url) . rawurlencode($year) . '/' . rawurlencode($name);
            $items[] = array(
                'href' => $href,
                'label' => $date->format('F j, Y'),
            );
        }

        if (! $items) {
            return '<p>' . esc_html__('No bulletins have been published yet.', 'church-bulletin-publisher') . '</p>';
        }

        $html = '<div class="cbp-bulletin-list">';

        if ($show_featured) {
            $html .= $this->render_featured($items[0]);
            $items = array_slice($items, 1);
        }

        if (! $items) {
            return $html . '</div>';
        }

        $list_title = $show_featured
            ? esc_html__('Previous Bulletins', 'church-bulletin-publisher')
            : esc_html__('Weekly Bulletins', 'church-bulletin-publisher');

        if ($columns <= 1) {
            $rows = '';
            foreach ($items as $item) {
                $rows .= '<tr><td><a target="_blank" rel="noopener" href="' . esc_url($item['href']) . '">' . esc_html($item['label']) . '</a></td></tr>';
            }

            $html .= '<table class="church-bulletins"><thead><tr><th>'
                . $list_title
                . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';

            return $html . '</div>';
        }

        // Split top-to-bottom into nearly equal columns.
        $columns = min($columns, count($items));
        $per_column = (int) ceil(count($items) / $columns);
        $chunks = array_chunk($items, $per_column);

        $html .= '<div class="church-bulletins church-bulletins--columns">';
        $html .= '<div class="cbp-bulletin-list__archive-title">' . $list_title . '</div>';
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:0 42px;align-items:flex-start;">';

        foreach ($chunks as $chunk) {
            $html .= '<div style="flex:1 1 280px;min-width:0;">';
            foreach ($chunk as $item) {
                $html .= '<div style="margin:0 0 8px;break-inside:avoid;">'
                    . '<a target="_blank" rel="noopener" href="' . esc_url($item['href']) . '">' . esc_html($item['label']) . '</a>'
                    . '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div></div></div>';
        return $html;
    }

    private function render_featured($item)
    {
        $href = esc_url($item['href']);
        $label = esc_html($item['label']);
        $aria = esc_attr(sprintf(__('Open bulletin for %s', 'church-bulletin-publisher'), $item['label']));

        $html = '<div class="cbp-featured-bulletin">';
        $html .= '<a class="cbp-featured-bulletin__cover" target="_blank" rel="noopener" href="' . $href . '" aria-label="' . $aria . '">';
        $html .= '<span class="cbp-featured-bulletin__cover-kicker">' . esc_html__('Weekly Bulletin', 'church-bulletin-publisher') . '</span>';
        $html .= '<span class="cbp-featured-bulletin__cover-title">' . esc_html__('St. Peter the Apostle', 'church-bulletin-publisher') . '<br>&amp; ' . esc_html__('St. Mary’s Two Inlets', 'church-bulletin-publisher') . '</span>';
        $html .= '<span class="cbp-featured-bulletin__cover-date">' . $label . '</span>';
        $html .= '<span class="cbp-featured-bulletin__pdf">PDF</span>';
        $html .= '</a>';

        $html .= '<div class="cbp-featured-bulletin__body">';
        $html .= '<div class="cbp-featured-bulletin__eyebrow">' . esc_html__('Latest Edition', 'church-bulletin-publisher') . '</div>';
        $html .= '<h3>' . $label . '</h3>';
        $html .= '<p>' . esc_html__('Open this week’s bulletin for Mass schedules, parish announcements, events, faith formation, and news from both church communities.', 'church-bulletin-publisher') . '</p>';
        $html .= '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" target="_blank" rel="noopener" href="' . $href . '">' . esc_html__('Read This Week’s Bulletin', 'church-bulletin-publisher') . '</a></div>';
        $html .= '</div></div>';

        return $html;
    }
}
