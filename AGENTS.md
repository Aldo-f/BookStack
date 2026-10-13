# AGENTS.md — Agent Instructions for BookStack (DigiPunt Fork)

## Repository Overview

This is a fork of BookStack v26 deployed at `https://docs.digipunt.aldof.duckdns.org` on k3s (Raspberry Pi 5 home-lab). The fork adds:

1. **EPUB export** — pages, chapters, and books can be exported as EPUB files.
2. **DigiPunt theme** — a custom theme (`APP_THEME=digipunt`) deployed to the PVC, not in this repo. It provides:
   - Sources management (bronnen) with per-page source linking
   - Dutch as default language with language switching for non-logged-in users
   - Praktische informatie page with SharePoint links
3. **Autonomous Documentation Freshness** (spec `001-autonomous-doc-freshness`) — ad-hoc source change detection + LLM auto-update.

## Autonomous Documentation Freshness System

### Architecture

The system detects when linked source URLs change content and, with admin approval, rewrites pages via LLM.

- **Trigger**: Ad-hoc, on page view by a logged-in editor (not a daily cron job).
- **Detection**: `SourceChecker.php` extracts main content (ignoring sidebars/ads/nav) and computes SHA-256 hashes. Compares against stored snapshots in `source_snapshots` table.
- **Cascade**: When a source changes, ALL pages sharing that source are queued in `page_update_queue`.
- **LLM Update**: `LLMUpdater.php` calls FreeLLM API (`https://freellm.aldof.duckdns.org/v1`) with `model=auto`. Output stored as draft (NOT auto-published).
- **Human-in-the-loop**: Admin reviews draft, then publishes (replaces page HTML) or rejects (deletes queue entry).

### Key Files (on PVC, not in git repo)

| File | Purpose |
|------|---------|
| `themes/digipunt/SourceChecker.php` | Content extraction + hash computation + change detection |
| `themes/digipunt/LLMUpdater.php` | LLM API caller + draft storage |
| `themes/digipunt/functions.php` | API routes (7 endpoints under `/api/digipunt/`) |
| `themes/digipunt/public/js/sources.js` | Ad-hoc check trigger + badge injection |
| `themes/digipunt/public/css/sources.css` | Changed-source badge styling |
| `themes/digipunt/views/sources/page-panel.blade.php` | Sources modal HTML |
| `themes/digipunt/migrations/003_create_source_snapshots_table.php` | source_snapshots table |
| `themes/digipunt/migrations/004_create_page_update_queue_table.php` | page_update_queue table |
| `themes/digipunt/SourceChecker.php` | Content extraction + SHA-256 hash + change detection (implemented 2026-10-02) |
| `themes/digipunt/LLMUpdater.php` | LLM API caller + draft storage (implemented 2026-10-02) |
| `themes/digipunt/functions.php` | API routes (7 endpoints, implemented 2026-10-02) |
| `themes/digipunt/tests/ContentExtractorTest.php` | TDD tests (4 assertions, implemented 2026-10-02) |

### API Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/digipunt/sources` | List sources (includes `changed` flag) |
| POST | `/api/digipunt/sources` | Add a source |
| GET | `/api/digipunt/sources/check` | Get check status |
| POST | `/api/digipunt/sources/check` | Trigger ad-hoc source check |
| POST | `/api/digipunt/pages/{id}/update` | Trigger LLM update for a page |
| GET | `/api/digipunt/updates` | List pending updates |
| DELETE | `/api/digipunt/updates/{id}` | Reject (delete) a draft |
| GET | `/api/digipunt/models` | List available LLM models |

### Database Tables

- `source_snapshots`: `id`, `source_id`, `content_hash`, `checked_at`, + Laravel timestamps
- `page_update_queue`: `id`, `page_id`, `source_id`, `status` (pending/done/failed), `trigger_reason`, `model_used`, `llm_output` (TEXT), `output_len`, `completed_at`, + Laravel timestamps

