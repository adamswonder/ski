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
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
$user_id = $_SESSION['user_id'];
$current_page = 'calendar';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    $conn = getDBConnection();
    $isAdmin = ($role === 'admin');
    $uid = intval($user_id);

    switch ($_GET['action']) {
        case 'getCalendarEvents':
            $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
            $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

            // Validate
            if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
                echo json_encode(['success' => false, 'message' => 'Invalid date']);
                exit();
            }

            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate = date('Y-m-t', strtotime($startDate));

            // Get applications with next_action_date in this month
            $sql = "SELECT a.id, a.candidate_name, a.position, a.company, a.next_action, a.next_action_date,
                           s.name AS stage_name, s.color AS stage_color,
                           st.name AS status_name, st.color AS status_color
                    FROM applications a
                    LEFT JOIN stages s ON a.stage_id = s.id
                    LEFT JOIN stages st ON a.status_id = st.id
                    WHERE a.next_action_date IS NOT NULL
                    AND a.next_action_date >= ?
                    AND a.next_action_date <= ?";

            if (!$isAdmin) {
                $sql .= " AND a.assigned_to = ?";
            }

            $sql .= " ORDER BY a.next_action_date ASC";

            $stmt = $conn->prepare($sql);
            if ($isAdmin) {
                $stmt->bind_param("ss", $startDate, $endDate);
            } else {
                $stmt->bind_param("ssi", $startDate, $endDate, $uid);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            $events = [];
            while ($row = $result->fetch_assoc()) {
                $events[] = [
                    'id' => $row['id'],
                    'candidate_name' => $row['candidate_name'],
                    'position' => $row['position'],
                    'company' => $row['company'],
                    'next_action' => $row['next_action'],
                    'next_action_date' => $row['next_action_date'],
                    'stage_name' => $row['stage_name'],
                    'stage_color' => $row['stage_color'],
                    'status_name' => $row['status_name'],
                    'status_color' => $row['status_color']
                ];
            }
            $stmt->close();
            $conn->close();

            echo json_encode(['success' => true, 'events' => $events, 'month' => $month, 'year' => $year]);
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
    <title>Calendar - Job Application Tracker</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=5.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        /* Calendar Controls */
        .calendar-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .calendar-nav button {
            background: var(--navy-primary);
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            touch-action: manipulation;
        }
        .calendar-nav button:hover {
            background: var(--navy-hover);
        }
        .calendar-month-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            min-width: 180px;
            text-align: center;
        }
        .calendar-today-btn {
            background: var(--navy-accent) !important;
        }

        /* Calendar Grid */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        .calendar-day-header {
            background: var(--navy-primary);
            color: white;
            padding: 10px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 13px;
        }
        .calendar-day {
            border: 1px solid var(--border-color);
            min-height: 100px;
            padding: 6px;
            background: var(--bg-card);
            vertical-align: top;
            position: relative;
        }
        .calendar-day.other-month {
            background: var(--bg-primary);
            opacity: 0.5;
        }
        .calendar-day.today {
            background: rgba(0, 116, 217, 0.06);
            border-color: var(--navy-accent);
        }
        .calendar-day-number {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .calendar-day.today .calendar-day-number {
            background: var(--navy-accent);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        /* Calendar Events */
        .cal-event {
            display: block;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 500;
            color: white;
            margin-bottom: 3px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: opacity 0.2s;
        }
        .cal-event:hover {
            opacity: 0.85;
        }
        .cal-event-more {
            font-size: 11px;
            color: var(--navy-accent);
            font-weight: 600;
            cursor: pointer;
            padding: 2px 6px;
        }
        .cal-event-more:hover {
            text-decoration: underline;
        }

        /* Event Popup Overlay */
        .event-popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.55);
            z-index: 1000;
            animation: popupFadeIn 0.2s ease;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
        }

        /* Event Popup Card */
        .event-popup {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--bg-card);
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            z-index: 1001;
            width: 92%;
            max-width: 440px;
            overflow: hidden;
            animation: popupSlideIn 0.25s ease;
        }

        @keyframes popupFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes popupSlideIn {
            from { opacity: 0; transform: translate(-50%, -48%) scale(0.96); }
            to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        }

        /* Popup Header */
        .ep-header {
            background: linear-gradient(135deg, var(--navy-primary) 0%, var(--navy-light) 100%);
            padding: 20px 24px 16px;
            color: #fff;
            position: relative;
        }
        .ep-header-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .ep-ref {
            font-size: 11px;
            opacity: 0.7;
            letter-spacing: 0.5px;
        }
        .ep-close {
            background: rgba(255,255,255,0.15);
            border: none;
            color: #fff;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .ep-close:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }
        .ep-candidate-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ep-position {
            font-size: 13px;
            opacity: 0.85;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Popup Body */
        .ep-body {
            padding: 18px 24px 20px;
        }

        /* Action Highlight Card */
        .ep-action-card {
            background: linear-gradient(135deg, rgba(0,116,217,0.08) 0%, rgba(0,116,217,0.03) 100%);
            border: 1px solid rgba(0,116,217,0.15);
            border-left: 4px solid var(--navy-accent);
            border-radius: 6px;
            padding: 14px 16px;
            margin-bottom: 16px;
        }
        .ep-action-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--navy-accent);
            margin-bottom: 4px;
        }
        .ep-action-text {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .ep-action-date {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Detail Rows */
        .ep-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .ep-detail-item {
            padding: 10px 12px;
            background: var(--bg-primary);
            border-radius: 6px;
            border: 1px solid var(--border-light);
        }
        .ep-detail-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .ep-detail-label i {
            font-size: 10px;
            color: var(--navy-accent);
        }
        .ep-detail-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .ep-detail-item.full-width {
            grid-column: 1 / -1;
        }

        /* Badges inside popup */
        .ep-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
        }

        /* Popup Footer Actions */
        .ep-footer {
            padding: 0 24px 20px;
            display: flex;
            gap: 10px;
        }
        .ep-footer .btn {
            flex: 1;
            text-decoration: none;
            font-size: 13px;
            padding: 10px 16px;
            border-radius: 6px;
        }

        /* Day Events SweetAlert improvements */
        .day-events-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--navy-primary);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--navy-accent);
        }
        .day-event-card {
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 8px;
            border-left: 4px solid #6B7280;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--bg-card);
        }
        .day-event-card:hover {
            border-color: var(--navy-accent);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transform: translateX(3px);
        }
        .day-event-card:last-child {
            margin-bottom: 0;
        }
        .day-event-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        .day-event-pos {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }
        .day-event-action {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        @media (max-width: 480px) {
            .ep-details-grid { grid-template-columns: 1fr; }
            .ep-candidate-name { font-size: 17px; }
            .ep-header { padding: 16px 18px 14px; }
            .ep-body { padding: 14px 18px 16px; }
            .ep-footer { padding: 0 18px 16px; }
        }

        /* Upcoming Events Sidebar */
        .calendar-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 20px;
        }
        .upcoming-panel {
            background: var(--bg-card);
            border-radius: 4px;
            box-shadow: 0 2px 8px var(--shadow-color);
            padding: 20px;
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }
        .upcoming-panel h3 {
            color: var(--navy-primary);
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .upcoming-event-item {
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .upcoming-event-item:last-child {
            border-bottom: none;
        }
        .upcoming-event-date {
            font-size: 11px;
            color: var(--navy-accent);
            font-weight: 600;
            margin-bottom: 2px;
        }
        .upcoming-event-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .upcoming-event-action {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Mobile */
        @media (max-width: 1024px) {
            .calendar-layout {
                grid-template-columns: 1fr;
            }
            .upcoming-panel {
                max-height: none;
            }
        }
        @media (max-width: 768px) {
            .calendar-day {
                min-height: 60px;
                padding: 4px;
            }
            .calendar-day-number {
                font-size: 11px;
            }
            .cal-event {
                font-size: 9px;
                padding: 2px 4px;
            }
            .calendar-month-title {
                font-size: 16px;
                min-width: 140px;
            }
            .calendar-day-header {
                font-size: 11px;
                padding: 8px 4px;
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
                <h1><i class="fas fa-calendar-alt"></i> Calendar</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="calendar-controls">
                    <div class="calendar-nav">
                        <button onclick="changeMonth(-1)" title="Previous Month"><i class="fas fa-chevron-left"></i></button>
                        <div class="calendar-month-title" id="calendarTitle"></div>
                        <button onclick="changeMonth(1)" title="Next Month"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <button class="btn btn-primary calendar-today-btn" onclick="goToToday()">
                        <i class="fas fa-calendar-day"></i> Today
                    </button>
                </div>

                <div class="calendar-layout">
                    <div>
                        <div class="calendar-grid" id="calendarGrid"></div>
                    </div>
                    <div class="upcoming-panel" id="upcomingPanel">
                        <h3><i class="fas fa-clock"></i> This Month's Actions</h3>
                        <div id="upcomingEvents">
                            <p style="color: var(--text-muted); text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        var userRole = '<?php echo $role; ?>';
        var currentMonth = new Date().getMonth() + 1;
        var currentYear = new Date().getFullYear();
        var calendarEvents = [];

        $(document).ready(function() {
            loadCalendar(currentMonth, currentYear);
        });

        function loadCalendar(month, year) {
            currentMonth = month;
            currentYear = year;

            // Update title
            var date = new Date(year, month - 1);
            document.getElementById('calendarTitle').textContent = date.toLocaleString('en-US', { month: 'long', year: 'numeric' });

            $.ajax({
                url: '?action=getCalendarEvents&month=' + month + '&year=' + year,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        calendarEvents = response.events;
                        renderCalendar(month, year, response.events);
                        renderUpcomingEvents(response.events);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                }
            });
        }

        function renderCalendar(month, year, events) {
            var grid = document.getElementById('calendarGrid');
            grid.innerHTML = '';

            // Day headers
            var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            days.forEach(function(day) {
                var header = document.createElement('div');
                header.className = 'calendar-day-header';
                header.textContent = day;
                grid.appendChild(header);
            });

            // Calculate calendar dates
            var firstDay = new Date(year, month - 1, 1);
            var lastDay = new Date(year, month, 0);
            var startPad = firstDay.getDay();
            var totalDays = lastDay.getDate();

            var today = new Date();
            var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

            // Group events by date
            var eventsByDate = {};
            events.forEach(function(ev) {
                var d = ev.next_action_date;
                if (!eventsByDate[d]) eventsByDate[d] = [];
                eventsByDate[d].push(ev);
            });

            // Previous month padding
            var prevMonth = new Date(year, month - 2, 1);
            var prevMonthDays = new Date(year, month - 1, 0).getDate();
            for (var i = startPad - 1; i >= 0; i--) {
                var cell = document.createElement('div');
                cell.className = 'calendar-day other-month';
                cell.innerHTML = '<div class="calendar-day-number">' + (prevMonthDays - i) + '</div>';
                grid.appendChild(cell);
            }

            // Current month days
            for (var d = 1; d <= totalDays; d++) {
                var dateStr = year + '-' + String(month).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                var cell = document.createElement('div');
                cell.className = 'calendar-day';
                if (dateStr === todayStr) cell.className += ' today';

                var numberHtml = '<div class="calendar-day-number">' + d + '</div>';
                var eventsHtml = '';

                if (eventsByDate[dateStr]) {
                    var dayEvents = eventsByDate[dateStr];
                    var maxShow = window.innerWidth < 768 ? 1 : 3;
                    for (var e = 0; e < Math.min(dayEvents.length, maxShow); e++) {
                        var ev = dayEvents[e];
                        eventsHtml += '<div class="cal-event" style="background:' + (ev.stage_color || '#6B7280') + ';" onclick=\'showEventPopup(' + JSON.stringify(ev).replace(/'/g, "\\'") + ')\'>' +
                            escapeHtml(ev.candidate_name) + '</div>';
                    }
                    if (dayEvents.length > maxShow) {
                        eventsHtml += '<div class="cal-event-more" onclick="showDayEvents(\'' + dateStr + '\')">+' + (dayEvents.length - maxShow) + ' more</div>';
                    }
                }

                cell.innerHTML = numberHtml + eventsHtml;
                grid.appendChild(cell);
            }

            // Next month padding
            var totalCells = startPad + totalDays;
            var remaining = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
            for (var i = 1; i <= remaining; i++) {
                var cell = document.createElement('div');
                cell.className = 'calendar-day other-month';
                cell.innerHTML = '<div class="calendar-day-number">' + i + '</div>';
                grid.appendChild(cell);
            }
        }

        function renderUpcomingEvents(events) {
            var container = document.getElementById('upcomingEvents');

            if (events.length === 0) {
                container.innerHTML = '<p style="color: var(--text-muted); text-align: center; padding: 20px;"><i class="fas fa-calendar-check" style="font-size:24px;display:block;margin-bottom:8px;"></i>No scheduled actions this month</p>';
                return;
            }

            var html = '';
            events.forEach(function(ev) {
                var date = new Date(ev.next_action_date);
                var dateStr = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });

                html += '<div class="upcoming-event-item" onclick=\'showEventPopup(' + JSON.stringify(ev).replace(/'/g, "\\'") + ')\' style="cursor:pointer;">';
                html += '<div class="upcoming-event-date"><i class="fas fa-calendar-day"></i> ' + dateStr + '</div>';
                html += '<div class="upcoming-event-name">' + escapeHtml(ev.candidate_name) + '</div>';
                html += '<div class="upcoming-event-action"><i class="fas fa-tasks"></i> ' + escapeHtml(ev.next_action || 'No action specified') + '</div>';
                html += '</div>';
            });
            container.innerHTML = html;
        }

        function showEventPopup(event) {
            var stageHtml = event.stage_name ? '<span class="ep-badge" style="background:' + (event.stage_color || '#6B7280') + '">' + escapeHtml(event.stage_name) + '</span>' : '<span style="color:var(--text-muted)">N/A</span>';
            var statusHtml = event.status_name ? '<span class="ep-badge" style="background:' + (event.status_color || '#6B7280') + '">' + escapeHtml(event.status_name) + '</span>' : '<span style="color:var(--text-muted)">N/A</span>';
            var dateDisplay = new Date(event.next_action_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

            var html = '<div class="event-popup-overlay" onclick="closeEventPopup()"></div>';
            html += '<div class="event-popup">';

            // Header
            html += '<div class="ep-header">';
            html += '<div class="ep-header-top">';
            html += '<div class="ep-ref"><i class="fas fa-hashtag"></i> APP-' + String(event.id || 0).padStart(4, '0') + '</div>';
            html += '<button class="ep-close" onclick="closeEventPopup()"><i class="fas fa-times"></i></button>';
            html += '</div>';
            html += '<div class="ep-candidate-name"><i class="fas fa-user-circle"></i> ' + escapeHtml(event.candidate_name) + '</div>';
            html += '<div class="ep-position"><i class="fas fa-briefcase"></i> ' + escapeHtml(event.position) + (event.company ? ' &mdash; ' + escapeHtml(event.company) : '') + '</div>';
            html += '</div>';

            // Body
            html += '<div class="ep-body">';

            // Action highlight card
            html += '<div class="ep-action-card">';
            html += '<div class="ep-action-label"><i class="fas fa-bolt"></i> Scheduled Action</div>';
            html += '<div class="ep-action-text">' + escapeHtml(event.next_action || 'No action specified') + '</div>';
            html += '<div class="ep-action-date"><i class="fas fa-calendar-alt"></i> ' + dateDisplay + '</div>';
            html += '</div>';

            // Details grid
            html += '<div class="ep-details-grid">';

            html += '<div class="ep-detail-item">';
            html += '<div class="ep-detail-label"><i class="fas fa-stream"></i> Stage</div>';
            html += '<div class="ep-detail-value">' + stageHtml + '</div>';
            html += '</div>';

            html += '<div class="ep-detail-item">';
            html += '<div class="ep-detail-label"><i class="fas fa-tags"></i> Status</div>';
            html += '<div class="ep-detail-value">' + statusHtml + '</div>';
            html += '</div>';

            if (event.company) {
                html += '<div class="ep-detail-item full-width">';
                html += '<div class="ep-detail-label"><i class="fas fa-building"></i> Company</div>';
                html += '<div class="ep-detail-value">' + escapeHtml(event.company) + '</div>';
                html += '</div>';
            }

            html += '</div>'; // ep-details-grid
            html += '</div>'; // ep-body

            // Footer actions
            html += '<div class="ep-footer">';
            html += '<a href="applications.php" class="btn btn-primary" style="text-decoration:none;"><i class="fas fa-file-alt"></i> View Applications</a>';
            html += '<button class="btn btn-secondary" onclick="closeEventPopup()"><i class="fas fa-times"></i> Close</button>';
            html += '</div>';

            html += '</div>'; // event-popup

            var popup = document.createElement('div');
            popup.id = 'eventPopupContainer';
            popup.innerHTML = html;
            document.body.appendChild(popup);
        }

        function closeEventPopup() {
            var popup = document.getElementById('eventPopupContainer');
            if (popup) popup.remove();
        }

        function showDayEvents(dateStr) {
            var dayEvents = calendarEvents.filter(function(ev) {
                return ev.next_action_date === dateStr;
            });

            if (dayEvents.length === 0) return;

            var date = new Date(dateStr);
            var dateDisplay = date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

            var html = '<div class="day-events-title"><i class="fas fa-calendar-day"></i> ' + dateDisplay + '</div>';
            dayEvents.forEach(function(ev) {
                var evJson = JSON.stringify(ev).replace(/'/g, "\\'").replace(/"/g, '&quot;');
                html += '<div class="day-event-card" style="border-left-color:' + (ev.stage_color || '#6B7280') + ';" onclick="Swal.close();setTimeout(function(){showEventPopup(' + evJson + ')},200);">';
                html += '<div class="day-event-name"><i class="fas fa-user" style="color:' + (ev.stage_color || '#6B7280') + ';font-size:12px;margin-right:4px;"></i> ' + escapeHtml(ev.candidate_name) + '</div>';
                html += '<div class="day-event-pos"><i class="fas fa-briefcase" style="font-size:10px;margin-right:3px;"></i> ' + escapeHtml(ev.position) + (ev.company ? ' &mdash; ' + escapeHtml(ev.company) : '') + '</div>';
                html += '<div class="day-event-action"><i class="fas fa-tasks"></i> ' + escapeHtml(ev.next_action || 'No action specified') + '</div>';
                html += '</div>';
            });
            html += '<div style="text-align:center;margin-top:12px;font-size:11px;color:var(--text-muted);">Click an event to see full details</div>';

            Swal.fire({
                html: html,
                showConfirmButton: false,
                showCloseButton: true,
                width: 460,
                customClass: { popup: 'day-events-swal' }
            });
        }

        function changeMonth(delta) {
            currentMonth += delta;
            if (currentMonth > 12) {
                currentMonth = 1;
                currentYear++;
            } else if (currentMonth < 1) {
                currentMonth = 12;
                currentYear--;
            }
            loadCalendar(currentMonth, currentYear);
        }

        function goToToday() {
            var now = new Date();
            loadCalendar(now.getMonth() + 1, now.getFullYear());
        }

        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        // Close popup on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeEventPopup();
        });
    </script>
</body>
</html>
