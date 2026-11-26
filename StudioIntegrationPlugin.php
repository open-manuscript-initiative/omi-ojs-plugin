<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use APP\plugins\generic\studioIntegration\classes\StudioIntegrationSettingsForm;
use PKP\core\APIRouter;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\submission\reviewAssignment\ReviewAssignment;

require_once __DIR__ . '/bootstrap.php';

class StudioIntegrationPlugin extends GenericPlugin
{
    private bool $assetsInjected = false;

    /** Check files before creating assertions or entering helper-dependent APIs. */
    public function getInstallationError(): ?array
    {
        $guard = __DIR__ . '/PackageIntegrity.php';
        if (is_file($guard) && is_readable($guard) && filesize($guard) > 0) {
            try {
                require_once $guard;
                if (!class_exists(PackageIntegrity::class, false)) {
                    throw new \RuntimeException('OMI plugin installation check could not be loaded: PackageIntegrity.php');
                }
                PackageIntegrity::assertComplete(__DIR__);
                return null;
            } catch (\Throwable $e) {
                error_log($e->getMessage());
            }
        } else {
            error_log('OMI plugin installation check is missing, empty or unreadable: PackageIntegrity.php');
        }
        // Filesystem paths and permission details belong in the administrator log.
        return [
            'code' => 'PLUGIN_INSTALLATION_INCOMPLETE',
            'message' => 'The OJS integration plugin installation is incomplete or unreadable. Ask the OJS administrator to check plugin file permissions and reinstall the complete release package.',
        ];
    }

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('TemplateManager::display', $this->displayTemplateHook(...));
            Hook::add('LoadHandler', $this->loadApiHandler(...));
            Hook::add('APIHandler::endpoints::plugin', $this->registerApiController(...));
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
        $installationError = $this->getInstallationError();
        if ($installationError && $request->getUserVar('save')) {
            return new JSONMessage(false, $this->installationErrorHtml());
        }
        // Settings do not depend on manuscript adapters. Keep saved values
        // visible when another runtime helper was lost during installation.
        foreach (['classes/StudioIntegrationSettingsForm.php', 'templates/settingsForm.tpl'] as $file) {
            $path = __DIR__ . '/' . $file;
            if (!is_file($path) || !is_readable($path) || filesize($path) === 0) {
                // AjaxModal requires renderable HTML, including on failure.
                return new JSONMessage(true, $this->installationErrorHtml());
            }
        }
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : PKPApplication::SITE_CONTEXT_ID;
        $form = new StudioIntegrationSettingsForm($this, $contextId);
        $form->setData('installationIncomplete', $installationError !== null);
        $form->setData('installationErrorMessage', $installationError ? __('plugins.generic.studioIntegration.settings.installationIncomplete') : '');
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

