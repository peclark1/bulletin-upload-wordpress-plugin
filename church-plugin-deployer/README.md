# Church Plugin Deployer v0.2.0 — Staging-only GitHub updates with WordPress confirmation

This is a **separate WordPress plugin** using WordPress 6.9+ Abilities API and the existing MCP Server for WordPress connection. It streamlines our own versioned plugin upgrades on `https://stpeterpr.org/design-demo`, without granting the assistant executable-code installation privileges.

## Workflow

1. ChatGPT codes a reviewed change in `peclark1/bulletin-upload-wordpress-plugin`, runs CI, and identifies the completed successful **Build WordPress plugin ZIPs** workflow run ID.
2. The authenticated MCP `church-plugin-deployer/prepare` ability verifies the workflow source, build result, plugin ZIP folder/header/version/path safety, and downloads its GitHub Actions artifact using a read-only **server-side** credential.
3. Preparation creates a private recovery ZIP of the already-installed plugin (SHA-256 verified) and records an **unexpired, 15-minute, owner-bound** prepared update. It **never installs**.
4. A WordPress administrator visits **Tools → Church Plugin Deployer** on the staging site. The page shows only preparations belonging to that same administrator, with plugin name, from/to versions, run/commit, package and recovery SHA-256 excerpts, and remaining validity.
5. A click on **Install Prepared Update** submits an **authenticated POST with a WordPress nonce scoped to the prepared record**. All staging/administrator, TTL, currently-installed version, target allowlist, package and backup SHA-256, exclusive lock and audit checks run again before native `Plugin_Upgrader` replaces files.
6. WordPress displays success or a safe error. ChatGPT independently inspects the installed plugin/version and website behavior through existing read-only abilities.

There is **no custom update server, scheduled deployment, arbitrary URL, GitHub credentials in Chat, or background installation**.

## MCP abilities

- `church-plugin-deployer/inspect`: read-only plugin versions and prerequisites
- `church-plugin-deployer/prepare`: authenticated source validation and private pre-update snapshot, **no installation**
- The former v0.1 `church-plugin-deployer/deploy` **is no longer registered through MCP** in v0.2. The installation must be initiated inside WordPress by the Administrator. A shared internal backend method continues the same validation path for all installations.

Do not change the MCP permissions for other church plugins or activate WP STAGING.

## Supported plugins

| Slug | Existing WordPress directory |
| --- | --- |
| `church-site-controls` | `church-site-controls/` |
| `parish-forms` | `parish-forms/` |
| `bulletin-publisher` | `bulletin-upload-wordpress-plugin/` |

No self-update, new-plugin installation, WP STAGING, unrelated plugins, themes, database migration or production-site deployment.

## Initial installation / update from v0.1

- Download the CI artifact for `church-plugin-deployer` from the successful PR run, extracting the **inner WordPress plugin ZIP**. The installer file is `church-plugin-deployer-0.2.0.zip`.
- Manually upload and **replace v0.1.0 with v0.2.0 once** in **staging** `/design-demo/wp-admin` → Plugins → Add New → Upload Plugin.
- Existing private deployment configuration `CPD_PRIVATE_DIR` and `CPD_GITHUB_TOKEN` remain unchanged, stored securely on HostGator. Never paste the token into ChatGPT or write it into GitHub.
- After installation, verify **Tools → Church Plugin Deployer** appears for the Administrator; independently verify `inspect` reports version `0.2.0` and `prepare` remains available, while the MCP `deploy` ability is absent.
- The old `v0.3.2` prepare-only candidate may have expired. Re-run `prepare` against successful candidate workflow run `38905794069` (Church Site Controls v0.3.2, **version-only/no behavior change**, see draft PR #45) to create a new 15-minute record.
- Open **Tools → Church Plugin Deployer** under the **same WordPress administrator account used for MCP preparation**. Check the details, then approve by clicking Install Prepared Update. Ask ChatGPT to independently check installed v0.3.2, menu behavior and WordPress audit status afterward.

## Safeguards and failure handling

- Hardcoded `/design-demo` `home_url` and `site_url` guard, Administrator `manage_options`, `install_plugins`, `update_plugins` checks, no multisite.
- Only successful builds from the allowlisted private repository/workflow and `main` or `feature/`/`fix/` branches; only approved plugin ZIP folders, header/version, entry counts and symlink/path checks; disallow stale/downgrade/same-version updates.
- Configured server-only GitHub token has **Actions: Read-only** on this repository; signed artifact storage receives no GitHub Authorization header.
- Plugin packages and recovery ZIPs stay **outside all public website document roots**, with filesystem permissions and SHA-256 verification. Recovery ZIP is code-only; existing Oct 14 full-site staging backup remains the separate database/content recovery path.
- Only unexpired preparation records for the **logged-in preparer** show up, and install requires a POST+scoped nonce (no GET deployment).
- A single-use record, lock, and durable minimal audit protect against replay and overlapping updates.
- If deployment fails or plugin PHP fatals, **there is no automatic rollback**. Stop, preserve the backup, use HostGator file access/WP-CLI to restore previous files, and verify the site. Fatal errors can leave stale locks; inspect before clearing.
- The full site may briefly be affected while plugin code changes. Do not promise zero downtime or execute without operator approval.
- Browser access to `tools.php?page=church-plugin-deployer` is authenticated. Non-Administrator users and production never see the management menu.
- The UI is intentionally small and uses native WordPress styles. No new theme styling or menu changes are involved.

## Development and test

```sh
make test-church-plugin-deployer
make church-plugin-deployer
make plugins
```

The offline PHP tests simulate permission, production guard, GitHub run/artifact verification, ZIP identity and snapshot, nonce and method restrictions, other-admin isolation, expiry, stale version, audited install, replay prevention and MCP's lack of direct deploy. GitHub Actions additionally runs PHP lint and the existing Church Site Controls / Parish Forms / Bulletin Publisher suites.

**Caution:** passing offline tests does not prove the administrator button can replace plugins on HostGator. Treat the first user-approved staging update as an acceptance test, and inspect the installed version and site behavior independently afterward.
