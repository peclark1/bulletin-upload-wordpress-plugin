<?php
/** Byte-preserving adapter for the evidenced first top-level Gutenberg Cover hero. */
final class CSC_Hero {
    public static function inspect($content) {
        // Deliberately refuse nested/HTML/other hero implementations rather than guess.
        if (!is_string($content) || !preg_match('/\A\s*<!-- wp:cover (\{[^\r\n]*\}) -->\s*(<div\b[^>]*>)/', $content, $m, PREG_OFFSET_CAPTURE)) {
            return new WP_Error('csc_unsupported_hero', 'Expected a first top-level Cover with single-line attributes and a div wrapper.');
        }
        $attrs = json_decode($m[1][0], true);
        $value = $attrs['style']['spacing']['padding']['top'] ?? null;
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,2})px\z/', $value)) {
            return new WP_Error('csc_unsupported_hero', 'Cover top padding must be an explicit integer px value.');
        }
        // Pin the precise attribute token, requiring only one padding object/top key.
        if (preg_match_all('/"padding"\s*:\s*\{[^{}]*\}/', $m[1][0], $pads, PREG_OFFSET_CAPTURE) !== 1 ||
            preg_match_all('/"top"\s*:\s*"([0-9]+px)"/', $pads[0][0][0], $tops, PREG_OFFSET_CAPTURE) !== 1 ||
            $tops[1][0][0] !== $value) {
            return new WP_Error('csc_unsupported_hero', 'Ambiguous Cover padding attributes.');
        }
        $tag = $m[2][0];
        if (!preg_match('/\bclass="([^"]*)"/', $tag, $classes) ||
            !in_array('wp-block-cover', preg_split('/\s+/', $classes[1]), true) ||
            preg_match_all('/\bstyle="([^"]*)"/', $tag, $styles, PREG_OFFSET_CAPTURE) !== 1 ||
            preg_match_all('/(?:^|;)\s*padding-top\s*:\s*([0-9]+px)(?=;|$)/', $styles[1][0][0], $css, PREG_OFFSET_CAPTURE) !== 1 ||
            $css[1][0][0] !== $value) {
            return new WP_Error('csc_unsupported_hero', 'Saved Cover HTML and attribute padding disagree or are ambiguous.');
        }
        return array(
            'field' => 'padding_top_px', 'value' => (int) $value,
            'attribute_path' => 'core/cover[0].style.spacing.padding.top',
            'spans' => array(
                array($m[1][1] + $pads[0][0][1] + $tops[1][0][1], strlen($value)),
                array($m[2][1] + $styles[1][0][1] + $css[1][0][1], strlen($value)),
            ),
        );
    }

    public static function patch($content, $value) {
        if (!is_int($value) || $value < 0 || $value > 200) {
            return new WP_Error('csc_invalid_value', 'padding_top_px must be an integer from 0 through 200.');
        }
        $hero = self::inspect($content);
        if (is_wp_error($hero)) { return $hero; }
        foreach (array_reverse($hero['spans']) as $span) {
            $content = substr_replace($content, $value . 'px', $span[0], $span[1]);
        }
        return $content;
    }
}
