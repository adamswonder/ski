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
$appId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Self-service document upload (AJAX)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'uploadDocument':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $uploadAppId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
                $documentLabel = isset($_POST['document_label']) ? trim($_POST['document_label']) : 'Other';

                if ($uploadAppId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                    exit();
                }

                $conn = getDBConnection();

                // Ownership check - this application must belong to the logged-in applicant
                $stmt = $conn->prepare("SELECT id FROM applications WHERE id = ? AND applicant_id = ?");
                $stmt->bind_param("ii", $uploadAppId, $applicantId);
                $stmt->execute();
                $owns = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if (!$owns) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $uploadResult = uploadDocument($_FILES['document'], $uploadAppId, $applicantId);

                if (!$uploadResult['success']) {
                    $conn->close();
                    echo json_encode($uploadResult);
                    exit();
                }

                $stmt = $conn->prepare("INSERT INTO application_documents (application_id, file_name, file_path, file_type, file_size_kb, document_label, uploaded_by_applicant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssisi",
                    $uploadAppId,
                    $uploadResult['original_name'],
                    $uploadResult['filename'],
                    $uploadResult['mime_type'],
                    $uploadResult['file_size_kb'],
                    $documentLabel,
                    $applicantId
                );

                if ($stmt->execute()) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
                } else {
                    deleteDocumentFile($uploadResult['filename']);
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to save document record: ' . $error]);
                }
                exit();

            case 'updateProfile':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $profileAppId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
                if ($profileAppId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Ownership check - this application must belong to the logged-in applicant
                $stmt = $conn->prepare("SELECT id FROM applications WHERE id = ? AND applicant_id = ?");
                $stmt->bind_param("ii", $profileAppId, $applicantId);
                $stmt->execute();
                $owns = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if (!$owns) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $contactNumber = trim($_POST['contact_number'] ?? '') ?: null;
                $currentLocation = trim($_POST['current_location'] ?? '');
                $countyOfResidence = trim($_POST['county_of_residence'] ?? '') ?: null;
                $nationality = trim($_POST['nationality'] ?? '') ?: null;
                $experience = trim($_POST['experience'] ?? '') ?: null;
                $expectedSalary = trim($_POST['expected_salary'] ?? '') ?: null;
                $availabilityNoticePeriod = trim($_POST['availability_notice_period'] ?? '') ?: null;
                $highestEducationLevel = trim($_POST['highest_education_level'] ?? '') ?: null;
                $institution = trim($_POST['institution'] ?? '') ?: null;
                $courseQualification = trim($_POST['course_qualification'] ?? '') ?: null;
                $graduationYear = !empty($_POST['graduation_year']) ? intval($_POST['graduation_year']) : null;
                $professionalCertifications = trim($_POST['professional_certifications'] ?? '') ?: null;
                $relevantTraining = trim($_POST['relevant_training'] ?? '') ?: null;
                $currentEmployer = trim($_POST['current_employer'] ?? '') ?: null;
                $currentJobTitle = trim($_POST['current_job_title'] ?? '') ?: null;
                $previousEmployers = trim($_POST['previous_employers'] ?? '') ?: null;
                $aviationExperience = trim($_POST['aviation_experience'] ?? '') ?: null;
                $customerServiceExperience = trim($_POST['customer_service_experience'] ?? '') ?: null;
                $relevantSkills = trim($_POST['relevant_skills'] ?? '') ?: null;

                $academicQual = isset($_POST['academic_qualification']) && is_array($_POST['academic_qualification'])
                    ? json_encode(array_values($_POST['academic_qualification']))
                    : null;
                $technicalQual = isset($_POST['technical_qualification']) && is_array($_POST['technical_qualification'])
                    ? json_encode(array_values($_POST['technical_qualification']))
                    : null;

                if ($currentLocation === '') {
                    echo json_encode(['success' => false, 'message' => 'Current location is required']);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE applications SET contact_number = ?, current_location = ?, county_of_residence = ?, nationality = ?, experience = ?, expected_salary = ?, availability_notice_period = ?, highest_education_level = ?, institution = ?, course_qualification = ?, graduation_year = ?, professional_certifications = ?, relevant_training = ?, current_employer = ?, current_job_title = ?, previous_employers = ?, aviation_experience = ?, customer_service_experience = ?, relevant_skills = ?, academic_qualification = ?, technical_qualification = ? WHERE id = ?");
                $stmt->bind_param(
                    "ssssssssssissssssssssi",
                    $contactNumber, $currentLocation, $countyOfResidence, $nationality, $experience,
                    $expectedSalary, $availabilityNoticePeriod, $highestEducationLevel, $institution,
                    $courseQualification, $graduationYear, $professionalCertifications, $relevantTraining,
                    $currentEmployer, $currentJobTitle, $previousEmployers, $aviationExperience,
                    $customerServiceExperience, $relevantSkills, $academicQual, $technicalQual, $profileAppId
                );

                if ($stmt->execute()) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update profile: ' . $error]);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("applicant-application.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']);
        exit();
    }
}

