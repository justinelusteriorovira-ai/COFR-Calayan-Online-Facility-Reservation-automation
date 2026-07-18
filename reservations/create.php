<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");
require_once("../config/csrf.php");

// Fetch facilities for dropdown
$facilities = $conn->query("SELECT id, name, price_per_hour, price_per_day, open_time, close_time, advance_days_required, min_duration_hours, max_duration_hours, allowed_days, capacity FROM facilities WHERE status = 'AVAILABLE'");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    requireCSRF();

    $fb_name = trim($_POST["fb_name"]);
    $fb_user_id = trim($_POST["fb_user_id"]);
    $user_email = trim($_POST["user_email"] ?? '');
    $user_phone = trim($_POST["user_phone"] ?? '');
    $facility_id = (int)$_POST["facility_id"];
    $reservation_date = $_POST["reservation_date"];
    $start_time = $_POST["start_time"];
    $end_time = $_POST["end_time"];
    $purpose = trim($_POST["purpose"]);
    $num_attendees = isset($_POST["num_attendees"]) ? (int)$_POST["num_attendees"] : null;

    // Validate email format
    if (!empty($user_email) && !filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    }
    // Validate phone format (digits, +, -, spaces, parens)
    if (!empty($user_phone) && !preg_match('/^[\d\s\-\+\(\)]{7,20}$/', $user_phone)) {
        $error = "Please enter a valid phone number.";
    }

    if (
        empty($fb_name) || empty($fb_user_id) ||
        empty($facility_id) || empty($reservation_date) ||
        empty($start_time) || empty($end_time)
    ) {
        $error = "All required fields must be filled.";
    } else {
        // Fetch facility rules
        $fstmt = $conn->prepare("SELECT * FROM facilities WHERE id = ? AND status = 'AVAILABLE'");
        $fstmt->bind_param("i", $facility_id);
        $fstmt->execute();
        $fac = $fstmt->get_result()->fetch_assoc();
        $fstmt->close();

        if (!$fac) {
            $error = "Selected facility is not available.";
        }
        
        // === VALIDATION 1: Rush booking check (advance days) ===
        if (!isset($error)) {
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            $resDate = new DateTime($reservation_date);
            $resDate->setTime(0, 0, 0);
            $diff = $today->diff($resDate)->days;
            $isInPast = $resDate < $today;
            
            if ($isInPast) {
                $error = "Cannot book a date in the past.";
            } elseif ($diff < $fac['advance_days_required']) {
                $error = "This facility requires at least {$fac['advance_days_required']} day(s) advance booking. For urgent requests, please visit the CEFI Reservation Office in person.";
            }
        }

        // === VALIDATION 2: Allowed day of week ===
        if (!isset($error)) {
            $dayOfWeek = (int)$resDate->format('w'); // 0=Sun, 1=Mon ... 6=Sat
            $allowedDays = explode(',', $fac['allowed_days']);
            if (!in_array((string)$dayOfWeek, $allowedDays)) {
                $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                $error = "This facility is not available on {$dayNames[$dayOfWeek]}s.";
            }
        }

        // === VALIDATION 3: Opening / closing hours ===
        if (!isset($error)) {
            $facOpen = substr($fac['open_time'], 0, 5);
            $facClose = substr($fac['close_time'], 0, 5);
            if ($start_time < $facOpen || $end_time > $facClose) {
                $error = "Time must be between {$facOpen} and {$facClose} for this facility.";
            }
            if ($start_time >= $end_time) {
                $error = "End time must be after start time.";
            }
        }

        // === VALIDATION 4: Duration limits ===
        if (!isset($error)) {
            $startDT = new DateTime($start_time);
            $endDT = new DateTime($end_time);
            $durationMinutes = ($endDT->getTimestamp() - $startDT->getTimestamp()) / 60;
            $durationHours = $durationMinutes / 60;
            
            if ($durationHours < $fac['min_duration_hours']) {
                $error = "Minimum booking duration is {$fac['min_duration_hours']} hour(s).";
            }
            if ($durationHours > $fac['max_duration_hours']) {
                $error = "Maximum booking duration is {$fac['max_duration_hours']} hours.";
            }
        }

        // === VALIDATION 5: Capacity check ===
        if (!isset($error) && $num_attendees && $num_attendees > $fac['capacity']) {
            $error = "Number of attendees ({$num_attendees}) exceeds facility capacity ({$fac['capacity']}).";
        }

        // === VALIDATION 6: Double-booking / conflict detection ===
        // Note: Conflicts with PENDING reservations are ALLOWED (admin will resolve).
        // Only hard-block if there's an APPROVED conflict.
        $conflict_warning = '';
        if (!isset($error)) {
            // Check for APPROVED conflicts (hard block)
            $approved_conflict = $conn->prepare("
                SELECT id, fb_name, start_time, end_time FROM reservations
                WHERE facility_id = ? AND reservation_date = ? AND status = 'APPROVED'
                AND (? < end_time) AND (? > start_time)
            ");
            $approved_conflict->bind_param("isss", $facility_id, $reservation_date, $start_time, $end_time);
            $approved_conflict->execute();
            $app_result = $approved_conflict->get_result();
            if ($app_result->num_rows > 0) {
                $c = $app_result->fetch_assoc();
                $error = "Time conflict! This facility already has an APPROVED reservation from {$c['start_time']} to {$c['end_time']} by {$c['fb_name']}.";
            }
            $approved_conflict->close();

            // Check for PENDING conflicts (warning only, still allow)
            if (!isset($error)) {
                $pending_conflict = $conn->prepare("
                    SELECT id, fb_name, start_time, end_time FROM reservations
                    WHERE facility_id = ? AND reservation_date = ? AND status = 'PENDING'
                    AND (? < end_time) AND (? > start_time)
                ");
                $pending_conflict->bind_param("isss", $facility_id, $reservation_date, $start_time, $end_time);
                $pending_conflict->execute();
                $pend_result = $pending_conflict->get_result();
                if ($pend_result->num_rows > 0) {
                    $conflict_names = [];
                    while ($pc = $pend_result->fetch_assoc()) {
                        $conflict_names[] = $pc['fb_name'] . ' (' . substr($pc['start_time'],0,5) . '-' . substr($pc['end_time'],0,5) . ')';
                    }
                    $conflict_warning = 'Note: There are ' . count($conflict_names) . ' pending reservation(s) overlapping this time slot: ' . implode(', ', $conflict_names) . '. The admin will need to resolve the conflict when approving.';
                }
                $pending_conflict->close();
            }
        }

        // === ALL VALIDATIONS PASSED — Insert ===
        if (!isset($error)) {
            $duration_hours = round($durationHours, 1);
            $total_cost = $duration_hours * $fac['price_per_hour'];
            
            // Set verification deadline: 24 hours from now
            $verificationDeadline = (new DateTime())->modify('+24 hours')->format('Y-m-d H:i:s');

            $reservation_needs = isset($_POST['reservation_needs']) ? trim($_POST['reservation_needs']) : null;

            $stmt = $conn->prepare("
                INSERT INTO reservations
                (fb_user_id, fb_name, user_email, user_phone, facility_id, reservation_date, start_time, end_time, purpose, num_attendees, duration_hours, total_cost, verification_deadline, reservation_needs, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
            ");

            $stmt->bind_param(
                "ssssissssiddss",
                $fb_user_id,
                $fb_name,
                $user_email,
                $user_phone,
                $facility_id,
                $reservation_date,
                $start_time,
                $end_time,
                $purpose,
                $num_attendees,
                $duration_hours,
                $total_cost,
                $verificationDeadline,
                $reservation_needs
            );

            if ($stmt->execute()) {
                $new_id = $conn->insert_id;

                // Save addon selections with quantities
                $addon_ids = isset($_POST['addon_ids']) && is_array($_POST['addon_ids']) ? $_POST['addon_ids'] : [];
                $addon_quantities = isset($_POST['addon_quantities']) && is_array($_POST['addon_quantities']) ? $_POST['addon_quantities'] : [];
                if (!empty($addon_ids)) {
                    $addonStmt = $conn->prepare("INSERT INTO reservation_addon_selections (reservation_id, addon_id, quantity) VALUES (?, ?, ?)");
                    foreach ($addon_ids as $addon_id) {
                        $quantity = isset($addon_quantities[$addon_id]) ? intval($addon_quantities[$addon_id]) : 1;
                        if ($quantity < 1) $quantity = 1;
                        $addonStmt->bind_param("iii", $new_id, $addon_id, $quantity);
                        $addonStmt->execute();
                    }
                    $addonStmt->close();
                }

                require_once("../config/audit_helper.php");
                logActivity($conn, 'CREATE', 'RESERVATION', $new_id, "Created reservation for $fb_name at {$fac['name']}", null, [
                    'fb_name' => $fb_name,
                    'facility' => $fac['name'],
                    'date' => $reservation_date,
                    'time' => "$start_time - $end_time",
                    'duration' => $duration_hours . 'h',
                    'cost' => '₱' . number_format($total_cost, 2)
                ]);

                $redirect = "create.php?success=1&cost=" . urlencode(number_format($total_cost, 2));
                if (!empty($conflict_warning)) {
                    $redirect .= "&conflict_warning=" . urlencode($conflict_warning);
                }
                header("Location: $redirect");
                exit;
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Reservation - CEFI Reservation</title>
    <link rel="stylesheet" href="../style/reservations.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
    <style>
        .form-container { max-width: 850px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        
        .facility-info-panel {
            background: #f0f7f2;
            border: 1px solid rgba(1, 60, 16, 0.15);
            border-left: 4px solid #013c10;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: none;
        }
        .facility-info-panel.show { display: block; }
        .facility-info-panel h4 {
            color: #013c10;
            font-size: 1rem;
            margin-bottom: 0.75rem;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.75rem;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            font-weight: 600;
        }
        .info-value {
            font-weight: 700;
            color: #013c10;
            font-size: 0.95rem;
        }
        .info-value.price { color: #d97706; }
        
        .cost-summary {
            background: #fffbee;
            border: 1.5px solid rgba(252, 185, 0, 0.4);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
            display: none;
        }
        .cost-summary.show { display: flex; align-items: center; justify-content: space-between; }
        .cost-label { font-weight: 600; color: #4a7c59; font-size: 0.9rem; }
        .cost-amount { font-size: 1.5rem; font-weight: 800; color: #013c10; }
        .cost-breakdown { font-size: 0.8rem; color: #6b7280; }
        
        .warning-banner {
            background: #fef3c7;
            border: 1px solid #d97706;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: #92400e;
            display: none;
        }
        .warning-banner.show { display: block; }
        .warning-banner strong { color: #78350f; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Create Reservation</h1>
        <div class="header-user">Admin</div>
    </div>

    <div class="container">
    <div class="form-container">
        <h2>Create Reservation</h2>
        
        <?php if (isset($_GET["success"])): ?>
            <p class="success">
                <i class="fas fa-circle-check" style="color:#166534;"></i> Reservation submitted successfully (PENDING).
                <?php if (isset($_GET["cost"]) && $_GET["cost"] != "0.00"): ?>
                    Estimated cost: <strong>₱<?= htmlspecialchars($_GET["cost"]) ?></strong>
                <?php endif; ?>
                <br><small>The user has 24 hours to verify this request.</small>
            </p>
            <?php if (isset($_GET['conflict_warning'])): ?>
                <p class="error" style="background:#fef3c7;color:#92400e;border-left-color:#f59e0b;">
                    <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($_GET['conflict_warning']) ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        
        <form method="POST" id="reservationForm">
            <?php csrfField(); ?>
            <div class="grid-2">
                <div class="input-group">
                    <label for="fb_name">Full Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="fb_name" name="fb_name" placeholder="Enter full name" required
                        value="<?= isset($fb_name) ? htmlspecialchars($fb_name) : '' ?>">
                </div>

                <div class="input-group">
                    <label for="fb_user_id">Facebook / Messenger ID <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="fb_user_id" name="fb_user_id" placeholder="Enter Facebook or Messenger ID" required
                        value="<?= isset($fb_user_id) ? htmlspecialchars($fb_user_id) : '' ?>">
                </div>
            </div>

            <div class="grid-2">
                <div class="input-group">
                    <label for="user_email">Email Address</label>
                    <input type="email" id="user_email" name="user_email" placeholder="user@email.com"
                        value="<?= isset($user_email) ? htmlspecialchars($user_email) : '' ?>">
                </div>

                <div class="input-group">
                    <label for="user_phone">Phone Number</label>
                    <input type="tel" id="user_phone" name="user_phone" placeholder="09171234567"
                        value="<?= isset($user_phone) ? htmlspecialchars($user_phone) : '' ?>">
                </div>
            </div>

            <div class="input-group">
                <label for="facility_id">Facility</label>
                <select id="facility_id" name="facility_id" required>
                    <option value="">Select Facility</option>
                    <?php while($row = $facilities->fetch_assoc()): ?>
                        <option value="<?= $row['id'] ?>"
                            data-price="<?= $row['price_per_hour'] ?>"
                            data-priceday="<?= $row['price_per_day'] ?>"
                            data-open="<?= substr($row['open_time'],0,5) ?>"
                            data-close="<?= substr($row['close_time'],0,5) ?>"
                            data-advance="<?= $row['advance_days_required'] ?>"
                            data-minhr="<?= $row['min_duration_hours'] ?>"
                            data-maxhr="<?= $row['max_duration_hours'] ?>"
                            data-days="<?= $row['allowed_days'] ?>"
                            data-capacity="<?= $row['capacity'] ?>"
                            <?= (isset($facility_id) && $facility_id == $row['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($row['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Dynamic Facility Info Panel -->
            <div class="facility-info-panel" id="facilityInfoPanel">
                <h4><i class="fas fa-clipboard-list"></i> Facility Rules</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Hours</span>
                        <span class="info-value" id="infoHours">—</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Rate / Hour</span>
                        <span class="info-value price" id="infoPrice">—</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Advance Notice</span>
                        <span class="info-value" id="infoAdvance">—</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Duration</span>
                        <span class="info-value" id="infoDuration">—</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Capacity</span>
                        <span class="info-value" id="infoCapacity">—</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Booking Days</span>
                        <span class="info-value" id="infoDays">—</span>
                    </div>
                </div>
            </div>

            <!-- Warning banner for rule violations -->
            <div class="warning-banner" id="warningBanner"></div>

            <div class="grid-2">
                <div class="input-group">
                    <label for="reservation_date">Reservation Date</label>
                    <input type="date" id="reservation_date" name="reservation_date" required
                        value="<?= isset($reservation_date) ? $reservation_date : '' ?>">
                </div>

                <div class="input-group">
                    <label for="num_attendees">Number of Attendees</label>
                    <input type="number" id="num_attendees" name="num_attendees" placeholder="How many people?"
                        value="<?= isset($num_attendees) ? $num_attendees : '' ?>">
                </div>
            </div>

            <div class="grid-2">
                <div class="input-group">
                    <label for="start_time">Start Time</label>
                    <input type="time" id="start_time" name="start_time" required
                        value="<?= isset($start_time) ? $start_time : '' ?>">
                </div>

                <div class="input-group">
                    <label for="end_time">End Time</label>
                    <input type="time" id="end_time" name="end_time" required
                        value="<?= isset($end_time) ? $end_time : '' ?>">
                </div>
            </div>

            <!-- Cost Summary -->
            <div class="cost-summary" id="costSummary">
                <div>
                    <span class="cost-label">Estimated Cost</span>
                    <div class="cost-breakdown" id="costBreakdown">0 hrs × ₱0.00/hr</div>
                </div>
                <span class="cost-amount" id="costAmount">₱0.00</span>
            </div>

            <div class="input-group" style="margin-top:1rem;">
                <label for="purpose">Purpose</label>
                <textarea id="purpose" name="purpose" placeholder="Enter purpose of reservation"><?= isset($purpose) ? htmlspecialchars($purpose) : '' ?></textarea>
            </div>

            <!-- Add-ons Section (loaded dynamically) -->
            <div class="input-group" id="addonsSection" style="margin-top:1rem; display: none;">
                <label>Add-on Services</label>
                <p class="input-hint">Select any additional services you need for this reservation:</p>
                <div class="checkbox-group" id="addonsCheckboxes" style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                    <!-- Dynamically loaded via AJAX -->
                </div>
            </div>

            <div class="input-group" id="reservationNeedsGroup" style="margin-top:1rem; display: none;">
                <label for="reservation_needs">Other Requirements / Items</label>
                <textarea id="reservation_needs" name="reservation_needs" placeholder="List any other requirements or items not listed above..."></textarea>
            </div>

            <div style="margin-top: 1rem; display: flex; gap: 0.75rem; align-items: center;">
                <button type="submit" class="btn btn-primary" id="submitBtn">Submit Reservation</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>

<div class="footer">
    © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc., Philippines | Contact: info@cefi.website
</div>

<script>
const facilitySelect = document.getElementById('facility_id');
const dateInput = document.getElementById('reservation_date');
const startInput = document.getElementById('start_time');
const endInput = document.getElementById('end_time');
const attendeesInput = document.getElementById('num_attendees');
const infoPanel = document.getElementById('facilityInfoPanel');
const warningBanner = document.getElementById('warningBanner');
const costSummary = document.getElementById('costSummary');
const submitBtn = document.getElementById('submitBtn');

const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

function getSelectedFacilityData() {
    const opt = facilitySelect.selectedOptions[0];
    if (!opt || !opt.value) return null;
    return {
        price: parseFloat(opt.dataset.price) || 0,
        priceDay: parseFloat(opt.dataset.priceday) || 0,
        open: opt.dataset.open,
        close: opt.dataset.close,
        advance: parseInt(opt.dataset.advance) || 0,
        minHr: parseInt(opt.dataset.minhr) || 1,
        maxHr: parseInt(opt.dataset.maxhr) || 8,
        days: opt.dataset.days.split(','),
        capacity: parseInt(opt.dataset.capacity) || 0
    };
}

function updateInfoPanel() {
    const data = getSelectedFacilityData();
    if (!data) {
        infoPanel.classList.remove('show');
        return;
    }
    
    infoPanel.classList.add('show');
    document.getElementById('infoHours').textContent = data.open + ' – ' + data.close;
    document.getElementById('infoPrice').textContent = data.price > 0 ? '₱' + data.price.toFixed(2) : 'Free';
    document.getElementById('infoAdvance').textContent = data.advance + ' day(s) minimum';
    document.getElementById('infoDuration').textContent = data.minHr + '–' + data.maxHr + ' hr(s)';
    document.getElementById('infoCapacity').textContent = data.capacity + ' persons';
    
    const allowedDayLabels = data.days.map(d => dayNames[parseInt(d)]).join(', ');
    document.getElementById('infoDays').textContent = allowedDayLabels;
    
    // Set min date based on advance days
    const minDate = new Date();
    minDate.setDate(minDate.getDate() + data.advance);
    dateInput.min = minDate.toISOString().split('T')[0];
    
    // Set time constraints
    startInput.min = data.open;
    startInput.max = data.close;
    endInput.min = data.open;
    endInput.max = data.close;
}

function validateForm() {
    const data = getSelectedFacilityData();
    let warnings = [];
    
    if (!data) {
        warningBanner.classList.remove('show');
        costSummary.classList.remove('show');
        return;
    }
    
    const date = dateInput.value;
    const start = startInput.value;
    const end = endInput.value;
    const attendees = parseInt(attendeesInput.value) || 0;
    
    // Check date
    if (date) {
        const selectedDate = new Date(date + 'T00:00:00');
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        const diffTime = selectedDate.getTime() - today.getTime();
        const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays < 0) {
            warnings.push('<i class="fas fa-triangle-exclamation"></i> Cannot book a date in the past.');
        } else if (diffDays < data.advance) {
            warnings.push('<i class="fas fa-triangle-exclamation"></i> <strong>Rush booking!</strong> This facility requires at least ' + data.advance + ' day(s) advance notice. Please visit the CEFI Reservation Office for urgent requests.');
        }
        
        // Day of week check
        const dayOfWeek = selectedDate.getDay();
        if (!data.days.includes(String(dayOfWeek))) {
            const dayName = dayNames[dayOfWeek];
            warnings.push('<i class="fas fa-triangle-exclamation"></i> This facility is not available on <strong>' + dayName + 's</strong>.');
        }
    }
    
    // Check time
    if (start && end) {
        if (start < data.open) {
            warnings.push('<i class="fas fa-triangle-exclamation"></i> Start time is before facility opening (' + data.open + ').');
        }
        if (end > data.close) {
            warnings.push('<i class="fas fa-triangle-exclamation"></i> End time is after facility closing (' + data.close + ').');
        }
        if (start >= end) {
            warnings.push('<i class="fas fa-triangle-exclamation"></i> End time must be after start time.');
        }
        
        // Duration check
        const startParts = start.split(':');
        const endParts = end.split(':');
        const durationMinutes = (parseInt(endParts[0]) * 60 + parseInt(endParts[1])) - (parseInt(startParts[0]) * 60 + parseInt(startParts[1]));
        const durationHours = durationMinutes / 60;
        
        if (durationHours > 0) {
            if (durationHours < data.minHr) {
                warnings.push('<i class="fas fa-triangle-exclamation"></i> Minimum booking duration is ' + data.minHr + ' hour(s).');
            }
            if (durationHours > data.maxHr) {
                warnings.push('<i class="fas fa-triangle-exclamation"></i> Maximum booking duration is ' + data.maxHr + ' hours.');
            }
            
            // Update cost
            if (data.price > 0) {
                const cost = durationHours * data.price;
                document.getElementById('costAmount').textContent = '₱' + cost.toFixed(2);
                document.getElementById('costBreakdown').textContent = durationHours.toFixed(1) + ' hrs × ₱' + data.price.toFixed(2) + '/hr';
                costSummary.classList.add('show');
            } else {
                costSummary.classList.remove('show');
            }
        }
    } else {
        costSummary.classList.remove('show');
    }
    
    // Capacity check
    if (attendees > 0 && attendees > data.capacity) {
        warnings.push('<i class="fas fa-triangle-exclamation"></i> Attendees (' + attendees + ') exceeds capacity (' + data.capacity + ').');
    }
    
    // Update UI
    if (warnings.length > 0) {
        warningBanner.innerHTML = warnings.join('<br>');
        warningBanner.classList.add('show');
    } else {
        warningBanner.classList.remove('show');
    }
}

// Add-ons management
const addonsSection = document.getElementById('addonsSection');
const addonsCheckboxes = document.getElementById('addonsCheckboxes');
const reservationNeedsGroup = document.getElementById('reservationNeedsGroup');

async function loadFacilityAddons(facilityId) {
    if (!facilityId) {
        addonsSection.style.display = 'none';
        reservationNeedsGroup.style.display = 'none';
        addonsCheckboxes.innerHTML = '';
        return;
    }

    try {
        const response = await fetch('../api/get_facility_addons.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ facility_id: facilityId })
        });
        const data = await response.json();

        if (data.success && data.addons && data.addons.length > 0) {
            let html = '';
            data.addons.forEach(addon => {
                const priceLabel = addon.price > 0 ? `₱${parseFloat(addon.price).toFixed(2)}` : 'Free';
                html += `<div class="addon-item" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; margin-bottom: 0.5rem;">
                    <input type="checkbox" name="addon_ids[]" value="${addon.id}" data-price="${addon.price}" style="width: 18px; height: 18px;">
                    <span style="flex: 1; font-weight: 500;">${escapeHtml(addon.addon_name)}</span>
                    <span style="color: #6b7280; font-size: 0.85rem;">${priceLabel}</span>
                    <input type="number" name="addon_quantities[${addon.id}]" placeholder="Qty" min="1" value="1" style="width: 60px; text-align: center;" title="Quantity">
                </div>`;
            });
            addonsCheckboxes.innerHTML = html;
            addonsSection.style.display = 'block';
            reservationNeedsGroup.style.display = 'block';
        } else {
            addonsSection.style.display = 'none';
            reservationNeedsGroup.style.display = 'none';
            addonsCheckboxes.innerHTML = '';
        }
    } catch (error) {
        console.error('Error loading add-ons:', error);
        addonsSection.style.display = 'none';
        reservationNeedsGroup.style.display = 'none';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Event listeners
facilitySelect.addEventListener('change', function() {
    updateInfoPanel();
    validateForm();
    loadFacilityAddons(this.value);
});
dateInput.addEventListener('change', validateForm);
startInput.addEventListener('change', validateForm);
endInput.addEventListener('change', validateForm);
attendeesInput.addEventListener('input', validateForm);

// Initialize if values already set (e.g., form re-render after error)
if (facilitySelect.value) {
    updateInfoPanel();
    validateForm();
}
</script>

</body>
</html>
