# 6. Problemy

*[English → ../en/06-troubleshooting.md](../en/06-troubleshooting.md)*

Zacznij od **Ustawienia → Agent MCP → Start**: każde ❌ ma tam podpowiedź. Potem znajdź swój objaw niżej.
Komunikaty z serwera są w języku strony; w nawiasach jest ich kod, który wygląda tak samo w każdym języku.

## Instalacja i panel

| Objaw | Przyczyna i rozwiązanie |
|---|---|
| „Nie można zainstalować pakietu” przy wysyłaniu ZIP-a | Wysłany został zły plik (np. ZIP innego projektu) albo limit wysyłania jest za mały. Użyj ZIP-a z wydania; limit (`upload_max_filesize`) może podnieść hosting. |
| Błąd krytyczny z `sodium_crypto_secretbox` przy zapisie tokenu GitHub | PHP nie ma rozszerzenia `sodium`. Poproś hosting o włączenie. Wszystko poza tokenem GitHub działa bez niego. |
| Brak odnośnika **Ustawienia** i menu **Agent MCP** | Nie jesteś administratorem. Panel wymaga uprawnienia do zarządzania ustawieniami. |
| ❌ **Języki w Polylangu: brak** | Dodaj co najmniej jeden język w **Języki → Języki**. |
| ❌ **Bezpośrednie odnośniki (permalinki) włączone** | **Ustawienia → Bezpośrednie odnośniki**, wybierz cokolwiek poza **Prosty**, **Zapisz zmiany**. |
| ❌ **Strona po HTTPS** | Nie przeszkadza klientowi lokalnemu, np. Claude Code w Twojej sieci. Perplexity wymaga HTTPS: włącz certyfikat u hostingu (często jednym kliknięciem „Let's Encrypt”). |
| **Odmawiam: token dla administratora** | Wybierz konto agenta (Redaktor) na liście **Konto**. |
| **Login „…” jest pusty albo zajęty** | Konto o tym loginie już istnieje. Wpisz inny login albo użyj istniejącego konta, jeśli jest Redaktorem. |
| Po odświeżeniu strony tokenu nie ma | Tak ma być: pokazuje się tylko raz. **Unieważnij** go i utwórz nowy. |

## Połączenie

| Objaw | Przyczyna i rozwiązanie |
|---|---|
| `401` i **Nieprawidłowy token.** (`colorbox_mcp_invalid_token`) | Token jest zły, unieważniony albo skopiowany bez jakiegoś znaku. Utwórz nowy i wklej ponownie. |
| `401` z kodem `rest_forbidden` | Token w ogóle nie dotarł do WordPressa. Albo klient go nie wysyła (sprawdź nazwę nagłówka: `Authorization: Bearer …`), albo serwer wycina nagłówek `Authorization` — zdarza się przy części konfiguracji Apache/CGI i serwerów pośredniczących. Spróbuj `X-API-Key: <token>` albo sprawdź niżej *Czy nagłówek dociera?*. |
| `404` (`rest_no_route`) | Zły adres, „Proste” odnośniki albo wtyczka wyłączona. Skopiuj adres z zakładki **Start**. |
| `429` i **Za dużo zapytań.** (`colorbox_mcp_rate_limited`) | Więcej niż 120 zapytań na minutę z jednego konta albo więcej niż 10 błędnych tokenów w 10 minut z jednego IP. Odczekaj (nagłówek `Retry-After` odpowiedzi mówi, ile sekund) i popraw token. |
| Perplexity się nie łączy, a curl z Twojego komputera działa | Perplexity łączy się z internetu: strona musi być publiczna i po HTTPS. Zapora albo wtyczka bezpieczeństwa może blokować nieznanych klientów albo REST API. |
| Wtyczka bezpieczeństwa (np. „wyłączająca REST API”) blokuje zapytania | Dopuść w niej `/wp-json/colorbox/mcp`. Nie testowano z konkretnymi wtyczkami bezpieczeństwa. |

### Czy nagłówek dociera?

Jeśli na serwerze jest WP-CLI, możesz zapisać, jakie nagłówki docierają na adres MCP. Zapisywane są tylko
**nazwy i długości** nagłówków, nigdy wartości:

```bash
wp colorbox-mcp headers on --minutes=30
# połącz klienta raz, potem:
wp colorbox-mcp headers show
wp colorbox-mcp headers off && wp colorbox-mcp headers clear
```

Jeśli w zapisie nie ma `authorization`, a klient go wysyła, to serwer go wycina.

## Narzędzia

| Objaw | Przyczyna i rozwiązanie |
|---|---|
| Klient nie widzi narzędzia albo wywołania kończą się „not found” po zmianie w zakładce **Narzędzia** | Klient pamięta starą listę. W Perplexity użyj **Synchronizuj narzędzia**; w Claude Code uruchom sesję od nowa. |
| Wyłączone narzędzie wciąż widać | Jak wyżej — i tak nie da się go wywołać. |
| **Polylang nie jest aktywny.** | Zainstaluj i włącz Polylanga (rozdział 1). |
| **Nieznany język „xx”. Dostępne: …** | Agent użył kodu języka, którego nie ma w Polylangu. Dodaj język albo podaj agentowi właściwy kod. |
| Agent nie może poprawić opublikowanego wpisu | `colorbox-update-post` działa tylko na szkicach i wpisach oczekujących. Włącz ⚠ `colorbox-update-published-post`, jeśli tego chcesz. |
| Prywatne repozytorium GitHub nie działa | Brak tokenu GitHub, token nie obejmuje tego repozytorium albo nie ma **Contents: Read-only**. Sprawdź zakładkę **GitHub**. |
| GitHub przestaje działać po kilkudziesięciu obrazkach | Limit 60 zapytań na godzinę bez tokenu. Dodaj token GitHub (limit 5000 na godzinę). |
| **Dane base64 są ograniczone do N MB** | Obrazek jest większy niż limit w zakładce **Obrazki**. Podnieś limit tam albo niech agent zaimportuje plik z GitHuba lub z adresu. |
| Import obrazka z adresu jest odrzucany | Adres jest lokalny albo prywatny (odrzucany celowo), nie jest `https` albo plik nie jest JPEG/PNG/WebP/GIF. |
| Bloki kodu nie są kolorowe | Wtyczka nie koloruje kodu. Dodaj skrypt kolorujący do motywu (rozdział 4). |
| `<script>` albo osadzenie od agenta zniknęło | Tak ma być: konto agenta nie ma `unfiltered_html`. Takie rzeczy dodaj sam. |

---

Dalej: [7. Jak to działa →](07-jak-to-dziala.md)
