<?php
session_start();
require_once("../config/db.php");
require_once("../config/csrf.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    requireCSRF();

    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    if (empty($username) || empty($password)) {
        $error = "All fields are required.";
    } else {

        $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
        if (!$stmt) {
            die("Login query preparation failed: " . $conn->error);
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($id, $hashed_password, $role);
            $stmt->fetch();

            if (password_verify($password, $hashed_password)) {
                $_SESSION["user_id"] = $id;
                $_SESSION["user_role"] = $role;

                // LOG LOGIN
                require_once("../config/audit_helper.php");
                logActivity($conn, 'LOGIN', strtoupper($role), $id, "$role logged in: $username");

                if ($role == 'admin') {
                    header("Location: ../admin_dashboard.php");
                } else {
                    header("Location: ../dashboard.php");
                }
                exit;
            } else {
                $error = "Invalid credentials.";
            }
        } else {
            $error = "Invalid credentials.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login</title>
    <link rel="stylesheet" href="../style/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body>

    <nav>
        <img src="https://enrollment.cefi.website/images/cefi-logo.png" alt="cefi-logo" loading="lazy">
        <div class="logo">CEFI ONLINE FACILITY RESERVATION</div>
    </nav>

    <form method="POST" class="loginForm">
        <?php csrfField(); ?>
        <h2>COFR LOGIN</h2>

        <div class="input-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" placeholder="Enter username" required>
        </div>

        <div class="input-group">
            <label for="password">Password</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password" placeholder="Enter password" required>
                <span class="toggle-password" id="togglePassword"><i class="fas fa-eye"></i></span>
            </div>
        </div>

        <button type="submit">Login</button>

        <?php if (isset($_GET['csrf_expired'])): ?>
            <p class="error" style="background: #fff3cd; color: #856404; border: 1px solid #ffc107;">
                <i class="fas fa-exclamation-triangle"></i> Your session has expired. Please log in again.
            </p>
        <?php elseif (isset($_GET['timeout'])): ?>
            <p class="error" style="background: #fff3cd; color: #856404; border: 1px solid #ffc107;">
                <i class="fas fa-clock"></i> Session timed out due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <p class="error"><?php echo $error; ?></p>
        <?php endif; ?>
    </form>

    <div class="footer">
        © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc., Philippines
        | Contact: info@cefi.website
    </div>
    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        togglePassword.addEventListener('click', function () {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);

            this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';

            if (type === 'text') {
                setTimeout(() => {
                    password.setAttribute('type', 'password');
                    togglePassword.innerHTML = '<i class="fas fa-eye"></i>';
                }, 3000);
            }
        });

    </script>
</body>

</html>