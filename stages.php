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
$current_page = 'stages';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getStages':
                $conn = getDBConnection();
                $type = isset($_GET['type']) ? $_GET['type'] : '';

                if ($type === 'pipeline' || $type === 'status') {
                    $stmt = $conn->prepare("SELECT id, name, stage_type, color, icon, display_order, is_active, created_at FROM stages WHERE stage_type = ? ORDER BY display_order ASC, id ASC");
                    $stmt->bind_param("s", $type);
                } else {
                    $stmt = $conn->prepare("SELECT id, name, stage_type, color, icon, display_order, is_active, created_at FROM stages ORDER BY stage_type ASC, display_order ASC, id ASC");
                }

                $stmt->execute();
                $result = $stmt->get_result();

                $stages = [];
                while ($row = $result->fetch_assoc()) {
                    $stages[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'stage_type' => $row['stage_type'],
                        'color' => $row['color'],
                        'icon' => $row['icon'],
                        'display_order' => (int)$row['display_order'],
                        'is_active' => (bool)$row['is_active'],
                        'created_at' => date('M d, Y', strtotime($row['created_at']))
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $stages]);
                exit();

            case 'addStage':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $name = isset($_POST['name']) ? trim($_POST['name']) : '';
                $stageType = isset($_POST['stage_type']) ? $_POST['stage_type'] : '';
                $color = isset($_POST['color']) ? trim($_POST['color']) : '#6B7280';
                $icon = isset($_POST['icon']) ? trim($_POST['icon']) : '';
                $displayOrder = isset($_POST['display_order']) ? intval($_POST['display_order']) : 0;

                if (empty($name) || empty($stageType)) {
                    echo json_encode(['success' => false, 'message' => 'Name and type are required']);
                    exit();
                }

                if (!in_array($stageType, ['pipeline', 'status'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid stage type']);
                    exit();
                }

                // Validate hex color
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                    $color = '#6B7280';
                }

                $conn = getDBConnection();

                // Check duplicate name within same type
                $stmt = $conn->prepare("SELECT id FROM stages WHERE name = ? AND stage_type = ?");
                $stmt->bind_param("ss", $name, $stageType);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'A ' . $stageType . ' with this name already exists']);
                    exit();
                }
                $stmt->close();

                // Auto-assign display_order if 0
                if ($displayOrder <= 0) {
                    $orderResult = $conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 as next_order FROM stages WHERE stage_type = '$stageType'");
                    $displayOrder = $orderResult->fetch_assoc()['next_order'];
                }

                $iconValue = !empty($icon) ? $icon : null;
                $stmt = $conn->prepare("INSERT INTO stages (name, stage_type, color, icon, display_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssi", $name, $stageType, $color, $iconValue, $displayOrder);

                if ($stmt->execute()) {
                    $typeLabel = $stageType === 'pipeline' ? 'Pipeline Stage' : 'Status';
                    logActivity($user_id, 'CREATE', "Created $typeLabel: $name", ['module' => 'stages']);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "$typeLabel added successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add stage']);
                }
                exit();

            case 'updateStage':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $stageId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                $name = isset($_POST['name']) ? trim($_POST['name']) : '';
                $stageType = isset($_POST['stage_type']) ? $_POST['stage_type'] : '';
                $color = isset($_POST['color']) ? trim($_POST['color']) : '#6B7280';
                $icon = isset($_POST['icon']) ? trim($_POST['icon']) : '';
                $displayOrder = isset($_POST['display_order']) ? intval($_POST['display_order']) : 0;
                $isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

                if ($stageId <= 0 || empty($name) || empty($stageType)) {
                    echo json_encode(['success' => false, 'message' => 'Name and type are required']);
                    exit();
                }

                if (!in_array($stageType, ['pipeline', 'status'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid stage type']);
                    exit();
                }

                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                    $color = '#6B7280';
                }

                $conn = getDBConnection();

                // Check duplicate name within same type (excluding self)
                $stmt = $conn->prepare("SELECT id FROM stages WHERE name = ? AND stage_type = ? AND id != ?");
                $stmt->bind_param("ssi", $name, $stageType, $stageId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'A ' . $stageType . ' with this name already exists']);
                    exit();
                }
                $stmt->close();

                $iconValue = !empty($icon) ? $icon : null;
                $stmt = $conn->prepare("UPDATE stages SET name = ?, stage_type = ?, color = ?, icon = ?, display_order = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("ssssiii", $name, $stageType, $color, $iconValue, $displayOrder, $isActive, $stageId);

                if ($stmt->execute()) {
                    $typeLabel = $stageType === 'pipeline' ? 'Pipeline Stage' : 'Status';
                    logActivity($user_id, 'UPDATE', "Updated $typeLabel: $name", ['module' => 'stages']);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "$typeLabel updated successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update stage']);
                }
                exit();

            case 'deleteStage':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $stageId = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($stageId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid stage ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get stage info before deleting
                $stmt = $conn->prepare("SELECT name, stage_type FROM stages WHERE id = ?");
                $stmt->bind_param("i", $stageId);
                $stmt->execute();
                $result = $stmt->get_result();
                $deletedName = '';
                $deletedType = '';
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $deletedName = $row['name'];
                    $deletedType = $row['stage_type'];
                }
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM stages WHERE id = ?");
                $stmt->bind_param("i", $stageId);

                if ($stmt->execute()) {
                    $typeLabel = $deletedType === 'pipeline' ? 'Pipeline Stage' : 'Status';
                    logActivity($user_id, 'DELETE', "Deleted $typeLabel: $deletedName", ['module' => 'stages']);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "$typeLabel deleted successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete stage']);
                }
                exit();

            case 'toggleActive':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $stageId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                $isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;

                if ($stageId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid stage ID']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("UPDATE stages SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $isActive, $stageId);

                if ($stmt->execute()) {
                    $status = $isActive ? 'Activated' : 'Deactivated';
                    logActivity($user_id, 'UPDATE', "Stage $status - Stage ID: $stageId", ['module' => 'stages']);

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "Stage $status successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update stage status']);
                }
                exit();

            case 'reorder':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $orderData = isset($_POST['order']) ? $_POST['order'] : '';

                if (empty($orderData)) {
                    echo json_encode(['success' => false, 'message' => 'No order data provided']);
                    exit();
                }

                $orders = json_decode($orderData, true);
                if (!is_array($orders)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid order data']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("UPDATE stages SET display_order = ? WHERE id = ?");

                $updated = 0;
                foreach ($orders as $item) {
                    $id = intval($item['id']);
                    $order = intval($item['order']);
                    $stmt->bind_param("ii", $order, $id);
                    if ($stmt->execute()) {
                        $updated++;
                    }
                }

                $stmt->close();
                $conn->close();

                logActivity($user_id, 'UPDATE', "Reordered $updated stages", ['module' => 'stages']);
                echo json_encode(['success' => true, 'message' => "Reordered $updated stages successfully"]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Stages.php error: " . $e->getMessage());
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
    <title>Stages & Statuses Skyward Airlines</title>

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
                <h1>Stages & Statuses</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <!-- Pipeline Flow Preview -->
            <div id="pipelinePreview" class="pipeline-flow" style="display:none;"></div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="ri-stack-line"></i> Manage Stages</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadStages()">
                            <i class="ri-refresh-line"></i> Refresh
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="ri-add-line"></i> Add Stage
                        </button>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tab-buttons">
                    <button class="tab-btn active" data-tab="all" onclick="switchTab('all', this)">
                        <i class="ri-list-check"></i> All
                        <span class="count-badge" id="countAll">0</span>
                    </button>
                    <button class="tab-btn" data-tab="pipeline" onclick="switchTab('pipeline', this)">
                        <i class="ri-list-unordered"></i> Pipeline
                        <span class="count-badge" id="countPipeline">0</span>
                    </button>
                    <button class="tab-btn" data-tab="status" onclick="switchTab('status', this)">
                        <i class="ri-price-tag-3-line"></i> Statuses
                        <span class="count-badge" id="countStatus">0</span>
                    </button>
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
                            <label><i class="ri-toggle-line"></i> Status</label>
                            <select id="filterActive" class="filter-input">
                                <option value="">All</option>
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
                    <table id="stagesTable" class="display" style="width:100%"></table>
                </div>
            </div>
        </div>
    </div>

    <!-- Stage Modal -->
    <div class="modal-overlay" id="stageModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width: 600px;">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="ri-add-circle-line"></i> Add Stage</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="stageForm">
                    <input type="hidden" id="stageId" name="id">

                    <div class="form-group">
                        <label><i class="ri-price-tag-line"></i> Name *</label>
                        <input type="text" id="stageName" name="name" required maxlength="100" placeholder="e.g. Interview, On Hold">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-stack-line"></i> Type *</label>
                            <select id="stageType" name="stage_type" required>
                                <option value="pipeline">Pipeline Stage</option>
                                <option value="status">Status</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="ri-sort-number-asc"></i> Display Order</label>
                            <input type="number" id="displayOrder" name="display_order" min="0" value="0" placeholder="Auto if 0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="ri-palette-line"></i> Color</label>
                        <div class="color-input-wrapper">
                            <input type="color" id="colorPicker" value="#6B7280" onchange="document.getElementById('stageColor').value = this.value">
                            <input type="text" id="stageColor" name="color" value="#6B7280" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#6B7280" onchange="document.getElementById('colorPicker').value = this.value">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="ri-shapes-line"></i> Icon (Remix Icon class)</label>
                        <input type="text" id="stageIcon" name="icon" maxlength="50" placeholder="e.g. ri-send-plane-line, ri-checkbox-circle-line">
                        <div id="iconPreviewBox" style="margin-top: 8px; display: none;">
                            Preview: <span id="iconLivePreview"></span>
                        </div>
                    </div>

                    <div class="form-group" id="activeGroup" style="display: none;">
                        <label><i class="ri-toggle-line"></i> Active</label>
                        <select id="stageActive" name="is_active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
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
        let stagesTable;
        let isEditMode = false;
        let allStagesData = [];
        let currentTab = 'all';

        $(document).ready(function() {
            loadStages();
        });

        // Icon preview
        document.getElementById('stageIcon').addEventListener('input', function() {
            const val = this.value.trim();
            const previewBox = document.getElementById('iconPreviewBox');
            const preview = document.getElementById('iconLivePreview');
            if (val) {
                preview.innerHTML = '<i class="' + val + '" style="font-size: 20px; margin-left: 8px;"></i>';
                previewBox.style.display = 'block';
            } else {
                previewBox.style.display = 'none';
            }
        });

        function loadStages() {
            $.ajax({
                url: '?action=getStages',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        allStagesData = response.data;
                        updateCounts(response.data);
                        renderPipelinePreview(response.data);
                        $('#filtersSection').show();
                        filterAndRender();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load stages' });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
                }
            });
        }

        function updateCounts(data) {
            const pipelineCount = data.filter(s => s.stage_type === 'pipeline').length;
            const statusCount = data.filter(s => s.stage_type === 'status').length;
            document.getElementById('countAll').textContent = data.length;
            document.getElementById('countPipeline').textContent = pipelineCount;
            document.getElementById('countStatus').textContent = statusCount;
        }

        function renderPipelinePreview(data) {
            const pipeline = data.filter(s => s.stage_type === 'pipeline' && s.is_active).sort((a, b) => a.display_order - b.display_order);
            const container = document.getElementById('pipelinePreview');

            if (pipeline.length === 0) {
                container.style.display = 'none';
                return;
            }

            let html = '<strong style="margin-right: 10px;"><i class="ri-list-unordered"></i> Pipeline Flow:</strong>';
            pipeline.forEach((stage, idx) => {
                html += '<span class="stage-badge" style="color:' + stage.color + '">';
                if (stage.icon) html += '<i class="' + stage.icon + '"></i> ';
                html += stage.name + '</span>';
                if (idx < pipeline.length - 1) {
                    html += '<span class="arrow"><i class="ri-arrow-right-line"></i></span>';
                }
            });

            container.innerHTML = html;
            container.style.display = 'flex';
        }

        function switchTab(tab, btn) {
            currentTab = tab;
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filterAndRender();
        }

        function filterAndRender() {
            let filtered = allStagesData;
            if (currentTab === 'pipeline') {
                filtered = allStagesData.filter(s => s.stage_type === 'pipeline');
            } else if (currentTab === 'status') {
                filtered = allStagesData.filter(s => s.stage_type === 'status');
            }
            initializeDataTable(filtered);
        }

        function initializeDataTable(data) {
            if (stagesTable) {
                stagesTable.destroy();
                $('#stagesTable').empty();
            }

            setTimeout(() => {
                stagesTable = $('#stagesTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: [
                        { data: 'display_order', title: 'Order', width: '60px' },
                        {
                            data: null,
                            title: 'Stage',
                            render: function(data, type, row) {
                                let icon = row.icon ? '<i class="' + row.icon + '"></i> ' : '';
                                return '<span class="stage-badge" style="color:' + row.color + '">' + icon + row.name + '</span>';
                            }
                        },
                        {
                            data: 'stage_type',
                            title: 'Type',
                            render: function(data) {
                                const cls = data === 'pipeline' ? 'type-pipeline' : 'type-status';
                                const label = data === 'pipeline' ? 'Pipeline' : 'Status';
                                return '<span class="type-badge ' + cls + '">' + label + '</span>';
                            }
                        },
                        {
                            data: 'color',
                            title: 'Color',
                            render: function(data) {
                                return '<span class="color-preview" style="background:' + data + '" title="' + data + '"></span> ' + data;
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
                        { data: 'created_at', title: 'Created' },
                        {
                            data: null,
                            title: 'Actions',
                            orderable: false,
                            render: function(data, type, row) {
                                const toggleIcon = row.is_active
                                    ? '<button class="action-icon delete-icon" onclick="toggleActive(' + row.id + ', 0)" title="Deactivate"><i class="ri-forbid-line"></i></button>'
                                    : '<button class="action-icon edit-icon" onclick="toggleActive(' + row.id + ', 1)" title="Activate"><i class="ri-checkbox-circle-line"></i></button>';

                                return '<button class="action-icon edit-icon" onclick=\'editStage(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="ri-edit-line"></i></button>' +
                                    toggleIcon +
                                    '<button class="action-icon delete-icon" onclick="deleteStage(' + row.id + ')" title="Delete"><i class="ri-delete-bin-line"></i></button>';
                            }
                        }
                    ],
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        { extend: 'csv', text: '<i class="ri-file-excel-2-line"></i> CSV', exportOptions: { columns: ':not(:last-child)' } },
                        { extend: 'pdf', text: '<i class="ri-file-pdf-line"></i> PDF', exportOptions: { columns: ':not(:last-child)' } },
                        { extend: 'print', text: '<i class="ri-printer-line"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
                    ],
                    order: [[0, 'asc']]
                });

                // Filter on change
                $('#filterActive').off('change').on('change', function() {
                    applyFilters();
                });
            }, 100);
        }

        function applyFilters() {
            if (!stagesTable) return;
            const status = document.getElementById('filterActive').value;
            if (status) {
                stagesTable.column(4).search(status).draw();
            } else {
                stagesTable.column(4).search('').draw();
            }
        }

        function clearFilters() {
            document.getElementById('filterActive').value = '';
            if (stagesTable) {
                stagesTable.columns().search('').draw();
            }
        }

        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="ri-add-circle-line"></i> Add Stage';
            document.getElementById('stageForm').reset();
            document.getElementById('stageId').value = '';
            document.getElementById('colorPicker').value = '#6B7280';
            document.getElementById('stageColor').value = '#6B7280';
            document.getElementById('activeGroup').style.display = 'none';
            document.getElementById('iconPreviewBox').style.display = 'none';
            document.getElementById('stageModal').classList.add('active');
        }

        function editStage(stage) {
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="ri-edit-line"></i> Edit Stage';
            document.getElementById('stageId').value = stage.id;
            document.getElementById('stageName').value = stage.name;
            document.getElementById('stageType').value = stage.stage_type;
            document.getElementById('displayOrder').value = stage.display_order;
            document.getElementById('stageColor').value = stage.color || '#6B7280';
            document.getElementById('colorPicker').value = stage.color || '#6B7280';
            document.getElementById('stageIcon').value = stage.icon || '';
            document.getElementById('stageActive').value = stage.is_active ? '1' : '0';
            document.getElementById('activeGroup').style.display = 'block';

            // Trigger icon preview
            const iconInput = document.getElementById('stageIcon');
            iconInput.dispatchEvent(new Event('input'));

            document.getElementById('stageModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('stageModal').classList.remove('active');
            document.getElementById('stageForm').reset();
        }

        document.getElementById('stageModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('stageForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateStage' : 'addStage';

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
                        Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                        closeModal();
                        setTimeout(() => loadStages(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        function toggleActive(stageId, isActive) {
            const action = isActive ? 'activate' : 'deactivate';
            Swal.fire({
                icon: 'question',
                title: action.charAt(0).toUpperCase() + action.slice(1) + ' Stage?',
                text: 'Are you sure you want to ' + action + ' this stage?',
                showCancelButton: true,
                confirmButtonColor: isActive ? '#34a853' : '#ea4335',
                confirmButtonText: 'Yes, ' + action,
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', stageId);
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
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => loadStages(), 100);
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

        function deleteStage(stageId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Stage?',
                text: 'This action cannot be undone. Consider deactivating instead.',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', stageId);

                    $.ajax({
                        url: '?action=deleteStage',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => loadStages(), 100);
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
