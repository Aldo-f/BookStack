package main

import (
    "context"
    "fmt"
    "log"
    "os"

    "code.beautifulmachines.dev/jakoubek/bookstack-api"
)

func main() {
    tokenID := os.Getenv("BOOKSTACK_TOKEN_ID")
    tokenSecret := os.Getenv("BOOKSTACK_TOKEN_SECRET")
    baseUrl := os.Getenv("BOOKSTACK_URL")

    if tokenID == "" || tokenSecret == "" || baseUrl == "" {
        log.Fatal("Missing env vars")
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

    // List pages in book 2 (Digitale Collectie Gent)
    pages, err := client.Pages.List(ctx, &bookstack.ListOptions{
        Filter: map[string]string{"book": "2"},
        Sort:   "priority",
    })
    if err != nil {
        log.Fatalf("Failed to list pages: %v", err)
    }

    fmt.Printf("=== DIGITALE COLLECTIE GENT PAGES (%d) ===\n", len(pages))
    for i, p := range pages {
        fmt.Printf("  %d. [%d] %s (%s) priority=%d\n", i+1, p.ID, p.Name, p.Slug, p.Priority)
    }
}
