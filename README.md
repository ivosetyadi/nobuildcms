# NoBuildCMS

> A flat-file, **no-build** mini CMS & dashboard. PHP + Twig + Datastar + SSE.
> No database. No build step. No npm. Edit in the dashboard → the public site updates live.
>
> An **HTMX / Hotwire-style hypermedia** app — and one of the more complete
> **Datastar + PHP examples** around.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)

NoBuildCMS renders every public page from a **JSON record** through a sandboxed
**Twig** template. Content bodies can query other collections, pull datasets and
snippets, and embed **Datastar** hypermedia — all server-rendered and patched live
over **Server-Sent Events**. The whole thing runs on PHP + Composer; styling is
Tailwind via CDN and Datastar is vendored, so there is genuinely nothing to build.

If you like **HTMX** or **Hotwire/Turbo** but want it in plain **PHP** with no build
pipeline, this is a full working example of the same hypermedia idea powered by
[Datastar](https://data-star.dev).

## Highlights

- 📄 **Flat-file store** — everything lives in `data/*.json`; Git-friendly, zero config
- 🧩 **Twig content** — `collection()`, `record()`, `data()`, `snippet()`, `attrs()`, filters `money`/`fdate`, all sandboxed
- ⚡ **Live updates** — dashboard edits appear on open public tabs; the editor patches over Datastar SSE (no reload)
- ✍️ **Rich editor** — off-canvas drawer with 4 tabs (Content / Fields / Attributes & SEO / Preview), inline rename, live preview, media picker
- 🗂️ **Content tools** — search, filter, sort, bulk publish/draft/trash, duplicate, drag-to-reorder
- 📊 **Dashboard** — composition donut, most-read, low-stock, activity feed (all inline SVG, no chart lib)
- 👥 **RBAC + audit + trash** — Owner/Editor/Viewer, write-once audit log (CSV export), soft-delete Trash
- 📚 **Library** — reusable Snippets (`snippet()`), Datasets (`data()`), and Media uploads
- 💬 **Visitor chat** — floating lobby widget, a rule-based auto-assistant, and staff replies from the dashboard
- 🧑‍🤝‍🧑 **Visitors** — presence tracking with an online counter
- 🏢 **Workspaces** — multi-tenant panel (name = alias, key = minted once, suffix = true identity)
- 🔬 **Datastar Lab** — 11 live hypermedia examples (search, click-to-edit, load-more, validation, tabs, polling…)
- 🎨 **Theme & currency** — dark/light toggle, configurable accent color, any currency with cents
- 🆓 **No build step** — Tailwind via CDN, Datastar vendored (MIT), flat-file data

## Deploy

NoBuildCMS is a PHP app, so it needs a host that runs PHP (not static-only
GitHub Pages). A `Dockerfile` is included — deploy it anywhere that runs
containers.

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/ivosetyadi/nobuildcms)

- **Render / Railway / Fly.io / Koyeb** — point at this repo (uses `render.yaml` /
  the `Dockerfile`). Free tiers work. Note: the flat-file data is ephemeral on
  most free hosts, so edits reset on redeploy — fine for a demo.
- **Static preview** — the included GitHub Actions workflow renders the *public*
  pages to static HTML and publishes them to GitHub Pages. That snapshot is
  read-only (no dashboard, login, chat, or live editing — those need PHP).

## Quick start

Requires **PHP 8.2+** and **Composer**.

```bash
composer install
composer serve          # => http://localhost:8000
# or: php -S localhost:8000 -t public public/index.php
```

- Public site → http://localhost:8000
- Dashboard → http://localhost:8000/admin

> If port 8000 is unavailable (some Windows setups reserve it), pick another, e.g. `8787`.

**Demo login:** `super@admin.com` / `admin123`
(also `editor@nobuildcms.test` and `viewer@nobuildcms.test`, same password). **Change these before deploying.**

> Try it: open the public site and the dashboard side by side, edit a title or price,
> hit Save — the public tab updates itself.

## Dev & production notes

- The public site **live-reloads via lightweight polling**, so it works on any server
  including the single-threaded `php -S`. The dashboard's live editing uses Datastar
  `@get`/`@post` requests that return short, `Content-Length`-delimited SSE patches.
- A streaming SSE endpoint (`/sse/reload`) is included for production. Behind a real
  server (nginx/Apache + PHP-FPM), disable output buffering for the `/sse/*` location.

## Project structure

```
nobuildcms/
├── public/
│   ├── index.php            # front controller + router
│   └── assets/datastar.js   # vendored Datastar (MIT)
├── src/
│   ├── App.php              # Twig envs + collection/data/snippet/record/attrs/money/fdate
│   ├── Store.php            # flat-file JSON store
│   ├── Auth.php             # session auth + roles
│   ├── Ds.php               # Datastar SSE patch helpers
│   ├── Audit.php            # append-only audit log
│   ├── Trash.php            # soft-delete store
│   └── Visitors.php         # visitor presence
├── templates/               # Twig: site + admin
├── data/                    # settings, pages, posts, products, users, … (JSON)
└── composer.json
```

## Content template reference

```twig
{{ item.title }}                      {# this record's title #}
{{ item.attrs.price|money }}          {# $1,250.00 — currency from Settings #}
{{ now|fdate('d M Y') }}

{% for p in collection('posts', {sort:'-created_at', limit:3, tag:'datastar'}) %}
  <a href="{{ p.url }}">{{ p.title }}</a>
{% endfor %}

{{ record('product','sunrise-keyboard').attrs.price }}
{{ snippet('cta', {limit: 3}) }}
{% for m in data('team') %}{{ m.name }}{% endfor %}

{# Datastar works inside content too #}
<div data-signals="{qty: 1}">
  <input type="number" data-bind="qty">
  Total: <span data-text="$qty * 100"></span>
</div>
```

## Status

Feature-complete across the core CMS, the editor, RBAC/audit/trash, workspaces,
the library, visitor chat, and the Datastar Lab. Contributions welcome.

## License

[MIT](LICENSE) © 2026 Ivo Setyadi and NoBuildCMS contributors.
Bundled and third-party components and their licenses are listed in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
