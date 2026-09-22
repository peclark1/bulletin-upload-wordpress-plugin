<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Guided staff workflow:
 *  1. cover templates (normally unchanged)
 *  2. select the weekly bulletin files -> preview starts automatically
 *  3. extraction starts automatically -> staff reviews PDF + generated data
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
            __('Review both the PDF Preview and the generated website information in Step 3 before publishing this bulletin.', 'church-bulletin-publisher')
        );
    }

    public function render_workflow_script()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $preview = get_transient($this->preview_key());
        $approved = $this->approved_for_preview($preview);

        // Auto-extraction must happen only as the direct continuation of a newly
        // created preview. An older preview can remain in the transient for two
        // days, so merely opening the Bulletin Publisher page must never launch
        // an extraction by itself.
        $message = isset($_GET['cbp_message']) ? sanitize_text_field(wp_unslash($_GET['cbp_message'])) : '';
        $fresh_preview = stripos($message, 'Private preview created') !== false;
        $auto_extract = is_array($preview) && ! $approved && $fresh_preview;

        // wp_nonce_url() HTML-escapes ampersands for use in markup. This URL is
        // passed to JavaScript instead, so build an unescaped query string or
        // WordPress will receive "amp;_wpnonce" and reject it as expired.
        $extract_url = add_query_arg(array(
            'action' => 'cbp_extract_schedule',
            '_wpnonce' => wp_create_nonce('cbp_extract_schedule'),
        ), admin_url('admin-post.php'));
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

            function ensureBusyNotice() {
                var existing = document.getElementById('cbp-busy-notice');
                if (existing) {
                    return existing;
                }
                if (!form) {
                    return null;
                }

                var notice = document.createElement('div');
                notice.id = 'cbp-busy-notice';
                notice.setAttribute('role', 'status');
                notice.setAttribute('aria-live', 'polite');
                notice.style.display = 'none';
                notice.style.margin = '18px 0';
                notice.style.padding = '18px 20px';
                notice.style.border = '1px solid #72aee6';
                notice.style.borderLeft = '5px solid #2271b1';
                notice.style.background = '#f0f6fc';
                notice.style.boxShadow = '0 1px 2px rgba(0,0,0,.05)';
                notice.style.fontSize = '17px';
                notice.style.fontWeight = '700';
                notice.style.lineHeight = '1.4';

                var spinner = document.createElement('span');
                spinner.className = 'spinner is-active';
                spinner.style.float = 'none';
                spinner.style.margin = '0 10px 0 0';
                spinner.style.verticalAlign = 'middle';
                notice.appendChild(spinner);

                var text = document.createElement('span');
                text.className = 'cbp-busy-text';
                notice.appendChild(text);

                var fileList = document.getElementById('cbp-file-list');
                if (fileList && fileList.parentNode) {
                    fileList.parentNode.insertBefore(notice, fileList);
                } else {
                    form.insertBefore(notice, form.firstChild);
                }
                return notice;
            }

            function showBusy(message) {
                var notice = ensureBusyNotice();
                if (!notice) {
                    return;
                }
                var text = notice.querySelector('.cbp-busy-text');
                if (text) {
                    text.textContent = message;
                }
                notice.style.display = 'block';
                if (progress) {
                    progress.textContent = message;
                }
            }

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
                showBusy('Please wait while I generate the PDF Preview...');
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
                ensureBusyNotice();
            }

            var preview = document.querySelector('.cbp-preview');
            if (preview) {
                var heading = preview.querySelector('h2');
                if (heading) {
                    heading.textContent = '3. Review PDF Preview and Generated Schedule';
                }

                if (!preview.querySelector('.cbp-review-checklist')) {
                    var checklist = document.createElement('div');
                    checklist.className = 'notice notice-warning inline cbp-review-checklist';
                    checklist.style.margin = '14px 0 18px';
                    checklist.innerHTML = '<p style="font-size:15px;line-height:1.55;"><strong>Before approving Step 3, please review BOTH:</strong><br>1. Open <strong>View PDF Preview</strong> and confirm the finished bulletin looks correct.<br>2. Review the generated Mass times, Rosary, Adoration, Reconciliation, and parish events below. Correct or delete anything that does not match the bulletin.<br><strong>Only approve after both reviews are complete.</strong></p>';
                    if (heading) {
                        heading.insertAdjacentElement('afterend', checklist);
                    } else {
                        preview.insertBefore(checklist, preview.firstChild);
                    }
                }

                var reviewPanel = preview.querySelector('.cbp-schedule-review');
                if (reviewPanel) {
                    var reviewHeading = reviewPanel.querySelector('h2');
                    if (reviewHeading) {
                        reviewHeading.textContent = 'Generated website schedule and weekly calendar';
                    }

                    var confirm = reviewPanel.querySelector('input[name="confirm_schedule"]');
                    var confirmLabel = confirm ? confirm.closest('label') : null;
                    if (confirm && confirmLabel) {
                        while (confirmLabel.firstChild) {
                            confirmLabel.removeChild(confirmLabel.firstChild);
                        }
                        confirmLabel.appendChild(confirm);
                        confirmLabel.appendChild(document.createTextNode(' I reviewed the PDF Preview and the generated schedule/events, corrected anything needed, and approve this website update.'));
                    }

                    var approvalButton = reviewPanel.querySelector('form[action*="admin-post.php"] input[type="submit"]');
                    var buttons = reviewPanel.querySelectorAll('input[type="submit"]');
                    if (buttons.length) {
                        approvalButton = buttons[buttons.length - 1];
                    }
                    if (approvalButton) {
                        approvalButton.value = 'Approve PDF Review & Website Information';
                    }
                }

                if (approved && !preview.querySelector('.cbp-approved-message')) {
                    var approvedMessage = document.createElement('div');
                    approvedMessage.className = 'notice notice-success inline cbp-approved-message';
                    approvedMessage.innerHTML = '<p><strong>PDF Preview and website information approved.</strong> Continue to Step 4 to publish the bulletin PDF.</p>';
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
                    instruction.textContent = 'The PDF Preview and generated website information were approved for this exact preview. Choose test or live publication below.';
                } else {
                    instruction.innerHTML = '<span style="display:block;padding:8px 12px;">Review the PDF Preview and generated schedule/events in Step 3, then approve them before publishing.</span>';
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
                mode.innerHTML = '<strong>Guided bulletin workflow:</strong> upload → automatic PDF Preview & extraction → review PDF + generated website information → approve → publish.';
            }

            // Use the existing extraction endpoint rather than duplicating parser
            // logic. This also preserves all V2–V10 post-processing hooks.
            if (autoExtract) {
                showBusy('PDF Preview complete. Please wait while I generate the website schedule and weekly events...');
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
