<?php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_staff();
$role = $_SESSION['staff_role'] ?? '';
if (!in_array($role, ['Admin', 'Reception'], true)) { http_response_code(403); exit('Access denied.'); }

$conn->query("CREATE TABLE IF NOT EXISTS clinic_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'Medicine',
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clinic_item_name_category (name, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function med_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$categories = ['Medicine','Therapy','Lab','Consultation','Miscellaneous'];
if (empty($_SESSION['medicine_csrf'])) $_SESSION['medicine_csrf'] = bin2hex(random_bytes(32));
$notice = $_SESSION['medicine_notice'] ?? null; unset($_SESSION['medicine_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['medicine_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $_SESSION['medicine_notice'] = ['type'=>'error','text'=>'Security token expired. Please try again.'];
        header('Location: manage_medicines.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $category = (string)($_POST['category'] ?? 'Medicine');
        $price = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($name === '' || mb_strlen($name) > 190 || !in_array($category, $categories, true) || $price === false || $price < 0) {
            $_SESSION['medicine_notice'] = ['type'=>'error','text'=>'Enter a medicine/service name, valid category and non-negative price.'];
        } else {
            $stmt = $conn->prepare('INSERT INTO clinic_items (name, category, unit_price, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE unit_price=VALUES(unit_price), is_active=1');
            if ($stmt) {
                $stmt->bind_param('ssd', $name, $category, $price);
                if ($stmt->execute()) {
                    $_SESSION['medicine_notice'] = ['type'=>'success','text'=>'Saved to the medicine catalogue. You can now search it in Billing.'];
                } else {
                    $_SESSION['medicine_notice'] = ['type'=>'error','text'=>'Could not save this item. The same name/category may already exist.'];
                }
                $stmt->close();
            } else {
                $_SESSION['medicine_notice'] = ['type'=>'error','text'=>'Could not prepare database query.'];
            }
        }

    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $category = (string)($_POST['category'] ?? 'Medicine');
        $price = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($id <= 0 || $name === '' || mb_strlen($name) > 190 || !in_array($category, $categories, true) || $price === false || $price < 0) {
            $_SESSION['medicine_notice'] = ['type'=>'error','text'=>'Please enter valid medicine/service details.'];
        } else {
            $stmt = $conn->prepare('UPDATE clinic_items SET name=?, category=?, unit_price=? WHERE id=?');

            if ($stmt) {
                $stmt->bind_param('ssdi', $name, $category, $price, $id);

                if ($stmt->execute()) {
                    $_SESSION['medicine_notice'] = [
                        'type'=>'success',
                        'text'=>'Medicine/service details updated successfully.'
                    ];
                } else {
                    $_SESSION['medicine_notice'] = [
                        'type'=>'error',
                        'text'=>'Could not update this item. The same name/category may already exist.'
                    ];
                }
                $stmt->close();
            } else {
                $_SESSION['medicine_notice'] = ['type'=>'error','text'=>'Could not prepare the edit query.'];
            }
        }

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare('UPDATE clinic_items SET is_active = IF(is_active=1,0,1) WHERE id=?');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }

        $_SESSION['medicine_notice'] = [
            'type'=>'success',
            'text'=>'Catalogue item status updated.'
        ];

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['medicine_notice'] = ['type'=>'error','text'=>'Invalid catalogue item.'];
        } else {
            $stmt = $conn->prepare('DELETE FROM clinic_items WHERE id=?');

            if ($stmt) {
                $stmt->bind_param('i', $id);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $_SESSION['medicine_notice'] = [
                        'type'=>'success',
                        'text'=>'Catalogue item permanently removed.'
                    ];
                } else {
                    $_SESSION['medicine_notice'] = [
                        'type'=>'error',
                        'text'=>'Catalogue item was not found or could not be removed.'
                    ];
                }

                $stmt->close();
            } else {
                $_SESSION['medicine_notice'] = ['type'=>'error','text'=>'Could not prepare the delete query.'];
            }
        }
    }

    header('Location: manage_medicines.php'); exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$items = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare('SELECT id,name,category,unit_price,is_active FROM clinic_items WHERE name LIKE ? ORDER BY is_active DESC, name ASC LIMIT 300');
    $stmt->bind_param('s', $like); $stmt->execute(); $res = $stmt->get_result();
} else {
    $res = $conn->query('SELECT id,name,category,unit_price,is_active FROM clinic_items ORDER BY is_active DESC, name ASC LIMIT 300');
}
if ($res) { while ($row = $res->fetch_assoc()) $items[] = $row; $res->free(); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Medicine Catalogue | AmarDayal</title>
<style>
:root{
    --blue:#2454dc;
    --bg:#f3f6fb;
    --text:#12233f;
    --muted:#64748b;
    --border:#dce5f0;
}

*{box-sizing:border-box}

body{
    margin:0;
    background:var(--bg);
    font-family:Inter,"Segoe UI",Arial,sans-serif;
    color:var(--text);
}

.content{
    margin-left:270px;
    min-height:100vh;
    padding:32px;
    max-width:calc(100% - 270px);
}

.page-head{
    display:flex;
    justify-content:space-between;
    gap:18px;
    align-items:start;
    margin-bottom:24px;
}

.page-head h1{
    margin:0 0 8px;
    font-size:30px;
}

.page-head p{
    margin:0;
    color:var(--muted);
}

.card{
    background:#fff;
    border:1px solid #e3e9f1;
    border-radius:20px;
    padding:24px;
    margin-bottom:22px;
    box-shadow:0 8px 24px rgba(15,23,42,.04);
}

.card h2{
    margin:0 0 18px;
    font-size:19px;
}

.form-grid{
    display:grid;
    grid-template-columns:minmax(220px,2fr) minmax(160px,1fr) minmax(130px,.8fr) auto;
    gap:14px;
    align-items:end;
}

label{
    display:block;
    font-size:12px;
    font-weight:800;
    color:#475569;
    margin-bottom:7px;
}

input,select{
    width:100%;
    height:46px;
    border:1px solid #cfdbea;
    border-radius:11px;
    padding:0 13px;
    background:#fff;
    font:inherit;
    color:var(--text);
    outline:none;
}

input:focus,select:focus{
    border-color:#3972ff;
    box-shadow:0 0 0 3px #e8efff;
}

.btn{
    height:46px;
    border:0;
    border-radius:11px;
    padding:0 18px;
    background:var(--blue);
    color:#fff;
    font-weight:800;
    cursor:pointer;
}

.btn:hover{
    filter:brightness(.96);
}

.alert{
    padding:13px 16px;
    border-radius:12px;
    margin-bottom:18px;
    font-weight:650;
}

.success{
    background:#ecfdf3;
    color:#167044;
    border:1px solid #bbf7d0;
}

.error{
    background:#fff1f2;
    color:#b42318;
    border:1px solid #fecdd3;
}

.table-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    flex-wrap:wrap;
}

