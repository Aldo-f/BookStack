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

	// List all books
	books, err := client.Books.List(ctx, nil)
	if err != nil {
		log.Fatalf("Failed to list books: %v", err)
	}

	fmt.Printf("=== BOOKS (%d) ===\n", len(books))
	for _, b := range books {
		fmt.Printf("  [%d] %s (%s)\n", b.ID, b.Name, b.Slug)
	}

	// List pages filtered to book 14 (Team Linux), sorted by priority
	pages, err := client.Pages.List(ctx, &bookstack.ListOptions{
		Filter: map[string]string{"book": "14"},
		Sort:   "priority",
	})
	if err != nil {
		log.Fatalf("Failed to list pages: %v", err)
	}

	fmt.Printf("\n=== TEAM LINUX PAGES (%d, sorted by priority) ===\n", len(pages))
	for i, p := range pages {
		fmt.Printf("  %d. [%d] %s (%s)\n", i+1, p.ID, p.Name, p.Slug)
	}
}
