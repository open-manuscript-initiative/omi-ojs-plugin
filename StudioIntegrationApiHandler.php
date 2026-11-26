<?php
namespace APP\plugins\generic\studioIntegration;

use APP\handler\Handler;
use PKP\core\JSONMessage;

class StudioIntegrationApiHandler extends Handler
{
    private StudioIntegrationPlugin $plugin;

    public function __construct(StudioIntegrationPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;

        // Do not add a PKP role assignment gate here. The launch operation
        // performs stricter role and submission-level checks itself through
        // resolveLaunchMode(), createLaunchUrl(), and createReviewerLaunchUrl().
        // Keeping both gates caused valid editorial users to be rejected by
        // PKPHandler before launch() could perform the authoritative checks.
    }

    public function launch(array $args, $request)
    {
        $error = method_exists($this->plugin, 'getInstallationError')
            ? $this->plugin->getInstallationError()
            : [
                'code' => 'PLUGIN_INSTALLATION_INCOMPLETE',
                'message' => 'The OJS integration plugin files are from different versions. Ask the OJS administrator to reinstall the complete release package.',
            ];
        if ($error) {
            http_response_code(503);
            return new JSONMessage(false, ['error' => $error]);
        }

        if ($request->getUserVar('issueId') !== null) {
            return $this->launchIssue($request);
        }

        $submissionId = filter_var(
            $request->getUserVar('submissionId'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$submissionId) {
            return new JSONMessage(false, [
                'error' => [
                    'code' => 'INVALID_SUBMISSION_ID',
                    'message' => 'A valid submissionId is required.',
                ],
            ]);
        }

        $requestedMode = (string)$request->getUserVar('mode');
        $resolvedMode = $this->plugin->resolveLaunchMode($request, $requestedMode);

        if ($resolvedMode === 'review') {
            $launchUrl = $this->plugin->createReviewerLaunchUrl($request, $submissionId);
        } elseif ($resolvedMode === 'author') {
            $launchUrl = $this->plugin->createLaunchUrl($request, $submissionId, 'author');
        } else {
            $launchUrl = $this->plugin->createLaunchUrl($request, $submissionId, 'editor');
        }

        if ($launchUrl === null) {
            return new JSONMessage(false, [
                'error' => [
                    'code' => 'LAUNCH_FORBIDDEN',
                    'message' => $resolvedMode === 'review'
                        ? 'The current reviewer cannot open this submission in Open Manuscript Studio review mode.'
                        : ($resolvedMode === 'author'
                            ? 'The current author cannot open this submission in Open Manuscript Studio.'
                            : 'The current editor cannot launch this submission in Open Manuscript Studio.'),
                ],
            ]);
        }

        if ((string)$request->getUserVar('redirect') === '1') {
            $this->sendDirectRedirect($launchUrl);
        }

        return new JSONMessage(
            true,
            [
                'launchUrl' => $launchUrl,
                'mode' => $resolvedMode,
            ],
            '0',
            [
                'launchUrl' => $launchUrl,
                'mode' => $resolvedMode,
            ]
        );
    }

    private function launchIssue($request)
    {
        $issueId = filter_var($request->getUserVar('issueId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$issueId || $request->getUserVar('submissionId') !== null) {
            return new JSONMessage(false, ['error' => ['code' => 'INVALID_ISSUE_ID', 'message' => 'Exactly one valid issueId is required.']]);
        }
        if (!method_exists($this->plugin, 'createIssueLaunchUrl')) {
            http_response_code(503);
            return new JSONMessage(false, ['error' => [
                'code' => 'PLUGIN_INSTALLATION_INCOMPLETE',
                'message' => 'The issue launch integration is not installed completely.',
            ]]);
        }
        $launchUrl = $this->plugin->createIssueLaunchUrl($request, $issueId);
        if ($launchUrl === null) {
            return new JSONMessage(false, ['error' => ['code' => 'ISSUE_LAUNCH_FORBIDDEN', 'message' => 'The current editor cannot import this planned issue.']]);
        }
        if ((string)$request->getUserVar('redirect') === '1') {
            $this->sendDirectRedirect($launchUrl);
        }
        return new JSONMessage(true, ['launchUrl' => $launchUrl, 'mode' => 'issue'], '0', ['launchUrl' => $launchUrl, 'mode' => 'issue']);
    }

    private function sendDirectRedirect(string $launchUrl): void
    {
        // Use a raw HTTP redirect rather than PKP's redirect pipeline or an
        // intermediate HTML/JavaScript handoff. The Studio launch assertion is
        // single-use, so the browser must receive exactly one navigation target.
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $launchUrl, true, 303);
        exit;
    }
}
