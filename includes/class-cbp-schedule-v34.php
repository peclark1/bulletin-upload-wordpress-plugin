<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * October 11 bulletin normalization and safe review-edit abilities.
 *
 * Parser repairs:
 * - classify "Liturgy of the Word" as its own dated worship item instead of Mass;
 * - consolidate split calendar/prose representations of the same off-site Liturgy of the Word;
 * - recover parish-prefixed "... after Mass" events that the weekly parser can drop at a section boundary.
 *
 * Review abilities:
 * - expose the current pending review to authenticated administrators;
 * - allow narrowly scoped edits to review rows and recurring schedule candidates;
 * - require a revision token on mutations so an agent cannot overwrite a newer browser review.
 *
 * These abilities modify only the pending review transient. They do not approve
 * the website update and do not publish a bulletin.
 */
final class CBP_Schedule_V34
{
    const REVIEW_TTL = 2 * DAY_IN_SECONDS;
    const ABILITY_CATEGORY = 'church-bulletin-publisher';

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
        // Parser normalization moved into the primary extraction pipeline in
        // test66. Keep this class instantiated for the review-edit abilities,
        // but do not mutate the saved review during shutdown.
        add_action('wp_abilities_api_categories_init', array($this, 'register_ability_category'));
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
        add_action('init', array($this, 'ensure_abilities_registered'), 99);
    }

    public function postprocess_review()
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== 'cbp_extract_schedule') {
            return;
        }

        $review = get_transient($this->review_key());
        if (! is_array($review) || empty($review['bulletin_date']) || empty($review['weekly']) || ! is_array($review['weekly'])) {
            return;
        }

        $raw = $this->raw_lines($review);
        $bulletin_date = (string) $review['bulletin_date'];

        $review['weekly']['masses'] = $this->normalize_liturgy_mass_rows(
            isset($review['weekly']['masses']) && is_array($review['weekly']['masses']) ? $review['weekly']['masses'] : array()
        );

        $canonical_liturgies = $this->liturgy_events_from_calendar($raw, $bulletin_date);
        if (! empty($canonical_liturgies)) {
            $events = isset($review['weekly']['events']) && is_array($review['weekly']['events'])
                ? $review['weekly']['events']
                : array();
            $review['weekly']['events'] = $this->merge_canonical_liturgy_events($events, $canonical_liturgies);
        }

        $events = isset($review['weekly']['events']) && is_array($review['weekly']['events'])
            ? $review['weekly']['events']
            : array();
        $review['weekly']['events'] = $this->recover_after_mass_events($events, $raw, $bulletin_date);

        if (class_exists('CBP_Schedule_V30')) {
            $review['weekly'] = CBP_Schedule_V30::sort_weekly($review['weekly']);
        }

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v34] Normalized Liturgy of the Word rows and recovered missing parish after-Mass events.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    public function register_ability_category()
    {
        if (! function_exists('wp_register_ability_category')) {
            return;
        }

        $this->register_ability_category_compat(
            self::ABILITY_CATEGORY,
            array(
                'label' => __('Church Bulletin Publisher', 'church-bulletin-publisher'),
                'description' => __('Read and safely edit the current Bulletin Publisher review before human approval and publication.', 'church-bulletin-publisher'),
            )
        );
    }

    public function register_abilities()
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        $this->register_ability_compat(
            'church-bulletin-publisher/get-review',
            array(
                'label' => __('Get bulletin review', 'church-bulletin-publisher'),
                'description' => __('Returns the current pending Bulletin Publisher review for the authenticated administrator, including recurring schedule candidates and weekly rows. This does not approve or publish anything.', 'church-bulletin-publisher'),
                'category' => self::ABILITY_CATEGORY,
                'execute_callback' => array($this, 'ability_get_review'),
                'permission_callback' => array($this, 'ability_permission'),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
                'meta' => array(
                    'public' => true,
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                    'annotations' => array(
                        'readonly' => true,
                        'destructive' => false,
                        'idempotent' => true,
                    ),
                ),
            )
        );

        $this->register_ability_compat(
            'church-bulletin-publisher/update-review-row',
            array(
                'label' => __('Update bulletin review row', 'church-bulletin-publisher'),
                'description' => __('Adds, edits, or deletes one pending review row in masses, devotions, events, or livestream. Requires the revision returned by get-review. Changes remain pending until a human approves the review; this ability never approves or publishes.', 'church-bulletin-publisher'),
                'category' => self::ABILITY_CATEGORY,
                'execute_callback' => array($this, 'ability_update_review_row'),
                'permission_callback' => array($this, 'ability_permission'),
                'input_schema' => array(
                    'type' => 'object',
                    'required' => array('expected_revision', 'section', 'operation'),
                    'properties' => array(
                        'expected_revision' => array('type' => 'string', 'minLength' => 1),
                        'section' => array('type' => 'string', 'enum' => array('masses', 'devotions', 'events', 'livestream')),
                        'operation' => array('type' => 'string', 'enum' => array('add', 'update', 'delete')),
                        'index' => array('type' => 'integer', 'minimum' => 0),
                        'row' => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => array(
                                'date' => array('type' => 'string'),
                                'time' => array('type' => 'string'),
                                'location' => array('type' => 'string'),
                                'title' => array('type' => 'string'),
                                'description' => array('type' => 'string'),
                            ),
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
                'meta' => array(
                    'public' => true,
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                    'annotations' => array(
                        'readonly' => false,
                        'destructive' => true,
                        'idempotent' => false,
                    ),
                ),
            )
        );

        $this->register_ability_compat(
            'church-bulletin-publisher/update-review-candidate',
            array(
                'label' => __('Update bulletin recurring schedule candidate', 'church-bulletin-publisher'),
                'description' => __('Updates one recurring schedule candidate in the current pending Bulletin Publisher review. Requires the revision returned by get-review. This does not approve or publish the change.', 'church-bulletin-publisher'),
                'category' => self::ABILITY_CATEGORY,
                'execute_callback' => array($this, 'ability_update_review_candidate'),
                'permission_callback' => array($this, 'ability_permission'),
                'input_schema' => array(
                    'type' => 'object',
                    'required' => array('expected_revision', 'key', 'value'),
                    'properties' => array(
                        'expected_revision' => array('type' => 'string', 'minLength' => 1),
                        'key' => array(
                            'type' => 'string',
                            'enum' => array('st_peter_saturday', 'st_peter_sunday', 'st_mary_sunday', 'reconciliation', 'adoration'),
                        ),
                        'value' => array('type' => 'string'),
                    ),
                    'additionalProperties' => false,
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
                'meta' => array(
                    'public' => true,
                    'show_in_rest' => true,
                    'mcp' => array(
                        'public' => true,
                    ),
                    'annotations' => array(
                        'readonly' => false,
                        'destructive' => false,
                        'idempotent' => true,
                    ),
                ),
            )
        );
    }

    /**
     * Compatibility bootstrap for sites where another plugin initializes the
     * Abilities registry before this plugin's registration hooks are attached.
     *
     * Ordinarily the dedicated Abilities API hooks above do all registration.
     * This init fallback checks the live registry and registers only missing
     * entries. It uses the registry directly only when the API init action has
     * already passed, avoiding a plugin-load-order dependency.
     */
    public function ensure_abilities_registered()
    {
        $this->register_ability_category();
        $this->register_abilities();
    }

    private function register_ability_category_compat($slug, array $args)
    {
        if (function_exists('wp_has_ability_category') && wp_has_ability_category($slug)) {
            return;
        }

        if (function_exists('doing_action')
            && doing_action('wp_abilities_api_categories_init')
            && function_exists('wp_register_ability_category')) {
            wp_register_ability_category($slug, $args);
            return;
        }

        if (class_exists('WP_Ability_Categories_Registry')) {
            $registry = WP_Ability_Categories_Registry::get_instance();
            if ($registry && ! $registry->is_registered($slug)) {
                $registry->register($slug, $args);
                return;
            }
        }

        // Regression stubs and older compatibility shims may expose only the
        // registration function, so preserve that path outside production.
        if (! class_exists('WP_Ability_Categories_Registry')
            && function_exists('wp_register_ability_category')) {
            wp_register_ability_category($slug, $args);
        }
    }

    private function register_ability_compat($name, array $args)
    {
        if (function_exists('wp_has_ability') && wp_has_ability($name)) {
            return;
        }

        if (function_exists('doing_action')
            && doing_action('wp_abilities_api_init')
            && function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
            return;
        }

        if (class_exists('WP_Abilities_Registry')) {
            $registry = WP_Abilities_Registry::get_instance();
            if ($registry && ! $registry->is_registered($name)) {
                $registry->register($name, $args);
                return;
            }
        }

        if (! class_exists('WP_Abilities_Registry')
            && function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
        }
    }

    public function ability_permission()
    {
        return current_user_can('manage_options');
    }

    public function ability_get_review()
    {
        $review = get_transient($this->review_key());
        if (! is_array($review)) {
            return new WP_Error('cbp_no_review', __('There is no pending Bulletin Publisher review for this administrator.', 'church-bulletin-publisher'));
        }
        return $this->ability_review_payload($review);
    }

    public function ability_update_review_row($input)
    {
        $input = is_array($input) ? $input : array();
        $review = get_transient($this->review_key());
        if (! is_array($review)) {
            return new WP_Error('cbp_no_review', __('There is no pending Bulletin Publisher review for this administrator.', 'church-bulletin-publisher'));
        }

        $stale = $this->require_revision($review, $input['expected_revision'] ?? '');
        if (is_wp_error($stale)) {
            return $stale;
        }

        $section = sanitize_key((string) ($input['section'] ?? ''));
        $operation = sanitize_key((string) ($input['operation'] ?? ''));
        if (! in_array($section, array('masses', 'devotions', 'events', 'livestream'), true)) {
            return new WP_Error('cbp_invalid_section', __('Unknown Bulletin Publisher review section.', 'church-bulletin-publisher'));
        }
        if (! in_array($operation, array('add', 'update', 'delete'), true)) {
            return new WP_Error('cbp_invalid_operation', __('Unknown Bulletin Publisher review operation.', 'church-bulletin-publisher'));
        }

        if (! isset($review['weekly']) || ! is_array($review['weekly'])) {
            $review['weekly'] = array();
        }
        $rows = isset($review['weekly'][$section]) && is_array($review['weekly'][$section])
            ? array_values($review['weekly'][$section])
            : array();

        if ($operation === 'add') {
            if (! isset($input['row']) || ! is_array($input['row'])) {
                return new WP_Error('cbp_missing_row', __('A row is required when adding review data.', 'church-bulletin-publisher'));
            }
            $row = $this->sanitize_review_row($input['row'], $section);
            if (is_wp_error($row)) {
                return $row;
            }
            $rows[] = $row;
        } else {
            if (! isset($input['index']) || ! is_numeric($input['index'])) {
                return new WP_Error('cbp_missing_index', __('A valid row index is required for update or delete.', 'church-bulletin-publisher'));
            }
            $index = (int) $input['index'];
            if ($index < 0 || ! array_key_exists($index, $rows)) {
                return new WP_Error('cbp_invalid_index', __('The requested review row no longer exists. Read the review again before editing.', 'church-bulletin-publisher'));
            }

            if ($operation === 'delete') {
                array_splice($rows, $index, 1);
            } else {
                if (! isset($input['row']) || ! is_array($input['row'])) {
                    return new WP_Error('cbp_missing_row', __('A row is required when updating review data.', 'church-bulletin-publisher'));
                }
                $merged = array_merge(is_array($rows[$index]) ? $rows[$index] : array(), $input['row']);
                $row = $this->sanitize_review_row($merged, $section);
                if (is_wp_error($row)) {
                    return $row;
                }
                $rows[$index] = $row;
            }
        }

        $review['weekly'][$section] = array_values($rows);
        if ($section !== 'livestream' && class_exists('CBP_Schedule_V30')) {
            $review['weekly'] = CBP_Schedule_V30::sort_weekly($review['weekly']);
        }
        set_transient($this->review_key(), $review, self::REVIEW_TTL);

        return $this->ability_review_payload($review);
    }

    public function ability_update_review_candidate($input)
    {
        $input = is_array($input) ? $input : array();
        $review = get_transient($this->review_key());
        if (! is_array($review)) {
            return new WP_Error('cbp_no_review', __('There is no pending Bulletin Publisher review for this administrator.', 'church-bulletin-publisher'));
        }

        $stale = $this->require_revision($review, $input['expected_revision'] ?? '');
        if (is_wp_error($stale)) {
            return $stale;
        }

        $key = sanitize_key((string) ($input['key'] ?? ''));
        if (! in_array($key, array('st_peter_saturday', 'st_peter_sunday', 'st_mary_sunday', 'reconciliation', 'adoration'), true)) {
            return new WP_Error('cbp_invalid_candidate', __('Unknown recurring schedule candidate.', 'church-bulletin-publisher'));
        }

        if (! isset($review['candidates']) || ! is_array($review['candidates'])) {
            $review['candidates'] = array();
        }
        $review['candidates'][$key] = sanitize_text_field((string) ($input['value'] ?? ''));
        set_transient($this->review_key(), $review, self::REVIEW_TTL);

        return $this->ability_review_payload($review);
    }

    private function ability_review_payload(array $review)
    {
        $weekly = isset($review['weekly']) && is_array($review['weekly'])
            ? $review['weekly']
            : array();

        return array(
            'bulletin_date' => (string) ($review['bulletin_date'] ?? ''),
            'revision' => $this->review_revision($review),
            'candidates' => isset($review['candidates']) && is_array($review['candidates']) ? $review['candidates'] : array(),
            'current' => isset($review['current']) && is_array($review['current']) ? $review['current'] : array(),
            'weekly' => $weekly,
            'warnings' => isset($review['warnings']) && is_array($review['warnings']) ? $review['warnings'] : array(),
            'pipeline' => isset($review['_pipeline']) && is_array($review['_pipeline']) ? $review['_pipeline'] : array(),
            'diagnostics' => $this->review_diagnostics($review),
            'counts' => array(
                'masses' => isset($weekly['masses']) && is_array($weekly['masses']) ? count($weekly['masses']) : 0,
                'devotions' => isset($weekly['devotions']) && is_array($weekly['devotions']) ? count($weekly['devotions']) : 0,
                'events' => isset($weekly['events']) && is_array($weekly['events']) ? count($weekly['events']) : 0,
                'livestream' => isset($weekly['livestream']) && is_array($weekly['livestream']) ? count($weekly['livestream']) : 0,
            ),
            'note' => __('Pending review only. A human must still approve the PDF review and website information before publication.', 'church-bulletin-publisher'),
        );
    }

    private function review_diagnostics(array $review)
    {
        $result = array();
        foreach (($review['source_lines'] ?? array()) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\[(?:v\d+|normalize|debug)\]/i', $line)) {
                $result[] = $line;
            }
        }
        return array_values(array_unique($result));
    }

    private function require_revision(array $review, $expected)
    {
        $expected = trim((string) $expected);
        $actual = $this->review_revision($review);
        if ($expected === '' || ! hash_equals($actual, $expected)) {
            return new WP_Error('cbp_stale_review', __('The Bulletin Publisher review changed after it was read. Read it again before applying edits.', 'church-bulletin-publisher'));
        }
        return true;
    }

    private function review_revision(array $review)
    {
        $relevant = array(
            'bulletin_date' => (string) ($review['bulletin_date'] ?? ''),
            'candidates' => isset($review['candidates']) && is_array($review['candidates']) ? $review['candidates'] : array(),
            'weekly' => isset($review['weekly']) && is_array($review['weekly']) ? $review['weekly'] : array(),
        );
        $json = function_exists('wp_json_encode') ? wp_json_encode($relevant) : json_encode($relevant);
        return hash('sha256', (string) $json);
    }

    private function sanitize_review_row(array $row, $section)
    {
        if ($section === 'livestream') {
            return array(
                'description' => sanitize_text_field((string) ($row['description'] ?? '')),
            );
        }

        $date = sanitize_text_field((string) ($row['date'] ?? ''));
        if ($date !== '' && ! $this->valid_iso_date($date)) {
            return new WP_Error('cbp_invalid_date', __('Review row dates must use YYYY-MM-DD.', 'church-bulletin-publisher'));
        }

        return array(
            'date' => $date,
            'time' => sanitize_text_field((string) ($row['time'] ?? '')),
            'location' => sanitize_text_field((string) ($row['location'] ?? '')),
            'title' => sanitize_text_field((string) ($row['title'] ?? '')),
            'description' => sanitize_text_field((string) ($row['description'] ?? '')),
        );
    }

    private function valid_iso_date($date)
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
        return $parsed && $parsed->format('Y-m-d') === (string) $date;
    }

    private function normalize_liturgy_mass_rows(array $rows)
    {
        foreach ($rows as $index => $row) {
            if (! is_array($row) || strcasecmp(trim((string) ($row['title'] ?? '')), 'Mass') !== 0) {
                continue;
            }
            $description = trim((string) ($row['description'] ?? ''));
            if (stripos($description, 'Liturgy of the Word') === false) {
                continue;
            }
            $rows[$index]['title'] = 'Liturgy of the Word';
            if ($this->semantic_text($description) === 'liturgy of the word') {
                $rows[$index]['description'] = '';
            }
        }
        return array_values($rows);
    }

    private function liturgy_events_from_calendar(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }

        $events = array();
        $current_date = '';
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            $heading_date = $this->date_from_heading($line, $bulletin);
            if ($heading_date !== '') {
                $current_date = $heading_date;
                continue;
            }
            if ($current_date === '') {
                continue;
            }

            if (! preg_match('/^(.{3,100}?):\s*(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?\s*$/iu', $line, $m)) {
                continue;
            }

            $next = '';
            for ($j = $i + 1; $j < min($count, $i + 3); $j++) {
                $candidate = trim((string) $lines[$j]);
                if ($candidate !== '') {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === '' || stripos($next, 'Liturgy of the Word') === false) {
                continue;
            }

            $location = $this->normalize_location_name(trim((string) $m[1]));
            $time = sprintf('%d:%02d %s', (int) $m[2], (int) $m[3], strtoupper($m[4]) === 'A' ? 'AM' : 'PM');
            $events[] = array(
                'date' => $current_date,
                'time' => $time,
                'location' => $location,
                'title' => 'Liturgy of the Word',
                'description' => '',
            );
        }

        return $this->dedupe_exact_rows($events);
    }

    private function merge_canonical_liturgy_events(array $events, array $canonical)
    {
        foreach ($canonical as $wanted) {
            $matching_indexes = array();
            foreach ($events as $index => $row) {
                if (is_array($row) && $this->is_same_liturgy_event($row, $wanted)) {
                    $matching_indexes[] = $index;
                }
            }

            if (count($matching_indexes) === 1) {
                $existing = $events[$matching_indexes[0]];
                $existing_time = trim((string) ($existing['time'] ?? ''));
                $wanted_time = trim((string) ($wanted['time'] ?? ''));
                $wanted_location = $this->semantic_text((string) ($wanted['location'] ?? ''));
                $existing_location = $this->semantic_text((string) ($existing['location'] ?? ''));
                $existing_title = $this->semantic_text((string) ($existing['title'] ?? ''));
                $existing_description = $this->semantic_text((string) ($existing['description'] ?? ''));
                $mentions_location = $wanted_location !== '' && (
                    $existing_location === $wanted_location
                    || strpos($existing_title, $wanted_location) !== false
                    || strpos($existing_description, $wanted_location) !== false
                );

                // A single, timed location-named row is already a coherent
                // representation (for example Crystal Brook in the August
                // fixture). Preserve it. The October 11 failure had two rows:
                // one prose fragment and one location-as-title fragment.
                if ($existing_time !== '' && $existing_time === $wanted_time && $mentions_location) {
                    continue;
                }
            }

            $kept = array();
            foreach ($events as $row) {
                if (! is_array($row) || ! $this->is_same_liturgy_event($row, $wanted)) {
                    $kept[] = $row;
                }
            }
            $kept[] = $wanted;
            $events = $kept;
        }
        return $this->dedupe_exact_rows($events);
    }

    private function is_same_liturgy_event(array $row, array $wanted)
    {
        if ((string) ($row['date'] ?? '') !== (string) ($wanted['date'] ?? '')) {
            return false;
        }

        $wanted_time = trim((string) ($wanted['time'] ?? ''));
        $row_time = trim((string) ($row['time'] ?? ''));
        $row_location = $this->semantic_text((string) ($row['location'] ?? ''));
        $row_title = $this->semantic_text((string) ($row['title'] ?? ''));
        $row_description = $this->semantic_text((string) ($row['description'] ?? ''));
        $wanted_location = $this->semantic_text((string) ($wanted['location'] ?? ''));

        if ($row_time !== '' && $wanted_time !== '' && $row_time !== $wanted_time) {
            return false;
        }

        $mentions_liturgy = strpos($row_title, 'liturgy of the word') !== false
            || strpos($row_description, 'liturgy of the word') !== false;
        $mentions_location = $wanted_location !== '' && (
            $row_location === $wanted_location
            || strpos($row_title, $wanted_location) !== false
            || strpos($row_description, $wanted_location) !== false
        );

        return $mentions_liturgy || $mentions_location;
    }

    private function recover_after_mass_events(array $events, array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return $events;
        }

        $current_date = '';
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $heading_date = $this->date_from_heading($line, $bulletin);
            if ($heading_date !== '') {
                $current_date = $heading_date;
                continue;
            }
            if ($current_date === '') {
                continue;
            }

            if (! preg_match('/^(SP|SM):\s*(.+?)\s+after\s+Mass\b/iu', $line, $m)) {
                continue;
            }

            $location = strtoupper($m[1]) === 'SM' ? 'St. Mary’s' : 'St. Peter';
            $title = trim((string) $m[2], " \t\n\r\0\x0B-–—,:;");
            if ($title === '') {
                continue;
            }

            $wanted = array(
                'date' => $current_date,
                'time' => 'After Mass',
                'location' => $location,
                'title' => $title,
                'description' => '',
            );

            if (! $this->has_semantic_event($events, $wanted)) {
                $events[] = $wanted;
            }
        }

        return $this->dedupe_exact_rows($events);
    }

    private function has_semantic_event(array $events, array $wanted)
    {
        $wanted_title = $this->semantic_text((string) ($wanted['title'] ?? ''));
        foreach ($events as $row) {
            if (! is_array($row)
                || (string) ($row['date'] ?? '') !== (string) ($wanted['date'] ?? '')
                || $this->semantic_text((string) ($row['location'] ?? '')) !== $this->semantic_text((string) ($wanted['location'] ?? ''))) {
                continue;
            }

            $title = $this->semantic_text((string) ($row['title'] ?? ''));
            $description = $this->semantic_text((string) ($row['description'] ?? ''));
            if ($title === $wanted_title || ($wanted_title !== '' && strpos($description, $wanted_title) !== false)) {
                return true;
            }
        }
        return false;
    }

    private function dedupe_exact_rows(array $rows)
    {
        $seen = array();
        $result = array();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = implode('|', array(
                (string) ($row['date'] ?? ''),
                (string) ($row['time'] ?? ''),
                $this->semantic_text((string) ($row['location'] ?? '')),
                $this->semantic_text((string) ($row['title'] ?? '')),
                $this->semantic_text((string) ($row['description'] ?? '')),
            ));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return array_values($result);
    }

    private function raw_lines(array $review)
    {
        $raw = array();
        foreach (($review['source_lines'] ?? array()) as $line) {
            $line = trim((string) $line);
            if ($line === '' || preg_match('/^\[v\d+\]/i', $line)) {
                continue;
            }
            if (strpos($line, '[raw] ') === 0) {
                $line = trim(substr($line, 6));
            }
            if ($line !== '') {
                $raw[] = $line;
            }
        }
        return $raw;
    }

    private function date_from_heading($line, DateTimeImmutable $bulletin)
    {
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', (string) $line, $m)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!F j Y', $m[2] . ' ' . $m[3] . ' ' . $bulletin->format('Y'));
        if (! $date) {
            return '';
        }
        if ($date < $bulletin->modify('-30 days')) {
            $date = $date->modify('+1 year');
        }
        return $date->format('Y-m-d');
    }

    private function normalize_location_name($location)
    {
        $location = trim((string) $location);
        if (preg_match('/^Heritage\s+(?:Senior\s+)?Living\s+Center$/iu', $location)) {
            return 'Heritage Living Center';
        }
        if (preg_match('/^St\.?\s*Peter[’\'s]*$/iu', $location)) {
            return 'St. Peter';
        }
        if (preg_match('/^St\.?\s*Mary[’\'s]*$/iu', $location)) {
            return 'St. Mary’s';
        }
        return sanitize_text_field($location);
    }

    private function semantic_text($text)
    {
        $text = strtolower((string) $text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
