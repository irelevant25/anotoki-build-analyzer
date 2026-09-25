---
name: verify-ui
description: Start hoyo-analyzer (Laravel API + Angular dev server) and check pages visually with headless Edge screenshots, then shut everything down. Use after any change to Angular templates, styles or API responses the UI renders, before saying a UI change works, when a page renders blank or looks broken, or when the user asks to see, check or screenshot the app.
---

# Verify the UI

Type checks and unit tests don't show overflowing columns, empty panels or a stale error overlay; a
screenshot does. The bundled `scripts/screenshot.sh` drives the installed Edge headlessly with a throwaway
profile, so it never touches the user's browser.

## 1. Build first

```bash
(cd frontend && npx ng build)      # compile errors are clearer here than in the dev server overlay
```

## 2. Start the servers in the background

Use `run_in_background` for both, then wait until they answer:

```bash
scripts/artisan.sh serve --host=127.0.0.1 --port=8000
(cd frontend && npx ng serve --host 127.0.0.1 --port 4200)
```

```bash
for i in $(seq 1 40); do curl -s -o /dev/null http://127.0.0.1:4200/ && curl -s -o /dev/null http://127.0.0.1:8000/api/meta && break; sleep 2; done
```

Pages need data. Check `curl -s http://127.0.0.1:8000/api/accounts`; if empty and the page under test needs an
account, import one (the user's UID or a sample file via `scripts/artisan.sh accounts:import`) and remember to
delete sample accounts afterwards. Builds are usually already synced.

## 3. Screenshot and look

```bash
S=.claude/skills/verify-ui/scripts/screenshot.sh
OUT="${TMPDIR:-/tmp}/hoyo-shots"; mkdir -p "$OUT"
$S http://127.0.0.1:4200/accounts/1/characters/hutao "$OUT/hutao.png" 1400 2600
$S http://127.0.0.1:4200/import?game=hsr "$OUT/wizard.png" 1400 1100
```

Open each PNG with the Read tool (`cygpath -w` gives the Windows path) and actually inspect it: layout at
the chosen width, overflowing text, empty sections, wrong numbers. Try a narrow width (e.g. 420) for
responsive changes. Routes: `/`, `/import`, `/accounts/{id}`, `/accounts/{id}/characters/{key}`,
`/builds/{genshin|hsr}`, `/builds/{game}/{key}`, `/sources`.

Known artifacts that are *not* bugs:

- Blank page or missing icons: the capture fired before data arrived. Retry with a larger time budget
  (5th argument in ms, e.g. `$S URL out.png 1400 1600 45000`); `--dom` mode proves whether content rendered.
- Empty boxes instead of character/weapon icons: lazy images not loaded yet; the yatta image URLs are fine.
- A red Vite error overlay right after editing: often a stale intermediate error. Re-run `ng build`; if it
  passes, restart `ng serve` and capture again.

For runtime errors, dump the DOM and console:

```bash
$S --dom http://127.0.0.1:4200/sources "$OUT/sources.html"     # prints CONSOLE / Uncaught lines
```

## 4. Shut down

Stop both background tasks. `ng serve` leaves an orphan `node` on port 4200; free the ports so the next
session starts clean:

```powershell
Get-NetTCPConnection -State Listen -LocalPort 4200,8000 -ErrorAction SilentlyContinue |
  ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
```

Check the command line first (`Get-CimInstance Win32_Process -Filter "ProcessId = <pid>"`) if anything else
could be using those ports. Tell the user what you checked and attach nothing they didn't ask for.
