<?php
require_once 'config.php';

// Staff session check
$isStaffLoggedIn = isset($_SESSION['user_id']);
session_write_close();

// Applicant session lives under a separate cookie name — check it independently
session_name('applicant_session');
session_start();
$isApplicantLoggedIn = isset($_SESSION['applicant_id']);
session_write_close();

if ($isStaffLoggedIn) {
    header("Location: dashboard.php");
    exit();
}
if ($isApplicantLoggedIn) {
    header("Location: applicant-dashboard.php");
    exit();
}

// Live stats for the hero section
$conn = getDBConnection();
$openPositions = 0;
$departments = [];
$locations = [];
$result = $conn->query("SELECT department, location, open_date, close_date, status_override FROM job_postings");
while ($result && ($row = $result->fetch_assoc())) {
    if (computeEffectiveStatus($row['status_override'], $row['open_date'], $row['close_date']) !== 'open') {
        continue;
    }
    $openPositions++;
    if (!empty($row['department'])) $departments[$row['department']] = true;
    if (!empty($row['location'])) $locations[$row['location']] = true;
}
$stagesResult = $conn->query("SELECT COUNT(*) AS c FROM stages WHERE stage_type = 'pipeline' AND is_active = 1");
$pipelineStages = $stagesResult ? (int)$stagesResult->fetch_assoc()['c'] : 0;
$conn->close();

$departmentCount = count($departments);
$locationCount = count($locations);