.search-form{
    display:flex;
    gap:8px;
    min-width:min(100%,360px);
}

.search-form input{
    min-width:0;
}

.table-wrap{
    overflow:auto;
}

table{
    border-collapse:collapse;
    width:100%;
    min-width:820px;
}

th,td{
    text-align:left;
    padding:14px 12px;
    border-bottom:1px solid #edf1f6;
    font-size:14px;
}

th{
    color:#64748b;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.04em;
}

td strong{
    font-weight:750;
}

.badge{
    padding:5px 9px;
    border-radius:99px;
    background:#dcfce7;
    color:#166534;
    font-size:12px;
    font-weight:800;
}

.badge.off{
    background:#f1f5f9;
    color:#64748b;
}

.small-btn{
    border:1px solid var(--border);
    border-radius:9px;
    background:#fff;
    padding:8px 11px;
    font-weight:750;
    cursor:pointer;
    color:#334155;
}

.small-btn:hover{
    background:#f8fafc;
}

.action-buttons{
    display:flex;
    align-items:center;
    gap:7px;
    flex-wrap:wrap;
}

.edit-btn{
    background:#eff6ff;
    color:#1d4ed8;
    border-color:#bfdbfe;
}

.delete-btn{
    background:#fff1f2;
    color:#be123c;
    border-color:#fecdd3;
}

.delete-btn:hover{
    background:#ffe4e6;
}

.edit-row td{
    background:#f8fbff;
}

.edit-form{
    display:grid;
    grid-template-columns:minmax(220px,2fr) minmax(150px,1fr) minmax(120px,.7fr) auto auto;
    gap:10px;
    align-items:end;
}

.edit-form .field label{
    margin-bottom:5px;
}

.edit-form input,
.edit-form select{
    height:40px;
}

.edit-form .small-btn{
    height:40px;
    white-space:nowrap;
}

.empty{
    text-align:center;
    padding:28px;
    color:var(--muted);
}

@media(max-width:900px){
    .content{
        margin-left:78px;
        max-width:calc(100% - 78px);
        padding:18px;
    }

    .form-grid{
        grid-template-columns:1fr 1fr;
    }

    .page-head h1{
        font-size:25px;
    }
}

