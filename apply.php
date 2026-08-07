<?php
require_once 'applicant_config.php';

$jobPostingId = isset($_GET['job_posting_id']) ? intval($_GET['job_posting_id']) : (isset($_POST['job_posting_id']) ? intval($_POST['job_posting_id']) : 0);

if (!isset($_SESSION['applicant_id'])) {
    header("Location: applicant-login.php?redirect=" . urlencode('apply.php?job_posting_id=' . $jobPostingId));
    exit();
}

if (!checkSessionTimeout()) {
    header("Location: applicant-login.php?redirect=" . urlencode('apply.php?job_posting_id=' . $jobPostingId));
    exit();
}

$applicantId = $_SESSION['applicant_id'];

$ACADEMIC_OPTIONS = ["Certificate", "Diploma", "Bachelor's", "Master's", "PhD", "Professional Certification"];
$CHOICE_FIELD_TYPES = ['radio', 'dropdown', 'checkbox'];

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT id, title, status_override, open_date, close_date FROM job_postings WHERE id = ?");
$stmt->bind_param("i", $jobPostingId);
$stmt->execute();
$result = $stmt->get_result();
$posting = $result->num_rows > 0 ? $result->fetch_assoc() : null;
$stmt->close();

if (!$posting || computeEffectiveStatus($posting['status_override'], $posting['open_date'], $posting['close_date']) !== 'open') {
    $conn->close();
    header("Location: careers.php");
    exit();
}

$stmt = $conn->prepare("SELECT id, label, field_type, options, is_required FROM job_posting_questions WHERE job_posting_id = ? ORDER BY display_order ASC");
$stmt->bind_param("i", $jobPostingId);
$stmt->execute();
$qResult = $stmt->get_result();
$questions = [];
while ($q = $qResult->fetch_assoc()) {
    $q['options'] = $q['options'] ? json_decode($q['options'], true) : [];
    $questions[] = $q;
}
$stmt->close();

$stmt = $conn->prepare("SELECT full_name, email, phone FROM applicants WHERE id = ?");
$stmt->bind_param("i", $applicantId);
$stmt->execute();
$applicant = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

$technicalSkills = json_decode(getSetting('technical_skills', '[]'), true);
if (!is_array($technicalSkills)) {
    $technicalSkills = [];
}

$error = '';
$csrf_token = generateCSRFToken();

