<?php
require_once 'applicant_config.php';

if (!isset($_SESSION['applicant_id'])) {
    header("Location: applicant-login.php?redirect=applicant-dashboard.php");
    exit();
}

if (!checkSessionTimeout()) {
    header("Location: applicant-login.php?redirect=applicant-dashboard.php");
    exit();
}

$applicantId = $_SESSION['applicant_id'];
$applicant_name = $_SESSION['applicant_name'];
$current_page = 'applicant-dashboard';
$submitted = isset($_GET['submitted']);

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT a.id, a.position, a.applied_date, a.recruitment_reference,
                                p_s.name AS stage_name, p_s.color AS stage_color,
                                st_s.name AS status_name, st_s.color AS status_color, jp.id AS job_posting_id
                         FROM applications a
                         LEFT JOIN stages p_s ON a.stage_id = p_s.id
                         LEFT JOIN stages st_s ON a.status_id = st_s.id
                         LEFT JOIN job_postings jp ON a.job_posting_id = jp.id
                         WHERE a.applicant_id = ?
                         ORDER BY a.applied_date DESC, a.id DESC");
$stmt->bind_param("i", $applicantId);
$stmt->execute();
$result = $stmt->get_result();

$applications = [];
while ($row = $result->fetch_assoc()) {
    $applications[] = $row;
}
$stmt->close();
$conn->close();

$totalCount = count($applications);
$activeCount = 0;
$offerCount = 0;
foreach ($applications as $app) {
    $status = strtolower($app['status_name'] ?? '');
    if (in_array($status, ['hired', 'rejected', 'blacklisted', 'withdrawn'])) {
        continue;
    }
    if ($status === 'offer') {
        $offerCount++;
    }
    $activeCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>My Applications - Skyward Airlines</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.11">
    <?php echo generateBrandAccentCSS(); ?>
    <style>
        .ad-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .ad-stat { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; text-align: center; }
        .ad-stat-value { font-size: 28px; font-weight: 700; color: var(--navy-accent); }
        .ad-stat-label { font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-muted); margin-top: 4px; }
        @media (max-width: 640px) {
            .ad-stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'applicant-mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'applicant-sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <h1><i class="ri-file-text-line"></i> My Applications</h1>
                <div>Welcome, <?php echo htmlspecialchars($applicant_name); ?></div>
            </div>

            <?php if ($submitted): ?>
                <div class="log-item log-success" style="margin-bottom: 20px;">
                    <i class="ri-checkbox-circle-line"></i> Your application was submitted successfully.
                </div>
            <?php endif; ?>

            <?php if (empty($applications)): ?>
                <div class="data-section" style="text-align:center; padding: 60px 20px;">
                    <i class="ri-inbox-line" style="font-size: 48px; opacity: 0.4;"></i>
                    <p style="margin-top: 16px;">You haven't applied to any positions yet.</p>
                    <a href="careers.php" class="btn btn-primary" style="margin-top: 12px;">
                        <i class="ri-briefcase-line"></i> Browse Job Openings
                    </a>
                </div>
            <?php else: ?>
                <div class="ad-stats">
                    <div class="ad-stat">
                        <div class="ad-stat-value"><?php echo $totalCount; ?></div>
                        <div class="ad-stat-label">Total Applications</div>
                    </div>
                    <div class="ad-stat">
                        <div class="ad-stat-value"><?php echo $activeCount; ?></div>
                        <div class="ad-stat-label">In Progress</div>
                    </div>
                    <div class="ad-stat">
                        <div class="ad-stat-value"><?php echo $offerCount; ?></div>
                        <div class="ad-stat-label">Offers</div>
                    </div>
                </div>

                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="ri-list-check"></i> Your Applications</h2>
                    </div>
                    <div class="table-scroll-hint">
                        <i class="ri-arrow-left-right-line"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding: 10px;">Position</th>
                                    <th style="text-align:left; padding: 10px;">Reference</th>
                                    <th style="text-align:left; padding: 10px;">Applied</th>
                                    <th style="text-align:left; padding: 10px;">Stage</th>
                                    <th style="text-align:left; padding: 10px;">Status</th>
                                    <th style="text-align:left; padding: 10px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $app): ?>
                                    <tr>
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($app['position']); ?></td>
                                        <td style="padding: 10px; color: var(--text-muted); font-size: 13px;"><?php echo htmlspecialchars($app['recruitment_reference'] ?: '—'); ?></td>
                                        <td style="padding: 10px;"><?php echo date('M d, Y', strtotime($app['applied_date'])); ?></td>
                                        <td style="padding: 10px;">
                                            <?php if ($app['stage_name']): ?>
                                                <span class="stage-badge" style="color:<?php echo htmlspecialchars($app['stage_color']); ?>">
                                                    <?php echo htmlspecialchars($app['stage_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 10px;">
                                            <?php if ($app['status_name']): ?>
                                                <span class="stage-badge" style="color:<?php echo htmlspecialchars($app['status_color']); ?>">
                                                    <?php echo htmlspecialchars($app['status_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 10px;">
                                            <a href="applicant-application.php?id=<?php echo $app['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="ri-eye-line"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
