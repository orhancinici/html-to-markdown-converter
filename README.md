# HTML to Markdown Converter

> WordPress plugin for converting Shamela / Maktaba-style HTML books to Markdown.

A WordPress plugin that ingests HTML/HTM files (typically exports from
[المكتبة الشاملة](https://shamela.ws/) and similar Arabic/Islamic book libraries),
normalizes their quirks, and outputs clean Markdown packaged as a downloadable ZIP.

It works on plain HTML too, but the conversion rules are tuned for the
Shamela/Maktaba layout: `PageNumber` spans, `PageHead` repeating headers,
footnote markers (`<sup><font>(1)</font></sup>`), and unmatched `</p>` tags
that would otherwise glue Arabic words together.

## Features

- Drag-and-drop upload of single or multiple HTML/HTM files (and ZIP archives of them).
- Markdown conversion with sensible defaults for headings, lists, tables, links, and images.
- **Page markers** — `<span class="PageNumber">(ص: 1)</span>` becomes `--ص: 1--`
  on its own line, right after the page's content. Or strip them entirely.
- **Footnote control** — keep footnotes, or remove both the footnote blocks
  *and* the in-text superscript markers (`<sup>(1)</sup>`). Plain parenthesised
  numbers in the body (e.g. `(859)` in a date) are left untouched.
- **Repeating page headers** can be removed in one click.
- ZIP packaging of the result with a time-limited download link.
- Admin dashboard with daily/user reports, rate limiting, and per-client
  concurrency limits.
- Maintenance mode, daily operation quotas, and admin bypass.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- `DOMDocument` and `mbstring` extensions (standard on most hosts)

## Installation

1. Download or clone this repository into `wp-content/plugins/html-to-markdown-converter/`.
2. Activate **HTML to Markdown Converter** from *Plugins* in the WordPress admin.
3. Open **HTML → Markdown** in the sidebar to configure conversion rules and limits.

## Usage

### As an admin

Configure conversion rules under **HTML → Markdown → Ayarlar**:

| Setting | Effect |
| --- | --- |
| Sayfa numaralarını kaldır | Drop `PageNumber` spans entirely. When off, they are rendered as `--ص: 1--` markers. |
| Tekrarlayan sayfa başlıklarını kaldır | Strip `<div class="PageHead">…</div>` blocks. |
| Dipnotları koru | When off, footnote blocks **and** in-text `<sup>` markers are removed. |

Other tabs cover quotas, rate limits, job lifetime, and reports.

### For end users

Drop the shortcode on any page:

```
[htmd_converter]
```

Visitors upload one or more `.html` / `.htm` / `.zip` files and receive a
download link for the converted Markdown bundle.

## Conversion rules at a glance

| Source HTML | Markdown output |
| --- | --- |
| `<h1>…</h1>` … `<h6>…</h6>` | `#` … `######` headings |
| `<p>` / `<div>` / `<section>` | Paragraph + blank line |
| `<ul>` / `<ol>` / `<li>` | `-` / `1.` lists, nested support |
| `<table>` | GitHub-flavored Markdown table |
| `<blockquote>` | `>`-prefixed lines |
| `<pre>` / `<code>` | Fenced / inline code |
| `<a href>` / `<img>` | `[text](url)` / `![alt](src)` (only `http/https/mailto/tel`) |
| `<span class="PageNumber">(ص: 1)</span>` | `--ص: 1--` on its own line |
| `<sup><font>(1)</font></sup>` | Removed when *Dipnotları koru* is off |

## Development

```
.
├── html-to-markdown-converter.php   # Bootstrap
├── includes/
│   ├── class-plugin.php             # Wires hooks, services
│   ├── class-upload-handler.php     # AJAX upload + validation
│   ├── class-archive-extractor.php  # ZIP handling
│   ├── class-html-normalizer.php    # Pre-parse cleanup (Shamela quirks)
│   ├── class-markdown-converter.php # DOM → Markdown
│   ├── class-job-repository.php     # DB persistence + reports
│   ├── class-download-controller.php
│   ├── class-settings.php           # Admin UI
│   ├── class-shortcode.php
│   ├── class-activator.php
│   └── helpers.php
├── templates/                        # Admin + frontend templates
├── assets/                           # CSS / JS
└── uninstall.php
```

## License

GPL v2 or later. See plugin headers for details.
