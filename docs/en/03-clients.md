# 3. Connecting a client

*[Polski → ../pl/03-klienci.md](../pl/03-klienci.md)*

A **client** is the AI app that talks to your site. Any client that supports **MCP over Streamable HTTP** with a
custom header works. Below are the two that were tested.

You need two things from **Settings → MCP Agent**:

- the **MCP server address** from the **Start** tab, for example `https://example.com/wp-json/colorbox/mcp`,
- a **token** from the **Agent account and tokens** tab (one per client).

The client sends the token in the header `Authorization: Bearer <token>`. Clients that can only send a custom header
may use `X-API-Key: <token>` instead.

## Perplexity

Custom connectors need a paid plan (Pro, Max or Enterprise). Menu names below are as of late 2026 and may change.

1. **Settings → Connectors → Add custom connector → MCP connector → Remote.**
2. **URL**: the MCP server address.
3. **Authentication**: **API Key**, then paste the token. Perplexity sends it as `Authorization: Bearer`, so OAuth is
   not needed.
4. **Transport**: **Streamable HTTP**. Save.
   → The connector shows the list of tools.
5. For every tool marked ⚠ in the **Tools** tab, set **Always ask**. Perplexity then asks you before each call.

After you change anything in the **Tools** tab, open the connector and use **Sync tools**: Perplexity keeps the old
list until you do.

Perplexity connects from the internet, so your site must be reachable from the internet over **HTTPS**.

## Claude Code

Create a file `.mcp.json` in your project folder:

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "http",
      "url": "https://example.com/wp-json/colorbox/mcp",
      "headers": { "Authorization": "Bearer ${COLORBOX_MCP_TOKEN}" }
    }
  }
}
```

Keep the token out of the file: set the environment variable `COLORBOX_MCP_TOKEN` before you start `claude`, for
example in your shell profile. Claude Code fills in `${COLORBOX_MCP_TOKEN}` itself. A copy of this file is in the
repository as `.mcp.json.example`.

Check: start `claude` in that folder and type `/mcp`. → `wordpress` is listed as connected.

## claude.ai (web and desktop app)

Not supported yet: custom connectors in claude.ai require OAuth, and this plugin only does tokens.

## Checking the connection by hand

If a client cannot connect, this command shows whether the site answers. Replace the address and token:

```bash
curl -s -X POST https://example.com/wp-json/colorbox/mcp \
  -H "Authorization: Bearer cbx_your_token" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}'
```

A good answer is JSON containing `"serverInfo"`. Anything else: see [chapter 6](06-troubleshooting.md).

---

Next: [4. Tools →](04-tools.md)
