<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Guided staff workflow:
 *  1. cover templates (normally unchanged)
 *  2. select the weekly bulletin files -> preview starts automatically
 *  3. extraction starts automatically -> staff reviews and approves
 *  4. publishing is enabled only for the exact approved preview
 */
final class CBP_Workflow_V11
{
    const APPROVAL_TTL = 2 * DAY_IN_SECONDS;

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
        add_action('cbp_weekly_calendar_updated', array($this, 'mark_preview_approved'), 20, 1);

        // Run before the existing test/live publish handlers. This makes the
        // visual four-step workflow an actual safety rule, not just UI chrome.
        add_action('admin_post_cbp_publish', array($this, 'guard_publish'), 1);
        add_action('admin_post_cbp_publish_live', array($this, 'guard_publish'), 1);

        // Render last so the original preview, schedule review, and production
        // controls already exist and can be rearranged into the guided flow.
        add_action('admin_footer-toplevel_page_church-bulletin-publisher', array($this, 'render_workflow_script'), 100);
    }

    public function mark_preview_approved($weekly)
    {
        if (! is_array($weekly)) {
            return;
        }

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['sha256']) || empty($preview['date'])) {
            return;
        }

        $source_date = isset($weekly['source_bulletin_date']) ? (string) $weekly['source_bulletin_date'] : '';
        if ($source_date === '' || $source_date !== (string) $preview['date']) {
            return;
        }

        set_transient($this->approval_key(), array(
            'sha256' => (string) $preview['sha256'],
            'date' => (string) $preview['date'],
            'approved_utc' => gmdate('c'),
        ), self::APPROVAL_TTL);
    }

    public function guard_publish()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $preview = get_transient($this->preview_key());
        if ($this->approved_for_preview($preview)) {
            return;
        }

        $this->redirect(
            'error',
            __('Review and approve the extracted website information in Step 3 before publishing this bulletin.', 'church-bulletin-publisher')
        );
    }

    public function render_workflow_script()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $preview = get_transient($this->preview_key());
        $review = get_transient($this->review_key());
        $approved = $this->approved_for_preview($preview);

        // A just-created preview should always be re-extracted, even if a stale
        // review transient from an earlier build happens to exist for the same
        // user. Otherwise, also recover automatically if a preview exists but
        // its review transient has expired.
        $message = isset($_GET['cbp_message']) ? sanitize_text_field(wp_unslash($_GET['cbp_message'])) : '';
        $fresh_preview = stripos($message, 'Private preview created') !== false;
        $has_review = is_array($review) && (! empty($review['candidates']) || ! empty($review['error']));
        $auto_extract = is_array($preview) && ! $approved && ($fresh_preview || ! $has_review);

        $extract_url = wp_nonce_url(
            admin_url('admin-post.php?action=cbp_extract_schedule'),
            'cbp_extract_schedule'
        );
        ?>
        <script>
        (function () {
            'use strict';

            var approved = <?php echo $approved ? 'true' : 'false'; ?>;
            var autoExtract = <?php echo $auto_extract ? 'true' : 'false'; ?>;
            var extractUrl = <?php echo wp_json_encode($extract_url); ?>;

            var form = document.getElementById('cbp-preview-form');
            var files = document.getElementById('cbp-files');
            var folder = document.getElementById('cbp-folder');
            var progress = document.getElementById('cbp-progress');
            var date = form ? form.querySelector('[name="bulletin_date"]') : null;
            var autoStarted = false;

            function selectedPdfCount() {
                var count = 0;
                [files, folder].forEach(function (input) {
                    if (!input || !input.files) {
                        return;
                    }
                    Array.prototype.forEach.call(input.files, function (file) {
                        if (/\.pdf$/i.test(file.name)) {
                            count += 1;
                        }
                    });
                });
                return count;
            }

            function maybeStartPreview() {
                if (autoStarted || !form || !date || !date.value || selectedPdfCount() < 1) {
                    return;
                }
                autoStarted = true;
                if (progress) {
                    progress.textContent = 'Files selected. Building the private preview automatically...';
                }
                window.setTimeout(function () {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }, 120);
            }

            [files, folder].forEach(function (input) {
                if (input) {
                    input.addEventListener('change', maybeStartPreview);
                }
            });
            if (date) {
                date.addEventListener('change', maybeStartPreview);
            }

            // Make Step 2 describe what staff actually does now. Keep the button
            // as a harmless fallback, although ordinary use starts automatically.
            if (form) {
                var uploadCard = form.closest('.cbp-card');
                var uploadHeading = uploadCard ? uploadCard.querySelector('h2') : null;
                if (uploadHeading) {
                    uploadHeading.textContent = '2. Upload weekly bulletin';
                }
                var submit = form.querySelector('[type="submit"]');
                if (submit && submit.value) {
                    submit.value = 'Build Preview & Extract';
                }
                if (uploadCard && !uploadCard.querySelector('.cbp-auto-workflow-note')) {
                    var note = document.createElement('p');
                    note.className = 'description cbp-auto-workflow-note';
                    note.textContent = 'Choose the bulletin Sunday and PDF file(s). Preview creation and website extraction start automatically.';
                    form.insertBefore(note, form.firstChild);
                }
            }

            var preview = document.querySelector('.cbp-preview');
            if (preview) {
                var heading = preview.querySelector('h2');
                if (heading) {
                    heading.textContent = '3. Review and approve';
                }

                var reviewPanel = preview.querySelector('.cbp-schedule-review');
                if (reviewPanel) {
                    var reviewHeading = reviewPanel.querySelector('h2');
                    if (reviewHeading) {
                        reviewHeading.textContent = 'Website schedule and weekly calendar';
                    }
                }

                if (approved && !preview.querySelector('.cbp-approved-message')) {
                    var approvedMessage = document.createElement('div');
                    approvedMessage.className = 'notice notice-success inline cbp-approved-message';
                    approvedMessage.innerHTML = '<p><strong>Website information approved.</strong> Continue to Step 4 to publish the bulletin PDF.</p>';
                    var actions = preview.querySelector('.cbp-actions');
                    if (actions) {
                        actions.insertAdjacentElement('beforebegin', approvedMessage);
                    } else {
                        preview.appendChild(approvedMessage);
                    }
                }

                var step4 = document.createElement('section');
                step4.className = 'cbp-card cbp-publish-step';
                step4.innerHTML = '<h2>4. Publish bulletin</h2>';
                preview.insertAdjacentElement('afterend', step4);

                var instruction = document.createElement('p');
                instruction.className = approved ? 'description' : 'notice notice-info inline';
                if (approved) {
                    instruction.textContent = 'The extracted website information is approved for this exact preview. Choose test or live publication below.';
                } else {
                    instruction.innerHTML = '<span style="display:block;padding:8px 12px;">Approve the extracted website information in Step 3 before publishing.</span>';
                }
                step4.appendChild(instruction);

                var actions = preview.querySelector('.cbp-actions');
                if (actions) {
                    var testAction = actions.querySelector('input[name="action"][value="cbp_publish"]');
                    var testForm = testAction ? testAction.closest('form') : null;
                    if (testForm) {
                        step4.appendChild(testForm);
                        testForm.style.display = approved ? '' : 'none';
                    }
                }

                var production = document.getElementById('cbp-production-panel');
                if (production) {
                    step4.appendChild(production);
                    production.style.display = approved ? 'block' : 'none';
                    var descriptions = production.querySelectorAll('p.description');
                    if (descriptions.length) {
                        var finalDescription = descriptions[descriptions.length - 1];
                        if (/isolated test publish/i.test(finalDescription.textContent || '')) {
                            finalDescription.textContent = 'Production publications and backups are recorded in the protected audit log.';
                        }
                    }
                }
            }

            var mode = document.querySelector('.cbp-mode');
            if (mode) {
                mode.innerHTML = '<strong>Guided bulletin workflow:</strong> upload → automatic preview & extraction → review & approve → publish.';
            }

            // Use the existing extraction endpoint rather than duplicating parser
            // logic. This also preserves all V2–V10 post-processing hooks.
            if (autoExtract) {
                if (progress) {
                    progress.textContent = 'Preview complete. Extracting website information automatically...';
                }
                window.setTimeout(function () {
                    window.location.assign(extractUrl);
                }, 180);
            }
        }());
        </script>
        <?php
    }

    private function approved_for_preview($preview)
    {
        if (! is_array($preview) || empty($preview['sha256']) || empty($preview['date'])) {
            return false;
        }

        $approval = get_transient($this->approval_key());
        if (! is_array($approval) || empty($approval['sha256']) || empty($approval['date'])) {
            return false;
        }

        return hash_equals((string) $preview['sha256'], (string) $approval['sha256'])
            && (string) $preview['date'] === (string) $approval['date'];
    }

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }

    private function approval_key()
    {
        return 'cbp_workflow_approval_' . get_current_user_id();
    }

    private function redirect($type, $message)
    {
        $url = add_query_arg(array(
            'page' => 'church-bulletin-publisher',
            'cbp_notice' => $type,
            'cbp_message' => $message,
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
