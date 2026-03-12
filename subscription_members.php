<?php
require_once 'auth.php';
$pageTitle = 'Subscriptions';

// AJAX: Generate unique User ID
if (isset($_GET['generate_id'])) {
    $prefix = 'S-';
    do {
        $rand = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        $newId = $prefix . $rand;
        $r = $conn->query("SELECT id FROM members WHERE member_id = '" . $conn->real_escape_string($newId) . "'");
    } while ($r && $r->num_rows > 0);
    header('Content-Type: application/json');
    echo json_encode(['id' => $newId]);
    exit;
}

$successMsg = $errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add new subscription member ──────────────────────────────────────────
    if ($action === 'add_subscription') {
        $name      = trim($_POST['name'] ?? '');
        $gender    = $_POST['gender'] ?? 'Male';
        $dob       = $_POST['dob'] ?? '';
        $phone     = trim($_POST['phone'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        $payment   = floatval($_POST['payment'] ?? 0);
        $startDate = $_POST['start_date'] ?? date('Y-m-d');
        $endDate   = $_POST['end_date'] ?? '';
        $memberId  = trim($_POST['member_id_input'] ?? '');

        if (!$memberId)                          { $errorMsg = 'Please enter a User ID.'; }
        elseif (!$name || !$payment || !$endDate){ $errorMsg = 'Please fill in all required fields.'; }
        else {
            $idCheck = $conn->query("SELECT id FROM members WHERE member_id = '" . $conn->real_escape_string($memberId) . "'");
            if ($idCheck && $idCheck->num_rows > 0) {
                $errorMsg = 'User ID already exists. Please choose a different ID.';
            } else {
                $age  = $dob ? (int)((strtotime(date('Y-m-d')) - strtotime($dob)) / (365.25 * 24 * 3600)) : 0;
                $stmt = $conn->prepare("INSERT INTO members (member_id, name, gender, date_of_birth, age, phone, address, member_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'subscription', 'active')");
                $stmt->bind_param("ssssiss", $memberId, $name, $gender, $dob, $age, $phone, $address);
                if ($stmt->execute()) {
                    $stmt->close();
                    $stmt2 = $conn->prepare("INSERT INTO subscriptions (member_id, payment_amount, start_date, end_date, status) VALUES (?,?,?,?,'active')");
                    $stmt2->bind_param("sdss", $memberId, $payment, $startDate, $endDate);
                    $stmt2->execute(); $stmt2->close();
                    $stmt3 = $conn->prepare("INSERT INTO payments (member_id, member_type, amount, payment_date) VALUES (?, 'subscription', ?, ?)");
                    $stmt3->bind_param("sds", $memberId, $payment, $startDate);
                    $stmt3->execute(); $stmt3->close();
                    $successMsg = "Member <strong>$name</strong> registered with ID: <strong>$memberId</strong>.";
                } else {
                    $stmt->close();
                    $errorMsg = 'Failed to register member.';
                }
            }
        }
    }

    // ── Renew subscription ───────────────────────────────────────────────────
    if ($action === 'renew_subscription') {
        $mid       = $conn->real_escape_string($_POST['member_id'] ?? '');
        $payment   = floatval($_POST['payment'] ?? 0);
        $startDate = $_POST['start_date'] ?? date('Y-m-d');
        $endDate   = $_POST['end_date'] ?? '';
        if ($mid && $payment && $startDate && $endDate) {
            // Check if current subscription is still active
            $activeSub = $conn->query("SELECT end_date FROM subscriptions WHERE member_id = '$mid' AND status = 'active' AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1")->fetch_assoc();
            if ($activeSub) {
                $daysLeft = (int)$conn->query("SELECT DATEDIFF('$activeSub[end_date]', CURDATE()) as d")->fetch_assoc()['d'];
                $endFormatted = date('M d, Y', strtotime($activeSub['end_date']));
                $errorMsg = "Cannot renew — this member's subscription is still active until <strong>$endFormatted</strong> ($daysLeft day" . ($daysLeft != 1 ? 's' : '') . " remaining). Renewal is only allowed after the subscription expires.";
            } else {
                // Mark all old subscriptions as expired
                $conn->query("UPDATE subscriptions SET status = 'expired' WHERE member_id = '$mid'");
                // Insert new active subscription
                $stmt = $conn->prepare("INSERT INTO subscriptions (member_id, payment_amount, start_date, end_date, status) VALUES (?,?,?,?,'active')");
                $stmt->bind_param("sdss", $mid, $payment, $startDate, $endDate);
                $stmt->execute(); $stmt->close();
                // Record payment
                $stmt2 = $conn->prepare("INSERT INTO payments (member_id, member_type, amount, payment_date) VALUES (?, 'subscription', ?, ?)");
                $stmt2->bind_param("sds", $mid, $payment, $startDate);
                $stmt2->execute(); $stmt2->close();
                // Reactivate member
                $conn->query("UPDATE members SET status = 'active' WHERE member_id = '$mid'");
                $successMsg = "Subscription renewed successfully.";
            }
        } else {
            $errorMsg = 'Please fill in all renewal fields.';
        }
    }

    // ── Freeze / Unfreeze ────────────────────────────────────────────────────
    if ($action === 'freeze_member') {
        $mid = $conn->real_escape_string($_POST['member_id'] ?? '');
        $existing = $conn->query("SELECT * FROM freeze_records WHERE member_id = '$mid' AND status = 'active'")->fetch_assoc();
        if ($existing) {
            $conn->query("UPDATE freeze_records SET status = 'ended', unfreeze_date = CURDATE() WHERE member_id = '$mid' AND status = 'active'");
            $conn->query("UPDATE members SET status = 'active' WHERE member_id = '$mid'");
            $conn->query("UPDATE subscriptions SET status = 'active' WHERE member_id = '$mid' AND status = 'frozen'");
            $successMsg = "Member unfrozen successfully.";
        } else {
            $conn->query("INSERT INTO freeze_records (member_id, freeze_date, status) VALUES ('$mid', CURDATE(), 'active')");
            $conn->query("UPDATE members SET status = 'frozen' WHERE member_id = '$mid'");
            $conn->query("UPDATE subscriptions SET status = 'frozen' WHERE member_id = '$mid' AND status = 'active'");
            $successMsg = "Member frozen successfully.";
        }
    }

    // ── Update member profile ────────────────────────────────────────────────
    if ($action === 'update_member') {
        $mid     = $_POST['member_id'] ?? '';
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gender  = $_POST['gender'] ?? 'Male';
        $dob     = $_POST['dob'] ?? '';
        if ($mid && $name) {
            $age  = $dob ? (int)((strtotime(date('Y-m-d')) - strtotime($dob)) / (365.25 * 24 * 3600)) : 0;
            $stmt = $conn->prepare("UPDATE members SET name=?, gender=?, date_of_birth=?, age=?, phone=?, address=? WHERE member_id=?");
            $stmt->bind_param("sssisss", $name, $gender, $dob, $age, $phone, $address, $mid);
            $stmt->execute(); $stmt->close();
            $successMsg = "Member updated successfully.";
        }
    }
}

// ── Auto-expire subscriptions ────────────────────────────────────────────────
$conn->query("UPDATE members m JOIN subscriptions s ON m.member_id = s.member_id
    SET s.status = 'expired', m.status = 'inactive'
    WHERE s.end_date < CURDATE() AND s.status = 'active'");
// Auto-unfreeze after 30 days
$conn->query("UPDATE members m JOIN freeze_records f ON m.member_id = f.member_id
    SET m.status = 'active', f.status = 'ended', f.unfreeze_date = CURDATE()
    WHERE f.status = 'active' AND DATEDIFF(CURDATE(), f.freeze_date) >= 30");

// ── Queries ──────────────────────────────────────────────────────────────────
$prices = $conn->query("SELECT * FROM price_settings WHERE type = 'subscription' AND is_active = 1 ORDER BY price ASC");

// ACTIVE members — join only the latest/active subscription per member
$activeMembers = $conn->query("
    SELECT m.*, s.payment_amount, s.start_date, s.end_date, s.status as sub_status,
        (SELECT freeze_date FROM freeze_records WHERE member_id = m.member_id AND status = 'active' LIMIT 1) as freeze_date
    FROM members m
    INNER JOIN subscriptions s ON s.id = (
        SELECT id FROM subscriptions WHERE member_id = m.member_id ORDER BY end_date DESC LIMIT 1
    )
    WHERE m.member_type = 'subscription'
        AND m.status IN ('active', 'frozen')
    ORDER BY m.name ASC
");

// EXPIRED members — join only the latest subscription per member
$expiredMembers = $conn->query("
    SELECT m.*, s.payment_amount, s.start_date, s.end_date, s.status as sub_status
    FROM members m
    INNER JOIN subscriptions s ON s.id = (
        SELECT id FROM subscriptions WHERE member_id = m.member_id ORDER BY end_date DESC LIMIT 1
    )
    WHERE m.member_type = 'subscription'
        AND m.status IN ('inactive', 'expired')
    ORDER BY s.end_date DESC
");

include 'header.php';
?>

<div class="page-header mb-4">
  <div>
    <h4 class="fw-800 mb-1" style="font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:800;">Subscriptions</h4>
    <p class="text-muted mb-0" style="font-size:13px;">Register and manage subscription memberships</p>
  </div>
  <button class="btn-primary-custom" data-bs-toggle="collapse" data-bs-target="#registerForm">
    <i class="fas fa-user-plus"></i> Register Member
  </button>
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

<!-- Register Form (collapsible) -->
<div class="collapse <?= $errorMsg ? 'show' : '' ?> mb-4" id="registerForm">
  <div class="section-card">
    <div class="section-card-header">
      <span class="section-card-title"><i class="fas fa-user-plus me-2" style="color:var(--primary)"></i>Register Subscription Member</span>

    </div>
    <div class="section-card-body">
      <form method="POST" action="">
        <input type="hidden" name="action" value="add_subscription">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">User ID <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="text" name="member_id_input" id="userIdInput" class="form-control" placeholder="Click Generate to create ID" required readonly style="background:#f8faff;font-weight:600;letter-spacing:1px;">
              <button type="button" class="btn btn-primary" onclick="generateUserId()" id="generateBtn" style="font-size:13px;padding:0 16px;border-radius:0 10px 10px 0;">
                <i class="fas fa-wand-magic-sparkles me-1"></i>Generate
              </button>
            </div>
            <div id="userIdStatus" style="font-size:12px;margin-top:4px;"></div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="Enter full name" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select">
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date of Birth</label>
            <input type="date" name="dob" class="form-control" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Phone Number</label>
            <input type="text" name="phone" class="form-control" placeholder="e.g. 09XX-XXX-XXXX">
          </div>
          <div class="col-12">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control" placeholder="Enter complete address">
          </div>
          <div class="col-md-4">
            <label class="form-label">Select Payment <span class="text-danger">*</span></label>
            <select name="payment" class="form-select" required id="paymentSelect">
              <option value="">-- Select payment --</option>
              <?php while($p = $prices->fetch_assoc()): ?>
              <option value="<?= $p['price'] ?>">₱<?= number_format($p['price'], 2) ?> – <?= htmlspecialchars($p['label']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Start Date <span class="text-danger">*</span></label>
            <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" id="startDate" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">End Date <span class="text-danger">*</span></label>
            <input type="date" name="end_date" class="form-control" id="endDate" required>
          </div>
          <div class="col-12">
            <button type="submit" class="btn-primary-custom"><i class="fas fa-user-plus"></i> Register Member</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── ACTIVE SUBSCRIPTIONS ──────────────────────────────────────────────── -->
<div class="section-card mb-4">
  <div class="section-card-header" style="background:rgba(16,185,129,0.04);">
    <span class="section-card-title"><i class="fas fa-id-card me-2" style="color:#10b981"></i>Active Subscriptions</span>
    <span class="badge-active" id="activeBadge"><?= $activeMembers->num_rows ?> member<?= $activeMembers->num_rows != 1 ? 's' : '' ?></span>
  </div>

  <!-- Search & Entries Toolbar -->
  <div style="padding:14px 18px;border-bottom:1px solid var(--border-color,#e8ecf4);background:#fafbff;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <label style="font-size:12px;font-weight:600;color:#6b7a99;white-space:nowrap;">Show</label>
      <select id="activeEntriesSelect" style="border:2px solid #e8ecf4;border-radius:8px;padding:5px 10px;font-size:13px;color:#0a1628;background:#fff;cursor:pointer;" onchange="renderActiveTable()">
        <option value="10">10</option>
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
        <option value="-1">All</option>
      </select>
      <label style="font-size:12px;font-weight:600;color:#6b7a99;white-space:nowrap;">entries</label>
    </div>
    <div style="position:relative;flex:1;max-width:320px;min-width:180px;">
      <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ba8be;font-size:13px;pointer-events:none;"></i>
      <input type="text" id="activeSearch" placeholder="Search by User ID or Name…"
        style="width:100%;border:2px solid #e8ecf4;border-radius:10px;padding:7px 12px 7px 32px;font-size:13px;color:#0a1628;background:#fff;transition:border-color .2s;outline:none;"
        oninput="renderActiveTable()" onfocus="this.style.borderColor='#10b981'" onblur="this.style.borderColor='#e8ecf4'">
      <button id="activeClearBtn" onclick="document.getElementById('activeSearch').value='';renderActiveTable();"
        style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ba8be;cursor:pointer;font-size:13px;padding:0;">
        <i class="fas fa-times-circle"></i>
      </button>
    </div>
  </div>

  <div class="section-card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0" id="activeTable">
        <thead>
          <tr>
            <th>User ID</th><th>Name</th><th>Gender</th><th>Age</th><th>Address</th><th>Phone No.</th><th>Payment</th><th>Expires</th><th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($activeMembers->num_rows > 0):
          while($row = $activeMembers->fetch_assoc()):
            $status = $row['status'];
          ?>
          <tr>
            <td><code style="background:#f0f4fb;padding:3px 8px;border-radius:6px;font-size:12px;"><?= htmlspecialchars($row['member_id']) ?></code></td>
            <td>
              <strong><?= htmlspecialchars($row['name']) ?></strong><br>
              <span class="badge-<?= $status ?>"><?= ucfirst($status) ?></span>
            </td>
            <td><?= htmlspecialchars($row['gender'] ?? '-') ?></td>
            <td><?= $row['age'] ?? '-' ?></td>
            <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($row['address'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
            <td>₱<?= number_format($row['payment_amount'] ?? 0, 2) ?></td>
            <td>
              <?php if ($row['end_date']): ?>
                <span style="font-size:12px;"><?= date('M d, Y', strtotime($row['end_date'])) ?></span>
                <?php if ($row['end_date'] <= date('Y-m-d', strtotime('+7 days'))): ?>
                <br><span class="badge-frozen" style="font-size:10px;">Expiring Soon</span>
                <?php endif; ?>
              <?php else: ?> - <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <button class="btn btn-sm btn-outline-primary rounded-pill" style="font-size:11px;padding:3px 10px;" onclick="openUpdateModal(<?= htmlspecialchars(json_encode($row)) ?>)">
                  <i class="fas fa-edit me-1"></i>Update
                </button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $status === 'frozen' ? 'Unfreeze' : 'Freeze' ?> this member?')">
                  <input type="hidden" name="action" value="freeze_member">
                  <input type="hidden" name="member_id" value="<?= $row['member_id'] ?>">
                  <button type="submit" class="btn btn-sm <?= $status === 'frozen' ? 'btn-warning' : 'btn-outline-warning' ?> rounded-pill" style="font-size:11px;padding:3px 10px;">
                    <i class="fas fa-snowflake me-1"></i><?= $status === 'frozen' ? 'Unfreeze' : 'Freeze' ?>
                  </button>
                </form>
                <button class="btn btn-sm btn-outline-success rounded-pill" style="font-size:11px;padding:3px 10px;" onclick="openRenewModal('<?= htmlspecialchars($row['member_id']) ?>', '<?= htmlspecialchars($row['name']) ?>')">
                  <i class="fas fa-rotate-right me-1"></i>Renew
                </button>
              </div>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="9" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>No active subscription members</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <!-- Info + Pagination row -->
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:12px 18px;border-top:1px solid var(--border-color,#e8ecf4);gap:10px;background:#fafbff;">
      <div id="activeInfo" style="font-size:12px;color:#6b7a99;"></div>
      <div id="activePagination" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
    </div>
  </div>
</div>

<!-- ── EXPIRED SUBSCRIPTION HISTORY ─────────────────────────────────────── -->
<div class="section-card">
  <div class="section-card-header" style="background:rgba(239,68,68,0.04);">
    <span class="section-card-title"><i class="fas fa-clock-rotate-left me-2" style="color:#ef4444"></i>Expired Subscription History</span>
    <span class="badge-expired" id="expiredBadge"><?= $expiredMembers->num_rows ?> record<?= $expiredMembers->num_rows != 1 ? 's' : '' ?></span>
  </div>

  <!-- Search & Entries Toolbar -->
  <div style="padding:14px 18px;border-bottom:1px solid var(--border-color,#e8ecf4);background:#fafbff;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <label style="font-size:12px;font-weight:600;color:#6b7a99;white-space:nowrap;">Show</label>
      <select id="expiredEntriesSelect" style="border:2px solid #e8ecf4;border-radius:8px;padding:5px 10px;font-size:13px;color:#0a1628;background:#fff;cursor:pointer;" onchange="renderExpiredTable()">
        <option value="10">10</option>
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
        <option value="-1">All</option>
      </select>
      <label style="font-size:12px;font-weight:600;color:#6b7a99;white-space:nowrap;">entries</label>
    </div>
    <div style="position:relative;flex:1;max-width:320px;min-width:180px;">
      <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ba8be;font-size:13px;pointer-events:none;"></i>
      <input type="text" id="expiredSearch" placeholder="Search by User ID or Name…"
        style="width:100%;border:2px solid #e8ecf4;border-radius:10px;padding:7px 12px 7px 32px;font-size:13px;color:#0a1628;background:#fff;transition:border-color .2s;outline:none;"
        oninput="renderExpiredTable()" onfocus="this.style.borderColor='#ef4444'" onblur="this.style.borderColor='#e8ecf4'">
      <button id="expiredClearBtn" onclick="document.getElementById('expiredSearch').value='';renderExpiredTable();"
        style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ba8be;cursor:pointer;font-size:13px;padding:0;">
        <i class="fas fa-times-circle"></i>
      </button>
    </div>
  </div>

  <div class="section-card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0" id="expiredTable">
        <thead>
          <tr>
            <th>User ID</th><th>Name</th><th>Gender</th><th>Age</th><th>Phone No.</th><th>Last Payment</th><th>Expired On</th><th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($expiredMembers->num_rows > 0):
          while($row = $expiredMembers->fetch_assoc()): ?>
          <tr>
            <td><code style="background:#fff0f0;padding:3px 8px;border-radius:6px;font-size:12px;"><?= htmlspecialchars($row['member_id']) ?></code></td>
            <td>
              <strong><?= htmlspecialchars($row['name']) ?></strong><br>
              <span class="badge-expired">Expired</span>
            </td>
            <td><?= htmlspecialchars($row['gender'] ?? '-') ?></td>
            <td><?= $row['age'] ?? '-' ?></td>
            <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
            <td>₱<?= number_format($row['payment_amount'] ?? 0, 2) ?></td>
            <td><span style="font-size:12px;color:#ef4444;"><?= $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : '-' ?></span></td>
            <td>
              <button class="btn btn-sm btn-success rounded-pill" style="font-size:11px;padding:3px 12px;" onclick="openRenewModal('<?= htmlspecialchars($row['member_id']) ?>', '<?= htmlspecialchars($row['name']) ?>')">
                <i class="fas fa-rotate-right me-1"></i>Renew
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>No expired subscriptions</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <!-- Info + Pagination row -->
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:12px 18px;border-top:1px solid var(--border-color,#e8ecf4);gap:10px;background:#fafbff;">
      <div id="expiredInfo" style="font-size:12px;color:#6b7a99;"></div>
      <div id="expiredPagination" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
    </div>
  </div>
</div>

<!-- Update Modal -->
<div class="modal fade" id="updateModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-edit me-2 text-primary"></i>Update Member Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="">
        <input type="hidden" name="action" value="update_member">
        <input type="hidden" name="member_id" id="updateMemberId">
        <div class="modal-body">
          <div class="alert" style="background:#fff8e6;border:1px solid #fde68a;border-radius:10px;font-size:13px;color:#92400e;padding:10px 14px;margin-bottom:16px;">
            <i class="fas fa-info-circle me-2"></i>Payment information cannot be modified from this section.
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="updateName" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Gender</label>
              <select name="gender" id="updateGender" class="form-select">
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date of Birth</label>
              <input type="date" name="dob" id="updateDob" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone Number</label>
              <input type="text" name="phone" id="updatePhone" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <input type="text" name="address" id="updateAddress" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-primary-custom"><i class="fas fa-save me-1"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Renew Modal -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-rotate-right me-2 text-success"></i>Renew Subscription</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="">
        <input type="hidden" name="action" value="renew_subscription">
        <input type="hidden" name="member_id" id="renewMemberId">
        <div class="modal-body">
          <div class="alert" style="background:#f0fff8;border:1px solid #a7f3d0;border-radius:10px;font-size:13px;color:#065f46;padding:10px 14px;margin-bottom:16px;">
            <i class="fas fa-user me-2"></i>Renewing subscription for: <strong id="renewMemberName"></strong>
          </div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Select Payment <span class="text-danger">*</span></label>
              <select name="payment" class="form-select" required>
                <option value="">-- Select payment --</option>
                <?php
                $renewPrices = $conn->query("SELECT * FROM price_settings WHERE type = 'subscription' AND is_active = 1 ORDER BY price ASC");
                while($rp = $renewPrices->fetch_assoc()):
                ?>
                <option value="<?= $rp['price'] ?>">₱<?= number_format($rp['price'], 2) ?> – <?= htmlspecialchars($rp['label']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Start Date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" id="renewStartDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">End Date <span class="text-danger">*</span></label>
              <input type="date" name="end_date" id="renewEndDate" class="form-control" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-success-custom"><i class="fas fa-rotate-right me-1"></i>Confirm Renewal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
/* ── Live-Search Table Engine ─────────────────────── */
.sub-table-row-hidden { display: none !important; }
.sub-highlight {
  background: #fffde6;
  color: #92400e;
  font-weight: 700;
  border-radius: 3px;
  padding: 0 2px;
}
.sub-pg-btn {
  min-width: 32px; height: 32px;
  border: 2px solid #e8ecf4;
  background: #fff;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  color: #344361;
  cursor: pointer;
  transition: all .15s;
  display: inline-flex; align-items: center; justify-content: center;
  padding: 0 8px;
}
.sub-pg-btn:hover:not(:disabled) { border-color: var(--primary, #10b981); color: var(--primary, #10b981); }
.sub-pg-btn.active {
  background: var(--primary, #10b981);
  border-color: var(--primary, #10b981);
  color: #fff;
}
.sub-pg-btn:disabled { opacity: .4; cursor: default; }
@media (max-width: 576px) {
  #activeSearch, #expiredSearch { font-size: 12px; }
}
</style>

<script>
/* ═══════════════════════════════════════════════════════════
   Subscription Members — Live Search + Pagination Engine
   ═══════════════════════════════════════════════════════════ */
(function () {
  /* ── helpers ───────────────────────────────────────── */
  function escapeRx(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  function highlight(text, term) {
    if (!term) return escapeHtml(text);
    var rx = new RegExp('(' + escapeRx(term) + ')', 'gi');
    return escapeHtml(text).replace(rx, '<mark class="sub-highlight">$1</mark>');
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ── snapshot original rows ────────────────────────── */
  function snapshotRows(tableId) {
    var tbody = document.querySelector('#' + tableId + ' tbody');
    if (!tbody) return [];
    return Array.from(tbody.rows).map(function (tr) {
      /* col 0 = User ID, col 1 = Name cell (may contain <strong> + badge) */
      var uid  = tr.cells[0] ? tr.cells[0].textContent.trim() : '';
      var name = tr.cells[1] ? tr.cells[1].textContent.trim() : '';
      return { tr: tr, uid: uid, name: name, origHtml: [
        tr.cells[0] ? tr.cells[0].innerHTML : '',
        tr.cells[1] ? tr.cells[1].innerHTML : ''
      ]};
    });
  }

  /* ── core renderer ─────────────────────────────────── */
  function buildRenderer(cfg) {
    /* cfg: { tableId, searchId, entriesId, infoId, paginationId,
              clearBtnId, badgeId, accentColor, colSpan } */
    var rows = [];
    var currentPage = 1;

    function getPerPage() {
      return parseInt(document.getElementById(cfg.entriesId).value, 10);
    }

    function getQuery() {
      return (document.getElementById(cfg.searchId).value || '').trim().toLowerCase();
    }

    function filtered() {
      var q = getQuery();
      if (!q) return rows;
      return rows.filter(function (r) {
        return r.uid.toLowerCase().includes(q) || r.name.toLowerCase().includes(q);
      });
    }

    function renderPagination(total, perPage) {
      var pg = document.getElementById(cfg.paginationId);
      pg.innerHTML = '';
      if (perPage === -1 || total === 0) return;
      var pages = Math.ceil(total / perPage);
      if (pages <= 1) return;

      function btn(label, page, disabled, active) {
        var b = document.createElement('button');
        b.className = 'sub-pg-btn' + (active ? ' active' : '');
        b.disabled = disabled;
        b.innerHTML = label;
        b.onclick = function () { currentPage = page; render(); };
        pg.appendChild(b);
      }

      btn('<i class="fas fa-chevron-left"></i>', currentPage - 1, currentPage === 1, false);
      var start = Math.max(1, currentPage - 2);
      var end   = Math.min(pages, start + 4);
      start = Math.max(1, end - 4);
      if (start > 1) { btn('1', 1, false, false); if (start > 2) { var sp=document.createElement('span'); sp.textContent='…'; sp.style='font-size:12px;color:#9ba8be;align-self:center;'; pg.appendChild(sp); } }
      for (var i = start; i <= end; i++) btn(i, i, false, i === currentPage);
      if (end < pages) { if (end < pages - 1) { var sp2=document.createElement('span'); sp2.textContent='…'; sp2.style='font-size:12px;color:#9ba8be;align-self:center;'; pg.appendChild(sp2); } btn(pages, pages, false, false); }
      btn('<i class="fas fa-chevron-right"></i>', currentPage + 1, currentPage === pages, false);
    }

    function render() {
      var q = getQuery();
      var perPage = getPerPage();
      var list = filtered();
      var total = list.length;

      /* clamp page */
      var maxPage = perPage === -1 ? 1 : Math.max(1, Math.ceil(total / perPage));
      if (currentPage > maxPage) currentPage = maxPage;

      var start = perPage === -1 ? 0 : (currentPage - 1) * perPage;
      var end   = perPage === -1 ? total : Math.min(start + perPage, total);

      /* show/hide all rows */
      rows.forEach(function (r) { r.tr.classList.add('sub-table-row-hidden'); });

      /* handle no-results */
      var tbody = document.querySelector('#' + cfg.tableId + ' tbody');
      var noResultRow = tbody.querySelector('.no-result-row');
      if (!noResultRow) {
        noResultRow = document.createElement('tr');
        noResultRow.className = 'no-result-row';
        noResultRow.innerHTML = '<td colspan="' + cfg.colSpan + '" class="text-center py-4" style="color:#9ba8be;font-size:13px;"><i class="fas fa-search me-2"></i>No matching users found</td>';
        tbody.appendChild(noResultRow);
      }

      if (total === 0) {
        noResultRow.style.display = '';
      } else {
        noResultRow.style.display = 'none';
        list.slice(start, end).forEach(function (r) {
          r.tr.classList.remove('sub-table-row-hidden');
          /* restore then highlight */
          if (r.tr.cells[0]) r.tr.cells[0].innerHTML = r.origHtml[0];
          if (r.tr.cells[1]) r.tr.cells[1].innerHTML = r.origHtml[1];
          if (q) {
            if (r.tr.cells[0]) {
              var codeEl = r.tr.cells[0].querySelector('code');
              if (codeEl) codeEl.innerHTML = highlight(r.uid, q);
            }
            if (r.tr.cells[1]) {
              var strongEl = r.tr.cells[1].querySelector('strong');
              if (strongEl) strongEl.innerHTML = highlight(r.name.replace(/\s+\(.*\)$/, ''), q);
            }
          }
        });
      }

      /* info text */
      var info = document.getElementById(cfg.infoId);
      if (total === 0) {
        info.textContent = q ? 'No results for "' + q + '"' : 'No entries';
      } else if (perPage === -1) {
        info.textContent = 'Showing all ' + total + ' entr' + (total === 1 ? 'y' : 'ies');
      } else {
        info.textContent = 'Showing ' + (start + 1) + ' to ' + end + ' of ' + total + ' entr' + (total === 1 ? 'y' : 'ies') + (q ? ' (filtered)' : '');
      }

      /* clear-btn visibility */
      var cb = document.getElementById(cfg.clearBtnId);
      if (cb) cb.style.display = q ? 'block' : 'none';

      renderPagination(total, perPage);
    }

    /* init */
    document.addEventListener('DOMContentLoaded', function () {
      rows = snapshotRows(cfg.tableId);
      render();
      document.getElementById(cfg.searchId).addEventListener('input', function () {
        currentPage = 1; render();
      });
      document.getElementById(cfg.entriesId).addEventListener('change', function () {
        currentPage = 1; render();
      });
    });

    return { render: render, reset: function () { currentPage = 1; render(); } };
  }

  /* ── init both tables ──────────────────────────────── */
  window._activeRenderer = buildRenderer({
    tableId: 'activeTable',
    searchId: 'activeSearch',
    entriesId: 'activeEntriesSelect',
    infoId: 'activeInfo',
    paginationId: 'activePagination',
    clearBtnId: 'activeClearBtn',
    badgeId: 'activeBadge',
    colSpan: 9
  });

  window._expiredRenderer = buildRenderer({
    tableId: 'expiredTable',
    searchId: 'expiredSearch',
    entriesId: 'expiredEntriesSelect',
    infoId: 'expiredInfo',
    paginationId: 'expiredPagination',
    clearBtnId: 'expiredClearBtn',
    badgeId: 'expiredBadge',
    colSpan: 8
  });

  /* proxy for external callers */
  window.renderActiveTable  = function () { if (window._activeRenderer)  window._activeRenderer.render(); };
  window.renderExpiredTable = function () { if (window._expiredRenderer) window._expiredRenderer.render(); };
})();

/* ── Modal helpers (unchanged) ─────────────────────── */
function openUpdateModal(data) {
  document.getElementById("updateMemberId").value = data.member_id;
  document.getElementById("updateName").value = data.name || "";
  document.getElementById("updateGender").value = data.gender || "Male";
  document.getElementById("updateDob").value = data.date_of_birth || "";
  document.getElementById("updatePhone").value = data.phone || "";
  document.getElementById("updateAddress").value = data.address || "";
  new bootstrap.Modal(document.getElementById("updateModal")).show();
}

function openRenewModal(memberId, memberName) {
  document.getElementById("renewMemberId").value = memberId;
  document.getElementById("renewMemberName").textContent = memberName;
  var today = new Date();
  var end = new Date(today);
  end.setMonth(end.getMonth() + 1);
  document.getElementById("renewStartDate").value = today.toISOString().split("T")[0];
  document.getElementById("renewEndDate").value = end.toISOString().split("T")[0];
  new bootstrap.Modal(document.getElementById("renewModal")).show();
}

function generateUserId() {
  var btn = document.getElementById("generateBtn");
  var statusEl = document.getElementById("userIdStatus");
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
  statusEl.innerHTML = '';
  fetch("subscription_members.php?generate_id=1")
    .then(function(r) { return r.json(); })
    .then(function(d) {
      document.getElementById("userIdInput").value = d.id;
      statusEl.innerHTML = '<span style="color:#10b981;"><i class="fas fa-check-circle me-1"></i>User ID <strong>' + d.id + '</strong> is ready to use.</span>';
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-rotate-right me-1"></i>Regenerate';
    })
    .catch(function() {
      statusEl.innerHTML = '<span style="color:#ef4444;">Failed to generate. Try again.</span>';
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i>Generate';
    });
}

/* ── Auto end-date on forms ──────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
  function updateEndDate() {
    var s = document.getElementById("startDate");
    var e = document.getElementById("endDate");
    if (s && e && s.value) {
      var d = new Date(s.value);
      d.setMonth(d.getMonth() + 1);
      e.value = d.toISOString().split("T")[0];
    }
  }
  var sd = document.getElementById("startDate");
  if (sd) { sd.addEventListener("change", updateEndDate); updateEndDate(); }

  var rs = document.getElementById("renewStartDate");
  if (rs) {
    rs.addEventListener("change", function () {
      var d = new Date(this.value);
      d.setMonth(d.getMonth() + 1);
      document.getElementById("renewEndDate").value = d.toISOString().split("T")[0];
    });
  }
});
</script>
<?php include 'footer.php'; ?>