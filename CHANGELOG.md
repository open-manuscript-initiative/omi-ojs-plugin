# Changelog

## 1.13.0.0 — 2026-11-28

- Add signed read-only work-context lookup for profile translator/language-review statistics in Studio 1.0.0-rc.6.
- Preserve native article/publication/issue grouping in assignment tokens and result receipts, with fresh editorial authorization and cross-context checks.
- Prepare the coordinated source version; native production acceptance remains pending.


## 1.12.0.0 — 2026-11-28

- Bind translation tasks to a source/target language pair and the current OJS publication version.
- Require translated title, abstract and keywords and update only the target native metadata locale.
- Separate metadata-only work from full manuscript translation using actual/planned full-text publication languages.
- Reject incomplete full-text translation, wrong language pairs and changed publication scope.
- Preserve source and other localized metadata; metadata-only tasks do not upload manuscript files.

## 1.11.0.0 — 2026-11-28

- Bind Studio scientific reviews to native OJS double-anonymous review assignments and native review forms/recommendations.
- Return language review and translation as open workflow tasks, with files stored in the current native workflow stage.
- Receive the corrected DOCX and exact structured review snapshot atomically, with checksummed receipts and duplicate-safe retries.
- Keep confidential peer-review notes in native editor-only comments; open tasks use native OJS discussions with separate author/editor participant sets.
- Recheck submission, role, review round and current editorial authorization before storing a task result.
- Close scientific reviewer launch/file access after submission; retain versioned open collaboration until editorial completion.


## 1.10.0.0 — 2026-11-27

