# Church Plugin Deployer v0.1.0 — staging only

A **separate** plugin using WordPress 6.9+ Abilities API and the same authenticated MCP Server for WordPress connection as Church Site Controls.

It replaces our repeated manual ZIP uploads with a **two-step, explicitly approved** deployment of GitHub Actions plugin artifacts. It does not update itself, activate an inactive plugin, alter WP STAGING, or touch production.

## Capabilities

- `church-plugin-deployer/inspect` — read-only installed versions, active status, and whether prerequisites are configured. No secrets or server paths returned.
- `church-plugin-deployer/prepare` — choose **only** one of `church-site-controls`, `parish-forms`, `bulletin-publisher` and a successful GitHub Actions `run_id`; check repository/run/workflow/artifact provenance, retrieve the ZIP, verify plugin folder, header, version and archive paths, then create a **private backup ZIP** of the installed plugin. Returns a 15-minute, administrator-bound, single-use `approval_token`. Does not install anything.
- `church-plugin-deployer/deploy` — explicitly invoke with that one-time token *after the operator agrees*. Recheck package and backup SHA-256, installed version/active state, and WordPress filesystem method, then use **WordPress's native `Plugin_Upgrader::install(..., overwrite_package=true)`**. Verify the installed version and preserve the existing plugin's active state; keep backup; record an audit. Uses an exclusive lock, refuses overlap, and never accepts arbitrary URLs, repository names, paths, ZIP bytes, or plugin slugs.

**Authorization:** `manage_options`, `install_plugins`, and `update_plugins` on the designated `https://stpeterpr.org/design-demo` installation; multisite refused. Add only the three Administrator abilities to the MCP agent. The `deploy` ability is flagged as destructive. Don't grant other roles. The program does not implement ChatGPT-side confirmation UI on its own: the human should explicitly authorize each use of `deploy`.

## One-time server setup

1. From the main plugin repository's successful CI run, download the **church-plugin-deployer** artifact, extract its inner `church-plugin-deployer-0.1.0.zip`, and manually install/activate it on `/design-demo` only. This is the final manual installation needed for the intended workflow **if the site supports direct WordPress filesystem upgrades**. Leave the existing plugins, WP STAGING state, and staff login access alone.
2. Create a **fine-grained GitHub Personal Access Token** belonging to a trusted owner with access only to private repository `peclark1/bulletin-upload-wordpress-plugin`. Grant **Actions: Read-only** and the minimum automatic Metadata read permission. Avoid repository-write/admin scopes. Prefer expiration and planned rotation. **Do not paste the token in ChatGPT, GitHub issues, comments, logs, or MCP responses.** A GitHub App installation credential can replace this later.
3. Store the credential in a *server-side* environment variable or directly in a **protected wp-config.php** `define('CPD_GITHUB_TOKEN', getenv('CPD_GITHUB_TOKEN'));`. If env configuration is unavailable, an administrator can securely set the constant to the token in wp-config.php, never in plugin files committed to GitHub.
4. Strongly recommended: create a writable **private directory outside all web-accessible document roots**, and set `define('CPD_PRIVATE_DIR','/private/absolute/server/path');` in wp-config.php. The plugin rejects the known public document roots and otherwise uses WordPress's system temp directory. The folder holds staged ZIPs, **backup copies of executable plugins** and metadata; it must not be web accessible.
5. Run `inspect`: ensure the token and safe temp directory are ready, and the three target plugins have the expected filenames and active status. If the site uses FTP credentials rather than WordPress's `direct` filesystem method, `deploy` stops and manual upgrades remain necessary.

## The standard workflow

1. Commit code, run the full test suite and await the successful **Build WordPress plugin ZIPs** workflow. Record the GitHub Actions **run ID**.
2. Call `prepare({plugin: 'church-site-controls', run_id: ...})`; read proposed version, commit SHA, package hash, backup hash/size and expiration. It only accepts `main` or branches under `feature/` or `fix/` from the **same private repository** with a successful approved `.github/workflows/build-test.yml` run.
3. **Operator explicitly approves** that specific version and target. Only then call `deploy({approval_token: ...})`.
4. Verify the installed version through the target plugin's own MCP ability, the WordPress plugin list, and site behavior. Test undo for visual/content edits where applicable.
5. Preserve the server-side recovery ZIP. If installation or runtime verification fails, stop and use a hosting administrator or WP-CLI to restore the backup. The **v0.1 deployer does not have an automatic rollback ability**; a backup alone does not prove a restoration drill. Do not test rollback by restoring the site without an explicit recovery requirement.

## Limitations and safety

- **Only the three existing site plugins can be upgraded**. The deployer itself is excluded.
- **No production install, self-update, or new-plugin installation**. It only replaces an already-installed and active approved plugin.
- ZIP content validation rejects path traversal, symlinks, unexpected directory structures, excess entries and oversized decompressed packages. GitHub download redirects are explicitly allowlisted and the server's GitHub token is never forwarded to signed artifact storage.
- The private backup is a plugin-code snapshot, **not a WordPress database backup**. The existing Oct 14 validated WP STAGING backup remains our separate database/site restore point, and WP STAGING remains intentionally disabled for staff access.
- Successful CI in a trusted repository establishes expected test/provenance context, **not cryptographic signing independent of repository maintainers**. Only trusted users with write access to the repo should be able to land source/workflow changes.
- `wp_remote_get` GitHub access and `ZipArchive` must work from HostGator. The site may block outbound artifact storage domains; `prepare` must fail safely and report a clear blocker, not fall back to arbitrary downloads.
- The WordPress native install operation can disrupt a plugin if the upgrade fails; the private plugin snapshot is for manual recovery. Do not present this as zero-risk or automatic rollback.
- A PHP fatal can bypass finally/lock cleanup; review the durable `cpd_deploy_lock` option before any manual stale-lock removal.
- Backup archives in system temp can be purged by hosting cleanup; configure private durable storage if long-term recovery copies are needed.

## Development / checks

```sh
make test-church-plugin-deployer
make church-plugin-deployer
make plugins
```

CI additionally runs the existing Church Site Controls, Parish Forms and Bulletin Publisher suites.

**Staging acceptance sequence:** install v0.1.0 manually, grant only the Administrator abilities, call `inspect`, attempt `prepare` for one known successful run, verify backup creation and identity, approve one deliberately small future plugin version update, then inspect, deploy, and independently verify that upgrade. Do not consider the install pathway proven until those live steps succeed.
