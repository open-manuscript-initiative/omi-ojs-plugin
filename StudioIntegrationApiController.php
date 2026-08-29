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
use PKP\db\DAORegistry;
use PKP\security\Role;
use PKP\submission\ReviewFilesDAO;
use PKP\submission\reviewAssignment\ReviewAssignment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudioIntegrationApiController extends PKPBaseController
{
    private const SERVICE_CLOCK_SKEW_SECONDS = 300;

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
        Route::post('review-result', $this->reviewResult(...))->name('api.omiIntegration.reviewResult');
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
                'version' => '1.2.0',
                'platform' => 'ojs',
            ],
            'context' => $this->contextData($context),
            'capabilities' => [
                'launch',
                'metadata.read',
                'contributors.read',
                'reviewers.read',
                'files.read',
                'files.content.read',
                'author.manuscript.write',
                'author.revision.write',
                'review.metadata.read',
                'review.files.read',
                'review.manuscript.read',
                'review.revision.write',
                'review.response.write',
                'review.files.scoped',
            ],
        ]);
    }

    public function submission(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (!$this->hasAnyScope($claims, ['metadata.read', 'review.metadata.read'])) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant submission metadata access.', 403);
        }
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
        if (!$this->hasScope($claims, 'contributors.read')) return $this->error('insufficient_scope', 'The signed assertion does not grant contributor identity access.', 403, ['required' => 'contributors.read']);
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
        if (!$this->hasAnyScope($claims, ['review.identity.read', 'contributors.read'])) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant access to reviewer identities.', 403, ['required' => 'review.identity.read']);
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
        if (!$this->hasAnyScope($claims, ['files.read', 'review.files.read'])) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant file access.', 403);
        }
        $adapter = new Ojs35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found.', 404);

        $files = $adapter->mapFiles($submission);
        if (($claims['actorMode'] ?? '') === 'review') {
            if (!$this->hasScope($claims, 'review.files.read')) return $this->error('insufficient_scope', 'Reviewer file access requires review.files.read.', 403);
            $reviewAssignment = $this->reviewAssignmentForClaims($claims, $submissionId);
            if (!$reviewAssignment) return $this->error('review_assignment_forbidden', 'The review assignment is not valid for this reviewer and submission.', 403);
            $files = array_values(array_filter(
                $files,
                fn (array $file): bool => $this->reviewFileAllowed($reviewAssignment, (int)($file['externalId'] ?? 0))
            ));
        }

        $files = array_map(function (array $file): array {
            $file['contentPath'] = 'files/' . rawurlencode((string)$file['externalId']) . '/content';
            return $file;
        }, $files);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'files' => $files,
            'binaryTransfer' => ['available' => true, 'authorization' => 'OMI launch assertion'],
        ]);
    }

    public function fileContent(IlluminateRequest $illuminateRequest): BinaryFileResponse|JsonResponse
    {
        $routeFileId = $illuminateRequest->route('submissionFileId');
        if (!is_scalar($routeFileId) || !ctype_digit((string)$routeFileId)) return $this->error('invalid_file_id', 'Invalid submission file ID.', 400);
        $submissionFileId = (int)$routeFileId;
        if ($submissionFileId < 1) return $this->error('invalid_file_id', 'Invalid submission file ID.', 400);

        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId] = $authorized;
        if (!$this->hasAnyScope($claims, ['files.read', 'review.files.read'])) return $this->error('insufficient_scope', 'The signed assertion does not grant file access.', 403);

        if (($claims['actorMode'] ?? '') === 'review') {
            if (!$this->hasScope($claims, 'review.files.read')) return $this->error('insufficient_scope', 'Reviewer file access requires review.files.read.', 403);
            $reviewAssignment = $this->reviewAssignmentForClaims($claims, $submissionId);
            if (!$reviewAssignment || !$this->reviewFileAllowed($reviewAssignment, $submissionFileId)) {
                return $this->error('file_not_available_for_review', 'This file is not available to the current review assignment.', 403);
            }
        }

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

    public function reviewResult(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A journal context is required.', 400);
        $serviceError = $this->authorizeServiceRequest($illuminateRequest, $context->getId());
        if ($serviceError) return $serviceError;

        $submissionId = (int)$illuminateRequest->input('submissionExternalId', 0);
        $reviewAssignmentId = (int)$illuminateRequest->input('reviewAssignmentExternalId', 0);
        if ($submissionId < 1 || $reviewAssignmentId < 1) return $this->error('invalid_review_result', 'A valid submission and review assignment are required.', 400);

        $reviewAssignment = Repo::reviewAssignment()->get($reviewAssignmentId, $submissionId);
        if (!($reviewAssignment instanceof ReviewAssignment) || $reviewAssignment->getCancelled() || $reviewAssignment->getDeclined()) {
            return $this->error('review_assignment_not_found', 'Review assignment not found or no longer writable.', 404);
        }
        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found in this journal.', 404);

        $authorComment = trim((string)$illuminateRequest->input('authorAndEditorComment', ''));
        $editorComment = trim((string)$illuminateRequest->input('editorOnlyComment', ''));
        $recommendation = trim((string)$illuminateRequest->input('recommendation', ''));
        if ($authorComment === '' && $editorComment === '' && $recommendation === '') {
            return $this->error('empty_review_result', 'The review result does not contain any writable content.', 400);
        }

        if ($authorComment !== '') Repo::reviewAssignment()->saveReviewComment($reviewAssignment, $authorComment, true);
        if ($recommendation !== '') {
            $editorComment = trim(($editorComment !== '' ? $editorComment . "\n\n" : '') . '[OMI recommendation: ' . $recommendation . ']');
        }
        if ($editorComment !== '') Repo::reviewAssignment()->saveReviewComment($reviewAssignment, $editorComment, false);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$reviewAssignmentId,
            'written' => true,
        ]);
    }

    private function reviewAssignmentForClaims(array $claims, int $submissionId): ?ReviewAssignment
    {
        if (($claims['actorMode'] ?? '') !== 'review') return null;
        if (!$this->hasScope($claims, 'review.manuscript.read')) return null;
        $assignmentId = (int)($claims['reviewAssignment']['externalId'] ?? 0);
        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        if ($assignmentId < 1 || $actorId < 1) return null;
        $assignment = Repo::reviewAssignment()->get($assignmentId, $submissionId);
        if (!($assignment instanceof ReviewAssignment)) return null;
        if ((int)$assignment->getSubmissionId() !== $submissionId || (int)$assignment->getReviewerId() !== $actorId) return null;
        if ($assignment->getCancelled() || $assignment->getDeclined()) return null;
        return $assignment;
    }

    private function reviewFileAllowed(ReviewAssignment $reviewAssignment, int $submissionFileId): bool
    {
        if ($submissionFileId < 1) return false;
        /** @var ReviewFilesDAO $reviewFilesDao */
        $reviewFilesDao = DAORegistry::getDAO('ReviewFilesDAO');
        return (bool)$reviewFilesDao->check($reviewAssignment->getId(), $submissionFileId);
    }

    private function authorizeServiceRequest(IlluminateRequest $request, int $contextId): ?JsonResponse
    {
        $installation = trim((string)$request->header('X-OMI-Installation', ''));
        $timestamp = trim((string)$request->header('X-OMI-Timestamp', ''));
        $signature = trim((string)$request->header('X-OMI-Signature', ''));
        if ($installation === '' || !ctype_digit($timestamp) || $signature === '') return $this->error('service_authentication_required', 'Signed OMI service authentication is required.', 401);
        if (abs(time() - (int)$timestamp) > self::SERVICE_CLOCK_SKEW_SECONDS) return $this->error('service_assertion_expired', 'The OMI service assertion is outside the allowed clock window.', 401);

        $expectedInstallation = $this->plugin->getInstallationId($contextId, Application::get()->getRequest());
        if (!hash_equals($expectedInstallation, $installation)) return $this->error('invalid_installation', 'The OMI installation identifier does not match this journal.', 401);
        $secret = (string)$this->plugin->getSetting($contextId, 'sharedSecret');
        if ($secret === '') return $this->error('integration_not_configured', 'The integration shared secret is not configured.', 503);

        $body = (string)$request->getContent();
        $canonical = $timestamp . "\n" . strtoupper($request->getMethod()) . "\n" . $request->getPathInfo() . "\n" . hash('sha256', $body);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $signature)) return $this->error('invalid_service_signature', 'The OMI service signature is invalid.', 401);
        return null;
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

    private function hasAnyScope(array $claims, array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope($claims, $scope)) return true;
        }
        return false;
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
