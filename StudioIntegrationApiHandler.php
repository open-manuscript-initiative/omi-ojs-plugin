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
            $this->sendBrowserHandoff($launchUrl);
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

    private function sendBrowserHandoff(string $launchUrl): void
    {
        $scriptUrl = json_encode(
            $launchUrl,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        if ($scriptUrl === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Unable to encode the Open Manuscript Studio launch URL.';
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Opening Open Manuscript Studio</title></head>'
            . '<body><p>Opening Open Manuscript Studio…</p>'
            . '<p><button id="omi-studio-handoff" type="button">Continue to Open Manuscript Studio</button></p>'
            . '<script>(function(){var target=' . $scriptUrl . ';'
            . 'var button=document.getElementById("omi-studio-handoff");'
            . 'if(button){button.addEventListener("click",function(){window.location.assign(target);});}'
            . 'window.location.replace(target);})();</script>'
            . '</body></html>';
        exit;
    }
}