- Extend planned-issue manifests with native imprint and editorial masthead metadata, retaining multilingual values (#53).
- Retire PHP 8.2 from the supported CI matrix; support PHP 8.3 and 8.4 (#54).
- Keep OJS native editorial authorization and publication approval unchanged. Compatible Studio issue assembly is required for consuming the extended metadata.

## 1.9.2.0 — 2026-11-26

- Shorten the planned-issue grid launch label to `OMI` to avoid wrapping and overlapping neighboring rows on narrow screens (#51).
- Keep the full localized action as the native tooltip and retain the descriptive table-of-contents launcher, exact issue target and authorization checks.
- Native mobile rendering on the operator's installation remains to be verified; no change to integration permissions or data contracts.

## 1.9.1.0 — 2026-11-26

- Include native current-publication DOI and printed pages in manager-scoped planned issue import metadata (#49), without changing write permissions or substituting issue DOI for article DOI.
- Coordinate with Studio 1.0.0-rc.5 for complete issue import, per-study artifact transfer and reviewed printed page writeback. Live end-to-end editorial acceptance remains required; neither component automatically publishes the issue.

## 1.9.0.0 — 2026-11-26

- Add an ordered native planned-issue manifest preserving journal, issue, section and current publication identities (#43).
- Add a dedicated signed editorial issue-import assertion and protected manuscript transfer, restricted to scheduled articles in the same unpublished issue (#44).
- Add localized launch actions in the native future issue grid and table of contents (#45).
- Extend publication artifact transfer with exact installation, issue and publication checks and inspected workflow tokens; retain native editorial approval (#46).
- Add authenticated printed page-range inspection and confirmed metadata writeback with native permission checks, transactional locks and stale-token rejection (#47).
- Requires a compatible Studio issue receiver and project assembly for complete issue launch. Studio batch publishing and live end-to-end acceptance remain separate; this plugin release does not automatically publish articles or issues.

## 1.8.7.0 — 2026-11-18

- Load launch/API handler files relative to the plugin itself, independently of the PHP process working directory. Normalize asset URL separators while retaining the current OJS origin and installation path.
- Exercise signed launch URLs on different domains, ports, installation subdirectories and native rewritten routes; retain submission authorization and configured Studio/installation identity.
- Add native HTTP checks after non-root installation/upgrade, using two Host headers and root/nested web layouts. Check the public API, shipped JS/CSS and rejection of unsigned submission access on the OJS/PHP compatibility matrix.
- Verify upgrades from the operator's exact working 1.6.0 archive as well as 1.8.3, comparing all installed bytes and PHP-account ownership even for old packages without a manifest.
- Publish only the exact main commit that passed both PHP CI and native OJS compatibility. Do not replace an existing version's release assets with bytes from another commit.
- These changes do not override operating-system copy denials or claim a repair of the operator's production filesystem.

## 1.8.6.0 — 2026-11-18

- Replace the inline installation-check middleware with an autoloaded class name. OJS 3.5 inspects middleware as strings; the closure caused HTTP 500 in DecodeApiTokenWithValidation before capabilities or submission endpoints could run.
- Preserve native context middleware and fail-closed incomplete/mixed-installation HTTP 503 responses, without changing signed-assertion or submission scopes.
- Add regression coverage for string-only middleware inspection and native PKP middleware discovery; require the new guard in installation/package integrity checks.

## 1.8.5.0 — 2026-11-18

- Keep the settings dialog renderable when installation is incomplete: show saved values with a localized warning instead of returning a non-HTML error object.
- Block settings writes while incomplete, and render a standalone diagnostic if the settings class or template itself is missing.
- Keep the Studio launcher visible with a localized explanation while installation is incomplete; disable navigation and restore normal launch after recovery.
- Give the form an explicit native plugin-grid action and test real Smarty rendering and settings preservation in the non-root OJS lifecycle matrix.

## 1.8.4.0 — 2026-11-18

- Return `PLUGIN_INSTALLATION_INCOMPLETE` / HTTP 503 before helper-dependent API calls or Studio launch when required files are missing, empty or unreadable; retain detailed failures in the administrator log.
- Suppress broken launch buttons and show the diagnostic when plugin settings are opened. Keep complete read-only deployments working and recover automatically after files are restored.
- Distinguish filesystem failure categories in native installation guards and explain host-independent PHP read/write requirements without site-specific paths or privilege changes.
- Add real native fresh-install and upgrade lifecycle CI as a separate non-root OS account; verify all shipped hashes and recorded versions, test unreadable files and protected target directories, and include OJS 3.5.0-6 on PHP 8.4.

## 1.8.3.0 — 2026-11-18

- Add native OJS install and upgrade descriptors with a standalone required-file check before version success. Missing, empty or unreadable adapters/helpers now produce an explicit installation error.
- Build ZIP and portable USTAR TAR.GZ deterministically from the same tracked-file inventory and include a per-file SHA-256 manifest.
- Exercise PHP PharData extraction on PHP 8.2/8.3/8.4, missing/empty adapter rejection, and the actual PKP installer code-action dispatch. The external transfer/copy loss reported by the operator is not claimed reproduced.

## 1.8.2.0 — 2026-11-18

- Rebuild a complete installable package after a deployed/downloaded copy was reported to contain an empty `classes/Adapters` directory.
- Validate the actual extracted ZIP and TAR.GZ, compare every helper PHP file with its source bytes, and run both direct-entry class-loading tests from each extracted package before publication.
- Include `classes/Adapters/Ojs35Adapter.php` and the shared loader. This packaging repair does not change submission permissions or metadata behavior.

## 1.8.1.0 — 2026-11-17

- Fix the authenticated submission HTTP 500 (`Ojs35Adapter` class not found) when OJS directly instantiates the namespaced plugin without running the legacy `index.php` wrapper.
- Load adapter and core helpers through a shared, idempotent bootstrap on plugin and API entry paths; keep legacy loading compatible.
- Add a direct-loading regression that maps a submission and signs/verifies a launch token without preloading helpers. Extend native OJS compatibility and release/package checks to cover all required helper classes.
- This addresses the reported missing-class exception. Production launch must still be retested after installing the new package; unrelated xmlConverter/funding asset and PageSpeed requests are separate issues.

## 1.8.0.0 — 2026-11-17

- Add `editor.metadata.reconcile` and the native authenticated metadata inspection/apply endpoint.
- Merge only selected title/subtitle/abstract/keyword fields in an enabled metadata locale; preserve other locales and native contributor identities.
- Recheck current-stage permissions, native publication edit permission, queued state, publication version and snapshot token under locks. Use native validation/edit and metadata-change notification.
- Add public contributor projection and privacy filtering, pure metadata tests and controller rejection/race coverage. Live OJS acceptance remains required.

## 1.7.0.0 — source preparation

- Add native editorial, submission-scoped workflow and selected-metadata inspection.
- Bind publication artifact inspection to an optional workflow token and reject changed metadata/stages before and during locked transfer.
- Preserve OJS decision/publication authority and existing client compatibility.
- Add projection and controller regression tests; real OJS acceptance remains required.

## Unreleased — 2026-11-07

- Extend installation CI to OJS 3.5.0-6 while retaining -4/-5.
- Verify the actual checked-out PKP version before recording a compatibility smoke result.


## 1.6.0.0 — 2026-09-19

- Add signed Studio → OJS reviewer-file writeback bound to the concrete native review assignment and external-review round.
- Store reviewer-returned files as native `SUBMISSION_FILE_REVIEW_ATTACHMENT` records associated with the OJS `ReviewAssignment`, while refusing declined, completed, thanked and cancelled assignments.
- Add signed Studio → OJS author-revision writeback for the latest external review round after an OJS decision opens revision upload.
- Store author revisions as new native `SUBMISSION_FILE_REVIEW_REVISION` records associated with the OJS `ReviewRound`; original source files are never overwritten.
- Mirror OJS 3.5 author revision eligibility for accept, pending-revisions, new-external-round and resubmit decisions.
- Validate workflow file names, media types, strict Base64 payloads and native OJS submission-file metadata; return byte length, SHA-256 and native submission-file IDs.
- Advertise operational `review.files.write` support and include it in reviewer launch assertions.
- Add CI contract coverage and documentation for native reviewer and author file writeback.

## 1.5.1.0 — 2026-09-19

- Expand authorized contributor import with preferred public name, email, country, URL, biography, competing-interests statement, browse-list visibility and primary-contact state.
- Export structured affiliations with ROR identifiers when available while preserving the existing flattened affiliation field for compatibility.
- Export PKP CRediT role identifiers and degrees without inferring contributor semantics in Studio.
- Keep reviewer launch boundaries unchanged; extended contributor metadata is available only through the existing contributor-authorized workflow.
- Grant author launches `contributors.read` for their own accessible submission while requiring the separate `review.identity.read` scope for reviewer discovery.

## 1.5.0.0 — 2026-09-18

- Add editor-authenticated `omi-publication-artifact/1` inspection and transfer for existing unpublished OJS 3.5 production submissions.
- Support provenance-verified self-contained HTML, JATS XML, print PDF and interactive PDF publication artifacts.
- Advertise granular publication artifact capabilities and format availability through Integration API capability discovery.
- Require and independently verify `omi-publication-build@0.1.0` provenance, exact artifact byte length, SHA-256 digest, manuscript identity, output identity, generator identity and deterministic build URN before persistence.
- Keep `.omi-build.json` provenance internal to verification rather than exposing it as a public galley; return the verified build ID and artifact digest in the transfer receipt.
- Give each manuscript/locale/format a stable galley identity; identical retries are idempotent while changed artifacts create a new proof file without publishing or approving the galley.
- Preserve the legacy `omi-html-galley/1` endpoint for older Studio clients.
- Add offline safety checks for JATS DTD declarations, PDF signatures and the existing restricted self-contained HTML model.
- Keep OJS authoritative by rechecking Production-stage editorial access and current unpublished publication state under database locks before transfer.
- Update the Integration API implementation version to 1.5.0 and plugin package version to 1.5.0.0.

## 1.4.1.0 — 2026-09-15

- Add assignment-scoped OJS reviewer recommendation options to the OMI Integration API.
- Return localized OJS labels and the current assignment selection without deriving recommendation IDs in Studio.
- Persist native PKP recommendation IDs on newer OJS versions and the OJS 3.5 assignment-bound recommendation codes on supported legacy installations.
- Validate signed Studio writeback against the concrete review assignment and offered options; preserve the legacy recommendation field for older Studio clients.

## 1.4.0.0 — 2026-09-13

- Add editor-authenticated HTML galley inspection and transfer for existing unpublished OJS 3.5 production submissions.
- Reuse one galley per Studio manuscript and language; identical retries do not create another proof file. Changed HTML retains prior proof files for editorial history.
- Require native editorial API authorization and production-stage access, recheck the target publication under a database lock, and leave publication/approval to OJS editors.
- Accept self-contained HTML with inline styles and embedded raster images, with an 8 MiB limit and no scripts or external resources.

## 1.3.2.0 — 2026-09-09

### Fixed

- Made the Studio launcher stable across PKP History API navigation, DOM remounts and repeated workflow openings.
- Consolidated launcher styling into the single `css/studioIntegration.css` source of truth; JavaScript no longer owns visual positioning.
- Added an explicit launcher instance/version marker so stale or legacy launcher elements are replaced instead of reused.
- Switched OJS from the legacy `1.1.4`/`1.1.7` launcher assets to unified cache-busted `studioIntegration.css` and `studioIntegration.js` assets.
- Removed the obsolete versioned launcher asset files.

## 1.3.1.0 — 2026-09-09

### Fixed

- Moved the fixed “Open in Studio” launcher to the top-left of the viewport so it no longer overlaps OJS workflow actions such as Save.
- Added mobile safe-area offsets and viewport-constrained width for consistent placement on desktop and mobile screens.
- No launch, authorization, submission-selection, or permission logic changed in this patch.

## 1.3.0.0 — 2026-09-08

- Expose public submission options for Studio's native OJS/OMP author submission flow.
- Include accepted languages, unrestricted active sections/series, file components and author terms.
- Require an enabled plugin/context; leave creation, file upload and completion to native PKP permissions.
- Document deployment dependencies and the required real-platform verification.


## Unreleased

## 1.2.1 — 2026-09-05

### Fixed

- Restored PKP 3.5-compatible review comments and form-response persistence without calling non-existent repository convenience methods.
- Verified the complete double-anonymous reviewer workflow, required review form, corrections, separated comments and Studio-to-OJS writeback against a disposable native OJS 3.5 environment.

## 1.2.0 — 2026-08-29

- Added bidirectional OJS ↔ Open Manuscript Studio peer-review synchronization.
- Added least-privilege launch scopes for authors, reviewers and editors.
- Restricted reviewer file access to the concrete OJS `ReviewAssignment` and native `review_files` grants.
- Enforced the reviewer file grant again on binary file downloads.
- Added timestamped HMAC-authenticated Studio → OJS review-result writeback.
- Kept author-visible and editor-only reviewer comments separate through PKP's native review-comment storage.
- Added native OJS review-form integration for reviewer assignments.
- Added support for all six PKP review-form element types: small text, text, textarea, checkboxes, radio buttons and dropdowns.
- Added `review.form.read` and `review.form.write` reviewer scopes.
- Preserved OJS review-form required-field rules and author visibility (`included`) when the form is rendered in Studio.
- Validated review-form responses again on the OJS side before saving them through PKP's native review-form response DAO.
- Added CI validation for PHP 8.2, 8.3 and 8.4 and package-integrity checks.
- Added pull-request dependency review for newly introduced vulnerable dependencies.

## 1.1.28 — 2026-08-18

- Added stable submission-file genre metadata to the OMI Integration API file listing.
- Each file now exposes `genreKey`, `genreName`, and `genreCategory` in addition to the existing numeric `genreExternalId`.
- This allows Studio to select source files in two dimensions: first the OJS submission-file stage (`stage = 2`), then the Article Text component (`genreKey = SUBMISSION`), without relying on localized labels or installation-specific numeric genre IDs.

## 1.1.17 — 2026-08-12

- Changed the authenticated Studio launch response to expose `launchUrl` and resolved launch `mode` as top-level PKP `JSONMessage` additional attributes.
- Removed dependence on client-side inference of the nested `content` payload for successful launches.
- Kept editor/reviewer role separation unchanged: editor launches retain `contributors.read`, reviewer launches remain anonymous.

## 1.1.16 — 2026-08-12

- Fixed the OJS browser launcher to unwrap PKP `JSONMessage` responses and read `content.launchUrl` as well as a top-level `launchUrl`.
- Fixed a false “The OJS integration did not return a Studio launch URL” error when the backend had actually returned a valid launch URL.
- Made launch-mode resolution role-aware on the server: editorial roles always receive the normal editor launch, even while viewing the Review stage.
- Reviewer mode is only selected for reviewer-role users requesting the reviewer launch path.
- Editor launches retain `contributors.read`; reviewer launches remain isolated from contributor metadata.
- Added the cache-busted `studioIntegration-1.1.6.js` launcher asset.

## 1.1.14 — 2026-08-12

- Added a dedicated Studio launcher to the OJS reviewer page.
- Added reviewer-specific launch authorization: the current user must have the reviewer role and an assignment to the requested submission.
- Reviewer launches open the Studio Peer Review workspace directly with `?review=1`.
- Reviewer launches deliberately do not issue the normal OJS metadata/contributor launch assertion, preventing author-identifying contributor metadata from being exposed through this path.
- Added English, Hungarian and German reviewer-launch labels.
- Updated the browser launcher to recognize reviewer URLs and request `mode=review`.

## 1.1.3 — 2026-08-08

- Changed the Studio launcher to request a fresh signed launch URL only when the user clicks the button.
- Added visible loading and error states instead of silently disabling or removing the launcher when the launch request fails.
- Added browser console diagnostics for failed launch requests.
- Switched the launcher to same-tab navigation after successful launch URL creation, avoiding popup-blocker ambiguity.
- Added `cache: no-store` for launch URL requests and bumped frontend asset cache keys.

## 1.1.2 — 2026-08-08

- Fixed the OJS 3.5 workflow launcher so it no longer depends on PHP resolving the submission ID during template rendering.
- Added an authenticated, authorization-checked launch URL endpoint inside the plugin.
- Updated the browser integration client to resolve the active submission from OJS workflow URLs and request a short-lived launch URL on demand.
- Added support for dynamic OJS workflow navigation through History API and DOM mutation detection.
- Kept the shared secret and HMAC signing server-side; the browser receives only a short-lived signed Studio launch URL.
- Added a loading state for the launcher while the authorized launch URL is being created.
- Added cache-busting to the launcher JavaScript and stylesheet URLs.

## 1.1.1 — 2026-08-07

- Fixed OJS 3.5 localization compatibility by adding valid Gettext PO headers to the English, Hungarian and German locale files.
- Declared UTF-8 encoding and the correct language metadata in each locale.
- Corrected a Hungarian translation typo in the launch-token lifetime setting.
- No Integration API or data-model changes; this is a localization compatibility patch release.

## 1.1.0 — 2026-08-07

- Added reusable PKP connector core primitives for Base64URL, HMAC launch tokens and JSON API responses.
- Added OJS 3.5 adapter for submission metadata, contributors and submission-file listing.
- Added OMI Integration API v1 capability discovery.
- Reworked launch claims to use `omi-integration/1` and `omi-integration/1/ojs` identifiers.
- Added stable installation ID support.
- Limited initial API capabilities to implemented read operations; binary transfer, review, revision and publication writes are not falsely advertised.
