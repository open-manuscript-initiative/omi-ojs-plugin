<?php
namespace APP\plugins\generic\studioIntegration\classes;

use APP\plugins\generic\studioIntegration\StudioIntegrationPlugin;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorUrl;

class StudioIntegrationSettingsForm extends Form
{
    public function __construct(private StudioIntegrationPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorUrl($this, 'studioUrl', 'required', 'plugins.generic.studioIntegration.settings.invalidUrl'));
    }

    public function initData(): void
    {
        $secret = (string)$this->plugin->getSetting($this->contextId, 'sharedSecret');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
        }
        $this->setData('studioUrl', (string)$this->plugin->getSetting($this->contextId, 'studioUrl'));
        $this->setData('installationId', (string)$this->plugin->getSetting($this->contextId, 'installationId'));
        $this->setData('sharedSecret', $secret);
        $this->setData('tokenTtl', (int)($this->plugin->getSetting($this->contextId, 'tokenTtl') ?: 300));
        parent::initData();
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = \APP\template\TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    public function readInputData(): void
    {
        $this->readUserVars(['studioUrl', 'installationId', 'sharedSecret', 'tokenTtl']);
        parent::readInputData();
    }

    public function execute(...$functionArgs)
    {
        $studioUrl = rtrim(trim((string)$this->getData('studioUrl')), '/');
        $installationId = trim((string)$this->getData('installationId'));
        $sharedSecret = trim((string)$this->getData('sharedSecret'));
        $tokenTtl = (int)$this->getData('tokenTtl');
        if ($sharedSecret === '') {
            $sharedSecret = bin2hex(random_bytes(32));
        }
        if ($tokenTtl < 60 || $tokenTtl > 3600) {
            $tokenTtl = 300;
        }
        $this->plugin->updateSetting($this->contextId, 'studioUrl', $studioUrl, 'string');
        $this->plugin->updateSetting($this->contextId, 'installationId', $installationId, 'string');
        $this->plugin->updateSetting($this->contextId, 'sharedSecret', $sharedSecret, 'string');
        $this->plugin->updateSetting($this->contextId, 'tokenTtl', $tokenTtl, 'int');
        parent::execute(...$functionArgs);
    }
}
