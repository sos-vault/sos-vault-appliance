# Security policy

## Reporting a vulnerability

Please report security issues privately to **security@sos-vault.com**. Do not open public GitHub issues for unpatched vulnerabilities.

We aim to acknowledge new reports within **3 business days** and to ship a fix or a documented mitigation within **30 days** for high-severity issues.

When reporting, please include:

- A description of the vulnerability and the impact you observed.
- Steps to reproduce (a minimal proof of concept is ideal).
- Affected version(s) — the output of `php artisan about` or the git commit hash you tested.
- Whether you would like public credit when the fix lands; if so, the name / handle to credit.

## Supported versions

Security fixes land on the `main` branch of the public open-core repository. We backport critical fixes to the most recent tagged release; older releases are not patched.

## Disclosure timeline

1. Reporter sends a detailed report to `security@sos-vault.com`.
2. Maintainers acknowledge receipt and begin investigation.
3. Once a fix is ready, we coordinate a release date with the reporter.
4. Fix is published with a CVE (when applicable) and the reporter is credited in the release notes unless they have asked to remain anonymous.

We follow a **90-day default disclosure window** from initial report to public advisory. Extensions are negotiable for issues that are unusually hard to fix.

## What is in scope

The sos-vault application code in this repository: the appliance build, the LUKS / GPG helpers under `sysadmin/`, the licensing pipeline, the customer portal where applicable.

## What is out of scope

- Vulnerabilities in third-party dependencies (please report those upstream and let us know so we can update).
- Issues that require physical access to the host or root-level access to the appliance OS (those are operator hardening concerns, not application bugs).
- Social-engineering attacks against project maintainers or community members.
- Brute-force or denial-of-service attacks that do not exploit a specific code defect.

## PGP

If you would like to encrypt your report, our security PGP key is published at `https://sos-vault.com/.well-known/security.txt`.
