<?php
require_once 'applicant_config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, title, description, department, location, status_override, open_date, close_date FROM job_postings WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$posting = $result->num_rows > 0 ? $result->fetch_assoc() : null;
$stmt->close();
$conn->close();

$isLoggedIn = isset($_SESSION['applicant_id']);
$effectiveStatus = $posting ? computeEffectiveStatus($posting['status_override'], $posting['open_date'], $posting['close_date']) : null;

$applyUrl = 'apply.php?job_posting_id=' . $id;
$applyHref = $isLoggedIn ? $applyUrl : 'applicant-login.php?redirect=' . urlencode($applyUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $posting ? htmlspecialchars($posting['title']) . ' - Careers' : 'Position Not Found - Careers'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=5.2">
</head>
<body>
    <div style="max-width: 800px; margin: 0 auto; padding: 30px 20px;">
        <a href="careers.php" style="display:inline-block; margin-bottom: 20px;"><i class="fas fa-arrow-left"></i> Back to all openings</a>

        <?php if (!$posting): ?>
            <div class="data-section" style="text-align:center; padding: 60px 20px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; opacity: 0.4;"></i>
                <p style="margin-top: 16px;">This position could not be found.</p>
            </div>
        <?php else: ?>
            <div class="data-section" style="padding: 30px;">
                <h1 style="margin-top:0;"><?php echo htmlspecialchars($posting['title']); ?></h1>
                <div style="margin-bottom: 16px; color: var(--text-secondary, #666);">
                    <?php if ($posting['department']): ?>
                        <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($posting['department']); ?></span>
                    <?php endif; ?>
                    <?php if ($posting['location']): ?>
                        <span style="margin-left: 12px;"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($posting['location']); ?></span>
                    <?php endif; ?>
                    <span class="status-badge <?php echo $effectiveStatus === 'open' ? 'status-active' : 'status-inactive'; ?>" style="margin-left: 12px;">
                        <?php echo $effectiveStatus === 'open' ? 'Open' : 'Closed'; ?>
                    </span>
                </div>

                <div style="line-height: 1.6;">
                    <?php echo $posting['description']; ?>
                </div>

                <div style="margin-top: 30px;">
                    <?php if ($effectiveStatus === 'open'): ?>
                        <a href="<?php echo htmlspecialchars($applyHref); ?>" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Apply Now
                        </a>
                    <?php else: ?>
                        <button class="btn btn-secondary" disabled>
                            <i class="fas fa-lock"></i> Applications Closed
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
