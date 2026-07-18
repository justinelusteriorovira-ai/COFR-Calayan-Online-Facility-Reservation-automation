<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");

// Fetch all occasions
$result = $conn->query("SELECT * FROM special_occasions ORDER BY occasion_date DESC");
$occasions = [];
while ($row = $result->fetch_assoc()) {
    $occasions[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Occasions - CEFI Reservation</title>
    <link rel="stylesheet" href="../style/calendar.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
    <link rel="stylesheet" href="../style/occasions.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Manage Occasions</h1>
        <div class="header-user">Admin</div>
    </div>

    <div class="container">
    <div class="header-row">
        <h1><i class="fas fa-star"></i> Special Occasions</h1>
        <button class="add-btn" id="open-add-modal"><span>+</span> Add New Occasion</button>
    </div>

    <div class="occasions-list">
        <?php if (empty($occasions)): ?>
            <div class="no-data">
                <div style="font-size: 3rem; margin-bottom: 10px;"><i class="fas fa-calendar-days"></i></div>
                <p>No special occasions found. Add one to mark holidays or school events.</p>
            </div>
        <?php else: ?>
            <?php foreach ($occasions as $occ): 
                $date = new DateTime($occ['occasion_date']);
                $type_class = 'type-' . strtolower($occ['type']);
            ?>
                <div class="occasion-card" id="occ-<?= $occ['id'] ?>">
                    <div class="occ-info">
                        <div class="occ-date-box">
                            <span class="occ-day"><?= $date->format('d') ?></span>
                            <span class="occ-month"><?= $date->format('M Y') ?></span>
                        </div>
                        <div class="occ-details">
                            <h3><?= htmlspecialchars($occ['title']) ?></h3>
                            <span class="occ-type <?= $type_class ?>"><?= str_replace('_', ' ', $occ['type']) ?></span>
                            <?php if ($occ['end_date']): ?>
                                <span style="font-size: 0.8rem; color: #7f8c8d; margin-left: 10px;">
                                    Until <?= date('M d, Y', strtotime($occ['end_date'])) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($occ['is_recurring']): ?>
                                <span style="font-size: 0.8rem; color: #f39c12; margin-left: 10px;"><i class="fas fa-rotate"></i> Yearly Recurring</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="occ-actions">
                        <button class="btn-icon edit-occ" data-occ="<?= htmlspecialchars(json_encode($occ), ENT_QUOTES, 'UTF-8') ?>" title="Edit"><i class="fas fa-pen"></i></button>
                        <button class="btn-icon btn-delete delete-occ" data-id="<?= $occ['id'] ?>" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="occ-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modal-title">Add Special Occasion</h3>
            <button class="modal-close" id="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <form id="occ-form" class="modal-form">
                <input type="hidden" name="id" id="form-id">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" id="form-title" required placeholder="e.g., Graduation Day">
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="occasion_date" id="form-date" required>
                    </div>
                    <div class="form-group">
                        <label>End Date (Optional)</label>
                        <input type="date" name="end_date" id="form-end-date">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" id="form-type">
                            <option value="SCHOOL_EVENT">School Event</option>
                            <option value="HOLIDAY">Holiday</option>
                            <option value="BLOCKED">Blocked</option>
                            <option value="ANNOUNCEMENT">Announcement</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <input type="color" name="color" id="form-color" value="#8e44ad">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="form-desc" rows="3"></textarea>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                    <input type="checkbox" name="is_recurring" id="form-recurring">
                    <label for="form-recurring" style="font-size: 0.85rem; cursor: pointer;">Yearly Recurring</label>
                </div>
                <button type="submit" class="submit-btn" id="form-submit">Save Occasion</button>
            </form>
        </div>
    </div>
</div>
</div>

<div class="footer">
    © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc., Philippines
</div>

<script>
const modal = document.getElementById('occ-modal');
const form = document.getElementById('occ-form');
const modalTitle = document.getElementById('modal-title');

document.getElementById('open-add-modal').onclick = () => {
    form.reset();
    document.getElementById('form-id').value = '';
    modalTitle.textContent = 'Add Special Occasion';
    modal.classList.add('open');
};

document.getElementById('close-modal').onclick = () => modal.classList.remove('open');

// Edit
document.querySelectorAll('.edit-occ').forEach(btn => {
    btn.onclick = () => {
        const occ = JSON.parse(btn.dataset.occ);
        document.getElementById('form-id').value = occ.id;
        document.getElementById('form-title').value = occ.title;
        document.getElementById('form-date').value = occ.occasion_date;
        document.getElementById('form-end-date').value = occ.end_date || '';
        document.getElementById('form-type').value = occ.type;
        document.getElementById('form-color').value = occ.color;
        document.getElementById('form-desc').value = occ.description;
        document.getElementById('form-recurring').checked = occ.is_recurring == 1;
        
        modalTitle.textContent = 'Edit Special Occasion';
        modal.classList.add('open');
    }
});

// Delete
document.querySelectorAll('.delete-occ').forEach(btn => {
    btn.onclick = async () => {
        if (!confirm('Are you sure you want to delete this occasion?')) return;
        const id = btn.dataset.id;
        const resp = await fetch('../api/delete_special_occasion.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `id=${id}`
        });
        const data = await resp.json();
        if (data.success) location.reload();
        else alert(data.message || 'Delete failed');
    }
});

// Submit
form.onsubmit = async (e) => {
    e.preventDefault();
    const id = document.getElementById('form-id').value;
    const url = id ? '../api/edit_special_occasion.php' : '../api/add_special_occasion.php';
    
    const formData = new FormData(form);
    const resp = await fetch(url, {
        method: 'POST',
        body: new URLSearchParams(formData)
    });
    const data = await resp.json();
    if (data.success) location.reload();
    else alert(data.message || 'Save failed');
};
</script>

</body>
</html>
