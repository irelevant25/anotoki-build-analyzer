# Build analyzer: frontend

Angular 21 + Angular Material. See [DEVELOPING.md](../DEVELOPING.md) for setting up, running, testing and
deploying, and [docs/architecture.md](../docs/architecture.md) ("Frontend") for how it is organised.

```bash
npm ci                     # npm 10.9.x has a resolver bug here; `npx -y npm@11 ci` works
npm start                  # http://localhost:4600 (proxies /api and /setup.php to http://127.0.0.1:8400)
npx ng test --watch=false  # unit tests (Vitest)
npx ng build               # production build into dist/frontend/browser (scripts/build-dist.php uses it)
```

Sign-in goes through the anotoki IAM's browser SDK, vendored unchanged in `src/app/core/iam/`
(`anotoki-auth.mjs` + `anotoki-auth.d.ts`, v1.1.1): copy both files from `anotoki-iam/sdk/js/` to update it.

The version shown in the top bar and its "What's new" come from `public/changelog.json`; a new release adds
an entry at the top (and the same version in `backend/src/Version.php`).
