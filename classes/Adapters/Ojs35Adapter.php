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
        return [
            'externalId' => (string)$submission->getId(),
            'type' => 'article',
            'status' => $this->mapStage((int)$submission->getData('stageId')),
            'stageId' => (int)$submission->getData('stageId'),
            'primaryLocale' => (string)$submission->getData('locale'),
            'title' => $publication ? (array)$publication->getData('title') : [],
            'subtitle' => $publication ? (array)$publication->getData('subtitle') : [],
            'abstract' => $publication ? (array)$publication->getData('abstract') : [],
            'keywords' => $publication ? (array)$publication->getData('keywords') : [],
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
