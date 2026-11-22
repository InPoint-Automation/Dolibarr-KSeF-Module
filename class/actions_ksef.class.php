<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
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

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');

class ActionsKSEF extends CommonHookActions
{
    private static $pdfHiddenKsefNumber = array();
    private static $pdfTitleSaved = array();
    private static $pdfTitleLangs = null;
    private static $depositLabelMap = array('InvoiceDeposit' => 'KSEF_CoreInvoiceDeposit', 'Deposit' => 'KSEF_CoreInvoiceDeposit',
        'Deposits' => 'KSEF_CoreInvoiceDeposits', 'PdfInvoiceDepositTitle' => 'KSEF_CoreInvoiceDeposit', 'DiscountFromDeposit' => 'KSEF_CoreDiscountFromDeposit');
    private static $pdfQrBand = array();
    private static $pageContexts = array('invoicecard', 'invoicelist', 'invoicenote', 'invoicesuppliercard', 'supplierpaymentcard',
        'completeTabsHead', 'pdfgeneration', 'afterPDFTotalTable', 'thirdpartycard', 'thirdpartycomm', 'sellsjournal', 'supplierinvoicelist',
        'thirdpartysupplier', 'purchasesjournal', 'invoice_supplierdao', 'recapcomptacard', 'externalbalance', 'customersupplierreportlist');

    public $db;
    public $error = '';
    // core < 23
    public $warnings = array();
    public $priority;
    private $sendNotices = array();

    /**
     * @brief Constructor
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->priority = 50;
        self::polishCoreStrings($db);
    }

    /**
     * @brief Polish core strings
     * @param DoliDB $db
     * @return void
     */
    private static function polishCoreStrings($db)
    {
        global $langs;
        static $done = false;
        if ($done || !is_object($langs) || !method_exists($langs, 'getDefaultLang') || $langs->getDefaultLang() !== 'pl_PL') {
            return;
        }
        $done = true;
        $langs->loadLangs(array('products', 'ksef@ksef'));
        // core pl_PL untranslated
        $english = array('SomeNotConvertedCreditNoteExists' => 'There is <b>%s</b> credit notes not yet converted for consumption', 'NotePrivate' => 'Note (internal)',
            'InvoiceDeposit' => 'Deposit invoice', 'Deposit' => 'Deposit invoice');
        foreach ($english as $key => $en) {
            $cur = (string) ($langs->tab_translate[$key] ?? '');
            if (($cur === '' || $cur === $en) && !empty($langs->tab_translate['KSEF_Core_' . $key])) {
                $langs->tab_translate[$key] = $langs->tab_translate['KSEF_Core_' . $key];
            }
        }
        $res = is_object($db) ? $db->query("SELECT code, label FROM " . MAIN_DB_PREFIX . "c_units WHERE active = 1") : false;
        while ($res && ($o = $db->fetch_object($res))) {
            $label = (string) $o->label;
            if ($label !== '' && empty($langs->tab_translate[$label]) && !empty($langs->tab_translate['unit' . $o->code])) {
                $langs->tab_translate[$label] = $langs->tab_translate['unit' . $o->code];
            }
        }
    }

    /**
     * @brief Add PDF button
     * @param $parameters
     * @param $object
     * @return int
     */
    public function formBuilddocOptions($parameters, &$object)
    {
        global $conf, $langs, $db, $user;

        if (!isModEnabled('ksef')) {
            return 0;
        }

        $contextArray = explode(':', $parameters['context']);
        if (!in_array('invoicecard', $contextArray)) {
            return 0;
        }

        $langs->load("ksef@ksef");
        $out = '';

        if (ksefIsCustomerExcluded($object->socid)) {
            $out .= '<tr class="oddeven"><td colspan="5" style="padding: 8px;"><span class="opacitymedium"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_CustomerExcludedInfo') . '</span></td></tr>';
            $this->resprints = $out;
            return 1;
        }

        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        if (class_exists('KsefNbpCurrencyRate')) {
            $this->displayNBPRateInfo($parameters, $object);
        }

        $state = ksefSubmissionState($db, $object->id);
        $submission = $state['has_submission'] ? $state['submission'] : new KsefSubmission($db);
        $has_submission = $state['has_submission'];
        $is_accepted = $state['is_accepted'];
        $is_offline = $state['is_offline'];
        $is_pending_submission = $state['is_pending'];
        $can_generate_pdf = $is_accepted || $is_offline;

        $is_preview = !$can_generate_pdf
            && in_array($object->statut, array(0, 1, 2))
            && !empty($object->lines)
            && !$is_pending_submission;

        $can_create = ksefUserCan($user, 'generate_pdf');
        if (!$can_create && ($can_generate_pdf || $is_preview) && ksefHideUnauthorized($user)) {
            // keep NBP block
            $this->resprints .= $out;
            return 0;
        }

        $out .= '<tr class="oddeven"><td colspan="5" style="padding: 4px 8px;">';
        $out .= '<div style="display: flex; align-items: center; gap: 8px;">';
        $out .= '<span style="font-size: 0.9em;">' . $langs->trans('KSEF_GenerateKSeFPDF') . '</span>';

        if (!$can_create && ($can_generate_pdf || $is_preview)) {
            $out .= '<input type="button" class="button buttongen reposition nomargintop nomarginbottom classfortooltip ' . ksefToneClass('action') . '" ';
            $out .= 'style="border-radius: 0; opacity: 0.5; cursor: not-allowed;" ';
            $out .= 'value="' . $langs->trans($can_generate_pdf ? 'Generate' : 'KSEF_PreviewKSeFPDF') . '" title="' . dol_escape_htmltag($langs->trans('NotEnoughPermissions')) . '" disabled />';
        } elseif ($can_generate_pdf) {
            $out .= '<input type="button" class="button buttongen reposition nomargintop nomarginbottom ' . ksefToneClass('action') . '" ';
            $out .= 'style="border-radius: 0;" ';
            $out .= 'value="' . $langs->trans('Generate') . '" ';
            $out .= 'onclick="window.location.href=\'' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_generate_pdf&token=' . newToken() . '\'" />';
        } elseif ($is_preview) {
            if ($has_submission && in_array($submission->status, array('SUBMITTED', 'TIMEOUT'), true)) {
                $previewTip = $langs->trans('KSEF_PreviewTooltipInFlight', $langs->transnoentitiesnoconv('KSEF_CheckStatus'));
            } elseif ($has_submission && in_array($submission->status, array('FAILED', 'REJECTED'), true)) {
                $previewTip = $langs->trans('KSEF_PreviewTooltipFailed');
            } else {
                $previewTip = $langs->trans('KSEF_PreviewTooltipNotSent');
            }
            $out .= '<input type="button" class="button buttongen reposition nomargintop nomarginbottom classfortooltip ksef-preview-pdf" ';
            $out .= 'value="' . $langs->trans('KSEF_PreviewKSeFPDF') . '" title="' . dol_escape_htmltag($previewTip) . '" ';
            $out .= 'onclick="window.location.href=\'' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_generate_pdf&preview=1&token=' . newToken() . '\'" />';
        } else {
            $tooltip = $has_submission ? $langs->trans('KSEF_PDFRequiresAccepted') : $langs->trans('KSEF_PDFRequiresSubmission');
            $out .= '<input type="button" class="button buttongen reposition nomargintop nomarginbottom classfortooltip ' . ksefToneClass('action') . '" ';
            $out .= 'style="border-radius: 0; opacity: 0.5; cursor: not-allowed;" ';
            $out .= 'value="' . $langs->trans('Generate') . '" title="' . dol_escape_htmltag($tooltip) . '" disabled />';
        }

        $out .= '</div>';
        $out .= '</td></tr>';

        $this->resprints .= $out;
        return 1;
    }

    /**
     * @brief Print korekta button
     * @param Facture $object
     * @return void
     */
    private function printCorrectionButton($object)
    {
        global $db, $langs, $user;
        if (in_array((int) $object->type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_DEPOSIT, Facture::TYPE_CREDIT_NOTE), true) && $user->hasRight('facture', 'creer')) {
            $sources = ksefCorrectionSources($db, $object->socid);
            $src = ($sources !== false && isset($sources[$object->id])) ? $sources[$object->id] : null;
            if (getDolGlobalString('INVOICE_DISABLE_REPLACEMENT') && ($sources === false || ($src && $src['eligible']))) {
                $settle = $object->type != Facture::TYPE_DEPOSIT && ksefSettlementNeedsCorrection($db, $object->id);
                print '<span class="butActionRefused classfortooltip ksef-correction-disabled ' . ksefToneClass('problem') . '" title="' . dol_escape_htmltag($langs->trans('KSEF_CorrectionDisabledByCore')) . '">'
                    . $langs->trans($settle ? 'KSEF_CreateSettlementCorrection' : 'KSEF_CreateCorrection') . '</span>';
            } elseif ($sources === false || ($src && $src['eligible'])) {
                $settle = $object->type != Facture::TYPE_DEPOSIT && ksefSettlementNeedsCorrection($db, $object->id);
                print '<a class="butAction classfortooltip' . ($settle ? ' ksef-settlement-correction' : '') . '" title="' . dol_escape_htmltag($langs->trans($settle ? 'KSEF_HintSettlementCorrection' : 'KSEF_HintReplacement')) . '" href="' . dol_escape_htmltag(ksefCorrectionCreateUrl($object)) . '">'
                    . $langs->trans($settle ? 'KSEF_CreateSettlementCorrection' : 'KSEF_CreateCorrection') . '</a>';
            } elseif ($src && $src['reason'] !== '') {
                print '<span class="butActionRefused classfortooltip ksef-correction-refused" title="' . dol_escape_htmltag($langs->trans($src['reason'], $src['child_ref'])) . '">'
                    . $langs->trans('KSEF_CreateCorrection') . '</span>';
                foreach (ksefCorrectionChildLinks($src, $user) as $lnk) {
                    print '<a class="' . ($lnk['delete'] ? 'butActionDelete' : 'butAction') . ' ksef-correction-child" href="' . dol_escape_htmltag(DOL_URL_ROOT . $lnk['href']) . '">' . dol_escape_htmltag($lnk['label']) . '</a>';
                }
            }
        }
    }

    /**
     * @brief Restate deposit credit
     * @param DoliDB $db
     * @param User $user
     * @param int $depositId
     * @param string $element
     * @return void
     */
    private function restateConverted($db, $user, $depositId, $element)
    {
        $db->begin();
        $rs = ksefDepositRestate($db, $user, $depositId, 0, false, 0, $element);
        if ($rs['result'] < 0) {
            $db->rollback();
            setEventMessages($rs['error'], null, 'errors');
            return;
        }
        $db->commit();
    }

    /**
     * @brief Shortfall confirm dialog
     * @param Facture|FactureFournisseur $object Draft korekta
     * @return string HTML or ''
     */
    private function shortfallConfirm($object)
    {
        global $db, $langs;
        $q = ksefShortfallQuestion($db, $object);
        if (!$q) {
            return '';
        }
        $langs->load('ksef@ksef');
        $m = function ($v) use ($langs, $q) {
            return price($v, 0, $langs, 1, -1, -1, $q['currency']);
        };
        $code = $q['short']['code'];
        $reason = $langs->trans('KSEF_ShortfallReason_' . ($code === 'badcustomer' ? 'bad' : $code));
        $text = $langs->trans('KSEF_ShortfallText', $q['ref'], $m($q['forgiven']) . ' (' . $reason . ')', $m($q['value']), $m($q['paid']));
        if (in_array($code, array('discount_vat', 'other', 'product_returned'), true)) {
            $text .= '<br><span class="opacitymedium">' . $langs->trans('KSEF_ShortfallHint', $m($q['forgiven'])) . '</span>';
        }
        $next = GETPOST('next', 'aZ09');
        require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
        $form = new Form($db);
        // text: question above
        return $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . ($next !== '' ? '&next=' . urlencode($next) : ''), $langs->trans('KSEF_ShortfallTitle', $q['ref']), '',
            'ksef_confirm_short', array('text' => $text, array('type' => 'radio', 'name' => 'ksef_short', 'label' => '', 'default' => $q['short']['ans'], 'values' => array(
                'keep' => $langs->trans('KSEF_ShortfallKeep', $m($q['keep']), $m($q['forgiven'])),
                'ask' => $langs->trans('KSEF_ShortfallAsk', $m($q['ask']), $m($q['forgiven'])),
            ))), 'yes', 1, 320, 640);
    }

    /**
     * @brief Stock shortage confirm
     * @param Facture $object Korekta
     * @return string HTML or ''
     */
    private function stockShortConfirm($object)
    {
        global $langs, $db;
        dol_include_once('/ksef/lib/ksef.lib.php');
        $w = GETPOSTINT('idwarehouse');
        $rows = ksefCorrectionStockPrecheck($db, $object, $w);
        if (!$rows) {
            return '';
        }
        $langs->load('ksef@ksef');
        $supplier = $object->element === 'invoice_supplier';
        $next = $supplier ? 'edit' : (GETPOST('action', 'aZ09') === 'confirm_delete' ? 'delete' : 'modif');
        require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
        $form = new Form($db);
        // trans() escapes
        $text = str_replace('__KSEF_STOCK_ROWS__', ksefCorrectionStockShortText($db, $langs, $rows, true),
            $langs->trans($supplier ? 'KSEF_CorrectionStockShortTextSupplier' : 'KSEF_CorrectionStockShortText', $object->ref, '__KSEF_STOCK_ROWS__'));
        return $form->formconfirm($_SERVER['PHP_SELF'] . '?' . ($supplier ? 'id' : 'facid') . '=' . (int) $object->id . '&idwarehouse=' . $w . '&ksef_next=' . $next,
            $langs->trans('KSEF_CorrectionStockShortTitle'), $text, 'ksef_confirm_stockshort', '', 'no', 1, 0, 640, 0,
            $langs->trans('KSEF_CorrectionStockShortProceed'), $langs->trans('KSEF_CorrectionStockShortCancel'));
    }

    /**
     * @brief Print notes, confirms
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    public function formConfirm($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $user;
        $this->resprints = '';
        if (function_exists('ksefDepositContextOnce')) {
            ksefDepositContextOnce($db, null, true);
        }
        $ret = $this->formConfirmMain($parameters, $object, $action, $hookmanager);
        if (!isModEnabled('ksef') || !is_object($object) || empty($object->id) || !in_array($object->element ?? '', array('facture', 'invoice_supplier'), true)
            || !array_intersect(array('invoicecard', 'invoicesuppliercard'), explode(':', $parameters['context'] ?? ''))) {
            return $ret;
        }
        dol_include_once('/ksef/lib/ksef_invoice_notes.lib.php');
        $notes = ksefInvoiceNotesOnCard($db, $object);
        if (!$notes) {
            return $ret;
        }
        $canFix = ksefNoteUserCanFix($user, $object->element);
        $html = ksefInvoiceNotesHtml($notes, $object, $canFix);
        if ($action === 'ksef_note_ask' && $canFix) {
            foreach ($notes as $n) {
                if ($n['key'] === GETPOST('note', 'alphanohtml') && !empty($n['fix'])) {
                    $html .= ksefInvoiceNoteConfirm($object, $n);
                }
            }
        }
        $this->resprints = $html . $this->resprints;
        return $ret;
    }

    /**
     * @brief Card confirm dialogs
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    private function formConfirmMain($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $conf, $db, $user;

        if ($action === 'ksef_pay_ask' && in_array('invoicesuppliercard', explode(':', $parameters['context'] ?? ''), true)
            && isModEnabled('ksef') && !empty($object->id)) {
            dol_include_once('/ksef/class/ksef_incoming.class.php');
            $incoming = new KsefIncoming($db);
            $incoming->fetchBySupplierInvoice($object->id);
            $pay = ksefSupplierPayState($user, $object, $incoming);
            if ($pay['show'] && $pay['refused'] === '') {
                $this->resprints = ksefSupplierPayFormConfirm($db, $object, $incoming, $pay);
            } else {
                $langs->load('ksef@ksef');
                setEventMessages($pay['refused'] !== '' ? $pay['refused'] : $langs->trans('KSEF_PayRefusedState'), null, 'errors');
            }
            return 0;
        }

        if ($action === 'confirm_converttoreduc' && in_array('invoicesuppliercard', explode(':', $parameters['context'] ?? ''), true) && isModEnabled('ksef')
            && !empty($object->id) && (int) $object->type === FactureFournisseur::TYPE_DEPOSIT && GETPOST('confirm', 'alpha') === 'yes'
            && ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'))) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            $this->restateConverted($db, $user, (int) $object->id, 'invoice_supplier');
            return 0;
        }

        if ($action === 'ksef_stockshort_ask' && array_intersect(array('invoicecard', 'invoicesuppliercard'), explode(':', $parameters['context'] ?? '')) && isModEnabled('ksef')
            && !empty($object->id) && in_array($object->element, array('facture', 'invoice_supplier'), true)) {
            $this->resprints = $this->stockShortConfirm($object);
            return 0;
        }

        if ($action === 'paid' && array_intersect(array('invoicecard', 'invoicesuppliercard'), explode(':', $parameters['context'] ?? '')) && isModEnabled('ksef')
            && !empty($object->id) && in_array($object->element, array('facture', 'invoice_supplier'), true)) {
            $form = $this->foreignOwedPartialConfirm($object);
            dol_include_once('/ksef/lib/ksef_writeoff.lib.php');
            $settle = ksefCloseDialogJs($db, $user, $object);
            if ($form !== '') {
                $this->resprints = $form . $settle;
                return 1;
            }
            $this->resprints = $settle;
            return 0;
        }

        if ($action === 'ksef_short_ask' && array_intersect(array('invoicecard', 'invoicesuppliercard'), explode(':', $parameters['context'] ?? '')) && isModEnabled('ksef')
            && !empty($object->id)) {
            $this->resprints = $this->shortfallConfirm($object);
            return 0;
        }

        if (!in_array('invoicecard', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        if (!isModEnabled('ksef')) {
            return 0;
        }

        // no core trigger
        if ($action === 'confirm_converttoreduc' && !empty($object->id) && (int) $object->type === Facture::TYPE_DEPOSIT && GETPOST('confirm', 'alpha') === 'yes'
            && $user->hasRight('facture', 'creer')) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            $this->restateConverted($db, $user, (int) $object->id, 'facture');
        }

        if (in_array($action, array('ksef_link_number', 'ksef_mark_not_sent', 'ksef_ask_create_offline', 'ksef_ask_resend'), true) && !empty($object->id)) {
            $langs->load("ksef@ksef");
            $form = new Form($db);
            $page = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
            if ($action === 'ksef_link_number') {
                $num = GETPOST('ksef_number', 'alphanohtml');
                $this->resprints = $form->formconfirm($page . '&ksef_number=' . urlencode($num), $langs->trans('KSEF_LinkKsefNumber'),
                    $langs->trans('KSEF_LinkKsefNumberConfirm', ksefEscapeText($num)), 'ksef_confirm_link_number', '', 'yes', 1);
            } elseif ($action === 'ksef_ask_create_offline') {
                $this->resprints = $form->formconfirm($page, $langs->trans('KSEF_CreateOfflineInvoice'),
                    $langs->trans('KSEF_CreateOfflineConfirm'), 'ksef_create_offline', '', 'no', 1);
            } elseif ($action === 'ksef_ask_resend') {
                $num = ksefSubmissionState($db, $object->id)['number_without_row'];
                $this->resprints = $form->formconfirm($page . '&next=' . urlencode(GETPOST('next', 'aZ09')), $langs->trans('KSEF_ResendNumberExistsTitle'),
                    $langs->trans('KSEF_ResendNumberExistsConfirm', ksefEscapeText($num)), 'ksef_confirm_resend', '', 'no', 1);
            } else {
                $this->resprints = $form->formconfirm($page, $langs->trans('KSEF_MarkNotSent'),
                    $langs->trans('KSEF_MarkNotSentConfirm'), 'ksef_confirm_mark_not_sent', '', 'no', 1);
            }
            return 0;
        }

        if ($action === 'valid' && !empty($object->id) && $object->element === 'facture' && (int) $object->type === Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source)) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            $this->resprints = $this->correctionValueInfo($object);
            return 0;
        }

        if (!in_array($action, array('modif', 'confirm_modif', 'delete'), true)) {
            return 0;
        }

        if (empty($object) || $object->element != 'facture' || empty($object->id)) {
            return 0;
        }

        $lock = $action == 'delete' ? ksefInvoiceEditLock($db, $object->id, 'delete') : ksefInvoiceModifyLock($db, $object->id);
        if ($lock['result'] == 'allowed') {
            return 0;
        }
        $langs->load("ksef@ksef");
        $text = $lock['key'] !== '' ? $langs->trans($lock['key'], ...$lock['args']) : '';

        if ($lock['result'] == 'info') {
            $this->resprints = '<div class="info ksef-note-info" style="margin-bottom: 15px; padding: 10px; border-radius: 4px;">'
                . '<i class="fa fa-info-circle ' . ksefToneTextClass('info') . '"></i> ' . $text . '</div>';
            return 0;
        }

        $title = $action == 'delete' && $lock['reason'] == 'issued' ? $langs->trans('KSEF_DeleteWarnTitle')
            : ($lock['result'] == 'blocked' ? $langs->trans($action == 'delete' ? 'KSEF_DeleteBlockedTitle' : 'KSEF_ModifyBlockedTitle') : $langs->trans('KSEF_ModifyWarningTitle'));
        $html = '<div class="warning ksef-note-check" style="margin-bottom: 15px; padding: 10px; border-radius: 4px;">';
        $html .= '<i class="fas fa-exclamation-triangle ' . ksefToneTextClass('check') . '"></i> ';
        $html .= '<strong>' . $title . '</strong><br>' . $text;
        if ($lock['reason'] == 'offline' && !empty($lock['state']['submission']->offline_deadline)) {
            $lockSub = $lock['state']['submission'];
            $html .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . ksefOfflineDeadlineText(ksefOfflineDeadlineForInvoice($db, $object->id, $lockSub->environment, $lockSub->offline_deadline, $object->date)) . '</small>';
        }
        if (!empty($lock['issued'])) {
            $html .= ksefIssuedDownloadLinks($object->id, $lock['issued']);
        }
        $html .= '</div>';
        if ($lock['result'] != 'blocked') {
            $html = $this->inCoreDialog('ksef-confirm-warning', $html);
        }
        $this->resprints = $html;
        return $lock['result'] == 'blocked' ? 1 : 0;
    }

    /**
     * @brief Into core dialog
     * @param string $id Wrapper id
     * @param string $html
     * @return string
     */
    private function inCoreDialog($id, $html)
    {
        return '<div id="' . $id . '">' . $html . '</div><script>
jQuery(function($) {
    var w = $("#' . $id . '"), tries = 0;
    (function move() {
        var d = $("#dialog-confirm");
        if (!d.length || !d.hasClass("ui-dialog-content")) {
            if (++tries < 40) setTimeout(move, 50);
            return;
        }
        d.prepend(w);
        d.dialog("option", {height: "auto", width: Math.max(560, d.dialog("option", "width") || 0)});
        d.dialog("option", "position", {my: "center", at: "center", of: window});
    })();
});
</script>';
    }

    /**
     * @brief Korekta value note
     * @param Facture $invoice Draft replacement
     * @return string HTML or ''
     */
    private function correctionValueInfo($invoice)
    {
        global $db, $langs, $conf;
        $invoice->fetch_optionals();
        $nl = empty(ksefCorrectionState($invoice)['absorb']) ? ksefCorrectionNotes($db, (int) $invoice->fk_facture_source) : false;
        $src = new Facture($db);
        if (!$nl || $src->fetch((int) $invoice->fk_facture_source) <= 0) {
            return '';
        }
        $mc = !empty($invoice->multicurrency_code) && $invoice->multicurrency_code != $conf->currency;
        $cur = $mc ? $invoice->multicurrency_code : $conf->currency;
        $notes = (float) price2num(array_sum(array_column($nl, $mc ? 'mttc' : 'ttc')), 'MT');
        $value = (float) ($mc ? $invoice->multicurrency_total_ttc : $invoice->total_ttc);
        $langs->load('ksef@ksef');
        return $this->inCoreDialog('ksef-kor-value', '<div class="info ksef-kor-value">' . $langs->trans('KSEF_KorValueAfter', $src->ref,
            price($value + $notes, 0, $langs, 1, -1, -1, $cur), price($value, 0, $langs, 1, -1, -1, $cur), price($notes, 0, $langs, 1, -1, -1, $cur)) . '</div>');
    }

    /**
     * @brief Grey locked buttons
     * @param array $parameters
     * @param Facture $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int 1 replaced, 0 core
     */
    public function dolGetButtonAction($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs, $user;

        if (!isModEnabled('ksef') || !$this->inContexts($parameters, array('invoicecard')) || !is_object($object) || $object->element !== 'facture'
            || empty($object->id) || empty($parameters['userRight']) || (int) $parameters['userRight'] < 0) {
            return 0;
        }
        $m = array();
        if (!preg_match('/[?&]action=(modif|delete|reopen)(&|$)/', (string) ($parameters['url'] ?? ''), $m)) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $langs->load('ksef@ksef');

        $why = null;
        if ($m[1] === 'delete') {
            // core 23+ greys
            if (($parameters['actionType'] ?? '') === 'delete' && !ksefDolibarrAtLeast('23.0')) {
                $lock = ksefInvoiceEditLock($db, $object->id, 'delete');
                if ($lock['result'] == 'blocked') {
                    $why = $lock['key'] !== '' ? $langs->transnoentitiesnoconv($lock['key'], ...$lock['args']) : '';
                }
            }
        } elseif ($m[1] === 'modif') {
            if (!empty($user->rights->facture->creer) && $object->statut > 0) {
                $lock = ksefInvoiceModifyLock($db, $object->id);
                if ($lock['result'] == 'blocked') {
                    $why = $lock['key'] !== '' ? $langs->transnoentitiesnoconv($lock['key'], ...$lock['args']) : '';
                }
            }
        } elseif (in_array((int) $object->statut, array(Facture::STATUS_CLOSED, Facture::STATUS_ABANDONED), true)) {
            $corrector = ksefGetCorrectingInvoice($db, $object->id);
            if ($corrector) {
                $why = $langs->transnoentitiesnoconv('KSEF_CorrectionReopenBlocked', $corrector->ref);
            }
        }
        if ($why === null) {
            return 0;
        }
        $text = (string) ($parameters['html'] ?? '');
        if ($text === '') {
            $text = (string) ($parameters['label'] ?? '');
        }
        $this->resprints = '<span class="butActionRefused classfortooltip ksef-' . $m[1] . '-refused" title="' . dol_escape_htmltag($why) . '">'
            . '<span class="textbutton">' . (dol_textishtml($text) ? $text : dol_escape_htmltag($text)) . '</span></span>';
        return 1;
    }

    /**
     * @brief Add KSeF buttons
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        global $langs, $conf, $user, $db, $form;

        if ($parameters['currentcontext'] == 'invoicesuppliercard' && !empty($object) && !empty($object->id)) {
            if (isModEnabled('ksef')) {
                $this->printClassifyPaidLock($object);
                print ksefSupplierDeductionScript($db, $object);
                dol_include_once('/ksef/class/ksef_incoming.class.php');
                $incoming = new KsefIncoming($db);
                if ($incoming->fetchBySupplierInvoice($object->id) > 0) {
                    $pay = ksefSupplierPayState($user, $object, $incoming);
                    if ($pay['show']) {
                        $label = $langs->trans($pay['validate'] ? 'KSEF_ValidateAndPay' : 'KSEF_PayFromKsef');
                        if ($pay['refused'] !== '') {
                            print '<span class="butActionRefused classfortooltip ksef-pay-refused" title="' . dol_escape_htmltag($pay['refused']) . '">' . $label . '</span>';
                            return 0;
                        }
                        $tip = price($pay['plan']['proposed'], 0, $langs, 0, -1, 2, $pay['plan']['currency']);
                        if (!empty($incoming->payment_date)) {
                            $tip .= ' - ' . dol_print_date($incoming->payment_date, 'day');
                        }
                        if (!empty($incoming->payment_method)) {
                            $tip .= ' - ' . ksefGetPaymentMethodLabel($incoming->payment_method);
                        }
                        print '<a class="butAction classfortooltip ksef-pay-from-ksef ' . ksefToneClass('ok') . '" style="font-weight: bold;" data-ksef-type="' . dol_escape_htmltag((string) $incoming->invoice_type) . '"'
                            . ' title="' . dol_escape_htmltag($tip) . '"'
                            . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_pay_ask&token=' . newToken() . '">'
                            . $label . '</a>';
                    }
                }
            }
            return 0;
        }

        if ($parameters['currentcontext'] != 'invoicecard' || empty($object) || empty($object->id) || $object->element != 'facture') {
            return 0;
        }

        if (!isModEnabled('ksef')) {
            return 0;
        }

        $langs->load("ksef@ksef");

        // before create-right check
        $this->printClassifyPaidLock($object);

        if (empty($user->rights->facture->creer)) {
            return 0;
        }

        if ($object->statut > 0) {
            $modLock = ksefInvoiceModifyLock($db, $object->id);
            if ($modLock['result'] == 'blocked' && $modLock['reason'] != 'corrected') {
                $pencils = array('editinvoicedate' => 'setinvoicedate', 'editref' => 'setref', 'editref_client' => 'setref_client', 'editconditions' => 'setconditions',
                    'editpaymentterm' => 'setpaymentterm', 'editmode' => 'setmode', 'editbankaccount' => 'setbankaccount', 'editmulticurrencycode' => 'setmulticurrencycode',
                    'editmulticurrencyrate' => 'setmulticurrencyrate', 'editrevenuestamp' => 'setrevenuestamp');
                print $this->xmlFieldPencilLock($object, $pencils, ksefInvoiceXmlExtrafields($object));
            }
            $discLock = $modLock['result'] == 'blocked' ? ksefDiscountChangeLock($db, $object->id) : array('result' => 'allowed');
            if ($discLock['result'] == 'blocked') {
                $ids = ksefDepositDiscountIds($db, $object->socid, $object->id);
                if ($ids) {
                    $why = $langs->transnoentitiesnoconv($discLock['key'], ...$discLock['args']);
                    print '<script>jQuery(function($) { var ids = ' . json_encode($ids) . ', why = ' . json_encode($why, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';'
                        . ' $("select[name=remise_id_for_payment] option").filter(function() { return ids.indexOf(parseInt(this.value, 10)) !== -1; }).remove();'
                        . ' $("a[href*=\'action=unlinkdiscount\']").each(function() { var m = /discountid=(\d+)/.exec($(this).attr("href"));'
                        . ' if (m && ids.indexOf(parseInt(m[1], 10)) !== -1) { $(this).replaceWith($("<span>").addClass("classfortooltip ksef-link-greyed ksef-unlink-refused").attr("title", why).html($(this).html())); } });'
                        . ' });</script>';
                }
            }
        }

        if (ksefIsCustomerExcluded($object->socid)) {
            return 0;
        }

        $button_style = 'style="font-weight: bold;"';
        $retry_style = 'style="font-weight: bold;"';

        $submission = new KsefSubmission($db);
        $has_submission = (
            $submission->fetchByInvoice($object->id, ksefGetEnvironment()) > 0
            && !empty($submission->status)
        );

        $is_accepted = $has_submission && $submission->status == 'ACCEPTED';
        $is_pending = $has_submission && $submission->status == 'PENDING';
        $is_failed = $has_submission && in_array($submission->status, array('FAILED', 'REJECTED', 'TIMEOUT'));
        $is_offline = $has_submission && !empty($submission->offline_mode);
        $has_ksef_number = $has_submission && ksefIsRealKsefNumber($submission->ksef_number);

        dol_include_once('/ksef/class/ksef_latarnia.class.php');
        $latarnia_cached = KsefLatarnia::getCachedStatus();
        if (in_array($latarnia_cached['status'], array('MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE'))) {
            $latarnia_msg = '';
            $lmsg = KsefLatarnia::currentMessage($latarnia_cached);
            if ($lmsg !== null) {
                $lmsg_start = !empty($lmsg['start']) ? dol_print_date(strtotime($lmsg['start']), 'dayhour') : '';
                if ($latarnia_cached['status'] === 'MAINTENANCE') {
                    $latarnia_msg = $langs->trans('KSEF_MaintenanceOngoing', $lmsg_start);
                } elseif ($latarnia_cached['status'] === 'TOTAL_FAILURE') {
                    $latarnia_msg = $langs->trans('KSEF_SystemDownTotalFailure', $lmsg_start);
                } else {
                    $latarnia_msg = $langs->trans('KSEF_SystemDownFailure', $lmsg_start);
                }
            }
            print '<div class="warning" style="margin-bottom: 10px; padding: 8px 12px; border-radius: 4px;">';
            print '<i class="fas fa-signal" style="margin-right: 5px;"></i> ';
            print '<strong>' . $langs->trans('KSEF_SystemStatus') . ':</strong> ';
            print ksefGetLatarniaStatusBadge($latarnia_cached['status']);
            if (!empty($latarnia_msg)) {
                print ' - ' . $latarnia_msg;
            }
            print '<br><small>' . $langs->trans('KSEF_SystemDownWarning') . '</small>';
            print '</div>';
        } elseif ($latarnia_cached['status'] === 'UNREACHABLE') {
            print '<div class="info ksef-note-info" style="margin-bottom: 10px; padding: 8px 12px; border-radius: 4px;">';
            print '<i class="fas fa-question-circle ' . ksefToneTextClass('info') . '" style="margin-right: 5px;"></i> ';
            print $langs->trans('KSEF_LatarniaUnreachableWarning');
            print '</div>';
        }
        print ksefLatarniaUpcomingBanner($latarnia_cached);

        $this->printSpinnerAssets();

        $depositRefusal = ksefDepositBlockMessage($db, $object, ksefDepositContextOnce($db, $object));
        if ($object->statut == 0 && !empty($object->lines) && !getDolGlobalString('KSEF_DISABLE_VALIDATE_AND_UPLOAD')) {
            $validate_action = getDolGlobalInt('KSEF_CONFIRM_BEFORE_UPLOAD', 1) ? 'ksef_validate_and_preview' : 'ksef_validate_and_submit';
            print ksefSendButton($langs->trans('KSEF_ValidateAndUpload'), $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=' . $validate_action . '&token=' . newToken(),
                $langs->transnoentitiesnoconv('KSEF_ValidatingAndSubmitting'), 'validate_and_send', $button_style, '', '', $depositRefusal, 'problem');
            return 0;
        }

        if ($object->statut == Facture::STATUS_ABANDONED) {
            $prodRow = ($is_accepted && $has_ksef_number) ? null : ksefProductionIssuedBlock($db, (int) $object->id);
            if (($is_accepted && $has_ksef_number) || $prodRow || ksefSourceAwaitingRow($db, (int) $object->id)) {
                $this->printCorrectionButton($object);
            }
            return 0;
        }

        if (in_array($object->statut, array(1, 2))) {
            if ($is_accepted && $has_ksef_number) {
                $this->printCorrectionButton($object);

                return 0;
            }

            $prodRow = ksefProductionIssuedBlock($db, (int) $object->id);
            $kstate = ksefSubmissionState($db, $object->id, ksefGetEnvironment());
            $cardUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
            if ($prodRow) {
                if ($kstate['outcome_unknown'] && !$is_offline) {
                    if ($kstate['can_check']) {
                        $checkRefusal = ksefStatusCheckRefusal($user, $submission);
                        print dolGetButtonAction(ksefTimeoutHint($submission) . ($checkRefusal !== '' ? '<br>' . dol_escape_htmltag($checkRefusal) : ''), '<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_CheckStatus'), 'default',
                            $cardUrl . '&action=ksef_check_status&token=' . newToken(), 'ksef-check-status', $checkRefusal === '');
                    }
                    if (empty($submission->ksef_reference) && !in_array(ksefHashStopState($submission), array('IN_SALES_UNLINKED', 'AMBIGUOUS'), true)) {
                        print dolGetButtonAction($langs->trans('KSEF_MarkNotSentConfirm'), $langs->trans('KSEF_MarkNotSent'), 'default',
                            $cardUrl . '&action=ksef_mark_not_sent&token=' . newToken(), 'ksef-mark-not-sent', ksefUserCan($user, 'mark_not_sent'));
                    }
                }
                print '<span class="butActionRefused classfortooltip ksef-production-issued" title="' . dol_escape_htmltag(ksefProductionIssuedRefusal($prodRow)) . '">'
                    . $langs->trans('KSEF_UploadToKSEF') . '</span>';
                $this->printCorrectionButton($object);
                return 0;
            }

            if (($is_pending || ($has_submission && $submission->status == 'SUBMITTED')) && !$kstate['is_stale']) {
                print '<span class="butActionRefused classfortooltip" title="' . $langs->trans('KSEF_SubmissionInProgress') . '">' . $langs->trans('KSEF_UploadToKSEF') . ' <i class="fa fa-spinner fa-spin"></i></span>';
                $this->printCorrectionButton($object);
                return 0;
            }

            if ($kstate['outcome_unknown'] && !$is_offline) {
                if ($kstate['can_check']) {
                    $checkRefusal = ksefStatusCheckRefusal($user, $submission);
                    print dolGetButtonAction(ksefTimeoutHint($submission) . ($checkRefusal !== '' ? '<br>' . dol_escape_htmltag($checkRefusal) : ''), '<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_CheckStatus'), 'default',
                        $cardUrl . '&action=ksef_check_status&token=' . newToken(), 'ksef-check-status', $checkRefusal === '');
                }
                if (empty($submission->ksef_reference)) {
                    print dolGetButtonAction($langs->trans('KSEF_StaleNoRefHint'), '<i class="fas fa-redo"></i> ' . $langs->trans('KSEF_RetrySubmission'), 'default',
                        $cardUrl . '&action=ksef_retry&token=' . newToken(), 'ksef-retry', ksefUserCan($user, 'send'));
                    if (!in_array(ksefHashStopState($submission), array('IN_SALES_UNLINKED', 'AMBIGUOUS'), true)) {
                        print dolGetButtonAction($langs->trans('KSEF_MarkNotSentConfirm'), $langs->trans('KSEF_MarkNotSent'), 'default',
                            $cardUrl . '&action=ksef_mark_not_sent&token=' . newToken(), 'ksef-mark-not-sent', ksefUserCan($user, 'mark_not_sent'));
                    }
                }
                return 0;
            }

            // 440: already sent
            if ($kstate['is_duplicate']) {
                print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('KSEF_RetryRefusedDuplicate')) . '"><i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_RetrySubmission') . '</span>';
                if ($kstate['duplicate_number'] !== '') {
                    print dolGetButtonAction($langs->trans('KSEF_LinkKsefNumberConfirm', $kstate['duplicate_number']), '<i class="fas fa-link"></i> ' . $langs->trans('KSEF_LinkKsefNumber'), 'default',
                        $cardUrl . '&action=ksef_link_number&ksef_number=' . urlencode($kstate['duplicate_number']) . '&token=' . newToken(), 'ksef-link-number', ksefUserCan($user, 'link_ksef_number'));
                }
                return 0;
            }

            $is_offline_status = $has_submission && ($submission->status == 'OFFLINE'
                || ($submission->status == 'PENDING' && !empty($submission->offline_mode) && $kstate['is_stale']));

            if ($is_offline_status) {
                print ksefSendButton($langs->trans('KSEF_SubmitOnline'), $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_retry_online&token=' . newToken(),
                    $langs->transnoentitiesnoconv('KSEF_SubmittingToKSEF'), 'send', $button_style, '', '', $depositRefusal, 'problem');
                // art. 106nda(12)
                $this->printCorrectionButton($object);
                return 0;
            }

            $offline_cert_check = ksefIsOfflineCertificateConfigured();

            if ($is_failed) {
                $error_display = ksefShortSubmissionError($submission->error_code, $submission->error_message);
                if ($error_display === '') {
                    $error_display = $langs->transnoentitiesnoconv('KSEF_NoErrorDetails');
                }
                $error_display = dol_escape_htmltag($error_display);

                if ($submission->status === 'REJECTED' && !empty($submission->offline_mode)) {
                    // never resend XML
                    print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('KSEF_RetryRefusedUseTechnicalCorrection')) . '"><i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_RetrySubmission') . '</span>';
                    print ksefSendButton('<i class="fas fa-tools"></i> ' . $langs->trans('KSEF_TechnicalCorrection'), $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_technical_correction&submission_id=' . (int) $submission->rowid . '&token=' . newToken(),
                        $langs->transnoentitiesnoconv('KSEF_SubmittingToKSEF'), 'send', $retry_style, $langs->transnoentitiesnoconv('KSEF_RetryHintTechnicalCorrection'), '', $depositRefusal, 'check');
                    return 0;
                }
                if ($kstate['number_without_row'] !== '') {
                    print dolGetButtonAction($langs->trans('KSEF_LinkKsefNumberConfirm', $kstate['number_without_row']), '<i class="fas fa-link"></i> ' . $langs->trans('KSEF_LinkKsefNumber'), 'default',
                        $cardUrl . '&action=ksef_link_number&ksef_number=' . urlencode($kstate['number_without_row']) . '&token=' . newToken(), 'ksef-link-number', ksefUserCan($user, 'link_ksef_number'));
                }
                if ($submission->canRetry()) {
                    print ksefSendButton('<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_RetrySubmission'), $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_retry&token=' . newToken(),
                        $langs->transnoentitiesnoconv('KSEF_RetryingSubmission'), 'send', $retry_style, ($error_display ?? ''), '', $depositRefusal, 'check');
                } else {
                    print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag((string) $submission->getNoRetryReason()) . '"><i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_RetrySubmission') . '</span>';
                }

                $offlineRefusal = ksefOfflineIssueRefusal($submission, $object, false);
                if ($offlineRefusal !== '') {
                    print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag(ksefOfflineRefusalText($offlineRefusal)) . '"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</span>';
                } elseif ($offline_cert_check['valid']) {
                    print dolGetButtonAction('', '<i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice'), 'default',
                        $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_ask_create_offline&token=' . newToken(), 'ksef-create-offline', ksefUserCan($user, 'send'));
                } else {
                    print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag(ksefOfflineCertRefusalText('KSEF_OfflineCertificateRequired', $offline_cert_check)) . '"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</span>';
                    if ($user->admin) {
                        print '<a class="butAction" href="' . dol_buildpath('/ksef/admin/setup_auth.php', 1) . '"><i class="fa fa-cog"></i> ' . $langs->trans('KSEF_SetUpOfflineCert') . '</a>';
                    }
                }
                return 0;
            }

            if (!$has_submission) {
                if ($object->type == Facture::TYPE_REPLACEMENT && in_array((int) $object->statut, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
                    print $this->ownModifyButton($object);
                }

                $upload_action = getDolGlobalInt('KSEF_CONFIRM_BEFORE_UPLOAD', 1) ? 'ksef_pre_submit' : 'ksef_submit';
                if ($kstate['number_without_row'] !== '') {
                    $linkUrl = $cardUrl . '&action=ksef_link_number&ksef_number=' . urlencode($kstate['number_without_row']) . '&token=' . newToken();
                    if (ksefUserCan($user, 'link_ksef_number')) {
                        print '<a class="butAction ksef-link-number-primary ' . ksefToneClass('action') . '" ' . $button_style . ' href="' . $linkUrl . '"><i class="fas fa-link"></i> ' . $langs->trans('KSEF_LinkKsefNumber') . '</a>';
                    } else {
                        print dolGetButtonAction('', '<i class="fas fa-link"></i> ' . $langs->trans('KSEF_LinkKsefNumber'), 'default', $linkUrl, 'ksef-link-number-primary', false);
                    }
                    print ksefSendButton($langs->trans('KSEF_UploadToKSEF'), $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=' . $upload_action . '&token=' . newToken(),
                        $langs->transnoentitiesnoconv('KSEF_SubmittingToKSEF'), 'send', '', dol_escape_htmltag($langs->trans('KSEF_ResendNumberExistsHint')), 'ksef-upload-secondary', $depositRefusal);
                    return 0;
                }
                print ksefSendButton($langs->trans('KSEF_UploadToKSEF'), $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=' . $upload_action . '&token=' . newToken(),
                    $langs->transnoentitiesnoconv('KSEF_SubmittingToKSEF'), 'send', $button_style, '', '', $depositRefusal, 'problem');
            }

            return 0;
        }

        return 0;
    }

    /**
     * @brief Lock XML pencils
     * @param Facture $object
     * @param array $pencils Edit => set action
     * @param array $extrafields Extrafield codes
     * @return string Script or ''
     */
    private function xmlFieldPencilLock($object, $pencils, $extrafields)
    {
        global $db;
        if (empty($object->array_options)) {
            $object->fetch_optionals();
        }
        $locked = array();
        foreach ($pencils as $edit => $set) {
            if (ksefInvoiceXmlField($db, $object, $set) !== '') {
                $locked[] = $edit;
            }
        }
        if (!$locked && !$extrafields) {
            return '';
        }
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        return '<script>jQuery(function($) { var edits = ' . json_encode($locked, $flags) . ', extras = ' . json_encode(array_values($extrafields), $flags) . ';'
            . ' $("a.editfielda, a.reposition").each(function() { var h = $(this).attr("href") || "", a = /[?&]action=(\w+)/.exec(h), x = /[?&]attribute=(\w+)/.exec(h);'
            . ' if (!a) { return; }'
            . ' if (edits.indexOf(a[1]) !== -1 || (a[1] === "edit_extras" && x && extras.indexOf(x[1]) !== -1)) { $(this).remove(); } });'
            . ' });</script>';
    }

    /**
     * @brief Block unsafe delete
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int
     */
    public function isErasable($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs;
        $this->results = array();
        if (!$this->inContexts($parameters) || !is_object($object) || $object->element !== 'facture' || empty($object->id)) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $lock = ksefInvoiceEditLock($db, $object->id, 'delete');
        if ($lock['result'] == 'blocked') {
            $langs->load("ksef@ksef");
            $this->results = array('result' => -10, 'error' => $langs->trans($lock['key']));
        }
        return 0;
    }

    /**
     * @brief Skip line fix
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int 1 skip
     */
    public function changeRoundingMode($parameters, &$object, &$action, $hookmanager)
    {
        if ($this->depositLineBefore23($parameters, $object)) {
            return 1;
        }
        if (!$this->inContexts($parameters, array_merge(self::$pageContexts, array('api', 'cli')))) {
            return 0;
        }
        return $this->ksefOwnsAmounts($object) ? 1 : 0;
    }

    /**
     * @brief Deposit line, <23
     * @param array $parameters
     * @param CommonObject $object
     * @return bool
     */
    private function depositLineBefore23($parameters, $object)
    {
        global $db;
        if (ksefDolibarrAtLeast('23.0') || empty($parameters['fk_element']) || !is_object($object)
            || !in_array($object->element ?? '', array('facture', 'invoice_supplier'), true) || empty($object->table_element_line)) {
            return false;
        }
        $res = $db->query("SELECT description FROM " . MAIN_DB_PREFIX . $db->sanitize($object->table_element_line) . " WHERE rowid = " . (int) $parameters['fk_element']);
        $o = $res ? $db->fetch_object($res) : null;
        return $o && (string) $o->description === '(DEPOSIT)';
    }

    /**
     * @brief Total from lines
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int 1 done, 0 core, -1 error
     */
    public function updateTotalPrice($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->inContexts($parameters, array_merge(self::$pageContexts, array('api', 'cli')))) {
            return 0;
        }
        $mode = isset($parameters['roundingadjust']) ? (string) $parameters['roundingadjust'] : 'auto';
        if ($mode === 'auto' && is_object($object)) {
            $mode = ($object->element ?? '') === 'invoice_supplier' ? getDolGlobalString('MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER', '0')
                : getDolGlobalString('MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND', '0');
        }
        if ($mode !== '1' || !$this->ksefOwnsAmounts($object)) {
            return 0;
        }
        $res = $object->update_price((int) ($parameters['exclspec'] ?? 0), '0', (int) ($parameters['nodatabaseupdate'] ?? 0), $parameters['seller'] ?? null);
        return $res < 0 ? -1 : 1;
    }

    /**
     * @brief Module owns amounts
     * @param CommonObject $object
     * @return bool
     */
    private function ksefOwnsAmounts($object)
    {
        global $conf;
        $element = is_object($object) ? ($object->element ?? '') : '';
        if (in_array($element, array('facture', 'invoice_supplier'), true) && !empty($object->multicurrency_code)
            && $object->multicurrency_code !== $conf->currency && (float) ($object->multicurrency_tx ?? 0) > 0 && abs((float) $object->multicurrency_tx - 1) > 1e-12
            && ($element === 'facture' || $this->supplierPlnDerived($object))) {
            return true;
        }
        return $this->importedFromKsef($object);
    }

    /**
     * @brief Supplier PLN derived
     * @param FactureFournisseur $object
     * @return bool
     */
    private function supplierPlnDerived($object)
    {
        $status = (int) ($object->status ?? ($object->statut ?? 0));
        $type = (int) ($object->type ?? 0);
        return $status !== 0 && $type !== 2 && !($type === 1 && !empty($object->fk_facture_source));
    }

    /**
     * @brief Imported from KSeF
     * @param CommonObject $object
     * @return bool
     */
    private function importedFromKsef($object)
    {
        global $db;
        static $imported = array();
        if (!is_object($object) || empty($object->id) || !in_array($object->element ?? '', array('facture', 'invoice_supplier'), true)) {
            return false;
        }
        // linked after totals
        $key = $object->element . ':' . (int) $object->id;
        if (empty($imported[$key])) {
            $sql = $object->element === 'facture'
                ? "SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE fk_facture = " . (int) $object->id
                : "SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE fk_facture_fourn = " . (int) $object->id;
            $res = $db->query($sql . " AND import_status = 'IMPORTED'");
            $imported[$key] = $res && $db->num_rows($res) > 0;
        }
        return $imported[$key];
    }

    /**
     * @brief Hook context match
     * @param array $parameters
     * @param array|null $contexts Null: $pageContexts
     * @return bool
     */
    private function inContexts($parameters, $contexts = null)
    {
        if (!is_array($parameters) || !isset($parameters['context'])) {
            return true;
        }
        return (bool) array_intersect($contexts === null ? self::$pageContexts : $contexts, explode(':', (string) $parameters['context']));
    }

    /**
     * @brief Journal write-offs
     * @param array $parameters
     * @param AccountingJournal $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int
     */
    public function getData($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $user;
        if (!isModEnabled('ksef') || !$this->inContexts($parameters, array('accountingjournaldao')) || !is_object($object) || (int) ($object->nature ?? 0) !== 1
            || !array_key_exists('data', (array) $parameters)) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef_writeoff.lib.php');
        $who = is_object($parameters['user'] ?? null) ? $parameters['user'] : $user;
        $type = (string) ($parameters['type'] ?? 'view');
        $rows = ksefWoJournalData($db, $who, $object, $type, $parameters['date_start'] ?? null, $parameters['date_end'] ?? null, (string) ($parameters['in_bookkeeping'] ?? 'notyet'));
        if (!$rows) {
            return 0;
        }
        // core<24 overwrites
        if (!method_exists($object, 'getDiscountCustomer')) {
            $asset = method_exists($object, 'getAssetData') ? $object->getAssetData($who, $type, $parameters['date_start'] ?? null, $parameters['date_end'] ?? null,
                (string) ($parameters['in_bookkeeping'] ?? 'notyet')) : array();
            foreach (array_merge(is_array($asset) ? $asset : array(), $rows) as $row) {
                $parameters['data'][] = $row;
            }
            return 1;
        }
        foreach ($rows as $row) {
            $parameters['data'][] = $row;
        }
        return 0;
    }

    /**
     * @brief Close paid FX
     * @param array $parameters
     * @param Paiement|PaiementFourn $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int 1 closed, 0 core, -1 error
     */
    public function createPayment($parameters, &$object, &$action, $hookmanager)
    {
        global $user;
        if (!isModEnabled('ksef') || !in_array($action, array('CLOSEPAIDINVOICE', 'CLOSEPAIDSUPPLIERINVOICE'), true)
            || !$this->inContexts($parameters, array('paymentdao', 'payment_supplierdao'))) {
            return 0;
        }
        $invoice = $parameters['invoice'] ?? null;
        if (!is_object($invoice) || ($invoice->element ?? '') !== ($action === 'CLOSEPAIDINVOICE' ? 'facture' : 'invoice_supplier')) {
            return 0;
        }
        $r = ksefCloseForeignPaid($this->db, $user, $invoice);
        if ($r < 0) {
            $this->error = (string) $invoice->error;
            $this->errors = array_merge(array($this->error), (array) $invoice->errors);
            return -1;
        }
        return $r > 0 ? 1 : 0;
    }

    /**
     * @brief Guard payment delete
     * @param array $parameters
     * @param CommonObject|null $object Unused
     * @param string $action Unused
     * @param HookManager $hookmanager
     * @return int
     */
    public function restrictedArea($parameters, &$object, &$action, $hookmanager)
    {
        global $db;
        $this->results = array();
        if (($parameters['currentcontext'] ?? '') === 'supplierpaymentcard' && GETPOST('action', 'aZ09') === 'confirm_delete' && GETPOST('confirm', 'alpha') === 'yes') {
            dol_include_once('/ksef/lib/ksef.lib.php');
            if (ksefNoLedgerBlocksDelete($db, 'payment_supplier', GETPOSTINT('id'))) {
                ksefNoLedgerAllowDelete();
            }
            ksefSupplierPaymentInvoices($db, GETPOSTINT('id'));
        }
        return 0;
    }

    /**
     * @brief Chart import actions
     * @param string $action
     * @return int
     */
    private function chartLoaderActions($action)
    {
        global $db, $user, $langs;
        if (!isModEnabled('ksef') || !in_array($action, array('ksef_chart_template', 'ksef_chart_import', 'ksef_chart_jpk'), true)) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef_chart.lib.php');
        $langs->load('ksef@ksef');
        if (!ksefChartCanLoad($user)) {
            accessforbidden();
        }
        if ($action === 'ksef_chart_template') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="plan_kont_szablon.csv"');
            print ksefChartImportCsv(ksefChartTemplateRows());
            exit;
        }
        if (!ksefChartCanImport($user)) {
            accessforbidden();
        }
        if ($action === 'ksef_chart_import') {
            ksefChartWizardReset();
            header('Location: ' . ksefChartWizardUrl());
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('token', 'alpha') !== currentToken()) {
            accessforbidden();
        }
        $r = ksefChartJpkImport($db, isset($_FILES['ksef_chart_file']) ? $_FILES['ksef_chart_file'] : null);
        if ($r['error'] !== '') {
            setEventMessages($r['error'], null, 'errors');
            header('Location: ' . ksefChartPageUrl() . '#ksef-chart-load');
            exit;
        }
        setEventMessages($r['msg'], null, 'mesgs');
        header('Location: ' . $r['url']);
        exit;
    }

    /**
     * @brief Handle card actions
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        dol_include_once('/ksef/class/ksef_service.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');

        global $conf, $user, $langs, $db;

        $currentcontext = $parameters['currentcontext'] ?? '';

        if ($currentcontext === 'accountancyadminaccount') {
            return $this->chartLoaderActions($action);
        }

        if (in_array($currentcontext, array('invoicecard', 'invoicelist', 'invoicenote', 'invoicesuppliercard', 'supplierinvoicelist'), true)) {
            self::translateDepositLabels($langs);
        }

        if ($currentcontext === 'invoicecard') {
            $langs->load("ksef@ksef");
            $langs->tab_translate['InvoiceReplacement'] = $langs->trans('KSEF_CorrectionInvoice');
            $langs->tab_translate['InvoiceReplacementShort'] = $langs->trans('KSEF_CorrectionInvoice');
            $langs->tab_translate['InvoiceReplacementAsk'] = $langs->trans('KSEF_CorrectionInvoiceAsk');
            $langs->tab_translate['InvoiceReplacementDesc'] = $langs->trans('KSEF_CorrectionInvoiceDesc');
            $langs->tab_translate['ReplaceInvoice'] = $langs->trans('KSEF_CorrectionInvoiceFor') . ' %s';
            $langs->tab_translate['ReplacedByInvoice'] = $langs->trans('KSEF_ReplacedByCorrectionInvoice') . ' %s';
            $langs->tab_translate['CreateCreditNote'] = $langs->trans('KSEF_CreateCreditNote');
            $langs->tab_translate['InvoiceAvoirAsk'] = $langs->trans('KSEF_CreditNoteAsk');
            if ($action === 'add') {
                ksefDepositFixedAmountSplit($db);
            }
            if ($action === 'create' && ksefOriginBilledByDepositsOnly($db)) {
                $langs->tab_translate['WarningBillExist'] = $langs->transnoentitiesnoconv('KSEF_WarningOrderHasDeposits');
            }
        }
        if ($currentcontext === 'paiementcard' && GETPOSTINT('ksef_back') > 0 && ksefUserCanReadInvoice($user, GETPOSTINT('ksef_back'))) {
            ksefDepositBackSet(GETPOSTINT('facid'), GETPOSTINT('ksef_back'));
        }
        if (in_array($currentcontext, array('invoicesuppliercard', 'supplierinvoicelist', 'thirdpartysupplier'), true)) {
            $langs->load("ksef@ksef");
            $langs->tab_translate['InvoiceReplacement'] = $langs->trans('KSEF_CorrectionInvoice');
            $langs->tab_translate['InvoiceReplacementShort'] = $langs->trans('KSEF_CorrectionInvoice');
            $langs->tab_translate['ReplaceInvoice'] = $langs->trans('KSEF_CorrectionInvoiceFor') . ' %s';
            $langs->tab_translate['ReplacedByInvoice'] = $langs->trans('KSEF_ReplacedByCorrectionInvoice') . ' %s';
        }
        $noLedger = array('paymentcard' => array('confirm_delete', 'payment', GETPOSTINT('id')), 'variouscard' => array('confirm_delete', 'payment_various', GETPOSTINT('id')),
            'invoicecard' => array('confirm_delete_paiement', 'payment', GETPOSTINT('paiement_id')),
            'invoicesuppliercard' => array('confirm_delete_paiement', 'payment_supplier', GETPOSTINT('paiement_id')));
        if (isset($noLedger[$currentcontext]) && $action === $noLedger[$currentcontext][0] && GETPOST('confirm', 'alpha') === 'yes'
            && ksefNoLedgerBlocksDelete($db, $noLedger[$currentcontext][1], $noLedger[$currentcontext][2])) {
            ksefNoLedgerAllowDelete();
        }

        if ($currentcontext === 'invoicesuppliercard' && $action === 'confirm_delete_paiement' && GETPOST('confirm', 'alpha') === 'yes') {
            ksefSupplierPaymentInvoices($db, GETPOSTINT('paiement_id'));
        }

        // core picks newest
        $massMail = GETPOST('massaction', 'alpha') === 'confirm_presend' && GETPOST('sendmail', 'alpha')
            && GETPOST('addmaindocfile', 'alpha') && getDolGlobalInt('MAIL_MASS_ACTION_SEARCH_MOST_RECENT_FILE_IF_NOT_FOUND');
        $massMerge = GETPOST('massaction', 'alpha') === 'builddoc' && GETPOST('confirmmassaction', 'alpha');
        if ($currentcontext === 'invoicelist' && ($massMail || $massMerge)) {
            $mailInvoice = new Facture($db);
            foreach ((array) GETPOST('toselect', 'array') as $mailId) {
                if ((int) $mailId > 0 && ksefUserCanReadInvoice($user, (int) $mailId) && $mailInvoice->fetch((int) $mailId) > 0) {
                    ksefMoveOldPreviewPdf($mailInvoice, $massMerge);
                }
            }
        }

        if (in_array($currentcontext, array('invoicecard', 'invoicesuppliercard'), true) && $action === 'ksef_note_fix' && is_object($object) && !empty($object->id)
            && in_array($object->element, array('facture', 'invoice_supplier'), true)) {
            dol_include_once('/ksef/lib/ksef_invoice_notes.lib.php');
            $langs->load('ksef@ksef');
            $this->requireToken($object);
            if (!ksefNoteUserCanFix($user, $object->element)) {
                accessforbidden();
            }
            if (GETPOST('confirm', 'alpha') === 'yes') {
                $r = ksefInvoiceNoteFix($db, $user, $object, GETPOST('note', 'alphanohtml'));
                if ($r['result'] === 'ok') {
                    setEventMessages($langs->trans('KSEF_NoteFixed'), null, 'mesgs');
                } else {
                    setEventMessages(dol_escape_htmltag($r['detail']), null, $r['result'] === 'failed' ? 'errors' : 'warnings');
                }
            }
            header('Location: ' . $_SERVER['PHP_SELF'] . '?facid=' . (int) $object->id);
            exit;
        }

        if ($currentcontext === 'thirdpartycard' && $action === 'ksef_gus_lookup') {
            $this->handleGusLookup();
        }

        // read by BILL_CREATE
        if ($currentcontext === 'invoicecard' && $action === 'add' && GETPOSTINT('type') === Facture::TYPE_REPLACEMENT && GETPOST('ksef_kor_base', 'aZ09') !== '') {
            $GLOBALS['ksef_kor_choice'] = array('base' => GETPOST('ksef_kor_base', 'aZ09') === 'a' ? 'a' : 'k', 'credit' => GETPOST('ksef_kor_credit', 'aZ09') === 'keep' ? 'keep' : 'use');
        }

        // BILL_VALIDATE checks sign
        if ($currentcontext === 'invoicecard' && is_object($object) && !empty($object->id) && $object->element === 'facture'
            && (int) $object->type === Facture::TYPE_REPLACEMENT && ksefNoteChainRoot($db, (int) $object->id) > 0) {
            $conf->global->FACTURE_ENABLE_NEGATIVE = 1;
            $conf->global->FACTURE_ENABLE_NEGATIVE_LINES = 1;
            $langs->load("ksef@ksef");
            $langs->tab_translate['ErrorInvoiceOfThisTypeMustBePositive'] = $langs->trans('KSEF_NoteKorektaNegativeInfo');
        }

        // BILL_CREATE relinks discounts
        if ($currentcontext === 'invoicecard' && $action === 'add' && GETPOSTINT('type') === Facture::TYPE_REPLACEMENT && GETPOSTINT('fac_replacement') > 0
            && $user->hasRight('facture', 'creer') && !ksefZeroDiscountLines($db, $user, GETPOSTINT('fac_replacement'), true)) {
            dol_syslog("ActionsKSEF: cannot unlink the 0.00 discount lines of invoice " . GETPOSTINT('fac_replacement') . ": " . $db->lasterror(), LOG_ERR);
        }

        if ($currentcontext === 'invoicecard' && $action === 'confirm_modif' && is_object($object) && !empty($object->id) && $object->element === 'facture') {
            $lock = ksefInvoiceModifyLock($db, $object->id);
            if ($lock['result'] == 'blocked' && in_array($lock['reason'], array('in_flight', 'check_first', 'db_error', 'issued', 'offline', 'corrected'), true)) {
                $langs->load("ksef@ksef");
                setEventMessages($langs->trans($lock['key'], ...$lock['args']), null, 'errors');
                $action = '';
                return 0;
            }
        }

        $stockCust = $currentcontext === 'invoicecard' && is_object($object) && !empty($object->id) && $object->element === 'facture'
            && ($action === 'confirm_modif' || ($action === 'confirm_delete' && GETPOST('confirm', 'alpha') === 'yes'));
        $stockSup = $currentcontext === 'invoicesuppliercard' && is_object($object) && !empty($object->id) && $object->element === 'invoice_supplier'
            && $action === 'confirm_edit' && GETPOST('confirm', 'alpha') === 'yes';
        $stockAns = in_array($currentcontext, array('invoicecard', 'invoicesuppliercard'), true) && is_object($object) && !empty($object->id)
            && in_array($object->element, array('facture', 'invoice_supplier'), true) && $action === 'ksef_confirm_stockshort';
        if ($stockCust || $stockSup || $stockAns) {
            if ($action === 'ksef_confirm_stockshort') {
                $this->requireToken($object);
                if (GETPOST('confirm', 'alpha') !== 'yes') {
                    $action = '';
                    return 0;
                }
                // core checks rights
                $GLOBALS['ksef_stock_short_ok'][(int) $object->id] = 1;
                $action = $object->element === 'invoice_supplier' ? 'confirm_edit' : (GETPOST('ksef_next', 'aZ09') === 'delete' ? 'confirm_delete' : 'confirm_modif');
            } else {
                $rows = ksefCorrectionStockPrecheck($db, $object, GETPOSTINT('idwarehouse'));
                if ($rows) {
                    $action = 'ksef_stockshort_ask';
                }
            }
        }

        if (in_array($currentcontext, array('invoicecard', 'invoicesuppliercard'), true) && in_array($action, array('valid', 'ksef_confirm_short'), true)
            && is_object($object) && !empty($object->id) && in_array($object->element, array('facture', 'invoice_supplier'), true)) {
            if ($action === 'ksef_confirm_short') {
                $this->requireToken($object);
                $ans = GETPOST('ksef_short', 'aZ09');
                $next = GETPOST('next', 'aZ09');
                $canCreate = $object->element === 'facture' ? $user->hasRight('facture', 'creer') : ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
                if (GETPOST('confirm', 'alpha') !== 'yes' || !$canCreate || !in_array($ans, array('keep', 'ask'), true)
                    || !ksefDepositStateSet($db, (int) $object->id, array('short_ans' => $ans), $object->element)) {
                    $action = '';
                    return 0;
                }
                $object->fetch_optionals();
                if ($next !== '' && $object->element === 'facture') {
                    $_SESSION['ksef_short_ack'][(int) $object->id] = 1;
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '&action=' . $this->cardContextAction($next) . '&token=' . newToken());
                    exit;
                }
                $action = 'valid';
            } elseif (ksefShortfallQuestion($db, $object)) {
                $action = 'ksef_short_ask';
            }
        }

        if (in_array($currentcontext, array('invoicecard', 'invoicesuppliercard'), true) && $action === 'ksef_setoff_set'
            && is_object($object) && !empty($object->id) && in_array($object->element, array('facture', 'invoice_supplier'), true)) {
            $this->requireToken($object);
            $langs->load('ksef@ksef');
            $discId = GETPOSTINT('discountid');
            $uses = (array) ksefExcessUseDates($db, (int) $object->id, $object->element);
            $y = GETPOSTINT('ksef_setoffyear');
            $m = GETPOSTINT('ksef_setoffmonth');
            $d = GETPOSTINT('ksef_setoffday');
            $date = checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : '';
            $floor = isset($uses[$discId]) ? dol_print_date($uses[$discId]['floor'], '%Y-%m-%d', 'tzserver') : '';
            if (!$this->canEditInvoice($object)) {
                $err = $langs->trans('NotEnoughPermissions');
            } elseif (empty($uses[$discId]['korekta']) || $date === '') {
                $err = $langs->trans('KSEF_SetoffDateInvalid');
            } elseif ($date < $floor) {
                $err = $langs->trans('KSEF_SetoffDateTooEarly', dol_print_date($uses[$discId]['floor'], 'day', 'tzserver'));
            } elseif ($date > ksefWarsawToday()) {
                $err = $langs->trans('KSEF_SetoffDateFuture');
            } elseif (!ksefSetoffDateSet($db, (int) $object->id, $discId, $date, $object->element)) {
                $err = $langs->trans('Error') . ' ' . $db->lasterror();
            } else {
                setEventMessages($langs->trans('KSEF_SetoffDateSaved', dol_print_date(dol_stringtotime($date, 0), 'day', 'tzserver')), null, 'mesgs');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id);
                exit;
            }
            setEventMessages($err, null, 'errors');
            $action = isset($uses[$discId]) ? 'ksef_setoff_edit' : '';
            return 0;
        }

        if ($currentcontext === 'invoicecard' && $action === 'ksef_apply_deposit' && is_object($object) && !empty($object->id) && $object->element === 'facture') {
            $this->requireToken($object);
            $langs->load('ksef@ksef');
            if (!$user->hasRight('facture', 'creer')) {
                accessforbidden();
            }
            $object->fetch((int) $object->id);
            $res = ksefDepositApplyReady($db, $object);
            if ($res['error'] !== '') {
                setEventMessages(dol_escape_htmltag($res['error']), null, 'errors');
            } else {
                setEventMessages($langs->trans('KSEF_DepositsApplied', dol_escape_htmltag(implode(', ', $res['applied']))), null, 'mesgs');
            }
            if ($res['waiting']) {
                setEventMessages($langs->trans('KSEF_DepositsStillWaiting', dol_escape_htmltag(implode(', ', $res['waiting']))), null, 'warnings');
            }
            header('Location: ' . $_SERVER['PHP_SELF'] . '?facid=' . (int) $object->id);
            exit;
        }

        if ($currentcontext === 'invoicecard' && in_array($action, array('setabsolutediscount', 'unlinkdiscount'), true) && is_object($object) && !empty($object->id) && $object->element === 'facture'
            && (int) $object->status > Facture::STATUS_DRAFT) {
            if ($action === 'setabsolutediscount') {
                $touches = GETPOSTINT('remise_id') > 0 || ksefIsDepositDiscount($db, GETPOSTINT('remise_id_for_payment'));
            } else {
                $touches = ksefIsDepositDiscount($db, GETPOSTINT('discountid'));
            }
            $lock = $touches ? ksefDiscountChangeLock($db, $object->id) : array('result' => 'allowed');
            if ($lock['result'] != 'allowed') {
                $langs->load("ksef@ksef");
                $msg = $langs->trans($lock['key'], ...$lock['args']);
                if ($lock['result'] == 'blocked') {
                    setEventMessages($msg, null, 'errors');
                    $action = '';
                    return 0;
                }
                setEventMessages($msg, null, 'warnings');
            }
        }

        if (in_array($currentcontext, array('invoicecard', 'invoicesuppliercard'), true) && in_array($action, array('paid', 'confirm_paid'), true)
            && is_object($object) && !empty($object->id) && in_array($object->element, array('facture', 'invoice_supplier'), true)
            && $object->fetch((int) $object->id) > 0) {
            $why = ksefClassifyPaidRefusal($object, $action);
            if ($why !== '') {
                setEventMessages(dol_escape_htmltag($why), null, 'errors');
                $action = '';
                return 0;
            }
        }
        if ($currentcontext === 'invoicecard' && in_array($action, array('converttoreduc', 'confirm_converttoreduc'), true)
            && is_object($object) && !empty($object->id) && ($object->element ?? '') === 'facture' && (int) $object->type === Facture::TYPE_DEPOSIT
            && $object->fetch((int) $object->id) > 0) {
            $why = $this->depositConvertRefusal($object);
            if ($why !== '') {
                setEventMessages(dol_escape_htmltag($why), null, 'errors');
                $action = '';
                return 0;
            }
        }

        // core compares PLN
        if (in_array($currentcontext, array('invoicecard', 'invoicesuppliercard'), true) && $action === 'setabsolutediscount' && GETPOSTINT('remise_id_for_payment') > 0
            && is_object($object) && !empty($object->id) && in_array($object->element, array('facture', 'invoice_supplier'), true)) {
            $supplierCard = $object->element === 'invoice_supplier';
            $canCreate = $supplierCard ? ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer')) : $user->hasRight('facture', 'creer');
            if ($canCreate && $object->fetch((int) $object->id) > 0) {
                $use = ksefUseForeignDepositCredit($db, $user, $object, GETPOSTINT('remise_id_for_payment'));
                if ((int) $use['result'] !== 0) {
                    $langs->load('ksef@ksef');
                    $object->fetch((int) $object->id);
                    if ((int) $use['result'] > 0) {
                        $pln = ksefDepositCreditPlnState($db, $object);
                        setEventMessages($pln['applies'] ? dol_escape_htmltag($pln['text']) : $langs->trans('RecordModifiedSuccessfully'), null, 'mesgs');
                    } else {
                        setEventMessages($use['error'], null, 'errors');
                    }
                    $action = '';
                    return 0;
                }
            }
        }

        if (in_array($currentcontext, array('invoicecard', 'invoicesuppliercard'), true) && in_array($action, array('converttoreduc', 'confirm_converttoreduc'), true)
            && is_object($object) && !empty($object->id) && in_array($object->element, array('facture', 'invoice_supplier'), true)
            && (int) $object->type === Facture::TYPE_STANDARD) {
            $pln = ksefDepositCreditPlnState($db, $object, array('open' => true));
            if ($pln['applies']) {
                $langs->load('ksef@ksef');
                setEventMessages($langs->trans('KSEF_DepositCreditPlnNoExcess', $pln['currency']) . ' ' . dol_escape_htmltag($pln['text']), null, 'errors');
                $action = '';
                return 0;
            }
        }

        if (in_array($currentcontext, array('invoicecard', 'invoicesuppliercard'), true) && $action === 'confirm_paid_partially' && GETPOST('confirm', 'alpha') === 'yes'
            && is_object($object) && !empty($object->id) && in_array($object->element, array('facture', 'invoice_supplier'), true)) {
            dol_include_once('/ksef/lib/ksef_writeoff.lib.php');
            if ($object->fetch((int) $object->id) > 0) {
                $woErr = ksefWoFromPost($db, $object);
                if ($woErr !== '') {
                    setEventMessages(dol_escape_htmltag($woErr), null, 'errors');
                    $action = 'paid';
                    return 1;
                }
            }
        }

        $xmlActions = array('setinvoicedate', 'setref', 'setref_client', 'setconditions', 'setpaymentterm', 'setmode', 'setbankaccount', 'setmulticurrencycode',
            'setmulticurrencyrate', 'setrevenuestamp', 'setremisepercent', 'setnote_public', 'update_extras');
        if (in_array($currentcontext, array('invoicecard', 'invoicenote'), true) && in_array($action, $xmlActions, true) && !GETPOST('cancel', 'alpha')
            && is_object($object) && !empty($object->id) && $object->element === 'facture' && (int) $object->status > Facture::STATUS_DRAFT) {
            if (empty($object->array_options)) {
                $object->fetch_optionals();
            }
            $field = ksefInvoiceXmlField($db, $object, $action);
            $lock = $field !== '' ? ksefFieldChangeLock($db, $object->id, $field) : array('result' => 'allowed');
            if ($lock['result'] != 'allowed') {
                $langs->load("ksef@ksef");
                $msg = $langs->trans($lock['key'], ...$lock['args']);
                if ($lock['result'] == 'blocked') {
                    setEventMessages($msg, null, 'errors');
                    $action = '';
                    return 0;
                }
                setEventMessages($msg, null, 'warnings');
            }
        }

        if ($currentcontext === 'invoicenote' && $action === 'ksef_set_note_override') {
            if (is_object($object) && !empty($object->id) && $object->element === 'facture') {
                if (!ksefUserCan($user, 'edit_ksef_data')) {
                    setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
                    $action = '';
                    return 0;
                }
                $langs->load("ksef@ksef");
                if (ksefSubmissionState($db, $object->id)['locked']) {
                    setEventMessages($langs->trans('KSEF_NotesOverrideLocked'), null, 'warnings');
                    $action = '';
                    return 0;
                }
                $val = GETPOST('ksef_override_value', 'alpha');
                if (!array_key_exists($val, ksefDodatkowyOpisModeOptions())) {
                    setEventMessages(ksefTransHtml('ErrorBadValueForParameter', array(ksefEscapeText($val), 'ksef_override_value')), null, 'errors');
                    $action = '';
                    return 0;
                }
                if (!isset($object->array_options) || empty($object->array_options)) {
                    $object->fetch_optionals();
                }
                $object->array_options['options_ksef_dodatkowy_opis_mode'] = $val;
                $res = $object->insertExtraFields();
                if ($res >= 0) {
                    setEventMessages($langs->trans('KSEF_DODATKOWY_OPIS_OVERRIDE_SAVED'), null, 'mesgs');
                } else {
                    setEventMessages($object->error ?: $langs->trans('KSEF_DODATKOWY_OPIS_OVERRIDE_ERROR'), $object->errors, 'errors');
                }
                $action = '';
            }
        }

        if ($currentcontext === 'invoicecard' && $action === 'ksef_save_correction_details') {
            if (is_object($object) && !empty($object->id) && $object->element === 'facture'
                && ($object->type == Facture::TYPE_CREDIT_NOTE || $object->type == Facture::TYPE_REPLACEMENT)) {
                $langs->load("ksef@ksef");
                if (!ksefUserCan($user, 'edit_ksef_data')) {
                    setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
                    $action = '';
                    return 0;
                }

                if (ksefSubmissionState($db, $object->id)['locked']) {
                    setEventMessages($langs->trans('KSEF_CorrectionDetailsLocked'), null, 'warnings');
                    $action = '';
                    return 0;
                }

                if (!isset($object->array_options) || empty($object->array_options)) {
                    $object->fetch_optionals();
                }

                $preset = GETPOST('ksef_correction_reason_preset', 'alphanohtml');
                if ($preset === 'custom') {
                    $reason = trim(GETPOST('ksef_correction_reason_custom', 'alphanohtml'));
                } else {
                    $reason = trim($preset);
                }
                $corrType = GETPOST('ksef_correction_type', 'int');
                if (!in_array($corrType, array(1, 2, 3), false)) {
                    $corrType = '';
                }

                $object->array_options['options_ksef_correction_reason'] = $reason;
                $object->array_options['options_ksef_correction_type'] = $corrType;
                $res = $object->insertExtraFields();
                if ($res >= 0) {
                    setEventMessages($langs->trans('KSEF_CorrectionDetailsSaved'), null, 'mesgs');
                } else {
                    setEventMessages($object->error ?: 'Error saving correction details', $object->errors, 'errors');
                }
                $action = '';
            }
        }

        if ($currentcontext === 'invoicecard' && $action === 'ksef_save_podmiot3') {
            if (is_object($object) && !empty($object->id) && $object->element === 'facture') {
                $langs->load("ksef@ksef");
                if (!ksefUserCan($user, 'edit_ksef_data')) {
                    setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
                    $action = '';
                    return 0;
                }

                if (ksefSubmissionState($db, $object->id)['locked']) {
                    setEventMessages($langs->trans('KSEF_Podmiot3_Locked'), null, 'warnings');
                    $action = '';
                    return 0;
                }

                if (!isset($object->array_options) || empty($object->array_options)) {
                    $object->fetch_optionals();
                }

                require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

                $allowedRoles = array('6', '2', '3', '4', '5', '7', '8', '9', '10', '11', '1');
                $ids = GETPOST('ksef_podmiot3_party', 'array');
                $roles = GETPOST('ksef_podmiot3_role', 'array');
                $udzials = GETPOST('ksef_podmiot3_udzial', 'array');
                $list = array();
                $factorNoBank = array();
                foreach ($ids as $i => $rid) {
                    $rid = (int) $rid;
                    $soc = ksefVisibleSociete($db, $rid);
                    if (!$soc) continue;
                    $role = isset($roles[$i]) ? (string) $roles[$i] : '';
                    if (!in_array($role, $allowedRoles, true)) {
                        $role = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
                    }
                    $entry = array('id' => $rid, 'role' => $role);
                    if ($role === '4') {
                        $u = isset($udzials[$i]) ? (float) str_replace(',', '.', (string) $udzials[$i]) : 0;
                        if ($u > 0 && $u <= 100) {
                            $entry['udzial'] = rtrim(rtrim(number_format($u, 2, '.', ''), '0'), '.');
                        }
                    }
                    if ($role === '1') {
                        $sqlRib = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "societe_rib"
                            . " WHERE fk_soc = " . ((int) $rid) . " AND type = 'ban'";
                        $resRib = $db->query($sqlRib);
                        $nbRib = ($resRib && ($o = $db->fetch_object($resRib))) ? (int) $o->nb : 0;
                        if ($nbRib === 0) {
                            $factorNoBank[] = !empty($soc->name) ? $soc->name : ('#' . $rid);
                        }
                    }
                    $list[] = $entry;
                    if (count($list) >= 20) break;
                }
                $object->array_options['options_ksef_podmiot3'] = $list ? json_encode($list) : '';
                $res = $object->insertExtraFields();
                if ($res >= 0) {
                    setEventMessages($langs->trans('KSEF_Podmiot3_Saved'), null, 'mesgs');
                    if (!empty($factorNoBank)) {
                        setEventMessages(ksefTransHtml('KSEF_Podmiot3_FactorNoBank', array(ksefEscapeText(implode(', ', $factorNoBank)))), null, 'warnings');
                    }
                } else {
                    setEventMessages($object->error ?: $langs->trans('Error'), $object->errors, 'errors');
                }
                $action = '';
            }
        }

        if ($currentcontext === 'invoicecard' && $action === 'reopen' && !empty($object->id)) {
            $corrector = ksefGetCorrectingInvoice($db, $object->id);
            if ($corrector) {
                $langs->load("ksef@ksef");
                setEventMessages($langs->trans('KSEF_CorrectionReopenBlocked', $corrector->ref), null, 'errors');
                $action = '';
                return 1;
            }
        }

        if ($currentcontext === 'invoicecard' && $action === 'update_extras' && is_object($object) && !empty($object->id) && $object->element === 'facture') {
            $attribute = GETPOST('attribute', 'aZ09');
            $posted = function ($field) use ($attribute) {
                return $attribute === $field || ($attribute === '' && (GETPOSTISSET('options_' . $field) || GETPOSTISSET('options_' . $field . 'day')));
            };
            $refused = '';
            foreach (array('ksef_number', 'ksef_status', 'ksef_submission_date') as $field) {
                if ($posted($field)) {
                    $refused = 'KSEF_FieldManagedByModule';
                }
            }
            if ($refused === '' && ($posted('ksef_sale_date') || $posted('ksef_kurs_data'))) {
                $kstate = ksefSubmissionState($db, $object->id);
                if (!empty($kstate['locked']) || !empty($kstate['db_error'])) {
                    $refused = 'KSEF_FieldLockedAfterSubmit';
                } elseif ((int) $object->status != Facture::STATUS_DRAFT) {
                    $refused = 'KSEF_FieldLockedAfterValidate';
                }
            }
            if ($refused !== '') {
                $langs->load("ksef@ksef");
                setEventMessages($langs->trans($refused), null, 'errors');
                $action = '';
                return 1;
            }
        }

        if ($currentcontext === 'invoicecard' && $action === 'create') {
            $own = array('cond_reglement_id' => 0, 'mode_reglement_id' => 0, 'fk_account' => 0);
            $createSocid = GETPOSTINT('socid');
            if ($createSocid > 0) {
                require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
                $createSoc = new Societe($db);
                if ($createSoc->fetch($createSocid) > 0) {
                    $own = array('cond_reglement_id' => (int) $createSoc->cond_reglement_id, 'mode_reglement_id' => (int) $createSoc->mode_reglement_id, 'fk_account' => (int) $createSoc->fk_account);
                }
            }
            $defaults = array('cond_reglement_id' => 'KSEF_DEFAULT_PAYMENT_TERM_ID', 'mode_reglement_id' => 'KSEF_DEFAULT_PAYMENT_METHOD_ID');
            if (isModEnabled('bank')) {
                $defaults['fk_account'] = 'KSEF_DEFAULT_BANK_ACCOUNT_ID';
            }
            $preset = array();
            foreach ($defaults as $field => $constName) {
                if (getDolGlobalInt($constName) > 0 && $own[$field] <= 0 && !GETPOSTISSET($field)) {
                    $_POST[$field] = (string) getDolGlobalInt($constName);
                    $preset[$field] = 1;
                }
            }
            // account in invoice currency
            if (!empty($preset['fk_account'])) {
                $createCur = GETPOST('multicurrency_code', 'alpha');
                if ($createCur === '') {
                    $createCur = (isModEnabled('multicurrency') && isset($createSoc) && !empty($createSoc->multicurrency_code)) ? (string) $createSoc->multicurrency_code : (string) $conf->currency;
                }
                if ($createCur !== (string) $conf->currency) {
                    $_POST['fk_account'] = (string) ksefBankAccountFor($db, (int) $_POST['fk_account'], $createCur);
                }
            }
        }

        if ($currentcontext == 'thirdpartycard') {
            if (!isModEnabled('ksef')) {
                return 0;
            }

            if (($action == 'update' || $action == 'add') && is_object($object) && !empty($object->id)) {
                require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

                $ksef_exclude = GETPOST('ksef_exclude', 'int');
                $current_excluded = $conf->global->KSEF_EXCLUDED_CUSTOMERS ?? '';
                $excluded_array = array_filter(array_map('trim', explode(',', $current_excluded)));

                if ($ksef_exclude) {
                    if (!in_array($object->id, $excluded_array)) {
                        $excluded_array[] = $object->id;
                        dolibarr_set_const($db, 'KSEF_EXCLUDED_CUSTOMERS', implode(',', $excluded_array), 'chaine', 0, '', $conf->entity);
                    }
                } else {
                    $excluded_array = array_diff($excluded_array, array($object->id));
                    dolibarr_set_const($db, 'KSEF_EXCLUDED_CUSTOMERS', implode(',', $excluded_array), 'chaine', 0, '', $conf->entity);
                }
            }

            return 0;
        }

        // core skips token
        if ($currentcontext == 'invoicesuppliercard' && $action == 'ksef_pay_confirm') {
            $this->requireToken($object);
            $back = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
            if (GETPOST('confirm', 'alpha') !== 'yes') {
                header('Location: ' . $back);
                exit;
            }
            $langs->load("ksef@ksef");
            dol_include_once('/ksef/class/ksef_incoming.class.php');
            $incoming = new KsefIncoming($db);
            $incoming->fetchBySupplierInvoice($object->id);
            $res = ksefSupplierPayFromKsef($db, $user, $object, $incoming, array(
                'amount' => GETPOST('ksef_pay_amount', 'alphanohtml'),
                'paiementid' => GETPOSTINT('paiementid'),
                'accountid' => GETPOSTINT('accountid'),
                'datepaye' => dol_mktime(12, 0, 0, GETPOSTINT('ksef_pay_datemonth'), GETPOSTINT('ksef_pay_dateday'), GETPOSTINT('ksef_pay_dateyear')),
                'idwarehouse' => GETPOSTINT('idwarehouse'),
            ));
            if (!$res['ok']) {
                setEventMessages($langs->trans('KSEF_PaymentConfirmError'), array_values(array_filter($res['errors'])), 'errors');
            } else {
                $msg = $res['closed'] === 'deductions' ? 'KSEF_PayClosedWithDeductions' : ($res['validated'] ? 'KSEF_PaymentConfirmed' : 'KSEF_PayRecorded');
                setEventMessages($langs->trans($msg), null, 'mesgs');
                if ($res['warnings']) {
                    setEventMessages(null, $res['warnings'], 'warnings');
                }
            }
            header('Location: ' . $back);
            exit;
        }

        if (!in_array('invoicecard', array($parameters['currentcontext']))) {
            return 0;
        }

        if (in_array($action, array('ksef_download_xml', 'ksef_download_upo'), true) && !empty($object->id)) {
            $langs->load("ksef@ksef");
            $back = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
            $sub = ksefFetchSubmissionForUser($db, $user, GETPOSTINT('sub_id'), (int) $object->id);
            if ($sub === null) {
                dol_syslog("KSEF " . $action . " invoice " . (int) $object->id . ": submission " . GETPOSTINT('sub_id') . " not readable by " . $user->login, LOG_WARNING);
                setEventMessages($langs->trans($action === 'ksef_download_xml' ? 'KSEF_XMLNotAvailable' : 'KSEF_UPONotAvailable'), null, 'warnings');
                header('Location: ' . $back);
                exit;
            }
            $number = ksefIsKsefNumber((string) $sub->ksef_number) ? (string) $sub->ksef_number : dol_sanitizeFileName($object->ref);
            if ($action === 'ksef_download_xml') {
                if (!empty($sub->fa3_xml)) {
                    header('Content-Type: application/xml; charset=utf-8');
                    header('Content-Disposition: attachment; filename="FA3_' . $number . '.xml"');
                    echo $sub->fa3_xml;
                    exit;
                }
                setEventMessages($langs->trans('KSEF_XMLNotAvailable'), null, 'warnings');
                header('Location: ' . $back);
                exit;
            }
            dol_include_once('/ksef/class/ksef_service.class.php');
            $ksef = new KsefService($db);
            $upo = ksefIsKsefNumber((string) $sub->ksef_number) ? $ksef->upoForDownload($sub, $user) : (string) $sub->upo_xml;
            $upoError = (string) $ksef->error;
            if ($upo !== '') {
                header('Content-Type: application/xml; charset=utf-8');
                header('Content-Disposition: attachment; filename="UPO_' . $number . '.xml"');
                echo $upo;
                exit;
            }
            setEventMessages($langs->trans('KSEF_UPONotAvailable') . ($upoError !== '' ? ': ' . ksefEscapeText($upoError) : ''), null, 'warnings');
            header('Location: ' . $back);
            exit;
        }

        if ($action == 'ksef_generate_pdf' && !empty($object->id)) {
            $langs->load("ksef@ksef");
            if (!ksefUserCan($user, 'generate_pdf')) {
                setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            if (GETPOST('preview', 'int') && in_array($object->statut, array(0, 1, 2))) {
                if (!empty(ksefSubmissionState($db, $object->id)['locked'])) {
                    setEventMessages($langs->trans('KSEF_AlreadySubmitted'), null, 'warnings');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
                $xml_warnings = array();
                $content = $this->buildPreviewPdf($object, $xml_warnings);
                $filename = $content !== false ? $this->writePreviewPdf($object, $content) : false;
                if ($filename !== false) {
                    setEventMessages($langs->trans('KSEF_PreviewPDFGenerated', $filename), null, 'mesgs');
                }
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $state = ksefSubmissionState($db, $object->id);
            if (!$state['has_submission']) {
                setEventMessages($langs->trans('KSEF_PDFRequiresSubmission'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }
            $submission = $state['submission'];

            if (!$state['is_accepted'] && !$state['is_offline']) {
                setEventMessages($langs->trans('KSEF_PDFRequiresAccepted'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $errorMsg = '';
            $result = ksefAutoGeneratePdf($db, $submission, $object->id, $errorMsg);
            if ($result) {
                $filename = dol_sanitizeFileName($object->ref) . '_ksef.pdf';
                setEventMessages($langs->trans('KSEF_PDFGeneratedSuccess', $filename), null, 'mesgs');
            } else {
                $userMsg = $langs->trans('KSEF_PDFGenerationError');
                if (!empty($errorMsg)) {
                    $userMsg .= ': ' . dol_escape_htmltag($errorMsg);
                }
                setEventMessages($userMsg, null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_pre_submit' && !empty($object->id)) {
            $this->requireSendAccess($object, 'send');
            $langs->load("ksef@ksef");

            if (!in_array($object->statut, array(1, 2))) {
                dol_syslog("KSEF ksef_pre_submit invoice " . (int) $object->id . " refused: status " . (int) $object->statut, LOG_WARNING);
                setEventMessages($langs->trans('KSEF_InvoiceMustBeValidated'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $go = $this->cardPreflightGate($object, 'pre_submit');
            if ($go !== null) {
                header('Location: ' . $go);
                exit;
            }

            $confirm_template = getDolGlobalString('KSEF_CONFIRM_PDF_TEMPLATE', 'ksef');

            if ($confirm_template == 'ksef') {
                $xml_warnings = array();
                $content = $this->buildPreviewPdf($object, $xml_warnings);
                if ($content === false || $this->writePreviewPdf($object, $content) === false) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }

                $_SESSION['ksef_confirm_preview'] = array(
                    'invoice_id' => $object->id,
                    'template' => 'ksef',
                    'xml_warnings' => $xml_warnings,
                    'notices' => $this->sendNotices,
                );
            } else {
                $gen_result = $object->generateDocument($confirm_template, $langs);
                if ($gen_result < 0) {
                    setEventMessages($langs->trans('KSEF_PDFGenerationError') . ': ' . dol_escape_htmltag(implode(', ', $object->errors)), null, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }

                $_SESSION['ksef_confirm_preview'] = array(
                    'invoice_id' => $object->id,
                    'template' => $confirm_template,
                    'xml_warnings' => array(),
                    'notices' => $this->sendNotices,
                );
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_upload&token=' . newToken());
            exit;
        }

        if ($action == 'ksef_validate_and_preview' && !empty($object->id)) {
            $this->requireSendAccess($object, 'validate_and_send');
            $langs->load("ksef@ksef");

            // no wasted number
            $go = $this->cardPreflightGate($object, 'validate_preview');
            if ($go !== null) {
                header('Location: ' . $go);
                exit;
            }

            if ($object->validate($user) > 0) {
                setEventMessages($langs->trans('KSEF_InvoiceValidated'), null, 'mesgs');

                $confirm_template = getDolGlobalString('KSEF_CONFIRM_PDF_TEMPLATE', 'ksef');
                $xml_warnings = array();

                if ($confirm_template == 'ksef') {
                    $pdfContent = $this->buildPreviewPdf($object, $xml_warnings);
                    if ($pdfContent === false || $this->writePreviewPdf($object, $pdfContent) === false) {
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                        exit;
                    }
                } else {
                    $gen_result = $object->generateDocument($confirm_template, $langs);
                    if ($gen_result < 0) {
                        setEventMessages($langs->trans('KSEF_PDFGenerationError') . ': ' . dol_escape_htmltag(implode(', ', $object->errors)), null, 'errors');
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                        exit;
                    }
                }

                $_SESSION['ksef_confirm_preview'] = array(
                    'invoice_id' => $object->id,
                    'template' => $confirm_template,
                    'xml_warnings' => $xml_warnings,
                    'notices' => $this->sendNotices,
                );

                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_upload&from_validate=1&token=' . newToken());
                exit;
            } else {
                setEventMessages($object->error, $object->errors, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }
        }

        if ($action == 'ksef_confirm_upload' && !empty($object->id)) {
            $langs->load("ksef@ksef");

            if (!in_array($object->statut, array(1, 2))) {
                unset($_SESSION['ksef_confirm_preview']);
                dol_syslog("KSEF ksef_confirm_upload invoice " . (int) $object->id . " refused: status " . (int) $object->statut, LOG_WARNING);
                setEventMessages($langs->trans('KSEF_InvoiceMustBeValidated'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $from_validate = GETPOST('from_validate', 'int');

            $title = $from_validate
                ? $langs->trans('KSEF_ConfirmUploadValidatedTitle')
                : $langs->trans('KSEF_ConfirmUploadTitle');

            $environment = ksefGetEnvironment();
            $env_badge = ksefGetEnvironmentBadge($environment, true);

            $confirm_data = isset($_SESSION['ksef_confirm_preview']) ? $_SESSION['ksef_confirm_preview'] : array();
            $xml_warnings = !empty($confirm_data['xml_warnings']) ? $confirm_data['xml_warnings'] : array();
            $confirm_template = !empty($confirm_data['template']) ? $confirm_data['template'] : 'ksef';

            $pdf_url = ksefInvoiceDocumentUrl($object, $confirm_template != 'ksef' ? dol_sanitizeFileName($object->ref) . '.pdf' : ksefInvoicePdfPath($object, true), true);

            $submit_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_submit&token=' . newToken();
            $cancel_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_dismiss_send_dialog&token=' . newToken();
            $send_notices = !empty($confirm_data['notices']) ? $confirm_data['notices'] : array();

            dol_include_once('/ksef/class/ksef_latarnia.class.php');
            $latarnia_cached = KsefLatarnia::getCachedStatus();
            $latarnia_status = $latarnia_cached['status'];
            $latarnia_is_issue = in_array($latarnia_status, array('MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE', 'UNREACHABLE'));

            print '<div id="ksef-confirm-upload-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
            print '<div class="ksef-modal-panel" style="padding: 25px; border-radius: 12px; width: 80vw; max-width: 1000px; max-height: 90vh; box-shadow: 0 8px 32px rgba(0,0,0,0.3); display: flex; flex-direction: column;">';
            print '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
            print '<h3 style="margin: 0; font-size: 20px;">' . $title . '</h3>';
            print '<div class="ksef-modal-env" style="text-align: right;">' . $langs->trans('KSEF_ENVIRONMENT') . ': ' . $env_badge . '</div>';
            print '</div>';

            print '<p style="margin: 0 0 5px 0; color: var(--ksef-text-muted, #555);">' . $langs->trans('KSEF_ConfirmUploadText', '<strong>' . dol_escape_htmltag($object->ref) . '</strong>') . '</p>';
            print '<p style="margin: 0 0 15px 0; color: var(--ksef-text-faint, #6c757d); font-size: 13px;"><i class="fas fa-info-circle"></i> ' . $langs->trans('KSEF_ConfirmUploadNote') . '</p>';

            $latarnia_upcoming = ksefLatarniaUpcomingBanner($latarnia_cached);
            if ($latarnia_is_issue || $latarnia_upcoming !== '' || !empty($xml_warnings) || !empty($send_notices)) {
                print '<div style="margin-bottom: 15px;">';
                print $latarnia_upcoming;

                foreach ($send_notices as $notice) {
                    print '<div class="ksef-confirm-notice ksef-note-check" style="padding: 10px 14px; border-radius: 6px; margin-bottom: 8px;">';
                    print '<i class="fas fa-exclamation-triangle ' . ksefToneTextClass('check') . '"></i> ' . $notice;
                    print '</div>';
                }

                if ($latarnia_is_issue) {
                    $latarnia_badge = ksefGetLatarniaStatusBadge($latarnia_status);
                    print '<div class="ksef-note-check" style="padding: 10px 14px; border-radius: 6px; margin-bottom: 8px;">';
                    print '<i class="fas fa-exclamation-triangle ' . ksefToneTextClass('check') . '"></i> ';
                    print $langs->trans('KSEF_ConfirmLatarniaWarning') . ' ' . $latarnia_badge;
                    print '</div>';
                }

                if (!empty($xml_warnings)) {
                    print '<div class="ksef-note-check" style="padding: 10px 14px; border-radius: 6px;">';
                    print '<i class="fas fa-exclamation-triangle ' . ksefToneTextClass('check') . '"></i> ';
                    print $langs->trans('KSEF_ConfirmXMLWarnings') . ': ' . dol_escape_htmltag(implode(', ', $xml_warnings));
                    print '</div>';
                }

                print '</div>';
            }
            print '<div id="ksef-confirm-pdf-wrap" style="flex: 1; min-height: 0; margin-bottom: 15px;">';
            print '<iframe id="ksef-confirm-pdf" src="' . dol_escape_htmltag($pdf_url) . '" style="width: 100%; height: 70vh; border: 1px solid var(--ksef-surface-border, #ddd); border-radius: 6px;" title="KSeF PDF Preview"></iframe>';
            print '</div>';

            print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
            print '<a class="butAction ksef-confirm-cancel" href="' . $cancel_url . '" onclick="var m=document.getElementById(\'ksef-confirm-upload-modal\'); if(m) m.remove(); return true;" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('KSEF_ConfirmUploadCancel') . '</a>';
            print '<a class="butAction ' . ksefToneClass('problem') . ' ksef-confirm-send" href="' . $submit_url . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold;" onclick="var m=document.getElementById(\'ksef-confirm-upload-modal\'); if(m) m.remove(); return (typeof ksefShowSpinner === \'function\') ? ksefShowSpinner(event, this) : true;" data-processing-text="' . $langs->trans('KSEF_SubmittingToKSEF') . '...">';
            print '<i class="fa fa-paper-plane"></i> ' . $langs->trans('KSEF_ConfirmUploadSubmit') . '</a>';
            print '</div>';

            print '</div>';
            print '</div>';

            return 0;
        }

        if (GETPOST('ksef_post_upload', 'int') && !empty($object->id)) {
            $langs->load("ksef@ksef");

            $environment = ksefGetEnvironment();
            $env_badge = ksefGetEnvironmentBadge($environment);

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $submission = new KsefSubmission($db);
            $ksef_number = '';
            if ($submission->fetchByInvoice($object->id) > 0 && $submission->status == 'ACCEPTED') {
                $ksef_number = $submission->ksef_number;
            } else {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $final_pdf = ksefInvoicePdfPath($object);
            $has_final_pdf = is_file($final_pdf) && !ksefPdfIsPreview($final_pdf);
            $pdf_url = $has_final_pdf ? ksefInvoiceDocumentUrl($object, $final_pdf, true) : '';
            $download_url = $has_final_pdf ? ksefInvoiceDocumentUrl($object, $final_pdf, false) : '';
            $close_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id;

            print '<div id="ksef-post-upload-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
            print '<div class="ksef-modal-panel" style="padding: 25px; border-radius: 12px; width: 80vw; max-width: 1000px; max-height: 90vh; box-shadow: 0 8px 32px rgba(0,0,0,0.3); display: flex; flex-direction: column;">';
            print '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
            print '<h3 style="margin: 0; font-size: 20px;"><i class="fas fa-check-circle ' . ksefToneTextClass('ok') . '"></i> ' . $langs->trans('KSEF_PostUploadTitle') . '</h3>';
            print '<div class="ksef-modal-env" style="text-align: right;">' . $langs->trans('KSEF_ENVIRONMENT') . ': ' . $env_badge . '</div>';
            print '</div>';

            print '<p style="margin: 0 0 5px 0; color: var(--ksef-text-muted, #555);">' . $langs->trans('KSEF_PostUploadText', '<strong>' . dol_escape_htmltag($object->ref) . '</strong>') . '</p>';
            if (!empty($ksef_number)) {
                print '<p style="margin: 0 0 15px 0; color: var(--ksef-text-muted, #555);">' . $langs->trans('KSEF_PostUploadKsefNumber') . ': <strong>' . dol_escape_htmltag($ksef_number) . '</strong></p>';
            }
            if ($has_final_pdf) {
                print '<div style="flex: 1; min-height: 0; margin-bottom: 15px;">';
                print '<iframe id="ksef-post-upload-pdf" src="' . dol_escape_htmltag($pdf_url) . '" style="width: 100%; height: 70vh; border: 1px solid var(--ksef-surface-border, #ddd); border-radius: 6px;" title="KSeF PDF"></iframe>';
                print '</div>';
            }

            print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
            print '<a class="butAction ksef-post-upload-close" href="' . $close_url . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('KSEF_PostUploadClose') . '</a>';
            if ($has_final_pdf) {
                print '<a class="butAction" href="' . dol_escape_htmltag($download_url) . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
                print '<i class="fas fa-download"></i> ' . $langs->trans('KSEF_PostUploadSave') . '</a>';
                print '<a class="butAction" href="#" onclick="try { document.getElementById(\'ksef-post-upload-pdf\').contentWindow.print(); } catch(e) { window.open(\'' . dol_escape_htmltag(dol_escape_js($pdf_url)) . '\', \'_blank\'); } return false;" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
                print '<i class="fas fa-print"></i> ' . $langs->trans('KSEF_PostUploadPrint') . '</a>';
            }
            print '</div>';

            print '</div>';
            print '</div>';

            return 0;
        }

        if ($action == 'ksef_submit' && !empty($object->id)) {
            $this->requireSendAccess($object, 'send');
            $langs->load("ksef@ksef");

            $preview = isset($_SESSION['ksef_confirm_preview']) ? $_SESSION['ksef_confirm_preview'] : array();
            $shown = (int) ($preview['invoice_id'] ?? 0) === (int) $object->id && !empty($preview['notices']) ? (array) $preview['notices'] : array();
            unset($_SESSION['ksef_confirm_preview']);

            $ksef_submission = new KsefSubmission($db);
            if ($ksef_submission->fetch(0, $object->id, ksefGetEnvironment()) > 0 && $ksef_submission->status == 'ACCEPTED') {
                dol_syslog("KSEF ksef_submit invoice " . (int) $object->id . " (" . $object->ref . ") not sent: latest submission " . (int) $ksef_submission->rowid . " is ACCEPTED", LOG_WARNING);
                setEventMessages($langs->trans('KSEF_AlreadySubmitted'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $go = $this->cardPreflightGate($object, 'submit', $shown);
            if ($go !== null) {
                header('Location: ' . $go);
                exit;
            }
            session_write_close();

            try {
                $ksef = $this->newSendService($object);
                $result = $ksef->submitInvoice($object->id, $user, 'SYNC');
            } catch (\Throwable $e) {
                $result = $this->exceptionResult($e, $ksef ?? null);
            }
            session_start();

            header('Location: ' . $this->cardSendResult($result, $object, 'submit', $ksef ?? null));
            exit;
        }

        if (in_array($action, array('ksef_check_status', 'ksef_confirm_link_number', 'ksef_confirm_mark_not_sent'), true) && !empty($object->id)) {
            $langs->load("ksef@ksef");
            $back = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
            $this->requireToken($object);
            $ksef = new KsefService($db);
            if ($action == 'ksef_check_status') {
                if (!ksefUserCan($user, 'refresh_submission') || !ksefUserCanReadInvoice($user, $object->id)) {
                    accessforbidden();
                }
                session_write_close();
                $result = $ksef->checkStatus($object->id, $user, true);
                session_start();
                if ($result && ($result['status'] ?? '') === 'ACCEPTED') {
                    setEventMessages(!empty($result['adopted']) ? $langs->trans('KSEF_AdoptedDuplicate', ksefEscapeText($result['ksef_number']))
                        : $langs->trans('KSEF_StatusNowAccepted', ksefEscapeText($result['ksef_number'])), null, 'mesgs');
                } elseif ($result && ($result['status'] ?? '') === 'FAILED') {
                    setEventMessages($langs->trans('KSEF_StatusNowRejected', ksefEscapeText(ksefKsefCodeLabel($result['code']))), null, 'warnings');
                } elseif ($result) {
                    setEventMessages($langs->trans('KSEF_StatusStillProcessing'), null, 'mesgs');
                } elseif ($ksef->failureReason === 'noref') {
                    setEventMessages($langs->trans('KSEF_StatusNoReference'), null, 'warnings');
                } elseif ($ksef->failureReason === 'notfound') {
                    setEventMessages($ksef->searchedByHash ? ksefEscapeText($ksef->error) : $langs->trans('KSEF_StatusNotReceived'), null, 'warnings');
                } elseif (in_array($ksef->failureReason, array('ambiguous', 'right', 'wait'), true)) {
                    setEventMessages(ksefEscapeText($ksef->error), null, 'warnings');
                } elseif ($ksef->failureReason === 'record') {
                    setEventMessages(ksefEscapeText($ksef->notRecordedNumber !== '' ? $ksef->error : ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric')), null, 'errors');
                } else {
                    setEventMessages($langs->trans('KSEF_CannotCheckNow'), null, 'errors');
                }
            } elseif ($action == 'ksef_confirm_link_number') {
                if (!ksefUserCan($user, 'link_ksef_number')) {
                    accessforbidden();
                }
                if (GETPOST('confirm', 'alpha') === 'yes') {
                    session_write_close();
                    $result = $ksef->linkKsefNumber($object->id, GETPOST('ksef_number', 'alphanohtml'), $user);
                    session_start();
                    if (($result['status'] ?? '') === 'ACCEPTED') {
                        setEventMessages($langs->trans('KSEF_LinkKsefNumberDone', ksefEscapeText($result['ksef_number'])), null, 'mesgs');
                        if (empty($result['has_upo'])) {
                            setEventMessages($langs->trans('KSEF_UPOUnavailableNoSession'), null, 'warnings');
                        }
                    } else {
                        setEventMessages(ksefEscapeText($result['error'] ?? $ksef->error), null, 'errors');
                    }
                }
            } else {
                if (!ksefUserCan($user, 'mark_not_sent')) {
                    accessforbidden();
                }
                if (GETPOST('confirm', 'alpha') === 'yes') {
                    $sub = new KsefSubmission($db);
                    if ($sub->fetchByInvoice($object->id, ksefGetEnvironment()) > 0 && $ksef->markNotSent($sub, $user) > 0) {
                        setEventMessages($langs->trans('KSEF_MarkedNotSent'), null, 'mesgs');
                    } else {
                        setEventMessages($ksef->error ? ksefEscapeText($ksef->error) : $langs->trans('KSEF_MarkNotSentRefused'), null, 'errors');
                    }
                }
            }
            header('Location: ' . $back);
            exit;
        }

        if ($action == 'ksef_retry' && !empty($object->id)) {
            $this->requireSendAccess($object, 'send');
            $langs->load("ksef@ksef");

            $go = $this->cardPreflightGate($object, 'retry');
            if ($go !== null) {
                header('Location: ' . $go);
                exit;
            }
            session_write_close();

            try {
                $ksef = $this->newSendService($object);
                $result = $ksef->retrySubmission($object->id, $user);
            } catch (\Throwable $e) {
                $result = $this->exceptionResult($e, $ksef ?? null);
            }
            session_start();

            header('Location: ' . $this->cardSendResult($result, $object, 'retry', $ksef ?? null));
            exit;
        }

        if ($action == 'ksef_create_offline' && !empty($object->id)) {
            $this->requireSendAccess($object, 'send');
            $langs->load("ksef@ksef");
            $offline_cert_check = ksefIsOfflineCertificateConfigured();
            if (!$offline_cert_check['valid']) {
                $_SESSION['ksef_needs_offline_cert'] = array(
                    'invoice_id' => $object->id,
                    'missing' => $offline_cert_check['configured'] ? array('expired') : $offline_cert_check['missing']
                );
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_cert_required&token=' . newToken());
                exit;
            }
            if (GETPOST('confirm', 'alpha') !== 'yes') {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_ask_create_offline&token=' . newToken());
                exit;
            }

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $submission = new KsefSubmission($db);
            if ($submission->fetchByInvoice($object->id, ksefGetEnvironment()) > 0) {
                if ($submission->status == 'ACCEPTED') {
                    setEventMessages($langs->trans('KSEF_AlreadySubmitted'), null, 'warnings');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            $go = $this->cardPreflightGate($object, 'offline_create');
            if ($go !== null) {
                header('Location: ' . $go);
                exit;
            }
            session_write_close();

            try {
                $ksef = $this->newSendService($object);
                $result = $ksef->submitInvoiceOffline($object->id, $user, 'failed_submission');
            } catch (\Throwable $e) {
                $result = $this->exceptionResult($e, $ksef ?? null);
            }
            session_start();
            $this->clearSendAcks($object->id);

            if ($result && $result['status'] == 'OFFLINE') {
                $msg = $langs->trans('KSEF_OfflineInvoiceCreated');
                if (!empty($result['ksef_number'])) {
                    $msg .= ': ' . $result['ksef_number'];
                }
                if (!empty($result['offline_deadline'])) {
                    $msg .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . ksefOfflineDeadlineText(ksefOfflineDeadlineForInvoice($this->db, $object->id, ksefGetEnvironment(), $result['offline_deadline'], $object->date)) . '</small>';
                }
                $msg .= '<br><small>' . $langs->trans('KSEF_OfflineAutoUpload') . '</small>';
                setEventMessages($msg, null, 'mesgs');
                if (!empty($result['pdf_warning'])) {
                    setEventMessages(ksefEscapeText($result['pdf_warning']), null, 'warnings');
                }
            } else {
                $error_msg = $langs->trans('KSEF_OfflineCreationFailed');
                if (!empty($result['error'])) {
                    $error_msg .= ': ' . ksefEscapeText($result['error']);
                }
                setEventMessages($error_msg, null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_show_failure_dialog' && !empty($_SESSION['ksef_failed_confirm'][(int) $object->id])) {
            $fail_data = $_SESSION['ksef_failed_confirm'][(int) $object->id];
            if ($fail_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");
                $this->printFailureDialog($object, $fail_data);
            }
            return 0;
        }

        if ($action == 'ksef_show_cert_required' && !empty($_SESSION['ksef_needs_offline_cert'])) {
            $cert_data = $_SESSION['ksef_needs_offline_cert'];
            if ($cert_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");

                print '<div id="ksef-cert-required-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
                print '<div class="ksef-modal-panel" style="padding: 30px; border-radius: 12px; max-width: 500px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';
                print '<div style="text-align: center; margin-bottom: 20px;">';
                print '<div class="ksef-modal-icon ' . ksefToneClass('check') . '">';
                print '<i class="fa fa-certificate"></i>';
                print '</div>';
                $certNow = ksefIsOfflineCertificateConfigured();
                $expired = ksefOfflineCertExpired($certNow);
                print '<h3 style="margin: 0; font-size: 20px;">' . $langs->trans($expired ? 'KSEF_OfflineCertExpiredTitle' : 'KSEF_OfflineCertificateRequired') . '</h3>';
                print '</div>';
                print '<div class="ksef-note-check" style="padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                if ($expired) {
                    print '<p class="' . ksefToneTextClass('check') . '" style="margin: 0;"><i class="fa fa-info-circle"></i> ' . dol_escape_htmltag(ksefOfflineCertRefusalText('KSEF_OfflineCertificateRequired', $certNow)) . '</p>';
                } else {
                    print '<p class="' . ksefToneTextClass('check') . '" style="margin: 0 0 10px 0;"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_OfflineCertificateRequiredExplanation') . '</p>';
                    print '<p class="' . ksefToneTextClass('check') . '" style="margin: 0; font-size: 13px;">' . $langs->trans('KSEF_OfflineCertificateMissingItems') . ':</p>';
                    print '<ul class="' . ksefToneTextClass('check') . '" style="margin: 5px 0 0 0; padding-left: 20px;">';
                    foreach ($cert_data['missing'] as $item) {
                        print '<li>' . $langs->trans('KSEF_Missing_' . $item) . '</li>';
                    }
                    print '</ul>';
                }
                print '</div>';
                print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_dismiss_cert_dialog&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Close') . '</a>';
                print '<a class="button button-save ' . ksefToneClass('action') . '" href="' . ksefConfigTabUrl('auth') . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
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
            unset($_SESSION['ksef_failed_confirm'][(int) $object->id]);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_confirm_offline_after_fail' && !empty($object->id)) {
            $this->requireSendAccess($object, 'send');
            $langs->load("ksef@ksef");

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $submission = new KsefSubmission($db);
            $submission->fetchByInvoice($object->id, ksefGetEnvironment());
            $_SESSION['ksef_failed_confirm'][(int) $object->id] = array(
                'invoice_id' => $object->id,
                'error' => $submission->error_message ?? 'Previous submission failed',
                'error_code' => $submission->error_code ?? null,
                'submission_id' => $submission->rowid ?? null,
                'kind' => ksefRowFailureKind($submission),
                'offline_row' => !empty($submission->offline_mode),
                'rejected_offline' => $submission->status === 'REJECTED' && !empty($submission->offline_mode),
                'context' => 'retry'
            );

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_failure_dialog&token=' . newToken());
            exit;
        }

        if ($action == 'ksef_retry_online' && !empty($object->id)) {
            $this->requireSendAccess($object, 'send');
            $langs->load("ksef@ksef");

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $existing = new KsefSubmission($db);

            if ($existing->fetchByInvoice($object->id, ksefGetEnvironment()) <= 0) {
                setEventMessages($langs->trans('KSEF_NoSubmissionFound'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $is_valid_offline = ($existing->status == 'OFFLINE') ||
                ($existing->status == 'ACCEPTED' && !empty($existing->offline_mode) &&
                    strpos((string) $existing->ksef_number, 'OFFLINE') === 0) ||
                ($existing->status == 'PENDING' && !empty($existing->offline_mode) && $existing->isStale());

            if (!$is_valid_offline) {
                setEventMessages($langs->trans('KSEF_InvalidStatusForUpload'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $lateInfo = !empty($existing->offline_deadline) ? ksefOfflineDeadlineForInvoice($this->db, $object->id, $existing->environment, $existing->offline_deadline, $object->date) : null;
            if ($lateInfo && $lateInfo['state'] === 'due' && ksefIsDeadlinePassed($lateInfo['deadline'])) {
                setEventMessages($langs->trans('KSEF_OfflineSentLate'), null, 'warnings');
            }
            unset($_SESSION['ksef_failed_confirm'][(int) $object->id]);
            session_write_close();

            try {
                $ksef = $this->newSendService($object);

                // keeps KOD hash
                if (!empty($existing->fa3_xml)) {
                    $result = $ksef->retryOfflineWithStoredXML($existing, $user);
                } else {
                    $result = array('status' => 'FAILED', 'error' => $langs->transnoentitiesnoconv('KSEF_OfflineXmlMissing'), 'failure_kind' => 'local');
                }
            } catch (\Throwable $e) {
                $result = $this->exceptionResult($e, $ksef ?? null);
            }
            session_start();

            if (is_array($result)) {
                $result['offline_mode'] = 'OFFLINE';
            }
            header('Location: ' . $this->cardSendResult($result, $object, 'retry_online', $ksef ?? null));
            exit;
        }

        if ($action == 'ksef_confirm_offline' && !empty($_SESSION['ksef_offline_confirm'][(int) $object->id])) {
            $confirm_data = $_SESSION['ksef_offline_confirm'][(int) $object->id];
            if ($confirm_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");

                $certOk = ksefIsOfflineCertificateConfigured();
                $title = $langs->trans('KSEF_OfflineBackdatedTitle');
                $message = $langs->trans((int) $confirm_data['days_behind'] === 1 ? 'KSEF_OfflineBackdatedMessageOne' : 'KSEF_OfflineBackdatedMessage', $confirm_data['days_behind']);

                print '<div id="ksef-offline-confirm-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
                print '<div class="ksef-modal-panel" style="padding: 30px; border-radius: 12px; max-width: 520px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';
                print '<div style="text-align: center; margin-bottom: 20px;">';
                print '<div class="ksef-modal-icon ' . ksefToneClass('check') . '">';
                print '<i class="fas fa-exclamation-triangle"></i>';
                print '</div>';
                print '<h3 style="margin: 0; font-size: 20px;">' . $title . '</h3>';
                print '</div>';
                print '<div style="background: var(--ksef-surface, #f8f9fa); padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0 0 10px 0; color: var(--ksef-text-muted, #555);">' . $message . '</p>';
                print '</div>';
                print '<div class="ksef-note-check" style="padding: 12px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0; font-size: 14px;"><strong><i class="fas fa-clock"></i> ' . $langs->trans('KSEF_OfflineDeadline') . ':</strong><br>';
                $dlInfo = ksefOfflineDeadlineInfo($object->date);
                $overdue = $dlInfo['state'] === 'due' && !empty($dlInfo['deadline']) && $dlInfo['deadline'] < dol_now();
                print ($overdue ? ksefFormatDeadline($dlInfo['deadline'], false, $dlInfo) . '<br><span class="ksef-offline-overdue-note">' . $langs->trans('KSEF_OfflineDeadlinePassedSend') . '</span>'
                    : ksefOfflineDeadlineText($dlInfo)) . '</p>';
                print '</div>';
                print '<p style="color: var(--ksef-text-muted, #666); font-size: 13px; margin-bottom: 25px;">';
                print '<i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_OfflineExplanation');
                print '</p>';
                print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_cancel_offline&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Cancel') . '</a>';
                if (!empty($certOk['valid'])) {
                    print '<a class="button button-save ' . ksefToneClass('problem') . '" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_submit_offline_confirmed&token=' . newToken() . '" onclick="return (typeof ksefShowSpinner === \'function\') ? ksefShowSpinner(event, this) : true;" data-processing-text="' . $langs->trans('KSEF_CreatingOfflineInvoice') . '..." style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
                    print '<i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_ConfirmOfflineSubmit') . '</a>';
                } else {
                    print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag(ksefOfflineCertRefusalText('KSEF_OfflineNeedsCert', $certOk)) . '"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_ConfirmOfflineSubmit') . '</span>';
                    if (!empty($GLOBALS['user']->admin)) {
                        print '<a class="button" href="' . dol_buildpath('/ksef/admin/setup_auth.php', 1) . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;"><i class="fa fa-cog"></i> ' . $langs->trans('KSEF_SetUpOfflineCert') . '</a>';
                    }
                }
                print '</div>';

                print '</div>';
                print '</div>';
            }
            return 0;
        }

        if ($action == 'ksef_cancel_offline') {
            unset($_SESSION['ksef_offline_confirm'][(int) $object->id]);
            $this->clearSendAcks($object->id);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_submit_offline_confirmed' && !empty($object->id)) {
            $this->requireSendAccess($object, 'send');
            $langs->load("ksef@ksef");

            $go = $this->cardPreflightGate($object, 'offline_confirmed');
            if ($go !== null) {
                header('Location: ' . $go);
                exit;
            }

            $offline_reason = '';

            if (!empty($_SESSION['ksef_offline_confirm'][(int) $object->id])) {
                $confirm_data = $_SESSION['ksef_offline_confirm'][(int) $object->id];
                $offline_reason = $confirm_data['reason'] ?? 'user_confirmed';
                unset($_SESSION['ksef_offline_confirm'][(int) $object->id]);
            }
            if (!empty($_SESSION['ksef_failed_confirm'][(int) $object->id])) {
                $offline_reason = 'failed_submission';
                unset($_SESSION['ksef_failed_confirm'][(int) $object->id]);
            }
            session_write_close();

            $upload = null;
            try {
                $ksef = $this->newSendService($object);
                $result = $ksef->submitInvoiceOffline($object->id, $user, $offline_reason);
                if (is_array($result) && ($result['status'] ?? '') === 'OFFLINE' && in_array($offline_reason, array('backdated', 'backdated_retry'), true)
                    && !empty($result['submission_id'])) {
                    $offSub = new KsefSubmission($db);
                    if ($offSub->fetch((int) $result['submission_id']) > 0) {
                        $upload = $ksef->retryOfflineWithStoredXML($offSub, $user);
                    }
                }
            } catch (\Throwable $e) {
                $result = $this->exceptionResult($e, $ksef ?? null);
            }
            session_start();
            $this->clearSendAcks($object->id);

            if (is_array($upload) && ($upload['status'] ?? '') !== 'TIMEOUT' && ($upload['status'] ?? '') !== 'ACCEPTED' && ($upload['failure_kind'] ?? '') !== 'transport') {
                $upload['offline_mode'] = 'OFFLINE';
                header('Location: ' . $this->cardSendResult($upload, $object, 'retry_online', $ksef ?? null));
                exit;
            }
            if (is_array($upload) && ($upload['status'] ?? '') === 'ACCEPTED' && ksefWarnIfNotRecorded($upload)) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }
            if (is_array($upload) && ($upload['status'] ?? '') === 'ACCEPTED') {
                if (empty($upload['adopted'])) {
                    setEventMessages($langs->trans('KSEF_OfflineIssuedAndSent', ksefEscapeText((string) $upload['ksef_number'])), null, 'mesgs');
                }
                if (!empty($upload['pdf_warning'])) {
                    setEventMessages(ksefEscapeText($upload['pdf_warning']), null, 'warnings');
                }
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            if ($result && !empty($result['needs_certificate'])) {
                $_SESSION['ksef_needs_offline_cert'] = array('invoice_id' => $object->id, 'missing' => $result['missing_items'] ?? array());
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_cert_required&token=' . newToken());
                exit;
            }
            ksefWarnIfNotRecorded($result);
            if ($result && $result['status'] == 'OFFLINE') {
                $msg = $langs->trans('KSEF_OfflineInvoiceCreated');
                if (!empty($result['ksef_number'])) {
                    $msg .= ' - ' . ksefEscapeText($result['ksef_number']);
                }
                $msg .= ' <span class="badge badge-warning">' . $langs->trans('KSEF_OfflineMode') . '</span>';
                if (!empty($result['offline_deadline'])) {
                    $msg .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . ksefOfflineDeadlineText(ksefOfflineDeadlineForInvoice($this->db, $object->id, ksefGetEnvironment(), $result['offline_deadline'], $object->date)) . '</small>';
                }
                $msg .= '<br><small>' . $langs->trans('KSEF_OfflineAutoUpload') . '</small>';
                setEventMessages($msg, null, 'mesgs');
                if (!empty($result['pdf_warning'])) {
                    setEventMessages(ksefEscapeText($result['pdf_warning']), null, 'warnings');
                }
            } else {
                $error_msg = $langs->trans('KSEF_OfflineCreationFailed');
                if (!empty($result['error'])) {
                    $error_msg .= ': ' . ksefEscapeText($result['error']);
                }
                setEventMessages($error_msg, null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_technical_correction' && !empty($object->id)) {
            $this->requireSendAccess($object, 'send');
            $submission_id = GETPOST('submission_id', 'int');
            $langs->load("ksef@ksef");
            session_write_close();

            try {
                $ksef = new KsefService($db);
                $result = $ksef->submitTechnicalCorrection($object->id, $submission_id, $user);
            } catch (\Throwable $e) {
                $result = array('status' => 'ERROR', 'error' => $e->getMessage());
            }
            session_start();

            $tcStatus = is_array($result) ? ($result['status'] ?? '') : '';
            if (ksefWarnIfNotRecorded($result)) {
                // warning already shown
            } elseif ($tcStatus === 'ACCEPTED') {
                setEventMessages($langs->trans('KSEF_TechnicalCorrectionSubmitted') . (!empty($result['ksef_number']) ? ' - ' . ksefEscapeText($result['ksef_number']) : ''), null, 'mesgs');
                if (!empty($result['pdf_warning'])) {
                    setEventMessages(ksefEscapeText($result['pdf_warning']), null, 'warnings');
                }
            } elseif ($tcStatus === 'OFFLINE') {
                setEventMessages($langs->trans('KSEF_TechnicalCorrectionQueued') . (!empty($result['error']) ? '<br><small>' . ksefEscapeText($result['error']) . '</small>' : ''), null, 'warnings');
            } elseif ($tcStatus === 'PENDING') {
                setEventMessages($langs->trans('KSEF_SubmissionInProgress'), null, 'warnings');
            } else {
                setEventMessages($langs->trans('KSEF_TechnicalCorrectionFailed') . ': ' . ksefEscapeText(is_array($result) ? ($result['error'] ?? '') : (isset($ksef) ? $ksef->error : '')), null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_validate_and_submit' && !empty($object->id)) {
            $this->requireSendAccess($object, 'validate_and_send');
            $langs->load("ksef@ksef");

            $go = $this->cardPreflightGate($object, 'validate_submit');
            if ($go !== null) {
                header('Location: ' . $go);
                exit;
            }

            if ($object->validate($user) > 0) {
                session_write_close();

                try {
                    $ksef = $this->newSendService($object);
                    $submit_result = $ksef->submitInvoice($object->id, $user, 'SYNC');
                } catch (\Throwable $e) {
                    $submit_result = $this->exceptionResult($e, $ksef ?? null);
                }
                session_start();

                header('Location: ' . $this->cardSendResult($submit_result, $object, 'validate_submit', $ksef ?? null));
                exit;
            }

            setEventMessages($object->error, $object->errors, 'errors');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_show_rate_dialog' && !empty($_SESSION['ksef_rate_dialog']) && (int) $_SESSION['ksef_rate_dialog']['invoice_id'] === (int) $object->id) {
            $langs->load("ksef@ksef");
            $this->printRateDialog($object, $_SESSION['ksef_rate_dialog']);
            return 0;
        }
        if ($action == 'ksef_show_deposit_dialog' && !empty($_SESSION['ksef_deposit_dialog']) && (int) $_SESSION['ksef_deposit_dialog']['invoice_id'] === (int) $object->id) {
            $langs->load("ksef@ksef");
            $this->printDepositDialog($object, $_SESSION['ksef_deposit_dialog']);
            return 0;
        }
        if ($action == 'ksef_show_backdated_dialog' && !empty($_SESSION['ksef_backdated_dialog']) && (int) $_SESSION['ksef_backdated_dialog']['invoice_id'] === (int) $object->id) {
            $langs->load("ksef@ksef");
            $this->printBackdatedDialog($object, $_SESSION['ksef_backdated_dialog']);
            return 0;
        }
        if ($action == 'ksef_confirm_resend' && !empty($object->id)) {
            $langs->load("ksef@ksef");
            $id = (int) $object->id;
            $next = GETPOST('next', 'aZ09');
            $this->requireSendAccess($object, in_array($next, array('validate_preview', 'validate_submit'), true) ? 'validate_and_send' : 'send');
            if (GETPOST('confirm', 'alpha') !== 'yes') {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $id);
                exit;
            }
            $_SESSION['ksef_resend_ack'][$id] = array('context' => $this->cardActionContext($this->cardContextAction($next)), 'time' => dol_now());
            dol_syslog('KSEF: user ' . $user->login . ' confirmed sending invoice ' . $id . ' although it carries KSeF number '
                . ksefSubmissionState($db, $id)['number_without_row'] . ' without a submission record', LOG_WARNING);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=' . $this->cardContextAction($next) . '&token=' . newToken());
            exit;
        }
        if (in_array($action, array('ksef_accept_stored_rate', 'ksef_backdated_online', 'ksef_set_date_today', 'ksef_dismiss_send_dialog', 'ksef_accept_deposits'), true) && !empty($object->id)) {
            $langs->load("ksef@ksef");
            $id = (int) $object->id;
            $card = $_SERVER['PHP_SELF'] . '?id=' . $id;
            $this->requireToken($object);
            $next = GETPOST('next', 'aZ09');
            $nextUrl = $card . '&action=' . $this->cardContextAction($next) . ($next === 'offline_create' ? '&confirm=yes' : '') . '&token=' . newToken();
            if ($action == 'ksef_dismiss_send_dialog') {
                unset($_SESSION['ksef_rate_dialog'], $_SESSION['ksef_backdated_dialog'], $_SESSION['ksef_deposit_dialog'], $_SESSION['ksef_deposit_ack'][$id], $_SESSION['ksef_resend_ack'][$id],
                    $_SESSION['ksef_offline_confirm'][$id]);
                header('Location: ' . $card);
                exit;
            }
            $this->requireSendAccess($object, $action == 'ksef_set_date_today' ? 'validate_and_send' : 'send');
            if ($action == 'ksef_accept_stored_rate') {
                dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
                $nbp = new KsefNbpCurrencyRate($db);
                $rc = $nbp->checkStoredRate($object);
                unset($_SESSION['ksef_rate_dialog']);
                if ($rc['strict']) {
                    setEventMessages($langs->trans('KSEF_NBPRateStale', $rc['stored_label'], $rc['reference_label']), null, 'errors');
                    header('Location: ' . $card);
                    exit;
                }
                $_SESSION['ksef_accept_stored_rate'][$id] = 1;
                dol_syslog('KSEF: user ' . $user->login . ' chose to send invoice ' . $id . ' with the stored NBP rate of ' . $rc['stored'] . ' (reference ' . $rc['reference'] . ')', LOG_WARNING);
                header('Location: ' . $nextUrl);
                exit;
            }
            if ($action == 'ksef_accept_deposits') {
                unset($_SESSION['ksef_deposit_dialog']);
                $kinds = ksefDepositAckKinds(ksefDepositContext($db, $object));
                if ((($kinds['deposits'] || !$kinds['rates']) && !GETPOST('ksef_deposits_ack', 'int')) || ($kinds['rates'] && !GETPOST('ksef_rates_ack', 'int'))) {
                    setEventMessages($langs->trans('KSEF_DepositsAckRequired'), null, 'warnings');
                    header('Location: ' . $card);
                    exit;
                }
                $_SESSION['ksef_deposit_ack'][$id] = 1;
                dol_syslog('KSEF: user ' . $user->login . ' acknowledged the deposit notices of invoice ' . $id . ' for this send', LOG_WARNING);
                header('Location: ' . $nextUrl);
                exit;
            }
            if ($action == 'ksef_backdated_online') {
                unset($_SESSION['ksef_backdated_dialog']);
                $_SESSION['ksef_backdated_online'][$id] = 1;
                header('Location: ' . $nextUrl);
                exit;
            }
            unset($_SESSION['ksef_backdated_dialog']);
            if ((int) $object->statut !== Facture::STATUS_DRAFT) {
                setEventMessages($langs->trans('KSEF_SetDateTodayDraftOnly'), null, 'errors');
                header('Location: ' . $card);
                exit;
            }
            $redate = ksefRedateDraftToday($object, $user, GETPOSTINT('ksef_keep_sale_date') > 0);
            if (!$redate['ok']) {
                setEventMessages($redate['error'], $redate['errors'], 'errors');
                header('Location: ' . $card);
                exit;
            }
            setEventMessages($langs->trans('KSEF_DateSetToday', dol_print_date($object->date, 'day')) . ' '
                . ($redate['kept'] ? $langs->trans('KSEF_SaleDateKeptSet', dol_print_date($redate['kept'], 'day'))
                    : $langs->trans('KSEF_SaleDateNow', dol_print_date(ksefGetSaleDate($object), 'day'))), null, 'mesgs');
            header('Location: ' . $nextUrl);
            exit;
        }

        if ($action == 'ksef_fetch_nbp_rate' && !empty($object->id)) {
            $this->requireSendAccess($object, 'edit_invoice_rate');
            $langs->load("ksef@ksef");
            dol_include_once('/ksef/lib/ksef.lib.php');
            dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');

            $nbp = new KsefNbpCurrencyRate($db);
            unset($_SESSION['ksef_rate_dialog']);

            if (!$nbp->invoiceNeedsNBPRate($object)) {
                setEventMessages($langs->trans('KSEF_NBPRateNotNeeded'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $pick = GETPOST('ksef_rate_pick', 'aZ09');
            $pickType = (int) $object->type === Facture::TYPE_REPLACEMENT || ((int) $object->type === Facture::TYPE_CREDIT_NOTE && $pick === 'keep' && !empty($object->fk_facture_source)
                && (int) $object->statut === Facture::STATUS_DRAFT);
            if (!in_array($pick, array('keep', 'exact'), true) || !$pickType
                || ((int) $object->statut !== Facture::STATUS_DRAFT && $nbp->invoiceHasNBPRate($object)) || ksefDepositRootId($db, (int) $object->id) > 0) {
                $pick = '';
            }
            $result = $nbp->fetchAndStoreForInvoice($object, $user, $pick);
            require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

            if ($result !== false) {
                setEventMessages(
                    $langs->trans('KSEF_NBPRateFetched', price((float) $result['rate'], 0, $langs, 0, -1, 6),
                        dol_print_date(dol_stringtotime((string) $result['date']), 'day')),
                    null,
                    'mesgs'
                );
            } else {
                setEventMessages(
                    in_array($nbp->errorCode, array('RATE_CHANGE_SETTLED', 'RATE_CHANGE_BOOKED'), true) ? dol_escape_htmltag($nbp->error) : ksefTransHtml('KSEF_NBPRateFetchError', array(ksefEscapeText($nbp->error))),
                    null,
                    'errors'
                );
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        return 0;
    }

    /**
     * @brief Require CSRF token
     * @param Facture $object Invoice
     * @return void
     */
    private function requireToken($object)
    {
        global $langs;
        if (GETPOST('token', 'alpha') === '') {
            $langs->load('ksef@ksef');
            setEventMessages($langs->trans('SecurityTokenHasExpiredSoActionHasBeenCanceledPleaseRetry'), null, 'errors');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id);
            exit;
        }
    }

    /**
     * @brief Build preview PDF
     * @param Facture $object Invoice
     * @param array &$xml_warnings XSD warnings
     * @return string|false PDF bytes
     */
    private function buildPreviewPdf($object, &$xml_warnings)
    {
        global $db, $langs;
        dol_include_once('/ksef/class/fa3_builder.class.php');
        dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');
        $xml_warnings = array();
        $builder = new FA3Builder($db);
        $xml = $builder->buildFromInvoice($object->id);
        if ($xml === false) {
            dol_syslog("KSEF preview of invoice " . (int) $object->id . ": XML not built: " . $builder->error, LOG_WARNING);
            setEventMessages($langs->trans('KSEF_PreviewXMLBuildError') . ': ' . dol_escape_htmltag($builder->error), null, 'errors');
            return false;
        }
        if (!$builder->validate($xml)) {
            $xml_warnings = $builder->errors;
            setEventMessages($langs->trans('KSEF_PreviewXMLValidationWarnings') . ': ' . dol_escape_htmltag(implode(', ', $builder->errors)), null, 'warnings');
        }

        $mockSubmission = new stdClass();
        $mockSubmission->rowid = 0;
        $mockSubmission->ksef_number = '';
        $mockSubmission->fa3_xml = $xml;
        $mockSubmission->environment = ksefGetEnvironment();
        $mockSubmission->offline_mode = false;
        $mockSubmission->offline_deadline = null;
        $mockSubmission->invoice_hash = $builder->getLastXmlHash();

        $pdfGen = new KsefInvoicePdf($db);
        $content = $pdfGen->generate($this->createPdfDataFromSubmission($mockSubmission, $object), '', true);
        if ($content === false) {
            dol_syslog("KSEF preview of invoice " . (int) $object->id . ": PDF not generated: " . $pdfGen->error, LOG_ERR);
            setEventMessages($langs->trans('KSEF_PDFGenerationError') . ': ' . dol_escape_htmltag($pdfGen->error), null, 'errors');
            return false;
        }
        return $content;
    }

    /**
     * @brief Save preview PDF
     * @param Facture $object Invoice
     * @param string $content PDF bytes
     * @return string|false File name
     */
    private function writePreviewPdf($object, $content)
    {
        global $conf, $langs;
        $ref = dol_sanitizeFileName($object->ref);
        $filename = basename(ksefInvoicePdfPath($object, true));
        $dir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . $ref;
        $why = '';
        if (!is_dir($dir) && dol_mkdir($dir) < 0) {
            $why = 'cannot create ' . $dir;
        } elseif (@file_put_contents($dir . '/' . $filename, $content) !== strlen($content)) {
            $last = error_get_last();
            $why = 'cannot write ' . $dir . '/' . $filename . ($last ? ': ' . $last['message'] : '');
        }
        if ($why !== '') {
            dol_syslog("KSEF preview of invoice " . (int) $object->id . " not saved: " . $why, LOG_ERR);
            setEventMessages(ksefTransHtml('KSEF_PreviewPDFWriteFailed', array(ksefEscapeText($filename))), null, 'errors');
            return false;
        }
        return $filename;
    }

    /**
     * @brief Attach KSeF PDF
     * @param array $parameters
     * @param FormMail $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int
     */
public function getFormMail($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->inContexts($parameters) || !is_object($object) || !isset($object->param) || !is_array($object->param)) {
            return 0;
        }
        // core trackid format
        if (!preg_match('/^inv(\d+)$/', (string) ($parameters['trackid'] ?? ($object->trackid ?? '')), $m) || (int) $m[1] <= 0) {
            return 0;
        }
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $m[1]) <= 0) {
            return 0;
        }
        $ref = dol_sanitizeFileName($invoice->ref);
        $files = (isset($object->param['fileinit']) && is_array($object->param['fileinit'])) ? $object->param['fileinit'] : array();
        $kept = array_values(array_filter($files, function ($f) {
            return !ksefPdfIsPreview((string) $f) && !ksefIsPreviewPdfName((string) $f);
        }));
        if (count($kept) !== count($files)) {
            dol_syslog("KSEF mail of invoice " . (int) $invoice->id . ": KSeF preview PDF removed from the attachments", LOG_WARNING);
            $main = dirname(ksefInvoicePdfPath($invoice)) . '/' . $ref . '.pdf';
            if (!$kept && is_readable($main)) {
                $kept[] = $main;
            }
            $object->param['fileinit'] = $kept;
            $files = $kept;
        }
        if (getDolGlobalString('KSEF_CONFIRM_PDF_TEMPLATE', 'ksef') !== 'ksef') {
            return 0;
        }
        $state = ksefSubmissionState($this->db, (int) $invoice->id);
        if (empty($state['locked'])) {
            return 0;
        }
        $file = ksefInvoicePdfPath($invoice);
        if (!is_readable($file)) {
            dol_syslog("KSEF mail of invoice " . (int) $invoice->id . ": " . $ref . "_ksef.pdf not found, not attached", LOG_WARNING);
            return 0;
        }
        if (ksefPdfIsPreview($file)) {
            dol_syslog("KSEF mail of invoice " . (int) $invoice->id . ": " . $ref . "_ksef.pdf is a preview (PODGLĄD), not attached; regenerate the KSeF PDF", LOG_WARNING);
            return 0;
        }
        $files = array_values(array_filter($files, function ($f) use ($ref) {
            return basename((string) $f) !== $ref . '.pdf';
        }));
        if (!in_array($file, $files, true)) {
            $files[] = $file;
        }
        $object->param['fileinit'] = $files;
        return 0;
    }

    /**
     * @brief Print processing overlay
     * @return void
     */
    private function printSpinnerAssets()
    {
        global $langs;
        static $spinner_added = false;
        if ($spinner_added) {
            return;
        }
        $spinner_added = true;
            print '<style>
            @keyframes ksef-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            #ksef-processing-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 9999; justify-content: center; align-items: center; }
            #ksef-processing-overlay.active { display: flex; }
            /* card in Dolibarr\'s theme colours (light and dark) */
            .ksef-processing-box { background: var(--colorbackbody, #fff); color: var(--colortext, #333); border: 1px solid var(--colortopbordertitle1, rgba(128, 128, 128, 0.4)); padding: 30px 40px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35); text-align: center; min-width: 300px; }
            .ksef-spinner-large { display: inline-block; width: 50px; height: 50px; border: 4px solid var(--colorbacklinepairhover, rgba(128, 128, 128, 0.3)); border-top: 4px solid #3498db; border-radius: 50%; animation: ksef-spin 1s linear infinite; margin: 0 auto 20px; }
            .ksef-processing-text { font-size: 16px; font-weight: bold; color: var(--colortext, #333); margin-bottom: 10px; }
            .ksef-processing-subtext { font-size: 14px; color: var(--colortext, #666); opacity: 0.7; }
        </style>';

            print '<script>
            function ksefShowSpinner(event, button, formId) {
                event.preventDefault();
                var overlay = document.getElementById("ksef-processing-overlay");
                if (!overlay) {
                    overlay = document.createElement("div");
                    overlay.id = "ksef-processing-overlay";
                    overlay.innerHTML = \'<div class="ksef-processing-box"><div class="ksef-spinner-large"></div><div class="ksef-processing-text">\' + button.getAttribute("data-processing-text") + \'</div><div class="ksef-processing-subtext">' . dol_escape_js($langs->transnoentitiesnoconv("KSEF_PleaseWait")) . '</div></div>\';
                    document.body.appendChild(overlay);
                }
                var textEl = overlay.querySelector(".ksef-processing-text");
                if (textEl && button.getAttribute("data-processing-text")) textEl.textContent = button.getAttribute("data-processing-text");
                overlay.classList.add("active");
                if (formId) {
                    setTimeout(function() { document.getElementById(formId).submit(); }, 100);
                } else {
                    setTimeout(function() { window.location.href = button.href; }, 100);
                }
                return false;
            }
        </script>';
    }

    /**
     * @brief Overlay link attributes
     * @param string $textKey Lang key
     * @return string Leading-space attributes
     */
    private function spinnerLinkAttrs($textKey)
    {
        global $langs;
        return ' onclick="return (typeof ksefShowSpinner === \'function\') ? ksefShowSpinner(event, this) : true;" data-processing-text="' . dol_escape_htmltag($langs->transnoentitiesnoconv($textKey)) . '..."';
    }

    /**
     * @brief Guard send action
     * @param Facture $object Invoice
     * @param string $what ksefUserCan() key
     * @return void
     */
    private function requireSendAccess($object, $what)
    {
        global $user;
        $this->requireToken($object);
        if (!ksefUserCan($user, $what)) {
            accessforbidden();
        }
    }

    /**
     * @brief Context to action
     * @param string $context Send context
     * @return string
     */
    private function cardContextAction($context)
    {
        $map = array('pre_submit' => 'ksef_pre_submit', 'submit' => 'ksef_submit', 'validate_preview' => 'ksef_validate_and_preview',
            'validate_submit' => 'ksef_validate_and_submit', 'retry' => 'ksef_retry', 'offline_create' => 'ksef_create_offline',
            'offline_confirmed' => 'ksef_submit_offline_confirmed');
        return isset($map[$context]) ? $map[$context] : 'ksef_submit';
    }

    /**
     * @brief Action to context
     * @param string $action
     * @return string
     */
    private function cardActionContext($action)
    {
        $map = array('ksef_pre_submit' => 'pre_submit', 'ksef_submit' => 'submit', 'ksef_validate_and_preview' => 'validate_preview',
            'ksef_validate_and_submit' => 'validate_submit', 'ksef_retry' => 'retry', 'ksef_create_offline' => 'offline_create',
            'ksef_submit_offline_confirmed' => 'offline_confirmed');
        return isset($map[$action]) ? $map[$action] : 'submit';
    }

    /**
     * @brief Send preflight
     * @param Facture $object Invoice
     * @param string $context Send context
     * @param array $shown Notices already shown
     * @return string|null Redirect URL or null
     */
    private function cardPreflightGate($object, $context, $shown = array())
    {
        global $db, $langs, $user;
        $id = (int) $object->id;
        $card = $_SERVER['PHP_SELF'] . '?id=' . $id;
        $accepted = !empty($_SESSION['ksef_accept_stored_rate'][$id]);
        if (in_array($context, array('validate_preview', 'validate_submit'), true) && empty($_SESSION['ksef_short_ack'][$id]) && ksefShortfallQuestion($db, $object)) {
            return $card . '&action=ksef_short_ask&next=' . urlencode($context) . '&token=' . newToken();
        }
        if (ksefAdoptOrderDepositLines($db, $id) > 0) {
            $object->fetch_lines();
        }
        // art. 106nda(12)
        if (in_array($context, array('validate_preview', 'validate_submit'), true) && (int) $object->statut === Facture::STATUS_DRAFT) {
            $early = ksefCorrectionNumberDayCheck($db, $object);
            if ($early) {
                $redate = ksefRedateDraftToday($object, $user, false);
                if (!$redate['ok']) {
                    setEventMessages($redate['error'], $redate['errors'], 'errors');
                    return $card;
                }
                $srcLink = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $early['source_id'] . '">' . dol_escape_htmltag($early['source_ref']) . '</a>';
                setEventMessages(ksefTransHtml('KSEF_CorrectionRedatedAfterSourceNumber', array($srcLink, dol_print_date(dol_stringtotime($early['number_day'] . ' 12:00:00'), 'day'),
                    dol_print_date($object->date, 'day'))), null, 'warnings');
                dol_syslog("KSEF draft correction " . $id . " moved from " . $early['day'] . " to today: original's KSeF number assigned " . $early['number_day'], LOG_INFO);
            }
        }
        $pf = ksefPreflightChecks($db, $object, $context, $accepted, !empty($_SESSION['ksef_deposit_ack'][$id]));
        if (!$pf['ok']) {
            dol_syslog("KSEF " . $this->cardContextAction($context) . " invoice " . $id . " (" . $object->ref . ") stopped by preflight: " . $pf['code'], LOG_WARNING);
            if ($pf['code'] === 'deposits_ack') {
                $_SESSION['ksef_deposit_dialog'] = array('invoice_id' => $id, 'next' => $context);
                return $card . '&action=ksef_show_deposit_dialog&token=' . newToken();
            }
            if ($pf['code'] === 'nbp_stale' || $pf['code'] === 'nbp_stale_choice') {
                $_SESSION['ksef_rate_dialog'] = array('invoice_id' => $id, 'stored' => $pf['rate']['stored_label'], 'reference' => $pf['rate']['reference_label'],
                    'strict' => $pf['code'] === 'nbp_stale', 'next' => $context);
                return $card . '&action=ksef_show_rate_dialog&token=' . newToken();
            }
            setEventMessages($pf['message'], null, $pf['level'] === 'error' ? 'errors' : 'warnings');
            return $card;
        }
        $resendAck = null;
        $offline = in_array($context, array('offline_create', 'offline_confirmed'), true);
        if (!$offline && ksefSubmissionState($db, $id)['number_without_row'] !== '') {
            $ack = isset($_SESSION['ksef_resend_ack'][$id]) ? $_SESSION['ksef_resend_ack'][$id] : null;
            unset($_SESSION['ksef_resend_ack'][$id]);
            if (!is_array($ack) || ($ack['context'] ?? '') !== $context || dol_now() - (int) ($ack['time'] ?? 0) > 1800) {
                return $card . '&action=ksef_ask_resend&next=' . urlencode($context) . '&token=' . newToken();
            }
            $resendAck = $ack;
            if (in_array($context, array('pre_submit', 'validate_preview'), true)) {
                $_SESSION['ksef_resend_ack'][$id] = array('context' => 'submit', 'time' => dol_now());
            }
        }
        $notices = $pf['warnings'];
        if (!empty($_SESSION['ksef_deposit_ack'][$id])) {
            $ackText = ksefDepositAckMessage($db, $object, ksefDepositContext($db, $object));
            if ($ackText !== '') {
                $notices[] = $ackText;
            }
        }
        $this->sendNotices = $notices;
        $flash = array_values(array_diff($notices, $shown));
        if (!in_array($context, array('pre_submit', 'validate_preview'), true) && !empty($flash)) {
            setEventMessages(null, $flash, 'warnings');
        }
        if ($context === 'retry' || $offline) {
            return null;
        }
        $bd = $pf['backdated'];
        if (empty($bd['is_backdated'])) {
            return null;
        }
        if ($pf['offline_cert']) {
            $fromValidate = in_array($context, array('validate_preview', 'validate_submit'), true);
            if ($fromValidate) {
                if ($object->validate($user) <= 0) {
                    setEventMessages($object->error, $object->errors, 'errors');
                    return $card;
                }
                setEventMessages($langs->trans('KSEF_InvoiceValidated'), null, 'mesgs');
            }
            $_SESSION['ksef_offline_confirm'][$id] = array('invoice_id' => $id, 'days_behind' => $bd['days_behind'], 'deadline' => $bd['deadline'],
                'reason' => 'backdated', 'from_validate' => $fromValidate);
            return $card . '&action=ksef_confirm_offline&token=' . newToken();
        }
        if (!empty($_SESSION['ksef_backdated_online'][$id])) {
            return null;
        }
        $_SESSION['ksef_backdated_dialog'] = array('invoice_id' => $id, 'days_behind' => $bd['days_behind'],
            'is_draft' => ((int) $object->statut === Facture::STATUS_DRAFT), 'next' => $context);
        if ($resendAck !== null) {
            $_SESSION['ksef_resend_ack'][$id] = array('context' => $context, 'time' => dol_now());
        }
        return $card . '&action=ksef_show_backdated_dialog&token=' . newToken();
    }

    /**
     * @brief Map send result
     * @param array|false $result
     * @param Facture $object Invoice
     * @param string $context Send context
     * @param KsefService|null $ksef
     * @return string Redirect URL
     */
    private function cardSendResult($result, $object, $context, $ksef = null)
    {
        global $langs;
        $id = (int) $object->id;
        $card = $_SERVER['PHP_SELF'] . '?id=' . $id;
        $this->clearSendAcks($id);
        $validated = ($context === 'validate_submit');
        if (is_object($ksef) && !empty($ksef->warnings)) {
            $left = array_values(array_diff($ksef->warnings, array_map(function ($t) {
                return dol_html_entity_decode(strip_tags($t), ENT_QUOTES);
            }, $this->sendNotices), $this->sendNotices));
            if (!empty($left)) {
                setEventMessages(null, $left, 'warnings');
            }
        }
        $notSaved = is_array($result) && isset($result['record_saved']) && $result['record_saved'] === false;
        if ($validated && !$notSaved) {
            setEventMessages($langs->trans('KSEF_InvoiceValidated'), null, 'mesgs');
        }
        if (ksefWarnIfNotRecorded($result)) {
            return $card;
        }
        $status = is_array($result) ? ($result['status'] ?? '') : '';
        if ($status === 'ACCEPTED' && !empty($result['ksef_number'])) {
            $key = ($context === 'retry_online') ? 'KSEF_OnlineSubmissionSuccess' : 'KSEF_SubmissionSuccess';
            if (empty($result['adopted'])) {
                setEventMessages($langs->trans($key) . ' - ' . ksefEscapeText($result['ksef_number']), null, 'mesgs');
            }
            if (!empty($result['backdated_online'])) {
                setEventMessages($langs->trans('KSEF_BackdatedSentOnline'), null, 'warnings');
            }
            if (!empty($result['pdf_warning'])) {
                setEventMessages(ksefEscapeText($result['pdf_warning']), null, 'warnings');
            }
            if (getDolGlobalInt('KSEF_SHOW_PDF_AFTER_UPLOAD', 1)) {
                return $card . '&ksef_post_upload=1';
            }
            return $card;
        }
        if ($status === 'NEEDS_CONFIRMATION' || $status === 'NEEDS_OFFLINE_CONFIRMATION') {
            $bd = $result['backdate_info'] ?? array();
            $_SESSION['ksef_offline_confirm'][$id] = array('invoice_id' => $id, 'days_behind' => $bd['days_behind'] ?? 0, 'deadline' => $result['deadline'] ?? 0,
                'reason' => ($context === 'retry') ? 'backdated_retry' : 'backdated', 'from_validate' => $validated);
            return $card . '&action=ksef_confirm_offline&token=' . newToken();
        }
        if ($status === 'PENDING') {
            setEventMessages($langs->trans('KSEF_SubmissionInProgress'), null, 'warnings');
            return $card;
        }
        if ($status === 'ERROR') {
            $err = $result['error'] ?? ($ksef ? $ksef->error : '');
            setEventMessages($langs->trans($context === 'retry_online' ? 'KSEF_OnlineRetryFailed' : 'KSEF_RetryFailed') . ($err !== '' ? ': ' . ksefEscapeText($err) : ''), null, 'errors');
            return $card;
        }
        if ($status === 'FAILED' || $status === 'EXCEPTION' || !is_array($result)) {
            $code = is_array($result) ? ($result['error_code'] ?? null) : null;
            if (is_array($result) && !empty($result['failure_kind'])) {
                $kind = $result['failure_kind'];
            } elseif ($status === 'EXCEPTION' || !is_array($result)) {
                $kind = 'internal';
            } else {
                $kind = ($code !== null && $code !== '' && is_numeric($code)) ? ksefFailureKind($code) : 'internal';
            }
            $_SESSION['ksef_failed_confirm'][$id] = array(
                'invoice_id' => $id,
                'error' => is_array($result) ? ($result['error'] ?? '') : ($ksef ? $ksef->error : ''),
                'error_code' => $code,
                'submission_id' => is_array($result) ? ($result['submission_id'] ?? null) : null,
                'kind' => $kind,
                'offline_row' => is_array($result) && !empty($result['offline_mode']),
                'rejected_offline' => is_array($result) && !empty($result['rejected_offline']),
                'context' => $context,
            );
            return $card . '&action=ksef_show_failure_dialog&token=' . newToken();
        }
        setEventMessages($langs->trans('KSEF_SubmissionStatus') . ': ' . ksefEscapeText($status !== '' ? $status : 'Unknown'), null, 'warnings');
        return $card;
    }

    /**
     * @brief Open module modal
     * @param string $id Element id
     * @param string $icon Font Awesome classes
     * @param string $tone ksefToneClass() tone
     * @param string $title HTML-safe title
     * @return void
     */
    private function printModalStart($id, $icon, $tone, $title)
    {
        print '<div id="' . $id . '" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
        print '<div class="ksef-modal-panel" style="padding: 30px; border-radius: 12px; max-width: 560px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';
        print '<div style="text-align: center; margin-bottom: 20px;">';
        print '<div class="ksef-modal-icon ' . ksefToneClass($tone) . '">';
        print '<i class="' . $icon . '"></i>';
        print '</div>';
        print '<h3 style="margin: 0; font-size: 20px;">' . $title . '</h3>';
        print '</div>';
    }

    /**
     * @brief Send failure dialog
     * @param Facture $object Invoice
     * @param array $fail Session data
     * @return void
     */
    private function printFailureDialog($object, $fail)
    {
        global $langs;
        $card = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
        $kind = !empty($fail['kind']) ? $fail['kind'] : 'internal';
        $offlineRow = !empty($fail['offline_row']);
        $rejectedOffline = !empty($fail['rejected_offline']);
        $code = trim((string) ($fail['error_code'] ?? ''));
        $isTransport = ($kind === 'transport');

        if ($isTransport && !$offlineRow) {
            $this->printModalStart('ksef-failure-modal', 'fa fa-wifi', 'check', $langs->trans('KSEF_ConnectionFailed'));
        } else {
            $this->printModalStart('ksef-failure-modal', 'fa fa-exclamation-circle', 'problem', $langs->trans($offlineRow ? 'KSEF_OnlineRetryFailedTitle' : 'KSEF_SubmissionFailed'));
        }

        print '<div class="ksef-failure-box" style="background: var(--ksef-surface-bad, #f8d7da); border: 1px solid var(--ksef-border-bad, #f5c6cb); padding: 15px; border-radius: 8px; margin-bottom: 15px;">';
        $showCode = $code !== '' && ctype_digit($code) && (!$isTransport || ksefRetryHint($code) !== '');
        if ($showCode) {
            print '<p class="' . ksefToneTextClass('problem') . '" style="margin: 0 0 6px 0; font-weight: bold;">' . ksefEscapeText($code . ': ' . ksefKsefCodeLabel($code)) . '</p>';
        }
        if ($isTransport && !$showCode) {
            print '<p class="' . ksefToneTextClass('problem') . '" style="margin: 0 0 6px 0;">' . $langs->trans('KSEF_ConnectionErrorExplanation') . '</p>';
        }
        if (trim((string) ($fail['error'] ?? '')) !== '') {
            if ($isTransport && !$showCode) {
                print '<details style="margin: 0; font-size: 12px; color: var(--ksef-text-muted, #555);"><summary>' . $langs->trans('KSEF_TechnicalDetails') . '</summary>' . ksefEscapeText(dol_trunc((string) $fail['error'], 300)) . '</details>';
            } else {
                $failText = ksefShortSubmissionError('', (string) $fail['error'], 300);
                if ($failText !== '') {
                    print '<p style="margin: 0; font-size: 13px; color: var(--ksef-text-muted, #555);">' . ksefEscapeText($failText) . '</p>';
                }
            }
        }
        print '</div>';

        $hint = ($code !== '' && ctype_digit($code)) ? ksefRetryHintText($code) : '';
        $dupNumber = '';
        if ((int) $code === 440) {
            dol_include_once('/ksef/class/ksef_submission.class.php');
            $dupSub = new KsefSubmission($this->db);
            if (!empty($fail['submission_id']) && $dupSub->fetch((int) $fail['submission_id']) > 0) {
                $dd = $dupSub->getErrorDetailsArray();
                $dupNumber = (string) $dupSub->getDuplicateOriginal()['ksef_number'];
                $hint = ksefDuplicateNextStep($dupNumber === '' ? 'no_original' : (string) ($dd['link_refused']['reason'] ?? ($dd['adopt'] ?? '')));
            }
        }
        if ($rejectedOffline) {
            $hint = $langs->transnoentitiesnoconv('KSEF_RetryHintTechnicalCorrection');
        }
        if ($hint === '' && $kind === 'local') {
            $hint = $langs->transnoentitiesnoconv('KSEF_RetryHintFix');
        }
        if ($hint !== '') {
            print '<p class="ksef-failure-hint" style="margin: 0 0 15px 0; color: var(--ksef-text-muted, #555);"><i class="fa fa-arrow-right"></i> ' . ksefEscapeText($hint) . '</p>';
        }

        $certOk = ksefIsOfflineCertificateConfigured();
        $offerOffline = $isTransport && !$offlineRow;
        if ($offerOffline) {
            print '<div class="ksef-offline-offer" style="background: ' . (!empty($certOk['valid']) ? 'var(--ksef-surface-good, #d4edda)' : 'var(--ksef-surface, #f1f1f1)') . '; border: 1px solid ' . (!empty($certOk['valid']) ? 'var(--ksef-border-good, #c3e6cb)' : 'var(--ksef-surface-border, #ddd)') . '; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
            print '<p style="margin: 0 0 8px 0; font-weight: bold;"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_OfflineOptionAvailable') . '</p>';
            if (!empty($certOk['valid'])) {
                print '<p style="margin: 0 0 8px 0; font-size: 13px;">' . $langs->trans('KSEF_OfflineOptionExplanation') . '</p>';
                print '<p style="margin: 0; font-size: 12px;"><strong>' . $langs->trans('KSEF_OfflineDeadline') . ':</strong> ' . ksefOfflineDeadlineText(ksefOfflineDeadlineInfo($object->date)) . '</p>';
            } else {
                print '<p style="margin: 0; font-size: 13px;">' . dol_escape_htmltag(ksefOfflineCertRefusalText('KSEF_OfflineNeedsCert', $certOk)) . '</p>';
            }
            print '</div>';
        }

        print '<div class="ksef-failure-actions" style="display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap; align-items: center;">';
        print '<a class="button" href="' . $card . '&action=ksef_dismiss_failure&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Close') . '</a>';
        if ($rejectedOffline) {
            print '<a class="button ' . ksefToneClass('check') . '" href="' . $card . '&action=ksef_technical_correction&submission_id=' . (int) ($fail['submission_id'] ?? 0) . '&token=' . newToken() . '" onclick="return (typeof ksefShowSpinner === \'function\') ? ksefShowSpinner(event, this) : true;" data-processing-text="' . $langs->trans('KSEF_SubmittingToKSEF') . '..." style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
            print '<i class="fas fa-tools"></i> ' . $langs->trans('KSEF_TechnicalCorrection') . '</a>';
        } elseif ($offlineRow) {
            print '<a class="button ' . ksefToneClass('check') . '" href="' . $card . '&action=ksef_retry_online&token=' . newToken() . '" onclick="return (typeof ksefShowSpinner === \'function\') ? ksefShowSpinner(event, this) : true;" data-processing-text="' . $langs->trans('KSEF_SubmittingToKSEF') . '..." style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
            print '<i class="fas fa-cloud-upload-alt"></i> ' . $langs->trans('KSEF_SubmitOnline') . '</a>';
        } elseif ((int) $code === 440) {
            if ($dupNumber !== '' && ksefUserCan($GLOBALS['user'], 'link_ksef_number')) {
                print '<a class="button ' . ksefToneClass('action') . '" href="' . $card . '&action=ksef_link_number&ksef_number=' . urlencode($dupNumber) . '&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
                print '<i class="fas fa-link"></i> ' . $langs->trans('KSEF_LinkKsefNumber') . '</a>';
            }
        } else {
            print '<a class="button ' . ksefToneClass('check') . '" href="' . $card . '&action=ksef_retry&token=' . newToken() . '" onclick="return (typeof ksefShowSpinner === \'function\') ? ksefShowSpinner(event, this) : true;" data-processing-text="' . $langs->trans('KSEF_RetryingSubmission') . '..." style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
            print '<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_TryAgain') . '</a>';
        }
        if ($offerOffline) {
            if (!empty($certOk['valid'])) {
                print '<a class="button button-save ' . ksefToneClass('problem') . '" href="' . $card . '&action=ksef_submit_offline_confirmed&token=' . newToken() . '" onclick="return (typeof ksefShowSpinner === \'function\') ? ksefShowSpinner(event, this) : true;" data-processing-text="' . $langs->trans('KSEF_CreatingOfflineInvoice') . '..." style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
                print '<i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</a>';
            } else {
                print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag(ksefOfflineCertRefusalText('KSEF_OfflineNeedsCert', $certOk)) . '"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</span>';
                if (!empty($GLOBALS['user']->admin)) {
                    print '<a class="button" href="' . dol_buildpath('/ksef/admin/setup_auth.php', 1) . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;"><i class="fa fa-cog"></i> ' . $langs->trans('KSEF_SetUpOfflineCert') . '</a>';
                }
            }
        }
        print '</div>';
        print '</div>';
        print '</div>';
    }

    /**
     * @brief Exception send result
     * @param \Throwable $e
     * @param KsefService|null $ksef
     * @return array
     */
    private function exceptionResult($e, $ksef = null)
    {
        dol_syslog("KSEF card send exception: " . get_class($e) . ': ' . $e->getMessage(), LOG_ERR);
        $r = array('status' => 'EXCEPTION', 'error' => $e->getMessage());
        if (is_object($ksef) && method_exists($ksef, 'lastFailureWasTransport') && $ksef->lastFailureWasTransport()) {
            $r['failure_kind'] = 'transport';
        }
        return $r;
    }

    /**
     * @brief Drop one-send choices
     * @param int $id Invoice id
     * @return void
     */
    private function clearSendAcks($id)
    {
        $id = (int) $id;
        unset($_SESSION['ksef_backdated_online'][$id], $_SESSION['ksef_accept_stored_rate'][$id], $_SESSION['ksef_deposit_ack'][$id], $_SESSION['ksef_resend_ack'][$id], $_SESSION['ksef_short_ack'][$id]);
    }

    /**
     * @brief Card send service
     * @param Facture $object Invoice
     * @return KsefService
     */
    private function newSendService($object)
    {
        $ksef = new KsefService($this->db);
        $ksef->acknowledgeDeposits = !empty($_SESSION['ksef_deposit_ack'][(int) $object->id]);
        $ksef->acknowledgeNegativeRates = $ksef->acknowledgeDeposits;
        return $ksef;
    }

    /**
     * @brief Print deposit notice
     * @param Facture $object Invoice
     * @param array $data Session data
     * @return void
     */
    private function printDepositDialog($object, $data)
    {
        global $langs;
        $card = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
        $ctx = ksefDepositContext($this->db, $object);
        $kinds = ksefDepositAckKinds($ctx);
        $ratesOnly = $kinds['rates'] && !$kinds['deposits'];
        $this->printModalStart('ksef-deposit-modal', 'fas fa-hand-holding-usd', 'check', $langs->trans($ratesOnly ? 'KSEF_SettlementRateNegativeTitle' : 'KSEF_DepositsAckTitle'));
        print '<div class="ksef-note-check" style="padding: 15px; border-radius: 8px; margin-bottom: 15px;">';
        print '<p style="margin: 0;">' . ksefDepositAckMessage($this->db, $object, $ctx, true) . '</p>';
        print '</div>';
        print '<p style="margin: 0 0 15px 0; font-size: 13px; color: var(--ksef-text-muted, #555);"><i class="fa fa-info-circle"></i> ' . $langs->trans($ratesOnly ? 'KSEF_SettlementRateNegativeHelp' : 'KSEF_DepositsAckHelp') . '</p>';
        print '<form method="POST" action="' . $card . '">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';
        print '<input type="hidden" name="action" value="ksef_accept_deposits">';
        print '<input type="hidden" name="next" value="' . dol_escape_htmltag((string) $data['next']) . '">';
        $toggle = 'onchange="var t = document.querySelectorAll(\'.ksef-deposits-tick\'), ok = true; for (var i = 0; i < t.length; i++) { ok = ok && t[i].checked; } document.getElementById(\'ksef-deposits-send\').disabled = !ok;"';
        if ($kinds['deposits'] || !$kinds['rates']) {
            print '<p><label><input type="checkbox" class="ksef-deposits-tick" name="ksef_deposits_ack" value="1" id="ksef_deposits_ack" ' . $toggle . '> '
                . $langs->trans('KSEF_DepositsAckTick') . '</label></p>';
        }
        if ($kinds['rates']) {
            print '<p><label><input type="checkbox" class="ksef-deposits-tick" name="ksef_rates_ack" value="1" id="ksef_rates_ack" ' . $toggle . '> '
                . $langs->trans('KSEF_SettlementRateNegativeTick', dol_escape_htmltag(ksefSettlementNegativeRatesText($ctx, ksefGetInvoiceCurrency($object)))) . '</label></p>';
        }
        print '<div style="display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap;">';
        print '<a class="button" href="' . $card . '&action=ksef_dismiss_send_dialog&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Cancel') . '</a>';
        print '<input type="submit" class="button button-save" id="ksef-deposits-send" disabled value="' . dol_escape_htmltag($langs->trans('KSEF_SendAnyway')) . '" style="padding: 10px 20px; border-radius: 6px;">';
        print '</div>';
        print '</form>';
        print '</div>';
        print '</div>';
    }

    /**
     * @brief Own Modify button
     * @param Facture $object Invoice
     * @return string HTML
     */
    private function ownModifyButton($object)
    {
        global $langs, $user;
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        $nbp = new KsefNbpCurrencyRate($this->db);
        $label = $langs->trans('Modify');
        $refused = function ($why) use ($label) {
            return dolGetButtonAction($label, '', 'default', '#', 'ksef-own-modify', false, array('attr' => array('class' => 'classfortooltip ksef-modify-refused', 'title' => $why)));
        };
        $parts = $nbp->rateChangeBlocked($object);
        if ($parts === false) {
            dol_syslog('ActionsKSEF::ownModifyButton cannot read the rules of invoice ' . (int) $object->id . ': ' . $this->db->lasterror(), LOG_ERR);
            return $refused($langs->transnoentitiesnoconv('Error') . ': ' . $this->db->lasterror());
        }
        // core greys Modify
        if (in_array('accounting', $parts, true) && !array_intersect($parts, array('never', 'closed'))) {
            return '';
        }
        // core lacks Modify
        if (array_intersect($parts, array('never', 'closed', 'payments', 'credits'))) {
            $kor = in_array('replaced', $parts, true) ? $nbp->rateChangeReplacement($object) : null;
            return $refused($langs->transnoentitiesnoconv('KSEF_ModifyRefusedCore', $nbp->rateChangeBookedParts($parts, $kor)));
        }
        // core greys Modify
        if (!empty($parts)) {
            return '';
        }
        // core Modify active
        if (!$object->getIdReplacingInvoice() && $object->is_last_in_cycle()) {
            return '';
        }
        $usercanunvalidate = (!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('facture', 'creer'))
            || (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('facture', 'invoice_advance', 'unvalidate'));
        $out = $usercanunvalidate
            ? dolGetButtonAction($label, '', 'default', $_SERVER['PHP_SELF'] . '?facid=' . (int) $object->id . '&action=modif&token=' . newToken(), 'ksef-own-modify', true)
            : $refused($langs->transnoentitiesnoconv('NotEnoughPermissions'));
        // drop greyed twin
        $out .= '<script>jQuery(function($) { $("div.tabsAction span.butActionRefused").not("#ksef-own-modify, .ksef-modif-refused").filter(function() { return $.trim($(this).text()) === '
            . json_encode($langs->transnoentitiesnoconv('Modify'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . '; }).remove(); });</script>';
        return $out;
    }

    /**
     * @brief Stale rate dialog
     * @param Facture $object Invoice
     * @param array $data Session data
     * @return void
     */
    private function printRateDialog($object, $data)
    {
        global $langs;
        $card = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
        $next = urlencode((string) $data['next']);
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        $nbp = new KsefNbpCurrencyRate($this->db);
        $refusal = $nbp->rateFetchRefusal($object);
        $booked = !empty($refusal['parts']);
        $this->printModalStart('ksef-rate-modal', 'fas fa-coins', 'check', $langs->trans('KSEF_NBPRateStaleTitle'));
        print '<div class="ksef-note-check" style="padding: 15px; border-radius: 8px; margin-bottom: 15px;">';
        if ($booked) {
            print '<p style="margin: 0;">' . $nbp->rateChangeBookedMessage(!empty($data['strict']) ? 'KSEF_NBPRateStaleBooked' : 'KSEF_NBPRateStaleChoiceBooked', $object, $refusal['parts'],
                array($data['stored'], $data['reference']), 'html') . '</p>';
        } else {
            print '<p style="margin: 0;">' . $langs->trans(!empty($data['strict']) ? 'KSEF_NBPRateStale' : 'KSEF_NBPRateStaleChoice', dol_escape_htmltag($data['stored']), dol_escape_htmltag($data['reference'])) . '</p>';
        }
        print '</div>';
        if (!$booked) {
            print '<p style="margin: 0 0 20px 0; font-size: 13px; color: var(--ksef-text-muted, #555);"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_NBPRefetchChangesAmounts') . '</p>';
        }
        print '<div style="display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap;">';
        print '<a class="button" href="' . $card . '&action=ksef_dismiss_send_dialog&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Cancel') . '</a>';
        if (empty($data['strict'])) {
            print '<a class="button" href="' . $card . '&action=ksef_accept_stored_rate&next=' . $next . '&token=' . newToken() . '"' . $this->spinnerLinkAttrs('KSEF_SubmittingToKSEF') . ' style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('KSEF_SendWithStoredRate') . '</a>';
        }
        if (!$booked) {
            print '<a class="button button-save ' . ksefToneClass('action') . '" href="' . $card . '&action=ksef_fetch_nbp_rate&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;"><i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_RefetchNBPRate') . '</a>';
        }
        print '</div>';
        print '</div>';
        print '</div>';
    }

    /**
     * @brief Print backdated dialog
     * @param Facture $object Invoice
     * @param array $data Session data
     * @return void
     */
    private function printBackdatedDialog($object, $data)
    {
        global $langs;
        $card = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
        $next = urlencode((string) $data['next']);
        $canKeep = false;
        $this->printModalStart('ksef-backdated-modal', 'fas fa-calendar-alt', 'check', $langs->trans('KSEF_OfflineBackdatedTitle'));
        print '<div class="ksef-note-check" style="padding: 15px; border-radius: 8px; margin-bottom: 15px;">';
        $cert = ksefIsOfflineCertificateConfigured();
        $one = (int) $data['days_behind'] === 1;
        if (ksefOfflineCertExpired($cert)) {
            $day = dol_print_date((int) $cert['valid_to'], 'day');
            $msg = $one ? $langs->trans('KSEF_BackdatedCertExpiredMessageOne', $day) : $langs->trans('KSEF_BackdatedCertExpiredMessage', (int) $data['days_behind'], $day);
        } else {
            $msg = $langs->trans($one ? 'KSEF_BackdatedNoCertMessageOne' : 'KSEF_BackdatedNoCertMessage', (int) $data['days_behind']);
        }
        print '<p style="margin: 0 0 8px 0;">' . $msg . '</p>';
        print '<p style="margin: 0; font-size: 13px;">' . $langs->trans('KSEF_BackdatedOnlineNote') . '</p>';
        if (!empty($data['is_draft'])) {
            if (empty($object->array_options)) {
                $object->fetch_optionals();
            }
            $saleSet = !empty($object->array_options['options_ksef_sale_date']);
            $canKeep = ksefCanKeepSaleDate($object);
            if ($canKeep) {
                print '<p class="ksef-keep-sale-date" style="margin: 8px 0 0 0; font-size: 13px;"><label><input type="checkbox" id="ksef-keep-sale-date" value="1" checked'
                    . ' onchange="var a = document.getElementById(\'ksef-set-date-today\'); a.href = a.getAttribute(\'data-href\') + (this.checked ? \'&ksef_keep_sale_date=1\' : \'\');'
                    . ' document.getElementById(\'ksef-sale-date-follows\').style.display = this.checked ? \'none\' : \'\';"> '
                    . $langs->trans('KSEF_KeepSaleDate', dol_print_date($object->date, 'day')) . '</label></p>';
            }
            print '<p class="ksef-sale-date-note" id="ksef-sale-date-follows" style="margin: 8px 0 0 0; font-size: 13px;' . ($canKeep ? ' display: none;' : '') . '"><i class="far fa-calendar-alt"></i> '
                . ($saleSet ? $langs->trans('KSEF_SaleDateStays', dol_print_date(ksefGetSaleDate($object), 'day')) : $langs->trans('KSEF_SaleDateFollows')) . '</p>';
        }
        print '</div>';
        print '<div style="display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap; align-items: center;">';
        print '<a class="button" href="' . $card . '&action=ksef_dismiss_send_dialog&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Cancel') . '</a>';
        print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag(ksefOfflineCertRefusalText('KSEF_OfflineNeedsCert', $cert)) . '"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</span>';
        if (!empty($GLOBALS['user']->admin)) {
            print '<a class="button" href="' . dol_buildpath('/ksef/admin/setup_auth.php', 1) . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;"><i class="fa fa-cog"></i> ' . $langs->trans('KSEF_SetUpOfflineCert') . '</a>';
        }
        if (!empty($data['is_draft'])) {
            $redateUrl = $card . '&action=ksef_set_date_today&next=' . $next . '&token=' . newToken();
            print '<a class="button" id="ksef-set-date-today" data-href="' . $redateUrl . '" href="' . $redateUrl . ($canKeep ? '&ksef_keep_sale_date=1' : '') . '"' . $this->spinnerLinkAttrs('KSEF_SubmittingToKSEF') . ' style="padding: 10px 20px; text-decoration: none; border-radius: 6px;"><i class="fas fa-calendar-day"></i> ' . $langs->trans('KSEF_SetDateToday') . '</a>';
        }
        print '<a class="button button-save ' . ksefToneClass('action') . '" href="' . $card . '&action=ksef_backdated_online&next=' . $next . '&token=' . newToken() . '"' . $this->spinnerLinkAttrs('KSEF_SubmittingToKSEF') . ' style="padding: 10px 20px; text-decoration: none; border-radius: 6px;"><i class="fa fa-paper-plane"></i> ' . $langs->trans('KSEF_SendOnline') . '</a>';
        print '</div>';
        print '</div>';
        print '</div>';
    }

    /**
     * @brief DodatkowyOpis modes
     * @param object $object Invoice
     * @return array
     */
    private function ksefComputeDodatkowyOpisContext($object)
    {
        dol_include_once('/ksef/class/fa3_builder.class.php');
        $noteMode = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
        $efConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS', '');
        $efCount = !empty($efConf) ? count(array_filter(array_map('trim', explode(',', $efConf)))) : 0;
        $detConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', '');
        if (!empty($detConf)) $efCount += count(array_filter(array_map('trim', explode(',', $detConf))));
        $prodConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', '');
        if (!empty($prodConf)) $efCount += count(array_filter(array_map('trim', explode(',', $prodConf))));
        $socConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', '');
        if (!empty($socConf)) $efCount += count(array_filter(array_map('trim', explode(',', $socConf))));
        $projConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', '');
        if (!empty($projConf)) $efCount += count(array_filter(array_map('trim', explode(',', $projConf))));
        $dodOverride = FA3Builder::getDodatkowyOpisOverride($object);

        $effectiveNoteMode = $noteMode;
        $effectiveEfCount = $efCount;
        $effectiveNoteConfigured = ($noteMode !== 'disabled');
        $effectiveEfConfigured = ($efCount > 0);

        if ($dodOverride === 'disabled') {
            $effectiveNoteMode = 'disabled';
            $effectiveEfCount = 0;
            $effectiveNoteConfigured = false;
            $effectiveEfConfigured = false;
        } elseif (in_array($dodOverride, array('simple_stopka', 'simple_dodatkowy', 'keyvalue_dodatkowy'))) {
            $parsed = FA3Builder::parseCombinedNoteMode($dodOverride);
            $effectiveNoteMode = $parsed ? $parsed['mode'] : $noteMode;
            $effectiveNoteConfigured = true;
        }

        return array(
            'noteMode' => $noteMode,
            'efCount' => $efCount,
            'effectiveNoteMode' => $effectiveNoteMode,
            'effectiveEfCount' => $effectiveEfCount,
            'noteConfigured' => $effectiveNoteConfigured,
            'efConfigured' => $effectiveEfConfigured,
            'dodOverride' => $dodOverride,
            'featureActive' => ($noteMode !== 'disabled') || ($efCount > 0) || in_array($dodOverride, array('simple_stopka', 'simple_dodatkowy', 'keyvalue_dodatkowy', 'disabled')),
        );
    }

    /**
     * @brief Notes preview HTML
     * @param object $object Invoice
     * @param bool $collapsible Wrap in details
     * @return string
     */
    private function ksefRenderDodatkowyOpisPreviewInner($object, $collapsible = true)
    {
        global $conf, $db, $langs;
        dol_include_once('/ksef/class/fa3_builder.class.php');

        $ctx = $this->ksefComputeDodatkowyOpisContext($object);
        $dodEntries = FA3Builder::parseDodatkowyOpisPreview($object, $conf, $db);

        $noteEntries = array();
        $efEntries = array();
        foreach ($dodEntries as $entry) {
            if (isset($entry['source']) && strpos($entry['source'], 'extrafield') === 0) {
                $efEntries[] = $entry;
            } else {
                $noteEntries[] = $entry;
            }
        }

        $overrideBadge = '';
        if (!empty($ctx['dodOverride'])) {
            $overrideLabel = '';
            if ($ctx['dodOverride'] === 'simple_stopka') $overrideLabel = $langs->transnoentities('KSEF_DodatkowyOpisMode_SimpleStopka');
            elseif ($ctx['dodOverride'] === 'simple_dodatkowy') $overrideLabel = $langs->transnoentities('KSEF_DodatkowyOpisMode_SimpleDodatkowy');
            elseif ($ctx['dodOverride'] === 'keyvalue_dodatkowy') $overrideLabel = $langs->transnoentities('KSEF_DodatkowyOpisMode_KeyValueDodatkowy');
            elseif ($ctx['dodOverride'] === 'disabled') $overrideLabel = $langs->transnoentities('KSEF_DodatkowyOpisMode_Disabled');
            if ($overrideLabel !== '') {
                $overrideBadge = ' <span class="badge badge-warning" style="margin-left:6px;">' . dol_escape_htmltag($langs->transnoentities('KSEF_DODATKOWY_OPIS_PREVIEW_OVERRIDDEN', $overrideLabel)) . '</span>';
            }
        }

        if ($collapsible) {
            $html = '<details' . (!empty($dodEntries) ? ' open' : '') . '>';
            $html .= '<summary style="cursor:pointer;">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_ENTRIES") . $overrideBadge . '</summary>';
        } else {
            $html = '<div>';
            if ($overrideBadge !== '') {
                $html .= '<div style="margin-bottom:4px;">' . $overrideBadge . '</div>';
            }
        }

        $hasNrWiersza = false;
        foreach ($dodEntries as $entry) {
            if (!empty($entry['nr_wiersza'])) {
                $hasNrWiersza = true;
                break;
            }
        }

        $sections = array(
            array('title' => $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_FROM_NOTE"), 'entries' => $noteEntries, 'configured' => $ctx['noteConfigured']),
            array('title' => $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_FROM_EXTRAFIELDS"), 'entries' => $efEntries, 'configured' => $ctx['efConfigured']),
        );

        foreach ($sections as $sec) {
            if (!$sec['configured'] && empty($sec['entries'])) continue;
            $html .= '<h5 style="margin:8px 0 4px 0; font-size:12px; text-transform:uppercase; color: var(--ksef-text-muted);">' . $sec['title'] . '</h5>';
            if (empty($sec['entries'])) {
                $html .= '<div class="opacitymedium" style="margin-bottom:4px;">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_EMPTY_SECTION") . '</div>';
                continue;
            }
            $html .= '<table class="noborder" style="margin-bottom:4px; width:100%; table-layout:fixed;">';
            $html .= '<tr class="liste_titre">';
            if ($hasNrWiersza) {
                $html .= '<td style="width:60px;">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_NRWIERSZA") . '</td>';
            }
            $html .= '<td style="width:30%;">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_KEY") . '</td><td>' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_VALUE") . '</td></tr>';
            foreach ($sec['entries'] as $entry) {
                $dispKey = dol_escape_htmltag($entry['key']);
                $dispVal = dol_escape_htmltag($entry['value']);
                $html .= '<tr class="oddeven">';
                if ($hasNrWiersza) {
                    $html .= '<td style="text-align:center; vertical-align:top;">' . (!empty($entry['nr_wiersza']) ? (int) $entry['nr_wiersza'] : '') . '</td>';
                }
                $html .= '<td style="word-break:break-word; vertical-align:top;">' . $dispKey . '</td><td style="word-break:break-word; white-space:pre-wrap;">' . $dispVal . '</td></tr>';
            }
            $html .= '</table>';
        }

        if (!$ctx['noteConfigured'] && !$ctx['efConfigured']) {
            $html .= '<div class="opacitymedium">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_EMPTY_SECTION") . '</div>';
        }

        $html .= $collapsible ? '</details>' : '</div>';
        return $html;
    }

    /**
     * @brief Live helper script
     * @param object $object Invoice
     * @return string
     */
    private function ksefRenderDodatkowyOpisHelperScript($object)
    {
        global $langs;
        $ctx = $this->ksefComputeDodatkowyOpisContext($object);

        $hintHtml = '';
        if ($ctx['effectiveNoteMode'] === 'simple') {
            $hintHtml = $langs->transnoentities("KSEF_DODATKOWY_OPIS_HINT_SIMPLE");
        } elseif ($ctx['effectiveNoteMode'] === 'keyvalue') {
            $hintHtml = $langs->transnoentities("KSEF_DODATKOWY_OPIS_HINT_KEYVALUE");
        }
        if ($ctx['effectiveEfCount'] > 0) {
            $hintHtml .= '<br>' . $langs->transnoentities("KSEF_DODATKOWY_OPIS_HINT_EXTRAFIELDS", $ctx['effectiveEfCount']);
        }

        $noEntriesLabel = addslashes($langs->transnoentities("KSEF_DODATKOWY_OPIS_NOTE_NO_ENTRIES"));
        $truncatedLabel = addslashes($langs->transnoentities("KSEF_DODATKOWY_OPIS_NOTE_TRUNCATED"));

        return '<script type="text/javascript">
jQuery(document).ready(function() {
    var noteMode = ' . json_encode($ctx['effectiveNoteMode']) . ';
    if (noteMode === "disabled") return;

    function findTextarea() {
        return jQuery("textarea[name=\'note_public\']").first();
    }
    function insertHelper() {
        var ta = findTextarea();
        if (!ta.length || jQuery("#ksef_note_helper").length) return false;
        var ckeWrapper = ta.parent().find(".cke_chrome, .cke").first();
        var anchor = ckeWrapper.length ? ckeWrapper : ta;
        anchor.after(
            \'<div id="ksef_note_helper" style="font-size:11px; color: var(--ksef-text-muted); margin-top:4px; padding:6px 8px; background: var(--ksef-surface); border-left:3px solid #6c757d;">\' +
            \'<div style="margin-bottom:4px;">' . addslashes($hintHtml) . '</div>\' +
            \'<div id="ksef_note_counter"></div>\' +
            \'</div>\'
        );
        return true;
    }
    function getText() {
        if (typeof CKEDITOR !== "undefined" && CKEDITOR.instances && CKEDITOR.instances.note_public) {
            return CKEDITOR.instances.note_public.getData() || "";
        }
        var ta = findTextarea();
        return ta.length ? (ta.val() || "") : "";
    }
    function escapeHtml(s) {
        return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
    function parseEntries(stripped) {
        var entries = [];
        if (noteMode === "simple") {
            var totalLen = stripped.length;
            if (totalLen === 0) return entries;
            if (totalLen <= 256) {
                entries.push({key: "Uwagi", value: stripped, len: totalLen});
            } else {
                var chunkNum = 0;
                for (var offset = 0; offset < totalLen; offset += 256) {
                    chunkNum++;
                    var end = Math.min(offset + 256, totalLen);
                    var label = chunkNum === 1 ? "Uwagi" : "Uwagi " + chunkNum;
                    entries.push({key: label, value: stripped.substring(offset, end), len: end - offset});
                }
            }
        } else if (noteMode === "keyvalue") {
            stripped.split("\\n").forEach(function(line) {
                line = line.trim();
                if (!line) return;
                var idx = line.indexOf(":");
                if (idx <= 0) return;
                var key = line.substring(0, idx).trim();
                var value = line.substring(idx + 1).trim();
                if (!key || !value) return;
                entries.push({key: key, value: value, len: value.length});
            });
        }
        return entries;
    }
    function updateCounter() {
        var el = jQuery("#ksef_note_counter");
        if (!el.length) return;
        var text = getText();
        var stripped = text.replace(/<br\s*\/?>/gi, "\\n").replace(/<\\/(p|div)>/gi, "\\n").replace(/<[^>]+>/g, "").trim();
        var entries = parseEntries(stripped);
        if (entries.length === 0) {
            el.html(\'<span class="opacitymedium">' . $noEntriesLabel . '</span>\');
            return;
        }
        var rowStyle = "display:flex; align-items:baseline; gap:8px; margin-bottom:2px;";
        var valStyle = "flex:1; min-width:0; font-style:italic; color:#333; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;";
        var badgeStyle = "flex:none; padding:1px 6px; border-radius:3px; font-family:monospace; font-size:11px; background:#e9ecef; color:#495057;";
        var badgeWarnStyle = "flex:none; padding:1px 6px; border-radius:3px; font-family:monospace; font-size:11px;";
        var html = entries.map(function(e) {
            var truncated = e.len > 256;
            var displayLen = truncated ? 256 : e.len;
            var badge = truncated ? badgeWarnStyle : badgeStyle;
            var suffix = truncated ? \' <em>(' . $truncatedLabel . ')</em>\' : "";
            return \'<div style="\' + rowStyle + \'">\' +
                \'<b>\' + escapeHtml(e.key) + \'</b>\' +
                \'<span style="\' + valStyle + \'">"\' + escapeHtml(e.value) + \'"</span>\' +
                \'<span\' + (truncated ? \' class="ksef-note-check ' . ksefToneTextClass('check') . '"\' : "") + \' style="\' + badge + \'">\' + displayLen + \'/256</span>\' +
                suffix +
                \'</div>\';
        }).join("");
        el.html(html);
    }

    if (!insertHelper()) {
        var retry = setInterval(function() {
            if (insertHelper()) { clearInterval(retry); updateCounter(); }
        }, 500);
        setTimeout(function() { clearInterval(retry); }, 10000);
    } else {
        updateCounter();
    }
    var counterInterval = setInterval(updateCounter, 500);
    jQuery(window).on("beforeunload", function() { clearInterval(counterInterval); });
    jQuery(document).on("input change", "textarea[name=\'note_public\']", updateCounter);
});
</script>';
    }

    /**
     * @brief Render GUS button
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    public function tabContentCreateThirdparty($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->inContexts($parameters)) {
            return 0;
        }
        $this->printGusLookupWidget($object);
        return 0;
    }

    /**
     * @brief Render GUS button
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    public function tabContentEditThirdparty($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->inContexts($parameters)) {
            return 0;
        }
        $this->printGusLookupWidget($object);
        return 0;
    }

    /**
     * @brief Print GUS button
     * @param $object Third party
     * @return void
     */
    private function printGusLookupWidget(&$object)
    {
        global $langs, $db, $conf;

        if (!isModEnabled('ksef') || empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
            return;
        }

        $countryCode = (is_object($object) && !empty($object->country_code)) ? $object->country_code : '';
        if ($countryCode !== '' && $countryCode !== 'PL') {
            return;
        }

        dol_include_once('/ksef/lib/ksef.lib.php');
        $langs->loadLangs(array('ksef@ksef'));

        $nipSelector = ksefGusDomFieldId('NIP');
        if (empty($nipSelector)) {
            return;
        }

        $ready = ksefTranslationOverridesReady($db);
        $setupUrl = dol_buildpath('/ksef/admin/setup_dictionaries.php', 1) . '#trans_lang_pl_PL';

        print '<span id="ksef_gus_widget" style="margin-left: 8px; white-space: nowrap;">';
        if ($ready) {
            print '<button type="button" id="ksef_gus_fetch" class="button smallpaddingimp">'
                . '<span class="fa fa-download"></span> ' . $langs->trans('KSEF_GusFetch') . '</button>';
            print ' <span id="ksef_gus_status" class="opacitymedium"></span>';
        } else {
            print '<button type="button" class="button smallpaddingimp" disabled title="' . dol_escape_htmltag($langs->trans('KSEF_GusSetupRequired')) . '">'
                . '<span class="fa fa-download"></span> ' . $langs->trans('KSEF_GusFetch') . '</button>';
            print ' <a href="' . $setupUrl . '">' . $langs->trans('KSEF_GusRunSetup') . '</a>';
        }
        print '</span>';
        if ($ready) {
            print '<div id="ksef_gus_conflicts" style="display: none;"></div>';
        }

        if (!$ready) {
            print '<script type="text/javascript">jQuery(function($) { var $n = $("' . dol_escape_js($nipSelector) . '"); if ($n.length) { $n.after($("#ksef_gus_widget")); } });</script>';
            return;
        }

        $cfg = array(
            'nipSelector' => $nipSelector,
        );
        $msg = array(
            'fetching'     => 'KSEF_GusFetching',
            'filled'       => 'KSEF_GusFilled',
            'noChanges'    => 'KSEF_GusNoChanges',
            'conflictTitle' => 'KSEF_GusConflictTitle',
            'apply'        => 'KSEF_GusApply',
            'dismiss'      => 'KSEF_GusDismiss',
            'selectAll'    => 'KSEF_GusSelectAll',
            'invalidNip'   => 'KSEF_GusErrInvalidNip',
            'nipCancelled' => 'KSEF_GusNipCancelled',
            'inactive'     => 'KSEF_GusInactiveWarn',
        );
        $errMap = array(
            'forbidden'        => 'KSEF_GusErrForbidden',
            'disabled'         => 'KSEF_GusErrDisabled',
            'setup_required'   => 'KSEF_GusErrSetupRequired',
            'invalid_nip'      => 'KSEF_GusErrInvalidNip',
            'no_key'           => 'KSEF_GusErrNoKey',
            'rate_limited'     => 'KSEF_GusErrRateLimited',
            'not_found'        => 'KSEF_GusErrNotFound',
            'unsupported_type' => 'KSEF_GusErrUnsupportedType',
            'session_error'    => 'KSEF_GusErrSession',
            'auth_failed'      => 'KSEF_GusErrAuthFailed',
            'transport_error'  => 'KSEF_GusErrTransport',
            'http_error'       => 'KSEF_GusErrTransport',
            'parse_error'      => 'KSEF_GusErrTransport',
            'error'            => 'KSEF_GusError',
        );
        $errText = array();
        foreach ($errMap as $code => $key) {
            $errText[$code] = in_array($code, array('no_key', 'auth_failed', 'session_error', 'transport_error', 'http_error', 'parse_error'), true)
                ? ksefGusErrorText($langs, $code) : $langs->transnoentitiesnoconv($key, '%s');
        }

        // JS: no entities
        $cfgJson = json_encode($cfg, JSON_HEX_APOS | JSON_HEX_TAG);
        $msgJson = ksefJsLangs($msg);
        $errJson = json_encode($errText, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        print '<script type="text/javascript">';
        print 'jQuery(function($) {';
        print 'var cfg = ' . $cfgJson . ';';
        print 'var MSG = ' . $msgJson . ';';
        print 'var ERR = ' . $errJson . ';';
        print 'var $btn = $("#ksef_gus_fetch");';
        print 'if (!$btn.length) return;';
        print 'var $nipField = $(cfg.nipSelector);';
        print 'if ($nipField.length) {';
        print '$nipField.after($("#ksef_gus_widget"));';
        print 'var $cr = $("<tr class=\'ksef-gus-conflict-row\'><td colspan=\'4\'></td></tr>");';
        print '$cr.children("td").append($("#ksef_gus_conflicts"));';
        print '$nipField.closest("tr").after($cr);';
        print '}';
        // card.php rolls token
        print 'function token() { return $("meta[name=\'anti-csrf-newtoken\']").attr("content") || ""; }';
        print 'function setStatus(t) { $("#ksef_gus_status").text(t || ""); }';
        print 'function highlight($el) { $el.css("background-color", "#e6ffe6"); }';
        print 'function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }';
        print 'function renderConflicts(list) {';
        print 'var h = "<table class=\'noborder centpercent\'>";';
        print 'h += "<tr class=\'liste_titre\'><td colspan=\'3\'>" + esc(MSG.conflictTitle) + "</td></tr>";';
        print '$.each(list, function(i, f) {';
        print 'h += "<tr class=\'oddeven\'><td class=\'center\' style=\'width:24px;\'><input type=\'checkbox\' class=\'ksef-gus-cf\' data-idx=\'" + i + "\'></td>";';
        print 'h += "<td><strong>" + esc(f.label) + "</strong></td>";';
        print 'h += "<td><span class=\'opacitymedium\'>" + esc($.trim($(f.selector).val() || "")) + "</span> &rarr; " + esc(f.value) + "</td></tr>";';
        print '});';
        print 'h += "<tr class=\'oddeven\'><td colspan=\'3\'><label><input type=\'checkbox\' id=\'ksef_gus_all\'> " + esc(MSG.selectAll) + "</label> ";';
        print 'h += "<button type=\'button\' class=\'button smallpaddingimp\' id=\'ksef_gus_apply\'>" + esc(MSG.apply) + "</button> ";';
        print 'h += "<button type=\'button\' class=\'button smallpaddingimp button-cancel\' id=\'ksef_gus_dismiss\'>" + esc(MSG.dismiss) + "</button></td></tr>";';
        print 'h += "</table>";';
        print 'var $box = $("#ksef_gus_conflicts"); $box.html(h).show(); $box.data("conflicts", list);';
        print '}';
        print '$btn.on("click", function() {';
        print 'var nip = ($(cfg.nipSelector).val() || "").replace(/[^0-9]/g, "");';
        print '$("#ksef_gus_conflicts").hide().empty();';
        print 'if (nip.length < 10) { setStatus(""); alert(MSG.invalidNip); return; }';
        print 'setStatus(MSG.fetching);';
        print '$.ajax({ type: "POST", url: window.location.pathname, dataType: "json", data: { action: "ksef_gus_lookup", nip: nip, token: token() } }).done(function(resp) {';
        print 'if (!resp || !resp.ok) { setStatus(""); var code = (resp && resp.error) ? resp.error : "error"; alert((ERR[code] || ERR.error).replace("%s", nip)); return; }';
        print 'var filled = 0, conflicts = [];';
        print '$.each(resp.fields, function(i, f) {';
        print 'var $el = $(f.selector); if (!$el.length) return;';
        print 'var cur = $.trim($el.val() || "");';
        print 'if (cur === "") { $el.val(f.value); highlight($el); filled++; }';
        print 'else if (cur !== $.trim(f.value)) { conflicts.push(f); }';
        print '});';
        print 'var notes = [];';
        print 'if (resp.meta && resp.meta.status_nip) { notes.push(MSG.nipCancelled.replace("%s", resp.meta.status_nip)); }';
        print 'if (resp.meta && resp.meta.active === false) { notes.push(MSG.inactive); }';
        print 'var summary = (!filled && !conflicts.length) ? MSG.noChanges : MSG.filled.replace("%s", filled);';
        print 'setStatus(summary + (notes.length ? " " + notes.join(" ") : ""));';
        print 'if (conflicts.length) { renderConflicts(conflicts); }';
        print '}).fail(function() { setStatus(""); alert(ERR.transport_error); });';
        print '});';
        print '$("#ksef_gus_conflicts").on("change", "#ksef_gus_all", function() { $(".ksef-gus-cf").prop("checked", $(this).prop("checked")); });';
        print '$("#ksef_gus_conflicts").on("click", "#ksef_gus_apply", function() {';
        print 'var list = $("#ksef_gus_conflicts").data("conflicts") || []; var n = 0;';
        print '$(".ksef-gus-cf:checked").each(function() { var f = list[$(this).data("idx")]; if (f) { var $el = $(f.selector); if ($el.length) { $el.val(f.value); highlight($el); n++; } } });';
        print '$("#ksef_gus_conflicts").hide().empty(); setStatus(MSG.filled.replace("%s", n));';
        print '});';
        print '$("#ksef_gus_conflicts").on("click", "#ksef_gus_dismiss", function() { $("#ksef_gus_conflicts").hide().empty(); });';
        print '});';
        print '</script>';
    }

    /**
     * @brief Handle GUS lookup
     * @return void
     */
    private function handleGusLookup()
    {
        global $db, $user, $langs;

        dol_include_once('/ksef/class/ksef_gus_client.class.php');
        $langs->loadLangs(array('ksef@ksef', 'companies'));

        if (!$user->hasRight('societe', 'creer')) {
            $this->gusJsonError('forbidden');
        }
        if (empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
            $this->gusJsonError('disabled');
        }
        if (!ksefTranslationOverridesReady($db)) {
            $this->gusJsonError('setup_required');
        }

        $nip = ksefCleanNIP(GETPOST('nip', 'alphanohtml', 2));
        if (empty($nip)) {
            $this->gusJsonError('invalid_nip');
        }

        $now = dol_now();
        $gusEnv = getDolGlobalString('KSEF_GUS_ENV', 'TEST');
        $cacheKey = $gusEnv . ':' . getDolGlobalString('KSEF_GUS_NAME_STYLE', 'full') . ':' . $nip;

        // cache before limit
        $gusCache = ksefSessionRead('KSEF_GUS_CACHE', array());
        if (!is_array($gusCache)) {
            $gusCache = array();
        }

        if (isset($gusCache[$cacheKey]) && $gusCache[$cacheKey]['ts'] > $now - 3600) {
            $data = $gusCache[$cacheKey]['data'];
        } else {
            $gusCalls = ksefSessionRead('KSEF_GUS_CALLS', array());
            if (!is_array($gusCalls)) {
                $gusCalls = array();
            }
            $recent = array();
            foreach ($gusCalls as $ts) {
                if ($ts > $now - 60) {
                    $recent[] = $ts;
                }
            }
            if (!empty($recent) && ($now - max($recent)) < 2) {
                $this->gusJsonError('rate_limited');
            }
            if (count($recent) >= 20) {
                $this->gusJsonError('rate_limited');
            }
            $recent[] = $now;
            ksefSessionWrite('KSEF_GUS_CALLS', $recent);

            $client = new KsefGusClient($db, $gusEnv);
            $data = $client->lookupByNip($nip);
            if ($data === false) {
                $this->gusJsonError($client->error ? $client->error : 'error');
            }

            $gusCache[$cacheKey] = array('ts' => $now, 'data' => $data);
            if (count($gusCache) > 50) {
                $gusCache = array_slice($gusCache, -50, null, true);
            }
            ksefSessionWrite('KSEF_GUS_CACHE', $gusCache);
        }

        $targets = array();
        $this->gusAddTarget($targets, '#name', $data['name'], $langs->transnoentitiesnoconv('Name'));
        $this->gusAddTarget($targets, '#name_alias_input', $data['name_short'], $langs->transnoentitiesnoconv('AliasNames'));
        $this->gusAddTarget($targets, '#address', $data['address'], $langs->transnoentitiesnoconv('Address'));
        $this->gusAddTarget($targets, '#zipcode', $data['zip'], $langs->transnoentitiesnoconv('Zip'));
        $this->gusAddTarget($targets, '#town', $data['town'], $langs->transnoentitiesnoconv('Town'));
        $this->gusAddTarget($targets, '#phone', $data['phone'], $langs->transnoentitiesnoconv('Phone'));
        $this->gusAddTarget($targets, '#email', $data['email'], $langs->transnoentitiesnoconv('Email'));
        $this->gusAddTarget($targets, '#url', $data['url'], $langs->transnoentitiesnoconv('Web'));

        $nipSelector = ksefGusDomFieldId('NIP');
        if ($nipSelector === '#intra_vat') {
            $this->gusAddTarget($targets, '#intra_vat', 'PL' . $data['nip'], 'VAT/NIP');
        } else {
            $this->gusAddTarget($targets, $nipSelector, $data['nip'], 'NIP');
            $this->gusAddTarget($targets, '#intra_vat', 'PL' . $data['nip'], 'VAT');
        }
        $this->gusAddTarget($targets, ksefGusDomFieldId('REGON'), $data['regon'], 'REGON');
        $this->gusAddTarget($targets, ksefGusDomFieldId('KRS'), $data['krs'], 'KRS');

        top_httphead('application/json');
        echo json_encode(array(
            'ok' => 1,
            'fields' => $targets,
            'meta' => array(
                'typ' => $data['typ'],
                'status_nip' => $data['status_nip'],
                'active' => $data['active'],
            ),
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @brief Add fill target
     * @param array $list
     * @param $selector jQuery selector
     * @param $value
     * @param $label
     * @return void
     */
    private function gusAddTarget(&$list, $selector, $value, $label)
    {
        if (empty($selector) || $value === '' || $value === null) {
            return;
        }
        foreach ($list as $existing) {
            if ($existing['selector'] === $selector) {
                return;
            }
        }
        $list[] = array('selector' => $selector, 'value' => $value, 'label' => $label);
    }

    /**
     * @brief JSON error exit
     * @param $code
     * @return void
     */
    private function gusJsonError($code)
    {
        top_httphead('application/json');
        echo json_encode(array('ok' => 0, 'error' => $code));
        exit;
    }

    /**
     * @brief Can edit invoice
     * @param Facture|FactureFournisseur $object Invoice
     * @return bool
     */
    private function canEditInvoice($object)
    {
        global $user;
        return $object->element === 'facture' ? $user->hasRight('facture', 'creer') : ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
    }

    /**
     * @brief Print set-off dates
     * @param Facture|FactureFournisseur $object Invoice
     * @param string $action
     * @return void
     */
    private function printSetoffDates($object, $action)
    {
        global $db, $langs;
        $k = ksefInvoiceKind($object);
        $uses = array_filter((array) ksefExcessUseDates($db, (int) $object->id, $k['element']), function ($u) {
            return $u['korekta'];
        });
        if (!$uses) {
            return;
        }
        $langs->load('ksef@ksef');
        require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
        $form = new Form($db);
        $canEdit = $this->canEditInvoice($object);
        $src = new $k['class']($db);
        $items = array();
        foreach ($uses as $id => $u) {
            $item = $langs->trans('KSEF_SetoffDateItem', $src->fetch($u['source']) > 0 ? $src->getNomUrl(0) : '', price($u['amount'])) . ' ';
            if ($canEdit && $action === 'ksef_setoff_edit' && GETPOSTINT('discountid') === $id) {
                $item .= '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '" class="inline-block">'
                    . '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="ksef_setoff_set">'
                    . '<input type="hidden" name="discountid" value="' . (int) $id . '">'
                    . $form->selectDate($u['date'], 'ksef_setoff', 0, 0, 0, 'ksef_setoff', 1, 0)
                    . ' <input type="submit" class="button smallpaddingimp" value="' . dol_escape_htmltag($langs->trans('Modify')) . '"></form>';
            } else {
                $item .= '<span class="ksef-setoff-date">' . dol_print_date($u['date'], 'day') . '</span>'
                    . ($u['recorded'] ? '' : ' <span class="opacitymedium">(' . $langs->trans('KSEF_SetoffDateFallback') . ')</span>');
                if ($canEdit) {
                    $item .= ' <a class="editfielda reposition" href="' . $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '&action=ksef_setoff_edit&discountid=' . (int) $id . '&token=' . newToken() . '">'
                        . img_edit($langs->transnoentitiesnoconv('KSEF_SetoffDateEdit'), 1) . '</a>';
                }
            }
            $items[] = $item;
        }
        print '<tr class="ksef-setoff-dates"><td class="titlefieldcreate">' . $form->textwithpicto($langs->trans('KSEF_SetoffDate'), $langs->trans('KSEF_SetoffDateHelp'))
            . '</td><td colspan="3">' . implode('<br>', $items) . '</td></tr>';
    }

    /**
     * @brief Print close note
     * @param Facture|FactureFournisseur $object Invoice
     * @return void
     */
    private function printCloseNote($object)
    {
        global $langs;
        if ((int) $object->status !== Facture::STATUS_CLOSED || empty($object->paye) || (string) $object->close_code !== ''
            || trim((string) $object->close_note) === '') {
            return;
        }
        $langs->load('ksef@ksef');
        print '<tr class="ksef-close-note"><td class="titlefieldcreate">' . $langs->trans('KSEF_CloseNoteRow') . '</td><td colspan="3">'
            . '<span class="opacitymedium">' . dol_escape_htmltag((string) $object->close_note) . '</span></td></tr>';
    }

    /**
     * @brief Grey Classify paid
     * @param Facture|FactureFournisseur $object Invoice
     * @return void
     */
    private function printClassifyPaidLock($object)
    {
        global $langs;
        $why = ksefClassifyPaidRefusal($object);
        if ($why === '') {
            return;
        }
        $enc = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        if ($this->depositConvertRefusal($object) !== '') {
            print '<script>jQuery(function($) { $("a.butAction[href*=\'action=converttoreduc\']").each(function() {'
                . ' $(this).replaceWith($("<span>").addClass("butActionRefused classfortooltip ksef-convert-refused").attr("title", ' . json_encode($why, $enc) . ').text($(this).text()));'
                . ' }); });</script>';
        }
        if (ksefForeignOwedCloseState($object)['applies']) {
            $langs->load('bills');
            print '<script>jQuery(function($) { var label = ' . json_encode($langs->transnoentitiesnoconv('ClassifyPaid'), $enc) . ';'
                . ' $("a.butAction[href*=\'action=paid&\'], a.butAction[href$=\'action=paid\']").filter(function() { return $.trim($(this).text()) === label; }).each(function() {'
                . ' $(this).addClass("ksef-paid-partially").text(' . json_encode($langs->transnoentitiesnoconv('ClassifyPaidPartially'), $enc) . ');'
                . ' }); });</script>';
            return;
        }
        print '<script>jQuery(function($) { var label = ' . json_encode($langs->transnoentitiesnoconv('ClassifyPaid'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';'
            . ' $("a.butAction[href*=\'action=paid&\'], a.butAction[href$=\'action=paid\']").filter(function() { return $.trim($(this).text()) === label; }).each(function() {'
            . ' $(this).replaceWith($("<span>").addClass("butActionRefused classfortooltip ksef-paid-refused").attr("title", ' . json_encode($why, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ').text($(this).text()));'
            . ' }); });</script>';
    }

    /**
     * @brief Deposit convert refusal
     * @param Facture|FactureFournisseur $object Invoice
     * @return string '' if allowed
     */
    private function depositConvertRefusal($object)
    {
        if (!is_object($object) || ($object->element ?? '') !== 'facture' || (int) $object->type !== Facture::TYPE_DEPOSIT) {
            return '';
        }
        if (getDolGlobalInt('DEPOSIT_CAN_BE_CONVERTED_AS_AVAILABLE_CREDIT_EVEN_IF_NOT_YET_PAID') && price2num($object->getSommePaiement(), 'MT') == 0) {
            return '';
        }
        return ksefClassifyPaidRefusal($object, 'confirm_paid');
    }

    /**
     * @brief Paid partially form
     * @param Facture|FactureFournisseur $object Invoice
     * @return string HTML or ''
     */
    private function foreignOwedPartialConfirm($object)
    {
        global $db, $langs, $user;
        dol_include_once('/ksef/lib/ksef.lib.php');
        $st = ksefForeignOwedCloseState($object);
        $supplier = $object->element === 'invoice_supplier';
        // confirm_paid_partially rights
        if (!$st['applies'] || !($supplier ? $this->canEditInvoice($object) : $user->hasRight('facture', 'paiement'))) {
            return '';
        }
        $langs->load('bills');
        $langs->load('ksef@ksef');
        $form = new Form($db);
        $code = (string) $object->multicurrency_code;
        $remain = price($st['remain'], 0, $langs, 1, -1, 2);
        $close = array();
        if ($supplier) {
            $close['badsupplier'] = array($langs->trans('ConfirmClassifyPaidPartiallyReasonBadSupplierDesc'), $langs->transnoentities('ConfirmClassifyPaidPartiallyReasonBadSupplier'));
        } else {
            $close['badcustomer'] = array($langs->trans('ConfirmClassifyPaidPartiallyReasonBadCustomerDesc'),
                $langs->transnoentities('ConfirmClassifyPaidPartiallyReasonBadCustomer', $remain, $langs->trans('Currency' . $code)));
            $close['bankcharge'] = array($langs->trans('ConfirmClassifyPaidPartiallyReasonBankChargeDesc'),
                $langs->transnoentities('ConfirmClassifyPaidPartiallyReasonBankCharge', $remain, $langs->trans('Currency' . $code)));
            $close['withholdingtax'] = array($langs->trans('ConfirmClassifyPaidPartiallyReasonWithholdingTaxDesc'), $langs->transnoentities('ConfirmClassifyPaidPartiallyReasonWithholdingTax'));
        }
        $close['other'] = array($langs->trans('Other'), $langs->transnoentities('Other'));
        $reasons = array();
        foreach ($close as $c => $v) {
            $reason = $form->textwithpicto($v[1], $v[0], 1);
            $reasons[$c] = $supplier ? $reason : '<span class="small">' . $reason . '</span>';
        }
        $note = '<div class="info ksef-owed-partial-note">' . $langs->trans('KSEF_ForeignOwedPartialNote', $code, price($st['remain'], 0, $langs, 1, -1, 2, $code),
            price($st['remain_pln'], 0, $langs, 1, -1, 2, 'PLN')) . '</div>';
        if ($supplier) {
            $question = array('text' => $langs->trans('ConfirmClassifyPaidPartiallyQuestion') . $note,
                0 => array('type' => 'radio', 'name' => 'close_code', 'label' => $langs->trans('Reason'), 'values' => $reasons),
                1 => array('type' => 'text', 'name' => 'close_note', 'label' => $langs->trans('Comment'), 'value' => '', 'morecss' => 'minwidth300'));
            return $form->formconfirm($_SERVER['PHP_SELF'] . '?facid=' . (int) $object->id, $langs->trans('ClassifyPaid'), $langs->trans('ConfirmClassifyPaidPartially', $object->ref),
                'confirm_paid_partially', $question, 'yes', 1, 400);
        }
        $question = array('text' => $langs->trans('ConfirmClassifyPaidPartiallyQuestion') . $note,
            0 => array('type' => 'radio', 'name' => 'close_code', 'label' => '', 'values' => $reasons),
            1 => array('type' => 'text', 'name' => 'close_note', 'moreattr' => 'placeholder = "' . $langs->trans('Comment') . '"', 'value' => '', 'morecss' => 'minwidth300'),
            2 => array('type' => 'separator'));
        return $form->formconfirm($_SERVER['PHP_SELF'] . '?facid=' . (int) $object->id . '&resteapayer=' . ((float) $st['remain_at_rate']), $langs->trans('ClassifyPaid'),
            $langs->trans('ConfirmClassifyPaidPartially', $object->ref), 'confirm_paid_partially', $question, 'yes', 1, 500, 600);
    }

    /**
     * @brief Foreign owed row
     * @param Facture|FactureFournisseur $object Invoice
     * @return void
     */
    private function printForeignOwedRow($object)
    {
        global $langs;
        if ((int) $object->status !== Facture::STATUS_VALIDATED || !empty($object->paye)) {
            return;
        }
        $owed = ksefForeignOwedCloseState($object);
        if ($owed['applies']) {
            $langs->load('ksef@ksef');
            $langs->load('bills');
            $code = (string) $object->multicurrency_code;
            $text = $langs->trans('KSEF_ForeignOwedOpen', $code, price($owed['remain'], 0, $langs, 1, -1, 2, $code), price($owed['remain_pln'], 0, $langs, 1, -1, 2, 'PLN'),
                $langs->transnoentitiesnoconv('ClassifyPaidPartially'));
            print '<tr class="ksef-foreign-owed"><td class="titlefieldcreate">' . $langs->trans('KSEF_ForeignOwedRow') . '</td><td colspan="3">'
                . '<span class="fa fa-info-circle paddingright"></span>' . $text . '</td></tr>';
            return;
        }
        if ((int) $object->type === Facture::TYPE_DEPOSIT && (float) price2num($object->getRemainToPay(0), 'MT') < 0) {
            $why = ksefClassifyPaidRefusal($object);
            if ($why !== '') {
                $langs->load('ksef@ksef');
                print '<tr class="ksef-foreign-owed ksef-foreign-owed-deposit"><td class="titlefieldcreate">' . $langs->trans('KSEF_ForeignOwedRow') . '</td><td colspan="3">'
                    . '<span class="fa fa-info-circle paddingright"></span>' . dol_escape_htmltag($why) . '</td></tr>';
            }
        }
    }

    /**
     * @brief Deposit credit note
     * @param Facture|FactureFournisseur $object Invoice
     * @return void
     */
    private function printDepositCreditPlnNote($object)
    {
        global $db, $langs;
        $pln = ksefDepositCreditPlnState($db, $object, array('open' => true));
        if (!$pln['applies']) {
            return;
        }
        $langs->load('ksef@ksef');
        $sfx = $pln['kind'] === 'note' ? 'Note' : ($pln['kind'] === 'mixed' ? 'Mixed' : '');
        $label = $langs->trans('KSEF_DepositCreditPlnRemainLabel' . $sfx);
        $langs->tab_translate['NegativeIfExcessReceived'] = $label;
        $langs->tab_translate['NegativeIfExcessPaid'] = $label;
        print '<tr class="ksef-deposit-credit-pln"><td class="titlefieldcreate">' . $langs->trans('KSEF_DepositCreditPlnRow' . $sfx) . '</td><td colspan="3">'
            . '<span class="fa fa-info-circle paddingright"></span>' . dol_escape_htmltag($pln['text']) . '</td></tr>';
        print '<style>a.butAction[href*="action=converttoreduc"] { display: none !important; }</style>';
        print '<script type="text/javascript">
jQuery(document).ready(function() {
    var label = ' . json_encode($langs->transnoentities('RemainderToPayMulticurrency')) . ';
    jQuery("td > span.opacitymedium").filter(function() {
        return jQuery(this).text().trim().indexOf(label) === 0;
    }).last().each(function() {
        jQuery(this).text(label).closest("td").next("td").html(' . json_encode(price($pln['remain'], 0, $langs, 1, -1, 2, $pln['currency'])) . ');
    });
});
</script>';
    }

    /**
     * @brief Print deposit rows
     * @param Facture $object Invoice
     * @return void
     */
    private function printDepositUnstatedPayments($object)
    {
        global $db, $langs, $user;
        if (in_array((int) $object->type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
            $this->printLoweringOverpaid($object);
            return;
        }
        if ((int) $object->type !== Facture::TYPE_DEPOSIT) {
            return;
        }
        $langs->load('ksef@ksef');
        // art. 31a(1)
        $restate = ksefDepositRateRestatement($db, $object);
        if ($restate['due']) {
            $rows = array();
            foreach ($restate['payments'] as $p) {
                $rows[] = '<div>' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DepositRateRestate', price($p['amount'], 0, $langs, 1, -1, 2, $object->multicurrency_code),
                    dol_print_date(dol_stringtotime($p['day'] . ' 12:00:00'), 'day'), $p['kurs'] > 0 ? price2num($p['kurs'], 6) : '?', price2num($p['stated_kurs'], 6))) . '</div>';
            }
            $chain = ksefDepositChain($db, (int) $object->id);
            $active = new Facture($db);
            $link = '';
            if ($active->fetch((int) $chain['active']) > 0 && $user->hasRight('facture', 'creer')) {
                $link = ' <a class="butAction ksef-deposit-restate" href="' . dol_escape_htmltag(ksefCorrectionCreateUrl($active)) . '">' . $langs->trans('KSEF_CreateCorrection') . '</a>';
            }
            print '<tr class="ksef-deposit-rate-restate"><td class="titlefieldcreate">' . $langs->trans('KSEF_DepositRateRestateRow') . '</td><td colspan="3">'
                . '<span class="warning"><span class="fa fa-exclamation-triangle paddingright"></span>' . implode('', $rows) . '</span>' . $link . '</td></tr>';
        }
        $un = ksefDepositUnstatedPayments($db, $object);
        if ($un['refund']) {
            // art. 106j(1)(4)
            $chain = ksefDepositChain($db, (int) $object->id);
            $active = new Facture($db);
            $link = '';
            if ($active->fetch((int) $chain['active']) > 0 && $user->hasRight('facture', 'creer')) {
                $link = ' <a class="butAction ksef-deposit-refund" href="' . dol_escape_htmltag(ksefCorrectionCreateUrl($active)) . '">' . $langs->trans('KSEF_CreateCorrection') . '</a>';
            }
            print '<tr class="ksef-deposit-refund-no-correction"><td class="titlefieldcreate">' . $langs->trans('KSEF_DepositRefundNoCorrectionRow') . '</td><td colspan="3">'
                . '<span class="warning"><span class="fa fa-exclamation-triangle paddingright"></span>' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DepositRefundNoCorrection',
                dol_print_date(dol_stringtotime($un['refund']['day'] . ' 12:00:00'), 'day'), price($un['refund']['amount'], 0, $langs, 1, -1, 2, $un['currency']))) . '</span>' . $link . '</td></tr>';
        }
        if (!$un['payments']) {
            return;
        }
        $items = array();
        foreach ($un['payments'] as $p) {
            $day = dol_print_date(dol_stringtotime($p['day'] . ' 12:00:00'), 'day');
            $amount = price($p['amount'], 0, $langs, 1, -1, 2, $un['currency']);
            // paid before lowering
            if ($un['lowered'] && $un['state'] === 'sent' && $p['day'] <= $un['lowered']['day']) {
                $items[] = '<div class="ksef-deposit-lowered-overpaid">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DepositPaymentAfterLowering', $un['lowered']['ref'], $day, $amount)) . '</div>';
                continue;
            }
            $items[] = '<div>' . dol_escape_htmltag($langs->transnoentitiesnoconv($un['state'] === 'sent' ? 'KSEF_DepositPaymentAfterSend' : 'KSEF_DepositPaymentAfterBooking', $day, $amount)) . '</div>';
        }
        print '<tr class="ksef-deposit-unstated-payment"><td class="titlefieldcreate">' . $langs->trans('KSEF_DepositPaymentOwnInvoiceRow') . '</td><td colspan="3">'
            . '<span class="warning"><span class="fa fa-exclamation-triangle paddingright"></span>' . implode('', $items) . '</span></td></tr>';
    }

    /**
     * @brief Lowering correction overpaid
     * @param Facture $object Correction
     * @return void
     */
    private function printLoweringOverpaid($object)
    {
        global $db, $langs;
        dol_include_once('/ksef/lib/ksef_correction.lib.php');
        $root = ksefCorrectionRoot($db, $object);
        if (!$root || (int) $root->type !== Facture::TYPE_DEPOSIT) {
            return;
        }
        $un = ksefDepositUnstatedPayments($db, $root);
        if (!$un['lowered'] || $un['state'] !== 'sent' || $un['lowered']['ref'] !== (string) $object->ref) {
            return;
        }
        $langs->load('ksef@ksef');
        $items = array();
        foreach ($un['payments'] as $p) {
            if ($p['day'] <= $un['lowered']['day']) {
                $items[] = '<div class="ksef-deposit-lowered-overpaid">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DepositPaymentAfterLoweringKor', $root->ref,
                    dol_print_date(dol_stringtotime($p['day'] . ' 12:00:00'), 'day'), price($p['amount'], 0, $langs, 1, -1, 2, $un['currency']))) . '</div>';
            }
        }
        if ($items) {
            print '<tr class="ksef-deposit-unstated-payment"><td class="titlefieldcreate">' . $langs->trans('KSEF_DepositPaymentOwnInvoiceRow') . '</td><td colspan="3">'
                . '<span class="warning"><span class="fa fa-exclamation-triangle paddingright"></span>' . implode('', $items) . '</span></td></tr>';
        }
    }

    /**
     * @brief Print correction chain
     * @param Facture|FactureFournisseur $object Invoice
     * @return void
     */
    private function printCorrectionChain($object)
    {
        global $db, $langs, $conf;
        dol_include_once('/ksef/lib/ksef.lib.php');
        $kind = ksefInvoiceKind($object);
        $cardUrl = $kind['element'] === 'invoice_supplier' ? '/fourn/facture/card.php?facid=' : '/compta/facture/card.php?facid=';
        $isInChain = false;
        if (!empty($object) && !empty($object->id)) {
            if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source)) {
                $isInChain = true;
            } else {
                $sql_has_replacement = "SELECT rowid FROM " . MAIN_DB_PREFIX . $kind['table']
                    . " WHERE fk_facture_source = " . ((int) $object->id)
                    . " AND type = " . Facture::TYPE_REPLACEMENT
                    . " AND fk_statut > 0 LIMIT 1";
                $res_has_replacement = $db->query($sql_has_replacement);
                if ($res_has_replacement && $db->num_rows($res_has_replacement) > 0) {
                    $isInChain = true;
                }
            }
        }
        if ($isInChain) {
            $langs->load("ksef@ksef");
            $chain = ksefBuildCorrectionChain($object, $db);

            if (count($chain) > 1) {
                $latestInChain = $chain[count($chain) - 1];
                $currentAmount = (float) $latestInChain->total_ttc;
                // korekta discounts count
                $remaining = (float) price2num($latestInChain->getRemainToPay(0), 'MT');
                $total_paid_on_chain = $currentAmount - $remaining;
                $isViewingLatest = ($object->id == $latestInChain->id);

                $chainNoteKey = $isViewingLatest ? 'KSEF_CorrectionChainCurrentNote' : 'KSEF_CorrectionChainCorrectedNote';
                $chainHtml = '<div class="div-table-responsive-no-min"><table class="border tableforfield centpercent ksef-correction-chain">';
                $chainHtml .= '<tr class="liste_titre"><td colspan="5"><span class="fas fa-info-circle" style="margin-right: 6px;"></span>'
                    . $langs->trans($chainNoteKey) . '</td></tr>';
                $chainHtml .= '<tr><td class="titlefieldmiddle"></td>';
                $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainExclTax') . '</span></td>';
                $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainTax') . '</span></td>';
                $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainInclTax') . '</span></td>';
                $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainDiff') . '</span></td>';
                $chainHtml .= '</tr>';

                $prevTtc = null;
                foreach ($chain as $idx => $chainInv) {
                    $isCurrent = ($chainInv->id == $object->id);
                    $bold = $isCurrent ? 'font-weight: bold;' : '';

                    $invLink = '<a href="' . DOL_URL_ROOT . $cardUrl . $chainInv->id . '">'
                        . dol_escape_htmltag($chainInv->ref) . '</a>';

                    $ht = price($chainInv->total_ht, 0, $langs, 1, -1, -1, $conf->currency);
                    $tva = price($chainInv->total_tva, 0, $langs, 1, -1, -1, $conf->currency);
                    $ttc = price($chainInv->total_ttc, 0, $langs, 1, -1, -1, $conf->currency);

                    if ($prevTtc === null) {
                        $diffHtml = price($chainInv->total_ttc, 0, $langs, 1, -1, -1, $conf->currency);
                    } else {
                        $diff = (float) $chainInv->total_ttc - $prevTtc;
                        $diffTone = abs($diff) < 0.01 ? 'info' : ($diff < 0 ? 'problem' : 'ok');
                        $diffPrefix = ($diff > 0.01) ? '+' : '';
                        $diffHtml = '<span class="' . ksefToneTextClass($diffTone) . '">' . $diffPrefix . price($diff, 0, $langs, 1, -1, -1, $conf->currency) . '</span>';
                    }
                    $prevTtc = (float) $chainInv->total_ttc;

                    $invPaid = $chainInv->getSommePaiement(0);
                    $badges = $chainInv->getLibStatut(5, $invPaid) . ' ';
                    $hasReplacement = ($idx < count($chain) - 1);
                    if ($hasReplacement) {
                        if (strpos($badges, $langs->trans('KSEF_StatusCorrected')) === false) {
                            $badges .= dolGetStatus($langs->trans('KSEF_CorrectionChainCorrected'), $langs->trans('KSEF_CorrectionChainCorrected'), '', 'status4', 5) . ' ';
                        }
                    } elseif ($idx == count($chain) - 1) {
                        $badges .= dolGetStatus($langs->trans('KSEF_CorrectionChainCurrent'), $langs->trans('KSEF_CorrectionChainCurrent'), '', 'status0', 5);
                    }

                    $chainHtml .= '<tr style="' . $bold . '">';
                    $chainHtml .= '<td><span class="nowraponall">' . $invLink . '</span><br>' . $badges . '</td>';
                    $chainHtml .= '<td class="nowraponall right">' . $ht . '</td>';
                    $chainHtml .= '<td class="nowraponall right">' . $tva . '</td>';
                    $chainHtml .= '<td class="nowraponall right">' . $ttc . '</td>';
                    $chainHtml .= '<td class="nowraponall right">' . $diffHtml . '</td>';
                    $chainHtml .= '</tr>';
                }

                $totalPaidFormatted = price($total_paid_on_chain, 0, $langs, 1, -1, -1, $conf->currency);
                $currentAmountFormatted = price($currentAmount, 0, $langs, 1, -1, -1, $conf->currency);

                $langs->load("bills");
                if (abs($remaining) < 0.01) {
                    $remainLabel = $langs->trans('KSEF_CorrectionChainSettled');
                    $remainFormatted = price(0, 0, $langs, 1, -1, -1, $conf->currency);
                    $remainTone = 'ok';
                } elseif ($remaining < 0) {
                    $remainLabel = $langs->trans('RemainderToPayBack');
                    $remainFormatted = price(abs($remaining), 0, $langs, 1, -1, -1, $conf->currency);
                    $remainTone = 'action';
                } else {
                    $remainLabel = $langs->trans('RemainderToPay');
                    $remainFormatted = price($remaining, 0, $langs, 1, -1, -1, $conf->currency);
                    $remainTone = 'problem';
                }

                $chainHtml .= '</table></div>';

                $chainHtmlJs = dol_escape_js($chainHtml);

                $totalPaidLabelJs = dol_escape_js($langs->trans('KSEF_CorrectionChainTotalPaid'));
                $totalPaidValueJs = dol_escape_js($totalPaidFormatted);
                $currentAmountLabelJs = dol_escape_js($langs->trans('KSEF_CorrectionChainCurrentAmount'));
                $currentAmountValueJs = dol_escape_js($currentAmountFormatted);
                $remainLabelJs = dol_escape_js($remainLabel);
                $remainValueJs = dol_escape_js($remainFormatted);

                print '<script type="text/javascript">
jQuery(document).ready(function() {
var amountTable = jQuery(".fichehalfright table.border.tableforfield.centpercent").first();
if (amountTable.length) {
    amountTable.after(\'' . $chainHtmlJs . '\');

    // chain summary columns
    var amountRows = amountTable.find("tr").has("td.amountcard");
    if (amountRows.length >= 3) {
        amountRows.eq(0).append(\'<td class="opacitymedium right" style="padding-left: 12px;">' . $totalPaidLabelJs . '</td><td class="nowraponall right">' . $totalPaidValueJs . '</td>\');
        amountRows.eq(1).append(\'<td class="opacitymedium right" style="padding-left: 12px;">' . $currentAmountLabelJs . '</td><td class="nowraponall right">' . $currentAmountValueJs . '</td>\');
        amountRows.eq(2).append(\'<td class="opacitymedium right" style="padding-left: 12px;">' . $remainLabelJs . '</td><td class="nowraponall right ' . ksefToneTextClass($remainTone) . '" style="font-weight: bold;">' . $remainValueJs . '</td>\');
        // match colspan
        var currencyRow = amountTable.find("tr").first();
        var currencyTd = currencyRow.find("td").last();
        currencyTd.attr("colspan", 3);
    }
}
});
</script>';

                if (!$isViewingLatest) {
                    $overrideLabel = dol_escape_js($langs->trans('KSEF_CorrectionChainSuperseded'));
                    $overrideCssClass = 'amountpaymentneutral';
                    $overrideValue = '<span class="opacitymedium small">' . dol_escape_js($langs->trans('KSEF_CorrectionChainSuperseded')) . '</span>';
                } else {
                    $overrideLabel = dol_escape_js($remainLabel);
                    if (abs($remaining) < 0.01) {
                        $overrideCssClass = 'amountpaymentcomplete';
                    } elseif ($remaining < 0) {
                        $overrideCssClass = 'amountremaintopayback';
                    } else {
                        $overrideCssClass = 'amountremaintopay';
                    }
                    $overrideValue = dol_escape_js($remainFormatted);
                }
                $overrideLabelJs = $overrideLabel;

                print '<script type="text/javascript">
jQuery(document).ready(function() {
var remainCell = jQuery("td.amountremaintopay, td.amountpaymentneutral, td.amountpaymentcomplete, td.amountremaintopayback");
if (!remainCell.length) return;
var row = remainCell.closest("tr");
row.find("span.opacitymedium").text(\'' . $overrideLabelJs . '\');
remainCell.replaceWith(\'<td class="right ' . $overrideCssClass . '">' . $overrideValue . '</td>\');
});
</script>';
            }
        }
    }

    /**
     * @brief Show KSeF status
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db, $conf, $form, $user;
        dol_include_once('/ksef/lib/ksef.lib.php');

        $currentcontext = $parameters['currentcontext'] ?? '';
        if ($currentcontext === 'accountancyadminaccount') {
            if (isModEnabled('ksef')) {
                dol_include_once('/ksef/lib/ksef_chart.lib.php');
                $this->resprints = ksefChartLoaderHtml($db, $user);
            }
            return 0;
        }
        if ($currentcontext === 'paymentcard') {
            static $fxRowShown = false;
            if (!$fxRowShown && is_object($object) && !empty($object->id) && ($object->element ?? '') === 'payment' && isModEnabled('ksef')) {
                $fxRowShown = true;
                print ksefPayFxInfoRow($db, 'payment', (int) $object->id);
            }
            return 0;
        }
        if ($currentcontext === 'variouscard') {
            static $noLedgerJs = false;
            if (!$noLedgerJs && is_object($object) && !empty($object->id) && ksefNoLedgerTable($db)) {
                $noLedgerJs = true;
                $langs->loadLangs(array('accountancy'));
                $f = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
                $href = DOL_URL_ROOT . '/compta/bank/various_payment/card.php?id=' . (int) $object->id . '&action=delete&token=' . newToken();
                print '<script>$(function () { $(".tabsAction .butActionRefused").filter(function () { return $(this).attr("title") === '
                    . json_encode($langs->transnoentitiesnoconv('Accounted'), $f) . '; }).removeClass("butActionRefused classfortooltip").addClass("butActionDelete ksef-noledger-delete")'
                    . '.removeAttr("title").attr("href", ' . json_encode($href, $f) . '); });</script>';
            }
            return 0;
        }
        if ($currentcontext == 'invoicecard') {
            if (empty($object) || empty($object->id)) {
                if (GETPOST('action', 'aZ09') != 'create') {
                    return 0;
                }
            }

            dol_include_once('/ksef/class/ksef_submission.class.php');
            dol_include_once('/ksef/lib/ksef.lib.php');
            dol_include_once('/ksef/class/ksef_client.class.php');

            print '<style>tr:has(.facture_extras_ksef_number), tr:has(.facture_extras_ksef_status), tr:has(.facture_extras_ksef_submission_date) { display: none !important; }</style>';
            print '<style>tr:has(.facture_extras_ksef_correction_original_ht), tr:has(.facture_extras_ksef_correction_original_tva), tr:has(.facture_extras_ksef_correction_original_ttc), tr:has(.facture_extras_ksef_correction_discount_id), tr:has(.facture_extras_ksef_correction_original_discount_ids) { display: none !important; }</style>';
            print '<style>tr:has(.facture_extras_ksef_correction_reason), tr:has(.facture_extras_ksef_correction_type) { display: none !important; }</style>';
            print '<style>tr:has(.facture_extras_ksef_podmiot3), tr:has(.facture_extras_ksef_podmiot3_role) { display: none !important; }</style>';

            if (!empty($object) && !empty($object->id)) {
                $_dodActiveCheck_noteMode = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
                $_dodActiveCheck_efConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS', '');
                if ((!isset($object->array_options) || empty($object->array_options))) {
                    $object->fetch_optionals();
                }
                $_dodActiveCheck_override = isset($object->array_options['options_ksef_dodatkowy_opis_mode']) ? trim((string) $object->array_options['options_ksef_dodatkowy_opis_mode']) : '';
                if ($_dodActiveCheck_noteMode === 'disabled' && empty($_dodActiveCheck_efConf) && $_dodActiveCheck_override === '') {
                    print '<style>tr:has(.facture_extras_ksef_dodatkowy_opis_mode) { display: none !important; }</style>';
                }
            }

            print '<script type="text/javascript">
jQuery(document).ready(function() {
    var saleDateRow = jQuery("tr").has(".facture_extras_ksef_sale_date");
    if (!saleDateRow.length) return;
    // Try edit button first (draft invoices)
    var dateRow = jQuery("a[href*=\'action=editinvoicedate\']").closest("table.nobordernopadding").closest("tr");
    // Fallback: find by label text (validated invoices where edit button is gone)
    if (!dateRow.length) {
        var label = ' . json_encode($langs->transnoentities("DateInvoice")) . ';
        dateRow = jQuery("table.border.tableforfield > tbody > tr").filter(function() {
            return jQuery(this).children("td").first().text().trim() === label;
        }).first();
    }
    if (dateRow.length) {
        saleDateRow.detach().insertAfter(dateRow).show();
    }
});
</script>';

            if (!empty($object) && !empty($object->id) && $object->element === 'facture') {
                $depositCtx = ksefDepositContextOnce($db, $object);
                $depositHtml = ksefDepositSummaryHtml($db, $object, $depositCtx);
                if ($depositHtml !== '') {
                    $langs->load('ksef@ksef');
                    $depositOpen = count($depositCtx['pending'] ?? array()) + count($depositCtx['partial'] ?? array()) + count($depositCtx['mismatch'] ?? array())
                        + (ksefDepositBlockCode($depositCtx) !== '' ? 1 : 0);
                    $depositState = (int) $object->type === Facture::TYPE_DEPOSIT && (int) $object->status > 0 ? ksefDepositPendingState($db, (int) $object->id)['state'] : '';
                    print '<tr class="ksef-deposit-summary" data-kind="' . dol_escape_htmltag(isset($depositCtx['type']) ? $depositCtx['type'] : '') . '" data-deposits-open="' . $depositOpen . '"'
                        . ($depositState !== '' ? ' data-deposit-state="' . dol_escape_htmltag($depositState) . '"' : '') . '><td class="titlefieldcreate">' . $langs->trans('KSEF_KsefInvoiceKind') . '</td><td colspan="3">' . $depositHtml . '</td></tr>';
                }
                print ksefDepositCreditRowScript($db, $object);
                print ksefDepositBackRow($db, $object);
                $this->printSetoffDates($object, $action);
                $this->printCloseNote($object);
                $this->printForeignOwedRow($object);
                $this->printDepositCreditPlnNote($object);
                $this->printDepositUnstatedPayments($object);
                if (getDolGlobalString('KSEF_FA3_LINE_DATES', 'disabled') === 'auto' || getDolGlobalInt('KSEF_FA3_PERIOD_FIELDS')) {
                    $supply = ksefInvoiceSupplyMode($object);
                    if ($supply['mode'] !== 'p6' && empty(ksefSubmissionState($db, (int) $object->id)['locked'])) {
                        $langs->load('ksef@ksef');
                        $text = $supply['mode'] === 'okres'
                            ? $langs->trans('KSEF_SaleDateReplacedByPeriod', dol_print_date(dol_stringtotime($supply['from']), 'day'), dol_print_date(dol_stringtotime($supply['to']), 'day'))
                            : $langs->trans('KSEF_SaleDateReplacedByLineDates');
                        print '<tr class="ksef-supply-dates"><td class="titlefieldcreate">' . $langs->trans('KSEF_SaleDateInKsef') . '</td><td colspan="3"><span class="opacitymedium">' . $text . '</span></td></tr>';
                    }
                }
                if ($object->statut > 0 && empty($object->paye) && $object->type != Facture::TYPE_CREDIT_NOTE) {
                    $resS = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE fk_facture = " . (int) $object->id . " ORDER BY rowid DESC LIMIT 1");
                    if ($resS && ($oS = $db->fetch_object($resS))) {
                        dol_include_once('/ksef/class/ksef_sales.class.php');
                        $sale = new KsefSales($db);
                        if ($sale->fetch((int) $oS->rowid) > 0) {
                            $payText = $sale->ksefPaymentText();
                            if ($payText !== '') {
                                $langs->load('ksef@ksef');
                                // invoice currency amounts
                                $foreignInv = !empty($object->multicurrency_code) && $object->multicurrency_code !== $conf->currency;
                                $paidHere = (float) $object->getSommePaiement($foreignInv ? 1 : 0);
                                $ksefSum = 0;
                                foreach ($sale->ksefPayments() as $it) {
                                    $ksefSum += (float) $it['amount'];
                                }
                                $missing = $paidHere + 0.005 < $ksefSum;
                                print '<tr class="ksef-payment-summary"><td class="titlefieldcreate">' . $langs->trans('KSEF_SalesKsefPayment') . '</td><td colspan="3">'
                                    . dol_escape_htmltag($payText) . ($missing ? ' <span class="warning">' . $langs->trans('KSEF_SalesKsefPaymentMissing')
                                    . (($why = $sale->bankAccountProblem()) !== '' ? ' ' . dol_escape_htmltag($why) : '') . '</span>' : '') . '</td></tr>';
                            }
                        }
                    }
                }
            }

            $this->printCorrectionChain($object);

            if (!empty($object) && !empty($object->id)
                && ($object->type == Facture::TYPE_REPLACEMENT || $object->type == Facture::TYPE_CREDIT_NOTE)
                && $object->statut == Facture::STATUS_DRAFT
                && !empty($object->fk_facture_source)) {
                $langs->load("ksef@ksef");
                $hintKey = ($object->type == Facture::TYPE_REPLACEMENT)
                    ? 'KSEF_CorrectionDraftHint'
                    : 'KSEF_CreditNoteDraftHint';
                $hintText = dol_escape_js($langs->trans($hintKey));
                print '<script type="text/javascript">
jQuery(document).ready(function() {
    var lineTable = jQuery("#tablelines, table.liste");
    if (lineTable.length) {
        lineTable.first().before(\'<div class="info" style="margin-bottom: 8px;"><span class="fas fa-info-circle" style="margin-right: 6px;"></span>' . $hintText . '</div>\');
    }
});
</script>';
            }

            if (!empty($object) && !empty($object->id)
                && ($object->type == Facture::TYPE_CREDIT_NOTE || $object->type == Facture::TYPE_REPLACEMENT)) {
                $langs->load("ksef@ksef");
                if (!isset($object->array_options) || empty($object->array_options)) {
                    $object->fetch_optionals();
                }

                $corrReadonly = !ksefUserCan($user, 'edit_ksef_data', ksefSubmissionState($db, $object->id));

                $corrReasonPresets = array(
                    'Zwrot towaru'          => 'KSEF_CorrectionReasonPreset_return',
                    'Błędna ilość'          => 'KSEF_CorrectionReasonPreset_qty',  // @phan-suppress-current-line PhanPluginDuplicateArrayKey
                    'Błędna cena'           => 'KSEF_CorrectionReasonPreset_price',
                    'Błędna stawka VAT'     => 'KSEF_CorrectionReasonPreset_vat',
                    'Rabat potransakcyjny'  => 'KSEF_CorrectionReasonPreset_discount',
                    'Skonto'                => 'KSEF_CorrectionReasonPreset_skonto',
                    'Zwrot zaliczki'        => 'KSEF_CorrectionReasonPreset_advance',
                    'Zmiana kwoty zaliczki' => 'KSEF_CorrectionReasonPreset_advance_change',
                    'Błędne dane nabywcy'   => 'KSEF_CorrectionReasonPreset_buyer',
                );

                $currentReason = trim((string) ($object->array_options['options_ksef_correction_reason'] ?? ''));
                $currentType = trim((string) ($object->array_options['options_ksef_correction_type'] ?? ''));

                $isPreset = !empty($currentReason) && array_key_exists($currentReason, $corrReasonPresets);
                $isCustom = !empty($currentReason) && !$isPreset;

                $detailsLabel = $form->textwithpicto(
                    $langs->trans("KSEF_CorrectionDetails"),
                    $langs->trans("KSEF_CorrectionReasonAlwaysPolishHelp")
                );
                print '<tr><td class="titlefieldcreate">' . $detailsLabel . '</td><td colspan="3">';

                if ($corrReadonly) {
                    $corrUnset = $this->importedFromKsef($object) ? 'KSEF_NotGivenInKsef' : 'KSEF_NotSet';
                    print '<div style="margin-bottom: 6px;">';
                    print $langs->trans("KSEF_CorrectionReason") . ': ';
                    if ($isPreset) {
                        print '<strong>' . $langs->trans($corrReasonPresets[$currentReason]) . '</strong>';
                    } elseif ($isCustom) {
                        print '<strong>' . dol_escape_htmltag($currentReason) . '</strong>';
                    } else {
                        print '<span class="opacitymedium">' . $langs->trans($corrUnset) . '</span>';
                    }
                    print '</div>';
                    print '<div>';
                    print $langs->trans("KSEF_CorrectionType") . ': ';
                    if (!empty($currentType) && in_array($currentType, array('1', '2', '3'))) {
                        print '<strong>' . $langs->trans("KSEF_CorrectionType" . $currentType) . '</strong>';
                    } else {
                        print '<span class="opacitymedium">' . $langs->trans($corrUnset) . '</span>';
                    }
                    print '</div>';
                } else {
                    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?facid=' . $object->id . '">';
                    print '<input type="hidden" name="token" value="' . newToken() . '">';
                    print '<input type="hidden" name="action" value="ksef_save_correction_details">';

                    print '<div style="margin-bottom: 6px;">';
                    print $langs->trans("KSEF_CorrectionReason") . ': ';
                    print '<select name="ksef_correction_reason_preset" id="ksef_correction_reason_preset" style="min-width: 220px;">';
                    print '<option value=""></option>';
                    $plainKor = $object->element === 'facture' && !in_array(ksefDepositContextOnce($db, $object)['type'] ?? '', array('KOR_ZAL', 'KOR_ROZ'), true);
                    foreach ($corrReasonPresets as $plText => $langKey) {
                        if ($plainKor && strpos($langKey, '_advance') !== false && $currentReason !== $plText) {
                            continue;
                        }
                        $selected = ($isPreset && $currentReason === $plText) ? ' selected' : '';
                        print '<option value="' . dol_escape_htmltag($plText) . '"' . $selected . '>' . $langs->trans($langKey) . '</option>';
                    }
                    $otherSelected = $isCustom ? ' selected' : '';
                    print '<option value="custom"' . $otherSelected . '>' . $langs->trans("KSEF_CorrectionReasonPreset_other") . '</option>';
                    print '</select>';

                    $customDisplay = $isCustom ? 'inline-block' : 'none';
                    $customValue = $isCustom ? dol_escape_htmltag($currentReason) : '';
                    print ' <input type="text" name="ksef_correction_reason_custom" id="ksef_correction_reason_custom"';
                    print ' value="' . $customValue . '" maxlength="256" style="display: ' . $customDisplay . '; width: 300px;"';
                    print ' placeholder="' . dol_escape_htmltag($langs->trans("KSEF_CorrectionReasonCustomPlaceholder")) . '">';
                    print '</div>';

                    print '<div>';
                    print $langs->trans("KSEF_CorrectionType") . ': ';
                    print '<select name="ksef_correction_type" style="min-width: 200px;">';
                    print '<option value=""></option>';
                    for ($t = 1; $t <= 3; $t++) {
                        $tSelected = ($currentType === (string) $t) ? ' selected' : '';
                        print '<option value="' . $t . '"' . $tSelected . '>' . $langs->trans("KSEF_CorrectionType" . $t) . '</option>';
                    }
                    print '</select>';
                    print ' <input type="submit" class="button smallpaddingimp" value="' . $langs->trans("Save") . '">';
                    print '</div>';

                    print '</form>';

                    print '<script type="text/javascript">
jQuery(document).ready(function() {
    jQuery("#ksef_correction_reason_preset").on("change", function() {
        if (jQuery(this).val() === "custom") {
            jQuery("#ksef_correction_reason_custom").show().focus();
        } else {
            jQuery("#ksef_correction_reason_custom").hide().val("");
        }
    });
});
</script>';
                }
                print '</td></tr>';
            }

            if (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') === 'enabled'
                && !empty($object) && !empty($object->id) && $object->element === 'facture') {
                $langs->load("ksef@ksef");
                if (!isset($object->array_options) || empty($object->array_options)) {
                    $object->fetch_optionals();
                }

                $p3Readonly = !ksefUserCan($user, 'edit_ksef_data', ksefSubmissionState($db, $object->id));

                $p3Roles = array('6', '2', '3', '4', '5', '7', '8', '9', '10', '11', '1');
                $p3DefaultRole = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');

                $p3Raw = $object->array_options['options_ksef_podmiot3'] ?? '';
                $p3List = array();
                $p3Decoded = (is_string($p3Raw) && $p3Raw !== '') ? json_decode($p3Raw, true) : null;
                if (is_array($p3Decoded)) {
                    foreach ($p3Decoded as $e) {
                        $eid = (int) ($e['id'] ?? 0);
                        if ($eid > 0) $p3List[] = array('id' => $eid, 'role' => (string) ($e['role'] ?? ''), 'udzial' => trim((string) ($e['udzial'] ?? '')));
                    }
                } elseif ((int) $p3Raw > 0) {
                    $p3List[] = array('id' => (int) $p3Raw, 'role' => trim((string) ($object->array_options['options_ksef_podmiot3_role'] ?? '')), 'udzial' => '');
                }

                require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
                $p3Existing = array();
                foreach ($p3List as $e) {
                    $rl = $e['role'] !== '' ? $e['role'] : $p3DefaultRole;
                    $nm = '';
                    $soc = new Societe($db);
                    if ($soc->fetch($e['id']) > 0) $nm = $soc->name;
                    $uTxt = ($rl === '4' && !empty($e['udzial'])) ? rtrim(rtrim($e['udzial'], '0'), '.') . '%' : '';
                    $p3Existing[] = array('id' => $e['id'], 'role' => $rl, 'udzial' => ($rl === '4' ? (string) $e['udzial'] : ''), 'name' => $nm, 'label' => $langs->trans('KSEF_Podmiot3_Role' . $rl) . ($uTxt !== '' ? ' (' . $uTxt . ')' : ''));
                }

                $p3Label = $form->textwithpicto($langs->trans('KSEF_Podmiot3_Details'), $langs->trans('KSEF_Podmiot3_Details_Help'));
                print '<tr><td class="titlefieldcreate">' . $p3Label . '</td><td colspan="3">';
                if ($p3Readonly) {
                    if (empty($p3Existing)) {
                        print '<span class="opacitymedium">' . $langs->trans('KSEF_NotSet') . '</span>';
                    } else {
                        print '<ul style="margin:0;padding-left:18px;">';
                        foreach ($p3Existing as $r) {
                            $nmTxt = $r['name'] !== '' ? dol_escape_htmltag($r['name']) : $langs->trans('KSEF_NotSet');
                            print '<li><strong>' . $nmTxt . '</strong> &mdash; ' . dol_escape_htmltag($r['label']) . '</li>';
                        }
                        print '</ul>';
                    }
                } else {
                    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?facid=' . $object->id . '" id="ksef_p3_form">';
                    print '<input type="hidden" name="token" value="' . newToken() . '">';
                    print '<input type="hidden" name="action" value="ksef_save_podmiot3">';

                    print '<table id="ksef_p3_list" class="centpercent" style="margin-bottom:6px;"></table>';

                    print '<table class="nobordernopadding"><tbody>';
                    print '<tr><td class="nowrap">' . $langs->trans('KSEF_Podmiot3_Party') . ': </td><td>';
                    print $form->select_company(0, 'ksef_podmiot3_pick', '', '&nbsp;', 0, 0, array(), 0, 'minwidth300');
                    print '</td></tr>';
                    print '<tr><td class="nowrap">' . $langs->trans('KSEF_Podmiot3_Role') . ': </td><td>';
                    print '<select id="ksef_podmiot3_role_pick">';
                    foreach ($p3Roles as $r) {
                        print '<option value="' . $r . '"' . ($r === $p3DefaultRole ? ' selected' : '') . '>' . $langs->trans('KSEF_Podmiot3_Role' . $r) . '</option>';
                    }
                    print '</select>';
                    print '</td></tr>';
                    print '<tr id="ksef_p3_udzial_row" style="display:none;"><td class="nowrap">' . $langs->trans('KSEF_Podmiot3_Udzial') . ': </td><td>';
                    print '<input type="text" id="ksef_podmiot3_udzial_pick" size="6" maxlength="6" placeholder="0-100"> %';
                    print ' <span class="opacitymedium">' . $langs->trans('KSEF_Podmiot3_Udzial_Help') . '</span>';
                    print '</td></tr>';
                    print '<tr><td></td><td>';
                    print '<button type="button" class="button smallpaddingimp" id="ksef_p3_add">' . $langs->trans('KSEF_Podmiot3_SaveAddAnother') . '</button>';
                    print ' <button type="button" class="button smallpaddingimp" id="ksef_p3_save">' . $langs->trans('Save') . '</button>';
                    print '</td></tr>';
                    print '</tbody></table>';
                    print '</form>';

                    $p3ExistingJson = json_encode(array_values($p3Existing));
                    $p3MaxMsg = dol_escape_js($langs->trans('KSEF_Podmiot3_Max'));
                    $p3RemoveTitle = dol_escape_js($langs->trans('KSEF_Podmiot3_Remove'));
                    print '<script>
                    (function() {
                        var MAX = 20;
                        var existing = ' . $p3ExistingJson . ';
                        function $list() { return document.getElementById("ksef_p3_list"); }
                        function addRow(id, role, name, label, udzial) {
                            var tb = $list();
                            if (!tb || tb.rows.length >= MAX) { if (tb) alert("' . $p3MaxMsg . '"); return false; }
                            var tr = tb.insertRow(-1);
                            var c1 = tr.insertCell(-1);
                            var nameSpan = document.createElement("strong"); nameSpan.textContent = name || "";
                            c1.appendChild(nameSpan);
                            c1.appendChild(document.createTextNode(" - " + (label || "")));
                            var h1 = document.createElement("input"); h1.type="hidden"; h1.name="ksef_podmiot3_party[]"; h1.value=id; c1.appendChild(h1);
                            var h2 = document.createElement("input"); h2.type="hidden"; h2.name="ksef_podmiot3_role[]"; h2.value=role; c1.appendChild(h2);
                            var h3 = document.createElement("input"); h3.type="hidden"; h3.name="ksef_podmiot3_udzial[]"; h3.value=(role === "4" ? (udzial || "") : ""); c1.appendChild(h3);
                            var c2 = tr.insertCell(-1); c2.style.textAlign="right"; c2.style.width="40px";
                            var rm = document.createElement("a"); rm.href="#"; rm.title="' . $p3RemoveTitle . '"; rm.innerHTML="&times;"; rm.style.color="#a00"; rm.style.fontWeight="bold";
                            rm.onclick = function(ev){ ev.preventDefault(); tr.parentNode.removeChild(tr); };
                            c2.appendChild(rm);
                            return true;
                        }
                        function toggleUdzial() {
                            var role = document.getElementById("ksef_podmiot3_role_pick").value;
                            document.getElementById("ksef_p3_udzial_row").style.display = (role === "4") ? "" : "none";
                        }
                        // true if a row was added
                        function commitPick($) {
                            var $pick = $("#ksef_podmiot3_pick");
                            var id = parseInt($pick.val(), 10);
                            if (!id || id <= 0) return false;
                            var name = $.trim($pick.find("option:selected").text());
                            var $role = $("#ksef_podmiot3_role_pick");
                            var role = $role.val();
                            var label = $.trim($role.find("option:selected").text());
                            var udzial = (role === "4") ? $.trim($("#ksef_podmiot3_udzial_pick").val()) : "";
                            if (udzial !== "") { label = label + " (" + udzial + "%)"; }
                            if (addRow(id, role, name, label, udzial)) {
                                $pick.val("").trigger("change");
                                $("#ksef_podmiot3_udzial_pick").val("");
                                return true;
                            }
                            return false;
                        }
                        jQuery(function($) {
                            existing.forEach(function(e){ addRow(e.id, e.role, e.name, e.label, e.udzial); });
                            $("#ksef_podmiot3_role_pick").on("change", toggleUdzial);
                            toggleUdzial();
                            $("#ksef_p3_add").on("click", function() {
                                commitPick($);
                            });
                            $("#ksef_p3_save").on("click", function() {
                                commitPick($);
                                document.getElementById("ksef_p3_form").submit();
                            });
                        });
                    })();
                    </script>';
                }
                print '</td></tr>';
            }

            $submission = new KsefSubmission($db);
            $result = (!empty($object) && !empty($object->id)) ? $submission->fetchByInvoice($object->id) : 0;

            if ($result > 0) {
                $langs->load("ksef@ksef");

                $shownStatus = ksefDisplayStatus($submission);
                print '<tr class="ksef-status-row" data-ksef-status="' . dol_escape_htmltag($shownStatus) . '" data-ksef-offline="' . (!empty($submission->offline_mode) ? 1 : 0) . '"><td class="titlefieldcreate">'
                    . $langs->trans("KSEF_Status") . '</td><td colspan="3">';
                print ksefGetStatusBadge($shownStatus);
                if ((string) $submission->environment !== ksefGetEnvironment()) {
                    print ' ' . ksefGetEnvironmentBadge((string) $submission->environment);
                    print ' <span class="opacitymedium ksef-other-env-note">' . $langs->trans('KSEF_RowOtherEnvNote', ksefEnvironmentLabel((string) $submission->environment), ksefEnvironmentLabel(ksefGetEnvironment())) . '</span>';
                }

                if (in_array($submission->status, array('REJECTED', 'FAILED'))) {
                    print ' <span class="fas fa-exclamation-triangle ksef-error-icon classfortooltip" title="' . htmlspecialchars(ksefErrorTooltip($submission->error_code, $submission->error_message), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></span>';
                }

                if (!empty($submission->ksef_number)) {
                    $is_online_ksef_number = ksefIsRealKsefNumber($submission->ksef_number);

                    if ($is_online_ksef_number) {
                        $verifyUrl = ksefGetVerificationURL($submission->ksef_number, ksefSubmissionHash($submission), $submission->environment, null, null, $submission->fa3_xml);
                        if ($verifyUrl !== '') {
                            print ' <a href="' . $verifyUrl . '" target="_blank" style="text-decoration: none;"><span class="badge badge-info ' . ksefToneClass('id') . '">' . ksefEscapeText($submission->ksef_number) . '</span></a>';
                        } else {
                            print ' <span class="badge badge-info ' . ksefToneClass('id') . '">' . ksefEscapeText($submission->ksef_number) . '</span>';
                        }
                    } else {
                        print ' <span class="badge badge-secondary" title="' . $langs->trans('KSEF_OfflineNumberNotYetSubmitted') . '">' . ksefEscapeText($submission->ksef_number) . '</span>';
                    }
                }

                if (!empty($submission->offline_mode)) {
                    $issuedOn = $submission->date_submission;
                    $resIss = $this->db->query("SELECT MIN(date_submission) AS d FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $object->id
                        . " AND offline_mode IS NOT NULL AND offline_mode <> '' AND environment = '" . $this->db->escape((string) $submission->environment) . "'");
                    if ($resIss && ($oIss = $this->db->fetch_object($resIss)) && !empty($oIss->d)) {
                        $issuedOn = (int) $oIss->d; // unix time column
                    }
                    if (!empty($issuedOn)) {
                        print '<br><small class="ksef-issued-offline opacitymedium">' . $langs->trans("KSEF_IssuedOfflineOn") . ': ' . dol_print_date($issuedOn, 'dayhour') . '</small>';
                    }
                    $dlInfo = in_array($submission->status, array('OFFLINE', 'PENDING'), true) && !empty($submission->offline_deadline)
                        ? ksefOfflineDeadlineForInvoice($this->db, $object->id, $submission->environment, $submission->offline_deadline, $object->date) : null;
                    if ($dlInfo && $dlInfo['state'] !== 'due') {
                        print '<br><small class="ksef-offline-deadline classfortooltip opacitymedium" title="' . dol_escape_htmltag(ksefOfflineDeadlineNote($dlInfo, true)) . '">' . $langs->trans('KSEF_OfflineDeadline') . ': ' . ksefOfflineDeadlineNote($dlInfo) . '</small>';
                    } elseif ($dlInfo) {
                        $moved = ksefOfflineDeadlineNote($dlInfo);
                        print '<br><small class="ksef-offline-deadline opacitymedium' . ($moved !== '' ? ' classfortooltip' : '') . '"' . ($moved !== '' ? ' title="' . dol_escape_htmltag($moved) . '"' : '') . '>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . dol_print_date($dlInfo['deadline'], 'dayhour') . '</small>';
                        $hours_remaining = ($dlInfo['deadline'] - dol_now()) / 3600;
                        if (ksefIsDeadlinePassed($dlInfo['deadline'])) {
                            print ' <small class="classfortooltip ' . ksefToneTextClass('problem') . '" id="ksef-offline-overdue" title="' . dol_escape_htmltag($langs->trans('KSEF_OfflineDeadlinePassed')) . '"><i class="fas fa-exclamation-triangle"></i> ' . $langs->trans('KSEF_OfflineOverdue') . '</small>';
                        } elseif ($hours_remaining < 8) {
                            print ' <small class="ksef-hours-remaining ' . ksefToneTextClass('check') . '"><i class="fas fa-clock"></i> ' . $langs->trans('KSEF_HoursRemaining', max(1, (int) round($hours_remaining))) . '</small>';
                        }
                    }
                    if ($submission->status == 'ACCEPTED' && !empty($submission->date_acceptance)) {
                        print '<br><small class="ksef-sent-to-ksef opacitymedium">' . $langs->trans("KSEF_SentToKsefOn") . ': ' . dol_print_date($submission->date_acceptance, 'dayhour') . '</small>';
                    }
                } elseif (!empty($submission->date_submission)) {
                    $onKey = $submission->source === KsefSubmission::SOURCE_LINKED_FROM_SYNC ? 'KSEF_InKsefSinceSync' : 'KSEF_SubmittedOn';
                    print '<br><small class="opacitymedium">' . $langs->trans($onKey) . ': ' . dol_print_date($submission->date_submission, 'dayhour') . '</small>';
                }
                if ($submission->status == 'ACCEPTED' && in_array($submission->source, array(KsefSubmission::SOURCE_ADOPTED_440, KsefSubmission::SOURCE_ADOPTED_MANUAL), true)) {
                    print '<br><small class="ksef-adopted-note opacitymedium">' . $langs->trans('KSEF_AdoptedDuplicate', ksefEscapeText($submission->ksef_number)) . '</small>';
                }
                if ($shownStatus == 'TIMEOUT' || $shownStatus == 'INTERRUPTED') {
                    print '<br><small class="ksef-timeout-hint ' . ksefToneTextClass('check') . '">' . ksefTimeoutHint($submission) . '</small>';
                }
                print ksefHashStopNoteHtml($db, $submission);
                if ($submission->isDuplicate()) {
                    $orig = $submission->getDuplicateOriginal();
                    $dd = $submission->getErrorDetailsArray();
                    $hint = ($orig['ksef_number'] === '') ? $langs->trans('KSEF_DuplicateNoOriginal')
                        : (($dd['adopt'] ?? '') === 'hash_mismatch' ? $langs->trans('KSEF_DuplicateNotOurs', ksefEscapeText($orig['ksef_number'])) : $langs->trans('KSEF_RetryRefusedDuplicate'));
                    $next = ksefDuplicateNextStep($orig['ksef_number'] === '' ? 'no_original' : (string) ($dd['link_refused']['reason'] ?? ($dd['adopt'] ?? '')));
                    print '<br><small class="ksef-duplicate-hint ' . ksefToneTextClass('check') . '">' . $hint . '</small>';
                    print '<br><small class="ksef-duplicate-next" style="color: var(--ksef-text-muted, #555);"><i class="fa fa-arrow-right"></i> ' . ksefEscapeText($next) . '</small>';
                }

                if (in_array($submission->status, array('REJECTED', 'FAILED'), true) && !$submission->isDuplicate()) {
                    $why = ksefShortSubmissionError($submission->error_code, $submission->error_message);
                    if ($why !== '') {
                        print '<br><small class="ksef-failure-text ' . ksefToneTextClass('problem') . '">' . ksefEscapeText($why) . '</small>';
                        if ($user->hasRight('ksef', 'read')) {
                            print ' <small><a class="ksef-failure-details" href="' . dol_buildpath('/ksef/tab_ksef.php', 1) . '?id=' . (int) $object->id . '">' . $langs->trans('KSEF_SeeKsefTabForDetails') . '</a></small>';
                        }
                    }
                }

                $issuedRow = ($submission->status == 'ACCEPTED' && ksefIsKsefNumber((string) $submission->ksef_number))
                    || ($submission->status == 'OFFLINE' && !empty($submission->fa3_xml));
                if ($issuedRow && ksefUserCanReadInvoice($user, $object->id)) {
                    $dl = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '&sub_id=' . (int) $submission->rowid . '&token=' . newToken() . '&action=';
                    print '<br><span class="ksef-card-downloads">';
                    print '<a class="ksef-download-xml" href="' . $dl . 'ksef_download_xml"><i class="fas fa-file-code"></i> ' . $langs->trans('KSEF_DownloadXml') . '</a>';
                    if ($submission->status == 'ACCEPTED') {
                        print ' &nbsp; <a class="ksef-download-upo classfortooltip" href="' . $dl . 'ksef_download_upo" title="' . dol_escape_htmltag(ksefUpoDownloadTitle($submission)) . '"><i class="fas fa-certificate"></i> ' . $langs->trans('KSEF_DownloadUPO') . '</a>';
                    }
                    print '</span>';
                }

                if ($submission->status == 'ACCEPTED' && getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE')) {
                    $mainRef = dol_sanitizeFileName($object->ref);
                    $mainPdf = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . $mainRef . '/' . $mainRef . '.pdf';
                    $acceptedOn = (int) ($submission->date_acceptance ?: $submission->date_submission);
                    if (is_file($mainPdf) && $acceptedOn > 0 && filemtime($mainPdf) < $acceptedOn) {
                        print '<br><small class="ksef-main-pdf-stale ' . ksefToneTextClass('check') . '"><i class="fas fa-info-circle"></i> ' . $langs->trans('KSEF_MainPdfPredatesKsef') . '</small>';
                    }
                }

                print '</td></tr>';

                if (!empty($submission->ksef_number)) {
                    print '<style>a[href*="attribute=ksef_sale_date"], a[href*="attribute=ksef_kurs_data"] { display: none !important; }</style>';
                }
            } elseif ($result < 0) {
                $langs->load("ksef@ksef");
                print '<tr class="ksef-status-row"><td class="titlefieldcreate">' . $langs->trans("KSEF_Status") . '</td><td colspan="3">';
                print '<span class="warning ksef-record-read-failed">' . ksefEscapeText(ksefDbErrorForUser($user, 'KSEF_SubmissionRecordReadFailed', $submission->error)) . '</span>';
                print '</td></tr>';
            } elseif (!empty($object) && !empty($object->id) && ($numberWithoutRow = ksefNumberWithoutRow($db, $object->id)) !== '') {
                $langs->load("ksef@ksef");
                print '<tr class="ksef-status-row"><td class="titlefieldcreate">' . $langs->trans("KSEF_Status") . '</td><td colspan="3">';
                print '<span class="warning ksef-number-without-row">' . $langs->trans('KSEF_KsefNumberWithoutRecord', ksefEscapeText($numberWithoutRow)) . '</span>';
                if (ksefUserCan($user, 'link_ksef_number')) {
                    print ' <a class="butAction smallpaddingimp ksef-row-button ksef-link-number" href="' . $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '&action=ksef_link_number&ksef_number=' . urlencode($numberWithoutRow) . '&token=' . newToken() . '"><i class="fas fa-link"></i> ' . $langs->trans('KSEF_LinkKsefNumber') . '</a>';
                }
                print '</td></tr>';
            }

            $ctx = (!empty($object) && !empty($object->id)) ? $this->ksefComputeDodatkowyOpisContext($object) : array('featureActive' => false);
            if ($ctx['featureActive']) {
                print '<tr id="ksef_dodatkowy_preview_row"><td class="titlefieldcreate">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW") . '</td><td colspan="3">';
                print $this->ksefRenderDodatkowyOpisPreviewInner($object);
                print '</td></tr>';
                print '<script type="text/javascript">
jQuery(document).ready(function() {
    var modeRow = jQuery("tr").has(".facture_extras_ksef_dodatkowy_opis_mode");
    if (!modeRow.length) return;
    var previewRow = jQuery("#ksef_dodatkowy_preview_row");
    if (previewRow.length) {
        modeRow.detach().insertBefore(previewRow).show();
    }
});
</script>';

                print $this->ksefRenderDodatkowyOpisHelperScript($object);
            }

            if (GETPOST('action', 'aZ09') == 'create') {
                $langs->load("ksef@ksef");
                $hintCreditNote = dol_escape_js($langs->trans('KSEF_HintCreditNote'));
                $hintReplacement = dol_escape_js($langs->trans('KSEF_HintReplacement'));
                print '<script type="text/javascript">
jQuery(document).ready(function() {
    // below the row
    var creditRadio = jQuery("#radio_creditnote");
    if (creditRadio.length) {
        creditRadio.closest(".listofinvoicetype").after("<div class=\"ksef-type-hint\">' . $hintCreditNote . '</div>");
    }
    var replaceRadio = jQuery("#radio_replacement");
    if (replaceRadio.length) {
        replaceRadio.closest(".listofinvoicetype").after("<div class=\"ksef-type-hint\">' . $hintReplacement . '</div>");
    }
});
</script>';

                $socid = GETPOST('socid', 'int');
                $sources = ($socid > 0) ? ksefCorrectionSources($db, $socid) : false;
                $pickWarn = ($socid > 0) ? ksefPickSourceWarnings($db, $socid) : array();
                if (!empty($sources)) {
                    $facturestatic = new Facture($db);
                    $picker = array();
                    $picker_labels = array();
                    $want = GETPOSTINT('fac_replacement');
                    $pickKinds = ksefPickKindsOfRows($db, array('kor', 'korzal'), array_keys($sources));
                    foreach ($sources as $s) {
                        if ($s['eligible']) {
                            if ($s['via'] == 'paid' && (int) $s['type'] !== Facture::TYPE_REPLACEMENT) {
                                $label = $s['ref'] . ' (' . $langs->trans('Paid') . ')';
                            } elseif ($s['via'] == 'paid') {
                                $label = $s['ref'] . ' (' . $langs->trans('KSEF_KorektaOfKorekta') . ')';
                            } elseif ($s['via'] == 'kor') {
                                $label = $s['ref'] . ' (' . $langs->trans('KSEF_KorektaOfKorekta') . ')';
                            } elseif ($s['via'] == 'note') {
                                $label = $s['ref'] . ' (' . $langs->trans('KSEF_CorrSrcNoteLabel') . ')';
                            } else {
                                $label = $s['ref'] . ' (' . $facturestatic->LibStatut($s['paye'], $s['status'], 0, $s['has_payment'] ? 1 : 0) . ')';
                            }
                        } else {
                            $label = $s['ref'] . ' - ' . $langs->transnoentitiesnoconv($s['reason'], $s['child_ref']);
                        }
                        $otherEnv = $this->acceptedOnlyElsewhere((int) $s['id']);
                        if ($otherEnv !== '') {
                            $label .= ' - ' . $langs->transnoentitiesnoconv('KSEF_CorrSrcOtherEnvOnly', ksefEnvironmentLabel($otherEnv), ksefEnvironmentLabel(ksefGetEnvironment()));
                        }
                        // not on PRODUCTION
                        $offProd = $otherEnv !== '' && $otherEnv !== 'PRODUCTION' && ksefGetEnvironment() === 'PRODUCTION';
                        // not accepted here
                        $notHere = $s['eligible'] && isset($pickWarn[$s['id']]) && (int) $s['id'] !== $want;
                        if ($notHere && $otherEnv === '') {
                            $label .= ' - ' . $langs->transnoentitiesnoconv('KSEF_CorrSrcNotInKsef', ksefEnvironmentLabel(ksefGetEnvironment()));
                        }
                        $picker[] = array('id' => $s['id'], 'date' => $s['date'], 'eligible' => ($s['eligible'] && !$offProd && !$notHere) ? 1 : 0, 'here' => $otherEnv === '' ? 1 : 0,
                            'pick' => implode(' ', $pickKinds[(int) $s['id']] ?? array()), 'label' => dol_string_nohtmltag($label));
                        $picker_labels[$s['id']] = dol_string_nohtmltag($label);
                    }
                    $js_sources = json_encode($picker, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    $notice = '';
                    if ($want > 0 && isset($sources[$want]) && !$sources[$want]['eligible']) {
                        $notice = dol_escape_htmltag($picker_labels[$want] ?? '');
                        foreach (ksefCorrectionChildLinks($sources[$want], $user) as $lnk) {
                            $notice .= ' <a class="' . ($lnk['delete'] ? 'ksef-correction-child-delete' : 'ksef-correction-child') . '" href="' . dol_escape_htmltag(DOL_URL_ROOT . $lnk['href']) . '">'
                                . ($lnk['delete'] ? img_picto('', 'delete', 'class="pictofixedwidth"') : img_picto('', 'bill', 'class="pictofixedwidth"')) . dol_escape_htmltag($lnk['label']) . '</a>';
                        }
                    }
                    $js_notice = json_encode($notice, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    $noteBlocks = array();
                    foreach ($sources as $s) {
                        $nl = ($s['eligible'] && $s['notes']) ? ksefCorrectionNotes($db, $s['id']) : false;
                        if (!$nl) {
                            continue;
                        }
                        $refs = implode(', ', array_column($nl, 'ref'));
                        $free = array_sum(array_column($nl, 'free'));
                        $html = '<label><input type="radio" name="ksef_kor_base" value="k" checked> ' . $langs->trans('KSEF_KorBaseKeep', $refs) . '</label>'
                            . '<div class="opacitymedium ksef-kor-keep-hint">' . $langs->trans('KSEF_KorBaseKeepHint', $refs, price(array_sum(array_column($nl, 'ttc')), 0, $langs, 1, -1, -1, $conf->currency)) . '</div>';
                        if ($free > 0.005) {
                            $html .= '<div class="ksef-kor-credit" style="margin-left: 24px;"><label><input type="radio" name="ksef_kor_credit" value="use" checked> '
                                . $langs->trans('KSEF_KorCreditUse', $refs, price($free, 0, $langs, 1, -1, -1, $conf->currency)) . '</label><br>'
                                . '<label><input type="radio" name="ksef_kor_credit" value="keep"> ' . $langs->trans('KSEF_KorCreditKeep') . '</label></div>';
                        }
                        $corrected = array_filter(array_column($nl, 'corrected'));
                        $noteBlocks[$s['id']] = $html . ($corrected ? '<div class="opacitymedium ksef-kor-absorb-refused">' . $langs->trans('KSEF_KorBaseAbsorbRefused', implode(', ', $corrected)) . '</div>'
                            : '<label><input type="radio" name="ksef_kor_base" value="a"> ' . $langs->trans('KSEF_KorBaseAbsorb', $refs) . '</label>');
                    }
                    $js_blocks = json_encode((object) $noteBlocks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    print '<script type="text/javascript">
jQuery(document).ready(function() {
    var sources = ' . $js_sources . ';
    var noteBlocks = ' . $js_blocks . ';
    var sel = jQuery("#fac_replacement");
    if (!sel.length) sel = jQuery("select[name=\'fac_replacement\']");
    if (!sel.length) {
        // INVOICE_DISABLE_REPLACEMENT (#38)
        var off = ' . json_encode((getDolGlobalString('INVOICE_DISABLE_REPLACEMENT') && (GETPOSTINT('type') === Facture::TYPE_REPLACEMENT || GETPOSTINT('fac_replacement') > 0))
            ? $langs->trans('KSEF_CorrectionDisabledByCore') : '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        if (off) {
            var offBox = jQuery("<div>", { "class": "warning ksef-correction-disabled-notice" }).text(off);
            var anchor = jQuery("#radio_standard").closest(".listofinvoicetype");
            if (anchor.length) {
                anchor.before(offBox);
            } else {
                jQuery("form[name=\'add\']").first().prepend(offBox);
            }
        }
        return;
    }

    var selectable = 0;
    for (var i = 0; i < sources.length; i++) {
        var s = sources[i];
        var opt = sel.find("option[value=\'" + s.id + "\']");
        if (!opt.length) {
            opt = jQuery("<option>", { value: s.id, text: s.label });
            if (s.eligible) {
                opt.attr("data-ksef-added", "1");
            } else {
                opt.prop("disabled", true).attr("data-ksef-reason", "1");
            }
            sel.append(opt);
        } else if (!s.eligible) {
            // module refuses it
            if (opt.prop("selected")) {
                opt.prop("selected", false);
                sel.val("-1");
            }
            opt.prop("disabled", true).attr("data-ksef-reason", "1").text(s.label);
        }
        opt.attr("data-ksef-date", s.date);
        if (s.pick) opt.attr("data-ksef-pick", s.pick);
        if (!opt.prop("disabled")) selectable++;
    }
    if (!selectable) {
        sel.closest(".listofinvoicetype").after(jQuery("<div>", { "class": "warning ksef-correction-none" }).text(' . json_encode($langs->transnoentitiesnoconv('KSEF_CorrSrcNoneEligible', ksefEnvironmentLabel(ksefGetEnvironment())), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . '));
    }

    // newest first
    var opts = sel.find("option").filter(function() { return jQuery(this).val() !== "-1"; }).get();
    opts.sort(function(a, b) {
        var da = a.hasAttribute("data-ksef-date") ? parseInt(a.getAttribute("data-ksef-date"), 10) : -Infinity;
        var db = b.hasAttribute("data-ksef-date") ? parseInt(b.getAttribute("data-ksef-date"), 10) : -Infinity;
        if (da !== db) return db - da;
        return parseInt(b.value, 10) - parseInt(a.value, 10);
    });
    var keep = sel.val();
    var empty = sel.find("option[value=\'-1\']");
    if (selectable > 0) {
        // core empty-list text
        empty.remove();
        sel.prepend(jQuery("<option>", { value: "-1", text: " " }));
        sel.prop("disabled", false);
        jQuery("#radio_replacement").prop("disabled", false);
    } else if (opts.length) {
        // keep reasons readable
        sel.prop("disabled", false);
    }
    for (var j = 0; j < opts.length; j++) {
        sel.append(opts[j]);
    }
    // keep the choice
    sel.val(keep && sel.find("option[value=\'" + keep + "\']:not(:disabled)").length ? keep : "-1");
    if (parseInt(sel.val(), 10) > 0) {
        jQuery("#radio_replacement").prop("checked", true);
    }

    try {
        if (sel.data("select2")) {
            sel.select2("destroy");
        }
        sel.select2({
            dir: "ltr",
            width: "resolve",
            minimumInputLength: 0,
            language: (typeof select2arrayoflanguage === "undefined") ? "en" : select2arrayoflanguage,
            theme: "default",
            containerCssClass: ":all:",
            selectionCssClass: ":all:",
            dropdownCssClass: "ui-dialog"
        });
    } catch(e) {
        console.warn("KSeF: Select2 refresh failed, dropdown may not be searchable: " + e.message);
    }

    // source credit choice
    sel.on("change", function() {
        jQuery("#ksef-kor-notes").remove();
        var b = noteBlocks[sel.val()];
        if (!b) return;
        var box = jQuery("<div>", { id: "ksef-kor-notes", "class": "info ksef-kor-notes" }).html(b);
        box.on("change", "input[name=ksef_kor_base]", function() {
            box.find(".ksef-kor-credit, .ksef-kor-keep-hint").toggle(jQuery(this).val() === "k");
        });
        sel.closest(".listofinvoicetype").after(box);
    });

    var urlParams = new URLSearchParams(window.location.search);
    var preselect = urlParams.get("fac_replacement");
    var want = preselect ? sel.find("option[value=\'" + preselect + "\']") : jQuery();
    if (want.length && !want.prop("disabled")) {
        sel.val(preselect).trigger("change");
        jQuery("#radio_replacement").prop("checked", true).trigger("change");
    } else if (want.length) {
        // why not correctable
        var note = jQuery("<div>", { "class": "warning ksef-correction-source-notice" });
        var noticeHtml = ' . $js_notice . ';
        if (noticeHtml) {
            note.html(noticeHtml);
        } else {
            note.text(want.text());
        }
        sel.closest(".listofinvoicetype").after(note);
    }
});
</script>';
                }
                if ($pickWarn) {
                    print '<script type="text/javascript">
jQuery(function() {
    var warn = ' . json_encode((object) $pickWarn, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
    function show(sel) {
        var id = sel.attr("id");
        jQuery(".ksef-pick-source-warning[data-ksef-pick-for=\'" + id + "\']").remove();
        var w = warn[sel.val()];
        if (w) {
            sel.closest(".listofinvoicetype").after(jQuery("<div>", { "class": "warning ksef-pick-source-warning", "data-ksef-pick-for": id }).text(w));
        }
    }
    jQuery("#fac_replacement, #fac_avoir").each(function() {
        show(jQuery(this));
    }).on("change", function() {
        show(jQuery(this));
    });
});
</script>';
                }
            }
        }

        if ($currentcontext == 'invoicesuppliercard') {
            if (empty($object) || empty($object->id)) {
                return 0;
            }

            $langs->load("ksef@ksef");

            $this->printCorrectionChain($object);
            $this->printSetoffDates($object, $action);
            $this->printCloseNote($object);
            $this->printForeignOwedRow($object);
            $this->printDepositCreditPlnNote($object);

            print '<style>tr:has(.invoice_supplier_extras_ksef_number), tr:has(.invoice_supplier_extras_ksef_status), tr:has(.invoice_supplier_extras_ksef_submission_date), tr:has(.invoice_supplier_extras_ksef_kurs_data) { display: none !important; }</style>';

            print '<script type="text/javascript">
jQuery(document).ready(function() {
    var saleDateRow = jQuery("tr").has(".invoice_supplier_extras_ksef_sale_date");
    if (!saleDateRow.length) return;
    var dateRow = jQuery("a[href*=\'action=editinvoicedate\']").closest("table.nobordernopadding").closest("tr");
    if (!dateRow.length) {
        var label = ' . json_encode($langs->transnoentities("DateInvoice")) . ';
        dateRow = jQuery("table.border.tableforfield > tbody > tr, table.border.centpercent.tableforfield > tbody > tr").filter(function() {
            return jQuery(this).children("td").first().text().trim() === label;
        }).first();
    }
    if (dateRow.length) {
        saleDateRow.detach().insertAfter(dateRow).show();
    }
});
</script>';

            dol_include_once('/ksef/class/ksef_incoming.class.php');
            $incomingTot = new KsefIncoming($db);
            if ($incomingTot->fetchBySupplierInvoice($object->id) > 0) {
                $sellerPln = ksefIncomingSellerPlnWarning($incomingTot, $object);
                if ($sellerPln !== '') {
                    print '<tr><td class="titlefieldcreate">KSeF</td><td colspan="3"><span class="warning ksef-seller-pln-vat">'
                        . '<span class="fa fa-exclamation-triangle paddingright"></span>' . dol_escape_htmltag($sellerPln) . '</span></td></tr>';
                }
                $kursWarn = ksefIncomingKursWarning($incomingTot, $object);
                if ($kursWarn !== '') {
                    print '<tr><td class="titlefieldcreate">KSeF</td><td colspan="3"><span class="warning ksef-no-kurs">'
                        . '<span class="fa fa-exclamation-triangle paddingright"></span>' . dol_escape_htmltag($kursWarn) . '</span></td></tr>';
                }
            }

            $incomingCorr = new KsefIncoming($db);
            if ($incomingCorr->fetchBySupplierInvoice($object->id) > 0 && KsefIncoming::isCorrectionType($incomingCorr->invoice_type)) {
                $correctionData = $incomingCorr->getCorrectionData();
                $resolvedCorrections = $incomingCorr->resolveCorrectedInvoices();
                $correctedCount = count($resolvedCorrections);

                $creditNoteInvoice = null;
                $linkedInvoice = null;
                $isReplaceMode = false;

                if ($incomingCorr->fk_credit_note > 0) {
                    $creditNoteInvoice = new FactureFournisseur($db);
                    if ($creditNoteInvoice->fetch($incomingCorr->fk_credit_note) <= 0) {
                        $creditNoteInvoice = null;
                    }
                }
                if ($incomingCorr->fk_facture_fourn > 0 && $incomingCorr->fk_facture_fourn != $object->id) {
                    $linkedInvoice = new FactureFournisseur($db);
                    if ($linkedInvoice->fetch($incomingCorr->fk_facture_fourn) <= 0) {
                        $linkedInvoice = null;
                    }
                }
                $isReplaceMode = ($creditNoteInvoice !== null && ($linkedInvoice !== null || $incomingCorr->fk_facture_fourn == $object->id));

                if ($isReplaceMode) {
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_ImportMode") . '</td>';
                    print '<td colspan="3"><span class="badgeneutral">' . $langs->trans("KSEF_UpwardCorrectionReplace") . '</span></td></tr>';
                }

                if ($correctedCount == 1) {
                    $rc = $resolvedCorrections[0];
                    $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $incomingCorr->ksef_number);
                    print '<tr class="ksef-sup-corrects"><td class="titlefieldcreate">' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td colspan="3">';

                    print $langs->trans("KSEF_CorrectsKsefNr") . ': ';
                    if ($rc['incoming']) {
                        print $rc['incoming']->getNomUrl(1);
                    } elseif (!empty($rc['ksef_number'])) {
                        print dol_escape_htmltag($rc['ksef_number']);
                    } else {
                        print '<span class="opacitymedium">-</span>';
                    }
                    if ($isSelfReference) {
                        print '<br><span class="warning"><span class="fas fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectedKsefNumberSameAsOwn") . '</span>';
                    }

                    if (!empty($rc['invoice_number'])) {
                        print '<br>' . $langs->trans("KSEF_VendorRef") . ': ';
                        print dol_escape_htmltag($rc['invoice_number']);
                    }

                    if ($rc['supplier_invoice']) {
                        print '<br>' . $langs->trans("KSEF_ImportedAs") . ': ';
                        print $rc['supplier_invoice']->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($rc['supplier_invoice'], 5);
                    }

                    print '</td></tr>';
                } elseif ($correctedCount > 1) {
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_CorrectedInvoices") . '</td><td colspan="3">';
                    print $langs->trans("KSEF_CorrectsXInvoices", $correctedCount);
                    print '</td></tr>';
                }

                if (!empty($correctionData['reason'])) {
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_CorrectionReason") . '</td>';
                    print '<td colspan="3">' . ksefHtmlMultiline($correctionData['reason']) . '</td></tr>';
                }

                if ($isReplaceMode) {
                    if ($creditNoteInvoice !== null) {
                        print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_ReplaceCreditNote") . '</td>';
                        print '<td colspan="3">' . $creditNoteInvoice->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($creditNoteInvoice, 5) . '</td></tr>';
                    }
                    if ($linkedInvoice !== null) {
                        print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_ReplaceNewInvoice") . '</td>';
                        print '<td colspan="3">' . $linkedInvoice->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($linkedInvoice, 5) . '</td></tr>';
                    }
                }
            }

            $ksefNumber = $object->array_options['options_ksef_number'] ?? '';

            dol_include_once('/ksef/lib/ksef.lib.php');

            if ($incomingCorr->id > 0 || !empty($ksefNumber)) {
                print '<tr class="ksef-sup-received" data-ksef-type="' . dol_escape_htmltag((string) $incomingCorr->invoice_type) . '"><td class="titlefieldcreate">' . $langs->trans("KSEF_ReceivedFromKsef") . '</td><td colspan="3">';
                $num = $incomingCorr->id > 0 ? (string) $incomingCorr->ksef_number : (string) $ksefNumber;
                if ($incomingCorr->id > 0) {
                    print $incomingCorr->getNomUrl(2) . ' ';
                    $recEnv = !empty($incomingCorr->environment) ? $incomingCorr->environment : ksefGetEnvironment();
                    $verifyUrl = !empty($incomingCorr->fa3_xml) ? ksefGetVerificationUrlFromXml($num, $incomingCorr->fa3_xml, $recEnv) : '';
                    if ($verifyUrl !== '') {
                        print '<a href="' . $verifyUrl . '" target="_blank" style="text-decoration: none;"><span class="badge badge-info ' . ksefToneClass('id') . '">' . dol_escape_htmltag($num) . '</span></a>';
                    } else {
                        print '<span class="badge badge-info ' . ksefToneClass('id') . '">' . dol_escape_htmltag($num) . '</span>';
                    }
                    if (!empty($incomingCorr->fetch_date)) {
                        print '<br><small class="opacitymedium">' . $langs->trans("KSEF_FetchedOn") . ': ' . dol_print_date($incomingCorr->fetch_date, 'dayhour') . '</small>';
                    }
                } else {
                    print '<span class="badge badge-info ' . ksefToneClass('id') . '">' . dol_escape_htmltag($num) . '</span>';
                }
                print '<style>a[href*="attribute=ksef_kurs_data"] { display: none !important; }</style>';
                print '</td></tr>';
            } else {
                $zeroOf = new KsefIncoming($db);
                if ($zeroOf->fetchByCreditNote($object->id) > 0) {
                    print '<tr><td class="titlefieldcreate">KSeF</td><td colspan="3"><span class="opacitymedium">'
                        . $langs->trans('KSEF_ZeroingCorrectionOf', $zeroOf->getNomUrl(1)) . '</span></td></tr>';
                }
            }

            if (!empty($ksefNumber) && $incomingCorr->id > 0 && !empty($incomingCorr->payment_status) && $incomingCorr->payment_status !== 'unpaid') {
                $notSpecified = '<span class="opacitymedium">' . $langs->trans("KSEF_NotSpecifiedInXml") . '</span>';

                print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_PaymentStatusSeller") . '</td><td colspan="3">';
                if ($incomingCorr->payment_status === 'paid') {
                    print '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPaid") . '</span>';
                } elseif ($incomingCorr->payment_status === 'paid_installments') {
                    print '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPaidInstallments") . '</span>';
                } elseif ($incomingCorr->payment_status === 'partial') {
                    print '<span class="badge ' . ksefToneBadgeClass('action') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPartial") . '</span>';
                }
                if (empty($object->paye) && (float) $object->getSommePaiement() == 0) {
                    print ' <span class="opacitymedium small ksef-seller-paid-note">' . $langs->trans("KSEF_SellerPaidNotRecorded") . '</span>';
                }
                print '</td></tr>';

                print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_PaymentDate") . '</td><td colspan="3">';
                print $incomingCorr->payment_date ? dol_print_date($incomingCorr->payment_date, 'day') : $notSpecified;
                print '</td></tr>';

                if (empty($object->mode_reglement_id)) {
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_PaymentMethod") . '</td><td colspan="3">';
                    $methodLabel = ksefGetPaymentMethodLabel($incomingCorr->payment_method);
                    print $methodLabel ? dol_escape_htmltag($methodLabel) : $notSpecified;
                    print '</td></tr>';
                }

                print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_PaymentAmount") . '</td><td colspan="3">';
                print price($incomingCorr->total_gross, 0, $langs, 0, -1, -1, $incomingCorr->currency);
                print '</td></tr>';

                $payPlan = ksefIncomingPayPlan($incomingCorr, $object);
                if ($payPlan['settlement'] !== null) {
                    $st = $payPlan['settlement'];
                    print '<tr class="ksef-pay-due"><td class="titlefieldcreate"><span class="classfortooltip" title="' . dol_escape_htmltag($langs->trans('KSEF_PayKsefDueHelp')) . '">'
                        . $langs->trans('KSEF_PayKsefDue') . ' <span class="fa fa-info-circle opacitymedium"></span></span></td><td colspan="3">';
                    print $st['to_settle'] !== null ? '0' : price($payPlan['due'], 0, $langs, 0, -1, 2, $payPlan['currency']);
                    $parts = array($langs->trans('KSEF_PayKsefTotal') . ' ' . price((float) $payPlan['p15'], 0, $langs, 0, -1, 2));
                    foreach ($st['charge_rows'] as $r) {
                        $parts[] = '+ ' . dol_escape_htmltag((string) $r['reason']) . ' ' . price((float) $r['amount'], 0, $langs, 0, -1, 2);
                    }
                    foreach ($st['deduction_rows'] as $r) {
                        $parts[] = '- ' . dol_escape_htmltag((string) $r['reason']) . ' ' . price((float) $r['amount'], 0, $langs, 0, -1, 2);
                    }
                    if ($parts) {
                        print ' <span class="opacitymedium">(' . implode('; ', $parts) . ')</span>';
                    }
                    if (!$payPlan['verified'] && $st['to_settle'] === null) {
                        print '<br><span class="warning">' . ($st['to_pay'] === null ? $langs->trans('KSEF_PayDueMissing', price($st['computed'], 0, $langs, 0, -1, 2, $payPlan['currency']))
                            : $langs->trans('KSEF_PayDueMismatch', price($st['to_pay'], 0, $langs, 0, -1, 2, $payPlan['currency']), price($st['computed'], 0, $langs, 0, -1, 2, $payPlan['currency']))) . '</span>';
                    }
                    print '</td></tr>';
                }
            }

            print '<script type="text/javascript">
jQuery(document).ready(function() {
    var corrRows = jQuery("tr").has("td:contains(\'' . dol_escape_js($langs->trans("KSEF_CorrectsInvoice")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_CorrectedInvoices")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_ImportMode")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_CorrectionReason")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_ReplaceCreditNote")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_ReplaceNewInvoice")) . '\')");
    if (!corrRows.length) return;
    // Find the invoice type row to insert after
    var anchorRow = jQuery("table.border.tableforfield > tbody > tr, table.border.centpercent.tableforfield > tbody > tr").filter(function() {
        var label = jQuery(this).children("td").first().text().trim();
        return label === ' . json_encode($langs->transnoentities("Type")) . ';
    }).first();
    if (anchorRow.length) {
        corrRows.detach().insertAfter(anchorRow);
    }
});
</script>';
        }

        if ($currentcontext == 'thirdpartycard') {
            if (!is_object($object) || empty($object->id)) {
                return 0;
            }

            $langs->load("ksef@ksef");

            print '<tr class="oddeven">';
            print '<td>' . $langs->trans("KSEF_ExcludeFromKSEF") . '</td>';
            print '<td colspan="3">';

            $client_type = isset($object->client) ? (int)$object->client : 0;
            $is_customer = in_array($client_type, array(1, 3));

            if (!$is_customer) {
                print '<span class="opacitymedium"><i class="fa fa-info-circle"></i> ' . $langs->trans("KSEF_NotApplicableNotCustomer") . '</span>';
            } else {
                $is_excluded = ksefIsCustomerExcluded($object->id);

                if ($action == 'edit' || $action == 'create') {
                    print '<input type="checkbox" name="ksef_exclude" id="ksef_exclude" value="1"' . ($is_excluded ? ' checked' : '') . '>';
                    print ' <label for="ksef_exclude">' . $langs->trans("KSEF_ExcludeFromKSEFHelp") . '</label>';
                } else {
                    if ($is_excluded) {
                        print '<span class="badge badge-warning">' . $langs->trans("Yes") . '</span>';
                        print ' <span class="opacitymedium">(' . $langs->trans("KSEF_CustomerExcludedInfo") . ')</span>';
                    } else {
                        print '<span class="badge badge-status4">' . $langs->trans("No") . '</span>';
                    }
                }
            }

            print '</td></tr>';
        }

        return 0;
    }

    /**
     * @brief Accepted elsewhere only
     * @param int $invoiceId
     * @return string Other environment or ''
     */
    private function acceptedOnlyElsewhere($invoiceId)
    {
        return ksefAcceptedOnlyElsewhere($this->db, $invoiceId);
    }

    /**
     * @brief Rate as PLN
     * @param Facture $object
     * @return string
     */
    private function nbpKursText($object)
    {
        global $conf, $db;
        $k = ksefInvoiceKurs($object, array('db' => $db, 'own' => true));
        return $k > 0 ? '1 ' . $object->multicurrency_code . ' = ' . price($k, 0, '', 1, 4, 4) . ' ' . $conf->currency : '';
    }

    /**
     * @brief Default invoice date
     * @return string
     */
    private function invoiceDateTodayJs()
    {
        $act = isset($GLOBALS['action']) && is_string($GLOBALS['action']) ? $GLOBALS['action'] : GETPOST('action', 'aZ09');
        // core < 23
        if ($act !== 'create' || (int) explode('.', DOL_VERSION)[0] >= 23 || getDolGlobalString('MAIN_AUTOFILL_DATE')) {
            return '';
        }
        $now = dol_now();
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        return '<script>jQuery(function($) { var f = $("form[name=add] input#re"); if (!f.length || f.val() !== "") { return; }'
            . ' f.val(' . json_encode(dol_print_date($now, 'day'), $flags) . ');'
            . ' $("#reday").val(' . (int) dol_print_date($now, '%d') . '); $("#remonth").val(' . (int) dol_print_date($now, '%m') . '); $("#reyear").val(' . (int) dol_print_date($now, '%Y') . '); });</script>';
    }

    /**
     * @brief Account follows currency
     * @return string
     */
    private function bankForCurrencyJs()
    {
        global $db, $conf;
        $act = isset($GLOBALS['action']) && is_string($GLOBALS['action']) ? $GLOBALS['action'] : GETPOST('action', 'aZ09');
        $def = getDolGlobalInt('KSEF_DEFAULT_BANK_ACCOUNT_ID');
        if ($act !== 'create' || $def <= 0 || !isModEnabled('bank') || !isModEnabled('multicurrency') || GETPOSTISSET('fk_account')) {
            return '';
        }
        $socid = GETPOSTINT('socid');
        if ($socid > 0) {
            $own = $db->getRow("SELECT fk_account FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . $socid);
            if ($own && (int) $own->fk_account > 0) {
                return '';
            }
        }
        $accs = ksefBankAccountCurrencies($db);
        if (count(array_unique($accs)) < 2) {
            return '';
        }
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        return '<script>jQuery(function($) { var accs = ' . json_encode((object) $accs, $flags) . ', def = "' . $def . '", main = ' . json_encode((string) $conf->currency, $flags) . ';'
            . ' function pick(c) { if (accs[def] === c || c === main) { return def; } for (var k in accs) { if (accs[k] === c) { return k; } } return def; }'
            . ' var cs = $("form[name=add] select[name=multicurrency_code]"), as = $("form[name=add] select[name=fk_account]"), last = cs.val();'
            . ' cs.on("change", function() { if (String(as.val()) === String(pick(last))) { as.val(String(pick(cs.val()))).trigger("change"); } last = cs.val(); }); });</script>';
    }

    /**
     * @brief Keep empty unit
     * @return string
     */
    private function keepEmptyUnitJs()
    {
        global $db;
        // core preselects unit
        $lineId = GETPOSTINT('lineid');
        if (GETPOSTISSET('units') || !getDolGlobalInt('PRODUCT_USE_UNITS')) {
            return '';
        }
        if (GETPOST('action', 'aZ09') !== 'editline' || $lineId <= 0) {
            // free line unit
            return '<script>jQuery(function($) { var s = $("#tablelines tr:has(#addline) select#units"); if (s.length && !s.find("option[value=\'\']").length) { s.prepend("<option value=\"\"></option>").val("").trigger("change"); } });</script>';
        }
        $row = $db->getRow("SELECT fk_unit FROM " . MAIN_DB_PREFIX . "facturedet WHERE rowid = " . $lineId);
        if (!$row || (int) $row->fk_unit > 0) {
            return '';
        }
        return '<script>jQuery(function($) { var s = $("#tablelines select#units"); if (s.length && !s.find("option[value=\'\']").length) { s.prepend("<option value=\"\"></option>").val(""); } });</script>';
    }

    /**
     * @brief Print notes preview
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int
     */
    public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db, $conf, $user;

        $currentcontext = $parameters['currentcontext'] ?? '';
        if ($currentcontext === 'accountancyadminaccount') {
            dol_include_once('/ksef/lib/ksef_chart.lib.php');
            dol_include_once('/ksef/lib/ksef_tour.lib.php');
            print ksefChartFormsHtml($user) . ksefHelpFooter($db, $user, false);
            return 0;
        }
        if (in_array($currentcontext, array('invoicecard', 'invoicelist', 'invoicesuppliercard', 'supplierinvoicelist', 'ordercard', 'orderlist', 'cronjoblist', 'userlist'), true)) {
            if ($currentcontext === 'invoicecard') {
                print $this->keepEmptyUnitJs();
                print $this->invoiceDateTodayJs();
                print $this->bankForCurrencyJs();
            }
            if ($currentcontext === 'invoicelist' && GETPOST('ksef_pick', 'aZ09') === 'roz') {
                print $this->depositPickInfo();
            }
            dol_include_once('/ksef/lib/ksef_tour.lib.php');
            $pick = $currentcontext === 'invoicelist' ? GETPOST('ksef_pick', 'aZ09') : '';
            if (in_array($pick, array('kor', 'korzal', 'roz', 'offline'), true)) {
                print ksefTourPickListHtml($db, $pick, $this->tourPickWhere($pick));
            }
            print ksefHelpFooter($db, $user, false);
            return 0;
        }
        if (in_array($currentcontext, array('paiementcard', 'paymentsupplierlist'), true)) {
            // core < 24
            $payAction = is_string($action) && $action !== '' ? $action : (isset($GLOBALS['action']) && is_string($GLOBALS['action']) ? $GLOBALS['action'] : GETPOST('action', 'aZ09'));
            if (in_array($payAction, array('create', ''), true)) {
                dol_include_once('/ksef/lib/ksef_invoice_notes.lib.php');
                print ksefPayColumnHintJs($db, $currentcontext === 'paiementcard' ? 'facture' : 'invoice_supplier', GETPOSTINT('facid'), GETPOSTINT('socid'));
            }
            return 0;
        }
        if ($currentcontext === 'supplierpaymentcard' && GETPOSTINT('id') > 0 && isModEnabled('ksef')) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            $row = ksefPayFxInfoRow($db, 'payment_supplier', GETPOSTINT('id'));
            if ($row !== '') {
                print '<script>jQuery(function($) { $("div.fichecenter table.border").first().children("tbody").append(' . json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . '); });</script>';
            }
            return 0;
        }
        if ($currentcontext !== 'invoicenote') return 0;

        $id = GETPOSTINT('id');
        if (empty($id)) {
            $ref = GETPOST('ref', 'alpha');
            if (empty($ref)) return 0;
        }
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($db);
        $fetchRes = !empty($id) ? $invoice->fetch($id) : $invoice->fetch(0, $ref);
        if ($fetchRes <= 0 || empty($invoice->id)) return 0;
        $invoice->fetch_optionals();

        dol_include_once('/ksef/class/fa3_builder.class.php');
        $langs->load('ksef@ksef');

        if ((int) $invoice->status > Facture::STATUS_DRAFT && ksefInvoiceEditLock($db, $invoice->id, 'modify')['result'] == 'blocked') {
            print $this->xmlFieldPencilLock($invoice, array('editnote_public' => 'setnote_public'), array());
        }

        $ctx = $this->ksefComputeDodatkowyOpisContext($invoice);
        if (!$ctx['featureActive']) return 0;

        $currentOverride = $ctx['dodOverride'];

        print '<div id="ksef_notes_panel" style="margin:12px 0; padding:12px; border:1px solid #e0e0e0; border-radius:4px; background:#fafbfc;">';
        print '<h4 style="margin:0 0 8px 0;">' . $langs->trans("KSEF_DODATKOWY_OPIS_SECTION") . '</h4>';

        $modeOptions = ksefDodatkowyOpisModeOptions();
        $noteState = ksefSubmissionState($db, $invoice->id);
        if (!ksefUserCan($user, 'edit_ksef_data', $noteState)) {
            $currentLabelKey = isset($modeOptions[$currentOverride]) ? $modeOptions[$currentOverride] : 'KSEF_DodatkowyOpisMode_Default';
            $reasonKey = !empty($noteState['locked']) ? 'KSEF_NotesOverrideLocked' : 'NotEnoughPermissions';
            print '<div style="margin-bottom:10px;">' . $langs->trans("KSEF_ExtraFieldDodatkowyOpisMode") . ': <strong>' . dol_escape_htmltag($langs->trans($currentLabelKey)) . '</strong>';
            print ' <span class="opacitymedium small ksef-notes-readonly">(' . $langs->trans($reasonKey) . ')</span></div>';
        } else {
            print '<form method="POST" action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?id=' . (int) $invoice->id . '" style="margin-bottom:10px;">';
            print '<input type="hidden" name="token" value="' . newToken() . '">';
            print '<input type="hidden" name="action" value="ksef_set_note_override">';
            print '<input type="hidden" name="id" value="' . (int) $invoice->id . '">';
            print '<label for="ksef_note_override_sel" style="margin-right:6px;">' . $langs->trans("KSEF_ExtraFieldDodatkowyOpisMode") . ':</label>';
            print '<select name="ksef_override_value" id="ksef_note_override_sel" class="flat">';
            foreach ($modeOptions as $val => $labelKey) {
                $sel = ((string) $currentOverride === (string) $val) ? ' selected' : '';
                print '<option value="' . dol_escape_htmltag($val) . '"' . $sel . '>' . dol_escape_htmltag($langs->trans($labelKey)) . '</option>';
            }
            print '</select> ';
            print '<button type="submit" class="button button-small">' . $langs->trans("Save") . '</button>';
            print '</form>';
        }

        print '<div style="margin-top:8px;"><strong>' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW") . '</strong></div>';
        print $this->ksefRenderDodatkowyOpisPreviewInner($invoice, false);
        print '</div>';
        print '<script type="text/javascript">
jQuery(document).ready(function() {
    var panel = jQuery("#ksef_notes_panel");
    if (!panel.length) return;
    var anchor = jQuery("#dragDropAreaTabBar .tagtable").first();
    if (!anchor.length) anchor = jQuery(".fiche .tagtable").first();
    if (!anchor.length) anchor = jQuery("textarea[name=\'note_public\']").first().closest(".tagtable");
    if (!anchor.length) anchor = jQuery(".tagtable").first();
    if (anchor.length) {
        panel.detach().insertAfter(anchor);
    }
});
</script>';

        print $this->ksefRenderDodatkowyOpisHelperScript($invoice);

        return 0;
    }

    /**
     * @brief Run mass actions
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    public function doMassActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $db, $user, $langs;

        if (!in_array($parameters['currentcontext'], array('invoicelist'))) return 0;

        if ($parameters['massaction'] == "ksefZip") {
            dol_include_once('/ksef/lib/ksef.lib.php');
            $langs->load("ksef@ksef");
            $obj = new Facture($db);
            $destdir = stripslashes($parameters['diroutputmassaction']);

            if (!is_dir($destdir)) mkdir($destdir, 0700, true);

            $zipname = $destdir . '/ksef-invoices-' . date('Ymd-His') . '.zip';
            $zip = new ZipArchive();

            if ($zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $this->errors[] = 'Failed to create ZIP archive';
                return -1;
            }

            $skipped = array();
            foreach ((array) $parameters['toselect'] as $objectid) {
                if ($obj->fetch($objectid) <= 0) continue;
                if (!ksefUserCanReadInvoice($user, $obj->id)) {
                    $skipped[] = $obj->ref;
                    continue;
                }
                $sanitizedRef = dol_sanitizeFileName($obj->ref);
                $docdir = $conf->invoice->multidir_output[$obj->entity ?? $conf->entity] . '/' . $sanitizedRef;
                $pdffile = $docdir . '/' . $sanitizedRef . ".pdf";
                if (file_exists($pdffile)) $zip->addFile($pdffile, $sanitizedRef . ".pdf");

                $submission = new KsefSubmission($db);
                if ($submission->fetchByInvoice($obj->id) > 0) {
                    if (in_array($submission->status, array('ACCEPTED', 'OFFLINE')) && !empty($submission->fa3_xml)) {
                        $zip->addFromString($sanitizedRef . "_fa3.xml", $submission->fa3_xml);
                        $ksefpdf = $docdir . '/' . $sanitizedRef . '_ksef.pdf';
                        $final = ($submission->status === 'OFFLINE') || ksefIsKsefNumber((string) $submission->ksef_number);
                        if ($final && file_exists($ksefpdf) && !ksefPdfIsPreview($ksefpdf)) {
                            $zip->addFile($ksefpdf, $sanitizedRef . '_ksef.pdf');
                        }
                    }
                    if (!empty($submission->upo_xml)) {
                        $zip->addFromString($sanitizedRef . "_upo.xml", $submission->upo_xml);
                    }
                }
            }
            $numFiles = $zip->numFiles;
            $zip->close();

            if (!empty($skipped)) {
                setEventMessages($langs->trans('KSEF_ZipSkippedNoAccess', implode(', ', array_map('dol_escape_htmltag', $skipped))), null, 'warnings');
            }
            if ($numFiles == 0) {
                @unlink($zipname);
                setEventMessages($langs->trans('KSEF_ZipNothingToExport'), null, 'warnings');
                return 0;
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename=' . basename($zipname));
            header('Content-Length: ' . filesize($zipname));
            readfile($zipname);
            unlink($zipname);
            exit;
        }
        return 0;
    }

    /**
     * @brief Add mass actions
     * @param $parameters
     * @param $object
     * @param $action
     * @param $hookmanager
     * @return int
     */
    public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs, $user;

        if (!isModEnabled('ksef')) return 0;
        if (!in_array($parameters['currentcontext'], array('invoicelist'))) return 0;

        if (!$user->hasRight('ksef', 'read')) return 0;

        $langs->load("ksef@ksef");
        $this->resprints = '<option value="ksefZip">' . $langs->trans("KSEF_DownloadKSEFZip") . '</option>';

        return 0;
    }

    /**
     * @brief Translate deposit labels
     * @param Translate $outlangs
     * @return void
     */
    public static function translateDepositLabels($outlangs)
    {
        if (!is_object($outlangs) || strpos((string) $outlangs->defaultlang, 'pl') !== 0) {
            return;
        }
        $outlangs->load('bills');
        $outlangs->load('ksef@ksef');
        foreach (self::$depositLabelMap as $core => $key) {
            $value = $outlangs->transnoentitiesnoconv($key, '%s');
            if ($value !== '' && $value !== $key) {
                $outlangs->tab_translate[$core] = $value;
            }
        }
    }

    /**
     * @brief Set PDF labels
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param $hookmanager
     * @return int
     */
    public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->inContexts($parameters) || empty($object) || $object->element != 'facture') return 0;
        if (!empty($object->array_options['options_ksef_number']) && strpos((string) $object->array_options['options_ksef_number'], 'OFFLINE') === 0) {
            self::$pdfHiddenKsefNumber[(int) $object->id] = $object->array_options['options_ksef_number'];
            $object->array_options['options_ksef_number'] = '';
        }
        if ($object->type == Facture::TYPE_DEPOSIT && !empty($parameters['outputlangs']) && is_object($parameters['outputlangs'])) {
            // restored in afterPDFCreation
            $parameters['outputlangs']->load('bills');
            self::savePdfLangKeys($parameters['outputlangs'], array_keys(self::$depositLabelMap));
            self::translateDepositLabels($parameters['outputlangs']);
            return 0;
        }
        if ($object->type != Facture::TYPE_REPLACEMENT && $object->type != Facture::TYPE_CREDIT_NOTE) return 0;

        $outputlangs = $parameters['outputlangs'] ?? null;
        if (empty($outputlangs) || !is_object($outputlangs)) {
            return 0;
        }
        $outputlangs->load("ksef@ksef");
        $titleKey = '';
        if (!ksefIsCustomerExcluded($object->socid)) {
            $ctx = ksefDepositContext($this->db, $object);
            $titleKey = 'KSEF_PdfTitle' . (in_array($ctx['type'], array('KOR_ZAL', 'KOR_ROZ'), true) ? $ctx['type'] : 'KOR');
        } elseif ($object->type == Facture::TYPE_REPLACEMENT) {
            $titleKey = 'KSEF_CorrectionInvoice';
        }
        $title = $titleKey !== '' ? $outputlangs->transnoentities($titleKey) : '';
        if ($title !== '' && $title !== $titleKey) {
            $keys = ($object->type == Facture::TYPE_CREDIT_NOTE) ? array('InvoiceAvoir', 'InvoiceAvoirShort') : array('InvoiceReplacement', 'InvoiceReplacementShort');
            self::savePdfLangKeys($outputlangs, $keys);
            foreach ($keys as $k) {
                $outputlangs->tab_translate[$k] = $title;
            }
        }

        return 0;
    }

    /**
     * @brief Save PDF translations
     * @param Translate $outputlangs
     * @param array $keys
     * @return void
     */
    private static function savePdfLangKeys($outputlangs, $keys)
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, self::$pdfTitleSaved)) {
                self::$pdfTitleSaved[$k] = isset($outputlangs->tab_translate[$k]) ? $outputlangs->tab_translate[$k] : null;
            }
        }
        self::$pdfTitleLangs = $outputlangs;
    }

    /**
     * @brief Restore PDF overrides
     * @param array $parameters
     * @param CommonDocGenerator $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int
     */
    public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->inContexts($parameters)) {
            return 0;
        }
        $inv = $parameters['object'] ?? null;
        if (is_object($inv) && isset(self::$pdfHiddenKsefNumber[(int) $inv->id])) {
            $inv->array_options['options_ksef_number'] = self::$pdfHiddenKsefNumber[(int) $inv->id];
            unset(self::$pdfHiddenKsefNumber[(int) $inv->id]);
        }
        if (self::$pdfTitleLangs !== null) {
            foreach (self::$pdfTitleSaved as $k => $v) {
                if ($v === null) {
                    unset(self::$pdfTitleLangs->tab_translate[$k]);
                } else {
                    self::$pdfTitleLangs->tab_translate[$k] = $v;
                }
            }
            self::$pdfTitleSaved = array();
            self::$pdfTitleLangs = null;
        }
        self::$pdfQrBand = array();
        return 0;
    }

    /**
     * @brief Reserve QR space
     * @param array $parameters
     * @param CommonDocGenerator $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int
     */
    public function printUnderHeaderPDFline($parameters, &$object, &$action, $hookmanager)
    {
        global $conf;

        // core keeps resArray
        $this->results = array();
        if (!$this->inContexts($parameters) || !isModEnabled('ksef') || empty($conf->global->KSEF_ADD_QR)) {
            return 0;
        }
        $inv = $parameters['object'] ?? null;
        if (!is_object($inv) || ($inv->element ?? '') != 'facture' || !is_object($object) || !isset($object->heightforinfotot) || !is_numeric($object->heightforinfotot)) {
            return 0;
        }
        $key = spl_object_id($object) . ':' . (int) $inv->id;
        if (isset(self::$pdfQrBand[$key])) {
            return 0;
        }
        dol_include_once('/ksef/class/ksef_qr.class.php');
        $band = (new KsefQR($this->db))->reservedBandHeight($inv->id);
        if ($band > 0) {
            $object->heightforinfotot += $band;
            self::$pdfQrBand[$key] = $band;
        }
        return 0;
    }

    /**
     * @brief Draw PDF QR
     * @param array $parameters
     * @param CommonDocGenerator $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int
     */
    public function afterPDFTotalTable($parameters, $object, $action, $hookmanager)
    {
        global $conf;

        // mass regeneration
        if (!isModEnabled('ksef') || !$this->inContexts($parameters)) return 0;

        // template as $object
        $generator = is_object($object) && isset($object->page_hauteur) ? $object : null;
        $object = $parameters['object'] ?? $object;

        if (empty($object) || $object->element != 'facture') return 0;
        if (empty($conf->global->KSEF_ADD_QR)) return 0;

        if (!isset($parameters['pdf']) || !is_object($parameters['pdf']) || !method_exists($parameters['pdf'], 'write2DBarcode')) {
            return 0;
        }

        dol_include_once('/ksef/class/ksef_qr.class.php');

        try {
            $qrGenerator = new KsefQR($this->db);
            $result = $qrGenerator->addQRToPDF($parameters['pdf'], $object->id, $generator, $parameters['outputlangs'] ?? null);

            if ($result) {
                dol_syslog('KSeF: QR code added to invoice ' . $object->ref, LOG_INFO);
            }
        } catch (Exception $e) {
            dol_syslog('KSeF: Exception during QR generation - ' . $e->getMessage(), LOG_ERR);
        }

        return 0;
    }

    /**
     * @brief Build PDF data
     * @param KsefSubmission $submission
     * @param Facture $invoice
     * @return object
     */
    public function createPdfDataFromSubmission($submission, $invoice)
    {
        $data = new stdClass();
        $data->rowid = $submission->rowid;
        $data->ksef_number = $submission->ksef_number;
        $data->fa3_xml = $submission->fa3_xml;
        $data->environment = $submission->environment;
        $data->invoice_number = $invoice->ref;
        $data->invoice_type = 'VAT';
        $data->invoice_date = $invoice->date;
        $data->total_gross = $invoice->total_ttc;
        $data->total_net = $invoice->total_ht;
        $data->total_vat = $invoice->total_tva;
        $data->currency = $invoice->multicurrency_code ?: 'PLN';
        $data->offline_mode = $submission->offline_mode;
        $data->offline_deadline = $submission->offline_deadline;
        $data->invoice_hash = $submission->invoice_hash;
        $data->seller_nip = null;
        $data->seller_name = null;
        $data->seller_address = null;
        $data->seller_country = 'PL';
        $data->buyer_nip = null;
        $data->buyer_name = null;
        $data->sale_date = null;
        $data->payment_method = null;

        if (!empty($submission->fa3_xml)) {
            dol_include_once('/ksef/class/fa3_parser.class.php');

            $parser = new FA3Parser($this->db);
            $parsed = $parser->parse($submission->fa3_xml);

            if ($parsed) {
                if (!empty($parsed['invoice']['number'])) {
                    $data->invoice_number = $parsed['invoice']['number'];
                }
                if (!empty($parsed['invoice']['type'])) {
                    $data->invoice_type = $parsed['invoice']['type'];
                }
                if (!empty($parsed['invoice']['date'])) {
                    $data->invoice_date = $parsed['invoice']['date'];
                }
                if (!empty($parsed['invoice']['sale_date'])) {
                    $data->sale_date = $parsed['invoice']['sale_date'];
                }
                if (!empty($parsed['invoice']['currency'])) {
                    $data->currency = $parsed['invoice']['currency'];
                }
                if (($parsed['invoice']['total_gross'] ?? 0) != 0) {
                    $data->total_gross = $parsed['invoice']['total_gross'];
                }
                if (($parsed['invoice']['total_net'] ?? 0) != 0) {
                    $data->total_net = $parsed['invoice']['total_net'];
                }
                if (($parsed['invoice']['total_vat'] ?? 0) != 0) {
                    $data->total_vat = $parsed['invoice']['total_vat'];
                }

                $data->seller_nip = $parsed['seller']['nip'] ?? null;
                $data->seller_name = $parsed['seller']['name'] ?? null;
                $data->seller_country = $parsed['seller']['country'] ?? 'PL';
                $data->seller_address = $parsed['seller']['address'] ?? null;

                $data->buyer_nip = $parsed['buyer']['nip'] ?? null;
                $data->buyer_name = $parsed['buyer']['name'] ?? null;
            } else {
                dol_syslog("ActionsKSEF::createPdfDataFromSubmission FA3Parser error: " . $parser->error, LOG_WARNING);
            }
        }

        return $data;
    }

    /**
     * @brief Rate choice buttons
     * @param string $rateHref Fetch URL
     * @param array|false $exactRate false: keep only
     * @return string HTML
     */
    private function nbpRateChoiceButtons($rateHref, $exactRate)
    {
        global $langs;
        require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
        $choiceTip = dol_escape_htmltag($langs->trans('KSEF_NBPRateCorrectionChoiceTooltip'));
        $out = ' <a class="button buttongen reposition" style="padding: 2px 8px; font-size: 0.85em; margin-left: 10px;" ';
        $out .= 'href="' . $rateHref . '&ksef_rate_pick=keep" title="' . ($exactRate ? $choiceTip : '') . '">';
        $out .= '<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_NBPRateKeepOriginal') . '</a>';
        if ($exactRate) {
            $out .= ' <a class="button buttongen reposition" style="padding: 2px 8px; font-size: 0.85em; margin-left: 10px;" ';
            $out .= 'href="' . $rateHref . '&ksef_rate_pick=exact" title="' . $choiceTip . '">';
            $out .= '<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_NBPRateCorrectOriginal', dol_escape_htmltag($exactRate['no']), dol_print_date(dol_stringtotime($exactRate['date']), 'day')) . '</a>';
        }
        return $out;
    }

    /**
     * @brief Show NBP rate
     * @param array $parameters
     * @param Facture $object
     * @return int
     */
    public function displayNBPRateInfo($parameters, &$object)
    {
        global $conf, $langs, $db, $user;

        if (!isModEnabled('ksef')) {
            return 0;
        }

        $contextArray = explode(':', $parameters['context']);
        if (!in_array('invoicecard', $contextArray)) {
            return 0;
        }

        if (!isModEnabled('multicurrency')) {
            return 0;
        }

        $langs->load("ksef@ksef");
        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');

        $nbp = new KsefNbpCurrencyRate($db);

        if (!$nbp->invoiceNeedsNBPRate($object)) {
            return 0;
        }

        $rateInfo = $nbp->getFormattedRateInfo($object);
        $canEdit = !empty($user->rights->facture->creer);
        $isDraft = ($object->statut == Facture::STATUS_DRAFT);

        $nbpContent = '';
        $shownButton = '';

        $rateWarn = '';
        $typedRate = $nbp->checkCorrectionRate($object);
        if ($isDraft && in_array((int) $object->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true) && (float) $object->multicurrency_tx > 0
            && ksefKorZalRateRefusal($db, $object, $korZalCtx = ksefDepositContextOnce($db, $object)) !== '') {
            $depInv = new Facture($db);
            $depInv->fetch((int) $korZalCtx['root_id']);
            $rateWarn = $langs->transnoentitiesnoconv('KSEF_KorZalRateWillRestore', $depInv->ref, number_format(1 / (float) $object->multicurrency_tx, 4, ',', ''),
                number_format(1 / (float) $depInv->multicurrency_tx, 4, ',', ''));
        } elseif ($typedRate['state'] === 'other') {
            $rateWarn = $nbp->correctionRateWarning($typedRate, !$isDraft);
        }

        if (!empty($rateInfo['date'])) {
            $rateCheck = $nbp->checkStoredRate($object);
            $rateDay = !empty($rateCheck['stored_label']) ? $rateCheck['stored_label'] : dol_print_date(is_numeric($object->array_options['options_ksef_kurs_data'] ?? '') ? (int) $object->array_options['options_ksef_kurs_data'] : strtotime((string) $rateInfo['date']), 'day');
            $rateText = $rateDay . ' (' . $this->nbpKursText($object) . ')';
            $userRate = ($rateWarn === '' && empty($rateCheck['stale'])) ? ksefTypedRateCheck($db, $object) : null;
            if ($rateWarn !== '') {
                $nbpContent .= '<span class="ksef-rate-typed classfortooltip ' . ksefToneTextClass('check') . '" title="' . dol_escape_htmltag($rateWarn) . '"><i class="fas fa-exclamation-triangle"></i> ';
            } elseif ($userRate !== null) {
                $userTip = ksefTypedRateText($userRate, $object, $canEdit && $isDraft);
                $rateText .= ', ' . $langs->transnoentitiesnoconv('KSEF_TypedRateShort');
                $nbpContent .= '<span class="ksef-rate-user ksef-rate-user-' . $userRate['state'] . ' classfortooltip ' . ksefToneTextClass('check') . '" title="' . dol_escape_htmltag($userTip) . '"><i class="fas '
                    . ($userRate['state'] === 'differs' ? 'fa-exclamation-triangle' : 'fa-question-circle') . '"></i> ';
            } elseif (!empty($rateCheck['stale'])) {
                $staleRefusal = $isDraft ? array() : $nbp->rateFetchRefusal($object);
                $staleTip = !empty($staleRefusal['parts'])
                    ? $nbp->rateChangeBookedMessage('KSEF_NBPRateStaleChoiceBooked', $object, $staleRefusal['parts'], array($rateCheck['stored_label'], $rateCheck['reference_label']))
                    : $langs->trans('KSEF_NBPRateStaleChoice', $rateCheck['stored_label'], $rateCheck['reference_label'])
                        . ($isDraft ? '' : ' ' . $langs->trans('KSEF_NBPRateStaleValidatedHint', $langs->transnoentitiesnoconv('Modify'), $langs->transnoentitiesnoconv('KSEF_RefreshNBPRate')));
                $nbpContent .= '<span class="ksef-rate-stale classfortooltip ' . ksefToneTextClass('check') . '" title="' . dol_escape_htmltag($staleTip) . '"><i class="fas fa-exclamation-triangle"></i> ';
            } else {
                $nbpContent .= '<span class="ksef-rate-ok ' . ksefToneTextClass('ok') . '"><i class="fa fa-check-circle"></i> ';
            }
            $nbpContent .= htmlspecialchars($rateText);
            $nbpContent .= '</span>';
            $exactRate = ($canEdit && $isDraft) ? $nbp->correctionRateChoice($object) : false;
            $rateHref = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_fetch_nbp_rate&token=' . newToken();
            if ($exactRate) {
                $nbpContent .= $this->nbpRateChoiceButtons($rateHref, $exactRate);
                $shownButton = $langs->transnoentitiesnoconv('KSEF_NBPRateKeepOriginal');
            } elseif ($canEdit && $isDraft && $typedRate['state'] === 'other') {
                $nbpContent .= $this->nbpRateChoiceButtons($rateHref, false);
                $shownButton = $langs->transnoentitiesnoconv('KSEF_NBPRateKeepOriginal');
            } elseif ($canEdit && $isDraft) {
                $shownButton = $langs->transnoentitiesnoconv('KSEF_RefreshNBPRate');
                $nbpContent .= ' <a class="button buttongen reposition" style="padding: 2px 8px; font-size: 0.85em; margin-left: 10px;" ';
                $nbpContent .= 'href="' . $rateHref . '" ';
                $nbpContent .= 'title="' . dol_escape_htmltag($langs->trans('KSEF_RefreshNBPRateTooltip')) . '">';
                $nbpContent .= '<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_RefreshNBPRate') . '</a>';
            }
        } else {
            $nbpContent .= '<span class="ksef-rate-missing ' . ksefToneTextClass('problem') . '"><i class="fas fa-exclamation-triangle"></i> ';
            $nbpContent .= $langs->trans('KSEF_NBPRateMissing');
            $nbpContent .= '</span>';
            if ($rateWarn !== '') {
                $nbpContent .= ' <span class="ksef-rate-typed classfortooltip ' . ksefToneTextClass('check') . '" title="' . dol_escape_htmltag($rateWarn) . '"><i class="fas fa-exclamation-triangle"></i> '
                    . htmlspecialchars($this->nbpKursText($object)) . '</span>';
            }

            $missingRefusal = ($canEdit && !$isDraft) ? $nbp->rateFetchRefusal($object) : array();
            if (!empty($missingRefusal['parts'])) {
                $canEdit = false;
                $nbpContent .= ' <span class="ksef-rate-booked classfortooltip ' . ksefToneTextClass('check') . '" title="' . dol_escape_htmltag($missingRefusal['text']) . '"><i class="fas fa-lock"></i> '
                    . $langs->trans('KSEF_NBPRateBookedShort') . '</span>';
                $rateKor = in_array('replaced', $missingRefusal['parts'], true) ? $nbp->rateChangeReplacement($object) : null;
                if ($rateKor) {
                    $nbpContent .= ' <span class="ksef-rate-booked-kor">' . $langs->transnoentitiesnoconv((int) $rateKor->statut === Facture::STATUS_DRAFT ? 'KSEF_NBPRateKorDraftLink' : 'KSEF_NBPRateKorIssuedLink', $rateKor->getNomUrl(1)) . '</span>';
                }
            }
            $exactRate = $canEdit ? $nbp->correctionRateChoice($object) : false;
            if ($exactRate) {
                $nbpContent .= $this->nbpRateChoiceButtons($_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_fetch_nbp_rate&token=' . newToken(), $exactRate);
                $shownButton = $langs->transnoentitiesnoconv('KSEF_NBPRateKeepOriginal');
            } elseif ($canEdit) {
                $shownButton = $langs->transnoentitiesnoconv('KSEF_FetchNBPRate');
                $nbpContent .= ' <a class="button buttongen reposition ' . ksefToneClass('action') . '" style="padding: 2px 8px; font-size: 0.85em; margin-left: 10px;" ';
                $nbpContent .= 'href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_fetch_nbp_rate&token=' . newToken() . '" ';
                $nbpContent .= 'title="' . dol_escape_htmltag($langs->trans('KSEF_FetchNBPRateTooltip')) . '">';
                $nbpContent .= '<i class="fa fa-download"></i> ' . $langs->trans('KSEF_FetchNBPRate') . '</a>';
            }
        }

        $nbpContent .= ksefLineKursCardHtml($db, $object);

        $disabledTooltip = $shownButton !== '' ? $langs->transnoentitiesnoconv('KSEF_MulticurrencyRefreshDisabled', $shownButton)
            : $langs->transnoentitiesnoconv('KSEF_MulticurrencyRefreshDisabledNoButton');

        $out = '<script type="text/javascript">
jQuery(document).ready(function() {
    var nbpCell = jQuery("td[id^=\'facture_extras_ksef_kurs_data_\']");
    if (nbpCell.length > 0) {
        var newContent = ' . json_encode($nbpContent) . ';
        var hiddenInput = nbpCell.find("input[type=\'hidden\']");
        if (hiddenInput.length > 0) {
            nbpCell.html("").append(hiddenInput).append(newContent);
        } else {
            nbpCell.html(newContent);
        }
    }

    // Disable the multicurrency module rate refresh button on ksef invoices
    // greyed like core
    jQuery("a[href*=\'action=actualizemulticurrencyrate\']").each(function() {
        var s = jQuery("<span>").addClass("classfortooltip ksef-link-greyed ksef-mcrate-refused").attr("title", ' . json_encode($disabledTooltip) . ').html(jQuery(this).html());
        s.find("[title]").removeAttr("title");
        jQuery(this).replaceWith(s);
    });
});
</script>';

        $this->resprints .= $out;
        return 0;
    }

    /**
     * @brief Rename korekta tooltip
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int
     */
    public function getTooltipContent($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;
        if (!isModEnabled('ksef') || !is_object($object) || !isset($parameters['tooltipcontentarray']['picto'])) {
            return 0;
        }
        // core pl_PL untranslated
        if ($object->element === 'facture' && (int) $object->type === Facture::TYPE_DEPOSIT && $this->inContexts($parameters, array('facturedao'))) {
            $pl = $langs->transnoentitiesnoconv('InvoiceDeposit');
            if ($pl !== 'Deposit invoice') {
                $parameters['tooltipcontentarray']['picto'] = str_replace('Deposit invoice', $pl, $parameters['tooltipcontentarray']['picto']);
            }
            return 0;
        }
        if (!$this->inContexts($parameters) || $object->element !== 'invoice_supplier' || (int) $object->type !== FactureFournisseur::TYPE_REPLACEMENT) {
            return 0;
        }
        $langs->load('ksef@ksef');
        $parameters['tooltipcontentarray']['picto'] = str_replace($langs->transnoentitiesnoconv('InvoiceReplacement'), $langs->transnoentitiesnoconv('KSEF_CorrectionInvoice'),
            $parameters['tooltipcontentarray']['picto']);
        return 0;
    }

    /**
     * @brief Override corrected status
     * @param array $parameters
     * @param CommonInvoice $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int 1 = override
     */
    public function LibStatut($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db, $conf;

        if (!isModEnabled('ksef')) {
            return 0;
        }

        if (!$this->inContexts($parameters) || !is_object($object) || ($object->element !== 'facture' && $object->element !== 'invoice_supplier')) {
            return 0;
        }

        $status = (int) $parameters['status'];
        $id = (int) $object->id;
        // supplier pages
        if ($id <= 0 || ($status !== $object::STATUS_CLOSED && $status !== $object::STATUS_ABANDONED)) {
            return 0;
        }
        if ($status === $object::STATUS_ABANDONED && (empty($object->close_code) || $object->close_code != 'replaced')) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        if (!ksefGetCorrectingInvoice($db, $id, 0, $object->element)) {
            return 0;
        }

        $langs->load("ksef@ksef");

        $mode = $parameters['mode'];
        $labelShort = $langs->trans('KSEF_StatusCorrected');
        $labelLong = $langs->trans('KSEF_StatusCorrectedLong');
        $statusType = 'status4';

        $this->resprints = dolGetStatus($labelLong, $labelShort, '', $statusType, $mode);
        return 1;
    }

    /**
     * @brief Get correction delta
     * @param Facture $invoice With extrafields
     * @return array|null
     */
    private function getCorrectionDelta($invoice)
    {
        if ($invoice->type != Facture::TYPE_REPLACEMENT) {
            return null;
        }
        if (!isset($invoice->array_options['options_ksef_correction_original_ht'])) {
            $invoice->fetch_optionals();
        }
        $orig_ht = $invoice->array_options['options_ksef_correction_original_ht'] ?? null;
        $orig_tva = $invoice->array_options['options_ksef_correction_original_tva'] ?? null;
        $orig_ttc = $invoice->array_options['options_ksef_correction_original_ttc'] ?? null;
        if ($orig_ht === null || $orig_ht === '') {
            return null;
        }
        return array(
            'delta_ht' => $invoice->total_ht - (float) $orig_ht,
            'delta_tva' => $invoice->total_tva - (float) $orig_tva,
            'delta_ttc' => $invoice->total_ttc - (float) $orig_ttc,
            'original_ht' => (float) $orig_ht,
            'original_tva' => (float) $orig_tva,
            'original_ttc' => (float) $orig_ttc,
        );
    }

    /**
     * @brief Original line breakdown
     * @param Facture $replacement
     * @return array|null
     */
    private function getOriginalLineBreakdown($replacement)
    {
        if (empty($replacement->fk_facture_source)) {
            return null;
        }
        $original = new Facture($this->db);
        if ($original->fetch($replacement->fk_facture_source) <= 0) {
            return null;
        }
        $original->fetch_thirdparty();
        if (!isset($replacement->array_options['options_ksef_correction_state'])) {
            $replacement->fetch_optionals();
        }
        foreach (array_map('intval', (array) (ksefCorrectionState($replacement)['absorb'] ?? array())) as $noteId) {
            $note = new Facture($this->db);
            if ($note->fetch($noteId) > 0) {
                $original->lines = array_merge($original->lines, $note->lines);
            }
        }

        $by_rate = array();
        foreach ($original->lines as $line) {
            $rate_key = (string) $line->tva_tx;
            if (!empty($line->vat_src_code)) {
                $rate_key .= ' (' . $line->vat_src_code . ')';
            }
            if (!isset($by_rate[$rate_key])) {
                $by_rate[$rate_key] = array('ht' => 0, 'tva' => 0, 'ttc' => 0);
            }
            $by_rate[$rate_key]['ht'] += $line->total_ht;
            $by_rate[$rate_key]['tva'] += $line->total_tva;
            $by_rate[$rate_key]['ttc'] += $line->total_ttc;
        }

        // mirror core sellsjournal.php
        global $mysoc;
        $by_account = array();
        $by_vat_account = array();
        $vat_account_rates = array();
        $journal_ttc = 0;
        $cpttva = getDolGlobalString('ACCOUNTING_VAT_SOLD_ACCOUNT', 'NotDefined');
        $buyer = null;
        if (getDolGlobalString('SERVICE_ARE_ECOMMERCE_200238EC') && !empty($original->thirdparty)) {
            $buyer = $original->thirdparty;
        }
        foreach ($original->lines as $line) {
            if (empty($line->fk_code_ventilation) || $line->fk_code_ventilation <= 0) {
                continue;
            }
            if (!in_array((int) $line->product_type, array(0, 1), true)) {
                continue;
            }
            $journal_ttc += $line->total_ttc;

            $compta_prod = '';
            if (!empty($line->fk_code_ventilation) && $line->fk_code_ventilation > 0) {
                $sql = "SELECT account_number FROM " . MAIN_DB_PREFIX . "accounting_account"
                    . " WHERE rowid = " . (int) $line->fk_code_ventilation;
                $res = $this->db->query($sql);
                if ($res) {
                    $obj = $this->db->fetch_object($res);
                    if ($obj) {
                        $compta_prod = $obj->account_number;
                    }
                    $this->db->free($res);
                }
            }
            if (empty($compta_prod)) {
                if ($line->product_type == 0) {
                    $compta_prod = getDolGlobalString('ACCOUNTING_PRODUCT_SOLD_ACCOUNT', 'NotDefined');
                } else {
                    $compta_prod = getDolGlobalString('ACCOUNTING_SERVICE_SOLD_ACCOUNT', 'NotDefined');
                }
            }
            if (!isset($by_account[$compta_prod])) {
                $by_account[$compta_prod] = 0;
            }
            $by_account[$compta_prod] += $line->total_ht;

            $vatrate_string = $line->tva_tx;
            if (!empty($line->vat_src_code)) {
                $vatrate_string .= ' (' . $line->vat_src_code . ')';
            }
            $vatdata = getTaxesFromId($vatrate_string, $buyer, $mysoc, 0);
            $compta_tva = $cpttva;
            if (is_array($vatdata) && !empty($vatdata['accountancy_code_sell'])) {
                $compta_tva = $vatdata['accountancy_code_sell'];
            }
            if (!isset($by_vat_account[$compta_tva])) {
                $by_vat_account[$compta_tva] = 0;
            }
            if ((((int) $line->info_bits) & 1) != 1) {
                // core skips NPR
                $by_vat_account[$compta_tva] += $line->total_tva;
            }
            if (price2num($line->tva_tx) || !empty($line->vat_src_code)) {
                $vat_label = vatrate($line->tva_tx) . (!empty($line->vat_src_code) ? ' (' . $line->vat_src_code . ')' : '');
                $vat_account_rates[$compta_tva][$vat_label] = $vat_label;
            }
        }

        $compta_soc = '';
        if (!empty($original->thirdparty)) {
            $compta_soc = $original->thirdparty->code_compta_client;
        }
        if (empty($compta_soc)) {
            $compta_soc = getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER', 'NotDefined');
        }
        $by_ttc_account = array($compta_soc => $journal_ttc);

        return array(
            'by_rate' => $by_rate,
            'by_account' => $by_account,
            'by_vat_account' => $by_vat_account,
            'vat_account_rates' => $vat_account_rates,
            'by_ttc_account' => $by_ttc_account,
            'original' => $original,
        );
    }

    /**
     * @brief Adjust sells journal
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     */
    public function processedJournalData($parameters, &$object, &$action, $hookmanager)
    {
        if (!in_array('sellsjournal', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $tabfac = &$parameters['tabfac'];
        $tabht = &$parameters['tabht'];
        $tabtva = &$parameters['tabtva'];
        $tabttc = &$parameters['tabttc'];
        $def_tva = &$parameters['def_tva'];

        foreach ($tabfac as $invoice_id => $facinfo) {
            if (($facinfo['type'] ?? -1) != Facture::TYPE_REPLACEMENT) {
                continue;
            }

            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                continue;
            }
            $delta = $this->getCorrectionDelta($invoice);
            if ($delta === null) {
                continue;
            }

            $breakdown = $this->getOriginalLineBreakdown($invoice);
            if ($breakdown === null) {
                continue;
            }

            if (isset($tabht[$invoice_id])) {
                foreach ($breakdown['by_account'] as $account => $orig_amount) {
                    if (isset($tabht[$invoice_id][$account])) {
                        $tabht[$invoice_id][$account] -= $orig_amount;
                    } else {
                        $tabht[$invoice_id][$account] = -$orig_amount;
                    }
                }
            }

            if (isset($tabtva[$invoice_id])) {
                foreach ($breakdown['by_vat_account'] as $account => $orig_amount) {
                    if (isset($tabtva[$invoice_id][$account])) {
                        $tabtva[$invoice_id][$account] -= $orig_amount;
                    } else {
                        $tabtva[$invoice_id][$account] = -$orig_amount;
                        if (!empty($breakdown['vat_account_rates'][$account]) && !isset($def_tva[$invoice_id][$account])) {
                            $def_tva[$invoice_id][$account] = $breakdown['vat_account_rates'][$account];
                        }
                    }
                }
            }

            if (isset($tabttc[$invoice_id])) {
                foreach ($breakdown['by_ttc_account'] as $account => $orig_amount) {
                    if (isset($tabttc[$invoice_id][$account])) {
                        $tabttc[$invoice_id][$account] -= $orig_amount;
                    } else {
                        $tabttc[$invoice_id][$account] = -$orig_amount;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * @brief Supplier korekta exists
     * @return bool
     */
    private function purchasesJournalActive()
    {
        static $active = null;
        if ($active === null) {
            $sql = "SELECT k.rowid FROM " . MAIN_DB_PREFIX . "facture_fourn AS k";
            $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_fourn_extrafields AS ke ON ke.fk_object = k.rowid";
            $sql .= " WHERE k.type = 1 AND k.fk_facture_source > 0 AND k.fk_statut IN (1,2)";
            $sql .= " AND ke.ksef_correction_original_ht IS NOT NULL";
            $sql .= " AND k.entity IN (" . getEntity('facture_fourn', 0) . ")";
            $resql = $this->db->query($sql . $this->db->plimit(1));
            $active = ($resql && $this->db->num_rows($resql) > 0);
        }
        return $active;
    }

    /**
     * @brief Purchases journal select
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     */
    public function printFieldListSelect($parameters, &$object, &$action, $hookmanager)
    {
        if (!in_array('purchasesjournal', explode(':', $parameters['context'] ?? '')) || !$this->purchasesJournalActive()) {
            return 0;
        }
        $out = '';
        foreach (array('rowid' => 'rowid', 'ref' => 'ref', 'type' => 'type', 'df' => 'datef', 'label' => 'libelle', 'ref_supplier' => 'ref_supplier',
            'dlr' => 'date_lim_reglement', 'close_code' => 'close_code', 'vat_reverse_charge' => 'vat_reverse_charge') as $alias => $col) {
            $out .= ", CASE WHEN ksefk.k = 1 THEN ksefr." . $col . " ELSE f." . $col . " END AS " . $alias;
        }
        foreach (array('total_ht' => 'total_ht', 'total_tva' => 'tva', 'total_localtax1' => 'total_localtax1', 'total_localtax2' => 'total_localtax2', 'total_ttc' => 'total_ttc') as $alias => $col) {
            $out .= ", CASE WHEN ksefk.k = 1 THEN -fd." . $col . " ELSE fd." . $col . " END AS " . $alias;
        }
        $this->resprints = $out;
        return 0;
    }

    /**
     * @brief Purchases journal joins
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     */
    public function printFieldListFrom($parameters, &$object, &$action, $hookmanager)
    {
        if (!in_array('purchasesjournal', explode(':', $parameters['context'] ?? '')) || !$this->purchasesJournalActive()) {
            return 0;
        }
        $out = " INNER JOIN (SELECT 0 AS k UNION ALL SELECT 1 AS k) AS ksefk ON ksefk.k IN (0, 1)";
        $out .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_fourn AS ksefr ON ksefk.k = 1 AND ksefr.fk_facture_source = f.rowid";
        $out .= " AND ksefr.type = 1 AND ksefr.fk_statut IN (1,2)";
        $out .= " AND ksefr.rowid IN (SELECT ksefre.fk_object FROM " . MAIN_DB_PREFIX . "facture_fourn_extrafields AS ksefre WHERE ksefre.ksef_correction_original_ht IS NOT NULL)";
        $this->resprints = $out;
        return 0;
    }

    /**
     * @brief Journal row filter
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     */
    public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
    {
        global $date_start, $date_end, $in_bookkeeping;

        $ctx = explode(':', $parameters['context'] ?? '');
        if (in_array('invoicelist', $ctx) && GETPOST('ksef_pick', 'aZ09') !== '') {
            $this->resprints = $this->tourPickWhere(GETPOST('ksef_pick', 'aZ09'));
            return 0;
        }
        $supplier = in_array('purchasesjournal', $ctx);
        if (!$supplier && !in_array('sellsjournal', $ctx)) {
            return 0;
        }
        $skip = getDolGlobalString($supplier ? 'FACTURE_SUPPLIER_DEPOSITS_ARE_JUST_PAYMENTS' : 'FACTURE_DEPOSITS_ARE_JUST_PAYMENTS')
            ? ksefDepositCorrectionIds($this->db, $supplier ? 'invoice_supplier' : 'facture') : array();
        $not = $skip ? " NOT IN (" . implode(',', $skip) . ")" : '';
        // resprints persist
        $out = $not ? " AND f.rowid" . $not : '';
        if (!$supplier || !$this->purchasesJournalActive()) {
            $this->resprints = $out;
            return 0;
        }
        $out .= " AND ksefk.k = 0";
        $out .= " OR (ksefk.k = 1 AND ksefr.rowid IS NOT NULL" . ($not ? " AND ksefr.rowid" . $not : '') . " AND fd.fk_code_ventilation > 0";
        $out .= " AND ksefr.entity IN (" . getEntity('facture_fourn', 0) . ")";
        if ($date_start && $date_end) {
            $out .= " AND ksefr.datef >= '" . $this->db->idate($date_start) . "' AND ksefr.datef <= '" . $this->db->idate($date_end) . "'";
        }
        if (getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) {
            $out .= " AND ksefr.datef >= '" . $this->db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) . "'";
        }
        if ($in_bookkeeping == 'already') {
            $out .= " AND ksefr.rowid IN (SELECT fk_doc FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping AS ksefab WHERE ksefab.doc_type = 'supplier_invoice')";
        }
        if ($in_bookkeeping == 'notyet') {
            $out .= " AND ksefr.rowid NOT IN (SELECT fk_doc FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping AS ksefab WHERE ksefab.doc_type = 'supplier_invoice')";
        }
        $out .= ")";
        $this->resprints = $out;
        return 0;
    }

    /**
     * @brief Deposit pick info
     * @return string HTML script
     */
    private function depositPickInfo()
    {
        $ids = array();
        $res = $this->db->query("SELECT f.rowid FROM " . MAIN_DB_PREFIX . "facture AS f WHERE f.entity IN (" . getEntity('invoice') . ")" . $this->tourPickWhere('roz') . " ORDER BY f.rowid DESC LIMIT 200");
        while ($res && ($o = $this->db->fetch_object($res))) {
            $ids[] = (int) $o->rowid;
        }
        return ksefDepositPickScript($this->db, $ids);
    }

    /**
     * @brief Guide list filter
     * @param string $pick offline, kor, korzal, roz
     * @return string SQL
     */
    private function tourPickWhere($pick)
    {
        return in_array($pick, array('kor', 'korzal', 'roz', 'offline'), true) ? ksefPickWhere($this->db, $pick) : '';
    }

    /**
     * @brief Adjust customer balance
     * @param array $parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     */
    public function facdao($parameters, &$object, &$action, $hookmanager)
    {
        if (!in_array('recapcomptacard', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $fac = $parameters['fac'];
        $delta = $this->getCorrectionDelta($fac);
        if ($delta === null) {
            return 0;
        }

        $parameters['values']['amount'] = $delta['delta_ttc'];
        return 0;
    }

    /**
     * @brief Corrections in period
     * @param int|string $date_start '' = unbounded
     * @param int|string $date_end '' = unbounded
     * @param int $socid 0 = all
     * @param bool $supplier
     * @param string $types '' = VAT reports
     * @return array
     */
    private function getReportCorrections($date_start, $date_end, $socid = 0, $supplier = false, $types = '')
    {
        $justPayments = $supplier ? getDolGlobalString('FACTURE_SUPPLIER_DEPOSITS_ARE_JUST_PAYMENTS') : getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS');
        if ($types === '') {
            $types = $justPayments ? '0,1,2,5' : '0,1,2,3,5';
        }
        $T = $supplier ? 'facture_fourn' : 'facture';
        $skip = $justPayments ? ksefDepositCorrectionIds($this->db, $supplier ? 'invoice_supplier' : 'facture') : array();

        $where = " AND f.entity IN (" . getEntity($supplier ? 'supplier_invoice' : 'invoice') . ")";
        if (!empty($date_start)) {
            $where .= " AND f.datef >= '" . $this->db->idate($date_start) . "'";
        }
        if (!empty($date_end)) {
            $where .= " AND f.datef <= '" . $this->db->idate($date_end) . "'";
        }
        if ($socid > 0) {
            $where .= " AND f.fk_soc = " . ((int) $socid);
        }

        $sql = "SELECT f.rowid, f.ref, f.type, f.fk_soc, f.datef, o.rowid as orig_id, o.ref as orig_ref,";
        $sql .= " fe.ksef_correction_original_ht as orig_ht, fe.ksef_correction_original_tva as orig_tva,";
        $sql .= " fe.ksef_correction_original_ttc as orig_ttc, fe.ksef_correction_state as state";
        $sql .= " FROM " . MAIN_DB_PREFIX . $T . " as f";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . $T . "_extrafields as fe ON fe.fk_object = f.rowid";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . $T . " as o ON o.rowid = f.fk_facture_source";
        $sql .= " WHERE f.type = 1";
        $sql .= " AND f.fk_statut IN (1,2)";
        $sql .= " AND o.fk_statut IN (1,2)";
        $sql .= " AND o.type IN (" . $this->db->sanitize($types) . ")";
        $sql .= " AND fe.ksef_correction_original_ht IS NOT NULL";
        if (!$supplier) {
            $sql .= " AND fe.ksef_correction_original_ht != ''";
        }
        $sql .= $where;
        if ($skip) {
            $sql .= " AND f.rowid NOT IN (" . implode(',', $skip) . ")";
        }
        $sql .= " ORDER BY f.datef, f.rowid";
        $sqls = array($sql);
        if ($skip) {
            $sqls[] = "SELECT f.rowid, f.ref, f.type, f.fk_soc, f.datef, f.rowid as orig_id, f.ref as orig_ref, f.total_ht as orig_ht,"
                . " f.total_tva as orig_tva, f.total_ttc as orig_ttc, '' as state FROM " . MAIN_DB_PREFIX . $T . " as f"
                . " WHERE f.rowid IN (" . implode(',', $skip) . ") AND f.fk_statut IN (1,2)" . $where . " ORDER BY f.datef, f.rowid";
        }

        $out = array();
        foreach ($sqls as $sql) {
            $resql = $this->db->query($sql);
            if (!$resql) {
                dol_syslog(__METHOD__ . ' ' . $this->db->lasterror(), LOG_ERR);
                continue;
            }
            while ($obj = $this->db->fetch_object($resql)) {
                $obj->orig_ht = (float) $obj->orig_ht;
                $obj->orig_tva = (float) $obj->orig_tva;
                $obj->orig_ttc = (float) $obj->orig_ttc;
                $state = (string) $obj->state !== '' ? json_decode((string) $obj->state, true) : array();
                $obj->excess_pay = (float) ($state['excess_pay'] ?? 0);
                $obj->excess_root = (int) ($state['excess_root'] ?? 0);
                if (!$obj->excess_root && !empty($state['excess'])) {
                    $resRoot = $this->db->query("SELECT " . ($supplier ? "fk_invoice_supplier_source" : "fk_facture_source") . " as r FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE rowid = " . ((int) $state['excess']));
                    $root = $resRoot ? $this->db->fetch_object($resRoot) : null;
                    $obj->excess_root = $root ? (int) $root->r : 0;
                }
                unset($obj->state);
                $out[] = $obj;
            }
            $this->db->free($resql);
        }
        return $out;
    }

    /**
     * @brief Excess credit uses
     * @param int|string $date_start '' = unbounded
     * @param int|string $date_end '' = unbounded
     * @param bool $supplier
     * @return array
     */
    private function getReportExcessUses($date_start, $date_end, $supplier)
    {
        $back = array();
        foreach ($this->getReportCorrections('', '', 0, $supplier) as $c) {
            if ($c->excess_pay > 0 && $c->excess_root > 0) {
                $back[$c->excess_root] = ($back[$c->excess_root] ?? 0) + $c->excess_pay;
            }
        }
        if (!$back) {
            return array();
        }
        $k = ksefInvoiceKind($supplier ? 'invoice_supplier' : 'facture');
        $justPayments = $supplier ? getDolGlobalString('FACTURE_SUPPLIER_DEPOSITS_ARE_JUST_PAYMENTS') : getDolGlobalString('FACTURE_DEPOSITS_ARE_JUST_PAYMENTS');
        $sql = "SELECT r.rowid as rid, r.amount_ttc, r." . $k['col_source'] . " as root, o.ref as orig_ref, x.rowid, x.ref, x.type, x.fk_soc, x.datef,"
            . " x.rowid as orig_id, x.total_ttc as orig_ttc"
            . " FROM " . MAIN_DB_PREFIX . "societe_remise_except as r"
            . " INNER JOIN " . MAIN_DB_PREFIX . $k['table'] . " as x ON x.rowid = r." . $k['col']
            . " INNER JOIN " . MAIN_DB_PREFIX . $k['table'] . " as o ON o.rowid = r." . $k['col_source']
            . " WHERE r." . $k['col_source'] . " IN (" . implode(',', array_map('intval', array_keys($back))) . ")"
            . " AND r.description LIKE '" . $this->db->escape($k['excess']) . "%'"
            . " AND x.fk_statut IN (1,2) AND x.type IN (" . ($justPayments ? '0,1,2,5' : '0,1,2,3,5') . ")"
            . " AND x.entity IN (" . getEntity($k['entity']) . ")"
            . " ORDER BY r.rowid";
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(__METHOD__ . ' ' . $this->db->lasterror(), LOG_ERR);
            return array();
        }
        $rows = array();
        $dates = array();
        while ($obj = $this->db->fetch_object($resql)) {
            if (!isset($dates[(int) $obj->rowid])) {
                $dates[(int) $obj->rowid] = (array) ksefExcessUseDates($this->db, (int) $obj->rowid, $k['element']);
            }
            $obj->datep = $dates[(int) $obj->rowid][(int) $obj->rid]['date'] ?? $this->db->jdate($obj->datef);
            $rows[] = $obj;
        }
        $this->db->free($resql);
        usort($rows, function ($a, $b) {
            return array($a->datep, (int) $a->rid) <=> array($b->datep, (int) $b->rid);
        });
        $out = array();
        foreach ($rows as $obj) {
            $used = min((float) $obj->amount_ttc, max(0.0, $back[(int) $obj->root]));
            $back[(int) $obj->root] -= (float) $obj->amount_ttc;
            $d = $obj->datep;
            if ($used < 0.005 || (!empty($date_start) && $d < $date_start) || (!empty($date_end) && $d > $date_end)) {
                continue;
            }
            $obj->excess_pay = (float) price2num($used, 'MT');
            $obj->orig_ttc = (float) $obj->orig_ttc;
            $obj->use = true;
            unset($obj->amount_ttc, $obj->root, $obj->rid);
            $out[] = $obj;
        }
        return $out;
    }

    /**
     * @brief Add VAT reversals
     * @param array $parameters
     * @param array $object
     * @param string $action
     * @param HookManager $hookmanager
     */
    public function addVatLine($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;
        static $noticeShown = array();

        if (!in_array('externalbalance', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        if (isset($parameters['type']) && $parameters['type'] !== 'vat') {
            return 0;
        }
        if (!is_array($object) || !isset($object[0]) || !is_array($object[0]) || !isset($object[1]) || !is_array($object[1]) || !isset($object[2]) || !is_array($object[2])) {
            return 0;
        }

        $date_start = $parameters['start'] ?? '';
        $date_end = $parameters['end'] ?? '';
        if (empty($date_start) && empty($date_end) && !empty($parameters['year'])) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
            $y = (int) $parameters['year'];
            $m = (int) ($parameters['month'] ?? 0);
            $date_start = dol_get_first_day($y, $m > 0 ? $m : 1, false);
            $date_end = dol_get_last_day($y, $m > 0 ? $m : 12, false);
        }

        $byCustomer = (strpos((string) ($_SERVER['PHP_SELF'] ?? ''), '/compta/tva/clients.php') !== false);
        $companies = array();

        foreach (array(false, true) as $supplier) {
            $corrections = array_merge($this->getReportCorrections($date_start, $date_end, 0, $supplier), $this->getReportExcessUses($date_start, $date_end, $supplier));
            if (empty($corrections)) {
                continue;
            }

            // summary pages
            if ($supplier) {
                require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
                $invoicestatic = new FactureFournisseur($this->db);
            } else {
                require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
                $invoicestatic = new Facture($this->db);
            }
            require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
            $langs->load('ksef@ksef');

            $idx = $supplier ? 1 : 0;
            $side = $supplier ? 'paye' : 'coll';
            $other = $supplier ? 'coll' : 'paye';
            $productOnInvoice = (($supplier ? getDolGlobalString('TAX_MODE_BUY_PRODUCT') : getDolGlobalString('TAX_MODE_SELL_PRODUCT')) == 'invoice');
            $serviceOnInvoice = (($supplier ? getDolGlobalString('TAX_MODE_BUY_SERVICE') : getDolGlobalString('TAX_MODE_SELL_SERVICE')) == 'invoice');
            $skipped = false;

            foreach ($corrections as $c) {
                // mirror core tax_by_rate()
                $sql = "SELECT d.product_type, d.tva_tx, d.vat_src_code, d.total_ht, d." . ($supplier ? "tva" : "total_tva") . " as total_tva, d.total_ttc, d.date_start, d.date_end";
                $sql .= " FROM " . MAIN_DB_PREFIX . ($supplier ? "facture_fourn_det" : "facturedet") . " as d";
                $sql .= " WHERE d." . ($supplier ? "fk_facture_fourn" : "fk_facture") . " = " . ((int) $c->orig_id);
                $sql .= " ORDER BY d.rang, d.rowid";
                $resql = $this->db->query($sql);
                if (!$resql) {
                    continue;
                }

                $use = !empty($c->use);
                $sign = $use ? 1 : -1;
                $self = (!$use && (int) $c->orig_id === (int) $c->rowid);
                $text = $langs->trans($self ? 'KSEF_CorrectionDepositPaymentRow' : 'KSEF_CorrectionReversalRow', $c->orig_ref);
                $invoicestatic->id = (int) $c->rowid;
                $invoicestatic->ref = $c->ref;
                $invoicestatic->type = (int) $c->type;
                $link = $invoicestatic->getNomUrl(1, '', 12) . '<br><span class="opacitymedium small">' . dol_escape_htmltag($text) . '</span>';
                $excessText = $langs->trans($use ? 'KSEF_CorrectionExcessUsedRow' : 'KSEF_CorrectionExcessPaymentRow', $c->orig_ref);
                $excessLink = $invoicestatic->getNomUrl(1, '', 12) . '<br><span class="opacitymedium small">' . dol_escape_htmltag($excessText) . '</span>';
                if (!isset($companies[(int) $c->fk_soc])) {
                    $soc = new Societe($this->db);
                    $companies[(int) $c->fk_soc] = ($soc->fetch((int) $c->fk_soc) > 0) ? $soc->getNomUrl(1, '', 20) : '';
                }

                while ($l = $this->db->fetch_object($resql)) {
                    $isProduct = ((int) $l->product_type == 0 && empty($l->date_start) && empty($l->date_end));
                    $isService = ((int) $l->product_type == 1 || !empty($l->date_start) || !empty($l->date_end));
                    if (!$isProduct && !$isService) {
                        continue;
                    }
                    if (getDolGlobalString('MAIN_NOT_INCLUDE_ZERO_VAT_IN_REPORTS') && (float) $l->tva_tx == 0 && (float) $l->total_tva == 0) {
                        continue;
                    }
                    $onPayment = ($isProduct && !$productOnInvoice) || ($isService && !$serviceOnInvoice);
                    if ($use && !$onPayment) {
                        continue;
                    }
                    if (!$use && $onPayment && ((float) $l->total_ht != 0 || (float) $l->total_tva != 0)) {
                        $skipped = true;
                    }
                    if ($onPayment && ($c->excess_pay <= 0 || $c->orig_ttc == 0)) {
                        continue;
                    }

                    $rate_key = $l->tva_tx;
                    if (!empty($l->vat_src_code) && !preg_match('/\(/', $rate_key)) {
                        $rate_key .= ' (' . $l->vat_src_code . ')';
                    }
                    $key = $byCustomer ? (int) $c->fk_soc : $rate_key;

                    $row = array(
                        'id' => (int) $c->rowid,
                        'descr' => $onPayment ? $excessText : $text,
                        'pid' => 0,
                        'pref' => '',
                        'ptype' => $isService ? 1 : 0,
                        'pstatus' => '',
                        'pstatusbuy' => '',
                        'payment_id' => 0,
                        'payment_ref' => '',
                        'payment_amount' => $onPayment ? $c->excess_pay : 0,
                        'ftotal_ttc' => $onPayment ? $c->orig_ttc : -$c->orig_ttc,
                        'dtotal_ttc' => $sign * (float) $l->total_ttc,
                        'dtype' => $isService ? 1 : 0,
                        'drate' => $l->tva_tx,
                        'datef' => $this->db->jdate($c->datef),
                        'datep' => $onPayment ? ($use ? $c->datep : $this->db->jdate($c->datef)) : '',
                        'company_link' => $companies[(int) $c->fk_soc],
                        'ddate_start' => $this->db->jdate($l->date_start),
                        'ddate_end' => $this->db->jdate($l->date_end),
                        'totalht' => $sign * (float) $l->total_ht,
                        'vat' => $sign * (float) $l->total_tva,
                        'link' => $onPayment ? $excessLink : $link,
                    );

                    if (!isset($object[$idx][$key]) || !is_array($object[$idx][$key])) {
                        $object[$idx][$key] = array('totalht' => 0, 'vat' => 0);
                    }
                    if (!isset($object[2][$key][$side]['detail']) || !is_array($object[2][$key][$side]['detail'])) {
                        $object[2][$key][$side]['totalht'] = $object[2][$key][$side]['totalht'] ?? 0;
                        $object[2][$key][$side]['vat'] = $object[2][$key][$side]['vat'] ?? 0;
                        $object[2][$key][$side]['links'] = '';
                        $object[2][$key][$side]['detail'] = array();
                    }
                    if (!isset($object[2][$key][$other])) {
                        $object[2][$key][$other] = array('totalht' => 0, 'vat' => 0, 'links' => '', 'detail' => array());
                    }
                    $object[$idx][$key]['totalht'] = ($object[$idx][$key]['totalht'] ?? 0) + $row['totalht'];
                    $object[$idx][$key]['vat'] = ($object[$idx][$key]['vat'] ?? 0) + $row['vat'];
                    $object[2][$key][$side]['totalht'] += $row['totalht'];
                    $object[2][$key][$side]['vat'] += $row['vat'];
                    $object[2][$key][$side]['detail'][] = $row;
                }
                $this->db->free($resql);
            }

            if ($skipped && empty($noticeShown[$side])) {
                $noticeShown[$side] = true;
                setEventMessages($langs->trans($supplier ? 'KSEF_CorrectionReportVatOnPaymentNoticeBuy' : 'KSEF_CorrectionReportVatOnPaymentNotice'), null, 'warnings');
            }
        }

        return 0;
    }

    /**
     * @brief Adjust income summary
     * @param array $parameters
     * @param array $object
     * @param string $action
     * @param HookManager $hookmanager
     */
    public function addReportInfo($parameters, &$object, &$action, $hookmanager)
    {
        global $user;

        if (!in_array('externalbalance', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $mode = $parameters['mode'] ?? '';
        if ($mode != 'CREANCES-DETTES') {
            return 0;
        }

        $months = array();
        for ($i = 0; $i < 4; $i++) {
            if (isset($object[$i]) && is_array($object[$i])) {
                $months = array_merge($months, array_keys($object[$i]));
            }
        }
        if (empty($months)) {
            return 0;
        }
        require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
        $first = explode('-', min($months));
        $last = explode('-', max($months));
        $date_min = dol_get_first_day((int) $first[0], (int) ($first[1] ?? 1), false);
        $date_max = dol_get_last_day((int) $last[0], (int) ($last[1] ?? 12), false);

        $socid = GETPOSTINT('socid');
        if (!empty($user->socid) && $user->socid > 0) {
            $socid = (int) $user->socid;
        }

        foreach ($this->getReportCorrections($date_min, $date_max) as $c) {
            $month_key = dol_print_date($this->db->jdate($c->datef), '%Y-%m');

            if (!$socid || (int) $c->fk_soc == $socid) {
                if (isset($object[0][$month_key])) {
                    $object[0][$month_key] -= $c->orig_ht;
                }
                if (isset($object[1][$month_key])) {
                    $object[1][$month_key] -= $c->orig_ttc;
                }
            }
            if (isModEnabled('tax') && isset($object[3][$month_key])) {
                $object[3][$month_key] -= $c->orig_tva;
            }
        }

        // no situation invoices
        $types = getDolGlobalString('FACTURE_SUPPLIER_DEPOSITS_ARE_JUST_PAYMENTS') ? '0,1,2' : '0,1,2,3';
        foreach ($this->getReportCorrections($date_min, $date_max, 0, true, $types) as $c) {
            $month_key = dol_print_date($this->db->jdate($c->datef), '%Y-%m');

            if (!$socid || (int) $c->fk_soc == $socid) {
                if (isset($object[2][$month_key])) {
                    $object[2][$month_key] -= $c->orig_ht;
                }
                if (isset($object[3][$month_key])) {
                    $object[3][$month_key] -= $c->orig_ttc;
                }
            }
            if (isModEnabled('tax') && isset($object[1][$month_key])) {
                $object[1][$month_key] -= $c->orig_tva;
            }
        }

        return 0;
    }

    /**
     * @brief Add balance corrections
     * @param array $parameters
     * @param array $object
     * @param string $action
     * @param HookManager $hookmanager
     */
    public function addBalanceLine($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $user;

        if (!in_array('customersupplierreportlist', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $mode = $parameters['mode'] ?? '';
        if ($mode != 'CREANCES-DETTES') {
            return 0;
        }

        $corrections = $this->getReportCorrections($parameters['date_start'] ?? '', $parameters['date_end'] ?? '');
        // no situation invoices
        $supTypes = getDolGlobalString('FACTURE_SUPPLIER_DEPOSITS_ARE_JUST_PAYMENTS') ? '0,1,2' : '0,1,2,3';
        $supCorrections = $this->getReportCorrections($parameters['date_start'] ?? '', $parameters['date_end'] ?? '', 0, true, $supTypes);
        if (empty($corrections) && empty($supCorrections)) {
            return 0;
        }

        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $langs->load('ksef@ksef');

        $socid = GETPOSTINT('socid');
        if (!empty($user->socid) && $user->socid > 0) {
            $socid = (int) $user->socid;
        }
        $taxOn = isModEnabled('tax');

        $sides = array();
        foreach (array('cust' => $corrections, 'sup' => $supCorrections) as $sk => $list) {
            $sides[$sk] = array('rows' => array(), 'ht' => 0, 'ttc' => 0, 'vat' => 0);
            foreach ($list as $c) {
                $sides[$sk]['vat'] += $c->orig_tva;
                if ($socid && (int) $c->fk_soc != $socid) {
                    continue;
                }
                $dep = ((int) $c->orig_id === (int) $c->rowid);
                $k = (int) $c->fk_soc . ($dep ? 'd' : '');
                if (!isset($sides[$sk]['rows'][$k])) {
                    $sides[$sk]['rows'][$k] = array('soc' => (int) $c->fk_soc, 'dep' => $dep, 'ht' => 0, 'ttc' => 0, 'refs' => array());
                }
                $sides[$sk]['rows'][$k]['ht'] += $c->orig_ht;
                $sides[$sk]['rows'][$k]['ttc'] += $c->orig_ttc;
                $sides[$sk]['rows'][$k]['refs'][] = $c->orig_ref;
                $sides[$sk]['ht'] += $c->orig_ht;
                $sides[$sk]['ttc'] += $c->orig_ttc;
            }
            if (!$taxOn) {
                $sides[$sk]['vat'] = 0;
            }
        }
        $cust_ht = $sides['cust']['ht'];
        $cust_ttc = $sides['cust']['ttc'];
        $vat_all = $sides['cust']['vat'];
        $sup_ht = $sides['sup']['ht'];
        $sup_ttc = $sides['sup']['ttc'];
        $sup_vat_all = $sides['sup']['vat'];

        $object[0] -= $cust_ht;
        $object[1] -= $cust_ttc - $vat_all;
        $object[0] += $sup_ht;
        $object[1] += $sup_ttc - $sup_vat_all;

        $out = '<tr class="trforbreak ksef-correction-section"><td colspan="4">' . dol_escape_htmltag($langs->trans('KSEF_CorrectionReportSection')) . '</td></tr>' . "\n";
        foreach ($sides['cust']['rows'] as $k => $a) {
            $soc = new Societe($this->db);
            $name = ($soc->fetch($a['soc']) > 0) ? $soc->getNomUrl(1, '', 0) : '#' . $a['soc'];
            $out .= '<tr class="oddeven ' . ($a['dep'] ? 'ksef-correction-deposit-row' : 'ksef-correction-row') . '" data-ksef-socid="' . $a['soc'] . '">';
            $out .= '<td>&nbsp;</td>';
            $out .= '<td>' . $langs->trans($a['dep'] ? 'KSEF_CorrectionReportDepositRow' : 'KSEF_CorrectionReportCustomerRow') . ' ' . $name;
            $out .= ' <span class="opacitymedium small">(' . dol_escape_htmltag(implode(', ', $a['refs'])) . ')</span></td>';
            $out .= '<td class="right"><span class="amount">' . price(price2num(-$a['ht'], 'MT')) . '</span></td>';
            $out .= '<td class="right"><span class="amount">' . price(price2num(-$a['ttc'], 'MT')) . '</span></td>';
            $out .= "</tr>\n";
        }
        foreach ($sides['sup']['rows'] as $k => $a) {
            $soc = new Societe($this->db);
            $name = ($soc->fetch($a['soc']) > 0) ? $soc->getNomUrl(1, '', 0) : '#' . $a['soc'];
            $out .= '<tr class="oddeven ' . ($a['dep'] ? 'ksef-correction-supplier-deposit-row' : 'ksef-correction-supplier-row') . '" data-ksef-socid="' . $a['soc'] . '">';
            $out .= '<td>&nbsp;</td>';
            $out .= '<td>' . $langs->trans($a['dep'] ? 'KSEF_CorrectionReportDepositRow' : 'KSEF_CorrectionReportSupplierRow') . ' ' . $name;
            $out .= ' <span class="opacitymedium small">(' . dol_escape_htmltag(implode(', ', $a['refs'])) . ')</span></td>';
            $out .= '<td class="right"><span class="amount">' . price(price2num($a['ht'], 'MT')) . '</span></td>';
            $out .= '<td class="right"><span class="amount">' . price(price2num($a['ttc'], 'MT')) . '</span></td>';
            $out .= "</tr>\n";
        }
        if ($taxOn && $vat_all != 0) {
            $out .= '<tr class="oddeven ksef-correction-vat">';
            $out .= '<td>&nbsp;</td>';
            $out .= '<td>' . dol_escape_htmltag($langs->trans('KSEF_CorrectionReportVatRow')) . '</td>';
            $out .= '<td class="right">&nbsp;</td>';
            $out .= '<td class="right"><span class="amount">' . price(price2num($vat_all, 'MT')) . '</span></td>';
            $out .= "</tr>\n";
        }
        if ($taxOn && $sup_vat_all != 0) {
            $out .= '<tr class="oddeven ksef-correction-supplier-vat">';
            $out .= '<td>&nbsp;</td>';
            $out .= '<td>' . dol_escape_htmltag($langs->trans('KSEF_CorrectionReportSupplierVatRow')) . '</td>';
            $out .= '<td class="right">&nbsp;</td>';
            $out .= '<td class="right"><span class="amount">' . price(price2num(-$sup_vat_all, 'MT')) . '</span></td>';
            $out .= "</tr>\n";
        }
        $out .= '<tr class="liste_total ksef-correction-total"><td></td><td></td>';
        $out .= '<td class="right">' . price(price2num(-$cust_ht + $sup_ht, 'MT')) . '</td>';
        $out .= '<td class="right">' . price(price2num(-$cust_ttc + $vat_all + $sup_ttc - $sup_vat_all, 'MT')) . '</td>';
        $out .= "</tr>\n";

        // core prints after
        $adj = array(
            'income' => array((float) price2num($cust_ht, 'MT'), (float) price2num($cust_ttc + $sup_vat_all, 'MT')),
            // avoids "-0.0"
            'outcome' => array((float) price2num(-$sup_ht, 'MT') + 0.0, (float) price2num(-$vat_all - $sup_ttc, 'MT') + 0.0),
            'labels' => array('income' => $langs->transnoentitiesnoconv('Income'), 'outcome' => $langs->transnoentitiesnoconv('Outcome')),
        );
        $out .= '<script type="text/javascript">
var ksefTotalsAdj = ' . json_encode($adj) . ';
jQuery(document).ready(function() {
    function ksefParseAmount(text) {
        var s = String(text).replace(/[\s  ]/g, "");
        var lastComma = s.lastIndexOf(","), lastDot = s.lastIndexOf(".");
        var dec = lastComma > lastDot ? "," : ".";
        var decPos = Math.max(lastComma, lastDot);
        var decimals = decPos >= 0 ? s.length - decPos - 1 : 0;
        var v = parseFloat(dec === "," ? s.replace(/\./g, "").replace(",", ".") : s.replace(/,/g, ""));
        var m = String(text).match(/\d([^\d\-])\d{3}(?!\d)/);
        var thou = (m && m[1] !== dec) ? m[1] : (dec === "," ? "\u00a0" : ",");
        return {value: v, dec: dec, thou: thou, decimals: decimals};
    }
    function ksefFormatAmount(v, p) {
        var parts = v.toFixed(p.decimals).split(".");
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, p.thou);
        return parts.join(p.dec);
    }
    jQuery("tr.liste_total").each(function() {
        var cells = jQuery(this).children("td");
        if (cells.length < 3) return;
        var label = jQuery.trim(cells.eq(0).text());
        var a = null;
        if (label === ksefTotalsAdj.labels.income) a = ksefTotalsAdj.income;
        if (label === ksefTotalsAdj.labels.outcome) a = ksefTotalsAdj.outcome;
        if (!a) return;
        [cells.length - 2, cells.length - 1].forEach(function(idx, i) {
            if (!a[i]) return;
            var td = cells.eq(idx);
            var p = ksefParseAmount(td.text());
            if (isNaN(p.value)) return;
            td.text(ksefFormatAmount(p.value - a[i], p));
        });
    });
});
</script>';

        $this->resprints = $out;

        return 0;
    }

}