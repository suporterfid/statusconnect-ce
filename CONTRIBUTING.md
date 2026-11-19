# Contributing to StatusConnect Community Edition

Thank you for helping. This page explains how this repository works, what a good pull request looks like, and the sign-off every commit needs.

## How this repository works

StatusConnect is developed upstream, and this repository receives a **snapshot of each release** (one commit per version, `Release vX.Y.Z`). Contributions still happen here, in the open:

1. You open an issue or a pull request in this repository.
2. A maintainer reviews it here.
3. When it is accepted, the maintainer applies your commits upstream with `git am`, which keeps you as the author and keeps your `Signed-off-by` line, and closes the pull request with a link to the change.
4. Your change reaches this repository in the next release, and the release notes credit it.

So your pull request is not merged with the GitHub button, but your authorship is preserved in the code history and the release notes.

## Before you start

- **Questions and ideas**: [Discussions](https://github.com/suporterfid/statusconnect-ce/discussions) (Q&A category for questions).
- **Bugs**: an [issue](https://github.com/suporterfid/statusconnect-ce/issues/new/choose) with steps to reproduce.
- **Larger changes**: open an issue or a discussion first, so we can agree on the approach before you write the code.
- **Security problems**: never in a public issue. See [SECURITY.md](SECURITY.md).

## Ground rules of the code

StatusConnect must keep running on commodity shared hosting. Changes that break any of these are not accepted:

- PHP 8.2+, MySQL 8.0+, a one-minute cron and a document root at `public/` are all the server offers.
- No always-on process, daemon or broker: no Redis, Memcached, queue workers, Horizon, Octane, Reverb or supervisor. `QUEUE_CONNECTION=sync`, file or database cache and sessions.
- No `exec()`, `shell_exec()`, `proc_open()` or `popen()` at runtime.
- Outbound requests to monitored targets go through the DNS-pinned, SSRF-protected HTTP client.
- Keep the layering: `app/Domain` (no framework), `app/Application` (use cases, transactions), `app/Infrastructure` (Eloquent models live in `app/Infrastructure/Persistence/Eloquent/`), `app/Http` (thin controllers).

## Development setup

Everything runs in Docker; do not install PHP, Composer or Node on your machine for this project.

```bash
./scripts/stc.sh up
./scripts/stc.sh bootstrap
./scripts/stc.sh test                 # the PHPUnit suite
./scripts/stc.sh npm run build        # the operator app
```

On Windows use `.\scripts\stc.ps1` with the same verbs.

## Pull requests

- One topic per pull request, based on `main`.
- Add or update tests. `./scripts/stc.sh test` must pass; the **Tests** workflow runs it on every pull request.
- Update the documentation in `docs/` and the user-facing strings in both languages (`lang/en`, `lang/pt_BR`, `frontend/src/i18n/en.json`, `frontend/src/i18n/pt-BR.json`) when behavior changes.
- Do not commit `public/app/`: it is built for each release.
- Keep commits focused and write clear messages (what changed and why).
- Every commit carries a DCO sign-off (below).

## Developer Certificate of Origin (DCO)

Every commit must be signed off. The sign-off certifies that you wrote the change or have the right to submit it under the project's MIT licence, as stated in the [Developer Certificate of Origin 1.1](https://developercertificate.org/):

```
Developer Certificate of Origin
Version 1.1

Copyright (C) 2004, 2006 The Linux Foundation and its contributors.

Everyone is permitted to copy and distribute verbatim copies of this
license document, but changing it is not allowed.


Developer's Certificate of Origin 1.1

By making a contribution to this project, I certify that:

(a) The contribution was created in whole or in part by me and I
    have the right to submit it under the open source license
    indicated in the file; or

(b) The contribution is based upon previous work that, to the best
    of my knowledge, is covered under an appropriate open source
    license and I have the right under that license to submit that
    work with modifications, whether created in whole or in part
    by me, under the same open source license (unless I am
    permitted to submit under a different license), as indicated
    in the file; or

(c) The contribution was provided directly to me by some other
    person who certified (a), (b) or (c) and I have not modified
    it.

(d) I understand and agree that this project and the contribution
    are public and that a record of the contribution (including all
    personal information I submit with it, including my sign-off) is
    maintained indefinitely and may be redistributed consistent with
    this project or the open source license(s) involved.
```

Sign off by adding a line with your real name and email to the commit message:

```
Signed-off-by: Your Name <you@example.com>
```

`git commit -s` adds it for you. To sign off commits you already made: `git rebase --signoff main`, then force-push your branch. The **DCO** check of the Tests workflow fails when a commit of the pull request has no sign-off.

## Licence

By contributing you agree that your contribution is licensed under the [MIT licence](LICENSE) of this project.
