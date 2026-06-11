import json
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from listing_bot import IndexScheduler, ListingBot, serialize_json

HOST = "0.0.0.0"
PORT = 8080

bot = ListingBot()
scheduler = IndexScheduler(bot)


class Handler(BaseHTTPRequestHandler):
    def _send_json(self, code: int, payload: dict) -> None:
        body = serialize_json(payload)
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Access-Control-Allow-Methods", "GET,POST,OPTIONS")
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self._send_json(HTTPStatus.NO_CONTENT, {})

    def do_GET(self):
        if self.path == "/health":
            self._send_json(
                HTTPStatus.OK,
                {
                    "ok": True,
                    "last_reindex_utc": bot.get_last_reindex(),
                },
            )
            return

        self._send_json(HTTPStatus.NOT_FOUND, {"error": "not found"})

    def do_POST(self):
        if self.path == "/api/reindex":
            result = bot.reindex()
            self._send_json(HTTPStatus.OK, {"ok": True, **result})
            return

        if self.path == "/api/chat":
            length = int(self.headers.get("Content-Length", "0"))
            raw = self.rfile.read(length) if length else b"{}"
            try:
                body = json.loads(raw.decode("utf-8"))
            except json.JSONDecodeError:
                self._send_json(HTTPStatus.BAD_REQUEST, {"error": "invalid json"})
                return

            message = (body.get("message") or "").strip()
            if not message:
                self._send_json(HTTPStatus.BAD_REQUEST, {"error": "message is required"})
                return

            answer = bot.answer(message)
            self._send_json(HTTPStatus.OK, answer)
            return

        self._send_json(HTTPStatus.NOT_FOUND, {"error": "not found"})


def run() -> None:
    scheduler.start()
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"Listing bot server running on http://{HOST}:{PORT}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        scheduler.stop()
        server.server_close()


if __name__ == "__main__":
    run()
