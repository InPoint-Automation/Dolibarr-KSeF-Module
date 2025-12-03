<?php
/* Copyright (C) 2025 InPoint Automation Sp z o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ksef/class/actions_ksef.class.php
 * \ingroup ksef
 * \brief   KSEF action hooks
 */
class ActionsKSEF
{
    public $db;
    public $error = '';
    public $errors = array();
    public $results = array();
    public $resprints;
    public $priority;


    public function __construct($db)
    {
        $this->db = $db;
        $this->priority = 50;
    }

    /**
     * @brief Adds customer exclusion to PDF generation options
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @return int Status code
     * @called_by Dolibarr hook: formBuilddocOptions
     */
    public function formBuilddocOptions($parameters, &$object)
    {
        global $conf, $langs;

        if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
            return 0;
        }

        $contextArray = explode(':', $parameters['context']);
        if (!in_array('invoicecard', $contextArray)) {
            return 0;
        }

        if (!empty($conf->global->KSEF_EXCLUDED_CUSTOMERS)) {
            $excluded = array_map('trim', explode(',', $conf->global->KSEF_EXCLUDED_CUSTOMERS));

            if (in_array($object->socid, $excluded)) {
                $langs->load("ksef@ksef");
                $this->resprints = '<tr class="oddeven"><td colspan="5" style="padding: 8px;"><span class="opacitymedium"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_CustomerExcludedInfo') . '</span></td></tr>';
                return 1;
            }
        }

        if (!empty($object->array_options['options_ksef_number'])) {
            $langs->load("ksef@ksef");
            $out = '<tr class="oddeven"><td colspan="5" style="padding: 8px;"><span class="opacitymedium" style="color: #28a745;"><i class="fa fa-check-circle"></i> ' . $langs->trans('KSEF_IncludedInPDF') . ': <strong>' . htmlspecialchars($object->array_options['options_ksef_number']) . '</strong></span></td></tr>';
            $this->resprints = $out;
            return 1;
        }

        return 0;
    }

    /**
     * @brief Adds "Validate and Upload" / "Upload to KSeF" buttons to invoice card
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: addMoreActionsButtons
     * @calls ksefIsCustomerExcluded()
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        global $langs, $conf, $user;

        if ($parameters['currentcontext'] != 'invoicecard' || empty($object) || empty($object->id) || $object->element != 'facture') {
            return 0;
        }

        if (empty($conf->ksef) || empty($conf->ksef->enabled) || empty($user->rights->facture->creer)) {
            return 0;
        }

        $langs->load("ksef@ksef");

        if (ksefIsCustomerExcluded($object->socid)) {
            return 0;
        }

        $button_color = !empty($conf->global->KSEF_BUTTON_COLOR) ? $conf->global->KSEF_BUTTON_COLOR : '#dc3545';
        $button_style = 'style="background-color: ' . $button_color . '; color: white; font-weight: bold;"';

        static $spinner_added = false;
        if (!$spinner_added) {
            print '<style>
                @keyframes ksef-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                #ksef-processing-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 9999; justify-content: center; align-items: center; }
                #ksef-processing-overlay.active { display: flex; }
                .ksef-processing-box { background: white; padding: 30px 40px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); text-align: center; min-width: 300px; }
                .ksef-spinner-large { display: inline-block; width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: ksef-spin 1s linear infinite; margin: 0 auto 20px; }
                .ksef-processing-text { font-size: 16px; font-weight: bold; color: #333; margin-bottom: 10px; }
                .ksef-processing-subtext { font-size: 14px; color: #666; }
            </style>';

            print '<script>
                function ksefShowSpinner(event, button) {
                    event.preventDefault();
                    var overlay = document.getElementById("ksef-processing-overlay");
                    if (!overlay) {
                        overlay = document.createElement("div");
                        overlay.id = "ksef-processing-overlay";
                        overlay.innerHTML = \'<div class="ksef-processing-box"><div class="ksef-spinner-large"></div><div class="ksef-processing-text">\' + button.getAttribute("data-processing-text") + \'</div><div class="ksef-processing-subtext">' . dol_escape_js($langs->trans("PleaseWait")) . '</div></div>\';
                        document.body.appendChild(overlay);
                    }
                    var textEl = overlay.querySelector(".ksef-processing-text");
                    if (textEl && button.getAttribute("data-processing-text")) textEl.textContent = button.getAttribute("data-processing-text");
                    overlay.classList.add("active");
                    setTimeout(function() { window.location.href = button.href; }, 100);
                    return false;
                }
            </script>';
            $spinner_added = true;
        }

        if ($object->statut == 0) {
            print '<a class="butAction" ' . $button_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_validate_and_submit&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_ValidatingAndSubmitting') . '..." onclick="return ksefShowSpinner(event, this);">' . $langs->trans('KSEF_ValidateAndUpload') . '</a>';
            return 0;
        }

        if ($object->statut == 1) {
            $ksef_number = $object->array_options['options_ksef_number'] ?? '';
            if (empty($ksef_number)) {
                print '<a class="butAction" ' . $button_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_submit&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_SubmittingToKSEF') . '..." onclick="return ksefShowSpinner(event, this);">' . $langs->trans('KSEF_UploadToKSEF') . '</a>';
            }
            return 0;
        }

        return 0;
    }

    /**
     * @brief Handles KSeF submission actions (submit, validate_and_submit, download_xml, download_upo)
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: doActions
     * @calls KSEF::submitInvoice(), ksefUpdateInvoiceExtrafields(), handleSubmissionError()
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        dol_include_once('/ksef/class/ksef.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');

        global $conf, $user, $langs, $db;

        if (!in_array('invoicecard', array($parameters['currentcontext']))) {
            return 0;
        }

        if (($action == 'download_xml' || $action == 'download_upo') && GETPOST('id', 'int')) {
            $id = GETPOST('id', 'int');
            $submission = new KsefSubmission($db);

            if ($submission->fetch($id) > 0) {
                if ($action == 'download_xml' && !empty($submission->fa3_xml)) {
                    $filename = 'FA3_' . ($submission->ksef_number ?: 'invoice_' . $submission->fk_facture) . '.xml';
                    header('Content-Type: application/xml; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    echo $submission->fa3_xml;
                    exit;
                } elseif ($action == 'download_upo' && !empty($submission->upo_xml)) {
                    header('Content-Type: application/xml; charset=utf-8');
                    header('Content-Disposition: attachment; filename="UPO_' . $submission->ksef_number . '.xml"');
                    echo $submission->upo_xml;
                    exit;
                }
            }
            if ($action == 'download_upo') setEventMessages($langs->trans("KSEF_UPONotAvailable"), null, 'warnings');
        }

        if ($action == 'ksef_submit' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            if (ksefIsCustomerExcluded($object->socid)) {
                setEventMessages($langs->trans('KSEF_CustomerExcluded'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $ksef_submission = new KsefSubmission($db);
            if ($ksef_submission->fetch(0, $object->id) > 0 && in_array($ksef_submission->status, array('ACCEPTED', 'PENDING'))) {
                setEventMessages($langs->trans('KSEF_AlreadySubmitted'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KSEF($db);
                $result = $ksef->submitInvoice($object->id, $user, 'SYNC');

                if ($result && $result['status'] == 'ACCEPTED') {
                    setEventMessages($langs->trans('KSEF_SubmissionSuccess') . ' - ' . $result['ksef_number'], null, 'mesgs');
                    ksefUpdateInvoiceExtrafields($this->db, $object->id, $result['ksef_number'], 'ACCEPTED', dol_now(), true);
                } elseif ($result && $result['status'] == 'PENDING') {
                    setEventMessages($langs->trans('KSEF_SubmissionPending'), null, 'warnings');
                } else {
                    $this->handleSubmissionError($result, $ksef->error, $langs);
                }
            } catch (Exception $e) {
                setEventMessages('KSEF Error: ' . $e->getMessage(), null, 'errors');
                dol_syslog("KSEF submission exception: " . $e->getMessage(), LOG_ERR);
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_validate_and_submit' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            if (ksefIsCustomerExcluded($object->socid)) {
                setEventMessages($langs->trans('KSEF_CustomerExcluded'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            if ($object->validate($user) > 0) {
                // Release session lock to prevent blocking
                session_write_close();

                try {
                    $ksef = new KSEF($db);
                    $submit_result = $ksef->submitInvoice($object->id, $user, 'SYNC');

                    if ($submit_result && $submit_result['status'] == 'ACCEPTED') {
                        setEventMessages($langs->trans('InvoiceValidated') . ' - ' . $langs->trans('KSEF_SubmissionSuccess') . ' - ' . $submit_result['ksef_number'], null, 'mesgs');
                        ksefUpdateInvoiceExtrafields($this->db, $object->id, $submit_result['ksef_number'], 'ACCEPTED', dol_now(), true);
                    } elseif ($submit_result && $submit_result['status'] == 'PENDING') {
                        setEventMessages($langs->trans('InvoiceValidated') . ' - ' . $langs->trans('KSEF_SubmissionPending'), null, 'warnings');
                    } else {
                        $error_msg = $langs->trans('InvoiceValidated') . ' - ' . $langs->trans('KSEF_SubmissionFailed');
                        if (!empty($submit_result['error'])) $error_msg .= ': ' . $submit_result['error'];
                        setEventMessages($error_msg, null, 'warnings');
                    }
                } catch (Exception $e) {
                    setEventMessages($langs->trans('InvoiceValidated') . ' - KSEF Error: ' . $e->getMessage(), null, 'warnings');
                    dol_syslog("KSEF submission exception: " . $e->getMessage(), LOG_ERR);
                }
            } else {
                setEventMessages($object->error, $object->errors, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        return 0;
    }


    /**
     * @brief Handles submission error display
     * @param $result Submission result array
     * @param $ksef_error KSEF error message
     * @param $langs Language object
     * @called_by doActions()
     */
    private function handleSubmissionError($result, $ksef_error, $langs)
    {
        $error_msg = isset($result['status']) && $result['status'] == 'REJECTED' ? $langs->trans('KSEF_SubmissionRejected') : $langs->trans('KSEF_SubmissionFailed');

        if (!empty($result['error'])) {
            $error_msg .= ': ' . $result['error'];
        } elseif (!empty($ksef_error)) {
            $error_msg .= ': ' . $ksef_error;
        }
        setEventMessages($error_msg, null, 'errors');
    }

    /**
     * @brief Adds KSeF status display to invoice form
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: formObjectOptions
     * @calls KsefSubmission::fetchByInvoice()
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db;

        if (in_array($parameters['currentcontext'], array('invoicecard'))) {
            dol_include_once('/ksef/class/ksef_submission.class.php');
            dol_include_once('/ksef/lib/ksef.lib.php');
            dol_include_once('/ksef/class/ksef_client.class.php');

            print '<style>tr:has(.facture_extras_ksef_number), tr:has(.facture_extras_ksef_status), tr:has(.facture_extras_ksef_submission_date) { display: none !important; }</style>';

            $submission = new KsefSubmission($db);
            $result = $submission->fetchByInvoice($object->id);

            if ($result > 0) {
                $langs->load("ksef@ksef");

                print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_Status") . '</td><td colspan="3">';
                print ksefGetStatusBadge($submission->status);

                if (!empty($submission->error_code) && in_array($submission->status, array('REJECTED', 'FAILED'))) {
                    $ksefClient = new KsefClient($db);
                    $errorDesc = $ksefClient->getErrorDescription($submission->error_code);
                    print ' <span class="fa fa-exclamation-triangle classfortooltip" style="color: #d9534f; cursor: help;" title="' . dol_escape_htmltag("Error {$submission->error_code}: {$errorDesc}") . '"></span>';
                }

                if (!empty($submission->ksef_number)) {
                    $verifyUrl = ksefGetVerificationURL($submission->ksef_number, $submission->invoice_hash ?? null, $submission->environment);
                    print ' <a href="' . $verifyUrl . '" target="_blank" style="text-decoration: none;"><span class="badge badge-info">' . $submission->ksef_number . '</span></a>';
                }

                if (!empty($submission->date_submission)) {
                    print '<br><small style="color: #666;">' . $langs->trans("KSEF_SubmittedOn") . ': ' . dol_print_date($submission->date_submission, 'dayhour') . '</small>';
                }
                print '</td></tr>';
            }
        }
        return 0;
    }

    /**
     * @brief Adds submission count badge to KSeF tab
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: completeTabsHead
     */
    public function completeTabsHead($parameters, &$object, &$action, $hookmanager)
    {
        global $db;

        if ($object->element != 'facture' || empty($parameters['head']) || !is_array($parameters['head'])) return 0;

        foreach ($parameters['head'] as $key => $tab) {
            if (isset($tab[2]) && $tab[2] == 'ksef') {
                $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int)$object->id;
                $resql = $db->query($sql);
                if ($resql) {
                    $obj = $db->fetch_object($resql);
                    if ($obj->count > 0) {
                        $parameters['head'][$key][1] = 'KSeF <span class="badge marginleftonlyshort">' . $obj->count . '</span>';
                    }
                    $db->free($resql);
                }
                break;
            }
        }
        return 0;
    }

    /**
     * @brief Handles mass actions for invoice list
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: doMassActions
     */
    public function doMassActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $db;

        if (!in_array($parameters['currentcontext'], array('invoicelist'))) return 0;

        if ($parameters['massaction'] == "ksefZip") {
            $obj = new Facture($db);
            $destdir = stripslashes($parameters['diroutputmassaction']);

            if (!is_dir($destdir)) mkdir($destdir, 0700, true);

            $zipname = $destdir . '/ksef-invoices-' . date('Ymd-His') . '.zip';
            $zip = new ZipArchive();

            if ($zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                foreach ($parameters['toselect'] as $objectid) {
                    if ($obj->fetch($objectid) > 0) {
                        $pdffile = $conf->facture->dir_output . '/' . $obj->ref . "/" . $obj->ref . ".pdf";
                        if (file_exists($pdffile)) $zip->addFile($pdffile, $obj->ref . ".pdf");

                        $xmlfile = $conf->facture->dir_output . '/' . $obj->ref . "/" . $obj->ref . "_fa3.xml";
                        if (file_exists($xmlfile)) $zip->addFile($xmlfile, $obj->ref . "_fa3.xml");

                        $submission = new KsefSubmission($db);
                        if ($submission->fetchByInvoice($objectid) > 0 && !empty($submission->upo_xml)) {
                            $zip->addFromString($obj->ref . "_upo.xml", $submission->upo_xml);
                        }
                    }
                }
                $zip->close();

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename=' . basename($zipname));
                header('Content-Length: ' . filesize($zipname));
                readfile($zipname);
                unlink($zipname);
                exit;
            } else {
                $this->errors[] = 'Failed to create ZIP archive';
                return -1;
            }
        }
        return 0;
    }

    /**
     * @brief Adds KSeF options to mass actions
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: addMoreMassActions
     */
    public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs;

        if (empty($conf->ksef) || empty($conf->ksef->enabled)) return 0;
        if (!in_array($parameters['currentcontext'], array('invoicelist'))) return 0;

        $langs->load("ksef@ksef");
        $this->resprints = '<option value="ksefZip">' . $langs->trans("KSEF_DownloadKSEFZip") . '</option>';

        return 0;
    }

    /**
     * @brief Adds QR code to invoice PDF
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: afterPDFTotalTable
     * @calls KsefQR::addQRToPDF()
     */
    public function afterPDFTotalTable($parameters, $object, $action, $hookmanager)
    {
        global $conf, $ksefqralreadyadded;

        if (!empty($ksefqralreadyadded)) return 0;
        if (empty($conf->ksef) || empty($conf->ksef->enabled)) return 0;

        $object = $parameters['object'] ?? $object;

        if (empty($object) || $object->element != 'facture') return 0;
        if (empty($conf->global->KSEF_ADD_QR)) return 0;

        if (!isset($parameters['pdf']) || !is_object($parameters['pdf']) || !method_exists($parameters['pdf'], 'write2DBarcode')) {
            return 0;
        }

        dol_include_once('/ksef/class/ksef_qr.class.php');

        try {
            $qrGenerator = new KsefQR($this->db);
            $result = $qrGenerator->addQRToPDF($parameters['pdf'], $object->id);

            if ($result) {
                $ksefqralreadyadded = true;
                dol_syslog('KSeF: QR code added to invoice ' . $object->ref, LOG_INFO);
            }
        } catch (Exception $e) {
            dol_syslog('KSeF: Exception during QR generation - ' . $e->getMessage(), LOG_ERR);
        }

        return 0;
    }
}
