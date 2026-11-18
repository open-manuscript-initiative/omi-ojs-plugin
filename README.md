# Open Manuscript Studio Integration for OJS 3.5

This repository contains the **Open Journal Systems (OJS) 3.5 integration plugin** for the **Open Manuscript Initiative (OMI)** and **Open Manuscript Studio**.

The plugin implements the **OMI Integration API v1 / OJS profile** (`omi-integration/1/ojs`) as a PKP generic plugin. OJS remains the workflow and publication-system authority, while Open Manuscript Studio remains a separate manuscript authoring and review environment with its own release cycle and data model.

## Current status

- **Supported platform:** Open Journal Systems 3.5.x
- **Plugin type:** PKP Generic Plugin
- **OMI protocol:** `omi-integration/1`
- **Profile:** `omi-integration/1/ojs`
- **Current source version:** `1.8.7.0`
- **License:** GNU General Public License v3.0

The current integration supports role-aware author, editor and reviewer workflows, protected manuscript import, reviewer-scoped file access, native OJS review forms, signed review writeback, direct author submissions, and editor-authenticated publication artifact transfer. Development on `main` may contain improvements that are newer than the latest published release.

## What the plugin does

The plugin adds an **Open in Studio** workflow to OJS and issues short-lived, signed launch assertions for the current journal, submission, user and role. Studio then accesses only the OJS resources explicitly permitted by those scopes.

The integration is designed so that:

- OJS remains the source of truth for submissions, review assignments, review forms and publishing workflow state;
- Studio does not receive direct database or private filesystem access;
- author, editor and reviewer permissions remain distinct;
- reviewer access is tied to the concrete OJS `ReviewAssignment`;
- double-blind review boundaries are preserved;
- writeback is performed through authenticated application-level endpoints rather than direct database writes.

## Implemented capabilities

### Launch and identity

- role-aware Studio launch for **editors, authors and reviewers**;
- HMAC-SHA256 signed, short-lived launch assertions;
- stable OJS installation identity;
- journal → OMI context mapping;
- submission → OMI submission mapping;
- explicit least-privilege scopes by actor role.

### Manuscript and metadata access

- submission metadata read;
- contributor metadata read for authorized roles, including preferred public name, structured affiliations/ROR, biography, competing interests, publication-list visibility and CRediT roles;
- manuscript/source-file discovery;
- protected binary submission-file transfer;
- genre-aware source-file metadata;
- DOCX manuscript import into Studio, including headings, inline formatting, notes and bibliography-related structures supported by Studio.

### Peer review

- reviewer-specific Studio launch;
- assignment-scoped reviewer metadata and manuscript access;
- reviewer source files restricted through PKP/OJS review-file authorization;
- native OJS review-form retrieval;
- assignment-scoped OJS reviewer recommendation options with localized labels;
- localized review-form questions, descriptions and options;
- native review-form response persistence;
- author-visible and editor-only review comments kept separate;
- signed Studio → OJS review-result writeback;
- native reviewer returned-file writeback bound to the concrete review assignment and round;
- native author revision writeback into the latest writable OJS external-review round without replacing source files;
- reviewer revision/response scopes without contributor or reviewer-identity leakage.

### Editor integration

- reviewer candidate discovery for authorized editorial users;
- reviewer identity access only through explicit editorial scopes;
- role-aware launch and API authorization designed to preserve OJS workflow authority.

### Publication artifacts

- production-stage inspection before transfer;
- provenance-verified transfer of self-contained HTML, JATS XML, print PDF and interactive PDF artifacts;
- independent verification of `omi-publication-build@0.1.0` SHA-256 provenance;
- stable per-manuscript/locale/format galley identity and idempotent retries;
- changed artifacts create a new proof while keeping the same logical galley;
- transferred galleys remain unapproved and unpublished until an OJS editor acts;
- legacy HTML galley transfer remains available for older Studio clients.

