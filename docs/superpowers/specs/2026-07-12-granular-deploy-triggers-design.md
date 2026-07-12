# Granular deploy triggers (approved design)

Date: 2026-07-12. Status: user-specified (supersedes the single Auto deploy switch
from 2026-07-11-auto-deploy-controls-design.md).

## Problem

0.3.0 shipped a single Automatic switch that enables webhook AND polling together.
Users who only want webhook deploys get useless polling load (server + GitHub API
pressure); users who only want polling do not need the webhook active. The user asked
for granular control: Manual, webhook, polling, or both, chosen when adding/editing
a deployment.

Note: polling works for private repositories too (the poller passes the stored
encrypted token to the GitHub API), so the split is about choice and load, not
capability.

## Data model

Replace the `auto_deploy` boolean with two independent booleans on `Deployment`:
- `webhook_deploy` (bool, default false): deploy when GitHub pushes to the branch.
- `poll_deploy` (bool, default false): deploy when the scheduled check finds a new
  commit. `poll_interval` (5/15/30/60, default 5) stays and is meaningful only when
  this is on.
Manual = both false (still the default for new deployments).

Getters: `deploysOnPush(): bool`, `deploysOnSchedule(): bool`. Remove
`isAutoDeployEnabled()` and update all callers.

Migration in fromArray(): when NEITHER new key is present and a legacy truthy
`auto_deploy` key exists, set both new booleans true (0.3.0 "Automatic" rows keep
their behavior). Rows with no trigger keys at all stay Manual. toArray() emits the
two new keys and drops `auto_deploy`.

## Enforcement

- WebhookController: after the branch check, gate on `deploysOnPush()`; disabled
  response message: "webhook deploys are disabled for this deployment".
- CronPoller: gate on `deploysOnSchedule()` (replaces the isAutoDeployEnabled check);
  the per-interval due logic and last-polls map are unchanged.

## UI

- Add/Edit form: the Manual/Automatic segmented control is replaced by a
  "Deploy triggers" field with two checkboxes:
  - "Webhook: deploy instantly when GitHub pushes to the watched branch" (help:
    requires the one-time webhook setup from the deployment card).
  - "Scheduled check: look for new commits on a schedule and deploy them" (help:
    works without a webhook; the interval select below appears when checked).
  - Both unchecked = manual; short line under the field states that.
- Interval row visibility switches on a form class `is-poll` (same mechanism as
  is-private/is-auto before it).
- Card badge (dw-pill--mode): 'Manual' (muted) / 'Webhook' / 'Every 15 min' /
  'Webhook + every 15 min' (accent tint when any trigger is on).
- Getting Started: triggers section updated to describe the three choices.

## CLI

- `wp deployward add`: replace `[--auto-deploy]` with `[--webhook-deploy]` and
  `[--poll-deploy]`; `[--poll-interval=<minutes>]` stays. (0.3.0 is hours old with
  no external users; no CLI alias kept.)
- `wp deployward list` mode column: `manual` | `webhook` | `poll:15m` |
  `webhook+poll:15m`.

## Docs and version

README: rewrite the Automatic deploys section around the three trigger choices,
note webhook-only means zero polling load, and that polling supports private repos
via the stored token. Version 0.4.0.

## Testing

- Deployment: defaults (both off), each flag independently, legacy auto_deploy=true
  maps to both on, legacy absent stays manual, toArray keys, with* roundtrip.
- Webhook: push with webhook off (poll on) -> disabled message, never schedules;
  webhook on -> 202 queued.
- CronPoller: poll off (webhook on) -> skipped entirely, id pruned from map;
  poll on -> polled per interval (existing interval tests updated to the new key).
- RestController save roundtrip for both flags; DeployCommand flags + list modes.
- node --check admin.js. All 0.3.0-era tests referencing auto_deploy updated.
