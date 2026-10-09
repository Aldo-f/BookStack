# Plan: Ensure sources are not stored in page content and are correctly exported

## Goal
Ensure that the stored HTML content of BookStack pages does not contain the digipunt-sources component or manual source references, and that EPUB exports correctly include the sources linked via the digipunt-sources mechanism.

## Current context / assumptions
- Working directory: `/mnt/aldo/dev/06-apps-bookstack`
- BookStack fork with digipunt theme and autonomous documentation freshness system (spec 001).
- The digipunt theme is located in `themes/digipunt/`.
- Page content is stored in the `entity_page_data` table (or accessible via API `raw_html` field).
- The `html` field from the API returns rendered content (with theme-injected components), while `raw_html` returns the stored content.
- From inspection, the stored content (`raw_html`) still contains some digipunt-sources markup, which should not be present.
- EPUB export feature exists but needs verification that it includes sources correctly.

## Architecture / proposed approach
1. Prevent the digipunt-sources markup from being saved to the page content by modifying the theme's integration with BookStack's editor/save pipeline.
   - Investigate how the theme injects the digipunt-sources markup (likely via `WEB_MIDDLEWARE_AFTER` or similar).
   - Ensure this injection only happens for display, not for content that is saved.
2. Verify that the stored content (`raw_html`) is clean of digipunt-sources divs and manual source references.
3. Ensure EPUB export functionality retrieves and includes the sources linked to a page (via the digipunt-sources system) in the exported file.
4. Write tests to validate both the storage and export behavior.

## Step-by-step tasks

### Task 1: Examine how digipunt-sources markup is injected into page content
- Read the theme's `functions.php` and any theme hook files to see where digipunt-sources is added.
- Check if the theme uses BookStack's view composers or middleware to inject content.
- Expected output: Identify the specific code that adds the digipunt-sources divs to the page body.

### Task 2: Modify injection to exclude content intended for storage
- Adjust the injection logic so that digipunt-sources markup is only added when rendering for display, not when the content is being saved or retrieved via API for editing.
- This may involve checking the request context (e.g., whether it's an API request vs. a web request) or using a flag.
- Expected output: After modification, the API's `raw_html` field for a page should not contain any digipunt-sources divs.

### Task 3: Verify stored content is clean
- Fetch a page via the API and check the `raw_html` field for absence of digipunt-sources divs.
- Also check that no manual source references (like `<em>Bronnen</em>`) are present.
- Expected output: `raw_html` contains no digipunt-sources divs and no manual source lines.

### Task 4: Ensure EPUB export includes sources
- Examine the EPUB export code (likely in the theme or a plugin) to see how it retrieves page content.
- Verify that it uses the stored content (without sources) and then appends or includes the sources linked via the digipunt-sources system.
- If necessary, modify the export to fetch the sources for the page and include them in the EPUB.
- Expected output: An EPUB export of a page with sources includes the source information (e.g., as a bibliography or linked notes).

### Task 5: Write and run tests
- Create a test that:
  1. Creates a page with a source linked via the digipunt-sources UI.
  2. Verifies the page's stored content (`raw_html`) does not contain the source markup.
  3. Triggers an EPUB export.
  4. Verifies the exported EPUB contains the source information.
- Run the test and ensure it passes.

## Tests / validation
For each task where code is changed, follow TDD:
- Write a failing test that captures the desired behavior.
- Run the test to see it fail.
- Implement the minimal change to make the test pass.
- Run the test again to confirm.

Example test for Task 3:
```bash
# After making changes, run:
curl -s -H "Authorization: Token $TOKEN" "https://docs.digipunt.aldof.duckdns.org/api/pages/16" | jq -r '.raw_html' | grep -c "digipunt-sources"
# Expected output: 0
```

Example test for Task 4:
```bash
# Export a page as EPUB and check for source content
# (Assume we have a script or command to export)
# Check the EPUB for a section containing the source title or URL.
```

## Risks, tradeoffs, and open questions
- Risk: Changing how the theme injects content might break the display of sources on the web UI. Mitigation: Ensure the injection still occurs for web requests (non-API, non-export).
- Tradeoff: If we strip the digipunt-sources markup during save, we must ensure the sources are still linked correctly in the database (via the source_snapshots and page_update_queue tables) for the autonomous freshness system.
- Open question: How does the EPUB export currently work? Does it use the rendered HTML or the stored content? We need to inspect the export code to answer this.

## Status (updated during execution)
- **Task 1 (Inspect injection)**: Done — identified `PAGE_CONTENT_POST_RENDER` event listener in `functions.php` (line 393) that appends `digipunt-sources` panel to every render.
- **Task 2 (Modify injection)**: Done — added `if (request()->is('*/api/*')) { return $html; }` guard at the top of the listener. Deployed to pod:
  ```
  /config/www/themes/digipunt/functions.php line 394-398
  ```
- **Task 3 (Verify clean storage)**: Pending — BookStack API unreachable (connection refused from host). Internal pod curl also timed out. Fix is deployed and ready for verification once service recovers.
- **Task 4 (EPUB export includes sources)**: Reviewed — export code in same listener (lines 396-421) already appends static `<ul>` sources for export requests; no change needed.
- **Task 5 (Tests)**: Pending — write PHP test that asserts API `raw_html` contains zero `digipunt-sources` divs.

## Next concrete action
Verify fix once BookStack API is reachable:
```bash
curl -s -H "Authorization: Token $TOKEN" "https://docs.digipunt.aldof.duckdns.org/api/pages/16" | python3 -c "import sys, json, re; d=json.load(sys.stdin); print('digipunt-sources in html:', len(re.findall(r'class=\"[^\"]*digipunt-sources[^\"]*\"', d['html']))); print('digipunt-sources in raw_html:', len(re.findall(r'class=\"[^\"]*digipunt-sources[^\"]*\"', d['raw_html'])))"
# Expected output: 0 for both html and raw_html (since fix prevents API from including them)
```