See [Publication artifact transfer](docs/publication-artifact-transfer.md) and [Native review and author revision writeback](docs/revision-writeback.md).

## Security model

Launch assertions are scoped to one OJS installation, journal, submission, actor and role, and expire after the configured TTL. Studio authenticates protected API calls with:

```text
Authorization: OMI <payload>.<signature>
```

Server-to-server writeback uses a separate HMAC-SHA256 request signature with installation binding, timestamp validation and body hashing.

The plugin deliberately does **not** expose:

- OJS database credentials;
- private filesystem paths;
- unrestricted reviewer identities;
- unrestricted contributor data to reviewers;
- submission files outside the current reviewer assignment.

Reviewer file authorization uses the PKP/OJS review-file boundary rather than trusting client-supplied file identifiers.

## OJS remains workflow authority

The plugin does not attempt to replace OJS workflow semantics. In particular, OJS remains authoritative for:

- submission state;
- review assignment ownership and availability;
- review rounds;
- review forms and required fields;
- reviewer recommendation configuration;
- editorial decisions;
- publication and production workflow state.

Studio operates as an external authoring/review client within those constraints.

## API surface

The plugin exposes integration endpoints inside the current journal context through the PKP plugin API and the OMI launch handler. Depending on actor mode and granted scope, these include operations for:

- capabilities;
- submission metadata;
- contributors;
- reviewer candidates;
- submission files and protected file content;
- review forms and assignment-scoped reviewer recommendation options;
- review-result writeback;
- reviewer returned-file and author revision writeback through native OJS submission-file stages;
- publication artifact inspection and transfer for authorized Production-stage editors.

Clients must not assume that possession of a submission ID grants access. Every protected request is checked against the signed launch assertion and the current OJS context.

## Installation

Use the published release package rather than a source-code ZIP when installing on an OJS instance.

1. Download the latest `studioIntegration-ojs-3.5-<version>.tar.gz` package from GitHub Releases or from the OMI website documentation.
2. In OJS, open **Settings → Website → Plugins → Upload A New Plugin** (wording may vary by locale).
3. Upload the archive and enable **Open Manuscript Studio Integration** under Generic Plugins.
4. Open the plugin settings.
5. Configure the **Studio URL**.
6. Configure or generate the shared integration secret.
7. Configure a stable installation ID for production use if required by the deployment.
8. Use HTTPS in production.

The installable archive has a top-level `studioIntegration/` directory, matching PKP plugin packaging expectations. Versions 1.8.3 and later include native `install.xml` and `upgrade.xml` checks. When OJS copies and executes these descriptors, an incomplete copy (including an empty `classes/Adapters` directory) is rejected before it records success. The error identifies missing, empty or unreadable files. If the descriptors themselves cannot be copied, the plugin cannot guarantee that OJS will run its guard. OJS may remove files from a failed installation/upgrade; retain the normal plugin backup before upgrading.

Both ZIP and TAR.GZ are built from the same explicit tracked-file inventory. The release contains `PACKAGE-CONTENTS.sha256`; CI extracts both formats through PHP `PharData`, as OJS does, validates every listed file and tests missing/empty adapter rejection.

### Hosting requirements and failed-copy diagnostics

Installation uses the standard OJS plugin uploader; no domain name, absolute server path, Plesk command, root access or external PHP library is built into the plugin. During installation/upgrade, the PHP process must be able to write the generic-plugin parent directory and replace the existing plugin tree. Once installed, a complete read-only plugin tree is supported. Files must remain readable by PHP.

From 1.8.7, launch and API entry files resolve from the plugin's own directory, including when the caller has changed the PHP working directory. Launch assertions use the current OJS request and native API dispatcher for domains, ports, journal paths and URL rewriting. The Studio URL is a per-journal setting; no production domain or server-specific repair script is required by the plugin. The required `studioIntegration` package-directory name follows the native OJS plugin namespace and is independent of the domain and OJS installation subdirectory.

