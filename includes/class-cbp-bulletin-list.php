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
    }

    public function render($attributes)
    {
        $attributes = shortcode_atts(
            array(
                'mode' => 'live',
                'limit' => 60,
                'columns' => 2,
            ),
            $attributes,
            'church_bulletins'
        );

        $test_mode = $attributes['mode'] === 'test';
        $limit = max(1, min(200, absint($attributes['limit'])));
        $columns = max(1, min(4, absint($attributes['columns'])));
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

        if ($columns <= 1) {
            $rows = '';
            foreach ($items as $item) {
                $rows .= '<tr><td><a target="_blank" rel="noopener" href="' . esc_url($item['href']) . '">' . esc_html($item['label']) . '</a></td></tr>';
            }

            return '<table class="church-bulletins"><thead><tr><th>'
                . esc_html__('Weekly Bulletins', 'church-bulletin-publisher')
                . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        // Split top-to-bottom into nearly equal columns. With 15 bulletins and
        // two columns this produces 8 in the first column and 7 in the second.
        $columns = min($columns, count($items));
        $per_column = (int) ceil(count($items) / $columns);
        $chunks = array_chunk($items, $per_column);

        $html = '<div class="church-bulletins church-bulletins--columns">';
        $html .= '<div style="text-align:center;font-weight:600;margin-bottom:14px;">'
            . esc_html__('Weekly Bulletins', 'church-bulletin-publisher')
            . '</div>';
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

        $html .= '</div></div>';
        return $html;
    }
}
