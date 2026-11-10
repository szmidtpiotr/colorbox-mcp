# 7. How it works

*[Polski → ../pl/07-jak-to-dziala.md](../pl/07-jak-to-dziala.md)*

For the curious and for anyone who wants to change the code.

![How it works](../img/how-it-works-en.png)

## The path of one request

1. The client sends a JSON-RPC message (MCP) to `/wp-json/colorbox/mcp` with the token in a header.
2. `includes/auth.php` finds the token by its SHA-256 fingerprint and signs the request in as the token's account
   — **only on this address**. Then it applies the rate limits.
3. The official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) (in `vendor/`) handles MCP and
   offers the tools listed by `includes/server.php`, minus the ones disabled in the panel.
4. Each tool is a WordPress **ability** (the Abilities API in WordPress core, `includes/abilities.php`). Before
   running, the ability checks that the account may do it (for example `edit_post` for that post).
5. The work itself happens in ordinary WordPress functions (`wp_insert_post`, `media_handle_sideload`, …), so
   revisions, hooks and other plugins behave as if a person did it.

## Files

| File | What is in it |
|---|---|
| `colorbox-wp-mcp.php` | Plugin header, constants, loading the parts. |
| `includes/server.php` | The MCP server at `/wp-json/colorbox/mcp` with an explicit tool list. The adapter's default server (with a generic “run any ability” tool) is turned off. |
| `includes/auth.php` | Tokens, sign-in only on the MCP address, rate limits, the temporary header log. |
| `includes/abilities.php` | All 28 tools: names, descriptions, input/output schemas, permission checks. |
| `includes/content.php` | Posts, pages, categories, languages and translations (Polylang). |
| `includes/manage.php` | ⚠ tools: published posts, revisions, trash, deleting categories and images. |
| `includes/media.php` | Image import (GitHub, address, base64) and the encrypted GitHub token. |
| `includes/code.php` | Code fragments from GitHub pinned to a commit. |
| `includes/comments.php` | Comment review, flags, the *Agent* column and filter. |
| `includes/review.php` | Dashboard box and admin-bar counter for the review queue. |
| `includes/audit.php` | The content audit report. |
| `includes/seo.php` | Meta description, Open Graph and Twitter tags. |
| `includes/settings.php` | The **Settings → MCP Agent** panel. |
| `includes/i18n.php` | Loads the translation matching the WordPress language. |
| `includes/cli.php` | WP-CLI commands (optional). |
| `languages/` | Polish translation (`.po` source, `.mo` and `.l10n.php` compiled) and the `.pot` template. |
| `vendor/` | MCP Adapter and its autoloader, committed so that no Composer is needed. |
| `tools/` | Demo site, screenshots and diagrams for this documentation — not part of the ZIP. |

## What the plugin stores

| Where | Name | Content |
|---|---|---|
| Option | `colorbox_mcp_tokens` | Token fingerprints, account, name, dates. |
| Option | `colorbox_mcp_disabled_tools` | Tools switched off in the panel. Missing = the defaults. |
| Option | `colorbox_mcp_github_token` | The encrypted GitHub token. |
| Options | `colorbox_mcp_c_…` | Rate-limit counters (short-lived). |
| Post meta | `_colorbox_seo_description`, `_colorbox_social_image` | SEO fields. |
| Post meta | `_colorbox_review_note` | The agent's note for review. |
| Post meta | `_colorbox_agent_log` | The last 20 ⚠ actions on that post: tool, reason, time, account. |
| Comment meta | `_colorbox_flag`, `_colorbox_flag_reason`, … | Agent flags; `_colorbox_prev_content` for undoing an edit. |

## Design decisions

- **Own token instead of WordPress application passwords.** An application password opens the whole REST API with
  all of the account's rights. A token valid only on one address limits a leak to the enabled tools.
- **An explicit list of tools instead of the adapter's generic “execute ability” tool.** The client sees exactly
  what you enabled, with annotations (`readOnlyHint`, `destructiveHint`) that clients like Perplexity use to ask
  for confirmation.
- **No publishing tool at all**, not even an optional one. Publishing is the moment a human takes responsibility.
- **⚠ tools need a `reason` and leave a log** on the post, so you can see later why something changed.
- **The front page and the posts page are protected in every language.** WordPress protects only the
  default-language ones; Polylang translations of them would otherwise be trashable.
- **Polylang required, not optional.** Most of the work on a bilingual site is translation; making languages
  optional would double the code paths. A single-language site just has one language in Polylang.
- **Code highlighting left to the theme.** Bundling a highlighter would load scripts on every page for a feature
  many sites already have.
- **Review queue in wp-admin instead of e-mail.** Many WordPress servers have no working mail.

## Known limitations

- No OAuth, so claude.ai custom connectors cannot use it.
- No automatic updates.
- Deleting the plugin leaves its options and the agent account.
- Tested with WordPress 7.1, PHP 8.3, Polylang 3.9, Perplexity and Claude Code. Other clients and Polylang Pro are
  not tested.

## Development

### Try it locally

`tools/demo/` contains a throwaway WordPress in Docker with invented content:

```bash
cd tools/demo
../../bin/build-zip.sh zip
docker compose up -d
./seed.sh
```

Open `http://127.0.0.1:8099/wp-admin` (login `admin`, password `demo-pass-123`). `docker compose down -v` removes it.

### Screenshots for these docs

```bash
pip install playwright && playwright install chromium
python3 tools/screenshots.py en      # English admin
tools/demo/seed.sh lang pl && python3 tools/screenshots.py pl && tools/demo/seed.sh lang en
```

Diagrams: `tools/diagrams/*.mmd`, rendered with
`mmdc -i tools/diagrams/how-it-works-en.mmd -o docs/img/how-it-works-en.png -b white -s 2`.

### Dependencies

`composer install --no-dev`. The MCP Adapter version is pinned in `composer.lock`; `vendor/` is committed.

### Translations

English is the source language. After changing any text in the code, run these in the repository root with WP-CLI
(no WordPress installation needed; without WP-CLI, prefix each line with
`docker run --rm -u $(id -u):$(id -g) -v $PWD:/p -w /p wordpress:cli`):

```bash
wp i18n make-pot . languages/colorbox-wp-mcp.pot --exclude=vendor,tools,dist
wp i18n update-po languages/colorbox-wp-mcp.pot languages/
# translate the new entries in languages/colorbox-wp-mcp-pl_PL.po, then:
wp i18n make-mo languages/ && wp i18n make-php languages/
```

A new language: copy the `.pot` to `languages/colorbox-wp-mcp-<locale>.po`, translate it, then run the last line.

### Release ZIP

`bin/build-zip.sh` writes `dist/colorbox-wp-mcp-<version>.zip` with only what the site needs. Bump the version in
both the header and `const VERSION` of `colorbox-wp-mcp.php`.

---

Back to [README](../../README.md)
