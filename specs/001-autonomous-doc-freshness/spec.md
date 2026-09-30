# Feature Specification: Autonomous Documentation Freshness

**Feature Branch**: `001-autonomous-doc-freshness`

**Created**: 2026-09-30

**Status**: Draft

**Input**: User description: "We moeten een logica vinden die er voor kan zorgen dat we steeds documentatie hebben die up to date is. Als de inhoud van een van de bronnen veranderd is is er een kans dat we onze documentatie dus ook moeten veranderen. We hebben dus een noodzaak aan het weten of een bron veranderd is of niet. Om zo te kunnen achterhalen of onze documentatie moet aangevuld of verbeterd worden."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ad-hoc Source Change Detection (Priority: P1)

When a logged-in admin or editor visits a BookStack documentation page, the system automatically checks all sources linked to that page in the background. It fetches each source URL, extracts only the main article content (stripping navigation, sidebars, footers, ads, and scripts), and compares a SHA-256 hash of that clean content to the last stored snapshot. If a source's content has changed since the last check, a visual "⚠ Gewijzigd" badge appears next to the source in the sources panel, and a notification tells the admin how many sources changed.

**Why this priority**: Without change detection, none of the other stories can function. This is the foundational capability — knowing what changed is prerequisite to updating anything.

**Independent Test**: Navigate to a page with sources as a logged-in admin. Verify the sources panel loads and displays source check results. Insert a fake previous snapshot with a wrong hash for one source, reload the page, and verify the "⚠ Gewijzigd" badge appears on that source.

**Acceptance Scenarios**:

1. **Given** a page with 9 linked sources and no previous snapshots, **When** an admin visits the page, **Then** all 9 sources are fetched, their main content is hashed and stored, and no "changed" badges appear (first baseline check).
2. **Given** a page with a previous snapshot for source 55 containing hash "FAKE_HASH", **When** an admin visits the page and source 55's real content hash differs, **Then** a "⚠ Gewijzigd" badge appears next to source 55 in the sources panel.
3. **Given** a source URL whose sidebar HTML changes but main content does not, **When** the system re-checks the source, **Then** the hash does NOT change (no false positive from sidebar noise).
4. **Given** an anonymous (non-logged-in) visitor, **When** they visit a page, **Then** no background source check is triggered (checks are admin/editor only).

---

### User Story 2 - LLM-Powered Page Auto-Update (Priority: P2)

When one or more sources of a page are detected as changed, the system automatically calls an LLM (via the freellm API at `https://freellm.aldof.duckdns.org/v1`) to rewrite the page content using the latest source content. The LLM receives the current page HTML and the extracted text from all sources (with changed sources marked), and produces an updated HTML version in Dutch. The output is stored as a draft in a queue. The admin sees a notification with the model used and a button to review the changes. The admin can then publish or reject the draft.

**Why this priority**: Detecting changes is only useful if we can act on them. The LLM update is the core value proposition — keeping documentation current without manual rewriting.

**Independent Test**: Trigger a source change (insert fake snapshot), then call the update API endpoint. Verify the LLM returns updated HTML and the queue entry shows `status=done` with the model name. Call the pending updates API and verify the draft is listed.

**Acceptance Scenarios**:

1. **Given** a page with 2 changed sources, **When** the LLM update is triggered, **Then** the system sends the current page HTML + all source content (changed sources marked) to the LLM and stores the response as a draft in the update queue.
2. **Given** a completed LLM update draft in the queue, **When** an admin clicks "Publish", **Then** the page HTML is replaced with the LLM output in the BookStack database.
3. **Given** a completed LLM update draft in the queue, **When** an admin clicks "Reject", **Then** the queue entry is deleted and the page is not modified.
4. **Given** the freellm API is unreachable, **When** an update is triggered, **Then** the system returns an error message and the queue entry is marked as `failed` (page content is not modified).

---

### User Story 3 - Cascading Updates Across Pages (Priority: P3)

When a source S changes, the system finds ALL pages that reference S via `entity_source_links` and queues each one for a full source re-check and potential LLM update. This ensures that when a page is updated, it always incorporates the latest content from ALL its sources — not just the one that triggered the cascade. Queued pages are processed on their next page view or when an admin reviews the pending queue.

