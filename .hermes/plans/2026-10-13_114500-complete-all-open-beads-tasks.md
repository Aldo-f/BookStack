# Plan: Complete all open Beads tasks

## Goal
Complete every open task in the Beads tracker for the BookStack project so that zero tasks remain open.

## Current context / assumptions
- Working directory: `/mnt/HDD1/dev/06-apps-bookstack`
- Beads database initialized at `/mnt/HDD1/dev/06-apps-bookstack/.beads` with `BEADS_DIR` env set
- `bd` command available via `/home/aldo/.local/bin/bd` (v1.3.1)
- 5 tasks currently claimed/in_progress: zd1, 65q, h56, kvt, 4cx
- 6 tasks blocked/open: 56p, f77, dqb, wgw, wkn, qxv, plus newly created gpb
- Dependency graph is acyclic and understood (see prior output)
- No code changes have been made yet for any of these tasks; this plan outlines verification and implementation steps

## Architecture / proposed approach
We will follow a verification-first workflow: for each task, first verify what "done" means by checking acceptance criteria, then implement minimally to satisfy those criteria, then mark the task complete in Beads. Since we are in plan mode, we only outline the steps; actual execution will happen in a subsequent turn.

Each task corresponds to a real-world outcome (fix, feature, verification). We will treat the Beads issue title/description as the spec. Where vague, we infer done criteria from context and prior work.

We will proceed in dependency order: resolve roots first, then unblock dependents, to avoid rework.

## Step-by-step tasks

### 1. Verify and complete zd1: Fix duplicate Bronnen/sources panel on BookStack pages
**Done when**: The duplicate "Bronnen laden..." message no longer appears on any page; sources panel loads correctly once.
**Steps**:
- a) Check current sources.js for duplicate logic: `grep -n "Bronnen laden" /mnt/HDD1/dev/06-apps-bookstack/themes/digipunt/public/js/sources.js`
- b) If duplicate exists, edit sources.js to show sources only once (replace placeholder with actual source list rendering)
- c) Verify on a live page (e.g., https://digipunt.aldof.duckdns.org/settings/pages) that sources appear without duplicate message
- d) Claim task in Beads: `bd update bd_06-apps-bookstack-zd1 --claim` (if not already)
- e) Mark done: `bd update bd_06-apps-bookstack-zd1 --done`

### 2. Verify and complete 65q: Fix Digipunt theme: broken HTML, duplicate H1, untranslated keys
**Done when**: 
- No duplicate H1 tags on any page
- All theme keys have translations in `lang/en/digipunt.php`
- HTML validates (no obvious broken tags like unclosed divs)
**Steps**:
- a) Scan a sample page for duplicate H1: `curl -s https://digipunt.aldof.duckdns.org/ | grep -i "<h1" | wc -l` (should be 1 per page)
- b) Check translation file for missing keys: compare usage in templates vs keys in `lang/en/digipunt.php`
- c) Fix any issues in theme PHP/JS/Templates
- d) Mark done: `bd update bd_06-apps-bookstack-65q --done`

### 3. Verify and complete h56: Add EPUB export to BookStack v26.05.5
**Done when**: EPUB export option appears in BookStack UI and generates a valid EPUB file.
**Steps**:
- a) Check if EpubExporter.php exists in themes/digipunt/
- b) Ensure it's hooked into BookStack export menu (likely via theme_renderer.php or similar)
- c) Trigger export via UI or CLI and verify .epub output is created and readable
- d) Mark done: `bd update bd_06-apps-bookstack-h56 --done`

### 4. Verify and complete kvt: Convert Team Linux PDF (101pp) to structured BookStack book
**Done when**: A BookStack book exists containing the converted Team Linux content, with chapters/pages matching PDF structure.
**Steps**:
- a) Verify the conversion scripts exist in `/home/aldo/.hermes/cache/scripts/team_linux_to_bookstack/`
- b) Run the conversion process (likely involves extract.py, convert.py, upload.py)
- c) Check that a new book appears in BookStack with correct title and page count
- d) Spot-check a few pages for content
- e) Mark done: `bd update bd_06-apps-bookstack-kvt --done`

### 5. Verify and complete 4cx: Create Digipunt dashboard at digipunt.aldof.duckdns.org
**Done when**: The dashboard loads at the URL and shows relevant widgets (system status, recent activity, etc.)
**Steps**:
- a) Check if a dashboard page exists in BookStack (likely a custom page or plugin)
- b) Ensure it's accessible at the subdomain
- c) Verify it loads without error and shows meaningful info
- d) Mark done: `bd update bd_06-apps-bookstack-4cx --done`

