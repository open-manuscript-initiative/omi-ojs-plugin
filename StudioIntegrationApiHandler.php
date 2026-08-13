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
