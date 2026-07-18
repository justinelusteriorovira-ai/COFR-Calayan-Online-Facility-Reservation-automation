<?php
// navbar.php
$current_page = basename($_SERVER['PHP_SELF']);
$dir = dirname($_SERVER['PHP_SELF']);
$is_subfolder = (strpos($dir, 'reservations') !== false || strpos($dir, 'facilities') !== false || strpos($dir, 'calendar') !== false || strpos($dir, 'auth') !== false || strpos($dir, 'admin') !== false);
$base_path = $is_subfolder ? "../" : "";

require_once($is_subfolder ? "../config/db.php" : "config/db.php");
$user_role = $_SESSION['user_role'] ?? 'staff';
$notif_count = 0;
$notif_items = null;

if ($user_role !== 'admin') {
    // ─── Notification: Pending ONLINE (Facebook) Reservations ──────
    $notif_count_result = $conn->query("SELECT COUNT(*) as cnt FROM reservations WHERE reservation_type = 'ONLINE' AND status = 'PENDING'");
    $notif_count = $notif_count_result ? $notif_count_result->fetch_assoc()['cnt'] : 0;

    $notif_items = $conn->query("
        SELECT r.id, r.fb_name, r.reservation_date, r.created_at, f.name AS facility_name
        FROM reservations r
        JOIN facilities f ON r.facility_id = f.id
        WHERE r.reservation_type = 'ONLINE' AND r.status = 'PENDING'
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
    crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="<?= $base_path ?>style/sidebar.css">

<?php
$brand_text = ($user_role === 'admin') ? 'CEFI ONLINE FACILITY RESERVATION' : 'CEFI ONLINE FACILITY RESERVATION';
$brand_text = ($user_role === 'admin') ? 'CEFI ONLINE FACILITY RESERVATION' : 'CEFI ONLINE FACILITY RESERVATION';
$dashboard_link = ($user_role === 'admin') ? 'admin_dashboard.php' : 'dashboard.php';
?>
<nav class="top-navbar">
    <a href="<?= $base_path ?><?= $dashboard_link ?>" class="nav-brand" style="text-decoration:none;">
        <img src="https://enrollment.cefi.website/images/cefi-logo.png" alt="cefi-logo">
        <span class="brand-text"><?= $brand_text ?></span>
    </a>

    <button class="mobile-menu-toggle" id="mobile-menu-btn" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <div class="nav-links" id="nav-links">
    </div>

    <div class="nav-right-group">
        <?php if ($user_role !== 'admin'): ?>
        <!-- Notification Bell -->
        <div class="notif-bell-wrapper" id="notifBellWrapper">
            <button class="notif-bell-btn" id="notifBellBtn" aria-label="Notifications"
                title="Pending Facebook Reservations">
                <i class="fas fa-bell"></i>
                <?php if ($notif_count > 0): ?>
                    <span class="notif-badge" id="notifBadge"><?= $notif_count > 99 ? '99+' : $notif_count ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <span><i class="fab fa-facebook"></i> Facebook Reservations!</span>
                    <span class="notif-count-label" id="notifCountLabel"><?= $notif_count ?> pending</span>
                </div>
                <div class="notif-list" id="notifList">
                    <?php if ($notif_items && $notif_items->num_rows > 0): ?>
                        <?php while ($ni = $notif_items->fetch_assoc()): ?>
                            <a href="<?= $base_path ?>reservations/edit.php?id=<?= $ni['id'] ?>" class="notif-item">
                                <div class="notif-item-icon"><i class="fas fa-user-circle"></i></div>
                                <div class="notif-item-body">
                                    <div class="notif-item-name"><?= htmlspecialchars($ni['fb_name']) ?></div>
                                    <div class="notif-item-detail"><?= htmlspecialchars($ni['facility_name']) ?> ·
                                        <?= date('M d', strtotime($ni['reservation_date'])) ?>
                                    </div>
                                    <div class="notif-item-time"><?= date('M d, g:i A', strtotime($ni['created_at'])) ?></div>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="notif-empty">
                            <i class="fas fa-check-circle"></i>
                            <span>No pending reservations</span>
                        </div>
                    <?php endif; ?>
                </div>
                <a href="<?= $base_path ?>reservations/index.php?status=PENDING" class="notif-footer">
                    View All Pending <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions Sidebar Toggle -->
        <button class="quick-toggle-btn" id="sidebarToggleBtn" title="Quick Actions" aria-label="Toggle Quick Actions Sidebar">
            <i class="fas fa-bolt"></i>
        </button>

        <a href="<?= $base_path ?>auth/logout.php" class="logout-btn">
            Logout
        </a>
    </div>
</nav>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const mobileBtn = document.getElementById('mobile-menu-btn');
        const navLinks = document.getElementById('nav-links');

        mobileBtn.addEventListener('click', function () {
            navLinks.classList.toggle('show');
        });

        // Handle dropdown toggles on mobile
        const dropdowns = document.querySelectorAll('.dropdown');
        dropdowns.forEach(drop => {
            drop.addEventListener('click', function (e) {
                if (window.innerWidth <= 768) {
                    // If it's already active, let it toggle off. If not, close others and open this one.
                    const wasActive = this.classList.contains('active-mobile');

                    dropdowns.forEach(d => d.classList.remove('active-mobile'));

                    if (!wasActive) {
                        this.classList.add('active-mobile');
                    }
                }
            });
        });
        const notifBellBtn = document.getElementById('notifBellBtn');
        const notifDropdown = document.getElementById('notifDropdown');

        if (notifBellBtn && notifDropdown) {
            notifBellBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                notifDropdown.classList.toggle('open');

                // Close mobile menu if open
                if (navLinks.classList.contains('show')) {
                    navLinks.classList.remove('show');
                }
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                if (!notifBellBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                    notifDropdown.classList.remove('open');
                }
            });
        }

    });
</script>

<?php include 'quick_sidebar.php'; ?>