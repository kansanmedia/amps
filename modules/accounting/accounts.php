<?php
// modules/accounting/accounts.php - Financial Accounts, Inter-Account Transfers & Master Ledger
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant']);

$db = DB::getConnection();
$error = '';

// Handle Inter-Account Transfer or Add New Account
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'transfer_funds') {
        $fromAccId = (int)($_POST['from_account_id'] ?? 0);
        $toAccId = (int)($_POST['to_account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $transferDate = $_POST['transfer_date'] ?? date('Y-m-d');
        $reference = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? 'Inter-account fund transfer');

        if (empty($fromAccId) || empty($toAccId) || $fromAccId === $toAccId || $amount <= 0) {
            $error = 'Please select two different accounts and enter a valid positive transfer amount.';
        } else {
            // Check source account balance
            $fromStmt = $db->prepare("SELECT account_name, current_balance FROM financial_accounts WHERE id = :id");
            $fromStmt->execute([':id' => $fromAccId]);
            $fromAcc = $fromStmt->fetch();

            if (!$fromAcc || (float)$fromAcc['current_balance'] < $amount) {
                $error = "Insufficient funds in source account '{$fromAcc['account_name']}'. Current balance is " . Helper::formatCurrency($fromAcc['current_balance'] ?? 0);
            } else {
                $toStmt = $db->prepare("SELECT account_name, current_balance FROM financial_accounts WHERE id = :id");
                $toStmt->execute([':id' => $toAccId]);
                $toAcc = $toStmt->fetch();

                $db->beginTransaction();
                try {
                    // Decrement from account
                    $db->prepare("UPDATE financial_accounts SET current_balance = current_balance - :amt WHERE id = :id")->execute([':amt' => $amount, ':id' => $fromAccId]);
                    // Increment to account
                    $db->prepare("UPDATE financial_accounts SET current_balance = current_balance + :amt WHERE id = :id")->execute([':amt' => $amount, ':id' => $toAccId]);

                    $txnId = 'TXN-TRF-' . date('YmdHis') . '-' . rand(100, 999);
                    $newFromBal = (float)$fromAcc['current_balance'] - $amount;
                    $newToBal = (float)$toAcc['current_balance'] + $amount;

                    // Outflow entry
                    $db->prepare("
                        INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                        VALUES (:txnid, :dt, :acc, 'financial_account', :acc, 'credit', :amt, :rbal, 'account_transfer', :acc, :narr, :uid)
                    ")->execute([
                        ':txnid' => $txnId, ':dt' => $transferDate, ':acc' => $fromAccId, ':amt' => $amount,
                        ':rbal' => $newFromBal, ':narr' => "Transfer OUT to {$toAcc['account_name']}. Ref: {$reference}", ':uid' => Auth::id()
                    ]);

                    // Inflow entry
                    $db->prepare("
                        INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                        VALUES (:txnid, :dt, :acc, 'financial_account', :acc, 'debit', :amt, :rbal, 'account_transfer', :acc, :narr, :uid)
                    ")->execute([
                        ':txnid' => $txnId, ':dt' => $transferDate, ':acc' => $toAccId, ':amt' => $amount,
                        ':rbal' => $newToBal, ':narr' => "Transfer IN from {$fromAcc['account_name']}. Ref: {$reference}", ':uid' => Auth::id()
                    ]);

                    $db->commit();
                    Audit::log('TRANSFER', 'ACCOUNTS', $fromAccId, null, ['to_account' => $toAccId, 'amount' => $amount]);
                    Helper::setFlash('success', "Transferred " . Helper::formatCurrency($amount) . " from {$fromAcc['account_name']} to {$toAcc['account_name']} successfully.");
                    Helper::redirect('modules/accounting/accounts.php');
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Transfer failed: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'create_account') {
        $accCode = strtoupper(trim($_POST['account_code'] ?? ''));
        $accName = trim($_POST['account_name'] ?? '');
        $accType = $_POST['account_type'] ?? 'bank';
        $bankName = trim($_POST['bank_name'] ?? '');
        $accNo = trim($_POST['account_number'] ?? '');
        $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
        $openBal = (float)($_POST['opening_balance'] ?? 0);

        if (empty($accName) || empty($accCode)) {
            $error = 'Account Code and Name are required.';
        } else {
            $insStmt = $db->prepare("
                INSERT INTO financial_accounts (account_code, account_name, account_type, bank_name, account_number, ifsc_code, opening_balance, current_balance, status)
                VALUES (:code, :name, :type, :bname, :ano, :ifsc, :bal, :bal, 'active')
            ");
            $insStmt->execute([
                ':code' => $accCode, ':name' => $accName, ':type' => $accType, ':bname' => $bankName,
                ':ano' => $accNo, ':ifsc' => $ifsc, ':bal' => $openBal
            ]);
            $accId = (int)$db->lastInsertId();

            if ($openBal > 0) {
                $db->prepare("
                    INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                    VALUES (:txnid, CURDATE(), :acc, 'financial_account', :acc, 'debit', :amt, :amt, 'opening_balance', :acc, 'Opening balance', :uid)
                ")->execute([':txnid' => 'TXN-INIT-' . $accCode, ':acc' => $accId, ':amt' => $openBal, ':uid' => Auth::id()]);
            }

            Audit::log('CREATE', 'FINANCIAL_ACCOUNTS', $accId, null, $_POST);
            Helper::setFlash('success', "Account '{$accName}' created successfully.");
            Helper::redirect('modules/accounting/accounts.php');
        }
    } elseif ($action === 'set_opening_balance') {
        $accId = (int)($_POST['account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $entryDate = !empty($_POST['entry_date']) ? $_POST['entry_date'] : date('Y-m-d');
        $adjustmentType = $_POST['adjustment_type'] ?? 'opening_balance';
        $narration = trim($_POST['narration'] ?? '');

        if ($accId <= 0 || $amount < 0) {
            $error = 'Please select a valid account and enter a non-negative amount.';
        } else {
            $accStmt = $db->prepare("SELECT * FROM financial_accounts WHERE id = :id");
            $accStmt->execute([':id' => $accId]);
            $acc = $accStmt->fetch();

            if (!$acc) {
                $error = 'Account not found.';
            } else {
                $db->beginTransaction();
                try {
                    if ($adjustmentType === 'opening_balance') {
                        $oldOpenBal = (float)$acc['opening_balance'];
                        $diff = $amount - $oldOpenBal;
                        $defaultNarr = "Opening Balance - {$acc['account_name']}";
                        $finalNarr = !empty($narration) ? $narration : $defaultNarr;

                        $chkLedger = $db->prepare("SELECT id FROM ledger_entries WHERE account_id = :accId AND source_type = 'opening_balance' ORDER BY id ASC LIMIT 1");
                        $chkLedger->execute([':accId' => $accId]);
                        $existingEntryId = $chkLedger->fetchColumn();

                        if ($existingEntryId) {
                            $db->prepare("UPDATE ledger_entries SET amount = :amt, running_balance = :amt, entry_date = :dt, narration = :narr WHERE id = :id")
                               ->execute([
                                   ':amt' => $amount,
                                   ':dt' => $entryDate,
                                   ':narr' => $finalNarr,
                                   ':id' => $existingEntryId
                               ]);
                        } else {
                            $db->prepare("
                                INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                                VALUES (:txnid, :dt, :acc, 'financial_account', :acc, 'debit', :amt, :amt, 'opening_balance', :acc, :narr, :uid)
                            ")->execute([
                                ':txnid' => 'TXN-INIT-' . ($acc['account_code'] ?: $accId) . '-' . date('Ymd'),
                                ':dt' => $entryDate,
                                ':acc' => $accId,
                                ':amt' => $amount,
                                ':narr' => $finalNarr,
                                ':uid' => Auth::id()
                            ]);
                        }

                        $db->prepare("UPDATE financial_accounts SET opening_balance = :ob, current_balance = current_balance + :diff WHERE id = :id")
                           ->execute([':ob' => $amount, ':diff' => $diff, ':id' => $accId]);

                        $flashMsg = "Opening balance for '{$acc['account_name']}' set to " . Helper::formatCurrency($amount) . " successfully.";
                    } else {
                        // Direct Capital Deposit
                        $defaultNarr = "Capital Inflow / Owner Equity - {$acc['account_name']}";
                        $finalNarr = !empty($narration) ? $narration : $defaultNarr;
                        $newBal = (float)$acc['current_balance'] + $amount;

                        $txnId = 'TXN-CAP-' . date('YmdHis') . '-' . rand(100, 999);
                        $db->prepare("
                            INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                            VALUES (:txnid, :dt, :acc, 'financial_account', :acc, 'debit', :amt, :rbal, 'opening_balance', :acc, :narr, :uid)
                        ")->execute([
                            ':txnid' => $txnId,
                            ':dt' => $entryDate,
                            ':acc' => $accId,
                            ':amt' => $amount,
                            ':rbal' => $newBal,
                            ':narr' => $finalNarr,
                            ':uid' => Auth::id()
                        ]);

                        $db->prepare("UPDATE financial_accounts SET current_balance = current_balance + :amt WHERE id = :id")
                           ->execute([':amt' => $amount, ':id' => $accId]);

                        $flashMsg = "Capital deposit of " . Helper::formatCurrency($amount) . " added to '{$acc['account_name']}' successfully.";
                    }

                    $db->commit();
                    Audit::log('SET_BALANCE', 'FINANCIAL_ACCOUNTS', $accId, null, $_POST);
                    Helper::setFlash('success', $flashMsg);
                    Helper::redirect('modules/accounting/accounts.php');
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Failed to update balance: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'edit_account') {
        $accId = (int)($_POST['account_id'] ?? 0);
        $accName = trim($_POST['account_name'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $accNo = trim($_POST['account_number'] ?? '');
        $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $hasOpenBal = isset($_POST['opening_balance']);
        $newOpenBal = $hasOpenBal ? max(0, (float)$_POST['opening_balance']) : null;

        if ($accId <= 0 || empty($accName)) {
            $error = 'Valid account ID and Account Name are required.';
        } else {
            $accStmt = $db->prepare("SELECT * FROM financial_accounts WHERE id = :id");
            $accStmt->execute([':id' => $accId]);
            $currentAcc = $accStmt->fetch();

            if (!$currentAcc) {
                $error = 'Account not found.';
            } else {
                $db->beginTransaction();
                try {
                    $upd = $db->prepare("
                        UPDATE financial_accounts 
                        SET account_name = :name, bank_name = :bname, account_number = :ano, ifsc_code = :ifsc, status = :status
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':name' => $accName, ':bname' => $bankName, ':ano' => $accNo, ':ifsc' => $ifsc, ':status' => $status, ':id' => $accId
                    ]);

                    if ($hasOpenBal && $newOpenBal !== null) {
                        $oldOpenBal = (float)$currentAcc['opening_balance'];
                        $diff = $newOpenBal - $oldOpenBal;

                        $chkLedger = $db->prepare("SELECT id FROM ledger_entries WHERE account_id = :accId AND source_type = 'opening_balance' ORDER BY id ASC LIMIT 1");
                        $chkLedger->execute([':accId' => $accId]);
                        $existingEntryId = $chkLedger->fetchColumn();

                        if ($existingEntryId) {
                            $db->prepare("UPDATE ledger_entries SET amount = :amt, running_balance = :amt, narration = :narr WHERE id = :id")
                               ->execute([
                                   ':amt' => $newOpenBal,
                                   ':narr' => "Opening Balance - {$accName}",
                                   ':id' => $existingEntryId
                               ]);
                        } elseif ($newOpenBal > 0) {
                            $db->prepare("
                                INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                                VALUES (:txnid, CURDATE(), :acc, 'financial_account', :acc, 'debit', :amt, :amt, 'opening_balance', :acc, :narr, :uid)
                            ")->execute([
                                ':txnid' => 'TXN-INIT-' . ($currentAcc['account_code'] ?: $accId),
                                ':acc' => $accId,
                                ':amt' => $newOpenBal,
                                ':narr' => "Opening Balance - {$accName}",
                                ':uid' => Auth::id()
                            ]);
                        }

                        $db->prepare("UPDATE financial_accounts SET opening_balance = :ob, current_balance = current_balance + :diff WHERE id = :id")
                           ->execute([':ob' => $newOpenBal, ':diff' => $diff, ':id' => $accId]);
                    }

                    $db->commit();
                    Audit::log('UPDATE', 'FINANCIAL_ACCOUNTS', $accId, $currentAcc, $_POST);
                    Helper::setFlash('success', "Account '{$accName}' updated successfully.");
                    Helper::redirect('modules/accounting/accounts.php');
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Failed to update account: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $accId = (int)($_POST['account_id'] ?? 0);
        $accStmt = $db->prepare("SELECT account_name, status FROM financial_accounts WHERE id = :id");
        $accStmt->execute([':id' => $accId]);
        $targetAcc = $accStmt->fetch();

        if ($targetAcc) {
            $newStatus = ($targetAcc['status'] === 'active') ? 'inactive' : 'active';
            $db->prepare("UPDATE financial_accounts SET status = :st WHERE id = :id")->execute([':st' => $newStatus, ':id' => $accId]);
            Audit::log('STATUS_CHANGE', 'FINANCIAL_ACCOUNTS', $accId, ['old' => $targetAcc['status']], ['new' => $newStatus]);
            $msgStatus = ($newStatus === 'active') ? 'activated / enabled' : 'disabled / deactivated';
            Helper::setFlash('success', "Account '{$targetAcc['account_name']}' has been {$msgStatus}.");
            Helper::redirect('modules/accounting/accounts.php');
        } else {
            $error = 'Account not found.';
        }
    } elseif ($action === 'delete_account') {
        $accId = (int)($_POST['account_id'] ?? 0);
        $transferToId = !empty($_POST['transfer_to_account_id']) ? (int)$_POST['transfer_to_account_id'] : null;

        $accStmt = $db->prepare("SELECT * FROM financial_accounts WHERE id = :id");
        $accStmt->execute([':id' => $accId]);
        $targetAcc = $accStmt->fetch();

        if (!$targetAcc) {
            $error = 'Account not found.';
        } else {
            $totalAccounts = (int)$db->query("SELECT COUNT(*) FROM financial_accounts")->fetchColumn();
            if ($totalAccounts <= 1) {
                $error = 'Cannot delete the only remaining financial account in the system.';
            } else {
                $toAcc = null;
                if ($transferToId && $transferToId !== $accId) {
                    $toStmt = $db->prepare("SELECT * FROM financial_accounts WHERE id = :id");
                    $toStmt->execute([':id' => $transferToId]);
                    $toAcc = $toStmt->fetch();
                }

                $db->beginTransaction();
                try {
                    if ($toAcc) {
                        $db->prepare("UPDATE staff_payments SET account_id = :to_id WHERE account_id = :from_id")
                           ->execute([':to_id' => $toAcc['id'], ':from_id' => $accId]);
                        
                        $db->prepare("UPDATE client_payments SET account_id = :to_id WHERE account_id = :from_id")
                           ->execute([':to_id' => $toAcc['id'], ':from_id' => $accId]);
                        
                        $db->prepare("UPDATE expenses SET account_id = :to_id WHERE account_id = :from_id")
                           ->execute([':to_id' => $toAcc['id'], ':from_id' => $accId]);
                        
                        $db->prepare("UPDATE ledger_entries SET account_id = :to_id WHERE account_id = :from_id")
                           ->execute([':to_id' => $toAcc['id'], ':from_id' => $accId]);
                        
                        $db->prepare("UPDATE ledger_entries SET entity_id = :to_id WHERE entity_type = 'financial_account' AND entity_id = :from_id")
                           ->execute([':to_id' => $toAcc['id'], ':from_id' => $accId]);

                        $remBal = (float)$targetAcc['current_balance'];
                        if ($remBal != 0) {
                            $db->prepare("UPDATE financial_accounts SET current_balance = current_balance + :bal WHERE id = :to_id")
                               ->execute([':bal' => $remBal, ':to_id' => $toAcc['id']]);
                        }
                    } else {
                        $db->prepare("UPDATE ledger_entries SET account_id = NULL WHERE account_id = :from_id")
                           ->execute([':from_id' => $accId]);
                    }

                    $db->prepare("DELETE FROM financial_accounts WHERE id = :id")->execute([':id' => $accId]);

                    $db->commit();
                    Audit::log('DELETE', 'FINANCIAL_ACCOUNTS', $accId, ['deleted_account' => $targetAcc['account_name']], ['reassigned_to' => $toAcc ? $toAcc['account_name'] : null]);
                    
                    $successMsg = "Account '{$targetAcc['account_name']}' has been permanently deleted.";
                    if ($toAcc) {
                        $successMsg .= " Its transactions and balance have been safely transferred to '{$toAcc['account_name']}'.";
                    }
                    Helper::setFlash('success', $successMsg);
                    Helper::redirect('modules/accounting/accounts.php');
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Failed to delete account: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch Accounts
$accounts = $db->query("SELECT * FROM financial_accounts ORDER BY id ASC")->fetchAll();

// Fetch Ledger Transactions
$filterAccId = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$ledSql = "
    SELECT le.*, fa.account_name, fa.account_type, u.full_name as created_by_name
    FROM ledger_entries le
    LEFT JOIN financial_accounts fa ON le.account_id = fa.id
    LEFT JOIN users u ON le.created_by = u.id
    WHERE 1=1
";
$ledParams = [];
if ($filterAccId) {
    $ledSql .= " AND le.account_id = :accid";
    $ledParams[':accid'] = $filterAccId;
}
$ledSql .= " ORDER BY le.entry_date DESC, le.id DESC LIMIT 100";

$stmt = $db->prepare($ledSql);
$stmt->execute($ledParams);
$ledger = $stmt->fetchAll();

// Total liquid balance across all accounts
$totalLiquidity = array_sum(array_column($accounts, 'current_balance'));

$pageTitle = 'Financial Accounts & Master Ledger';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-journal-bookmark-fill text-primary me-2"></i>Accounts & Financial Ledgers</h4>
        <p class="text-muted small mb-0">Maintain cash in hand, bank accounts, and UPI balances with synchronized double-entry postings.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-success btn-sm fw-semibold" onclick="openOpeningBalanceModal()">
            <i class="bi bi-cash-stack me-1"></i> Add / Set Opening Balance
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" onclick="openTransferModal()">
            <i class="bi bi-arrow-left-right me-1"></i> Inter-Account Transfer
        </button>
        <button type="button" class="btn btn-primary btn-sm fw-semibold" onclick="openNewAccountModal()">
            <i class="bi bi-plus-lg me-1"></i> Add Account
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Accounts Cards -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center p-3 bg-white rounded-3 border shadow-sm">
            <div>
                <span class="text-muted small text-uppercase fw-bold">Total Liquid Capital Across Accounts</span>
                <div class="fs-3 fw-bold text-dark"><?= Helper::formatCurrency($totalLiquidity) ?></div>
            </div>
            <div class="text-muted small">
                <i class="bi bi-shield-check text-success fs-5 me-1"></i> Synchronized Real-Time
            </div>
        </div>
    </div>

    <?php foreach ($accounts as $acc): 
        $typeIcon = match($acc['account_type']) {
            'cash' => 'bi-cash-coin text-warning',
            'bank' => 'bi-bank text-primary',
            'upi'  => 'bi-phone text-success',
            default => 'bi-wallet2 text-secondary'
        };
        $isInactive = ($acc['status'] === 'inactive');
    ?>
        <div class="col-md-3 col-sm-6">
            <div class="custom-card h-100 p-3 shadow-sm border <?= $filterAccId == $acc['id'] ? 'border-primary' : '' ?> <?= $isInactive ? 'bg-light opacity-75' : '' ?>">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi <?= $typeIcon ?> fs-4"></i>
                        <div>
                            <strong class="text-dark d-block text-truncate" style="max-width: 140px;" title="<?= htmlspecialchars($acc['account_name']) ?>"><?= htmlspecialchars($acc['account_name']) ?></strong>
                            <small class="text-muted text-uppercase" style="font-size:0.68rem;"><?= $acc['account_type'] ?> &bull; <?= $acc['account_code'] ?></small>
                        </div>
                    </div>
                    <?php if (!$isInactive): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Disabled</span>
                    <?php endif; ?>
                </div>

                <div class="my-2">
                    <div class="d-flex justify-content-between align-items-center mb-0.5">
                        <div class="text-muted small fw-semibold" style="font-size:0.72rem; letter-spacing: 0.3px;">CURRENT BALANCE</div>
                        <span class="badge bg-light text-secondary border fw-medium" style="font-size:0.68rem;" title="Starting Opening Balance">
                            Opening: <?= Helper::formatCurrency($acc['opening_balance']) ?>
                        </span>
                    </div>
                    <div class="fs-4 fw-bold text-dark"><?= Helper::formatCurrency($acc['current_balance']) ?></div>
                </div>

                <?php if ($acc['account_number']): ?>
                    <div class="text-muted small text-truncate" style="font-size:0.75rem;" title="No: <?= htmlspecialchars($acc['account_number']) ?>">
                        No: <?= htmlspecialchars($acc['account_number']) ?>
                        <?php if ($acc['ifsc_code']): ?> &bull; IFSC: <?= htmlspecialchars($acc['ifsc_code']) ?><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted small" style="font-size:0.75rem;">
                        Physical Cash Drawer / Safe
                    </div>
                <?php endif; ?>

                <div class="mt-3 pt-2 border-top d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 flex-grow-1" 
                            style="font-size:0.78rem;" 
                            onclick='openEditAccountModal(<?= json_encode($acc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                        <i class="bi bi-pencil-square me-1"></i> Edit
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-success py-1 px-2" style="font-size:0.78rem;" title="Set / Add Opening Balance"
                            onclick='openOpeningBalanceModal(<?= $acc['id'] ?>)'>
                        <i class="bi bi-cash-stack"></i>
                    </button>

                    <form method="POST" action="" class="d-inline">
                        <?= CSRF::tokenField() ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                        <?php if ($acc['status'] === 'active'): ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" style="font-size:0.78rem;" title="Deactivate / Disable Account">
                                <i class="bi bi-slash-circle"></i>
                            </button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-sm btn-success py-1 px-2" style="font-size:0.78rem;" title="Activate / Enable Account">
                                <i class="bi bi-check-circle"></i>
                            </button>
                        <?php endif; ?>
                    </form>

                    <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2" style="font-size:0.78rem;" title="Delete Financial Account"
                            onclick='openDeleteAccountModal(<?= json_encode($acc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                        <i class="bi bi-trash3"></i>
                    </button>

                    <a href="?account_id=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-size:0.78rem;" title="Filter Ledger">
                        <i class="bi bi-list-check"></i>
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Master Ledger Table -->
<div class="custom-card">
    <div class="custom-card-header">
        <div class="d-flex align-items-center gap-2">
            <h6 class="fw-bold mb-0">Master Financial Transaction Ledger</h6>
            <?php if ($filterAccId): ?>
                <span class="badge bg-primary-subtle text-primary">Filtered</span>
                <a href="<?= BASE_URL ?>/modules/accounting/accounts.php" class="small text-danger ms-2">Reset</a>
            <?php endif; ?>
        </div>
        <input type="text" class="form-control form-control-sm" placeholder="Search ledger entries..." data-table-search="#ledgerTable" style="width: 240px;">
    </div>
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="ledgerTable">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Txn ID</th>
                    <th>Account / Entity</th>
                    <th>Source Type</th>
                    <th>Narration</th>
                    <th class="text-end">Debit (Inflow)</th>
                    <th class="text-end">Credit (Outflow)</th>
                    <th class="text-end">Running Balance</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No ledger transactions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($ledger as $l): ?>
                        <tr>
                            <td><?= Helper::formatDate($l['entry_date']) ?></td>
                            <td><code class="text-dark"><?= htmlspecialchars($l['transaction_id']) ?></code></td>
                            <td>
                                <?php if ($l['account_name']): ?>
                                    <strong><?= htmlspecialchars($l['account_name']) ?></strong>
                                <?php else: ?>
                                    <span class="text-capitalize text-muted"><?= htmlspecialchars($l['entity_type']) ?> #<?= $l['entity_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border text-capitalize">
                                    <?= str_replace('_', ' ', $l['source_type']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 260px;" title="<?= htmlspecialchars($l['narration']) ?>">
                                    <?= htmlspecialchars($l['narration']) ?>
                                </div>
                            </td>
                            <td class="text-end">
                                <?php if ($l['entry_type'] === 'debit'): ?>
                                    <strong class="text-success">+<?= Helper::formatCurrency($l['amount']) ?></strong>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($l['entry_type'] === 'credit'): ?>
                                    <strong class="text-danger">-<?= Helper::formatCurrency($l['amount']) ?></strong>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold text-dark">
                                <?= Helper::formatCurrency($l['running_balance']) ?>
                            </td>
                            <td><small class="text-muted"><?= htmlspecialchars($l['created_by_name'] ?? 'System') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Inter-Account Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="transfer_funds">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Inter-Account Fund Transfer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">From Account (Source) *</label>
                            <select name="from_account_id" class="form-select" required>
                                <option value="">-- Choose Sending Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?> (Avail: <?= Helper::formatCurrency($acc['current_balance']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">To Account (Destination) *</label>
                            <select name="to_account_id" class="form-select" required>
                                <option value="">-- Choose Receiving Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Transfer Date *</label>
                            <input type="date" name="transfer_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Amount (₹) *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="10" name="amount" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Reference / ATM Slip / UTR</label>
                            <input type="text" name="reference_number" class="form-control" placeholder="e.g. ATM Cash Withdrawal / IMPS Ref">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-check2-circle me-1"></i> Execute Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add New Account Modal -->
<div class="modal fade" id="newAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="create_account">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Add Financial Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Account Code *</label>
                            <input type="text" name="account_code" class="form-control" placeholder="ACC-BANK-02" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Account Name *</label>
                            <input type="text" name="account_name" class="form-control" placeholder="e.g. Axis Bank Current A/C" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Account Type</label>
                            <select name="account_type" class="form-select">
                                <option value="bank">Bank Account</option>
                                <option value="cash">Cash</option>
                                <option value="upi">UPI Merchant Account</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Opening Balance (₹)</label>
                            <input type="number" step="10" name="opening_balance" class="form-control" value="0.00">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Bank Name (if bank)</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="Axis Bank">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Account Number / UPI ID</label>
                            <input type="text" name="account_number" class="form-control" placeholder="Account or UPI handle">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">IFSC Code</label>
                            <input type="text" name="ifsc_code" class="form-control" placeholder="UTIB0001234">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Account Modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="edit_account">
                <input type="hidden" name="account_id" id="edit_account_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-1 text-primary"></i> Modify Financial Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Account Code</label>
                            <input type="text" id="edit_account_code" class="form-control bg-light" readonly>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">Account Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Active (Usable in Transactions)</option>
                                <option value="inactive">Disabled / Inactive (Hidden)</option>
                            </select>
                        </div>

                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">Account Name *</label>
                            <input type="text" name="account_name" id="edit_account_name" class="form-control" placeholder="e.g. Cash / Axis Current A/C" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Opening Balance (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="0.5" min="0" name="opening_balance" id="edit_opening_balance" class="form-control fw-semibold" placeholder="0.00">
                            </div>
                            <small class="text-muted" style="font-size:0.7rem;">Starting capital / initial ledger balance</small>
                        </div>

                        <div class="col-md-6" id="edit_bank_name_group">
                            <label class="form-label small fw-semibold">Bank Name</label>
                            <input type="text" name="bank_name" id="edit_bank_name" class="form-control" placeholder="e.g. HDFC Bank">
                        </div>
                        <div class="col-md-6" id="edit_account_number_group">
                            <label class="form-label small fw-semibold">Account Number / UPI ID</label>
                            <input type="text" name="account_number" id="edit_account_number" class="form-control" placeholder="e.g. 50200012345678">
                        </div>

                        <div class="col-12" id="edit_ifsc_group">
                            <label class="form-label small fw-semibold">IFSC Code</label>
                            <input type="text" name="ifsc_code" id="edit_ifsc_code" class="form-control text-uppercase" placeholder="e.g. HDFC0001234">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteFromEditModal()">
                        <i class="bi bi-trash3 me-1"></i> Delete Account
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3">
                            <i class="bi bi-save me-1"></i> Update Account
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="background-color: #ffffff !important;">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="delete_account">
                <input type="hidden" name="account_id" id="deleteAccId">
                <div class="modal-header bg-danger text-white">
                    <h6 class="modal-title fw-bold"><i class="bi bi-trash3-fill me-2"></i>Permanently Delete Financial Account</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" style="background-color: #ffffff !important;">
                    <div class="text-center mb-3">
                        <div class="avatar-lg bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center p-3 mb-2">
                            <i class="bi bi-exclamation-triangle-fill fs-2"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Kya aap sure hain ki is account ko delete karna chahte hain?</h6>
                        <p class="text-muted small mb-0">Account: <strong id="deleteAccName" class="text-danger"></strong> (<span id="deleteAccCode"></span>)</p>
                        <div class="text-muted small mt-1">Current Balance: <strong id="deleteAccBalance" class="text-dark"></strong></div>
                    </div>

                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-shield-lock me-1"></i> 
                        Past audit & transaction records safe rakhne ke liye, is account ke purane payments aur balance ko niche chune gaye account me transfer/reassign kiya jayega.
                    </div>

                    <div class="mb-3" id="transferTargetDiv">
                        <label class="form-label small fw-semibold">Transfer / Reassign Linked Transactions & Balance To: *</label>
                        <select name="transfer_to_account_id" id="deleteTransferToSelect" class="form-select form-select-sm" required>
                            <!-- Populated dynamically via JavaScript -->
                        </select>
                        <small class="text-muted" style="font-size:0.72rem;">Purane vouchers, salary records aur ledger entries selected account par safely reassign ho jayenge.</small>
                    </div>
                </div>
                <div class="modal-footer bg-light" style="background-color: #f8fafc !important;">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold">
                        <i class="bi bi-trash3-fill me-1"></i> Confirm & Delete Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Set Opening Balance / Capital Inflow Modal -->
<div class="modal fade" id="openingBalanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="set_opening_balance">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-cash-stack me-2"></i>Add / Set Opening Balance & Capital</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Select Financial Account *</label>
                            <select name="account_id" id="ob_account_id" class="form-select" required onchange="onObAccountChange()">
                                <option value="">-- Choose Account --</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>" data-type="<?= $a['account_type'] ?>" data-curr="<?= $a['current_balance'] ?>" data-open="<?= $a['opening_balance'] ?>">
                                        <?= htmlspecialchars($a['account_name']) ?> (<?= strtoupper($a['account_type']) ?> &bull; Current: <?= Helper::formatCurrency($a['current_balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">As-On / Entry Date *</label>
                            <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Amount (₹) *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold">₹</span>
                                <input type="number" step="0.5" min="0" name="amount" id="ob_amount" class="form-control fw-bold text-success fs-6" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Adjustment Type</label>
                            <select name="adjustment_type" id="ob_adjustment_type" class="form-select">
                                <option value="opening_balance">Set Initial Opening Balance (Starting Ledger Capital)</option>
                                <option value="capital_deposit">Add Direct Capital Inflow / Owner Investment (Credits Balance)</option>
                            </select>
                            <small class="text-muted" style="font-size:0.72rem;">Agar aap pehli baar balance daal rahe hain to "Set Initial Opening Balance" chunein.</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Narration / Remarks</label>
                            <input type="text" name="narration" id="ob_narration" class="form-control" placeholder="e.g. Initial Cash in Hand / Starting Bank Balance">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm px-3 fw-bold">
                        <i class="bi bi-check2-circle me-1"></i> Save & Post Balance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const allAccountsData = <?= json_encode($accounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
const newAccountModal = new bootstrap.Modal(document.getElementById('newAccountModal'));
const editAccountModal = new bootstrap.Modal(document.getElementById('editAccountModal'));
const deleteAccountModal = new bootstrap.Modal(document.getElementById('deleteAccountModal'));
const openingBalanceModal = new bootstrap.Modal(document.getElementById('openingBalanceModal'));

function openTransferModal() { transferModal.show(); }
function openNewAccountModal() { newAccountModal.show(); }

function openOpeningBalanceModal(accId = null) {
    const sel = document.getElementById('ob_account_id');
    if (accId) {
        sel.value = accId;
        onObAccountChange();
    } else {
        sel.value = '';
        document.getElementById('ob_amount').value = '';
        document.getElementById('ob_narration').value = '';
    }
    openingBalanceModal.show();
}

function onObAccountChange() {
    const sel = document.getElementById('ob_account_id');
    const accId = sel.value;
    const acc = allAccountsData.find(a => a.id == accId);
    if (acc) {
        const openBal = parseFloat(acc.opening_balance || 0);
        document.getElementById('ob_amount').value = openBal > 0 ? openBal.toFixed(2) : (parseFloat(acc.current_balance || 0) > 0 ? parseFloat(acc.current_balance).toFixed(2) : '');
        document.getElementById('ob_narration').value = `Opening balance for ${acc.account_name}`;
    }
}

function openEditAccountModal(acc) {
    document.getElementById('edit_account_id').value = acc.id;
    document.getElementById('edit_account_code').value = acc.account_code || '';
    document.getElementById('edit_account_name').value = acc.account_name || '';
    document.getElementById('edit_status').value = acc.status || 'active';
    document.getElementById('edit_opening_balance').value = parseFloat(acc.opening_balance || 0).toFixed(2);
    document.getElementById('edit_bank_name').value = acc.bank_name || '';
    document.getElementById('edit_account_number').value = acc.account_number || '';
    document.getElementById('edit_ifsc_code').value = acc.ifsc_code || '';

    const isCash = (acc.account_type === 'cash');
    document.getElementById('edit_bank_name_group').style.display = isCash ? 'none' : 'block';
    document.getElementById('edit_account_number_group').style.display = isCash ? 'none' : 'block';
    document.getElementById('edit_ifsc_group').style.display = isCash ? 'none' : 'block';

    editAccountModal.show();
}

function openDeleteAccountModal(acc) {
    document.getElementById('deleteAccId').value = acc.id;
    document.getElementById('deleteAccName').textContent = acc.account_name;
    document.getElementById('deleteAccCode').textContent = acc.account_code || '';
    document.getElementById('deleteAccBalance').textContent = '₹' + parseFloat(acc.current_balance || 0).toLocaleString('en-IN', {minimumFractionDigits: 2});

    const select = document.getElementById('deleteTransferToSelect');
    select.innerHTML = '';
    
    const otherAccounts = allAccountsData.filter(a => a.id != acc.id);
    if (otherAccounts.length > 0) {
        otherAccounts.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = `${a.account_name} (${a.account_type.toUpperCase()} • Balance: ₹${parseFloat(a.current_balance || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})})`;
            select.appendChild(opt);
        });
        document.getElementById('transferTargetDiv').style.display = 'block';
    } else {
        document.getElementById('transferTargetDiv').style.display = 'none';
    }

    deleteAccountModal.show();
}

function deleteFromEditModal() {
    const accId = document.getElementById('edit_account_id').value;
    editAccountModal.hide();
    const acc = allAccountsData.find(a => a.id == accId);
    if (acc) {
        setTimeout(() => openDeleteAccountModal(acc), 300);
    }
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