**Why this priority**: Sources are shared across pages. A change to the Arch Linux NTFS wiki page affects every documentation page that cites it. Without cascading, some pages would stay stale.

**Independent Test**: Insert a fake changed snapshot for a source that is linked to 3 different pages. Verify all 3 pages appear in the `page_update_queue` table with `status=pending`. Call the pending updates API and verify all 3 are listed.

**Acceptance Scenarios**:

1. **Given** source 55 is linked to pages 17, 42, and 88, **When** page 17 detects source 55 as changed, **Then** pages 42 and 88 are added to `page_update_queue` with `status=pending` and `trigger_reason="source 55 changed"`.
2. **Given** page 42 is already in the queue with `status=pending`, **When** another source change triggers a cascade for page 42, **Then** no duplicate queue entry is created.
3. **Given** a page in the queue with `status=pending`, **When** an admin visits that page, **Then** the full source check runs (all sources, not just the trigger source) and the page is eligible for LLM update.

---

### User Story 4 - Automatic LLM Model Upgrade (Priority: P4)

The system uses a model family prefix (default: `claude-sonnet`) to select the LLM for page updates. On each update call, it queries the freellm `/v1/models` endpoint and picks the highest-versioned model matching the family prefix. If a newer version of the same model family becomes available (e.g., `claude-sonnet-4-6` when `claude-sonnet-4-5` was previously used), the system automatically uses the newer version without any configuration change.

**Why this priority**: The freellm endpoint is regularly updated with new models. Automatic upgrade ensures the system always uses the best available writing model without manual intervention.

**Independent Test**: Call the model selection function and verify it returns a model starting with `claude-sonnet`. Check the freellm `/v1/models` endpoint and verify the selected model is the highest-versioned one in the family.

**Acceptance Scenarios**:

1. **Given** the freellm API offers `claude-sonnet-4-5` and `claude-sonnet-4-3`, **When** the system selects a model, **Then** it picks `claude-sonnet-4-5` (higher version).
2. **Given** the freellm API is unreachable, **When** the system selects a model, **Then** it falls back to the configured default model (`claude-sonnet-4-5`).
3. **Given** the freellm API adds `claude-sonnet-5-0` tomorrow, **When** the system selects a model, **Then** it automatically picks `claude-sonnet-5-0` (version 500 > 405).

---

### Edge Cases

- What happens when a source URL returns HTTP 404 or 500? The snapshot is stored with `http_status=404` and `changed=false` (no false positive from a broken page).
- What happens when a source URL takes longer than 30 seconds to respond? The cURL timeout fires, the snapshot is stored with `http_status=0` and `changed=false`.
- What happens when two admins visit the same page simultaneously? Both trigger source checks. Duplicate snapshots are stored (acceptable — the latest snapshot is always used for comparison).
- What happens when the LLM returns empty content? The queue entry is marked as `failed` with error "Empty LLM response". The page is not modified.
- What happens when a source URL is NULL (non-web source like a book)? The source is skipped during the check (only sources with non-empty `https://` URLs are fetched).
- What happens when the LLM output contains personal data (GDPR)? The LLM prompt explicitly instructs it not to include personal data. The admin reviews the draft before publishing, providing a human checkpoint.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST extract the main article content from a source URL by stripping navigation, sidebars, footers, scripts, styles, ads, and other non-content elements, using DOM parsing.
- **FR-002**: System MUST compute a SHA-256 hash of the extracted main content text (not raw HTML) and store it as a snapshot in the database.
- **FR-003**: System MUST compare the current content hash to the most recent previous snapshot hash to determine if a source has changed.
- **FR-004**: System MUST NOT flag a source as changed when only sidebar, navigation, footer, or other non-main-content elements have changed.
- **FR-005**: System MUST trigger source checks ad-hoc when a logged-in admin or editor visits a page with linked sources.
- **FR-006**: System MUST NOT trigger source checks for anonymous (non-logged-in) visitors.
- **FR-007**: System MUST display a "⚠ Gewijzigd" badge next to changed sources in the sources panel.
- **FR-008**: System MUST display a notification to the admin when one or more sources have changed, indicating the count of changed sources.
- **FR-009**: System MUST call the freellm API (`https://freellm.aldof.duckdns.org/v1/chat/completions`) with the current page HTML and all source content to generate an updated page version.
- **FR-010**: The LLM prompt MUST instruct the model to write in clear Dutch for non-technical users, preserve existing structure where possible, and exclude personal data (GDPR).
- **FR-011**: System MUST store the LLM output as a draft in a queue table, NOT auto-publish it to the live page.
- **FR-012**: System MUST allow an admin to review, publish, or reject LLM-generated drafts.
- **FR-013**: When a source changes, system MUST find all pages linked to that source and queue them for full source re-check and potential update.
- **FR-014**: When a page is queued for update, system MUST avoid creating duplicate queue entries if one already exists in a non-terminal status.
- **FR-015**: System MUST select the highest-versioned model from the configured model family prefix by querying the freellm `/v1/models` endpoint.
- **FR-016**: System MUST fall back to a configured default model if the freellm API is unreachable.
- **FR-017**: System MUST record which LLM model was used for each page update in the queue table.
- **FR-018**: System MUST NOT include any personal data (created_by, user names, email addresses) in LLM prompts or outputs.
- **FR-019**: All new database tables MUST be created via Laravel migrations deployed through the digipunt theme migration system (`php artisan digipunt:migrate`).
- **FR-020**: All new PHP classes MUST be loaded via `require_once` in `functions.php` using absolute paths (theme_path() is not available at bootstrap time).

