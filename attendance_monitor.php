<?php
require_once 'auth.php';
$pageTitle = 'Attendance Monitoring';

// Fetch walk-in members available today
$walkinList = $conn->query("
    SELECT m.member_id, m.name
    FROM members m
    WHERE m.member_type = 'walkin' AND m.status = 'active' AND DATE(m.created_at) = CURDATE()
    ORDER BY m.name
");

// Fetch active subscription members
$subList = $conn->query("
    SELECT m.member_id, m.name, m.status
    FROM members m
    INNER JOIN subscriptions s ON s.id = (
        SELECT id FROM subscriptions WHERE member_id = m.member_id ORDER BY end_date DESC LIMIT 1
    )
    WHERE m.member_type = 'subscription'
      AND m.status IN ('active','frozen')
      AND s.status = 'active'
    ORDER BY m.name
");

// Already checked in today — to mark in dropdown
$checkedInToday = [];
$ciRes = $conn->query("SELECT member_id FROM attendance_logs WHERE DATE(time_in) = CURDATE()");
while ($r = $ciRes->fetch_assoc()) $checkedInToday[] = $r['member_id'];

// Initial data for first render
$walkinAttend = $conn->query("
    SELECT a.*, m.status as mem_status
    FROM attendance_logs a
    LEFT JOIN members m ON a.member_id = m.member_id
    WHERE a.member_type = 'walkin' AND DATE(a.time_in) = CURDATE()
    ORDER BY a.time_in DESC
");
$subAttend = $conn->query("
    SELECT a.*, m.status as mem_status
    FROM attendance_logs a
    LEFT JOIN members m ON a.member_id = m.member_id
    WHERE a.member_type = 'subscription' AND DATE(a.time_in) = CURDATE()
    ORDER BY a.time_in DESC
");

$totalToday = $walkinAttend->num_rows + $subAttend->num_rows;
include 'header.php';
?>

<style>
/* ── Toast Notifications ────────────────────────────────── */
#toastWrap {
  position: fixed; top: 20px; right: 20px; z-index: 9999;
  display: flex; flex-direction: column; gap: 10px; pointer-events: none;
}
.toast-item {
  padding: 13px 18px; border-radius: 12px; font-size: 13px; font-weight: 600;
  max-width: 360px; display: flex; align-items: center; gap: 10px;
  pointer-events: auto; box-shadow: 0 8px 24px rgba(0,0,0,0.12);
  animation: tIn .35s cubic-bezier(.34,1.56,.64,1) both;
}
.toast-success { background:#f0fff8; border:1px solid #a7f3d0; color:#065f46; }
.toast-error   { background:#fff0f0; border:1px solid #fca5a5; color:#991b1b; }
@keyframes tIn  { from{transform:translateX(40px);opacity:0} to{transform:translateX(0);opacity:1} }
@keyframes tOut { from{transform:translateX(0);opacity:1}   to{transform:translateX(40px);opacity:0} }

/* ── Live badge ─────────────────────────────────────────── */
.live-pill {
  display:inline-flex; align-items:center; gap:5px;
  background:rgba(16,185,129,.10); border:1px solid rgba(16,185,129,.30);
  color:#10b981; font-size:11px; font-weight:700;
  padding:3px 11px; border-radius:20px; letter-spacing:1px; text-transform:uppercase;
}
.live-dot {
  width:6px; height:6px; background:#10b981; border-radius:50%;
  animation: ldot 1.5s ease-in-out infinite;
}
@keyframes ldot { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Row highlight on new entry ─────────────────────────── */
@keyframes rowPop {
  0%   { background:rgba(30,120,255,.18); transform:scale(1.005); }
  100% { background:transparent; transform:scale(1); }
}
.row-new td { animation: rowPop 2s ease forwards; }

/* ── Display-screen link card ───────────────────────────── */
.display-card {
  display:flex; align-items:center; gap:14px;
  background:linear-gradient(135deg,rgba(30,120,255,.08),rgba(30,120,255,.03));
  border:1px solid rgba(30,120,255,.20); border-radius:14px;
  padding:13px 18px; margin-bottom:20px; cursor:pointer;
  transition:all .2s; text-decoration:none; color:inherit;
}
.display-card:hover {
  border-color:var(--primary); transform:translateY(-1px);
  box-shadow:0 4px 16px rgba(30,120,255,.15); color:inherit;
}
.display-card-icon {
  width:40px; height:40px; border-radius:10px;
  background:var(--primary); color:#fff;
  display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0;
}

/* ── Checked-in option styling ──────────────────────────── */
option.already-in { color: #aaa; }
</style>

<!-- Toast container -->
<div id="toastWrap"></div>

<!-- Page Header -->
<div class="page-header mb-4">
  <div>
    <h4 class="fw-800 mb-1" style="font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:800;">
      Attendance Monitoring
    </h4>
    <p class="text-muted mb-0" style="font-size:13px;">
      Real-time check-in — <?= date('l, F d, Y') ?>
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <span class="live-pill"><span class="live-dot"></span>Live</span>
    <div class="stat-card py-2 px-3" style="min-width:0;text-align:center;">
      <div class="card-label" style="font-size:11px;">Today's Check-ins</div>
      <div class="card-value" style="font-size:20px;" id="totalBadge"><?= $totalToday ?></div>
    </div>
  </div>
</div>

<!-- Display Screen Banner -->
<a href="attendance_display.php" target="_blank" class="display-card">
  <div class="display-card-icon"><i class="fas fa-display"></i></div>
  <div style="flex:1;">
    <div style="font-weight:700;font-size:14px;">Open Member Display Screen</div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
      Open on the front-desk PC. It shows live check-ins automatically.
    </div>
  </div>
  <i class="fas fa-external-link-alt" style="color:var(--text-muted);font-size:12px;flex-shrink:0;"></i>
</a>

<!-- ── Check-in Section ──────────────────────────────────── -->
<div class="section-card mb-4">
  <div class="section-card-header">
    <span class="section-card-title">
      <i class="fas fa-user-check me-2" style="color:var(--primary)"></i>Check-in Member
    </span>
    <span style="font-size:12px;color:var(--text-muted);">Select member type then member name</span>
  </div>
  <div class="section-card-body">

    <!-- Type Tabs -->
    <div class="d-flex gap-2 mb-3">
      <button class="btn-primary-custom" id="btnWalkin" onclick="showCheckin('walkin')">
        <i class="fas fa-person-walking"></i> Walk-in
      </button>
      <button class="btn-outline-custom" id="btnSub" onclick="showCheckin('subscription')"
              style="border-color:#8b5cf6;color:#8b5cf6;">
        <i class="fas fa-id-card"></i> Subscriber
      </button>
    </div>

    <!-- Walk-in Form -->
    <div id="formWalkin" style="display:none;">
      <div class="row g-3 align-items-end">
        <div class="col-md-6">
          <label class="form-label">Walk-in Member</label>
          <select id="selWalkin" class="form-select">
            <option value="">-- Select Walk-in Member --</option>
            <?php
            $walkinList->data_seek(0);
            while ($m = $walkinList->fetch_assoc()):
              $alreadyIn = in_array($m['member_id'], $checkedInToday);
            ?>
            <option value="<?= htmlspecialchars($m['member_id']) ?>"
              <?= $alreadyIn ? 'disabled class="already-in"' : '' ?>>
              <?= htmlspecialchars($m['member_id'] . ' – ' . $m['name']) ?>
              <?= $alreadyIn ? ' ✓ Checked In' : '' ?>
            </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-3">
          <button class="btn-primary-custom w-100" style="justify-content:center;"
                  id="btnCheckWalkin" onclick="doCheckin('walkin')">
            <i class="fas fa-sign-in-alt me-1"></i> Check In
          </button>
        </div>
      </div>
    </div>

    <!-- Subscriber Form -->
    <div id="formSub" style="display:none;">
      <div class="row g-3 align-items-end">
        <div class="col-md-6">
          <label class="form-label">Subscriber</label>
          <select id="selSub" class="form-select">
            <option value="">-- Select Subscriber --</option>
            <?php
            $subList->data_seek(0);
            while ($m = $subList->fetch_assoc()):
              $alreadyIn = in_array($m['member_id'], $checkedInToday);
            ?>
            <option value="<?= htmlspecialchars($m['member_id']) ?>"
              <?= $alreadyIn ? 'disabled class="already-in"' : '' ?>>
              <?= htmlspecialchars($m['member_id'] . ' – ' . $m['name']) ?>
              (<?= ucfirst($m['status']) ?>)
              <?= $alreadyIn ? ' ✓ Checked In' : '' ?>
            </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-3">
          <button class="btn-success-custom w-100" style="justify-content:center;"
                  id="btnCheckSub" onclick="doCheckin('subscription')">
            <i class="fas fa-sign-in-alt me-1"></i> Check In
          </button>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ── Attendance Tables ──────────────────────────────────── -->
<div class="row g-3">

  <!-- Walk-in Attendance -->
  <div class="col-12 col-lg-6">
    <div class="section-card">
      <div class="section-card-header" style="background:rgba(30,120,255,.04);">
        <span class="section-card-title">
          <i class="fas fa-person-walking me-2" style="color:var(--primary)"></i>Walk-in Attendance
        </span>
        <span class="badge-walkin" id="walkinCountBadge"><?= $walkinAttend->num_rows ?> today</span>
      </div>
      <div class="section-card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead>
              <tr>
                <th>User ID</th><th>Name</th><th>Time In</th><th>Status</th>
              </tr>
            </thead>
            <tbody id="walkinTbody">
              <?php if ($walkinAttend->num_rows > 0):
                while ($row = $walkinAttend->fetch_assoc()): ?>
              <tr data-id="<?= htmlspecialchars($row['member_id']) ?>">
                <td><code style="background:#f0f4fb;padding:3px 8px;border-radius:6px;font-size:11px;">
                  <?= htmlspecialchars($row['member_id']) ?></code></td>
                <td style="font-size:13px;font-weight:600;"><?= htmlspecialchars($row['member_name']) ?></td>
                <td style="font-size:12px;"><?= date('h:i A', strtotime($row['time_in'])) ?></td>
                <td><span class="badge-<?= $row['status'] ?? 'active' ?>">
                  <?= ucfirst($row['status'] ?? 'Active') ?></span></td>
              </tr>
              <?php endwhile; else: ?>
              <tr id="walkinEmpty">
                <td colspan="4" class="text-center text-muted py-4" style="font-size:13px;">
                  <i class="fas fa-inbox me-2"></i>No walk-in check-ins today
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Subscriber Attendance -->
  <div class="col-12 col-lg-6">
    <div class="section-card">
      <div class="section-card-header" style="background:rgba(139,92,246,.04);">
        <span class="section-card-title">
          <i class="fas fa-id-card me-2" style="color:#8b5cf6"></i>Subscriber Attendance
        </span>
        <span class="badge-subscription" id="subCountBadge"><?= $subAttend->num_rows ?> today</span>
      </div>
      <div class="section-card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead>
              <tr>
                <th>User ID</th><th>Name</th><th>Time In</th><th>Status</th>
              </tr>
            </thead>
            <tbody id="subTbody">
              <?php if ($subAttend->num_rows > 0):
                while ($row = $subAttend->fetch_assoc()): ?>
              <tr data-id="<?= htmlspecialchars($row['member_id']) ?>">
                <td><code style="background:#f0f4fb;padding:3px 8px;border-radius:6px;font-size:11px;">
                  <?= htmlspecialchars($row['member_id']) ?></code></td>
                <td style="font-size:13px;font-weight:600;"><?= htmlspecialchars($row['member_name']) ?></td>
                <td style="font-size:12px;"><?= date('h:i A', strtotime($row['time_in'])) ?></td>
                <td><span class="badge-<?= $row['status'] ?? 'active' ?>">
                  <?= ucfirst($row['status'] ?? 'Active') ?></span></td>
              </tr>
              <?php endwhile; else: ?>
              <tr id="subEmpty">
                <td colspan="4" class="text-center text-muted py-4" style="font-size:13px;">
                  <i class="fas fa-inbox me-2"></i>No subscriber check-ins today
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

</div>

<?php
$extraScripts = <<<'JS'
<script>
// ── Known IDs (to detect new entries from polling) ──────────
const knownIds = new Set();
document.querySelectorAll('#walkinTbody tr[data-id], #subTbody tr[data-id]').forEach(r => knownIds.add(r.dataset.id));

// ── Tab switcher ─────────────────────────────────────────────
function showCheckin(type) {
  document.getElementById('formWalkin').style.display   = type === 'walkin'       ? 'block' : 'none';
  document.getElementById('formSub').style.display      = type === 'subscription' ? 'block' : 'none';
  document.getElementById('btnWalkin').className = type === 'walkin'       ? 'btn-primary-custom' : 'btn-outline-custom';
  document.getElementById('btnSub').className    = type === 'subscription' ? 'btn-success-custom' : 'btn-outline-custom';
  if (type === 'walkin')       { document.getElementById('btnWalkin').style.cssText = ''; document.getElementById('btnSub').style.cssText = 'border-color:#8b5cf6;color:#8b5cf6;'; }
  if (type === 'subscription') { document.getElementById('btnSub').style.cssText = '';    document.getElementById('btnWalkin').style.cssText = ''; }
}

// ── Toast helper ─────────────────────────────────────────────
function toast(msg, type='success') {
  const wrap = document.getElementById('toastWrap');
  const el = document.createElement('div');
  el.className = `toast-item toast-${type}`;
  el.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.animation = 'tOut .35s ease forwards';
    setTimeout(() => el.remove(), 370);
  }, 3500);
}

// ── Escape HTML ───────────────────────────────────────────────
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Build a table row ─────────────────────────────────────────
function buildRow(entry, isNew) {
  const tr = document.createElement('tr');
  tr.dataset.id = entry.id;
  if (isNew) tr.className = 'row-new';
  tr.innerHTML = `
    <td><code style="background:#f0f4fb;padding:3px 8px;border-radius:6px;font-size:11px;">${esc(entry.id)}</code></td>
    <td style="font-size:13px;font-weight:600;">${esc(entry.name)}</td>
    <td style="font-size:12px;">${esc(entry.time)}</td>
    <td><span class="badge-${esc(entry.status || 'active')}">${esc((entry.status||'active').charAt(0).toUpperCase()+(entry.status||'active').slice(1))}</span></td>
  `;
  return tr;
}

// ── Mark option as checked-in in both dropdowns ───────────────
function markCheckedIn(memberId) {
  ['selWalkin','selSub'].forEach(selId => {
    const sel = document.getElementById(selId);
    Array.from(sel.options).forEach(opt => {
      if (opt.value === memberId) {
        opt.disabled = true;
        opt.className = 'already-in';
        if (!opt.text.includes('✓')) opt.text += ' ✓ Checked In';
      }
    });
  });
}

// ── AJAX Check-in ─────────────────────────────────────────────
function doCheckin(type) {
  const selId  = type === 'walkin' ? 'selWalkin' : 'selSub';
  const btnId  = type === 'walkin' ? 'btnCheckWalkin' : 'btnCheckSub';
  const sel    = document.getElementById(selId);
  const btn    = document.getElementById(btnId);
  const memberId = sel.value;

  if (!memberId) { toast('Please select a member first.', 'error'); return; }

  btn.classList.add('btn-loading');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Checking in…';

  const fd = new FormData();
  fd.append('member_id',   memberId);
  fd.append('member_type', type);

  fetch('attendance_api.php?action=checkin', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.classList.remove('btn-loading');
      btn.innerHTML = '<i class="fas fa-sign-in-alt me-1"></i> Check In';

      if (data.success) {
        toast(`<strong>${esc(data.entry.name)}</strong> checked in at ${esc(data.entry.time)} ✓`, 'success');
        sel.value = '';
        markCheckedIn(memberId);

        // Insert row into the correct table immediately (no wait for poll)
        const tbody  = type === 'walkin' ? document.getElementById('walkinTbody') : document.getElementById('subTbody');
        const emptyId = type === 'walkin' ? 'walkinEmpty' : 'subEmpty';
        const empty  = document.getElementById(emptyId);
        if (empty) empty.remove();

        if (!knownIds.has(data.entry.id)) {
          knownIds.add(data.entry.id);
          const row = buildRow(data.entry, true);
          tbody.insertBefore(row, tbody.firstChild);
          updateCounts();
        }
      } else {
        toast(data.message || 'Check-in failed.', 'error');
      }
    })
    .catch(() => {
      btn.classList.remove('btn-loading');
      btn.innerHTML = '<i class="fas fa-sign-in-alt me-1"></i> Check In';
      toast('Network error. Please try again.', 'error');
    });
}

// ── Update count badges & header total ───────────────────────
function updateCounts() {
  const wCount = document.querySelectorAll('#walkinTbody tr[data-id]').length;
  const sCount = document.querySelectorAll('#subTbody tr[data-id]').length;
  document.getElementById('walkinCountBadge').textContent = wCount + ' today';
  document.getElementById('subCountBadge').textContent    = sCount + ' today';
  document.getElementById('totalBadge').textContent       = wCount + sCount;
}

// ── Background polling (adds rows from other devices) ─────────
let lastTs = null;

async function pollAttendance() {
  try {
    const res  = await fetch('attendance_api.php?action=list&_=' + Date.now());
    const data = await res.json();
    if (!data.success) return;

    // Only process if something changed
    if (data.latest_ts === lastTs && lastTs !== null) return;
    lastTs = data.latest_ts;

    const allEntries = [...(data.walkins||[]), ...(data.subscribers||[])];
    let addedAny = false;

    allEntries.forEach(entry => {
      if (knownIds.has(entry.id)) return;
      knownIds.add(entry.id);
      addedAny = true;

      const isWalkin = entry.type === 'walkin';
      const tbody  = isWalkin ? document.getElementById('walkinTbody') : document.getElementById('subTbody');
      const emptyId = isWalkin ? 'walkinEmpty' : 'subEmpty';
      const empty  = document.getElementById(emptyId);
      if (empty) empty.remove();

      const row = buildRow(entry, true); // highlight as new
      tbody.insertBefore(row, tbody.firstChild);
      markCheckedIn(entry.id);
      toast(`<strong>${esc(entry.name)}</strong> checked in at ${esc(entry.time)} ✓`, 'success');
    });

    if (addedAny) updateCounts();

  } catch(e) { /* silent fail */ }
}

// Poll every 4 seconds
setInterval(pollAttendance, 4000);
</script>
JS;
include 'footer.php';
?>
