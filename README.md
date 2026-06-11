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
- `wordpress/cleopatra-rentals-chat-widget.php`: Optional WordPress plugin wrapper.

## Quick start (API server)

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

## Deploying on WordPress (recommended path)

### 1) Deploy backend API on a VPS/server

On your API server (Ubuntu example):

```bash
# install system packages
sudo apt update && sudo apt install -y python3 python3-venv nginx

# copy this repo and run service
cd /opt
sudo git clone <your-repo-url> cleopatra-chat
cd cleopatra-chat
python3 backend/server.py
```

For production, run with `systemd` and reverse proxy via NGINX to expose HTTPS endpoint such as:

- `https://bot.cleopatrarentals.one/health`
- `https://bot.cleopatrarentals.one/api/chat`

### 2) Install WordPress plugin wrapper

### Ready-to-import ZIP (for WordPress upload)

Generate plugin ZIP with one command:

```bash
./wordpress/build_plugin_zip.sh
```

It will produce:

- `dist/cleopatra-rentals-chat-widget.zip`

Upload this ZIP in **WordPress Admin → Plugins → Add New → Upload Plugin**.

1. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
2. Zip this project (or just the plugin + frontend files) and upload it.
3. Activate **Cleopatra Rentals Smart Chat Widget**.
4. Go to **Settings → Cleopatra Chat Widget**.
5. Set:
   - Enable **Use built-in WordPress engine** (recommended for shared hosting with no command-line access), OR disable it and use external API mode.
   - If external API mode: set **API Base URL** (example: `https://bot.cleopatrarentals.one`).
   - Widget title + greeting text.
6. Click **Run Connection Test** on the settings page to verify your selected mode before testing on frontend.

The plugin auto-loads widget JS/CSS on your site frontend.


### Fix for “I’m having trouble reaching the listings service right now”

If you see this message in WordPress:

1. Go to **Settings → Cleopatra Chat Widget** and set **API Base URL** (must be HTTPS in production).
2. Test your backend directly: `https://YOUR-API-DOMAIN/health` should return JSON.
3. Test WordPress proxy route: `https://YOUR-WP-DOMAIN/wp-json/cleo-chat/v1/chat` with POST body `{"message":"hello"}`.
4. Ensure your hosting can reach the API domain outbound (firewall/DNS).

The plugin now sends browser requests to WordPress first (`/wp-json/cleo-chat/v1/chat`). In built-in mode, WordPress answers directly from a cached listings index. In external mode, WordPress forwards to your backend API.

### 3) Verify on live site

- Open your site in private browser mode.
- Click the 💬 button.
- Ask: “I need 2 bedroom with parking near downtown.”
- Check server logs to confirm `/api/chat` requests are received.

## Alternative: Manual embed in WordPress (without plugin)

If you prefer not to use the plugin:

1. Upload `frontend/widget.js` and `frontend/widget.css` to your theme assets.
2. Add this in your footer template (`footer.php`) before `</body>`:

```html
<link rel="stylesheet" href="/wp-content/themes/your-theme/assets/widget.css" />
<script>
  window.CLEO_WIDGET_CONFIG = {
    apiBaseUrl: "https://bot.cleopatrarentals.one",
    title: "Cleopatra Rentals Assistant",
    greeting: "Hi 👋 Tell me what kind of rental you need and I’ll find matching listings."
  };
</script>
<script src="/wp-content/themes/your-theme/assets/widget.js" defer></script>
```

## Notes

- The crawler is intentionally defensive and generic because listing websites can change structure.
- Ranking uses keyword overlap and preference signals extracted from visitor text.
- For production, place this service behind HTTPS and add auth/rate limits for admin endpoints.
