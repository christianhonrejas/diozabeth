<?php
require_once 'auth.php';
$pageTitle = 'Price Settings';

if ($_SESSION['admin_role'] !== 'superadmin') {
    header('Location: dashboard.php');
    exit;
}

$successMsg = $errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_price') {
        $type = $_POST['type'] ?? '';
        $price = floatval($_POST['price'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if (!in_array($type, ['walkin', 'subscription'])) {
            $errorMsg = 'Invalid price type.';
        } elseif ($price > 0 && $label) {
            $safeLabel = $conn->real_escape_string($label);
            $conn->query("INSERT INTO price_settings (type, price, label) VALUES ('$type', $price, '$safeLabel')");
            $successMsg = "Price option added.";
        } else { $errorMsg = 'Please fill all fields.'; }
    }
    
    if ($action === 'toggle_price') {
        $id = intval($_POST['price_id'] ?? 0);
        $conn->query("UPDATE price_settings SET is_active = NOT is_active WHERE id = $id");
        $successMsg = "Price option updated.";
    }
    
    if ($action === 'delete_price') {
        $id = intval($_POST['price_id'] ?? 0);
        $conn->query("DELETE FROM price_settings WHERE id = $id");
        $successMsg = "Price option deleted.";
    }
}

$prices = $conn->query("SELECT * FROM price_settings ORDER BY type, price ASC");
include 'header.php';
?>

<div class="page-header mb-4">
  <div>
    <h4 class="fw-800 mb-1" style="font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:800;">Price Settings</h4>
    <p class="text-muted mb-0" style="font-size:13px;">Configure walk-in and subscription pricing options</p>
  </div>
</div>

<?php if ($successMsg): ?>
<div class="alert" style="background:#f0fff8;border:1px solid #a7f3d0;border-radius:12px;padding:14px 18px;color:#065f46;font-size:14px;margin-bottom:20px;">
  <i class="fas fa-check-circle me-2"></i><?= $successMsg ?>
</div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div class="alert" style="background:#fff0f0;border:1px solid #fca5a5;border-radius:12px;padding:14px 18px;color:#991b1b;font-size:14px;margin-bottom:20px;">
  <i class="fas fa-exclamation-circle me-2"></i><?= $errorMsg ?>
</div>
<?php endif; ?>

<!-- Add Price Form -->
<div class="section-card mb-4">
  <div class="section-card-header">
    <span class="section-card-title"><i class="fas fa-plus-circle me-2" style="color:var(--primary)"></i>Add Price Option</span>
  </div>
  <div class="section-card-body">
    <form method="POST" action="">
      <input type="hidden" name="action" value="add_price">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select name="type" class="form-select">
            <option value="walkin">Walk-in</option>
            <option value="subscription">Subscription</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Price (₱)</label>
          <input type="number" name="price" class="form-control" placeholder="e.g. 150" min="1" step="0.01" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Label</label>
          <input type="text" name="label" class="form-control" placeholder="e.g. ₱150 - Premium" required>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn-primary-custom w-100" style="justify-content:center;"><i class="fas fa-plus"></i> Add</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Prices List -->
<div class="row g-3">
  <!-- Walk-in Prices -->
  <div class="col-12">
    <div class="section-card">
      <div class="section-card-header" style="background:rgba(30,120,255,0.04);">
        <span class="section-card-title"><i class="fas fa-person-walking me-2" style="color:var(--primary)"></i>Walk-in Prices</span>
      </div>
      <div class="section-card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead><tr>
              <th style="width:140px;">Price</th>
              <th>Label</th>
              <th style="width:120px;">Status</th>
              <th style="width:80px;">Action</th>
            </tr></thead>
            <tbody>
              <?php
              $prices->data_seek(0);
              $hasWalkin = false;
              while($p = $prices->fetch_assoc()):
                if ($p['type'] !== 'walkin') continue;
                $hasWalkin = true;
              ?>
              <tr>
                <td><strong style="color:var(--success);">₱<?= number_format($p['price'], 2) ?></strong></td>
                <td style="font-size:13px;"><?= htmlspecialchars($p['label']) ?></td>
                <td>
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_price">
                    <input type="hidden" name="price_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="badge-<?= $p['is_active'] ? 'active' : 'inactive' ?>" style="border:none;cursor:pointer;">
                      <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this price?')">
                    <input type="hidden" name="action" value="delete_price">
                    <input type="hidden" name="price_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" style="font-size:11px;"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
              <?php endwhile;
              if (!$hasWalkin): ?>
              <tr><td colspan="4" class="text-center text-muted py-3" style="font-size:13px;">No walk-in prices set</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Subscription Prices -->
  <div class="col-12">
    <div class="section-card">
      <div class="section-card-header" style="background:rgba(139,92,246,0.04);">
        <span class="section-card-title"><i class="fas fa-id-card me-2" style="color:#8b5cf6"></i>Subscription Prices</span>
      </div>
      <div class="section-card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead><tr>
              <th style="width:140px;">Price</th>
              <th>Label</th>
              <th style="width:120px;">Status</th>
              <th style="width:80px;">Action</th>
            </tr></thead>
            <tbody>
              <?php
              $prices->data_seek(0);
              $hasSub = false;
              while($p = $prices->fetch_assoc()):
                if ($p['type'] !== 'subscription') continue;
                $hasSub = true;
              ?>
              <tr>
                <td><strong style="color:#8b5cf6;">₱<?= number_format($p['price'], 2) ?></strong></td>
                <td style="font-size:13px;"><?= htmlspecialchars($p['label']) ?></td>
                <td>
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_price">
                    <input type="hidden" name="price_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="badge-<?= $p['is_active'] ? 'active' : 'inactive' ?>" style="border:none;cursor:pointer;">
                      <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this price?')">
                    <input type="hidden" name="action" value="delete_price">
                    <input type="hidden" name="price_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" style="font-size:11px;"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
              <?php endwhile;
              if (!$hasSub): ?>
              <tr><td colspan="4" class="text-center text-muted py-3" style="font-size:13px;">No subscription prices set</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>
