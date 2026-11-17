<?php
namespace APP\plugins\generic\studioIntegration\classes\Core;

/** Minimal editorial projection: no reviewer identities, reports, or private file paths. */
final class WorkflowSnapshot
{
    public static function create(object $submission, ?object $publication): array
    {
        $stage = (int)$submission->getData('stageId');
        $state = [
            'submissionId' => (int)$submission->getId(),
            'publicationId' => $publication ? (int)$publication->getId() : null,
            'stageId' => $stage,
            'stage' => match ($stage) {
                1 => 'submission', 2 => 'internal-review', 3 => 'review',
                4 => 'copyediting', 5 => 'production', default => 'unknown',
            },
            'submissionStatus' => (int)$submission->getData('status'),
            'publicationStatus' => $publication ? (int)$publication->getData('status') : null,
            'lastModified' => $publication?->getData('lastModified') ?? $submission->getData('lastModified'),
            'metadata' => $publication ? [
                'title' => (array)$publication->getData('title'),
                'subtitle' => (array)$publication->getData('subtitle'),
                'abstract' => (array)$publication->getData('abstract'),
                'pages' => $publication->getData('pages'),
                'issueId' => $publication->getData('issueId'),
                'datePublished' => $publication->getData('datePublished'),
                'licenseUrl' => $publication->getData('licenseUrl'),
                'copyrightHolder' => $publication->getData('copyrightHolder'),
                'copyrightYear' => $publication->getData('copyrightYear'),
            ] : [],
        ];
        return [
            'protocol' => 'omi-ojs-workflow/1',
            'authority' => 'OJS',
            'stateToken' => self::token($state),
            ...$state,
            'editorialDecisionsWritable' => false,
        ];
    }
    private static function token(array $state): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($sort, $value);
        };
        return hash('sha256', json_encode($sort($state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
