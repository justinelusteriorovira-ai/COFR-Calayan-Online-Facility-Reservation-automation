<?php
session_start();
require_once("../config/session.php");
require_once("../config/db.php");
require_once("../config/audit_helper.php");
require_once("../config/csrf.php");

// Admin only
if (!isset($_SESSION["user_id"]) || $_SESSION["user_role"] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$current_admin_id = $_SESSION["user_id"];

// ─── Handle POST Actions ──────────────────────────────
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // CREATE USER
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'staff';

        if (empty($username) || empty($password)) {
            $error_msg = "Username and password are required.";
        } elseif (strlen($password) < 6) {
            $error_msg = "Password must be at least 6 characters.";
        } elseif (!in_array($role, ['staff', 'admin'])) {
            $error_msg = "Invalid role selected.";
        } else {
            // Check for duplicate username
            $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error_msg = "Username '$username' already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $username, $hashed, $role);
                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    logActivity($conn, 'CREATE', 'USER', $new_id, "Created user: $username (role: $role)");
                    $success_msg = "User '$username' created successfully.";
                } else {
                    $error_msg = "Failed to create user.";
                }
            }
        }
    }

    // UPDATE USER
    if ($action === 'update') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $new_password = trim($_POST['new_password'] ?? '');

        if ($user_id <= 0 || empty($username)) {
            $error_msg = "Invalid user data.";
        } elseif (!in_array($role, ['staff', 'admin'])) {
            $error_msg = "Invalid role selected.";
        } else {
            // Get old data
            $old = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
            $old->bind_param("i", $user_id);
            $old->execute();
            $old_data = $old->get_result()->fetch_assoc();

            // Check duplicate username (exclude self)
            $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check->bind_param("si", $username, $user_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error_msg = "Username '$username' is already taken.";
            } else {
                if (!empty($new_password)) {
                    if (strlen($new_password) < 6) {
                        $error_msg = "Password must be at least 6 characters.";
                    } else {
                        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, role = ? WHERE id = ?");
                        $stmt->bind_param("sssi", $username, $hashed, $role, $user_id);
                    }
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $username, $role, $user_id);
                }

                if (empty($error_msg)) {
                    if ($stmt->execute()) {
                        logActivity($conn, 'UPDATE', 'USER', $user_id, 
                            "Updated user #$user_id", 
                            $old_data, 
                            ['username' => $username, 'role' => $role, 'password_changed' => !empty($new_password)]
                        );
                        $success_msg = "User '$username' updated successfully.";
                    } else {
                        $error_msg = "Failed to update user.";
                    }
                }
            }
        }
    }

    // DELETE USER
    if ($action === 'delete') {
        $user_id = intval($_POST['user_id'] ?? 0);

        if ($user_id === $current_admin_id) {
            $error_msg = "You cannot delete your own account.";
        } elseif ($user_id <= 0) {
            $error_msg = "Invalid user ID.";
        } else {
            // Get user info before delete
            $info = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
            $info->bind_param("i", $user_id);
            $info->execute();
            $user_info = $info->get_result()->fetch_assoc();

            if ($user_info) {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                if ($stmt->execute()) {
                    logActivity($conn, 'DELETE', 'USER', $user_id, 
                        "Deleted user: {$user_info['username']} (role: {$user_info['role']})");
                    $success_msg = "User '{$user_info['username']}' deleted.";
                } else {
                    $error_msg = "Failed to delete user.";
                }
            } else {
                $error_msg = "User not found.";
            }
        }
    }

    // RESET PASSWORD
    if ($action === 'reset_password') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $new_password = trim($_POST['new_password'] ?? '');

        if ($user_id <= 0 || strlen($new_password) < 6) {
            $error_msg = "Password must be at least 6 characters.";
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed, $user_id);
            if ($stmt->execute()) {
                logActivity($conn, 'UPDATE', 'USER', $user_id, "Password reset for user #$user_id");
                $success_msg = "Password reset successfully.";
            } else {
                $error_msg = "Failed to reset password.";
            }
        }
    }
}

