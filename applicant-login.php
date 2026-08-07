<?php
require_once 'applicant_config.php';

if (isset($_SESSION['applicant_id'])) {
    header("Location: careers.php");
    exit();
}

$redirect = sanitizeApplicantRedirect($_GET['redirect'] ?? '');

$error = '';
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $redirect = sanitizeApplicantRedirect($_POST['redirect'] ?? $redirect);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (empty($password) || strlen($password) < 6) {
            $error = 'Invalid password format.';
        } elseif (checkLoginAttempts($email)) {
            $error = 'Too many login attempts. Please try again in 15 minutes.';
        } else {
            try {
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, is_active FROM applicants WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $applicant = $result->fetch_assoc();

                    if (!$applicant['is_active']) {
                        recordLoginAttempt($email);
                        $error = 'Your account has been deactivated.';
                    } elseif (password_verify($password, $applicant['password_hash'])) {
                        clearLoginAttempts($email);

                        $updateLogin = $conn->prepare("UPDATE applicants SET last_login_at = NOW() WHERE id = ?");
                        $updateLogin->bind_param("i", $applicant['id']);
                        $updateLogin->execute();
                        $updateLogin->close();

                        session_regenerate_id(true);
                        $_SESSION['applicant_id'] = $applicant['id'];
                        $_SESSION['applicant_name'] = $applicant['full_name'];
                        $_SESSION['applicant_email'] = $applicant['email'];
                        $_SESSION['LAST_ACTIVITY'] = time();

                        $stmt->close();
                        $conn->close();

                        header("Location: " . $redirect);
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
            } catch (Exception $e) {
                error_log("Applicant login error: " . $e->getMessage());
                $error = 'Database error. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Applicant Login - Careers</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.12">
    <?php echo generateBrandAccentCSS(); ?>
</head>
<body>
    <div class="login-container">
        <div class="login-brand-panel">
            <div class="login-brand-content">
                <h1>Track your career journey with Skyward Airlines</h1>
                <p>Sign in to check your application status, pick up where you left off, and apply to new openings without re-entering your details.</p>
                <div class="login-brand-features">
                    <div><i class="ri-route-line"></i> Track Progress</div>
                    <div><i class="ri-flashlight-line"></i> Fast Applications</div>
                    <div><i class="ri-lock-line"></i> Secure Account</div>
                </div>
            </div>
        </div>
        <div class="login-form-panel">
        <div class="login-box">
            <?php
            $loginLogo = getSetting('login_logo', '');
            $logoUrl = !empty($loginLogo) ? htmlspecialchars($loginLogo) : 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
            ?>
            <img src="<?php echo $logoUrl; ?>" alt="Logo" class="login-logo">
            <h2>Applicant Login</h2>

            <?php if ($error): ?>
                <div class="error"><i class="ri-error-warning-line"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

                <div class="form-group">
                    <label><i class="ri-mail-line"></i> Email</label>
                    <input type="email" name="email" required autofocus maxlength="150" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                </div>

                <div class="form-group">
                    <label><i class="ri-lock-line"></i> Password</label>
                    <input type="password" name="password" required autocomplete="current-password" minlength="6">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="ri-login-box-line"></i> Login
                </button>
            </form>

            <div class="login-footer">
                <p>Don't have an account? <a href="applicant-register.php<?php echo $redirect !== 'careers.php' ? '?redirect=' . urlencode($redirect) : ''; ?>">Create one</a></p>
                <p><a href="careers.php">&larr; Back to job listings</a></p>
            </div>
        </div>
        </div>

        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="ri-moon-line" id="themeIcon"></i>
        </button>
    </div>

    <script>
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
        if (icon) icon.className = isDark ? 'ri-sun-line' : 'ri-moon-line';
    }
    initTheme();
    </script>
</body>
</html>
