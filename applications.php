<?php

require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'applications';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getApplications':
                $conn = getDBConnection();

                $canViewSensitiveNotes = hasPermission($user_id, $role, 'view_sensitive_notes');

                $sql = "SELECT a.id, a.candidate_name, a.email, a.contact_number, a.position, a.company,
                               a.experience, a.academic_qualification, a.technical_qualification,
                               a.expected_salary, a.nationality, a.current_location,
                               a.recruitment_reference, a.county_of_residence,
                               a.highest_education_level, a.institution, a.course_qualification, a.graduation_year,
                               a.professional_certifications, a.relevant_training,
                               a.current_employer, a.current_job_title, a.previous_employers,
                               a.aviation_experience, a.customer_service_experience, a.relevant_skills,
                               a.availability_notice_period, a.rejection_reason, a.hr_comments,
                               a.stage_id, a.status_id, a.next_action, a.next_action_date,
                               a.applied_date, a.joined_date, a.days_to_join, a.notes,
                               a.assigned_to, a.created_by, a.created_at, a.updated_at,
                               s.name AS stage_name, s.color AS stage_color, s.icon AS stage_icon,
                               st.name AS status_name, st.color AS status_color, st.icon AS status_icon,
                               u.full_name AS assigned_to_name,
                               c.full_name AS created_by_name,
                               COALESCE(dc.doc_count, 0) AS document_count,
                               sc.eligibility_pass, sc.min_qualification_pass, sc.required_experience_pass,
                               sc.location_requirement_pass, sc.screening_score, sc.recruiter_comments,
                               scu.full_name AS screened_by_name, sc.screened_at,
                               asm.assessment_score, asm.assessment_comments,
                               asmu.full_name AS assessed_by_name, asm.assessed_at,
                               ivagg.interview_count, ivagg.avg_interview_score, ivagg.latest_interview_date
                        FROM applications a
                        LEFT JOIN stages s ON a.stage_id = s.id
                        LEFT JOIN stages st ON a.status_id = st.id
                        LEFT JOIN users u ON a.assigned_to = u.id
                        LEFT JOIN users c ON a.created_by = c.id
                        LEFT JOIN (
                            SELECT application_id, COUNT(*) AS doc_count
                            FROM application_documents
                            GROUP BY application_id
                        ) dc ON dc.application_id = a.id
                        LEFT JOIN application_screening sc ON sc.application_id = a.id
                        LEFT JOIN users scu ON sc.screened_by = scu.id
                        LEFT JOIN application_assessment asm ON asm.application_id = a.id
                        LEFT JOIN users asmu ON asm.assessed_by = asmu.id
                        LEFT JOIN (
                            SELECT application_id, COUNT(*) AS interview_count,
                                   AVG(interview_score) AS avg_interview_score,
                                   MAX(interview_date) AS latest_interview_date
                            FROM application_interviews
                            GROUP BY application_id
                        ) ivagg ON ivagg.application_id = a.id";

                // Regular users see only applications assigned to them
                if ($role !== 'admin') {
                    $sql .= " WHERE a.assigned_to = ?";
                }

                $sql .= " ORDER BY a.id DESC";

                $stmt = $conn->prepare($sql);
                if ($role !== 'admin') {
                    $stmt->bind_param("i", $user_id);
                }
                $stmt->execute();
                $result = $stmt->get_result();

                $applications = [];
                while ($row = $result->fetch_assoc()) {
                    $applications[] = [
                        'id' => $row['id'],
                        'candidate_name' => $row['candidate_name'],
                        'email' => $row['email'],
                        'contact_number' => $row['contact_number'],
                        'position' => $row['position'],
                        'company' => $row['company'],
                        'experience' => $row['experience'],
                        'academic_qualification' => $row['academic_qualification'] ? json_decode($row['academic_qualification'], true) : [],
                        'technical_qualification' => $row['technical_qualification'] ? json_decode($row['technical_qualification'], true) : [],
                        'expected_salary' => $row['expected_salary'],
                        'nationality' => $row['nationality'],
                        'current_location' => $row['current_location'],
                        'recruitment_reference' => $row['recruitment_reference'],
                        'county_of_residence' => $row['county_of_residence'],
                        'highest_education_level' => $row['highest_education_level'],
                        'institution' => $row['institution'],
                        'course_qualification' => $row['course_qualification'],
                        'graduation_year' => $row['graduation_year'],
                        'professional_certifications' => $row['professional_certifications'],
                        'relevant_training' => $row['relevant_training'],
                        'current_employer' => $row['current_employer'],
                        'current_job_title' => $row['current_job_title'],
                        'previous_employers' => $row['previous_employers'],
                        'aviation_experience' => $row['aviation_experience'],
                        'customer_service_experience' => $row['customer_service_experience'],
                        'relevant_skills' => $row['relevant_skills'],
                        'availability_notice_period' => $row['availability_notice_period'],
                        'rejection_reason' => $canViewSensitiveNotes ? $row['rejection_reason'] : null,
                        'hr_comments' => $canViewSensitiveNotes ? $row['hr_comments'] : null,
                        'stage_id' => $row['stage_id'],
                        'stage_name' => $row['stage_name'],
                        'stage_color' => $row['stage_color'],
                        'stage_icon' => $row['stage_icon'],
                        'status_id' => $row['status_id'],
                        'status_name' => $row['status_name'],
                        'status_color' => $row['status_color'],
                        'status_icon' => $row['status_icon'],
                        'next_action' => $row['next_action'],
                        'next_action_date' => $row['next_action_date'],
                        'applied_date' => $row['applied_date'],
                        'joined_date' => $row['joined_date'],
                        'days_to_join' => $row['days_to_join'],
                        'notes' => $row['notes'],
                        'assigned_to' => $row['assigned_to'],
                        'assigned_to_name' => $row['assigned_to_name'],
                        'screening' => [
                            'eligibility_pass' => $row['eligibility_pass'],
                            'min_qualification_pass' => $row['min_qualification_pass'],
                            'required_experience_pass' => $row['required_experience_pass'],
                            'location_requirement_pass' => $row['location_requirement_pass'],
                            'screening_score' => $row['screening_score'],
                            'recruiter_comments' => $canViewSensitiveNotes ? $row['recruiter_comments'] : null,
                            'screened_by_name' => $row['screened_by_name'],
                            'screened_at' => $row['screened_at']
                        ],
                        'assessment' => [
                            'assessment_score' => $row['assessment_score'],
                            'assessment_comments' => $row['assessment_comments'],
                            'assessed_by_name' => $row['assessed_by_name'],
                            'assessed_at' => $row['assessed_at']
                        ],
                        'interview' => [
                            'interview_count' => intval($row['interview_count']),
                            'avg_interview_score' => $row['avg_interview_score'] !== null ? round($row['avg_interview_score'], 1) : null,
                            'latest_interview_date' => $row['latest_interview_date']
                        ],
                        'overall_score' => computeOverallScore($row['screening_score'], $row['assessment_score'], $row['avg_interview_score']),
                        'created_by' => $row['created_by'],
                        'created_by_name' => $row['created_by_name'],
                        'created_at' => date('M d, Y', strtotime($row['created_at'])),
                        'applied_date_display' => $row['applied_date'] ? date('M d, Y', strtotime($row['applied_date'])) : '',
                        'joined_date_display' => $row['joined_date'] ? date('M d, Y', strtotime($row['joined_date'])) : '',
                        'next_action_date_display' => $row['next_action_date'] ? date('M d, Y', strtotime($row['next_action_date'])) : '',
                        'days_elapsed' => $row['applied_date'] ? (function() use ($row) {
                            $start = new DateTime($row['applied_date']);
                            $end = $row['joined_date'] ? new DateTime($row['joined_date']) : new DateTime();
                            return intval($start->diff($end)->days);
                        })() : null,
                        'document_count' => intval($row['document_count'])
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $applications]);
                exit();

            case 'getFormData':
                $conn = getDBConnection();

                // Get active pipeline stages
                $stmt = $conn->prepare("SELECT id, name, color, icon FROM stages WHERE stage_type = 'pipeline' AND is_active = 1 ORDER BY display_order ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                $pipelineStages = [];
                while ($row = $result->fetch_assoc()) {
                    $pipelineStages[] = $row;
                }
                $stmt->close();

                // Get active statuses
                $stmt = $conn->prepare("SELECT id, name, color, icon FROM stages WHERE stage_type = 'status' AND is_active = 1 ORDER BY display_order ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                $statuses = [];
                while ($row = $result->fetch_assoc()) {
                    $statuses[] = $row;
                }
                $stmt->close();

                // Get active users (for assigned_to dropdown)
                $stmt = $conn->prepare("SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                $users = [];
                while ($row = $result->fetch_assoc()) {
                    $users[] = $row;
                }
                $stmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'pipeline_stages' => $pipelineStages,
                    'statuses' => $statuses,
                    'users' => $users
                ]);
                exit();

            case 'addApplication':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $candidateName = isset($_POST['candidate_name']) ? trim($_POST['candidate_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $contactNumber = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
                $position = isset($_POST['position']) ? trim($_POST['position']) : '';
                $company = isset($_POST['company']) ? trim($_POST['company']) : null;
                $experience = isset($_POST['experience']) ? trim($_POST['experience']) : null;
                $expectedSalary = isset($_POST['expected_salary']) ? trim($_POST['expected_salary']) : null;
                $nationality = isset($_POST['nationality']) ? trim($_POST['nationality']) : null;
                $currentLocation = isset($_POST['current_location']) ? trim($_POST['current_location']) : null;
                $stageId = isset($_POST['stage_id']) ? intval($_POST['stage_id']) : 0;
                $statusId = isset($_POST['status_id']) ? intval($_POST['status_id']) : 0;
                $nextAction = isset($_POST['next_action']) ? trim($_POST['next_action']) : null;
                $nextActionDate = isset($_POST['next_action_date']) && !empty($_POST['next_action_date']) ? $_POST['next_action_date'] : null;
                $appliedDate = isset($_POST['applied_date']) ? $_POST['applied_date'] : '';
                $joinedDate = isset($_POST['joined_date']) && !empty($_POST['joined_date']) ? $_POST['joined_date'] : null;
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
                $assignedTo = isset($_POST['assigned_to']) && !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;

                // Candidate profile: education, work experience, county (editable by anyone who can edit the application)
                $countyOfResidence = isset($_POST['county_of_residence']) ? trim($_POST['county_of_residence']) : null;
                $highestEducationLevel = isset($_POST['highest_education_level']) ? trim($_POST['highest_education_level']) : null;
                $institution = isset($_POST['institution']) ? trim($_POST['institution']) : null;
                $courseQualification = isset($_POST['course_qualification']) ? trim($_POST['course_qualification']) : null;
                $graduationYear = isset($_POST['graduation_year']) && !empty($_POST['graduation_year']) ? intval($_POST['graduation_year']) : null;
                $professionalCertifications = isset($_POST['professional_certifications']) ? trim($_POST['professional_certifications']) : null;
                $relevantTraining = isset($_POST['relevant_training']) ? trim($_POST['relevant_training']) : null;
                $currentEmployer = isset($_POST['current_employer']) ? trim($_POST['current_employer']) : null;
                $currentJobTitle = isset($_POST['current_job_title']) ? trim($_POST['current_job_title']) : null;
                $previousEmployers = isset($_POST['previous_employers']) ? trim($_POST['previous_employers']) : null;
                $aviationExperience = isset($_POST['aviation_experience']) ? trim($_POST['aviation_experience']) : null;
                $customerServiceExperience = isset($_POST['customer_service_experience']) ? trim($_POST['customer_service_experience']) : null;
                $relevantSkills = isset($_POST['relevant_skills']) ? trim($_POST['relevant_skills']) : null;
                $availabilityNoticePeriod = isset($_POST['availability_notice_period']) ? trim($_POST['availability_notice_period']) : null;

                // Sensitive notes: only accepted from users with view_sensitive_notes (silently ignored otherwise)
                $canViewSensitiveNotes = hasPermission($user_id, $role, 'view_sensitive_notes');
                $rejectionReason = $canViewSensitiveNotes && isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null;
                $hrComments = $canViewSensitiveNotes && isset($_POST['hr_comments']) ? trim($_POST['hr_comments']) : null;

                // Handle multi-select qualifications (sent as comma-separated or JSON)
                $academicQual = isset($_POST['academic_qualification']) ? $_POST['academic_qualification'] : '';
                $technicalQual = isset($_POST['technical_qualification']) ? $_POST['technical_qualification'] : '';

                // Convert to JSON arrays
                if (is_string($academicQual) && !empty($academicQual)) {
                    $academicQual = json_encode(array_map('trim', explode(',', $academicQual)));
                } else if (is_array($academicQual)) {
                    $academicQual = json_encode($academicQual);
                } else {
                    $academicQual = null;
                }

                if (is_string($technicalQual) && !empty($technicalQual)) {
                    $technicalQual = json_encode(array_map('trim', explode(',', $technicalQual)));
                } else if (is_array($technicalQual)) {
                    $technicalQual = json_encode($technicalQual);
                } else {
                    $technicalQual = null;
                }

                if (empty($candidateName) || empty($email) || empty($position) || $stageId <= 0 || $statusId <= 0 || empty($appliedDate)) {
                    echo json_encode(['success' => false, 'message' => 'Candidate name, email, position, stage, status and applied date are required']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                $conn = getDBConnection();

                $stmt = $conn->prepare("INSERT INTO applications (candidate_name, email, contact_number, position, company, experience, academic_qualification, technical_qualification, expected_salary, nationality, current_location, county_of_residence, highest_education_level, institution, course_qualification, graduation_year, professional_certifications, relevant_training, current_employer, current_job_title, previous_employers, aviation_experience, customer_service_experience, relevant_skills, availability_notice_period, rejection_reason, hr_comments, stage_id, status_id, next_action, next_action_date, applied_date, joined_date, notes, assigned_to, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $createdBy = $user_id;
                $stmt->bind_param("sssssssssssssssisssssssssssiisssssii",
                    $candidateName, $email, $contactNumber, $position, $company,
                    $experience, $academicQual, $technicalQual, $expectedSalary,
                    $nationality, $currentLocation, $countyOfResidence, $highestEducationLevel,
                    $institution, $courseQualification, $graduationYear, $professionalCertifications,
                    $relevantTraining, $currentEmployer, $currentJobTitle, $previousEmployers,
                    $aviationExperience, $customerServiceExperience, $relevantSkills, $availabilityNoticePeriod,
                    $rejectionReason, $hrComments, $stageId, $statusId,
                    $nextAction, $nextActionDate, $appliedDate, $joinedDate,
                    $notes, $assignedTo, $createdBy
                );

                if ($stmt->execute()) {
                    $newAppId = $conn->insert_id;
                    generateRecruitmentReference($conn, $newAppId, $appliedDate);
                    logActivity($user_id, 'CREATE', "Created application for: $candidateName - $position", ['module' => 'applications', 'application_id' => $newAppId]);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Application added successfully', 'application_id' => $newAppId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add application: ' . $error]);
                }
                exit();

            case 'updateApplication':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $appId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                $candidateName = isset($_POST['candidate_name']) ? trim($_POST['candidate_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $contactNumber = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
                $position = isset($_POST['position']) ? trim($_POST['position']) : '';
                $company = isset($_POST['company']) ? trim($_POST['company']) : null;
                $experience = isset($_POST['experience']) ? trim($_POST['experience']) : null;
                $expectedSalary = isset($_POST['expected_salary']) ? trim($_POST['expected_salary']) : null;
                $nationality = isset($_POST['nationality']) ? trim($_POST['nationality']) : null;
                $currentLocation = isset($_POST['current_location']) ? trim($_POST['current_location']) : null;
                $stageId = isset($_POST['stage_id']) ? intval($_POST['stage_id']) : 0;
                $statusId = isset($_POST['status_id']) ? intval($_POST['status_id']) : 0;
                $nextAction = isset($_POST['next_action']) ? trim($_POST['next_action']) : null;
                $nextActionDate = isset($_POST['next_action_date']) && !empty($_POST['next_action_date']) ? $_POST['next_action_date'] : null;
                $appliedDate = isset($_POST['applied_date']) ? $_POST['applied_date'] : '';
                $joinedDate = isset($_POST['joined_date']) && !empty($_POST['joined_date']) ? $_POST['joined_date'] : null;
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
                $assignedTo = isset($_POST['assigned_to']) && !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;

                // Candidate profile: education, work experience, county (editable by anyone who can edit the application)
                $countyOfResidence = isset($_POST['county_of_residence']) ? trim($_POST['county_of_residence']) : null;
                $highestEducationLevel = isset($_POST['highest_education_level']) ? trim($_POST['highest_education_level']) : null;
                $institution = isset($_POST['institution']) ? trim($_POST['institution']) : null;
                $courseQualification = isset($_POST['course_qualification']) ? trim($_POST['course_qualification']) : null;
                $graduationYear = isset($_POST['graduation_year']) && !empty($_POST['graduation_year']) ? intval($_POST['graduation_year']) : null;
                $professionalCertifications = isset($_POST['professional_certifications']) ? trim($_POST['professional_certifications']) : null;
                $relevantTraining = isset($_POST['relevant_training']) ? trim($_POST['relevant_training']) : null;
                $currentEmployer = isset($_POST['current_employer']) ? trim($_POST['current_employer']) : null;
                $currentJobTitle = isset($_POST['current_job_title']) ? trim($_POST['current_job_title']) : null;
                $previousEmployers = isset($_POST['previous_employers']) ? trim($_POST['previous_employers']) : null;
                $aviationExperience = isset($_POST['aviation_experience']) ? trim($_POST['aviation_experience']) : null;
                $customerServiceExperience = isset($_POST['customer_service_experience']) ? trim($_POST['customer_service_experience']) : null;
                $relevantSkills = isset($_POST['relevant_skills']) ? trim($_POST['relevant_skills']) : null;
                $availabilityNoticePeriod = isset($_POST['availability_notice_period']) ? trim($_POST['availability_notice_period']) : null;

                // Handle multi-select qualifications
                $academicQual = isset($_POST['academic_qualification']) ? $_POST['academic_qualification'] : '';
                $technicalQual = isset($_POST['technical_qualification']) ? $_POST['technical_qualification'] : '';

                if (is_string($academicQual) && !empty($academicQual)) {
                    $academicQual = json_encode(array_map('trim', explode(',', $academicQual)));
                } else if (is_array($academicQual)) {
                    $academicQual = json_encode($academicQual);
                } else {
                    $academicQual = null;
                }

                if (is_string($technicalQual) && !empty($technicalQual)) {
                    $technicalQual = json_encode(array_map('trim', explode(',', $technicalQual)));
                } else if (is_array($technicalQual)) {
                    $technicalQual = json_encode($technicalQual);
                } else {
                    $technicalQual = null;
                }

                if ($appId <= 0 || empty($candidateName) || empty($email) || empty($position) || $stageId <= 0 || $statusId <= 0 || empty($appliedDate)) {
                    echo json_encode(['success' => false, 'message' => 'Required fields are missing']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                $conn = getDBConnection();

                // Check ownership for non-admin users
                if ($role !== 'admin') {
                    $stmt = $conn->prepare("SELECT assigned_to FROM applications WHERE id = ?");
                    $stmt->bind_param("i", $appId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        if ($row['assigned_to'] != $user_id) {
                            $stmt->close();
                            $conn->close();
                            echo json_encode(['success' => false, 'message' => 'You can only edit applications assigned to you']);
                            exit();
                        }
                    }
                    $stmt->close();
                }

                $stmt = $conn->prepare("UPDATE applications SET candidate_name = ?, email = ?, contact_number = ?, position = ?, company = ?, experience = ?, academic_qualification = ?, technical_qualification = ?, expected_salary = ?, nationality = ?, current_location = ?, county_of_residence = ?, highest_education_level = ?, institution = ?, course_qualification = ?, graduation_year = ?, professional_certifications = ?, relevant_training = ?, current_employer = ?, current_job_title = ?, previous_employers = ?, aviation_experience = ?, customer_service_experience = ?, relevant_skills = ?, availability_notice_period = ?, stage_id = ?, status_id = ?, next_action = ?, next_action_date = ?, applied_date = ?, joined_date = ?, notes = ?, assigned_to = ? WHERE id = ?");

                $stmt->bind_param("sssssssssssssssisssssssssiisssssii",
                    $candidateName, $email, $contactNumber, $position, $company,
                    $experience, $academicQual, $technicalQual, $expectedSalary,
                    $nationality, $currentLocation, $countyOfResidence, $highestEducationLevel,
                    $institution, $courseQualification, $graduationYear, $professionalCertifications,
                    $relevantTraining, $currentEmployer, $currentJobTitle, $previousEmployers,
                    $aviationExperience, $customerServiceExperience, $relevantSkills, $availabilityNoticePeriod,
                    $stageId, $statusId,
                    $nextAction, $nextActionDate, $appliedDate, $joinedDate,
                    $notes, $assignedTo, $appId
                );

                if ($stmt->execute()) {
                    logActivity($user_id, 'UPDATE', "Updated application #$appId: $candidateName - $position", ['module' => 'applications', 'application_id' => $appId]);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Application updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update application: ' . $error]);
                }
                exit();

            case 'deleteApplication':
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only admins can delete applications.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $appId = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($appId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get application info before deleting
                $stmt = $conn->prepare("SELECT candidate_name, position FROM applications WHERE id = ?");
                $stmt->bind_param("i", $appId);
                $stmt->execute();
                $result = $stmt->get_result();
                $deletedName = '';
                $deletedPosition = '';
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $deletedName = $row['candidate_name'];
                    $deletedPosition = $row['position'];
                }
                $stmt->close();

                // Delete physical document files before deleting application (DB records cascade-delete)
                $docStmt = $conn->prepare("SELECT file_path FROM application_documents WHERE application_id = ?");
                $docStmt->bind_param("i", $appId);
                $docStmt->execute();
                $docResult = $docStmt->get_result();
                while ($docRow = $docResult->fetch_assoc()) {
                    deleteDocumentFile($docRow['file_path']);
                }
                $docStmt->close();

                $stmt = $conn->prepare("DELETE FROM applications WHERE id = ?");
                $stmt->bind_param("i", $appId);

                if ($stmt->execute()) {
                    logActivity($user_id, 'DELETE', "Deleted application: $deletedName - $deletedPosition", ['module' => 'applications', 'application_id' => $appId]);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Application deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete application']);
                }
                exit();

            case 'getDocuments':
                $appId = isset($_GET['application_id']) ? intval($_GET['application_id']) : 0;
                if ($appId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Check ownership for non-admin users
                if ($role !== 'admin') {
                    $stmt = $conn->prepare("SELECT assigned_to FROM applications WHERE id = ?");
                    $stmt->bind_param("i", $appId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        if ($row['assigned_to'] != $user_id) {
                            $stmt->close();
                            $conn->close();
                            echo json_encode(['success' => false, 'message' => 'Access denied']);
                            exit();
                        }
                    }
                    $stmt->close();
                }

                $stmt = $conn->prepare("SELECT d.*, COALESCE(u.full_name, CONCAT(ap.full_name, ' (applicant)')) AS uploaded_by_name
                                         FROM application_documents d
                                         LEFT JOIN users u ON d.uploaded_by = u.id
                                         LEFT JOIN applicants ap ON d.uploaded_by_applicant_id = ap.id
                                         WHERE d.application_id = ? ORDER BY d.uploaded_at DESC");
                $stmt->bind_param("i", $appId);
                $stmt->execute();
                $result = $stmt->get_result();
                $docs = [];
                while ($row = $result->fetch_assoc()) {
                    $docs[] = [
                        'id' => $row['id'],
                        'application_id' => $row['application_id'],
                        'file_name' => $row['file_name'],
                        'file_path' => $row['file_path'],
                        'file_type' => $row['file_type'],
                        'file_size_kb' => $row['file_size_kb'],
                        'document_label' => $row['document_label'],
                        'uploaded_by' => $row['uploaded_by'],
                        'uploaded_by_name' => $row['uploaded_by_name'],
                        'uploaded_at' => date('M d, Y H:i', strtotime($row['uploaded_at']))
                    ];
                }
                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $docs]);
                exit();

            case 'uploadDocument':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $appId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
                $documentLabel = isset($_POST['document_label']) ? trim($_POST['document_label']) : 'CV';

                if ($appId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                    exit();
                }

                $conn = getDBConnection();

                // Check ownership for non-admin users
                if ($role !== 'admin') {
                    $stmt = $conn->prepare("SELECT assigned_to FROM applications WHERE id = ?");
                    $stmt->bind_param("i", $appId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        if ($row['assigned_to'] != $user_id) {
                            $stmt->close();
                            $conn->close();
                            echo json_encode(['success' => false, 'message' => 'Access denied']);
                            exit();
                        }
                    }
                    $stmt->close();
                }

                // Upload the file
                $uploadResult = uploadDocument($_FILES['document'], $appId, $user_id);

                if (!$uploadResult['success']) {
                    $conn->close();
                    echo json_encode($uploadResult);
                    exit();
                }

                // Insert record into database
                $stmt = $conn->prepare("INSERT INTO application_documents (application_id, file_name, file_path, file_type, file_size_kb, document_label, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssisi",
                    $appId,
                    $uploadResult['original_name'],
                    $uploadResult['filename'],
                    $uploadResult['mime_type'],
                    $uploadResult['file_size_kb'],
                    $documentLabel,
                    $user_id
                );

                if ($stmt->execute()) {
                    logActivity($user_id, 'CREATE', "Uploaded document for application #$appId: " . $uploadResult['original_name'], ['module' => 'documents', 'application_id' => $appId]);
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
                } else {
                    // Clean up uploaded file on DB error
                    deleteDocumentFile($uploadResult['filename']);
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to save document record: ' . $error]);
                }
                exit();

            case 'deleteDocument':
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only admins can delete documents.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $docId = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;

                if ($docId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get document info and check ownership
                $stmt = $conn->prepare("SELECT d.*, a.assigned_to FROM application_documents d JOIN applications a ON d.application_id = a.id WHERE d.id = ?");
                $stmt->bind_param("i", $docId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Document not found']);
                    exit();
                }

                $doc = $result->fetch_assoc();
                $stmt->close();

                // Check ownership for non-admin users
                if ($role !== 'admin' && $doc['assigned_to'] != $user_id) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                // Delete physical file
                deleteDocumentFile($doc['file_path']);

                // Delete DB record
                $stmt = $conn->prepare("DELETE FROM application_documents WHERE id = ?");
                $stmt->bind_param("i", $docId);

                if ($stmt->execute()) {
                    logActivity($user_id, 'DELETE', "Deleted document: " . $doc['file_name'] . " from application #" . $doc['application_id'], ['module' => 'documents', 'application_id' => $doc['application_id']]);
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete document']);
                }
                exit();

            case 'saveScreening':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!hasPermission($user_id, $role, 'manage_screening')) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $screeningAppId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
                if ($screeningAppId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $toPassFail = function($val) {
                    if ($val === '' || $val === null) return null;
                    return $val === '1' ? 1 : 0;
                };
                $eligibilityPass = $toPassFail($_POST['eligibility_pass'] ?? null);
                $minQualificationPass = $toPassFail($_POST['min_qualification_pass'] ?? null);
                $requiredExperiencePass = $toPassFail($_POST['required_experience_pass'] ?? null);
                $locationRequirementPass = $toPassFail($_POST['location_requirement_pass'] ?? null);
                $screeningScore = isset($_POST['screening_score']) && $_POST['screening_score'] !== '' ? intval($_POST['screening_score']) : null;
                $recruiterComments = isset($_POST['recruiter_comments']) ? trim($_POST['recruiter_comments']) : null;

                $conn = getDBConnection();
                $stmt = $conn->prepare("INSERT INTO application_screening
                    (application_id, eligibility_pass, min_qualification_pass, required_experience_pass, location_requirement_pass, screening_score, recruiter_comments, screened_by, screened_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    eligibility_pass = VALUES(eligibility_pass),
                    min_qualification_pass = VALUES(min_qualification_pass),
                    required_experience_pass = VALUES(required_experience_pass),
                    location_requirement_pass = VALUES(location_requirement_pass),
                    screening_score = VALUES(screening_score),
                    recruiter_comments = VALUES(recruiter_comments),
                    screened_by = VALUES(screened_by),
                    screened_at = NOW()");
                $stmt->bind_param("iiiiiisi", $screeningAppId, $eligibilityPass, $minQualificationPass, $requiredExperiencePass, $locationRequirementPass, $screeningScore, $recruiterComments, $user_id);

                if ($stmt->execute()) {
                    logActivity($user_id, 'UPDATE', "Saved screening for application #$screeningAppId", ['module' => 'applications', 'application_id' => $screeningAppId]);
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Screening saved successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to save screening: ' . $error]);
                }
                exit();

            case 'saveAssessment':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!hasPermission($user_id, $role, 'manage_assessment')) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $assessmentAppId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
                if ($assessmentAppId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $assessmentScore = isset($_POST['assessment_score']) && $_POST['assessment_score'] !== '' ? intval($_POST['assessment_score']) : null;
                $assessmentComments = isset($_POST['assessment_comments']) ? trim($_POST['assessment_comments']) : null;

                $conn = getDBConnection();
                $stmt = $conn->prepare("INSERT INTO application_assessment
                    (application_id, assessment_score, assessment_comments, assessed_by, assessed_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    assessment_score = VALUES(assessment_score),
                    assessment_comments = VALUES(assessment_comments),
                    assessed_by = VALUES(assessed_by),
                    assessed_at = NOW()");
                $stmt->bind_param("iisi", $assessmentAppId, $assessmentScore, $assessmentComments, $user_id);

                if ($stmt->execute()) {
                    logActivity($user_id, 'UPDATE', "Saved assessment for application #$assessmentAppId", ['module' => 'applications', 'application_id' => $assessmentAppId]);
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Assessment saved successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to save assessment: ' . $error]);
                }
                exit();

            case 'getInterviewRounds':
                if (!hasPermission($user_id, $role, 'manage_interviews')) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $interviewAppId = isset($_GET['application_id']) ? intval($_GET['application_id']) : 0;
                if ($interviewAppId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT iv.id, iv.round_label, iv.interview_date, iv.interview_score, iv.interviewer_comments, u.full_name AS interviewed_by_name
                                         FROM application_interviews iv
                                         LEFT JOIN users u ON iv.interviewed_by = u.id
                                         WHERE iv.application_id = ?
                                         ORDER BY iv.interview_date ASC, iv.id ASC");
                $stmt->bind_param("i", $interviewAppId);
                $stmt->execute();
                $result = $stmt->get_result();
                $rounds = [];
                while ($row = $result->fetch_assoc()) {
                    $rounds[] = $row;
                }
                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $rounds]);
                exit();

            case 'addInterviewRound':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!hasPermission($user_id, $role, 'manage_interviews')) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $interviewAppId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
                if ($interviewAppId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $roundLabel = isset($_POST['round_label']) ? trim($_POST['round_label']) : null;
                $roundLabel = $roundLabel !== '' ? $roundLabel : null;
                $interviewDate = isset($_POST['interview_date']) && !empty($_POST['interview_date']) ? $_POST['interview_date'] : null;
                $interviewScore = isset($_POST['interview_score']) && $_POST['interview_score'] !== '' ? intval($_POST['interview_score']) : null;
                $interviewerComments = isset($_POST['interviewer_comments']) ? trim($_POST['interviewer_comments']) : null;

                $conn = getDBConnection();
                $stmt = $conn->prepare("INSERT INTO application_interviews
                    (application_id, round_label, interview_date, interview_score, interviewer_comments, interviewed_by)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issisi", $interviewAppId, $roundLabel, $interviewDate, $interviewScore, $interviewerComments, $user_id);

                if ($stmt->execute()) {
                    logActivity($user_id, 'CREATE', "Added interview round for application #$interviewAppId", ['module' => 'applications', 'application_id' => $interviewAppId]);
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Interview round added successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add interview round: ' . $error]);
                }
                exit();

            case 'deleteInterviewRound':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!hasPermission($user_id, $role, 'manage_interviews')) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $roundId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                if ($roundId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid round ID']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT application_id FROM application_interviews WHERE id = ?");
                $stmt->bind_param("i", $roundId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Round not found']);
                    exit();
                }
                $roundAppId = $result->fetch_assoc()['application_id'];
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM application_interviews WHERE id = ?");
                $stmt->bind_param("i", $roundId);

                if ($stmt->execute()) {
                    logActivity($user_id, 'DELETE', "Deleted interview round for application #$roundAppId", ['module' => 'applications', 'application_id' => $roundAppId]);
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Interview round deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete interview round']);
                }
                exit();

            case 'saveNotes':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $notesAppId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                if ($notesAppId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Check ownership for non-admin users
                if ($role !== 'admin') {
                    $stmt = $conn->prepare("SELECT assigned_to FROM applications WHERE id = ?");
                    $stmt->bind_param("i", $notesAppId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        if ($row['assigned_to'] != $user_id) {
                            $stmt->close();
                            $conn->close();
                            echo json_encode(['success' => false, 'message' => 'You can only edit applications assigned to you']);
                            exit();
                        }
                    }
                    $stmt->close();
                }

                $notesValue = isset($_POST['notes']) ? trim($_POST['notes']) : null;
                $stmt = $conn->prepare("UPDATE applications SET notes = ? WHERE id = ?");
                $stmt->bind_param("si", $notesValue, $notesAppId);
                $stmt->execute();
                $stmt->close();

                // Sensitive fields are only touched when the submitter actually has permission,
                // so a request without them (no permission) can't silently wipe existing values
                if (hasPermission($user_id, $role, 'view_sensitive_notes')) {
                    $rejectionReasonValue = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null;
                    $hrCommentsValue = isset($_POST['hr_comments']) ? trim($_POST['hr_comments']) : null;
                    $stmt = $conn->prepare("UPDATE applications SET rejection_reason = ?, hr_comments = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $rejectionReasonValue, $hrCommentsValue, $notesAppId);
                    $stmt->execute();
                    $stmt->close();
                }

                logActivity($user_id, 'UPDATE', "Updated notes for application #$notesAppId", ['module' => 'applications', 'application_id' => $notesAppId]);
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Notes saved successfully']);
                exit();

            case 'getApplicationActivity':
                $activityAppId = isset($_GET['application_id']) ? intval($_GET['application_id']) : 0;
                if ($activityAppId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
                    exit();
                }

                $conn = getDBConnection();

                if ($role !== 'admin') {
                    $stmt = $conn->prepare("SELECT assigned_to FROM applications WHERE id = ?");
                    $stmt->bind_param("i", $activityAppId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        if ($row['assigned_to'] != $user_id) {
                            $stmt->close();
                            $conn->close();
                            echo json_encode(['success' => false, 'message' => 'Access denied']);
                            exit();
                        }
                    }
                    $stmt->close();
                }

                $stmt = $conn->prepare("SELECT l.action, l.description, l.created_at, u.full_name AS user_name
                                         FROM activity_logs l
                                         LEFT JOIN users u ON l.user_id = u.id
                                         WHERE l.application_id = ?
                                         ORDER BY l.created_at DESC");
                $stmt->bind_param("i", $activityAppId);
                $stmt->execute();
                $result = $stmt->get_result();
                $logs = [];
                while ($row = $result->fetch_assoc()) {
                    $logs[] = [
                        'action' => $row['action'],
                        'description' => $row['description'],
                        'user_name' => $row['user_name'],
                        'created_at' => date('M d, Y h:i A', strtotime($row['created_at']))
                    ];
                }
                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $logs]);
                exit();

            case 'getTechnicalSkills':
                $skills = getSetting('technical_skills', '["HTML/CSS","JavaScript","PHP","Python","Java","SQL"]');
                $skillsArray = json_decode($skills, true);
                echo json_encode(['success' => true, 'data' => $skillsArray]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Applications.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<!--
  Developed by Rameez Scripts
  WhatsApp: https://wa.me/923224083545 (For Custom Projects)
  YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Applications Skyward Airlines</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=5.12">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1><i class="ri-file-text-line"></i> Applications</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2>Job Applications</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadApplications()">
                            <i class="ri-refresh-line"></i> Refresh
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="ri-add-line"></i> Add Application
                        </button>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section" id="filtersSection" style="display: none;">
                    <div class="filters-header">
                        <h3><i class="ri-filter-line"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="ri-close-circle-line"></i> Clear All
                        </button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="ri-calendar-line"></i> Applied From</label>
                            <input type="date" id="filterDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-calendar-line"></i> Applied To</label>
                            <input type="date" id="filterDateTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-list-unordered"></i> Stage</label>
                            <select id="filterStage" class="filter-input">
                                <option value="">All Stages</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-price-tag-3-line"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Statuses</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-building-line"></i> Department</label>
                            <select id="filterCompany" class="filter-input">
                                <option value="">All Departments</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-user-3-line"></i> Assigned To</label>
                            <select id="filterAssigned" class="filter-input">
                                <option value="">All</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="table-scroll-hint">
                    <i class="ri-arrow-left-right-line"></i> Swipe left/right to see all columns
                </div>
                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table id="applicationsTable" class="display" style="width:100%"></table>
                </div>
            </div>
        </div>
    </div>

    <!-- Application Modal -->
    <div class="modal-overlay" id="appModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width: 900px;">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="ri-add-circle-line"></i> Add Application</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="appForm">
                    <input type="hidden" id="appId" name="id">

                    <!-- Candidate Info -->
                    <h4 class="form-section-title"><i class="ri-user-line"></i> Candidate Information</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-user-line"></i> Candidate Name *</label>
                            <input type="text" id="candidateName" name="candidate_name" required maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-mail-line"></i> Email *</label>
                            <input type="email" id="appEmail" name="email" required maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-phone-line"></i> Contact Number</label>
                            <input type="text" id="contactNumber" name="contact_number" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-flag-line"></i> Nationality</label>
                            <input type="text" id="nationality" name="nationality" maxlength="80">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-map-pin-line"></i> Current Location</label>
                            <input type="text" id="currentLocation" name="current_location" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-map-2-line"></i> County of Residence</label>
                            <input type="text" id="countyOfResidence" name="county_of_residence" maxlength="100" placeholder="e.g. Nairobi">
                        </div>
                    </div>

                    <!-- Job Details -->
                    <h4 class="form-section-title"><i class="ri-briefcase-line"></i> Job Details</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-id-card-line"></i> Position *</label>
                            <input type="text" id="position" name="position" required maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-building-line"></i> Department</label>
                            <input type="text" id="company" name="company" maxlength="150" placeholder="e.g. Marketing, IT, HR">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-time-line"></i> Experience</label>
                            <input type="text" id="experience" name="experience" maxlength="100" placeholder="e.g. 3 years, 5+ years">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-money-dollar-circle-line"></i> Expected Salary</label>
                            <input type="text" id="expectedSalary" name="expected_salary" maxlength="50" placeholder="e.g. KES 80,000">
                        </div>
                    </div>

                    <!-- Qualifications -->
                    <h4 class="form-section-title"><i class="ri-graduation-cap-line"></i> Qualifications</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-graduation-cap-line"></i> Academic Qualification</label>
                            <div class="checkbox-group" id="academicCheckboxes">
                                <label class="checkbox-item"><input type="checkbox" name="academic_qualification[]" value="Matric"> Matric</label>
                                <label class="checkbox-item"><input type="checkbox" name="academic_qualification[]" value="Intermediate"> Intermediate</label>
                                <label class="checkbox-item"><input type="checkbox" name="academic_qualification[]" value="Diploma"> Diploma</label>
                                <label class="checkbox-item"><input type="checkbox" name="academic_qualification[]" value="Bachelor's"> Bachelor's</label>
                                <label class="checkbox-item"><input type="checkbox" name="academic_qualification[]" value="Master's"> Master's</label>
                                <label class="checkbox-item"><input type="checkbox" name="academic_qualification[]" value="PhD"> PhD</label>
                                <label class="checkbox-item"><input type="checkbox" name="academic_qualification[]" value="Professional Certification"> Professional Certification</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-terminal-window-line"></i> Technical Qualification</label>
                            <div class="checkbox-group" id="technicalCheckboxes">
                                <p style="color: #999; text-align: center; padding: 20px;">
                                    <i class="ri-loader-4-line ri-spin"></i> Loading skills...
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Education & Qualifications -->
                    <h4 class="form-section-title"><i class="ri-book-open-line"></i> Education & Qualifications</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-graduation-cap-line"></i> Highest Education Level</label>
                            <input type="text" id="highestEducationLevel" name="highest_education_level" maxlength="100" placeholder="e.g. Bachelor's Degree">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-school-line"></i> Institution</label>
                            <input type="text" id="institution" name="institution" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-file-list-3-line"></i> Course / Qualification</label>
                            <input type="text" id="courseQualification" name="course_qualification" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-calendar-line"></i> Graduation Year</label>
                            <input type="number" id="graduationYear" name="graduation_year" min="1950" max="2100" placeholder="e.g. 2020">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-award-line"></i> Professional Certifications</label>
                            <textarea id="professionalCertifications" name="professional_certifications" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-slideshow-line"></i> Relevant Training</label>
                            <textarea id="relevantTraining" name="relevant_training" rows="2"></textarea>
                        </div>
                    </div>

                    <!-- Work Experience -->
                    <h4 class="form-section-title"><i class="ri-briefcase-4-line"></i> Work Experience</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-building-4-line"></i> Current / Most Recent Employer</label>
                            <input type="text" id="currentEmployer" name="current_employer" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-id-card-line"></i> Job Title</label>
                            <input type="text" id="currentJobTitle" name="current_job_title" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-time-line"></i> Availability / Notice Period</label>
                            <input type="text" id="availabilityNoticePeriod" name="availability_notice_period" maxlength="100" placeholder="e.g. 30 days">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-history-line"></i> Previous Employers</label>
                            <textarea id="previousEmployers" name="previous_employers" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-flight-takeoff-line"></i> Aviation Experience</label>
                            <textarea id="aviationExperience" name="aviation_experience" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-customer-service-2-line"></i> Customer Service Experience</label>
                            <textarea id="customerServiceExperience" name="customer_service_experience" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-tools-line"></i> Relevant Skills</label>
                            <textarea id="relevantSkills" name="relevant_skills" rows="2"></textarea>
                        </div>
                    </div>

                    <!-- Pipeline & Status -->
                    <h4 class="form-section-title"><i class="ri-list-unordered"></i> Pipeline & Status</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-list-unordered"></i> Pipeline Stage *</label>
                            <select id="stageId" name="stage_id" required>
                                <option value="">Select Stage</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-price-tag-3-line"></i> Status *</label>
                            <select id="statusId" name="status_id" required>
                                <option value="">Select Status</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-user-3-line"></i> Assigned To</label>
                            <select id="assignedTo" name="assigned_to">
                                <option value="">Unassigned</option>
                            </select>
                        </div>
                    </div>

                    <!-- Dates & Actions -->
                    <h4 class="form-section-title"><i class="ri-calendar-line"></i> Dates & Next Action</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-calendar-event-line"></i> Applied Date *</label>
                            <input type="date" id="appliedDate" name="applied_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-calendar-check-line"></i> Joined Date</label>
                            <input type="date" id="joinedDate" name="joined_date">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-checkbox-multiple-line"></i> Next Action</label>
                            <input type="text" id="nextAction" name="next_action" maxlength="255" placeholder="e.g. Schedule Interview">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-calendar-todo-line"></i> Next Action Date</label>
                            <input type="date" id="nextActionDate" name="next_action_date">
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="form-group">
                        <label><i class="ri-sticky-note-line"></i> Notes</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Additional notes about this application..."></textarea>
                    </div>

                    <!-- Attach Document (Optional) -->
                    <h4 class="form-section-title"><i class="ri-attachment-line"></i> Attach Document (Optional)</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-price-tag-line"></i> Document Label</label>
                            <select id="inlineDocLabel">
                                <option value="CV">CV / Resume</option>
                                <option value="Cover Letter">Cover Letter</option>
                                <option value="Certificate">Certificate</option>
                                <option value="ID Document">ID Document</option>
                                <option value="Offer Letter">Offer Letter</option>
                                <option value="Reference">Reference</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-file-line"></i> Select File</label>
                            <input type="file" id="inlineDocFile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp">
                        </div>
                    </div>
                    <p style="font-size: 13px; color: #888; margin-bottom: 15px;"><i class="ri-information-line"></i> Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, WEBP (Max 15MB). Document will be uploaded after saving.</p>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-save-line"></i> Save
                        </button>

                        <!-- Upload Documents Button (visible only in edit mode) -->
                        <button type="button" class="btn btn-success" id="uploadDocsBtn" style="display: none;" onclick="openDocsModalFromForm()">
                            <i class="ri-attachment-line"></i> Upload
                        </button>

                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="ri-close-line"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Application Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width: 960px;">
            <div class="modal-header">
                <h3 id="profileModalTitle"><i class="ri-eye-line"></i> Application Profile</h3>
                <button class="close-btn" onclick="closeViewModal()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <!-- Documents Modal -->
    <div class="modal-overlay" id="docsModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width: 700px;">
            <div class="modal-header">
                <h3><i class="ri-attachment-line"></i> Documents - <span id="docsModalName"></span></h3>
                <button class="close-btn" onclick="closeDocsModal()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Upload Section -->
                <div class="doc-upload-section">
                    <h4 style="margin-bottom: 15px; color: var(--navy-primary);"><i class="ri-upload-cloud-2-line"></i> Upload Document</h4>
                    <form id="docUploadForm" enctype="multipart/form-data">
                        <input type="hidden" id="docAppId" name="application_id">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="ri-price-tag-line"></i> Document Label</label>
                                <select id="docLabel" name="document_label">
                                    <option value="CV">CV / Resume</option>
                                    <option value="Cover Letter">Cover Letter</option>
                                    <option value="Certificate">Certificate</option>
                                    <option value="ID Document">ID Document</option>
                                    <option value="Offer Letter">Offer Letter</option>
                                    <option value="Reference">Reference</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="ri-file-line"></i> Select File *</label>
                                <input type="file" id="docFile" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp" required>
                            </div>
                        </div>
                        <p class="doc-upload-hint"><i class="ri-information-line"></i> Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, WEBP (Max 15MB)</p>
                        <button type="submit" class="btn btn-primary" id="docUploadBtn" style="margin-top: 10px;">
                            <i class="ri-upload-line"></i> Upload
                        </button>
                    </form>
                </div>

                <!-- Documents List -->
                <div id="docsListContainer" style="margin-top: 25px;">
                    <h4 style="margin-bottom: 15px; color: var(--navy-primary);"><i class="ri-folder-open-line"></i> Uploaded Documents <span class="docs-count-badge" id="docsCount">0</span></h4>
                    <div id="docsList">
                        <p style="color: #999; text-align: center; padding: 20px;">Loading documents...</p>
                    </div>
                </div>

                <!-- Document Preview Area -->
                <div id="docPreviewArea" style="display: none; margin-top: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 2px solid var(--navy-primary);">
                        <h4 style="color: var(--navy-primary); margin: 0;"><i class="ri-eye-line"></i> Document Preview - <span id="previewDocName"></span></h4>
                        <button class="btn btn-secondary" onclick="closeDocPreview()" style="padding: 6px 14px; font-size: 13px;"><i class="ri-close-line"></i> Close Preview</button>
                    </div>
                    <div id="docPreviewContent" style="border: 1px solid #dee2e6; border-radius: 4px; overflow: hidden; background: #f8f9fa;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Overlay (Same-Tab View) -->
    <div id="printOverlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 2000; background: #e8e8e8; overflow-y: auto;">
        <div id="printOverlayActions" style="position: sticky; top: 0; z-index: 10; background: var(--navy-primary); padding: 12px 20px; display: flex; justify-content: center; gap: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
            <button onclick="printOverlayContent()" class="btn btn-primary" style="background: #fff; color: var(--navy-primary); font-weight: 600;"><i class="ri-printer-line"></i> Print Document</button>
            <button onclick="closePrintOverlay()" class="btn btn-secondary"><i class="ri-close-line"></i> Close</button>
        </div>
        <div id="printOverlayContent" style="padding: 20px 0;"></div>
    </div>

    <script>
        var userRole = '<?php echo $role; ?>';
        var canManageScreening = <?php echo hasPermission($user_id, $role, 'manage_screening') ? 'true' : 'false'; ?>;
        var canManageAssessment = <?php echo hasPermission($user_id, $role, 'manage_assessment') ? 'true' : 'false'; ?>;
        var canManageInterviews = <?php echo hasPermission($user_id, $role, 'manage_interviews') ? 'true' : 'false'; ?>;
        var canViewSensitiveNotes = <?php echo hasPermission($user_id, $role, 'view_sensitive_notes') ? 'true' : 'false'; ?>;
        let applicationsTable;
        let isEditMode = false;
        let currentFormAppId = null; // Track current application ID for upload button
        let applicationsData = [];
        let formDataCache = null;

        function findAppById(id) {
            return applicationsData.find(function(a) { return a.id == id; });
        }

        $(document).ready(function() {
            loadFormData();
            loadApplications();
        });

        // Load dropdown data (stages, statuses, users)
        function loadFormData() {
            $.ajax({
                url: '?action=getFormData',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        formDataCache = response;
                        populateDropdowns(response);
                    }
                },
                error: function() {
                    console.error('Failed to load form data');
                }
            });
        }

        function populateDropdowns(data) {
            // Pipeline stages dropdown
            const stageSelect = document.getElementById('stageId');
            const filterStage = document.getElementById('filterStage');
            stageSelect.innerHTML = '<option value="">Select Stage</option>';
            filterStage.innerHTML = '<option value="">All Stages</option>';
            data.pipeline_stages.forEach(function(stage) {
                stageSelect.innerHTML += '<option value="' + stage.id + '">' + stage.name + '</option>';
                filterStage.innerHTML += '<option value="' + stage.name + '">' + stage.name + '</option>';
            });

            // Status dropdown
            const statusSelect = document.getElementById('statusId');
            const filterStatus = document.getElementById('filterStatus');
            statusSelect.innerHTML = '<option value="">Select Status</option>';
            filterStatus.innerHTML = '<option value="">All Statuses</option>';
            data.statuses.forEach(function(status) {
                statusSelect.innerHTML += '<option value="' + status.id + '">' + status.name + '</option>';
                filterStatus.innerHTML += '<option value="' + status.name + '">' + status.name + '</option>';
            });

            // Assigned to dropdown
            const assignedSelect = document.getElementById('assignedTo');
            const filterAssigned = document.getElementById('filterAssigned');
            assignedSelect.innerHTML = '<option value="">Unassigned</option>';
            filterAssigned.innerHTML = '<option value="">All</option>';
            data.users.forEach(function(user) {
                assignedSelect.innerHTML += '<option value="' + user.id + '">' + user.full_name + '</option>';
                filterAssigned.innerHTML += '<option value="' + user.full_name + '">' + user.full_name + '</option>';
            });
        }

        function loadApplications() {
            $.ajax({
                url: '?action=getApplications',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        applicationsData = response.data;
                        populateCompanyFilter(response.data);
                        $('#filtersSection').show();
                        initializeDataTable(response.data);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load applications' });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
                }
            });
        }

        function populateCompanyFilter(data) {
            const companies = [...new Set(data.map(a => a.company).filter(Boolean))].sort();
            const filterCompany = document.getElementById('filterCompany');
            filterCompany.innerHTML = '<option value="">All Departments</option>';
            companies.forEach(function(company) {
                filterCompany.innerHTML += '<option value="' + company + '">' + company + '</option>';
            });
        }

        function initializeDataTable(data) {
            if (applicationsTable) {
                applicationsTable.destroy();
                $('#applicationsTable').empty();
            }

            setTimeout(() => {
                applicationsTable = $('#applicationsTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: [
                        { data: 'id', title: 'ID', width: '50px' },
                        { data: 'candidate_name', title: 'Candidate' },
                        { data: 'position', title: 'Position' },
                        { data: 'company', title: 'Dept', defaultContent: '-' },
                        {
                            data: null,
                            title: 'Stage',
                            render: function(data, type, row) {
                                if (!row.stage_name) return '-';
                                var icon = row.stage_icon ? '<i class="' + row.stage_icon + '"></i> ' : '';
                                return '<span class="stage-badge" style="color:' + (row.stage_color || '#6B7280') + '">' + icon + row.stage_name + '</span>';
                            }
                        },
                        {
                            data: null,
                            title: 'Status',
                            render: function(data, type, row) {
                                if (!row.status_name) return '-';
                                var icon = row.status_icon ? '<i class="' + row.status_icon + '"></i> ' : '';
                                return '<span class="stage-badge" style="color:' + (row.status_color || '#6B7280') + '">' + icon + row.status_name + '</span>';
                            }
                        },
                        { data: 'applied_date_display', title: 'Applied Date' },
                        {
                            data: 'days_elapsed',
                            title: 'Days Elapsed',
                            width: '85px',
                            className: 'text-center',
                            render: function(data, type, row) {
                                if (data === null || data === undefined) return '-';
                                var color = '#34a853';
                                var icon = 'ri-time-line';
                                if (data > 60) { color = '#ea4335'; icon = 'ri-error-warning-line'; }
                                else if (data > 30) { color = '#fbbc04'; icon = 'ri-hourglass-line'; }
                                var isJoined = row.joined_date ? ' title="Applied to Joined"' : ' title="Days in pipeline"';
                                return '<span class="days-badge" style="color:' + color + ';"' + isJoined + '><i class="' + icon + '"></i> ' + data + '</span>';
                            }
                        },
                        { data: 'assigned_to_name', title: 'Assigned To', defaultContent: '<span style="color:#999">Unassigned</span>' },
                        { data: 'joined_date_display', title: 'Joined Date', defaultContent: '<span style="color:#999">-</span>' },
                        {
                            data: null,
                            title: 'Docs',
                            orderable: false,
                            width: '60px',
                            className: 'text-center',
                            render: function(data, type, row) {
                                var count = row.document_count || 0;
                                var badge = count > 0 ? '<span class="doc-count-badge">' + count + '</span>' : '';
                                return '<button class="action-icon btn-docs-app" data-id="' + row.id + '" title="Documents" style="color: var(--navy-primary); position: relative;"><i class="ri-attachment-line"></i>' + badge + '</button>';
                            }
                        },
                        {
                            data: null,
                            title: 'Actions',
                            orderable: false,
                            render: function(data, type, row) {
                                var actions = '<button class="action-icon btn-view-app" data-id="' + row.id + '" title="View" style="color: var(--navy-accent);"><i class="ri-eye-line"></i></button>' +
                                    '<button class="action-icon edit-icon btn-edit-app" data-id="' + row.id + '" title="Edit"><i class="ri-edit-line"></i></button>';
                                if (userRole === 'admin') {
                                    actions += '<button class="action-icon delete-icon btn-delete-app" data-id="' + row.id + '" title="Delete"><i class="ri-delete-bin-line"></i></button>';
                                }
                                return actions;
                            }
                        }
                    ],
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        { extend: 'csv', text: '<i class="ri-file-excel-2-line"></i> CSV', exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] } },
                        { extend: 'pdf', text: '<i class="ri-file-pdf-line"></i> PDF', exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] } },
                        { extend: 'print', text: '<i class="ri-printer-line"></i> Print', exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] } }
                    ],
                    order: [[0, 'desc']]
                });

                // Event delegation for action buttons
                $('#applicationsTable').off('click', '.btn-view-app, .btn-edit-app, .btn-delete-app, .btn-docs-app');

                $('#applicationsTable').on('click', '.btn-view-app', function(e) {
                    e.stopPropagation();
                    var app = findAppById($(this).data('id'));
                    if (app) {
                        renderProfileModal(app);
                        document.getElementById('viewModal').classList.add('active');
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Could not find application data. Please refresh.' });
                    }
                });

                $('#applicationsTable').on('click', '.btn-edit-app', function(e) {
                    e.stopPropagation();
                    var app = findAppById($(this).data('id'));
                    if (app) {
                        editApplication(app);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Could not find application data. Please refresh.' });
                    }
                });

                $('#applicationsTable').on('click', '.btn-delete-app', function(e) {
                    e.stopPropagation();
                    deleteApplication($(this).data('id'));
                });

                $('#applicationsTable').on('click', '.btn-docs-app', function(e) {
                    e.stopPropagation();
                    var app = findAppById($(this).data('id'));
                    if (app) {
                        openDocsModal(app.id, app.candidate_name || '');
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Could not find application data. Please refresh.' });
                    }
                });

                // Apply filters on change
                $('#filterDateFrom, #filterDateTo, #filterStage, #filterStatus, #filterCompany, #filterAssigned').on('change', function() {
                    applyFilters();
                });
            }, 100);
        }

        function applyFilters() {
            if (!applicationsTable) return;

            // Clear previous custom filters
            $.fn.dataTable.ext.search = [];

            var dateFrom = document.getElementById('filterDateFrom').value;
            var dateTo = document.getElementById('filterDateTo').value;
            var stage = document.getElementById('filterStage').value;
            var status = document.getElementById('filterStatus').value;
            var company = document.getElementById('filterCompany').value;
            var assigned = document.getElementById('filterAssigned').value;

            // Date range filter on applied_date
            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    var appliedDate = applicationsData[dataIndex]?.applied_date;
                    if (!appliedDate) return true;

                    var recordDate = new Date(appliedDate);
                    var fromDate = dateFrom ? new Date(dateFrom) : null;
                    var toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;

                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            // Column filters
            if (stage) {
                applicationsTable.column(4).search(stage);
            } else {
                applicationsTable.column(4).search('');
            }

            if (status) {
                applicationsTable.column(5).search(status);
            } else {
                applicationsTable.column(5).search('');
            }

            if (company) {
                applicationsTable.column(3).search(company);
            } else {
                applicationsTable.column(3).search('');
            }

            if (assigned) {
                applicationsTable.column(8).search(assigned);
            } else {
                applicationsTable.column(8).search('');
            }

            applicationsTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterStage').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterCompany').value = '';
            document.getElementById('filterAssigned').value = '';

            if (applicationsTable) {
                $.fn.dataTable.ext.search = [];
                applicationsTable.columns().search('').draw();
            }
        }

        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="ri-add-circle-line"></i> Add Application';
            document.getElementById('appForm').reset();
            document.getElementById('appId').value = '';
            // Set default applied date to today
            document.getElementById('appliedDate').value = new Date().toISOString().split('T')[0];

            // Reset inline document upload
            document.getElementById('inlineDocFile').value = '';
            document.getElementById('inlineDocLabel').value = 'CV';

            // Load technical skills dynamically for new application
            loadTechnicalSkills();

            // Uncheck all academic qualification checkboxes
            document.querySelectorAll('#academicCheckboxes input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });

            document.getElementById('appModal').classList.add('active');
        }

        // Sets a field's value only if the element exists (some fields are permission-gated and may not be in the DOM)
        function setFieldValue(id, value) {
            var el = document.getElementById(id);
            if (el) el.value = value || '';
        }

        function editApplication(app) {
            isEditMode = true;
            currentFormAppId = app.id; // Set current app ID for upload button
            document.getElementById('modalTitle').innerHTML = '<i class="ri-edit-line"></i> Edit Application';
            document.getElementById('appId').value = app.id;

            // Reset inline document upload
            document.getElementById('inlineDocFile').value = '';
            document.getElementById('inlineDocLabel').value = 'CV';

            // Show upload button in edit mode
            document.getElementById('uploadDocsBtn').style.display = 'inline-flex';
            document.getElementById('candidateName').value = app.candidate_name || '';
            document.getElementById('appEmail').value = app.email || '';
            document.getElementById('contactNumber').value = app.contact_number || '';
            document.getElementById('nationality').value = app.nationality || '';
            document.getElementById('currentLocation').value = app.current_location || '';
            document.getElementById('position').value = app.position || '';
            document.getElementById('company').value = app.company || '';
            document.getElementById('experience').value = app.experience || '';
            document.getElementById('expectedSalary').value = app.expected_salary || '';
            document.getElementById('stageId').value = app.stage_id || '';
            document.getElementById('statusId').value = app.status_id || '';
            document.getElementById('assignedTo').value = app.assigned_to || '';
            document.getElementById('appliedDate').value = app.applied_date || '';
            document.getElementById('joinedDate').value = app.joined_date || '';
            document.getElementById('nextAction').value = app.next_action || '';
            document.getElementById('nextActionDate').value = app.next_action_date || '';
            document.getElementById('notes').value = app.notes || '';

            // Candidate profile: county, education, work experience
            setFieldValue('countyOfResidence', app.county_of_residence);
            setFieldValue('highestEducationLevel', app.highest_education_level);
            setFieldValue('institution', app.institution);
            setFieldValue('courseQualification', app.course_qualification);
            setFieldValue('graduationYear', app.graduation_year);
            setFieldValue('professionalCertifications', app.professional_certifications);
            setFieldValue('relevantTraining', app.relevant_training);
            setFieldValue('currentEmployer', app.current_employer);
            setFieldValue('currentJobTitle', app.current_job_title);
            setFieldValue('availabilityNoticePeriod', app.availability_notice_period);
            setFieldValue('previousEmployers', app.previous_employers);
            setFieldValue('aviationExperience', app.aviation_experience);
            setFieldValue('customerServiceExperience', app.customer_service_experience);
            setFieldValue('relevantSkills', app.relevant_skills);

            // Set academic qualification checkboxes
            document.querySelectorAll('#academicCheckboxes input[type="checkbox"]').forEach(function(cb) {
                cb.checked = app.academic_qualification && app.academic_qualification.includes(cb.value);
            });

            // Load technical skills dynamically, then check existing skills
            loadTechnicalSkills(function() {
                // After skills are loaded, check the ones that exist for this application
                if (app.technical_qualification) {
                    try {
                        const existingSkills = Array.isArray(app.technical_qualification)
                            ? app.technical_qualification
                            : JSON.parse(app.technical_qualification);

                        existingSkills.forEach(skill => {
                            const checkbox = document.querySelector('input[name="technical_qualification[]"][value="' + skill + '"]');
                            if (checkbox) {
                                checkbox.checked = true;
                            }
                        });
                    } catch (e) {
                        console.error('Error parsing technical qualifications:', e);
                    }
                }
            });

            document.getElementById('appModal').classList.add('active');
        }

        function saveScreening(appId) {
            var formData = new FormData();
            formData.append('application_id', appId);
            formData.append('eligibility_pass', document.getElementById('eligibilityPass').value);
            formData.append('min_qualification_pass', document.getElementById('minQualificationPass').value);
            formData.append('required_experience_pass', document.getElementById('requiredExperiencePass').value);
            formData.append('location_requirement_pass', document.getElementById('locationRequirementPass').value);
            formData.append('screening_score', document.getElementById('screeningScore').value);
            formData.append('recruiter_comments', document.getElementById('recruiterComments').value);

            $.ajax({
                url: '?action=saveScreening',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', text: response.message, timer: 1500, showConfirmButton: false });
                        refreshProfileTab(appId, 'screening');
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        }

        function saveAssessment(appId) {
            var formData = new FormData();
            formData.append('application_id', appId);
            formData.append('assessment_score', document.getElementById('assessmentScore').value);
            formData.append('assessment_comments', document.getElementById('assessmentComments').value);

            $.ajax({
                url: '?action=saveAssessment',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', text: response.message, timer: 1500, showConfirmButton: false });
                        refreshProfileTab(appId, 'assessment');
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        }

        function loadInterviewRounds(appId) {
            var pane = document.getElementById('profile-tab-interviews');
            $.ajax({
                url: '?action=getInterviewRounds&application_id=' + appId,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (!response.success) {
                        pane.innerHTML = '<div class="profile-empty">Failed to load interviews.</div>';
                        return;
                    }
                    renderInterviewRoundsList(response.data, appId);
                },
                error: function() {
                    pane.innerHTML = '<div class="profile-empty">Connection error.</div>';
                }
            });
        }

        function renderInterviewRoundsList(rounds, appId) {
            var pane = document.getElementById('profile-tab-interviews');
            var html = '';

            if (rounds.length === 0) {
                html += '<div class="profile-empty"><i class="ri-calendar-line" style="font-size:32px;"></i><p>No interview rounds recorded yet.</p></div>';
            } else {
                rounds.forEach(function(r) {
                    html += '<div style="padding:14px 0;border-bottom:1px solid var(--border-light);">';
                    html += '<div style="display:flex;justify-content:space-between;align-items:center;">';
                    html += '<strong>' + escapeHtml(r.round_label || 'Interview') + '</strong>';
                    html += '<button type="button" class="action-icon delete-icon" onclick="deleteInterviewRound(' + r.id + ', ' + appId + ')" title="Delete"><i class="ri-delete-bin-line"></i></button>';
                    html += '</div>';
                    html += '<div style="font-size:13px;color:var(--text-secondary);margin-top:4px;display:flex;gap:14px;">';
                    if (r.interview_date) html += '<span><i class="ri-calendar-event-line"></i> ' + escapeHtml(r.interview_date) + '</span>';
                    if (r.interview_score !== null && r.interview_score !== undefined) html += '<span><i class="ri-percent-line"></i> ' + r.interview_score + '%</span>';
                    html += '</div>';
                    if (r.interviewer_comments) html += '<div style="font-size:14px;margin-top:6px;">' + escapeHtml(r.interviewer_comments) + '</div>';
                    if (r.interviewed_by_name) html += '<div style="font-size:12px;color:var(--text-muted);margin-top:4px;">by ' + escapeHtml(r.interviewed_by_name) + '</div>';
                    html += '</div>';
                });
            }

            html += '<h4 class="form-section-title"><i class="ri-add-circle-line"></i> Add Interview Round</h4>';
            html += '<div class="form-grid">';
            html += '<div class="form-group"><label>Round Label</label><input type="text" id="newRoundLabel" placeholder="e.g. Technical Interview" maxlength="100"></div>';
            html += '<div class="form-group"><label>Interview Date</label><input type="date" id="newRoundDate"></div>';
            html += '<div class="form-group"><label>Interview Score</label><input type="number" id="newRoundScore" min="0" max="100"></div>';
            html += '</div>';
            html += '<div class="form-group"><label>Interviewer Comments</label><textarea id="newRoundComments" rows="3"></textarea></div>';
            html += '<button type="button" class="btn btn-primary btn-sm" onclick="addInterviewRound(' + appId + ')"><i class="ri-save-line"></i> Add Round</button>';

            pane.innerHTML = html;
        }

        function addInterviewRound(appId) {
            var formData = new FormData();
            formData.append('application_id', appId);
            formData.append('round_label', document.getElementById('newRoundLabel').value);
            formData.append('interview_date', document.getElementById('newRoundDate').value);
            formData.append('interview_score', document.getElementById('newRoundScore').value);
            formData.append('interviewer_comments', document.getElementById('newRoundComments').value);

            $.ajax({
                url: '?action=addInterviewRound',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', text: response.message, timer: 1500, showConfirmButton: false });
                        refreshProfileTab(appId, 'interviews');
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        }

        function deleteInterviewRound(roundId, appId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete this interview round?',
                text: 'This action cannot be undone.',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (!result.isConfirmed) return;

                var formData = new FormData();
                formData.append('id', roundId);

                $.ajax({
                    url: '?action=deleteInterviewRound',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            refreshProfileTab(appId, 'interviews');
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                    }
                });
            });
        }

        function saveNotes(appId) {
            var formData = new FormData();
            formData.append('id', appId);
            formData.append('notes', document.getElementById('profileNotes').value);
            var rejectionEl = document.getElementById('profileRejectionReason');
            var hrEl = document.getElementById('profileHrComments');
            if (rejectionEl) formData.append('rejection_reason', rejectionEl.value);
            if (hrEl) formData.append('hr_comments', hrEl.value);

            $.ajax({
                url: '?action=saveNotes',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', text: response.message, timer: 1500, showConfirmButton: false });
                        refreshProfileTab(appId, 'notes');
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        }

        // Re-fetches applications data (so the in-memory cache reflects the save), then re-renders the open profile modal on the given tab
        function refreshProfileTab(appId, tabName) {
            $.ajax({
                url: '?action=getApplications',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        applicationsData = response.data;
                        if (applicationsTable) {
                            applicationsTable.clear().rows.add(response.data).draw(false);
                        }
                        var app = findAppById(appId);
                        if (app) {
                            renderProfileModal(app);
                            switchProfileTab(tabName);
                        }
                    }
                }
            });
        }

        function closeModal() {
            document.getElementById('appModal').classList.remove('active');
            document.getElementById('appForm').reset();
            currentFormAppId = null;
            document.getElementById('uploadDocsBtn').style.display = 'none';
        }

        // Open documents modal from application form
        function openDocsModalFromForm() {
            if (!currentFormAppId) {
                Swal.fire({
                    icon: 'info',
                    title: 'Save First',
                    text: 'Please save the application before uploading documents.'
                });
                return;
            }

            // Get candidate name for modal title
            var candidateName = document.getElementById('candidateName').value || 'Application';

            // Close application form modal
            closeModal();

            // Open documents modal (uses existing function)
            openDocsModal(currentFormAppId, candidateName);
        }

        document.getElementById('appModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // ===== Dynamic Technical Skills Loading =====
        function loadTechnicalSkills(callback) {
            $.ajax({
                url: '?action=getTechnicalSkills',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        renderTechnicalSkills(response.data);
                        if (callback) callback();
                    } else {
                        document.getElementById('technicalCheckboxes').innerHTML =
                            '<p style="color:#ea4335;">Error loading skills</p>';
                    }
                },
                error: function() {
                    document.getElementById('technicalCheckboxes').innerHTML =
                        '<p style="color:#ea4335;">Connection error</p>';
                }
            });
        }

        function renderTechnicalSkills(skills) {
            const container = document.getElementById('technicalCheckboxes');
            container.innerHTML = '';

            skills.forEach(skill => {
                const label = document.createElement('label');
                label.className = 'checkbox-item';
                label.innerHTML =
                    '<input type="checkbox" name="technical_qualification[]" value="' + skill + '"> ' +
                    skill;
                container.appendChild(label);
            });
        }

        function viewApplication(app) {
            // Open professional A4 printable document in new window
            var printWin = window.open('', '_blank', 'width=900,height=700');

            var qualList = function(arr) {
                if (!arr || arr.length === 0) return '<span class="empty-val">N/A</span>';
                return arr.map(function(q) { return '<span class="doc-qual-tag">' + q + '</span>'; }).join('');
            };

            var stageHtml = app.stage_name ? '<span class="doc-status-tag" style="background:' + (app.stage_color || '#6B7280') + '">' + (app.stage_name || '-') + '</span>' : '<span class="empty-val">N/A</span>';
            var statusHtml = app.status_name ? '<span class="doc-status-tag" style="background:' + (app.status_color || '#6B7280') + '">' + (app.status_name || '-') + '</span>' : '<span class="empty-val">N/A</span>';

            var today = new Date();
            var printDate = today.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: '2-digit' });

            var html = '<!DOCTYPE html>';
            html += '<html lang="en"><head><meta charset="UTF-8">';
            html += '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
            html += '<title>Application - ' + (app.candidate_name || 'Unknown') + '</title>';
            html += '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">';
            html += '<style>';

            // A4 Print Document Styles
            html += '*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }';
            html += 'body { font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, sans-serif; background: #e8e8e8; color: #1a1a1a; line-height: 1.5; }';

            // A4 Page Container
            html += '.doc-page { width: 210mm; min-height: 297mm; margin: 20px auto; background: #fff; padding: 25mm 22mm 20mm; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative; }';

            // Header / Letterhead
            html += '.doc-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 18px; border-bottom: 3px solid #e8262c; margin-bottom: 22px; }';
            html += '.doc-header-left h1 { font-size: 22px; font-weight: 800; color: #e8262c; letter-spacing: -0.5px; margin-bottom: 3px; }';
            html += '.doc-header-left p { font-size: 11px; color: #666; letter-spacing: 0.5px; }';
            html += '.doc-header-right { text-align: right; }';
            html += '.doc-ref { font-size: 11px; color: #555; margin-bottom: 3px; }';
            html += '.doc-ref strong { color: #e8262c; }';

            // Title Bar
            html += '.doc-title-bar { background: #e8262c; color: #fff; padding: 10px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }';
            html += '.doc-title-bar h2 { font-size: 15px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }';
            html += '.doc-title-bar .doc-id { font-size: 12px; opacity: 0.8; }';

            // Section
            html += '.doc-section { margin-bottom: 18px; }';
            html += '.doc-section-title { font-size: 12px; font-weight: 700; color: #e8262c; text-transform: uppercase; letter-spacing: 1.5px; padding: 6px 0; border-bottom: 1.5px solid #023f57; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }';
            html += '.doc-section-title i { font-size: 11px; color: #023f57; }';

            // Table layout for fields
            html += '.doc-fields { width: 100%; border-collapse: collapse; }';
            html += '.doc-fields td { padding: 7px 10px; font-size: 12px; vertical-align: top; border: 1px solid #e5e5e5; }';
            html += '.doc-fields .field-label { width: 140px; background: #f7f8fa; font-weight: 700; color: #333; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; white-space: nowrap; }';
            html += '.doc-fields .field-value { color: #1a1a1a; font-weight: 500; }';
            html += '.empty-val { color: #bbb; font-style: italic; font-weight: 400; }';

            // Status tags
            html += '.doc-status-tag { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 11px; font-weight: 700; color: #fff; }';
            html += '.doc-qual-tag { display: inline-block; padding: 2px 8px; margin: 1px 3px 1px 0; border-radius: 2px; font-size: 10px; font-weight: 600; background: #EBF5FF; color: #023f57; border: 1px solid #d0e5ff; }';

            // Notes
            html += '.doc-notes { background: #f9fafb; border: 1px solid #e5e5e5; border-left: 3px solid #023f57; padding: 12px 15px; font-size: 12px; color: #333; white-space: pre-wrap; line-height: 1.6; min-height: 40px; }';

            // Footer
            html += '.doc-footer { position: absolute; bottom: 15mm; left: 22mm; right: 22mm; border-top: 1px solid #ddd; padding-top: 10px; display: flex; justify-content: space-between; font-size: 9px; color: #999; }';

            // Action Bar (no-print)
            html += '.doc-action-bar { width: 210mm; margin: 0 auto 20px; display: flex; gap: 10px; justify-content: center; }';
            html += '.doc-action-btn { padding: 10px 28px; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }';
            html += '.doc-action-btn-print { background: #e8262c; color: #fff; }';
            html += '.doc-action-btn-print:hover { background: #023f57; }';
            html += '.doc-action-btn-close { background: #6c757d; color: #fff; }';
            html += '.doc-action-btn-close:hover { background: #555; }';

            // Print styles
            html += '@media print {';
            html += '  body { background: #fff; margin: 0; }';
            html += '  .doc-page { margin: 0; padding: 20mm 18mm 15mm; box-shadow: none; width: 100%; min-height: auto; }';
            html += '  .doc-action-bar { display: none !important; }';
            html += '  .doc-footer { position: fixed; bottom: 10mm; left: 18mm; right: 18mm; }';
            html += '  @page { size: A4; margin: 0; }';
            html += '}';

            // Responsive for screen
            html += '@media screen and (max-width: 800px) {';
            html += '  .doc-page { width: 100%; padding: 15px; min-height: auto; margin: 10px; }';
            html += '  .doc-action-bar { width: 100%; padding: 0 10px; }';
            html += '  .doc-fields .field-label { width: 110px; font-size: 9px; }';
            html += '  .doc-fields .field-value { font-size: 11px; }';
            html += '  .doc-footer { position: relative; bottom: auto; left: auto; right: auto; margin-top: 30px; }';
            html += '}';

            html += '</style></head><body>';

            // Action buttons bar (visible on screen only)
            html += '<div class="doc-action-bar">';
            html += '<button class="doc-action-btn doc-action-btn-print" onclick="window.print()"><i class="ri-printer-line"></i> Print Document</button>';
            html += '<button class="doc-action-btn doc-action-btn-close" onclick="window.close()"><i class="ri-close-line"></i> Close</button>';
            html += '</div>';

            // A4 Page
            html += '<div class="doc-page">';

            // Header / Letterhead
            html += '<div class="doc-header">';
            html += '<div class="doc-header-left">';
            html += '<h1><i class="ri-briefcase-line" style="color:#023f57;font-size:20px;"></i> Job Application Form</h1>';
            html += '<p>Recruitment Management System</p>';
            html += '</div>';
            html += '<div class="doc-header-right">';
            html += '<div class="doc-ref"><strong>Application ID:</strong> #' + (app.id || '-') + '</div>';
            html += '<div class="doc-ref"><strong>Date:</strong> ' + printDate + '</div>';
            html += '<div class="doc-ref"><strong>Applied:</strong> ' + (app.applied_date_display || '-') + '</div>';
            html += '</div>';
            html += '</div>';

            // Title Bar
            html += '<div class="doc-title-bar">';
            html += '<h2><i class="ri-file-text-line"></i> Application Details</h2>';
            html += '<span class="doc-id">REF: APP-' + String(app.id || 0).padStart(4, '0') + '</span>';
            html += '</div>';

            // Section: Candidate Information
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="ri-user-line"></i> Candidate Information</div>';
            html += '<table class="doc-fields">';
            html += '<tr><td class="field-label">Full Name</td><td class="field-value" colspan="3"><strong>' + (app.candidate_name || '-') + '</strong></td></tr>';
            html += '<tr><td class="field-label">Email</td><td class="field-value">' + (app.email || '-') + '</td>';
            html += '<td class="field-label">Contact No.</td><td class="field-value">' + (app.contact_number || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="field-label">Nationality</td><td class="field-value">' + (app.nationality || '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="field-label">Location</td><td class="field-value">' + (app.current_location || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Section: Position Details
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="ri-briefcase-line"></i> Position Details</div>';
            html += '<table class="doc-fields">';
            html += '<tr><td class="field-label">Position</td><td class="field-value"><strong>' + (app.position || '-') + '</strong></td>';
            html += '<td class="field-label">Department</td><td class="field-value">' + (app.company || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="field-label">Experience</td><td class="field-value">' + (app.experience || '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="field-label">Expected Salary</td><td class="field-value">' + (app.expected_salary || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Section: Qualifications
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="ri-graduation-cap-line"></i> Qualifications</div>';
            html += '<table class="doc-fields">';
            html += '<tr><td class="field-label">Academic</td><td class="field-value" colspan="3">' + qualList(app.academic_qualification) + '</td></tr>';
            html += '<tr><td class="field-label">Technical</td><td class="field-value" colspan="3">' + qualList(app.technical_qualification) + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Section: Pipeline & Status
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="ri-list-unordered"></i> Pipeline & Status</div>';
            html += '<table class="doc-fields">';
            html += '<tr><td class="field-label">Stage</td><td class="field-value">' + stageHtml + '</td>';
            html += '<td class="field-label">Status</td><td class="field-value">' + statusHtml + '</td></tr>';
            html += '<tr><td class="field-label">Assigned To</td><td class="field-value">' + (app.assigned_to_name || '<span class="empty-val">Unassigned</span>') + '</td>';
            html += '<td class="field-label">Created By</td><td class="field-value">' + (app.created_by_name || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Section: Timeline & Dates
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="ri-calendar-line"></i> Timeline</div>';
            html += '<table class="doc-fields">';
            html += '<tr><td class="field-label">Applied Date</td><td class="field-value">' + (app.applied_date_display || '-') + '</td>';
            html += '<td class="field-label">Joined Date</td><td class="field-value">' + (app.joined_date_display || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="field-label">Days to Join</td><td class="field-value">' + (app.days_to_join !== null && app.days_to_join !== undefined ? app.days_to_join + ' days' : '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="field-label">Next Action</td><td class="field-value">' + (app.next_action || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="field-label">Action Date</td><td class="field-value" colspan="3">' + (app.next_action_date_display || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Section: Notes
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="ri-sticky-note-line"></i> Notes / Remarks</div>';
            html += '<div class="doc-notes">' + (app.notes || '<span class="empty-val">No notes recorded.</span>') + '</div>';
            html += '</div>';

            // Footer
            html += '<div class="doc-footer">';
            html += '<span>Generated on ' + printDate + ' &bull; Application #' + (app.id || '-') + '</span>';
            html += '<span>Confidential &mdash; Recruitment Management System</span>';
            html += '</div>';

            html += '</div>'; // end doc-page
            html += '</body></html>';

            printWin.document.write(html);
            printWin.document.close();
        }

        function viewApplicationInPage(app) {
            var qualList = function(arr) {
                if (!arr || arr.length === 0) return '<span class="empty-val">N/A</span>';
                return arr.map(function(q) { return '<span class="doc-qual-tag">' + q + '</span>'; }).join('');
            };

            var stageHtml = app.stage_name ? '<span class="doc-status-tag" style="background:' + (app.stage_color || '#6B7280') + '">' + (app.stage_name || '-') + '</span>' : '<span class="empty-val">N/A</span>';
            var statusHtml = app.status_name ? '<span class="doc-status-tag" style="background:' + (app.status_color || '#6B7280') + '">' + (app.status_name || '-') + '</span>' : '<span class="empty-val">N/A</span>';

            var today = new Date();
            var printDate = today.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: '2-digit' });

            var html = '<div class="print-overlay-page">';

            // Header
            html += '<div class="po-header">';
            html += '<div class="po-header-left">';
            html += '<h1><i class="ri-briefcase-line" style="color:#023f57;font-size:20px;"></i> Job Application Form</h1>';
            html += '<p>Recruitment Management System</p>';
            html += '</div>';
            html += '<div class="po-header-right">';
            html += '<div class="po-ref"><strong>Application ID:</strong> #' + (app.id || '-') + '</div>';
            html += '<div class="po-ref"><strong>Date:</strong> ' + printDate + '</div>';
            html += '<div class="po-ref"><strong>Applied:</strong> ' + (app.applied_date_display || '-') + '</div>';
            html += '</div>';
            html += '</div>';

            // Title Bar
            html += '<div class="po-title-bar">';
            html += '<h2><i class="ri-file-text-line"></i> Application Details</h2>';
            html += '<span>REF: APP-' + String(app.id || 0).padStart(4, '0') + '</span>';
            html += '</div>';

            // Candidate Information
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="ri-user-line"></i> Candidate Information</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Full Name</td><td class="po-value" colspan="3"><strong>' + (app.candidate_name || '-') + '</strong></td></tr>';
            html += '<tr><td class="po-label">Email</td><td class="po-value">' + (app.email || '-') + '</td>';
            html += '<td class="po-label">Contact No.</td><td class="po-value">' + (app.contact_number || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="po-label">Nationality</td><td class="po-value">' + (app.nationality || '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="po-label">Location</td><td class="po-value">' + (app.current_location || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table></div>';

            // Position Details
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="ri-briefcase-line"></i> Position Details</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Position</td><td class="po-value"><strong>' + (app.position || '-') + '</strong></td>';
            html += '<td class="po-label">Department</td><td class="po-value">' + (app.company || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="po-label">Experience</td><td class="po-value">' + (app.experience || '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="po-label">Expected Salary</td><td class="po-value">' + (app.expected_salary || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table></div>';

            // Qualifications
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="ri-graduation-cap-line"></i> Qualifications</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Academic</td><td class="po-value" colspan="3">' + qualList(app.academic_qualification) + '</td></tr>';
            html += '<tr><td class="po-label">Technical</td><td class="po-value" colspan="3">' + qualList(app.technical_qualification) + '</td></tr>';
            html += '</table></div>';

            // Pipeline & Status
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="ri-list-unordered"></i> Pipeline & Status</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Stage</td><td class="po-value">' + stageHtml + '</td>';
            html += '<td class="po-label">Status</td><td class="po-value">' + statusHtml + '</td></tr>';
            html += '<tr><td class="po-label">Assigned To</td><td class="po-value">' + (app.assigned_to_name || '<span class="empty-val">Unassigned</span>') + '</td>';
            html += '<td class="po-label">Created By</td><td class="po-value">' + (app.created_by_name || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table></div>';

            // Timeline
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="ri-calendar-line"></i> Timeline</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Applied Date</td><td class="po-value">' + (app.applied_date_display || '-') + '</td>';
            html += '<td class="po-label">Joined Date</td><td class="po-value">' + (app.joined_date_display || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="po-label">Days Elapsed</td><td class="po-value">' + (app.days_elapsed !== null && app.days_elapsed !== undefined ? app.days_elapsed + ' days' : '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="po-label">Next Action</td><td class="po-value">' + (app.next_action || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="po-label">Action Date</td><td class="po-value" colspan="3">' + (app.next_action_date_display || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table></div>';

            // Notes
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="ri-sticky-note-line"></i> Notes / Remarks</div>';
            html += '<div class="po-notes">' + (app.notes || '<span class="empty-val">No notes recorded.</span>') + '</div>';
            html += '</div>';

            // Footer
            html += '<div class="po-footer">';
            html += '<span>Generated on ' + printDate + ' &bull; Application #' + (app.id || '-') + '</span>';
            html += '<span>Confidential &mdash; Recruitment Management System</span>';
            html += '</div>';

            html += '</div>';

            document.getElementById('printOverlayContent').innerHTML = html;
            document.getElementById('printOverlay').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function printOverlayContent() {
            var overlay = document.getElementById('printOverlay');
            overlay.classList.add('printing');
            window.print();
            setTimeout(function() { overlay.classList.remove('printing'); }, 500);
        }

        function closePrintOverlay() {
            document.getElementById('printOverlay').style.display = 'none';
            document.getElementById('printOverlayContent').innerHTML = '';
            document.body.style.overflow = '';
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        function escapeHtml(text) {
            if (text === null || text === undefined || text === '') return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        function profileField(label, value) {
            return '<div><div class="profile-field-label">' + escapeHtml(label) + '</div><div class="profile-field-value">' +
                (value !== null && value !== undefined && value !== '' ? escapeHtml(value) : '<span style="color:var(--text-muted);">&mdash;</span>') +
                '</div></div>';
        }

        var currentProfileApp = null;

        function renderProfileModal(app) {
            currentProfileApp = app;
            document.getElementById('profileModalTitle').innerHTML = '<i class="ri-eye-line"></i> ' + escapeHtml(app.candidate_name);

            var stageHtml = app.stage_name ? '<span class="stage-badge" style="color:' + (app.stage_color || '#6B7280') + '">' + escapeHtml(app.stage_name) + '</span>' : '';
            var statusHtml = app.status_name ? '<span class="stage-badge" style="color:' + (app.status_color || '#6B7280') + '">' + escapeHtml(app.status_name) + '</span>' : '';

            var html = '';
            html += '<div class="profile-summary">';
            html += '<div>';
            html += '<h2>' + escapeHtml(app.candidate_name) + '</h2>';
            html += '<div>' + escapeHtml(app.position) + (app.company ? ' &ndash; ' + escapeHtml(app.company) : '') + '</div>';
            html += '<div class="profile-summary-meta">';
            html += '<span><i class="ri-hashtag"></i> ' + escapeHtml(app.recruitment_reference || ('APP-' + app.id)) + '</span>';
            if (app.email) html += '<span><i class="ri-mail-line"></i> ' + escapeHtml(app.email) + '</span>';
            if (app.contact_number) html += '<span><i class="ri-phone-line"></i> ' + escapeHtml(app.contact_number) + '</span>';
            if (app.current_location) html += '<span><i class="ri-map-pin-line"></i> ' + escapeHtml(app.current_location) + '</span>';
            html += '</div></div>';
            html += '<div class="profile-summary-badges">' + stageHtml + statusHtml;
            if ((canManageScreening || canManageAssessment || canManageInterviews) && app.overall_score !== null && app.overall_score !== undefined) {
                html += '<span class="stage-badge" style="color:var(--navy-accent);"><i class="ri-medal-line"></i> Overall: ' + app.overall_score + '%</span>';
            }
            html += '</div>';
            html += '</div>';

            html += '<div class="profile-tabs">';
            html += '<button type="button" class="profile-tab-btn active" data-tab="overview" onclick="switchProfileTab(\'overview\', ' + app.id + ')">Overview</button>';
            html += '<button type="button" class="profile-tab-btn" data-tab="application" onclick="switchProfileTab(\'application\', ' + app.id + ')">Application</button>';
            html += '<button type="button" class="profile-tab-btn" data-tab="documents" onclick="switchProfileTab(\'documents\', ' + app.id + ')">CV &amp; Documents</button>';
            if (canManageScreening) html += '<button type="button" class="profile-tab-btn" data-tab="screening" onclick="switchProfileTab(\'screening\', ' + app.id + ')">Screening</button>';
            if (canManageAssessment) html += '<button type="button" class="profile-tab-btn" data-tab="assessment" onclick="switchProfileTab(\'assessment\', ' + app.id + ')">Assessment</button>';
            if (canManageInterviews) html += '<button type="button" class="profile-tab-btn" data-tab="interviews" onclick="switchProfileTab(\'interviews\', ' + app.id + ')">Interviews</button>';
            html += '<button type="button" class="profile-tab-btn" data-tab="notes" onclick="switchProfileTab(\'notes\', ' + app.id + ')">Notes</button>';
            html += '<button type="button" class="profile-tab-btn" data-tab="activity" onclick="switchProfileTab(\'activity\', ' + app.id + ')">Activity</button>';
            html += '</div>';

            html += '<div class="profile-tab-content">';
            html += '<div class="profile-tab-pane active" id="profile-tab-overview">' + buildOverviewTab(app) + '</div>';
            html += '<div class="profile-tab-pane" id="profile-tab-application">' + buildApplicationTab(app) + '</div>';
            html += '<div class="profile-tab-pane" id="profile-tab-documents"><div class="profile-empty"><i class="ri-loader-4-line ri-spin"></i></div></div>';
            if (canManageScreening) html += '<div class="profile-tab-pane" id="profile-tab-screening">' + buildScreeningTab(app) + '</div>';
            if (canManageAssessment) html += '<div class="profile-tab-pane" id="profile-tab-assessment">' + buildAssessmentTab(app) + '</div>';
            if (canManageInterviews) html += '<div class="profile-tab-pane" id="profile-tab-interviews"><div class="profile-empty"><i class="ri-loader-4-line ri-spin"></i></div></div>';
            html += '<div class="profile-tab-pane" id="profile-tab-notes">' + buildNotesTab(app) + '</div>';
            html += '<div class="profile-tab-pane" id="profile-tab-activity"><div class="profile-empty"><i class="ri-loader-4-line ri-spin"></i></div></div>';
            html += '</div>';

            document.getElementById('viewModalBody').innerHTML = html;
        }

        function switchProfileTab(tabName, appId) {
            document.querySelectorAll('.profile-tab-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.getAttribute('data-tab') === tabName);
            });
            document.querySelectorAll('.profile-tab-pane').forEach(function(pane) {
                pane.classList.toggle('active', pane.id === 'profile-tab-' + tabName);
            });

            if (tabName === 'documents') loadProfileDocuments(appId);
            if (tabName === 'interviews') loadInterviewRounds(appId);
            if (tabName === 'activity') loadProfileActivity(appId);
        }

        function buildOverviewTab(app) {
            var html = '<div class="profile-grid">';
            html += profileField('Department', app.company);
            html += profileField('Assigned To', app.assigned_to_name);
            html += profileField('Applied Date', app.applied_date_display);
            html += profileField('Joined Date', app.joined_date_display);
            html += profileField('Next Action', app.next_action);
            html += profileField('Next Action Date', app.next_action_date_display);
            html += '</div>';

            if (canManageScreening || canManageAssessment || canManageInterviews) {
                var sc = app.screening || {};
                var asm = app.assessment || {};
                var iv = app.interview || {};
                html += '<h4 class="form-section-title"><i class="ri-medal-line"></i> Scores</h4>';
                html += '<div class="profile-grid">';
                html += profileField('Overall Score', app.overall_score !== null && app.overall_score !== undefined ? app.overall_score + '%' : null);
                if (canManageScreening) html += profileField('Screening Score', sc.screening_score !== null && sc.screening_score !== undefined ? sc.screening_score + '%' : null);
                if (canManageAssessment) html += profileField('Assessment Score', asm.assessment_score !== null && asm.assessment_score !== undefined ? asm.assessment_score + '%' : null);
                if (canManageInterviews) html += profileField('Avg. Interview Score (' + (iv.interview_count || 0) + ' round' + (iv.interview_count === 1 ? '' : 's') + ')', iv.avg_interview_score !== null && iv.avg_interview_score !== undefined ? iv.avg_interview_score + '%' : null);
                html += '</div>';
            }

            html += '<button type="button" class="btn btn-secondary btn-sm" onclick="viewApplication(currentProfileApp)"><i class="ri-printer-line"></i> Print / Export</button>';
            return html;
        }

        function buildApplicationTab(app) {
            var html = '<div class="profile-grid">';
            html += profileField('Email', app.email);
            html += profileField('Contact Number', app.contact_number);
            html += profileField('Current Location', app.current_location);
            html += profileField('County of Residence', app.county_of_residence);
            html += profileField('Nationality', app.nationality);
            html += profileField('Experience', app.experience);
            html += profileField('Expected Salary', app.expected_salary);
            html += profileField('Availability / Notice Period', app.availability_notice_period);
            html += '</div>';

            if (app.highest_education_level || app.institution || app.course_qualification || app.professional_certifications || app.relevant_training) {
                html += '<h4 class="form-section-title"><i class="ri-graduation-cap-line"></i> Education</h4>';
                html += '<div class="profile-grid">';
                html += profileField('Highest Education Level', app.highest_education_level);
                html += profileField('Institution', app.institution);
                html += profileField('Course / Qualification', app.course_qualification);
                html += profileField('Graduation Year', app.graduation_year);
                html += '</div>';
                if (app.professional_certifications) html += profileField('Professional Certifications', app.professional_certifications);
                if (app.relevant_training) html += profileField('Relevant Training', app.relevant_training);
            }

            if (app.current_employer || app.current_job_title || app.previous_employers || app.aviation_experience || app.customer_service_experience || app.relevant_skills) {
                html += '<h4 class="form-section-title"><i class="ri-briefcase-4-line"></i> Work Experience</h4>';
                html += '<div class="profile-grid">';
                html += profileField('Current / Most Recent Employer', app.current_employer);
                html += profileField('Job Title', app.current_job_title);
                html += '</div>';
                if (app.previous_employers) html += profileField('Previous Employers', app.previous_employers);
                if (app.aviation_experience) html += profileField('Aviation Experience', app.aviation_experience);
                if (app.customer_service_experience) html += profileField('Customer Service Experience', app.customer_service_experience);
                if (app.relevant_skills) html += profileField('Relevant Skills', app.relevant_skills);
            }

            var acad = app.academic_qualification || [];
            var tech = app.technical_qualification || [];
            if (acad.length || tech.length) {
                html += '<h4 class="form-section-title"><i class="ri-award-line"></i> Qualifications</h4>';
                html += '<div style="margin-bottom:10px;">';
                acad.forEach(function(q) { html += '<span class="doc-qual-tag">' + escapeHtml(q) + '</span>'; });
                tech.forEach(function(q) { html += '<span class="doc-qual-tag">' + escapeHtml(q) + '</span>'; });
                html += '</div>';
            }

            return html;
        }

        function passSelectHtml(id, val) {
            var v = (val === null || val === undefined) ? '' : String(val);
            return '<select id="' + id + '"><option value="">Not Screened</option>' +
                '<option value="1"' + (v === '1' ? ' selected' : '') + '>Pass</option>' +
                '<option value="0"' + (v === '0' ? ' selected' : '') + '>Fail</option></select>';
        }

        function buildScreeningTab(app) {
            var sc = app.screening || {};
            var html = '<div class="form-grid">';
            html += '<div class="form-group"><label>Eligibility</label>' + passSelectHtml('eligibilityPass', sc.eligibility_pass) + '</div>';
            html += '<div class="form-group"><label>Minimum Qualification</label>' + passSelectHtml('minQualificationPass', sc.min_qualification_pass) + '</div>';
            html += '<div class="form-group"><label>Required Experience</label>' + passSelectHtml('requiredExperiencePass', sc.required_experience_pass) + '</div>';
            html += '<div class="form-group"><label>Location Requirement</label>' + passSelectHtml('locationRequirementPass', sc.location_requirement_pass) + '</div>';
            html += '<div class="form-group"><label>Screening Score</label><input type="number" id="screeningScore" min="0" max="100" value="' + (sc.screening_score !== null && sc.screening_score !== undefined ? sc.screening_score : '') + '"></div>';
            html += '</div>';
            html += '<div class="form-group"><label>Recruiter Comments</label><textarea id="recruiterComments" rows="3">' + escapeHtml(sc.recruiter_comments) + '</textarea></div>';
            if (sc.screened_by_name) {
                html += '<p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Last screened by ' + escapeHtml(sc.screened_by_name) + (sc.screened_at ? ' on ' + escapeHtml(sc.screened_at) : '') + '</p>';
            }
            html += '<button type="button" class="btn btn-primary btn-sm" onclick="saveScreening(' + app.id + ')"><i class="ri-save-line"></i> Save Screening</button>';
            return html;
        }

        function buildAssessmentTab(app) {
            var asm = app.assessment || {};
            var html = '<div class="form-grid">';
            html += '<div class="form-group"><label>Assessment Score</label><input type="number" id="assessmentScore" min="0" max="100" value="' + (asm.assessment_score !== null && asm.assessment_score !== undefined ? asm.assessment_score : '') + '"></div>';
            html += '</div>';
            html += '<div class="form-group"><label>Assessment Comments</label><textarea id="assessmentComments" rows="3">' + escapeHtml(asm.assessment_comments) + '</textarea></div>';
            if (asm.assessed_by_name) {
                html += '<p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Last assessed by ' + escapeHtml(asm.assessed_by_name) + (asm.assessed_at ? ' on ' + escapeHtml(asm.assessed_at) : '') + '</p>';
            }
            html += '<button type="button" class="btn btn-primary btn-sm" onclick="saveAssessment(' + app.id + ')"><i class="ri-save-line"></i> Save Assessment</button>';
            return html;
        }

        function buildNotesTab(app) {
            var html = '<div class="form-group"><label>Notes</label><textarea id="profileNotes" rows="4">' + escapeHtml(app.notes) + '</textarea></div>';
            if (canViewSensitiveNotes) {
                html += '<div class="form-grid">';
                html += '<div class="form-group"><label><i class="ri-close-circle-line"></i> Rejection Reason</label><textarea id="profileRejectionReason" rows="3">' + escapeHtml(app.rejection_reason) + '</textarea></div>';
                html += '<div class="form-group"><label><i class="ri-shield-user-line"></i> HR Comments</label><textarea id="profileHrComments" rows="3">' + escapeHtml(app.hr_comments) + '</textarea></div>';
                html += '</div>';
            }
            html += '<button type="button" class="btn btn-primary btn-sm" onclick="saveNotes(' + app.id + ')"><i class="ri-save-line"></i> Save Notes</button>';
            return html;
        }

        function loadProfileDocuments(appId) {
            var pane = document.getElementById('profile-tab-documents');
            $.ajax({
                url: '?action=getDocuments&application_id=' + appId,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (!response.success) {
                        pane.innerHTML = '<div class="profile-empty">Failed to load documents.</div>';
                        return;
                    }
                    var html = '';
                    if (response.data.length === 0) {
                        html += '<div class="profile-empty"><i class="ri-inbox-line" style="font-size:32px;"></i><p>No documents on file.</p></div>';
                    } else {
                        response.data.forEach(function(doc) {
                            html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-light);">';
                            html += '<div><i class="ri-file-line"></i> ' + escapeHtml(doc.file_name) + ' <span style="color:var(--text-muted);font-size:12px;">(' + escapeHtml(doc.document_label) + (doc.uploaded_by_name ? ' &bull; ' + escapeHtml(doc.uploaded_by_name) : '') + ')</span></div>';
                            html += '<a href="' + doc.file_path + '" target="_blank" class="btn btn-secondary btn-sm"><i class="ri-download-line"></i></a>';
                            html += '</div>';
                        });
                    }
                    html += '<button type="button" class="btn btn-primary btn-sm" style="margin-top:16px;" onclick="openDocsModal(currentProfileApp.id, currentProfileApp.candidate_name)"><i class="ri-upload-cloud-2-line"></i> Manage Documents</button>';
                    pane.innerHTML = html;
                },
                error: function() {
                    pane.innerHTML = '<div class="profile-empty">Connection error.</div>';
                }
            });
        }

        function loadProfileActivity(appId) {
            var pane = document.getElementById('profile-tab-activity');
            $.ajax({
                url: '?action=getApplicationActivity&application_id=' + appId,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (!response.success) {
                        pane.innerHTML = '<div class="profile-empty">Failed to load activity.</div>';
                        return;
                    }
                    var html = '';
                    if (response.data.length === 0) {
                        html += '<div class="profile-empty"><i class="ri-history-line" style="font-size:32px;"></i><p>No activity recorded yet.</p></div>';
                    } else {
                        response.data.forEach(function(log) {
                            html += '<div class="activity-log-item">';
                            html += '<div>' + escapeHtml(log.description) + '</div>';
                            html += '<div class="activity-log-meta">' + escapeHtml(log.user_name || 'System') + ' &bull; ' + escapeHtml(log.created_at) + '</div>';
                            html += '</div>';
                        });
                    }
                    pane.innerHTML = html;
                },
                error: function() {
                    pane.innerHTML = '<div class="profile-empty">Connection error.</div>';
                }
            });
        }

        // Form submit handler
        document.getElementById('appForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // Collect checked qualifications as comma-separated strings
            var academicQuals = [];
            document.querySelectorAll('#academicCheckboxes input[type="checkbox"]:checked').forEach(function(cb) {
                academicQuals.push(cb.value);
            });

            var technicalQuals = [];
            document.querySelectorAll('#technicalCheckboxes input[type="checkbox"]:checked').forEach(function(cb) {
                technicalQuals.push(cb.value);
            });

            var formData = new FormData(this);

            // Remove individual checkbox entries and add as comma-separated
            formData.delete('academic_qualification[]');
            formData.delete('technical_qualification[]');
            formData.set('academic_qualification', academicQuals.join(','));
            formData.set('technical_qualification', technicalQuals.join(','));

            var action = isEditMode ? 'updateApplication' : 'addApplication';

            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var inlineFile = document.getElementById('inlineDocFile');
                        var hasFile = inlineFile && inlineFile.files && inlineFile.files.length > 0;

                        if (hasFile) {
                            var appIdForUpload = isEditMode
                                ? document.getElementById('appId').value
                                : response.application_id;

                            if (appIdForUpload) {
                                var docFormData = new FormData();
                                docFormData.append('application_id', appIdForUpload);
                                docFormData.append('document_label', document.getElementById('inlineDocLabel').value);
                                docFormData.append('document', inlineFile.files[0]);

                                Swal.fire({ title: 'Uploading document...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                                $.ajax({
                                    url: '?action=uploadDocument',
                                    method: 'POST',
                                    data: docFormData,
                                    processData: false,
                                    contentType: false,
                                    dataType: 'json',
                                    success: function(docResponse) {
                                        var msg = response.message;
                                        if (docResponse.success) {
                                            msg += ' Document uploaded.';
                                            Swal.fire({ icon: 'success', title: 'Success!', text: msg, timer: 3000, showConfirmButton: false });
                                        } else {
                                            Swal.fire({ icon: 'warning', title: 'Partial Success', text: msg + ' Document upload failed: ' + docResponse.message });
                                        }
                                        closeModal();
                                        setTimeout(() => loadApplications(), 100);
                                    },
                                    error: function() {
                                        Swal.fire({ icon: 'warning', title: 'Partial Success', text: response.message + ' But document upload failed due to connection error.' });
                                        closeModal();
                                        setTimeout(() => loadApplications(), 100);
                                    }
                                });
                            } else {
                                Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                                closeModal();
                                setTimeout(() => loadApplications(), 100);
                            }
                        } else {
                            Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                            closeModal();
                            setTimeout(() => loadApplications(), 100);
                        }
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        // Document Management Functions
        var currentDocsAppId = null;

        function openDocsModal(appId, candidateName) {
            currentDocsAppId = appId;
            document.getElementById('docsModalName').textContent = candidateName || 'Application #' + appId;
            document.getElementById('docAppId').value = appId;
            document.getElementById('docUploadForm').reset();
            document.getElementById('docsModal').classList.add('active');
            loadDocuments(appId);
        }

        function closeDocsModal() {
            document.getElementById('docsModal').classList.remove('active');
            currentDocsAppId = null;
        }

        document.getElementById('docsModal').addEventListener('click', function(e) {
            if (e.target === this) closeDocsModal();
        });

        function loadDocuments(appId) {
            document.getElementById('docsList').innerHTML = '<p style="color: #999; text-align: center; padding: 20px;"><i class="ri-loader-4-line ri-spin"></i> Loading...</p>';

            $.ajax({
                url: '?action=getDocuments&application_id=' + appId,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        document.getElementById('docsCount').textContent = response.data.length;
                        renderDocumentsList(response.data);
                    } else {
                        document.getElementById('docsList').innerHTML = '<p style="color: #ea4335; text-align: center;">' + (response.message || 'Failed to load documents') + '</p>';
                    }
                },
                error: function() {
                    document.getElementById('docsList').innerHTML = '<p style="color: #ea4335; text-align: center;">Connection error</p>';
                }
            });
        }

        function renderDocumentsList(docs) {
            var container = document.getElementById('docsList');
            if (docs.length === 0) {
                container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;"><i class="ri-folder-open-line"></i> No documents uploaded yet.</p>';
                return;
            }

            var html = '';
            docs.forEach(function(doc) {
                html += '<div class="doc-item">';
                html += '<div class="doc-item-icon">' + getFileIcon(doc.file_type) + '</div>';
                html += '<div class="doc-item-info">';
                html += '<div class="doc-item-name">' + doc.file_name + '</div>';
                html += '<div class="doc-item-meta">';
                html += '<span class="doc-label-badge">' + doc.document_label + '</span> ';
                html += (doc.file_size_kb ? doc.file_size_kb + ' KB' : '') + ' &bull; ';
                html += doc.uploaded_at;
                if (doc.uploaded_by_name) html += ' &bull; by ' + doc.uploaded_by_name;
                html += '</div>';
                html += '</div>';
                html += '<div class="doc-item-actions">';
                // Add preview button for viewable file types (PDF, images)
                var isViewable = doc.file_type && (doc.file_type.indexOf('pdf') !== -1 || doc.file_type.indexOf('image') !== -1);
                if (isViewable) {
                    html += '<button class="action-icon" onclick="previewDocument(\'' + doc.file_path.replace(/'/g, "\\'") + '\', \'' + (doc.file_type || '').replace(/'/g, "\\'") + '\', \'' + (doc.file_name || '').replace(/'/g, "\\'") + '\')" title="Preview in page" style="color: var(--navy-primary);"><i class="ri-eye-line"></i></button>';
                }
                html += '<a href="' + doc.file_path + '" target="_blank" class="action-icon" title="Open in new tab" style="color: var(--navy-accent);"><i class="ri-external-link-line"></i></a>';
                html += '<a href="' + doc.file_path + '" download class="action-icon" title="Download" style="color: #34a853;"><i class="ri-download-line"></i></a>';
                if (userRole === 'admin') {
                    html += '<button class="action-icon delete-icon" onclick="deleteDocument(' + doc.id + ')" title="Delete"><i class="ri-delete-bin-line"></i></button>';
                }
                html += '</div>';
                html += '</div>';
            });
            container.innerHTML = html;
        }

        function getFileIcon(mimeType) {
            if (!mimeType) return '<i class="ri-file-line" style="color: #6B7280;"></i>';
            if (mimeType.indexOf('pdf') !== -1) return '<i class="ri-file-pdf-line" style="color: #ea4335;"></i>';
            if (mimeType.indexOf('word') !== -1 || mimeType.indexOf('msword') !== -1) return '<i class="ri-file-word-2-line" style="color: #2b579a;"></i>';
            if (mimeType.indexOf('image') !== -1) return '<i class="ri-file-image-line" style="color: #34a853;"></i>';
            return '<i class="ri-file-line" style="color: #6B7280;"></i>';
        }

        function previewDocument(filePath, fileType, fileName) {
            var previewArea = document.getElementById('docPreviewArea');
            var previewContent = document.getElementById('docPreviewContent');
            document.getElementById('previewDocName').textContent = fileName || 'Document';

            if (fileType.indexOf('pdf') !== -1) {
                previewContent.innerHTML = '<iframe src="' + filePath + '" style="width: 100%; height: 500px; border: none;"></iframe>';
            } else if (fileType.indexOf('image') !== -1) {
                previewContent.innerHTML = '<div style="text-align: center; padding: 15px;"><img src="' + filePath + '" alt="' + fileName + '" style="max-width: 100%; max-height: 500px; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>';
            }

            previewArea.style.display = 'block';
            previewArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function closeDocPreview() {
            var previewArea = document.getElementById('docPreviewArea');
            previewArea.style.display = 'none';
            document.getElementById('docPreviewContent').innerHTML = '';
        }

        // Document upload form handler
        document.getElementById('docUploadForm').addEventListener('submit', function(e) {
            e.preventDefault();

            var fileInput = document.getElementById('docFile');
            if (!fileInput.files || fileInput.files.length === 0) {
                Swal.fire({ icon: 'warning', title: 'No File', text: 'Please select a file to upload' });
                return;
            }

            var formData = new FormData(this);
            var uploadBtn = document.getElementById('docUploadBtn');
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="ri-loader-4-line ri-spin"></i> Uploading...';

            $.ajax({
                url: '?action=uploadDocument',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="ri-upload-line"></i> Upload';

                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Uploaded!', text: response.message, timer: 2000, showConfirmButton: false });
                        document.getElementById('docUploadForm').reset();
                        document.getElementById('docAppId').value = currentDocsAppId;
                        loadDocuments(currentDocsAppId);
                        // Refresh table to update document count
                        setTimeout(() => loadApplications(), 500);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Upload Failed', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="ri-upload-line"></i> Upload';
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        function deleteDocument(docId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Document?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    var formData = new FormData();
                    formData.append('document_id', docId);

                    $.ajax({
                        url: '?action=deleteDocument',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                if (currentDocsAppId) loadDocuments(currentDocsAppId);
                                // Refresh table to update document count
                                setTimeout(() => loadApplications(), 500);
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                        }
                    });
                }
            });
        }

        function deleteApplication(appId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Application?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    var formData = new FormData();
                    formData.append('id', appId);

                    $.ajax({
                        url: '?action=deleteApplication',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => loadApplications(), 100);
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>
