<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Adapters\Ojs35Adapter;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Route;
use PKP\config\Config;
use PKP\core\PKPBaseController;
use PKP\security\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudioIntegrationApiController extends PKPBaseController
{
    public function __construct(private StudioIntegrationPlugin $plugin)
    {
    }

    public function getHandlerPath(): string
    {
        return 'omi-integration';
    }

    public function getRouteGroupMiddleware(): array
    {
        return ['has.context'];
    }

    public function getGroupRoutes(): void
    {
        Route::get('', $this->capabilities(...))->name('api.omiIntegration.capabilities');
        Route::get('submission', $this->submission(...))->name('api.omiIntegration.submission');
        Route::get('contributors', $this->contributors(...))->name('api.omiIntegration.contributors');
        Route::get('reviewers', $this->reviewers(...))->name('api.omiIntegration.reviewers');
        Route::get('files', $this->files(...))->name('api.omiIntegration.files');
        Route::get('files/{submissionFileId}/content', $this->fileContent(...))
            ->whereNumber('submissionFileId')
            ->name('api.omiIntegration.fileContent');
    }

    public function capabilities(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) return $this->error('context_required', 'A journal context is required.', 400);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/ojs',
            'implementation' => [
                'name' => 'Open Manuscript Studio Integration for OJS',
                'version' => '1.1.8',
                'platform' => 'ojs',
            ],
            'context' => $this->contextData($context),
            'capabilities' => ['launch', 'metadata.read', 'contributors.read', 'reviewers.read', 'files.read', 'files.content.read'],
        ]);
    }

    public function submission(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (!$this->hasScope($claims, 'metadata.read')) return $this->error('insufficient_scope', 'The signed assertion does not grant the required scope.', 403, ['required' => 'metadata.read']);
        $adapter = new Ojs35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found.', 404);

        $actor = null;
        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        if ($actorId > 0) {
            $actorUser = Repo::user()->get($actorId);
            if ($actorUser) {
                $actor = [
                    'externalId' => (string)$actorId,
                    'email' => (string)$actorUser->getEmail(),
                    'fullName' => (string)$actorUser->getFullName(),
                ];
            }
        }

        return response()->json([
            'protocol' => 'omi-integration/1',
            'installationId' => $this->plugin->getInstallationId($context->getId(), Application::get()->getRequest()),
            'context' => $this->contextData($context),
            'submission' => $adapter->mapSubmission($submission),
            'actor' => $actor,
        ]);
    }

    public function contributors(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (!$this->hasScope($claims, 'contributors.read')) return $this->error('insufficient_scope', 'The signed assertion does not grant the required scope.', 403, ['required' => 'contributors.read']);
        $adapter = new Ojs35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found.', 404);
        return response()->json([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'contributors' => $adapter->mapContributors($submission),
        ]);
    }

    public function reviewers(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;

        // Reviewer-pool identity is editorial metadata. Reuse the existing
        // editor-only contributors.read launch scope so author/reviewer launch
        // assertions can never enumerate the journal's reviewer pool.
        if (!$this->hasScope($claims, 'contributors.read')) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant access to reviewer identities.', 403, ['required' => 'contributors.read']);
        }

        $userGroupIds = Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_REVIEWER, $context->getId());
        $reviewers = [];
        if ($userGroupIds) {
            $users = Repo::user()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterByUserGroupIds($userGroupIds)
                ->getMany();

            foreach ($users as $user) {
                $email = trim((string)$user->getEmail());
                if ($email === '') continue;
                $reviewers[] = [
                    'externalId' => (string)$user->getId(),
                    'email' => $email,
                    'fullName' => (string)$user->getFullName(),
                ];
            }
        }

        usort($reviewers, static fn (array $a, array $b): int => strcasecmp($a['fullName'], $b['fullName']));

        return response()->json([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'reviewers' => $reviewers,
        ]);
    }

    public function files(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (!$this->hasScope($claims, 'files.read')) return $this->error('insufficient_scope', 'The signed assertion does not grant the required scope.', 403, ['required' => 'files.read']);
        $adapter = new Ojs35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found.', 404);
        $files = array_map(function (array $file): array {
            $file['contentPath'] = 'files/' . rawurlencode((string)$file['externalId']) . '/content';
            return $file;
        }, $adapter->mapFiles($submission));
        return response()->json([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'files' => $files,
            'binaryTransfer' => ['available' => true, 'authorization' => 'OMI launch assertion', 'scope' => 'files.read'],
        ]);
    }

    public function fileContent(IlluminateRequest $illuminateRequest): BinaryFileResponse|JsonResponse
    {
        $routeFileId = $illuminateRequest->route('submissionFileId');
        if (!is_scalar($routeFileId) || !ctype_digit((string)$routeFileId)) {
            return $this->error('invalid_file_id', 'Invalid submission file ID.', 400);
        }
        $submissionFileId = (int)$routeFileId;
        if ($submissionFileId < 1) return $this->error('invalid_file_id', 'Invalid submission file ID.', 400);

        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId] = $authorized;
        if (!$this->hasScope($claims, 'files.read')) return $this->error('insufficient_scope', 'The signed assertion does not grant the required scope.', 403, ['required' => 'files.read']);

        $submissionFile = Repo::submissionFile()->get($submissionFileId, $submissionId);
        if (!$submissionFile || (int)$submissionFile->getData('submissionId') !== $submissionId) return $this->error('file_not_found', 'Submission file not found.', 404);
        $fileId = (int)$submissionFile->getData('fileId');
        $storedFile = $fileId > 0 ? app()->get('file')->get($fileId) : null;
        if (!$storedFile || empty($storedFile->path)) return $this->error('file_not_found', 'Stored file content not found.', 404);

        $absolutePath = rtrim((string)Config::getVar('files', 'files_dir'), '/') . '/' . ltrim((string)$storedFile->path, '/');
        if (!is_file($absolutePath) || !is_readable($absolutePath)) return $this->error('file_not_readable', 'Stored file content is not readable.', 404);

        $name = (string)($submissionFile->getData('originalFileName') ?? $submissionFile->getData('name', Application::get()->getRequest()->getContext()?->getPrimaryLocale()) ?? ('submission-file-' . $submissionFileId));
        $mediaType = (string)($submissionFile->getData('mimetype') ?? 'application/octet-stream');
        return response()->file($absolutePath, [
            'Content-Type' => $mediaType,
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($name),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeSubmissionRequest(IlluminateRequest $illuminateRequest): array|JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) return $this->error('context_required', 'A journal context is required.', 400);
        $authorization = trim((string)$illuminateRequest->header('Authorization', ''));
        if (!preg_match('/^OMI\s+([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $authorization, $matches)) return $this->error('authentication_required', 'A signed OMI launch assertion is required.', 401);
        $secret = (string)$this->plugin->getSetting($context->getId(), 'sharedSecret');
        if ($secret === '') return $this->error('integration_not_configured', 'The integration shared secret is not configured.', 503);
        $claims = LaunchToken::verify($matches[1], $matches[2], $secret, $context->getId());
        if (!$claims) return $this->error('invalid_assertion', 'The signed OMI assertion is invalid or expired.', 401);
        $submissionId = (int)($claims['submission']['externalId'] ?? 0);
        if ($submissionId < 1) return $this->error('submission_required', 'The assertion does not identify a submission.', 400);
        return [$claims, $submissionId, $context];
    }

    private function hasScope(array $claims, string $scope): bool
    {
        $scopes = is_array($claims['scope'] ?? null) ? $claims['scope'] : [];
        return in_array($scope, $scopes, true);
    }

    private function contextData(object $context): array
    {
        return ['externalId' => (string)$context->getId(), 'type' => 'journal', 'path' => $context->getPath(), 'name' => (array)$context->getData('name')];
    }

    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['error' => array_merge(['code' => $code, 'message' => $message], $extra)], $status);
    }
}
