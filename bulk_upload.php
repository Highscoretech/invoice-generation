<?php
/**
 * Bulk invoice upload — accountants upload a CSV of invoice lines and the page
 * creates each invoice (grouped by invoice_ref) with its line items, optionally
 * running each straight through the FIRS pipeline (validate + sign).
 *
 * No spreadsheet library is bundled, so this uses native fgetcsv. Customers and
 * catalogue items referenced in the CSV are resolved within the accountant's
 * company; unknown ones are created so a first upload "just works".
 */
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/FirsService.php';
require_once 'includes/Crypto.php';

$auth = new Auth();
$auth->requireRole('accountant');
$conn = (new Database())->getConnection();

$companyId = (int) $_SESSION['company_id'];
$userId    = (int) $_SESSION['user_id'];

// ── CSV template download ────────────────────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bulk_invoice_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['invoice_ref','customer_name','customer_tax_id','invoice_date','due_date','invoice_type_code','item_code','item_name','hsn_code','quantity','rate','tax_rate','discount_amount']);
    fputcsv($out, ['INV-A','Acme Trading Limited','10214563-0001','2026-09-21','2026-10-21','381','LAP-14','Laptop computer','8471.30','2','5000','7.5','0']);
    fputcsv($out, ['INV-A','Acme Trading Limited','10214563-0001','2026-09-21','2026-10-21','381','PHN-01','Smartphone','8517.12','3','8000','7.5','0']);
    fputcsv($out, ['INV-B','Netswitch Limited','02331809-0001','2026-09-21','','381','SRV-1','Consulting service','8523.49','1','25000','7.5','0']);
    fclose($out);
    exit;
}

/** Find a customer by TIN or name within the company, creating it if absent. */
function resolveCustomer(PDO $conn, int $companyId, string $name, string $taxId): int {
    if ($taxId !== '') {
        $s = $conn->prepare("SELECT id FROM customers WHERE company_id = :co AND tax_id = :t LIMIT 1");
        $s->execute([':co' => $companyId, ':t' => $taxId]);
        if ($id = $s->fetchColumn()) return (int) $id;
    }
    $s = $conn->prepare("SELECT id FROM customers WHERE company_id = :co AND name = :n LIMIT 1");
    $s->execute([':co' => $companyId, ':n' => $name]);
    if ($id = $s->fetchColumn()) return (int) $id;
    $s = $conn->prepare("INSERT INTO customers (company_id, name, tax_id, status) VALUES (:co, :n, :t, 'active')");
    $s->execute([':co' => $companyId, ':n' => $name, ':t' => ($taxId !== '' ? $taxId : null)]);
    return (int) $conn->lastInsertId();
}

/** Find a catalogue item by code or name within the company, creating it if absent.
 *  Returns [item_id, item_code, rate] — existing items keep their catalogue price. */
function resolveItem(PDO $conn, int $companyId, string $code, string $name, string $hsn, float $rate, float $taxRate): array {
    if ($code !== '') {
        $s = $conn->prepare("SELECT id, item_code, selling_price FROM items WHERE company_id = :co AND item_code = :c AND status = 'active' LIMIT 1");
        $s->execute([':co' => $companyId, ':c' => $code]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) return ['item_id' => (int) $r['id'], 'item_code' => $r['item_code'], 'rate' => round((float) $r['selling_price'], 2)];
    }
    if ($name !== '') {
        $s = $conn->prepare("SELECT id, item_code, selling_price FROM items WHERE company_id = :co AND name = :n AND status = 'active' LIMIT 1");
        $s->execute([':co' => $companyId, ':n' => $name]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) return ['item_id' => (int) $r['id'], 'item_code' => ($r['item_code'] ?: $code), 'rate' => round((float) $r['selling_price'], 2)];
    }
    $newCode = $code !== '' ? $code : ('ITM-' . strtoupper(substr(md5($name . microtime()), 0, 6)));
    $s = $conn->prepare("INSERT INTO items (company_id, item_code, name, hsn_code, selling_price, tax_rate, status) VALUES (:co, :c, :n, :h, :p, :tr, 'active')");
    $s->execute([':co' => $companyId, ':c' => $newCode, ':n' => ($name !== '' ? $name : $newCode), ':h' => ($hsn !== '' ? $hsn : null), ':p' => $rate, ':tr' => $taxRate]);
    return ['item_id' => (int) $conn->lastInsertId(), 'item_code' => $newCode, 'rate' => round($rate, 2)];
}

