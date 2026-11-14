---
name: Feature request
about: Suggest a new capability for the sos-vault appliance
title: '[feature] '
labels: enhancement
assignees: ''
---

## The problem you are trying to solve

What workflow are you blocked on, slowed down by, or unable to express today?

Bias toward describing the user-facing pain rather than proposing a specific implementation — that lets us consider alternatives you might not have thought of.

## Who else hits this

If you can, point to other teams or operators who would benefit. "Just me" is fine; "five of my customers asked for this last month" is a stronger signal.

## What you have in mind

If you have a concrete proposal, sketch it. Otherwise leave this section blank — we will come back with options.

## Where it belongs

The sos-vault project distinguishes between the open-core baseline (free, single-admin) and the licensed Pro tier (multi-user, groups, modules, ITSM, encrypted vaults, event log). Where do you think this feature fits?

- [ ] Open-core baseline (everyone gets it)
- [ ] Licensed Pro tier (gated behind a license)
- [ ] Not sure — we will help decide

See [`docs/FEATURES.md`](../../docs/FEATURES.md) for the current boundary.

## Out of scope

- Vendor lock-in features ("must integrate with cloud X exclusively").
- Telemetry / phone-home capabilities — sos-vault is intentionally air-gappable.
- Features whose only purpose is to displace the licensed Pro tier (we are open about the open-core boundary).
