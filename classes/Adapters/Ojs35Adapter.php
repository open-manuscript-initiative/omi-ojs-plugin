<?php
namespace APP\plugins\generic\studioIntegration\classes\Adapters;

use APP\facades\Repo;

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
        $publication = $submission->getCurrentPublication();
        $primaryLocale = (string)$submission->getData('locale');
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
                ? $this->normalizeLocalizedKeywords($publication->getData('keywords'), $primaryLocale)
                : [],
            'publicationExternalId' => $publication ? (string)$publication->getId() : null,
            'updatedAt' => $this->formatDate($publication?->getData('lastModified') ?? $submission->getData('lastModified')),
            'extensions' => [
                'org.pkp.ojs' => [
                    'stageId' => (int)$submission->getData('stageId'),
                    'status' => $submission->getData('status'),
                ],
            ],
        ];
    }

    public function mapContributors(object $submission): array
    {
        $publication = $submission->getCurrentPublication();
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

        // OJS installations may expose keywords as a direct list for the
        // submission locale instead of a locale-keyed map.
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
