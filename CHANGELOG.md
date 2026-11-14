# Changelog

*[Polska wersja → CHANGELOG.pl.md](CHANGELOG.pl.md)*

Every release gets an entry here before it is tagged, written for the person who installs the plugin: what
changed for them, not which files moved. The same text goes into the GitHub release notes.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions: [SemVer](https://semver.org/).

## [0.5.0] — 2026-11-14

### Added
- **Post date** (`date`) in `colorbox-create-draft`, `colorbox-update-post` and `colorbox-update-published-post`,
  in the site local time (`YYYY-MM-DD HH:MM:SS` or `YYYY-MM-DD HH:MM`). Lets the agent backdate a post about an
  older project, so the blog keeps the order in which things were made.
  - On a draft the date is pinned: it stays when you publish the draft. Before, WordPress gave every draft the
    moment of publishing.
  - `colorbox-get-post` and `colorbox-list-posts` return `date_fixed`: `false` means a draft without a pinned date.
  - A date in the future is refused (the agent does not schedule posts), so is a malformed or non-existent date.
    A scheduled post's date cannot be changed by the agent.
  - Every date change is written to the post's agent log (`_colorbox_agent_log`, action `set-date`, old and new
    date). `colorbox-update-published-post` also returns `previous_date`, because WordPress revisions do not store
    the date.
- This changelog, filled in back to 0.2.0.

## [0.4.0] — 2026-11-12

### Added
- New tool `colorbox-github-document`: a Markdown file from GitHub (by default `README.md`, private repositories
  too) becomes ordinary blocks: headings, paragraphs, lists, code, tables, quotes and images. Pinned to an exact
  commit; images from the repository go into the media library; links to other files point to GitHub (public
  repository) or keep only their text (private). Badges, SVG images and raw HTML are skipped and listed for the
  agent. Up to 300 KB and 20 images.
- Server instructions: clients get a short guide to the workflow when they connect. Other plugins can extend it
  with the `colorbox_mcp_instructions` filter (docs, chapter 7).

## [0.3.1] — 2026-11-12

### Fixed
- `colorbox-get-post` can read pages that WordPress lets only administrators edit, such as the privacy policy page
  and its Polylang translations. Changing them still needs edit rights, and the `editable` field says so.

## [0.3.0] — 2026-11-11

### Added
- **Settings → MCP Agent → Images**: configurable limit for images sent as base64 (default 4 MB, up to about 70%
  of PHP `post_max_size`).
- Extension point for site-specific tools: the `colorbox_mcp_tools` filter and `Media\import_bytes()` (docs,
  chapter 7).
- Installation docs: release ZIP, Code → Download ZIP, `git clone` (chapter 1).

## [0.2.0] — 2026-11-10

First version in this repository, ready to share.

### Added
- MCP server at `/wp-json/colorbox/mcp` on the official WordPress MCP Adapter, with a Bearer token valid only on
  that address and rate limits.
- 28 tools: drafts and pages in every Polylang language, translations, images, code from GitHub, comment
  moderation, content audit, review queue; optional ⚠ tools for published posts (with undo), trash, deleting
  unused images and categories. The agent never publishes.
- **Settings → MCP Agent** panel: agent account, tokens, switching tools on and off, GitHub token.
- English and Polish interface and tool descriptions.

[0.5.0]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.5.0
[0.4.0]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.4.0
[0.3.1]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.3.1
[0.3.0]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.3.0
[0.2.0]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.2.0
