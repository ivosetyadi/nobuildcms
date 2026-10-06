# NoBuildCMS

> A flat-file, **no-build** mini CMS & dashboard. PHP + Twig + Datastar + SSE.
> No database. No build step. No npm. Edit in the dashboard → the public site updates in real time.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)

NoBuildCMS renders every public page from a **JSON record** through a sandboxed
**Twig** template. Content bodies can query other collections, pull datasets and
snippets, and embed **Datastar** hypermedia — all server-rendered and pushed live
over **Server-Sent Events**.

## Features (current)

- 📄 **Flat-file store** — content lives in `data/*.json`, Git-friendly, zero config
- 🧩 **Twig content** — `collection()`, `record()`, `data()`, `snippet()`, `attrs()`, filters `money`/`fdate`, sandboxed
- ⚡ **SSE live-reload** — save in the dashboard, open public tabs refresh themselves
- 🔐 **Admin dashboard** — session login, content CRUD (Pages/Articles/Products), settings
- 💬 **Visitor chat** — floating lobby widget on the site, staff replies from the dashboard
- 📚 **Library** — reusable Snippets (`snippet()`), Datasets (`data()`), and Media uploads
- 💱 **Configurable currency** — USD default with cents; set any currency in Settings
- 🎨 **Tailwind via CDN** + **Datastar** vendored — genuinely no build step

## Quick start

Requires **PHP 8.2+** and **Composer**.

```bash
composer install
composer serve          # => http://localhost:8000
# or: php -S localhost:8000 -t public public/index.php
```

Open:
- Public site → http://localhost:8000
- Dashboard → http://localhost:8000/admin

**Demo login:** `super@admin.com` / `admin123`
(also `editor@…` editor, `viewer@…` viewer — same password). **Change these before deploying.**

> Tip: open the public site and the dashboard side by side, edit a title or price,
> hit Save — the public tab reloads itself via SSE.

### SSE on the dev server

PHP's built-in server is single-threaded. To keep the SSE channel from blocking
other requests during local development, start it with workers:

```bash
PHP_CLI_SERVER_WORKERS=8 php -S localhost:8000 -t public public/index.php
```

For production, run behind a real server (nginx/Apache + PHP-FPM) and disable
output buffering for the `/sse/*` location.

## Project structure

```
nobuildcms/
├── public/
│   ├── index.php            # front controller + router
│   └── assets/datastar.js   # vendored Datastar (MIT)
├── src/
│   ├── App.php              # Twig envs + collection/data/snippet/record/attrs/money/fdate
│   ├── Store.php            # flat-file JSON store
│   └── Auth.php             # session auth + roles
├── templates/               # Twig: site + admin
├── data/                    # settings, pages, posts, products, users (JSON)
└── composer.json
```

## Content template reference

```twig
{{ item.title }}                      {# this record's title #}
{{ item.attrs.price|money }}          {# Rp 1.250.000 #}
{{ now|fdate('d M Y') }}

{% for p in collection('posts', {sort:'-created_at', limit:3, tag:'datastar'}) %}
  <a href="{{ p.url }}">{{ p.title }}</a>
{% endfor %}

{{ record('product','keyboard-sunrise').attrs.price }}
{{ snippet('faq_block', {limit: 3}) }}
{% for m in data('team') %}{{ m.name }}{% endfor %}
```

## Roadmap

- [x] **Phase 1** — MVP core (public render, SSE reload, auth, content CRUD, settings)
- [x] **Phase 2 (library)** — Snippets, Datasets, Media upload
- [x] **Phase 2 (editor UX)** — off-canvas editor, inline edit (Datastar `@get`/`@post` → SSE patches)
- [x] **Phase 3** — Real-time visitor chat (lobby widget + staff replies in the dashboard)
- [ ] **Phase 4** — RBAC (Owner/Editor/Viewer), immutable Audit log, Trash
- [ ] **Phase 5** — Multi-tenant Workspaces, Datastar Lab examples

## License

[MIT](LICENSE) © 2026 Ivo Setyadi and NoBuildCMS contributors.
Bundled/third-party components and their licenses are listed in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
