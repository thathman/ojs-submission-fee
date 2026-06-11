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
        parent::initData();
    }

    public function readInputData()
    {
        $this->readUserVars(['feeEnabled', 'amount', 'currency', 'mode']);
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
        return parent::execute(...$functionArgs);
    }
}