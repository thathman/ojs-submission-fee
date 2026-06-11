<?php

/**
 * @file PaymentHelper.php
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
use PKP\payment\QueuedPayment;

class PaymentHelper
{
    private SubmissionFeePlugin $plugin;

    public function __construct(SubmissionFeePlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function feeEnabled(Context $context): bool
    {
        // 'feeEnabled', not 'enabled': GenericPlugin reserves 'enabled' for
        // the plugin-enabled flag itself.
        return (bool) $this->plugin->getSetting($context->getId(), 'feeEnabled')
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
        // NB: getByAssoc()'s first parameter is a USER id filter, not a context
        // id — pass null to match the payment regardless of who paid.
        $payment = $dao->getByAssoc(
            null,
            SubmissionFeePlugin::PAYMENT_TYPE_SUBMISSION,
            $submission->getId()
        );
        return (bool) $payment;
    }

    /**
     * Resolve a notice text setting, falling back to the locale default when
     * the journal has not customised it.
     */
    public function noticeText(Context $context, string $settingName, string $localeKey, array $localeParams = []): string
    {
        $custom = trim((string) $this->plugin->getSetting($context->getId(), $settingName));
        return $custom !== '' ? $custom : (string) __($localeKey, $localeParams);
    }

    /**
     * Create + queue a submission-fee payment and return the queued payment id.
     */
    public function queueForSubmission(Submission $submission, Context $context): int
    {
        $request = Application::get()->getRequest();
        $paymentManager = Application::get()->getPaymentManager($context);

        // Submissions carry no 'submitterId' in OJS 3.5; the payer is the
        // logged-in user (the handler has already authorized them).
        $userId = $request->getUser() ? $request->getUser()->getId() : null;

        // Build the QueuedPayment directly: OJSPaymentManager::createQueuedPayment()
        // treats PAYMENT_TYPE_SUBMISSION as deprecated (no return URL, logs an
        // "Invalid payment type" error) even though fulfillQueuedPayment()
        // still completes it correctly.
        $queued = new QueuedPayment(
            $this->amount($context),
            $this->currency($context),
            $userId,
            $submission->getId()
        );
        $queued->setContextId($context->getId());
        $queued->setType(SubmissionFeePlugin::PAYMENT_TYPE_SUBMISSION);
        // After payment, return the author to the submission wizard.
        $queued->setRequestUrl($request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'submission',
            null,
            null,
            ['id' => $submission->getId()]
        ));

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