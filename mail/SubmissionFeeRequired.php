<?php

/**
 * @file mail/SubmissionFeeRequired.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SubmissionFeeRequired
 * @brief Email sent to the submitting author when their submission completes
 *        with an outstanding submission fee (hold-until-paid mode).
 */

namespace APP\plugins\generic\submissionFee\mail;

use APP\plugins\generic\submissionFee\PaymentHelper;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class SubmissionFeeRequired extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'mailable.submissionFeeRequired.name';
    protected static ?string $description = 'mailable.submissionFeeRequired.description';
    protected static ?string $emailTemplateKey = 'SUBMISSION_FEE_REQUIRED';
    protected static bool $supportsTemplates = true;
    protected static array $groupIds = [self::GROUP_SUBMISSION];
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR];

    // NB: every constructor parameter MUST be type-hinted — the Emails
    // settings page reflects mailable constructors to derive template
    // variables and throws on untyped parameters.
    public function __construct(Context $context, Submission $submission, PaymentHelper $helper)
    {
        parent::__construct([$context, $submission]);

        $publication = $submission->getCurrentPublication();
        $title = $publication ? strip_tags((string) $publication->getLocalizedFullTitle(null, 'html')) : '';

        $this->addData([
            'submissionTitle' => $title,
            'submissionFeeAmount' => $helper->displayAmount($context),
            'submissionFeeCurrency' => $helper->currency($context),
            'submissionFeePayUrl' => $helper->payUrl($submission, $context),
        ]);
    }

    public static function getDataDescriptions(): array
    {
        $descriptions = parent::getDataDescriptions();
        $descriptions['submissionTitle'] = __('plugins.generic.submissionFee.emailVar.submissionTitle');
        $descriptions['submissionFeeAmount'] = __('plugins.generic.submissionFee.emailVar.amount');
        $descriptions['submissionFeeCurrency'] = __('plugins.generic.submissionFee.emailVar.currency');
        $descriptions['submissionFeePayUrl'] = __('plugins.generic.submissionFee.emailVar.payUrl');
        return $descriptions;
    }
}
