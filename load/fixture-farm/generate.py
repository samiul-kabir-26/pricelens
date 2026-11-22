#!/usr/bin/env python3
"""
PriceLens fixture farm generator (stdlib only).

Generates N static shop sites (shop-01.fixture.test ... shop-NN.fixture.test) that look enough like real
storefronts for the scraping pipeline to discover, fetch, extract and match offers:

  /                       home page linking to every product
  /search?q=...           nginx maps the query to /search/<slug>.html (target product first, then related items and
                          accessories) and falls back to /search/index.html (all products)
  /p/<slug>               product pages: JSON-LD (Product/Offer, AggregateOffer, @graph), microdata, or meta tags only
  /img/<slug>.png         small generated product image
  /robots.txt, /sitemap.xml
  /catalog.json           the catalogue (titles, GTINs, MPNs) the k6 script picks search inputs from

Every value is derived from a seeded RNG, so the output is identical between builds for the same arguments.

Usage:
  python3 generate.py [--sites 10] [--out ./build] [--seed 42]
  python3 generate.py --sites 10 --print-aliases     # network aliases for docker-compose.loadtest.yml
"""

from __future__ import annotations

import argparse
import html
import json
import random
import shutil
import struct
import zlib
from pathlib import Path

DOMAIN_SUFFIX = "fixture.test"

# (slug, brand, title, mpn, category, base_price_usd, gtin_kind, query_regex, accessory_of)
# query_regex is matched case-insensitively against the raw (URL-encoded) `q` argument by nginx, so it only uses
# characters that survive percent-encoding (letters, digits, '-', '.', '_') and '.?' / '.*' between tokens.
# Accessories and near-miss variants come first: nginx evaluates regex map entries in order.
CATALOG = [
    ("sony-wh-1000xm5-ear-pads", "Sony", "Replacement Ear Pads for Sony WH-1000XM5 Headphones (Pair)", "EP-XM5-PADS",
     "Headphone Accessories", 29.99, 13, r"ear.?pads", "sony-wh-1000xm5"),
    ("switch-oled-hard-case", "Orzly", "Hard Carry Case for Nintendo Switch OLED Model", "ORZ-SWOLED-CASE",
     "Gaming Accessories", 19.99, 12, r"(case|hard.?shell).*switch", "nintendo-switch-oled"),
    ("mx-master-3s-usb-c-cable", "Logitech", "USB-C Charging Cable for Logitech MX Master 3S (1 m)", "993-002153",
     "Computer Accessories", 12.99, 13, r"(cable|charging).*mx.?master", "logitech-mx-master-3s"),
    ("sony-wh-1000xm4", "Sony", "Sony WH-1000XM4 Wireless Noise Cancelling Headphones Black", "WH1000XM4/B",
     "Headphones", 279.99, 12, r"wh.?1000.?xm4", None),
    ("sony-wh-1000xm5", "Sony", "Sony WH-1000XM5 Wireless Noise Cancelling Headphones Black", "WH1000XM5/B",
     "Headphones", 399.99, 12, r"wh.?1000.?xm5", None),
    ("bose-quietcomfort-ultra", "Bose", "Bose QuietComfort Ultra Wireless Noise Cancelling Headphones Black",
     "880066-0100", "Headphones", 429.00, 12, r"quiet.?comfort.?ultra", None),
    ("apple-airpods-pro-2-usb-c", "Apple", "Apple AirPods Pro (2nd generation) with MagSafe Case (USB-C)", "MTJV3AM/A",
     "Earbuds", 249.00, 12, r"airpods.?pro", None),
    ("samsung-galaxy-buds3-pro", "Samsung", "Samsung Galaxy Buds3 Pro Wireless Earbuds Silver", "SM-R630NZAAXAR",
     "Earbuds", 249.99, 12, r"buds.?3.?pro", None),
    ("logitech-mx-master-3s", "Logitech", "Logitech MX Master 3S Performance Wireless Mouse Graphite", "910-006556",
     "Mice", 99.99, 13, r"mx.?master.?3s?", None),
    ("logitech-mx-keys-s", "Logitech", "Logitech MX Keys S Wireless Illuminated Keyboard Graphite", "920-011406",
     "Keyboards", 109.99, 13, r"mx.?keys", None),
    ("anker-737-power-bank", "Anker", "Anker 737 Power Bank (PowerCore 24K) 140W 24,000mAh", "A1289",
     "Power Banks", 109.99, 13, r"anker.*737|powercore.?24k", None),
    ("nintendo-switch-oled", "Nintendo", "Nintendo Switch OLED Model with White Joy-Con", "HEGSKAAAA",
     "Consoles", 349.99, 12, r"switch.*oled|oled.*switch", None),
    ("kindle-paperwhite-16gb", "Amazon", "Kindle Paperwhite 16 GB (2024 release) Black", "PW12-16GB-BLK",
     "E-Readers", 159.99, 12, r"paperwhite", None),
    ("gopro-hero13-black", "GoPro", "GoPro HERO13 Black Waterproof Action Camera", "CHDHX-131-RW",
     "Cameras", 399.99, 12, r"hero.?13", None),
    ("jbl-flip-6-blue", "JBL", "JBL Flip 6 Portable Waterproof Bluetooth Speaker Blue", "JBLFLIP6BLUAM",
     "Speakers", 129.95, 12, r"flip.?6", None),
    ("dyson-v15-detect-absolute", "Dyson", "Dyson V15 Detect Absolute Cordless Vacuum Cleaner", "394451-01",
     "Vacuums", 749.99, 13, r"v15.?detect", None),
    ("garmin-forerunner-265", "Garmin", "Garmin Forerunner 265 GPS Running Smartwatch Black", "010-02810-00",
     "Wearables", 449.99, 12, r"forerunner.?265", None),
    ("philips-hue-starter-kit-e26", "Philips Hue", "Philips Hue White and Color Ambiance Starter Kit E26 (3 bulbs + Bridge)",
     "563296", "Smart Home", 199.99, 13, r"hue.*starter", None),
    ("sandisk-extreme-pro-ssd-1tb", "SanDisk", "SanDisk Extreme PRO Portable SSD V2 1TB USB-C", "SDSSDE81-1T00-G25",
     "Storage", 149.99, 12, r"extreme.?pro.*ssd|sdssde81", None),
    ("kingston-fury-beast-ddr5-32gb", "Kingston", "Kingston FURY Beast 32GB (2x16GB) DDR5 6000MT/s CL40 Desktop Memory",
     "KF560C40BBK2-32", "Memory", 104.99, 13, r"fury.?beast", None),
    ("canon-eos-r50-kit", "Canon", "Canon EOS R50 Mirrorless Camera with RF-S 18-45mm Lens Black", "5811C012",
     "Cameras", 799.00, 13, r"eos.?r50", None),
]

