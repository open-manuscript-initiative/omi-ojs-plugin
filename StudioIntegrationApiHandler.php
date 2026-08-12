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

    public function launch(array $args, $request): JSONMessage
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

        $mode = (string)$request->getUserVar('mode');
        $launchUrl = $mode === 'review'
            ? $this->plugin->createReviewerLaunchUrl($request, $submissionId)
            : $this->plugin->createLaunchUrl($request, $submissionId);

        if ($launchUrl === null) {
            return new JSONMessage(false, [
                'error' => [
                    'code' => 'LAUNCH_FORBIDDEN',
                    'message' => $mode === 'review'
                        ? 'The current reviewer cannot open this submission in Open Manuscript Studio review mode.'
                        : 'The current user cannot launch this submission in Open Manuscript Studio.',
                ],
            ]);
        }

        return new JSONMessage(true, ['launchUrl' => $launchUrl]);
    }
}
