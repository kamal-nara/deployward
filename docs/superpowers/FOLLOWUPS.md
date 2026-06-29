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