// Sticky form values so a validation error doesn't wipe what the applicant already typed
$formValues = [
    'contact_number' => $applicant['phone'] ?? '',
    'current_location' => '',
    'experience' => '',
    'expected_salary' => '',
    'nationality' => '',
    'academic_qualification' => [],
    'technical_qualification' => [],
    'answers' => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $formValues['contact_number'] = trim($_POST['contact_number'] ?? '');
        $formValues['current_location'] = trim($_POST['current_location'] ?? '');
        $formValues['experience'] = trim($_POST['experience'] ?? '');
        $formValues['expected_salary'] = trim($_POST['expected_salary'] ?? '');
        $formValues['nationality'] = trim($_POST['nationality'] ?? '');
        $formValues['academic_qualification'] = isset($_POST['academic_qualification']) && is_array($_POST['academic_qualification'])
            ? array_values(array_intersect($_POST['academic_qualification'], $ACADEMIC_OPTIONS))
            : [];
        $formValues['technical_qualification'] = isset($_POST['technical_qualification']) && is_array($_POST['technical_qualification'])
            ? array_values(array_intersect($_POST['technical_qualification'], $technicalSkills))
            : [];
        $formValues['answers'] = $_POST['answers'] ?? [];

        $files = $_FILES['files'] ?? null;

        $missingRequired = false;
        foreach ($questions as $q) {
            if (!$q['is_required']) {
                continue;
            }
            if ($q['field_type'] === 'file') {
                if (!$files || !isset($files['tmp_name'][$q['id']]) || $files['error'][$q['id']] !== UPLOAD_ERR_OK) {
                    $missingRequired = true;
                    break;
                }
            } elseif ($q['field_type'] === 'checkbox') {
                if (empty($formValues['answers'][$q['id']]) || !is_array($formValues['answers'][$q['id']])) {
                    $missingRequired = true;
                    break;
                }
            } else {
                if (trim((string)($formValues['answers'][$q['id']] ?? '')) === '') {
                    $missingRequired = true;
                    break;
                }
            }
        }

        if ($formValues['current_location'] === '') {
            $error = 'Current location is required.';
        } elseif ($missingRequired) {
            $error = 'Please fill in all required questions.';
        } else {
            try {
                $conn = getDBConnection();

                $stageResult = $conn->query("SELECT id FROM stages WHERE stage_type = 'pipeline' AND is_active = 1 ORDER BY display_order ASC LIMIT 1");
                $statusResult = $conn->query("SELECT id FROM stages WHERE stage_type = 'status' AND is_active = 1 ORDER BY display_order ASC LIMIT 1");
                $stageId = $stageResult->num_rows > 0 ? $stageResult->fetch_assoc()['id'] : null;
                $statusId = $statusResult->num_rows > 0 ? $statusResult->fetch_assoc()['id'] : null;

                if (!$stageId || !$statusId) {
                    throw new Exception('No active pipeline stages/statuses are configured. Please contact the recruitment team.');
                }

                $academicJson = json_encode($formValues['academic_qualification']);
                $technicalJson = json_encode($formValues['technical_qualification']);
                $appliedDate = date('Y-m-d');
                $contactValue = $formValues['contact_number'] !== '' ? $formValues['contact_number'] : null;
                $experienceValue = $formValues['experience'] !== '' ? $formValues['experience'] : null;
                $salaryValue = $formValues['expected_salary'] !== '' ? $formValues['expected_salary'] : null;
                $nationalityValue = $formValues['nationality'] !== '' ? $formValues['nationality'] : null;
                $currentLocation = $formValues['current_location'];

                $conn->begin_transaction();

                $stmt = $conn->prepare("INSERT INTO applications (candidate_name, email, contact_number, position, experience, academic_qualification, technical_qualification, expected_salary, nationality, current_location, stage_id, status_id, applied_date, job_posting_id, applicant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    "ssssssssssiisii",
                    $applicant['full_name'], $applicant['email'], $contactValue, $posting['title'], $experienceValue,
                    $academicJson, $technicalJson, $salaryValue, $nationalityValue, $currentLocation,
                    $stageId, $statusId, $appliedDate, $jobPostingId, $applicantId
                );
                $stmt->execute();
                $newApplicationId = $conn->insert_id;
                $stmt->close();

                $insertAnswer = $conn->prepare("INSERT INTO application_answers (application_id, question_id, answer_value) VALUES (?, ?, ?)");

                foreach ($questions as $q) {
                    $qid = $q['id'];
                    $answerValue = null;

                    if ($q['field_type'] === 'file') {
                        if ($files && isset($files['tmp_name'][$qid]) && $files['error'][$qid] === UPLOAD_ERR_OK) {
                            $fileArray = [
                                'name' => $files['name'][$qid],
                                'type' => $files['type'][$qid],
                                'tmp_name' => $files['tmp_name'][$qid],
                                'error' => $files['error'][$qid],
                                'size' => $files['size'][$qid]
                            ];
                            $uploadResult = uploadDocument($fileArray, $newApplicationId, $applicantId);
                            if ($uploadResult['success']) {
                                $answerValue = $uploadResult['filename'];
                            } else {
                                throw new Exception($uploadResult['message']);
                            }
                        }
                    } elseif ($q['field_type'] === 'checkbox') {
                        $selected = $formValues['answers'][$qid] ?? [];
                        if (is_array($selected) && !empty($selected)) {
                            $answerValue = json_encode(array_values(array_intersect($selected, $q['options'])));
                        }
                    } else {
                        $raw = trim((string)($formValues['answers'][$qid] ?? ''));
                        if ($raw !== '') {
                            if (in_array($q['field_type'], ['radio', 'dropdown']) && !in_array($raw, $q['options'])) {
                                throw new Exception('Invalid selection for question: ' . $q['label']);
                            }
                            $answerValue = $raw;
                        }
                    }

                    if ($answerValue !== null) {
                        $insertAnswer->bind_param("iis", $newApplicationId, $qid, $answerValue);
                        $insertAnswer->execute();
                    }
                }
                $insertAnswer->close();

                $conn->commit();
                $conn->close();

                header("Location: applicant-dashboard.php?submitted=1");
                exit();
            } catch (Exception $e) {
                if (isset($conn)) {
                    $conn->rollback();
                    $conn->close();
                }
                error_log("Apply.php error: " . $e->getMessage());
                $error = 'Could not submit your application: ' . $e->getMessage();
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
    <title>Apply - <?php echo htmlspecialchars($posting['title']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.11">
    <?php echo generateBrandAccentCSS(); ?>
</head>
<body>
    <div style="max-width: 800px; margin: 0 auto; padding: 30px 20px;">
        <a href="careers-job.php?id=<?php echo $jobPostingId; ?>" style="display:inline-block; margin-bottom: 20px;">
            <i class="ri-arrow-left-line"></i> Back to posting
        </a>

        <div class="data-section" style="padding: 30px;">
            <h1 style="margin-top:0;"><i class="ri-send-plane-line"></i> Apply: <?php echo htmlspecialchars($posting['title']); ?></h1>

            <?php if ($error): ?>
                <div class="error"><i class="ri-error-warning-line"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="apply.php?job_posting_id=<?php echo $jobPostingId; ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="job_posting_id" value="<?php echo $jobPostingId; ?>">

                <h3><i class="ri-user-line"></i> Your Details</h3>

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" value="<?php echo htmlspecialchars($applicant['full_name']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" value="<?php echo htmlspecialchars($applicant['email']); ?>" disabled>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" maxlength="20" value="<?php echo htmlspecialchars($formValues['contact_number']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Current Location *</label>
                        <input type="text" name="current_location" required maxlength="150" value="<?php echo htmlspecialchars($formValues['current_location']); ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Experience</label>
                        <input type="text" name="experience" maxlength="100" placeholder="e.g. 3 years" value="<?php echo htmlspecialchars($formValues['experience']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Expected Salary</label>
                        <input type="text" name="expected_salary" maxlength="50" placeholder="e.g. KES 80,000" value="<?php echo htmlspecialchars($formValues['expected_salary']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Nationality</label>
                    <input type="text" name="nationality" maxlength="80" value="<?php echo htmlspecialchars($formValues['nationality']); ?>">
                </div>

                <div class="form-group">
                    <label>Academic Qualification</label>
                    <div class="checkbox-group">
                        <?php foreach ($ACADEMIC_OPTIONS as $opt): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="academic_qualification[]" value="<?php echo htmlspecialchars($opt); ?>"
                                    <?php echo in_array($opt, $formValues['academic_qualification']) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($opt); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($technicalSkills)): ?>
                <div class="form-group">
                    <label>Technical Skills</label>
                    <div class="checkbox-group">
                        <?php foreach ($technicalSkills as $skill): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="technical_qualification[]" value="<?php echo htmlspecialchars($skill); ?>"
                                    <?php echo in_array($skill, $formValues['technical_qualification']) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($skill); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($questions)): ?>
                    <hr>
                    <h3><i class="ri-list-ordered"></i> Position Questions</h3>

                    <?php foreach ($questions as $q): ?>
                        <div class="form-group">
                            <label><?php echo htmlspecialchars($q['label']); ?><?php echo $q['is_required'] ? ' *' : ''; ?></label>

                            <?php if ($q['field_type'] === 'text'): ?>
                                <input type="text" name="answers[<?php echo $q['id']; ?>]" value="<?php echo htmlspecialchars($formValues['answers'][$q['id']] ?? ''); ?>">

                            <?php elseif ($q['field_type'] === 'textarea'): ?>
                                <textarea name="answers[<?php echo $q['id']; ?>]" rows="4"><?php echo htmlspecialchars($formValues['answers'][$q['id']] ?? ''); ?></textarea>

                            <?php elseif ($q['field_type'] === 'radio'): ?>
                                <div class="radio-group">
                                    <?php foreach ($q['options'] as $opt): ?>
                                        <label class="radio-item">
                                            <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="<?php echo htmlspecialchars($opt); ?>"
                                                <?php echo (($formValues['answers'][$q['id']] ?? '') === $opt) ? 'checked' : ''; ?>>
                                            <span><?php echo htmlspecialchars($opt); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($q['field_type'] === 'dropdown'): ?>
                                <select name="answers[<?php echo $q['id']; ?>]">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($q['options'] as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo (($formValues['answers'][$q['id']] ?? '') === $opt) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($opt); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($q['field_type'] === 'checkbox'): ?>
                                <div class="checkbox-group">
                                    <?php $selectedAnswers = $formValues['answers'][$q['id']] ?? []; ?>
                                    <?php foreach ($q['options'] as $opt): ?>
                                        <label class="checkbox-item">
                                            <input type="checkbox" name="answers[<?php echo $q['id']; ?>][]" value="<?php echo htmlspecialchars($opt); ?>"
                                                <?php echo (is_array($selectedAnswers) && in_array($opt, $selectedAnswers)) ? 'checked' : ''; ?>>
                                            <span><?php echo htmlspecialchars($opt); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($q['field_type'] === 'file'): ?>
                                <input type="file" name="files[<?php echo $q['id']; ?>]">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-send-plane-line"></i> Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
