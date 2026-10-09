// Go script to create page 8 "Recente bugs en problemen basisinfrastructuur" using the bookstack-api client.
package main

import (
    "context"
    "fmt"
    "log"
    "os"
    "strings"

    "code.beautifulmachines.dev/jakoubek/bookstack-api"
)

func main() {
    tokenID := os.Getenv("BOOKSTACK_TOKEN_ID")
    tokenSecret := os.Getenv("BOOKSTACK_TOKEN_SECRET")
    baseUrl := os.Getenv("BOOKSTACK_URL")

    if tokenID == "" || tokenSecret == "" || baseUrl == "" {
        log.Fatal("Missing env vars. Source .env first: set -a && source .env && set +a")
    }

    cfg := bookstack.Config{
        BaseURL:     baseUrl,
        TokenID:     tokenID,
        TokenSecret: tokenSecret,
    }
    client, err := bookstack.NewClient(cfg)
    if err != nil {
        log.Fatalf("Failed to create client: %v", err)
    }

    ctx := context.Background()

    // List all books to find "Digitale Collectie Gent"
    books, err := client.Books.List(ctx, nil)
    if err != nil {
        log.Fatalf("Failed to list books: %v", err)
    }

    fmt.Printf("=== BOOKS (%d) ===\n", len(books))
    var targetBookID int
    var targetBookName string

    for _, b := range books {
        fmt.Printf("  [%d] %s (%s)\n", b.ID, b.Name, b.Slug)
        if strings.Contains(strings.ToLower(b.Name), "digitale collectie gent") ||
           strings.Contains(strings.ToLower(b.Name), "gent digital collection") ||
           b.Slug == "digitale-collectie-gent" {
            targetBookID = b.ID
            targetBookName = b.Name
            fmt.Printf("    -> TARGET BOOK FOUND\n")
        }
    }

    if targetBookID == 0 {
        fmt.Printf("\nTarget book not found. Available books above.\n")
        fmt.Printf("Please provide the target book ID or name.\n")
        return
    }

    // Check existing pages in target book to ensure we can create page 8
    fmt.Printf("\n=== CHECKING EXISTING PAGES IN %s ===\n", targetBookName)
    pages, err := client.Pages.List(ctx, &bookstack.ListOptions{
        Filter: map[string]string{"book": fmt.Sprintf("%d", targetBookID)},
        Sort:   "priority",
    })
    if err != nil {
        log.Fatalf("Failed to list pages: %v", err)
    }

    fmt.Printf("  Found %d pages\n", len(pages))
    for i, p := range pages {
        fmt.Printf("    %d. [%d] %s (%s)\n", i+1, p.ID, p.Name, p.Slug)
    }

    // Create page 8
    pageName := "8-Recente bugs en problemen basisinfrastructuur"
    pageSlug := "8-recente-bugs-en-problemen-basisinfrastructuur"

    // Create page data
    page := bookstack.Page{
        Name:    pageName,
        Slug:    pageSlug,
        Content: "## Recente bugs en problemen basisinfrastructuur\n\n" +
                 "Inhoud van het bugs-topic:\n\n" +
                 "- **Covers**: Gedefekteerde exemplaar- en beschermhoezen\n" +
                 "- **Formulierdownloads**: Problematische downloads en validatiefouten\n" +
                 "- **Cantook**: Toegangsproblemen en synchronisatieproblemen\n" +
                 "- **Carrousels**: UI-fouten en afspeelfouten\n" +
                 "- **Exemplaarregels**: Registratie- en toewijzingsfouten\n" +
                 "- **Verwijderde gebruikers**: Niet-goedgekeurde verwijderingen en restanten\n" +
                 "- **Contributieregel**: Controlefouten en validatieproblemen\n\n" +
                 "### Bekende problemen\n\n" +
                 "[Controleer dan zeker eerst de bugpagina van onze diensten: Recente bugs in basisinfrastructuur]\n",
        BookID: targetBookID,
        Status: "published",
        Priority: 8, // Page 8 in the series
        Type: "page",
    }

    // Create the page (assuming the API supports Create method)
    createdPage, err := client.Pages.Create(ctx, page)
    if err != nil {
        // Try alternative: check if API supports different method
        fmt.Printf("Page creation failed: %v\n", err)

        // If Create is not available, the user would need to manually create the page
        fmt.Printf("\nManual step required:\n")
        fmt.Printf("1. Log in to https://docs.digipunt.aldof.duckdns.org\n")
        fmt.Printf("2. Navigate to the \"Digitale Collectie Gent\" book\n")
        fmt.Printf("3. Create a new page with the following content:\n")
        fmt.Printf("   Title: \"8-Recente bugs en problemen basisinfrastructuur\"\n")
        fmt.Printf("   Slug: \"8-recente-bugs-en-problemen-basisinfrastructuur\"\n")
        fmt.Printf("   Status: Published\n")
        fmt.Printf("   Priority: 8\n")
        fmt.Printf("\nContent:\n")
        fmt.Printf(page.Content)
        return
    }

    fmt.Printf("\n=== PAGE CREATED SUCCESSFULLY ===\n")
    fmt.Printf("  ID: %d\n", createdPage.ID)
    fmt.Printf("  Name: %s\n", createdPage.Name)
    fmt.Printf("  Slug: %s\n", createdPage.Slug)
    fmt.Printf("  URL: %s/%s\n", baseUrl, createdPage.Slug)
}