$results = [];
$summary = null;
$error   = null;

if ($_POST && ($_POST['action'] ?? '') === 'bulk_upload') {
    try {
        $file = $_FILES['csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please choose a CSV file to upload.');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new Exception('Only .csv files are supported. Download the template for the exact format.');
        }
        $submitAfter = ($_POST['submit_firs'] ?? '') === '1';

        // Parse the CSV into associative rows keyed by the (lower-cased) header.
        $rows = [];
        if (($h = fopen($file['tmp_name'], 'r')) !== false) {
            $header = fgetcsv($h);
            if (!$header) throw new Exception('The CSV is empty.');
            $keys = array_map(fn($x) => strtolower(trim((string) $x)), $header);
            while (($data = fgetcsv($h)) !== false) {
                if (count(array_filter($data, fn($v) => trim((string) $v) !== '')) === 0) continue;
                $row = [];
                foreach ($keys as $i => $k) $row[$k] = trim((string) ($data[$i] ?? ''));
                $rows[] = $row;
            }
            fclose($h);
        }
        if (empty($rows)) throw new Exception('No data rows found in the CSV.');

        // Group line rows into invoices by invoice_ref (blank ref => its own invoice).
        $groups = [];
        $auto = 0;
        foreach ($rows as $row) {
            $ref = ($row['invoice_ref'] ?? '') !== '' ? $row['invoice_ref'] : ('ROW-' . (++$auto));
            $groups[$ref][] = $row;
        }

        $seq = 0;
        $created = 0; $submitted = 0; $failed = 0;
        $service = new FirsService($conn);

        foreach ($groups as $ref => $group) {
            $seq++;
            $first = $group[0];
            $custName = $first['customer_name'] ?? '';
            $res = ['ref' => $ref, 'invoice_number' => '', 'customer' => $custName, 'lines' => 0, 'total' => 0, 'ok' => false, 'status' => '', 'detail' => ''];
            try {
                if ($custName === '') throw new Exception('customer_name is required');

                // Build the line items (catalogue-authoritative pricing).
                $lineItems = []; $subtotal = 0.0;
                foreach ($group as $row) {
                    $qty = (float) ($row['quantity'] ?? 0);
                    if ($qty <= 0) continue;
                    $lineTax = ($row['tax_rate'] ?? '') !== '' ? (float) $row['tax_rate'] : 7.5;
                    $it = resolveItem($conn, $companyId, $row['item_code'] ?? '', $row['item_name'] ?? '', $row['hsn_code'] ?? '', (float) ($row['rate'] ?? 0), $lineTax);
                    $amount = round($it['rate'] * $qty, 2);
                    $subtotal += $amount;
                    $lineItems[] = ['item_id' => $it['item_id'], 'item_code' => $it['item_code'], 'quantity' => $qty, 'rate' => $it['rate'], 'amount' => $amount];
                }
                if (empty($lineItems)) throw new Exception('no valid item lines (need item + quantity > 0)');

                $subtotal = round($subtotal, 2);
                $discount = round(max(0.0, min((float) ($first['discount_amount'] ?? 0), $subtotal)), 2);
                $taxRate  = ($first['tax_rate'] ?? '') !== '' ? (float) $first['tax_rate'] : 7.5;
                if ($taxRate < 0 || $taxRate > 100) $taxRate = 7.5;
                $taxExcl  = round($subtotal - $discount, 2);
                $taxAmt   = round($taxExcl * $taxRate / 100, 2);
                $taxIncl  = round($taxExcl + $taxAmt, 2);
                $total    = $taxIncl;

                $invDate  = ($first['invoice_date'] ?? '') !== '' ? date('Y-m-d', strtotime($first['invoice_date'])) : date('Y-m-d');
                $dueDate  = ($first['due_date'] ?? '') !== '' ? date('Y-m-d', strtotime($first['due_date'])) : null;
                $typeCode = ($first['invoice_type_code'] ?? '') !== '' ? $first['invoice_type_code'] : '381';

                $conn->beginTransaction();
                $customerId = resolveCustomer($conn, $companyId, $custName, $first['customer_tax_id'] ?? '');
                $invoiceNumber = 'INV-' . date('Ymd-His') . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

                $stmt = $conn->prepare(
                    "INSERT INTO invoices (invoice_number, date, time, customer_id, company_id, user_id,
                      due_date, subtotal, line_extension_amount, tax_rate, tax_category_id, tax_amount,
                      discount_amount, discount_rate, allowance_total_amount, allowance_charge_reason, charge_total_amount,
                      tax_exclusive_amount, tax_inclusive_amount, total_amount, payable_amount, qr_url, status,
                      invoice_type_code, payment_status, document_currency_code, tax_currency_code, tax_point_date, notes)
                     VALUES (:invoice_number, :date, :time, :customer_id, :company_id, :user_id,
                      :due_date, :subtotal, :line_extension_amount, :tax_rate, :tax_category_id, :tax_amount,
                      :discount_amount, :discount_rate, :allowance_total_amount, :allowance_charge_reason, :charge_total_amount,
                      :tax_exclusive_amount, :tax_inclusive_amount, :total_amount, :payable_amount, :qr_url, :status,
                      :invoice_type_code, :payment_status, :document_currency_code, :tax_currency_code, :tax_point_date, :notes)"
                );
                $stmt->execute([
                    'invoice_number' => $invoiceNumber,
                    'date' => $invDate,
                    'time' => date('H:i:s'),
                    'customer_id' => $customerId,
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'due_date' => $dueDate,
                    'subtotal' => $subtotal,
                    'line_extension_amount' => $subtotal,
                    'tax_rate' => $taxRate,
                    'tax_category_id' => 'STANDARD_VAT',
                    'tax_amount' => $taxAmt,
                    'discount_amount' => $discount,
                    'discount_rate' => ($subtotal > 0) ? round(($discount / $subtotal) * 100, 2) : 0,
                    'allowance_total_amount' => $discount,
                    'allowance_charge_reason' => $discount > 0 ? 'Discount' : null,
                    'charge_total_amount' => 0,
                    'tax_exclusive_amount' => $taxExcl,
                    'tax_inclusive_amount' => $taxIncl,
                    'total_amount' => $total,
                    'payable_amount' => $total,
                    'qr_url' => '',
                    'status' => 'draft',
                    'invoice_type_code' => $typeCode,
                    'payment_status' => 'PENDING',
                    'document_currency_code' => 'NGN',
                    'tax_currency_code' => 'NGN',
                    'tax_point_date' => null,
                    'notes' => null,
                ]);
                $invoiceId = (int) $conn->lastInsertId();
                $ins = $conn->prepare("INSERT INTO invoice_items (invoice_id, item_id, item_code, quantity, rate, amount) VALUES (:invoice_id, :item_id, :item_code, :quantity, :rate, :amount)");
                foreach ($lineItems as $li) {
                    $ins->execute([
                        'invoice_id' => $invoiceId, 'item_id' => $li['item_id'], 'item_code' => $li['item_code'],
                        'quantity' => $li['quantity'], 'rate' => $li['rate'], 'amount' => $li['amount'],
                    ]);
                }
                $conn->commit();

                $created++;
                $res['invoice_number'] = $invoiceNumber;
                $res['lines'] = count($lineItems);
                $res['total'] = $total;
                $res['ok'] = true;
                $res['status'] = 'Draft';

                if ($submitAfter) {
                    $sr = $service->submit($invoiceId);
                    if ($sr['ok']) {
                        $submitted++;
                        $res['status'] = ucfirst($sr['status']); // signed
                        $res['detail'] = $sr['irn'];
                    } else {
                        $res['status'] = 'Saved · FIRS ' . $sr['status'];
                        $res['detail'] = $sr['message'];
                    }
                }
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $failed++;
                $res['ok'] = false;
                $res['status'] = 'Error';
                $res['detail'] = $e->getMessage();
            }
            $results[] = $res;
        }
        $summary = ['created' => $created, 'submitted' => $submitted, 'failed' => $failed, 'invoices' => count($groups)];
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'Bulk Upload';
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h2 class="mb-1"><i class="fas fa-file-upload text-success me-2"></i>Bulk Invoice Upload</h2>
    <p class="text-muted mb-0">Upload a CSV to create many invoices at once — optionally signing each with FIRS.</p>
  </div>
  <a class="btn btn-outline-secondary" href="bulk_upload.php?template=1"><i class="fas fa-download me-1"></i> Download CSV template</a>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($summary): ?>
  <div class="alert alert-success">
    <i class="fas fa-check-circle me-2"></i>
    Processed <b><?php echo $summary['invoices']; ?></b> invoice(s): <b><?php echo $summary['created']; ?></b> created<?php
      if ($summary['submitted']) echo ', <b>' . $summary['submitted'] . '</b> signed with FIRS';
      if ($summary['failed']) echo ', <b>' . $summary['failed'] . '</b> failed';
    ?>.
  </div>
