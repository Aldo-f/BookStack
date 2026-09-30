# BookStack Digipunt Theme Constitution

## Core Principles

### I. Theme-First (Upgrade-Safe Extension)

All features are implemented as extensions to the BookStack `digipunt` theme. No core BookStack files are modified. New classes are loaded via `require_once` in `functions.php`. New routes are registered via `ThemeEvents::APP_BOOT`. New migrations are deployed to `themes/digipunt/migrations/` and run via `php artisan digipunt:migrate`. This ensures all features survive BookStack core upgrades.

### II. Verification-First (NON-NEGOTIABLE)

No feature is considered complete until it has been tested with real output. Claims of success must be backed by curl output, test results, or browser E2E verification. "It should work" is not acceptable — "it works, here's the proof" is.

### III. Content Integrity

Source content extraction MUST isolate the main article content. Navigation, sidebars, footers, ads, scripts, and styles MUST be stripped before hashing. Hash changes from sidebar noise are bugs, not features. This is non-negotiable — false positives destroy trust in the change detection system.

### IV. Human-in-the-Loop (Reviewable Automation)

LLM-generated content is stored as a draft, not auto-published. An admin must review and explicitly publish or reject each update. This provides a safety net for LLM hallucinations, incorrect information, or GDPR violations. The system may move to fully autonomous publishing in a future iteration, but v1 requires human review.

### V. GDPR Compliance

No personal data (created_by fields, user names, email addresses, IP addresses) is included in LLM prompts or outputs. The LLM prompt explicitly instructs the model to exclude personal data. This is a legal requirement under GDPR, not a preference.

### VI. Cascading Completeness

When a source changes, ALL pages referencing that source are queued for update. When a page is updated, ALL its sources are re-checked. A page update always incorporates the latest content from every source, not just the one that triggered the cascade.

## Additional Constraints

- **Deployment target**: Raspberry Pi 5 (arm64, headless), k3s cluster. All code must run on PHP 8.x with dom, libxml, curl extensions.
- **Database**: MariaDB via `stantonius-db` service. All new tables use Laravel migrations.
- **LLM endpoint**: `https://freellm.aldof.duckdns.org/v1` (OpenAI-compatible). API key from environment.
- **No personal data**: The `created_by` column and user tables are never queried for LLM input. The `consulted_by` column in `entity_source_links` is not sent to the LLM.

## Development Workflow

1. Write migration → deploy to PVC → run `php artisan digipunt:migrate` → verify columns exist.
2. Write PHP class → deploy to PVC → verify with `php -l` (syntax check).
3. Write API route in `functions.php` → deploy → verify with `curl` against the endpoint.
4. Write JS → deploy → verify in browser as admin (E2E).
5. Write test → run in pod → verify pass.
6. Every commit must include verification output in the commit message or PR description.

## Governance

Constitution supersedes all other practices. All implementations must verify compliance with each principle. GDPR violations are blocking issues. Content integrity false positives are blocking bugs.

**Version**: 1.0.0 | **Ratified**: 2026-09-30 | **Last Amended**: 2026-09-30
