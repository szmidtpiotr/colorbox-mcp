# Historia zmian

*[English → CHANGELOG.md](CHANGELOG.md)*

Każde wydanie dostaje tu wpis, zanim powstanie tag. Wpis jest pisany dla osoby, która instaluje wtyczkę: co się
dla niej zmieniło, a nie które pliki się przesunęły. Ten sam tekst trafia do opisu wydania na GitHubie.
Format: [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), numery wersji: [SemVer](https://semver.org/lang/pl/).

## [0.5.0] — jeszcze niewydana

### Dodane
- **Data wpisu** (`date`) w `colorbox-create-draft`, `colorbox-update-post` i `colorbox-update-published-post`,
  w czasie lokalnym strony (`YYYY-MM-DD HH:MM:SS` albo `YYYY-MM-DD HH:MM`). Agent może nadać datę wsteczną wpisowi
  o starszym projekcie, więc blog zachowuje kolejność, w jakiej rzeczy powstawały.
  - W szkicu data zostaje przypięta i nie zmienia się, gdy go opublikujesz. Wcześniej WordPress dawał każdemu
    szkicowi chwilę publikacji.
  - `colorbox-get-post` i `colorbox-list-posts` zwracają `date_fixed`: `false` oznacza szkic bez przypiętej daty.
  - Data w przyszłości jest odrzucana (agent nie planuje publikacji), tak samo zły format i data, która nie
    istnieje. Daty wpisu zaplanowanego agent nie zmieni.
  - Każda zmiana daty trafia do dziennika agenta przy wpisie (`_colorbox_agent_log`, akcja `set-date`, data stara
    i nowa). `colorbox-update-published-post` zwraca też `previous_date`, bo rewizje WordPressa nie przechowują
    daty.
- Ta historia zmian, uzupełniona wstecz do 0.2.0.

## [0.4.0] — 2026-11-12

### Dodane
- Nowe narzędzie `colorbox-github-document`: plik Markdown z GitHuba (domyślnie `README.md`, także z prywatnych
  repozytoriów) staje się zwykłymi blokami: nagłówki, akapity, listy, kod, tabele, cytaty i obrazki. Przypięty do
  konkretnego commita; obrazki z repozytorium trafiają do biblioteki mediów; linki do innych plików wskazują na
  GitHub (repozytorium publiczne) albo zostaje sam ich tekst (prywatne). Znaczki, obrazki SVG i surowy HTML są
  pomijane i wypisywane agentowi. Do 300 KB i 20 obrazków.
- Instrukcje serwera: klient po połączeniu dostaje krótki opis sposobu pracy. Inne wtyczki mogą go uzupełnić
  filtrem `colorbox_mcp_instructions` (dokumentacja, rozdział 7).

## [0.3.1] — 2026-11-12

### Poprawione
- `colorbox-get-post` czyta strony, które WordPress pozwala edytować tylko administratorom, np. stronę polityki
  prywatności i jej tłumaczenia z Polylanga. Zmiana nadal wymaga prawa edycji i pole `editable` to pokazuje.

## [0.3.0] — 2026-11-11

### Dodane
- **Ustawienia → Agent MCP → Obrazki**: limit obrazków wysyłanych jako base64 (domyślnie 4 MB, najwyżej około 70%
  `post_max_size` z PHP).
- Miejsce na narzędzia potrzebne tylko na jednej stronie: filtr `colorbox_mcp_tools` i `Media\import_bytes()`
  (dokumentacja, rozdział 7).
- Instrukcja instalacji: ZIP z wydania, Code → Download ZIP, `git clone` (rozdział 1).

## [0.2.0] — 2026-11-10

Pierwsza wersja w tym repozytorium, gotowa do udostępnienia.

### Dodane
- Serwer MCP pod `/wp-json/colorbox/mcp` na oficjalnym WordPress MCP Adapter, z tokenem Bearer ważnym tylko pod
  tym adresem i limitami zapytań.
- 28 narzędzi: szkice i strony w każdym języku z Polylanga, tłumaczenia, obrazki, kod z GitHuba, moderacja
  komentarzy, raport braków, kolejka do przeglądu; opcjonalne narzędzia ⚠ do opublikowanych wpisów (z cofaniem),
  kosza, usuwania nieużywanych obrazków i kategorii. Agent nigdy nie publikuje.
- Panel **Ustawienia → Agent MCP**: konto agenta, tokeny, włączanie i wyłączanie narzędzi, token GitHub.
- Interfejs i opisy narzędzi po angielsku i po polsku.

[0.5.0]: https://github.com/szmidtpiotr/colorbox-mcp/compare/v0.4.0...develop
[0.4.0]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.4.0
[0.3.1]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.3.1
[0.3.0]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.3.0
[0.2.0]: https://github.com/szmidtpiotr/colorbox-mcp/releases/tag/v0.2.0
