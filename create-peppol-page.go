package main

import (
	"context"
	"fmt"
	"os"
	"strings"

	"code.beautifulmachines.dev/jakoubek/bookstack-api"
)

func main() {
	tokenID := os.Getenv("BOOKSTACK_TOKEN_ID")
	tokenSecret := os.Getenv("BOOKSTACK_TOKEN_SECRET")
	baseUrl := os.Getenv("BOOKSTACK_URL")

	if tokenID == "" || tokenSecret == "" || baseUrl == "" {
		fmt.Println("Missing env vars. Source .env first: set -a && source .env && set +a")
		return
	}

	cfg := bookstack.Config{
		BaseURL:     baseUrl,
		TokenID:     tokenID,
		TokenSecret: tokenSecret,
	}
	client, err := bookstack.NewClient(cfg)
	if err != nil {
		fmt.Printf("Failed to create client: %v\n", err)
		return
	}

	ctx := context.Background()

	// List all books to find the right target
	books, err := client.Books.List(ctx, nil)
	if err != nil {
		fmt.Printf("Failed to list books: %v\n", err)
		return
	}

	fmt.Printf("=== BOOKS (%d) ===\n", len(books))
	var targetBookID int
	var targetBookName string
	for _, b := range books {
		fmt.Printf("  [%d] %s (%s)\n", b.ID, b.Name, b.Slug)
		if strings.Contains(strings.ToLower(b.Name), "digitale collectie gent") ||
			strings.Contains(strings.ToLower(b.Name), "welkom") {
			if strings.Contains(strings.ToLower(b.Name), "digipunt") {
				// Prefer "Welkom bij Digipunt" for user-facing content
				targetBookID = b.ID
				targetBookName = b.Name
				fmt.Printf("    -> TARGET BOOK FOUND\n")
			}
		}
	}

	if targetBookID == 0 {
		fmt.Printf("\nTarget book not found. Please choose manually.\n")
		return
	}

	// Check existing pages in target book
	fmt.Printf("\n=== EXISTING PAGES IN %s ===\n", targetBookName)
	pages, err := client.Pages.List(ctx, &bookstack.ListOptions{
		Filter: map[string]string{"book": fmt.Sprintf("%d", targetBookID)},
		Sort:   "priority",
	})
	if err != nil {
		fmt.Printf("Failed to list pages: %v\n", err)
		return
	}
	fmt.Printf("  Found %d pages\n", len(pages))
	for i, p := range pages {
		fmt.Printf("    %d. [%d] %s (%s)\n", i+1, p.ID, p.Name, p.Slug)
	}

	// Create the Peppol overview page
	pageName := "Peppol: Gecertificeerde aanbieders en prijzen"
	pageSlug := "peppol-aanbieders-prijzen"

	// Read content from the prepared markdown file
	contentBytes, err := os.ReadFile("/mnt/HDD1/dev/06-apps-bookstack/.hermes/scratch/peppol-overview.md")
	if err != nil {
		fmt.Printf("Failed to read content file: %v\n", err)
		return
	}
	content := string(contentBytes)

	// Convert markdown to HTML-ish content
	// BookStack API content is HTML; we convert common markdown to HTML
	htmlContent := markdownToHTML(content)

	fmt.Printf("\n=== CREATING PAGE ===\n")
	fmt.Printf("  Title: %s\n", pageName)
	fmt.Printf("  Slug: %s\n", pageSlug)
	fmt.Printf("  Book: %s (ID %d)\n", targetBookName, targetBookID)
	fmt.Printf("  Content length: %d chars\n", len(htmlContent))

	// Create the page
	page := bookstack.Page{
		Name:    pageName,
		Slug:    pageSlug,
		Content: htmlContent,
		BookID:  targetBookID,
		Status:  "published",
		Priority: 999,
		Type:    "page",
	}

	createdPage, err := client.Pages.Create(ctx, page)
	if err != nil {
		fmt.Printf("\nPAGE CREATION FAILED: %v\n", err)
		return
	}

	fmt.Printf("\n=== PAGE CREATED SUCCESSFULLY ===\n")
	fmt.Printf("  ID: %d\n", createdPage.ID)
	fmt.Printf("  Name: %s\n", createdPage.Name)
	fmt.Printf("  Slug: %s\n", createdPage.Slug)
	fmt.Printf("  URL: %s/%s\n", baseUrl, createdPage.Slug)
	fmt.Printf("  Book: %s\n", targetBookName)

	// Add sources for auto-update
	sources := []struct {
		name    string
		url     string
		desc    string
	}{
		{"Peppol Certified Service Providers", "https://peppol.org/members/peppol-certified-service-providers/", "Officiële registry van alle gecertificeerde Peppol Service Providers (AP + SMP)"},
		{"Peppol Fees", "https://peppol.org/join/fees/", "OpenPeppol lidmaatschap- en certificatiekosten (2025/2026)"},
		{"Peppol België Platforms", "https://dexxter.be/lijst-peppol-platformen-belgie/", "Overzicht van Peppol-platformen in België"},
		{"Peppol wat is", "https://peppol.org/", "Algemene Peppol-informatie en resources"},
		{"Peppol kosten", "https://e-invoice.be/blog/kosten-peppol-e-facturatie", "Kostenoverzicht e-facturatie voor zelfstandigen"},
		{"Peppol providers vergeleken", "https://e-invoice.be/blog/best-peppol-access-points", "Vergelijking gecertificeerde Peppol access points"},
	}

	fmt.Printf("\n=== ADDING SOURCES (%d) ===\n", len(sources))
	for _, s := range sources {
		// First find the source ID (sources table in BookStack)
		// Use the digipunt API to add sources
		fmt.Printf("  Adding: %s (%s)\n", s.name, s.url)
	}
	fmt.Printf("\nSources must be added via the web UI or digipunt API endpoint.\n")
}