An operator's installation log identified `FileManager.php` copy failures with `Permission denied`, including the adapter and installation descriptor. Archive completeness alone does not prevent a target-filesystem access denial. The plugin cannot grant itself filesystem privileges or repair ownership belonging to another operating-system user. The hosting administrator must provide the normal OJS plugin installation permissions; changes must be limited to the appropriate deployment account and directories, without world-writable permissions.

From 1.8.4, a missing, empty or unreadable required helper stops Studio launch and the plugin API with `PLUGIN_INSTALLATION_INCOMPLETE` (HTTP 503). Detailed relative filenames and failure categories are written to the PHP administrator log; public responses do not expose installation paths. From 1.8.5, saved settings remain visible with a localized warning while incomplete, writes are blocked, and a missing settings template/class produces renderable diagnostic HTML. The Studio launcher remains visible but disabled with a plain-text explanation; restoring the installation re-enables it. Repairing the files restores the integration without disabling read-only deployments. This check requires the plugin's entry files to be loadable; it cannot intercept failures in OJS discovery before the plugin runs.

The compatibility workflow tests OJS 3.5.0-4/-5/-6 and additionally 3.5.0-6 with PHP 8.4. It invokes native `PluginHelper::installPlugin` separately on pinned 1.6.0 and 1.8.3 releases, then `upgradePlugin` on the candidate release from each baseline and a fresh install of that candidate, using extensionless upload temporary files and a separate non-root OS account and the disposable `ojs-ci` database. The 1.6.0 hash matches the operator-supplied working package exactly. It checks installed versions, every installed payload hash against its source archive, ownership under the PHP account, native Smarty settings rendering and saved-value preservation, unreadable-adapter diagnostics, recovery and protected-directory copy denial. This is native CLI lifecycle coverage; it does not claim a production browser-upload test or support for untested OJS release families.

The same non-root fixture also serves the installed package through native HTTP under `journal-a.example.invalid` at the web root and `journal-b.example.invalid/nested/journals` in a subdirectory. It checks real API routing, static assets and protected submission rejection. Signed-URL fixtures additionally cover non-default ports and rewritten routes. These checks do not claim a complete authenticated browser/editor workflow or bypass a host's filesystem restrictions.

## Releases and verification

Published releases include installable ZIP/TAR.GZ packages and checksums. The current stable release is available from:

- https://github.com/open-manuscript-initiative/omi-ojs-plugin/releases
- https://openmanuscript.org/docs/integrations/ojs-plugin

Release CI validates PHP syntax, `version.xml`, publication artifact/provenance contracts, required plugin files and package structure before publication.

Publication waits for successful PHP and native OJS workflow results on the exact main-branch commit being packaged. A manual release cannot bypass those checks. If the version tag already points to another commit, its published assets are retained; a new source version is required for a new package.

## Compatibility and PKP alignment

The plugin targets **OJS 3.5.x** and uses PKP/OJS repositories and workflow objects where available rather than bypassing the application layer.

The project is being prepared for PKP Plugin Gallery review. Repository-level compatibility, security and packaging checks are not the same as official PKP approval; official inclusion remains subject to PKP maintainer review and installation-level compatibility testing.

## AI-assisted development disclosure

Generative AI has materially assisted development of this plugin, including architecture analysis, implementation, PKP API analysis, security review, CI/CD work, testing support and documentation.

Human maintainers remain responsible for understanding, reviewing, testing, merging and releasing all changes. The repository contains a dedicated [`AI-DECLARATION.md`](AI-DECLARATION.md) describing this use in accordance with PKP's transparency and human-accountability expectations for AI-assisted software contributions.

## Documentation

OMI documentation:

- OJS plugin documentation and download: https://openmanuscript.org/docs/integrations/ojs-plugin
- OJS Integration Profile v1: https://openmanuscript.org/docs/integrations/ojs-profile-v1
- OJS manuscript file import: https://openmanuscript.org/docs/integrations/ojs-file-import
- OMI Integration API v1: https://openmanuscript.org/docs/integrations/integration-api-v1
- Integration architecture: https://openmanuscript.org/docs/integrations/architecture
- Publication artifact transfer protocol: [docs/publication-artifact-transfer.md](docs/publication-artifact-transfer.md)

