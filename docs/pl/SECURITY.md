# Polityka bezpieczeństwa

[English](../../SECURITY.md) · **Polski**

Ten SDK niesie klucz API w każdym żądaniu, a przez `RmaRequest` obsługuje e-mail, adres
pocztowy i numer konta bankowego klienta. Wszystko, co mogłoby wyciec - klucz albo dane
osobowe - traktuj jako problem bezpieczeństwa, nie zwykły błąd.

## Zgłaszanie podatności

Nie otwieraj publicznego issue na GitHubie dla podejrzewanej podatności - to publikuje
szczegóły, zanim powstanie poprawka.

Zamiast tego użyj jednego z:

- [GitHub Security Advisories](https://github.com/RetJet/returns-api-php-client/security/advisories/new)
  dla tego repozytorium (domyślnie prywatne).
- E-maila [contact@retjet.com](mailto:contact@retjet.com).

Dołącz wersję, której to dotyczy, minimalną reprodukcję i wpływ, jaki rozumiesz (co zyskuje
atakujący i co musi mieć wcześniej). Potwierdzimy zgłoszenie, przygotujemy poprawkę i ustalimy
z tobą harmonogram ujawnienia, zanim cokolwiek trafi do publicznej wiadomości.

## Wspierane wersje

Przed `v1.0.0` wspierane jest wyłącznie ostatnie otagowane wydanie. Gdy pojawią się otagowane
wydania, ta sekcja będzie wymieniać, które gałęzie major nadal dostają poprawki bezpieczeństwa.

## Zakres

W zakresie: kod tego pakietu (`src/`) - obsługa poświadczeń, parsowanie żądań/odpowiedzi i
wszystko, co mogłoby ujawnić klucz API albo dane osobowe niesione przez `RmaRequest`.

Poza zakresem: samo API RetJet (zgłoś to osobno do RetJet) oraz podatności w zależnościach
tego pakietu (zgłoś je do ich autorów; issue tutaj otwórz tylko wtedy, gdy podatność zależności
wymaga podbicia wersji po naszej stronie).

Wszystko, co nie jest problemem bezpieczeństwa - pytanie o użycie, zwykły błąd, prośba o nową
funkcję - trafia do [issue trackera](https://github.com/RetJet/returns-api-php-client/issues)
albo, jeśli wolisz nie pisać publicznie, na [support@retjet.com](mailto:support@retjet.com).
Nie wysyłaj tam podatności: to zwykła kolejka wsparcia, czytana przez więcej osób i bez
gwarancji poufności.