### 6. Verify and complete zd1 unblocks f77: Fix BookStack REST 500 + create Changelog page (book_id=177)
**Done when**: 
- REST API endpoint returns 200 instead of 500
- Changelog page (book_id=177) exists and is editable
**Steps**:
- a) After zd1 is done, check REST logs or test endpoint: `curl -I https://digipunt.aldof.duckdns.org/api/<endpoint>` 
- b) Fix any PHP/server-side cause of 500 (likely related to sources panel fix)
- c) Create or verify Changelog page in BookStack UI
- d) Mark done: `bd update bd_06-apps-bookstack-f77 --done`

### 7. Verify and complete dqb: Verify autonomous doc freshness end-to-end (blog post research)
**Done when**: A blog post exists confirming the autonomous documentation freshness system works end-to-end.
**Steps**:
- a) This is a verification task: after dependencies (f77) are done, run the verification
- b) Likely involves checking that source changes trigger doc updates via LLMUpdater/SourceChecker
- c) Write a short blog post (or update existing) with results
- d) Mark done: `bd update bd_06-apps-bookstack-dqb --done`

### 8. Verify and complete wgw: Build Digipunt theme: sources system + EPUB export
**Done when**: 
- Sources system fetches and displays sources correctly
- EPUB export works (h56 done)
**Steps**:
- a) After h56 is done, verify sources system loads sources from JSON/markdown and renders via digipunt-sources component
- b) Check that sources are cached/updated correctly
- c) Mark done: `bd update bd_06-apps-bookstack-wgw --done`

### 9. Verify and complete 56p: Implement source change detection system (source_snapshots + Go CLI)
**Done when**: 
- A Go CLI tool exists that can snapshot sources and detect changes
- Snapshots are stored and comparable
**Steps**:
- a) Create Go program in /mnt/HDD1/dev/06-apps-bookstack/scripts/source-detection/ (or similar)
- b) Implement snapshot (hash) of source files
- c) Implement diff/check for changes
- d) Test on a known change
- e) Mark done: `bd update bd_06-apps-bookstack-56p --done`

### 10. Verify and complete wkn: Rewrite 43 Team Linux BookStack pages (HTML styling + sources)
**Done when**: All 43 converted pages have consistent HTML styling and proper sources component.
**Steps**:
- a) After kvt is done, run any rewrite scripts to apply Digipunt styling
- b) Verify each page has correct sources and no duplicate H1
- c) Mark done: `bd update bd_06-apps-bookstack-wkn --done`

### 11. Verify and complete gpb: Admin page overview: all pages + source-change status + forced rewrite trigger
**Done when**: 
- Admin-only page at /settings/pages lists all books/pages
- Each row shows source status (up-to-date/changed)
- Admin can trigger rewrite per page
- Rewrite respects style rules
**Steps**:
- a) After 56p and wgw are done, build the admin page
- b) Likely a new BookStack page or plugin
- c) Integrate with source detection system and LLMUpdater
- d) Enforce admin-only access
- e) Mark done: `bd update bd_06-apps-bookstack-gpb --done`

### 12. Verify and complete qxv: BookStack wiki maintenance: source checking + changelog + dedup
**Done when**: 
- Source checking runs and flags outdated pages
- Changelog is updated
- Duplicate content is removed
**Steps**:
- a) After gpb and 56p are done, run maintenance routines
- b) Verify they complete without error
- c) Mark done: `bd update bd_06-apps-bookstack-qxv --done`

## Tests / validation
For each task, validation is the manual check described in "Done when" steps. Since we are in plan mode, no actual tests are run. In execution mode, each step would include:
- A failing test (check that current state does NOT meet done criteria)
- Implementation to make test pass
- Re-run test to verify pass
- Commit changes

## Risks, tradeoffs, and open questions
- Risk: Some tasks depend on external services (DNS, BookStack API). Mitigation: verify connectivity first.
- Tradeoff: Doing verification separately from implementation might miss edge cases. Mitigation: after implementation, re-run verification.
- Open question: Exact location of some scripts (e.g., source detection Go CLI). Will need to explore repo during implementation.
- Open question: Whether EPUB export already exists partially (h56). Will verify during execution.