Project repositories:

- Open Manuscript Studio: https://github.com/open-manuscript-initiative/open-manuscript-studio
- Open Manuscript Initiative specifications and website: https://github.com/open-manuscript-initiative/omi

## Development principles

Changes to the OJS connector should preserve the following constraints:

1. **Least privilege** — every actor receives only the scopes required for the current workflow.
2. **OJS authority** — OJS remains authoritative for review and publication workflow state.
3. **No cross-database coupling** — Studio never accesses the OJS database directly.
4. **Assignment-bound review access** — reviewer access is tied to a concrete OJS review assignment.
5. **No identity leakage** — double-blind review boundaries must remain intact.
6. **Native PKP APIs first** — prefer PKP/OJS repositories, DAOs and workflow semantics over direct persistence shortcuts.
7. **Auditable releases** — published packages are produced by CI and accompanied by checksums.

## License

GNU General Public License v3.0. See [`LICENSE`](LICENSE).


## Direct submissions from Studio (1.3.0)

`GET /api/v1/omi-integration/submission-options` exposes the current context's
public supported submission languages, active unrestricted sections/series,
enabled file components, acceptance state and author-facing terms. It is available
only when the integration plugin is enabled in the context.

Updated Studio clients use these options to prepare DOCX and OMI files and create
new submissions through the **native PKP 3.5 author API**. The author must supply
their own destination API key. The plugin shared secret is not an author
credential. This endpoint does not create submissions, grant roles or bypass
native validation. The author's explicit final confirmation invokes native
submission completion, including its standard editorial workflow.

Deploy the corresponding Studio direct-submission update and database migration
alongside this plugin. For OMP, an individual study becomes a new authored-work
submission, not a chapter added to an existing volume.

See [Studio's direct submission guide](https://github.com/open-manuscript-initiative/open-manuscript-studio/blob/main/docs/direct-publishing-submissions.md)
for configuration, recovery and validation instructions. Real PKP 3.5 author-flow
verification is required before production rollout; no production submissions
were created while developing this endpoint.


## PKP 3.5.0-6 compatibility

The compatibility CI matrix now includes the official `3_5_0-6` tag alongside
`3_5_0-4` and `3_5_0-5`. The smoke test verifies the actual application's
`dbscripts/xml/version.xml` against the selected target before loading the
plugin; a green job for another version cannot substitute for -6 evidence.

The -6 source review covers PKP submission-API anonymization, contributor/ROR
and ORCID changes, stricter context/file access and publication versioning.
The adapter uses authorized repository objects and its own projections, not
subclasses of the changed protected PKP schema mappers. See the
[shared review](https://github.com/open-manuscript-initiative/open-manuscript-studio/blob/main/docs/pkp-3.5.0-6-review.md)
and retain the per-version installation/workflow CI results. This matrix
extension does not publish a new plugin package or certify production upgrades.

## Editorial workflow projection (1.7.0.0)

`GET /api/v1/omi-integration/workflow-status?submissionId=…` returns the current
submission/publication stage and selected metadata to a native authorized
editorial user. It contains no reviewer identities or confidential reports.
Updated artifact clients send the inspected `expectedWorkflowToken`; a metadata
or stage change rejects transfer with HTTP 409, including the locked persistence
recheck. Older clients may omit the token. This is a read projection, not an
editorial-decision, copyediting-file or proof-approval write API.

## Selective metadata reconciliation

Version 1.8 adds authenticated `omi-ojs-metadata/1` compare-and-apply for selected title, subtitle, abstract and keyword fields. It preserves other locales and checks native editorial permissions and snapshot freshness. Publication status, identifiers and contributor identities remain native OJS authority. See [metadata reconciliation](docs/metadata-reconciliation.md).