    private function installationErrorHtml(): string
    {
        $message = htmlspecialchars(__('plugins.generic.studioIntegration.settings.installationIncomplete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<div class="pkp_notification" role="alert"><p>' . $message
            . '</p><p><code>PLUGIN_INSTALLATION_INCOMPLETE</code></p></div>';
    }

    public function loadApiHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        if ($page !== 'omiIntegration') {
            return false;
        }
        require_once __DIR__ . '/StudioIntegrationApiHandler.php';
        $handler = new StudioIntegrationApiHandler($this);
        return true;
    }

    public function registerApiController(string $hookName, APIRouter $apiRouter): bool
    {
        require_once __DIR__ . '/StudioIntegrationApiController.php';
        $apiRouter->registerPluginApiControllers([
            new StudioIntegrationApiController($this),
        ]);
        return Hook::CONTINUE;
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

        $requestedPage = $request->getRequestedPage();
        if (!in_array($requestedPage, ['workflow', 'dashboard', 'reviewer'], true)) {
            return false;
        }

        $installationError = $this->getInstallationError();

        $launchMode = $this->resolveLaunchMode(
            $request,
            $requestedPage === 'reviewer' ? 'review' : 'auto'
        );
        $reviewerMode = $launchMode === 'review';
        $contextId = $context->getId();
        $studioUrl = rtrim(trim((string)$this->getSetting($contextId, 'studioUrl')), '/');
        if ($studioUrl === '') {
            return false;
        }

        if (!$installationError && $launchMode !== 'review' && $this->ensureSharedSecret($contextId) === '') {
            return false;
        }

        $templateMgr = $params[0];
        $pluginBase = rtrim($request->getBaseUrl(), '/') . '/' . ltrim($this->getPluginPath(), '/');
        $launchEndpoint = $request->url(
            $context->getPath(),
            'omiIntegration',
            'launch'
        );

        $config = json_encode([
            'launchEndpoint' => $launchEndpoint,
            'mode' => $launchMode,
            'installationError' => $installationError ? __('plugins.generic.studioIntegration.settings.installationIncomplete') : null,
            'label' => $reviewerMode
                ? __('plugins.generic.studioIntegration.openInStudioForReview')
                : __('plugins.generic.studioIntegration.openInStudio'),
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
            'studioIntegrationLauncher185',
            $pluginBase . '/js/studioIntegration.js?v=1.8.5',
            ['contexts' => ['backend']]
        );
        $templateMgr->addStyleSheet(
            'studioIntegrationLauncher185',
            $pluginBase . '/css/studioIntegration.css?v=1.8.5',
            ['contexts' => ['backend']]
        );

        $this->assetsInjected = true;
        return false;
    }

    public function resolveLaunchMode($request, string $requestedMode): string
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user) {
            return 'editor';
        }

        if ($this->isEditorialUser($user, $context)) {
            return 'editor';
        }

        if (
            $requestedMode === 'review' &&
            $user->hasRole([Role::ROLE_ID_REVIEWER], $context->getId())
        ) {
            return 'review';
        }

        if ($user->hasRole([Role::ROLE_ID_AUTHOR], $context->getId())) {
            return 'author';
        }

        if ($user->hasRole([Role::ROLE_ID_REVIEWER], $context->getId())) {
            return 'review';
        }

        return 'editor';
    }

