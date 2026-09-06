# Współtworzenie

[English](../../CONTRIBUTING.md) · **Polski**

## Setup

```bash
composer install
```

Wymaga PHP `^8.2`. Zestaw testów działa na ręcznie napisanym stubie PSR-18
(`tests/Support/MockHttpClient`), więc do developmentu ani uruchamiania testów nie jest
potrzebny dostęp do sieci ani żywy klucz API.

## Przed otwarciem pull requesta

```bash
composer test           # PHPUnit: unit + integration
composer stan            # PHPStan, level max
composer cs               # coding standard, dry run
composer coverage-check  # every OpenAPI operation has a resource method
composer docs-check      # English and Polish docs pairs have not drifted apart
```

`composer cs-fix` nakłada poprawki, które zgłasza `composer cs`. CI uruchamia wszystkie pięć
sprawdzeń (plus zadanie z najniższymi zależnościami i sprawdzenie archiwum dystrybucyjnego) przy
każdym pushu i pull requeście - patrz `.github/workflows/ci.yml`.

## Dokumentacja zostaje dwujęzyczna

`README.md`, `CHANGELOG.md`, `SECURITY.md` i `CONTRIBUTING.md` mieszkają w korzeniu repo
wyłącznie po angielsku; ich polskie tłumaczenia są pod `docs/pl/` pod tą samą nazwą pliku.
Każdy inny dokument ma angielską kopię pod `docs/en/` i polską pod `docs/pl/`, znowu pod tą
samą nazwą. `composer docs-check` sprawdza, czy obie strony każdej pary mają te same nagłówki
i identyczne bloki kodu; wywala build, jeśli się rozjadą. Aktualizuj oba języki w tym samym
pull requeście - PR dotykający tylko jednej strony pary nie przejdzie CI.

## Changelog

Nieopublikowane zmiany trafiają pod `## [Unreleased]` w `CHANGELOG.md` (i
`docs/pl/CHANGELOG.md`), zgodnie z [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Tłumacz, *dlaczego* zmiana powstała, nie tylko co się zmieniło - taką konwencję trzyma reszta
pliku.

## Wydawanie

Wydanie to opublikowanie taga gita. To `CHANGELOG.md` nadaje temu tagowi znaczenie, więc musi
już opisywać, co się w nim znalazło - *zanim* tag powstanie, nie po fakcie.

1. Przenieś odpowiednie wpisy z `## [Unreleased]` do nowej sekcji `## [x.y.z] - RRRR-MM-DD`,
   zarówno w `CHANGELOG.md`, jak i w `docs/pl/CHANGELOG.md`. Zostaw `## [Unreleased]` na
   miejscu, puste, na to, co przyjdzie dalej. Zaktualizuj odnośniki na dole obu plików: nowa
   wersja linkuje do swojego wydania na GitHubie, a `[Unreleased]` wskazuje porównanie względem
   nowego taga.
2. Otwórz pull request z tą aktualizacją CHANGELOG-a i zmerguj go do `main`.
3. Otaguj commit na `main`, który niesie zaktualizowany CHANGELOG - nie wcześniejszy:
   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```
4. Zweryfikuj: CI jest zielone na tagu, a `CHANGELOG.md` na `main` pokazuje wersję pod
   datowanym nagłówkiem, a nie pod `## [Unreleased]`. Tag opublikowany, gdy changelog wciąż
   mówi "Unreleased", to wydanie bez śladu tego, co zawiera.

## Styl kodu

- PSR-12, egzekwowany przez `.php-cs-fixer.dist.php`.
- Żadnych komentarzy powtarzających to, co robi kod; komentarz zasługuje na miejsce, gdy
  tłumaczy nieoczywiste ograniczenie, obejście albo niezmiennik. To odzwierciedla docbloki już
  obecne w `src/` - przeczytaj kilka, zanim dopiszesz nowy kod.
- Nowe metody zasobów, wyjątki albo modele potrzebują odpowiadającego wpisu w
  `docs/en/api-reference.md` (i `docs/pl/api-reference.md`), a `composer coverage-check` musi
  nadal przechodzić, jeśli zmiana dotyka operacji z OpenAPI.
