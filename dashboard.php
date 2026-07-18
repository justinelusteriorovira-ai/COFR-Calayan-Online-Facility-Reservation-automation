<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit;
}

require_once("config/db.php");

// ─── Basic Statistics ──────────────────────────────────────
$total_facilities = $conn->query("SELECT COUNT(*) as count FROM facilities")->fetch_assoc()['count'];
$available_facilities = $conn->query("SELECT COUNT(*) as count FROM facilities WHERE status = 'AVAILABLE'")->fetch_assoc()['count'];
$total_reservations = $conn->query("SELECT COUNT(*) as count FROM reservations")->fetch_assoc()['count'];
$pending_reservations = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'PENDING'")->fetch_assoc()['count'];
$approved_reservations = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'APPROVED'")->fetch_assoc()['count'];
$rejected_reservations = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'REJECTED'")->fetch_assoc()['count'];
$cancelled_reservations = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'CANCELLED'")->fetch_assoc()['count'];

// ─── Today's date ──────────────────────────────────────────
$today = date('Y-m-d');
$today_reservations = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE reservation_date = '$today' AND status IN ('APPROVED', 'PENDING')")->fetch_assoc()['count'];

// ─── Pending Verification Count ────────────────────────────
$pending_verification = $conn->query("
    SELECT COUNT(*) as count FROM reservations 
    WHERE status IN ('PENDING', 'PENDING_VERIFICATION') 
    AND verification_deadline IS NOT NULL 
    AND verification_deadline > NOW()
")->fetch_assoc()['count'];

// ─── Ongoing Events (happening right now) ──────────────────
$current_time = date('H:i:s');
$ongoing_events = $conn->query("
    SELECT r.*, f.name AS facility_name, f.image AS facility_image
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id
    WHERE r.reservation_date = '$today' 
    AND r.status = 'APPROVED'
    AND r.start_time <= '$current_time' 
    AND r.end_time > '$current_time'
    ORDER BY r.start_time ASC
");

// ─── Upcoming Today (later today, approved) ────────────────
$upcoming_today = $conn->query("
    SELECT r.*, f.name AS facility_name
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id
    WHERE r.reservation_date = '$today' 
    AND r.status = 'APPROVED'
    AND r.start_time > '$current_time'
    ORDER BY r.start_time ASC
    LIMIT 3
");

// ─── Expiring Soon (within 4 hours) ────────────────────────
$expiring_soon_result = $conn->query("
    SELECT r.*, f.name AS facility_name 
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id
    WHERE r.status IN ('PENDING', 'PENDING_VERIFICATION') 
    AND r.verification_deadline IS NOT NULL 
    AND r.verification_deadline > NOW() 
    AND r.verification_deadline < DATE_ADD(NOW(), INTERVAL 4 HOUR)
    ORDER BY r.verification_deadline ASC
    LIMIT 5
");

// ─── Revenue Summary ──────────────────────────────────────
// Get base revenue from reservations
$revenue_total = $conn->query("SELECT COALESCE(SUM(total_cost), 0) as total FROM reservations WHERE status = 'APPROVED'")->fetch_assoc()['total'];
$revenue_month = $conn->query("
    SELECT COALESCE(SUM(total_cost), 0) as total FROM reservations 
    WHERE status = 'APPROVED' AND MONTH(reservation_date) = MONTH(CURDATE()) AND YEAR(reservation_date) = YEAR(CURDATE())
")->fetch_assoc()['total'];
$revenue_today = $conn->query("
    SELECT COALESCE(SUM(total_cost), 0) as total FROM reservations 
    WHERE status = 'APPROVED' AND reservation_date = '$today'
")->fetch_assoc()['total'];

// Get add-ons revenue (include for APPROVED reservations)
$addons_total = $conn->query("
    SELECT COALESCE(SUM(ras.quantity * fa.price), 0) as total
    FROM reservation_addon_selections ras
    JOIN facility_addons fa ON ras.addon_id = fa.id
    JOIN reservations r ON ras.reservation_id = r.id
    WHERE r.status = 'APPROVED'
")->fetch_assoc()['total'];
$addons_month = $conn->query("
    SELECT COALESCE(SUM(ras.quantity * fa.price), 0) as total
    FROM reservation_addon_selections ras
    JOIN facility_addons fa ON ras.addon_id = fa.id
    JOIN reservations r ON ras.reservation_id = r.id
    WHERE r.status = 'APPROVED' AND MONTH(r.reservation_date) = MONTH(CURDATE()) AND YEAR(r.reservation_date) = YEAR(CURDATE())
")->fetch_assoc()['total'];
$addons_today = $conn->query("
    SELECT COALESCE(SUM(ras.quantity * fa.price), 0) as total
    FROM reservation_addon_selections ras
    JOIN facility_addons fa ON ras.addon_id = fa.id
    JOIN reservations r ON ras.reservation_id = r.id
    WHERE r.status = 'APPROVED' AND r.reservation_date = '$today'
")->fetch_assoc()['total'];

// Add add-ons to revenue totals
$revenue_total = $revenue_total + $addons_total;
$revenue_month = $revenue_month + $addons_month;
$revenue_today = $revenue_today + $addons_today;

// ─── Today's Schedule ──────────────────────────────────────
$todays_schedule = $conn->query("
    SELECT r.*, f.name AS facility_name 
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id
    WHERE r.reservation_date = '$today' 
    AND r.status IN ('APPROVED', 'PENDING')
    ORDER BY r.start_time ASC
");

// ─── Occupancy Rate per Facility (this month) ──────────────
$occupancy_result = $conn->query("
    SELECT f.id, f.name, f.open_time, f.close_time,
           COUNT(r.id) as booking_count,
           COALESCE(SUM(r.duration_hours), 0) as booked_hours
    FROM facilities f
    LEFT JOIN reservations r ON f.id = r.facility_id 
        AND r.status = 'APPROVED'
        AND MONTH(r.reservation_date) = MONTH(CURDATE())
        AND YEAR(r.reservation_date) = YEAR(CURDATE())
    GROUP BY f.id
    ORDER BY f.name ASC
");

// Calculate occupancy percentages
$occupancy_data = [];
$days_in_month = date('t');
$days_passed = min(date('j'), $days_in_month);
while ($occ = $occupancy_result->fetch_assoc()) {
    $open = new DateTime($occ['open_time']);
    $close = new DateTime($occ['close_time']);
    $daily_hours = ($close->getTimestamp() - $open->getTimestamp()) / 3600;
    // Approximate weekdays (Mon-Sat = ~26 days/month)
    $total_available_hours = $daily_hours * $days_passed * 0.86; // ~6/7 days
    $percentage = $total_available_hours > 0 ? min(100, round(($occ['booked_hours'] / $total_available_hours) * 100, 1)) : 0;
    $occupancy_data[] = [
        'name' => $occ['name'],
        'booked_hours' => $occ['booked_hours'],
        'bookings' => $occ['booking_count'],
        'percentage' => $percentage
    ];
}

// ─── Recent Reservations ───────────────────────────────────
$recent_reservations = $conn->query("
    SELECT r.*, f.name AS facility_name 
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id 
    ORDER BY r.created_at DESC 
    LIMIT 5
");

// ─── Upcoming Occasions ────────────────────────────────────
$upcoming_occ = $conn->query("
    SELECT * FROM special_occasions 
    WHERE occasion_date >= '$today' 
    ORDER BY occasion_date ASC 
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - CEFI Reservation</title>
    <link rel="stylesheet" href="style/dashboard.css">
    <link rel="stylesheet" href="style/navbar.css?v=2">
    <style>
        /* Phase 2 Dashboard Widgets */
        .dashboard-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .dashboard-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* Alert Widget */
        .alert-widget {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid rgba(1, 60, 16, 0.08);
        }
        .alert-widget h3 {
            font-size: 0.85rem;
            font-weight: 700;
            color: #013c10;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .alert-widget h3 .icon {
            font-size: 1.1rem;
        }
        .alert-widget.urgent {
            border-left: 4px solid #ef4444;
        }
        .alert-widget.warning {
            border-left: 4px solid #f59e0b;
        }
        .alert-widget.info {
            border-left: 4px solid #013c10;
        }

        /* Expiring Item */
        .expiring-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.65rem 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            font-size: 0.83rem;
        }
        .expiring-item:last-child { border-bottom: none; }
        .expiring-item .name { font-weight: 600; color: #1a1a1a; }
        .expiring-item .facility { color: #6b7280; font-size: 0.75rem; }
        .expiring-timer {
            background: #fee2e2;
            color: #dc2626;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            white-space: nowrap;
        }

        /* Revenue Cards */
        .revenue-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid rgba(1, 60, 16, 0.08);
            text-align: center;
        }
        .revenue-card .rev-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7280;
            margin-bottom: 0.35rem;
        }
        .revenue-card .rev-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #013c10;
        }
        .revenue-card .rev-sub {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-top: 0.25rem;
        }

        /* Today's Schedule */
        .schedule-timeline {
            position: relative;
        }
        .schedule-item {
            display: flex;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .schedule-item:last-child { border-bottom: none; }
        .schedule-time {
            font-size: 0.8rem;
            font-weight: 700;
            color: #013c10;
            min-width: 100px;
            white-space: nowrap;
        }
        .schedule-details {
            flex: 1;
        }
        .schedule-details .sched-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: #1a1a1a;
        }
        .schedule-details .sched-facility {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .schedule-status {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
            align-self: center;
        }
        .schedule-status.approved { background: #dcfce7; color: #166534; }
        .schedule-status.pending { background: #fef3c7; color: #d97706; }

        /* Occupancy Bars */
        .occupancy-item {
            margin-bottom: 1rem;
        }
        .occupancy-item:last-child { margin-bottom: 0; }
        .occupancy-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.35rem;
        }
        .occupancy-header .occ-name {
            font-size: 0.83rem;
            font-weight: 600;
            color: #1a1a1a;
        }
        .occupancy-header .occ-percent {
            font-size: 0.83rem;
            font-weight: 700;
            color: #013c10;
        }
        .occupancy-bar {
            height: 10px;
            background: #e5e7eb;
            border-radius: 99px;
            overflow: hidden;
        }
        .occupancy-fill {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, #013c10, #16a34a);
            transition: width 1s ease;
        }
        .occupancy-fill.medium { background: linear-gradient(90deg, #d97706, #f59e0b); }
        .occupancy-fill.high { background: linear-gradient(90deg, #dc2626, #ef4444); }
        .occ-meta {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-top: 0.2rem;
        }

        /* Cancelled stat card */
        .status-card.cancelled {
            border-color: #6b7280;
            color: #6b7280;
        }

        .empty-state {
            text-align: center;
            color: #9ca3af;
            font-size: 0.83rem;
            padding: 1.5rem 0;
            font-style: italic;
        }

        /* Ongoing Events Widget */
        .ongoing-banner {
            background: linear-gradient(135deg, #013c10 0%, #015a18 100%);
            border-radius: 14px;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            color: #ffffff;
            box-shadow: 0 4px 20px rgba(1, 60, 16, 0.25);
        }
        .ongoing-banner h2 {
            color: #fcb900 !important;
            border: none !important;
            margin: 0 0 1rem 0 !important;
            padding: 0 !important;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .ongoing-event-card {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 0.75rem;
            backdrop-filter: blur(4px);
        }
        .ongoing-event-card:last-child { margin-bottom: 0; }
        .ongoing-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        .ongoing-name {
            font-size: 1.05rem;
            font-weight: 700;
        }
        .ongoing-facility {
            font-size: 0.8rem;
            opacity: 0.85;
            margin-top: 0.15rem;
        }
        .ongoing-live-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(22, 163, 74, 0.25);
            border: 1px solid rgba(22, 163, 74, 0.5);
            color: #86efac;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .live-dot {
            width: 8px;
            height: 8px;
            background: #22c55e;
            border-radius: 50%;
            animation: pulse-dot 1.5s ease-in-out infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.3); }
        }
        .ongoing-progress {
            margin-bottom: 0.5rem;
        }
        .ongoing-progress-bar {
            height: 6px;
            background: rgba(255,255,255,0.15);
            border-radius: 99px;
            overflow: hidden;
        }
        .ongoing-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #fcb900, #f59e0b);
            border-radius: 99px;
            transition: width 0.5s ease;
        }
        .ongoing-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            opacity: 0.75;
        }
        .upcoming-next {
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 1px solid rgba(255,255,255,0.12);
        }
        .upcoming-next-title {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255,255,255,0.5);
            margin-bottom: 0.5rem;
        }
        .upcoming-next-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.35rem 0;
            font-size: 0.8rem;
            opacity: 0.8;
        }
        .upcoming-next-time {
            font-weight: 700;
            color: #fcb900;
            min-width: 80px;
        }
        .no-ongoing {
            text-align: center;
            padding: 1rem;
            opacity: 0.7;
            font-size: 0.85rem;
        }

        @media (max-width: 900px) {
            .dashboard-grid-2, .dashboard-grid-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Dashboard</h1>
        <div class="header-user">Staff</div>
    </div>

    <div class="container loading" id="dashboardContainer">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h1 style="margin: 0;">Welcome, Staff</h1>
        <div id="realTimeClock" style="display: flex; align-items: center; gap: 0.6rem; background: #ffffff; padding: 0.6rem 1.2rem; border-radius: 12px; border: 1px solid rgba(1, 60, 16, 0.1); box-shadow: 0 4px 12px rgba(0,0,0,0.05); color: #013c10; font-family: 'Inter', sans-serif;">
            <i class="fas fa-clock" style="color: #fcb900; font-size: 1.2rem; filter: drop-shadow(0 0 2px rgba(252,185,0,0.4));"></i>
            <div>
                <div id="clockTime" style="font-size: 1.15rem; font-weight: 700; line-height: 1.1; letter-spacing: 0.5px;">--:--:--</div>
                <div id="clockDate" style="font-size: 0.7rem; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">---</div>
            </div>
        </div>
    </div>
    <?php if (isset($_GET['msg'])): ?>
        <p class="success"><?= htmlspecialchars($_GET['msg']) ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <p class="error"><?= htmlspecialchars($_GET['error']) ?></p>
    <?php endif; ?>
    
    <!-- ═══════ Top Statistics Cards ═══════ -->
    <div class="stats-grid">
        <a href="facilities/index.php" class="stat-card skeleton-item" style="text-decoration:none;color:inherit;cursor:pointer;">
            <div class="stat-icon facilities-icon"><i class="fas fa-building"></i></div>
            <div class="stat-info">
                <h3><?= $total_facilities ?></h3>
                <p>Total Facilities</p>
            </div>
        </a>
        <a href="facilities/index.php" class="stat-card skeleton-item" style="text-decoration:none;color:inherit;cursor:pointer;">
            <div class="stat-icon available-icon"><i class="fas fa-circle-check"></i></div>
            <div class="stat-info">
                <h3><?= $available_facilities ?></h3>
                <p>Available Facilities</p>
            </div>
        </a>
        <a href="reservations/index.php" class="stat-card skeleton-item" style="text-decoration:none;color:inherit;cursor:pointer;">
            <div class="stat-icon reservations-icon"><i class="fas fa-calendar-days"></i></div>
            <div class="stat-info">
                <h3><?= $total_reservations ?></h3>
                <p>Total Reservations</p>
            </div>
        </a>
        <a href="reservations/index.php?date_from=<?= $today ?>&date_to=<?= $today ?>" class="stat-card skeleton-item" style="text-decoration:none;color:inherit;cursor:pointer;">
            <div class="stat-icon today-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-info">
                <h3><?= $today_reservations ?></h3>
                <p>Today's Reservations</p>
            </div>
        </a>
    </div>
    

    <!-- ═══════ Status Overview ═══════ -->
    <h2>Reservation Status Overview</h2>
    <div class="status-grid" style="grid-template-columns: repeat(4, 1fr);">
        <a href="reservations/index.php?status=PENDING" class="status-card pending skeleton-item" style="text-decoration:none;color:inherit;cursor:pointer;">
            <h3><?= $pending_reservations ?></h3>
            <p>Pending</p>
        </a>
        <a href="reservations/index.php?status=APPROVED" class="status-card approved skeleton-item" style="text-decoration:none;color:inherit;cursor:pointer;">
            <h3><?= $approved_reservations ?></h3>
            <p>Approved</p>
        </a>
        <a href="reservations/index.php?status=REJECTED" class="status-card rejected skeleton-item" style="text-decoration:none;color:inherit;cursor:pointer;">
            <h3><?= $rejected_reservations ?></h3>
            <p>Rejected</p>
        </a>
        <a href="reservations/index.php?status=CANCELLED" class="status-card cancelled skeleton-item" style="text-decoration:none;color:inherit;cursor:pointer;">
            <h3><?= $cancelled_reservations ?></h3>
            <p>Cancelled</p>
        </a>
    </div>

    <!-- ═══════ Ongoing Events Banner ═══════ -->
    <div class="ongoing-banner skeleton-item" style="margin-top: 2rem;">
        <h2><i class="fas fa-circle" style="color:#22c55e;font-size:0.7em;"></i> Ongoing Events</h2>
        <?php if ($ongoing_events->num_rows > 0): ?>
            <?php while($ev = $ongoing_events->fetch_assoc()): 
                $ev_start = new DateTime($ev['start_time']);
                $ev_end = new DateTime($ev['end_time']);
                $ev_now = new DateTime($current_time);
                $total_seconds = $ev_end->getTimestamp() - $ev_start->getTimestamp();
                $elapsed_seconds = $ev_now->getTimestamp() - $ev_start->getTimestamp();
                $progress_pct = min(100, round(($elapsed_seconds / $total_seconds) * 100));
                $remaining_min = round(($total_seconds - $elapsed_seconds) / 60);
                $start_f = date('g:i A', strtotime($ev['start_time']));
                $end_f = date('g:i A', strtotime($ev['end_time']));
            ?>
            <div class="ongoing-event-card">
                <div class="ongoing-top">
                    <div>
                        <div class="ongoing-name"><?= htmlspecialchars($ev['fb_name']) ?></div>
                        <div class="ongoing-facility"><i class="fas fa-location-dot"></i> <?= htmlspecialchars($ev['facility_name']) ?> · <?= htmlspecialchars($ev['purpose']) ?></div>
                    </div>
                    <span class="ongoing-live-badge"><span class="live-dot"></span> Live Now</span>
                </div>
                <div class="ongoing-progress">
                    <div class="ongoing-progress-bar">
                        <div class="ongoing-progress-fill" style="width: <?= $progress_pct ?>%;"></div>
                    </div>
                </div>
                <div class="ongoing-meta">
                    <span><?= $start_f ?> — <?= $end_f ?></span>
                    <span><?= $remaining_min ?> min remaining</span>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-ongoing">No events happening right now</div>
        <?php endif; ?>

        <?php if ($upcoming_today->num_rows > 0): ?>
        <div class="upcoming-next">
            <div class="upcoming-next-title"><i class="fas fa-forward"></i> Coming Up Today</div>
            <?php while($up = $upcoming_today->fetch_assoc()): ?>
            <div class="upcoming-next-item">
                <span class="upcoming-next-time"><?= date('g:i A', strtotime($up['start_time'])) ?></span>
                <span><?= htmlspecialchars($up['fb_name']) ?> · <?= htmlspecialchars($up['facility_name']) ?></span>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════ Revenue Summary ═══════ -->
    <h2><i class="fas fa-coins" style="color:#f59e0b;"></i> Revenue Summary</h2>
    <div class="dashboard-grid-3">
        <div class="revenue-card skeleton-item">
            <div class="rev-label">Today's Revenue</div>
            <div class="rev-amount">₱<?= number_format($revenue_today, 2) ?></div>
            <div class="rev-sub"><?= date('F j, Y') ?></div>
        </div>
        <div class="revenue-card skeleton-item">
            <div class="rev-label">This Month</div>
            <div class="rev-amount">₱<?= number_format($revenue_month, 2) ?></div>
            <div class="rev-sub"><?= date('F Y') ?></div>
        </div>
        <div class="revenue-card skeleton-item">
            <div class="rev-label">Total Revenue</div>
            <div class="rev-amount">₱<?= number_format($revenue_total, 2) ?></div>
            <div class="rev-sub">All time (approved)</div>
        </div>
    </div>

    <!-- ═══════ Alerts Row: Pending Verification + Expiring Soon ═══════ -->
    <h2><i class="fas fa-bolt" style="color:#f59e0b;"></i> Alerts & Notifications</h2>
    <div class="dashboard-grid-2">
        <!-- Pending Verification -->
        <div class="alert-widget warning">
            <h3><span class="icon"><i class="fas fa-hourglass-half"></i></span> Pending Verification (<?= $pending_verification ?>)</h3>
            <?php if ($pending_verification > 0): ?>
                <p style="font-size:0.83rem; color:#6b7280; margin-bottom:0.5rem;">
                    <?= $pending_verification ?> reservation<?= $pending_verification != 1 ? 's' : '' ?> awaiting user verification within 24 hours.
                </p>
                <a href="reservations/index.php" style="font-size:0.8rem; color:#013c10; font-weight:600; text-decoration:none;">View All →</a>
            <?php else: ?>
                <div class="empty-state">No pending verifications</div>
            <?php endif; ?>
        </div>

        <!-- Expiring Soon -->
        <div class="alert-widget urgent">
            <h3><span class="icon"><i class="fas fa-clock" style="color:#dc2626;"></i></span> Expiring Soon</h3>
            <?php if ($expiring_soon_result->num_rows > 0): ?>
                <?php while($exp = $expiring_soon_result->fetch_assoc()): 
                    $dl = new DateTime($exp['verification_deadline']);
                    $now = new DateTime();
                    $diff = $now->diff($dl);
                    $remaining = $diff->h . 'h ' . $diff->i . 'm';
                ?>
                <div class="expiring-item">
                    <div>
                        <div class="name"><?= htmlspecialchars($exp['fb_name']) ?></div>
                        <div class="facility"><?= htmlspecialchars($exp['facility_name']) ?> — <?= $exp['reservation_date'] ?></div>
                    </div>
                    <div class="expiring-timer"><i class="fas fa-stopwatch"></i> <?= $remaining ?> left</div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">No reservations expiring soon</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══════ Today's Schedule + Occupancy ═══════ -->
    <div class="dashboard-grid-2">
        <!-- Today's Schedule -->
        <div class="alert-widget info">
            <h3><span class="icon"><i class="fas fa-clipboard-list"></i></span> Today's Schedule (<?= date('M d') ?>)</h3>
            <?php if ($todays_schedule->num_rows > 0): ?>
                <div class="schedule-timeline">
                    <?php while($sched = $todays_schedule->fetch_assoc()): 
                        $stime = date('g:i A', strtotime($sched['start_time']));
                        $etime = date('g:i A', strtotime($sched['end_time']));
                        $st_class = strtolower($sched['status']);
                    ?>
                    <div class="schedule-item">
                        <div class="schedule-time"><?= $stime ?></div>
                        <div class="schedule-details">
                            <div class="sched-name"><?= htmlspecialchars($sched['fb_name']) ?></div>
                            <div class="sched-facility"><?= htmlspecialchars($sched['facility_name']) ?> · <?= $stime ?> – <?= $etime ?></div>
                        </div>
                        <span class="schedule-status <?= $st_class ?>"><?= $sched['status'] ?></span>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No reservations scheduled for today</div>
            <?php endif; ?>
        </div>

        <!-- Occupancy Rate -->
        <div class="alert-widget info">
            <h3><span class="icon"><i class="fas fa-chart-bar"></i></span> Facility Occupancy (<?= date('F') ?>)</h3>
            <?php if (count($occupancy_data) > 0): ?>
                <?php foreach($occupancy_data as $occ): 
                    $fill_class = '';
                    if ($occ['percentage'] > 75) $fill_class = 'high';
                    elseif ($occ['percentage'] > 40) $fill_class = 'medium';
                ?>
                <div class="occupancy-item">
                    <div class="occupancy-header">
                        <span class="occ-name"><?= htmlspecialchars($occ['name']) ?></span>
                        <span class="occ-percent"><?= $occ['percentage'] ?>%</span>
                    </div>
                    <div class="occupancy-bar">
                        <div class="occupancy-fill <?= $fill_class ?>" style="width: <?= $occ['percentage'] ?>%;"></div>
                    </div>
                    <div class="occ-meta"><?= $occ['bookings'] ?> booking<?= $occ['bookings'] != 1 ? 's' : '' ?> · <?= $occ['booked_hours'] ?> hrs booked</div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">No facilities found</div>
            <?php endif; ?>
        </div>
    </div>
    

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Remove skeleton loading after a short delay to simulate content loading
            setTimeout(() => {
                document.getElementById('dashboardContainer').classList.remove('loading');
                const skeletons = document.querySelectorAll('.skeleton-item');
                skeletons.forEach(el => el.classList.remove('skeleton-item'));
            }, 800);
        });
    </script>

    <!-- ═══════ Upcoming Occasions ═══════ -->
    <?php if ($upcoming_occ->num_rows > 0): ?>
    <div class="upcoming-occasions">
        <h2>Upcoming Special Occasions</h2>
        <div class="occasions-grid">
            <?php while($occ = $upcoming_occ->fetch_assoc()): 
                $d = date('M d', strtotime($occ['occasion_date']));
            ?>
            <div class="occasion-card" style="border-left-color: <?= $occ['color'] ?>;">
                <div class="occasion-date"><?= $d ?></div>
                <div class="occasion-title"><?= htmlspecialchars($occ['title']) ?></div>
                <div class="occasion-type"><?= str_replace('_', ' ', $occ['type']) ?></div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ═══════ Recent Reservations ═══════ -->
    <h2>Recent Reservations</h2>
    <?php if ($recent_reservations->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Facility</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $recent_reservations->fetch_assoc()): ?>
                <tr>
                    <td class="skeleton-item"><?= htmlspecialchars($row['fb_name']) ?></td>
                    <td class="skeleton-item"><?= htmlspecialchars($row['facility_name']) ?></td>
                    <td class="skeleton-item"><?= $row['reservation_date'] ?></td>
                    <td class="skeleton-item"><?= substr($row['start_time'],0,5) ?> - <?= substr($row['end_time'],0,5) ?></td>
                    <td class="skeleton-item">
                        <?php if ($row['total_cost'] > 0): ?>
                            <span style="font-weight:600;color:#013c10;">₱<?= number_format($row['total_cost'], 2) ?></span>
                        <?php else: ?>
                            <span style="color:#9ca3af;">Free</span>
                        <?php endif; ?>
                    </td>
                    <td class="skeleton-item">
                        <?php
                            $status_class = 'status-' . strtolower($row['status']);
                            echo "<span class=\"$status_class\">{$row['status']}</span>";
                        ?>
                    </td>
                    <td class="skeleton-item">
                        <a href="reservations/edit.php?id=<?= $row['id'] ?>" class="edit-link">Edit</a>
                        <a href="reservations/print.php?id=<?= $row['id'] ?>" class="edit-link" target="_blank" style="background:#fef3c7; color:#d97706;">Print</a>
                        <a href="javascript:void(0)" class="delete-link" onclick="showDeleteModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['fb_name'], ENT_QUOTES) ?>')">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
    <?php elseif ($recent_reservations->num_rows == 0): ?>
        <p class="no-data">No reservations found. <a href="reservations/create.php">Create one now</a>.</p>
    <?php endif; ?>
    
    <div class="view-all">
        <a href="reservations/index.php" class="view-all-btn">View All Reservations →</a>
    </div>
    </div>
</div>

<div class="footer">
    © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc., Philippines | Contact: info@cefi.website
</div>


<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:0;pointer-events:none;transition:opacity 0.3s;">
    <div style="background:#fff;border-radius:16px;padding:2rem;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.15);text-align:center;">
        <h3 style="color:#991b1b;margin-bottom:0.5rem;font-size:1.25rem;"><i class="fas fa-triangle-exclamation"></i> Delete Reservation</h3>
        <p id="deleteModalText" style="color:#6b7280;margin-bottom:1.5rem;font-size:0.9rem;">Are you sure?</p>
        <div style="display:flex;gap:0.75rem;justify-content:center;">
            <button onclick="closeDeleteModal()" style="padding:0.6rem 1.25rem;border-radius:8px;border:1px solid #d1d5db;background:#f3f4f6;color:#374151;font-weight:600;cursor:pointer;">Cancel</button>
            <a id="deleteConfirmBtn" href="#" style="padding:0.6rem 1.25rem;border-radius:8px;border:none;background:#dc2626;color:#fff;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;">Delete</a>
        </div>
    </div>
</div>

<script>
function showDeleteModal(id, name) {
    const modal = document.getElementById('deleteModal');
    document.getElementById('deleteModalText').innerHTML = 'Are you sure you want to permanently delete the reservation by <strong>"' + name + '"</strong>?';
    document.getElementById('deleteConfirmBtn').href = 'reservations/delete.php?id=' + id;
    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
}
function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.style.opacity = '0';
    modal.style.pointerEvents = 'none';
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// Animate occupancy bars on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.occupancy-fill').forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => { bar.style.width = width; }, 300);
    });
});

// Real-time Clock Logic
function updateClock() {
    const clockTimeElem = document.getElementById('clockTime');
    const clockDateElem = document.getElementById('clockDate');
    
    if (clockTimeElem && clockDateElem) {
        const now = new Date();
        const timeDisplay = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
        const dateDisplay = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        
        clockTimeElem.textContent = timeDisplay;
        clockDateElem.textContent = dateDisplay;
    }
}
// Run once on load then every 1s
updateClock();
setInterval(updateClock, 1000);
</script>

</body>
</html>
