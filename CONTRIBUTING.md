# Contributing

Thanks for contributing to Laravel API Telemetry.

## Local Checks

Run before opening a PR:

```bash
composer validate --strict
composer analyse
composer test
```

## Commit Convention

This repository uses Conventional Commits and automated semantic versioning.

Supported prefixes:

- `fix:` -> patch release
- `feat:` -> minor release
- `feat!:` or `BREAKING CHANGE:` -> major release

Examples:

```text
fix: handle invalid request ids safely
feat: add monthly consolidation command option
feat!: rename config keys for v2
```

## Release Process

Releases are fully automated by [semantic-release](https://semantic-release.gitbook.io/)
via `.github/workflows/release.yml`.

Flow:

1. Commits are merged into `main`.
2. The `Create Release` workflow runs on every push to `main`.
3. semantic-release analyses the commits since the last release (using the
   Conventional Commits preset) to determine the next version:
   - no `fix:`/`feat:`/breaking commits -> no release is published.
   - otherwise the version is bumped according to the
     [commit convention](#commit-convention) above.
4. When a release is warranted, semantic-release automatically:
   - generates/updates `CHANGELOG.md`
   - creates the git tag `vX.Y.Z`
   - publishes the GitHub Release with generated notes

There is no release PR to review or merge — merging to `main` is the release
trigger. Make sure your commit messages are accurate before merging.

## Changelog

`CHANGELOG.md` is generated and maintained automatically by semantic-release
from the commit history. Do not edit it manually.
