<?php

/**
 * @file SubmissionFeePlugin.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SubmissionFeePlugin
 * @brief Charge a submission fee at submission time in OJS 3.5.
 *
 * Two enforcement modes (set in settings):
 *   - hardBlock:  the author cannot complete the submission wizard until a
 *                 completed payment exists for the submission. Enforced via
 *                 the Submission::validateSubmit hook (server side).
 *   - holdUntilPaid: the submission completes normally, the fee is queued on
 *                 the SubmissionSubmitted event, and the submission is flagged
 *                 as fee-outstanding for the editor.
 */

namespace APP\plugins\generic\submissionFee;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\observers\events\SubmissionSubmitted;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class SubmissionFeePlugin extends GenericPlugin
{
    /**
     * Use the native OJS submission payment type. Core OJSPaymentManager
     * already supports this type (value 5) and handles it gracefully in
     * fulfillQueuedPayment() by recording the completed payment.
     */
    public const PAYMENT_TYPE_SUBMISSION = \APP\payment\ojs\OJSPaymentManager::PAYMENT_TYPE_SUBMISSION;

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }

        if (!$this->getEnabled($mainContextId)) {
            return true;
        }

        // --- Author pay-now page handler (queues payment, redirects to gateway) ---
        Hook::add('LoadHandler', [$this, 'setupPaymentHandler']);

        // --- HARD BLOCK: refuse to complete the wizard until paid ---
        // Verified stable in OJS 3.5: Hook::call('Submission::validateSubmit', [&$errors, $submission, $context]);
        Hook::add('Submission::validateSubmit', [$this, 'checkPaymentOnSubmit']);

        // --- AUTHOR-FACING NOTICE: surface the fee + a pay button inside the
        // submission wizard. The Template::SubmissionWizard::Section hook
        // output lands inside the wizard's client-side v-for, so Vue clones it
        // into every step section; the injected script then shows it only on
        // the steps the journal selected (and dedupes within a step). ---
        Hook::add('Template::SubmissionWizard::Section', [$this, 'addWizardSectionNotice']);

        // Wizard page: inject the popup/polling/step-filter script (output
        // filter, outside the Vue root so Vue cannot strip it), splice in the
        // dedicated Payment step when enabled, and add notices to the
        // "Begin a Submission" and submission-complete pages.
        Hook::add('TemplateManager::display', [$this, 'handlePageDisplay']);

        // Register the SUBMISSION_FEE_REQUIRED mailable (editable under
        // Settings > Emails).
        Hook::add('Mailer::Mailables', [$this, 'addMailables']);

        // --- HOLD UNTIL PAID: queue the fee after the wizard completes ---
        Event::listen(SubmissionSubmitted::class, function (SubmissionSubmitted $event) {
            if ($this->getMode($event->context->getId()) !== 'holdUntilPaid') {
                return;
            }
            $helper = new PaymentHelper($this);
            if (!$helper->feeEnabled($event->context) || $helper->hasPaid($event->submission, $event->context)) {
                return;
            }
            $helper->queueForSubmission($event->submission, $event->context);
            $event->submission->setData('submissionFeeOutstanding', true);
            Repo::submission()->edit($event->submission, []);
            $this->sendFeeRequiredEmail($event->submission, $event->context);
        });

        return true;
    }

    /**
     * HARD BLOCK enforcement. Adds an error to the submit-validation result
     * when the fee is enabled, the mode is hardBlock, and no completed payment
     * exists for this submission.
     *
     * @param string $hookName
     * @param array  $args [&$errors, $submission, $context]
     */
    public function checkPaymentOnSubmit(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $submission = $args[1] ?? null;
        $context = $args[2] ?? Application::get()->getRequest()->getContext();

        if (!$submission || !$context) {
            return Hook::CONTINUE;
        }
        if ($this->getMode($context->getId()) !== 'hardBlock') {
            return Hook::CONTINUE;
        }

        $helper = new PaymentHelper($this);
        if (!$helper->feeEnabled($context) || $helper->hasPaid($submission, $context)) {
            return Hook::CONTINUE;
        }

        $payUrl = $helper->payUrl($submission, $context);
        $errors['submissionFee'] = [
            __('plugins.generic.submissionFee.error.unpaid', ['url' => $payUrl]),
        ];

        return Hook::CONTINUE;
    }

    /**
     * Wizard banner. Template::SubmissionWizard::Section fires once during the
     * Smarty render, but its output sits inside the wizard's client-side
     * v-for, so Vue clones it into every step section. The data-sf-show
     * attribute tells the injected script which steps may show it; a static
     * guard keeps the server-side output single.
     */
    public function addWizardSectionNotice(string $hookName, array $args): bool
    {
        $steps = $this->getNoticeSteps();
        if (!$steps) {
            return Hook::CONTINUE;
        }
        static $rendered = false;
        if ($rendered) {
            return Hook::CONTINUE;
        }
        $notice = $this->buildNotice($args[0]['submission'] ?? null, implode(',', $steps));
        if ($notice !== '') {
            $rendered = true;
            $args[2] .= $notice;
        }
        return Hook::CONTINUE;
    }

    /**
     * Build the fee-notice HTML for a submission, or '' when no notice is due.
     * Title and body texts are journal-editable settings with locale defaults.
     * Output is plain, Vue-safe static HTML (no {{ }} bindings, no <script>).
     *
     * @param mixed  $submission Submission object
     * @param string $stepsAttr  Comma-separated wizard step ids this notice may
     *                           show on (data-sf-show, filtered client-side);
     *                           '' renders it unconditionally.
     */
    protected function buildNotice($submission, string $stepsAttr = ''): string
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$submission || !$context) {
            return '';
        }

        $helper = new PaymentHelper($this);
        if (!$helper->feeEnabled($context) || $helper->hasPaid($submission, $context)) {
            return '';
        }

        $isHardBlock = $this->getMode($context->getId()) === 'hardBlock';
        $payUrl = $helper->payUrl($submission, $context);
        $statusUrl = Application::get()->getRequest()->getDispatcher()->url(
            Application::get()->getRequest(),
            Application::ROUTE_PAGE,
            $context->getPath(),
            'submissionFee',
            'status',
            [$submission->getId()]
        );

        $title = htmlspecialchars(
            $helper->noticeText($context, 'noticeTitle', 'plugins.generic.submissionFee.notice.title'),
            ENT_QUOTES
        );
        $amountLine = htmlspecialchars(
            (string) __('plugins.generic.submissionFee.notice.amount', [
                'amount' => $helper->formattedAmount($context),
                'currency' => $helper->currency($context),
            ]),
            ENT_QUOTES
        );
        $body = htmlspecialchars(
            $helper->noticeText(
                $context,
                $isHardBlock ? 'noticeBodyHardBlock' : 'noticeBodyHoldUntilPaid',
                $isHardBlock
                    ? 'plugins.generic.submissionFee.notice.bodyHardBlock'
                    : 'plugins.generic.submissionFee.notice.bodyHoldUntilPaid'
            ),
            ENT_QUOTES
        );
        $payLabel = htmlspecialchars((string) __('plugins.generic.submissionFee.notice.payNow'), ENT_QUOTES);
        $paidText = htmlspecialchars((string) __('plugins.generic.submissionFee.notice.paid'), ENT_QUOTES);

        // Inline styles keep this theme-agnostic on any 3.5 install. The
        // data-sf-pay attributes are picked up by the delegated click handler
        // injected via handlePageDisplay() (popup + status polling).
        return '<div class="submissionFeeNotice" role="note"'
            . ($stepsAttr !== '' ? ' data-sf-show="' . htmlspecialchars($stepsAttr, ENT_QUOTES) . '"' : '')
            . ' style="margin:1rem 0;padding:1rem 1.25rem;border:1px solid #e0a800;'
            . 'border-left-width:4px;border-radius:4px;background:#fff8e6;">'
            . '<h3 style="margin:0 0 .25rem;font-size:1rem;color:#8a6d00;">' . $title . '</h3>'
            . '<p style="margin:0 0 .5rem;"><strong>' . $amountLine . '</strong></p>'
            . '<p style="margin:0 0 .75rem;">' . $body . '</p>'
            . '<a href="' . htmlspecialchars($payUrl, ENT_QUOTES) . '"'
            . ' class="pkp_button" data-sf-pay="1"'
            . ' data-status-url="' . htmlspecialchars($statusUrl, ENT_QUOTES) . '"'
            . ' data-paid-text="' . $paidText . '"'
            . ' target="_blank" rel="noopener"'
            . ' style="display:inline-block;padding:.5rem 1rem;background:#006798;color:#fff;'
            . 'text-decoration:none;border-radius:3px;font-weight:600;">' . $payLabel . '</a>'
            . '</div>';
    }

    /**
     * TemplateManager::display dispatcher for the submission pages:
     * - wizard.tpl:   inject popup/polling/step-filter script; splice in the
     *                 dedicated Payment step when enabled.
     * - start.tpl:    informational fee notice on "Begin a Submission".
     * - complete.tpl: fee notice + Pay button on the confirmation page.
     */
    public function handlePageDisplay(string $hookName, array $args): bool
    {
        $template = (string) ($args[1] ?? '');
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];

        switch ($template) {
            case 'submission/wizard.tpl':
                $templateMgr->registerFilter('output', [$this, 'appendWizardScript']);
                $this->maybeAddPaymentStep($templateMgr);
                break;
            case 'submission/start.tpl':
                if ($this->getSetting($this->currentContextId(), 'showOnStart')) {
                    $templateMgr->registerFilter('output', [$this, 'appendStartNotice']);
                }
                break;
            case 'submission/complete.tpl':
                if ($this->getSetting($this->currentContextId(), 'showOnComplete')) {
                    $templateMgr->registerFilter('output', [$this, 'appendCompleteNotice']);
                    $templateMgr->registerFilter('output', [$this, 'appendWizardScript']);
                }
                break;
        }
        return Hook::CONTINUE;
    }

    /** Current context id or null (helper for setting reads in hooks). */
    protected function currentContextId(): ?int
    {
        $context = Application::get()->getRequest()->getContext();
        return $context ? $context->getId() : null;
    }

    /**
     * When the dedicated-step option is on and the fee is outstanding, splice
     * a "Payment" step into the wizard's steps state just before Review. The
     * step's section uses the wizard's generic component branch
     * (section.component), rendered by the SubmissionFeeStep component the
     * injected script registers before the Vue app boots.
     */
    protected function maybeAddPaymentStep(TemplateManager $templateMgr): void
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context || !$this->getSetting($context->getId(), 'ownStep')) {
            return;
        }

        $submission = $templateMgr->getTemplateVars('submission');
        if (!$submission) {
            return;
        }
        $helper = new PaymentHelper($this);
        if (!$helper->feeEnabled($context) || $helper->hasPaid($submission, $context)) {
            return;
        }

        $steps = $templateMgr->getState('steps');
        if (!is_array($steps) || !count($steps)) {
            return;
        }

        $paymentStep = [
            'id' => 'submissionFeePayment',
            'name' => (string) __('plugins.generic.submissionFee.step.name'),
            'reviewName' => '',
            'reviewTemplate' => '',
            'sections' => [
                [
                    'id' => 'submissionFeeNoticeSection',
                    'name' => $helper->noticeText($context, 'noticeTitle', 'plugins.generic.submissionFee.notice.title'),
                    'description' => '',
                    'type' => '',
                    'component' => 'SubmissionFeeStep',
                    'props' => [
                        'noticeHtml' => $this->buildNotice($submission),
                    ],
                ],
            ],
        ];

        // Insert before the Review step (id 'review'); append as fallback.
        $reviewIndex = null;
        foreach ($steps as $i => $step) {
            if (($step['id'] ?? '') === 'review') {
                $reviewIndex = $i;
                break;
            }
        }
        if ($reviewIndex === null) {
            $steps[] = $paymentStep;
        } else {
            array_splice($steps, $reviewIndex, 0, [$paymentStep]);
        }
        $templateMgr->setState(['steps' => $steps]);
    }

    /** Output filter: informational notice on the "Begin a Submission" page. */
    public function appendStartNotice(string $output, $templateMgr): string
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context || str_contains($output, 'submissionFeeStartNotice')) {
            return $output;
        }
        $helper = new PaymentHelper($this);
        if (!$helper->feeEnabled($context)) {
            return $output;
        }

        $title = htmlspecialchars(
            $helper->noticeText($context, 'noticeTitle', 'plugins.generic.submissionFee.notice.title'),
            ENT_QUOTES
        );
        $amountLine = htmlspecialchars(
            (string) __('plugins.generic.submissionFee.notice.amount', [
                'amount' => $helper->formattedAmount($context),
                'currency' => $helper->currency($context),
            ]),
            ENT_QUOTES
        );
        $isHardBlock = $this->getMode($context->getId()) === 'hardBlock';
        $body = htmlspecialchars(
            $helper->noticeText(
                $context,
                $isHardBlock ? 'noticeBodyHardBlock' : 'noticeBodyHoldUntilPaid',
                $isHardBlock
                    ? 'plugins.generic.submissionFee.notice.bodyHardBlock'
                    : 'plugins.generic.submissionFee.notice.bodyHoldUntilPaid'
            ),
            ENT_QUOTES
        );

        $notice = '<div class="submissionFeeNotice submissionFeeStartNotice" role="note"'
            . ' style="margin:1rem auto;max-width:50rem;padding:1rem 1.25rem;border:1px solid #e0a800;'
            . 'border-left-width:4px;border-radius:4px;background:#fff8e6;">'
            . '<h2 style="margin:0 0 .25rem;font-size:1rem;color:#8a6d00;">' . $title . '</h2>'
            . '<p style="margin:0 0 .5rem;"><strong>' . $amountLine . '</strong></p>'
            . '<p style="margin:0;">' . $body . '</p>'
            . '</div>';

        // Place it directly under the page heading.
        $pos = strpos($output, '</h1>');
        if ($pos !== false) {
            return substr_replace($output, '</h1>' . $notice, $pos, strlen('</h1>'));
        }
        return $output;
    }

    /** Output filter: fee notice + Pay button on the submission-complete page. */
    public function appendCompleteNotice(string $output, $templateMgr): string
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || str_contains($output, 'submissionFeeNotice')) {
            return $output;
        }
        $submissionId = (int) $request->getUserVar('id');
        $submission = $submissionId ? Repo::submission()->get($submissionId) : null;
        if (!$submission || $submission->getData('contextId') != $context->getId()) {
            return $output;
        }

        $notice = $this->buildNotice($submission);
        if ($notice === '') {
            return $output;
        }
        // Constrain to the page's content width.
        $notice = '<div style="max-width:50rem;margin:0 auto;">' . $notice . '</div>';

        $pos = strpos($output, '</h1>');
        if ($pos !== false) {
            return substr_replace($output, '</h1>' . $notice, $pos, strlen('</h1>'));
        }
        return $output;
    }

    /** Output filter: add the pay-popup/polling script before </body>. */
    public function appendWizardScript(string $output, $templateMgr): string
    {
        if (str_contains($output, 'sfPayPopupInit') || !str_contains($output, '</body>')) {
            return $output;
        }
        $script = <<<'JS'
<script>
(function () {
    if (window.sfPayPopupInit) { return; } window.sfPayPopupInit = true;

    // Register the dedicated Payment step's component before the Vue app
    // boots (used when the "own step" placement is enabled).
    if (window.pkp && pkp.registry && pkp.modules && pkp.modules.vue) {
        pkp.registry.registerComponent('SubmissionFeeStep', {
            props: { noticeHtml: { type: String, default: '' } },
            render: function () {
                return pkp.modules.vue.h('div', {
                    class: 'submissionFeeStep',
                    innerHTML: this.noticeHtml
                });
            }
        });
    }

    // The wizard banner is cloned by Vue into every step section. Show it
    // only on the steps listed in data-sf-show, and at most once per step.
    var SF_STEP_IDS = ['details', 'files', 'contributors', 'editors', 'reviewerSuggestions', 'review', 'submissionFeePayment'];
    function sfStepIdFor(node) {
        var el = node.parentElement;
        while (el) {
            if (el.id && SF_STEP_IDS.indexOf(el.id) !== -1) { return el.id; }
            el = el.parentElement;
        }
        return null;
    }
    function sfFilterNotices() {
        var seenSteps = {};
        document.querySelectorAll('.submissionFeeNotice[data-sf-show]').forEach(function (n) {
            var allowed = n.getAttribute('data-sf-show').split(',');
            var stepId = sfStepIdFor(n);
            var show = !stepId || allowed.indexOf(stepId) !== -1;
            if (show && stepId) {
                if (seenSteps[stepId]) { show = false; } // dedupe within a step
                else { seenSteps[stepId] = true; }
            }
            n.style.display = show ? '' : 'none';
        });
    }
    sfFilterNotices();
    setInterval(sfFilterNotices, 800);

    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-sf-pay]') : null;
        if (!a) { return; }
        e.preventDefault();
        var w = window.open(a.href, 'sfPayWin', 'width=980,height=780,menubar=no,toolbar=no');
        if (!w) { window.open(a.href, '_blank'); return; } // popup blocked: fall back to a tab
        var closedAt = 0;
        var timer = setInterval(function () {
            fetch(a.getAttribute('data-status-url'), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.paid) {
                    clearInterval(timer);
                    try { w.close(); } catch (err) {}
                    document.querySelectorAll('.submissionFeeNotice').forEach(function (n) {
                        n.style.background = '#ecfdf5';
                        n.style.borderColor = '#2d8a4e';
                        n.innerHTML = '<p style="margin:0;color:#2d8a4e;font-weight:600;">✓ '
                            + (a.getAttribute('data-paid-text') || 'Payment received.') + '</p>';
                    });
                }
            }).catch(function () {});
            // Keep polling ~30s after the popup closes (webhook/callback race),
            // then stop.
            if (w.closed) {
                if (!closedAt) { closedAt = Date.now(); }
                else if (Date.now() - closedAt > 30000) { clearInterval(timer); }
            }
        }, 4000);
    }, true);
}());
</script>
JS;
        return str_replace('</body>', $script . "\n</body>", $output);
    }

    /**
     * Register this plugin's mailables so their templates are editable under
     * Settings > Emails.
     */
    public function addMailables(string $hookName, array $args): bool
    {
        $mailables = $args[0];
        if (is_object($mailables)) {
            $mailables->push(\APP\plugins\generic\submissionFee\mail\SubmissionFeeRequired::class);
        }
        return Hook::CONTINUE;
    }

    /** @copydoc Plugin::getInstallEmailTemplatesFile() */
    public function getInstallEmailTemplatesFile()
    {
        return $this->getPluginPath() . '/emailTemplates.xml';
    }

    /**
     * Send the fee-required email to the submitting author (holdUntilPaid mode).
     * Subject/body come from the editable SUBMISSION_FEE_REQUIRED template,
     * with hardcoded fallbacks so the mail is never silently dropped.
     */
    protected function sendFeeRequiredEmail($submission, $context): void
    {
        try {
            $request = Application::get()->getRequest();
            $user = $request->getUser();
            if (!$user) {
                return;
            }
            $helper = new PaymentHelper($this);
            $mailable = new mail\SubmissionFeeRequired($context, $submission, $helper);

            $subject = '';
            $body = '';
            try {
                $tpl = Repo::emailTemplate()->getByKey($context->getId(), $mailable::getEmailTemplateKey());
                if ($tpl) {
                    $subject = (string) $tpl->getLocalizedData('subject');
                    $body = (string) $tpl->getLocalizedData('body');
                }
            } catch (\Throwable $e) {
                // fall through to hardcoded fallback
            }
            if (trim($subject) === '') {
                $subject = (string) __('emails.submissionFeeRequired.subject');
            }
            if (trim($body) === '') {
                $body = (string) __('emails.submissionFeeRequired.body');
            }

            $fromEmail = (string) ($context->getData('contactEmail') ?: $context->getData('supportEmail') ?: '');
            if ($fromEmail !== '') {
                $mailable->from($fromEmail, (string) ($context->getData('contactName') ?: $context->getLocalizedName()));
            }
            $mailable->recipients([$user]);
            $mailable->subject($subject)->body($body);
            Mail::send($mailable);
        } catch (\Throwable $e) {
            error_log('[SubmissionFee] fee-required email failed: ' . $e->getMessage());
        }
    }

    /** Route /index.php/<journal>/submissionFee/... to our handler. */
    public function setupPaymentHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $op = &$args[1];
        if ($page === 'submissionFee') {
            define('SUBMISSION_FEE_PLUGIN_NAME', $this->getName());
            $args[2] = $this->getPluginPath() . '/pages/PaymentHandler.php';
            $handler = '\APP\plugins\generic\submissionFee\pages\PaymentHandler';
            \PKP\core\Registry::set('plugin', $this);
            $args[3] = new $handler();
            return Hook::ABORT;
        }
        return Hook::CONTINUE;
    }

    // ---- Settings helpers -------------------------------------------------

    public function getMode(?int $contextId): string
    {
        return $this->getSetting($contextId, 'mode') ?: 'hardBlock';
    }

    /** Wizard step ids the journal can target with the banner notice. */
    public const WIZARD_STEPS = ['details', 'files', 'contributors', 'editors', 'reviewerSuggestions', 'review'];

    /**
     * The wizard steps the banner notice may show on. Reads the noticeSteps
     * setting (comma-separated step ids); falls back to the legacy
     * noticePlacement setting, defaulting to the Review step.
     */
    public function getNoticeSteps(): array
    {
        $contextId = $this->currentContextId();
        if ($contextId === null) {
            return [];
        }
        $raw = trim((string) $this->getSetting($contextId, 'noticeSteps'));
        if ($raw !== '') {
            return array_values(array_intersect(explode(',', $raw), self::WIZARD_STEPS));
        }
        // Back-compat with the 1.2.0.0 noticePlacement setting.
        return match ((string) $this->getSetting($contextId, 'noticePlacement')) {
            'everyStep', 'reviewAndSteps' => self::WIZARD_STEPS,
            default => ['review'],
        };
    }

    // ---- Plugin metadata --------------------------------------------------

    public function getDisplayName()
    {
        return __('plugins.generic.submissionFee.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.submissionFee.description');
    }

    /** Add a "Settings" action to the plugin row. */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, array_merge($actionArgs, ['verb' => 'settings'])),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));
        return $actions;
    }

    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'settings') {
            $form = new SettingsForm($this);
            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
            } else {
                $form->initData();
            }
            return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }
}