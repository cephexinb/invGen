import html
import json
import re
import sqlite3
import threading
import time
from dataclasses import dataclass, asdict
from datetime import datetime, timezone
from typing import Dict, List, Optional, Tuple
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen

BASE_URL = "https://cleopatrarentals.one"
DEFAULT_INDEX_INTERVAL_SECONDS = 60 * 60 * 6  # every 6 hours

STOP_WORDS = {
    "a", "an", "and", "or", "the", "to", "for", "of", "in", "on", "at", "is", "are",
    "with", "i", "we", "you", "it", "this", "that", "about", "from", "be", "as", "by",
    "my", "me", "our", "your", "can", "could", "would", "should", "please", "need", "want",
}


@dataclass
class Listing:
    url: str
    title: str
    description: str
    price_text: str
    bedrooms_text: str
    location_text: str
    amenities_text: str
    last_seen_utc: str

    @property
    def combined_text(self) -> str:
        return " ".join([
            self.title,
            self.description,
            self.price_text,
            self.bedrooms_text,
            self.location_text,
            self.amenities_text,
        ]).strip().lower()


class ListingBot:
    def __init__(self, db_path: str = "backend/listings.db", base_url: str = BASE_URL):
        self.db_path = db_path
        self.base_url = base_url.rstrip("/")
        self._lock = threading.Lock()
        self._ensure_db()

    def _ensure_db(self) -> None:
        with sqlite3.connect(self.db_path) as con:
            con.execute(
                """
                CREATE TABLE IF NOT EXISTS listings (
                    url TEXT PRIMARY KEY,
                    title TEXT,
                    description TEXT,
                    price_text TEXT,
                    bedrooms_text TEXT,
                    location_text TEXT,
                    amenities_text TEXT,
                    last_seen_utc TEXT
                )
                """
            )
            con.execute(
                """
                CREATE TABLE IF NOT EXISTS meta (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
                """
            )

    def _fetch(self, url: str, timeout: int = 15) -> str:
        req = Request(
            url,
            headers={
                "User-Agent": "Mozilla/5.0 (compatible; CleopatraListingsBot/1.0)"
            },
        )
        with urlopen(req, timeout=timeout) as resp:
            return resp.read().decode("utf-8", errors="ignore")

    def _extract_links(self, html_text: str, page_url: str) -> List[str]:
        links = re.findall(r'href=["\'](.*?)["\']', html_text, flags=re.IGNORECASE)
        normalized = []
        for href in links:
            if href.startswith("javascript:") or href.startswith("mailto:"):
                continue
            full = urljoin(page_url, href)
            parsed = urlparse(full)
            if parsed.netloc and parsed.netloc != urlparse(self.base_url).netloc:
                continue
            clean = f"{parsed.scheme}://{parsed.netloc}{parsed.path}".rstrip("/")
            if clean:
                normalized.append(clean)
        return list(dict.fromkeys(normalized))

    def _looks_like_listing_url(self, url: str) -> bool:
        lowered = url.lower()
        return any(k in lowered for k in ["listing", "property", "rent", "apartment", "villa", "unit"])

    def _strip_html(self, html_text: str) -> str:
        txt = re.sub(r"<script[\\s\\S]*?</script>", " ", html_text, flags=re.IGNORECASE)
        txt = re.sub(r"<style[\\s\\S]*?</style>", " ", txt, flags=re.IGNORECASE)
        txt = re.sub(r"<[^>]+>", " ", txt)
        txt = html.unescape(txt)
        txt = re.sub(r"\s+", " ", txt).strip()
        return txt

    def _extract_meta(self, html_text: str, key_patterns: List[str]) -> str:
        clean = self._strip_html(html_text)
        chunks = re.split(r"[|,;•\n\r]", clean)
        for chunk in chunks:
            low = chunk.lower()
            for kp in key_patterns:
                if kp in low:
                    return chunk.strip()
        return ""

    def _extract_title(self, html_text: str) -> str:
        match = re.search(r"<title>(.*?)</title>", html_text, flags=re.IGNORECASE | re.DOTALL)
        if match:
            return self._strip_html(match.group(1))[:200]
        h1 = re.search(r"<h1[^>]*>(.*?)</h1>", html_text, flags=re.IGNORECASE | re.DOTALL)
        if h1:
            return self._strip_html(h1.group(1))[:200]
        return "Listing"

    def _extract_description(self, html_text: str) -> str:
        meta_desc = re.search(
            r'<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']',
            html_text,
            flags=re.IGNORECASE,
        )
        if meta_desc:
            return self._strip_html(meta_desc.group(1))[:1200]
        txt = self._strip_html(html_text)
        return txt[:1200]

    def _crawl_candidate_urls(self, max_pages: int = 250) -> List[str]:
        to_visit = [self.base_url]
        seen = set()
        candidates = []

        while to_visit and len(seen) < max_pages:
            current = to_visit.pop(0)
            if current in seen:
                continue
            seen.add(current)
            try:
                html_text = self._fetch(current)
            except Exception:
                continue

            for link in self._extract_links(html_text, current):
                if link not in seen and link not in to_visit and link.startswith(self.base_url):
                    to_visit.append(link)
                if self._looks_like_listing_url(link):
                    candidates.append(link)

        return list(dict.fromkeys(candidates))

    def _parse_listing(self, url: str) -> Optional[Listing]:
        try:
            html_text = self._fetch(url)
        except Exception:
            return None

        title = self._extract_title(html_text)
        description = self._extract_description(html_text)
        price_text = self._extract_meta(html_text, ["price", "$", "usd", "egp", "aed", "per month", "monthly"])
        bedrooms_text = self._extract_meta(html_text, ["bed", "bedroom", "br"])
        location_text = self._extract_meta(html_text, ["location", "area", "district", "city", "near"])
        amenities_text = self._extract_meta(html_text, ["parking", "pool", "gym", "furnished", "wifi", "balcony"])

        if len(description) < 40:
            return None

        return Listing(
            url=url,
            title=title,
            description=description,
            price_text=price_text,
            bedrooms_text=bedrooms_text,
            location_text=location_text,
            amenities_text=amenities_text,
            last_seen_utc=datetime.now(timezone.utc).isoformat(),
        )

    def reindex(self) -> Dict[str, int]:
        with self._lock:
            urls = self._crawl_candidate_urls()
            parsed = [self._parse_listing(url) for url in urls]
            listings = [p for p in parsed if p is not None]

            with sqlite3.connect(self.db_path) as con:
                for listing in listings:
                    con.execute(
                        """
                        INSERT INTO listings (url, title, description, price_text, bedrooms_text, location_text, amenities_text, last_seen_utc)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON CONFLICT(url) DO UPDATE SET
                            title=excluded.title,
                            description=excluded.description,
                            price_text=excluded.price_text,
                            bedrooms_text=excluded.bedrooms_text,
                            location_text=excluded.location_text,
                            amenities_text=excluded.amenities_text,
                            last_seen_utc=excluded.last_seen_utc
                        """,
                        (
                            listing.url,
                            listing.title,
                            listing.description,
                            listing.price_text,
                            listing.bedrooms_text,
                            listing.location_text,
                            listing.amenities_text,
                            listing.last_seen_utc,
                        ),
                    )
                con.execute(
                    """
                    INSERT INTO meta (key, value) VALUES ('last_reindex_utc', ?)
                    ON CONFLICT(key) DO UPDATE SET value=excluded.value
                    """,
                    (datetime.now(timezone.utc).isoformat(),),
                )

            return {"indexed": len(listings), "candidates": len(urls)}

    def get_last_reindex(self) -> Optional[str]:
        with sqlite3.connect(self.db_path) as con:
            row = con.execute("SELECT value FROM meta WHERE key='last_reindex_utc'").fetchone()
        return row[0] if row else None

    def _tokenize(self, text: str) -> List[str]:
        words = re.findall(r"[a-zA-Z0-9]+", text.lower())
        return [w for w in words if w not in STOP_WORDS and len(w) > 1]

    def _extract_preferences(self, query: str) -> Dict[str, str]:
        q = query.lower()
        prefs: Dict[str, str] = {}

        bed = re.search(r"(\d+)\s*(bed|bedroom|br)", q)
        if bed:
            prefs["bedrooms"] = bed.group(1)

        budget = re.search(r"(under|below|max|budget)\s*\$?\s*([0-9][0-9,]*)", q)
        if budget:
            prefs["max_budget"] = budget.group(2).replace(",", "")

        for amenity in ["parking", "pool", "gym", "furnished", "wifi", "balcony", "pet", "pets"]:
            if amenity in q:
                prefs.setdefault("amenities", "")
                prefs["amenities"] += f" {amenity}"

        near_match = re.search(r"near\s+([a-zA-Z\s]{2,30})", q)
        if near_match:
            prefs["location_hint"] = near_match.group(1).strip()

        return prefs

    def _score_listing(self, listing: Listing, query: str, prefs: Dict[str, str]) -> float:
        ltxt = listing.combined_text
        q_tokens = self._tokenize(query)
        overlap = sum(1 for t in q_tokens if t in ltxt)

        score = float(overlap)

        if "bedrooms" in prefs and prefs["bedrooms"] in listing.bedrooms_text:
            score += 4.0
        if "location_hint" in prefs and prefs["location_hint"].lower() in ltxt:
            score += 3.0
        if "amenities" in prefs:
            for am in prefs["amenities"].split():
                if am in ltxt:
                    score += 1.25

        return score

    def _load_listings(self) -> List[Listing]:
        with sqlite3.connect(self.db_path) as con:
            rows = con.execute(
                "SELECT url, title, description, price_text, bedrooms_text, location_text, amenities_text, last_seen_utc FROM listings"
            ).fetchall()
        return [Listing(*row) for row in rows]

    def answer(self, message: str) -> Dict[str, object]:
        listings = self._load_listings()
        if not listings:
            return {
                "reply": "I’m still learning your listings. Please try again shortly while I finish indexing the website.",
                "matches": [],
                "preferences": {},
            }

        prefs = self._extract_preferences(message)
        ranked = sorted(
            listings,
            key=lambda x: self._score_listing(x, message, prefs),
            reverse=True,
        )
        top = ranked[:3]

        if not top:
            return {
                "reply": "I could not find a close match yet. Could you share budget, bedrooms, and preferred location?",
                "matches": [],
                "preferences": prefs,
            }

        primary = top[0]
        suggestions = "\n".join(
            [f"- {l.title} ({l.url})" for l in top]
        )

        creative_line = ""
        if prefs:
            creative_line = "I matched options based on your preferences"
            if "bedrooms" in prefs:
                creative_line += f" ({prefs['bedrooms']} bedrooms"
                if "location_hint" in prefs:
                    creative_line += f", near {prefs['location_hint']}"
                creative_line += ")."
            else:
                creative_line += "."
        else:
            creative_line = "I picked the closest listings based on your message semantics and listing details."

        reply = (
            f"Great question! The best match right now is **{primary.title}**.\n"
            f"{creative_line}\n\n"
            f"Here are your top options:\n{suggestions}\n\n"
            f"If you want, tell me your budget, desired bedrooms, and area and I’ll refine further."
        )

        return {
            "reply": reply,
            "matches": [asdict(l) for l in top],
            "preferences": prefs,
        }


class IndexScheduler:
    def __init__(self, bot: ListingBot, interval_seconds: int = DEFAULT_INDEX_INTERVAL_SECONDS):
        self.bot = bot
        self.interval_seconds = interval_seconds
        self._stop = threading.Event()
        self._thread: Optional[threading.Thread] = None

    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            return
        self._thread = threading.Thread(target=self._loop, daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=5)

    def _loop(self) -> None:
        while not self._stop.is_set():
            try:
                self.bot.reindex()
            except Exception:
                pass
            self._stop.wait(self.interval_seconds)


def serialize_json(data: Dict[str, object]) -> bytes:
    return json.dumps(data, ensure_ascii=False).encode("utf-8")