// ─── Fetch Users ───────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$filter_role = $_GET['role'] ?? '';

$query = "SELECT id, username, role, created_at FROM users WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND username LIKE ?";
    $search_param = "%$search%";
    $params[] = &$search_param;
    $types .= "s";
}
if (!empty($filter_role) && in_array($filter_role, ['staff', 'admin'])) {
    $query .= " AND role = ?";
    $params[] = &$filter_role;
    $types .= "s";
}

$query .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();

// Stats
$total_users = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$total_admins = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='admin'")->fetch_assoc()['c'];
$total_staff = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='staff'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - CEFI Admin</title>
    <link rel="stylesheet" href="../style/dashboard.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
    <link rel="stylesheet" href="../style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-users-cog"></i> Staff Management</h1>
        <div class="header-user">Administrator</div>
    </div>

    <div class="container">
        <?php if ($success_msg): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="info-metrics">
            <div class="info-metric">
                <div class="info-metric-icon green"><i class="fas fa-users"></i></div>
                <div class="info-metric-data">
                    <h4><?= $total_users ?></h4>
                    <span>Total Users</span>
                </div>
            </div>
            <div class="info-metric">
                <div class="info-metric-icon amber"><i class="fas fa-user-shield"></i></div>
                <div class="info-metric-data">
                    <h4><?= $total_admins ?></h4>
                    <span>Administrators</span>
                </div>
            </div>
            <div class="info-metric">
                <div class="info-metric-icon blue"><i class="fas fa-user-tie"></i></div>
                <div class="info-metric-data">
                    <h4><?= $total_staff ?></h4>
                    <span>Staff Members</span>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="admin-toolbar">
            <form method="GET" class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search by username..." value="<?= htmlspecialchars($search) ?>">
                <?php if (!empty($filter_role)): ?>
                    <input type="hidden" name="role" value="<?= htmlspecialchars($filter_role) ?>">
                <?php endif; ?>
            </form>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <a href="?role=" class="btn btn-outline btn-sm <?= empty($filter_role) ? 'active' : '' ?>" style="<?= empty($filter_role) ? 'background:#013c10;color:#fff;' : '' ?>">All</a>
                <a href="?role=admin" class="btn btn-outline btn-sm <?= $filter_role==='admin' ? 'active' : '' ?>" style="<?= $filter_role==='admin' ? 'background:#013c10;color:#fff;' : '' ?>">Admins</a>
                <a href="?role=staff" class="btn btn-outline btn-sm <?= $filter_role==='staff' ? 'active' : '' ?>" style="<?= $filter_role==='staff' ? 'background:#013c10;color:#fff;' : '' ?>">Staff</a>
                <button class="btn btn-primary" onclick="openModal('createModal')">
                    <i class="fas fa-user-plus"></i> Add User
                </button>
            </div>
        </div>

        <!-- Users Table -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3><i class="fas fa-list"></i> User Accounts</h3>
                <span style="font-size:0.75rem;color:#94a3b8;"><?= $users->num_rows ?> result(s)</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users->num_rows === 0): ?>
                            <tr><td colspan="5">
                                <div class="empty-state">
                                    <i class="fas fa-user-slash"></i>
                                    <h4>No users found</h4>
                                    <p>Try adjusting your search or filter.</p>
                                </div>
                            </td></tr>
                        <?php else: ?>
                            <?php while ($u = $users->fetch_assoc()): ?>
                            <tr>
                                <td style="font-weight:600;color:#94a3b8;">#<?= $u['id'] ?></td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:0.6rem;">
                                        <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#013c10,#026b1c);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;">
                                            <?= strtoupper(substr($u['username'], 0, 1)) ?>
                                        </div>
                                        <span style="font-weight:600;"><?= htmlspecialchars($u['username']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="role-badge <?= $u['role'] ?>">
                                        <i class="fas <?= $u['role'] === 'admin' ? 'fa-crown' : 'fa-user' ?>"></i>
                                        <?= ucfirst($u['role']) ?>
                                    </span>
                                </td>
                                <td style="color:#64748b;font-size:0.8rem;">
                                    <?= date('M d, Y', strtotime($u['created_at'])) ?>
                                </td>
                                <td>
                                    <div class="actions-group">
                                        <button class="btn btn-outline btn-icon" title="Edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button class="btn btn-outline btn-icon" title="Reset Password" style="color:#f59e0b;border-color:rgba(245,158,11,0.3);" onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <?php if ($u['id'] !== $current_admin_id): ?>
                                        <button class="btn btn-icon" title="Delete" style="background:rgba(239,68,68,0.08);color:#ef4444;border:1px solid rgba(239,68,68,0.2);" onclick="openDeleteModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ═══ CREATE USER MODAL ═══ -->
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Create New User</h3>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label>Username <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" placeholder="Enter username" required minlength="3" maxlength="50">
                </div>
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <input type="password" name="password" class="form-control" placeholder="Enter password" required minlength="6" id="createPw" oninput="checkPwStrength(this.value, 'createPwBar')">
                    <div class="pw-strength"><div class="pw-strength-bar" id="createPwBar"></div></div>
                    <span class="form-hint">Minimum 6 characters</span>
                </div>
                <div class="form-group">
                    <label>Role <span class="required">*</span></label>
                    <select name="role" class="form-control" required>
                        <option value="staff">Staff</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ EDIT USER MODAL ═══ -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit"></i> Edit User</h3>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Username <span class="required">*</span></label>
                    <input type="text" name="username" id="editUsername" class="form-control" required minlength="3" maxlength="50">
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current" minlength="6" oninput="checkPwStrength(this.value, 'editPwBar')">
                    <div class="pw-strength"><div class="pw-strength-bar" id="editPwBar"></div></div>
                    <span class="form-hint">Leave empty to keep current password</span>
                </div>
                <div class="form-group">
                    <label>Role <span class="required">*</span></label>
                    <select name="role" id="editRole" class="form-control" required>
                        <option value="staff">Staff</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ RESET PASSWORD MODAL ═══ -->
<div class="modal-overlay" id="resetModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-key" style="color:#f59e0b;"></i> Reset Password</h3>
            <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
        </div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetUserId">
            <div class="modal-body">
                <p style="font-size:0.85rem;color:#64748b;margin-bottom:1rem;">
                    Reset password for <strong id="resetUsername" style="color:#013c10;"></strong>
                </p>
                <div class="form-group">
                    <label>New Password <span class="required">*</span></label>
                    <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required minlength="6" oninput="checkPwStrength(this.value, 'resetPwBar')">
                    <div class="pw-strength"><div class="pw-strength-bar" id="resetPwBar"></div></div>
                    <span class="form-hint">Minimum 6 characters</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('resetModal')">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ DELETE CONFIRMATION MODAL ═══ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Delete User</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId">
            <div class="modal-body">
                <div class="confirm-body">
                    <i class="fas fa-exclamation-triangle danger"></i>
                    <h4>Are you sure?</h4>
                    <p>You are about to permanently delete <strong id="deleteUsername" style="color:#ef4444;"></strong>. This action cannot be undone.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Delete User</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function openEditModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editRole').value = user.role;
    openModal('editModal');
}

function openResetModal(id, username) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUsername').textContent = username;
    openModal('resetModal');
}

function openDeleteModal(id, username) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteUsername').textContent = username;
    openModal('deleteModal');
}

function checkPwStrength(pw, barId) {
    const bar = document.getElementById(barId);
    bar.className = 'pw-strength-bar';
    if (pw.length === 0) { bar.style.width = '0'; return; }
    if (pw.length < 6) { bar.classList.add('weak'); }
    else if (pw.length < 10 || !/[A-Z]/.test(pw) || !/[0-9]/.test(pw)) { bar.classList.add('medium'); }
    else { bar.classList.add('strong'); }
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
    }
});

// Auto-hide alerts after 5 seconds
document.querySelectorAll('.success, .error').forEach(el => {
    setTimeout(() => { el.style.transition = 'opacity 0.5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 5000);
});
</script>

</body>
</html>