SHOP_NAMES = [
    "Northwind Electronics", "Bluefin Gadgets", "Cedar & Volt", "Pixel Harbor", "Orbit Outlet", "Maple Tech Supply",
    "Quartz Corner", "Lumen Market", "Summit Digital", "Harbor Lane Audio", "Copperleaf Store", "Brightside Devices",
]

AVAILABILITY = [("InStock", "In stock"), ("OutOfStock", "Out of stock"), ("PreOrder", "Pre-order"),
                ("BackOrder", "Ships in 2-3 weeks")]


# --------------------------------------------------------------------------------------------------------------------
# Identifiers
# --------------------------------------------------------------------------------------------------------------------

def gtin_check_digit(data: str) -> str:
    """GS1 mod-10: weights 3,1,3,... starting from the rightmost data digit."""
    total = sum(int(d) * (3 if i % 2 == 0 else 1) for i, d in enumerate(reversed(data)))
    return str((10 - total % 10) % 10)


def make_gtin(index: int, kind: int) -> str:
    # 0-prefixed UPC-A for "US" products, 4006xxx (DE GS1 range style) for GTIN-13. Synthetic, not real products.
    if kind == 12:
        data = f"08{index:03d}7{(index * 7919) % 100000:05d}"  # 11 data digits
    else:
        data = f"4006{index:03d}{(index * 104729) % 100000:05d}"  # 12 data digits
    gtin = data + gtin_check_digit(data)
    assert len(gtin) == kind
    return gtin


def valid_gtin(gtin: str) -> bool:
    return gtin.isdigit() and len(gtin) in (8, 12, 13, 14) and gtin_check_digit(gtin[:-1]) == gtin[-1]


# --------------------------------------------------------------------------------------------------------------------
# Tiny PNG writer (solid background + a diagonal band, so images differ by product)
# --------------------------------------------------------------------------------------------------------------------

