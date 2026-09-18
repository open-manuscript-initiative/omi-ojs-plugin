<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Adapters\Ojs35Adapter;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use APP\plugins\generic\studioIntegration\classes\Core\HtmlGalleyDocument;
use APP\plugins\generic\studioIntegration\classes\Core\PublicationArtifactDocument;
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
        return ['has.context'];
    }

    public function getGroupRoutes(): void
    {
        Route::get('', $this->capabilities(...))->name('api.omiIntegration.capabilities');
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
        Route::post('html-galley', $this->htmlGalley(...))
            ->middleware(['has.user', self::roleAuthorizer([
                Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
            ])])->name('api.omiIntegration.htmlGalley');
        Route::post('publication-artifact', $this->publicationArtifact(...))
            ->middleware(['has.user', self::roleAuthorizer([
                Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT,
            ])])->name('api.omiIntegration.publicationArtifact');
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
            'format' => 'required_if:action,transfer|in:html,jats,pdf-print,pdf-interactive',
            'mediaType' => 'required_if:action,transfer|string|max:128',
            'fileName' => 'required_if:action,transfer|string|max:255',
            'artifactBase64' => 'required_if:action,transfer|string|max:90000000',
            'build' => 'required_if:action,transfer|array',
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

        $submissionId = (int)$data['submissionId'];
        $authorize = function () use ($context, $user, $submissionId, $data) {
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
            if (
                (int)$submission->getData('stageId') !== WORKFLOW_STAGE_ID_PRODUCTION ||
                !$publication ||
                (int)$publication->getData('status') !== Submission::STATUS_QUEUED ||
                (isset($data['publicationId']) &&
                    (int)$data['publicationId'] !== (int)$publication->getId())
            ) {
                abort(
                    409,
                    'Inspect the current unpublished production version before transferring a publication artifact.'
                );
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
                &$used
            ) {
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
                'version' => '1.5.0',
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
                'review.form.read',
                'review.form.write',
                'review.forms.native',
                'review.files.scoped',
                'editor.html-galley.write',
                'editor.publication-artifact.write',
                'publication.html.write',
                'publication.jats.write',
                'publication.pdf.write',
                'publication.provenance.verify',
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

        $formId = (int)$assignment->getData('reviewFormId');
        if ($formId < 1) {
            return response()->json([
                'protocol' => 'omi-integration/1',
                'submissionExternalId' => (string)$submissionId,
                'reviewAssignmentExternalId' => (string)$assignment->getId(),
                'reviewForm' => null,
            ]);
        }

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

        return response()->json([
            'protocol' => 'omi-integration/1',
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$assignment->getId(),
            'reviewForm' => [
                'externalId' => (string)$formId,
                'elements' => $elements,
            ],
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

        if ($authorComment === '' && $editorComment === '' && $recommendation === '' && $recommendationWriteback === null && $validatedFormResponses === []) {
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
