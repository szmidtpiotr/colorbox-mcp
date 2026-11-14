# 4. Narzędzia

*[English → ../en/04-tools.md](../en/04-tools.md)*

Wtyczka daje agentowi 29 narzędzi. Każde włączasz i wyłączasz w **Ustawienia → Agent MCP → Narzędzia**; tam jest
też pełny opis, który czyta agent. Ten rozdział wyjaśnia, do czego służą.

## Czego agent nigdy nie zrobi

- **Nie opublikuje.** Nie ma narzędzia do publikowania. Gotowy szkic trafia do Ciebie jako **Oczekujący** i to Ty
  go publikujesz.
- **Nie usunie trwale wpisów, stron ani komentarzy.** Najwyżej przeniesie je do kosza WordPressa, który WordPress
  sam opróżnia po 30 dniach.
- **Nie zmieni motywu, menu, nagłówka, stopki, widżetów, wtyczek, użytkowników ani ustawień.** To nie są treści,
  a rola Redaktor i tak nie ma do większości z nich dostępu.
- **Nie zobaczy adresów e-mail ani IP** komentujących.

## Tylko odczyt

| Narzędzie | Co robi |
|---|---|
| `colorbox-list-languages` | Języki strony z Polylanga i który jest domyślny. |
| `colorbox-list-categories` | Kategorie z językiem i połączonymi tłumaczeniami. |
| `colorbox-list-posts` | Wpisy albo strony, od ostatnio zmienionych, z filtrami: język, status, kategoria, fraza. |
| `colorbox-get-post` | Jeden wpis albo strona w całości: treść w blokach, opis SEO, obrazki, tłumaczenia. |
| `colorbox-list-repo-images` | Obrazki w katalogu repozytorium GitHub. |
| `colorbox-github-code` | Fragment pliku z GitHuba, gotowy do wstawienia jako blok kodu z akapitem „Źródło”. Wersja jest przypięta do konkretnego commita, więc wpis nie zmienia się razem z repozytorium. |
| `colorbox-list-comments`, `colorbox-get-comment` | Komentarze z sygnałami spamu: liczba linków, wcześniejsze zatwierdzone komentarze autora, komentarze z tego samego IP w 30 dni, czarna lista WordPressa. |
| `colorbox-content-audit` | Raport braków: tłumaczenia, obrazki wyróżniające, opisy SEO, teksty alternatywne obrazków, szkice nieruszane od 30 dni, a na żądanie zepsute linki. |

## Zapis: szkice, tłumaczenia, media, komentarze

| Narzędzie | Co robi |
|---|---|
| `colorbox-create-draft` | Nowy wpis albo strona, zawsze jako szkic, w podanym języku. |
| `colorbox-update-post` | Zmienia szkic albo wpis oczekujący: tytuł, treść, zajawkę, kategorie, tagi, slug, opis SEO, obrazki, stronę nadrzędną, datę. Odmawia przy opublikowanych. |
| `colorbox-save-translation` | Tworzy albo poprawia tłumaczenie i łączy je z oryginałem w Polylangu. |
| `colorbox-set-review-status` | Oddaje szkic Tobie do przeglądu (**Oczekujący** + notatka) albo cofa do szkicu. |
| `colorbox-import-image` | Dodaje obrazek do biblioteki mediów z GitHuba, z publicznego adresu `https://` albo z danych przysłanych przez agenta. Zwraca gotowy blok obrazka. |
| `colorbox-github-document` | Plik Markdown z GitHuba (domyślnie `README.md`) jako zwykłe bloki: nagłówki, akapity, listy, kod, tabele, cytaty i obrazki. Przypięty do konkretnego commita; obrazki z repozytorium trafiają do biblioteki mediów; linki do innych plików wskazują na GitHub (repozytorium publiczne) albo zostaje sam ich tekst (prywatne). Pierwszy nagłówek pierwszego poziomu staje się proponowanym tytułem. Znaczki (badge), obrazki SVG i surowy HTML są pomijane i wypisywane agentowi. Do 300 KB i 20 obrazków. |
| `colorbox-update-image` | Tekst alternatywny, podpis i tytuł obrazka. |
| `colorbox-create-category`, `colorbox-update-category` | Tworzy kategorię w kilku językach naraz; zmienia nazwę albo położenie jednej. |
| `colorbox-restore-post` | Wyciąga wpis albo stronę z kosza. Wraca jako szkic. |
| `colorbox-flag-comment` | Oznacza komentarz dla Ciebie: spam, do usunięcia albo do przejrzenia, z powodem. Niczego więcej nie zmienia. |
| `colorbox-hold-comment` | Cofa zatwierdzony komentarz do oczekujących, więc znika ze strony, dopóki nie zdecydujesz. |
| `colorbox-edit-comment` | Zmienia treść komentarza, np. usuwa link. Poprzednia treść zostaje zapisana i da się ją przywrócić. |
| `colorbox-restore-comment` | Cofa przeniesienie do kosza lub spamu albo ostatnią poprawkę. |

