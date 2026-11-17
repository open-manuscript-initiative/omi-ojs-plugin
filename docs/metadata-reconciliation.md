# Metadata reconciliation — omi-ojs-metadata/1

Requires OJS 3.5 and plugin source 1.8.0.0. Capability: `editor.metadata.reconcile`.
Use native OJS user API authentication at `POST /api/v1/omi-integration/metadata`;
the shared integration secret does not confer editorial permission.

Inspect: `{ "action": "inspect", "submissionId": 12 }`. The response contains a
`snapshot` with current publication ID, native locale list, metadata, public
contributors, four `writableFields` when permitted, `stateToken` and separate
`workflowToken`. The metadata token includes metadata and ordered contributor
projection. Emails and private contributor authorization data are excluded.

Apply requires `action: "apply"`, submissionId, publicationId, exact native locale,
expectedStateToken, confirmed:true and `fields`, an object selecting one or more
of title, subtitle, abstract (strings) and keywords (array of strings). Unknown
fields and unsupported locales fail. Native validation also applies. Text is
escaped; abstract plain text uses line breaks. Only that locale is replaced;
other locales, contributors and identifiers remain intact. The returned receipt
binds the previous token, publication, locale and selected fields, with
`published:false`.

Current-stage editorial access and native publication edit permission are checked
again during apply. Only a current queued/unpublished publication can be edited.
Submission and publication rows are locked, native state is reread and compared,
then `Repo::publication()->validate/edit` is used. The native `MetadataChanged`
event is emitted after the transaction. Permission failures return 403, changed
publication/snapshot 409 and invalid proposals 422. Responses are no-store.

DOI, issue/pages, publication date/status, contributor identity and references
are read-only. License/copyright are exposed for explicit Studio-side import;
this endpoint does not write them. An apply is never a publication action or an
external acceptance receipt. Reinspect the artifact target after metadata edits.
There is no automatic retry after an uncertain response.

Run `php tests/metadata-reconciliation.php` and
`php tests/publication-artifact-transfer.php` plus the existing PHP CI suite.
Controller fixtures exercise native permission denial, context isolation,
publication/version races, native validation, selective locale preservation and
exactly one metadata event on success. Real OJS acceptance remains necessary:
use separate sessions, cause a stale proposal, confirm rejection and compare
other language values and native notification behavior after a valid edit.
