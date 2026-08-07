<?php
namespace APP\plugins\generic\studioIntegration;

use APP\plugins\generic\studioIntegration\classes\Adapters\Ojs35Adapter;
use APP\plugins\generic\studioIntegration\classes\Core\ApiResponse;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;

class StudioIntegrationApiHandler extends \APP\handler\Handler
{
    public function __construct(private StudioIntegrationPlugin $plugin)
    {
    }

    public function index($args, $request): void
    {
        $this->capabilities($args, $request);
    }

    public function capabilities($args, $request): void
    {
        $context = $request->getContext();
        if (!$context) {
            ApiResponse::error('context_required', 'A journal context is required.', 400);
        }
        ApiResponse::send([
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/ojs',
            'implementation' => [
                'name' => 'Open Manuscript Studio Integration for OJS',
                'version' => '1.1.0',
                'platform' => 'ojs',
            ],
            'context' => [
                'externalId' => (string)$context->getId(),
                'type' => 'journal',
                'path' => $context->getPath(),
                'name' => (array)$context->getData('name'),
            ],
            'capabilities' => [
                'launch',
                'metadata.read',
                'contributors.read',
                'files.read',
            ],
        ]);
    }

    public function submission($args, $request): void
    {
        [$claims, $submissionId, $context] = $this->authorizeSubmissionRequest($request);
        $this->requireScope($claims, 'metadata.read');
        $adapter = new Ojs35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) {
            ApiResponse::error('submission_not_found', 'Submission not found.', 404);
        }
        ApiResponse::send([
            'protocol' => 'omi-integration/1',
            'installationId' => $this->plugin->getInstallationId($context->getId(), $request),
            'context' => $this->contextData($context),
            'submission' => $adapter->mapSubmission($submission),
        ]);
    }

    public function contributors($args, $request): void
    {
        [$claims, $submissionId, $context] = $this->authorizeSubmissionRequest($request);
        $this->requireScope($claims, 'contributors.read');
        $adapter = new Ojs35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) {
            ApiResponse::error('submission_not_found', 'Submission not found.', 404);
        }
        ApiResponse::send([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'contributors' => $adapter->mapContributors($submission),
        ]);
    }

    public function files($args, $request): void
    {
        [$claims, $submissionId, $context] = $this->authorizeSubmissionRequest($request);
        $this->requireScope($claims, 'files.read');
        $adapter = new Ojs35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) {
            ApiResponse::error('submission_not_found', 'Submission not found.', 404);
        }
        ApiResponse::send([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'files' => $adapter->mapFiles($submission),
            'binaryTransfer' => [
                'available' => false,
                'reason' => 'Binary transfer is intentionally deferred to a later profile capability.',
            ],
        ]);
    }

    private function authorizeSubmissionRequest($request): array
    {
        $context = $request->getContext();
        if (!$context) {
            ApiResponse::error('context_required', 'A journal context is required.', 400);
        }
        $payload = (string)$request->getUserVar('payload');
        $signature = (string)$request->getUserVar('signature');
        if ($payload === '' || $signature === '') {
            $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (preg_match('/^OMI\s+([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $authorization, $m)) {
                $payload = $m[1];
                $signature = $m[2];
            }
        }
        if ($payload === '' || $signature === '') {
            ApiResponse::error('authentication_required', 'A signed OMI launch assertion is required.', 401);
        }
        $secret = (string)$this->plugin->getSetting($context->getId(), 'sharedSecret');
        if ($secret === '') {
            ApiResponse::error('integration_not_configured', 'The integration shared secret is not configured.', 503);
        }
        $claims = LaunchToken::verify($payload, $signature, $secret, $context->getId());
        if (!$claims) {
            ApiResponse::error('invalid_assertion', 'The signed OMI assertion is invalid or expired.', 401);
        }
        $submissionId = (int)($claims['submission']['externalId'] ?? 0);
        if ($submissionId < 1) {
            ApiResponse::error('submission_required', 'The assertion does not identify a submission.', 400);
        }
        return [$claims, $submissionId, $context];
    }

    private function requireScope(array $claims, string $scope): void
    {
        $scopes = is_array($claims['scope'] ?? null) ? $claims['scope'] : [];
        if (!in_array($scope, $scopes, true)) {
            ApiResponse::error('insufficient_scope', 'The signed assertion does not grant the required scope.', 403, ['required' => $scope]);
        }
    }

    private function contextData(object $context): array
    {
        return [
            'externalId' => (string)$context->getId(),
            'type' => 'journal',
            'path' => $context->getPath(),
            'name' => (array)$context->getData('name'),
        ];
    }
}
