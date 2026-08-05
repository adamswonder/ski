<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

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

                $sql = "SELECT a.id, a.candidate_name, a.email, a.contact_number, a.position, a.company,
                               a.experience, a.academic_qualification, a.technical_qualification,
                               a.expected_salary, a.nationality, a.current_location,
                               a.stage_id, a.status_id, a.next_action, a.next_action_date,
                               a.applied_date, a.joined_date, a.days_to_join, a.notes,
                               a.assigned_to, a.created_by, a.created_at, a.updated_at,
                               s.name AS stage_name, s.color AS stage_color, s.icon AS stage_icon,
                               st.name AS status_name, st.color AS status_color, st.icon AS status_icon,
                               u.full_name AS assigned_to_name,
                               c.full_name AS created_by_name,
                               COALESCE(dc.doc_count, 0) AS document_count
                        FROM applications a
                        LEFT JOIN stages s ON a.stage_id = s.id
                        LEFT JOIN stages st ON a.status_id = st.id
                        LEFT JOIN users u ON a.assigned_to = u.id
                        LEFT JOIN users c ON a.created_by = c.id
                        LEFT JOIN (
                            SELECT application_id, COUNT(*) AS doc_count
                            FROM application_documents
                            GROUP BY application_id
                        ) dc ON dc.application_id = a.id";

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

                $stmt = $conn->prepare("INSERT INTO applications (candidate_name, email, contact_number, position, company, experience, academic_qualification, technical_qualification, expected_salary, nationality, current_location, stage_id, status_id, next_action, next_action_date, applied_date, joined_date, notes, assigned_to, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $createdBy = $user_id;
                $stmt->bind_param("sssssssssssiisssssis",
                    $candidateName, $email, $contactNumber, $position, $company,
                    $experience, $academicQual, $technicalQual, $expectedSalary,
                    $nationality, $currentLocation, $stageId, $statusId,
                    $nextAction, $nextActionDate, $appliedDate, $joinedDate,
                    $notes, $assignedTo, $createdBy
                );

                if ($stmt->execute()) {
                    $newAppId = $conn->insert_id;
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

                $stmt = $conn->prepare("UPDATE applications SET candidate_name = ?, email = ?, contact_number = ?, position = ?, company = ?, experience = ?, academic_qualification = ?, technical_qualification = ?, expected_salary = ?, nationality = ?, current_location = ?, stage_id = ?, status_id = ?, next_action = ?, next_action_date = ?, applied_date = ?, joined_date = ?, notes = ?, assigned_to = ? WHERE id = ?");

                $stmt->bind_param("sssssssssssiisssssii",
                    $candidateName, $email, $contactNumber, $position, $company,
                    $experience, $academicQual, $technicalQual, $expectedSalary,
                    $nationality, $currentLocation, $stageId, $statusId,
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

                $stmt = $conn->prepare("SELECT d.*, u.full_name AS uploaded_by_name FROM application_documents d LEFT JOIN users u ON d.uploaded_by = u.id WHERE d.application_id = ? ORDER BY d.uploaded_at DESC");
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
    <title>Applications - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=5.1">

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
                <h1><i class="fas fa-file-alt"></i> Applications</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-briefcase"></i> Job Applications</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadApplications()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Application
                        </button>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section" id="filtersSection" style="display: none;">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times-circle"></i> Clear All
                        </button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Applied From</label>
                            <input type="date" id="filterDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Applied To</label>
                            <input type="date" id="filterDateTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-stream"></i> Stage</label>
                            <select id="filterStage" class="filter-input">
                                <option value="">All Stages</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tags"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Statuses</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-building"></i> Department</label>
                            <select id="filterCompany" class="filter-input">
                                <option value="">All Departments</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-user-tie"></i> Assigned To</label>
                            <select id="filterAssigned" class="filter-input">
                                <option value="">All</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="table-scroll-hint">
                    <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
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
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Application</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="appForm">
                    <input type="hidden" id="appId" name="id">

                    <!-- Candidate Info -->
                    <h4 class="form-section-title"><i class="fas fa-user"></i> Candidate Information</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Candidate Name *</label>
                            <input type="text" id="candidateName" name="candidate_name" required maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email *</label>
                            <input type="email" id="appEmail" name="email" required maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Contact Number</label>
                            <input type="text" id="contactNumber" name="contact_number" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> Nationality</label>
                            <input type="text" id="nationality" name="nationality" maxlength="80">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Current Location</label>
                            <input type="text" id="currentLocation" name="current_location" maxlength="150">
                        </div>
                    </div>

                    <!-- Job Details -->
                    <h4 class="form-section-title"><i class="fas fa-briefcase"></i> Job Details</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-id-badge"></i> Position *</label>
                            <input type="text" id="position" name="position" required maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Department</label>
                            <input type="text" id="company" name="company" maxlength="150" placeholder="e.g. Marketing, IT, HR">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Experience</label>
                            <input type="text" id="experience" name="experience" maxlength="100" placeholder="e.g. 3 years, 5+ years">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Expected Salary</label>
                            <input type="text" id="expectedSalary" name="expected_salary" maxlength="50" placeholder="e.g. 50,000 PKR">
                        </div>
                    </div>

                    <!-- Qualifications -->
                    <h4 class="form-section-title"><i class="fas fa-graduation-cap"></i> Qualifications</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-graduation-cap"></i> Academic Qualification</label>
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
                            <label><i class="fas fa-laptop-code"></i> Technical Qualification</label>
                            <div class="checkbox-group" id="technicalCheckboxes">
                                <p style="color: #999; text-align: center; padding: 20px;">
                                    <i class="fas fa-spinner fa-spin"></i> Loading skills...
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Pipeline & Status -->
                    <h4 class="form-section-title"><i class="fas fa-stream"></i> Pipeline & Status</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-stream"></i> Pipeline Stage *</label>
                            <select id="stageId" name="stage_id" required>
                                <option value="">Select Stage</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Status *</label>
                            <select id="statusId" name="status_id" required>
                                <option value="">Select Status</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Assigned To</label>
                            <select id="assignedTo" name="assigned_to">
                                <option value="">Unassigned</option>
                            </select>
                        </div>
                    </div>

                    <!-- Dates & Actions -->
                    <h4 class="form-section-title"><i class="fas fa-calendar-alt"></i> Dates & Next Action</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-plus"></i> Applied Date *</label>
                            <input type="date" id="appliedDate" name="applied_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Joined Date</label>
                            <input type="date" id="joinedDate" name="joined_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tasks"></i> Next Action</label>
                            <input type="text" id="nextAction" name="next_action" maxlength="255" placeholder="e.g. Schedule Interview">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Next Action Date</label>
                            <input type="date" id="nextActionDate" name="next_action_date">
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> Notes</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Additional notes about this application..."></textarea>
                    </div>

                    <!-- Attach Document (Optional) -->
                    <h4 class="form-section-title"><i class="fas fa-paperclip"></i> Attach Document (Optional)</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Document Label</label>
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
                            <label><i class="fas fa-file"></i> Select File</label>
                            <input type="file" id="inlineDocFile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp">
                        </div>
                    </div>
                    <p style="font-size: 13px; color: #888; margin-bottom: 15px;"><i class="fas fa-info-circle"></i> Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, WEBP (Max 15MB). Document will be uploaded after saving.</p>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>

                        <!-- Upload Documents Button (visible only in edit mode) -->
                        <button type="button" class="btn btn-success" id="uploadDocsBtn" style="display: none;" onclick="openDocsModalFromForm()">
                            <i class="fas fa-paperclip"></i> Upload
                        </button>

                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Application Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width: 800px;">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Application Details</h3>
                <button class="close-btn" onclick="closeViewModal()">
                    <i class="fas fa-times"></i>
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
                <h3><i class="fas fa-paperclip"></i> Documents - <span id="docsModalName"></span></h3>
                <button class="close-btn" onclick="closeDocsModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Upload Section -->
                <div class="doc-upload-section">
                    <h4 style="margin-bottom: 15px; color: var(--navy-primary);"><i class="fas fa-cloud-upload-alt"></i> Upload Document</h4>
                    <form id="docUploadForm" enctype="multipart/form-data">
                        <input type="hidden" id="docAppId" name="application_id">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Document Label</label>
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
                                <label><i class="fas fa-file"></i> Select File *</label>
                                <input type="file" id="docFile" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp" required>
                            </div>
                        </div>
                        <p class="doc-upload-hint"><i class="fas fa-info-circle"></i> Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, WEBP (Max 15MB)</p>
                        <button type="submit" class="btn btn-primary" id="docUploadBtn" style="margin-top: 10px;">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                    </form>
                </div>

                <!-- Documents List -->
                <div id="docsListContainer" style="margin-top: 25px;">
                    <h4 style="margin-bottom: 15px; color: var(--navy-primary);"><i class="fas fa-folder-open"></i> Uploaded Documents <span class="docs-count-badge" id="docsCount">0</span></h4>
                    <div id="docsList">
                        <p style="color: #999; text-align: center; padding: 20px;">Loading documents...</p>
                    </div>
                </div>

                <!-- Document Preview Area -->
                <div id="docPreviewArea" style="display: none; margin-top: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 2px solid var(--navy-primary);">
                        <h4 style="color: var(--navy-primary); margin: 0;"><i class="fas fa-eye"></i> Document Preview - <span id="previewDocName"></span></h4>
                        <button class="btn btn-secondary" onclick="closeDocPreview()" style="padding: 6px 14px; font-size: 13px;"><i class="fas fa-times"></i> Close Preview</button>
                    </div>
                    <div id="docPreviewContent" style="border: 1px solid #dee2e6; border-radius: 4px; overflow: hidden; background: #f8f9fa;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Overlay (Same-Tab View) -->
    <div id="printOverlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 2000; background: #e8e8e8; overflow-y: auto;">
        <div id="printOverlayActions" style="position: sticky; top: 0; z-index: 10; background: var(--navy-primary); padding: 12px 20px; display: flex; justify-content: center; gap: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
            <button onclick="printOverlayContent()" class="btn btn-primary" style="background: #fff; color: var(--navy-primary); font-weight: 600;"><i class="fas fa-print"></i> Print Document</button>
            <button onclick="closePrintOverlay()" class="btn btn-secondary"><i class="fas fa-times"></i> Close</button>
        </div>
        <div id="printOverlayContent" style="padding: 20px 0;"></div>
    </div>

    <script>
        var userRole = '<?php echo $role; ?>';
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
                                var icon = row.stage_icon ? '<i class="fas ' + row.stage_icon + '"></i> ' : '';
                                return '<span class="stage-badge" style="background:' + (row.stage_color || '#6B7280') + '">' + icon + row.stage_name + '</span>';
                            }
                        },
                        {
                            data: null,
                            title: 'Status',
                            render: function(data, type, row) {
                                if (!row.status_name) return '-';
                                var icon = row.status_icon ? '<i class="fas ' + row.status_icon + '"></i> ' : '';
                                return '<span class="stage-badge" style="background:' + (row.status_color || '#6B7280') + '">' + icon + row.status_name + '</span>';
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
                                var icon = 'fa-clock';
                                if (data > 60) { color = '#ea4335'; icon = 'fa-exclamation-circle'; }
                                else if (data > 30) { color = '#fbbc04'; icon = 'fa-hourglass-half'; }
                                var isJoined = row.joined_date ? ' title="Applied to Joined"' : ' title="Days in pipeline"';
                                return '<span class="days-badge" style="background:' + color + ';"' + isJoined + '><i class="fas ' + icon + '"></i> ' + data + '</span>';
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
                                return '<button class="action-icon btn-docs-app" data-id="' + row.id + '" title="Documents" style="color: var(--navy-primary); position: relative;"><i class="fas fa-paperclip"></i>' + badge + '</button>';
                            }
                        },
                        {
                            data: null,
                            title: 'Actions',
                            orderable: false,
                            render: function(data, type, row) {
                                var actions = '<button class="action-icon btn-view-app" data-id="' + row.id + '" title="View" style="color: var(--navy-accent);"><i class="fas fa-eye"></i></button>' +
                                    '<button class="action-icon edit-icon btn-edit-app" data-id="' + row.id + '" title="Edit"><i class="fas fa-edit"></i></button>';
                                if (userRole === 'admin') {
                                    actions += '<button class="action-icon delete-icon btn-delete-app" data-id="' + row.id + '" title="Delete"><i class="fas fa-trash"></i></button>';
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
                        { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] } },
                        { extend: 'pdf', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] } },
                        { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] } }
                    ],
                    order: [[0, 'desc']]
                });

                // Event delegation for action buttons
                $('#applicationsTable').off('click', '.btn-view-app, .btn-edit-app, .btn-delete-app, .btn-docs-app');

                $('#applicationsTable').on('click', '.btn-view-app', function(e) {
                    e.stopPropagation();
                    var app = findAppById($(this).data('id'));
                    if (app) {
                        viewApplicationInPage(app);
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
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Application';
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

        function editApplication(app) {
            isEditMode = true;
            currentFormAppId = app.id; // Set current app ID for upload button
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Application';
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
            html += '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">';
            html += '<style>';

            // A4 Print Document Styles
            html += '*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }';
            html += 'body { font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, sans-serif; background: #e8e8e8; color: #1a1a1a; line-height: 1.5; }';

            // A4 Page Container
            html += '.doc-page { width: 210mm; min-height: 297mm; margin: 20px auto; background: #fff; padding: 25mm 22mm 20mm; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative; }';

            // Header / Letterhead
            html += '.doc-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 18px; border-bottom: 3px solid #001f3f; margin-bottom: 22px; }';
            html += '.doc-header-left h1 { font-size: 22px; font-weight: 800; color: #001f3f; letter-spacing: -0.5px; margin-bottom: 3px; }';
            html += '.doc-header-left p { font-size: 11px; color: #666; letter-spacing: 0.5px; }';
            html += '.doc-header-right { text-align: right; }';
            html += '.doc-ref { font-size: 11px; color: #555; margin-bottom: 3px; }';
            html += '.doc-ref strong { color: #001f3f; }';

            // Title Bar
            html += '.doc-title-bar { background: #001f3f; color: #fff; padding: 10px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }';
            html += '.doc-title-bar h2 { font-size: 15px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }';
            html += '.doc-title-bar .doc-id { font-size: 12px; opacity: 0.8; }';

            // Section
            html += '.doc-section { margin-bottom: 18px; }';
            html += '.doc-section-title { font-size: 12px; font-weight: 700; color: #001f3f; text-transform: uppercase; letter-spacing: 1.5px; padding: 6px 0; border-bottom: 1.5px solid #0074D9; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }';
            html += '.doc-section-title i { font-size: 11px; color: #0074D9; }';

            // Table layout for fields
            html += '.doc-fields { width: 100%; border-collapse: collapse; }';
            html += '.doc-fields td { padding: 7px 10px; font-size: 12px; vertical-align: top; border: 1px solid #e5e5e5; }';
            html += '.doc-fields .field-label { width: 140px; background: #f7f8fa; font-weight: 700; color: #333; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; white-space: nowrap; }';
            html += '.doc-fields .field-value { color: #1a1a1a; font-weight: 500; }';
            html += '.empty-val { color: #bbb; font-style: italic; font-weight: 400; }';

            // Status tags
            html += '.doc-status-tag { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 11px; font-weight: 700; color: #fff; }';
            html += '.doc-qual-tag { display: inline-block; padding: 2px 8px; margin: 1px 3px 1px 0; border-radius: 2px; font-size: 10px; font-weight: 600; background: #EBF5FF; color: #0074D9; border: 1px solid #d0e5ff; }';

            // Notes
            html += '.doc-notes { background: #f9fafb; border: 1px solid #e5e5e5; border-left: 3px solid #0074D9; padding: 12px 15px; font-size: 12px; color: #333; white-space: pre-wrap; line-height: 1.6; min-height: 40px; }';

            // Footer
            html += '.doc-footer { position: absolute; bottom: 15mm; left: 22mm; right: 22mm; border-top: 1px solid #ddd; padding-top: 10px; display: flex; justify-content: space-between; font-size: 9px; color: #999; }';

            // Action Bar (no-print)
            html += '.doc-action-bar { width: 210mm; margin: 0 auto 20px; display: flex; gap: 10px; justify-content: center; }';
            html += '.doc-action-btn { padding: 10px 28px; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }';
            html += '.doc-action-btn-print { background: #001f3f; color: #fff; }';
            html += '.doc-action-btn-print:hover { background: #003366; }';
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
            html += '<button class="doc-action-btn doc-action-btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print Document</button>';
            html += '<button class="doc-action-btn doc-action-btn-close" onclick="window.close()"><i class="fas fa-times"></i> Close</button>';
            html += '</div>';

            // A4 Page
            html += '<div class="doc-page">';

            // Header / Letterhead
            html += '<div class="doc-header">';
            html += '<div class="doc-header-left">';
            html += '<h1><i class="fas fa-briefcase" style="color:#0074D9;font-size:20px;"></i> Job Application Form</h1>';
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
            html += '<h2><i class="fas fa-file-alt"></i> Application Details</h2>';
            html += '<span class="doc-id">REF: APP-' + String(app.id || 0).padStart(4, '0') + '</span>';
            html += '</div>';

            // Section: Candidate Information
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="fas fa-user"></i> Candidate Information</div>';
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
            html += '<div class="doc-section-title"><i class="fas fa-briefcase"></i> Position Details</div>';
            html += '<table class="doc-fields">';
            html += '<tr><td class="field-label">Position</td><td class="field-value"><strong>' + (app.position || '-') + '</strong></td>';
            html += '<td class="field-label">Department</td><td class="field-value">' + (app.company || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="field-label">Experience</td><td class="field-value">' + (app.experience || '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="field-label">Expected Salary</td><td class="field-value">' + (app.expected_salary || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Section: Qualifications
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="fas fa-graduation-cap"></i> Qualifications</div>';
            html += '<table class="doc-fields">';
            html += '<tr><td class="field-label">Academic</td><td class="field-value" colspan="3">' + qualList(app.academic_qualification) + '</td></tr>';
            html += '<tr><td class="field-label">Technical</td><td class="field-value" colspan="3">' + qualList(app.technical_qualification) + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Section: Pipeline & Status
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="fas fa-stream"></i> Pipeline & Status</div>';
            html += '<table class="doc-fields">';
            html += '<tr><td class="field-label">Stage</td><td class="field-value">' + stageHtml + '</td>';
            html += '<td class="field-label">Status</td><td class="field-value">' + statusHtml + '</td></tr>';
            html += '<tr><td class="field-label">Assigned To</td><td class="field-value">' + (app.assigned_to_name || '<span class="empty-val">Unassigned</span>') + '</td>';
            html += '<td class="field-label">Created By</td><td class="field-value">' + (app.created_by_name || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Section: Timeline & Dates
            html += '<div class="doc-section">';
            html += '<div class="doc-section-title"><i class="fas fa-calendar-alt"></i> Timeline</div>';
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
            html += '<div class="doc-section-title"><i class="fas fa-sticky-note"></i> Notes / Remarks</div>';
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
            html += '<h1><i class="fas fa-briefcase" style="color:#0074D9;font-size:20px;"></i> Job Application Form</h1>';
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
            html += '<h2><i class="fas fa-file-alt"></i> Application Details</h2>';
            html += '<span>REF: APP-' + String(app.id || 0).padStart(4, '0') + '</span>';
            html += '</div>';

            // Candidate Information
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="fas fa-user"></i> Candidate Information</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Full Name</td><td class="po-value" colspan="3"><strong>' + (app.candidate_name || '-') + '</strong></td></tr>';
            html += '<tr><td class="po-label">Email</td><td class="po-value">' + (app.email || '-') + '</td>';
            html += '<td class="po-label">Contact No.</td><td class="po-value">' + (app.contact_number || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="po-label">Nationality</td><td class="po-value">' + (app.nationality || '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="po-label">Location</td><td class="po-value">' + (app.current_location || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table></div>';

            // Position Details
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="fas fa-briefcase"></i> Position Details</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Position</td><td class="po-value"><strong>' + (app.position || '-') + '</strong></td>';
            html += '<td class="po-label">Department</td><td class="po-value">' + (app.company || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="po-label">Experience</td><td class="po-value">' + (app.experience || '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="po-label">Expected Salary</td><td class="po-value">' + (app.expected_salary || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table></div>';

            // Qualifications
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="fas fa-graduation-cap"></i> Qualifications</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Academic</td><td class="po-value" colspan="3">' + qualList(app.academic_qualification) + '</td></tr>';
            html += '<tr><td class="po-label">Technical</td><td class="po-value" colspan="3">' + qualList(app.technical_qualification) + '</td></tr>';
            html += '</table></div>';

            // Pipeline & Status
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="fas fa-stream"></i> Pipeline & Status</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Stage</td><td class="po-value">' + stageHtml + '</td>';
            html += '<td class="po-label">Status</td><td class="po-value">' + statusHtml + '</td></tr>';
            html += '<tr><td class="po-label">Assigned To</td><td class="po-value">' + (app.assigned_to_name || '<span class="empty-val">Unassigned</span>') + '</td>';
            html += '<td class="po-label">Created By</td><td class="po-value">' + (app.created_by_name || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table></div>';

            // Timeline
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="fas fa-calendar-alt"></i> Timeline</div>';
            html += '<table class="po-fields">';
            html += '<tr><td class="po-label">Applied Date</td><td class="po-value">' + (app.applied_date_display || '-') + '</td>';
            html += '<td class="po-label">Joined Date</td><td class="po-value">' + (app.joined_date_display || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="po-label">Days Elapsed</td><td class="po-value">' + (app.days_elapsed !== null && app.days_elapsed !== undefined ? app.days_elapsed + ' days' : '<span class="empty-val">N/A</span>') + '</td>';
            html += '<td class="po-label">Next Action</td><td class="po-value">' + (app.next_action || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '<tr><td class="po-label">Action Date</td><td class="po-value" colspan="3">' + (app.next_action_date_display || '<span class="empty-val">N/A</span>') + '</td></tr>';
            html += '</table></div>';

            // Notes
            html += '<div class="po-section">';
            html += '<div class="po-section-title"><i class="fas fa-sticky-note"></i> Notes / Remarks</div>';
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
            document.getElementById('docsList').innerHTML = '<p style="color: #999; text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';

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
                container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;"><i class="fas fa-folder-open"></i> No documents uploaded yet.</p>';
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
                    html += '<button class="action-icon" onclick="previewDocument(\'' + doc.file_path.replace(/'/g, "\\'") + '\', \'' + (doc.file_type || '').replace(/'/g, "\\'") + '\', \'' + (doc.file_name || '').replace(/'/g, "\\'") + '\')" title="Preview in page" style="color: var(--navy-primary);"><i class="fas fa-eye"></i></button>';
                }
                html += '<a href="' + doc.file_path + '" target="_blank" class="action-icon" title="Open in new tab" style="color: var(--navy-accent);"><i class="fas fa-external-link-alt"></i></a>';
                html += '<a href="' + doc.file_path + '" download class="action-icon" title="Download" style="color: #34a853;"><i class="fas fa-download"></i></a>';
                if (userRole === 'admin') {
                    html += '<button class="action-icon delete-icon" onclick="deleteDocument(' + doc.id + ')" title="Delete"><i class="fas fa-trash"></i></button>';
                }
                html += '</div>';
                html += '</div>';
            });
            container.innerHTML = html;
        }

        function getFileIcon(mimeType) {
            if (!mimeType) return '<i class="fas fa-file" style="color: #6B7280;"></i>';
            if (mimeType.indexOf('pdf') !== -1) return '<i class="fas fa-file-pdf" style="color: #ea4335;"></i>';
            if (mimeType.indexOf('word') !== -1 || mimeType.indexOf('msword') !== -1) return '<i class="fas fa-file-word" style="color: #2b579a;"></i>';
            if (mimeType.indexOf('image') !== -1) return '<i class="fas fa-file-image" style="color: #34a853;"></i>';
            return '<i class="fas fa-file" style="color: #6B7280;"></i>';
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
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

            $.ajax({
                url: '?action=uploadDocument',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';

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
                    uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
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
