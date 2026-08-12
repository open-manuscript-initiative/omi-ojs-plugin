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
        $this->addRoleAssignment(
            [
                ROLE_ID_SITE_ADMIN,
                ROLE_ID_MANAGER,
                ROLE_ID_SUB_EDITOR,
                ROLE_ID_ASSISTANT,
                ROLE_ID_REVIEWER,
                ROLE_ID_AUTHOR,
            ],
            ['launch']
        );
    }

    public function launch(array $args, $request)
    {
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

        // The browser launcher normally navigates directly to this endpoint.
        // Let OJS perform the redirect itself instead of serializing a URL into
        // JSON and asking client JavaScript to unwrap PKP JSONMessage formats.
        if ((string)$request->getUserVar('redirect') === '1') {
            return $request->redirectUrl($launchUrl);
        }

        // Keep a JSON form for diagnostics and non-browser integrations.
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
}
