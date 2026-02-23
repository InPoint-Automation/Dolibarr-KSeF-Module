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
 * \file    core/triggers/interface_99_modKSEF_KsefTriggers.class.php
 * \ingroup ksef
 * \brief   event triggers
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceKsefTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "financial";
        $this->description = "KSEF database event triggers";
        $this->version = '1.0.0';
        $this->picto = 'ksef@ksef';
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDesc()
    {
        return $this->description;
    }

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
            return 0;
        }

        switch ($action) {

            case 'BILL_CREATE':
                $object->fetch_optionals();
                if (empty($object->array_options['options_ksef_sale_date'])) {
                    $sale_date = null;
                    $saleDateSource = getDolGlobalString('KSEF_FA3_SALE_DATE_SOURCE', 'delivery_date');

                    if ($saleDateSource === 'delivery_date') {
                        // shipment -> order delivery -> order date -> invoice date
                        $object->fetchObjectLinked(null, '', null, '', 'OR', 1, 'sourcetype', 1);

                        // linked shipments
                        foreach (array('expedition', 'shipping') as $shipment_key) {
                            if (!empty($object->linkedObjects[$shipment_key])) {
                                foreach ($object->linkedObjects[$shipment_key] as $shipment) {
                                    if (!empty($shipment->date_delivery)) {
                                        $sale_date = $shipment->date_delivery;
                                        break 2;
                                    }
                                    if (!empty($shipment->date_expedition)) {
                                        $sale_date = $shipment->date_expedition;
                                        break 2;
                                    }
                                }
                            }
                        }

                        // linked sales orders delivery date
                        if (empty($sale_date) && !empty($object->linkedObjects['commande'])) {
                            foreach ($object->linkedObjects['commande'] as $order) {
                                if (!empty($order->delivery_date)) {
                                    $sale_date = $order->delivery_date;
                                    break;
                                }
                                if (!empty($order->date_livraison)) {
                                    $sale_date = $order->date_livraison;
                                    break;
                                }
                            }
                            // linked sales order date
                            if (empty($sale_date)) {
                                foreach ($object->linkedObjects['commande'] as $order) {
                                    if (!empty($order->date_commande)) {
                                        $sale_date = $order->date_commande;
                                        break;
                                    }
                                    if (!empty($order->date)) {
                                        $sale_date = $order->date;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    // saleDateSource === 'invoice_date'

                    if (empty($sale_date)) {
                        $sale_date = $object->date;
                    }

                    $object->array_options['options_ksef_sale_date'] = $sale_date;
                    $object->insertExtraFields();
                }
                return 0;

            case 'BILL_VALIDATE':
                $object->fetch_optionals();
                if (!empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: Invoice " . $object->ref . " validated with KSEF number: " . $object->array_options['options_ksef_number'], LOG_INFO);
                }
                return 0;

            case 'BILL_MODIFY':
                if (!empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: WARNING - Invoice " . $object->ref . " with KSEF number " . $object->array_options['options_ksef_number'] . " was modified", LOG_WARNING);
                }
                return 0;

            case 'EXTRAFIELDS_UPDATE':
                // Regenerate PDF when KSEF number is added
                if ($object->element == 'facture' && !empty($object->array_options['options_ksef_number'])) {
                    static $pdf_regenerated = array();
                    $cache_key = $object->id . '_' . $object->array_options['options_ksef_number'];

                    if (!isset($pdf_regenerated[$cache_key])) {
                        dol_syslog("KsefTriggers: KSEF number added to invoice " . $object->ref . ", regenerating PDF", LOG_INFO);

                        try {
                            $result = $object->generateDocument($object->model_pdf, $langs, 0, 0, 0);

                            if ($result > 0) {
                                dol_syslog("KsefTriggers: PDF successfully regenerated for invoice " . $object->ref, LOG_INFO);
                            } else {
                                dol_syslog("KsefTriggers: PDF regeneration failed for invoice " . $object->ref . ": " . $object->error, LOG_ERR);
                            }

                            $pdf_regenerated[$cache_key] = true;

                        } catch (Exception $e) {
                            dol_syslog("KsefTriggers: Exception during PDF regeneration: " . $e->getMessage(), LOG_ERR);
                        }
                    }
                }
                return 0;

            case 'BILL_DELETE':
                if (!empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: CRITICAL - Invoice " . $object->ref . " with KSEF number " . $object->array_options['options_ksef_number'] . " was deleted", LOG_ALERT);
                }
                return 0;

            default:
                return 0;
        }
    }
}