<?php

require_once 'config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
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
$current_page = 'users';

// Grants or revokes the manage_postings permission for a user, based on the "Manage Job Postings" checkbox
function syncManagePostingsPermission($conn, $targetUserId, $canManage) {
    $permStmt = $conn->prepare("SELECT id FROM permissions WHERE slug = 'manage_postings'");
    $permStmt->execute();
    $permResult = $permStmt->get_result();
    $permId = $permResult->num_rows > 0 ? $permResult->fetch_assoc()['id'] : null;
    $permStmt->close();

    if (!$permId) {
        return;
    }

    if ($canManage) {
        $stmt = $conn->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_id) VALUES (?, ?)");
    } else {
        $stmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?");
    }
    $stmt->bind_param("ii", $targetUserId, $permId);
    $stmt->execute();
    $stmt->close();
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getUsers':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT u.id, u.full_name, u.email, u.role, u.is_active, u.avatar_url, u.last_login_at, u.created_at, u.updated_at,
                                                EXISTS(
                                                    SELECT 1 FROM user_permissions up
                                                    JOIN permissions p ON p.id = up.permission_id
                                                    WHERE up.user_id = u.id AND p.slug = 'manage_postings'
                                                ) AS can_manage_postings
                                         FROM users u ORDER BY u.id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $users = [];
                while ($row = $result->fetch_assoc()) {
                    $users[] = [
                        'id' => $row['id'],
                        'full_name' => $row['full_name'],
                        'email' => $row['email'],
                        'role' => $row['role'],
                        'is_active' => (bool)$row['is_active'],
                        'avatar_url' => $row['avatar_url'],
                        'last_login_at' => $row['last_login_at'] ? date('M d, Y h:i A', strtotime($row['last_login_at'])) : 'Never',
                        'created_at' => date('M d, Y', strtotime($row['created_at'])),
                        'can_manage_postings' => (bool)$row['can_manage_postings']
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $users]);
                exit();

            case 'addUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $newRole = isset($_POST['role']) ? $_POST['role'] : 'user';
                $isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

                if (empty($fullName) || empty($email) || empty($password)) {
                    echo json_encode(['success' => false, 'message' => 'Full name, email and password are required']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                if (strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit();
                }

                $conn = getDBConnection();

                // Check if email already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                    exit();
                }
                $stmt->close();

                $canManagePostings = isset($_POST['can_manage_postings']) && $_POST['can_manage_postings'] == '1';

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssi", $fullName, $email, $hashedPassword, $newRole, $isActive);

                if ($stmt->execute()) {
                    $newUserId = $conn->insert_id;
                    $stmt->close();

                    if ($newRole !== 'admin') {
                        syncManagePostingsPermission($conn, $newUserId, $canManagePostings);
                    }

                    logActivity($user_id, 'CREATE', "Created user: $fullName ($email) - Role: $newRole", ['module' => 'users']);

                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'User added successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add user']);
                }
                exit();

            case 'updateUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $userId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $newRole = isset($_POST['role']) ? $_POST['role'] : 'user';
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

                if ($userId <= 0 || empty($fullName) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Full name and email are required']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                $conn = getDBConnection();

                // Check if email already exists for another user
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $email, $userId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already exists for another user']);
                    exit();
                }
                $stmt->close();

                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                        exit();
                    }
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, password_hash = ?, role = ?, is_active = ? WHERE id = ?");
                    $stmt->bind_param("ssssii", $fullName, $email, $hashedPassword, $newRole, $isActive, $userId);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?");
                    $stmt->bind_param("sssii", $fullName, $email, $newRole, $isActive, $userId);
                }

                if ($stmt->execute()) {
                    $stmt->close();

                    $canManagePostings = isset($_POST['can_manage_postings']) && $_POST['can_manage_postings'] == '1';
                    syncManagePostingsPermission($conn, $userId, $newRole !== 'admin' && $canManagePostings);

                    $details = !empty($password) ? "Updated user: $fullName ($email) - password changed" : "Updated user: $fullName ($email)";
                    logActivity($user_id, 'UPDATE', $details, ['module' => 'users']);

                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update user']);
                }
                exit();

            case 'deleteUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $userId = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }

                if ($userId == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                    exit();
                }

                $conn = getDBConnection();

                // Get user info before deleting
                $stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $deletedName = '';
                $deletedEmail = '';
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $deletedName = $row['full_name'];
                    $deletedEmail = $row['email'];
                }
                $stmt->close();

                // Check for FK references that would block deletion
                $refCheck = $conn->prepare("SELECT
                    (SELECT COUNT(*) FROM applications WHERE assigned_to = ?) AS assigned_count,
                    (SELECT COUNT(*) FROM applications WHERE created_by = ?) AS created_count,
                    (SELECT COUNT(*) FROM activity_logs WHERE user_id = ?) AS log_count");
                $refCheck->bind_param("iii", $userId, $userId, $userId);
                $refCheck->execute();
                $refs = $refCheck->get_result()->fetch_assoc();
                $refCheck->close();

                $blocks = [];
                if ($refs['assigned_count'] > 0) $blocks[] = $refs['assigned_count'] . ' assigned application(s)';
                if ($refs['created_count'] > 0) $blocks[] = $refs['created_count'] . ' created application(s)';
                if ($refs['log_count'] > 0) $blocks[] = $refs['log_count'] . ' activity log(s)';

                if (!empty($blocks)) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Cannot delete this user. They have: ' . implode(', ', $blocks) . '. Reassign or remove these records first, or deactivate the user instead.']);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);

                if ($stmt->execute()) {
                    logActivity($user_id, 'DELETE', "Deleted user: $deletedName ($deletedEmail)", ['module' => 'users']);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
                }
                exit();

            case 'toggleActive':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $userId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                $isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;

                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }

                if ($userId == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot deactivate your own account']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $isActive, $userId);

                if ($stmt->execute()) {
                    $status = $isActive ? 'Activated' : 'Deactivated';
                    logActivity($user_id, 'UPDATE', "User $status - User ID: $userId", ['module' => 'users']);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "User $status successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update user status']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Users.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// If we reach here, render the HTML page
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
    <title>User Management Skyward Airlines</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=5.7">

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
                <h1><i class="ri-group-line"></i> User Management</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="ri-table-line"></i> Users</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadUsers()">
                            <i class="ri-refresh-line"></i> Refresh
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="ri-add-line"></i> Add User
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
                            <label><i class="ri-calendar-line"></i> Date From</label>
                            <input type="date" id="filterDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-calendar-line"></i> Date To</label>
                            <input type="date" id="filterDateTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-user-settings-line"></i> Role</label>
                            <select id="filterRole" class="filter-input">
                                <option value="">All Roles</option>
                                <option value="admin">Admin</option>
                                <option value="user">User</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-toggle-line"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Status</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="table-scroll-hint">
                    <i class="ri-arrow-left-right-line"></i> Swipe left/right to see all columns
                </div>
                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table id="usersTable" class="display" style="width:100%"></table>
                </div>
            </div>
        </div>
    </div>

    <!-- User Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="ri-user-add-line"></i> Add User</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" id="userId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-user-line"></i> Full Name *</label>
                            <input type="text" id="fullName" name="full_name" required maxlength="100">
                        </div>

                        <div class="form-group">
                            <label><i class="ri-mail-line"></i> Email *</label>
                            <input type="email" id="email" name="email" required maxlength="150">
                        </div>

                        <div class="form-group">
                            <label><i class="ri-lock-line"></i> Password <span id="passwordHint" style="display:none;">(Leave empty to keep current)</span></label>
                            <input type="password" id="password" name="password" minlength="6">
                        </div>

                        <div class="form-group">
                            <label><i class="ri-user-settings-line"></i> Role *</label>
                            <select id="role" name="role" required>
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="ri-toggle-line"></i> Account Status *</label>
                            <select id="isActive" name="is_active" required>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" id="managePostingsGroup">
                        <label style="display:flex; align-items:center; gap:8px; font-weight:normal;">
                            <input type="checkbox" id="canManagePostings" name="can_manage_postings" value="1" style="width:18px; height:18px;">
                            <i class="ri-megaphone-line"></i> Can Manage Job Postings
                        </label>
                        <small class="help-text" style="color: var(--text-muted); margin-top: 5px; display: block;">Grants access to create and edit job postings without full admin rights.</small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-save-line"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="ri-close-line"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let usersTable;
        let isEditMode = false;
        let usersData = [];

        $(document).ready(function() {
            loadUsers();
        });

        function loadUsers() {
            $.ajax({
                url: '?action=getUsers',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        usersData = response.data;
                        $('#filtersSection').show();
                        initializeDataTable(response.data);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load users'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        function initializeDataTable(data) {
            if (usersTable) {
                usersTable.destroy();
                $('#usersTable').empty();
            }

            setTimeout(() => {
                usersTable = $('#usersTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: [
                        { data: 'id', title: 'ID' },
                        { data: 'full_name', title: 'Full Name' },
                        { data: 'email', title: 'Email' },
                        {
                            data: 'role',
                            title: 'Role',
                            render: function(data) {
                                const badgeClass = data === 'admin' ? 'status-admin' : 'status-user';
                                const label = data.charAt(0).toUpperCase() + data.slice(1);
                                return `<span class="status-badge ${badgeClass}">${label}</span>`;
                            }
                        },
                        {
                            data: 'is_active',
                            title: 'Status',
                            render: function(data) {
                                if (data) {
                                    return '<span class="status-badge status-active">Active</span>';
                                } else {
                                    return '<span class="status-badge status-inactive">Inactive</span>';
                                }
                            }
                        },
                        { data: 'last_login_at', title: 'Last Login' },
                        { data: 'created_at', title: 'Created' },
                        {
                            data: null,
                            title: 'Actions',
                            orderable: false,
                            render: function(data, type, row) {
                                const toggleIcon = row.is_active
                                    ? '<button class="action-icon delete-icon" onclick="toggleActive(' + row.id + ', 0)" title="Deactivate"><i class="ri-forbid-line"></i></button>'
                                    : '<button class="action-icon edit-icon" onclick="toggleActive(' + row.id + ', 1)" title="Activate"><i class="ri-checkbox-circle-line"></i></button>';

                                return `
                                    <button class="action-icon edit-icon" onclick='editUser(${JSON.stringify(row)})' title="Edit">
                                        <i class="ri-edit-line"></i>
                                    </button>
                                    ${toggleIcon}
                                    <button class="action-icon delete-icon" onclick="deleteUser(${row.id})" title="Delete">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                `;
                            }
                        }
                    ],
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        {
                            extend: 'csv',
                            text: '<i class="ri-file-excel-2-line"></i> CSV',
                            exportOptions: { columns: ':not(:last-child)' }
                        },
                        {
                            extend: 'pdf',
                            text: '<i class="ri-file-pdf-line"></i> PDF',
                            exportOptions: { columns: ':not(:last-child)' }
                        },
                        {
                            extend: 'print',
                            text: '<i class="ri-printer-line"></i> Print',
                            exportOptions: { columns: ':not(:last-child)' }
                        }
                    ],
                    order: [[0, 'desc']]
                });

                // Apply filters on change
                $('#filterDateFrom, #filterDateTo, #filterRole, #filterStatus').on('change', function() {
                    applyFilters();
                });
            }, 100);
        }

        function applyFilters() {
            if (!usersTable) return;

            // Clear previous custom filters
            $.fn.dataTable.ext.search = [];

            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const role = document.getElementById('filterRole').value;
            const status = document.getElementById('filterStatus').value;

            // Date range filter
            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const createdAt = usersData[dataIndex]?.created_at;
                    if (!createdAt) return true;

                    const recordDate = new Date(createdAt);
                    const fromDate = dateFrom ? new Date(dateFrom) : null;
                    const toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;

                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            // Role filter (column index 3)
            if (role) {
                usersTable.column(3).search(role);
            } else {
                usersTable.column(3).search('');
            }

            // Status filter (column index 4)
            if (status) {
                usersTable.column(4).search(status);
            } else {
                usersTable.column(4).search('');
            }

            usersTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterRole').value = '';
            document.getElementById('filterStatus').value = '';

            if (usersTable) {
                $.fn.dataTable.ext.search = [];
                usersTable.columns().search('').draw();
            }
        }

        function updateManagePostingsVisibility() {
            const role = document.getElementById('role').value;
            const group = document.getElementById('managePostingsGroup');
            if (role === 'admin') {
                group.style.display = 'none';
                document.getElementById('canManagePostings').checked = false;
            } else {
                group.style.display = 'block';
            }
        }

        document.getElementById('role').addEventListener('change', updateManagePostingsVisibility);

        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="ri-user-add-line"></i> Add User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('passwordHint').style.display = 'none';
            document.getElementById('password').required = true;
            document.getElementById('isActive').value = '1';
            document.getElementById('canManagePostings').checked = false;
            updateManagePostingsVisibility();
            document.getElementById('userModal').classList.add('active');
        }

        function editUser(user) {
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="ri-edit-line"></i> Edit User';
            document.getElementById('userId').value = user.id;
            document.getElementById('fullName').value = user.full_name;
            document.getElementById('email').value = user.email;
            document.getElementById('role').value = user.role;
            document.getElementById('isActive').value = user.is_active ? '1' : '0';
            document.getElementById('password').value = '';
            document.getElementById('passwordHint').style.display = 'inline';
            document.getElementById('password').required = false;
            document.getElementById('canManagePostings').checked = !!user.can_manage_postings;
            updateManagePostingsVisibility();
            document.getElementById('userModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
            document.getElementById('userForm').reset();
        }

        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateUser' : 'addUser';

            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                        closeModal();
                        setTimeout(() => loadUsers(), 100);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        function toggleActive(userId, isActive) {
            const action = isActive ? 'activate' : 'deactivate';
            Swal.fire({
                icon: 'question',
                title: `${action.charAt(0).toUpperCase() + action.slice(1)} User?`,
                text: `Are you sure you want to ${action} this user?`,
                showCancelButton: true,
                confirmButtonColor: isActive ? '#34a853' : '#ea4335',
                confirmButtonText: `Yes, ${action}`,
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', userId);
                    formData.append('is_active', isActive);

                    $.ajax({
                        url: '?action=toggleActive',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    text: response.message,
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                setTimeout(() => loadUsers(), 100);
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.message
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Connection error: ' + error
                            });
                        }
                    });
                }
            });
        }

        function deleteUser(userId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete User?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', userId);

                    $.ajax({
                        url: '?action=deleteUser',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    text: response.message,
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                setTimeout(() => loadUsers(), 100);
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.message
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', error);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Connection error: ' + error
                            });
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>
