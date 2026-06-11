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
use PKP\core\Registry;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class PaymentHandler extends Handler
{
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
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

        // Only the submitter may pay.
        $user = $request->getUser();
        if ($submission->getData('submitterId') != $user->getId()) {
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

        // displayPaymentForm() dispatches to whichever paymethod plugin is
        // configured for the journal (your Paystack/Flutterwave plugin).
        $paymentManager->displayPaymentForm($queuedPaymentId, $queuedPayment, $request);
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