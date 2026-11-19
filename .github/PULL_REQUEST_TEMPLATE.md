## What and why

<!-- What does this change, and why? Link the issue: "Fixes #123". -->

## How it was tested

<!-- Commands you ran (for example ./scripts/stc.sh test) and what you checked by hand. -->

## Checklist

- [ ] Every commit is signed off (`git commit -s`, see CONTRIBUTING.md)
- [ ] Tests added or updated, and `./scripts/stc.sh test` passes
- [ ] Docs and both languages (en, pt-BR) updated where behavior changed
- [ ] Still runs on shared hosting: no daemon, broker or `exec()`-family call
- [ ] `public/app/` is not part of the change

Accepted pull requests are applied upstream with `git am`, keeping your authorship, and reach this repository in the next release (see CONTRIBUTING.md).
