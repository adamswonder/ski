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
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'staff';
$user_id = $_SESSION['user_id'];
$current_page = 'logs';

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'getLogs') {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        // Admin sees all logs, regular users see only their own
        if ($role === 'admin') {
            $stmt = $conn->prepare("SELECT l.*, u.full_name AS user_name FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC");
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("SELECT l.*, u.full_name AS user_name FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id WHERE l.user_id = ? ORDER BY l.created_at DESC");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
        }

        $result = $stmt->get_result();
        $logs = [];

        while ($row = $result->fetch_assoc()) {
            $logs[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'user_name' => $row['user_name'] ?? 'Unknown',
                'application_id' => $row['application_id'],
                'action' => $row['action'],
                'module' => $row['module'],
                'description' => $row['description'],
                'old_values' => $row['old_values'],
                'new_values' => $row['new_values'],
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'],
                'created_at' => date('M d, Y H:i:s', strtotime($row['created_at'])),
                'created_at_iso' => $row['created_at']
            ];
        }

        $stmt->close();
        $conn->close();

        echo json_encode(['success' => true, 'data' => $logs]);
        exit();

    } catch (Exception $e) {
        error_log("Logs.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading logs: ' . $e->getMessage()]);
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
    <title>Activity Logs Skyward Airlines</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=5.10">

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

    <style>
        .action-badge {
            padding: 4px 10px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            display: inline-block;
            color: white;
            text-transform: uppercase;
        }
        .action-LOGIN { background: #34a853; }
        .action-LOGOUT { background: #6c757d; }
        .action-CREATE { background: #023f57; }
        .action-UPDATE { background: #F59E0B; color: #333; }
        .action-DELETE { background: #ea4335; }
        .action-EXPORT { background: #6f42c1; }
        .action-PRINT { background: #20c997; }
        .action-VIEW_CV { background: #e8262c; }

        .module-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            background: #e9ecef;
            color: #495057;
            text-transform: capitalize;
        }
        .module-applications { background: #dbeafe; color: #1e40af; }
        .module-users { background: #d1fae5; color: #065f46; }
        .module-stages { background: #fef3c7; color: #92400e; }
        .module-documents { background: #ede9fe; color: #5b21b6; }
        .module-settings { background: #fee2e2; color: #991b1b; }
        .module-auth { background: #e0e7ff; color: #3730a3; }

        .log-detail-section { margin-bottom: 15px; }
        .log-detail-section label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--navy-primary);
            display: block;
            margin-bottom: 5px;
        }
        .log-detail-section .detail-value {
            background: #f8f9fa;
            padding: 10px 14px;
            border-radius: 3px;
            border: 1px solid #e9ecef;
            font-size: 14px;
            color: #333;
            word-break: break-word;
        }
        .json-display {
            background: #1e1e2e;
            color: #cdd6f4;
            padding: 12px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 200px;
            overflow-y: auto;
        }
        .log-detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        @media (max-width: 768px) {
            .log-detail-grid { grid-template-columns: 1fr; }
        }

        /* Dark mode */
        body.dark-mode .action-badge { opacity: 0.9; }
        body.dark-mode .module-badge { opacity: 0.85; }
        body.dark-mode .log-detail-section .detail-value {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.1);
            color: #b0b3c5;
        }
        body.dark-mode .json-display {
            background: #11111b;
            border: 1px solid rgba(255,255,255,0.1);
        }
    </style>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1><i class="ri-history-line"></i> Activity Logs</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="ri-table-line"></i> Activity History</h2>
                    <button class="btn btn-primary" onclick="loadLogs()">
                        <i class="ri-refresh-line"></i> Refresh
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
                            <label><i class="ri-calendar-line"></i> Date From</label>
                            <input type="date" id="filterDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-calendar-line"></i> Date To</label>
                            <input type="date" id="filterDateTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-flashlight-line"></i> Action</label>
                            <select id="filterAction" class="filter-input">
                                <option value="">All Actions</option>
                                <option value="LOGIN">LOGIN</option>
                                <option value="LOGOUT">LOGOUT</option>
                                <option value="CREATE">CREATE</option>
                                <option value="UPDATE">UPDATE</option>
                                <option value="DELETE">DELETE</option>
                                <option value="EXPORT">EXPORT</option>
                                <option value="PRINT">PRINT</option>
                                <option value="VIEW_CV">VIEW_CV</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="ri-box-3-line"></i> Module</label>
                            <select id="filterModule" class="filter-input">
                                <option value="">All Modules</option>
                            </select>
                        </div>
                        <?php if ($role === 'admin'): ?>
                        <div class="filter-group">
                            <label><i class="ri-user-line"></i> User</label>
                            <select id="filterUser" class="filter-input">
                                <option value="">All Users</option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- DataTable -->
                <div id="tableContainer" style="display: none;">
                    <div class="table-scroll-hint">
                        <i class="ri-arrow-left-right-line"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table id="logsTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Detail Modal -->
    <div class="modal-overlay" id="logDetailModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width: 700px;">
            <div class="modal-header">
                <h3><i class="ri-information-line"></i> Log Entry Details</h3>
                <button class="close-btn" onclick="closeLogDetail()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal-body" id="logDetailBody">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <script>
        let logsTable;
        let logsData = [];

        $(document).ready(function() {
            loadLogs();
        });

        function loadLogs() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();
            $('#filtersSection').hide();

            $.ajax({
                url: '?action=getLogs',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logsData = response.data;

                        // Populate filter dropdowns from data
                        populateFilters(response.data);

                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        $('#filtersSection').show();

                        setTimeout(() => initializeDataTable(response.data), 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load logs'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#loadingSkeleton').hide();
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server.'
                    });
                }
            });
        }

        function populateFilters(data) {
            // Populate module filter
            var modules = [...new Set(data.map(r => r.module).filter(Boolean))].sort();
            var moduleSelect = document.getElementById('filterModule');
            moduleSelect.innerHTML = '<option value="">All Modules</option>';
            modules.forEach(function(m) {
                var opt = document.createElement('option');
                opt.value = m;
                opt.textContent = m.charAt(0).toUpperCase() + m.slice(1);
                moduleSelect.appendChild(opt);
            });

            // Populate user filter (Admin only)
            <?php if ($role === 'admin'): ?>
            var users = [...new Set(data.map(r => r.user_name).filter(Boolean))].sort();
            var userSelect = document.getElementById('filterUser');
            userSelect.innerHTML = '<option value="">All Users</option>';
            users.forEach(function(u) {
                var opt = document.createElement('option');
                opt.value = u;
                opt.textContent = u;
                userSelect.appendChild(opt);
            });
            <?php endif; ?>
        }

        function getActionBadge(action) {
            if (!action) return '-';
            var cls = 'action-' + action.replace(/\s+/g, '_');
            return '<span class="action-badge ' + cls + '">' + action + '</span>';
        }

        function getModuleBadge(module) {
            if (!module) return '<span style="color:#999">-</span>';
            var cls = 'module-' + module;
            return '<span class="module-badge ' + cls + '">' + module + '</span>';
        }

        function initializeDataTable(data) {
            if (logsTable) {
                logsTable.destroy();
                $('#logsTable').empty();
            }

            setTimeout(() => {
                logsTable = $('#logsTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: [
                        { data: 'id', title: 'ID', width: '55px' },
                        { data: 'user_name', title: 'User' },
                        {
                            data: 'action',
                            title: 'Action',
                            render: function(data) {
                                return getActionBadge(data);
                            }
                        },
                        {
                            data: 'module',
                            title: 'Module',
                            render: function(data) {
                                return getModuleBadge(data);
                            }
                        },
                        {
                            data: 'description',
                            title: 'Description',
                            render: function(data) {
                                if (!data) return '<em style="color:#999">-</em>';
                                return data.length > 80 ? data.substring(0, 80) + '...' : data;
                            }
                        },
                        { data: 'ip_address', title: 'IP', defaultContent: '-' },
                        { data: 'created_at', title: 'Timestamp' },
                        {
                            data: null,
                            title: '',
                            orderable: false,
                            width: '40px',
                            className: 'text-center',
                            render: function(data, type, row, meta) {
                                return '<button class="action-icon" data-idx="' + meta.row + '" title="View Details" style="color: var(--navy-accent);"><i class="ri-eye-line"></i></button>';
                            }
                        }
                    ],
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        { extend: 'csv', text: '<i class="ri-file-excel-2-line"></i> CSV', exportOptions: { columns: [0,1,2,3,4,5,6] } },
                        { extend: 'pdf', text: '<i class="ri-file-pdf-line"></i> PDF', exportOptions: { columns: [0,1,2,3,4,5,6] } },
                        { extend: 'print', text: '<i class="ri-printer-line"></i> Print', exportOptions: { columns: [0,1,2,3,4,5,6] } }
                    ],
                    order: [[0, 'desc']]
                });

                // View detail click
                $('#logsTable').off('click', '.action-icon');
                $('#logsTable').on('click', '.action-icon', function() {
                    var idx = $(this).data('idx');
                    if (idx !== undefined && logsData[idx]) {
                        viewLogDetail(logsData[idx]);
                    }
                });

                // Apply filters on change
                $('#filterDateFrom, #filterDateTo, #filterAction, #filterModule').on('change', function() {
                    applyFilters();
                });
                <?php if ($role === 'admin'): ?>
                $('#filterUser').on('change', function() {
                    applyFilters();
                });
                <?php endif; ?>

            }, 100);
        }

        function viewLogDetail(log) {
            var html = '';

            // Top info row
            html += '<div class="log-detail-grid">';
            html += '<div class="log-detail-section"><label>Log ID</label><div class="detail-value">#' + log.id + '</div></div>';
            html += '<div class="log-detail-section"><label>User</label><div class="detail-value">' + (log.user_name || 'Unknown') + '</div></div>';
            html += '</div>';

            html += '<div class="log-detail-grid">';
            html += '<div class="log-detail-section"><label>Action</label><div class="detail-value">' + getActionBadge(log.action) + '</div></div>';
            html += '<div class="log-detail-section"><label>Module</label><div class="detail-value">' + getModuleBadge(log.module) + '</div></div>';
            html += '</div>';

            // Description
            html += '<div class="log-detail-section"><label>Description</label><div class="detail-value">' + (log.description || '<em style="color:#999">No description</em>') + '</div></div>';

            // Application ID
            if (log.application_id) {
                html += '<div class="log-detail-section"><label>Application ID</label><div class="detail-value">#' + log.application_id + '</div></div>';
            }

            // Old values / New values
            if (log.old_values || log.new_values) {
                html += '<div class="log-detail-grid">';
                if (log.old_values) {
                    html += '<div class="log-detail-section"><label><i class="ri-arrow-left-line"></i> Old Values</label><div class="json-display">' + formatJSON(log.old_values) + '</div></div>';
                }
                if (log.new_values) {
                    html += '<div class="log-detail-section"><label><i class="ri-arrow-right-line"></i> New Values</label><div class="json-display">' + formatJSON(log.new_values) + '</div></div>';
                }
                html += '</div>';
            }

            // IP & User Agent
            html += '<div class="log-detail-grid">';
            html += '<div class="log-detail-section"><label>IP Address</label><div class="detail-value">' + (log.ip_address || '-') + '</div></div>';
            html += '<div class="log-detail-section"><label>Timestamp</label><div class="detail-value">' + log.created_at + '</div></div>';
            html += '</div>';

            if (log.user_agent) {
                html += '<div class="log-detail-section"><label><i class="ri-computer-line"></i> User Agent</label><div class="detail-value" style="font-size:12px; word-break:break-all;">' + log.user_agent + '</div></div>';
            }

            document.getElementById('logDetailBody').innerHTML = html;
            document.getElementById('logDetailModal').classList.add('active');
        }

        function formatJSON(str) {
            if (!str) return '-';
            try {
                var obj = typeof str === 'string' ? JSON.parse(str) : str;
                return JSON.stringify(obj, null, 2)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            } catch (e) {
                return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
        }

        function closeLogDetail() {
            document.getElementById('logDetailModal').classList.remove('active');
        }

        document.getElementById('logDetailModal').addEventListener('click', function(e) {
            if (e.target === this) closeLogDetail();
        });

        function applyFilters() {
            if (!logsTable) return;

            // Clear previous custom filters
            $.fn.dataTable.ext.search = [];

            var dateFrom = document.getElementById('filterDateFrom').value;
            var dateTo = document.getElementById('filterDateTo').value;
            var action = document.getElementById('filterAction').value;
            var module = document.getElementById('filterModule').value;
            <?php if ($role === 'admin'): ?>
            var userName = document.getElementById('filterUser').value;
            <?php endif; ?>

            // Date range filter
            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    var isoDate = logsData[dataIndex]?.created_at_iso;
                    if (!isoDate) return true;

                    var recordDate = new Date(isoDate);
                    var fromDate = dateFrom ? new Date(dateFrom) : null;
                    var toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;

                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            // Column filters
            logsTable.columns().search('');
            if (action) logsTable.column(2).search(action);
            if (module) logsTable.column(3).search(module);
            <?php if ($role === 'admin'): ?>
            if (userName) logsTable.column(1).search(userName);
            <?php endif; ?>

            logsTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterAction').value = '';
            document.getElementById('filterModule').value = '';
            <?php if ($role === 'admin'): ?>
            document.getElementById('filterUser').value = '';
            <?php endif; ?>

            if (logsTable) {
                $.fn.dataTable.ext.search = [];
                logsTable.columns().search('').draw();
            }
        }
    </script>
</body>
</html>
