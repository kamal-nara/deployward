# Auto-deploy controls (approved design)

Date: 2026-07-11. Status: approved by Kamal (options chosen via structured questions).

## Problem

Adding a deployment silently enables auto-deploy: the 5-minute poller deploys any new
commit on the watched branch even if the user only wanted the manual Deploy button.
There is no way to turn this off and no UI indication that it is happening. There is
also no control over the polling interval.

## Decisions (user-selected)

1. A single per-deployment "Auto deploy" switch gates EVERYTHING: when off, the poller
   skips the deployment and the webhook responds without deploying. Off means strictly
   manual.
2. Default for new deployments: OFF.
3. Migration: existing deployments (saved before this version) load as OFF.
4. Polling interval is PER DEPLOYMENT: 5, 15, 30, or 60 minutes.

## Data model

`Deployment` gains:
- `auto_deploy` bool, default false. Truthy inputs accepted (true, 'true', '1', 1)
  via FILTER_VALIDATE_BOOLEAN.
- `poll_interval` int minutes, allowed {5, 15, 30, 60}, default 5. Invalid values
  throw InvalidArgumentException (consistent with existing validation style).
Both appear in toArray(); withToken()/withLastDeployedSha() preserve them via the
existing fromArray(toArray()) roundtrip. Missing keys on old rows produce the
defaults; no data rewrite needed.

## Enforcement

- WebhookController::handle order: found -> signature -> ping -> non-push -> branch ->
  NEW auto gate -> queue. The auto gate returns
  `200 {"message": "auto deploy is disabled for this deployment"}` and never calls the
  scheduler. Ping still pongs; invalid signatures still 401.
- CronPoller: a single master tick stays scheduled every 5 minutes (no dynamic cron
  schedules). Per tick: skip deployments with auto off (zero GitHub calls for them);
  for enabled ones, poll only when due per their own interval. Due-ness uses a
  last-polled-at map stored in option `deployward_last_polls` (id => unix ts,
  autoload no), with a 30 second grace so cron jitter cannot skip an interval.
  Stale ids (deleted deployments) are pruned from the map when it is written.

## UI

- Add/Edit form: segmented control "Manual / Automatic" (reuses the visibility
  segmented pattern), default Manual. Help text: "When on, new commits on the watched
  branch deploy automatically: instantly via webhook, or checked every N minutes.
  When off, nothing deploys until you click Deploy now." An interval select
  (Every 5/15/30/60 minutes) is visible only when Automatic is selected (form class
  toggle, same mechanism as the private-token row).
- Deployment card: a mode badge next to the status pill: "Auto - every 15 min"
  (accent tint) or "Manual" (muted). State is visible at a glance.
- Getting Started: new short section stating auto deploy is off by default and how
  to enable it.

## CLI

- `wp deployward add` gains `[--auto-deploy]` and `[--poll-interval=<minutes>]`.
- `wp deployward list` appends the mode per row: `auto:15m` or `manual`.

## Docs and version

- README: correct the polling section (off by default, per-deployment switch and
  interval); mention the badge and CLI flags.
- Version 0.3.0.

## Testing

- Deployment: defaults (missing keys -> off/5), truthy parsing, invalid interval
  throws, roundtrip preservation.
- CronPoller: disabled deployments never hit GitHub; enabled+due polled; enabled but
  not-yet-due skipped; timestamps recorded; interval respected (time mocked via
  patchwork, precedent in DeploySchedulerTest).
- Webhook: auto-off push returns the disabled message and never schedules; existing
  fixtures updated to auto_deploy=true where queuing is asserted.
- RestController: save roundtrip persists both fields. DeployCommand: new flags work.
- admin.js: node --check.
