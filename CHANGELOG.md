# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- Repository bootstrap; specification committed.
- Recorded the Kimai 2.40.0 vs 2.65.0 API investigation in `docs/kimai-version-notes.md`;
  no breaking difference on any extension point the plugin uses.
- Added the registrable plugin skeleton: bundle class, DI extension, `pro_rata_time_export.base_rate`
  configuration wiring, and unimplemented stubs for the compensation domain services.
- Added a Docker Compose development environment (Kimai 2.40.0 by default, `KIMAI_TAG=2.65.0`
  for the newest supported version) and a `composer validate` CI workflow.
