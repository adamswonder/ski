<?php
require_once 'applicant_config.php';

$conn = getDBConnection();
$result = $conn->query("SELECT id, title, description, department, location, employment_type, salary_range, open_date, close_date, status_override, created_at FROM job_postings ORDER BY created_at DESC");

$postings = [];
while ($row = $result->fetch_assoc()) {
    $effectiveStatus = computeEffectiveStatus($row['status_override'], $row['open_date'], $row['close_date']);
    if ($effectiveStatus !== 'open') {
        continue;
    }
    $excerpt = trim(strip_tags($row['description']));
    if (strlen($excerpt) > 160) {
        $excerpt = substr($excerpt, 0, 160) . '...';
    }
    $postings[] = [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'department' => $row['department'],
        'location' => $row['location'],
        'employment_type' => $row['employment_type'],
        'salary_range' => $row['salary_range'],
        'excerpt' => $excerpt
    ];
}
$conn->close();

$isLoggedIn = isset($_SESSION['applicant_id']);
$loginLogo = getSetting('login_logo', '');
$logoUrl = !empty($loginLogo) ? htmlspecialchars($loginLogo) : 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Careers - Skyward Airlines</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.10">
    <?php echo generateBrandAccentCSS(); ?>
    <style>

        .careers-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; flex-wrap: wrap; gap: 12px; }
        .careers-nav-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; }
        .careers-nav-brand img { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; }
        .careers-nav-brand h1 { margin: 0; font-size: 24px; }

        .careers-list { border-top: 1px solid var(--border-color); }
        .careers-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 28px 0;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .careers-row-main { flex: 1; min-width: 240px; }
        .careers-eyebrow {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--navy-accent);
            margin-bottom: 8px;
        }
        .careers-row-main h2 { font-size: 26px; margin-bottom: 10px; line-height: 1.25; }
        .careers-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; color: var(--text-secondary); font-size: 14px; }
        .careers-meta .dot { width: 4px; height: 4px; border-radius: 50%; background: var(--text-muted); flex-shrink: 0; }

        .careers-row-actions { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .careers-view-btn {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.2s;
        }
        .careers-view-btn:hover { border-color: var(--navy-primary); color: var(--navy-primary); }
        .careers-apply-btn {
            background: var(--navy-primary);
            color: #fff;
            padding: 12px 22px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: filter 0.2s, transform 0.2s;
        }
        .careers-apply-btn:hover { filter: brightness(0.9); transform: translateY(-1px); }

        .careers-footer-info { display: grid; grid-template-columns: repeat(2, 1fr); gap: 30px; margin-top: 50px; }
        .careers-footer-info-item .careers-eyebrow { background: var(--bg-secondary); border: 1px solid var(--border-color); display: inline-block; padding: 4px 12px; border-radius: 999px; margin-bottom: 14px; }
        .careers-footer-info-item p { color: var(--text-secondary); font-size: 15px; line-height: 1.6; }

        @media (max-width: 640px) {
            .careers-row { flex-direction: column; align-items: flex-start; }
            .careers-row-actions { width: 100%; }
            .careers-apply-btn { flex: 1; justify-content: center; }
            .careers-footer-info { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="careers-page">
    <div style="max-width: 1000px; margin: 0 auto; padding: 30px 20px 60px;">
        <div class="careers-nav">
            <a href="index.php" class="careers-nav-brand">
                <img src="<?php echo $logoUrl; ?>" alt="Logo">
                <h1>Careers</h1>
            </a>
            <div>
                <?php if ($isLoggedIn): ?>
                    <span style="margin-right:12px;">Hi, <?php echo htmlspecialchars($_SESSION['applicant_name']); ?></span>
                    <a href="applicant-dashboard.php" class="btn btn-secondary btn-sm"><i class="ri-user-line"></i> My Applications</a>
                    <a href="applicant-logout.php" class="btn btn-secondary btn-sm"><i class="ri-logout-box-line"></i> Logout</a>
                <?php else: ?>
                    <a href="applicant-login.php" class="btn btn-secondary btn-sm"><i class="ri-login-box-line"></i> Login</a>
                    <a href="applicant-register.php" class="btn btn-primary btn-sm"><i class="ri-user-add-line"></i> Create Account</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($postings)): ?>
            <div class="data-section" style="text-align:center; padding: 60px 20px;">
                <i class="ri-inbox-line" style="font-size: 48px; opacity: 0.4;"></i>
                <p style="margin-top: 16px;">No open positions right now. Check back soon.</p>
            </div>
        <?php else: ?>
            <div class="careers-list">
                <?php foreach ($postings as $posting): ?>
                    <div class="careers-row">
                        <div class="careers-row-main">
                            <div class="careers-eyebrow"><?php echo htmlspecialchars($posting['department'] ?: 'Open Roles'); ?></div>
                            <h2><?php echo htmlspecialchars($posting['title']); ?></h2>
                            <div class="careers-meta">
                                <?php
                                    $metaParts = array_filter([
                                        $posting['employment_type'],
                                        $posting['salary_range'],
                                        $posting['location']
                                    ]);
                                ?>
                                <?php foreach ($metaParts as $i => $part): ?>
                                    <?php if ($i > 0): ?><span class="dot"></span><?php endif; ?>
                                    <span><?php echo htmlspecialchars($part); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="careers-row-actions">
                            <a href="careers-job.php?id=<?php echo $posting['id']; ?>" class="careers-view-btn" title="View Details">
                                <i class="ri-arrow-down-s-line"></i>
                            </a>
                            <a href="careers-job.php?id=<?php echo $posting['id']; ?>" class="careers-apply-btn">
                                Submit Application <i class="ri-arrow-right-up-line"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="careers-footer-info">
            <div class="careers-footer-info-item">
                <div class="careers-eyebrow">How It Works</div>
                <p>Create an account, browse open roles, and apply in minutes. Track every application's progress from your personal dashboard.</p>
            </div>
            <div class="careers-footer-info-item">
                <div class="careers-eyebrow">Contact Us</div>
                <p>Have a question about a role or your application? Reach out to our recruitment team and we'll get back to you shortly.</p>
                <p><a href="mailto:careers@skywardairlines.co.ke" style="color: var(--navy-accent); font-weight: 600;">careers@skywardairlines.co.ke</a></p>
            </div>
        </div>
    </div>
</body>
</html>
