<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");
require_once("../config/csrf.php");

$result = $conn->query("SELECT f.*, 
    (SELECT COUNT(*) FROM reservations WHERE facility_id = f.id) as reservation_count
    FROM facilities f ORDER BY f.id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facilities - CEFI Reservation</title>
    <link rel="stylesheet" href="../style/facilities.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
    <style>
        .action-links { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .action-links a {
            text-decoration: none;
            font-weight: 500;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            transition: all 0.2s ease;
            font-size: 0.8rem;
            display: inline-block;
            cursor: pointer;
        }
        .action-links .edit-btn {
            background: rgba(1, 60, 16, 0.08);
            color: #013c10;
        }
        .action-links .edit-btn:hover { background: rgba(1, 60, 16, 0.15); }
        .action-links .delete-btn {
            background: #fee2e2;
            color: #b91c1c;
        }
        .action-links .delete-btn:hover { background: #fecaca; }
        
        /* Delete confirmation modal */
        .delete-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.4); backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999; opacity: 0; pointer-events: none; transition: opacity 0.3s;
        }
        .delete-modal-overlay.open { opacity: 1; pointer-events: auto; }
        .delete-modal {
            background: #fff; border-radius: 16px; padding: 2rem; max-width: 420px; width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15); text-align: center;
            transform: translateY(20px); transition: transform 0.3s;
        }
        .delete-modal-overlay.open .delete-modal { transform: translateY(0); }
        .delete-modal h3 { color: #991b1b; margin-bottom: 0.5rem; font-size: 1.25rem; }
        .delete-modal p { color: #6b7280; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .delete-modal .modal-actions { display: flex; gap: 0.75rem; justify-content: center; }
        .delete-modal .btn-cancel {
            padding: 0.6rem 1.25rem; border-radius: 8px; border: 1px solid #d1d5db;
            background: #f3f4f6; color: #374151; font-weight: 600; cursor: pointer; font-size: 0.9rem;
        }
        .delete-modal .btn-confirm-delete {
            padding: 0.6rem 1.25rem; border-radius: 8px; border: none;
            background: #dc2626; color: #fff; font-weight: 700; cursor: pointer; font-size: 0.9rem;
        }
        .delete-modal .btn-confirm-delete:hover { background: #b91c1c; }
        .facility-info-row { font-size: 0.75rem; color: #6b7280; display: block; margin-top: 0.15rem; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Facilities</h1>
        <div class="header-user"><?= ucfirst($user_role) ?></div>
    </div>

    <div class="container">
    <h2>Facilities</h2>
    <?php if ($user_role !== 'admin'): ?>
        <a href="create.php" class="add-btn">+ Add Facility</a>
    <?php endif; ?>
    
    <?php if (isset($_GET['msg'])): ?>
        <p class="success"><?= htmlspecialchars($_GET['msg']) ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <p class="error"><?= htmlspecialchars($_GET['error']) ?></p>
    <?php endif; ?>
    
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Capacity</th>
                <th>Rate/hr</th>
                <th>Hours</th>
                <th>Status</th>
                <?php if ($user_role !== 'admin'): ?>
                    <th>Action</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td>
                    <?= htmlspecialchars($row['name']) ?>
                    <?php if ($row['description']): ?>
                        <span class="facility-info-row"><?= htmlspecialchars(substr($row['description'], 0, 50)) ?><?= strlen($row['description']) > 50 ? '...' : '' ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $row['capacity'] ?></td>
                <td><?= $row['price_per_hour'] > 0 ? '₱' . number_format($row['price_per_hour'], 2) : 'Free' ?></td>
                <td><?= substr($row['open_time'],0,5) ?> – <?= substr($row['close_time'],0,5) ?></td>
                <td>
                    <?php 
                        $status_class = 'status-' . strtolower($row['status']);
                        echo "<span class=\"$status_class\">{$row['status']}</span>";
                    ?>
                </td>
                <?php if ($user_role !== 'admin'): ?>
                    <td>
                        <div class="action-links">
                            <a href="edit.php?id=<?= $row['id'] ?>" class="edit-btn">Edit</a>
                            <a href="javascript:void(0)" class="delete-btn" 
                               data-id="<?= $row['id'] ?>" 
                               data-name="<?= htmlspecialchars($row['name']) ?>"
                               data-reservations="<?= $row['reservation_count'] ?>"
                               onclick="showDeleteModal(this)">Delete</a>
                        </div>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="delete-modal-overlay" id="deleteModal">
    <div class="delete-modal">
        <h3><i class="fas fa-triangle-exclamation"></i> Delete Facility</h3>
        <p id="deleteModalText">Are you sure?</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <form id="deleteForm" method="POST" action="delete.php" style="display:inline;">
                <?php csrfField(); ?>
                <input type="hidden" name="id" id="deleteIdInput" value="">
                <button type="submit" id="deleteConfirmBtn" class="btn-confirm-delete">Delete</button>
            </form>
        </div>
    </div>
</div>

<div class="footer">
    © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc., Philippines | Contact: info@cefi.website
</div>

<script>
function showDeleteModal(el) {
    const id = el.dataset.id;
    const name = el.dataset.name;
    const reservations = parseInt(el.dataset.reservations);
    
    const modal = document.getElementById('deleteModal');
    const text = document.getElementById('deleteModalText');
    const btn = document.getElementById('deleteConfirmBtn');
    
    if (reservations > 0) {
        text.innerHTML = '<strong>"' + name + '"</strong> has <strong>' + reservations + ' reservation(s)</strong>. You must delete all reservations first before deleting this facility.';
        document.getElementById('deleteForm').style.display = 'none';
    } else {
        text.innerHTML = 'Are you sure you want to permanently delete <strong>"' + name + '"</strong>? This action cannot be undone.';
        document.getElementById('deleteForm').style.display = 'inline';
        document.getElementById('deleteIdInput').value = id;
    }
    
    modal.classList.add('open');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
}

// Close on overlay click
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
</script>

</body>
</html>