### Model Selection

- `getBestModel()` queries `/v1/models` and picks the highest version in the `claude-sonnet` family.
- Fallback: `agnes-2.5-flash` (via `model=auto` auto-routing).
- Model used is recorded in `page_update_queue.model_used`.

### Content Extraction

- PHP `DOMDocument` with `STRIP_SELECTORS` (nav, aside, script, style, header, footer, .sidebar, .menu, .toc, .infobox, .related, .category).
- Main content selectors: `<main>`, `<article>`, `.mw-content-text`, `.entry-content`, `#content`, `#main-content`.
- Class matching uses exact token comparison (`explode(' ', $class)`) to avoid substring false positives.

### BookStack v26 Schema Notes

- No `pages` table — uses `entities` (with `type` column) + `entity_page_data` (for HTML content).
- Always query `entities` with `WHERE type = 'page'` and join `entity_page_data` on `page_id`.

## Deployment

- **k3s**: Pod label `app=bookstack`, PVC `bookstack-data`.
- **PVC path**: `/var/lib/rancher/k3s/storage/pvc-775740a0-27f2-4d8e-ab4e-62340067c512_default_bookstack-data/www`
- **Theme path on pod**: `/config/www/themes/digipunt/`
- **DB**: MariaDB on `stantonius-db`, database `bookstack`.
- **LLM**: `https://freellm.aldof.duckdns.org/v1` (auto-routing, key in deployment env).

## Spec-Kit

Feature specs live under `specs/` and `.specify/`. The constitution is at `.specify/memory/constitution.md`.

## Conventions

- **Verification-first**: Never claim success without real test output (curl, kubectl, PHPUnit).
- **English-only** logs and communication.
- **Upgrade-safe**: All custom logic lives in the theme layer (`themes/digipunt/`), not in core BookStack files.
- **No GDPR data** in logs or test output.
- **Pi5 arm64**: Pre-built images must support `linux/arm64`.

## Beads (bd) — Task Management

This project uses [Beads](https://github.com/gastownhall/beads), a Dolt-backed graph issue tracker for AI agents. It is the **source of truth** for task tracking — do not use markdown TODO lists.

### Quick Commands

| Command | Purpose |
|---------|---------|
| `bd ready` | List tasks with no open blockers |
| `bd show <id>` | View task details + audit trail |
| `bd update <id> --claim` | Atomically claim a task |
| `bd close <id> "reason"` | Mark complete |
| `bd create "Title" -p <1-4>` | Create a task |
| `bd dep add <child> <parent>` | Link tasks (child blocked by parent) |
| `bd prime` | Inject workflow context + memories |
| `bd remember "insight"` | Store durable project fact |

### Workflow

1. **Plan** with spec-kit: `specify` → `plan` → `tasks` produces `spec.md`, `plan.md`, `tasks.md`
2. **Import** tasks into beads: `bd create` with proper priorities and dependencies (`bd dep add`)
3. **Execute**: `bd ready` → claim → work → `bd close`
4. **Context**: `bd prime` injects project state at session start; `bd remember` stores cross-session insights

### Spec-Kit + Beads Integration

- `tasks.md` is a **generated artifact** from spec-kit, not the runtime tracker
- beads owns the runtime state: claims, dependencies, audit trails, context injection
- When importing spec-kit tasks, translate checkbox order into explicit `blocks` dependency edges
- Use `bd remember` for durable project facts that `bd prime` injects later

### Git-Free Mode

Beads runs without git via `--stealth` + `BEADS_DIR`:
```bash
export BEADS_DIR=/mnt/HDD1/dev/06-apps-bookstack/.beads
bd init --quiet --stealth
```
This avoids committing `.beads/` to the repo and works on non-git VCS.

- **Task tracking**: Use beads (`bd`), not markdown TODO lists. `.hermes/plans/` files are planning artifacts; beads is the runtime tracker.
