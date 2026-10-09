# Peppol Documentation Integration Plan

## Overview

This document describes how to integrate Peppol provider and pricing information into the BookStack DigiPunt documentation at `https://docs.digipunt.aldof.duckdns.org`, with automatic update support via the SourceChecker system.

## Where to Integrate

### Recommended Location

Add a new **book** called **"Services & Integrations"** (or similar) under the main documentation structure, with the following hierarchy:

```
📁 Services & Integrations
  📁 Peppol
    📄 01-what-is-peppol.md       ← What Peppol is, overview
    📄 02-providers.md            ← Certified service providers list
    📄 03-pricing.md              ← Membership and pricing info
    📄 04-belgian-platforms.md    ← Belgian Peppol platforms
```

### Alternative: Single Page

If a simpler structure is preferred, add all content to a single page:
- **Title**: "Peppol: Gecertificeerde aanbieders en prijzen"
- **Slug**: `peppol-providers`
- **Book**: Choose an appropriate existing book (e.g., "Algemene Diensten" or "Informatie")

## Integration Method

### Step 1: Create the Page(s) via BookStack Admin UI

1. Log in to `https://docs.digipunt.aldof.duckdns.org` as administrator
2. Navigate to the target book/section
3. Create new pages using the content from `peppol-overview.md` (already prepared)
4. Set page status to "published"

### Step 2: Register Source URLs for Auto-Update

Using the BookStack API or UI, add the following sources to each page:

| Source Name | URL | Purpose |
|-------------|-----|---------|
| Peppol Certified Service Providers | https://peppol.org/members/peppol-certified-service-providers/ | Full registry of all certified SP/AP/SMP providers |
| Peppol Fees | https://peppol.org/join/fees/ | Membership fee structure (updated 2025/2026) |
| Peppol Belgium Platforms | https://dexxter.be/lijst-peppol-platformen-belgie/ | Belgian Peppol platforms and access points |
| OpenPeppol Main Site | https://peppol.org/ | General Peppol information and resources |

**Via Admin UI**: Go to Page → Sources → Add source → Enter URL and description.

**Via API**: Use the `/api/digipunt/sources` endpoint:

```bash
# Add a source (requires BOOKSTACK_TOKEN_ID and BOOKSTACK_TOKEN_SECRET)
curl -X POST https://docs.digipunt.aldof.duckdns.org/api/digipunt/sources \
  -H "Authorization: Bearer $BOOKSTACK_TOKEN_ID:$BOOKSTACK_TOKEN_SECRET" \
  -d "name=Peppol Certified Service Providers&url=https://peppol.org/members/peppol-certified-service-providers/&description=Full registry of all certified Peppol Service Providers"
```

### Step 3: Trigger Initial Source Check

After adding sources:

1. Navigate to the page as a logged-in admin
2. The system automatically checks all sources on page load
3. SHA-256 hashes are computed and stored in `source_snapshots` table
4. A "⚠ Gewijzigd" badge appears if content has changed since last check

### Step 4: Enable Automatic Updates

When source content changes:

1. Admin visits page → background source check runs
2. If hash differs → "⚠ Gewijzigd" badge appears next to source
3. Admin can trigger LLM update via button in the sources panel
4. System calls FreeLLM API (`https://freellm.aldof.duckdns.org/v1`) with:
   - Current page HTML
   - All source content (changed sources marked)
   - Prompt to rewrite page in Dutch
5. Output stored as draft in `page_update_queue` table
6. Admin reviews draft, then publishes or rejects

### Step 5: Maintain the Content

The markdown file `peppol-overview.md` in the scratch directory contains the initial content. To refresh:

1. Update the markdown file with new provider data
2. Re-run the API calls or manually update BookStack pages
3. Trigger source check to detect changes and update via LLM

## Source URLs for Monitoring

The following URLs are critical for the SourceChecker system and should be monitored:

| Priority | URL | Change Frequency | Criticality |
|----------|-----|-----------------|-------------|
| P1 | https://peppol.org/members/peppol-certified-service-providers/ | Weekly | High — full provider registry |
| P1 | https://peppol.org/join/fees/ | Monthly (fees changed July 2025) | High — pricing changes |
| P2 | https://dexxter.be/lijst-peppol-platformen-belgie/ | Quarterly | Medium — Belgian platforms |
| P3 | https://peppol.org/ | Ongoing | Low — general info |

### How Change Detection Works

1. **Admin visits page** → SourceChecker triggers background fetch
2. **Content extraction** → PHP DOMDocument strips selectors: `nav, aside, script, style, header, footer, .sidebar, .menu, .toc, .infobox, .related, .category`
3. **Hash computation** → SHA-256 of extracted main content text (not raw HTML)
4. **Comparison** → New hash compared to stored snapshot in `source_snapshots` table
5. **Flagging** → If hash differs, `changed=true` and "⚠ Gewijzigd" badge appears
6. **Cascade** → All pages sharing that source are queued for update

## Technical Implementation Details

### API Endpoints Used

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/digipunt/sources` | GET | List all sources with `changed` flag |
| `/api/digipunt/sources` | POST | Add a new source URL |
| `/api/digipunt/sources/check` | POST | Trigger ad-hoc source check for a page |
| `/api/digipunt/pages/{id}/update` | POST | Trigger LLM update for a specific page |
| `/api/digipunt/updates` | GET | List pending LLM update queue entries |

### LLM Prompt for Page Generation

When the LLM updates a Peppol page, the prompt includes:

```
You are updating a Peppol documentation page for a Dutch audience. 
Rewrite the page content in clear Dutch, preserving existing structure.
Include:
1. What Peppol is (definition)
2. List of certified service providers (with country/AP/SMP status)
3. Pricing information for service provider membership
4. Belgian Peppol platforms
5. Source URLs for manual verification

Keep the content up-to-date and accurate. Do not include personal data.
```

### Database Tables Involved

- **`source_snapshots`**: Stores SHA-256 hashes of source content, `changed` flag, `extracted_text`, `http_status`, `checked_at`
- **`page_update_queue`**: Stores LLM output (`llm_output`), `status` (pending/checking/updating/done/failed), `model_used`, `trigger_reason`

## Verification

### After Integration, Verify:

1. ✅ Page loads correctly at the expected URL
2. ✅ Sources panel appears with source names and URLs
3. ✅ Initial source check runs (hashes computed, no "changed" badges initially)
4. ✅ "⚠ Gewijzigd" badge appears when source content is modified
5. ✅ LLM update triggers successfully (draft stored in queue)
6. ✅ Admin can publish/reject the LLM-generated draft
7. ✅ Page content reflects latest source information after publish

### Test Commands

```bash
# Check sources are registered
curl https://docs.digipunt.aldof.duckdns.org/api/digipunt/sources \
  -H "Authorization: Bearer $TOKEN"

# Trigger source check for a page
curl -X POST https://docs.digipunt.aldof.duckdns.org/api/digipunt/sources/check \
  -H "Authorization: Bearer $TOKEN" \
  -d "page_id=PAGE_ID"

# Trigger LLM update
curl -X POST https://docs.digipunt.aldof.duckdns.org/api/digipunt/pages/UPDATE_PAGE_ID \
  -H "Authorization: Bearer $TOKEN"
```

## Maintenance Schedule

- **Weekly**: Admin visits Peppol page to check for source changes
- **Monthly**: Verify pricing data is current (fees changed July 2025)
- **Quarterly**: Update Belgian platforms list from Dexxter
- **As needed**: Add new certified Peppol providers to the registry

## Backup & Recovery

The SourceChecker system stores:
- Full extracted text in `source_snapshots.extracted_text`
- LLM output in `page_update_queue.llm_output`
- All data is in the MariaDB `bookstack` database

To recover from data loss:
1. Re-add source URLs via the API or UI
2. Re-run source checks to recompute hashes
3. Trigger LLM updates if page content needs regeneration