<?php endif; ?>

<div class="row">
  <div class="col-lg-5 mb-4">
    <div class="card">
      <div class="card-header"><b>Upload CSV</b></div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="bulk_upload">
          <div class="mb-3">
            <label class="form-label">CSV file</label>
            <input type="file" name="csv" accept=".csv" class="form-control" required>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="submit_firs" value="1" id="submit_firs">
            <label class="form-check-label" for="submit_firs">Submit each invoice to FIRS after upload (validate &amp; sign)</label>
          </div>
          <button type="submit" class="btn btn-success"><i class="fas fa-upload me-1"></i> Upload &amp; Process</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7 mb-4">
    <div class="card">
      <div class="card-header"><b>CSV format</b></div>
      <div class="card-body">
        <p class="mb-2">One row per <b>invoice line</b>. Rows that share the same <code>invoice_ref</code> become one invoice with multiple lines.</p>
        <ul class="mb-2 small">
          <li><code>invoice_ref</code> — groups lines into one invoice (any label; blank = its own invoice)</li>
          <li><code>customer_name</code> <span class="text-danger">*</span>, <code>customer_tax_id</code> — customer is matched by TIN/name, or created</li>
          <li><code>invoice_date</code> (default today), <code>due_date</code>, <code>invoice_type_code</code> (default 381)</li>
          <li><code>item_code</code>, <code>item_name</code>, <code>hsn_code</code>, <code>quantity</code> <span class="text-danger">*</span>, <code>rate</code> — item is matched by code/name, or created</li>
          <li><code>tax_rate</code> (default 7.5), <code>discount_amount</code></li>
        </ul>
        <p class="small text-muted mb-0">If an <code>item_code</code> already exists, its catalogue price is used. Invoices are saved as drafts unless you tick the FIRS option.</p>
      </div>
    </div>
  </div>
