#!/usr/bin/env python3
"""Screenshots of the settings panel for docs/img/, taken from the demo site in tools/demo/.

Usage: python3 tools/screenshots.py <lang: en|pl> [shot ...]
Requires: pip install playwright && playwright install chromium.
The admin's language is switched by tools/demo/seed.sh (`lang en` / `lang pl`), not here.
Only ever point this at the demo site: screenshots show token labels, users and posts.
"""
import os
import sys

from playwright.sync_api import sync_playwright

BASE = os.environ.get('DEMO_URL', 'http://127.0.0.1:8099')
USER = os.environ.get('DEMO_USER', 'admin')
PASS = os.environ.get('DEMO_PASS', 'demo-pass-123')
OUT = os.path.join(os.path.dirname(__file__), '..', 'docs', 'img')
PANEL = BASE + '/wp-admin/options-general.php?page=colorbox-mcp'

# name -> (url, height or None for the whole page, or a CSS selector to capture just that element)
SHOTS = {
    'start': (PANEL, None),
    'start-problems': (PANEL, 520),
    'access': (PANEL + '&tab=access', None),
    'tools': (PANEL + '&tab=tools', None),
    'github': (PANEL + '&tab=integrations', None),
    'plugins': (BASE + '/wp-admin/plugins.php', 'tr[data-plugin^="colorbox-wp-mcp/"]'),
    'review': (BASE + '/wp-admin/index.php', '#colorbox_review'),
    'comments': (BASE + '/wp-admin/edit-comments.php', 520),
}


def main():
    lang, names = sys.argv[1], sys.argv[2:] or [n for n in SHOTS if n != 'start-problems']
    os.makedirs(OUT, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={'width': 1280, 'height': 860}, device_scale_factor=2)
        page.goto(BASE + '/wp-login.php')
        page.fill('#user_login', USER)
        page.fill('#user_pass', PASS)
        page.click('#wp-submit')
        page.wait_for_load_state('networkidle')
        for name in names:
            url, height = SHOTS[name]
            page.goto(url)
            page.wait_for_load_state('networkidle')
            # Hide WordPress update nags and the footer: noise in docs.
            page.add_style_tag(content='.update-nag,#wpfooter{display:none!important}')
            path = os.path.join(OUT, f'{name}-{lang}.png')
            if isinstance(height, str):
                page.locator(height).screenshot(path=path)
                print(path)
                continue
            h = height or page.evaluate('document.documentElement.scrollHeight')
            page.set_viewport_size({'width': 1280, 'height': min(h, 3000)})
            page.screenshot(path=path)
            page.set_viewport_size({'width': 1280, 'height': 860})
            print(path)
        browser.close()


if __name__ == '__main__':
    main()
