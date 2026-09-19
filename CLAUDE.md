# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

NetAuto ITSM: a network device inventory / change-management / live-CLI / AI-incident-detection
tool for Cisco lab devices (built and tested against an EVE-NG lab). Three independent
services, each a separate top-level directory, that only talk to each other over HTTP/UDP —
there is no shared code or shared process between them:

- `backend/` — Laravel 12 (PHP 8.2) REST API + Sanctum token auth + SQLite. The source of truth
  for all persisted data (devices, users, change requests, incidents) and the only service the
  frontend talks to directly.
- `frontend/` — Vue 3 + Vite + Tailwind SPA. Talks only to `backend/`, never directly to
  `middleware/`.
- `middleware/` — Python FastAPI service. The only thing in the stack that touches the actual
  network: SSH (paramiko/netmiko), raw Telnet sockets, ICMP ping, and a UDP syslog listener.
  Laravel calls it over HTTP; it calls Laravel back over HTTP for one thing (reporting a
  detected syslog incident).

## Commands

### Backend (run from `backend/`)
- Dev server: `php artisan serve` (defaults to `127.0.0.1:8000`)
- Queue worker (**required** for the Incidents/AI feature — jobs sit pending forever without
  it): `php artisan queue:work --tries=1`
- All three backend processes + Vite at once: `composer run dev` (uses `concurrently`; starts
  `serve`, `queue:listen`, `pail` log tailing, and `npm run dev`)
- Run tests: `composer test` or `php artisan test`
- Run a single test: `php artisan test --filter=TestName` or `php artisan test tests/Feature/SomeTest.php`
- Format: `./vendor/bin/pint`
- Migrate: `php artisan migrate` (SQLite DB file lives at `backend/database/database.sqlite`)

### Frontend (run from `frontend/`)
- Dev server: `npm run dev` (Vite, default port 5173; proxies `/api` → `127.0.0.1:8000` and
  `/middleware` → `127.0.0.1:8001` — see `vite.config.js`)
- Build: `npm run build`
- No test suite or lint script is configured in this project.

### Middleware (run from `middleware/`)
- Activate the venv, then: `python main.py` (binds FastAPI on `0.0.0.0:8001`, and a UDP syslog
  listener on port 5514 in a background thread — see Architecture below)
- No automated test suite. `test_api.py`, `test_ssh.py`, and `../test_inventory_api.py` are
  manual one-off smoke scripts (plain `python <file>.py`, not pytest) for poking the running
  middleware/backend by hand against a real lab device.

### Windows convenience launcher
`start_services.bat` (repo root) opens four separate terminal windows: backend serve, queue
worker, Vite dev server, and the middleware. Useful reference for what needs to be running
simultaneously for the app to fully work — the Incidents/AI feature specifically breaks
silently (no errors, just nothing happens) if the queue worker isn't one of them.

## Architecture

### The three-service call graph, and why it's shaped this way

- **Frontend → backend only.** All API calls go through the single `axios` instance in
  `frontend/src/utils/api.js` (`baseURL: '/api'`), which Vite's dev proxy forwards to the
  backend. The frontend never talks to the middleware directly, even for features that are
  ultimately about live network access (Telnet CLI, SSH commands) — it always goes through a
  Laravel controller first.
- **Backend → middleware, over plain HTTP, one-shot per request.** Every controller method
  that needs live device access does `Http::post("{$middlewareUrl}/...", ...)` where
  `$middlewareUrl = env('MIDDLEWARE_URL', 'http://127.0.0.1:8001')`. There is no persistent
  connection between the two processes — each call is independent. `MIDDLEWARE_URL` is not
  currently set in `.env`, so it's always using the hardcoded localhost default.
- **Middleware → backend, only for one purpose:** reporting a detected syslog incident (see
  below). Controlled by `BACKEND_URL` (env var, defaults to `http://127.0.0.1:8000`) and
  authenticated with a shared secret (`MIDDLEWARE_INTERNAL_SECRET`), not Sanctum — the
  middleware has no user session to authenticate as.

### Auth model
Sanctum bearer tokens (`Route::middleware('auth:sanctum')` wraps almost everything in
`routes/api.php`). `role` on the `User` model (`admin`/`engineer`/`manager`) gates
admin-only endpoints server-side (e.g. `UserController`) — there's no separate roles table.
The one deliberate exception is `POST /api/internal/syslog-event`: unauthenticated by Sanctum,
instead checked against `X-Internal-Secret` against `config('services.middleware.internal_secret')`,
since it's called machine-to-machine by the middleware, not a logged-in user.

