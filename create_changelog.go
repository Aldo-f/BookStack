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
        log.Fatal("Missing env vars")
    }
    cfg := bookstack.Config{BaseURL: baseUrl, TokenID: tokenID, TokenSecret: tokenSecret}
    client, err := bookstack.NewClient(cfg)
    if err != nil { log.Fatal(err) }
    ctx := context.Background()
    books, err := client.Books.List(ctx, nil)
    if err != nil { log.Fatal(err) }
    var targetID int
    for _, b := range books {
        if b.ID == 177 || strings.Contains(b.Name, "BookStack") { targetID = b.ID }
    }
    if targetID == 0 { log.Fatal("Book 177 not found") }
    page, err := client.Pages.Create(ctx, bookstack.NewPage(bookstack.Page{
        BookID: targetID,
        Name:   "Changelog",
    }))
    if err != nil { log.Fatal(err) }
    fmt.Printf("Created page ID=%d name=%s in book=%d\n", page.ID, page.Name, targetID)
}