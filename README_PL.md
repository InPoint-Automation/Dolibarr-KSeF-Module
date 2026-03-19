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
>### Version v1.3.1
>- Reject malformed/incorrect invoices without blocking correct invoices
>- Batch processing improved so one malformed invoice no longer breaks entire batch
>- Fix bad KSeF number extraction fallback
>- Handle out-of-range dates
>- Incoming sync chunks in to 60-day windows to stay within KSeF API limits
>- Display sync errors from last sync in a popup list
>- Sync coverage timeline bar to incoming list and index pages
>- Statistics slightly improved
>- Reset sync state now resets to KSeF start date

>### Version v1.3.0
>- Importing of incoming invoices with individual and batch import from the incoming invoices list
>- Supplier auto-matching by NIP and EU VAT ID
>- Product auto-matching by product reference, supplier reference, and barcode
>- Auto-creation of suppliers and products during batch import
>- Batch import preview with validation warnings and blocking checks for correction invoices
>- Updated import page with per-line product selection and supplier selection/creation
>- Add import status tracking (New, Imported, Skipped, Error) with ability to reset or reopen
>- Fixed VAT vs NIP for incoming invoices
>- Incoming invoice sync now proper background processing to stop locking out the database when downloading thousands of invoices
>- More info displayed during incoming invoice sync
>- Block duplicate imports
>- Link imported incoming invoices to the supplier invoices
>- Fix KSeF verification URL on supplier invoices
>- Values of 0 were incorrectly treated as empty when calculating fields
>- Correction invoice type badges for KOR_ZAL and KOR_ROZ subtypes
>- Updated "How To Use" page with importing documentation
>- Fixed the mass actions for status and import tables
>- Add import related settings
>- Handle corrective invoices which increase total and mixed values (since Dolibarr doesn't really play nice with these)
>- Incoming invoice handles multicurrency and takes exchange rate from XML
>- PDF visualization auto-attached to imported supplier invoices
>- Two modes for incoming invoices which increase the amount - 1. Zero out the original invoice and create a replacement invoice for finalized amount 2. create only replacement invoice with difference
>- Warning when correction invoices have the same KSeF number as the corrected invoice

>### Version v1.2.0
>- Token/certificate choosing logic now chooses based on what is available
>- Add setting to persist configuration through module disable/re-enable (defaults to persisting)
>- Fix Issue #6 with date of sale by adding extrafield populated with sales order data. Date of sale is chosen in the following priority: 1. Linked shipment date 2. Linked sales order planned delivery date 3. Linked sales order date 4. Invoice date. It can be overridden per-invoice.
>- Fix broken UPO download and handle grabbing at a later time
>- Add setting to change default behavior of date of sale from the above to defaulting to invoice date (it's manually editable either way)
>- Update scheduled jobs, removed the broken job and added jobs for checking status and re-attemping offline invoices, syncing incoming invoices, downloading outstanding confirmations, and warning of offline deadlines
>- Add REST API endpoints with workaround for Dolibarr issue #32491
>- Fix Issue #7 missing unit prices in HTML/PDF - they should be calculated if not included in the xml
>- Add extrafields for supplier invoices so that importing has somewhere to write relevant fields
>- Update actions_ksef.class.php to handle supplier invoices
>- Add migration system to handle updates. Right now re-runs parsing when updating to new version since issue 7 requires this.
>- Add handling for gross price only and net price only invoices, including without unit prices, since these are technically all valid ways to provide invoices
>- Corrective invoices which correct multiple invoices now display correctly on the incoming invoices card and links now point to the corrected invoice


>### Version v1.1.1
>- Corrected payment mappings between Doliabrr/KSeF
>- Fix PDF generation error for correction invoices
>- QR code generation was using wrong translation method

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