### Key Entities *(include if feature involves data)*

- **Source**: An external reference (web page, PDF, book) linked to a BookStack page. Has a URL, title, author, type. Stored in existing `sources` table.
- **EntitySourceLink**: Links a source to a BookStack entity (page, chapter, book, shelf) with a role (primary, supplementary, see_also). Stored in existing `entity_source_links` table.
- **SourceSnapshot**: A point-in-time record of a source's extracted main content. Contains the SHA-256 hash, extracted text, HTTP status, checked_at timestamp, and a `changed` boolean. Stored in new `source_snapshots` table.
- **PageUpdateQueue**: A queue entry for a page that needs LLM-based updating. Contains the page ID, status (pending/checking/updating/done/failed), trigger reason, LLM output, model used, and timestamps. Stored in new `page_update_queue` table.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: When an admin visits a page with 9 sources, all sources are fetched and checked within 10 seconds (background, non-blocking).
- **SC-002**: Sidebar-only changes to a source URL do NOT produce a "changed" flag (false positive rate: 0%).
- **SC-003**: Real content changes to a source URL DO produce a "changed" flag (true positive rate: 100%).
- **SC-004**: When a source changes, all pages referencing that source appear in the update queue within 1 second of the check completing.
- **SC-005**: The LLM produces a valid HTML page update within 120 seconds of being triggered.
- **SC-006**: The system automatically uses the highest-versioned model from the configured family without manual configuration changes.
- **SC-007**: An admin can review, publish, or reject an LLM-generated draft from the page view (no separate admin panel needed for the initial flow).
- **SC-008**: No personal data (created_by, user names, emails) appears in LLM prompts or outputs.

## Assumptions

- All source URLs are publicly accessible (no authentication required to fetch them).
- Source URLs are predominantly Arch Linux Wiki pages and similar documentation sites with `<main>` or `#mw-content-text` containers.
- The freellm API is available at `https://freellm.aldof.duckdns.org/v1` with the API key `freellmapi-82ea901092bf963260f3ea5fe1e1a02e3995a488b4f186e1`.
- The `claude-sonnet` model family is the preferred family for Dutch-language documentation writing.
- BookStack PHP has the `dom`, `libxml`, `SimpleXML`, and `curl` extensions available.
- The digipunt theme `functions.php` is the correct extension point for new API routes, event listeners, and class loading.
- Database migrations are deployed to the PVC at `/config/www/themes/digipunt/migrations/` and run via `php artisan digipunt:migrate`.
- The BookStack k3s pod runs on a Raspberry Pi 5 (arm64) and is accessible at `https://docs.digipunt.aldof.duckdns.org`.
- LLM drafts require admin review before publishing (human-in-the-loop safety measure). This may be changed to fully autonomous in a future iteration.
- The existing `sources` table (85 rows) and `entity_source_links` table (102 rows) contain the current source data and are not modified by this feature.
