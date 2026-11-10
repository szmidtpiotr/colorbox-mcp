# 6. Troubleshooting

*[Polski → ../pl/06-problemy.md](../pl/06-problemy.md)*

Start with **Settings → MCP Agent → Start**: every ❌ there comes with a hint. Then look for your symptom below.

## Installation and panel

| Symptom | Cause and fix |
|---|---|
| “The package could not be installed” when uploading the ZIP | You uploaded the wrong file (for example the source ZIP of a different project) or the upload limit is too small. Use the release ZIP; if the limit is the problem, your host can raise `upload_max_filesize`. |
| Fatal error mentioning `sodium_crypto_secretbox` when saving the GitHub token | PHP has no `sodium` extension. Ask your host to enable it. Everything except the GitHub token works without it. |
| No **Settings** link and no **MCP Agent** menu | You are not an administrator. The panel requires the *manage options* permission. |
| ❌ **Polylang languages: none** | Add at least one language in **Languages → Languages**. |
| ❌ **Pretty permalinks enabled** | **Settings → Permalinks**, choose anything except **Plain**, **Save Changes**. |
| ❌ **Site served over HTTPS** | Fine for a local client such as Claude Code on your own network. Perplexity needs HTTPS: enable a certificate at your host (often a one-click “Let's Encrypt”). |
| **Refused: no tokens for administrators** | Choose the agent account (Editor) in the **Account** list. |
| **The login "…" is empty or already taken** | An account with that login exists. Type another login, or use the existing account if it is an Editor. |
| The token is gone after reloading the page | By design: it is shown only once. **Revoke** it and create a new one. |

## Connection

| Symptom | Cause and fix |
|---|---|
| `401` with **Invalid token.** | The token is wrong, revoked, or copied with a missing character. Create a new one and paste it again. |
| `401` with **Sorry, you are not allowed to do that.** | The token did not reach WordPress at all. Either the client does not send it (check the header name: `Authorization: Bearer …`), or the server removes the `Authorization` header — common with some Apache/CGI setups and proxies. Try `X-API-Key: <token>` instead, or see *Is the header arriving?* below. |
| `404` / **No route was found** | Wrong address, plain permalinks, or the plugin is inactive. Copy the address from the **Start** tab. |
| `429` / **Too many requests** | More than 120 requests a minute from one account, or more than 10 wrong tokens in 10 minutes from one IP. Wait (the answer's `Retry-After` header says how many seconds) and fix the token. |
| Perplexity cannot connect, curl from your computer works | Perplexity connects from the internet: the site must be public and on HTTPS. A firewall or security plugin may block unknown clients or the REST API. |
| A security plugin (for example one that “disables the REST API”) blocks the requests | Allow `/wp-json/colorbox/mcp` in that plugin. Not tested with specific security plugins. |

### Is the header arriving?

If you have WP-CLI on the server, you can record which headers reach the MCP address. Only header **names and
lengths** are stored, never values:

```bash
wp colorbox-mcp headers on --minutes=30
# connect the client once, then:
wp colorbox-mcp headers show
wp colorbox-mcp headers off && wp colorbox-mcp headers clear
```

If `authorization` is missing from the log while the client sends it, the server strips it.

## Tools

| Symptom | Cause and fix |
|---|---|
| The client does not show a tool, or calls fail with “not found” after you changed the **Tools** tab | The client remembers the old list. In Perplexity use **Sync tools**; in Claude Code restart the session. |
| A tool you disabled still appears | Same as above — and it cannot be called anyway. |
| **Polylang is not active.** | Install and activate Polylang (chapter 1). |
| **Unknown language "xx". Available: …** | The agent used a language code that your Polylang does not have. Add the language or tell the agent the right code. |
| The agent cannot change a published post | `colorbox-update-post` only works on drafts and pending posts. Enable ⚠ `colorbox-update-published-post` if you want this. |
| A private GitHub repository fails | No GitHub token, or the token does not cover that repository, or it lacks **Contents: Read-only**. Check the **GitHub** tab. |
| GitHub stops working after a few dozen images | The 60 requests per hour limit without a token. Add a GitHub token (limit 5,000 per hour). |
| **base64 data is limited to N MB** | The image is larger than the limit in the **Images** tab. Raise the limit there, or let the agent import the file from GitHub or a web address. |
| Image import from an address is refused | The address is local or private (refused on purpose), not `https`, or the file is not JPEG/PNG/WebP/GIF. |
| Code blocks are not coloured | The plugin does not colour code. Add a highlighter to your theme (chapter 4). |
| The agent's `<script>` or embed disappeared | By design: the agent account has no `unfiltered_html`. Add such things yourself. |

---

Next: [7. How it works →](07-how-it-works.md)
