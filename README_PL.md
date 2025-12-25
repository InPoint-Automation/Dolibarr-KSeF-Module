# KSeF dla Dolibarr ERP CRM

<p align="center" width="100%">
<img alt="Icon" src="./img/ksef.png" width="20%" />
</p>

## [In English](README.md)

## Opis

Ten moduł integruje Dolibarr (https://www.dolibarr.org/) z KSeF (Krajowy System e-Faktur), polskim systemem elektronicznego fakturowania.

## Funkcje

- Generowanie plików FA(3) XML
- Podpisywanie i przesyłanie plików FA(3) XML do KSeF
- Pobieranie UPO (Potwierdzenie przesłania)
- Automatyczne dodawanie kodu QR do PDF faktury
- Możliwość wykluczenia stron trzecich z KSeF (np. generyczne podmioty B2C)
- Dodawanie pól KSeF (w tym klikalny numer KSeF i status wysyłki do weryfikacji) na głównej stronie faktury
- Dodawanie strony z przeglądem wszystkich przesyłek, a także karty KSeF na stronie faktury

## Wymagania

### Wymagania systemowe

- Dolibarr: Na dzień 02 grudnia 2025 testowano jedynie na v22.0.3
- PHP: 7.4 lub nowsza
- Rozszerzenia PHP:
    - `OpenSSL`
    - `cURL`
    - `DOM`
- Moduł kodów kreskowych Dolibarr: do generowania kodów QR w PDF-ach

### Konto KSeF

- Ważny polski NIP (numer identyfikacyjny podatnika) skonfigurowany w ustawieniach firmy.
- Aktywne konto KSeF z tokenem autoryzacji wygenerowanym z oficjalnego portalu KSeF dla wybranego środowiska
  (Test lub Produkcja).

## Instalacja

Zalecana metoda instalacji to instalacja z pliku ZIP.

1. Pobierz najnowszy plik ZIP modułu ze strony wydań
2. W Dolibarr przejdź do Strona główna → Konfiguracja → Moduły/Aplikacje
3. Wybierz kartę Wdróż/zainstaluj moduł zewnętrzny
4. Prześlij plik ZIP modułu
5. Znajdź moduł Integracja KSeF na liście i włącz go

## Wspieranie projektu

Zapraszamy do forku tego modułu lub do przesyłania PR-ów, aby wesprzeć społeczność Dolibarr

Instrukcje budowania znajdują się w README dla deweloperów [DEV.md](DEV.md)

## Plan rozwoju

- [ ] Dodaj wykluczenie KSeF do kart Stron trzecich (https://www.dolibarr.org/forum/t/ksef-module-for-dolibarr/30788/9)
- [ ] Generator FA(3) nie jest jeszcze kompletny dla niektórych przypadków brzegowych
- [ ] Napraw problemy php-scoper z phpseclib

## Licencje

### Kod główny

Ten projekt jest licencjonowany na warunkach AGPLv3+

Szczegóły znajdują się w pliku [LICENSE](LICENSE).

### Biblioteki stron trzecich

Ten moduł zawiera obecnie następujące biblioteki stron trzecich w katalogu `lib/vendor`:

- [phpseclib/phpseclib](https://github.com/phpseclib/phpseclib) - Licencja MIT - Wykorzystywana do uwierzytelniania API
  i szyfrowania faktury

- [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding) - Licencja MIT - Wykorzystywana
  przez phpseclib

- [composer](https://github.com/composer/composer) - Licencja MIT - Pliki Composera znajdują się obecnie w katalogu
  vendor do czasu naprawy scopingu i nie są używane w czasie działania

> Zależności są obecnie niescoped i dystrybuowane w stanie niezmiennym z Composera. Przyszłe wersje będą
> używać [humbug/php-scoper](https://github.com/humbug/php-scoper) do scopingu zależności

## Zastrzeżenie prawne

Ten moduł nie jest oficjalnie zatwierdzony ani powiązany z:

- Ministerstwem Finansów Polski
- Operatorami systemu KSeF (Krajowy System e-Faktur)

Korzystanie z tego modułu nie gwarantuje zgodności z polskimi przepisami podatkowymi. Użytkownicy są odpowiedzialni
za zapewnienie, że ich praktyki fakturowania są zgodne z obowiązującym prawem polskim.

## Podziękowania

Dziękujemy Éricowi Seignemu i wtyczce FacturX (https://registry.inligit.fr/cap-rel/dolibarr/plugin-facturx). Gdyby nie
możliwość nauki na podstawie ich modułu, ten projekt zajęłby znacznie więcej czasu

Ten moduł został opracowany przez InPoint Automation Sp. z o.o.
