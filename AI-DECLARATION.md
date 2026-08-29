# AI Contribution Declaration

This repository has been developed with substantial assistance from generative AI tools.

## Scope of AI assistance

Generative AI has been used as a development assistant for parts of:

- software architecture and implementation planning;
- drafting and refactoring PHP integration code;
- analysis of OJS and PKP 3.5 APIs and source code;
- security review and hardening suggestions;
- CI/CD and release workflow development;
- test planning and debugging;
- technical documentation and release notes.

AI assistance has been especially relevant to integration code that interacts with PKP workflow, authorization, peer-review anonymity, review rounds, review forms, and submission-file handling. These areas are treated as security- and workflow-sensitive and are reviewed against the actual OJS/PKP source APIs rather than relying on generated assumptions.

## Human responsibility and oversight

The project maintainer retains responsibility for all contributions accepted into this repository. AI-generated or AI-assisted output is not accepted as authoritative by itself.

Before changes are merged or released, the project workflow is intended to include:

- human review and approval through Git/GitHub;
- verification against the relevant OJS and PKP source code and documented APIs;
- automated PHP syntax and package-integrity checks;
- dependency/security review where available;
- installation and end-to-end testing for workflow-sensitive changes before claiming production compatibility.

The maintainer is expected to understand, explain, modify, and take responsibility for submitted code and documentation. AI assistance does not transfer responsibility for correctness, licensing, security, privacy, or compatibility to the AI provider or to PKP maintainers.

## Contribution quality

Raw AI output should not be treated as a finished contribution. Generated suggestions must be reviewed for correctness, scope, licensing implications, security consequences, and consistency with PKP architecture before acceptance.

No autonomous AI agent is authorized to merge changes or publish releases without human approval.

## Disclosure to PKP

When this plugin, or changes derived from it, are submitted to PKP or the PKP Plugin Gallery, the use of generative AI assistance should be disclosed in the submission or pull request. Reviewers may use this declaration to identify areas that warrant additional verification.

This declaration is intended to follow the transparency, human accountability, understanding, verification, and reviewability principles in PKP's Policy on AI Contributions to Software (published July 2026).

## Maintenance

This file describes the development process for the repository as a whole. It should be updated if the role of AI in the project materially changes.
