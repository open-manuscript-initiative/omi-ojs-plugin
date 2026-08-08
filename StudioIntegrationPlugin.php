<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use APP\plugins\generic\studioIntegration\classes\StudioIntegrationSettingsForm;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

class StudioIntegrationPlugin extends GenericPlugin
{
    private bool $assetsInjected = false;

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('TemplateManager::display', $this->displayTemplateHook(...));
            Hook::add('LoadHandler', $this->loadApiHandler(...));
        }
        return $success;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.studioIntegration.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.studioIntegration.description');
    }

    public function getActions($request, $verb): array
    {
        $router = $request->getRouter();
        return array_merge($this->getEnabled() ? [
            new LinkAction('settings', new AjaxModal($router->url($request, null, null, 'manage', null, [
                'verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic',
            ]), $this->getDisplayName()), __('manager.plugins.settings'), null),
        ] : [], parent::getActions($request, $verb));
    }

    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : PKPApplication::SITE_CONTEXT_ID;
        $form = new StudioIntegrationSettingsForm($this, $contextId);
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

    public function loadApiHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        if ($page !== 'omiIntegration') {
            return false;
        }
        require_once($this->getPluginPath() . '/StudioIntegrationApiHandler.php');
        $handler = new StudioIntegrationApiHandler($this);
        return true;
    }

    public function displayTemplateHook(string $hookName, array $params): bool
    {
        if ($this->assetsInjected) {
            return false;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();

        if (!$context || !$user) {
            return false;
        }

        if (!in_array($request->getRequestedPage(), ['workflow', 'dashboard'], true)) {
            return false;
        }

        $contextId = $context->getId();
        $studioUrl = rtrim(trim((string)$this->getSetting($contextId, 'studioUrl')), '/');
        if ($studioUrl === '') {
            return false;
        }

        if ($this->ensureSharedSecret($contextId) === '') {
            return false;
        }

        $templateMgr = $params[0];
        $pluginBase = $request->getBaseUrl() . '/' . $this->getPluginPath();
        $launchEndpoint = $request->url(
            $context->getPath(),
            'omiIntegration',
            'launch'
        );

        $config = json_encode([
            'launchEndpoint' => $launchEndpoint,
            'label' => __('plugins.generic.studioIntegration.openInStudio'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if ($config === false) {
            return false;
        }

        $templateMgr->addHeader(
            'studioIntegrationConfig',
            '<script>window.OMI_STUDIO_INTEGRATION=' . $config . ';</script>',
            ['contexts' => ['backend']]
        );
        $templateMgr->addJavaScript(
            'studioIntegration',
            $pluginBase . '/js/studioIntegration.js',
            ['contexts' => ['backend']]
        );
        $templateMgr->addStyleSheet(
            'studioIntegration',
            $pluginBase . '/css/studioIntegration.css',
            ['contexts' => ['backend']]
        );

        $this->assetsInjected = true;
        return false;
    }

    public function createLaunchUrl($request, int $submissionId): ?string
    {
        $context = $request->getContext();
        $user = $request->getUser();

        if (!$context || !$user || $submissionId < 1) {
            return null;
        }

        if (!$this->userCanAccessSubmission($user, $context, $submissionId)) {
            return null;
        }

        $contextId = $context->getId();
        $studioUrl = rtrim(trim((string)$this->getSetting($contextId, 'studioUrl')), '/');
        if ($studioUrl === '') {
            return null;
        }

        $secret = $this->ensureSharedSecret($contextId);
        if ($secret === '') {
            return null;
        }

        $ttl = (int)$this->getSetting($contextId, 'tokenTtl');
        if ($ttl < 60 || $ttl > 3600) {
            $ttl = 300;
        }

        $now = time();
        $claims = [
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/ojs',
            'installationId' => $this->getInstallationId($contextId, $request),
            'context' => [
                'externalId' => (string)$contextId,
                'type' => 'journal',
                'path' => $context->getPath(),
            ],
            'submission' => ['externalId' => (string)$submissionId],
            'actor' => ['externalId' => (string)$user->getId()],
            'scope' => [
                'metadata.read',
                'contributors.read',
                'files.read',
                'manuscript.read',
                'manuscript.write',
                'revision.write',
            ],
            'iat' => $now,
            'exp' => $now + $ttl,
            'nonce' => bin2hex(random_bytes(16)),
            'externalBaseUrl' => $request->getBaseUrl(),
            'apiBaseUrl' => $request->url($context->getPath(), 'omiIntegration'),
        ];

        try {
            $token = LaunchToken::issue($claims, $secret);
        } catch (\Throwable) {
            return null;
        }

        return $studioUrl . '/integrations/ojs/launch?' . http_build_query(
            $token,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    public function getInstallationId(int $contextId, $request): string
    {
        $configured = trim((string)$this->getSetting($contextId, 'installationId'));
        if ($configured !== '') {
            return $configured;
        }
        return 'ojs-' . substr(hash('sha256', strtolower(rtrim($request->getBaseUrl(), '/'))), 0, 16);
    }

    private function userCanAccessSubmission($user, $context, int $submissionId): bool
    {
        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) {
            return false;
        }
        if ($user->hasRole([Role::ROLE_ID_MANAGER], $context->getId()) ||
            $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID)) {
            return true;
        }
        $assignments = StageAssignment::withSubmissionIds([$submissionId])->withUserId($user->getId())->get();
        return $assignments->isNotEmpty();
    }

    private function ensureSharedSecret(int $contextId): string
    {
        $secret = (string)$this->getSetting($contextId, 'sharedSecret');
        if ($secret !== '') {
            return $secret;
        }
        try {
            $secret = bin2hex(random_bytes(32));
            $this->updateSetting($contextId, 'sharedSecret', $secret, 'string');
            return $secret;
        } catch (\Throwable) {
            return '';
        }
    }
}
