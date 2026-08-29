# Open Manuscript Studio Integration for OJS 3.5

This repository contains the **Open Journal Systems (OJS) 3.5 integration plugin** for the **Open Manuscript Initiative (OMI)** and **Open Manuscript Studio**.

The plugin implements the **OMI Integration API v1 / OJS profile** (`omi-integration/1/ojs`) as a PKP generic plugin. OJS remains the workflow and publication-system authority, while Open Manuscript Studio remains a separate manuscript authoring and review environment with its own release cycle and data model.

## Current status

- **Supported platform:** Open Journal Systems 3.5.x
- **Plugin type:** PKP Generic Plugin
- **OMI protocol:** `omi-integration/1`
- **Profile:** `omi-integration/1/ojs`
- **Current stable release:** `v1.2.0`
- **License:** GNU General Public License v3.0

The current integration supports role-aware author, editor and reviewer workflows, protected manuscript import, reviewer-scoped file access, native OJS review forms and signed review writeback. Development on `main` may contain improvements that are newer than the latest published release.

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
- contributor metadata read for authorized roles;
- manuscript/source-file discovery;
- protected binary submission-file transfer;
- genre-aware source-file metadata;
- DOCX manuscript import into Studio, including headings, inline formatting, notes and bibliography-related structures supported by Studio.

### Peer review

- reviewer-specific Studio launch;
- assignment-scoped reviewer metadata and manuscript access;
- reviewer source files restricted through PKP/OJS review-file authorization;
- native OJS review-form retrieval;
- localized review-form questions, descriptions and options;
- native review-form response persistence;
- author-visible and editor-only review comments kept separate;
- signed Studio → OJS review-result writeback;
- reviewer revision/response scopes without contributor or reviewer-identity leakage.

### Editor integration

- reviewer candidate discovery for authorized editorial users;
- reviewer identity access only through explicit editorial scopes;
- role-aware launch and API authorization designed to preserve OJS workflow authority.

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
- review forms;
- review-result writeback.

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

The installable archive has a top-level `studioIntegration/` directory, matching PKP plugin packaging expectations.

## Releases and verification

Published releases include installable ZIP/TAR.GZ packages and checksums. The current stable release is available from:

- https://github.com/open-manuscript-initiative/omi-ojs-plugin/releases
- https://openmanuscript.org/docs/integrations/ojs-plugin

Release CI validates PHP syntax, `version.xml`, required plugin files and package structure before publication.

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
