# Releasing

This module is distributed as a **GitHub Release carrying an installable ZIP**. PrestaShop has no
central directory to publish to — a merchant downloads the ZIP and uploads it in their back office
under **Modules → Module Manager → Upload a module**.

Versions follow [SemVer](https://semver.org/).

## Cutting a release

1. **Bump the version in BOTH places** — `nitrosearch.php` (`$this->version`) and `config.xml`
   (`<version>`). The build refuses to run if they disagree, because PrestaShop reads them at
   different moments: `config.xml` on the module list, the class property after instantiation. When
   they differ the back office shows one number and the upgrade machinery uses the other, and
   nobody notices until an upgrade fails to run.
2. **Move the `## [Unreleased]` entries** in `CHANGELOG.md` into a dated section for the new version.
3. **Build:**

   ```bash
   ./bin/build-module.sh
   ```

   It lints every PHP file that ships, asserts the three names that must agree (directory,
   `$this->name`, and the class), stages an **allowlist** of shipping paths, regenerates the
   `index.php` stubs, and then verifies the **archive itself** — a single `nitrosearch/` root
   containing the main file and `config.xml`, and no `__MACOSX` entries.

4. **Install the built ZIP into a PrestaShop that has never seen the module.** This is not optional
   and it is not covered by any test: a working copy sitting in `modules/` has already put the files
   where they belong, so it cannot show you a packaging mistake. Uninstall, delete the directory,
   confirm no `ps_module` row and no `NITROSEARCH_*` configuration rows survive, then install from
   the archive.

5. **Tag and publish**, attaching the ZIP as the release asset:

   ```bash
   git tag -a v1.0.0 -m "v1.0.0"
   git push origin main --follow-tags
   gh release create v1.0.0 --title "v1.0.0" --notes-from-tag dist/nitrosearch-1.0.0.zip
   ```

   Run `gh release create` **inside this repo** — combined with `--repo` and `--notes-from-tag` it
   is an invalid flag pair, and it fails without publishing while the tag push still looks green.
   Verify with `gh release list`, never with a checkmark.
