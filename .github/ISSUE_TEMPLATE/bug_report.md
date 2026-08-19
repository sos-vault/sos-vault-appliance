---
name: Bug report
about: Report a defect in the sos-vault appliance
title: '[bug] '
labels: bug
assignees: ''
---

## What happened

A clear, concise description of the bug.

## What you expected to happen

What you thought would happen instead.

## Reproduction

Steps to reproduce the behaviour. The more specific, the better:

1. Sign in as `<role>`.
2. Navigate to `<path>`.
3. Click `<button>`.
4. Observe `<problem>`.

If the bug only reproduces with a specific sosreport or vault state, attach a minimal example (with secrets redacted) or describe the shape of the data.

## Environment

- sos-vault version (from `php artisan about` or the git commit hash): `<version>`
- PHP version: `<version>`
- Host OS: `<distro and version>`
- Install method: `<deb / rpm / Docker Compose / from source>`
- License state: `<open-core baseline / licensed / expired>`

## Logs

Relevant excerpts from `storage/logs/laravel.log` or `docker compose logs sos-vault`. Wrap in code fences.

```
paste log lines here
```

## Anything else

Screenshots, related issues, workarounds you tried, etc.

---

### For security vulnerabilities

Please do NOT open a public issue. Email `security@sos-vault.com`. See [`SECURITY.md`](../../SECURITY.md).
