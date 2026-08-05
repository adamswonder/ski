<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$csrf_token = generateCSRFToken();

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        // Input validation
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (empty($password) || strlen($password) < 6) {
            $error = 'Invalid password format.';
        } else {
            // Check rate limiting
            if (checkLoginAttempts($email)) {
                $error = 'Too many login attempts. Please try again in 15 minutes.';
            } else {
                try {
                    $conn = getDBConnection();

                    $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, role, is_active FROM users WHERE email = ?");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                } catch (Exception $e) {
                    // Check if it's a table/column not found error
                    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
                        $error = 'Database not set up. Please run <a href="setup.php" style="color: var(--navy-accent); text-decoration: underline;">setup.php</a> first.';
                    } else {
                        $error = 'Database error. Please contact administrator.';
                        error_log("Login error: " . $e->getMessage());
                    }
                    if (isset($stmt)) $stmt->close();
                    if (isset($conn)) $conn->close();
                    goto skip_login;
                }

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();

                    // Check if account is active
                    if (!$user['is_active']) {
                        recordLoginAttempt($email);
                        $error = 'Your account has been deactivated. Please contact the administrator.';
                        $stmt->close();
                        $conn->close();
                        goto skip_login;
                    }

                    if (password_verify($password, $user['password_hash'])) {
                        // Clear failed login attempts
                        clearLoginAttempts($email);

                        // Update last_login_at
                        $updateLogin = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                        $updateLogin->bind_param("i", $user['id']);
                        $updateLogin->execute();
                        $updateLogin->close();

                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['full_name'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['LAST_ACTIVITY'] = time();

                        // Log successful login
                        logActivity($user['id'], 'LOGIN', 'User logged in successfully', ['module' => 'auth']);

                        $stmt->close();
                        $conn->close();

                        header("Location: dashboard.php");
                        exit();
                    } else {
                        recordLoginAttempt($email);
                        $error = 'Invalid email or password';
                    }
                } else {
                    recordLoginAttempt($email);
                    $error = 'Invalid email or password';
                }

                $stmt->close();
                $conn->close();
            }
        }
        skip_login:
    }
}
?>
<!--
  Developed by Rameez Scripts
  WhatsApp: https://wa.me/923224083545 (For Custom Projects)
  YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Login - Dashboard System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=5.0">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <?php
            $loginLogo = getSetting('login_logo', '');
            $logoUrl = !empty($loginLogo) ? htmlspecialchars($loginLogo) : 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
            ?>
            <img src="<?php echo $logoUrl; ?>" alt="Logo" class="login-logo">
            <h2>System Login</h2>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email</label>
                    <input type="email" id="email" name="email" required autofocus autocomplete="email" maxlength="150" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" minlength="6">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>

            <div class="login-footer">
                <p>&copy; 2025 Dashboard System. All rights reserved.</p>
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
    // Theme Toggle for Login Page
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.body.classList.add('dark-mode');
            updateThemeIcon(true);
        }
    }

    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    }

    function updateThemeIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    initTheme();
    </script>
</body>
</html>