### Device credentials live in two places, and they can drift
Each `Device` row stores `username`/`password` (hidden from JSON serialization) — used both by
the SSH-based `/run-command` middleware calls *and* by the automated diagnostic-gathering job
(`InvestigateIncident`). Separately, the Web CLI's Telnet tab lets an engineer type credentials
live into the terminal per-session, which never touches the stored `Device` credentials at all.
**These two credential sources are not kept in sync** — if someone changes the router's actual
login but not the `Device` row (or vice versa), automated jobs that rely on the stored
credentials (diagnostic collection) will fail even though manual Telnet login still works fine.

### Telnet vs SSH — deliberate, not an oversight
The lab devices in this project have no SSH server configured, only Telnet. `DeviceController`
still has SSH-based methods (`runCommand`, using paramiko in the middleware) because that code
predates the Telnet work and other devices/environments may have SSH. But the two custom
pieces built for this project's actual devices are Telnet-only:
- The interactive Web CLI (`/telnet/connect|write|read|close` in `middleware/main.py`) — a
  raw socket kept open per session in `TELNET_SESSIONS`, polled by the frontend every 300ms.
  Telnet IAC negotiation is hand-parsed in `_parse_telnet_bytes` (there's no telnetlib usage
  here by design, since it doesn't give raw access to the negotiation bytes the way this needs).
- The one-shot diagnostic collector (`/telnet/run-commands`) — logs in, runs
  `terminal length 0`, then a batch of `show` commands, and returns all output in one HTTP
  response. Used only by `InvestigateIncident`, never by the frontend directly.

The Web CLI's frontend terminal (`WebCliView.vue`) intentionally does **not** use xterm.js
despite it being in `package.json` — xterm rendered correctly in complete isolation but never
inside this app for reasons never fully root-caused (ref churn, Vite/Vue-specific rendering
issue). It was replaced with a plain reactive string buffer (`tab.output`) rendered directly via
Vue interpolation, with manual control-character handling (Backspace erases the last character,
bare `\r` erases back to the last newline) since a plain string buffer doesn't get that for
free the way a real terminal emulator would. Router-echoed Backspace is `\x08`, not `\x7f`
(Cisco IOS is inconsistent about honoring DEL).

### The Incidents / AI pipeline (the newest and most involved feature)
End-to-end flow, spanning all three services:
1. A router (configured with `logging host <middleware-ip> transport udp port 5514`) sends a
   syslog message on any state change.
2. `middleware/main.py`'s `_syslog_listener_loop` (a background thread started via FastAPI's
   `startup` event) receives it on a raw UDP socket.
3. `CISCO_SYSLOG_RE` splits every Cisco message into facility/severity/mnemonic — this parsing
   is generic (works for any facility: OSPF, BGP, LINK, SYS, hardware, security, etc.), not
   hardcoded per-protocol. `_should_report()` decides whether it's worth flagging: severity ≤4
   always qualifies; severity 5 only qualifies for mnemonics in `NOTICE_MNEMONICS_OF_INTEREST`
   (real state-change events like `ADJCHG`/`UPDOWN`, as opposed to routine severity-5 admin
   noise like `CONFIG_I`); `EXCLUDED_MNEMONICS` blocklists specific known-benign messages
   regardless of severity (e.g. `CONFIG_RESOLVE_FAILURE`, the TFTP auto-config-boot retry that
   IOU/EVE-NG labs emit repeatedly with no TFTP server present).
4. Qualifying events get POSTed to `POST /api/internal/syslog-event` →
   `IncidentController::ingestSyslogEvent` looks up the `Device` by source IP, creates an
   `Incident` row (`status: detected`), and dispatches `InvestigateIncident` onto the queue —
   the HTTP response to the middleware returns immediately, it doesn't wait for the job.
5. `InvestigateIncident` (needs `queue:work` running) gathers live diagnostics over
   `/telnet/run-commands` — a facility-specific command set layered on a fixed baseline (see
   `extraCommandsFor()`/`gatherDiagnostics()` in the job) — then sends the raw syslog message
   plus that diagnostic output to the Anthropic Messages API (`AnthropicClient`, needs
   `ANTHROPIC_API_KEY` in `.env`) for a root-cause write-up, saved back onto the same row.
6. `IncidentsView.vue` polls `GET /api/incidents` every 10s and renders whatever's currently in
   the DB — it has no awareness of the pipeline's internal state, it just reflects the row.

Two things worth knowing if this stops working: syslog is UDP, so any message that arrives
while the middleware isn't running is silently lost forever (no retry, no replay) — unlike an
incident that's already been created but not yet processed, which *is* durable in the jobs
queue and will get picked up whenever a worker next runs. And Windows Firewall blocks inbound
UDP to a new port by default, so the one-time
`netsh advfirewall firewall add rule ... protocol=UDP localport=5514` step is easy to forget
when moving to a new machine.
