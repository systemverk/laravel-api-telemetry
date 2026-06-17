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

Releases are managed by Release Please via `.github/workflows/release.yml`.

Flow:

1. Commits are merged into `main`.
2. Release Please opens or updates a release PR.
3. The release PR includes the next version and updates `CHANGELOG.md`.
4. When the release PR is merged, Release Please creates:
   - git tag `vX.Y.Z`
   - GitHub Release

## Changelog

`CHANGELOG.md` is generated and maintained automatically by Release Please.
Do not edit it manually unless there is a specific reason.
