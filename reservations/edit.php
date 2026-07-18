<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");
require_once("../config/csrf.php");

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: index.php");
    exit;
}

$id = (int)$_GET["id"];

// Fetch the reservation with facility details
$stmt = $conn->prepare("
    SELECT r.*, f.name AS facility_name, f.price_per_hour, f.open_time, f.close_time, 
           f.advance_days_required, f.min_duration_hours, f.max_duration_hours, f.capacity
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    WHERE r.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$reservation = $result->fetch_assoc();

if (!$reservation) {
    header("Location: index.php");
    exit;
}

// Fetch facilities for dropdown
$facilities = $conn->query("SELECT id, name FROM facilities WHERE status = 'AVAILABLE'");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    requireCSRF();

    $fb_name = trim($_POST["fb_name"]);
    $fb_user_id = trim($_POST["fb_user_id"]);
    $facility_id = $_POST["facility_id"];
    $reservation_date = $_POST["reservation_date"];
    $start_time = $_POST["start_time"];
    $end_time = $_POST["end_time"];
    $purpose = trim($_POST["purpose"]);
    $status = $_POST["status"];
    $reject_reason = trim($_POST["reject_reason"] ?? '');
    $approval_reason = trim($_POST["approval_reason"] ?? '');
    $admin_notes = trim($_POST["admin_notes"] ?? '');
    $num_attendees = isset($_POST["num_attendees"]) ? (int)$_POST["num_attendees"] : null;

    $valid_statuses = ['PENDING', 'APPROVED', 'REJECTED', 'PENDING_VERIFICATION', 'EXPIRED', 'CANCELLED', 'ON_HOLD', 'WAITLISTED'];
    if (!in_array($status, $valid_statuses)) {
        $status = 'PENDING';
    }

    // Require reasons for status changes
    if ($status === 'REJECTED' && empty($reject_reason)) {
        $error = "A rejection reason is required when rejecting a reservation.";
    }
    if ($status === 'APPROVED' && empty($approval_reason)) {
        $error = "An approval reason is required when approving a reservation.";
    }

    if (
        empty($fb_name) ||
        empty($facility_id) || empty($reservation_date) ||
        empty($start_time) || empty($end_time)
    ) {
        $error = "All required fields must be filled.";
    }

    // Facebook ID required only for ONLINE reservations
    if ($reservation['reservation_type'] === 'ONLINE' && empty($fb_user_id)) {
        $error = "Facebook User ID is required for online reservations.";
    }

    if (!isset($error)) {
        // Calculate duration and cost
        $startDT = new DateTime($start_time);
        $endDT = new DateTime($end_time);
        $duration_hours = round(($endDT->getTimestamp() - $startDT->getTimestamp()) / 3600, 1);
        $total_cost = $duration_hours * $reservation['price_per_hour'];

        // Conflict check if approving
        if ($status === 'APPROVED') {
            $conflict_stmt = $conn->prepare("
                SELECT id FROM reservations
                WHERE facility_id = ?
                AND reservation_date = ?
                AND status = 'APPROVED'
                AND id != ?
                AND (? < end_time) AND (? > start_time)
            ");
            $conflict_stmt->bind_param("issss", $facility_id, $reservation_date, $id, $start_time, $end_time);
            $conflict_stmt->execute();
            $conflict_stmt->store_result();
            
            if ($conflict_stmt->num_rows > 0) {
                $error = "Cannot approve — time conflict with another approved reservation.";
            }
            $conflict_stmt->close();
        }
    }

    if (!isset($error)) {
        $stmt = $conn->prepare("
            UPDATE reservations 
            SET fb_user_id = ?, fb_name = ?, facility_id = ?, 
                reservation_date = ?, start_time = ?, end_time = ?, 
                purpose = ?, status = ?, reject_reason = ?, approval_reason = ?, admin_notes = ?,
                num_attendees = ?, duration_hours = ?, total_cost = ?,
                user_type = ?, id_number = ?, host_person = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ssissssssssiddsssi",
            $fb_user_id,
            $fb_name,
            $facility_id,
            $reservation_date,
            $start_time,
            $end_time,
            $purpose,
            $status,
            $reject_reason,
            $approval_reason,
            $admin_notes,
            $num_attendees,
            $duration_hours,
            $total_cost,
            $_POST['user_type'],
            $_POST['id_number'],
            $_POST['host_person'],
            $id
        );

        if ($stmt->execute()) {
            // Update addon selections
            $addon_ids = isset($_POST['addon_ids']) && is_array($_POST['addon_ids']) ? $_POST['addon_ids'] : [];
            $addon_quantities = isset($_POST['addon_quantities']) && is_array($_POST['addon_quantities']) ? $_POST['addon_quantities'] : [];
            
            // Delete existing selections
            $delStmt = $conn->prepare("DELETE FROM reservation_addon_selections WHERE reservation_id = ?");
            $delStmt->bind_param("i", $id);
            $delStmt->execute();
            $delStmt->close();
            
            // Insert new selections
            if (!empty($addon_ids)) {
                $insStmt = $conn->prepare("INSERT INTO reservation_addon_selections (reservation_id, addon_id, quantity) VALUES (?, ?, ?)");
                foreach ($addon_ids as $addon_id) {
                    $quantity = isset($addon_quantities[$addon_id]) ? intval($addon_quantities[$addon_id]) : 1;
                    if ($quantity < 1) $quantity = 1;
                    $insStmt->bind_param("iii", $id, $addon_id, $quantity);
                    $insStmt->execute();
                }
                $insStmt->close();
            }

            require_once("../config/audit_helper.php");
            $actionDetail = "Updated reservation for $fb_name (Status: $status)";
            if ($status === 'REJECTED') $actionDetail .= " — Reason: $reject_reason";
            if ($status === 'APPROVED') $actionDetail .= " — Reason: $approval_reason";
            
            logActivity($conn, 'UPDATE', 'RESERVATION', $id, $actionDetail, $reservation, [
                'fb_name' => $fb_name,
                'status' => $status,
                'reject_reason' => $reject_reason,
                'approval_reason' => $approval_reason,
                'admin_notes' => $admin_notes
            ]);

            header("Location: index.php");
            exit;
        } else {
            $error = "Database error: " . $conn->error;
        }
    }
}

// Check verification deadline
$verificationExpired = false;
if ($reservation['verification_deadline']) {
    $deadline = new DateTime($reservation['verification_deadline']);
    $now = new DateTime();
    if ($now > $deadline && $reservation['status'] === 'PENDING') {
        $verificationExpired = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Reservation - CEFI Reservation</title>
    <link rel="stylesheet" href="../style/reservations.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
    <style>
        .form-container { max-width: 850px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        
        .reservation-meta {
            background: #f0f7f2;
            border: 1px solid rgba(1, 60, 16, 0.12);
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .reservation-meta h4 { color: #013c10; margin-bottom: 0.75rem; }
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.5rem; }
        .meta-item { font-size: 0.85rem; }
        .meta-item span:first-child { color: #6b7280; }
        .meta-item span:last-child { font-weight: 600; color: #1a1a1a; }
        
        .deadline-warning {
            background: #fee2e2;
            border: 1px solid #ef4444;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            color: #991b1b;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .reject-reason-group, .approve-reason-group { display: none; }
        .reject-reason-group.show, .approve-reason-group.show { display: flex; }
        
        .admin-section {
            background: #fefce8;
            border: 1px solid rgba(252, 185, 0, 0.3);
            border-radius: 10px;
            padding: 1.25rem;
            margin-top: 1rem;
        }
        .admin-section h4 { color: #92400e; margin-bottom: 0.75rem; font-size: 0.95rem; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Edit Reservation</h1>
        <div class="header-user">Staff</div>
    </div>

    <div class="container">
    <div class="form-container">
        <h2>Edit Reservation #<?= $id ?></h2>
        
        <?php if ($verificationExpired): ?>
            <div class="deadline-warning">
                <i class="fas fa-triangle-exclamation"></i> Verification deadline has passed! This reservation was not verified within 24 hours.
                Consider setting status to EXPIRED.
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        
        <!-- Reservation Info Summary -->
        <div class="reservation-meta">
            <h4><i class="fas fa-clipboard-list"></i> Reservation Details</h4>
            <div class="meta-grid">
                <div class="meta-item"><span>Facility: </span><span><?= htmlspecialchars($reservation['facility_name']) ?></span></div>
                <div class="meta-item"><span>Created: </span><span><?= $reservation['created_at'] ?></span></div>
                <?php if ($reservation['duration_hours']): ?>
                    <div class="meta-item"><span>Duration: </span><span><?= $reservation['duration_hours'] ?> hrs</span></div>
                <?php endif; ?>
                <?php if ($reservation['total_cost'] > 0): ?>
                    <div class="meta-item"><span>Est. Cost: </span><span>₱<?= number_format($reservation['total_cost'], 2) ?></span></div>
                <?php endif; ?>
                <?php if ($reservation['verification_deadline']): ?>
                    <div class="meta-item"><span>Verify By: </span><span><?= $reservation['verification_deadline'] ?></span></div>
                <?php endif; ?>
                <div class="meta-item"><span>Reservation Type: </span><span style="color: <?= $reservation['reservation_type'] == 'WALK_IN' ? '#013c10' : '#1d4ed8' ?>;"><?= $reservation['reservation_type'] ?></span></div>
                <?php if ($reservation['num_attendees']): ?>
                    <div class="meta-item"><span>Attendees: </span><span><?= $reservation['num_attendees'] ?></span></div>
                <?php endif; ?>
            </div>
        </div>
        
        <form method="POST">
            <?php csrfField(); ?>
            <div class="grid-2">
                <div class="input-group">
                    <label for="fb_name">Full Name</label>
                    <input type="text" id="fb_name" name="fb_name" value="<?= htmlspecialchars($reservation['fb_name']) ?>" required>
                </div>

                <div class="input-group">
                    <label for="user_type">User Type</label>
                    <select id="user_type" name="user_type" onchange="toggleTypeFields()">
                        <option value="FACEBOOK" <?= $reservation['user_type'] == 'FACEBOOK' ? 'selected' : '' ?>>Facebook User</option>
                        <option value="STUDENT" <?= $reservation['user_type'] == 'STUDENT' ? 'selected' : '' ?>>Student (Walk-in)</option>
                        <option value="STAFF" <?= $reservation['user_type'] == 'STAFF' ? 'selected' : '' ?>>Staff/Faculty (Walk-in)</option>
                        <option value="OUTSIDE" <?= $reservation['user_type'] == 'OUTSIDE' ? 'selected' : '' ?>>Outside (Walk-in)</option>
                    </select>
                </div>
            </div>

            <div class="grid-2">
                <div class="input-group" id="fb_id_group">
                    <label for="fb_user_id">Facebook / Messenger ID</label>
                    <input type="text" id="fb_user_id" name="fb_user_id" value="<?= htmlspecialchars($reservation['fb_user_id'] ?? '') ?>">
                </div>

                <div class="input-group" id="id_number_group">
                    <label for="id_number">Identification ID (Student/Emp ID)</label>
                    <input type="text" id="id_number" name="id_number" value="<?= htmlspecialchars($reservation['id_number'] ?? '') ?>">
                </div>
            </div>

            <div class="input-group" id="host_person_group">
                <label for="host_person">Host / Contact Person (Inside School)</label>
                <input type="text" id="host_person" name="host_person" value="<?= htmlspecialchars($reservation['host_person'] ?? '') ?>">
            </div>

            <div class="input-group">
                <label for="facility_id">Facility</label>
                <select id="facility_id" name="facility_id" required>
                    <option value="">Select Facility</option>
                    <?php 
                    $facilities->data_seek(0);
                    while($row = $facilities->fetch_assoc()): 
                    ?>
                        <option value="<?= $row['id'] ?>" <?= $reservation['facility_id'] == $row['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($row['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="grid-2">
                <div class="input-group">
                    <label for="reservation_date">Date</label>
                    <input type="date" id="reservation_date" name="reservation_date" value="<?= $reservation['reservation_date'] ?>" required>
                </div>

                <div class="input-group">
                    <label for="num_attendees">Attendees</label>
                    <input type="number" id="num_attendees" name="num_attendees" value="<?= $reservation['num_attendees'] ?>">
                </div>
            </div>

            <div class="grid-2">
                <div class="input-group">
                    <label for="start_time">Start Time</label>
                    <input type="time" id="start_time" name="start_time" value="<?= substr($reservation['start_time'],0,5) ?>" required>
                </div>

                <div class="input-group">
                    <label for="end_time">End Time</label>
                    <input type="time" id="end_time" name="end_time" value="<?= substr($reservation['end_time'],0,5) ?>" required>
                </div>
            </div>

            <div class="input-group">
                <label for="purpose">Purpose</label>
                <textarea id="purpose" name="purpose"><?= htmlspecialchars($reservation['purpose']) ?></textarea>
            </div>

            <!-- Add-ons Section (loaded dynamically) -->
            <?php
            // Fetch facility add-ons for this reservation's facility
            $facility_id = $reservation['facility_id'];
            $addonStmt = $conn->prepare("SELECT fa.id, fa.addon_name, fa.price FROM facility_addons fa WHERE fa.facility_id = ? ORDER BY fa.addon_name");
            $addonStmt->bind_param("i", $facility_id);
            $addonStmt->execute();
            $addonResult = $addonStmt->get_result();

            // Fetch existing selections for this reservation
            $selStmt = $conn->prepare("SELECT addon_id, quantity FROM reservation_addon_selections WHERE reservation_id = ?");
            $selStmt->bind_param("i", $id);
            $selStmt->execute();
            $selResult = $selStmt->get_result();
            $selectedAddons = [];
            while ($selRow = $selResult->fetch_assoc()) {
                $selectedAddons[$selRow['addon_id']] = $selRow['quantity'];
            }
            $selStmt->close();
            ?>
            <div class="input-group" id="addonsSection" style="margin-top: 1rem;">
                <label>Add-on Services</label>
                <p class="input-hint">Select additional services and specify quantity:</p>
                <div class="addon-list" id="addonsList" style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php if ($addonResult->num_rows > 0): ?>
                        <?php while ($addonRow = $addonResult->fetch_assoc()): ?>
                            <?php $isSelected = isset($selectedAddons[$addonRow['id']]); $qty = $isSelected ? $selectedAddons[$addonRow['id']] : 1; ?>
                            <div class="addon-item" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; background: #fff; border: 1px solid #d1d5db; border-radius: 6px;">
                                <input type="checkbox" name="addon_ids[]" value="<?= $addonRow['id'] ?>" <?= $isSelected ? 'checked' : '' ?> data-price="<?= $addonRow['price'] ?>" style="width: 18px; height: 18px;">
                                <span style="flex: 1;"><?= htmlspecialchars($addonRow['addon_name']) ?></span>
                                <span style="color: #6b7280; font-size: 0.85rem;"><?= $addonRow['price'] > 0 ? '₱' . number_format($addonRow['price'], 2) : 'Free' ?></span>
                                <input type="number" name="addon_quantities[<?= $addonRow['id'] ?>]" value="<?= $qty ?>" min="1" style="width: 60px; text-align: center;">
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p style="color: #6b7280; font-size: 0.85rem;">No add-ons available for this facility.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cost Display -->
            <div id="costSummary" style="display: none; background: #fffbee; border: 1.5px solid rgba(252, 185, 0, 0.4); border-radius: 10px; padding: 1rem 1.25rem; margin-top: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 600; color: #4a7c59;">Estimated Cost</span>
                    <span id="costAmount" style="font-size: 1.5rem; font-weight: 800; color: #013c10;"></span>
                </div>
                <div id="costBreakdown" style="font-size: 0.8rem; color: #6b7280; margin-top: 0.25rem;"></div>
            </div>

            <!-- Staff Action Section -->
            <div class="admin-section">
                <h4><i class="fas fa-key"></i> Staff Actions</h4>

                <div class="input-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" onchange="toggleRejectReason()">
                        <?php 
                        $allStatuses = ['PENDING', 'APPROVED', 'REJECTED', 'PENDING_VERIFICATION', 'EXPIRED', 'CANCELLED', 'ON_HOLD', 'WAITLISTED'];
                        foreach ($allStatuses as $s):
                        ?>
                            <option value="<?= $s ?>" <?= $reservation['status'] == $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="input-group reject-reason-group" id="rejectReasonGroup">
                    <label for="reject_reason">Rejection Reason <span style="color:#ef4444;">*</span></label>
                    <textarea id="reject_reason" name="reject_reason" placeholder="Explain why this reservation is being rejected..."><?= htmlspecialchars($reservation['reject_reason'] ?? '') ?></textarea>
                </div>

                <div class="input-group approve-reason-group" id="approveReasonGroup">
                    <label for="approval_reason">Approval Reason <span style="color:#166534;">*</span></label>
                    <textarea id="approval_reason" name="approval_reason" placeholder="Explain the approval decision..."><?= htmlspecialchars($reservation['approval_reason'] ?? '') ?></textarea>
                </div>

                <div class="input-group" style="margin-top: 0.75rem;">
                    <label for="admin_notes">Staff Notes (internal)</label>
                    <textarea id="admin_notes" name="admin_notes" placeholder="Internal notes visible only to staff..."><?= htmlspecialchars($reservation['admin_notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary">Update Reservation</button>
                <a href="index.php" class="btn btn-secondary">Back</a>
                <?php if ($reservation['status'] == 'APPROVED'): ?>
                    <a href="print.php?id=<?= $reservation['id'] ?>" class="btn btn-secondary" style="background:#013c10;color:#fff;border-color:#013c10;"><i class="fas fa-print"></i> Print Slip</a>
                <?php endif; ?>
                <?php if (in_array($reservation['status'], ['PENDING', 'APPROVED', 'PENDING_VERIFICATION', 'ON_HOLD'])): ?>
                    <a href="javascript:void(0)" class="btn btn-secondary" style="background:#f59e0b;color:#fff;border-color:#f59e0b;" onclick="if(confirm('Are you sure you want to cancel this reservation?')){let r=prompt('Please provide a cancellation reason:');if(r){window.location='cancel.php?id=<?= $reservation['id'] ?>&reason='+encodeURIComponent(r);}}"><i class="fas fa-xmark"></i> Cancel Reservation</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
</div>

<div class="footer">
    © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc., Philippines | Contact: info@cefi.website
</div>

<script>
function toggleRejectReasonCheck() {
    const status = document.getElementById('status').value;
    const rGroup = document.getElementById('rejectReasonGroup');
    const aGroup = document.getElementById('approveReasonGroup');
    
    rGroup.classList.remove('show');
    aGroup.classList.remove('show');
    
    if (status === 'REJECTED') {
        rGroup.classList.add('show');
    } else if (status === 'APPROVED') {
        aGroup.classList.add('show');
    }
}

function toggleTypeFields() {
    const type = document.getElementById('user_type').value;
    const fbGroup = document.getElementById('fb_id_group');
    const idGroup = document.getElementById('id_number_group');
    const hostGroup = document.getElementById('host_person_group');

    fbGroup.style.display = (type === 'FACEBOOK') ? 'block' : 'none';
    idGroup.style.display = (type === 'STUDENT' || type === 'STAFF') ? 'block' : 'none';
        hostGroup.style.display = (type === 'OUTSIDE') ? 'block' : 'none';
}

// Initialize on load
toggleRejectReasonCheck();
toggleTypeFields();
window.onload = calcCost;

// Cost calculation
const startInput = document.getElementById('start_time');
const endInput = document.getElementById('end_time');
const facilitySel = document.getElementById('facility_id');

startInput.addEventListener('change', calcCost);
startInput.addEventListener('input', calcCost);
endInput.addEventListener('change', calcCost);
endInput.addEventListener('input', calcCost);
facilitySel.addEventListener('change', calcCost);

document.addEventListener('change', function(e) {
    if (e.target.name === 'addon_ids[]' || (e.target.name && e.target.name.startsWith('addon_quantities['))) {
        calcCost();
    }
});

document.addEventListener('input', function(e) {
    if (e.target.name && e.target.name.startsWith('addon_quantities[')) {
        calcCost();
    }
});

function calcCost() {
    const facilityOption = document.querySelector('#facility_id option:checked');
    const facilityPrice = facilityOption ? parseFloat(facilityOption.dataset.price) || 0 : 0;
    
    const startTime = document.getElementById('start_time').value;
    const endTime = document.getElementById('end_time').value;
    
    let facilityCost = 0;
    if (startTime && endTime && facilityPrice > 0) {
        const startParts = startTime.split(':');
        const endParts = endTime.split(':');
        const durationMinutes = (parseInt(endParts[0]) * 60 + parseInt(endParts[1])) - (parseInt(startParts[0]) * 60 + parseInt(startParts[1]));
        const durationHours = durationMinutes / 60;
        if (durationHours > 0) {
            facilityCost = durationHours * facilityPrice;
        }
    }
    
    // Add-ons cost
    let addonTotal = 0;
    document.querySelectorAll('input[name="addon_ids[]"]:checked').forEach(checkbox => {
        const addonId = checkbox.value;
        const quantityInput = document.querySelector(`input[name="addon_quantities[${addonId}]")`);
        const quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
        const price = parseFloat(checkbox.dataset.price) || 0;
        addonTotal += price * quantity;
    });
    
    const total = facilityCost + addonTotal;
    
    const costSummary = document.getElementById('costSummary');
    if (total > 0) {
        let breakdown = '';
        if (facilityCost > 0) {
            breakdown = 'Facility: ₱' + facilityCost.toFixed(2);
        }
        if (addonTotal > 0) {
            breakdown += (breakdown ? ' + ' : '') + 'Add-ons: ₱' + addonTotal.toFixed(2);
        }
        document.getElementById('costAmount').textContent = '₱' + total.toFixed(2);
        document.getElementById('costBreakdown').textContent = breakdown;
        costSummary.style.display = 'block';
    } else {
        costSummary.style.display = 'none';
    }
}
</script>

</body>
</html>