if ($appId <= 0) {
    header("Location: applicant-dashboard.php");
    exit();
}

$applicant_name = $_SESSION['applicant_name'];
$current_page = 'applicant-application';

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT a.*, p_s.name AS stage_name, p_s.color AS stage_color, p_s.icon AS stage_icon,
                                st_s.name AS status_name, st_s.color AS status_color,
                                jp.title AS posting_title, jp.department AS posting_department, jp.location AS posting_location,
                                ivagg.latest_interview_date
                         FROM applications a
                         LEFT JOIN stages p_s ON a.stage_id = p_s.id
                         LEFT JOIN stages st_s ON a.status_id = st_s.id
                         LEFT JOIN job_postings jp ON a.job_posting_id = jp.id
                         LEFT JOIN (
                             SELECT application_id, MAX(interview_date) AS latest_interview_date
                             FROM application_interviews
                             GROUP BY application_id
                         ) ivagg ON ivagg.application_id = a.id
                         WHERE a.id = ? AND a.applicant_id = ?");
$stmt->bind_param("ii", $appId, $applicantId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header("Location: applicant-dashboard.php");
    exit();
}

$app = $result->fetch_assoc();
$stmt->close();

// Pipeline stages, for the progress tracker
$stmt = $conn->prepare("SELECT id, name, icon, display_order FROM stages WHERE stage_type = 'pipeline' AND is_active = 1 ORDER BY display_order ASC");
$stmt->execute();
$pipelineStages = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pipelineStages[] = $row;
}
$stmt->close();

$currentStageOrder = null;
foreach ($pipelineStages as $s) {
    if ($s['id'] == $app['stage_id']) {
        $currentStageOrder = $s['display_order'];
        break;
    }
}




// Custom application question answers (submitted with the application)
$questionAnswers = [];
if ($app['job_posting_id']) {
    $stmt = $conn->prepare("SELECT q.label, q.field_type, aa.answer_value
                             FROM application_answers aa
                             JOIN job_posting_questions q ON aa.question_id = q.id
                             WHERE aa.application_id = ?
                             ORDER BY q.display_order ASC");
    $stmt->bind_param("i", $appId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $questionAnswers[] = $row;
    }
    $stmt->close();
}

// Documents attached to this application
$stmt = $conn->prepare("SELECT id, file_name, file_path, document_label, uploaded_at FROM application_documents WHERE application_id = ? ORDER BY uploaded_at DESC");
$stmt->bind_param("i", $appId);
$stmt->execute();
$documents = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $documents[] = $row;
}
$stmt->close();

$conn->close();

$academicQual = $app['academic_qualification'] ? json_decode($app['academic_qualification'], true) : [];
$technicalQual = $app['technical_qualification'] ? json_decode($app['technical_qualification'], true) : [];

