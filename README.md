# KSEF for Dolibarr ERP CRM

<p align="center" width="100%">
<img alt="Icon" src="./img/ksef.png" width="20%" />
</p>

## [Po Polsku](README_PL.md)

## Description

This module integrates Dolibarr (https://www.dolibarr.org/) with KSEF (Krajowy System e-Faktur / National e-Invoice System), the Polish electronic
invoicing system.

## Features

- Generation of FA(3) XML
- Signing and submission of FA(3) XML invoices to KSeF
- Download of UPO (Recept of submission)
- Automatic addition of QR code to PDF invoice
- Can exclude 3rd parties from KSeF (e.g. generic B2C third parties)
- Adds KSeF Fields (including clickable KSeF number and submission status to verify submission) to main invoice page
- Adds page with overview of all submissions, as well as KSeF tab to invoice page
- Download and processing of incoming invoices from KSeF
- Generation of KSeF-style invoice visualizations

## Requirements

### System Requirements

- Dolibarr: As of 02 Dec 25 only tested on v22.0.3
- PHP: 7.4 or higher
- PHP Extensions:
    - `OpenSSL`
    - `cURL`
    - `DOM`
- Dolibarr Barcode Module: for generating QR codes on PDFs

### KSEF Account

- A valid Polish NIP (tax identification number) configured in your company settings.
- An active KSEF account with an authorization token generated from the official KSEF portal for your chosen
  environment (Test or Production).

## Installation

The recommended installation method is from a ZIP file.

1. Download the latest module ZIP file from the release page
2. In Dolibarr, go to Home → Setup → Modules/Applications
3. Select the Deploy/install external module tab
4. Upload the module's ZIP file
5. Find the KSEF Integration module in the list and enable it

## Contributing

Feel free to fork this module or contribute a PR to help improve the Dolibarr community

Build instructions can be found in the developer readme [DEV.md](DEV.md)

## Roadmap

- [X] ~~Add KSeF exclusion to Third Party tabs (https://www.dolibarr.org/forum/t/ksef-module-for-dolibarr/30788/9)~~
- [ ] FA(3) builder is yet not complete for some edge cases
- [ ] Fix php-scoper issues with phpseclib

## Licenses

### Main code

This project is licensed under the AGPLv3+

See [LICENSE](LICENSE) file for details.

### Third-Party Libraries

This module currently includes the following third-party libraries in the `lib/vendor` directory:

- [phpseclib/phpseclib](https://github.com/phpseclib/phpseclib) - MIT License - Used for API authentication and invoice
  encryption

- [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding) - MIT License - Used by
  phpseclib

- [composer](https://github.com/composer/composer) - MIT License - Composer files are currently vendor directory until
  scoping is fixed and are not used at runtime

> Dependencies are currently unscoped and distributed as-is from Composer. Future versions will
> use [humbug/php-scoper](https://github.com/humbug/php-scoper) to scope dependencies

## Legal Notice

This module is not officially endorsed by or affiliated with:

- The Polish Ministry of Finance
- The KSeF (Krajowy System e-Faktur) system operators

Use of this module does not guarantee compliance with Polish tax regulations. Users are responsible for ensuring their
invoicing practices comply with current Polish law.

## Credits

Thanks to Éric Seigne and the FacturX plugin (https://registry.inligit.fr/cap-rel/dolibarr/plugin-facturx). Without
learning from their module, this project would have taken ages longer to complete

This module was developed by InPoint Automation Sp. z o.o.


## Changelog

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