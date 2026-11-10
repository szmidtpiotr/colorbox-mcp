# 4. Tools

*[Polski → ../pl/04-narzedzia.md](../pl/04-narzedzia.md)*

The plugin gives the agent 28 tools. You switch each one on or off in **Settings → MCP Agent → Tools**; the full
description the agent reads is shown there too. This chapter explains what they are for.

## What the agent can never do

- **Publish.** There is no publishing tool. A finished draft goes to you as **Pending** and you publish it.
- **Delete posts, pages or comments permanently.** Only the WordPress trash, which WordPress itself empties after
  30 days.
- **Change the theme, menus, header, footer, widgets, plugins, users or settings.** Those are not content and the
  Editor role cannot change most of them anyway.
- **See e-mail or IP addresses** of commenters.

## Read-only

| Tool | What it does |
|---|---|
| `colorbox-list-languages` | Site languages from Polylang and which one is the default. |
| `colorbox-list-categories` | Categories with their language and linked translations. |
| `colorbox-list-posts` | Posts or pages, newest change first, filtered by language, status, category or phrase. |
| `colorbox-get-post` | One post or page in full: content as blocks, SEO description, images, translations. |
| `colorbox-list-repo-images` | Images in a GitHub repository folder. |
| `colorbox-github-code` | A fragment of a file from GitHub, ready to insert as a code block with a “Source” line. The version is pinned to an exact commit, so the post does not change when the repository does. |
| `colorbox-list-comments`, `colorbox-get-comment` | Comments with spam signals: number of links, earlier approved comments of the author, comments from the same IP in 30 days, WordPress disallowed keys. |
| `colorbox-content-audit` | A report of gaps: missing translations, featured images, SEO descriptions and image alt texts, drafts untouched for 30 days, and on request broken links. |

## Write: drafts, translations, media, comments

| Tool | What it does |
|---|---|
| `colorbox-create-draft` | A new post or page, always as a draft, in the given language. |
| `colorbox-update-post` | Changes a draft or pending post: title, content, excerpt, categories, tags, slug, SEO description, images, parent page. Refuses published content. |
| `colorbox-save-translation` | Creates or updates a translation and links it to the original in Polylang. |
| `colorbox-set-review-status` | Hands a draft to you for review (**Pending** + a note) or takes it back to draft. |
| `colorbox-import-image` | Puts an image into the media library from GitHub, from a public `https://` address or from data sent by the agent. Returns a ready image block. |
| `colorbox-update-image` | Alt text, caption and title of an image. |
| `colorbox-create-category`, `colorbox-update-category` | Creates a category in several languages at once; renames or moves one. |
| `colorbox-restore-post` | Takes a post or page out of the trash. It comes back as a draft. |
| `colorbox-flag-comment` | Marks a comment for you: spam, delete or review, with a reason. Changes nothing else. |
| `colorbox-hold-comment` | Moves an approved comment back to pending, so it disappears from the site until you decide. |
| `colorbox-edit-comment` | Changes the text of a comment, for example removes a link. The previous text is kept and can be restored. |
| `colorbox-restore-comment` | Undoes a trash/spam move or the last edit. |

## ⚠ Change published content or delete

These are off on a fresh install, except *Trash or spam comment*. Each one requires a `reason` from the agent.

| Tool | What it does | How to undo |
|---|---|---|
| `colorbox-trash-comment` | Moves a comment to the trash or to spam. | `colorbox-restore-comment` or **Comments → Trash** |
| `colorbox-update-published-post` | Changes a published post or page; visible on the site immediately. A revision is saved first. | `colorbox-restore-post-revision` with the returned `undo_revision_id`, or **Revisions** in the editor |
| `colorbox-restore-post-revision` | Lists revisions, or restores one. | It saves the current version first, so it can be undone too. |
| `colorbox-trash-post` | Moves a post or page to the trash. Refuses the front page, the posts page (in every language) and pages that have child pages. | `colorbox-restore-post` or **Posts → Trash** within 30 days |
| `colorbox-delete-category` | Deletes one language version of a category. Posts left without a category get the default one in their language. | None — recreate the category. |
| `colorbox-delete-image` | Deletes an image and its files. Refuses if the image is used anywhere (content, featured image, social image) and says where. | None — WordPress has no trash for media. |

## Things that happen automatically

- **SEO tags.** On every post and page the plugin prints `meta description`, Open Graph and `twitter:card` tags.
  It uses the SEO description and social image the agent set; without them, the excerpt (or the start of the
  content) and the featured image. If you already use an SEO plugin, you will get duplicate tags: turn the plugin's
  tags off with a filter in your theme's `functions.php`:
  `add_filter( 'colorbox_mcp_seo_output', '__return_false' );`
- **Code highlighting is not included.** `colorbox-github-code` inserts a normal code block with a
  `language-<name>` class. A highlighter in your theme (for example highlight.js or Prism) colours it; without one
  the code shows as plain monospaced text.

---

Next: [5. Security →](05-security.md)
