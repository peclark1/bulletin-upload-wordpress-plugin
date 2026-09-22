<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Renderer
{
    public static function render($definition, $state)
    {
        $values = isset($state['values']) ? $state['values'] : array();
        $errors = isset($state['errors']) ? $state['errors'] : array();
        ob_start();
        ?>
        <div id="parish-form" class="pform-shell">
            <?php if (! empty($state['success'])) : ?>
                <div class="pform-notice pform-notice--success" role="status" tabindex="-1">
                    <h2><?php esc_html_e('Registration Received', 'parish-forms'); ?></h2>
                    <p><?php echo esc_html($definition['confirmation']); ?></p>
                </div>
            <?php else : ?>
                <div class="pform-intro">
                    <p class="pform-eyebrow"><?php esc_html_e('Welcome to Our Parish Family', 'parish-forms'); ?></p>
                    <h2><?php echo esc_html($definition['title']); ?></h2>
                    <p><?php echo esc_html($definition['description']); ?></p>
                </div>

                <?php if ($errors) : ?>
                    <div class="pform-notice pform-notice--error" role="alert" tabindex="-1">
                        <h3><?php esc_html_e('Please check the form', 'parish-forms'); ?></h3>
                        <?php if (isset($errors['_form'])) : ?>
                            <p><?php echo esc_html($errors['_form']); ?></p>
                        <?php else : ?>
                            <p><?php esc_html_e('Some information is missing or needs correction. The affected fields are marked below.', 'parish-forms'); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form class="pform" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="pform_submit">
                    <input type="hidden" name="pform_id" value="<?php echo esc_attr($definition['id']); ?>">
                    <?php wp_nonce_field('pform_submit_' . $definition['id'], '_pform_nonce'); ?>
                    <?php $started = time(); ?>
                    <input type="hidden" name="pform_started" value="<?php echo esc_attr($started); ?>">
                    <input type="hidden" name="pform_signature" value="<?php echo esc_attr(PFORM_Plugin::signature($definition['id'], $started)); ?>">
                    <div class="pform-honeypot" aria-hidden="true">
                        <label for="pform-website"><?php esc_html_e('Website', 'parish-forms'); ?></label>
                        <input id="pform-website" type="text" name="pf[website]" tabindex="-1" autocomplete="off">
                    </div>

                    <?php foreach ($definition['sections'] as $section) : ?>
                        <section class="pform-section" data-pform-section="<?php echo esc_attr($section['id']); ?>" <?php echo self::condition_attributes(isset($section['condition']) ? $section['condition'] : null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                            <div class="pform-section__heading">
                                <h3><?php echo esc_html($section['title']); ?></h3>
                                <?php if (! empty($section['description'])) : ?>
                                    <p><?php echo esc_html($section['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if (! empty($section['fields'])) : ?>
                                <div class="pform-grid">
                                    <?php foreach ($section['fields'] as $field) : ?>
                                        <?php self::field($field, $values, $errors, 'pf', ''); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>

                    <div class="pform-submit">
                        <p><?php esc_html_e('Information submitted through this form is intended for parish registration and parish-office follow-up.', 'parish-forms'); ?></p>
                        <button class="pform-button" type="submit"><?php echo esc_html($definition['submit_label']); ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function field($field, $values, $errors, $prefix, $path_prefix)
    {
        if ($field['type'] === 'repeater') {
            self::repeater($field, $values, $errors, $prefix, $path_prefix);
            return;
        }

        $id = $field['id'];
        $path = $path_prefix ? $path_prefix . '.' . $id : $id;
        $name = $prefix . '[' . $id . ']';
        $value = isset($values[$id]) ? $values[$id] : '';
        $error = isset($errors[$path]) ? $errors[$path] : '';
        $html_id = 'pform-' . sanitize_html_class(str_replace('.', '-', $path));
        $width = isset($field['width']) ? sanitize_html_class($field['width']) : 'full';
        $classes = 'pform-field pform-field--' . $width . ($error ? ' pform-field--error' : '');
        ?>
        <div class="<?php echo esc_attr($classes); ?>" <?php echo self::condition_attributes(isset($field['condition']) ? $field['condition'] : null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <?php if ($field['type'] === 'radio' || $field['type'] === 'checkboxes') : ?>
                <fieldset <?php echo $error ? 'aria-describedby="' . esc_attr($html_id . '-error') . '"' : ''; ?>>
                    <legend><?php self::label_text($field); ?></legend>
                    <div class="pform-options <?php echo count($field['options']) > 5 ? 'pform-options--columns' : ''; ?> <?php echo $id === 'ministries' ? 'pform-options--ministries' : ''; ?>">
                        <?php foreach ($field['options'] as $option_value => $option_label) : ?>
                            <?php $option_id = $html_id . '-' . sanitize_html_class($option_value); ?>
                            <label class="pform-option" for="<?php echo esc_attr($option_id); ?>">
                                <input
                                    id="<?php echo esc_attr($option_id); ?>"
                                    type="<?php echo $field['type'] === 'radio' ? 'radio' : 'checkbox'; ?>"
                                    name="<?php echo esc_attr($name . ($field['type'] === 'checkboxes' ? '[]' : '')); ?>"
                                    value="<?php echo esc_attr($option_value); ?>"
                                    <?php checked($field['type'] === 'checkboxes' ? in_array($option_value, (array) $value, true) : $value === $option_value); ?>
                                    <?php echo ! empty($field['required']) ? 'required' : ''; ?>
                                >
                                <span><?php echo esc_html($option_label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php elseif ($field['type'] === 'consent') : ?>
                <label class="pform-consent" for="<?php echo esc_attr($html_id); ?>">
                    <input id="<?php echo esc_attr($html_id); ?>" type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($value, '1'); ?> required <?php echo $error ? 'aria-describedby="' . esc_attr($html_id . '-error') . '"' : ''; ?>>
                    <span><?php self::label_text($field); ?></span>
                </label>
            <?php else : ?>
                <label for="<?php echo esc_attr($html_id); ?>"><?php self::label_text($field); ?></label>
                <?php if ($field['type'] === 'textarea') : ?>
                    <textarea id="<?php echo esc_attr($html_id); ?>" name="<?php echo esc_attr($name); ?>" rows="3" <?php self::input_attributes($field, $error, $html_id); ?>><?php echo esc_textarea($value); ?></textarea>
                <?php else : ?>
                    <input id="<?php echo esc_attr($html_id); ?>" type="<?php echo esc_attr($field['type']); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php self::input_attributes($field, $error, $html_id); ?>>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($error) : ?>
                <p id="<?php echo esc_attr($html_id . '-error'); ?>" class="pform-error"><?php echo esc_html($error); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function repeater($field, $values, $errors, $prefix, $path_prefix)
    {
        $items = isset($values[$field['id']]) && is_array($values[$field['id']]) ? array_values($values[$field['id']]) : array();
        if (! $items) {
            $items[] = array();
        }
        ?>
        <div class="pform-repeater pform-field--full" data-pform-repeater data-max-items="<?php echo esc_attr(absint($field['max_items'])); ?>">
            <div class="pform-repeater__items" data-pform-repeater-items>
                <?php foreach ($items as $index => $item) : ?>
                    <?php self::repeater_item($field, $item, $errors, $prefix, $path_prefix, $index); ?>
                <?php endforeach; ?>
            </div>
            <template data-pform-repeater-template>
                <?php self::repeater_item($field, array(), array(), $prefix, $path_prefix, '__INDEX__'); ?>
            </template>
            <button class="pform-button pform-button--secondary" type="button" data-pform-add><?php echo esc_html($field['add_label']); ?></button>
            <p class="pform-repeater__limit" hidden data-pform-limit><?php esc_html_e('The maximum number of entries has been reached.', 'parish-forms'); ?></p>
        </div>
        <?php
    }

    private static function repeater_item($field, $item, $errors, $prefix, $path_prefix, $index)
    {
        $item_prefix = $prefix . '[' . $field['id'] . '][' . $index . ']';
        $item_path = ($path_prefix ? $path_prefix . '.' : '') . $field['id'] . '.' . $index;
        ?>
        <fieldset class="pform-repeater__item" data-pform-repeater-item>
            <legend><?php echo esc_html($field['item_label'] . ' ' . (is_numeric($index) ? ((int) $index + 1) : '')); ?></legend>
            <div class="pform-grid">
                <?php foreach ($field['fields'] as $item_field) : ?>
                    <?php self::field($item_field, $item, $errors, $item_prefix, $item_path); ?>
                <?php endforeach; ?>
            </div>
            <button class="pform-remove" type="button" data-pform-remove><?php esc_html_e('Remove Child', 'parish-forms'); ?></button>
        </fieldset>
        <?php
    }

    private static function label_text($field)
    {
        echo esc_html($field['label']);
        if (! empty($field['required'])) {
            echo ' <span class="pform-required" aria-hidden="true">*</span><span class="screen-reader-text"> ' . esc_html__('required', 'parish-forms') . '</span>';
        }
    }

    private static function input_attributes($field, $error, $html_id)
    {
        if (! empty($field['required'])) {
            echo ' required';
        }
        if (! empty($field['max_length'])) {
            echo ' maxlength="' . esc_attr(absint($field['max_length'])) . '"';
        }
        if (! empty($field['autocomplete'])) {
            echo ' autocomplete="' . esc_attr($field['autocomplete']) . '"';
        }
        if ($error) {
            echo ' aria-invalid="true" aria-describedby="' . esc_attr($html_id . '-error') . '"';
        }
    }

    private static function condition_attributes($condition)
    {
        if (! $condition) {
            return '';
        }
        return sprintf(
            'data-pform-condition-field="%s" data-pform-condition-value="%s"',
            esc_attr($condition['field']),
            esc_attr($condition['equals'])
        );
    }
}