## ⚠ Zmiana opublikowanych treści albo usuwanie

Po instalacji wyłączone, poza *Komentarz do kosza lub spamu*. Każde wymaga od agenta powodu (`reason`).

| Narzędzie | Co robi | Jak cofnąć |
|---|---|---|
| `colorbox-trash-comment` | Przenosi komentarz do kosza albo do spamu. | `colorbox-restore-comment` albo **Komentarze → Kosz** |
| `colorbox-update-published-post` | Zmienia opublikowany wpis albo stronę; widać to na stronie od razu. Najpierw zapisuje wersję (rewizję). | `colorbox-restore-post-revision` ze zwróconym `undo_revision_id` albo **Wersje** w edytorze |
| `colorbox-restore-post-revision` | Pokazuje wersje albo przywraca jedną z nich. | Najpierw zapisuje bieżącą wersję, więc też da się to cofnąć. |
| `colorbox-trash-post` | Przenosi wpis albo stronę do kosza. Odmawia dla strony głównej, strony wpisów (w każdym języku) i stron, które mają podstrony. | `colorbox-restore-post` albo **Wpisy → Kosz** w ciągu 30 dni |
| `colorbox-delete-category` | Usuwa jedną wersję językową kategorii. Wpisy bez kategorii dostają domyślną w swoim języku. | Brak — trzeba utworzyć kategorię od nowa. |
| `colorbox-delete-image` | Usuwa obrazek razem z plikami. Odmawia, jeśli obrazek jest gdziekolwiek użyty (treść, obrazek wyróżniający, obrazek do udostępnień), i mówi gdzie. | Brak — WordPress nie ma kosza dla mediów. |

## Data wpisu

`colorbox-create-draft`, `colorbox-update-post` i `colorbox-update-published-post` przyjmują opcjonalne pole `date`
w czasie lokalnym strony: `YYYY-MM-DD HH:MM:SS` albo `YYYY-MM-DD HH:MM` (np. `2024-03-15 18:30`). Służy do wpisów
o starszych projektach, żeby blog zachował kolejność, w jakiej rzeczy powstawały.

- **W szkicu data zostaje przypięta.** Zwykle WordPress daje szkicowi chwilę, w której go publikujesz; z polem
  `date` szkic zachowuje tę datę po publikacji. `colorbox-get-post` pokazuje wtedy `date_fixed: true`.
- **Bez dat w przyszłości.** To byłoby planowanie publikacji, a publikujesz tylko Ty. Zły format i data, która nie
  istnieje, też dają błąd; daty wpisu zaplanowanego agent nie zmieni.
- **Zapis w dzienniku.** Każda zmiana daty trafia do dziennika przy wpisie (`_colorbox_agent_log`, akcja
  `set-date`, data stara i nowa). Rewizje WordPressa nie przechowują daty, więc `colorbox-update-published-post`
  zwraca też `previous_date` — agent podaje ją z powrotem jako `date`, żeby cofnąć zmianę.

## Co dzieje się samo

- **Znaczniki SEO.** Na każdym wpisie i każdej stronie wtyczka wstawia `meta description`, Open Graph i
  `twitter:card`. Używa opisu SEO i obrazka do udostępnień ustawionych przez agenta; bez nich zajawki (albo początku
  treści) i obrazka wyróżniającego. Jeśli masz już wtyczkę SEO, znaczniki się zdublują: wyłącz te z tej wtyczki
  filtrem w `functions.php` motywu:
  `add_filter( 'colorbox_mcp_seo_output', '__return_false' );`
- **Kolorowania kodu nie ma w zestawie.** `colorbox-github-code` wstawia zwykły blok kodu z klasą
  `language-<nazwa>`. Koloruje go skrypt w motywie (np. highlight.js albo Prism); bez niego kod jest zwykłym tekstem
  o stałej szerokości znaków.

---

Dalej: [5. Bezpieczeństwo →](05-bezpieczenstwo.md)