@media(max-width:520px){
    .content{
        margin-left:66px;
        max-width:calc(100% - 66px);
        padding:14px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .card{
        padding:17px;
    }

    .page-head{
        display:block;
    }
}
</style></head><body>

<?php
/*
|--------------------------------------------------------------------------
| Use the existing shared admin sidebar.
|--------------------------------------------------------------------------
| Do not create another sidebar here. sidebar.php automatically highlights
| the current page and displays the logged-in staff account.
*/
include __DIR__ . '/sidebar.php';
?>

<main class="content"><header class="page-head"><div><h1>Medicine Catalogue</h1><p>Add medicines and services once, then search them instantly while creating an invoice.</p></div><strong><?= date('d M Y') ?></strong></header>
<?php if($notice):?><div class="alert <?= med_e($notice['type']) ?>"><?= med_e($notice['text']) ?></div><?php endif;?>
<section class="card"><h2>＋ Add Medicine / Service</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= med_e($_SESSION['medicine_csrf']) ?>"><input type="hidden" name="action" value="add"><div><label for="name">Medicine / Service Name</label><input id="name" name="name" maxlength="190" placeholder="e.g. Paracetamol 500 mg" required></div><div><label for="category">Category</label><select id="category" name="category"><?php foreach($categories as $cat):?><option value="<?= med_e($cat) ?>" <?= $cat==='Medicine'?'selected':'' ?>><?= med_e($cat) ?></option><?php endforeach;?></select></div><div><label for="unit_price">Unit Price (₹)</label><input id="unit_price" name="unit_price" type="number" min="0" step="0.01" placeholder="0.00" required></div><button class="btn" type="submit">Save to Catalogue</button></form></section>
<section class="card"><div class="table-top"><h2>Saved Catalogue Items</h2><form class="search-form" method="get"><input name="q" value="<?= med_e($search) ?>" placeholder="Search medicine name..."><button class="btn" type="submit">Search</button></form></div><div class="table-wrap"><table><thead><tr><th>Medicine / Service</th><th>Category</th><th>Unit Price</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($items as $item):?>
<tr>
    <td><strong><?= med_e($item['name']) ?></strong></td>
    <td><?= med_e($item['category']) ?></td>
    <td>₹<?= number_format((float)$item['unit_price'],2) ?></td>
    <td>
        <span class="badge <?= $item['is_active']?'':'off' ?>">
            <?= $item['is_active']?'Active':'Inactive' ?>
        </span>
    </td>
    <td>
        <div class="action-buttons">
            <button type="button"
                    class="small-btn edit-btn"
                    onclick="toggleEdit(<?= (int)$item['id'] ?>)">
                ✏️ Edit
            </button>

            <form method="post"
                  onsubmit="return confirm('Permanently remove this medicine/service? This cannot be undone.');">
                <input type="hidden" name="csrf" value="<?= med_e($_SESSION['medicine_csrf']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                <button class="small-btn delete-btn" type="submit">🗑️ Remove</button>
            </form>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= med_e($_SESSION['medicine_csrf']) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                <button class="small-btn" type="submit">
                    <?= $item['is_active']?'Deactivate':'Activate' ?>
                </button>
            </form>
        </div>
    </td>
</tr>

<tr id="edit-row-<?= (int)$item['id'] ?>" class="edit-row" style="display:none;">
    <td colspan="5">
        <form method="post" class="edit-form">
            <input type="hidden" name="csrf" value="<?= med_e($_SESSION['medicine_csrf']) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

            <div class="field">
                <label for="edit-name-<?= (int)$item['id'] ?>">Medicine / Service Name</label>
                <input id="edit-name-<?= (int)$item['id'] ?>"
                       name="name"
                       maxlength="190"
                       value="<?= med_e($item['name']) ?>"
                       required>
            </div>

            <div class="field">
                <label for="edit-category-<?= (int)$item['id'] ?>">Category</label>
                <select id="edit-category-<?= (int)$item['id'] ?>" name="category" required>
                    <?php foreach($categories as $cat):?>
                        <option value="<?= med_e($cat) ?>"
                            <?= $cat === $item['category'] ? 'selected' : '' ?>>
                            <?= med_e($cat) ?>
                        </option>
                    <?php endforeach;?>
                </select>
            </div>

            <div class="field">
                <label for="edit-price-<?= (int)$item['id'] ?>">Unit Price (₹)</label>
                <input id="edit-price-<?= (int)$item['id'] ?>"
                       name="unit_price"
                       type="number"
                       min="0"
                       step="0.01"
                       value="<?= med_e($item['unit_price']) ?>"
                       required>
            </div>

            <button class="small-btn edit-btn" type="submit">💾 Save</button>

            <button class="small-btn"
                    type="button"
                    onclick="toggleEdit(<?= (int)$item['id'] ?>)">
                Cancel
            </button>
        </form>
    </td>
</tr>
<?php endforeach;?>

<?php if(!$items):?>
<tr>
    <td class="empty" colspan="5">
        No catalogue items found. Add your first medicine above.
    </td>
</tr>
<?php endif;?>
</tbody></table></div></section>
</main>

<script>
function toggleEdit(id) {
    const row = document.getElementById('edit-row-' + id);
    if (!row) return;

    const show = row.style.display === 'none' || row.style.display === '';
    row.style.display = show ? 'table-row' : 'none';

    if (show) {
        const input = document.getElementById('edit-name-' + id);
        if (input) {
            input.focus();
            input.select();
        }
    }
}
</script>

</body>
</html>
