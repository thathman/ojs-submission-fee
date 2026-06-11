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
use Illuminate\Support\Facades\Event;
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

        // --- AUTHOR-FACING NOTICE: surface the fee + a pay link inside the
        // submission wizard's Review step. Uses the native, documented
        // Template::SubmissionWizard::Section::Review hook (no Vue build, no
        // theme edits), so it is upgrade-safe across 3.5.x point releases. ---
        Hook::add('Template::SubmissionWizard::Section::Review', [$this, 'addReviewStepNotice']);

        // --- HOLD UNTIL PAID: queue the fee after the wizard completes ---
        Event::listen(SubmissionSubmitted::class, function (SubmissionSubmitted $event) {
            if ($this->getMode($event->context->getId()) !== 'holdUntilPaid') {
                return;
            }
            $helper = new PaymentHelper($this);
            $helper->queueForSubmission($event->submission, $event->context);
            $event->submission->setData('submissionFeeOutstanding', true);
            Repo::submission()->edit($event->submission, []);
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
     * Render a fee notice + "Pay now" link in the submission wizard's Review
     * step. Fires on the native Template::SubmissionWizard::Section::Review
     * hook, whose callback receives [&$params, $smarty, &$output].
     *
     * The Review step iterates server-side over the review sub-steps, so this
     * hook can fire several times per page; a static guard renders the notice
     * exactly once. Output is plain, Vue-safe static HTML (no {{ }} bindings).
     *
     * @param string $hookName
     * @param array  $args [&$params, $smarty, &$output]
     */
    public function addReviewStepNotice(string $hookName, array $args): bool
    {
        static $rendered = false;
        if ($rendered) {
            return Hook::CONTINUE;
        }

        $params = $args[0];
        $output = &$args[2];

        $submission = $params['submission'] ?? null;
        $context = Application::get()->getRequest()->getContext();
        if (!$submission || !$context) {
            return Hook::CONTINUE;
        }

        $helper = new PaymentHelper($this);
        if (!$helper->feeEnabled($context) || $helper->hasPaid($submission, $context)) {
            return Hook::CONTINUE;
        }

        // Render once, regardless of how many review sub-steps follow.
        $rendered = true;

        $isHardBlock = $this->getMode($context->getId()) === 'hardBlock';
        $payUrl = $helper->payUrl($submission, $context);

        $title = htmlspecialchars((string) __('plugins.generic.submissionFee.notice.title'), ENT_QUOTES);
        $amountLine = htmlspecialchars(
            (string) __('plugins.generic.submissionFee.notice.amount', [
                'amount' => $helper->formattedAmount($context),
                'currency' => $helper->currency($context),
            ]),
            ENT_QUOTES
        );
        $body = htmlspecialchars(
            (string) __($isHardBlock
                ? 'plugins.generic.submissionFee.notice.bodyHardBlock'
                : 'plugins.generic.submissionFee.notice.bodyHoldUntilPaid'),
            ENT_QUOTES
        );
        $payLabel = htmlspecialchars((string) __('plugins.generic.submissionFee.notice.payNow'), ENT_QUOTES);

        // Inline styles keep this theme-agnostic on any 3.5 install.
        $output .= '<div class="submissionFeeNotice" role="note"'
            . ' style="margin:1rem 0;padding:1rem 1.25rem;border:1px solid #e0a800;'
            . 'border-left-width:4px;border-radius:4px;background:#fff8e6;">'
            . '<h3 style="margin:0 0 .25rem;font-size:1rem;color:#8a6d00;">' . $title . '</h3>'
            . '<p style="margin:0 0 .5rem;"><strong>' . $amountLine . '</strong></p>'
            . '<p style="margin:0 0 .75rem;">' . $body . '</p>'
            . '<a href="' . htmlspecialchars($payUrl, ENT_QUOTES) . '"'
            . ' class="pkp_button" target="_blank" rel="noopener"'
            . ' style="display:inline-block;padding:.5rem 1rem;background:#006798;color:#fff;'
            . 'text-decoration:none;border-radius:3px;font-weight:600;">' . $payLabel . '</a>'
            . '</div>';

        return Hook::CONTINUE;
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