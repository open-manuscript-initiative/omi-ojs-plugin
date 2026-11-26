# Planned issue import into Studio (work in progress)

The first slice exposes the private, read-only OJS manifest at
`GET /api/v1/omi-integration/issues/{issueId}/import-manifest` (relative to the journal's API base).
It requires a native authenticated journal manager/site-admin role, and rejects published
issues and other journal contexts. It uses current scheduled publication versions only.
Sections follow the native issue-specific ordering and articles are sorted by publication
sequence (submission ID breaks ties). An unresolved section is an explicit error.

The manifest preserves issue title, description, volume, number, year, DOI, journal ISSNs,
localized article metadata, submission/publication IDs, section IDs and order. It does not
contain source binaries, reviewer records, or write grants. Responses are private/no-store.

## Remaining end-to-end work (not implemented in this slice)

- Add a localized **Open issue in Studio** action in OJS Future Issues and issue TOC.
- Define a short-lived, single-use issue launch assertion bound to installation, journal,
  issue and editor; do not reuse a single-submission grant for bulk access. Revalidate
  editorial privileges and current membership on every protected source-file request.
- Select each article's approved copyedited/production source where available; preserve
  the existing rich DOCX conversion for notes, tables and citations. Report missing or
  unsupported files without silently creating empty articles.
- Build one periodical issue project in Studio, with separately editable articles and
  preserved journal sections, metadata and stable article-to-OJS identities. Do not
  concatenate submissions into a single article or grant reviewer access.
- Import atomically (or explicitly confirm incomplete import), support progress/retry and
  deduplicate by installation, journal, issue and submission/publication identifiers.
- Preserve individual article writeback targets; issue-wide publishing remains explicit.
- Localize labels in Studio and plugin locales.

## Acceptance checks

1. Two future issues in separate journals: a manager cannot import the other journal.
2. Author, reviewer and section-only editor cannot read the whole unpublished manifest.
3. Native custom section order and article order are preserved, including non-article
   sections; stale publication versions assigned to an issue do not appear.
4. An empty future issue is valid; a published issue returns not found.
5. Two articles with notes, tables, citations, different locales and multiple contributors
   are imported as independently editable manuscripts inside one new issue.
6. Missing/unsupported source is explicitly reported; no hidden partial import.
7. Re-import does not duplicate documents; existing local edits are not silently overwritten.
8. Ordinary article/author/reviewer launches remain unchanged. Full native integration and
   browser E2E tests on supported OJS 3.5 versions are required before release.
