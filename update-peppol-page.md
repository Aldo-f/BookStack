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
        os.Exit(1)
    }

    cfg := bookstack.Config{
        BaseURL:     baseUrl,
        TokenID:     tokenID,
        TokenSecret: tokenSecret,
    }
    client, err := bookstack.NewClient(cfg)
    if err != nil {
        fmt.Printf("Failed to create client: %v\n", err)
        os.Exit(1)
    }

    ctx := context.Background()

    // Read the updated Peppol content (with Let's Peppol)
    contentBytes, err := os.ReadFile("/mnt/HDD1/dev/06-apps-bookstack/.hermes/scratch/peppol-overview.md")
    if err != nil {
        fmt.Printf("Failed to read content file: %v\n", err)
        os.Exit(1)
    }
    content := string(contentBytes)

    // Update the page with Let's Peppol added
    htmlContent := convertMarkdownToHTML(content)

    // Find the page
    pages, err := client.Pages.List(ctx, &bookstack.ListOptions{
        Filter: map[string]string{"slug": "peppol-aanbieders-prijzen"},
        Sort:   "priority",
    })
    if err != nil {
        fmt.Printf("Failed to list pages: %v\n", err)
        os.Exit(1)
    }

    if len(pages) == 0 {
        fmt.Printf("Page 'peppol-aanbieders-prijzen' not found.\n")
        os.Exit(1)
    }

    page := pages[0]

    // Update page with new content
    updatedPage := bookstack.Page{
        ID:      page.ID,
        Name:    page.Name,
        Slug:    page.Slug,
        Content: htmlContent,
        BookID:  page.BookID,
        Status:  page.Status,
        Priority: page.Priority,
        Type:    page.Type,
    }

    _, err = client.Pages.Update(ctx, updatedPage)
    if err != nil {
        fmt.Printf("Failed to update page: %v\n", err)
        os.Exit(1)
    }

    fmt.Printf("=== PAGE UPDATED WITH LET'S PEPPOL ===\n")
    fmt.Printf("  Page ID: %d\n", page.ID)
    fmt.Printf("  Name: %s\n", page.Name)
    fmt.Printf("  Slug: %s\n", page.Slug)
    fmt.Printf("  URL: %s/%s\n", baseUrl, page.Slug)
    fmt.Printf("  Content length: %d chars\n", len(htmlContent))
    fmt.Printf("\n=== REMINDER: ADD SOURCES MANUALLY ===\n")
    fmt.Println("1. Go to the page in BookStack admin")
    fmt.Println("2. Click 'Sources' in the page editor")
    fmt.println("3. Add these sources:")
    fmt.Println("   - https://peppol.org/members/peppol-certified-service-providers/")
    fmt.println("   - https://peppol.org/join/fees/")
    fmt.println("   - https://dexxter.be/lijst-peppol-platformen-belgie/")
    fmt.println("   - https://letspeppol.org/nl/")
    fmt.println("   - https://e-invoice.be/blog/kosten-peppol-e-facturatie")
}

func convertMarkdownToHTML(md string) string {
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
        } else if strings.HasPrefix(trimmed, "**") && len(trimmed) >= 4 && strings.HasSuffix(trimmed, "**") {
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
            // Simple table handling
            sb.WriteString("<table>\n<tr>\n")
            cells := strings.Split(strings.TrimPrefix(trimmed, "| "), " | ")
            for _, cell := range cells {
                sb.WriteString(fmt.Sprintf("<th>%s</th>\n", htmlEscape(strings.TrimSpace(cell))))
            }
            sb.WriteString("</tr>\n")

            j := i + 2
            for j < len(lines) {
                nextLine := strings.TrimSpace(lines[j])
                if strings.HasPrefix(nextLine, "|---") || nextLine == "" {
                    break
                }
                sb.WriteString("<tr>\n")
                for _, cell := range strings.Split(nextLine, " | ") {
                    sb.WriteString(fmt.Sprintf("<td>%s</td>\n", htmlEscape(strings.TrimSpace(cell))))
                }
                sb.WriteString("</tr>\n")
                j++
            }
            sb.WriteString("</table>\n")
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

    return sb.String()
}

func htmlEscape(s string) string {
    s = strings.ReplaceAll(s, "&", "&amp;")
    s = strings.ReplaceAll(s, "<", "&lt;")
    s = strings.ReplaceAll(s, ">", "&gt;")
    s = strings.ReplaceAll(s, "\"", "&quot;")
    s = strings.ReplaceAll(s, "'", "&#039;")
    return s
}
