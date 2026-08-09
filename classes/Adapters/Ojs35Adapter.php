<?php
namespace APP\plugins\generic\studioIntegration\classes\Adapters;

use APP\core\Application;
use APP\facades\Repo;
use PKP\controlledVocab\ControlledVocab;

final class Ojs35Adapter
{
    public function getSubmission(int $submissionId, int $contextId): ?object
    {
        $submission = Repo::submission()->get($submissionId, $contextId);
        if (!$submission || (int)$submission->getData('contextId') !== $contextId) {
            return null;
        }
        return $submission;
    }

    public function mapSubmission(object $submission): array
    {
        $publication = $this->getHydratedCurrentPublication($submission);
        $primaryLocale = (string)$submission->getData('locale');

        $publicationMetadata = $publication
            ? [
                'subjects' => $this->getControlledVocab(
                    $publication,
                    ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_SUBJECT,
                    $primaryLocale
                ),
                'disciplines' => $this->getControlledVocab(
                    $publication,
                    ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_DISCIPLINE,
                    $primaryLocale
                ),
                'supportingAgencies' => $this->getControlledVocab(
                    $publication,
                    ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_AGENCY,
                    $primaryLocale
                ),
                'coverage' => $this->normalizeLocaleObject($publication->getData('coverage')),
                'rights' => $this->normalizeLocaleObject($publication->getData('rights')),
                'source' => $this->normalizeLocaleObject($publication->getData('source')),
                'type' => $this->normalizeLocaleObject($publication->getData('type')),
                'dataAvailability' => $this->normalizeLocaleObject($publication->getData('dataAvailability')),
                'languages' => $this->normalizeLocalizedKeywords($publication->getData('languages'), $primaryLocale),
                'publisherId' => $this->nullableString($publication->getData('pub-id::publisher-id')),
                'licenseUrl' => $this->nullableString($publication->getData('licenseUrl')),
                'copyrightHolder' => $this->normalizeLocaleObject($publication->getData('copyrightHolder')),
                'copyrightYear' => $publication->getData('copyrightYear') !== null
                    ? (int)$publication->getData('copyrightYear')
                    : null,
            ]
            : [];

        return [
            'externalId' => (string)$submission->getId(),
            'type' => 'article',
            'status' => $this->mapStage((int)$submission->getData('stageId')),
            'stageId' => (int)$submission->getData('stageId'),
            'primaryLocale' => $primaryLocale,
            'title' => $publication ? (array)$publication->getData('title') : [],
            'subtitle' => $publication ? (array)$publication->getData('subtitle') : [],
            'abstract' => $publication ? (array)$publication->getData('abstract') : [],
            'keywords' => $publication
                ? $this->getPublicationKeywords($publication, $primaryLocale)
                : [],
            'metadata' => $publicationMetadata,
            'publicationExternalId' => $publication ? (string)$publication->getId() : null,
            'updatedAt' => $this->formatDate($publication?->getData('lastModified') ?? $submission->getData('lastModified')),
            'extensions' => [
                'org.pkp.ojs' => [
                    'stageId' => (int)$submission->getData('stageId'),
                    'status' => $submission->getData('status'),
                    'publicationId' => $publication ? (int)$publication->getId() : null,
                ],
                // These fields can be provided by Open Science / custom OJS
                // metadata plugins. They are deliberately kept outside the
                // portable OMI core because they are not part of the OJS 3.5
                // Publication REST schema supplied by PKP.
                'org.pkp.ojs.openScience' => $publication
                    ? $this->mapOpenScienceExtension($publication)
                    : [],
            ],
        ];
    }

    public function mapContributors(object $submission): array
    {
        $publication = $this->getHydratedCurrentPublication($submission);
        if (!$publication) {
            return [];
        }
        $authors = $publication->getData('authors');
        if (!$authors) {
            return [];
        }
        $result = [];
        foreach ($authors as $author) {
            $orcid = $author->getData('orcid');
            $identifiers = [];
            if ($orcid) {
                $identifiers[] = ['scheme' => 'orcid', 'value' => (string)$orcid];
            }
            $result[] = [
                'externalId' => (string)$author->getId(),
                'name' => [
                    'given' => (string)$author->getLocalizedGivenName(),
                    'family' => (string)$author->getLocalizedFamilyName(),
                ],
                'email' => (string)($author->getData('email') ?? ''),
                'affiliation' => $author->getLocalizedAffiliationNamesAsString(),
                'country' => $author->getData('country'),
                'roles' => ['author'],
                'sequence' => $author->getSequence(),
                'primaryContact' => (bool)$author->getPrimaryContact(),
                'identifiers' => $identifiers,
                'scope' => ['type' => 'submission', 'externalId' => (string)$submission->getId()],
            ];
        }
        return $result;
    }