// markdownToHTML converts basic markdown syntax to HTML
func markdownToHTML(md string) string {
	var sb strings.Builder

	lines := strings.Split(md, "\n")
	inList := false
	inCode := false

	for i, line := range lines {
		trimmed := strings.TrimSpace(line)

		if strings.HasPrefix(trimmed, "```") {
			inCode = !inCode
			if inCode {
				sb.WriteString("<pre><code>")
			} else {
				sb.WriteString("</code></pre>\n")
			}
			continue
		}

		if inCode {
			sb.WriteString(htmlEscape(trimmed) + "\n")
			continue
		}

		if strings.HasPrefix(trimmed, "# ") {
			sb.WriteString(fmt.Sprintf("<h1>%s</h1>\n", htmlEscape(trimmed[2:])))
			inList = false
		} else if strings.HasPrefix(trimmed, "## ") {
			sb.WriteString(fmt.Sprintf("<h2>%s</h2>\n", htmlEscape(trimmed[3:])))
			inList = false
		} else if strings.HasPrefix(trimmed, "### ") {
			sb.WriteString(fmt.Sprintf("<h3>%s</h3>\n", htmlEscape(trimmed[4:])))
			inList = false
		} else if strings.HasPrefix(trimmed, "##### ") {
			sb.WriteString(fmt.Sprintf("<h4>%s</h4>\n", htmlEscape(trimmed[6:])))
			inList = false
		} else if strings.HasPrefix(trimmed, "**") {
			sb.WriteString(fmt.Sprintf("<p><strong>%s</strong></p>\n", htmlEscape(trimmed[2:len(trimmed)-2])))
			inList = false
		} else if trimmed == "" {
			sb.WriteString("\n")
			inList = false
		} else if strings.HasPrefix(trimmed, "- ") {
			if !inList {
				sb.WriteString("<ul>\n")
				inList = true
			}
			sb.WriteString(fmt.Sprintf("<li>%s</li>\n", htmlEscape(trimmed[2:])))
		} else if strings.HasPrefix(trimmed, "| ") && strings.Contains(trimmed, "|") && i+1 < len(lines) && strings.HasPrefix(lines[i+1], "|---") {
			// Table - simplified handling
			sb.WriteString("<table><thead><tr>\n")
			for _, cell := range splitTable(trimmed) {
				sb.WriteString(fmt.Sprintf("<th>%s</th>\n", htmlEscape(strings.TrimSpace(cell))))
			}
			sb.WriteString("</tr></thead><tbody>\n")
			j := i + 2
			for j < len(lines) {
				next := strings.TrimSpace(lines[j])
				if strings.HasPrefix(next, "|---") || next == "" {
					break
				}
				sb.WriteString("<tr>\n")
				for _, cell := range splitTable(next) {
					sb.WriteString(fmt.Sprintf("<td>%s</td>\n", htmlEscape(strings.TrimSpace(cell))))
				}
				sb.WriteString("</tr>\n")
				j++
			}
			sb.WriteString("</tbody></table>\n")
			i = j - 1
			inList = false
		} else if inList {
			sb.WriteString("</ul>\n")
			inList = false
			sb.WriteString(fmt.Sprintf("<p>%s</p>\n", htmlEscape(trimmed)))
		} else {
			sb.WriteString(fmt.Sprintf("<p>%s</p>\n", htmlEscape(trimmed)))
			inList = false
		}
	}

	if inList {
		sb.WriteString("</ul>\n")
	}
	if inCode {
		sb.WriteString("</code></pre>\n")
	}

	// Clean up empty paragraphs
	sb.WriteString("<p></p>\n")
	return strings.ReplaceAll(sb.String(), "<p></p>\n<p></p>\n", "<p></p>\n")
}

func splitTable(line string) []string {
	var cells []string
	current := ""
	inQuotes := false
	for _, ch := range line {
		if ch == '"' {
			inQuotes = !inQuotes
		} else if ch == '|' && !inQuotes {
			cells = append(cells, current)
			current = ""
		} else {
			current += string(ch)
		}
	}
	cells = append(cells, current)
	return cells
}

func htmlEscape(s string) string {
	s = strings.ReplaceAll(s, "&", "&amp;")
	s = strings.ReplaceAll(s, "<", "&lt;")
	s = strings.ReplaceAll(s, ">", "&gt;")
	s = strings.ReplaceAll(s, "\"", "&quot;")
	s = strings.ReplaceAll(s, "'", "&#039;")
	return s
}
