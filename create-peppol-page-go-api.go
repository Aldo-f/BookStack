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
        fmt.Println("Missing env vars. Source .env first:")
        fmt.Println("  export BOOKSTACK_TOKEN_ID=...BOOKSTACK_TOKEN_SECRET=...BOOKSTACK_URL=...")
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

    // List all books to find the right target for Peppol content
    books, err := client.Books.List(ctx, nil)
    if err != nil {
        fmt.Printf("Failed to list books: %v\n", err)
        os.Exit(1)
    }

    fmt.Printf("=== AVAILABLE BOOKS ===\n")
    var targetBookID int
    var targetBookName string
    for _, b := range books {
        fmt.Printf("  [%d] %s (%s)\n", b.ID, b.Name, b.Slug)
        if strings.Contains(strings.ToLower(b.Name), "digitale collectie gent") {
            targetBookID = b.ID
            targetBookName = b.Name
            fmt.Printf("    -> TARGET FOUND\n")
        }
    }

    if targetBookID == 0 {
        fmt.Printf("\nTarget book not found. Please choose manually from the list above.\n")
        os.Exit(1)
    }

    // Read the prepared Peppol content
    contentBytes, err := os.ReadFile("/mnt/HDD1/dev/06-apps-bookstack/.hermes/scratch/peppol-overview.md")
    if err != nil {
        fmt.Printf("Failed to read content file: %v\n", err)
        os.Exit(1)
    }
    content := string(contentBytes)

    // Convert markdown to HTML (simplified version)
    htmlContent := convertMarkdownToHTML(content)

    // Create the Peppol page
    pageName := "Peppol: Gecertificeerde aanbieders en prijzen"
    pageSlug := "peppol-aanbieders-prijzen"

    fmt.Printf("\n=== CREATING PEPPOL PAGE ===\n")
    fmt.Printf("  Title: %s\n", pageName)
    fmt.Printf("  Slug: %s\n", pageSlug)
    fmt.Printf("  Book: %s (ID %d)\n", targetBookName, targetBookID)
    fmt.Printf("  Content length: %d chars\n", len(htmlContent))

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
        os.Exit(1)
    }

    fmt.Printf("\n=== PAGE CREATED SUCCESSFULLY ===\n")
    fmt.Printf("  ID: %d\n", createdPage.ID)
    fmt.Printf("  Name: %s\n", createdPage.Name)
    fmt.Printf("  Slug: %s\n", createdPage.Slug)
    fmt.Printf("  URL: %s/%s\n", baseUrl, createdPage.Slug)
    fmt.Printf("  Book: %s\n", targetBookName)

    // Try to add a "Sources" section for automatic updates
    fmt.Printf("\n=== MANUAL STEP: SOURCES FOR AUTO-UPDATE ===\n")
    fmt.Printf("To enable automatic source updates, you need to add the following sources manually:\n")
    sources := []struct {
        name string
        url  string
    }{
        {"Peppol Certified Service Providers", "https://peppol.org/members/peppol-certified-service-providers/"},
        {"Peppol Fees", "https://peppol.org/join/fees/"},
        {"Peppol België Platforms", "https://dexxter.be/lijst-peppol-platformen-belgie/"},
    }
    for _, s := range sources {
        fmt.Printf("  - %s (%s)\n", s.name, s.url)
    }
    fmt.Printf("\nThese can be added via the BookStack Sources API or via the web UI.\n")
}

// Simplified markdown-to-HTML converter for this specific content
func convertMarkdownToHTML(md string) string {
    html := strings.ReplaceAll(md, "## ", "<h2>")
    html = strings.ReplaceAll(html, "\n### ", "</h2><h3>")
    html = strings.ReplaceAll(html, "\n#### ", "</h3><h4>")
    html = strings.ReplaceAll(html, "\n-----\n", "<hr>\n")
    html = strings.ReplaceAll(html, "\n* ", "<li>")
    html = strings.ReplaceAll(html, "\n| ", "<tr><td>")
    html = strings.ReplaceAll(html, "</td>", "</td></tr>")

    // Fix list items
    html = strings.ReplaceAll(html, "</li>\n* ", "</li><li>")
    html = strings.ReplaceAll(html, "<tr><td> ", "<tr><td>")

    // Wrap paragraphs
    lines := strings.Split(html, "\n")
    var result string
    for _, line := range lines {
        if line == "" || strings.HasPrefix(line, "<h") || strings.HasPrefix(line, "<li") || strings.HasPrefix(line, "<tr") || strings.HasPrefix(line, "<td") || strings.HasPrefix(line, "<hr") {
            result += line + "\n"
        } else if line != "" {
            result += "<p>" + line + "</p>\n"
        } else {
            result += "\n"
        }
    }

    // Replace headers and clean up
    result = strings.ReplaceAll(result, "##", "</h2>")
    result = strings.ReplaceAll(result, "<h2>", "<h2>")
    result = strings.ReplaceAll(result, "</h2></h2>", "</h2>")

    return result
}