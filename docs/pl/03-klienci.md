# 3. Podłączenie klienta

*[English → ../en/03-clients.md](../en/03-clients.md)*

**Klient** to aplikacja AI, która rozmawia z Twoją stroną. Działa każdy klient obsługujący **MCP przez Streamable
HTTP** z własnym nagłówkiem. Niżej dwa przetestowane.

Z **Ustawienia → Agent MCP** potrzebujesz dwóch rzeczy:

- **adresu serwera MCP** z zakładki **Start**, np. `https://example.com/wp-json/colorbox/mcp`,
- **tokenu** z zakładki **Konto agenta i tokeny** (osobnego dla każdego klienta).

Klient wysyła token w nagłówku `Authorization: Bearer <token>`. Klient, który umie wysłać tylko własny nagłówek,
może zamiast tego użyć `X-API-Key: <token>`.

## Perplexity

Własne łączniki wymagają płatnego planu (Pro, Max albo Enterprise). Nazwy menu są ze stanu na koniec 2026 roku
i mogą się zmienić.

1. **Ustawienia → Łączniki → Dodaj własny → łącznik MCP → zdalny.**
2. **URL**: adres serwera MCP.
3. **Uwierzytelnianie**: **API Key**, wklej token. Perplexity wysyła go jako `Authorization: Bearer`, więc OAuth nie
   jest potrzebny.
4. **Transport**: **Streamable HTTP**. Zapisz.
   → Łącznik pokazuje listę narzędzi.
5. Każdemu narzędziu oznaczonemu ⚠ w zakładce **Narzędzia** ustaw **Zawsze pytaj**. Perplexity zapyta Cię wtedy
   przed każdym wywołaniem.

Po każdej zmianie w zakładce **Narzędzia** otwórz łącznik i użyj **Synchronizuj narzędzia**: do tego czasu
Perplexity trzyma starą listę.

Perplexity łączy się z internetu, więc strona musi być dostępna z internetu po **HTTPS**.

## Claude Code

Utwórz plik `.mcp.json` w katalogu projektu:

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

Tokenu nie wpisuj do pliku: ustaw zmienną środowiskową `COLORBOX_MCP_TOKEN` przed uruchomieniem `claude`, np.
w pliku startowym powłoki. Claude Code sam podstawi `${COLORBOX_MCP_TOKEN}`. Kopia tego pliku leży w repozytorium
jako `.mcp.json.example`.

Sprawdzenie: uruchom `claude` w tym katalogu i wpisz `/mcp`. → `wordpress` jest na liście jako połączony.

## claude.ai (strona i aplikacja)

Jeszcze nie działa: własne łączniki w claude.ai wymagają OAuth, a wtyczka obsługuje tylko tokeny.

## Ręczne sprawdzenie połączenia

Gdy klient nie może się połączyć, to polecenie pokaże, czy strona w ogóle odpowiada. Podmień adres i token:

```bash
curl -s -X POST https://example.com/wp-json/colorbox/mcp \
  -H "Authorization: Bearer cbx_twoj_token" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}'
```

Dobra odpowiedź to JSON zawierający `"serverInfo"`. Cokolwiek innego: [rozdział 6](06-problemy.md).

---

Dalej: [4. Narzędzia →](04-narzedzia.md)
