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

    /**
     * Common currency symbols for display. ISO codes are for machines; readers
     * expect "₦10,000", not "10000 NGN".
     */
    public const CURRENCY_SYMBOLS = [
        'NGN' => '₦', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
        'CNY' => '¥', 'INR' => '₹', 'ZAR' => 'R', 'GHS' => 'GH₵', 'KES' => 'KSh',
        'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'BRL' => 'R$', 'KRW' => '₩',
    ];

    /**
     * Amount + currency for humans: "₦10,000" (symbol, thousands-separated,
     * decimals only when the amount has them). Falls back to "XYZ 10,000" for
     * currencies without a known symbol.
     */
    public function displayAmount(Context $context): string
    {
        $amount = $this->amount($context);
        $code = strtoupper(trim($this->currency($context)));
        $decimals = fmod($amount, 1.0) != 0.0 ? 2 : 0;
        $number = number_format($amount, $decimals);
        $symbol = self::CURRENCY_SYMBOLS[$code] ?? null;
        return $symbol !== null ? $symbol . $number : trim($code . ' ' . $number);
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
     * True when an un-denied fee-waiver request on this submission should
     * release the submission-fee gate.
     *
     * The Request Waiver plugin writes `waiverRequested` (the fee type) and
     * `waiverStatus` (pending/approved/denied) onto the submission. We read
     * those data fields directly so this plugin keeps no hard dependency on
     * the waiver plugin. A request releases the gate while it is pending or
     * approved; a denied request re-imposes the fee (the author is emailed a
     * payment link). A publication-fee waiver never releases the submission
     * fee. Legacy requests with no status are treated as pending (released).
     */
    public function waiverReleases(Submission $submission): bool
    {
        $requested = (string) $submission->getData('waiverRequested');
        if ($requested === '' || $requested === 'publication') {
            return false;
        }
        return (string) $submission->getData('waiverStatus') !== 'denied';
    }

    /**
     * Whether the fee gate is satisfied for this submission: either a completed
     * payment exists, or an un-denied waiver request releases it.
     */
    public function gateSatisfied(Submission $submission, Context $context): bool
    {
        return $this->hasPaid($submission, $context) || $this->waiverReleases($submission);
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