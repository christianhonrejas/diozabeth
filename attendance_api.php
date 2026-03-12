<?php
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/database.php';

$action = $_GET['action'] ?? 'list';

// ── AJAX Check-in ─────────────────────────────────────────────────────────
if ($action === 'checkin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
        exit;
    }

    $memberId   = trim($_POST['member_id']   ?? '');
    $memberType = trim($_POST['member_type'] ?? '');

    if (!$memberId || !$memberType) {
        echo json_encode(['success' => false, 'message' => 'Please select a member.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM members WHERE member_id = ?");
    $stmt->bind_param("s", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }
    if ($member['status'] === 'frozen') {
        echo json_encode(['success' => false, 'message' => 'This member is currently frozen and cannot check in.']);
        exit;
    }
    if (in_array($member['status'], ['inactive', 'expired'])) {
        echo json_encode(['success' => false, 'message' => "This member's subscription has expired."]);
        exit;
    }

    $safeId   = $conn->real_escape_string($memberId);
    $existing = $conn->query("SELECT id FROM attendance_logs WHERE member_id='$safeId' AND DATE(time_in)=CURDATE()")->fetch_assoc();
    if ($existing) {
        echo json_encode(['success' => false, 'message' => htmlspecialchars($member['name']) . ' has already checked in today.']);
        exit;
    }

    $now  = date('Y-m-d H:i:s');
    $stmt2 = $conn->prepare("INSERT INTO attendance_logs (member_id, member_name, member_type, time_in, access_result, status) VALUES (?,?,?,?,'granted',?)");
    $stmt2->bind_param("sssss", $memberId, $member['name'], $memberType, $now, $member['status']);
    if ($stmt2->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Check-in recorded!',
            'entry'   => [
                'id'     => $memberId,
                'name'   => $member['name'],
                'type'   => $memberType,
                'time'   => date('h:i A', strtotime($now)),
                'status' => $member['status'],
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
    $stmt2->close();
    exit;
}

// ── Today's Attendance List — used by display screen & monitor polling ────
if ($action === 'list') {
    $walkins = $conn->query("
        SELECT member_id, member_name, member_type, time_in, status
        FROM attendance_logs
        WHERE DATE(time_in) = CURDATE() AND member_type = 'walkin'
        ORDER BY time_in DESC
    ");
    $subs = $conn->query("
        SELECT member_id, member_name, member_type, time_in, status
        FROM attendance_logs
        WHERE DATE(time_in) = CURDATE() AND member_type = 'subscription'
        ORDER BY time_in DESC
    ");
    $latest = $conn->query("SELECT MAX(time_in) as ts FROM attendance_logs WHERE DATE(time_in) = CURDATE()")->fetch_assoc();

    $walkinRows = [];
    while ($r = $walkins->fetch_assoc()) {
        $walkinRows[] = [
            'id'     => $r['member_id'],
            'name'   => $r['member_name'],
            'type'   => $r['member_type'],
            'time'   => date('h:i A', strtotime($r['time_in'])),
            'status' => $r['status'],
        ];
    }
    $subRows = [];
    while ($r = $subs->fetch_assoc()) {
        $subRows[] = [
            'id'     => $r['member_id'],
            'name'   => $r['member_name'],
            'type'   => $r['member_type'],
            'time'   => date('h:i A', strtotime($r['time_in'])),
            'status' => $r['status'],
        ];
    }

    echo json_encode([
        'success'     => true,
        'date'        => date('l, F d, Y'),
        'latest_ts'   => $latest['ts'] ?? null,
        'total'       => count($walkinRows) + count($subRows),
        'walkins'     => $walkinRows,
        'subscribers' => $subRows,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