$loginLogo = getSetting('login_logo', '');
$logoUrl = !empty($loginLogo) ? htmlspecialchars($loginLogo) : 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skyward Airlines Careers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.7">
    <?php echo generateBrandAccentCSS(); ?>
    <style>
        html, body.landing {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        html::-webkit-scrollbar, body.landing::-webkit-scrollbar {
            display: none;
        }

        .landing { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-primary); color: var(--text-primary); }
        .landing h1, .landing h2, .landing h3 { font-family: 'Playfair Display', Georgia, serif; }
        .landing a { text-decoration: none; }

        .landing .btn { border-radius: 999px; }
        .landing .btn-primary { background: var(--navy-accent); color: #fff; }
        .landing .btn-primary:hover { background: var(--navy-accent); filter: brightness(0.88); transform: translateY(-2px); }
        .landing .btn-outline-accent { background: transparent; color: var(--navy-accent); border: 2px solid var(--navy-accent); }
        .landing .btn-outline-accent:hover { background: var(--navy-accent); color: #fff; transform: translateY(-2px); }

        .landing-nav {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, 0.65);
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 1px 20px rgba(0, 0, 0, 0.04);
        }
        body.dark-mode .landing-nav {
            background: rgba(22, 33, 62, 0.55);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .landing-nav-inner { max-width: 1180px; margin: 0 auto; padding: 16px 24px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .landing-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; color: var(--text-primary); }
        .landing-brand img { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; }
        .landing-nav-links { display: flex; align-items: center; gap: 28px; }
        .landing-nav-links a { color: var(--text-secondary); font-weight: 500; font-size: 14px; }
        .landing-nav-links a:hover { color: var(--navy-accent); }
        .landing-nav-actions { display: flex; align-items: center; gap: 12px; }

        .landing-hero { max-width: 800px; margin: 0 auto; padding: 90px 24px 60px; text-align: center; }
        .landing-eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--navy-accent); margin-bottom: 20px; }
        .landing-eyebrow::before { content: ''; width: 24px; height: 2px; background: var(--navy-accent); display: inline-block; }
        .landing-hero h1 { font-size: 48px; line-height: 1.15; margin-bottom: 20px; }
        .landing-hero p { font-size: 17px; color: var(--text-secondary); line-height: 1.6; margin-bottom: 32px; }
        .landing-hero-actions { display: flex; align-items: center; justify-content: center; gap: 14px; flex-wrap: wrap; }
        .landing-hero-actions .btn { padding: 13px 26px; font-size: 15px; }

        .landing-stats { max-width: 1180px; margin: 0 auto 80px; padding: 0 24px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .landing-stat { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 24px 16px; text-align: center; }
        .landing-stat-value { font-family: 'Playfair Display', serif; font-size: 30px; font-weight: 700; color: var(--navy-accent); }
        .landing-stat-label { font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-muted); margin-top: 6px; }

        .landing-section { max-width: 1180px; margin: 0 auto; padding: 60px 24px; }
        .landing-section-head { text-align: center; max-width: 640px; margin: 0 auto 44px; }
        .landing-section-head h2 { font-size: 34px; margin-bottom: 12px; }
        .landing-section-head p { color: var(--text-secondary); font-size: 15px; line-height: 1.6; }

        .landing-features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .landing-feature { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 26px; }
        .landing-feature-icon { width: 44px; height: 44px; border-radius: 10px; background: rgba(0, 116, 217, 0.12); display: flex; align-items: center; justify-content: center; margin-bottom: 16px; font-size: 20px; color: var(--navy-accent); }
        .landing-feature h3 { font-size: 18px; margin-bottom: 8px; }
        .landing-feature p { font-size: 14px; color: var(--text-secondary); line-height: 1.6; }

        .landing-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
        .landing-step { text-align: center; padding: 0 12px; }
        .landing-step-number { width: 40px; height: 40px; border-radius: 50%; background: var(--navy-accent); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; margin: 0 auto 16px; font-family: 'Playfair Display', serif; }
        .landing-step h3 { font-size: 17px; margin-bottom: 8px; }
        .landing-step p { font-size: 14px; color: var(--text-secondary); line-height: 1.6; }

        .landing-cta { background: linear-gradient(135deg, var(--navy-accent) 0%, #6e0a17 100%); border-radius: 20px; padding: 56px 32px; text-align: center; margin: 0 24px 80px; max-width: 1132px; margin-left: auto; margin-right: auto; }
        .landing-cta h2 { color: #fff; font-size: 30px; margin-bottom: 12px; }
        .landing-cta p { color: rgba(255,255,255,0.75); margin-bottom: 26px; }
        .landing-cta .btn-primary { background: #fff; color: var(--navy-accent); }
        .landing-cta .btn-primary:hover { background: #fff; filter: none; opacity: 0.9; }

        .landing-footer { border-top: 1px solid var(--border-color); padding: 28px 24px; text-align: center; color: var(--text-muted); font-size: 13px; }

        @media (max-width: 900px) {
            .landing-nav-links { display: none; }
            .landing-stats { grid-template-columns: repeat(2, 1fr); }
            .landing-features { grid-template-columns: 1fr; }
            .landing-steps { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .landing-hero h1 { font-size: 34px; }
            .landing-stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body class="landing">
    <nav class="landing-nav">
        <div class="landing-nav-inner">
            <a href="index.php" class="landing-brand">
                <img src="<?php echo $logoUrl; ?>" alt="Skyward Airlines">
                <span>Skyward Airlines</span>
            </a>
            <div class="landing-nav-links">
                <a href="#features">Features</a>
                <a href="#how-it-works">How It Works</a>
                <a href="careers.php">Open Positions</a>
            </div>
            <div class="landing-nav-actions">
                <a href="login.php" class="btn btn-outline-accent btn-sm">Staff Login</a>
                <a href="careers.php" class="btn btn-primary btn-sm">Apply Now <i class="ri-arrow-right-line"></i></a>
            </div>
        </div>
    </nav>

    <section class="landing-hero">
        <div class="landing-eyebrow">Skyward Airlines Careers</div>
        <h1>Take Off With Your Next Career</h1>
        <p>Explore open positions across our teams, apply in minutes, and track every step of your application from one personal dashboard.</p>
        <div class="landing-hero-actions">
            <a href="careers.php" class="btn btn-primary">View Open Positions <i class="ri-arrow-right-line"></i></a>
            <a href="applicant-login.php" class="btn btn-secondary">Applicant Login</a>
        </div>
    </section>

    <div class="landing-stats">
        <div class="landing-stat">
            <div class="landing-stat-value"><?php echo $openPositions; ?></div>
            <div class="landing-stat-label">Open Positions</div>
        </div>
        <div class="landing-stat">
            <div class="landing-stat-value"><?php echo $departmentCount; ?></div>
            <div class="landing-stat-label">Departments Hiring</div>
        </div>
        <div class="landing-stat">
            <div class="landing-stat-value"><?php echo $locationCount; ?></div>
            <div class="landing-stat-label">Locations</div>
        </div>
        <div class="landing-stat">
            <div class="landing-stat-value"><?php echo $pipelineStages; ?></div>
            <div class="landing-stat-label">Hiring Stages</div>
        </div>
    </div>

    <section class="landing-section" id="features">
        <div class="landing-section-head">
            <h2>Everything You Need to Apply</h2>
            <p>A straightforward application process, built to keep you in the loop from your first click to your final interview.</p>
        </div>
        <div class="landing-features">
            <div class="landing-feature">
                <div class="landing-feature-icon"><i class="ri-briefcase-4-line"></i></div>
                <h3>Dynamic Job Postings</h3>
                <p>Every role has its own detailed listing with application questions tailored specifically to that position.</p>
            </div>
            <div class="landing-feature">
                <div class="landing-feature-icon"><i class="ri-user-shared-line"></i></div>
                <h3>One Account, Every Application</h3>
                <p>Register once and reuse the same account to apply to as many openings as you like.</p>
            </div>
            <div class="landing-feature">
                <div class="landing-feature-icon"><i class="ri-line-chart-line"></i></div>
                <h3>Real-Time Status Tracking</h3>
                <p>Follow your application as it moves through each hiring stage from your personal dashboard.</p>
            </div>
            <div class="landing-feature">
                <div class="landing-feature-icon"><i class="ri-file-upload-line"></i></div>
                <h3>Simple Document Uploads</h3>
                <p>Attach your resume and supporting documents directly to your application, no email attachments needed.</p>
            </div>
            <div class="landing-feature">
                <div class="landing-feature-icon"><i class="ri-shield-check-line"></i></div>
                <h3>Secure & Private</h3>
                <p>Your account and application data are protected by secure, separate sessions and encrypted credentials.</p>
            </div>
            <div class="landing-feature">
                <div class="landing-feature-icon"><i class="ri-smartphone-line"></i></div>
                <h3>Apply From Any Device</h3>
                <p>A clean, mobile-friendly form means you can apply from your phone, tablet, or desktop.</p>
            </div>
        </div>
    </section>

    <section class="landing-section" id="how-it-works">
        <div class="landing-section-head">
            <h2>How It Works</h2>
            <p>Three steps between you and your next role at Skyward Airlines.</p>
        </div>
        <div class="landing-steps">
            <div class="landing-step">
                <div class="landing-step-number">1</div>
                <h3>Create an Account</h3>
                <p>Sign up once with your email — no need to re-register for future applications.</p>
            </div>
            <div class="landing-step">
                <div class="landing-step-number">2</div>
                <h3>Browse & Apply</h3>
                <p>Find a role that fits and submit your application, resume, and answers in one go.</p>
            </div>
            <div class="landing-step">
                <div class="landing-step-number">3</div>
                <h3>Track Your Progress</h3>
                <p>Check your dashboard anytime to see which stage your application has reached.</p>
            </div>
        </div>
    </section>

    <div class="landing-cta">
        <h2>Ready to Join Our Team?</h2>
        <p>Browse current openings and submit your application today.</p>
        <a href="careers.php" class="btn btn-primary">View Open Positions <i class="ri-arrow-right-line"></i></a>
    </div>

    <footer class="landing-footer">
        &copy; 2026 Skyward Airlines. All rights reserved.
    </footer>
</body>
</html>
