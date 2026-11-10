# 2. Configuration

*[Polski → ../pl/02-konfiguracja.md](../pl/02-konfiguracja.md)*

Everything is set in **Settings → MCP Agent**. The panel has four tabs. You need the first three; the fourth
(GitHub) is optional.

## Start tab

![Start tab](../img/start-en.png)

- **Status** — every requirement with ✅ / ❌ and what to do about a ❌.
- **MCP server address** — the address you paste into the client. It is built from your site address, for example
  `https://example.com/wp-json/colorbox/mcp`.
- **How to connect a client** — a short version of [chapter 3](03-clients.md), plus a ready `.mcp.json` for
  Claude Code.

## Agent account and tokens tab

The agent does not use your account. It gets its own WordPress user with the **Editor** role. An Editor can edit
all posts and pages, but cannot install plugins, change settings or manage users. The plugin also removes the
`unfiltered_html` permission from this account, so WordPress strips `<script>` tags and `on…` attributes from
whatever the agent writes.

![Agent account and tokens tab](../img/access-en.png)

1. **Create the agent account.** Leave the login as `agent` (or type another) and click **Create agent account**.
   → A green message says the account was created.
   The account gets a long random password that nobody knows: the agent never signs in with a password.
2. **Create a token.** Under **New token** choose the account, type a name that says what the token is for (for
   example `perplexity`), and click **Create token**.
   → A yellow box shows the token, starting with `cbx_`.

   ![New token shown once](../img/new-token-en.png)

3. **Copy the token now** and paste it into the client (chapter 3). The site keeps only a fingerprint (SHA-256
   hash) of it, so after you reload the page **nobody can show it again**. Lost it? Revoke it and create a new one.

Rules worth knowing:

- **One token per client.** Then you can cut off one client without touching the others.
- The table shows when each token was **last used**: an unused token is a good candidate for **Revoke**.
- **Revoke** works immediately: the next request with that token gets `401 Invalid token`.
- The panel **refuses to create a token for an administrator**. A leaked token would otherwise open everything the
  administrator can do.
- The **Account** list offers every Editor and Author, so you can attach a token to an existing account too. The
  agent then has that account's permissions. An existing Editor usually still has `unfiltered_html`; the panel marks
  such an account with “(has unfiltered_html, not recommended)”.

## Tools tab

Here you decide which tools the agent sees. A disabled tool disappears from the client's tool list and cannot be
called even by a client that remembers it.

![Tools tab](../img/tools-en.png)

The tools are in three groups:

| Group | What it contains | Default on a fresh install |
|---|---|---|
| **Read-only** | Lists, previews, content audit, reading from GitHub. Changes nothing. | on |
| **Write (drafts, comments, media)** | Drafts, translations, images, comment moderation, categories. Never touches published content, except comment moderation. | on |
| **⚠ Change published content or delete** | Editing published posts, trashing posts, deleting categories and images, trashing comments. | **off**, except *Trash or spam comment* |

Click **Save tools** after a change, then **sync the tools in the client** (Perplexity caches the list; see
[chapter 3](03-clients.md)).

Tip: in Perplexity set **Always ask** for every ⚠ tool, so the agent asks you before each such action. Every ⚠
tool also requires the agent to give a `reason`, which is stored with the post (see
[chapter 7](07-how-it-works.md)).

The full list with descriptions is in [chapter 4](04-tools.md).

## GitHub tab (optional)

Needed only if the agent should take code or images from **private** GitHub repositories, or if you hit the
limit of 60 GitHub requests per hour.

![GitHub tab](../img/github-en.png)

1. On GitHub: **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**.
2. **Repository access**: choose the repositories the agent may read.
3. **Permissions → Repository permissions → Contents: Read-only**. Nothing else.
4. Copy the token (it starts with `github_pat_`), paste it into **GitHub token**, click **Save**.
   → **Status** shows the token is set.

The token is stored encrypted with keys from your `wp-config.php`. The agent never sees it: the site's server
downloads the files and hands the agent only the result. Remember that code or an image from a private repository
becomes public as soon as you publish the post that contains it.

## The review queue

The agent cannot publish. When it finishes a draft, it hands it over with *Submit for review*: the post becomes
**Pending** and gets a note. You see it in two places:

- a **To review: N** counter in the top admin bar,
- the **Waiting for review (from agent)** box on the dashboard:

  ![Dashboard box](../img/review-en.png)

Open the post, check it, and publish it yourself.

## Comments flagged by the agent

**Comments** gets an **Agent** column with the agent's flag and reason, and a **Flagged by agent** filter.

![Comments with agent flags](../img/comments-en.png)

The flag is only a suggestion; you approve, trash or mark as spam as usual.

---

Next: [3. Connecting a client →](03-clients.md)
