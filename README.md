# Cleopatra Rentals Smart Listings Chat Widget

This repository contains an embeddable website chat widget + a lightweight Python API that:

1. Crawls and indexes property/listing pages from `https://cleopatrarentals.one`.
2. Refreshes the index automatically on a schedule (or manually via endpoint).
3. Answers visitor questions about a listing.
4. Suggests similar or better-suited listings based on user preferences.

## Architecture

- `backend/listing_bot.py`: Listing crawler, indexer, recommender, and answer generator.
- `backend/server.py`: HTTP API with endpoints used by the widget.
- `frontend/widget.js`: Embeddable floating support chat widget.
- `frontend/widget.css`: Widget styles.

## Quick start

```bash
python3 backend/server.py
```

This starts the API server on `http://localhost:8080`.

## API endpoints

- `GET /health`
- `POST /api/reindex` -> manually refresh listings index
- `POST /api/chat`

Example payload for `/api/chat`:

```json
{
  "message": "I need a 2 bedroom apartment close to downtown with parking",
  "session_id": "visitor-123"
}
```

## Embedding the widget

Add this near the end of your HTML page:

```html
<link rel="stylesheet" href="/widget.css" />
<script>
  window.CLEO_WIDGET_CONFIG = {
    apiBaseUrl: "https://your-api-domain.com",
    title: "Cleopatra Rentals Assistant",
    greeting: "Hi 👋 Tell me what kind of rental you need and I’ll find matching listings."
  };
</script>
<script src="/widget.js" defer></script>
```

## Notes

- The crawler is intentionally defensive and generic because listing websites can change structure.
- Ranking uses keyword overlap and preference signals extracted from visitor text.
- For production, place this service behind HTTPS and add auth/rate limits for admin endpoints.
