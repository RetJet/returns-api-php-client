# Contributing

**English** · [Polski](docs/pl/CONTRIBUTING.md)

## Setup

```bash
composer install
```

Requires PHP `^8.2`. The test suite runs against a hand-written PSR-18 stub
(`tests/Support/MockHttpClient`), so no network access or live API key is needed to develop or
run tests.

## Before opening a pull request

```bash
composer test           # PHPUnit: unit + integration
composer stan            # PHPStan, level max
composer cs               # coding standard, dry run
composer coverage-check  # every OpenAPI operation has a resource method
composer docs-check      # English and Polish docs pairs have not drifted apart
```

`composer cs-fix` applies the coding-standard fixes `composer cs` reports. CI runs all five
checks (plus a lowest-dependencies job and a distribution-archive check) on every push and
pull request - see `.github/workflows/ci.yml`.

## Documentation stays bilingual

`README.md`, `CHANGELOG.md`, `SECURITY.md` and `CONTRIBUTING.md` live at the repository root in
English only; their Polish translations live under `docs/pl/` with the same filename. Every
other document has an English copy under `docs/en/` and a Polish one under `docs/pl/`, again
sharing a filename. `composer docs-check` verifies both sides of each pair have the same
headings and identical code blocks; it fails the build if they drift apart. Update both
languages in the same pull request - a PR that only touches one side of a pair will not pass CI.

## Changelog

Unreleased changes go under `## [Unreleased]` in `CHANGELOG.md` (and `docs/pl/CHANGELOG.md`),
following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Explain *why* a change was
made, not just what changed - that is the convention the rest of the file already follows.

## Releasing

Releasing means publishing a git tag. `CHANGELOG.md` is what makes that tag mean something, so
it has to already describe what shipped *before* the tag exists - not after.

1. Move the relevant entries from `## [Unreleased]` into a new `## [x.y.z] - YYYY-MM-DD`
   section, in both `CHANGELOG.md` and `docs/pl/CHANGELOG.md`. Leave `## [Unreleased]` in place,
   empty, for whatever comes next. Update the link references at the bottom of both files: the
   new version links to its GitHub release, and `[Unreleased]` re-points to compare against the
   new tag.
2. Open a pull request with that CHANGELOG update and merge it to `main`.
3. Tag the commit on `main` that carries the updated CHANGELOG - not an earlier one:
   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```
4. Verify: CI is green on the tag, and `main`'s `CHANGELOG.md` shows the version under a dated
   heading rather than `## [Unreleased]`. A tag pushed while the changelog still says
   "Unreleased" is a release with no record of what it contains.

## Code style

- PSR-12, enforced by `.php-cs-fixer.dist.php`.
- No comments that restate what the code does; a comment earns its place by explaining a
  non-obvious constraint, workaround, or invariant. This mirrors the docblocks already in
  `src/` - read a few before adding new code.
- New resource methods, exceptions, or models need a matching entry in
  `docs/en/api-reference.md` (and `docs/pl/api-reference.md`), and `composer coverage-check`
  must still pass if the change touches an OpenAPI operation.
