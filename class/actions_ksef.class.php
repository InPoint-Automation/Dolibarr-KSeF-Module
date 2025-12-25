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
     * @brief warning when trying to modify a KSeF-submitted invoice
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook formConfirm
     */
    public function formConfirm($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $conf, $db;

        if (!in_array('invoicecard', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
            return 0;
        }

        if ($action != 'modif' && $action != 'confirm_modif') {
            return 0;
        }

        if (empty($object) || $object->element != 'facture') {
            return 0;
        }

        dol_include_once('/ksef/class/ksef_submission.class.php');
        $submission = new KsefSubmission($db);

        if ($submission->fetchByInvoice($object->id) <= 0) {
            return 0;
        }

        $langs->load("ksef@ksef");

        $warning_msg = '';
        $is_offline = !empty($submission->offline_mode);
        $has_ksef_number = !empty($submission->ksef_number) && strpos($submission->ksef_number, 'OFFLINE') === false;

        if ($has_ksef_number) {
            $warning_msg = '<div class="warning" style="margin-bottom: 15px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">';
            $warning_msg .= '<i class="fa fa-exclamation-triangle" style="color: #856404;"></i> ';
            $warning_msg .= '<strong>' . $langs->trans('KSEF_ModifyWarningTitle') . '</strong><br>';
            $warning_msg .= $langs->trans('KSEF_ModifyWarningKSeF', $submission->ksef_number);
            $warning_msg .= '</div>';
        } elseif ($is_offline) {
            $warning_msg = '<div class="warning" style="margin-bottom: 15px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">';
            $warning_msg .= '<i class="fa fa-exclamation-triangle" style="color: #856404;"></i> ';
            $warning_msg .= '<strong>' . $langs->trans('KSEF_ModifyWarningTitle') . '</strong><br>';
            $warning_msg .= $langs->trans('KSEF_ModifyWarningOffline');
            if (!empty($submission->offline_deadline)) {
                $warning_msg .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . dol_print_date($submission->offline_deadline, 'dayhour') . '</small>';
            }
            $warning_msg .= '</div>';
        } elseif ($submission->status == 'FAILED') {
            $warning_msg = '<div class="info" style="margin-bottom: 15px; padding: 10px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px;">';
            $warning_msg .= '<i class="fa fa-info-circle" style="color: #0c5460;"></i> ';
            $warning_msg .= $langs->trans('KSEF_ModifyInfoFailed');
            $warning_msg .= '</div>';
        }

        if (!empty($warning_msg)) {
            $this->resprints = $warning_msg;
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
        dol_include_once('/ksef/class/ksef_submission.class.php');
        global $langs, $conf, $user, $db;

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
        $retry_style = 'style="background-color: #ffc107; color: #212529; font-weight: bold;"';
        $offline_style = 'style="background-color: #28a745; color: white; font-weight: bold;"';

        $submission = new KsefSubmission($db);
//        $has_submission = ($submission->fetchByInvoice($object->id) > 0);
        $has_submission = (
            $submission->fetchByInvoice($object->id) > 0
            && !empty($submission->status)
        );

        $is_accepted = $has_submission && $submission->status == 'ACCEPTED';
        $is_pending = $has_submission && $submission->status == 'PENDING';
        $is_failed = $has_submission && in_array($submission->status, array('FAILED', 'REJECTED', 'TIMEOUT'));
        $is_offline = $has_submission && !empty($submission->offline_mode);
        $has_ksef_number = $has_submission && !empty($submission->ksef_number) &&
            strpos($submission->ksef_number, 'OFFLINE') === false &&
            strpos($submission->ksef_number, 'PENDING') === false &&
            strpos($submission->ksef_number, 'ERROR') === false;

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

        if ($object->statut == 0 && !empty($object->lines)) {
            print '<a class="butAction" ' . $button_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_validate_and_submit&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_ValidatingAndSubmitting') . '..." onclick="return ksefShowSpinner(event, this);">' . $langs->trans('KSEF_ValidateAndUpload') . '</a>';
            return 0;
        }

        if ($object->statut == 1) {
            if ($is_accepted && $has_ksef_number) {
                return 0;
            }

            if ($is_pending) {
                print '<span class="butActionRefused classfortooltip" title="' . $langs->trans('KSEF_SubmissionInProgress') . '">' . $langs->trans('KSEF_UploadToKSEF') . ' <i class="fa fa-spinner fa-spin"></i></span>';
                return 0;
            }

            $is_offline_status = $has_submission && $submission->status == 'OFFLINE';

            if ($is_offline_status) {
                $deadline_passed = !empty($submission->offline_deadline) && ksefIsDeadlinePassed($submission->offline_deadline);
                if ($deadline_passed) {
                    print '<span class="butActionRefused classfortooltip" style="background-color: #dc3545; color: white;" title="' . $langs->trans('KSEF_OfflineDeadlinePassed') . '">';
                    print '<i class="fa fa-exclamation-triangle"></i> ' . $langs->trans('KSEF_DeadlinePassed') . '</span>';
                } else {
                    print '<a class="butAction" ' . $button_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_retry_online&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_SubmittingToKSEF') . '..." onclick="return ksefShowSpinner(event, this);">';
                    print '<i class="fa fa-cloud-upload"></i> ' . $langs->trans('KSEF_SubmitOnline') . '</a>';
                    if (!empty($submission->offline_deadline)) {
                        $hours_remaining = ($submission->offline_deadline - dol_now()) / 3600;
                        if ($hours_remaining < 8) {
                            print ' <span class="badge badge-warning" style="margin-left: 5px;"><i class="fa fa-clock-o"></i> ' . sprintf($langs->trans('KSEF_HoursRemaining'), round($hours_remaining)) . '</span>';
                        }
                    }
                }
                return 0;
            }

            $offline_cert_check = ksefIsOfflineCertificateConfigured();

            if ($is_failed) {
                if (!empty($submission->error_message)) {
                    $error_display = dol_escape_htmltag(dol_trunc($submission->error_message, 80));
                    if (!empty($submission->error_code)) {
                        dol_include_once('/ksef/class/ksef_client.class.php');
                        $client = new KsefClient($db);
                        $error_desc = $client->getErrorDescription($submission->error_code);
                        $error_display = "Error {$submission->error_code}: " . dol_escape_htmltag($error_desc);
                    }
                }

                print '<a class="butAction" ' . $retry_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_retry&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_RetryingSubmission') . '..." onclick="return ksefShowSpinner(event, this);" title="' . ($error_display ?? '') . '"><i class="fa fa-refresh"></i> ' . $langs->trans('KSEF_RetrySubmission') . '</a>';

                if ($offline_cert_check['configured']) {
                    print '<a class="butAction" ' . $offline_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_create_offline&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_CreatingOfflineInvoice') . '..." onclick="return ksefShowSpinner(event, this);"><i class="fa fa-file-text-o"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</a>';
                } else {
                    print '<span class="butActionRefused classfortooltip" title="' . $langs->trans('KSEF_OfflineCertificateRequired') . '"><i class="fa fa-file-text-o"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</span>';
                }
                return 0;
            }

            if (!$has_submission) {
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
            if ($action == 'download_upo') {
                setEventMessages($langs->trans("KSEF_UPONotAvailable"), null, 'warnings');
            }
        }

        if ($action == 'ksef_submit' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            if (ksefIsCustomerExcluded($object->socid)) {
                setEventMessages($langs->trans('KSEF_CustomerExcluded'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $ksef_submission = new KsefSubmission($db);
            if ($ksef_submission->fetch(0, $object->id) > 0) {
                if ($ksef_submission->status == 'ACCEPTED') {
                    setEventMessages($langs->trans('KSEF_AlreadySubmitted'), null, 'warnings');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
                if ($ksef_submission->status == 'PENDING') {
                    setEventMessages($langs->trans('KSEF_SubmissionInProgress'), null, 'warnings');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            $backdate_info = ksefDetectBackdating($object->date);
            if ($backdate_info['is_backdated']) {
                $_SESSION['ksef_offline_confirm'] = array(
                    'invoice_id' => $object->id,
                    'days_behind' => $backdate_info['days_behind'],
                    'deadline' => $backdate_info['deadline'],
                    'reason' => 'backdated',
                    'message' => $backdate_info['reason']
                );
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
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
                } elseif ($result && $result['status'] == 'FAILED') {
                    $error_msg = $langs->trans('KSEF_SubmissionFailed');
                    if (!empty($result['error'])) {
                        $error_msg .= ': ' . $result['error'];
                    }
                    setEventMessages($error_msg, null, 'errors');
                } else {
                    $this->handleSubmissionError($result, $ksef->error, $langs);
                }
            } catch (Exception $e) {
                setEventMessages($langs->trans('KSEF_SubmissionFailed') . ': ' . $e->getMessage(), null, 'errors');
                dol_syslog("KSEF submission exception: " . $e->getMessage(), LOG_ERR);
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_retry' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KSEF($db);
                $result = $ksef->retrySubmission($object->id, $user);

                if ($result && $result['status'] == 'ACCEPTED') {
                    setEventMessages($langs->trans('KSEF_SubmissionSuccess') . ' - ' . $result['ksef_number'], null, 'mesgs');
                    ksefUpdateInvoiceExtrafields($this->db, $object->id, $result['ksef_number'], 'ACCEPTED', dol_now(), true);
                } elseif ($result && $result['status'] == 'NEEDS_OFFLINE_CONFIRMATION') {
                    ksefSessionWrite('ksef_offline_confirm', array(
                        'invoice_id' => $object->id,
                        'days_behind' => $result['backdate_info']['days_behind'] ?? 0,
                        'deadline' => $result['deadline'],
                        'reason' => 'backdated_retry'
                    ));
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
                    exit;
                } elseif ($result && $result['status'] == 'FAILED') {
                    $error_msg = $langs->trans('KSEF_RetryFailed');
                    if (!empty($result['error'])) {
                        $error_msg .= ': ' . $result['error'];
                    }
                    setEventMessages($error_msg, null, 'errors');
                } elseif ($result && $result['status'] == 'PENDING') {
                    setEventMessages($langs->trans('KSEF_SubmissionInProgress'), null, 'warnings');
                } else {
                    setEventMessages($langs->trans('KSEF_RetryFailed') . ': ' . ($result['error'] ?? $ksef->error ?? 'Unknown'), null, 'errors');
                }
            } catch (Exception $e) {
                setEventMessages('KSEF Error: ' . $e->getMessage(), null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_create_offline' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            $offline_cert_check = ksefIsOfflineCertificateConfigured();
            if (!$offline_cert_check['configured']) {
                $_SESSION['ksef_needs_offline_cert'] = array(
                    'invoice_id' => $object->id,
                    'missing' => $offline_cert_check['missing']
                );
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_cert_required&token=' . newToken());
                exit;
            }

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $submission = new KsefSubmission($db);
            if ($submission->fetchByInvoice($object->id) > 0) {
                if ($submission->status == 'ACCEPTED') {
                    setEventMessages($langs->trans('KSEF_AlreadySubmitted'), null, 'warnings');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KSEF($db);
                $result = $ksef->submitInvoiceOffline($object->id, $user, 'failed_submission');

                if ($result && $result['status'] == 'OFFLINE') {
                    $msg = $langs->trans('KSEF_OfflineInvoiceCreated');
                    if (!empty($result['ksef_number'])) {
                        $msg .= ': ' . $result['ksef_number'];
                    }
                    if (!empty($result['offline_deadline'])) {
                        $msg .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . dol_print_date($result['offline_deadline'], 'dayhour') . '</small>';
                    }
                    setEventMessages($msg, null, 'mesgs');
                } else {
                    $error_msg = $langs->trans('KSEF_OfflineCreationFailed');
                    if (!empty($result['error'])) {
                        $error_msg .= ': ' . $result['error'];
                    }
                    setEventMessages($error_msg, null, 'errors');
                }
            } catch (Exception $e) {
                setEventMessages('KSEF Error: ' . $e->getMessage(), null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_show_failure_dialog' && !empty($_SESSION['ksef_failed_confirm'])) {
            $fail_data = $_SESSION['ksef_failed_confirm'];
            if ($fail_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");

                dol_include_once('/ksef/class/ksef_client.class.php');
                $ksefClient = new KsefClient($db);

                $is_connection_error = !empty($fail_data['is_connection_error']);

                $error_desc = '';
                if (!empty($fail_data['error_code'])) {
                    $error_desc = $ksefClient->getErrorDescription($fail_data['error_code']);
                }

                $offline_deadline = ksefCalculateOfflineDeadline($object->date);

                print '<div id="ksef-failure-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
                print '<div style="background: white; padding: 30px; border-radius: 12px; max-width: 550px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';

                print '<div style="text-align: center; margin-bottom: 20px;">';
                if ($is_connection_error) {
                    print '<div style="width: 60px; height: 60px; background: #ffc10720; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                    print '<i class="fa fa-wifi" style="font-size: 28px; color: #ffc107;"></i>';
                    print '</div>';
                    print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $langs->trans('KSEF_ConnectionFailed') . '</h3>';
                } else {
                    print '<div style="width: 60px; height: 60px; background: #dc354520; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                    print '<i class="fa fa-exclamation-circle" style="font-size: 28px; color: #dc3545;"></i>';
                    print '</div>';
                    print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $langs->trans('KSEF_SubmissionFailed') . '</h3>';
                }
                print '</div>';

                if ($is_connection_error) {
                    print '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                    print '<p style="margin: 0 0 10px 0; color: #856404;"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_ConnectionErrorExplanation') . '</p>';
                    print '<p style="margin: 0; font-size: 12px; color: #666;">' . dol_escape_htmltag($fail_data['error']) . '</p>';
                    print '</div>';
                } else {
                    print '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                    if (!empty($fail_data['error_code'])) {
                        print '<p style="margin: 0 0 5px 0; font-weight: bold; color: #721c24;">Error ' . $fail_data['error_code'] . '</p>';
                        if (!empty($error_desc)) {
                            print '<p style="margin: 0 0 10px 0; color: #721c24;">' . dol_escape_htmltag($error_desc) . '</p>';
                        }
                    }
                    print '<p style="margin: 0; font-size: 13px; color: #856404;">' . dol_escape_htmltag($fail_data['error']) . '</p>';
                    print '</div>';
                }

                print '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0 0 10px 0; font-weight: bold; color: #155724;"><i class="fa fa-file-text-o"></i> ' . $langs->trans('KSEF_OfflineOptionAvailable') . '</p>';
                print '<p style="margin: 0 0 10px 0; font-size: 13px; color: #155724;">' . $langs->trans('KSEF_OfflineOptionExplanation') . '</p>';
                print '<p style="margin: 0; font-size: 12px; color: #155724;"><strong>' . $langs->trans('KSEF_OfflineDeadline') . ':</strong> ' . dol_print_date($offline_deadline, 'dayhour') . '</p>';
                print '</div>';

                print '<div style="display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap;">';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_dismiss_failure&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Close') . '</a>';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_retry&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background: #ffc107; color: #212529;">';
                print '<i class="fa fa-refresh"></i> ' . $langs->trans('KSEF_TryAgain') . '</a>';
                print '<a class="button button-save" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_submit_offline_confirmed&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background: #28a745; color: white;">';
                print '<i class="fa fa-file-text-o"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</a>';

                print '</div>';

                print '</div>';
                print '</div>';
            }
            return 0;
        }

        if ($action == 'ksef_show_cert_required' && !empty($_SESSION['ksef_needs_offline_cert'])) {
            $cert_data = $_SESSION['ksef_needs_offline_cert'];
            if ($cert_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");

                print '<div id="ksef-cert-required-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
                print '<div style="background: white; padding: 30px; border-radius: 12px; max-width: 500px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';
                print '<div style="text-align: center; margin-bottom: 20px;">';
                print '<div style="width: 60px; height: 60px; background: #ffc10720; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                print '<i class="fa fa-certificate" style="font-size: 28px; color: #ffc107;"></i>';
                print '</div>';
                print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $langs->trans('KSEF_OfflineCertificateRequired') . '</h3>';
                print '</div>';
                print '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0 0 10px 0; color: #856404;"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_OfflineCertificateRequiredExplanation') . '</p>';
                print '<p style="margin: 0; font-size: 13px; color: #856404;">' . $langs->trans('KSEF_OfflineCertificateMissingItems') . ':</p>';
                print '<ul style="margin: 5px 0 0 0; padding-left: 20px; color: #856404;">';
                foreach ($cert_data['missing'] as $item) {
                    $item_label = $langs->trans('KSEF_Missing_' . $item);
                    print '<li>' . $item_label . '</li>';
                }
                print '</ul>';
                print '</div>';
                print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_dismiss_cert_dialog&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Close') . '</a>';
                print '<a class="button button-save" href="' . dol_buildpath('/ksef/admin/setup.php', 1) . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background: #007bff; color: white;">';
                print '<i class="fa fa-cog"></i> ' . $langs->trans('KSEF_GoToSetup') . '</a>';
                print '</div>';
                print '</div>';
                print '</div>';

                unset($_SESSION['ksef_needs_offline_cert']);
            }
            return 0;
        }

        if ($action == 'ksef_dismiss_cert_dialog') {
            unset($_SESSION['ksef_needs_offline_cert']);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_dismiss_failure') {
            unset($_SESSION['ksef_failed_confirm']);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_confirm_offline_after_fail' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $submission = new KsefSubmission($db);
            $submission->fetchByInvoice($object->id);
            $offline_cert_check = ksefIsOfflineCertificateConfigured();
            $_SESSION['ksef_failed_confirm'] = array(
                'invoice_id' => $object->id,
                'error' => $submission->error_message ?? 'Previous submission failed',
                'error_code' => $submission->error_code ?? null,
                'submission_id' => $submission->rowid ?? null
            );

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_failure_dialog&token=' . newToken());
            exit;
        }

        if ($action == 'ksef_retry_online' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $existing = new KsefSubmission($db);

            if ($existing->fetchByInvoice($object->id) <= 0) {
                setEventMessages($langs->trans('KSEF_NoSubmissionFound'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $is_valid_offline = ($existing->status == 'OFFLINE') ||
                ($existing->status == 'ACCEPTED' && !empty($existing->offline_mode) &&
                    strpos($existing->ksef_number, 'OFFLINE') !== false);

            if (!$is_valid_offline) {
                setEventMessages($langs->trans('KSEF_InvalidStatusForUpload'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            if (!empty($existing->offline_deadline) && ksefIsDeadlinePassed($existing->offline_deadline)) {
                setEventMessages($langs->trans('KSEF_OfflineDeadlinePassed'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Release session lock to prevent blocking
            session_write_close();
            ksefSessionUnset('ksef_failed_confirm');

            try {
                $ksef = new KSEF($db);

                if (!empty($existing->fa3_xml)) {
                    $result = $ksef->retryOfflineWithStoredXML($existing, $user);
                } else {
                    $result = $ksef->submitInvoice($object->id, $user, 'SYNC');
                }

                if ($result && $result['status'] == 'ACCEPTED' &&
                    strpos($result['ksef_number'], 'OFFLINE') === false) {
                    setEventMessages($langs->trans('KSEF_OnlineSubmissionSuccess') . ' - ' . $result['ksef_number'], null, 'mesgs');
                    ksefUpdateInvoiceExtrafields($this->db, $object->id, $result['ksef_number'], 'ACCEPTED', dol_now(), true);
                } elseif ($result && $result['status'] == 'FAILED') {
                    setEventMessages($langs->trans('KSEF_OnlineRetryFailed') . ': ' . ($result['error'] ?? ''), null, 'errors');
                } else {
                    setEventMessages($langs->trans('KSEF_SubmissionStatus') . ': ' . ($result['status'] ?? 'Unknown'), null, 'mesgs');
                }
            } catch (Exception $e) {
                setEventMessages('KSEF Error: ' . $e->getMessage(), null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_confirm_offline' && !empty($_SESSION['ksef_offline_confirm'])) {
            $confirm_data = $_SESSION['ksef_offline_confirm'];
            if ($confirm_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");

                $is_backdated = ($confirm_data['reason'] == 'backdated');
                $is_connection_error = ($confirm_data['reason'] == 'connection_error');

                if ($is_backdated) {
                    $title = $langs->trans('KSEF_OfflineBackdatedTitle');
                    $icon_color = '#ffc107';
                    $message = sprintf($langs->trans('KSEF_OfflineBackdatedMessage'), $confirm_data['days_behind']);
                } else {
                    $title = $langs->trans('KSEF_OfflineConnectionTitle');
                    $icon_color = '#dc3545';
                    $message = $langs->trans('KSEF_OfflineConnectionMessage');
                }

                print '<div id="ksef-offline-confirm-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
                print '<div style="background: white; padding: 30px; border-radius: 12px; max-width: 520px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';
                print '<div style="text-align: center; margin-bottom: 20px;">';
                print '<div style="width: 60px; height: 60px; background: ' . $icon_color . '20; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                print '<i class="fa fa-exclamation-triangle" style="font-size: 28px; color: ' . $icon_color . ';"></i>';
                print '</div>';
                print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $title . '</h3>';
                print '</div>';
                print '<div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0 0 10px 0; color: #555;">' . $message . '</p>';
                if ($is_connection_error && !empty($confirm_data['message'])) {
                    print '<p style="margin: 0; font-size: 12px; color: #888;"><strong>Error:</strong> ' . dol_escape_htmltag($confirm_data['message']) . '</p>';
                }
                print '</div>';
                print '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0; font-size: 14px;"><strong><i class="fa fa-clock-o"></i> ' . $langs->trans('KSEF_OfflineDeadline') . ':</strong><br>';
                print dol_print_date($confirm_data['deadline'], 'dayhour') . '</p>';
                print '</div>';
                print '<p style="color: #666; font-size: 13px; margin-bottom: 25px;">';
                print '<i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_OfflineExplanation');
                print '</p>';
                print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_cancel_offline&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Cancel') . '</a>';
                print '<a class="button button-save" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_submit_offline_confirmed&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background: #28a745; color: white;">';
                print '<i class="fa fa-paper-plane"></i> ' . $langs->trans('KSEF_ConfirmOfflineSubmit') . '</a>';
                print '</div>';

                print '</div>';
                print '</div>';
            }
            return 0;
        }

        if ($action == 'ksef_cancel_offline') {
            unset($_SESSION['ksef_offline_confirm']);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_submit_offline_confirmed' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            $offline_reason = '';

            if (!empty($_SESSION['ksef_offline_confirm'])) {
                $confirm_data = $_SESSION['ksef_offline_confirm'];
                $offline_reason = $confirm_data['reason'] ?? 'user_confirmed';
                unset($_SESSION['ksef_offline_confirm']);
            }
            if (!empty($_SESSION['ksef_failed_confirm'])) {
                $fail_data = $_SESSION['ksef_failed_confirm'];
                $offline_reason = 'failed_submission';
                unset($_SESSION['ksef_failed_confirm']);
            }

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KSEF($db);

                $result = $ksef->submitInvoiceOffline($object->id, $user, $offline_reason);

                if ($result && $result['status'] == 'OFFLINE') {
                    $msg = $langs->trans('KSEF_OfflineInvoiceCreated');
                    if (!empty($result['ksef_number'])) {
                        $msg .= ' - ' . $result['ksef_number'];
                    }
                    $msg .= ' <span class="badge badge-warning">' . $langs->trans('KSEF_OfflineMode') . '</span>';
                    if (!empty($result['offline_deadline'])) {
                        $msg .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . dol_print_date($result['offline_deadline'], 'dayhour') . '</small>';
                    }
                    setEventMessages($msg, null, 'mesgs');
                } else {
                    $error_msg = $langs->trans('KSEF_OfflineCreationFailed');
                    if (!empty($result['error'])) {
                        $error_msg .= ': ' . $result['error'];
                    }
                    setEventMessages($error_msg, null, 'errors');
                }
            } catch (Exception $e) {
                setEventMessages('KSEF Error: ' . $e->getMessage(), null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_technical_correction' && !empty($user->rights->facture->creer)) {
            $submission_id = GETPOST('submission_id', 'int');
            $langs->load("ksef@ksef");

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KSEF($db);
                $result = $ksef->submitTechnicalCorrection($object->id, $submission_id, $user);

                if ($result && $result['status'] != 'ERROR') {
                    setEventMessages($langs->trans('KSEF_TechnicalCorrectionSubmitted'), null, 'mesgs');
                } else {
                    setEventMessages($langs->trans('KSEF_TechnicalCorrectionFailed') . ': ' . ($result['error'] ?? $ksef->error), null, 'errors');
                }
            } catch (Exception $e) {
                setEventMessages('KSEF Error: ' . $e->getMessage(), null, 'errors');
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

            $backdate_info = ksefDetectBackdating($object->date);
            if ($backdate_info['is_backdated']) {
                if ($object->validate($user) > 0) {
                    $_SESSION['ksef_offline_confirm'] = array(
                        'invoice_id' => $object->id,
                        'days_behind' => $backdate_info['days_behind'],
                        'deadline' => $backdate_info['deadline'],
                        'reason' => 'backdated',
                        'message' => $backdate_info['reason'],
                        'from_validate' => true
                    );
                    setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
                    exit;
                } else {
                    setEventMessages($object->error, $object->errors, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            if ($object->validate($user) > 0) {

                // Release session lock to prevent blocking
                session_write_close();

                try {
                    $ksef = new KSEF($db);
                    $submit_result = $ksef->submitInvoice($object->id, $user, 'SYNC');

                    if ($submit_result && $submit_result['status'] == 'NEEDS_CONFIRMATION') {
                        $backdate_info = $submit_result['backdate_info'];
                        ksefSessionWrite('ksef_offline_confirm', array(
                            'invoice_id' => $object->id,
                            'days_behind' => $backdate_info['days_behind'] ?? 0,
                            'deadline' => $submit_result['deadline'],
                            'reason' => $backdate_info['reason'] ?? 'connection_error',
                            'from_validate' => true
                        ));
                        setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
                        exit;
                    }

                    if ($submit_result && $submit_result['status'] == 'ACCEPTED') {
                        $msg = $langs->trans('InvoiceValidated') . ' - ' . $langs->trans('KSEF_SubmissionSuccess') . ' - ' . $submit_result['ksef_number'];
                        if (!empty($submit_result['offline_mode'])) {
                            $msg .= ' (' . $langs->trans('KSEF_OfflineMode') . ')';
                        }
                        setEventMessages($msg, null, 'mesgs');
                        ksefUpdateInvoiceExtrafields($this->db, $object->id, $submit_result['ksef_number'], 'ACCEPTED', dol_now(), true);

                    } elseif ($submit_result && $submit_result['status'] == 'FAILED') {
                        setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');

                        ksefSessionWrite('ksef_failed_confirm', array(
                            'invoice_id' => $object->id,
                            'error' => $submit_result['error'] ?? 'Unknown error',
                            'error_code' => $submit_result['error_code'] ?? null,
                            'submission_id' => $submit_result['submission_id'] ?? null
                        ));
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_failure_dialog&token=' . newToken());
                        exit;

                    } else {
                        $error_msg = $langs->trans('InvoiceValidated') . ' - ' . $langs->trans('KSEF_SubmissionFailed');
                        if (!empty($submit_result['error'])) {
                            $error_msg .= ': ' . $submit_result['error'];
                        }
                        setEventMessages($error_msg, null, 'warnings');
                    }

                } catch (Exception $e) {
                    setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');

                    ksefSessionWrite('ksef_failed_confirm', array(
                        'invoice_id' => $object->id,
                        'error' => $e->getMessage(),
                        'error_code' => null,
                        'submission_id' => null,
                    ));
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_failure_dialog&token=' . newToken());
                    exit;
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
                    $is_online_ksef_number = (strpos($submission->ksef_number, 'OFFLINE') === false &&
                        strpos($submission->ksef_number, 'PENDING') === false &&
                        strpos($submission->ksef_number, 'ERROR') === false);

                    if ($is_online_ksef_number) {
                        $verifyUrl = ksefGetVerificationURL($submission->ksef_number, $submission->invoice_hash ?? null, $submission->environment);
                        print ' <a href="' . $verifyUrl . '" target="_blank" style="text-decoration: none;"><span class="badge badge-info">' . $submission->ksef_number . '</span></a>';
                    } else {
                        print ' <span class="badge badge-secondary" title="' . $langs->trans('KSEF_OfflineNumberNotYetSubmitted') . '">' . $submission->ksef_number . '</span>';
                    }
                }

                if (!empty($submission->date_submission)) {
                    print '<br><small style="color: #666;">' . $langs->trans("KSEF_SubmittedOn") . ': ' . dol_print_date($submission->date_submission, 'dayhour') . '</small>';
                }

                if ($submission->status == 'OFFLINE' && !empty($submission->offline_deadline)) {
                    print '<br><small style="color: #856404;"><i class="fa fa-clock-o"></i> ' . $langs->trans('KSEF_OfflineDeadline') . ': ' . ksefFormatDeadline($submission->offline_deadline) . '</small>';
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
