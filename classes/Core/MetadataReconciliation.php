<?php
namespace APP\plugins\generic\studioIntegration\classes\Core;

/** Field proposals never own publication status, identifiers or contributor identity. */
final class MetadataReconciliation
{
    public const PROTOCOL = 'omi-ojs-metadata/1';
    public const WRITABLE_FIELDS = ['title', 'subtitle', 'abstract', 'keywords'];

    public static function snapshot(object $submission, object $publication, array $locales, array $keywords, array $contributors, bool $canWrite): array
    {
        $workflow = WorkflowSnapshot::create($submission, $publication);
        $metadata = $workflow['metadata'];
        foreach (['title', 'subtitle', 'abstract'] as $key) {
            $metadata[$key] = array_map(self::plainText(...), (array)$publication->getData($key));
        }
        $metadata['keywords'] = $keywords;
        $metadata['doi'] = method_exists($publication, 'getDoi') ? $publication->getDoi() : null;
        $metadata['references'] = (string)($publication->getData('citationsRaw') ?? '');
        $publicContributors = array_map(static fn(array $item): array => array_intersect_key($item, array_flip([
            'externalId', 'name', 'preferredPublicName', 'affiliation', 'affiliations', 'identifiers', 'sequence', 'roles', 'includeInBrowse',
        ])), $contributors);
        $state = [
            'workflowToken' => $workflow['stateToken'],
            'submissionId' => (int)$submission->getId(),
            'publicationId' => (int)$publication->getId(),
            'stage' => $workflow['stage'],
            'publicationStatus' => $workflow['publicationStatus'],
            'locales' => array_values($locales),
            'metadata' => $metadata,
            'contributors' => $publicContributors,
        ];
        return [
            'protocol' => self::PROTOCOL,
            'authority' => 'OJS',
            ...$state,
            'stateToken' => hash('sha256', self::canonical($state)),
            'writableFields' => $canWrite ? self::WRITABLE_FIELDS : [],
            'abstractRepresentation' => 'plain-text',
        ];
    }

    /** Merge only the selected locale and fields; preserve all other native values. */
    public static function patch(object $publication, string $locale, array $fields, array $locales, array $keywords = []): array
    {
        if (!in_array($locale, $locales, true)) throw new \InvalidArgumentException('Choose a supported OJS metadata locale.');
        if (!$fields || count($fields) > count(self::WRITABLE_FIELDS)) throw new \InvalidArgumentException('Select at least one supported manuscript metadata field.');
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, self::WRITABLE_FIELDS, true)) throw new \InvalidArgumentException('This field is controlled in OJS and cannot be written by this endpoint.');
            if ($key === 'keywords') {
                if (!is_array($value) || !array_is_list($value) || count($value) > 100) throw new \InvalidArgumentException('Keywords must be a list of at most 100 terms.');
                foreach ($value as $term) if (!is_string($term) || trim($term) === '' || mb_strlen($term) > 200) throw new \InvalidArgumentException('Invalid keyword.');
                $keywords[$locale] = array_values(array_unique(array_map('trim', $value)));
                $params[$key] = $keywords;
            } else {
                if (!is_string($value) || mb_strlen($value) > ($key === 'abstract' ? 100000 : 1000)) throw new \InvalidArgumentException('Invalid text metadata value.');
                $map = (array)$publication->getData($key);
                $text = htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $map[$locale] = $key === 'abstract' ? nl2br($text, false) : $text;
                $params[$key] = $map;
            }
        }
        return $params;
    }
    public static function plainText(mixed $value): string
    {
        if (!is_string($value)) return '';
        $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $value);
        $value = preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6])\s*>~i', "\n", $value);
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private static function canonical(mixed $value): string
    {
        if (!is_array($value)) return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $item) $parts[] = (array_is_list($value) ? '' : json_encode((string)$key) . ':') . self::canonical($item);
        return (array_is_list($value) ? '[' : '{') . implode(',', $parts) . (array_is_list($value) ? ']' : '}');
    }
}
