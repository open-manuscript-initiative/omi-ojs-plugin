<?php
namespace APP\plugins\generic\studioIntegration;

require_once __DIR__ . '/bootstrap.php';

use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Adapters\Ojs35Adapter;
use APP\plugins\generic\studioIntegration\classes\Middleware\EnsureCompleteInstallation;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use APP\plugins\generic\studioIntegration\classes\Core\WorkflowSnapshot;
use APP\plugins\generic\studioIntegration\classes\Core\MetadataReconciliation;
use PKP\observers\events\MetadataChanged;
use APP\plugins\generic\studioIntegration\classes\Core\HtmlGalleyDocument;
use APP\plugins\generic\studioIntegration\classes\Core\PublicationArtifactDocument;
use APP\plugins\generic\studioIntegration\classes\Core\WorkflowFileDocument;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\plugins\PluginRegistry;
use PKP\submissionFile\SubmissionFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Route;
use PKP\config\Config;
use PKP\core\Core;
use PKP\core\PKPBaseController;
use PKP\db\DAORegistry;
use PKP\reviewForm\ReviewFormElement;
use PKP\reviewForm\ReviewFormResponse;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\submission\ReviewFilesDAO;
use PKP\submission\SubmissionComment;
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
        // PKP inspects every route middleware as a string before dispatch.
        // An inline Closure crashes DecodeApiTokenWithValidation even on public APIs.
        return ['has.context', EnsureCompleteInstallation::class];
    }

    public function getGroupRoutes(): void
    {
        Route::get('', $this->capabilities(...))->name('api.omiIntegration.capabilities');
        Route::post('metadata', $this->metadata(...))->middleware(['has.user', self::roleAuthorizer([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT])])->name('api.omiIntegration.metadata');
        Route::get('workflow-status', $this->workflowStatus(...))->middleware(['has.user', self::roleAuthorizer([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT])])->name('api.omiIntegration.workflowStatus');
        // Planned issues contain private editorial material.
        Route::get('issues/{issueId}/import-manifest', $this->issueImportManifest(...))
            ->whereNumber('issueId')
            ->middleware(['has.user', self::roleAuthorizer([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN])])
            ->name('api.omiIntegration.issueImportManifest');
        Route::get('issue-import/manifest', $this->signedIssueManifest(...))->name('api.omiIntegration.signedIssueManifest');
        Route::get('issue-import/articles/{submissionId}', $this->issueArticle(...))
            ->whereNumber('submissionId')->name('api.omiIntegration.issueArticle');
        Route::get('issue-import/articles/{submissionId}/files/{submissionFileId}/content', $this->issueArticleFile(...))
            ->whereNumber('submissionId')->whereNumber('submissionFileId')->name('api.omiIntegration.issueArticleFile');
        Route::get('submission-options', $this->submissionOptions(...))->name('api.omiIntegration.submissionOptions');
        Route::get('submission', $this->submission(...))->name('api.omiIntegration.submission');
        Route::get('contributors', $this->contributors(...))->name('api.omiIntegration.contributors');
        Route::get('reviewers', $this->reviewers(...))->name('api.omiIntegration.reviewers');
        Route::get('files', $this->files(...))->name('api.omiIntegration.files');
        Route::get('review-form', $this->reviewForm(...))->name('api.omiIntegration.reviewForm');
        Route::get('review-recommendations', $this->reviewRecommendations(...))->name('api.omiIntegration.reviewRecommendations');
        Route::get('files/{submissionFileId}/content', $this->fileContent(...))
            ->whereNumber('submissionFileId')
            ->name('api.omiIntegration.fileContent');
        Route::post('review-result', $this->reviewResult(...))->name('api.omiIntegration.reviewResult');
        Route::post('workflow-assignment', $this->workflowAssignment(...))->name('api.omiIntegration.workflowAssignment');
        Route::post('assignment-result', $this->assignmentResult(...))->name('api.omiIntegration.assignmentResult');
        Route::post('review-file', $this->reviewFile(...))->name('api.omiIntegration.reviewFile');
        Route::post('author-revision', $this->authorRevision(...))->name('api.omiIntegration.authorRevision');
        Route::post('html-galley', $this->htmlGalley(...))
            ->middleware(['has.user', self::roleAuthorizer([
                Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
            ])])->name('api.omiIntegration.htmlGalley');
        Route::post('issue-page-range', $this->issuePageRange(...))
            ->middleware(['has.user', self::roleAuthorizer([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN])])
            ->name('api.omiIntegration.issuePageRange');
        Route::post('publication-artifact', $this->publicationArtifact(...))
            ->middleware(['has.user', self::roleAuthorizer([
                Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
            ])])->name('api.omiIntegration.publicationArtifact');
    }


    /**
     * Update only the printed bibliographic page range, not physical PDF indices.
     * The issue import assertion is read-only; this endpoint requires native user authentication.
     */
    public function issuePageRange(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate([
            'action' => 'required|in:inspect,apply',
            'installationId' => 'required|string|max:255',
            'contextId' => 'required|integer|min:1',
            'issueId' => 'required|integer|min:1',
            'submissionId' => 'required|integer|min:1',
            'publicationId' => 'required|integer|min:1',
            'firstPrintedPage' => 'required_if:action,apply|integer|min:1|max:999999',
            'lastPrintedPage' => 'required_if:action,apply|integer|min:1|max:999999',
            'expectedWorkflowToken' => 'required_if:action,apply|string|size:64',
            'confirmed' => 'required_if:action,apply|accepted',
        ]);
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())) {
            return $this->error('editor_required', 'An authenticated editor is required.', 403);
        }
        $contextId = (int)$context->getId();
        $issueId = (int)$data['issueId'];
        $submissionId = (int)$data['submissionId'];
        $publicationId = (int)$data['publicationId'];
        $authorize = function () use ($context, $contextId, $user, $request, $data, $issueId, $submissionId, $publicationId) {
            if ((int)$data['contextId'] !== $contextId ||
                !hash_equals($this->plugin->getInstallationId($contextId, $request), (string)$data['installationId']) ||
                !$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $contextId)) {
                abort(403, 'Matching installation and journal-wide editorial access required.');
            }
            $issue = Repo::issue()->get($issueId, $contextId);
            $submission = Repo::submission()->get($submissionId);
            if (!$issue || (int)$issue->getJournalId() !== $contextId ||
                !$submission || (int)$submission->getData('contextId') !== $contextId) {
                abort(404, 'Planned issue or submission not found.');
            }
            $publication = Repo::publication()->get((int)$submission->getData('currentPublicationId'));
            if ($issue->getPublished() || !$publication || (int)$publication->getId() !== $publicationId ||
                (int)$publication->getData('submissionId') !== $submissionId ||
                (int)$publication->getData('issueId') !== $issueId ||
                (int)$submission->getData('stageId') !== WORKFLOW_STAGE_ID_PRODUCTION ||
                (int)$submission->getData('status') !== Submission::STATUS_SCHEDULED ||
                (int)$publication->getData('status') !== Submission::STATUS_SCHEDULED) {
                abort(409, 'The exact current article must be scheduled in an unpublished production issue.');
            }
            $roles = Repo::user()->getAccessibleWorkflowStages($user->getId(), $contextId, $submission);
            if (!array_intersect($roles[WORKFLOW_STAGE_ID_PRODUCTION] ?? [], [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN]) ||
                !Repo::submission()->canEditPublication($submissionId, $user->getId())) {
                abort(403, 'Native OJS production metadata write permission required.');
            }
            return [$submission, $publication];
        };
        $snapshot = function ($submission, $publication) use ($contextId, $issueId, $data) {
            return [
                'protocol' => 'omi-issue-page-range/1',
                'installationId' => (string)$data['installationId'],
                'contextId' => $contextId,
                'issueId' => $issueId,
                'submissionId' => (int)$submission->getId(),
                'publicationId' => (int)$publication->getId(),
                'pages' => $publication->getData('pages'),
                'workflowToken' => WorkflowSnapshot::create($submission, $publication)['stateToken'],
                'published' => false,
            ];
        };
        if ($data['action'] === 'inspect') {
            [$submission, $publication] = $authorize();
            return response()->json(['snapshot' => $snapshot($submission, $publication)])->header('Cache-Control', 'no-store');
        }
        $first = (int)$data['firstPrintedPage'];
        $last = (int)$data['lastPrintedPage'];
        if ($first < 1 || $last < $first || $last > 999999) {
            return $this->error('invalid_page_range', 'Provide an ordered positive printed page range.', 422);
        }
        $pages = $first === $last ? (string)$first : $first . '-' . $last;
        $result = DB::transaction(function () use ($data, $context, $issueId, $submissionId, $publicationId, $pages, $authorize, $snapshot) {
            // Same lock order as the issue-bound artifact transfer.
            DB::table('issues')->where('issue_id', $issueId)->lockForUpdate()->first();
            DB::table('submissions')->where('submission_id', $submissionId)->lockForUpdate()->first();
            DB::table('publications')->where('publication_id', $publicationId)->lockForUpdate()->first();
            [$submission, $publication] = $authorize();
            $before = $snapshot($submission, $publication);
            if (!hash_equals($before['workflowToken'], (string)$data['expectedWorkflowToken'])) {
                abort(409, 'OJS metadata or workflow state changed. Inspect again.');
            }
            $changed = $before['pages'] !== $pages;
            if ($changed) {
                $params = ['id' => $publicationId, 'submissionId' => $submissionId, 'pages' => $pages];
                if (Repo::publication()->validate($publication, $params, $submission, $context)) {
                    abort(422, 'Native OJS validation rejected the page range.');
                }
                Repo::publication()->edit($publication, $params);
            }
            [$updatedSubmission, $updatedPublication] = $authorize();
            $after = $snapshot($updatedSubmission, $updatedPublication);
            if ($after['pages'] !== $pages) abort(409, 'OJS did not retain the requested printed page range.');
            return [
                'snapshot' => $after,
                'receipt' => ['protocol' => 'omi-issue-page-range/1', 'installationId' => $after['installationId'],
                    'contextId' => $after['contextId'], 'issueId' => $after['issueId'], 'submissionId' => $submissionId,
                    'publicationId' => $publicationId, 'previousPages' => $before['pages'], 'pages' => $pages,
                    'previousWorkflowToken' => $before['workflowToken'], 'workflowToken' => $after['workflowToken'],
                    'unchanged' => !$changed, 'published' => false],
                'submission' => $changed ? $updatedSubmission : null,
            ];
        });
        if ($result['submission']) event(new MetadataChanged($result['submission']));
        unset($result['submission']);
        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    /** Native editorial permissions, current publication and compare-and-apply semantics. */
    public function metadata(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate([
            'action' => 'required|in:inspect,apply', 'submissionId' => 'required|integer|min:1',
            'publicationId' => 'required_if:action,apply|integer|min:1',
            'locale' => 'required_if:action,apply|string|max:32',
            'expectedStateToken' => 'required_if:action,apply|string|size:64',
            'fields' => 'required_if:action,apply|array|min:1|max:4',
            'confirmed' => 'required_if:action,apply|accepted',
        ]);
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())) return $this->error('editor_required', 'An authenticated editorial user is required.', 403);
        $load = function () use ($data, $context, $user) {
            $submission = Repo::submission()->get((int)$data['submissionId']);
            if (!$submission || (int)$submission->getData('contextId') !== (int)$context->getId()) abort(404, 'Submission not found.');
            $stages = Repo::user()->getAccessibleWorkflowStages($user->getId(), $context->getId(), $submission);
            $roles = $stages[(int)$submission->getData('stageId')] ?? [];
            if (!array_intersect($roles, [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT])) abort(403, 'Editorial access to this submission stage is required.');
            $publication = Repo::publication()->get((int)$submission->getData('currentPublicationId'));
            if (!$publication || (int)$publication->getData('submissionId') !== (int)$submission->getId()) abort(409, 'The current publication is unavailable.');
            return [$submission, $publication];
        };
        [$submission, $publication] = $load();
        $canWrite = fn($s, $p) => (int)$p->getData('status') === Submission::STATUS_QUEUED && Repo::submission()->canEditPublication($s->getId(), $user->getId());
        $snapshot = function ($s, $p) use ($context, $canWrite) {
            $adapter = new Ojs35Adapter();
            return MetadataReconciliation::snapshot($s, $p, $context->getSupportedSubmissionMetadataLocales(), $adapter->reconciliationKeywords($p, (string)$s->getData('locale')), $adapter->mapContributors($s), $canWrite($s, $p));
        };
        if ($data['action'] === 'inspect') return response()->json(['snapshot' => $snapshot($submission, $publication)])->header('Cache-Control', 'no-store');
        $result = DB::transaction(function () use ($data, $context, $load, $canWrite, $snapshot) {
            DB::table('submissions')->where('submission_id', (int)$data['submissionId'])->lockForUpdate()->first();
            DB::table('publications')->where('publication_id', (int)$data['publicationId'])->lockForUpdate()->first();
            [$submission, $publication] = $load();
            if (!$canWrite($submission, $publication)) abort(403, 'Native OJS metadata write permission on an unpublished publication is required.');
            $current = $snapshot($submission, $publication);
            if ((int)$data['publicationId'] !== (int)$publication->getId() || !hash_equals($current['stateToken'], (string)$data['expectedStateToken'])) abort(409, 'OJS metadata changed. Inspect and compare again.');
            try { $params = MetadataReconciliation::patch($publication, (string)$data['locale'], (array)$data['fields'], $context->getSupportedSubmissionMetadataLocales(), $current['metadata']['keywords']); }
            catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
            $params['id'] = $publication->getId();
            $params['submissionId'] = $submission->getId();
            $errors = Repo::publication()->validate($publication, $params, $submission, $context);
            if ($errors) abort(422, 'Native OJS metadata validation rejected the proposal.');
            Repo::publication()->edit($publication, $params);
            $updated = Repo::publication()->get($publication->getId());
            return [
                'snapshot' => $snapshot($submission, $updated),
                'receipt' => ['protocol' => MetadataReconciliation::PROTOCOL, 'submissionId' => (int)$submission->getId(), 'publicationId' => (int)$publication->getId(), 'locale' => $data['locale'], 'fields' => array_keys($data['fields']), 'previousStateToken' => $current['stateToken'], 'published' => false],
                'submission' => $submission,
            ];
        });
        event(new MetadataChanged($result['submission']));
        unset($result['submission']);
        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    /** Read the current OJS editorial state using native, submission-scoped access. */
    public function workflowStatus(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate(['submissionId' => 'required|integer|min:1']);
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())) {
            return $this->error('editor_required', 'An authenticated editorial user is required.', 403);
        }
        $submission = Repo::submission()->get((int)$data['submissionId']);
        if (!$submission || (int)$submission->getData('contextId') !== (int)$context->getId()) {
            return $this->error('submission_not_found', 'Submission not found.', 404);
        }
        $stages = Repo::user()->getAccessibleWorkflowStages($user->getId(), $context->getId(), $submission);
        $allowed = false;
        foreach ($stages as $roles) {
            if (array_intersect($roles, [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT])) $allowed = true;
        }
        if (!$allowed) return $this->error('editor_required', 'Editorial access to this submission is required.', 403);
        $publication = Repo::publication()->get((int)$submission->getData('currentPublicationId'));
        return response()->json(WorkflowSnapshot::create($submission, $publication))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Provenance-verified publication artifact transfer.
     *
     * OJS remains authoritative: only an editor with production-stage access
     * may transfer, the current publication must still be unpublished, and
     * transferred galleys are left unapproved for native OJS review.
     */
    public function publicationArtifact(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate([
            'action' => 'required|in:inspect,transfer',
            'submissionId' => 'required|integer|min:1',
            'manuscriptId' => 'required|string|max:128',
            'publicationId' => 'required_if:action,transfer|integer|min:1',
            'locale' => 'required_if:action,transfer|string|max:32',
            'genreId' => 'required_if:action,transfer|integer|min:1',
            'format' => 'required_if:action,transfer|in:html,jats,pdf-print,pdf-interactive,epub',
            'mediaType' => 'required_if:action,transfer|string|max:128',
            'fileName' => 'required_if:action,transfer|string|max:255',
            'artifactBase64' => 'required_if:action,transfer|string|max:90000000',
            'build' => 'required_if:action,transfer|array',
            'expectedWorkflowToken' => 'sometimes|string|size:64',
            'expectedIssueId' => 'sometimes|integer|min:1',
            'expectedInstallationId' => 'required_with:expectedIssueId|string|max:255',
            'confirmed' => 'required_if:action,transfer|accepted',
        ]);

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())) {
            return $this->error(
                'editor_required',
                'An authenticated editor in an enabled context is required.',
                403
            );
        }

        if ($data['action'] === 'transfer' && ($data['format'] ?? '') === 'epub') {
            if (!isset($data['expectedWorkflowToken'], $data['expectedInstallationId']) ||
                !hash_equals($this->plugin->getInstallationId($context->getId(), $request), (string)$data['expectedInstallationId'])) {
                return $this->error('epub_target_required', 'EPUB requires the exact inspected installation and workflow token.', 422);
            }
        }
        $expectedIssueId = isset($data['expectedIssueId']) ? (int)$data['expectedIssueId'] : null;
        if ($expectedIssueId !== null && (
            !$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $context->getId()) ||
            !hash_equals($this->plugin->getInstallationId($context->getId(), $request), (string)($data['expectedInstallationId'] ?? ''))
        )) {
            return $this->error('issue_target_forbidden', 'A matching journal installation and journal-wide editorial access are required.', 403);
        }
        if ($expectedIssueId !== null && (!isset($data['publicationId']) ||
            ($data['action'] === 'transfer' && !isset($data['expectedWorkflowToken'])))) {
            return $this->error('issue_target_required', 'An exact publication ID and an inspected workflow token are required for issue transfer.', 422);
        }
        $submissionId = (int)$data['submissionId'];
        $authorize = function () use ($context, $user, $submissionId, $data, $expectedIssueId) {
            $submission = Repo::submission()->get($submissionId);
            if (!$submission || (int)$submission->getData('contextId') !== (int)$context->getId()) {
                abort(404, 'Submission not found.');
            }

            $stages = Repo::user()->getAccessibleWorkflowStages(
                $user->getId(),
                $context->getId(),
                $submission
            );
            if (!array_intersect($stages[WORKFLOW_STAGE_ID_PRODUCTION] ?? [], [
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_ASSISTANT,
            ])) {
                abort(403, 'Editorial access to this submission in production is required.');
            }

            $publication = Repo::publication()->get((int)$submission->getData('currentPublicationId'));
            if ($expectedIssueId !== null) {
                $issue = Repo::issue()->get($expectedIssueId, $context->getId());
                if (!$issue || (int)$issue->getJournalId() !== (int)$context->getId() || $issue->getPublished() ||
                    !$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $context->getId()) ||
                    !$publication || (int)$publication->getData('issueId') !== $expectedIssueId ||
                    (int)$submission->getData('status') !== Submission::STATUS_SCHEDULED) {
                    abort(409, 'The article no longer belongs to the inspected planned issue.');
                }
            }
            $expectedStatus = $expectedIssueId !== null ? Submission::STATUS_SCHEDULED : Submission::STATUS_QUEUED;
            if (
                (int)$submission->getData('stageId') !== WORKFLOW_STAGE_ID_PRODUCTION ||
                !$publication ||
                (int)$publication->getData('status') !== $expectedStatus ||
                (isset($data['publicationId']) &&
                    (int)$data['publicationId'] !== (int)$publication->getId())
            ) {
                abort(
                    409,
                    'Inspect the current unpublished production version before transferring a publication artifact.'
                );
            }
            if (isset($data['expectedWorkflowToken']) && !hash_equals(WorkflowSnapshot::create($submission, $publication)['stateToken'], (string)$data['expectedWorkflowToken'])) {
                abort(409, 'OJS metadata or workflow state changed. Inspect the destination again.');
            }
            if (($data['format'] ?? '') === 'epub' && !Repo::submission()->canEditPublication($submissionId, $user->getId())) {
                abort(403, 'Native publication edit permission required.');
            }
            return [$submission, $publication];
        };

        [$submission, $publication] = $authorize();

        $reader = PluginRegistry::getPlugin('generic', 'htmlarticlegalleyplugin');
        $htmlAvailable = $reader && $reader->getEnabled($context->getId());

        $genres = [];
        $enabled = DAORegistry::getDAO('GenreDAO')->getEnabledByContextId($context->getId());
        while ($genre = $enabled->next()) {
            if (!$genre->getDependent() && !$genre->getSupplementary()) {
                $genres[] = [
                    'id' => (int)$genre->getId(),
                    'label' => $genre->getLocalizedName(),
                ];
            }
        }
        $locales = array_values($context->getSupportedSubmissionLocales());

        if ($data['action'] === 'inspect') {
            return response()->json([
                'protocol' => PublicationArtifactDocument::PROTOCOL,
                'submissionId' => $submissionId,
                'publicationId' => (int)$publication->getId(),
                'title' => $publication->getLocalizedData('title'),
                'issueId' => $expectedIssueId,
                'installationId' => $this->plugin->getInstallationId($context->getId(), $request),
                'contextId' => (int)$context->getId(),
                'locales' => $locales,
                'genres' => $genres,
                'formats' => PublicationArtifactDocument::formats((bool)$htmlAvailable),
                'provenance' => [
                    'required' => true,
                    'model' => PublicationArtifactDocument::BUILD_MODEL,
                    'version' => PublicationArtifactDocument::BUILD_VERSION,
                    'digest' => 'sha256',
                ],
                'published' => false,
                'workflowToken' => WorkflowSnapshot::create($submission, $publication)['stateToken'],
            ]);
        }

        if (
            !in_array($data['locale'], $locales, true) ||
            !in_array((int)$data['genreId'], array_column($genres, 'id'), true)
        ) {
            return $this->error(
                'invalid_options',
                'Choose an enabled publication language and article file genre.',
                422
            );
        }
        if ($data['format'] === 'html' && !$htmlAvailable) {
            return $this->error(
                'html_reader_required',
                'Enable the OJS HTML Article Galley plugin before transferring HTML.',
                409
            );
        }

        try {
            $artifact = PublicationArtifactDocument::validateTransfer(
                (string)$data['format'],
                (string)$data['mediaType'],
                (string)$data['fileName'],
                (string)$data['artifactBase64'],
                (array)$data['build'],
                (string)$data['manuscriptId']
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('invalid_publication_artifact', $e->getMessage(), 422);
        }

        $storedName = 'omi-' .
            preg_replace('/[^a-z0-9]+/i', '-', (string)$data['format']) .
            '-' . $artifact['sha256'] . '.' . $artifact['extension'];
        $path = PublicationArtifactDocument::path(
            (string)$data['manuscriptId'],
            (string)$data['locale'],
            (string)$data['format']
        );

        $temporary = tmpfile();
        if (!$temporary) {
            return $this->error('storage_error', 'Unable to allocate temporary storage.', 500);
        }

        $fileId = null;
        $used = false;
        try {
            $bytes = $artifact['bytes'];
            if (fwrite($temporary, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Unable to write publication artifact.');
            }

            $dir = Repo::submissionFile()->getSubmissionDir($context->getId(), $submissionId);
            $fileId = app()->get('file')->add(
                stream_get_meta_data($temporary)['uri'],
                $dir . '/' . bin2hex(random_bytes(16)) . '.' . $artifact['extension']
            );

            $receipt = DB::transaction(function () use (
                $authorize,
                $submissionId,
                $data,
                $path,
                $storedName,
                $fileId,
                $context,
                $user,
                $artifact,
                &$used,
                $expectedIssueId
            ) {
                if ($expectedIssueId !== null) {
                    DB::table('issues')->where('issue_id', $expectedIssueId)->lockForUpdate()->first();
                }
                DB::table('submissions')
                    ->where('submission_id', $submissionId)
                    ->lockForUpdate()
                    ->first();
                DB::table('publications')
                    ->where('publication_id', (int)$data['publicationId'])
                    ->lockForUpdate()
                    ->first();

                [$submission, $publication] = $authorize();
                $galley = Repo::galley()->getByUrlPath($path, $publication);
                $existingFile = $galley && $galley->getData('submissionFileId')
                    ? Repo::submissionFile()->get(
                        (int)$galley->getData('submissionFileId'),
                        $submissionId
                    )
                    : null;

                if (
                    $existingFile &&
                    $existingFile->getData('name', $data['locale']) === $storedName
                ) {
                    return [
                        'galleyId' => (int)$galley->getId(),
                        'submissionFileId' => (int)$existingFile->getId(),
                        'unchanged' => true,
                    ];
                }

                if (!$galley) {
                    $galleyId = Repo::galley()->add(
                        Repo::galley()->newDataObject([
                            'publicationId' => (int)$publication->getId(),
                            'submissionFileId' => null,
                            'label' => $artifact['label'],
                            'locale' => $data['locale'],
                            'urlPath' => $path,
                            'isApproved' => false,
                        ])
                    );
                    $galley = Repo::galley()->get($galleyId);
                }

                $params = [
                    'fileId' => $fileId,
                    'submissionId' => $submissionId,
                    'uploaderUserId' => (int)$user->getId(),
                    'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
                    'genreId' => (int)$data['genreId'],
                    'assocType' => Application::ASSOC_TYPE_REPRESENTATION,
                    'assocId' => (int)$galley->getId(),
                    'name' => [
                        $data['locale'] => $storedName,
                        $submission->getData('locale') => $storedName,
                    ],
                ];

                $errors = Repo::submissionFile()->validate(
                    null,
                    $params,
                    $context->getSupportedSubmissionMetadataLocales(),
                    $submission->getData('locale')
                );
                if ($errors) {
                    abort(422, 'The publication proof file failed OJS validation.');
                }

                $submissionFileId = Repo::submissionFile()->add(
                    Repo::submissionFile()->newDataObject($params)
                );
                Repo::galley()->edit($galley, [
                    'submissionFileId' => $submissionFileId,
                    'label' => $artifact['label'],
                    'isApproved' => false,
                ]);
                $used = true;

                return [
                    'galleyId' => (int)$galley->getId(),
                    'submissionFileId' => $submissionFileId,
                    'unchanged' => false,
                ];
            });

            return response()->json(array_merge($receipt, [
                'protocol' => PublicationArtifactDocument::PROTOCOL,
                'submissionId' => $submissionId,
                'publicationId' => (int)$data['publicationId'],
                'issueId' => $expectedIssueId,
                'format' => $data['format'],
                'mediaType' => $artifact['mediaType'],
                'artifactFileName' => $artifact['fileName'],
                'sha256' => $artifact['sha256'],
                'buildId' => $artifact['buildId'],
                'provenanceVerified' => true,
                'published' => false,
            ]));
        } catch (\Throwable $e) {
            $used = false;
            throw $e;
        } finally {
            fclose($temporary);
            if ($fileId !== null && !$used) {
                app()->get('file')->delete($fileId);
            }
        }
    }

    /** Native OJS API-token authorization; the shared integration secret is not an editor credential. */
    public function htmlGalley(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate([
            'action' => 'required|in:inspect,transfer',
            'submissionId' => 'required|integer|min:1',
            'manuscriptId' => 'required|string|max:128',
            'publicationId' => 'required_if:action,transfer|integer|min:1',
            'locale' => 'required_if:action,transfer|string|max:32',
            'genreId' => 'required_if:action,transfer|integer|min:1',
            'html' => 'required_if:action,transfer|string|max:8388608',
            'confirmed' => 'required_if:action,transfer|accepted',
        ]);
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())) {
            return $this->error('editor_required', 'An authenticated editor in an enabled context is required.', 403);
        }
        $submissionId = (int)$data['submissionId'];
        $authorize = function () use ($context, $user, $submissionId, $data) {
            $submission = Repo::submission()->get($submissionId);
            if (!$submission || (int)$submission->getData('contextId') !== (int)$context->getId()) {
                abort(404, 'Submission not found.');
            }
            $stages = Repo::user()->getAccessibleWorkflowStages($user->getId(), $context->getId(), $submission);
            if (!array_intersect($stages[WORKFLOW_STAGE_ID_PRODUCTION] ?? [], [
                Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
            ])) abort(403, 'Editorial access to this submission in production is required.');
            $publication = Repo::publication()->get((int)$submission->getData('currentPublicationId'));
            if ((int)$submission->getData('stageId') !== WORKFLOW_STAGE_ID_PRODUCTION || !$publication
                || (int)$publication->getData('status') !== Submission::STATUS_QUEUED
                || (isset($data['publicationId']) && (int)$data['publicationId'] !== (int)$publication->getId())) {
                abort(409, 'Inspect the current unpublished production version before transferring HTML.');
            }
            return [$submission, $publication];
        };
        [$submission, $publication] = $authorize();
        $reader = PluginRegistry::getPlugin('generic', 'htmlarticlegalleyplugin');
        if (!$reader || !$reader->getEnabled($context->getId())) {
            return $this->error('html_reader_required', 'Enable the OJS HTML Article Galley plugin first.', 409);
        }
        $genres = [];
        $enabled = DAORegistry::getDAO('GenreDAO')->getEnabledByContextId($context->getId());
        while ($genre = $enabled->next()) {
            if (!$genre->getDependent() && !$genre->getSupplementary()) {
                $genres[] = ['id' => (int)$genre->getId(), 'label' => $genre->getLocalizedName()];
            }
        }
        $locales = array_values($context->getSupportedSubmissionLocales());
        if ($data['action'] === 'inspect') {
            return response()->json([
                'protocol' => 'omi-html-galley/1', 'submissionId' => $submissionId,
                'publicationId' => (int)$publication->getId(), 'title' => $publication->getLocalizedData('title'),
                'locales' => $locales, 'genres' => $genres,
            ]);
        }
        if (!in_array($data['locale'], $locales, true) || !in_array((int)$data['genreId'], array_column($genres, 'id'), true)) {
            return $this->error('invalid_options', 'Choose an enabled publication language and article file genre.', 422);
        }
        try { HtmlGalleyDocument::validate($data['html']); }
        catch (\InvalidArgumentException $e) { return $this->error('invalid_html', $e->getMessage(), 422); }
        $digest = hash('sha256', $data['html']);
        $fileName = 'omi-html-' . $digest . '.html';
        $path = HtmlGalleyDocument::path($data['manuscriptId'], $data['locale']);
        $temporary = tmpfile();
        if (!$temporary) return $this->error('storage_error', 'Unable to allocate temporary storage.', 500);
        $fileId = null;
        $used = false;
        try {
            // Defense in depth, placed before the document's own metadata and stylesheet.
            $html = preg_replace('/<head(?:\s[^>]*)?>/i', '<head><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; img-src data:; style-src &#39;unsafe-inline&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;">', $data['html'], 1);
            if (fwrite($temporary, $html) !== strlen($html)) throw new \RuntimeException('Unable to write HTML.');
            $dir = Repo::submissionFile()->getSubmissionDir($context->getId(), $submissionId);
            // Keep the physical file record outside the transaction so rollback cleanup can find it.
            $fileId = app()->get('file')->add(stream_get_meta_data($temporary)['uri'], $dir . '/' . bin2hex(random_bytes(16)) . '.html');
            $receipt = DB::transaction(function () use ($authorize, $submissionId, $data, $path, $fileName, $fileId, $context, $user, &$used) {
                DB::table('submissions')->where('submission_id', $submissionId)->lockForUpdate()->first();
                DB::table('publications')->where('publication_id', (int)$data['publicationId'])->lockForUpdate()->first();
                [$submission, $publication] = $authorize();
                $galley = Repo::galley()->getByUrlPath($path, $publication);
                $existingFile = $galley && $galley->getData('submissionFileId')
                    ? Repo::submissionFile()->get((int)$galley->getData('submissionFileId'), $submissionId) : null;
                if ($existingFile && $existingFile->getData('name', $data['locale']) === $fileName) {
                    return ['galleyId' => (int)$galley->getId(), 'submissionFileId' => (int)$existingFile->getId(), 'unchanged' => true];
                }
                if (!$galley) {
                    $galleyId = Repo::galley()->add(Repo::galley()->newDataObject([
                        'publicationId' => (int)$publication->getId(), 'submissionFileId' => null,
                        'label' => 'HTML', 'locale' => $data['locale'], 'urlPath' => $path, 'isApproved' => false,
                    ]));
                    $galley = Repo::galley()->get($galleyId);
                }
                $params = [
                    'fileId' => $fileId, 'submissionId' => $submissionId, 'uploaderUserId' => (int)$user->getId(),
                    'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF, 'genreId' => (int)$data['genreId'],
                    'assocType' => Application::ASSOC_TYPE_REPRESENTATION, 'assocId' => (int)$galley->getId(),
                    'name' => [$data['locale'] => $fileName, $submission->getData('locale') => $fileName],
                ];
                $errors = Repo::submissionFile()->validate(null, $params, $context->getSupportedSubmissionMetadataLocales(), $submission->getData('locale'));
                if ($errors) abort(422, 'The HTML proof file failed OJS validation.');
                $submissionFileId = Repo::submissionFile()->add(Repo::submissionFile()->newDataObject($params));
                Repo::galley()->edit($galley, ['submissionFileId' => $submissionFileId, 'isApproved' => false]);
                $used = true;
                return ['galleyId' => (int)$galley->getId(), 'submissionFileId' => $submissionFileId, 'unchanged' => false];
            });
            return response()->json(array_merge($receipt, [
                'protocol' => 'omi-html-galley/1', 'submissionId' => $submissionId,
                'publicationId' => (int)$data['publicationId'], 'sha256' => $digest, 'published' => false,
            ]));
        } catch (\Throwable $e) {
            $used = false;
            throw $e;
        } finally {
            fclose($temporary);
            if ($fileId !== null && !$used) app()->get('file')->delete($fileId);
        }
    }

    /** Public submission requirements; creation remains protected by native PKP author authorization. */
    /**
     * Read-only table of contents for a future issue, using native OJS
     * section ordering and current, scheduled publications.
     */
    public function issueImportManifest(IlluminateRequest $input): JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user) {
            return $this->error('authentication_required', 'A journal and authenticated editor are required.', 401);
        }
        if (!$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $context->getId())) {
            return $this->error('issue_import_forbidden', 'Journal-wide editorial access is required.', 403);
        }
        return $this->buildIssueImportManifest($context, (int)$input->route('issueId'));
    }

    public function signedIssueManifest(IlluminateRequest $input): JsonResponse
    {
        $authorized = $this->authorizeIssueImport($input);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$context, $issueId] = $authorized;
        return $this->buildIssueImportManifest($context, $issueId);
    }

    private function buildIssueImportManifest(object $context, int $issueId): JsonResponse
    {
        $issue = Repo::issue()->get($issueId, $context->getId());
        if (!$issue || (int)$issue->getJournalId() !== (int)$context->getId() || $issue->getPublished()) {
            return $this->error('planned_issue_not_found', 'An unpublished issue in this journal is required.', 404);
        }

        $sections = [];
        foreach (Repo::section()->getByIssueId($issueId) as $section) {
            $sections[(string)$section->getId()] = [
                'externalId' => (string)$section->getId(),
                'title' => (array)$section->getData('title'),
                'sequence' => count($sections) + 1,
                'articles' => [],
            ];
        }

        $adapter = new Ojs35Adapter();
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByIssueIds([$issueId])
            ->filterByStatus([Submission::STATUS_SCHEDULED])
            ->getMany();

        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            // The collector may match older publication versions.
            if (!$publication || (int)$publication->getData('issueId') !== $issueId) {
                continue;
            }
            $sectionId = (string)$publication->getData('sectionId');
            if (!isset($sections[$sectionId])) {
                return $this->error('issue_section_unresolved', 'A scheduled article has no resolvable issue section.', 409);
            }
            $sections[$sectionId]['articles'][] = [
                'submissionExternalId' => (string)$submission->getId(),
                'publicationExternalId' => (string)$publication->getId(),
                'sequence' => (float)$publication->getData('seq'),
                'submission' => $adapter->mapIssueArticleSubmission($submission),
            ];
        }
        foreach ($sections as &$section) {
            usort($section['articles'], static fn (array $a, array $b): int =>
                ($a['sequence'] <=> $b['sequence']) ?: ((int)$a['submissionExternalId'] <=> (int)$b['submissionExternalId']));
        }
        unset($section);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/ojs',
            'context' => array_merge($this->contextData($context), ['publicationMatter' => $this->issuePublicationMatter($context)]),
            'issue' => [
                'externalId' => (string)$issueId,
                'title' => (array)$issue->getData('title'),
                'description' => (array)$issue->getData('description'),
                'identification' => $issue->getIssueIdentification(),
                'volume' => $issue->getVolume(),
                'number' => $issue->getNumber(),
                'year' => $issue->getYear(),
                'published' => false,
                'doi' => $issue->getDoi(),
                'printIssn' => $context->getData('printIssn'),
                'onlineIssn' => $context->getData('onlineIssn'),
            ],
            'sections' => array_values($sections),
        ])->header('Cache-Control', 'private, no-store');
    }

    /** Verify issue scope, journal, installation, current role and unpublished status on every request. */
    private function authorizeIssueImport(IlluminateRequest $input): array|JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) return $this->error('context_required', 'A journal context is required.', 400);
        $authorization = trim((string)$input->header('Authorization', ''));
        if (!preg_match('/^OMI\\s+([A-Za-z0-9_-]+)\\.([A-Za-z0-9_-]+)$/', $authorization, $matches)) {
            return $this->error('authentication_required', 'A signed issue assertion is required.', 401);
        }
        $secret = (string)$this->plugin->getSetting($context->getId(), 'sharedSecret');
        $claims = $secret !== '' ? LaunchToken::verify($matches[1], $matches[2], $secret, $context->getId()) : null;
        if (!$claims ||
            ($claims['profile'] ?? '') !== 'omi-integration/1/ojs' ||
            ($claims['installationId'] ?? '') !== $this->plugin->getInstallationId($context->getId(), $request)) {
            return $this->error('invalid_assertion', 'Invalid or expired issue assertion.', 401);
        }
        if (($claims['actorMode'] ?? '') !== 'editor' ||
            ($claims['scope'] ?? []) !== ['issue.import.read'] ||
            isset($claims['submission']) || isset($claims['reviewAssignment'])) {
            return $this->error('insufficient_scope', 'An issue-only editorial read grant is required.', 403);
        }
        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        $actor = $actorId > 0 ? Repo::user()->get($actorId) : null;
        if (!$actor || !$actor->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $context->getId())) {
            return $this->error('issue_import_forbidden', 'Current journal-wide editorial access is required.', 403);
        }
        $issueId = (int)($claims['issue']['externalId'] ?? 0);
        $issue = $issueId > 0 ? Repo::issue()->get($issueId, $context->getId()) : null;
        if (!$issue || (int)$issue->getJournalId() !== (int)$context->getId() || $issue->getPublished()) {
            return $this->error('planned_issue_not_found', 'An unpublished issue in this journal is required.', 404);
        }
        return [$context, $issueId];
    }

    private function authorizeIssueArticle(IlluminateRequest $input): array|JsonResponse
    {
        $authorized = $this->authorizeIssueImport($input);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$context, $issueId] = $authorized;
        $submissionId = (int)$input->route('submissionId');
        $adapter = new Ojs35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        $publication = $submission?->getCurrentPublication();
        if (!$submission || !$publication ||
            (int)$submission->getData('status') !== Submission::STATUS_SCHEDULED ||
            (int)$publication->getData('issueId') !== $issueId) {
            return $this->error('issue_article_not_found', 'The current article is not scheduled in this issue.', 404);
        }
        return [$adapter, $submission];
    }

    /** Never disclose review attachments, internal queries, or other private workflow files. */
    private function issueSourceFiles(Ojs35Adapter $adapter, object $submission): array
    {
        $priority = [
            SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY => 0,
            SubmissionFile::SUBMISSION_FILE_COPYEDIT => 1,
            SubmissionFile::SUBMISSION_FILE_FINAL => 2,
            SubmissionFile::SUBMISSION_FILE_SUBMISSION => 3,
        ];
        $files = array_values(array_filter($adapter->mapFiles($submission), static function (array $file) use ($priority): bool {
            return isset($priority[(int)$file['stage']]) &&
                strtoupper(trim((string)$file['genreKey'])) === 'SUBMISSION' &&
                (strtolower((string)$file['mediaType']) === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ||
                    str_ends_with(strtolower((string)$file['name']), '.docx'));
        }));
        usort($files, static fn (array $a, array $b): int =>
            ($priority[(int)$a['stage']] <=> $priority[(int)$b['stage']]) ?:
            strcmp((string)$b['updatedAt'], (string)$a['updatedAt']) ?:
            ((int)$b['revision'] <=> (int)$a['revision']) ?:
            ((int)$b['externalId'] <=> (int)$a['externalId']));
        foreach ($files as &$file) {
            $file['contentPath'] = 'issue-import/articles/' . $submission->getId() . '/files/' . rawurlencode($file['externalId']) . '/content';
        }
        unset($file);
        return $files;
    }

    public function issueArticle(IlluminateRequest $input): JsonResponse
    {
        $authorized = $this->authorizeIssueArticle($input);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$adapter, $submission] = $authorized;
        return response()->json([
            'protocol' => 'omi-integration/1',
            'submission' => $adapter->mapIssueArticleSubmission($submission),
            'contributors' => $adapter->mapContributors($submission),
            'files' => $this->issueSourceFiles($adapter, $submission),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function issueArticleFile(IlluminateRequest $input): BinaryFileResponse|JsonResponse
    {
        $authorized = $this->authorizeIssueArticle($input);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$adapter, $submission] = $authorized;
        $fileId = (int)$input->route('submissionFileId');
        foreach ($this->issueSourceFiles($adapter, $submission) as $file) {
            if ((int)$file['externalId'] === $fileId) {
                return $this->sendSubmissionFile((int)$submission->getId(), $fileId);
            }
        }
        return $this->error('file_not_found', 'An eligible issue article source file was not found.', 404);
    }

    public function submissionOptions(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context || !$this->plugin->getEnabled($context->getId())) {
            return $this->error('context_required', 'An enabled publishing context is required.', 404);
        }
        $sections = [];
        foreach (Repo::section()->getCollector()->filterByContextIds([$context->getId()])->getMany() as $section) {
            if ($section->getIsInactive() || $section->getEditorRestricted()) continue;
            $sections[] = ['id' => (int)$section->getId(), 'label' => $section->getLocalizedTitle()];
        }
        $genres = [];
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $enabledGenres = $genreDao->getEnabledByContextId($context->getId());
        while ($genre = $enabledGenres->next()) {
            $genres[] = ['id' => (int)$genre->getId(), 'label' => $genre->getLocalizedName()];
        }
        return response()->json([
            'protocol' => 'omi-direct-submission/1',
            'platform' => 'ojs',
            'name' => $context->getLocalizedData('name'),
            'acceptingSubmissions' => !(bool)$context->getData('disableSubmissions'),
            'locales' => array_values($context->getSupportedSubmissionLocales()),
            'sections' => $sections,
            'genres' => $genres,
            'requirements' => $context->getLocalizedData('submissionChecklist'),
            'copyrightNotice' => $context->getLocalizedData('copyrightNotice'),
            'privacyStatement' => $context->getLocalizedData('privacyStatement'),
        ]);
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
                'version' => '1.12.0',
                'platform' => 'ojs',
            ],
            'context' => $this->contextData($context),
            'capabilities' => [
                'launch',
                'editor.workflow.read',
                'editor.metadata.reconcile',
                'metadata.read',
                'contributors.read',
                'reviewers.read',
                'files.read',
                'files.content.read',
                'author.manuscript.write',
                'author.revision.write',
                'review.metadata.read',
                'review.files.read',
                'review.files.write',
                'review.manuscript.read',
                'review.revision.write',
                'review.response.write',
                'review.form.read',
                'review.form.write',
                'review.forms.native',
                'review.files.scoped',
                'workflow.assignment.write',
                'workflow.assignment-result.write',
                'editor.html-galley.write',
                'editor.publication-artifact.write',
                'publication.html.write',
                'publication.jats.write',
                ...(class_exists(\ZipArchive::class) ? ['publication.epub.write'] : []),
                'publication.pdf.write',
                'publication.provenance.verify',
                'issue.pages.write',
                'review.recommendations',
                ...$this->reviewerRecommendationCapabilities($context),
            ],
            'publicationArtifacts' => [
                'protocol' => PublicationArtifactDocument::PROTOCOL,
                'provenanceModel' => PublicationArtifactDocument::BUILD_MODEL,
                'provenanceVersion' => PublicationArtifactDocument::BUILD_VERSION,
                'formats' => PublicationArtifactDocument::formats(
                    (bool)(
                        ($htmlReader = PluginRegistry::getPlugin('generic', 'htmlarticlegalleyplugin')) &&
                        $htmlReader->getEnabled($context->getId())
                    )
                ),
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
            'submission' => array_merge($adapter->mapSubmission($submission), ['publicationLanguages' => $this->workflowPublicationLanguages($context, $submission)]),
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
        if (!$this->hasScope($claims, 'review.identity.read')) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant access to reviewer identities.', 403, ['required' => 'review.identity.read']);
        }

        $userGroupIds = array_merge(Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_REVIEWER, $context->getId()), Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_ASSISTANT, $context->getId()));
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

    public function reviewRecommendations(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (($claims['actorMode'] ?? '') !== 'review' || !$this->hasScope($claims, 'review.form.read')) {
            return $this->error('insufficient_scope', 'Reviewer recommendation access requires review.form.read.', 403, ['required' => 'review.form.read']);
        }

        $assignment = $this->reviewAssignmentForClaims($claims, $submissionId);
        if (!$assignment) return $this->error('review_assignment_forbidden', 'The review assignment is not valid for this reviewer and submission.', 403);

        $catalog = $this->reviewerRecommendationCatalog($context, $assignment);
        return response()->json([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$assignment->getId(),
            'recommendationStorage' => $catalog['storage'],
            'options' => $catalog['options'],
            'selectedExternalId' => $catalog['selectedExternalId'],
        ]);
    }

    public function reviewForm(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId] = $authorized;
        if (($claims['actorMode'] ?? '') !== 'review' || !$this->hasScope($claims, 'review.form.read')) {
            return $this->error('insufficient_scope', 'Reviewer form access requires review.form.read.', 403, ['required' => 'review.form.read']);
        }
        $assignment = $this->reviewAssignmentForClaims($claims, $submissionId);
        if (!$assignment) return $this->error('review_assignment_forbidden', 'The review assignment is not valid for this reviewer and submission.', 403);

        return response()->json(['protocol' => 'omi-integration/1', 'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$assignment->getId(), 'reviewForm' => $this->workflowReviewForm($assignment)]);
    }

    private function workflowReviewForm(ReviewAssignment $assignment): ?array
    {
        $formId = (int)$assignment->getData('reviewFormId');
        if ($formId < 1) return null;
        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $responseValues = $responseDao->getReviewReviewFormResponseValues($assignment->getId());
        $elements = [];
        $result = $elementDao->getByReviewFormId($formId);
        while ($element = $result->next()) {
            $possible = $element->getLocalizedPossibleResponses();
            $options = $this->reviewFormOptions($possible);
            $localizations = $this->reviewFormLocalizations($element);
            $elementId = (int)$element->getId();
            $elements[] = [
                'externalId' => (string)$elementId,
                'type' => $this->reviewFormElementType((int)$element->getElementType()),
                'question' => $this->reviewFormPlainText($element->getLocalizedQuestion()),
                'description' => $this->reviewFormPlainText($element->getLocalizedDescription()),
                'required' => (bool)$element->getRequired(),
                'authorVisible' => (bool)$element->getIncluded(),
                'options' => $options,
                'localizations' => $localizations,
                'value' => array_key_exists($elementId, $responseValues) ? $responseValues[$elementId] : null,
            ];
        }

        return ['externalId' => (string)$formId, 'elements' => $elements];
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

        return $this->sendSubmissionFile($submissionId, $submissionFileId);
    }

    private function sendSubmissionFile(int $submissionId, int $submissionFileId): BinaryFileResponse|JsonResponse
    {
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

    /** Bind Studio tasks to native OJS review/stage permissions before inviting a participant. */
    public function workflowAssignment(IlluminateRequest $input): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A journal context is required.', 400);
        if ($error = $this->authorizeServiceRequest($input, $context->getId())) return $error;
        $submission = Repo::submission()->get((int)$input->input('submissionExternalId'), $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found.', 404);
        $editorId = (int)$input->input('editorExternalId');
        if (!$this->workflowEditorAllowed($context, $submission, $editorId)) return $this->error('editor_required', 'Current editorial access to this submission is required.', 403);
        $id = (string)$input->input('assignmentId');
        $type = (string)$input->input('assignmentType');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id) || !in_array($type, ['SCIENTIFIC_REVIEW', 'LANGUAGE_REVIEW', 'TRANSLATION'], true)) {
            return $this->error('invalid_assignment', 'A valid task identifier and role are required.', 422);
        }
        if ($type === 'TRANSLATION') {
            try {
                $sourceLanguage = $this->translationLanguage((string)$input->input('sourceLanguage'));
                $targetLanguage = $this->translationLanguage((string)$input->input('targetLanguage'));
            } catch (\InvalidArgumentException) { return $this->error('invalid_languages', 'Valid translation languages are required.', 422); }
            if ($sourceLanguage === $targetLanguage) return $this->error('invalid_languages', 'Translation requires distinct source and target languages.', 422);
            $supportedTargets = array_map($this->translationLanguage(...), $context->getSupportedSubmissionMetadataLocales());
            if (!in_array($targetLanguage, $supportedTargets, true)) return $this->error('unsupported_target_language', 'Enable the translation target metadata language in OJS first.', 422);
        }
        $participant = Repo::user()->getByEmail(strtolower(trim((string)$input->input('reviewerEmail'))));
        if (!$participant) return $this->error('participant_not_found', 'An active OJS participant account is required.', 422);
        $groupIds = Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_REVIEWER, $context->getId());
        if ($type !== 'SCIENTIFIC_REVIEW') $groupIds = array_merge($groupIds, Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_ASSISTANT, $context->getId()));
        if (!$groupIds) return $this->error('participant_not_available', 'No eligible participant group is available.', 403);
        $member = Repo::user()->getCollector()->filterByContextIds([$context->getId()])->filterByUserGroupIds($groupIds)->getMany()->first(fn ($user) => (int)$user->getId() === (int)$participant->getId());
        if (!$member) return $this->error('participant_not_available', 'Select an eligible participant from this journal.', 403);
        return DB::transaction(function () use ($input, $context, $submission, $editorId, $participant, $id, $type) {
            DB::table('submissions')->where('submission_id', $submission->getId())->lockForUpdate()->first();
            $submission = Repo::submission()->get((int)$submission->getId(), $context->getId());
            if (!$this->workflowEditorAllowed($context, $submission, $editorId)) return $this->error('editor_required', 'Editorial permissions changed.', 403);
            $stage = (int)$submission->getData('stageId');
            $round = null;
            if ($stage === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) $round = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submission->getId(), WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
            if ($input->input('expectedReviewRound') !== null && (int)$input->input('expectedReviewRound') !== ($round ? (int)$round->getRound() : 1)) return $this->error('review_round_changed', 'The current OJS round differs from this task. Create a new task.', 409);
            $publicationLanguages = $this->workflowPublicationLanguages($context, $submission);
            if ($type === 'TRANSLATION' && $input->input('publicationLanguages') !== null) {
                $requested = $input->input('publicationLanguages');
                if (!is_array($requested) || count($requested) > 32) return $this->error('invalid_publication_languages', 'Specify full-text publication languages.', 422);
                foreach ($requested as $language) $publicationLanguages[] = $this->translationLanguage((string)$language);
                $publicationLanguages = array_values(array_unique($publicationLanguages));
                $publication = $submission->getCurrentPublication();
                $this->plugin->updateSetting($context->getId(), 'translationPublicationLanguages_' . ($publication ? $publication->getId() : $submission->getId()), $publicationLanguages, 'object');
            }
            $externalId = 'workflow-' . $id;
            if ($type === 'SCIENTIFIC_REVIEW') {
                if (!$round) return $this->error('review_round_required', 'Scientific peer review requires an active external review round.', 409);
                $authorRoles = Repo::user()->getAccessibleWorkflowStages($participant->getId(), $context->getId(), $submission);
                foreach ($authorRoles as $roles) if (in_array(Role::ROLE_ID_AUTHOR, $roles, true)) return $this->error('reviewer_is_author', 'An author cannot review their own submission.', 403);
                $assignment = Repo::reviewAssignment()->getCollector()->filterByReviewRoundIds([$round->getId()])->filterByReviewerIds([$participant->getId()])->getMany()->first();
                if ($assignment) {
                    if ((int)$assignment->getReviewMethod() !== ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS || !$this->reviewAssignmentAllowsFileWrite($assignment)) {
                        return $this->error('review_assignment_conflict', 'The existing review assignment is not writable double-anonymous peer review.', 409);
                    }
                    $externalId = (string)$assignment->getId();
                } else {
                    $params = ['submissionId' => (int)$submission->getId(), 'reviewerId' => (int)$participant->getId(),
                        'reviewRoundId' => (int)$round->getId(), 'round' => (int)$round->getRound(), 'stageId' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
                        'reviewMethod' => ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS, 'dateAssigned' => Core::getCurrentDate()];
                    $reviewWeeks = (int)$context->getData('numWeeksPerReview');
                    $responseWeeks = (int)$context->getData('numWeeksPerResponse');
                    $params['dateDue'] = date('Y-m-d 23:59:59', strtotime('+' . ($reviewWeeks > 0 ? $reviewWeeks : 4) . ' weeks'));
                    $params['dateResponseDue'] = date('Y-m-d 23:59:59', strtotime('+' . ($responseWeeks > 0 ? $responseWeeks : 3) . ' weeks'));
                    $publication = $submission->getCurrentPublication();
                    $sectionId = $publication ? (int)$publication->getData(Application::getSectionIdPropName()) : 0;
                    $section = $sectionId ? Repo::section()->get($sectionId, $context->getId()) : null;
                    if ($section && $section->getReviewFormId()) $params['reviewFormId'] = (int)$section->getReviewFormId();
                    if (Repo::reviewAssignment()->validate(null, $params, $context)) return $this->error('assignment_validation_failed', 'OJS rejected the review assignment.', 422);
                    $externalId = (string)Repo::reviewAssignment()->add(Repo::reviewAssignment()->newDataObject($params));
                }
            }
            $binding = ['contextId' => (int)$context->getId(), 'submissionId' => (int)$submission->getId(),
                'editorId' => $editorId, 'participantId' => (int)$participant->getId(), 'assignmentId' => $externalId,
                'assignmentType' => $type, 'stageId' => $stage,
                'publicationId' => $submission->getCurrentPublication()?->getId(),
                'publicationLanguages' => $publicationLanguages,
                ...($type === 'TRANSLATION' ? ['sourceLanguage' => $this->translationLanguage((string)$input->input('sourceLanguage')), 'targetLanguage' => $this->translationLanguage((string)$input->input('targetLanguage'))] : []), 'reviewRoundId' => $round ? (int)$round->getId() : null,
                'reviewRound' => $round ? (int)$round->getRound() : 1];
            $payload = rtrim(strtr(base64_encode(json_encode($binding, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $signature = hash_hmac('sha256', 'workflow-assignment/1.' . $payload, (string)$this->plugin->getSetting($context->getId(), 'sharedSecret'));
            return response()->json(['externalAssignmentId' => $externalId, 'reviewRound' => $binding['reviewRound'],
                'publicationLanguages' => $publicationLanguages, 'anonymityMode' => $type === 'SCIENTIFIC_REVIEW' ? 'double_blind' : 'open', 'assignmentToken' => $payload . '.' . $signature,
                ...($type === 'SCIENTIFIC_REVIEW' ? ['recommendations' => $this->reviewerRecommendationCatalog($context, Repo::reviewAssignment()->get((int)$externalId, $submission->getId())), 'reviewForm' => $this->workflowReviewForm(Repo::reviewAssignment()->get((int)$externalId, $submission->getId()))] : [])]);
        });
    }

    private function translationLanguage(string $value): string
    {
        if (!preg_match('/^([a-z]{2,8})(?:[-_]([a-z]{4}))?(?:[-_](?:[a-z]{2}|[0-9]{3}))?$/i', trim($value), $parts)) throw new \InvalidArgumentException('Invalid translation language.');
        return strtolower($parts[1]) . (!empty($parts[2]) ? '-' . ucfirst(strtolower($parts[2])) : '');
    }

    /** Actual/planned full-text languages, never the metadata-locale list. */
    private function workflowPublicationLanguages(object $context, object $submission): array
    {
        $publication = $submission->getCurrentPublication();
        $id = $publication ? (int)$publication->getId() : (int)$submission->getId();
        $languages = [$this->translationLanguage((string)$submission->getData('locale'))];
        foreach ((array)$this->plugin->getSetting($context->getId(), 'translationPublicationLanguages_' . $id) as $language) $languages[] = $this->translationLanguage((string)$language);
        if ($publication) foreach (Repo::galley()->getCollector()->filterByPublicationIds([$publication->getId()])->getMany() as $galley) {
            $locale = $galley->getData('locale');
            if (is_string($locale) && $locale !== '') $languages[] = $this->translationLanguage($locale);
        }
        return array_values(array_unique($languages));
    }

    private function writeWorkflowTranslationMetadata(object $context, object $submission, array $binding, array $translation): array
    {
        $publication = $submission->getCurrentPublication();
        if (!$publication || (int)$publication->getId() !== (int)($binding['publicationId'] ?? 0)) abort(409, 'The OJS publication version changed. Reconnect the translation task.');
        if ((int)$publication->getData('status') !== Submission::STATUS_QUEUED || !Repo::submission()->canEditPublication($submission->getId(), (int)$binding['editorId'])) abort(403, 'Translation metadata can be returned only to an editable unpublished OJS version.');
        $target = $this->translationLanguage((string)$binding['targetLanguage']);
        $locales = $context->getSupportedSubmissionMetadataLocales();
        $locale = null;
        foreach ($locales as $candidate) if ($this->translationLanguage((string)$candidate) === $target) { $locale = (string)$candidate; break; }
        if ($locale === null) abort(422, 'Enable the target metadata language in OJS before returning this translation.');
        $adapter = new Ojs35Adapter();
        $params = MetadataReconciliation::patch($publication, $locale,
            ['title' => $translation['title'], 'abstract' => $translation['abstract'], 'keywords' => $translation['keywords']],
            $locales, $adapter->reconciliationKeywords($publication, (string)$submission->getData('locale')));
        $params['id'] = (int)$publication->getId(); $params['submissionId'] = (int)$submission->getId();
        if (Repo::publication()->validate($publication, $params, $submission, $context)) abort(422, 'OJS rejected the translated metadata.');
        Repo::publication()->edit($publication, $params);
        return ['locale' => $locale, 'targetLanguage' => $target, 'publicationExternalId' => (string)$publication->getId(), 'fields' => ['title', 'abstract', 'keywords']];
    }

    private function workflowEditorAllowed(object $context, object $submission, int $editorId): bool
    {
        if ($editorId < 1 || !Repo::user()->get($editorId)) return false;
        $stages = Repo::user()->getAccessibleWorkflowStages($editorId, $context->getId(), $submission);
        return (bool)array_intersect($stages[(int)$submission->getData('stageId')] ?? [], [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT]);
    }

    /** Atomic, idempotent return of the shareable Word revision and exact structured snapshot. */
    public function assignmentResult(IlluminateRequest $input): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A journal context is required.', 400);
        if ($error = $this->authorizeServiceRequest($input, $context->getId())) return $error;
        $submissionId = (int)$input->input('submissionExternalId');
        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found.', 404);
        $externalId = (string)$input->input('reviewAssignmentExternalId');
        $type = (string)$input->input('assignmentType');
        $roundNumber = (int)$input->input('reviewRound');
        $sourceDigest = (string)$input->input('sourceDigest');
        if (!preg_match('/^[a-f0-9]{64}$/', $sourceDigest) || $roundNumber < 1) return $this->error('invalid_result', 'A content digest and review round are required.', 422);
        $binding = null;
        $token = $input->input('assignmentToken');
        if ($token !== null) {
            $parts = is_string($token) ? explode('.', $token) : [];
            if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', 'workflow-assignment/1.' . $parts[0], (string)$this->plugin->getSetting($context->getId(), 'sharedSecret')), $parts[1])) return $this->error('invalid_assignment_token', 'The task binding is invalid.', 403);
            $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
            $binding = $decoded === false ? null : json_decode($decoded, true);
            if (!is_array($binding) || ($binding['contextId'] ?? null) !== (int)$context->getId() || ($binding['submissionId'] ?? null) !== $submissionId || ($binding['assignmentId'] ?? null) !== $externalId || ($binding['assignmentType'] ?? null) !== $type || ($binding['reviewRound'] ?? null) !== $roundNumber) return $this->error('assignment_mismatch', 'The task belongs to another journal, submission, role or round.', 403);
            if (!$this->workflowEditorAllowed($context, $submission, (int)$binding['editorId']) || !Repo::user()->get((int)$binding['participantId'])) return $this->error('assignment_revoked', 'The editorial task is no longer authorized.', 403);
        }
        $review = null;
        if ($type === 'SCIENTIFIC_REVIEW') {
            $review = ctype_digit($externalId) ? Repo::reviewAssignment()->get((int)$externalId, $submissionId) : null;
            if (!$review || (int)$review->getRound() !== $roundNumber || (int)$review->getReviewMethod() !== ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS || ($binding && (int)$review->getReviewerId() !== (int)$binding['participantId'])) return $this->error('review_assignment_mismatch', 'A double-anonymous review assignment in the requested round is required.', 403);
            $nativeRound = DAORegistry::getDAO('ReviewRoundDAO')->getById((int)$review->getReviewRoundId());
            if (!$nativeRound || (int)$nativeRound->getSubmissionId() !== $submissionId || (int)$nativeRound->getStageId() !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) return $this->error('review_round_mismatch', 'The review round does not belong to this submission.', 409);
        } elseif (!$binding || !in_array($type, ['LANGUAGE_REVIEW', 'TRANSLATION'], true)) return $this->error('assignment_required', 'A bound language review or translation task is required.', 403);
        $files = $input->input('files');
        $translation = $type === 'TRANSLATION' ? $input->input('translationMetadata') : null;
        $metadataOnly = false;
        if ($type === 'TRANSLATION') {
            if (!is_array($translation) || !is_string($translation['targetLanguage'] ?? null) || $this->translationLanguage($translation['targetLanguage']) !== ($binding['targetLanguage'] ?? null) || !is_string($translation['sourceLanguage'] ?? null) || $this->translationLanguage($translation['sourceLanguage']) !== ($binding['sourceLanguage'] ?? null)) return $this->error('translation_pair_mismatch', 'The translated metadata belongs to another language pair.', 422);
            foreach (['title', 'abstract'] as $field) if (!is_string($translation[$field] ?? null) || trim($translation[$field]) === '') return $this->error('translation_metadata_required', 'Translated title, abstract and keywords are all required.', 422);
            if (!is_array($translation['keywords'] ?? null) || !array_is_list($translation['keywords']) || !$translation['keywords'] || count($translation['keywords']) > 100) return $this->error('translation_metadata_required', 'Translated keywords are required.', 422);
            foreach ($translation['keywords'] as $term) if (!is_string($term) || trim($term) === '' || mb_strlen($term) > 200) return $this->error('translation_metadata_required', 'Translated keywords must be nonempty terms.', 422);
            $metadataOnly = !in_array($binding['targetLanguage'], $this->workflowPublicationLanguages($context, $submission), true);
            if (!$metadataOnly && $input->input('documentTranslationCompleted') !== true) return $this->error('document_translation_required', 'Complete the full manuscript translation for this publication language.', 422);
        }
        if (!is_array($files) || count($files) !== ($metadataOnly ? 0 : 2)) return $this->error('documents_required', 'Return the Word document and structured snapshot together.', 422);
        $documents = [];
        $total = 0;
        foreach ($files as $file) {
            if (!is_array($file)) return $this->error('invalid_document', 'Invalid returned document.', 422);
            try { $document = WorkflowFileDocument::decode((string)($file['fileName'] ?? ''), (string)($file['mediaType'] ?? ''), (string)($file['contentBase64'] ?? '')); }
            catch (\InvalidArgumentException $e) { return $this->error('invalid_document', $e->getMessage(), 422); }
            $total += $document['byteLength'];
            $documents[] = $document;
        }
        if (!$metadataOnly && ($total > WorkflowFileDocument::MAX_BYTES || !str_ends_with($documents[0]['fileName'], '.docx') || !str_ends_with($documents[1]['fileName'], '.omi-review.json'))) return $this->error('invalid_documents', 'The document pair exceeds the limit or has incorrect formats.', 422);
        if (!$metadataOnly) {
        if ($type === 'TRANSLATION') foreach ($documents as $document) if (!str_starts_with($document['fileName'], 'translation-' . $binding['sourceLanguage'] . '-' . $binding['targetLanguage'] . '-assignment-')) return $this->error('translation_file_language', 'Translation filenames must identify the bound language pair.', 422);
        $structured = json_decode($documents[1]['bytes'], true);
        if (!is_array($structured) || ($structured['protocol'] ?? '') !== 'omi-review-revision/1' || ($structured['assignmentType'] ?? '') !== $type || ($structured['anonymityMode'] ?? '') !== ($review ? 'double_blind' : 'open')) return $this->error('invalid_snapshot', 'The structured document does not match this task.', 422);
        $manuscript = $structured['manuscript'] ?? [];
        if ($type === 'TRANSLATION' && (($manuscript['translation'] ?? null) !== $translation || empty($manuscript['blocks']))) return $this->error('translation_snapshot_mismatch', 'The translated manuscript must match the translated metadata.', 422);
        if ($review && (($manuscript['authorIdentity'] ?? '') !== 'hidden' || !empty($manuscript['authors']))) return $this->error('identity_disclosure', 'Scientific review documents must not contain author metadata.', 422);
        foreach ($manuscript['annotations'] ?? [] as $annotation) {
            if (($annotation['visibility'] ?? '') !== 'author_and_editor' || ($review && !empty($annotation['authorName']))) return $this->error('private_annotation', 'Shareable files must not contain private annotations or reviewer identity.', 422);
        }
        }
        $createdFiles = [];
        return DB::transaction(function () use (&$createdFiles, $input, $context, $submission, $submissionId, $externalId, $type, $binding, $review, $roundNumber, $sourceDigest, $documents, $translation, $metadataOnly) {
            try {
                DB::table('submissions')->where('submission_id', $submissionId)->lockForUpdate()->first();
                $receiptKey = 'workflowReceipt_' . hash('sha256', $externalId . ':' . $roundNumber . ($review ? '' : ':' . $sourceDigest));
                $previous = $this->plugin->getSetting($context->getId(), $receiptKey);
                if (is_array($previous)) {
                    if (($previous['sourceDigest'] ?? '') !== $sourceDigest || ($previous['submissionExternalId'] ?? '') !== (string)$submissionId) return $this->error('result_conflict', 'This task was already returned with different content.', 409);
                    return response()->json(array_merge($previous, ['unchanged' => true]));
                }
                $current = Repo::submission()->get($submissionId, $context->getId());
                if ($binding && !$this->workflowEditorAllowed($context, $current, (int)$binding['editorId'])) return $this->error('assignment_revoked', 'Editorial permissions changed.', 403);
                if ($binding && (int)$current->getData('stageId') !== (int)$binding['stageId']) return $this->error('workflow_changed', 'The OJS workflow stage changed. Reassign the task before returning it.', 409);
                if ($binding && (int)$binding['stageId'] === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
                    $latest = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
                    if (!$latest || (int)$latest->getId() !== (int)$binding['reviewRoundId']) return $this->error('review_round_changed', 'The current review round changed. Reassign the task.', 409);
                }
                $metadataReceipt = null;
                if ($type === 'TRANSLATION') {
                    $nowRequired = in_array($binding['targetLanguage'], $this->workflowPublicationLanguages($context, $current), true);
                    if ($nowRequired === $metadataOnly) return $this->error('translation_scope_changed', 'The publication languages changed. Reconnect the task.', 409);
                }
                $genreId = null;
                if ($review) {
                    $currentReview = Repo::reviewAssignment()->get((int)$externalId, $submissionId);
                    if (!$this->reviewAssignmentAllowsFileWrite($currentReview)) return $this->error('review_closed', 'The review assignment is no longer writable.', 409);
                    $fileStage = SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT;
                    $assocType = Application::ASSOC_TYPE_REVIEW_ASSIGNMENT;
                    $assocId = (int)$externalId;
                    $uploaderId = (int)$review->getReviewerId();
                } else {
                    $stage = (int)$binding['stageId'];
                    $fileStage = match ($stage) {
                        WORKFLOW_STAGE_ID_SUBMISSION => SubmissionFile::SUBMISSION_FILE_SUBMISSION,
                        WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
                        WORKFLOW_STAGE_ID_EDITING => SubmissionFile::SUBMISSION_FILE_COPYEDIT,
                        WORKFLOW_STAGE_ID_PRODUCTION => SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
                        default => 0,
                    };
                    if (!$fileStage) return $this->error('unsupported_stage', 'This workflow stage does not accept returned documents.', 409);
                    $assocType = $stage === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW ? Application::ASSOC_TYPE_REVIEW_ROUND : Application::ASSOC_TYPE_SUBMISSION;
                    $assocId = $stage === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW ? (int)$binding['reviewRoundId'] : $submissionId;
                    $uploaderId = (int)$binding['participantId'];
                    $genres = $metadataOnly ? null : DAORegistry::getDAO('GenreDAO')->getEnabledByContextId($context->getId());
                    while ($genres && ($genre = $genres->next())) if (!$genre->getDependent()) {
                        if ($genreId === null) $genreId = (int)$genre->getId();
                        if ($genre->getKey() === 'SUBMISSION') { $genreId = (int)$genre->getId(); break; }
                    }
                    if (!$metadataOnly && !$genreId) return $this->error('genre_required', 'No enabled manuscript genre is available.', 422);
                }
                if ($type === 'TRANSLATION') $metadataReceipt = $this->writeWorkflowTranslationMetadata($context, $current, $binding, $translation);
                $receipts = [];
                foreach ($documents as $document) {
                    $stored = $this->persistWorkflowFile($context, $submission, $uploaderId, $fileStage, $assocType, $assocId, $genreId, $document, []);
                    if ($stored->getStatusCode() >= 400) abort($stored->getStatusCode(), 'OJS rejected a returned document.');
                    $fileReceipt = $stored->getData(true);
                    $storedFile = Repo::submissionFile()->get((int)$fileReceipt['submissionFileExternalId']);
                    $createdFiles[] = $storedFile->getData('fileId');
                    $receipts[] = $fileReceipt;
                }
                if ($review) {
                    $result = $this->reviewResult($input);
                    if ($result->getStatusCode() >= 400) abort($result->getStatusCode(), 'OJS rejected the review result.');
                    Repo::reviewAssignment()->edit(Repo::reviewAssignment()->get((int)$externalId, $submissionId), ['dateCompleted' => Core::getCurrentDate()]);
                } else {
                    $this->saveWorkflowComment($context, $submission, $uploaderId, (int)$binding['editorId'], $externalId, (string)$input->input('authorAndEditorComment', ''), true);
                    $this->saveWorkflowComment($context, $submission, $uploaderId, (int)$binding['editorId'], $externalId, (string)$input->input('editorOnlyComment', ''), false);
                }
                $receipt = ['protocol' => 'omi-assignment-result/1', 'written' => true, 'sourceDigest' => $sourceDigest,
                    'submissionExternalId' => (string)$submissionId, 'reviewAssignmentExternalId' => $externalId,
                    'assignmentType' => $type, 'anonymityMode' => $review ? 'double_blind' : 'open', 'files' => $receipts, 'translationMetadata' => $metadataReceipt];
                $this->plugin->updateSetting($context->getId(), $receiptKey, $receipt, 'object');
                return response()->json($receipt);
            } catch (\Throwable $error) {
                // Delete bytes while their native file rows still exist in this transaction.
                foreach ($createdFiles as $fileId) app()->get('file')->delete($fileId);
                throw $error;
            }
        });
    }

    private function saveWorkflowComment(object $context, object $submission, int $userId, int $editorId, string $assignmentId, string $body, bool $viewable): void
    {
        if (trim($body) === '') return;
        $participants = [$editorId, $userId];
        if ($viewable) {
            $authors = StageAssignment::withSubmissionIds([$submission->getId()])->withRoleIds([Role::ROLE_ID_AUTHOR])
                ->withStageIds([(int)$submission->getData('stageId')])->get()->pluck('user_id')->all();
            $participants = array_merge($participants, $authors);
        }
        // Native OJS 3.5 discussions are visible in the actual workflow UI.
        // Separate participant sets enforce confidentiality; no mail is sent here.
        Repo::query()->addQuery((int)$submission->getId(), (int)$submission->getData('stageId'),
            'Open Manuscript task ' . $assignmentId . ($viewable ? '' : ' — editor only'),
            nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            Repo::user()->get($userId), array_values(array_unique($participants)), (int)$context->getId(), false);
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
        if (!($reviewAssignment instanceof ReviewAssignment) || $reviewAssignment->getCancelled() || $reviewAssignment->getDeclined() || $reviewAssignment->getDateCompleted()) {
            return $this->error('review_assignment_not_found', 'Review assignment not found or no longer writable.', 404);
        }
        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found in this journal.', 404);

        $authorComment = trim((string)$illuminateRequest->input('authorAndEditorComment', ''));
        $editorComment = trim((string)$illuminateRequest->input('editorOnlyComment', ''));
        $recommendation = trim((string)$illuminateRequest->input('recommendation', ''));
        $rawExternalRecommendationId = $illuminateRequest->input('reviewerRecommendationExternalId');
        if ($rawExternalRecommendationId !== null && !is_scalar($rawExternalRecommendationId)) {
            return $this->error('invalid_reviewer_recommendation', 'The reviewer recommendation identifier must be a scalar value.', 400);
        }
        $externalRecommendationId = trim((string)($rawExternalRecommendationId ?? ''));
        $formResponses = $illuminateRequest->input('reviewFormResponses', []);
        if (!is_array($formResponses)) return $this->error('invalid_review_form_responses', 'Review form responses must be an array.', 400);

        $validatedFormResponses = $this->validateReviewFormResponses($reviewAssignment, $formResponses);
        if ($validatedFormResponses instanceof JsonResponse) return $validatedFormResponses;

        $recommendationWriteback = null;
        if ($externalRecommendationId !== '') {
            $recommendationWriteback = $this->persistReviewerRecommendation($context, $reviewAssignment, $externalRecommendationId);
            if ($recommendationWriteback instanceof JsonResponse) return $recommendationWriteback;
        }

        if ($authorComment === '' && $editorComment === '' && $recommendation === '' && $recommendationWriteback === null && $validatedFormResponses === [] && !is_array($illuminateRequest->input('files'))) {
            return $this->error('empty_review_result', 'The review result does not contain any writable content.', 400);
        }

        foreach ($validatedFormResponses as $elementId => $value) {
            $this->saveReviewFormResponse($reviewAssignment, $elementId, $value);
        }
        if ($authorComment !== '') $this->saveReviewComment($reviewAssignment, $authorComment, true);
        if ($recommendation !== '' && $recommendationWriteback === null) {
            $editorComment = trim(($editorComment !== '' ? $editorComment . "\n\n" : '') . '[OMI recommendation: ' . $recommendation . ']');
        }
        if ($editorComment !== '') $this->saveReviewComment($reviewAssignment, $editorComment, false);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$reviewAssignmentId,
            'reviewFormResponsesWritten' => count($validatedFormResponses),
            ...(is_array($recommendationWriteback) ? ['reviewerRecommendation' => $recommendationWriteback] : []),
            'written' => true,
        ]);
    }

    /**
     * Store a reviewer-returned file through the native OJS review attachment model.
     *
     * The OMI service signature authenticates Studio. OJS still resolves the
     * concrete assignment, round and reviewer and refuses completed, thanked,
     * cancelled or declined assignments.
     */
    public function reviewFile(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A journal context is required.', 400);
        $serviceError = $this->authorizeServiceRequest($illuminateRequest, $context->getId());
        if ($serviceError) return $serviceError;

        $submissionId = (int)$illuminateRequest->input('submissionExternalId', 0);
        $reviewAssignmentId = (int)$illuminateRequest->input('reviewAssignmentExternalId', 0);
        $round = (int)$illuminateRequest->input('reviewRound', 0);
        if ($submissionId < 1 || $reviewAssignmentId < 1 || $round < 1) {
            return $this->error(
                'invalid_review_file',
                'A valid submission, review assignment and review round are required.',
                400
            );
        }

        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) {
            return $this->error('submission_not_found', 'Submission not found in this journal.', 404);
        }

        $assignment = Repo::reviewAssignment()->get($reviewAssignmentId, $submissionId);
        if (!($assignment instanceof ReviewAssignment)) {
            return $this->error('review_assignment_not_found', 'Review assignment not found.', 404);
        }
        if ((int)$assignment->getRound() !== $round) {
            return $this->error(
                'review_round_mismatch',
                'The review assignment does not belong to the requested review round.',
                409
            );
        }
        if (!$this->reviewAssignmentAllowsFileWrite($assignment)) {
            return $this->error(
                'review_assignment_not_writable',
                'The review assignment no longer accepts reviewer files.',
                409
            );
        }

        /** @var \PKP\submission\reviewRound\ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $reviewRound = $reviewRoundDao->getById((int)$assignment->getReviewRoundId());
        if (
            !$reviewRound ||
            (int)$reviewRound->getSubmissionId() !== $submissionId ||
            (int)$reviewRound->getStageId() !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW ||
            (int)$reviewRound->getRound() !== $round
        ) {
            return $this->error(
                'review_round_mismatch',
                'The review assignment is not bound to this submission and external review round.',
                409
            );
        }

        $document = $this->workflowFileInput($illuminateRequest);
        if ($document instanceof JsonResponse) return $document;

        return $this->persistWorkflowFile(
            $context,
            $submission,
            (int)$assignment->getReviewerId(),
            SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT,
            Application::ASSOC_TYPE_REVIEW_ASSIGNMENT,
            (int)$assignment->getId(),
            null,
            $document,
            [
                'kind' => 'reviewer-file',
                'reviewAssignmentExternalId' => (string)$assignment->getId(),
                'reviewRound' => $round,
            ]
        );
    }

    /**
     * Store an author revision in the native OJS external-review revision stage.
     *
     * This mirrors SubmissionFileStageAccessPolicy: the author must be assigned
     * to external review and the latest round must contain an OJS decision that
     * permits revision upload. A new review-revision file is created; the
     * original source file is never replaced.
     */
    public function authorRevision(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A journal context is required.', 400);
        $serviceError = $this->authorizeServiceRequest($illuminateRequest, $context->getId());
        if ($serviceError) return $serviceError;

        $submissionId = (int)$illuminateRequest->input('submissionExternalId', 0);
        $authorId = (int)$illuminateRequest->input('authorExternalId', 0);
        $round = (int)$illuminateRequest->input('reviewRound', 0);
        $genreId = (int)$illuminateRequest->input('genreExternalId', 0);
        if ($submissionId < 1 || $authorId < 1 || $round < 1 || $genreId < 1) {
            return $this->error(
                'invalid_author_revision',
                'A valid submission, author, review round and file genre are required.',
                400
            );
        }

        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) {
            return $this->error('submission_not_found', 'Submission not found in this journal.', 404);
        }

        $author = Repo::user()->get($authorId);
        if (!$author) {
            return $this->error('author_not_found', 'The author account is not available.', 404);
        }

        $accessibleStages = Repo::user()->getAccessibleWorkflowStages(
            $authorId,
            $context->getId(),
            $submission
        );
        if (!in_array(
            Role::ROLE_ID_AUTHOR,
            $accessibleStages[WORKFLOW_STAGE_ID_EXTERNAL_REVIEW] ?? [],
            true
        )) {
            return $this->error(
                'author_revision_forbidden',
                'The author is not assigned to this submission in external review.',
                403
            );
        }

        /** @var \PKP\submission\reviewRound\ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId(
            $submissionId,
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW
        );
        if (
            !$reviewRound ||
            (int)$reviewRound->getSubmissionId() !== $submissionId ||
            (int)$reviewRound->getRound() !== $round
        ) {
            return $this->error(
                'review_round_mismatch',
                'Only the latest external review round can receive an author revision.',
                409
            );
        }

        $decisionCount = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByStageIds([WORKFLOW_STAGE_ID_EXTERNAL_REVIEW])
            ->filterByReviewRoundIds([(int)$reviewRound->getId()])
            ->filterByDecisionTypes([
                Decision::ACCEPT,
                Decision::PENDING_REVISIONS,
                Decision::NEW_EXTERNAL_ROUND,
                Decision::RESUBMIT,
            ])
            ->getCount();
        if (!$decisionCount) {
            return $this->error(
                'author_revision_not_requested',
                'OJS has not opened the latest review round for author revisions.',
                409
            );
        }

        /** @var \PKP\submission\GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genre = $genreDao->getById($genreId, $context->getId());
        if (!$genre || $genre->getDependent()) {
            return $this->error(
                'invalid_file_genre',
                'The requested OJS file genre is not available for an author revision.',
                422
            );
        }

        $document = $this->workflowFileInput($illuminateRequest);
        if ($document instanceof JsonResponse) return $document;

        return $this->persistWorkflowFile(
            $context,
            $submission,
            $authorId,
            SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
            Application::ASSOC_TYPE_REVIEW_ROUND,
            (int)$reviewRound->getId(),
            $genreId,
            $document,
            [
                'kind' => 'author-revision',
                'authorExternalId' => (string)$authorId,
                'reviewRound' => $round,
            ]
        );
    }

    private function workflowFileInput(IlluminateRequest $request): array|JsonResponse
    {
        $fileName = $request->input('fileName');
        $mediaType = $request->input('mediaType');
        $contentBase64 = $request->input('contentBase64');

        if (!is_string($fileName) || !is_string($mediaType) || !is_string($contentBase64)) {
            return $this->error(
                'invalid_workflow_file',
                'fileName, mediaType and contentBase64 are required string values.',
                400
            );
        }

        try {
            return WorkflowFileDocument::decode($fileName, $mediaType, $contentBase64);
        } catch (\InvalidArgumentException $e) {
            return $this->error('invalid_workflow_file', $e->getMessage(), 422);
        }
    }

    private function reviewAssignmentAllowsFileWrite(ReviewAssignment $assignment): bool
    {
        return !$assignment->getDateCompleted() && !in_array(
            $assignment->getStatus(),
            [
                ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_DECLINED,
                ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_COMPLETE,
                ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_THANKED,
                ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_CANCELLED,
            ],
            true
        );
    }

    private function persistWorkflowFile(
        object $context,
        object $submission,
        int $uploaderUserId,
        int $fileStage,
        int $assocType,
        int $assocId,
        ?int $genreId,
        array $document,
        array $receipt
    ): JsonResponse {
        $temporary = tmpfile();
        if (!$temporary) {
            return $this->error('storage_error', 'Unable to allocate temporary workflow-file storage.', 500);
        }

        $fileId = null;
        $used = false;
        try {
            if (fwrite($temporary, $document['bytes']) !== $document['byteLength']) {
                throw new \RuntimeException('Unable to write the workflow file.');
            }

            $suffix = $document['extension'] !== '' ? '.' . $document['extension'] : '';
            $dir = Repo::submissionFile()->getSubmissionDir(
                (int)$context->getId(),
                (int)$submission->getId()
            );
            $fileId = app()->get('file')->add(
                stream_get_meta_data($temporary)['uri'],
                $dir . '/' . bin2hex(random_bytes(16)) . $suffix
            );

            $params = [
                'fileId' => $fileId,
                'submissionId' => (int)$submission->getId(),
                'uploaderUserId' => $uploaderUserId,
                'fileStage' => $fileStage,
                'genreId' => $genreId,
                'assocType' => $assocType,
                'assocId' => $assocId,
                'name' => [
                    $submission->getData('locale') => $document['fileName'],
                ],
            ];

            $errors = Repo::submissionFile()->validate(
                null,
                $params,
                $context->getSupportedSubmissionMetadataLocales(),
                $submission->getData('locale')
            );
            if ($errors) {
                return $this->error(
                    'workflow_file_validation_failed',
                    'The file failed native OJS submission-file validation.',
                    422,
                    ['fields' => $errors]
                );
            }

            $submissionFileId = Repo::submissionFile()->add(
                Repo::submissionFile()->newDataObject($params)
            );
            $used = true;
            $stored = app()->get('file')->get($fileId);

            return response()->json(array_merge($receipt, [
                'protocol' => WorkflowFileDocument::PROTOCOL,
                'submissionExternalId' => (string)$submission->getId(),
                'submissionFileExternalId' => (string)$submissionFileId,
                'fileStage' => $fileStage,
                'fileName' => $document['fileName'],
                'mediaType' => (string)($stored->mimetype ?? 'application/octet-stream'),
                'declaredMediaType' => $document['mediaType'],
                'byteLength' => $document['byteLength'],
                'sha256' => $document['sha256'],
                'written' => true,
            ]));
        } finally {
            fclose($temporary);
            if ($fileId !== null && !$used) {
                app()->get('file')->delete($fileId);
            }
        }
    }

    private function reviewerRecommendationCapabilities(object $context): array
    {
        return [$this->nativeReviewerRecommendationsAvailable() ? 'review.recommendations.native' : 'review.recommendations.legacy'];
    }

    private function nativeReviewerRecommendationsAvailable(): bool
    {
        if (
            !method_exists(Repo::class, 'reviewerRecommendation')
            || !method_exists(ReviewAssignment::class, 'getReviewerRecommendationId')
            || !method_exists(ReviewAssignment::class, 'setReviewerRecommendationId')
        ) {
            return false;
        }

        try {
            $repository = Repo::reviewerRecommendation();
            return is_object($repository) && method_exists($repository, 'getRecommendationOptions');
        } catch (\Throwable) {
            return false;
        }
    }

    private function reviewerRecommendationCatalog(object $context, ReviewAssignment $assignment): array
    {
        $storage = $this->nativeReviewerRecommendationsAvailable() ? 'native' : 'legacy';
        $options = [];

        if ($storage === 'native') {
            $rawOptions = Repo::reviewerRecommendation()->getRecommendationOptions(
                context: $context,
                reviewAssignment: $assignment
            );
            foreach (is_array($rawOptions) ? $rawOptions : [] as $externalId => $label) {
                $externalId = (string)$externalId;
                if (!ctype_digit($externalId) || (int)$externalId < 1) continue;
                $options[] = [
                    'externalId' => $externalId,
                    'label' => $this->reviewFormPlainText($label),
                ];
            }
            $selected = method_exists($assignment, 'getReviewerRecommendationId')
                ? $assignment->getReviewerRecommendationId()
                : null;
        } else {
            foreach (ReviewAssignment::getReviewerRecommendationOptions() as $externalId => $translationKey) {
                $externalId = (string)$externalId;
                if ($externalId === '' || !ctype_digit($externalId) || (int)$externalId < 1) continue;
                $options[] = [
                    'externalId' => $externalId,
                    'label' => $this->reviewFormPlainText(__((string)$translationKey)),
                ];
            }
            $selected = $assignment->getRecommendation();
        }

        $selectedExternalId = is_scalar($selected) && ctype_digit((string)$selected)
            && in_array((string)$selected, array_column($options, 'externalId'), true)
            ? (string)$selected
            : null;

        return [
            'storage' => $storage,
            'options' => $options,
            'selectedExternalId' => $selectedExternalId,
        ];
    }

    private function persistReviewerRecommendation(object $context, ReviewAssignment $assignment, string $externalId): array|JsonResponse
    {
        $catalog = $this->reviewerRecommendationCatalog($context, $assignment);
        $selected = null;
        foreach ($catalog['options'] as $option) {
            if ($option['externalId'] === $externalId) {
                $selected = $option;
                break;
            }
        }
        if (!$selected) {
            return $this->error(
                'invalid_reviewer_recommendation',
                'The selected reviewer recommendation is not offered for this review assignment.',
                422,
                ['availableExternalIds' => array_column($catalog['options'], 'externalId')]
            );
        }

        if ($catalog['storage'] === 'native') {
            Repo::reviewAssignment()->edit($assignment, ['reviewerRecommendationId' => (int)$externalId]);
        } else {
            Repo::reviewAssignment()->edit($assignment, ['recommendation' => (int)$externalId]);
        }

        return [
            'externalId' => $externalId,
            'label' => $selected['label'],
            'storage' => $catalog['storage'],
        ];
    }

    private function validateReviewFormResponses(ReviewAssignment $assignment, array $responses): array|JsonResponse
    {
        $formId = (int)$assignment->getData('reviewFormId');
        if ($responses !== [] && $formId < 1) return $this->error('review_form_not_assigned', 'This review assignment does not use a review form.', 400);
        if ($formId < 1) return [];

        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $existing = $responseDao->getReviewReviewFormResponseValues($assignment->getId());
        $validated = [];

        foreach ($responses as $response) {
            if (!is_array($response)) return $this->error('invalid_review_form_response', 'Each review form response must be an object.', 400);
            $elementId = (int)($response['elementExternalId'] ?? 0);
            if ($elementId < 1 || array_key_exists($elementId, $validated)) return $this->error('invalid_review_form_element', 'Review form element identifiers must be valid and unique.', 400);
            $element = $elementDao->getById($elementId, $formId);
            if (!($element instanceof ReviewFormElement)) return $this->error('review_form_element_forbidden', 'A response references an element outside the assigned review form.', 403);
            $normalized = $this->normalizeReviewFormValue($element, $response['value'] ?? null);
            if ($normalized instanceof JsonResponse) return $normalized;
            $validated[$elementId] = $normalized;
        }

        $requiredIds = $elementDao->getRequiredReviewFormElementIds($formId);
        foreach ($requiredIds as $requiredId) {
            $value = array_key_exists((int)$requiredId, $validated)
                ? $validated[(int)$requiredId]
                : ($existing[(int)$requiredId] ?? null);
            if ($this->reviewFormValueEmpty($value)) {
                return $this->error('review_form_required', 'All required OJS review form fields must be completed before submission.', 400, ['elementExternalId' => (string)$requiredId]);
            }
        }
        return $validated;
    }

    private function normalizeReviewFormValue(ReviewFormElement $element, mixed $value): mixed
    {
        $type = (int)$element->getElementType();
        $possible = $element->getLocalizedPossibleResponses();
        $allowed = is_array($possible) ? array_map('strval', array_keys($possible)) : [];

        if ($type === ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
            if (!is_array($value)) return $this->error('invalid_review_form_value', 'Checkbox responses must be arrays.', 400);
            $values = array_values(array_unique(array_map('strval', $value)));
            foreach ($values as $item) if (!in_array($item, $allowed, true)) return $this->error('invalid_review_form_option', 'A checkbox response contains an invalid option.', 400);
            return $values;
        }
        if (in_array($type, [ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS, ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX], true)) {
            if (!is_scalar($value) && $value !== null) return $this->error('invalid_review_form_value', 'Choice responses must contain one option.', 400);
            $scalar = $value === null ? '' : (string)$value;
            if ($scalar !== '' && !in_array($scalar, $allowed, true)) return $this->error('invalid_review_form_option', 'The selected review form option is invalid.', 400);
            return $scalar;
        }
        if (!in_array($type, [ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_SMALL_TEXT_FIELD, ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXT_FIELD, ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXTAREA], true)) {
            return $this->error('unsupported_review_form_element', 'The assigned OJS review form contains an unsupported element type.', 400);
        }
        if (!is_scalar($value) && $value !== null) return $this->error('invalid_review_form_value', 'Text review form responses must be text.', 400);
        $text = $value === null ? '' : (string)$value;
        if (mb_strlen($text) > 100000) return $this->error('review_form_value_too_long', 'A review form response exceeds the supported length.', 400);
        return $text;
    }

    private function reviewFormValueEmpty(mixed $value): bool
    {
        if (is_array($value)) return $value === [];
        return trim((string)($value ?? '')) === '';
    }

    private function reviewFormElementType(int $type): string
    {
        return match ($type) {
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_SMALL_TEXT_FIELD => 'small_text',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXT_FIELD => 'text',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXTAREA => 'textarea',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES => 'checkboxes',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS => 'radio',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX => 'dropdown',
            default => 'unsupported',
        };
    }

    private function reviewFormLocalizations(ReviewFormElement $element): array
    {
        $questions = $element->getData('question');
        $descriptions = $element->getData('description');
        $possibleResponses = $element->getData('possibleResponses');

        $locales = array_values(array_unique(array_merge(
            is_array($questions) ? array_keys($questions) : [],
            is_array($descriptions) ? array_keys($descriptions) : [],
            is_array($possibleResponses) ? array_keys($possibleResponses) : []
        )));

        $localizations = [];
        foreach ($locales as $locale) {
            $question = is_array($questions) ? ($questions[$locale] ?? '') : '';
            $description = is_array($descriptions) ? ($descriptions[$locale] ?? '') : '';
            $possible = is_array($possibleResponses) ? ($possibleResponses[$locale] ?? []) : [];
            $localizations[(string)$locale] = [
                'question' => $this->reviewFormPlainText($question),
                'description' => $this->reviewFormPlainText($description),
                'options' => $this->reviewFormOptions($possible),
            ];
        }
        return $localizations;
    }

    private function reviewFormOptions(mixed $possible): array
    {
        if (!is_array($possible)) return [];
        $options = [];
        foreach ($possible as $value => $label) {
            $options[] = [
                'value' => (string)$value,
                'label' => $this->reviewFormPlainText($label),
            ];
        }
        return $options;
    }

    private function reviewFormPlainText(mixed $value): string
    {
        if (!is_scalar($value)) return '';
        $text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', ' ', $text) ?? $text;
        $text = preg_replace('/<\s*\/p\s*>/i', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
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
        if ($assignment->getCancelled() || $assignment->getDeclined() || $assignment->getDateCompleted()) return null;
        return $assignment;
    }

    private function reviewFileAllowed(ReviewAssignment $reviewAssignment, int $submissionFileId): bool
    {
        if ($submissionFileId < 1) return false;
        /** @var ReviewFilesDAO $reviewFilesDao */
        $reviewFilesDao = DAORegistry::getDAO('ReviewFilesDAO');
        return (bool)$reviewFilesDao->check($reviewAssignment->getId(), $submissionFileId);
    }

    private function saveReviewFormResponse(ReviewAssignment $assignment, int $elementId, mixed $value): void
    {
        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $element = $elementDao->getById($elementId, (int)$assignment->getReviewFormId());
        if (!($element instanceof ReviewFormElement)) return;

        $response = $responseDao->getReviewFormResponse((int)$assignment->getId(), $elementId)
            ?? new ReviewFormResponse();
        $responseType = match ((int)$element->getElementType()) {
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES => 'object',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS,
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX => 'int',
            default => 'string',
        };
        $response->setResponseType($responseType);
        $response->setValue($value);
        if ($response->getReviewId() !== null && $response->getReviewFormElementId() !== null) {
            $responseDao->updateObject($response);
            return;
        }
        $response->setReviewId((int)$assignment->getId());
        $response->setReviewFormElementId($elementId);
        $responseDao->insertObject($response);
    }

    private function saveReviewComment(ReviewAssignment $assignment, string $text, bool $viewable): void
    {
        /** @var \PKP\submission\SubmissionCommentDAO $commentDao */
        $commentDao = DAORegistry::getDAO('SubmissionCommentDAO');
        $comments = $commentDao->getReviewerCommentsByReviewerId(
            (int)$assignment->getSubmissionId(),
            (int)$assignment->getReviewerId(),
            (int)$assignment->getId(),
            $viewable
        );
        $comment = $comments->next() ?? $commentDao->newDataObject();
        $comment->setCommentType(SubmissionComment::COMMENT_TYPE_PEER_REVIEW);
        $comment->setRoleId(Role::ROLE_ID_REVIEWER);
        $comment->setSubmissionId((int)$assignment->getSubmissionId());
        $comment->setAssocId((int)$assignment->getId());
        $comment->setAuthorId((int)$assignment->getReviewerId());
        $comment->setCommentTitle('');
        $comment->setComments($text);
        $comment->setViewable($viewable);
        if ($comment->getId() !== null) {
            $comment->setDateModified(Core::getCurrentDate());
            $commentDao->updateObject($comment);
            return;
        }
        $comment->setDatePosted(Core::getCurrentDate());
        $commentDao->insertObject($comment);
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
        if (($claims['actorMode'] ?? '') === 'review' && !$this->reviewAssignmentForClaims($claims, $submissionId)) return $this->error('review_closed', 'This review is closed. Document access is no longer available.', 403);
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

    /** Only the manager-scoped planned-issue manifest receives this public-facing snapshot. */
    private function issuePublicationMatter(object $context): array
    {
        $plain = static function ($value) {
            $text = static fn ($item): string => trim(html_entity_decode(strip_tags(preg_replace('/<\\/?(?:p|div|br|li)\\b[^>]*>/i', "\n", (string)$item) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (is_array($value)) return array_map($text, array_filter($value, 'is_string'));
            return is_string($value) ? $text($value) : '';
        };
        $groups = [];
        if ($context->getData('enableEnrollmentMasthead')) {
            $repository = Repo::userGroup();
            // The sorted repository method was introduced in 3.5.0-6;
            // mirror the native about-page selection on earlier 3.5 patches.
            if (method_exists($repository, 'getSortedMastheadUserGroups')) {
                $roles = $repository->getSortedMastheadUserGroups($context);
            } else {
                $order = (array)$context->getData('mastheadUserGroupIds');
                $roles = \PKP\userGroup\UserGroup::withContextIds([$context->getId()])
                    ->masthead(true)->excludeRoles([Role::ROLE_ID_REVIEWER])->get()
                    ->sortBy(static function ($group) use ($order) {
                        $position = array_search($group->id, $order);
                        return $position === false ? PHP_INT_MAX : $position;
                    });
            }
            $ids = $repository->getMastheadUserIdsByRoleIds($roles->all(), $context->getId());
            foreach ($roles as $group) {
                $members = [];
                foreach ($ids[$group->id] ?? [] as $userId) {
                    $user = Repo::user()->get($userId);
                    if (!$user || !\PKP\userGroup\relationships\UserUserGroup::withUserId($user->getId())
                        ->withUserGroupIds([$group->id])->withActive()->withMasthead()->first()) continue;
                    $members[] = ['name' => $user->getFullName(), 'localizedNames' => $plain($user->getFullNames()), 'affiliation' => $plain($user->getData('affiliation'))];
                }
                if ($members) $groups[] = ['role' => $plain($group->getAttribute('name')), 'members' => $members];
            }
        }
        // Do not include private account emails, reviewer identities, or user IDs.
        return [
            'publisher' => $plain($context->getData('publisherInstitution')),
            'address' => $plain($context->getData('mailingAddress')),
            'contactName' => $plain($context->getData('contactName')),
            'editorialBoard' => $groups,
        ];
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
