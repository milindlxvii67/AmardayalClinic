<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_staff();

// Shared medicine/service catalogue used by both billing.php and manage_medicines.php.
$conn->query("
    CREATE TABLE IF NOT EXISTS clinic_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'Medicine',
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_clinic_item_name_category (name, category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$role = $_SESSION['staff_role'] ?? '';
if (!in_array($role, ['Admin', 'Reception'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

function bill_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// The shared sidebar.php uses e() for escaped output. Define it only if
// config.php has not already provided the same helper.
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function bill_has_column(mysqli $conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $r && $r->num_rows > 0;
}

function bill_next_invoice(mysqli $conn): string {
    $prefix = 'INV-' . date('Ym') . '-';
    $stmt = $conn->prepare("SELECT invoice_no FROM bills WHERE invoice_no LIKE ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $like = $prefix . '%';
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && preg_match('/(\d+)$/', $row['invoice_no'], $m)) {
            return $prefix . str_pad((string)((int)$m[1] + 1), 4, '0', STR_PAD_LEFT);
        }
    }
    return $prefix . '0001';
}

function money($value): string {
    return '₹' . number_format((float)$value, 2);
}

$flash = $_SESSION['billing_flash'] ?? null;
unset($_SESSION['billing_flash']);

$selectedPatient = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$selectedVisit = (int)($_GET['visit_id'] ?? $_POST['visit_id'] ?? 0);
$action = $_POST['action'] ?? '';

/* ============================================================
   SAVE BILL
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_bill') {
    $patientId  = (int)($_POST['patient_id'] ?? 0);
    $visitId    = (int)($_POST['visit_id'] ?? 0);
    $category   = trim($_POST['category'] ?? 'Medicine');
    $payment    = trim($_POST['payment_mode'] ?? 'Cash');
    $discount   = (float)($_POST['discount'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    $names  = $_POST['item_name'] ?? [];
    $qtys   = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $items = [];
    $subtotal = 0.0;

    if ($patientId <= 0) {
        $_SESSION['billing_flash'] = ['type'=>'error','message'=>'Please select a patient.'];
        header('Location: billing.php');
        exit;
    }

    if (!is_array($names) || !is_array($qtys) || !is_array($prices)) {
        $_SESSION['billing_flash'] = ['type'=>'error','message'=>'Please add at least one bill item.'];
        header('Location: billing.php?patient_id=' . $patientId);
        exit;
    }

    foreach ($names as $i => $name) {
        $name = trim((string)$name);
        $qty = isset($qtys[$i]) ? (int)$qtys[$i] : 0;
        $price = isset($prices[$i]) ? (float)$prices[$i] : -1;

        if ($name === '' && $qty === 0 && $price <= 0) {
            continue;
        }

        if ($name === '' || $qty <= 0 || $price < 0) {
            $_SESSION['billing_flash'] = ['type'=>'error','message'=>'Every bill item must have a name, valid quantity and price.'];
            header('Location: billing.php?patient_id=' . $patientId . ($visitId ? '&visit_id=' . $visitId : ''));
            exit;
        }

        $total = $qty * $price;
        $items[] = ['name'=>$name, 'qty'=>$qty, 'price'=>$price, 'total'=>$total];
        $subtotal += $total;
    }

    if (!$items) {
        $_SESSION['billing_flash'] = ['type'=>'error','message'=>'Please add at least one bill item.'];
        header('Location: billing.php?patient_id=' . $patientId . ($visitId ? '&visit_id=' . $visitId : ''));
        exit;
    }

    $discount = max(0, min($discount, $subtotal));
    $net = max(0, $subtotal - $discount);
    $invoiceNo = bill_next_invoice($conn);
    $createdBy = $_SESSION['staff_username'] ?? $_SESSION['staff_name'] ?? 'staff';
    $billDate = date('Y-m-d');
    $createdAt = date('Y-m-d H:i:s');

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO bills
            (invoice_no, patient_id, visit_id, bill_date, category, total_amount,
             discount, net_amount, payment_mode, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception('Unable to prepare invoice.');
        }

        $stmt->bind_param(
            'siissdddssss',
            $invoiceNo,
            $patientId,
            $visitId,
            $billDate,
            $category,
            $subtotal,
            $discount,
            $net,
            $payment,
            $notes,
            $createdBy,
            $createdAt
        );

        if (!$stmt->execute()) {
            throw new Exception('Unable to save invoice.');
        }

        $billId = $stmt->insert_id;
        $stmt->close();

        $itemStmt = $conn->prepare("
            INSERT INTO bill_items
            (bill_id, item_name, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$itemStmt) {
            throw new Exception('Unable to prepare invoice items.');
        }

        foreach ($items as $item) {
            $itemStmt->bind_param(
                'isidd',
                $billId,
                $item['name'],
                $item['qty'],
                $item['price'],
                $item['total']
            );

            if (!$itemStmt->execute()) {
                throw new Exception('Unable to save an invoice item.');
            }
        }

        $itemStmt->close();
        $conn->commit();

        if (function_exists('audit')) {
            audit('CREATE_BILL', 'Invoice ' . $invoiceNo . ' generated for ' . money($net));
        }

        $_SESSION['billing_flash'] = [
            'type'=>'success',
            'message'=>'Invoice ' . $invoiceNo . ' created successfully.'
        ];

        header('Location: billing.php?invoice_id=' . $billId);
        exit;

    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['billing_flash'] = [
            'type'=>'error',
            'message'=>'Invoice could not be created. ' . $e->getMessage()
        ];
        header('Location: billing.php?patient_id=' . $patientId);
        exit;
    }
}

/* ============================================================
   AJAX PATIENT SEARCH
   Searches the same database table directly on each request.
   ============================================================ */
if (isset($_GET['ajax_patient_search'])) {
    header('Content-Type: application/json; charset=utf-8');
    $query = trim((string)($_GET['q'] ?? ''));
    $queryDigits = preg_replace('/\D+/', '', $query);
    $queryCanonical = strlen($queryDigits) >= 10 ? substr($queryDigits, -10) : $queryDigits;
    $results = [];

    $searchResult = $conn->query("SELECT id, code, name, age, gender, phone AS mobile FROM patients ORDER BY name ASC");
    if ($searchResult) {
        while ($row = $searchResult->fetch_assoc()) {
            $name = (string)($row['name'] ?? '');
            $code = (string)($row['code'] ?? '');
            $mobile = (string)($row['mobile'] ?? '');
            $digits = preg_replace('/\D+/', '', $mobile);
            $canonical = strlen($digits) >= 10 ? substr($digits, -10) : $digits;

            $textMatch = ($query === '') ||
                (stripos($name, $query) !== false) ||
                (stripos($code, $query) !== false) ||
                (stripos('AD-' . $code, $query) !== false) ||
                ((string)$row['id'] === $query);
            $phoneMatch = $queryDigits !== '' && $digits !== '' &&
                (strpos($digits, $queryDigits) !== false ||
                 (strlen($queryCanonical) === 10 && strlen($canonical) === 10 && $canonical === $queryCanonical));

            if ($textMatch || $phoneMatch) {
                $results[] = [
                    'id' => (int)$row['id'],
                    'code' => $code,
                    'name' => $name,
                    'mobile' => $mobile,
                    'age' => (string)($row['age'] ?? ''),
                    'gender' => (string)($row['gender'] ?? '')
                ];
            }
        }
        $searchResult->free();
    }

    echo json_encode(['results' => $results, 'count' => count($results)], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
}

/* ============================================================
   PATIENT LIST
   ============================================================ */
$patients = [];
$r = $conn->query("
    SELECT id, code, name, age, gender, phone AS mobile
    FROM patients
    ORDER BY name ASC
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $patients[] = $row;
    }
}

/* ============================================================
   SELECTED PATIENT
   ============================================================ */
$patient = null;
if ($selectedPatient > 0) {
    $stmt = $conn->prepare("
        SELECT id, code, name, age, gender, phone AS mobile, address, district
        FROM patients
        WHERE id=?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $selectedPatient);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* ============================================================
   SELECTED VISIT
   ============================================================ */
$visit = null;
if ($selectedVisit > 0) {
    $stmt = $conn->prepare("
        SELECT v.id, v.opd_serial, v.patient_id, v.visit_date, v.main_conditions,
               v.diagnosis, v.prescription, v.status, p.name, p.code
        FROM visits v
        INNER JOIN patients p ON p.id=v.patient_id
        WHERE v.id=?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $selectedVisit);
        $stmt->execute();
        $visit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($visit) {
            $selectedPatient = (int)$visit['patient_id'];
            if (!$patient) {
                $stmt = $conn->prepare("
                    SELECT id, code, name, age, gender, phone AS mobile, address, district
                    FROM patients WHERE id=? LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param('i', $selectedPatient);
                    $stmt->execute();
                    $patient = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
            }
        }
    }
}

/* ============================================================
   RECENT COMPLETED VISITS FOR QUICK BILLING
   ============================================================ */
$completedVisits = [];
$stmt = $conn->prepare("
    SELECT v.id AS visit_id, v.opd_serial, v.visit_date, v.main_conditions,
           p.id AS patient_id, p.code, p.name, p.phone AS mobile
    FROM visits v
    INNER JOIN patients p ON p.id=v.patient_id
    WHERE v.status='Completed'
    ORDER BY v.id DESC
    LIMIT 20
");
if ($stmt) {
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $completedVisits[] = $row;
    }
    $stmt->close();
}

/* ============================================================
   RECENT INVOICES
   ============================================================ */
$recentBills = [];
$r = $conn->query("
    SELECT b.id, b.invoice_no, b.bill_date, b.category, b.net_amount,
           b.payment_mode, p.code, p.name
    FROM bills b
    INNER JOIN patients p ON p.id=b.patient_id
    ORDER BY b.id DESC
    LIMIT 30
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $recentBills[] = $row;
    }
}

/* ============================================================
   SELECTED INVOICE FOR PRINT / PREVIEW
   ============================================================ */
$invoice = null;
$invoiceItems = [];

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
if ($invoiceId > 0) {
    $stmt = $conn->prepare("
        SELECT b.*, p.code AS patient_code, p.name AS patient_name, p.age,
               p.gender, p.phone AS mobile, p.address, p.district
        FROM bills b
        INNER JOIN patients p ON p.id=b.patient_id
        WHERE b.id=?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($invoice) {
        $stmt = $conn->prepare("
            SELECT item_name, quantity, unit_price, total_price
            FROM bill_items
            WHERE bill_id=?
            ORDER BY id ASC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $invoiceItems[] = $row;
            }
            $stmt->close();
        }
    }
}

$categories = ['Medicine','Therapy','Lab','Consultation','Miscellaneous'];
$paymentModes = ['Cash','UPI','Card','Online'];

// Load active medicines/services. Existing invoice items remain snapshots in bill_items.
$catalogItems = [];
$catalogResult = $conn->query("SELECT name, category, unit_price FROM clinic_items WHERE is_active = 1 ORDER BY category, name");
if ($catalogResult) {
    while ($catalogRow = $catalogResult->fetch_assoc()) {
        $catalogItems[] = [
            'name' => (string)$catalogRow['name'],
            'category' => (string)$catalogRow['category'],
            'price' => (float)$catalogRow['unit_price']
        ];
    }
    $catalogResult->free();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Billing & Pharmacy | AmarDayal Clinic</title>
<link rel="stylesheet" href="../Styles/style.css">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7fb;color:#10233f;font-family:"Segoe UI",Arial,sans-serif}
.layout{min-height:100vh;display:flex}




.content{margin-left:270px;width:calc(100% - 270px);padding:30px;max-width:1600px}
.page-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:22px}
.page-head h1{margin:0;font-size:30px}.page-head p{margin:6px 0;color:#64748b}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 8px 25px rgba(15,23,42,.05);overflow:hidden}
.card-head{padding:18px 20px;border-bottom:1px solid #edf2f7;display:flex;justify-content:space-between;align-items:center}
.card-head h2{margin:0;font-size:17px}
.grid{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:18px;align-items:start}
.form-card{padding:20px}
label{display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:6px}
.patient-search-wrap{position:relative}
#patientSearch{padding-right:42px}
.patient-results{position:absolute;top:calc(100% + 5px);left:0;right:0;z-index:1100;max-height:280px;overflow:auto;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 14px 30px rgba(15,23,42,.16);display:none}
.patient-result{display:block;width:100%;padding:11px 13px;border:0;border-bottom:1px solid #eef2f7;background:#fff;text-align:left;cursor:pointer;color:#10233f}
.patient-result:last-child{border-bottom:0}
.patient-result:hover,.patient-result:focus{background:#eff6ff;outline:none}
.patient-result strong{display:block;font-size:13px}
.patient-result small{display:block;margin-top:3px;color:#64748b;font-size:11px}
.patient-selected{margin-top:6px;color:#047857;font-size:11px;font-weight:800}
.patient-no-results{padding:13px;color:#64748b;font-size:12px}
input,select,textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;font:inherit;background:#fff}
input:focus,select:focus,textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
textarea{min-height:85px;resize:vertical}
.field-grid{display:grid;grid-template-columns:1.6fr 1fr 1fr;gap:12px}
.section-title{margin:22px 0 10px;font-size:15px;font-weight:900;color:#0f172a}
.items-head,.item-row{display:grid;grid-template-columns:minmax(0,1fr) 90px 130px 130px 40px;gap:8px;align-items:center}
.items-head{font-size:11px;text-transform:uppercase;font-weight:900;color:#64748b;margin-bottom:6px}
.item-row{margin-bottom:8px}
.catalogue-hint{font-size:12px;color:#64748b;margin:0 0 10px;line-height:1.5}.catalogue-hint a{color:#2563eb;font-weight:800;text-decoration:none}.catalogue-hint a:hover{text-decoration:underline}
.item-row input[list]{min-width:0}
.item-total{background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:10px;text-align:right;font-weight:900}
.remove{border:0;background:#fee2e2;color:#b91c1c;border-radius:9px;height:40px;font-weight:900;cursor:pointer}
.add{border:0;background:#e0f2fe;color:#0369a1;padding:10px 13px;border-radius:9px;font-weight:900;cursor:pointer}
.totals{margin-top:18px;margin-left:auto;max-width:330px;border-top:1px solid #e2e8f0;padding-top:12px}
.total-line{display:flex;justify-content:space-between;padding:6px 0;color:#475569}
.total-line.net{font-size:19px;font-weight:900;color:#0f766e;border-top:2px solid #e2e8f0;margin-top:6px;padding-top:12px}
.actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;border-radius:10px;padding:11px 16px;font-weight:900;cursor:pointer}
.primary{background:#2563eb;color:#fff}.secondary{background:#e2e8f0;color:#334155}
.alert{padding:14px 16px;border-radius:12px;margin-bottom:18px;font-weight:700}
.alert.success{background:#ecfdf5;border:1px solid #a7f3d0;color:#047857}
.alert.error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}
.quick-list{padding:12px}
.quick-item{display:block;text-decoration:none;color:inherit;border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:8px}
.quick-item:hover{border-color:#93c5fd;background:#f8fbff}
.quick-item strong{display:block}.quick-item span{font-size:12px;color:#64748b}
.invoice-preview{margin-top:18px;padding:22px}
.invoice-top{display:flex;justify-content:space-between;gap:15px;border-bottom:2px solid #0f766e;padding-bottom:14px}
.invoice-top h2{margin:0;color:#0f766e}.invoice-table{width:100%;border-collapse:collapse;margin-top:18px}
.invoice-table th,.invoice-table td{border-bottom:1px solid #e2e8f0;padding:9px;text-align:left}
.invoice-table th{font-size:11px;text-transform:uppercase;color:#64748b}
.invoice-table td:last-child,.invoice-table th:last-child{text-align:right}
.invoice-total{max-width:320px;margin:15px 0 0 auto}
.recent{margin-top:18px}
.table-wrap{overflow:auto}.recent table{width:100%;border-collapse:collapse}
.recent th,.recent td{padding:11px 12px;border-bottom:1px solid #e2e8f0;text-align:left;white-space:nowrap}
.recent th{font-size:11px;color:#64748b;text-transform:uppercase}
.print-only{display:none}
@media print{
 body{background:#fff}.admin-sidebar,.page-head,.alert,.grid,.recent,.actions,.print-hide{display:none!important}
 .content{margin:0;width:100%;padding:0;max-width:none}
 .invoice-preview{display:block!important;box-shadow:none;border:0;margin:0;padding:20px}
 .print-only{display:block}
}
@media(max-width:1100px){.grid{grid-template-columns:1fr}.field-grid{grid-template-columns:1fr 1fr}}
@media(max-width:700px){
 .content{margin-left:0;width:100%;padding:16px}
 .field-grid{grid-template-columns:1fr}.items-head{display:none}.item-row{grid-template-columns:1fr 80px 1fr 1fr 40px}
}
</style>
</head>
<body>
<div class="layout">

<?php include __DIR__ . '/sidebar.php'; ?>

<main class="content">
<div class="page-head">
<div>
<h1>Billing & Pharmacy</h1>
<p>Create invoices for medicines, therapy, laboratory services and other clinic charges.</p>
</div>
<div><strong><?= bill_e(date('d M Y')) ?></strong></div>
</div>

<?php if ($flash): ?>
<div class="alert <?= bill_e($flash['type']) ?>"><?= bill_e($flash['message']) ?></div>
<?php endif; ?>

<div class="grid">

<section class="card">
<div class="card-head">
<h2>Create New Invoice</h2>
<span>Amounts in INR</span>
</div>

<form method="post" action="billing.php" class="form-card" id="billForm">
<input type="hidden" name="action" value="create_bill">
<input type="hidden" name="patient_id" id="patientId" value="<?= (int)$selectedPatient ?>">
<input type="hidden" name="visit_id" id="visitId" value="<?= (int)$selectedVisit ?>">

<div class="field-grid">
<div>
<label for="patientSearch">Patient</label>
<div class="patient-search-wrap">
    <input type="search" id="patientSearch" placeholder="Search by phone, name or patient ID..." autocomplete="off" aria-label="Search patients by phone number, name or patient ID" aria-controls="patientResults" aria-expanded="false" required>
    <div id="patientResults" class="patient-results" role="listbox"></div>
</div>
<div id="patientSelectedLabel" class="patient-selected" aria-live="polite"></div>
</div>
<div>
<label>Category</label>
<select name="category">
<?php foreach ($categories as $cat): ?>
<option value="<?= bill_e($cat) ?>"><?= bill_e($cat) ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label>Payment Mode</label>
<select name="payment_mode">
<?php foreach ($paymentModes as $mode): ?>
<option value="<?= bill_e($mode) ?>"><?= bill_e($mode) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="section-title">Invoice Items</div>
<div class="items-head">
<div>Item / Service</div><div>Qty</div><div>Unit Price</div><div>Total</div><div></div>
</div>
<div class="catalogue-hint">Start typing a saved medicine/service and select it to fill in its price automatically. <a href="manage_medicines.php">Manage medicine catalogue</a>.</div>
<datalist id="medicineCatalogue">
<?php foreach ($catalogItems as $catalogItem): ?>
<option value="<?= bill_e($catalogItem['name']) ?>"><?= bill_e($catalogItem['category']) ?> — <?= money($catalogItem['price']) ?></option>
<?php endforeach; ?>
</datalist>
<div id="items"></div>

<button type="button" class="add" onclick="addItem()">＋ Add Item</button>

<div class="totals">
<div class="total-line"><span>Subtotal</span><strong id="subtotal">₹0.00</strong></div>
<div class="total-line">
<span>Discount</span>
<input type="number" name="discount" id="discount" value="0" min="0" step="0.01" style="width:140px;text-align:right" oninput="recalculate()">
</div>
<div class="total-line net"><span>Net Amount</span><span id="net">₹0.00</span></div>
</div>

<div class="section-title">Notes</div>
<textarea name="notes" placeholder="Optional billing notes..."></textarea>

<div class="actions">
<button class="btn primary" type="submit">✓ Create Invoice</button>
<a class="btn secondary" href="billing.php">Clear</a>
</div>
</form>
</section>

<aside>
<section class="card">
<div class="card-head">
<h2>Completed Visits</h2>
<span>Quick billing</span>
</div>
<div class="quick-list">
<?php if ($completedVisits): ?>
<?php foreach ($completedVisits as $v): ?>
<a class="quick-item" href="billing.php?patient_id=<?= (int)$v['patient_id'] ?>&visit_id=<?= (int)$v['visit_id'] ?>">
<strong>OPD-<?= (int)$v['opd_serial'] ?> · <?= bill_e($v['name']) ?></strong>
<span>AD-<?= bill_e($v['code']) ?> · <?= bill_e($v['visit_date']) ?> · <?= bill_e($v['main_conditions'] ?: 'Visit') ?></span>
</a>
<?php endforeach; ?>
<?php else: ?>
<div style="padding:25px;text-align:center;color:#64748b">No completed visits yet.</div>
<?php endif; ?>
</div>
</section>
</aside>

</div>

<?php if ($invoice): ?>
<section class="card invoice-preview" id="invoicePreview">
<div class="invoice-top">
<div>
<h2>AMARDAYAL JOINT & PAIN CARE CENTER</h2>
<div style="margin-top:4px;color:#64748b">Official Receipt / Payment Invoice</div>
</div>
<div style="text-align:right">
<strong><?= bill_e($invoice['invoice_no']) ?></strong><br>
<?= bill_e($invoice['bill_date']) ?>
</div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px">
<div><strong>Patient:</strong> <?= bill_e($invoice['patient_name']) ?></div>
<div><strong>Patient ID:</strong> AD-<?= bill_e($invoice['patient_code']) ?></div>
<div><strong>Mobile:</strong> <?= bill_e($invoice['mobile']) ?></div>
<div><strong>Payment:</strong> <?= bill_e($invoice['payment_mode']) ?></div>
</div>

<table class="invoice-table">
<thead><tr><th>#</th><th>Item / Service</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
<tbody>
<?php foreach ($invoiceItems as $i => $item): ?>
<tr>
<td><?= $i + 1 ?></td>
<td><?= bill_e($item['item_name']) ?></td>
<td><?= (int)$item['quantity'] ?></td>
<td><?= money($item['unit_price']) ?></td>
<td><?= money($item['total_price']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="invoice-total">
<div class="total-line"><span>Subtotal</span><strong><?= money($invoice['total_amount']) ?></strong></div>
<div class="total-line"><span>Discount</span><strong><?= money($invoice['discount']) ?></strong></div>
<div class="total-line net"><span>Net Total Paid</span><strong><?= money($invoice['net_amount']) ?></strong></div>
</div>

<div class="actions print-hide">
<button class="btn primary" onclick="window.print()">🖨 Print Invoice</button>
<a class="btn secondary" href="billing.php">New Invoice</a>
</div>
</section>
<?php endif; ?>

<section class="card recent">
<div class="card-head"><h2>Recent Invoices</h2><span>Latest 30</span></div>
<div class="table-wrap">
<table>
<thead><tr><th>Invoice</th><th>Date</th><th>Patient</th><th>Category</th><th>Amount</th><th>Payment</th></tr></thead>
<tbody>
<?php foreach ($recentBills as $b): ?>
<tr>
<td><a href="billing.php?invoice_id=<?= (int)$b['id'] ?>"><?= bill_e($b['invoice_no']) ?></a></td>
<td><?= bill_e($b['bill_date']) ?></td>
<td>AD-<?= bill_e($b['code']) ?> — <?= bill_e($b['name']) ?></td>
<td><?= bill_e($b['category']) ?></td>
<td><?= money($b['net_amount']) ?></td>
<td><?= bill_e($b['payment_mode']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$recentBills): ?>
<tr><td colspan="6" style="text-align:center;color:#64748b;padding:25px">No invoices have been created yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

</main>
</div>

<script>
window.clinicCatalogue = <?= json_encode($catalogItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
let itemIndex = 0;

function addItem(name='', qty=1, price=0) {
    const container = document.getElementById('items');
    const row = document.createElement('div');
    row.className = 'item-row';
    row.dataset.index = itemIndex++;

    row.innerHTML = `
        <input name="item_name[]" list="medicineCatalogue" placeholder="Search or type medicine/service" value="${escapeHtml(name)}" autocomplete="off" oninput="applyCataloguePrice(this, false)" onchange="applyCataloguePrice(this, true)" required>
        <input name="quantity[]" type="number" min="1" step="1" value="${qty}" oninput="recalculate()" required>
        <input name="unit_price[]" type="number" min="0" step="0.01" value="${price}" oninput="recalculate()" required>
        <div class="item-total">₹0.00</div>
        <button type="button" class="remove" aria-label="Remove item" onclick="this.parentElement.remove();recalculate()">×</button>
    `;

    container.appendChild(row);
    recalculate();
}

// When a saved catalogue name is selected/typed exactly, fill its stored price.
// Staff can still enter a one-off item manually if it is not in the catalogue.
function applyCataloguePrice(input, exactOnly) {
    const typed = input.value.trim().toLocaleLowerCase();
    const row = input.closest('.item-row');
    if (!row || !typed) return;

    const catalogue = window.clinicCatalogue || [];
    const exact = catalogue.find(item => item.name.toLocaleLowerCase() === typed);
    if (exact) {
        row.querySelector('[name="unit_price[]"]').value = Number(exact.price).toFixed(2);
        recalculate();
        return;
    }

    if (!exactOnly) {
        const match = catalogue.find(item => item.name.toLocaleLowerCase().startsWith(typed));
        if (match && match.name.toLocaleLowerCase() === typed) {
            row.querySelector('[name="unit_price[]"]').value = Number(match.price).toFixed(2);
            recalculate();
        }
    }
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
}

function recalculate() {
    let subtotal = 0;

    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
        const price = parseFloat(row.querySelector('[name="unit_price[]"]').value) || 0;
        const total = qty * price;
        row.querySelector('.item-total').textContent = '₹' + total.toFixed(2);
        subtotal += total;
    });

    const discount = Math.max(0, parseFloat(document.getElementById('discount').value) || 0);
    const net = Math.max(0, subtotal - discount);

    document.getElementById('subtotal').textContent = '₹' + subtotal.toFixed(2);
    document.getElementById('net').textContent = '₹' + net.toFixed(2);
}

// Search patients directly from the server/database so the result list is
// not dependent on a potentially stale client-side patient array.
const patientSearch = document.getElementById('patientSearch');
const patientIdInput = document.getElementById('patientId');
const patientResults = document.getElementById('patientResults');
const patientSelectedLabel = document.getElementById('patientSelectedLabel');
let patientSearchTimer = null;
let patientRequestSequence = 0;

function patientDisplay(patient) {
    return `AD-${patient.code} — ${patient.name} (${patient.mobile || 'No phone number'})`;
}
function closePatientResults() {
    patientResults.style.display = 'none';
    patientSearch.setAttribute('aria-expanded', 'false');
}
function renderPatientResults(query) {
    const requestId = ++patientRequestSequence;
    patientResults.innerHTML = '<div class="patient-no-results">Searching patients…</div>';
    patientResults.style.display = 'block';
    patientSearch.setAttribute('aria-expanded', 'true');

    const url = `billing.php?ajax_patient_search=1&q=${encodeURIComponent(query)}`;
    fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(data => {
            if (requestId !== patientRequestSequence) return;
            patientResults.innerHTML = '';
            const matches = Array.isArray(data.results) ? data.results : [];
            if (!matches.length) {
                patientResults.innerHTML =
                    '<div class="patient-no-results">No matching patients found in the database. Check the number or confirm this page uses the same clinic database as Patient Directory.</div>';
            } else {
                matches.forEach(patient => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'patient-result';
                    button.setAttribute('role', 'option');

                    const title = document.createElement('strong');
                    title.textContent = `AD-${patient.code} — ${patient.name}`;

                    const detail = document.createElement('small');
                    detail.textContent =
                        `Patient ID: AD-${patient.code} · Phone: ${patient.mobile || 'Not provided'} · ` +
                        `${patient.age ? 'Age: ' + patient.age : 'Age: N/A'} · ${patient.gender || 'Gender: N/A'}`;

                    button.append(title, detail);
                    button.addEventListener('click', () => selectBillingPatient(patient));
                    patientResults.appendChild(button);
                });
            }
            patientResults.style.display = 'block';
            patientSearch.setAttribute('aria-expanded', 'true');
        })
        .catch(error => {
            if (requestId !== patientRequestSequence) return;
            patientResults.innerHTML =
                '<div class="patient-no-results">Patient search failed. Refresh the page and check the browser console / PHP error log.</div>';
            patientResults.style.display = 'block';
            console.error('Patient search request failed:', error);
        });
}
function selectBillingPatient(patient) {
    patientIdInput.value = String(patient.id);
    patientSearch.value = patientDisplay(patient);
    patientSelectedLabel.textContent =
        `Selected patient: AD-${patient.code} · ${patient.name} · ${patient.mobile || 'No phone number'}`;
    closePatientResults();
}
patientSearch.addEventListener('input', () => {
    patientIdInput.value = '';
    patientSelectedLabel.textContent = '';
    patientSearch.setCustomValidity('');
    clearTimeout(patientSearchTimer);
    patientSearchTimer = setTimeout(() => renderPatientResults(patientSearch.value.trim()), 120);
});
patientSearch.addEventListener('focus', () => renderPatientResults(patientSearch.value.trim()));
document.addEventListener('click', event => {
    if (!event.target.closest('.patient-search-wrap')) closePatientResults();
});
document.getElementById('billForm').addEventListener('submit', event => {
    if (!patientIdInput.value) {
        event.preventDefault();
        patientSearch.setCustomValidity('Please search for and select a patient from the results.');
        patientSearch.reportValidity();
        patientSearch.focus();
    }
});
addItem();
</script>
</body>
</html>
