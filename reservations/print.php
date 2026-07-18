<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");

// Check if ID is provided
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: index.php");
    exit;
}

$id = (int)$_GET["id"];

// Fetch the reservation with full facility details
$stmt = $conn->prepare("
    SELECT r.*, f.name AS facility_name, f.description AS facility_description, 
           f.capacity AS facility_capacity, f.price_per_hour, f.open_time, f.close_time,
           f.image AS facility_image
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

// Check if reservation is approved
if ($reservation["status"] != "APPROVED") {
    header("Location: index.php?error=Only approved reservations can be printed.");
    exit;
}

// Format date and time
$formatted_date = date("F j, Y", strtotime($reservation["reservation_date"]));
$formatted_created_at = date("F j, Y h:i A", strtotime($reservation["created_at"]));
$day_of_week = date("l", strtotime($reservation["reservation_date"]));

// Calculate duration if not stored
$duration = $reservation['duration_hours'];
if (!$duration && $reservation['start_time'] && $reservation['end_time']) {
    $startDT = new DateTime($reservation['start_time']);
    $endDT = new DateTime($reservation['end_time']);
    $duration = round(($endDT->getTimestamp() - $startDT->getTimestamp()) / 3600, 1);
}

$total_cost = $reservation['total_cost'] ?: ($duration * ($reservation['price_per_hour'] ?? 0));

// Format times
$start_formatted = date("g:i A", strtotime($reservation['start_time']));
$end_formatted = date("g:i A", strtotime($reservation['end_time']));

// Document number
$doc_number = 'RES-' . str_pad($reservation["id"], 6, '0', STR_PAD_LEFT);

// Fetch addon selections from new tables
$addons = [];
$addonCost = 0;
try {
    $addonStmt = $conn->prepare("
        SELECT ras.quantity, fa.addon_name, fa.price 
        FROM reservation_addon_selections ras 
        JOIN facility_addons fa ON ras.addon_id = fa.id 
        WHERE ras.reservation_id = ?
    ");
    if ($addonStmt) {
        $addonStmt->bind_param("i", $id);
        $addonStmt->execute();
        $addonResult = $addonStmt->get_result();
        while ($addonRow = $addonResult->fetch_assoc()) {
            $addons[] = $addonRow;
            $addonCost += ($addonRow['quantity'] * $addonRow['price']);
        }
        $addonStmt->close();
    }
} catch (Exception $e) {
    // Table may not exist yet - ignore and use legacy data
    $addons = [];
    $addonCost = 0;
}

// Legacy: Decode Reservation Needs (for old data)
$needs = json_decode($reservation['reservation_needs'] ?? '[]', true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Slip - <?= $doc_number ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IM+Fell+English&family=Source+Serif+4:ital,wght@0,300;0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <style>
        /* ===== Print & Template Styles ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: #d6cfc4;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 40px 16px;
            font-family: 'Source Serif 4', Georgia, serif;
            color: #1a1a1a;
        }

        .page {
            background: #faf8f4;
            width: 794px; /* A4 width in pixels approx */
            min-height: 1123px; /* A4 height */
            padding: 50px 60px;
            border: 1px solid #bbb;
            box-shadow: 0 4px 32px rgba(0,0,0,0.18);
            position: relative;
            margin-bottom: 2rem;
        }

        /* Subtle paper texture */
        .page::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3CfeColorMatrix type='saturate' values='0'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            opacity: 0.5;
        }

        /* ── Header ── */
        .header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 10px;
        }

        .header img {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }

        .header-text {
            text-align: center;
            flex: 1;
            line-height: 1.4;
        }

        .header-text .school-name {
            font-family: 'IM Fell English', serif;
            font-size: 20px;
            font-weight: 400;
            color: #1a1a1a;
        }

        .header-text .school-address {
            font-size: 13px;
            color: #444;
        }

        .divider {
            border: none;
            border-top: 2px solid #1a1a1a;
            margin: 15px 0 10px;
        }

        .form-title {
            text-align: center;
            font-family: 'IM Fell English', serif;
            font-size: 24px;
            letter-spacing: 0.06em;
            font-weight: 400;
            color: #111;
            margin: 10px 0 25px;
            text-transform: uppercase;
        }

        /* ── Field rows ── */
        .field-row {
            display: flex;
            align-items: flex-end;
            gap: 15px;
            margin-bottom: 12px;
        }

        .field-group {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            flex: 1;
        }

        .field-label {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a1a;
            white-space: nowrap;
        }

        .field-value {
            flex: 1;
            border-bottom: 1px solid #333;
            font-size: 14px;
            color: #222;
            padding: 0 5px 2px;
            min-height: 20px;
        }

        /* ── Reservation Needs Table ── */
        .needs-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            font-size: 13px;
        }

        .needs-table th {
            background: #1a1a1a;
            color: #faf8f4;
            font-weight: 600;
            padding: 8px;
            text-align: center;
            border: 1px solid #1a1a1a;
        }

        .needs-table td {
            border: 1px solid #aaa;
            padding: 6px 10px;
            vertical-align: middle;
        }

        .total-row td {
            font-weight: 700;
            background: #f0ece4;
        }

        /* ── Signature section ── */
        .sig-section {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            gap: 30px;
        }

        .sig-block {
            flex: 1;
            text-align: center;
        }

        .sig-line {
            border-top: 1px solid #333;
            margin-top: 35px;
            width: 100%;
            padding-top: 5px;
        }

        .sig-name {
            font-weight: 600;
            font-size: 13px;
        }

        .sig-desc {
            font-size: 11px;
            color: #555;
            font-style: italic;
        }

        .no-print {
            margin-top: 20px;
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 10px 25px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }

        .btn-print { background: #013c10; color: white; }
        .btn-back { background: #666; color: white; }

        @media print {
            body { background: white; padding: 0; }
            .page { box-shadow: none; border: none; margin: 0; width: 100%; }
            .no-print { display: none; }
            @page { margin: 0; size: auto; }
        }
    </style>
</head>
<body>

<div class="page">
    <!-- Header -->
    <div class="header">
        <img src="https://enrollment.cefi.website/images/cefi-logo.png" alt="CEFI Logo">
        <div class="header-text">
            <div class="school-name">Calayan Educational Foundation, Inc.</div>
            <div class="school-address">Maharlika Highway, Red-V, Lucena City</div>
            <div class="school-address">Tel., No. (042) 710-25-14</div>
        </div>
    </div>

    <hr class="divider">
    <div class="form-title">Reservation Confirmation Slip</div>

    <!-- Row 1 -->
    <div class="field-row">
        <div class="field-group">
            <span class="field-label">Requester Name:</span>
            <div class="field-value"><?= htmlspecialchars($reservation["fb_name"]) ?></div>
        </div>
        <div class="field-group" style="flex: 0 0 200px;">
            <span class="field-label">Reservation No.:</span>
            <div class="field-value"><?= $doc_number ?></div>
        </div>
    </div>

    <!-- Row 2 -->
    <div class="field-row">
        <div class="field-group">
            <span class="field-label">Facility / Place:</span>
            <div class="field-value"><?= htmlspecialchars($reservation["facility_name"]) ?></div>
        </div>
        <div class="field-group" style="flex: 0 0 200px;">
            <span class="field-label">Date Filed:</span>
            <div class="field-value"><?= date("M d, Y", strtotime($reservation["created_at"])) ?></div>
        </div>
    </div>

    <!-- Row 3 -->
    <div class="field-row">
        <div class="field-group">
            <span class="field-label">Reservation Date:</span>
            <div class="field-value"><?= $formatted_date ?> (<?= $day_of_week ?>)</div>
        </div>
        <div class="field-group">
            <span class="field-label">Schedule:</span>
            <div class="field-value"><?= $start_formatted ?> – <?= $end_formatted ?></div>
        </div>
    </div>

    <!-- Row 4 -->
    <div class="field-row">
        <div class="field-group">
            <span class="field-label">Purpose of Reservation:</span>
            <div class="field-value"><?= htmlspecialchars($reservation["purpose"]) ?></div>
        </div>
    </div>

    <!-- Row 5 (Remarks) -->
    <div class="field-row">
        <div class="field-group">
            <span class="field-label">Remarks / Notes:</span>
            <div class="field-value"><?= htmlspecialchars($reservation["remarks"] ?: 'None') ?></div>
        </div>
    </div>

    <!-- Needs Table (now displays Add-ons) -->
    <table class="needs-table">
        <thead>
            <tr>
                <th style="width: 45%; text-align: left;">Reservation Needs</th>
                <th style="width: 10%;">Qty.</th>
                <th style="width: 30%;">Particulars</th>
                <th style="width: 15%;">Cost (₱)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            // Use new add-ons table if available, otherwise fallback to legacy
            if (!empty($addons)) {
                $needs_total = 0;
                foreach ($addons as $item) {
                    $item_cost = (float)($item['quantity'] * $item['price']);
                    $needs_total += $item_cost;
                    echo "<tr>
                        <td>- " . htmlspecialchars($item['addon_name']) . "</td>
                        <td style='text-align:center;'>" . htmlspecialchars($item['quantity']) . "</td>
                        <td>" . htmlspecialchars($item['price']) . " per unit</td>
                        <td style='text-align:right;'>" . number_format($item_cost, 2) . "</td>
                    </tr>";
                }
                echo "<tr class='total-row'>
                    <td colspan='3' style='text-align:right;'>ADD-ONS TOTAL:</td>
                    <td style='text-align:right;'>₱" . number_format($needs_total, 2) . "</td>
                </tr>";
            } elseif (!empty($needs)) {
                // Legacy support for old reservation_needs data
                $needs_total = 0;
                foreach ($needs as $item) {
                    $item_cost = (float)($item['cost'] ?? 0);
                    $needs_total += $item_cost;
                    echo "<tr>
                        <td>- " . htmlspecialchars($item['name']) . "</td>
                        <td style='text-align:center;'>" . htmlspecialchars($item['qty']) . "</td>
                        <td>" . htmlspecialchars($item['particulars']) . "</td>
                        <td style='text-align:right;'>" . number_format($item_cost, 2) . "</td>
                    </tr>";
                }
                echo "<tr class='total-row'>
                    <td colspan='3' style='text-align:right;'>ADDITIONAL NEEDS TOTAL:</td>
                    <td style='text-align:right;'>₱" . number_format($needs_total, 2) . "</td>
                </tr>";
            } else {
                echo '<tr><td colspan="4" style="text-align:center; color:#888; font-style:italic;">No additional equipment or personnel requested.</td></tr>';
                $needs_total = 0;
            }
            ?>
            <tr class="total-row" style="background: #e5e7eb;">
                <td colspan="3" style="text-align:right; font-size: 15px;">TOTAL ESTIMATED RESERVATION COST:</td>
                <td style="text-align:right; font-size: 15px;">₱<?= number_format($total_cost + $needs_total, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- Signatures -->
    <div class="sig-section">
        <div class="sig-block">
            <div class="sig-label">Requested by:</div>
            <div class="sig-line"></div>
            <div class="sig-name"><?= htmlspecialchars($reservation["fb_name"]) ?></div>
            <div class="sig-desc">Signature over Printed Name</div>
        </div>
        <div class="sig-block">
            <div class="sig-label">Noted by:</div>
            <div class="sig-line"></div>
            <div class="sig-name">Immediate Head / Supervisor</div>
            <div class="sig-desc">Department Office</div>
        </div>
    </div>

    <div style="text-align: center; margin-top: 40px;">
        <div style="font-size: 13px; font-weight: 600;">Approved by:</div>
        <div style="border-top: 1px solid #333; width: 250px; margin: 35px auto 5px;"></div>
        <div style="font-weight: 600; font-size: 13px;">Administrative Officer</div>
        <div style="font-size: 11px; color: #555; font-style: italic;">Calayan Educational Foundation, Inc.</div>
    </div>

</div>

<div class="no-print">
    <a href="index.php" class="btn btn-back">← Back to List</a>
    <button onclick="window.print()" class="btn btn-print"><i class="fas fa-print"></i> Print Confirmation Slip</button>
</div>

</body>
</html>