def png_bytes(seed: int, size: int = 96) -> bytes:
    rng = random.Random(seed)
    bg = (rng.randint(150, 250), rng.randint(150, 250), rng.randint(150, 250))
    fg = (rng.randint(10, 90), rng.randint(10, 90), rng.randint(10, 90))
    band = rng.randint(8, 24)
    rows = bytearray()
    for y in range(size):
        rows.append(0)
        for x in range(size):
            rows.extend(fg if abs(x - y) < band or (x + y) % 37 < 3 else bg)

    def chunk(tag: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + tag + data + struct.pack(">I", zlib.crc32(tag + data) & 0xFFFFFFFF)

    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(bytes(rows), 9))
            + chunk(b"IEND", b""))


# --------------------------------------------------------------------------------------------------------------------
# HTML
# --------------------------------------------------------------------------------------------------------------------

def esc(s: object) -> str:
    return html.escape(str(s), quote=True)


def page(shop: dict, title: str, body: str, head: str = "") -> str:
    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(title)} | {esc(shop['name'])}</title>
{head}
<style>body{{font-family:system-ui,sans-serif;max-width:960px;margin:0 auto;padding:16px;color:#222}}
.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}}
.card{{border:1px solid #ddd;border-radius:8px;padding:10px}} .price{{font-weight:700;font-size:1.3em}}
.was{{text-decoration:line-through;color:#888}} header a{{margin-right:12px}}</style>
</head>
<body>
<header><a href="/"><strong>{esc(shop['name'])}</strong></a>
<form action="/search" method="get" style="display:inline"><input name="q" placeholder="Search products"><button>Search</button></form>
<a href="/cart">Cart</a></header>
<main>
{body}
</main>
<footer><p>&copy; 2026 {esc(shop['name'])}. Fixture site for PriceLens load tests.</p></footer>
</body>
</html>
"""


def fmt_price(value: float, style: int) -> str:
    # A little locale/format variety on the visible price (the structured data always has a plain decimal).
    if style == 0:
        return f"${value:,.2f}"
    if style == 1:
        return f"US$ {value:,.2f}"
    return f"{value:,.2f} USD"


def card(shop: dict, offer: dict) -> str:
    p = offer["product"]
    return (f'<div class="card product-card"><a class="product-card__link" href="/p/{p["slug"]}">'
            f'<img src="/img/{p["slug"]}.png" alt="" width="96" height="96">'
            f'<div class="product-card__title">{esc(p["title"])}</div></a>'
            f'<div class="product-card__price">{esc(fmt_price(offer["price"], shop["price_style"]))}</div></div>')


def jsonld_product(shop: dict, offer: dict, variant: str) -> str:
    p = offer["product"]
    url = f"http://{shop['host']}/p/{p['slug']}"
    gtin_key = {"gtin13": "gtin13", "gtin12": "gtin12", "gtin": "gtin"}[offer["gtin_key"]]
    o: dict = {
        "@type": "Offer",
        "url": url,
        "priceCurrency": "USD",
        "price": f"{offer['price']:.2f}",
        "availability": f"https://schema.org/{offer['availability'][0]}",
        "itemCondition": f"https://schema.org/{offer['condition']}",
        "seller": {"@type": "Organization", "name": shop["name"]},
    }
    if offer["list_price"]:
        o["priceSpecification"] = {"@type": "UnitPriceSpecification", "priceType": "https://schema.org/ListPrice",
                                   "price": f"{offer['list_price']:.2f}", "priceCurrency": "USD"}
    if variant == "aggregate":
        o = {"@type": "AggregateOffer", "priceCurrency": "USD", "lowPrice": f"{offer['price']:.2f}",
             "highPrice": f"{offer['price'] * 1.06:.2f}", "offerCount": 2, "offers": [o]}
    product = {
        "@context": "https://schema.org",
        "@type": "Product",
        "name": p["title"],
        "image": [f"http://{shop['host']}/img/{p['slug']}.png"],
        "description": f"{p['title']} from {p['brand']}. Category: {p['category']}.",
        "sku": f"{shop['sku_prefix']}-{p['index']:04d}",
        "mpn": p["mpn"],
        gtin_key: p["gtin"],
        "brand": {"@type": "Brand", "name": p["brand"]},
        "category": p["category"],
        "offers": o,
    }
    if offer["rating"]:
        product["aggregateRating"] = {"@type": "AggregateRating", "ratingValue": offer["rating"][0],
                                      "reviewCount": offer["rating"][1]}
    if variant == "graph":
        product.pop("@context")
        doc = {"@context": "https://schema.org", "@graph": [
            {"@type": "BreadcrumbList", "itemListElement": [
                {"@type": "ListItem", "position": 1, "name": "Home", "item": f"http://{shop['host']}/"},
                {"@type": "ListItem", "position": 2, "name": p["category"]}]},
            product]}
    else:
        doc = product
    return f'<script type="application/ld+json">{json.dumps(doc, indent=1)}</script>'


def product_page(shop: dict, offer: dict, related: list[dict]) -> str:
    p = offer["product"]
    fmt = offer["format"]
    url = f"http://{shop['host']}/p/{p['slug']}"
    avail_code, avail_label = offer["availability"]
    price_txt = fmt_price(offer["price"], shop["price_style"])
    was = f'<span class="was" data-testid="price-was">{esc(fmt_price(offer["list_price"], shop["price_style"]))}</span>' \
        if offer["list_price"] else ""
    cond_txt = "Renewed / refurbished" if offer["condition"] == "RefurbishedCondition" else "New"

    head = [f'<link rel="canonical" href="{url}">',
            f'<meta property="og:title" content="{esc(p["title"])}">',
            f'<meta property="og:image" content="http://{shop["host"]}/img/{p["slug"]}.png">',
            f'<meta property="og:url" content="{url}">',
            f'<meta name="description" content="Buy {esc(p["title"])} at {esc(shop["name"])}.">']
    if fmt == "meta":
        head += ['<meta property="og:type" content="product">',
                 f'<meta property="product:price:amount" content="{offer["price"]:.2f}">',
                 '<meta property="product:price:currency" content="USD">',
                 f'<meta property="product:availability" content="{"instock" if avail_code == "InStock" else "oos"}">',
                 f'<meta property="product:condition" content="{"refurbished" if cond_txt != "New" else "new"}">',
                 f'<meta property="product:brand" content="{esc(p["brand"])}">',
                 f'<meta property="product:retailer_item_id" content="{shop["sku_prefix"]}-{p["index"]:04d}">',
                 f'<meta property="og:site_name" content="{esc(shop["name"])}">']
    elif fmt.startswith("jsonld"):
        head.append(jsonld_product(shop, offer, fmt.split(":", 1)[1]))

    if fmt == "microdata":
        avail_link = f'<link itemprop="availability" href="https://schema.org/{avail_code}">'
        body = f"""
<article itemscope itemtype="https://schema.org/Product">
  <h1 class="product-title" itemprop="name">{esc(p['title'])}</h1>
  <img itemprop="image" src="/img/{p['slug']}.png" alt="{esc(p['title'])}" width="96" height="96">
  <p>Brand: <span itemprop="brand" itemscope itemtype="https://schema.org/Brand"><span itemprop="name">{esc(p['brand'])}</span></span></p>
  <p>Model: <span itemprop="mpn">{esc(p['mpn'])}</span> &middot; GTIN: <span itemprop="{offer['gtin_key']}">{p['gtin']}</span></p>
  <div itemprop="offers" itemscope itemtype="https://schema.org/Offer">
    <meta itemprop="priceCurrency" content="USD">
    <p><span class="price" data-testid="price-current" itemprop="price" content="{offer['price']:.2f}">{esc(price_txt)}</span> {was}</p>
    {avail_link}<link itemprop="itemCondition" href="https://schema.org/{offer['condition']}">
    <p class="stock-status">{esc(avail_label)} &middot; {cond_txt}</p>
  </div>
  <p itemprop="description">{esc(p['title'])} from {esc(p['brand'])}.</p>
</article>"""
    else:
        ident = "" if fmt == "meta" else f"<p>Model: {esc(p['mpn'])} &middot; GTIN: {p['gtin']}</p>"
        body = f"""
<article>
  <h1 class="product-title">{esc(p['title'])}</h1>
  <img src="/img/{p['slug']}.png" alt="{esc(p['title'])}" width="96" height="96">
  <p>Brand: {esc(p['brand'])}</p>
  {ident}
  <p><span class="price" data-testid="price-current">{esc(price_txt)}</span> {was}</p>
  <p class="stock-status">{esc(avail_label)} &middot; {cond_txt}</p>
  <button type="button" {'disabled' if avail_code == 'OutOfStock' else ''}>Add to cart</button>
</article>"""

    if related:
        body += "\n<section><h2>Customers also viewed</h2><div class=\"grid\">" + \
                "".join(card(shop, r) for r in related) + "</div></section>"
    return page(shop, p["title"], body, "\n".join(head))


def search_page(shop: dict, query_label: str, offers: list[dict], empty_suggestions: list[dict]) -> str:
    if offers:
        body = (f"<h1>Search results for “{esc(query_label)}”</h1><p>{len(offers)} results</p>"
                f'<div class="grid product-grid">{"".join(card(shop, o) for o in offers)}</div>')
    else:
        body = (f"<h1>No results for “{esc(query_label)}”</h1><p>Popular right now:</p>"
                f'<div class="grid">{"".join(card(shop, o) for o in empty_suggestions)}</div>')
    return page(shop, f"Search: {query_label}", body, '<meta name="robots" content="noindex,follow">')


# --------------------------------------------------------------------------------------------------------------------
# Site generation
# --------------------------------------------------------------------------------------------------------------------

def build_catalog() -> list[dict]:
    products = []
    for i, (slug, brand, title, mpn, category, price, kind, regex, accessory_of) in enumerate(CATALOG, start=1):
        gtin = make_gtin(i, kind)
        assert valid_gtin(gtin), gtin
        products.append({"index": i, "slug": slug, "brand": brand, "title": title, "mpn": mpn, "category": category,
                         "base_price": price, "gtin": gtin, "query_regex": regex, "accessory_of": accessory_of,
                         "accessory": accessory_of is not None})
    return products


def charm(value: float, rng: random.Random) -> float:
    whole = int(value)
    return round(whole + rng.choice([0.99, 0.95, 0.49, 0.00, 0.99, 0.99]), 2)


def build_shop(n: int, products: list[dict], seed: int) -> dict:
    rng = random.Random(seed * 1000 + n)
    host = f"shop-{n:02d}.{DOMAIN_SUFFIX}"
    shop = {"n": n, "host": host, "name": SHOP_NAMES[(n - 1) % len(SHOP_NAMES)] + ("" if n <= len(SHOP_NAMES) else f" {n}"),
            "sku_prefix": f"S{n:02d}", "price_style": rng.randrange(3), "offers": {}}
    # Each shop leans on one structured-data style, with some pages in the others.
    lean = ["jsonld:plain", "jsonld:plain", "jsonld:graph", "microdata", "jsonld:aggregate"][n % 5]
    for p in products:
        # The first nine catalogue entries (three accessories, the XM4/XM5 pair, four flagships) are stocked by every
        # shop, so load-test searches for them always find offers on all five domains.
        carried = p["index"] <= 9 or rng.random() < 0.85
        if not carried:
            continue
        roll = rng.random()
        fmt = lean if roll < 0.6 else ("meta" if roll < 0.75 else
                                       "microdata" if roll < 0.87 else rng.choice(["jsonld:plain", "jsonld:graph"]))
        price = charm(p["base_price"] * rng.uniform(0.88, 1.10), rng)
        list_price = charm(p["base_price"] * 1.12, rng) if rng.random() < 0.25 else None
        a = rng.random()
        availability = AVAILABILITY[0] if a < 0.85 else AVAILABILITY[1] if a < 0.94 else rng.choice(AVAILABILITY[2:])
        condition = "RefurbishedCondition" if rng.random() < 0.07 else "NewCondition"
        gtin_key = "gtin12" if len(p["gtin"]) == 12 else rng.choice(["gtin13", "gtin13", "gtin"])
        rating = (round(rng.uniform(3.6, 4.9), 1), rng.randint(3, 2400)) if rng.random() < 0.6 else None
        shop["offers"][p["slug"]] = {"product": p, "format": fmt, "price": price, "list_price": list_price,
                                     "availability": availability, "condition": condition, "gtin_key": gtin_key,
                                     "rating": rating}
    return shop


def related_offers(shop: dict, products: list[dict], target: dict, rng: random.Random, count: int) -> list[dict]:
    offers = shop["offers"]
    first = [offers[q["slug"]] for q in products
             if q["slug"] != target["slug"] and q["slug"] in offers
             and (q["accessory_of"] == target["slug"] or target["accessory_of"] == q["slug"]
                  or (q["category"] == target["category"]))]
    rest = [o for s, o in offers.items() if s != target["slug"] and o not in first]
    rng.shuffle(rest)
    return (first + rest)[:count]


def write_site(root: Path, shop: dict, products: list[dict], seed: int) -> None:
    rng = random.Random(seed * 7 + shop["n"])
    site = root / "sites" / shop["host"]
    for sub in ("p", "search", "img"):
        (site / sub).mkdir(parents=True, exist_ok=True)
    offers = shop["offers"]
    all_offers = [offers[p["slug"]] for p in products if p["slug"] in offers]

    for o in all_offers:
        p = o["product"]
        (site / "p" / f"{p['slug']}.html").write_text(
            product_page(shop, o, related_offers(shop, products, p, rng, 4)), encoding="utf-8")
        (site / "img" / f"{p['slug']}.png").write_bytes(png_bytes(p["index"]))

    popular = all_offers[:3]
    for p in products:
        if p["slug"] in offers:
            hits = [offers[p["slug"]]] + related_offers(shop, products, p, rng, 5)
        else:
            hits = []
        (site / "search" / f"{p['slug']}.html").write_text(search_page(shop, p["title"], hits, popular), encoding="utf-8")
    shuffled = all_offers[:]
    rng.shuffle(shuffled)
    (site / "search" / "index.html").write_text(search_page(shop, "all products", shuffled, popular), encoding="utf-8")

    home = "<h1>Featured products</h1><div class=\"grid\">" + "".join(card(shop, o) for o in all_offers) + "</div>"
    (site / "index.html").write_text(page(shop, "Home", home), encoding="utf-8")
    (site / "robots.txt").write_text(
        "User-agent: *\nAllow: /\nDisallow: /cart\nDisallow: /checkout\nDisallow: /account\n\n"
        f"Sitemap: http://{shop['host']}/sitemap.xml\n", encoding="utf-8")
    urls = "".join(f"<url><loc>http://{shop['host']}/p/{o['product']['slug']}</loc></url>" for o in all_offers)
    (site / "sitemap.xml").write_text(
        f'<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>\n',
        encoding="utf-8")


def write_catalog(root: Path, products: list[dict], shops: list[dict]) -> None:
    catalog = {
        "sites": [s["host"] for s in shops],
        "products": [{k: p[k] for k in ("slug", "brand", "title", "mpn", "gtin", "category", "base_price", "accessory")}
                     | {"stocked_by": [s["host"] for s in shops if p["slug"] in s["offers"]]} for p in products],
    }
    data = json.dumps(catalog, indent=1)
    (root / "catalog.json").write_text(data, encoding="utf-8")
    for s in shops:
        (root / "sites" / s["host"] / "catalog.json").write_text(data, encoding="utf-8")


def write_nginx(root: Path, products: list[dict], shops: list[dict]) -> None:
    lines = ["# Generated by load/fixture-farm/generate.py. Do not edit by hand.",
             "# Maps the raw ?q= argument to a per-product search results page; first matching regex wins.",
             "map $arg_q $fixture_search_slug {", '    default "";']
    for p in products:
        # ".?" between tokens must also span an encoded space ("%20"), hence ".{0,3}".
        regex = p["query_regex"].replace(".?", ".{0,3}")
        lines.append(f'    "~*{regex}" {p["slug"]};')
        lines.append(f'    "~{p["gtin"]}" {p["slug"]};')
    lines.append("}\n")
    for s in shops:
        lines += ["server {", "    listen 80;", f"    server_name {s['host']};",
                  f"    root /srv/fixture-farm/sites/{s['host']};", "    include /etc/nginx/fixture-common.conf;", "}\n"]
    (root / "sites.conf").write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--sites", type=int, default=10, help="number of shop sites (default 10)")
    ap.add_argument("--out", type=Path, default=Path(__file__).resolve().parent / "build")
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--print-aliases", action="store_true", help="print compose network aliases and exit")
    args = ap.parse_args()
    if not 1 <= args.sites <= 99:
        ap.error("--sites must be between 1 and 99")

    if args.print_aliases:
        for n in range(1, args.sites + 1):
            print(f"          - shop-{n:02d}.{DOMAIN_SUFFIX}")
        return

    products = build_catalog()
    shops = [build_shop(n, products, args.seed) for n in range(1, args.sites + 1)]
    if args.out.exists():
        shutil.rmtree(args.out)
    args.out.mkdir(parents=True)
    for s in shops:
        write_site(args.out, s, products, args.seed)
    write_catalog(args.out, products, shops)
    write_nginx(args.out, products, shops)
    pages = sum(len(s["offers"]) for s in shops)
    print(f"Generated {len(shops)} sites, {pages} product pages, {len(products)} catalogue items in {args.out}")


if __name__ == "__main__":
    main()
