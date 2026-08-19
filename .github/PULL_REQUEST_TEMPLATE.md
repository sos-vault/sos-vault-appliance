## Summary

What this PR changes, in 1–3 bullet points. Focus on the **why** — the diff already shows the what.

## Test plan

- [ ] `php artisan test --compact` passes locally.
- [ ] If you touched any file under `app/Filament/`, you exercised the change in a browser before opening the PR.
- [ ] If you added user-visible strings, all four language files (`lang/{en,es,ja,de}/*.php`) have been updated.
- [ ] If you added or changed dependencies, `composer.lock` / `package-lock.json` are committed.

## Related issues

Closes #
Refs #

## Notes for reviewers

Anything reviewers should know that is not obvious from the diff: tricky migrations, performance hot paths, areas you would like a second opinion on, etc.

## Pre-flight

- [ ] `vendor/bin/pint --dirty --format agent` produced no diff.
- [ ] Commits are signed off (`git commit -s`).
- [ ] PR title is short (under 70 chars).
