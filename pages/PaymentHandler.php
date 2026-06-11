<?php

/**
 * @file pages/PaymentHandler.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief /<journal>/submissionFee/pay/{submissionId}
 *        Queues the fee (if not already paid) and forwards the author into the
 *        configured payment method plugin (your Paystack/Flutterwave gateway).
 */

namespace APP\plugins\generic\submissionFee\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\submissionFee\PaymentHelper;
use APP\plugins\generic\submissionFee\SubmissionFeePlugin;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\core\Registry;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\stageAssignment\StageAssignment;

class PaymentHandler extends Handler
{
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * JSON payment status for a submission's fee, polled by the wizard notice
     * while the payment popup is open.
     * Route: /<journal>/submissionFee/status/{submissionId}
     */
    public function status($args, $request)
    {
        $context = $request->getContext();
        $submissionId = (int) ($args[0] ?? 0);
        $submission = $submissionId ? Repo::submission()->get($submissionId) : null;

        header('Content-Type: application/json; charset=utf-8');
        if (!Validation::isLoggedIn()
            || !$submission
            || $submission->getData('contextId') != $context->getId()
            || !$this->userMayAccess($request, $submission, $context)
        ) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        /** @var SubmissionFeePlugin $plugin */
        $plugin = Registry::get('plugin');
        $helper = new PaymentHelper($plugin);
        echo json_encode([
            'required' => $helper->feeEnabled($context),
            'paid' => $helper->hasPaid($submission, $context),
        ]);
        exit;
    }

    /**
     * Start payment for a submission's fee.
     */
    public function pay($args, $request)
    {
        if (!Validation::isLoggedIn()) {
            Validation::redirectLogin();
        }

        $context = $request->getContext();
        $submissionId = (int) ($args[0] ?? 0);
        $submission = Repo::submission()->get($submissionId);

        if (!$submission || $submission->getData('contextId') != $context->getId()) {
            $request->redirect(null, 'dashboard');
        }

        // Only a participant on the submission (the author gets a stage
        // assignment when the wizard starts) or a journal manager/site admin
        // may pay. NB: submissions have no 'submitterId' field in OJS 3.5.
        $user = $request->getUser();
        if (!$this->userMayAccess($request, $submission, $context)) {
            error_log(sprintf(
                '[SubmissionFee] Unauthorized payment access attempt by user ID %d for submission ID %d',
                $user->getId(),
                $submission->getId()
            ));
            $request->redirect(null, 'dashboard');
        }

        /** @var SubmissionFeePlugin $plugin */
        $plugin = Registry::get('plugin');
        $helper = new PaymentHelper($plugin);

        // Already paid? Bounce back to the submission.
        if ($helper->hasPaid($submission, $context)) {
            $request->redirectUrl($this->returnUrl($request, $submission));
        }

        // Queue and hand off to the active payment method plugin.
        $queuedPaymentId = $helper->queueForSubmission($submission, $context);

        $paymentManager = Application::get()->getPaymentManager($context);
        $queuedPayment = $paymentManager->getQueuedPayment($queuedPaymentId);

        // getPaymentForm() dispatches to whichever paymethod plugin is
        // configured for the journal; false when none is configured.
        $paymentForm = $paymentManager->getPaymentForm($queuedPayment);
        if (!$paymentForm) {
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign([
                'pageTitle' => 'common.payment',
                'message' => 'payment.notFound',
            ]);
            $templateMgr->display('frontend/pages/message.tpl');
            return;
        }
        $paymentForm->display($request);
    }

    /**
     * True when the current user holds a stage assignment on the submission
     * or is a journal manager / site admin.
     */
    private function userMayAccess($request, $submission, $context): bool
    {
        $user = $request->getUser();
        if (!$user) {
            return false;
        }
        $isParticipant = StageAssignment::withSubmissionIds([$submission->getId()])
            ->withUserId($user->getId())
            ->exists();
        return $isParticipant
            || $user->hasRole([Role::ROLE_ID_MANAGER], $context->getId())
            || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID);
    }

    private function returnUrl($request, $submission): string
    {
        // Send the author back to the submission wizard / submission page.
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $request->getContext()->getPath(),
            'submission',
            'wizard',
            null,
            ['id' => $submission->getId()]
        );
    }
}