$ACADEMIC_OPTIONS = ["Certificate", "Diploma", "Bachelor's", "Master's", "PhD", "Professional Certification"];
$technicalSkillsOptions = json_decode(getSetting('technical_skills', '[]'), true);
if (!is_array($technicalSkillsOptions)) {
    $technicalSkillsOptions = [];
}

$loginLogo = getSetting('login_logo', '');
$logoUrl = !empty($loginLogo) ? htmlspecialchars($loginLogo) : 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($app['candidate_name']); ?> - Application Detail</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.12">
    <?php echo generateBrandAccentCSS(); ?>
    <style>
        .aa-summary { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 24px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .aa-summary h2 { margin: 0 0 4px; }
        .aa-summary-meta { color: var(--text-secondary); font-size: 14px; display: flex; flex-wrap: wrap; gap: 14px; margin-top: 8px; }
        .aa-summary-meta span { display: flex; align-items: center; gap: 5px; }
        .aa-summary-badges { display: flex; gap: 8px; flex-wrap: wrap; }

        .aa-progress { display: flex; align-items: center; flex-wrap: wrap; gap: 0; margin-bottom: 24px; }
        .aa-progress-step { display: flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px; background: var(--bg-card); border: 1px solid var(--border-color); font-size: 13px; font-weight: 600; color: var(--text-muted); }
        .aa-progress-step.done { background: color-mix(in srgb, var(--navy-accent) 14%, var(--bg-card)); border-color: var(--navy-accent); color: var(--navy-accent); }
        .aa-progress-step.current { background: var(--navy-primary); border-color: var(--navy-primary); color: #fff; }
        .aa-progress-arrow { color: var(--border-color); margin: 0 4px; font-size: 16px; }

        .aa-grid { display: grid; grid-template-columns: 1fr 1fr; column-gap: 32px; row-gap: 20px; margin-bottom: 8px; }
        .aa-field-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 6px; }
        .aa-field-value { font-size: 14px; color: var(--text-primary); margin-bottom: 16px; }
        .aa-doc-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border-light); }
        .aa-doc-row:last-child { border-bottom: none; }

        @media (max-width: 640px) {
            .aa-grid { grid-template-columns: 1fr; }
            .aa-summary { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <?php include 'applicant-mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'applicant-sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <h1><i class="ri-file-user-line"></i> Application Detail</h1>
                <a href="applicant-dashboard.php" class="btn btn-secondary btn-sm"><i class="ri-arrow-left-line"></i> Back to My Applications</a>
            </div>

        <!-- Summary Card -->
        <div class="aa-summary">
            <div>
                <h2><?php echo htmlspecialchars($app['candidate_name']); ?></h2>
                <div><?php echo htmlspecialchars($app['position']); ?><?php echo $app['posting_location'] ? ' &ndash; ' . htmlspecialchars($app['posting_location']) : ''; ?></div>
                <div class="aa-summary-meta">
                    <span><i class="ri-hashtag"></i> <?php echo htmlspecialchars($app['recruitment_reference'] ?: ('APP-' . $app['id'])); ?></span>
                    <span><i class="ri-calendar-line"></i> Applied <?php echo date('M d, Y', strtotime($app['applied_date'])); ?></span>
                    <?php if ($app['latest_interview_date']): ?>
                        <span><i class="ri-calendar-check-line"></i> Interview scheduled: <?php echo date('M d, Y', strtotime($app['latest_interview_date'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="aa-summary-badges">
                <?php if ($app['stage_name']): ?>
                    <span class="stage-badge" style="color:<?php echo htmlspecialchars($app['stage_color']); ?>"><?php echo htmlspecialchars($app['stage_name']); ?></span>
                <?php endif; ?>
                <?php if ($app['status_name']): ?>
                    <span class="stage-badge" style="color:<?php echo htmlspecialchars($app['status_color']); ?>"><?php echo htmlspecialchars($app['status_name']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Progress -->
        <?php if (!empty($pipelineStages)): ?>
        <div class="data-section" style="margin-bottom: 24px;">
            <h3 style="margin: 0 0 20px;"><i class="ri-route-line"></i> Application Progress</h3>
            <div class="aa-progress">
                <?php foreach ($pipelineStages as $i => $s): ?>
                    <?php
                        $stepClass = 'aa-progress-step';
                        if ($currentStageOrder !== null) {
                            if ($s['display_order'] < $currentStageOrder) $stepClass .= ' done';
                            elseif ($s['display_order'] == $currentStageOrder) $stepClass .= ' current';
                        }
                    ?>
                    <span class="<?php echo $stepClass; ?>">
                        <?php if ($s['icon']): ?><i class="<?php echo htmlspecialchars($s['icon']); ?>"></i><?php endif; ?>
                        <?php echo htmlspecialchars($s['name']); ?>
                    </span>
                    <?php if ($i < count($pipelineStages) - 1): ?><i class="ri-arrow-right-s-line aa-progress-arrow"></i><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Application Details -->
        <div class="data-section" style="margin-bottom: 24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h3 style="margin: 0;"><i class="ri-file-user-line"></i> Application Details</h3>
                <button type="button" class="btn btn-secondary btn-sm" id="profileEditToggleBtn" onclick="toggleProfileEdit()">
                    <i class="ri-edit-line"></i> Edit
                </button>
            </div>

            <div id="profileViewMode">
                <div class="aa-grid">
                    <div>
                        <div class="aa-field-label">Email</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['email']); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Contact Number</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['contact_number'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Current Location</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['current_location'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">County of Residence</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['county_of_residence'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Nationality</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['nationality'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Experience</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['experience'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Expected Salary</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['expected_salary'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Availability / Notice Period</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['availability_notice_period'] ?: '—'); ?></div>
                    </div>
                </div>

                <?php if ($app['highest_education_level'] || $app['institution'] || $app['course_qualification']): ?>
                <h4 class="form-section-title"><i class="ri-graduation-cap-line"></i> Education</h4>
                <div class="aa-grid">
                    <div>
                        <div class="aa-field-label">Highest Education Level</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['highest_education_level'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Institution</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['institution'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Course / Qualification</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['course_qualification'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Graduation Year</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['graduation_year'] ?: '—'); ?></div>
                    </div>
                </div>
                <?php if ($app['professional_certifications']): ?><div class="aa-field-label">Professional Certifications</div><div class="aa-field-value"><?php echo nl2br(htmlspecialchars($app['professional_certifications'])); ?></div><?php endif; ?>
                <?php if ($app['relevant_training']): ?><div class="aa-field-label">Relevant Training</div><div class="aa-field-value"><?php echo nl2br(htmlspecialchars($app['relevant_training'])); ?></div><?php endif; ?>
                <?php endif; ?>

                <?php if ($app['current_employer'] || $app['current_job_title'] || $app['previous_employers'] || $app['aviation_experience'] || $app['customer_service_experience'] || $app['relevant_skills']): ?>
                <h4 class="form-section-title"><i class="ri-briefcase-4-line"></i> Work Experience</h4>
                <div class="aa-grid">
                    <div>
                        <div class="aa-field-label">Current / Most Recent Employer</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['current_employer'] ?: '—'); ?></div>
                    </div>
                    <div>
                        <div class="aa-field-label">Job Title</div>
                        <div class="aa-field-value"><?php echo htmlspecialchars($app['current_job_title'] ?: '—'); ?></div>
                    </div>
                </div>
                <?php if ($app['previous_employers']): ?><div class="aa-field-label">Previous Employers</div><div class="aa-field-value"><?php echo nl2br(htmlspecialchars($app['previous_employers'])); ?></div><?php endif; ?>
                <?php if ($app['aviation_experience']): ?><div class="aa-field-label">Aviation Experience</div><div class="aa-field-value"><?php echo nl2br(htmlspecialchars($app['aviation_experience'])); ?></div><?php endif; ?>
                <?php if ($app['customer_service_experience']): ?><div class="aa-field-label">Customer Service Experience</div><div class="aa-field-value"><?php echo nl2br(htmlspecialchars($app['customer_service_experience'])); ?></div><?php endif; ?>
                <?php if ($app['relevant_skills']): ?><div class="aa-field-label">Relevant Skills</div><div class="aa-field-value"><?php echo nl2br(htmlspecialchars($app['relevant_skills'])); ?></div><?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($academicQual) || !empty($technicalQual)): ?>
                <h4 class="form-section-title"><i class="ri-award-line"></i> Qualifications</h4>
                <div style="margin-bottom: 10px;">
                    <?php foreach ($academicQual as $q): ?><span class="doc-qual-tag"><?php echo htmlspecialchars($q); ?></span><?php endforeach; ?>
                    <?php foreach ($technicalQual as $q): ?><span class="doc-qual-tag"><?php echo htmlspecialchars($q); ?></span><?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($questionAnswers)): ?>
                <h4 class="form-section-title"><i class="ri-question-answer-line"></i> Your Responses</h4>
                <?php foreach ($questionAnswers as $qa): ?>
                    <div class="aa-field-label"><?php echo htmlspecialchars($qa['label']); ?></div>
                    <div class="aa-field-value">
                        <?php if ($qa['field_type'] === 'file' && $qa['answer_value']): ?>
                            <a href="<?php echo htmlspecialchars($qa['answer_value']); ?>" target="_blank"><i class="ri-file-line"></i> View uploaded file</a>
                        <?php else: ?>
                            <?php echo htmlspecialchars($qa['answer_value'] ?: '—'); ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="profileEditMode" style="display:none;">
                <form id="profileEditForm">
                    <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="contact_number" maxlength="20" value="<?php echo htmlspecialchars($app['contact_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Current Location *</label>
                            <input type="text" name="current_location" required maxlength="150" value="<?php echo htmlspecialchars($app['current_location'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>County of Residence</label>
                            <input type="text" name="county_of_residence" maxlength="100" value="<?php echo htmlspecialchars($app['county_of_residence'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Nationality</label>
                            <input type="text" name="nationality" maxlength="80" value="<?php echo htmlspecialchars($app['nationality'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Experience</label>
                            <input type="text" name="experience" maxlength="100" placeholder="e.g. 3 years" value="<?php echo htmlspecialchars($app['experience'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Expected Salary</label>
                            <input type="text" name="expected_salary" maxlength="50" placeholder="e.g. KES 80,000" value="<?php echo htmlspecialchars($app['expected_salary'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Availability / Notice Period</label>
                            <input type="text" name="availability_notice_period" maxlength="100" placeholder="e.g. 30 days" value="<?php echo htmlspecialchars($app['availability_notice_period'] ?? ''); ?>">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="ri-graduation-cap-line"></i> Education</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Highest Education Level</label>
                            <input type="text" name="highest_education_level" maxlength="100" value="<?php echo htmlspecialchars($app['highest_education_level'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Institution</label>
                            <input type="text" name="institution" maxlength="150" value="<?php echo htmlspecialchars($app['institution'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Course / Qualification</label>
                            <input type="text" name="course_qualification" maxlength="150" value="<?php echo htmlspecialchars($app['course_qualification'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Graduation Year</label>
                            <input type="number" name="graduation_year" min="1950" max="2100" value="<?php echo htmlspecialchars($app['graduation_year'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Professional Certifications</label>
                        <textarea name="professional_certifications" rows="2"><?php echo htmlspecialchars($app['professional_certifications'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Relevant Training</label>
                        <textarea name="relevant_training" rows="2"><?php echo htmlspecialchars($app['relevant_training'] ?? ''); ?></textarea>
                    </div>

                    <h4 class="form-section-title"><i class="ri-briefcase-4-line"></i> Work Experience</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Current / Most Recent Employer</label>
                            <input type="text" name="current_employer" maxlength="150" value="<?php echo htmlspecialchars($app['current_employer'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Job Title</label>
                            <input type="text" name="current_job_title" maxlength="150" value="<?php echo htmlspecialchars($app['current_job_title'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Previous Employers</label>
                        <textarea name="previous_employers" rows="2"><?php echo htmlspecialchars($app['previous_employers'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Aviation Experience</label>
                        <textarea name="aviation_experience" rows="2"><?php echo htmlspecialchars($app['aviation_experience'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Customer Service Experience</label>
                        <textarea name="customer_service_experience" rows="2"><?php echo htmlspecialchars($app['customer_service_experience'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Relevant Skills</label>
                        <textarea name="relevant_skills" rows="2"><?php echo htmlspecialchars($app['relevant_skills'] ?? ''); ?></textarea>
                    </div>

                    <h4 class="form-section-title"><i class="ri-award-line"></i> Qualifications</h4>
                    <div class="form-group">
                        <label>Academic Qualification</label>
                        <div class="checkbox-group">
                            <?php foreach ($ACADEMIC_OPTIONS as $opt): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="academic_qualification[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo in_array($opt, $academicQual) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($opt); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if (!empty($technicalSkillsOptions)): ?>
                    <div class="form-group">
                        <label>Technical Skills</label>
                        <div class="checkbox-group">
                            <?php foreach ($technicalSkillsOptions as $skill): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="technical_qualification[]" value="<?php echo htmlspecialchars($skill); ?>" <?php echo in_array($skill, $technicalQual) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($skill); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Changes</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleProfileEdit()"><i class="ri-close-line"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Documents -->
        <div class="data-section">
            <h3 style="margin: 0 0 20px;"><i class="ri-attachment-line"></i> Documents</h3>

            <?php if (empty($documents)): ?>
                <p style="color: var(--text-muted);">No documents on file yet.</p>
            <?php else: ?>
                <?php foreach ($documents as $doc): ?>
                    <div class="aa-doc-row">
                        <div><i class="ri-file-line"></i> <?php echo htmlspecialchars($doc['file_name']); ?> <span style="color: var(--text-muted); font-size: 12px;">(<?php echo htmlspecialchars($doc['document_label']); ?>)</span></div>
                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="ri-download-line"></i> Download</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <h4 class="form-section-title" style="margin-top: 20px;"><i class="ri-upload-cloud-2-line"></i> Upload a Document</h4>
            <form id="docUploadForm" enctype="multipart/form-data">
                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Document Label</label>
                        <select id="docLabel" name="document_label">
                            <option value="CV">CV / Resume</option>
                            <option value="Cover Letter">Cover Letter</option>
                            <option value="Certificate">Certificate</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Select File *</label>
                        <input type="file" id="docFile" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp" required>
                    </div>
                </div>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;"><i class="ri-information-line"></i> Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, WEBP (Max 15MB)</p>
                <button type="submit" class="btn btn-primary"><i class="ri-upload-line"></i> Upload</button>
            </form>
        </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function toggleProfileEdit() {
            var viewMode = document.getElementById('profileViewMode');
            var editMode = document.getElementById('profileEditMode');
            var btn = document.getElementById('profileEditToggleBtn');
            var isEditing = editMode.style.display !== 'none';

            if (isEditing) {
                editMode.style.display = 'none';
                viewMode.style.display = 'block';
                btn.innerHTML = '<i class="ri-edit-line"></i> Edit';
            } else {
                viewMode.style.display = 'none';
                editMode.style.display = 'block';
                btn.innerHTML = '<i class="ri-close-line"></i> Cancel';
            }
        }

        document.getElementById('profileEditForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);

            Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '?action=updateProfile',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Saved!', timer: 1200, showConfirmButton: false })
                            .then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        document.getElementById('docUploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);

            Swal.fire({ title: 'Uploading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '?action=uploadDocument',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Uploaded!', timer: 1200, showConfirmButton: false })
                            .then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });
    </script>
</body>
</html>
