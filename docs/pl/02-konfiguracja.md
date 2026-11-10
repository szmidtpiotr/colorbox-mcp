# 2. Konfiguracja

*[English → ../en/02-configuration.md](../en/02-configuration.md)*

Wszystko ustawia się w **Ustawienia → Agent MCP**. Panel ma cztery zakładki. Potrzebne są trzy pierwsze; czwarta
(GitHub) jest opcjonalna.

## Zakładka Start

![Zakładka Start](../img/start-pl.png)

- **Stan** — każde wymaganie z ✅ / ❌ i co zrobić z ❌.
- **Adres serwera MCP** — adres, który wklejasz w kliencie. Powstaje z adresu Twojej strony, np.
  `https://example.com/wp-json/colorbox/mcp`.
- **Jak podłączyć klienta** — skrót [rozdziału 3](03-klienci.md) i gotowy plik `.mcp.json` dla Claude Code.

## Zakładka Konto agenta i tokeny

Agent nie używa Twojego konta. Dostaje własnego użytkownika WordPressa z rolą **Redaktor**. Redaktor może
edytować wszystkie wpisy i strony, ale nie zainstaluje wtyczek, nie zmieni ustawień i nie zarządza użytkownikami.
Wtyczka dodatkowo odbiera temu kontu uprawnienie `unfiltered_html`, więc WordPress wycina z tego, co pisze agent,
znaczniki `<script>` i atrybuty `on…`.

![Zakładka Konto agenta i tokeny](../img/access-pl.png)

1. **Utwórz konto agenta.** Zostaw login `agent` (albo wpisz inny) i kliknij **Utwórz konto agenta**.
   → Zielony komunikat potwierdza utworzenie konta.
   Konto dostaje długie losowe hasło, którego nikt nie zna: agent nigdy nie loguje się hasłem.
2. **Utwórz token.** W części **Nowy token** wybierz konto, wpisz nazwę mówiącą, do czego token służy (np.
   `perplexity`), i kliknij **Utwórz token**.
   → W żółtej ramce pojawia się token zaczynający się od `cbx_`. Na zrzucie panel po angielsku:

   ![Nowy token pokazany jeden raz](../img/new-token-en.png)

3. **Skopiuj token od razu** i wklej go w kliencie (rozdział 3). Strona zapisuje tylko jego odcisk (skrót
   SHA-256), więc po odświeżeniu strony **nikt nie pokaże go ponownie**. Zgubiony? Unieważnij go i utwórz nowy.

Zasady, które warto znać:

- **Jeden token na klienta.** Wtedy odetniesz jednego klienta bez ruszania pozostałych.
- Tabela pokazuje, kiedy token był **ostatnio użyty**: nieużywany token to dobry kandydat do **Unieważnij**.
- **Unieważnij** działa od razu: następne zapytanie z tym tokenem dostaje `401 Invalid token`.
- Panel **nie utworzy tokenu dla administratora**. Wyciek takiego tokenu otwierałby wszystko, co może administrator.
- Lista **Konto** pokazuje wszystkich Redaktorów i Autorów, więc token można przypisać też do istniejącego konta.
  Agent ma wtedy uprawnienia tego konta. Istniejący Redaktor zwykle ma `unfiltered_html`; panel oznacza takie konto
  dopiskiem „(ma unfiltered_html — nie zalecane)”.

## Zakładka Narzędzia

Tu decydujesz, które narzędzia widzi agent. Wyłączone narzędzie znika z listy w kliencie i nie da się go wywołać,
nawet jeśli klient je pamięta.

![Zakładka Narzędzia](../img/tools-pl.png)

Narzędzia są w trzech grupach:

| Grupa | Co zawiera | Domyślnie po instalacji |
|---|---|---|
| **Tylko odczyt** | Listy, podglądy, raport treści, czytanie z GitHuba. Niczego nie zmienia. | włączone |
| **Zapis (szkice, komentarze, media)** | Szkice, tłumaczenia, obrazki, moderacja komentarzy, kategorie. Nie rusza opublikowanych treści, poza moderacją komentarzy. | włączone |
| **⚠ Zmieniają opublikowane treści albo usuwają** | Edycja opublikowanych wpisów, wpisy do kosza, usuwanie kategorii i obrazków, komentarze do kosza. | **wyłączone**, poza *Komentarz do kosza lub spamu* |

Po zmianie kliknij **Zapisz narzędzia**, a potem **zsynchronizuj narzędzia w kliencie** (Perplexity pamięta starą
listę; zob. [rozdział 3](03-klienci.md)).

Rada: w Perplexity ustaw **Zawsze pytaj** dla każdego narzędzia ⚠, żeby agent pytał Cię przed każdą taką akcją.
Każde narzędzie ⚠ wymaga też od agenta podania powodu (`reason`), który zostaje zapisany przy wpisie (zob.
[rozdział 7](07-jak-to-dziala.md)).

Pełna lista z opisami jest w [rozdziale 4](04-narzedzia.md).

## Zakładka GitHub (opcjonalna)

Potrzebna tylko wtedy, gdy agent ma brać kod albo obrazki z **prywatnych** repozytoriów GitHub, albo gdy kończy się
limit 60 zapytań do GitHuba na godzinę.

![Zakładka GitHub](../img/github-pl.png)

1. Na GitHubie: **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new
   token**.
2. **Repository access**: wybierz repozytoria, które agent może czytać.
3. **Permissions → Repository permissions → Contents: Read-only**. Nic więcej.
4. Skopiuj token (zaczyna się od `github_pat_`), wklej w pole **Token GitHub**, kliknij **Zapisz**.
   → **Stan** pokazuje, że token jest ustawiony.

Token jest zapisany zaszyfrowany kluczami z Twojego `wp-config.php`. Agent go nie widzi: pliki pobiera serwer
strony i oddaje agentowi tylko wynik. Pamiętaj, że kod albo obrazek z prywatnego repozytorium staje się publiczny,
gdy opublikujesz wpis, w którym jest.

## Kolejka do przeglądu

Agent nie może publikować. Gdy skończy szkic, oddaje go narzędziem *Oddanie do przeglądu*: wpis dostaje status
**Oczekujący** i notatkę. Widzisz go w dwóch miejscach:

- licznik **Do przeglądu: N** na górnym pasku panelu,
- ramka **Do przeglądu od agenta** w kokpicie:

  ![Ramka w kokpicie](../img/review-pl.png)

Otwórz wpis, sprawdź go i opublikuj sam.

## Komentarze oznaczone przez agenta

Ekran **Komentarze** dostaje kolumnę **Agent** z oznaczeniem i powodem oraz filtr **Oznaczone przez agenta**.

![Komentarze z oznaczeniami agenta](../img/comments-pl.png)

Oznaczenie to tylko podpowiedź; zatwierdzasz, usuwasz albo oznaczasz jako spam jak zwykle.

---

Dalej: [3. Podłączenie klienta →](03-klienci.md)
