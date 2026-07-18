<!-- includes/quick_sidebar.php -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="quick-actions-sidebar" id="quickSidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-bars-staggered"></i> Navigation Menus</h2>
        <button class="close-sidebar-btn" id="closeSidebarBtn" title="Close Panel">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="sidebar-content">
        <?php if ($user_role !== 'admin'): ?>
        <div class="sidebar-section-title">New Entry</div>
        <div class="sidebar-actions-list">
            <a href="<?= $base_path ?>reservations/walkin_create.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-person-walking"></i></div>
                <span class="action-text">Walk-in Reservation</span>
            </a>
            <a href="<?= $base_path ?>reservations/create.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-pen-to-square"></i></div>
                <span class="action-text">Online Reservation</span>
            </a>
            <a href="<?= $base_path ?>facilities/create.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-plus"></i></div>
                <span class="action-text">Add Facility</span>
            </a>
        </div>
        <?php endif; ?>

        <div class="sidebar-section-title" style="margin-top: 2rem;">Management</div>
        <div class="sidebar-actions-list">
            <?php if ($user_role !== 'admin'): ?>
            <a href="<?= $base_path ?>reservations/index.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-clipboard-list"></i></div>
                <span class="action-text">Manage Approvals</span>
            </a>
            <?php endif; ?>
            <a href="<?= $base_path ?>facilities/index.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-building"></i></div>
                <span class="action-text">System Facilities</span>
            </a>
            <a href="<?= $base_path ?>calendar/index.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-calendar-alt"></i></div>
                <span class="action-text">Event Calendar</span>
            </a>
            <a href="<?= $base_path ?>calendar/occasions.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-star"></i></div>
                <span class="action-text">Manage Occasions</span>
            </a>
        </div>

        <?php if ($user_role === 'admin'): ?>
        <div class="sidebar-section-title" style="margin-top: 2rem;">Admin Tools</div>
        <div class="sidebar-actions-list">
            <a href="<?= $base_path ?>admin/users.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-users-cog"></i></div>
                <span class="action-text">Staff Management</span>
            </a>
            <a href="<?= $base_path ?>admin/settings.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-cogs"></i></div>
                <span class="action-text">System Settings</span>
            </a>
            <a href="<?= $base_path ?>admin/maintenance.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-tools"></i></div>
                <span class="action-text">Maintenance</span>
            </a>
        </div>
        <?php endif; ?>

        <div class="sidebar-section-title" style="margin-top: 2rem;">System Logs</div>
        <div class="sidebar-actions-list">
            <a href="<?= $base_path ?>reservations/history.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-history"></i></div>
                <span class="action-text">Reservation History</span>
            </a>
            <a href="<?= $base_path ?>audit_trail.php" class="sidebar-action-item">
                <div class="sidebar-icon"><i class="fas fa-file-shield"></i></div>
                <span class="action-text">Audit Trail</span>
            </a>
        </div>
    </div>

    <div class="sidebar-footer">
        <label class="status-indicator">
            <span class="status-dot"></span> System Live
        </label>
        <p>CEFI Facility Reservation v2.1</p>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        const closeBtn = document.getElementById('closeSidebarBtn');
        const sidebar = document.getElementById('quickSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            if (sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
        if (closeBtn) closeBtn.addEventListener('click', toggleSidebar);
        if (overlay) overlay.addEventListener('click', toggleSidebar);

        // Escape key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                toggleSidebar();
            }
        });
    });
</script>
