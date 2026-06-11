<?php

/**
 * @file SettingsForm.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Journal-level settings: enable, amount, currency, enforcement mode.
 */

namespace APP\plugins\generic\submissionFee;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    private SubmissionFeePlugin $plugin;

    public function __construct(SubmissionFeePlugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorCustom(
            $this, 'amount', 'required', 'plugins.generic.submissionFee.settings.amount.invalid',
            function ($value) {
                return is_numeric($value) && (float) $value > 0;
            }
        ));
    }

    public function initData()
    {
        $contextId = Application::get()->getRequest()->getContext()->getId();
        $this->setData('feeEnabled', (bool) $this->plugin->getSetting($contextId, 'feeEnabled'));
        $this->setData('amount', $this->plugin->getSetting($contextId, 'amount'));
        $this->setData('currency', $this->plugin->getSetting($contextId, 'currency'));
        $this->setData('mode', $this->plugin->getSetting($contextId, 'mode') ?: 'hardBlock');
        $this->setData('noticeSteps', $this->plugin->getNoticeSteps());
        $this->setData('ownStep', (bool) $this->plugin->getSetting($contextId, 'ownStep'));
        $this->setData('showOnStart', (bool) $this->plugin->getSetting($contextId, 'showOnStart'));
        $this->setData('showOnComplete', (bool) $this->plugin->getSetting($contextId, 'showOnComplete'));
        $this->setData('noticeTitle', $this->plugin->getSetting($contextId, 'noticeTitle'));
        $this->setData('noticeBodyHardBlock', $this->plugin->getSetting($contextId, 'noticeBodyHardBlock'));
        $this->setData('noticeBodyHoldUntilPaid', $this->plugin->getSetting($contextId, 'noticeBodyHoldUntilPaid'));
        parent::initData();
    }

    public function readInputData()
    {
        $this->readUserVars([
            'feeEnabled', 'amount', 'currency', 'mode',
            'noticeSteps', 'ownStep', 'showOnStart', 'showOnComplete',
            'noticeTitle', 'noticeBodyHardBlock', 'noticeBodyHoldUntilPaid',
        ]);
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $contextId = Application::get()->getRequest()->getContext()->getId();
        // NB: the fee toggle must NOT be stored as 'enabled' — GenericPlugin
        // uses that setting name for the plugin-enabled flag itself.
        $this->plugin->updateSetting($contextId, 'feeEnabled', (bool) $this->getData('feeEnabled'), 'bool');
        $this->plugin->updateSetting($contextId, 'amount', (float) $this->getData('amount'), 'string');
        $this->plugin->updateSetting($contextId, 'currency', trim((string) $this->getData('currency')), 'string');
        $mode = in_array($this->getData('mode'), ['hardBlock', 'holdUntilPaid']) ? $this->getData('mode') : 'hardBlock';
        $this->plugin->updateSetting($contextId, 'mode', $mode, 'string');
        $steps = (array) $this->getData('noticeSteps');
        $steps = array_values(array_intersect($steps, SubmissionFeePlugin::WIZARD_STEPS));
        $this->plugin->updateSetting($contextId, 'noticeSteps', implode(',', $steps), 'string');
        $this->plugin->updateSetting($contextId, 'ownStep', (bool) $this->getData('ownStep'), 'bool');
        $this->plugin->updateSetting($contextId, 'showOnStart', (bool) $this->getData('showOnStart'), 'bool');
        $this->plugin->updateSetting($contextId, 'showOnComplete', (bool) $this->getData('showOnComplete'), 'bool');
        $this->plugin->updateSetting($contextId, 'noticeTitle', trim((string) $this->getData('noticeTitle')), 'string');
        $this->plugin->updateSetting($contextId, 'noticeBodyHardBlock', trim((string) $this->getData('noticeBodyHardBlock')), 'string');
        $this->plugin->updateSetting($contextId, 'noticeBodyHoldUntilPaid', trim((string) $this->getData('noticeBodyHoldUntilPaid')), 'string');
        return parent::execute(...$functionArgs);
    }
}