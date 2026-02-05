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
- Pobieranie i przetwarzanie faktur przychodzących z KSeF
- Generowanie wizualizacji faktur w stylu KSeF

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

- [X] ~~Dodaj wykluczenie KSeF do kart Stron trzecich (https://www.dolibarr.org/forum/t/ksef-module-for-dolibarr/30788/9)~~
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

## Changelog (In English)

>### Version v1.1.0
>- Reverted default to Demo environment instead of Production environment on enabling the module, just to ensure we don't accidentally submit official invoices when testing!)
>- Add NBP exchange rate API for foreign currency invoices - fetches rate for last working day before invoice date from button on invoice page and configurable rate mode like the multicurrency module
>- Block KSeF submission if NBP rate is missing for foreign currency invoices
>- Add multicurrency fields to the fa3_builder
>- Add some missing fa3 parser and fa3 builder fields that have support in Dolibarr
>- Add exchange rate display and VAT-UE number support to PDF visualization
>- Change payment due date box styling to match official KSeF visualization
>- Consolidate duplicated XML parsing between fa3 parser and pdf builder
>- Add Dolibarr MAIN_SECURITY_CSRF_WITH_TOKEN checks for CSRF protection
>- Add NIP Checksum validation to kseflib
>- Set max 30 retries before erroring out permanently and requiring new invoice
>- Timeouts for incoming invoice syncing to prevent deadlocking
>- Consolidate more logic into kseflib - the URLs in particular were getting out of hand
>- Add check for CSRF tokens on about page
>- Update the How To Use page with current instructions
>- Update index page

>### Version v1.0.0
>- /!\ Update to use PRODUCTION environment as default now that system is live /!\
>- Added warning in settings about production environment state
>- Add configuration to settings for optional FA(3) fields (Client code, productID, GTIN/EAN, unit of measure, and bank description)
>- Add timezone to DataWytworzeniaFa
>- Seller NIP now grabs from KSeF configuration properly
>- Add additional FA(3) fields (client code, product reference, GTIN, unit of measure, SWIFT code, bank name, bank description)
>- Fix two payment method mappings
>- Add FA(3) parser class to handle incoming invoices
>- Fix retry attempt counting for failed submissions
>- Improve capture of error details to help troubleshooting
>- Add incoming invoices page - and all the requesting, downloading, decrypting, processing, parsing, and syncing state with HWM things it needs
>- Remove thee unused methods from ksef.class.php
>- Add code 429 handling when rate limited
>- Add error handling for duplicated invoice number (happens in test/demo but unlikely to happen in production, yet still confusing)
>- Update extrafields to use translation strings and properly update when module is updated
>- Add PDF generation for incoming PDFs
>- Add button to Generate KSeF-style visualizations of invoices for outgoing invoices (this uses the Company Logo from company/organization settings and the size is set by the standard Setup/PDF/Logo height setting)
>- Add KRS, REGON, and BDO numbers to setup and FA3 Builder

>### Version v0.3.0
>- Add the exclusion options to third party page
>- Update KSeF API addresses to the new ones
>- Allow CURL to follow redirects if MF changes API again
>- Fix typo in "How to Use" page link so it properly uses translation
>- Fixed adding KSEF Number extrafield to PDF when it's included with QR code
>- Added language strings for the above
>- It should work on the day of system going live (1 Feb 2026). Just remember to change the environment to production at this time! (In v1.0 and beyond, after KSeF system is live, we will default to this environment)
>- In case of any errors about XML validation, since KSeF doesn't provide specific feedback on XML validation errors, here's some troubleshooting to catch things (for instance, not including NIP for customer, etc)
   >  - Download the schemat_FA(3)_v1-0E.xsd from the KSEF-Docs github
>  - Download the XML from the submission attempt either in the KSEF Submissions tab or on the invoice KSEF tab.
>  - Use xmllint to verify the XML against the schema, for instance xmllint --schema schemat_FA\(3\)_v1-0E.xsd FA3_invoice_2(1).xml  FA3_invoice_1.xml
>  - This should provide you specifically what validation check is being failed.


>### Version v0.2.0
>- Add warning when trying to modify KSeF-submitted invoice
>- Validate and Upload appears only once a line-item is placed in, like rest of dolibarr actions
>- Added Submit Online button for offline invoices
>- Invoices with previous date display warning
>- Added creation of offline invoices
>- Fixed FA(3) builder using current time when re-hashing offline invoice for submission, breaking hash
>- Added offline certificate handling for Offline invoices
>- Added online certificates to use in place of tokens
>- Can now switch between token and certificate authentication
>- Added ability to handle technical corrections for XML errors
>- Added certificate logic to kseflib
>- Added QRII code to offline invoices
>- Refactored the settings page to support certificates, selection between certs and tokens, etc

>### Version v0.1.0
>Initial Release
>
>Known issues:
>- FA(3) builder is yet not complete for some edge cases
>- Offline24 mode handling is missing, and it does not generate the Offline QR Codes yet
>- php-scoper issues with phpseclib - need to rework scoping
>
>Uncompleted tasks:
>- Add KSeF exclusion to Third Party tabs (https://www.dolibarr.org/forum/t/ksef-module-for-dolibarr/30788/9)