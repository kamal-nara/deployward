# Deployward: deferred follow-ups (from Plan 1 reviews)

These are non-blocking findings raised during the per-task and final whole-branch
reviews of Plan 1 (the deploy engine). All were triaged as ship-as-follow-up: none
affects correctness, security, or data safety in v1. Pick them up in a cleanup pass
or alongside Plan 2/3.

## Tests / coverage
- `ResultTest`: assert `fail()->isSkipped() === false` and assert `skip()` message/data.
- `EncryptorTest`: add a bit-flipped-ciphertext tamper test (current test only hits the
  length guard, not the MAC-rejection path).
- `DeployLogTest`: assert the `message` column mapping in the record test.
- `BackupManager`: add a `restoreLatest()` no-prior-target (Path D) test.
- `GitHubClientTest`: replace `@unlink` with a `file_exists` guard (cosmetic).

## Robustness / hardening
- `Encryptor::fromSalts()`: guard each of `AUTH_KEY` / `AUTH_SALT` individually (today a
  single defined salt still yields a 32-byte key, just from less material).
- `Encryptor::decrypt()`: tighten the length guard to
  `< NONCEBYTES + MACBYTES` (currently `<= NONCEBYTES`; safe today because the MAC
  rejects short input).
- `BackupManager::backup()`: make the destination unique to avoid a same-second +
  same-sha collision (today it fails safe via a failed rename, no data loss).
- `BackupManager::prune()`: add an `if ($keep < 1) return;` guard (unreachable today;
  composition root hardcodes 3).
- `BackupManager::sortedBackups()`: sort by basename rather than full path (works today
  because the path prefix is constant).
- `DeploymentRepository::all()`: build the decrypted row via `array_merge` instead of
  `$row['token'] = ...`, matching `save()` and the immutability rule (local copy, no
  behavioral risk today).

## Autoloader / portability
- `Autoloader::register()`: dedupe on repeated registration; `rtrim` handles `/` only
  (Windows path assumption, not a v1 target).

## CLI / UX
- `DeployCommand::log`: require an id (today an empty id returns no rows).
- `DeployCommandTest`: drop the redundant explicit `Mockery::close()` (the
  `MockeryPHPUnitIntegration` trait already closes).
- `Plugin::makeCommand()`: pass `home_url()` (no trailing `/`) to the health URL, and
  reuse single `$log` / `$tmp` locals instead of constructing twice.

## Design note (recorded, not a fix)
- Maintenance mode is intentionally disabled before the post-deploy health check; see
  the spec section "Why maintenance mode is exited before the health check". The brief
  exposure window is inherent to in-place deploys and is the target of the future
  build/atomic-switch mode.

## Plan 3 (admin UI) follow-ups
- listBranches WP_Error/non-array-body tests.
- Assert create-path webhook secret is set and non-empty.
- Deploy 502 body: assert {status:failed} in response data.
- Log page=2 offset test (offset = 20 when page = 2).
- Lazy-construct REST controller inside rest_api_init to avoid eager engine graph per request.
- admin.js pill prevClass cleanup: strip modifier classes before storing, not the full className.
- buildToastEl vs inline-error surface: decide one pattern for form-level errors.
- Extract repeated 'public' literal into a named constant or config value.
- Container builds its own DeploymentRepository (stateless, fine as-is).

## Plan 2 (triggers) follow-ups

- Add a per-deployment deploy lock/dedupe (transient keyed by deployment id) so concurrent triggers (webhook + cron, or overlapping cron) cannot run two deploys of the same commit. Harmless today (identical code, atomic swap + backup), but worth hardening.
- SignatureVerifier: add a wrong-algorithm-prefix (sha1=) rejection test.
- CronPoller: add a private-visibility test asserting the token is passed to resolveSha.
- DeployScheduler: constructor is untyped ($container) for partial-mock testability; consider a ContainerInterface to restore the type hint.
- RestRoutesTest: tighten the webhook permission assertion to === '__return_true'; add a test that the webhook-info route is canManage-gated.
- admin.js webhook panel: associate each row <label> with its <input> (for/id) for screen readers; verify .dw-copy padding vs dw-btn in the browser.
- Webhook 202 response 'sha' echoes the pushed payload.after, not necessarily the SHA actually deployed (Deployer re-resolves head async). Cosmetic response field; document it.

## Post-v1 fix follow-ups (repo-URL normalize + optional slug)

- DONE (commit 9f0ff13): accept full GitHub URLs (normalize to owner/repo) + optional slug.
- DONE (follow-up): `normalizeRepo()` now extracts owner/repo from deep URLs
  (`/tree/<branch>`, `/blob/...`, `?query`), and `branches()` returns a 422 with a clear
  message for input that is not owner/repo (instead of a generic 502 from GitHub).
- `deriveSlug()` returns a clear "invalid target_slug" error for pathological repo names
  (e.g. `___`, `--`) instead of a usable slug. Acceptable fail-safe; revisit only if real.
- Derived slugs preserve uppercase (e.g. `My-Plugin`). WP dirs are usually lowercase;
  consider lowercasing the derived slug if it ever causes a mismatch. Low priority.

## Field bug: WP Engine cross-filesystem deploy (fixed in 0.2.1)
- Root cause: extraction happened in get_temp_dir() (local /tmp) and the directory was
  rename()d into wp-content (NAS mount on WPE); PHP cannot rename directories across
  filesystems (EXDEV). Fixed: extraction now works under uploads and every directory
  move goes through DirectoryMover (rename with recursive copy+delete fallback), the
  same guarantee core's upgrader provides.
- Remaining hardening (not blocking):
  - `Plugin::boot()` builds the full Deployer graph on every request via the REST
    controller wiring. During a non-atomic code update (partial file sync, mid-edit
    on a live box) a constructor signature mismatch fatals EVERY request. Build the
    engine lazily (inside rest_api_init callbacks / cron / CLI paths only). This
    subsumes the earlier "lazy-construct REST controller" item.
  - DirectoryMover merges into an existing non-empty destination on the copy path.
    Unreachable in current flows (backup/set-aside always clears the destination
    first) but worth an explicit guard if the mover is ever reused elsewhere.
  - Health check hits the home URL only; a fatal confined to wp-admin or a specific
    template can still pass. Consider an authenticated-less admin-ajax ping as a
    second probe.
