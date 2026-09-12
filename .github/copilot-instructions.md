---
applyTo: '**'
---

# AGENTS.md

## Environment

- Use `rg` for search.
- Prefer project-local scripts and lockfile-backed dependencies when they exist. Use global tools as fallback checks when a repo has no local tooling yet.
- Windows-backed global tools are exposed through WSL shims in `/usr/local/bin`.
- The `/usr/local/bin/node`, `/usr/local/bin/npm`, and `/usr/local/bin/npx` shims translate existing absolute WSL paths with `wslpath -w` before calling Windows Node. Use normal WSL paths such as `/tmp/file.js` and `/mnt/c/...` through those shims.

## Setup Commands

- If `composer.json` exists, run `composer install --no-interaction --prefer-dist`.
- If `package-lock.json` exists, run `npm ci`; if only `package.json` exists, run `npm install`.
- If Python workflow files or tests need dependencies, prefer the repo's `.venv`, `requirements*.txt`, `pyproject.toml`, `uv.lock`, `poetry.lock`, or `tox.ini`.
- If a Ruby `Gemfile.lock` exists, run `bundle install` and prefer `bundle exec <tool>`.
- Do not add a package manifest only to use a global fallback tool. Add manifests when the repo should own reproducible build, test, lint, or CI behavior.

## Testing Instructions

- Prefer repo scripts first: `composer test`, `composer check-all`, `npm test`, `npm run lint`, `npm run build`, `pytest`, `tox`, `nox`, or `bundle exec rspec`.
- For PHP fallback checks, use relevant tools from: `parallel-lint`, `phpcs`, `phpcbf`, `php-cs-fixer`, `phpmd`, `pdepend`, `phpmetrics`, `phpstan`, `psalm`, `phpunit`, and `wp`.
- For JavaScript, HTML, CSS, and Markdown fallback checks, use relevant tools from: `eslint`, `jest`, `vitest`, `prettier`, `stylelint`, `htmlhint`, `html-validate`, `markdownlint-cli2`, `cspell`, `codespell`, `playwright`, `tsc`, `tsx`, `pa11y`, `svgo`, `sass`, `postcss`, `autoprefixer`, `http-server`, `jscpd`, `depcruise`, `nyc`, and `jsdoc`.
- For Bash fallback checks, use `shellcheck`, `shfmt`, and `bats`.
- For GitHub Actions and config files, use `actionlint`, `yamllint`, `jq`, `yq`, `gh`, and `check-jsonschema`.
- For Python workflow support, use relevant tools from: `pytest`, `tox`, `nox`, `ruff`, `mypy`, `pyright`, `bandit`, `black`, `isort`, `coverage`, `radon`, `xenon`, `pip-audit`, `pre-commit`, and `check-jsonschema`.
- For Ruby fallback checks, use `rubocop`, `standardrb`, `reek`, `flog`, `flay`, `rubycritic`, `brakeman`, and `bundler-audit`. `rubocop --version` and `standardrb --version` can take several seconds through the WSL shim.
- Rector is not installed globally. Install and run Rector per repo if that repo needs it.

## Code Style

- Match the existing code style and framework conventions.
- Prefer existing repo helpers and scripts over inventing new wrappers.
- Add or update focused tests when behavior changes.

## Project Standards

- This is a WordPress plugin with current minimums of WordPress 6.8 and PHP 8.2. Treat the plugin header, `readme.txt`, `composer.json`, and `phpcs.xml` as the authoritative compatibility surfaces and keep them aligned.
- Follow the configured WordPress Coding Standards and project PHPCS rules.
- Prefix plugin functions with `sse_` and new constants with `SSE_`; preserve the established `ES_SITE_EXPORTER_VERSION` constant.
- Use the `enginescript-site-exporter` text domain for all translatable strings.
- Prefer WordPress APIs over raw PHP equivalents when they provide the appropriate behavior.
- Add PHPDoc, including `@param`, `@return`, and `@since`, consistent with the existing codebase.

## WordPress Security

- Validate and sanitize untrusted input at the trust boundary, and escape output for its specific HTML, attribute, URL, or JavaScript context.
- Require both nonce verification and an appropriate capability check before state-changing or sensitive operations.
- Prefer WordPress database APIs; prepare dynamic raw SQL with `$wpdb->prepare()`.
- Canonicalize and constrain filesystem paths before access, prevent traversal outside approved directories, and use the WordPress Filesystem API where appropriate.
- Return `WP_Error` where consistent with existing APIs, and do not expose sensitive information in user-facing errors or logs.

## Internationalization

- Internationalize all user-facing strings with the appropriate WordPress helper and the `enginescript-site-exporter` text domain.
- Update `languages/enginescript-site-exporter.pot` when translatable strings change, following the repository's existing POT-generation workflow.

## Releases

- Change version numbers only when explicitly instructed.
- For a release, keep the plugin header, `ES_SITE_EXPORTER_VERSION`, README version badge/download link, `readme.txt` stable tag, changelogs, and POT project version synchronized.
- Move the Unreleased changelog entries into the released version section.

## Changelog Updates

- When updating the main plugin codebase, update both `CHANGELOG.md` and the changelog section in `readme.txt` in the same change.
- Avoid changelog updates for changes limited to `.github/`, `tests/`, `stubs/`, `.private/`, or `languages/`.

## Repo Hygiene

- Respect uncommitted user changes. Do not revert unrelated edits.
- Do not commit generated caches, dependency folders, coverage reports, or tool output unless the repo already tracks them.
- If a global tool reports that project-local configuration is missing, either use a conservative command-line fallback or add config only when it improves repeatable repo workflows.
