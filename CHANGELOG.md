# Changelog

## 1.1.0 — 2026-08-07

- Added reusable PKP connector core primitives for Base64URL, HMAC launch tokens and JSON API responses.
- Added OJS 3.5 adapter for submission metadata, contributors and submission-file listing.
- Added OMI Integration API v1 capability discovery.
- Reworked launch claims to use `omi-integration/1` and `omi-integration/1/ojs` identifiers.
- Added stable installation ID support.
- Limited initial API capabilities to implemented read operations; binary transfer, review, revision and publication writes are not falsely advertised.