    public function mapFiles(object $submission): array
    {
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();
        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'externalId' => (string)$file->getId(),
                'name' => (string)($file->getData('originalFileName') ?? $file->getData('name', $submission->getData('locale')) ?? ''),
                'mediaType' => (string)($file->getData('mimetype') ?? ''),
                'size' => $file->getData('fileSize'),
                'stage' => (int)$file->getData('fileStage'),
                'genreExternalId' => $file->getData('genreId') !== null ? (string)$file->getData('genreId') : null,
                'revision' => $file->getData('revision'),
                'createdAt' => $this->formatDate($file->getData('createdAt')),
                'updatedAt' => $this->formatDate($file->getData('updatedAt')),
            ];
        }
        return $result;
    }

    private function getHydratedCurrentPublication(object $submission): ?object
    {
        $current = $submission->getCurrentPublication();
        if (!$current) {
            return null;
        }

        $publicationId = (int)$current->getId();
        $submissionId = (int)$submission->getId();
        if ($publicationId <= 0 || $submissionId <= 0) {
            return $current;
        }

        return Repo::publication()->get($publicationId, $submissionId) ?? $current;
    }

    private function getPublicationKeywords(object $publication, string $primaryLocale): array
    {
        return $this->getControlledVocab(
            $publication,
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            $primaryLocale
        );
    }

    private function getControlledVocab(
        object $publication,
        string $symbolic,
        string $primaryLocale
    ): array {
        $publicationId = (int)$publication->getId();
        if ($publicationId <= 0) {
            return [];
        }

        $values = Repo::controlledVocab()->getBySymbolic(
            $symbolic,
            Application::ASSOC_TYPE_PUBLICATION,
            $publicationId,
            [],
            false
        );

        return $this->normalizeLocalizedKeywords($values, $primaryLocale);
    }

    private function normalizeLocaleObject(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_object($value)) {
            $value = $value instanceof \Traversable
                ? iterator_to_array($value)
                : (array)$value;
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $locale => $text) {
            if (!is_scalar($text)) {
                continue;
            }
            $normalized = trim((string)$text);
            if ($normalized !== '') {
                $result[(string)$locale] = $normalized;
            }
        }
        return $result;
    }

    private function normalizeLocalizedKeywords(mixed $value, string $primaryLocale): array
    {
        if ($value === null) {
            return [];
        }

        if (is_object($value)) {
            if ($value instanceof \Traversable) {
                $value = iterator_to_array($value);
            } else {
                $value = (array)$value;
            }
        }

        if (is_string($value)) {
            $normalized = $this->normalizeKeywordList($value);
            return $normalized === [] ? [] : [$primaryLocale => $normalized];
        }

        if (!is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $normalized = $this->normalizeKeywordList($value);
            return $normalized === [] ? [] : [$primaryLocale => $normalized];
        }

        $result = [];
        foreach ($value as $locale => $keywords) {
            if (is_object($keywords) && $keywords instanceof \Traversable) {
                $keywords = iterator_to_array($keywords);
            }

            $normalized = $this->normalizeKeywordList($keywords);
            if ($normalized !== []) {
                $result[(string)$locale] = $normalized;
            }
        }

        return $result;
    }

    private function normalizeKeywordList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*[;,]\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $keyword) {
            if (is_scalar($keyword)) {
                $text = trim((string)$keyword);
                if ($text !== '') {
                    $normalized[] = $text;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    private function mapOpenScienceExtension(object $publication): array
    {
        $result = [];
        foreach ([
            'openData',
            'openMaterials',
            'preregistered',
            'preregisteredPlus',
        ] as $property) {
            $value = $publication->getData($property);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $result[$property] = is_array($value)
                ? $this->normalizeLocaleObject($value)
                : $value;
        }
        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function mapStage(int $stageId): string
    {
        return match ($stageId) {
            1 => 'submission',
            2 => 'internal-review',
            3 => 'review',
            4 => 'copyediting',
            5 => 'production',
            default => 'unknown',
        };
    }

    private function formatDate(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string)$value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return (string)$value;
        }
    }
}
