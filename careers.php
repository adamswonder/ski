<?php
require_once 'applicant_config.php';

$conn = getDBConnection();
$result = $conn->query("SELECT id, title, description, department, location, open_date, close_date, status_override, created_at FROM job_postings ORDER BY created_at DESC");

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
    <title>Careers - Job Openings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=5.3">
    <?php echo generateBrandAccentCSS(); ?>
</head>
<body>
    <div style="max-width: 1000px; margin: 0 auto; padding: 30px 20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 30px; flex-wrap: wrap; gap: 12px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <img src="<?php echo $logoUrl; ?>" alt="Logo" style="width:48px; height:48px; border-radius:8px; object-fit:cover;">
                <h1 style="margin:0;"><i class="fas fa-briefcase"></i> Careers</h1>
            </div>
            <div>
                <?php if ($isLoggedIn): ?>
                    <span style="margin-right:12px;">Hi, <?php echo htmlspecialchars($_SESSION['applicant_name']); ?></span>
                    <a href="applicant-dashboard.php" class="btn btn-secondary btn-sm"><i class="fas fa-user"></i> My Applications</a>
                    <a href="applicant-logout.php" class="btn btn-secondary btn-sm"><i class="fas fa-sign-out-alt"></i> Logout</a>
                <?php else: ?>
                    <a href="applicant-login.php" class="btn btn-secondary btn-sm"><i class="fas fa-sign-in-alt"></i> Login</a>
                    <a href="applicant-register.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Create Account</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($postings)): ?>
            <div class="data-section" style="text-align:center; padding: 60px 20px;">
                <i class="fas fa-inbox" style="font-size: 48px; opacity: 0.4;"></i>
                <p style="margin-top: 16px;">No open positions right now. Check back soon.</p>
            </div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
                <?php foreach ($postings as $posting): ?>
                    <div class="data-section" style="padding: 20px;">
                        <h3 style="margin-top:0;"><?php echo htmlspecialchars($posting['title']); ?></h3>
                        <div style="margin-bottom: 10px; color: var(--text-secondary, #666); font-size: 14px;">
                            <?php if ($posting['department']): ?>
                                <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($posting['department']); ?></span>
                            <?php endif; ?>
                            <?php if ($posting['location']): ?>
                                <span style="margin-left: 10px;"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($posting['location']); ?></span>
                            <?php endif; ?>
                        </div>
                        <p><?php echo htmlspecialchars($posting['excerpt']); ?></p>
                        <a href="careers-job.php?id=<?php echo $posting['id']; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-arrow-right"></i> View & Apply
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
