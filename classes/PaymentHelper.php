<?php

/**
 * @file classes/PaymentHelper.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Wraps OJSPaymentManager + completed-payment lookups for the fee.
 */

namespace APP\plugins\generic\submissionFee;

use APP\core\Application;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\db\DAORegistry;

class PaymentHelper
{
    private SubmissionFeePlugin $plugin;

    public function __construct(SubmissionFeePlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function feeEnabled(Context $context): bool
    {
        return (bool) $this->plugin->getSetting($context->getId(), 'enabled')
            && (float) $this->plugin->getSetting($context->getId(), 'amount') > 0;
    }

    public function amount(Context $context): float
    {
        return (float) $this->plugin->getSetting($context->getId(), 'amount');
    }

    /** Human-readable amount for display (thousands-separated, 2 decimals). */
    public function formattedAmount(Context $context): string
    {
        return number_format($this->amount($context), 2);
    }

    public function currency(Context $context): string
    {
        // Reuse the journal's configured payment currency.
        return $this->plugin->getSetting($context->getId(), 'currency')
            ?: ($context->getData('currency') ?: 'NGN');
    }

    /** True if a completed submission-fee payment exists for this submission. */
    public function hasPaid(Submission $submission, Context $context): bool
    {
        /** @var \APP\payment\ojs\OJSCompletedPaymentDAO $dao */
        $dao = DAORegistry::getDAO('OJSCompletedPaymentDAO');
        $payment = $dao->getByAssoc(
            $context->getId(),
            SubmissionFeePlugin::PAYMENT_TYPE_SUBMISSION,
            $submission->getId()
        );
        return (bool) $payment;
    }

    /**
     * Create + queue a submission-fee payment and return the queued payment id.
     */
    public function queueForSubmission(Submission $submission, Context $context): int
    {
        $request = Application::get()->getRequest();
        $paymentManager = Application::get()->getPaymentManager($context);

        $userId = $submission->getData('submitterId')
            ?? ($request->getUser() ? $request->getUser()->getId() : null);

        $queued = $paymentManager->createQueuedPayment(
            $request,
            SubmissionFeePlugin::PAYMENT_TYPE_SUBMISSION,
            $userId,
            $submission->getId(),
            $this->amount($context),
            $this->currency($context)
        );

        return $paymentManager->queuePayment($queued);
    }

    /** URL the author follows to start payment for this submission. */
    public function payUrl(Submission $submission, Context $context): string
    {
        $request = Application::get()->getRequest();
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'submissionFee',
            'pay',
            [$submission->getId()]
        );
    }
}