    public function createLaunchUrl($request, int $submissionId, string $mode = 'editor'): ?string
    {
        $context = $request->getContext();
        $user = $request->getUser();

        if (!$context || !$user || $submissionId < 1) {
            return null;
        }

        if ($mode === 'editor') {
            if (!$this->isEditorialUser($user, $context)) {
                return null;
            }
        } elseif ($mode === 'author') {
            if (!$user->hasRole([Role::ROLE_ID_AUTHOR], $context->getId())) {
                return null;
            }
        } else {
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
        $apiBaseUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_API,
            $context->getPath(),
            'omi-integration'
        );

        $scope = $mode === 'editor'
            ? [
                'metadata.read',
                'metadata.write',
                'contributors.read',
                'contributors.write',
                'files.read',
                'files.write',
                'manuscript.read',
                'manuscript.write',
                'revision.read',
                'revision.write',
                'review.assignment.read',
                'review.assignment.write',
                'review.identity.read',
                'review.response.read',
            ]
            : [
                'metadata.read',
                'contributors.read',
                'files.read',
                'manuscript.read',
                'author.manuscript.write',
                'author.revision.write',
            ];

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
            'actorMode' => $mode,
            'scope' => $scope,
            'iat' => $now,
            'exp' => $now + $ttl,
            'nonce' => bin2hex(random_bytes(16)),
            'externalBaseUrl' => $request->getBaseUrl(),
            'apiBaseUrl' => $apiBaseUrl,
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

    /** Create a read-only assertion for one unpublished issue, not a submission grant. */
    public function createIssueLaunchUrl($request, int $issueId): ?string
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || $issueId < 1 ||
            !$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $context->getId())) {
            return null;
        }
        $contextId = $context->getId();
        $issue = Repo::issue()->get($issueId, $contextId);
        if (!$issue || (int)$issue->getJournalId() !== (int)$contextId || $issue->getPublished()) {
            return null;
        }
        $studioUrl = rtrim(trim((string)$this->getSetting($contextId, 'studioUrl')), '/');
        $secret = $this->ensureSharedSecret($contextId);
        if ($studioUrl === '' || $secret === '') return null;
        $ttl = (int)$this->getSetting($contextId, 'tokenTtl');
        if ($ttl < 60 || $ttl > 3600) $ttl = 300;
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
            'issue' => ['externalId' => (string)$issueId],
            'actor' => ['externalId' => (string)$user->getId()],
            'actorMode' => 'editor',
            'scope' => ['issue.import.read'],
            'iat' => $now,
            'exp' => $now + $ttl,
            'nonce' => bin2hex(random_bytes(16)),
            'externalBaseUrl' => $request->getBaseUrl(),
            'apiBaseUrl' => $request->getDispatcher()->url(
                $request, Application::ROUTE_API, $context->getPath(), 'omi-integration'
            ),
        ];
        try {
            $token = LaunchToken::issue($claims, $secret);
        } catch (\Throwable) {
            return null;
        }
        return $studioUrl . '/integrations/ojs/issue-launch?' . http_build_query(
            $token, '', '&', PHP_QUERY_RFC3986
        );
    }

    public function createReviewerLaunchUrl($request, int $submissionId): ?string
    {
        $context = $request->getContext();
        $user = $request->getUser();

        if (!$context || !$user || $submissionId < 1) {
            return null;
        }

        if (!$user->hasRole([Role::ROLE_ID_REVIEWER], $context->getId())) {
            return null;
        }

        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) {
            return null;
        }

        $reviewAssignment = Repo::reviewAssignment()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByReviewerIds([$user->getId()], true)
            ->getMany()
            ->first();

        if (!($reviewAssignment instanceof ReviewAssignment)) {
            return null;
        }
        if ($reviewAssignment->getCancelled() || $reviewAssignment->getDeclined()) {
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
        $apiBaseUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_API,
            $context->getPath(),
            'omi-integration'
        );

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
            'reviewAssignment' => [
                'externalId' => (string)$reviewAssignment->getId(),
                'round' => (int)$reviewAssignment->getRound(),
            ],
            'actor' => ['externalId' => (string)$user->getId()],
            'actorMode' => 'review',
            'scope' => [
                'review.metadata.read',
                'review.files.read',
                'review.files.write',
                'review.manuscript.read',
                'review.revision.write',
                'review.response.write',
                'review.form.read',
                'review.form.write',
            ],
            'iat' => $now,
            'exp' => $now + $ttl,
            'nonce' => bin2hex(random_bytes(16)),
            'externalBaseUrl' => $request->getBaseUrl(),
            'apiBaseUrl' => $apiBaseUrl,
        ];

        try {
            $token = LaunchToken::issue($claims, $secret);
        } catch (\Throwable) {
            return null;
        }

        return $studioUrl . '/?' . http_build_query(
            array_merge([
                'review' => '1',
                'ojsReviewLaunch' => '1',
            ], $token),
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

    private function isEditorialUser($user, $context): bool
    {
        return $user->hasRole([
            Role::ROLE_ID_MANAGER,
            Role::ROLE_ID_SUB_EDITOR,
            Role::ROLE_ID_ASSISTANT,
        ], $context->getId()) ||
            $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID);
    }

    private function userCanAccessSubmission($user, $context, int $submissionId): bool
    {
        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) {
            return false;
        }

        if ($this->isEditorialUser($user, $context)) {
            return true;
        }

        if (
            $user->hasRole([Role::ROLE_ID_AUTHOR], $context->getId()) &&
            $this->authorCanAccessSubmission($user, $context, $submission)
        ) {
            return true;
        }

        if (
            $user->hasRole([Role::ROLE_ID_REVIEWER], $context->getId()) &&
            $this->reviewerCanAccessSubmission($user, $submissionId)
        ) {
            return true;
        }

        return false;
    }

    private function authorCanAccessSubmission($user, $context, $submission): bool
    {
        $accessibleWorkflowStages = Repo::user()->getAccessibleWorkflowStages(
            $user->getId(),
            $context->getId(),
            $submission
        );

        foreach ($accessibleWorkflowStages as $roles) {
            if (in_array(Role::ROLE_ID_AUTHOR, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    private function reviewerCanAccessSubmission($user, int $submissionId): bool
    {
        $reviewAssignment = Repo::reviewAssignment()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByReviewerIds([$user->getId()], true)
            ->getMany()
            ->first();

        if (!($reviewAssignment instanceof ReviewAssignment)) {
            return false;
        }

        if ($reviewAssignment->getCancelled() || $reviewAssignment->getDeclined()) {
            return false;
        }

        return true;
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
