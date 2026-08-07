<?php

require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'staff';
$user_id = $_SESSION['user_id'];
$current_page = 'pipeline';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    $conn = getDBConnection();
    $isAdmin = ($role === 'admin');
    $uid = intval($user_id);

    switch ($_GET['action']) {
        case 'getPipelineData':
            // Get pipeline stages
            $stages = [];
            $stResult = $conn->query("SELECT id, name, color, icon FROM stages WHERE stage_type = 'pipeline' AND is_active = 1 ORDER BY display_order ASC");
            while ($row = $stResult->fetch_assoc()) {
                $stages[] = $row;
            }

            // Get applications with stage info
            $appSql = "SELECT a.id, a.candidate_name, a.position, a.company, a.stage_id, a.next_action, a.next_action_date,
                              a.experience, a.contact_number, a.email,
                              s.name AS status_name, s.color AS status_color
                       FROM applications a
                       LEFT JOIN stages s ON a.status_id = s.id";
            if (!$isAdmin) {
                $appSql .= " WHERE a.assigned_to = $uid";
            }
            $appSql .= " ORDER BY a.updated_at DESC";

            $apps = [];
            $appResult = $conn->query($appSql);
            while ($row = $appResult->fetch_assoc()) {
                $apps[] = $row;
            }

            $conn->close();

            echo json_encode([
                'success' => true,
                'stages' => $stages,
                'applications' => $apps,
                'isAdmin' => $isAdmin
            ]);
            exit();

        case 'updateStage':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                exit();
            }

            $appId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
            $newStageId = isset($_POST['stage_id']) ? intval($_POST['stage_id']) : 0;

            if ($appId <= 0 || $newStageId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit();
            }

            // Check ownership for non-admin
            if (!$isAdmin) {
                $stmt = $conn->prepare("SELECT assigned_to FROM applications WHERE id = ?");
                $stmt->bind_param("i", $appId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    if ($row['assigned_to'] != $uid) {
                        $stmt->close();
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'You can only update applications assigned to you']);
                        exit();
                    }
                }
                $stmt->close();
            }

            // Get stage name for logging
            $stageStmt = $conn->prepare("SELECT name FROM stages WHERE id = ?");
            $stageStmt->bind_param("i", $newStageId);
            $stageStmt->execute();
            $stageResult = $stageStmt->get_result();
            $stageName = $stageResult->fetch_assoc()['name'] ?? 'Unknown';
            $stageStmt->close();

            // Get app name for logging
            $appStmt = $conn->prepare("SELECT candidate_name FROM applications WHERE id = ?");
            $appStmt->bind_param("i", $appId);
            $appStmt->execute();
            $appResult = $appStmt->get_result();
            $appName = $appResult->fetch_assoc()['candidate_name'] ?? 'Unknown';
            $appStmt->close();

            $stmt = $conn->prepare("UPDATE applications SET stage_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $newStageId, $appId);

            if ($stmt->execute()) {
                logActivity($user_id, 'UPDATE', "Moved '$appName' to pipeline stage: $stageName", ['module' => 'pipeline', 'application_id' => $appId]);
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Stage updated successfully']);
            } else {
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => false, 'message' => 'Failed to update stage']);
            }
            exit();

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
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
    <title>Pipeline - Skyward Airlines</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.12">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        /* Kanban Board */
        .kanban-board {
            display: flex;
            gap: 16px;
            overflow-x: auto;
            padding-bottom: 20px;
            -webkit-overflow-scrolling: touch;
            min-height: 500px;
        }
        .kanban-column {
            min-width: 280px;
            max-width: 320px;
            flex: 1;
            background: var(--bg-secondary);
            border-radius: 6px;
            box-shadow: 0 2px 8px var(--shadow-color);
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 200px);
        }
        .kanban-column-header {
            padding: 14px 16px;
            border-radius: 6px 6px 0 0;
            color: white;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-shrink: 0;
        }
        .kanban-column-header .column-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .kanban-count {
            background: rgba(255,255,255,0.25);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }
        .kanban-cards {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            min-height: 100px;
        }
        .kanban-cards.drag-over {
            background: rgba(2,63,87,0.08);
            border: 2px dashed var(--navy-accent);
            border-radius: 0 0 6px 6px;
        }
        .kanban-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 14px;
            margin-bottom: 10px;
            cursor: grab;
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .kanban-card:hover {
            box-shadow: 0 4px 12px var(--shadow-hover);
            transform: translateY(-1px);
        }
        .kanban-card.dragging {
            opacity: 0.5;
            transform: rotate(2deg);
        }
        .kanban-card-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kanban-card-position {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kanban-card-detail {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kanban-card-detail i {
            flex-shrink: 0;
            width: 14px;
            text-align: center;
            color: var(--navy-accent);
            font-size: 11px;
        }
        .kanban-card-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-top: 8px;
        }
        .kanban-card-company {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
            max-width: 60%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kanban-card-status {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            color: white;
            flex-shrink: 0;
        }
        .kanban-card-action {
            font-size: 11px;
            color: var(--navy-accent);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .kanban-empty {
            text-align: center;
            padding: 30px 10px;
            color: var(--text-muted);
            font-size: 13px;
        }
        .kanban-empty i {
            font-size: 28px;
            display: block;
            margin-bottom: 8px;
            color: var(--border-color);
        }

        /* Skeleton */
        .kanban-skeleton {
            display: flex;
            gap: 16px;
            overflow: hidden;
        }
        .kanban-skeleton-col {
            min-width: 280px;
            flex: 1;
            background: var(--bg-secondary);
            border-radius: 6px;
            padding: 16px;
            box-shadow: 0 2px 8px var(--shadow-color);
        }

        /* Scroll hint for mobile */
        .kanban-scroll-hint {
            display: none;
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
            padding: 8px 0;
            margin-bottom: 8px;
        }

        /* Mobile */
        @media (max-width: 768px) {
            .kanban-column {
                min-width: 260px;
                max-width: 280px;
            }
            .kanban-scroll-hint {
                display: block;
            }
            .kanban-board {
                min-height: 400px;
            }
            /* Compact contact details on mobile */
            .kanban-card-detail {
                font-size: 11px;
                margin-bottom: 3px;
            }
            .kanban-card-detail i {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <h1>Pipeline</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="ri-list-unordered"></i> Kanban Board</h2>
                    <button class="btn btn-primary" onclick="loadPipeline()">
                        <i class="ri-refresh-line"></i> Refresh
                    </button>
                </div>

                <div class="kanban-scroll-hint">
                    <i class="ri-arrow-left-right-line"></i> Swipe left/right to see all stages
                </div>

                <!-- Skeleton -->
                <div id="pipelineSkeleton">
                    <div class="kanban-skeleton">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="kanban-skeleton-col">
                            <div class="skeleton" style="height:36px;border-radius:4px;margin-bottom:16px;"></div>
                            <div class="skeleton" style="height:90px;border-radius:4px;margin-bottom:10px;"></div>
                            <div class="skeleton" style="height:90px;border-radius:4px;margin-bottom:10px;"></div>
                            <div class="skeleton" style="height:90px;border-radius:4px;"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Kanban Board -->
                <div id="kanbanBoard" class="kanban-board" style="display:none;"></div>
            </div>
        </div>
    </div>

    <script>
        var userRole = '<?php echo $role; ?>';

        $(document).ready(function() {
            loadPipeline();
        });

        function loadPipeline() {
            $('#pipelineSkeleton').show();
            $('#kanbanBoard').hide();

            $.ajax({
                url: '?action=getPipelineData',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        renderKanban(response.stages, response.applications);
                        $('#pipelineSkeleton').hide();
                        $('#kanbanBoard').show();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load pipeline' });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                }
            });
        }

        function renderKanban(stages, applications) {
            var board = document.getElementById('kanbanBoard');
            board.innerHTML = '';

            stages.forEach(function(stage) {
                var stageApps = applications.filter(function(app) {
                    return parseInt(app.stage_id) === parseInt(stage.id);
                });

                var column = document.createElement('div');
                column.className = 'kanban-column';
                column.innerHTML =
                    '<div class="kanban-column-header" style="background:' + (stage.color || '#6B7280') + ';">' +
                        '<div class="column-title">' +
                            (stage.icon ? '<i class="' + stage.icon + '"></i>' : '') +
                            '<span>' + escapeHtml(stage.name) + '</span>' +
                        '</div>' +
                        '<span class="kanban-count">' + stageApps.length + '</span>' +
                    '</div>' +
                    '<div class="kanban-cards" data-stage-id="' + stage.id + '"></div>';

                var cardsContainer = column.querySelector('.kanban-cards');

                if (stageApps.length === 0) {
                    cardsContainer.innerHTML = '<div class="kanban-empty"><i class="ri-inbox-line"></i>No applications</div>';
                } else {
                    stageApps.forEach(function(app) {
                        var card = document.createElement('div');
                        card.className = 'kanban-card';
                        card.setAttribute('draggable', 'true');
                        card.setAttribute('data-app-id', app.id);

                        var statusHtml = '';
                        if (app.status_name) {
                            statusHtml = '<span class="kanban-card-status" style="background:' + (app.status_color || '#6B7280') + ';">' + escapeHtml(app.status_name) + '</span>';
                        }

                        var companyHtml = '';
                        if (app.company) {
                            companyHtml = '<span class="kanban-card-company"><i class="ri-building-line"></i> ' + escapeHtml(app.company) + '</span>';
                        }

                        var actionHtml = '';
                        if (app.next_action) {
                            var dateStr = '';
                            if (app.next_action_date) {
                                var d = new Date(app.next_action_date);
                                dateStr = ' · ' + d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                            }
                            actionHtml = '<div class="kanban-card-action"><i class="ri-checkbox-multiple-line"></i> ' + escapeHtml(app.next_action) + dateStr + '</div>';
                        }

                        // Build contact details HTML
                        var contactDetailsHtml = '';

                        if (app.experience) {
                            contactDetailsHtml += '<div class="kanban-card-detail"><i class="ri-time-line"></i> ' + escapeHtml(app.experience) + '</div>';
                        }

                        if (app.contact_number) {
                            contactDetailsHtml += '<div class="kanban-card-detail"><i class="ri-phone-line"></i> ' + escapeHtml(app.contact_number) + '</div>';
                        }

                        if (app.email) {
                            contactDetailsHtml += '<div class="kanban-card-detail"><i class="ri-mail-line"></i> ' + escapeHtml(app.email) + '</div>';
                        }

                        card.innerHTML =
                            '<div class="kanban-card-name">' + escapeHtml(app.candidate_name) + '</div>' +
                            '<div class="kanban-card-position"><i class="ri-id-card-line"></i> ' + escapeHtml(app.position) + '</div>' +
                            contactDetailsHtml +
                            '<div class="kanban-card-meta">' + companyHtml + statusHtml + '</div>' +
                            actionHtml;

                        // Drag events
                        card.addEventListener('dragstart', function(e) {
                            e.dataTransfer.setData('text/plain', app.id);
                            this.classList.add('dragging');
                        });
                        card.addEventListener('dragend', function() {
                            this.classList.remove('dragging');
                            document.querySelectorAll('.kanban-cards').forEach(function(c) {
                                c.classList.remove('drag-over');
                            });
                        });

                        cardsContainer.appendChild(card);
                    });
                }

                // Drop zone events
                cardsContainer.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('drag-over');
                });
                cardsContainer.addEventListener('dragleave', function(e) {
                    if (!this.contains(e.relatedTarget)) {
                        this.classList.remove('drag-over');
                    }
                });
                cardsContainer.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('drag-over');
                    var appId = e.dataTransfer.getData('text/plain');
                    var newStageId = this.getAttribute('data-stage-id');
                    updateApplicationStage(appId, newStageId);
                });

                board.appendChild(column);
            });
        }

        function updateApplicationStage(appId, stageId) {
            var formData = new FormData();
            formData.append('application_id', appId);
            formData.append('stage_id', stageId);

            $.ajax({
                url: '?action=updateStage',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', text: response.message, timer: 1500, showConfirmButton: false });
                        loadPipeline();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                        loadPipeline();
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                    loadPipeline();
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }
    </script>
</body>
</html>
