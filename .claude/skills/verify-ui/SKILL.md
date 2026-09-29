---
name: verify-ui
description: Start the build analyzer (PHP API + Angular dev server, sign-in switched off) and check pages visually with headless Edge screenshots, then shut everything down. Use after any change to Angular templates, styles or API responses the UI renders, before saying a UI change works, when a page renders blank or looks broken, or when the user asks to see, check or screenshot the app.
---

# Verify the UI

Type checks and unit tests don't show overflowing columns, empty panels or a stale error overlay; a
screenshot does. The bundled `scripts/screenshot.sh` drives the installed Edge headlessly with a throwaway
profile, so it never touches the user's browser.

Screenshots need **sign-in switched off**: the headless browser cannot sign in at the anotoki IAM. In that
mode (`'mode' => 'none'` in `backend/config/auth.local.php`, what `setup.php` writes for "Switched off") every
request from the app's own pages on this computer is the owner (`local_user_id`) with every role, so every
page - the admin-only Sources page too - opens without a token, and the toolbar shows no sign-in controls. If
this copy signs in through the IAM (`"mode":"iam"` in `/api/meta`), ask the user before switching it off with
`setup.php` (and switch it back afterwards), or limit yourself to the signed-out views (below).

## 1. Build first

```bash
(cd frontend && npx ng build)      # compile errors are clearer here than in the dev server overlay
```

## 2. Start the servers in the background

Use `run_in_background` for both, then wait until they answer:

```bash
cd backend && php -S 127.0.0.1:8400 router.php
cd frontend && npx ng serve                       # http://localhost:4600, proxies /api and /setup.php to :8400
```

```bash
for i in $(seq 1 40); do curl -s -o /dev/null http://localhost:4600/ && curl -s -o /dev/null http://127.0.0.1:8400/api/meta && break; sleep 2; done
curl -s http://127.0.0.1:8400/api/meta | grep -o '"auth":{[^}]*}'    # "mode":"none" for the full tour
```

A 503 with `"code":"not_set_up"` means this copy has no configuration yet: the app shows its "Not set up yet"
page (worth a screenshot of its own), and `setup.php` sets it up - ask the user for the database first.

Ports 8400 / 4600 taken (another session)? Use others, e.g. 8401 / 4601, with a proxy file outside the repo:
`echo '{"/api":{"target":"http://127.0.0.1:8401","secure":false},"/setup.php":{"target":"http://127.0.0.1:8401","secure":false}}' > "$TMPDIR/proxy.json"`,
then `php -S 127.0.0.1:8401 router.php` and `npx ng serve --port 4601 --proxy-config "$TMPDIR/proxy.json"`.

Pages need data. Check `curl -s http://127.0.0.1:8400/api/accounts` (in "none" mode a request from this
computer that no other site made - curl included - is the owner's); if empty and the page under test needs an
account, import one (the user's UID, or a sample file with
`cd backend && php import-account.php <game> <file>`, which imports for `local_user_id`) and remember to delete
sample accounts afterwards. Builds are usually already synced.

## 3. Screenshot and look

```bash
S=.claude/skills/verify-ui/scripts/screenshot.sh
OUT="${TMPDIR:-/tmp}/build-analyzer-shots"; mkdir -p "$OUT"
$S http://localhost:4600/accounts/1/characters/hutao "$OUT/hutao.png" 1400 2600
$S "http://localhost:4600/import?game=hsr" "$OUT/wizard.png" 1400 1100
```

Open each PNG with the Read tool (`cygpath -w` gives the Windows path) and actually inspect it: layout at
the chosen width, overflowing text, empty sections, wrong numbers, and that nothing names another website,
service or tool (sources are "Source 1", "Source 2" …). Routes: `/`, `/import`, `/accounts/{id}`,
`/accounts/{id}/characters/{key}`, `/builds/{genshin|hsr}`, `/builds/{game}/{key}`, `/sources`, `/sign-in`.

The first page a fresh browser profile opens shows "What's new" (the changelog of the current version) once;
`screenshot.sh` keeps its profile between runs, so capture any page once and the next captures are clean.
`viewport.cjs` marks the current version as seen by itself.

**Phone widths**: `screenshot.sh` cannot show them - headless Edge keeps a minimum window width of about 500 px
and only crops narrower captures, so a 420 px shot shows a cut-off 500 px layout. Use `viewport.cjs` (a true
viewport through playwright-core and the installed Edge; installs nothing into the repo):

```bash
npm i --prefix "$TMPDIR/pw" playwright-core      # once
NODE_PATH="$TMPDIR/pw/node_modules" node .claude/skills/verify-ui/scripts/viewport.cjs http://localhost:4600/sources "$OUT/sources-390.png" 390
```

It also prints the page's scroll width; more than the viewport means a horizontal page scroll. With
playwright-core, states behind sign-in can be checked without the IAM: `context.route('**/api/meta', …)` to
answer `auth.mode: "iam"` (signed out: guards go to `/sign-in`), plus an `addInitScript` that stores an SDK
session under `localStorage['anotoki-auth:build-analyzer']` (`{access_token, access_expires_at, refresh_token,
user: {sub, preferred_username, roles: ['MEMBER']}}`) for the member view; the "none"-mode backend ignores the
token. Never let such a script press Sync or Resume against real sources.

Known artifacts that are *not* bugs:

- Blank page or missing icons: the capture fired before data arrived. Retry with a larger time budget
  (5th argument in ms, e.g. `$S URL out.png 1400 1600 45000`); `--dom` mode proves whether content rendered.
- Empty boxes instead of character/weapon icons: lazy images not loaded yet, or `/api/icons/…` fetching an
  image for the first time.
- A red Vite error overlay right after editing: often a stale intermediate error. Re-run `ng build`; if it
  passes, restart `ng serve` and capture again.
- The Sources page never starts a sync by itself: a sync runs only after Sync / Resume is pressed, and a
  screenshot must not trigger one (it would fetch live sites).

For runtime errors, dump the DOM and console:

```bash
$S --dom http://localhost:4600/sources "$OUT/sources.html"     # prints CONSOLE / Uncaught lines
```

## 4. Shut down

Stop both background tasks. `ng serve` can leave an orphan `node` on port 4600; free the ports so the next
session starts clean:

```powershell
Get-NetTCPConnection -State Listen -LocalPort 4600,8400 -ErrorAction SilentlyContinue |
  ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
```

Check the command line first (`Get-CimInstance Win32_Process -Filter "ProcessId = <pid>"`) if anything else
could be using those ports. Tell the user what you checked and attach nothing they didn't ask for.
