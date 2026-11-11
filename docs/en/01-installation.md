# 1. Installation

*[Polski → ../pl/01-instalacja.md](../pl/01-instalacja.md)*

This chapter takes you from nothing to an installed, active plugin. It takes about 15 minutes if your site already
runs Polylang.

## What you need

| What | Why | How to check |
|---|---|---|
| WordPress **6.9 or newer** | The plugin uses the Abilities API that arrived in WordPress 6.9. | **Dashboard → Updates** shows your version. |
| PHP **8.1 or newer** with the `sodium` extension | `sodium` encrypts the optional GitHub token. It is on by default in PHP 7.2+, but some hosts disable it. | **Tools → Site Health → Info → Server**. |
| [Polylang](https://wordpress.org/plugins/polylang/) with **at least one language** | The language and translation tools rely on it. One language is enough if your site is not multilingual. | **Languages** menu in wp-admin. |
| **Pretty permalinks** (anything except “Plain”) | The MCP address `/wp-json/colorbox/mcp` needs them. | **Settings → Permalinks**. |
| **HTTPS** | Clients that connect from the internet (Perplexity) refuse plain HTTP. A local client (Claude Code on your own network) works without it. | Your site address starts with `https://`. |

You do **not** need Composer, SSH or a terminal: everything the plugin needs is inside the ZIP.

## Step by step

1. **Get the ZIP.** On the [repository page](https://github.com/szmidtpiotr/colorbox-mcp) open **Releases** (right
   column) → the newest version → **Assets** → `colorbox-wp-mcp-<version>.zip`. Do not unpack it.
   Other ways to get the plugin from GitHub are described below in *Other ways to install from GitHub*.
2. **Upload it.** In wp-admin go to **Plugins → Add Plugin → Upload Plugin**, choose the ZIP and click
   **Install Now**.
   → You see “Plugin installed successfully”.
3. **Activate it.** Click **Activate Plugin**.
   → The plugin list shows **Colorbox MCP — tools for AI agents** with a **Settings** link.

   ![Plugin row with the Settings link](../img/plugins-en.png)

4. **Polylang** (skip if you already have it): **Plugins → Add Plugin**, search “Polylang”, **Install Now**,
   **Activate**. Then **Languages → Languages**: add at least one language (for example English). Polylang offers a
   setup wizard; you can use it or skip it.
5. **Permalinks** (skip if already set): **Settings → Permalinks**, choose for example **Post name**, click
   **Save Changes**.
6. **Check the result.** Go to **Settings → MCP Agent**. The **Start** tab lists every requirement with ✅ or ❌
   and a hint how to fix each ❌.

   ![Start tab with unmet requirements](../img/start-problems-en.png)

   The last two rows (agent account, token) turn green in the next chapter.

## Other ways to install from GitHub

The release ZIP above is the recommended way: it holds only what the site needs, and its folder name
(`colorbox-wp-mcp`) stays the same between versions, so updating replaces the plugin in place. The two ways below
also work.

### Code → Download ZIP (the newest code, no release needed)

1. On the repository page click the green **Code** button → **Download ZIP**.
   → You get `colorbox-mcp-main.zip`. Do not unpack it.
2. Upload it like a release ZIP: **Plugins → Add Plugin → Upload Plugin → Install Now → Activate Plugin**.

Differences from the release ZIP:

- The plugin lands in the folder `colorbox-mcp-main` instead of `colorbox-wp-mcp`. WordPress treats those as two
  different plugins. **If you ever switch between the two kinds of ZIP, deactivate and delete the old plugin first**
  (tokens and settings stay, they are in the database), otherwise you end up with two copies.
- The ZIP also contains `docs/` and `tools/` (documentation, demo site, screenshot script). They do nothing on the
  site; they just take a few MB of disk.
- `main` is the released code. Work in progress lives on the `develop` branch; do not install that on a live site.

### git clone (if you have SSH access to the server)

```bash
cd wp-content/plugins
git clone https://github.com/szmidtpiotr/colorbox-mcp.git colorbox-wp-mcp
```

Then **Plugins → Colorbox MCP → Activate**. Update later with `git pull` in that folder. Files must be readable by
the web server user (often `www-data`). Composer is not needed: `vendor/` is in the repository.

## Language of the plugin

The plugin speaks the language of your WordPress: the admin panel follows your profile language, and the
descriptions the agent reads follow the site language (**Settings → General → Site Language**). English and Polish
are included; any other language falls back to English.

## Updating

There is no automatic update. Download the new ZIP and upload it the same way; WordPress asks
**Replace current with uploaded** — confirm. Tokens, settings and the agent account stay. Use the same kind of ZIP
as the first time (see *Other ways to install from GitHub*). With `git clone`, run `git pull`.

To be told about new versions, click **Watch → Custom → Releases** on the repository page.

## Removing

**Plugins → Deactivate**, then **Delete**. The MCP address stops working immediately, so every token stops working
too. Note: the plugin does not delete its settings from the database (option names start with `colorbox_mcp_`) or
the agent account; delete the account in **Users** if you do not need it.

---

Next: [2. Configuration →](02-configuration.md)
