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
        $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
        $redirect = sanitizeApplicantRedirect($_POST['redirect'] ?? $redirect);

        if (strlen($fullName) < 2 || strlen($fullName) > 100) {
            $error = 'Please enter your full name.';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!validatePassword($password)) {
            $error = 'Password must be between 6 and 255 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $conn = getDBConnection();

                $stmt = $conn->prepare("SELECT id FROM applicants WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($exists) {
                    $error = 'An account with this email already exists.';
                    $conn->close();
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $phoneValue = $phone !== '' ? $phone : null;

                    $stmt = $conn->prepare("INSERT INTO applicants (full_name, email, password_hash, phone) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $fullName, $email, $passwordHash, $phoneValue);
                    $stmt->execute();
                    $applicantId = $conn->insert_id;
                    $stmt->close();
                    $conn->close();

                    session_regenerate_id(true);
                    $_SESSION['applicant_id'] = $applicantId;
                    $_SESSION['applicant_name'] = $fullName;
                    $_SESSION['applicant_email'] = $email;
                    $_SESSION['LAST_ACTIVITY'] = time();

                    header("Location: " . $redirect);
                    exit();
                }
            } catch (Exception $e) {
                error_log("Applicant registration error: " . $e->getMessage());
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
    <title>Create Account - Careers</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.10">
    <?php echo generateBrandAccentCSS(); ?>
</head>
<body>
    <div class="login-container">
        <div class="login-brand-panel">
            <div class="login-brand-content">
                <h1>Join the Skyward Airlines team</h1>
                <p>Create an account to apply for open positions and keep track of every application from one place.</p>
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
            <h2>Create Your Account</h2>
            <p style="text-align:center; margin-top:-10px;">Track your job applications in one place</p>

            <?php if ($error): ?>
                <div class="error"><i class="ri-error-warning-line"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

                <div class="form-group">
                    <label><i class="ri-user-line"></i> Full Name</label>
                    <input type="text" name="full_name" required maxlength="100" autofocus value="<?php echo isset($fullName) ? htmlspecialchars($fullName) : ''; ?>">
                </div>

                <div class="form-group">
                    <label><i class="ri-mail-line"></i> Email</label>
                    <input type="email" name="email" required maxlength="150" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                </div>

                <div class="form-group">
                    <label><i class="ri-phone-line"></i> Phone (optional)</label>
                    <input type="text" name="phone" maxlength="20" value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>">
                </div>

                <div class="form-group">
                    <label><i class="ri-lock-line"></i> Password</label>
                    <input type="password" name="password" required minlength="6" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label><i class="ri-lock-line"></i> Confirm Password</label>
                    <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="ri-user-add-line"></i> Create Account
                </button>
            </form>

            <div class="login-footer">
                <p>Already have an account? <a href="applicant-login.php<?php echo $redirect !== 'careers.php' ? '?redirect=' . urlencode($redirect) : ''; ?>">Log in</a></p>
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
