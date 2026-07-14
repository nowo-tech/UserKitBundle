# Release process

1. Update [CHANGELOG.md](CHANGELOG.md): move entries from `[Unreleased]` to a new `[X.Y.Z] - YYYY-MM-DD` section. (This project does not store version in `composer.json`; Packagist uses the git tag.)
2. Update [UPGRADING.md](UPGRADING.md) if the release has upgrade notes.
3. Run pre-release checks: `make release-check` (cs-fix, cs-check, rector-dry, phpstan, test-coverage-100, validate-translations, and optionally demo healthchecks).
4. Commit all changes, create an annotated tag (e.g. `v1.0.2`), and push branch and tag. The release workflow will create the GitHub Release with the changelog.
5. Publish the package to Packagist if applicable (usually automatic when the tag is pushed).

## Example for v1.0.2

```bash
git add -A
git status   # review
make release-check
git commit -m "Release 1.0.2: bundle standards compliance and i18n locales"
git tag -a v1.0.2 -m "Release 1.0.2"
git push origin main
git push origin v1.0.2
```