</div>

<?php if ($results): ?>
  <div class="card">
    <div class="card-header"><b>Results</b></div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0 align-middle">
        <thead><tr><th class="ps-3">Ref</th><th>Invoice #</th><th>Customer</th><th class="text-end">Lines</th><th class="text-end">Total (₦)</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td class="ps-3"><?php echo htmlspecialchars($r['ref']); ?></td>
            <td><?php echo htmlspecialchars($r['invoice_number'] ?: '—'); ?></td>
            <td><?php echo htmlspecialchars($r['customer']); ?></td>
            <td class="text-end"><?php echo $r['lines']; ?></td>
            <td class="text-end"><?php echo number_format((float) $r['total'], 2); ?></td>
            <td>
              <?php if (!$r['ok']): ?><span class="badge bg-danger"><?php echo htmlspecialchars($r['status']); ?></span>
              <?php elseif (stripos($r['status'], 'signed') !== false): ?><span class="badge bg-success"><?php echo htmlspecialchars($r['status']); ?></span>
              <?php elseif (stripos($r['status'], 'FIRS') !== false): ?><span class="badge bg-warning text-dark"><?php echo htmlspecialchars($r['status']); ?></span>
              <?php else: ?><span class="badge bg-secondary"><?php echo htmlspecialchars($r['status']); ?></span><?php endif; ?>
            </td>
            <td class="small text-muted"><?php echo htmlspecialchars($r['detail']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
