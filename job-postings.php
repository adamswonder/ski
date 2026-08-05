<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !hasPermission($_SESSION['user_id'], $_SESSION['role'], 'manage_postings')) {
    header("Location: dashboard.php");
    exit();
}

if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'job-postings';

$ALLOWED_FIELD_TYPES = ['text', 'textarea', 'radio', 'dropdown', 'checkbox', 'file'];
$CHOICE_FIELD_TYPES = ['radio', 'dropdown', 'checkbox'];

function computeEffectiveStatus($statusOverride, $openDate, $closeDate) {
    if ($statusOverride === 'force_open') {
        return 'open';
    }
    if ($statusOverride === 'force_closed') {
        return 'closed';
    }
    $today = date('Y-m-d');
    if ($openDate && $today < $openDate) {
        return 'closed';
    }
    if ($closeDate && $today > $closeDate) {
        return 'closed';
    }
    return 'open';
}

function validatePostingQuestions($questionsRaw, $allowedTypes, $choiceTypes) {
    $questions = json_decode($questionsRaw, true);
    if (!is_array($questions)) {
        return ['error' => 'Invalid questions data'];
    }

    $clean = [];
    foreach ($questions as $q) {
        $label = isset($q['label']) ? trim($q['label']) : '';
        $fieldType = isset($q['field_type']) ? $q['field_type'] : '';
        $isRequired = !empty($q['is_required']) ? 1 : 0;

        if ($label === '' || !in_array($fieldType, $allowedTypes)) {
            return ['error' => 'Each question needs a label and a valid type'];
        }

        $options = null;
        if (in_array($fieldType, $choiceTypes)) {
            $rawOptions = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
            $rawOptions = array_values(array_filter(array_map('trim', $rawOptions), fn($o) => $o !== ''));
            if (empty($rawOptions)) {
                return ['error' => 'Question "' . $label . '" needs at least one option'];
            }
            $options = $rawOptions;
        }

        $clean[] = [
            'label' => $label,
            'field_type' => $fieldType,
            'options' => $options,
            'is_required' => $isRequired
        ];
    }

    return ['questions' => $clean];
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getPostings':
                $conn = getDBConnection();
                $result = $conn->query("SELECT jp.id, jp.title, jp.department, jp.location, jp.open_date, jp.close_date,
                                                jp.status_override, jp.created_at, u.full_name AS created_by_name,
                                                (SELECT COUNT(*) FROM job_posting_questions q WHERE q.job_posting_id = jp.id) AS question_count
                                         FROM job_postings jp
                                         LEFT JOIN users u ON jp.created_by = u.id
                                         ORDER BY jp.created_at DESC");

                $postings = [];
                while ($row = $result->fetch_assoc()) {
                    $postings[] = [
                        'id' => (int)$row['id'],
                        'title' => $row['title'],
                        'department' => $row['department'],
                        'location' => $row['location'],
                        'open_date' => $row['open_date'],
                        'close_date' => $row['close_date'],
                        'status_override' => $row['status_override'],
                        'effective_status' => computeEffectiveStatus($row['status_override'], $row['open_date'], $row['close_date']),
                        'question_count' => (int)$row['question_count'],
                        'created_by_name' => $row['created_by_name'],
                        'created_at' => date('M d, Y', strtotime($row['created_at']))
                    ];
                }
                $conn->close();

                echo json_encode(['success' => true, 'data' => $postings]);
                exit();

            case 'getPosting':
                $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid posting ID']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT id, title, description, department, location, open_date, close_date, status_override FROM job_postings WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Posting not found']);
                    exit();
                }

                $posting = $result->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("SELECT id, label, field_type, options, is_required, display_order FROM job_posting_questions WHERE job_posting_id = ? ORDER BY display_order ASC");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $qResult = $stmt->get_result();

                $questions = [];
                while ($q = $qResult->fetch_assoc()) {
                    $questions[] = [
                        'id' => (int)$q['id'],
                        'label' => $q['label'],
                        'field_type' => $q['field_type'],
                        'options' => $q['options'] ? json_decode($q['options'], true) : [],
                        'is_required' => (bool)$q['is_required']
                    ];
                }
                $stmt->close();
                $conn->close();

                $posting['questions'] = $questions;
                echo json_encode(['success' => true, 'data' => $posting]);
                exit();

            case 'addPosting':
            case 'updatePosting':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $isUpdate = $_GET['action'] === 'updatePosting';
                $postingId = $isUpdate ? intval($_POST['id'] ?? 0) : 0;

                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $department = trim($_POST['department'] ?? '') ?: null;
                $location = trim($_POST['location'] ?? '') ?: null;
                $openDate = trim($_POST['open_date'] ?? '') ?: null;
                $closeDate = trim($_POST['close_date'] ?? '') ?: null;
                $statusOverride = $_POST['status_override'] ?? 'auto';

                if ($isUpdate && $postingId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid posting ID']);
                    exit();
                }
                if ($title === '' || strip_tags($description) === '') {
                    echo json_encode(['success' => false, 'message' => 'Title and description are required']);
                    exit();
                }
                if (!in_array($statusOverride, ['auto', 'force_open', 'force_closed'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status override']);
                    exit();
                }
                if ($openDate && $closeDate && $openDate > $closeDate) {
                    echo json_encode(['success' => false, 'message' => 'Open date must be before close date']);
                    exit();
                }

                $validated = validatePostingQuestions($_POST['questions'] ?? '[]', $ALLOWED_FIELD_TYPES, $CHOICE_FIELD_TYPES);
                if (isset($validated['error'])) {
                    echo json_encode(['success' => false, 'message' => $validated['error']]);
                    exit();
                }
                $questions = $validated['questions'];

                $conn = getDBConnection();
                $conn->begin_transaction();

                try {
                    if ($isUpdate) {
                        $stmt = $conn->prepare("UPDATE job_postings SET title = ?, description = ?, department = ?, location = ?, open_date = ?, close_date = ?, status_override = ? WHERE id = ?");
                        $stmt->bind_param("sssssssi", $title, $description, $department, $location, $openDate, $closeDate, $statusOverride, $postingId);
                        $stmt->execute();
                        $stmt->close();

                        $del = $conn->prepare("DELETE FROM job_posting_questions WHERE job_posting_id = ?");
                        $del->bind_param("i", $postingId);
                        $del->execute();
                        $del->close();
                    } else {
                        $stmt = $conn->prepare("INSERT INTO job_postings (title, description, department, location, open_date, close_date, status_override, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssssssi", $title, $description, $department, $location, $openDate, $closeDate, $statusOverride, $user_id);
                        $stmt->execute();
                        $postingId = $conn->insert_id;
                        $stmt->close();
                    }

                    if (!empty($questions)) {
                        $insertQ = $conn->prepare("INSERT INTO job_posting_questions (job_posting_id, label, field_type, options, is_required, display_order) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($questions as $order => $q) {
                            $optionsJson = $q['options'] !== null ? json_encode($q['options']) : null;
                            $insertQ->bind_param("isssii", $postingId, $q['label'], $q['field_type'], $optionsJson, $q['is_required'], $order);
                            $insertQ->execute();
                        }
                        $insertQ->close();
                    }

                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $conn->close();
                    error_log("Job-postings.php error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Failed to save posting: ' . $e->getMessage()]);
                    exit();
                }

                $conn->close();

                $actionLabel = $isUpdate ? 'Updated' : 'Created';
                logActivity($user_id, $isUpdate ? 'UPDATE' : 'CREATE', "$actionLabel job posting: $title", ['module' => 'job_postings']);

                echo json_encode(['success' => true, 'message' => "Job posting $actionLabel successfully" ]);
                exit();

            case 'deletePosting':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $postingId = intval($_POST['id'] ?? 0);
                if ($postingId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid posting ID']);
                    exit();
                }

                $conn = getDBConnection();

                $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM applications WHERE job_posting_id = ?");
                $check->bind_param("i", $postingId);
                $check->execute();
                $appCount = $check->get_result()->fetch_assoc()['cnt'];
                $check->close();

                if ($appCount > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete: $appCount application(s) reference this posting. Close it instead."]);
                    exit();
                }

                $stmt = $conn->prepare("SELECT title FROM job_postings WHERE id = ?");
                $stmt->bind_param("i", $postingId);
                $stmt->execute();
                $result = $stmt->get_result();
                $deletedTitle = $result->num_rows > 0 ? $result->fetch_assoc()['title'] : '';
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM job_postings WHERE id = ?");
                $stmt->bind_param("i", $postingId);

                if ($stmt->execute()) {
                    logActivity($user_id, 'DELETE', "Deleted job posting: $deletedTitle", ['module' => 'job_postings']);
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Job posting deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete posting']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Job-postings.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Job Postings - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
    <link rel="stylesheet" href="styles.css?v=5.2">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-bullhorn"></i> Job Postings</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-list"></i> Manage Postings</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadPostings()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> New Posting
                        </button>
                    </div>
                </div>

                <div class="table-scroll-hint">
                    <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                </div>
                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table id="postingsTable" class="display" style="width:100%"></table>
                </div>
            </div>
        </div>
    </div>

    <!-- Posting Modal -->
    <div class="modal-overlay" id="postingModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width: 800px;">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> New Job Posting</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="postingForm">
                    <input type="hidden" id="postingId" name="id">

                    <div class="form-group">
                        <label><i class="fas fa-heading"></i> Title *</label>
                        <input type="text" id="postingTitle" name="title" required maxlength="150" placeholder="e.g. Senior Backend Developer">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Department</label>
                            <input type="text" id="postingDepartment" name="department" maxlength="100" placeholder="e.g. Engineering">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Location</label>
                            <input type="text" id="postingLocation" name="location" maxlength="150" placeholder="e.g. Nairobi / Remote">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description *</label>
                        <div id="descriptionEditor" style="background:#fff; min-height: 160px;"></div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Open Date</label>
                            <input type="date" id="openDate" name="open_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-times"></i> Close Date</label>
                            <input type="date" id="closeDate" name="close_date">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-toggle-on"></i> Status Override</label>
                        <select id="statusOverride" name="status_override">
                            <option value="auto">Auto (based on dates above)</option>
                            <option value="force_open">Force Open</option>
                            <option value="force_closed">Force Closed</option>
                        </select>
                    </div>

                    <hr>

                    <div class="form-group">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <label style="margin:0;"><i class="fas fa-list-ol"></i> Application Questions</label>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addQuestionRow()">
                                <i class="fas fa-plus"></i> Add Question
                            </button>
                        </div>
                        <div id="questionsList" style="margin-top: 12px; display: flex; flex-direction: column; gap: 10px;"></div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Question row template -->
    <template id="questionRowTemplate">
        <div class="question-row" style="border:1px solid var(--border-color, #ddd); border-radius:8px; padding:12px;">
            <div class="form-grid">
                <div class="form-group">
                    <label>Question Label</label>
                    <input type="text" class="q-label" placeholder="e.g. Years of experience" maxlength="255">
                </div>
                <div class="form-group">
                    <label>Field Type</label>
                    <select class="q-type">
                        <option value="text">Short Text</option>
                        <option value="textarea">Long Text</option>
                        <option value="radio">Radio (single choice)</option>
                        <option value="dropdown">Dropdown</option>
                        <option value="checkbox">Checkbox (multi choice)</option>
                        <option value="file">File Upload</option>
                    </select>
                </div>
            </div>
            <div class="q-options-group form-group" style="display:none;">
                <label>Options (one per line)</label>
                <textarea class="q-options" rows="3" placeholder="Option A&#10;Option B&#10;Option C"></textarea>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <label style="display:flex; align-items:center; gap:6px; margin:0;">
                    <input type="checkbox" class="q-required"> Required
                </label>
                <button type="button" class="action-icon delete-icon" onclick="this.closest('.question-row').remove()" title="Remove">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    </template>

    <script>
        let postingsTable;
        let isEditMode = false;
        let quillEditor;
        const CHOICE_TYPES = ['radio', 'dropdown', 'checkbox'];

        $(document).ready(function() {
            quillEditor = new Quill('#descriptionEditor', { theme: 'snow' });
            loadPostings();
        });

        function loadPostings() {
            $.ajax({
                url: '?action=getPostings',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        initializeDataTable(response.data);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load postings' });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
                }
            });
        }

        function initializeDataTable(data) {
            if (postingsTable) {
                postingsTable.destroy();
                $('#postingsTable').empty();
            }

            setTimeout(() => {
                postingsTable = $('#postingsTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: [
                        { data: 'title', title: 'Title' },
                        { data: 'department', title: 'Department', defaultContent: '—' },
                        { data: 'location', title: 'Location', defaultContent: '—' },
                        {
                            data: 'effective_status',
                            title: 'Status',
                            render: function(data) {
                                const cls = data === 'open' ? 'status-active' : 'status-inactive';
                                const label = data === 'open' ? 'Open' : 'Closed';
                                return '<span class="status-badge ' + cls + '">' + label + '</span>';
                            }
                        },
                        { data: 'question_count', title: 'Questions', width: '80px' },
                        { data: 'created_at', title: 'Created' },
                        {
                            data: null,
                            title: 'Actions',
                            orderable: false,
                            render: function(data, type, row) {
                                return '<button class="action-icon edit-icon" onclick="editPosting(' + row.id + ')" title="Edit"><i class="fas fa-edit"></i></button>' +
                                    '<button class="action-icon delete-icon" onclick="deletePosting(' + row.id + ')" title="Delete"><i class="fas fa-trash"></i></button>';
                            }
                        }
                    ],
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                    responsive: true,
                    order: [[5, 'desc']]
                });
            }, 100);
        }

        function addQuestionRow(question) {
            const template = document.getElementById('questionRowTemplate');
            const clone = template.content.cloneNode(true);
            const row = clone.querySelector('.question-row');

            if (question) {
                row.querySelector('.q-label').value = question.label;
                row.querySelector('.q-type').value = question.field_type;
                row.querySelector('.q-required').checked = !!question.is_required;
                if (question.options) {
                    row.querySelector('.q-options').value = question.options.join('\n');
                }
            }

            const typeSelect = row.querySelector('.q-type');
            const optionsGroup = row.querySelector('.q-options-group');
            const toggleOptions = () => {
                optionsGroup.style.display = CHOICE_TYPES.includes(typeSelect.value) ? 'block' : 'none';
            };
            typeSelect.addEventListener('change', toggleOptions);
            toggleOptions();

            document.getElementById('questionsList').appendChild(clone);
        }

        function collectQuestions() {
            const rows = document.querySelectorAll('#questionsList .question-row');
            const questions = [];
            rows.forEach(row => {
                const fieldType = row.querySelector('.q-type').value;
                const options = CHOICE_TYPES.includes(fieldType)
                    ? row.querySelector('.q-options').value.split('\n').map(o => o.trim()).filter(o => o !== '')
                    : null;
                questions.push({
                    label: row.querySelector('.q-label').value.trim(),
                    field_type: fieldType,
                    options: options,
                    is_required: row.querySelector('.q-required').checked ? 1 : 0
                });
            });
            return questions;
        }

        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> New Job Posting';
            document.getElementById('postingForm').reset();
            document.getElementById('postingId').value = '';
            document.getElementById('questionsList').innerHTML = '';
            quillEditor.setContents([]);
            document.getElementById('postingModal').classList.add('active');
        }

        function editPosting(id) {
            $.ajax({
                url: '?action=getPosting&id=' + id,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (!response.success) {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                        return;
                    }

                    const posting = response.data;
                    isEditMode = true;
                    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Job Posting';
                    document.getElementById('postingId').value = posting.id;
                    document.getElementById('postingTitle').value = posting.title;
                    document.getElementById('postingDepartment').value = posting.department || '';
                    document.getElementById('postingLocation').value = posting.location || '';
                    document.getElementById('openDate').value = posting.open_date || '';
                    document.getElementById('closeDate').value = posting.close_date || '';
                    document.getElementById('statusOverride').value = posting.status_override;
                    quillEditor.root.innerHTML = posting.description;

                    document.getElementById('questionsList').innerHTML = '';
                    posting.questions.forEach(q => addQuestionRow(q));

                    document.getElementById('postingModal').classList.add('active');
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
                }
            });
        }

        function closeModal() {
            document.getElementById('postingModal').classList.remove('active');
            document.getElementById('postingForm').reset();
            document.getElementById('questionsList').innerHTML = '';
        }

        document.getElementById('postingModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('postingForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const questions = collectQuestions();
            for (const q of questions) {
                if (!q.label) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Every question needs a label' });
                    return;
                }
                if (CHOICE_TYPES.includes(q.field_type) && (!q.options || q.options.length === 0)) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Question "' + q.label + '" needs at least one option' });
                    return;
                }
            }

            const formData = new FormData(this);
            formData.set('description', quillEditor.root.innerHTML);
            formData.set('questions', JSON.stringify(questions));
            const action = isEditMode ? 'updatePosting' : 'addPosting';

            Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                        closeModal();
                        setTimeout(() => loadPostings(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        function deletePosting(id) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Posting?',
                text: 'This action cannot be undone.',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', id);

                    $.ajax({
                        url: '?action=deletePosting',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => loadPostings(), 100);
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
