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
$submitted = isset($_GET['submitted']);

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT a.id, a.position, a.applied_date, p_s.name AS stage_name, p_s.color AS stage_color,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Applications - Careers</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.10">
    <?php echo generateBrandAccentCSS(); ?>
</head>
<body>
    <div style="max-width: 900px; margin: 0 auto; padding: 30px 20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
            <h1 style="margin:0;"><i class="ri-user-line"></i> My Applications</h1>
            <div>
                <a href="careers.php" class="btn btn-secondary btn-sm"><i class="ri-briefcase-line"></i> Browse Openings</a>
                <a href="applicant-logout.php" class="btn btn-secondary btn-sm"><i class="ri-logout-box-line"></i> Logout</a>
            </div>
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
            <div class="table-scroll-hint">
                <i class="ri-arrow-left-right-line"></i> Swipe left/right to see all columns
            </div>
            <div class="data-section" style="overflow-x:auto;">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left; padding: 10px;">Position</th>
                            <th style="text-align:left; padding: 10px;">Applied</th>
                            <th style="text-align:left; padding: 10px;">Stage</th>
                            <th style="text-align:left; padding: 10px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($app['position']); ?></td>
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
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
