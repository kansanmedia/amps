<?php
// services/BillingService.php - Automated Client Invoicing & Ledger Integration

declare(strict_types=1);

class BillingService {
    /**
     * Fetch configured client category billing rates from rate_cards
     */
    public static function getClientCategoryRates(int $clientId): array {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT rc.category_id, rc.client_billing_rate
            FROM rate_cards rc
            WHERE rc.client_id = :cid
              AND rc.category_id IS NOT NULL
              AND rc.site_id IS NULL
              AND rc.shift_id IS NULL
              AND rc.staff_id IS NULL
              AND rc.effective_from <= CURDATE()
              AND (rc.effective_to IS NULL OR rc.effective_to >= CURDATE())
            ORDER BY rc.id DESC
        ");
        $stmt->execute([':cid' => $clientId]);
        $rates = [];
        while ($row = $stmt->fetch()) {
            $catId = (int)$row['category_id'];
            if (!isset($rates[$catId])) {
                $rates[$catId] = (float)$row['client_billing_rate'];
            }
        }
        return $rates;
    }

    /**
     * Get unbilled approved attendance for client in date range
     */
    public static function getUnbilledAttendance(int $clientId, string $startDate, string $endDate): array {
        $db = DB::getConnection();
        if (strtotime($endDate) > strtotime(date('Y-m-d'))) {
            $endDate = date('Y-m-d');
        }
        $stmt = $db->prepare("
            SELECT a.*, d.deployment_date, d.client_rate, d.staff_rate,
                   s.site_name, sh.shift_name, sh.shift_code, sh.is_overnight,
                   stf.full_name as staff_name, stf.staff_code, stf.skill_category,
                   stf.category_id,
                   COALESCE(sc.id, stf.category_id, 1) as resolved_category_id,
                   COALESCE(sc.category_name, stf.skill_category, 'Service') as category_name
            FROM attendance a
            JOIN deployments d ON a.deployment_id = d.id
            JOIN sites s ON a.site_id = s.id
            JOIN shifts sh ON a.shift_id = sh.id
            JOIN staff stf ON a.staff_id = stf.id
            LEFT JOIN staff_categories sc ON (stf.category_id = sc.id OR stf.skill_category = sc.category_name)
            WHERE a.client_id = :cid
              AND a.attendance_date BETWEEN :start AND :end
              AND a.attendance_date <= CURDATE()
              AND a.is_billed = 0
              AND a.status IN ('present', 'half_day')
            ORDER BY a.attendance_date ASC, s.site_name ASC, sh.id ASC
        ");
        $stmt->execute([':cid' => $clientId, ':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetchAll();
    }

    /**
     * Generate Invoice from attendance records and post ledger
     */
    public static function generateInvoice(int $clientId, string $startDate, string $endDate, float $taxPercentage, float $discountAmount, array $attendanceIds, ?string $notes = null, array $categoryRates = []): int {
        $db = DB::getConnection();

        if (empty($attendanceIds)) {
            throw new Exception("No attendance items selected to bill.");
        }

        // Fetch client details
        $cStmt = $db->prepare("SELECT * FROM clients WHERE id = :id");
        $cStmt->execute([':id' => $clientId]);
        $client = $cStmt->fetch();
        if (!$client) {
            throw new Exception("Client not found.");
        }

        // Fetch company settings for prefix
        $compStmt = $db->query("SELECT invoice_prefix FROM company_settings LIMIT 1");
        $comp = $compStmt->fetch();
        $prefix = $comp['invoice_prefix'] ?? 'INV-';

        // Generate unique invoice number: INV-YYYYMM-XXXX
        $seqStmt = $db->query("SELECT COUNT(id) FROM invoices WHERE YEAR(invoice_date) = YEAR(CURDATE()) AND MONTH(invoice_date) = MONTH(CURDATE())");
        $seq = (int)$seqStmt->fetchColumn() + 1;
        $invoiceNumber = $prefix . date('Ym') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        // Fetch selected attendance records
        $inClause = implode(',', array_fill(0, count($attendanceIds), '?'));
        $attStmt = $db->prepare("
            SELECT a.*, d.deployment_date, d.client_rate, s.site_name, sh.shift_name,
                   stf.full_name as staff_name, stf.skill_category, stf.category_id,
                   COALESCE(sc.id, stf.category_id, 1) as resolved_category_id,
                   COALESCE(sc.category_name, stf.skill_category, 'Service') as category_name
            FROM attendance a
            JOIN deployments d ON a.deployment_id = d.id
            JOIN sites s ON a.site_id = s.id
            JOIN shifts sh ON a.shift_id = sh.id
            JOIN staff stf ON a.staff_id = stf.id
            LEFT JOIN staff_categories sc ON (stf.category_id = sc.id OR stf.skill_category = sc.category_name)
            WHERE a.id IN ($inClause) 
              AND a.client_id = ? 
              AND a.is_billed = 0 
              AND a.attendance_date <= CURDATE() 
              AND a.status IN ('present', 'half_day')
        ");
        $params = array_merge($attendanceIds, [$clientId]);
        $attStmt->execute($params);
        $items = $attStmt->fetchAll();

        if (empty($items)) {
            throw new Exception("No valid unbilled attendance records found among the selected items.");
        }

        $db->beginTransaction();
        try {
            // 1. Persist category rate overrides to rate_cards for this client (Dynamic & persistent)
            if (!empty($categoryRates)) {
                $chkRc = $db->prepare("
                    SELECT id, effective_from FROM rate_cards 
                    WHERE client_id = :cid AND category_id = :catid 
                      AND site_id IS NULL AND shift_id IS NULL AND staff_id IS NULL
                      AND (effective_to IS NULL OR effective_to >= CURDATE())
                    ORDER BY id DESC LIMIT 1
                ");
                $closeOldRc = $db->prepare("
                    UPDATE rate_cards 
                    SET effective_to = DATE_SUB(CURDATE(), INTERVAL 1 DAY) 
                    WHERE id = :id
                ");
                $updTodayRc = $db->prepare("
                    UPDATE rate_cards 
                    SET client_billing_rate = :rate, notes = 'Updated via Invoice Generation' 
                    WHERE id = :id
                ");
                $insRc = $db->prepare("
                    INSERT INTO rate_cards (client_id, category_id, site_id, shift_id, staff_id, client_billing_rate, staff_payout_rate, effective_from, effective_to, notes, created_by)
                    VALUES (:cid, :catid, NULL, NULL, NULL, :rate, 0.00, CURDATE(), NULL, 'Saved via Invoice Generation', :uid)
                ");

                foreach ($categoryRates as $catId => $ovRate) {
                    $catId = (int)$catId;
                    $ovRate = round((float)$ovRate, 2);
                    if ($catId > 0 && $ovRate > 0) {
                        $chkRc->execute([':cid' => $clientId, ':catid' => $catId]);
                        $existingRc = $chkRc->fetch();
                        if ($existingRc) {
                            if ($existingRc['effective_from'] === date('Y-m-d')) {
                                $updTodayRc->execute([':rate' => $ovRate, ':id' => $existingRc['id']]);
                            } else {
                                $closeOldRc->execute([':id' => $existingRc['id']]);
                                $insRc->execute([':cid' => $clientId, ':catid' => $catId, ':rate' => $ovRate, ':uid' => Auth::id()]);
                            }
                        } else {
                            $insRc->execute([':cid' => $clientId, ':catid' => $catId, ':rate' => $ovRate, ':uid' => Auth::id()]);
                        }

                        // Also align client's default_billing_rate if it's the primary Service category (id 1)
                        if ($catId === 1) {
                            $db->prepare("UPDATE clients SET default_billing_rate = :rate WHERE id = :cid")
                               ->execute([':rate' => $ovRate, ':cid' => $clientId]);
                        }
                    }
                }
            }

            // 2. Process items and calculate effective subtotal
            $subtotal = 0.00;
            $processedItems = [];
            foreach ($items as $item) {
                $catId = (int)($item['resolved_category_id'] ?? 1);
                $multiplier = (float)($item['billable_rate_multiplier'] ?? 1.0);
                if ($item['status'] === 'half_day' && $multiplier >= 1.0) {
                    $multiplier = 0.5;
                }

                if (isset($categoryRates[$catId]) && (float)$categoryRates[$catId] > 0) {
                    $unitRate = round((float)$categoryRates[$catId], 2);
                    $itemAmount = round($multiplier * $unitRate, 2);
                } else {
                    $unitRate = (float)$item['client_rate'];
                    $itemAmount = (float)$item['final_client_amount'];
                }

                $item['effective_rate'] = $unitRate;
                $item['effective_amount'] = $itemAmount;
                $subtotal += $itemAmount;
                $processedItems[] = $item;
            }

            $taxAmount = round($subtotal * ($taxPercentage / 100), 2);
            $totalAmount = round($subtotal + $taxAmount - $discountAmount, 2);
            if ($totalAmount < 0) $totalAmount = 0.00;

            $invoiceDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime("+{$client['payment_terms_days']} days"));

            // Check for previous unpaid back-due invoices for this client to carry forward
            $priorStmt = $db->prepare("
                SELECT id, invoice_number, invoice_date, total_amount, paid_amount, balance_amount
                FROM invoices
                WHERE client_id = :cid
                  AND balance_amount > 0
                  AND payment_status IN ('unpaid', 'partially_paid')
                ORDER BY invoice_date ASC, id ASC
            ");
            $priorStmt->execute([':cid' => $clientId]);
            $priorInvoices = $priorStmt->fetchAll();

            $carriedDues = 0.00;
            $carriedDetail = null;
            if (!empty($priorInvoices)) {
                $carriedDues = array_sum(array_column($priorInvoices, 'balance_amount'));
                $carriedDetail = json_encode($priorInvoices);
            }
            $netBalanceAmount = $totalAmount + $carriedDues;

            // 3. Insert Invoice
            $insInv = $db->prepare("
                INSERT INTO invoices (invoice_number, client_id, invoice_date, due_date, period_start, period_end,
                                      subtotal, tax_percentage, tax_amount, discount_amount, carried_dues, carried_invoices_detail,
                                      total_amount, paid_amount, balance_amount, payment_status, notes, created_by)
                VALUES (:num, :cid, :idt, :ddt, :pstart, :pend, :sub, :tp, :ta, :disc, :cdues, :cdet, :tot, 0.00, :bal, 'unpaid', :notes, :uid)
            ");
            $insInv->execute([
                ':num'    => $invoiceNumber,
                ':cid'    => $clientId,
                ':idt'    => $invoiceDate,
                ':ddt'    => $dueDate,
                ':pstart' => $startDate,
                ':pend'   => $endDate,
                ':sub'    => $subtotal,
                ':tp'     => $taxPercentage,
                ':ta'     => $taxAmount,
                ':disc'   => $discountAmount,
                ':cdues'  => $carriedDues,
                ':cdet'   => $carriedDetail,
                ':tot'    => $totalAmount,
                ':bal'    => $netBalanceAmount,
                ':notes'  => $notes,
                ':uid'    => Auth::id()
            ]);
            $invoiceId = (int)$db->lastInsertId();

            // Zero out prior invoices that have been carried forward into this new invoice
            if (!empty($priorInvoices)) {
                $zeroPrior = $db->prepare("
                    UPDATE invoices 
                    SET balance_amount = 0.00, 
                        payment_status = 'carried_forward',
                        carried_to_invoice_number = :newnum, 
                        notes = CONCAT(COALESCE(notes, ''), ' [Carry forward to ', :newnum2, ']')
                    WHERE id = :pid
                ");
                foreach ($priorInvoices as $pInv) {
                    $zeroPrior->execute([
                        ':newnum'  => $invoiceNumber,
                        ':newnum2' => $invoiceNumber,
                        ':pid'     => $pInv['id']
                    ]);
                }
            }

            // 4. Insert Invoice Items and update attendance with updated amount
            $insItem = $db->prepare("
                INSERT INTO invoice_items (invoice_id, attendance_id, deployment_id, service_date, site_name, shift_name, staff_name, staff_category, quantity, unit_rate, total_amount)
                VALUES (:invid, :attid, :did, :sdt, :sname, :shname, :stfname, :scat, 1.00, :urate, :totamt)
            ");
            $updAtt = $db->prepare("UPDATE attendance SET is_billed = 1, final_client_amount = :amt WHERE id = :id");
            $updDep = $db->prepare("UPDATE deployments SET client_rate = :crate WHERE id = :did");

            foreach ($processedItems as $item) {
                $insItem->execute([
                    ':invid'   => $invoiceId,
                    ':attid'   => $item['id'],
                    ':did'     => $item['deployment_id'],
                    ':sdt'     => $item['attendance_date'],
                    ':sname'   => $item['site_name'],
                    ':shname'  => $item['shift_name'],
                    ':stfname' => $item['staff_name'],
                    ':scat'    => $item['category_name'],
                    ':urate'   => $item['effective_rate'],
                    ':totamt'  => $item['effective_amount']
                ]);
                $updAtt->execute([':id' => $item['id'], ':amt' => $item['effective_amount']]);
                if (!empty($item['deployment_id'])) {
                    $updDep->execute([':crate' => $item['effective_rate'], ':did' => $item['deployment_id']]);
                }
            }

            // 3. Post to Financial Ledger (Accounts Receivable Debit)
            $txnId = 'TXN-INV-' . $invoiceNumber;
            // Get current client balance
            $balStmt = $db->prepare("
                SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END), 0)
                FROM ledger_entries WHERE entity_type='client' AND entity_id=:cid
            ");
            $balStmt->execute([':cid' => $clientId]);
            $prevBalance = (float)$balStmt->fetchColumn();
            $newBalance = $prevBalance + $totalAmount;

            $insLedger = $db->prepare("
                INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                VALUES (:txnid, :dt, NULL, 'client', :cid, 'debit', :amt, :rbal, 'invoice', :sid, :narr, :uid)
            ");
            $insLedger->execute([
                ':txnid' => $txnId,
                ':dt'    => $invoiceDate,
                ':cid'   => $clientId,
                ':amt'   => $totalAmount,
                ':rbal'  => $newBalance,
                ':sid'   => $invoiceId,
                ':narr'  => "Invoice {$invoiceNumber} for period {$startDate} to {$endDate}",
                ':uid'   => Auth::id()
            ]);

            $db->commit();
            
            // Security Hardening: Checkpoint 17 - Verify Backfill Signatures & Data Integrity
            $integritySig = Security::generateSignature([
                'invoice_id'     => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'client_id'      => $clientId,
                'total_amount'   => $totalAmount,
                'items_count'    => count($items)
            ]);

            Audit::log('CREATE', 'INVOICES', $invoiceId, null, [
                'invoice_number'      => $invoiceNumber,
                'client_id'           => $clientId,
                'total_amount'        => $totalAmount,
                'items_count'         => count($items),
                'integrity_signature' => $integritySig
            ]);

            return $invoiceId;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Toggle or update Tax (GST) on an existing invoice
     */
    public static function updateInvoiceTax(int $invoiceId, float $taxPercentage): bool {
        $db = DB::getConnection();
        $invStmt = $db->prepare("SELECT * FROM invoices WHERE id = :id");
        $invStmt->execute([':id' => $invoiceId]);
        $invoice = $invStmt->fetch();

        if (!$invoice) {
            throw new Exception("Invoice not found.");
        }

        $subtotal = (float)$invoice['subtotal'];
        $discountAmount = (float)$invoice['discount_amount'];
        $paidAmount = (float)$invoice['paid_amount'];

        $taxAmount = round($subtotal * ($taxPercentage / 100), 2);
        $totalAmount = round($subtotal + $taxAmount - $discountAmount, 2);
        if ($totalAmount < 0) $totalAmount = 0.00;
        $balanceAmount = round($totalAmount - $paidAmount, 2);

        $status = $invoice['payment_status'];
        if ($paidAmount >= $totalAmount && $totalAmount > 0) {
            $status = 'paid';
        } elseif ($paidAmount > 0) {
            $status = 'partially_paid';
        } else {
            $status = 'unpaid';
        }

        $db->beginTransaction();
        try {
            $db->prepare("
                UPDATE invoices 
                SET tax_percentage = :tp, tax_amount = :ta, total_amount = :tot, balance_amount = :bal, payment_status = :st
                WHERE id = :id
            ")->execute([
                ':tp' => $taxPercentage,
                ':ta' => $taxAmount,
                ':tot' => $totalAmount,
                ':bal' => $balanceAmount,
                ':st' => $status,
                ':id' => $invoiceId
            ]);

            // Update associated ledger entry amount if exists
            $txnId = 'TXN-INV-' . $invoice['invoice_number'];
            $db->prepare("
                UPDATE ledger_entries 
                SET amount = :amt, running_balance = :amt
                WHERE transaction_id = :txnid AND source_type = 'invoice'
            ")->execute([
                ':amt' => $totalAmount,
                ':txnid' => $txnId
            ]);

            $db->commit();
            Audit::log('UPDATE_TAX', 'INVOICES', $invoiceId, [
                'old_tax' => $invoice['tax_amount'],
                'old_total' => $invoice['total_amount']
            ], [
                'new_tax_percentage' => $taxPercentage,
                'new_tax_amount' => $taxAmount,
                'new_total' => $totalAmount
            ]);
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
