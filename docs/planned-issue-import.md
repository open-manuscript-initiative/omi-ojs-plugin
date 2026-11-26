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

## Signed source transfer (second slice)

OJS now accepts an explicit issueId at the existing launch operation, instead of a
submissionId. The new short-lived assertion has exactly the issue.import.read scope,
contains an issue identifier rather than a submission identifier, and targets the
dedicated Studio /integrations/ojs/issue-launch route. The Studio receiver is not
implemented yet, so the OJS UI does not advertise this action yet.

The signed read endpoints are relative to the existing OMI integration API base:

- GET issue-import/manifest
- GET issue-import/articles/{submissionId}
- GET issue-import/articles/{submissionId}/files/{submissionFileId}/content

Use Authorization: OMI payload.signature. The signed manifest uses the same builder as
the authenticated native manifest. Every signed request verifies signature, expiration,
installation, context, exact scope, current manager role and unpublished issue status.
Article and binary requests also recheck current scheduled membership. The article
response includes localized metadata, contributors, and eligible source descriptors
in priority order: production-ready, copyedited, final, original submission.
Only article-text DOCX files are eligible; review attachments and internal workflow
files are excluded. The Studio must select the first available eligible source or
report missing content. No write permissions are conveyed.

## Remaining end-to-end work

- Verify the localized **Open complete issue in Studio** action against the deployed Studio receiver in a native browser E2E test.
- Implement the dedicated Studio receiver and single-use nonce enforcement, with
  bounded source downloads through the signed endpoints and strict origin validation.
- Consume the ordered source descriptors and preserve the existing rich DOCX conversion
  for notes, tables and citations. Report missing or unsupported files without silently
  creating empty articles.
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

## Native OJS launcher

The future issue grid exposes a native row action and the issue TOC modal exposes an escaped local launch link. Both use the native issue object, only for unpublished issues within the authenticated journal manager's context. A fresh assertion is minted only on click by the existing launch handler. Hungarian, English and German labels are included. Deployment must include the Studio receiver and issue assembly before enabling this feature in production.
