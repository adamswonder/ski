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
$current_page = 'dashboard';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    $conn = getDBConnection();
    $isAdmin = ($role === 'admin');
    $uid = intval($user_id);

    switch ($_GET['action']) {
        case 'getFilterOptions':
            // Fetch pipeline stages
            $pipelineStages = [];
            $res = $conn->query("SELECT id, name FROM stages WHERE stage_type = 'pipeline' AND is_active = 1 ORDER BY display_order ASC");
            while ($row = $res->fetch_assoc()) {
                $pipelineStages[] = $row;
            }

            // Fetch status stages
            $statusStages = [];
            $res2 = $conn->query("SELECT id, name FROM stages WHERE stage_type = 'status' AND is_active = 1 ORDER BY display_order ASC");
            while ($row = $res2->fetch_assoc()) {
                $statusStages[] = $row;
            }

            $conn->close();
            echo json_encode([
                'success' => true,
                'pipelineStages' => $pipelineStages,
                'statusStages' => $statusStages
            ]);
            exit();

        case 'getDashboardStats':
            // Optional filter parameters
            $filterStage = isset($_GET['stage_id']) ? intval($_GET['stage_id']) : 0;
            $filterStatus = isset($_GET['status_id']) ? intval($_GET['status_id']) : 0;

            // Build dynamic filter fragments
            $roleFilter = $isAdmin ? "" : " AND a.assigned_to = $uid";
            $stageFilter = $filterStage > 0 ? " AND a.stage_id = $filterStage" : "";
            $statusFilter = $filterStatus > 0 ? " AND a.status_id = $filterStatus" : "";
            $extraFilters = $roleFilter . $stageFilter . $statusFilter;

            // ---- KPI Cards (consolidated into 1 query) ----
            $kpiSql = "SELECT
                COUNT(*) as total_apps,
                SUM(CASE WHEN st_s.name = 'Active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN p_s.name = 'Interview' THEN 1 ELSE 0 END) as interview_count,
                SUM(CASE WHEN p_s.name = 'Offer' THEN 1 ELSE 0 END) as offer_count,
                SUM(CASE WHEN st_s.name = 'Hired' THEN 1 ELSE 0 END) as hired_count,
                ROUND(AVG(CASE WHEN a.days_to_join IS NOT NULL THEN a.days_to_join ELSE NULL END)) as avg_days
                FROM applications a
                LEFT JOIN stages p_s ON a.stage_id = p_s.id
                LEFT JOIN stages st_s ON a.status_id = st_s.id
                WHERE 1=1" . $extraFilters;
            $kpiResult = $conn->query($kpiSql)->fetch_assoc();
            $totalApps = intval($kpiResult['total_apps']);
            $activeCount = intval($kpiResult['active_count']);
            $interviewCount = intval($kpiResult['interview_count']);
            $offerCount = intval($kpiResult['offer_count']);
            $hiredCount = intval($kpiResult['hired_count']);
            $avgDays = $kpiResult['avg_days'] !== null ? intval($kpiResult['avg_days']) : 0;

            // Total Users (admin only, not filtered)
            $totalUsers = $isAdmin ? $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'] : 0;

            // Total Documents
            if ($isAdmin) {
                $totalDocs = $conn->query("SELECT COUNT(*) as c FROM application_documents d JOIN applications a ON d.application_id = a.id WHERE 1=1" . $stageFilter . $statusFilter)->fetch_assoc()['c'];
            } else {
                $totalDocs = $conn->query("SELECT COUNT(*) as c FROM application_documents d JOIN applications a ON d.application_id = a.id WHERE a.assigned_to = $uid" . $stageFilter . $statusFilter)->fetch_assoc()['c'];
            }

            // ---- Month-over-Month Trends (consolidated into 1 query) ----
            $curMonth = date('Y-m');
            $prevMonth = date('Y-m', strtotime('-1 month'));

            $trendSql = "SELECT
                SUM(CASE WHEN DATE_FORMAT(a.applied_date,'%Y-%m') = '$curMonth' THEN 1 ELSE 0 END) as cur_total,
                SUM(CASE WHEN DATE_FORMAT(a.applied_date,'%Y-%m') = '$prevMonth' THEN 1 ELSE 0 END) as prev_total,
                SUM(CASE WHEN DATE_FORMAT(a.applied_date,'%Y-%m') = '$curMonth' AND st_s.name = 'Active' THEN 1 ELSE 0 END) as cur_active,
                SUM(CASE WHEN DATE_FORMAT(a.applied_date,'%Y-%m') = '$prevMonth' AND st_s.name = 'Active' THEN 1 ELSE 0 END) as prev_active,
                SUM(CASE WHEN DATE_FORMAT(a.applied_date,'%Y-%m') = '$curMonth' AND p_s.name = 'Interview' THEN 1 ELSE 0 END) as cur_interview,
                SUM(CASE WHEN DATE_FORMAT(a.applied_date,'%Y-%m') = '$prevMonth' AND p_s.name = 'Interview' THEN 1 ELSE 0 END) as prev_interview,
                SUM(CASE WHEN DATE_FORMAT(a.applied_date,'%Y-%m') = '$curMonth' AND p_s.name = 'Offer' THEN 1 ELSE 0 END) as cur_offer,
                SUM(CASE WHEN DATE_FORMAT(a.applied_date,'%Y-%m') = '$prevMonth' AND p_s.name = 'Offer' THEN 1 ELSE 0 END) as prev_offer
                FROM applications a
                LEFT JOIN stages p_s ON a.stage_id = p_s.id
                LEFT JOIN stages st_s ON a.status_id = st_s.id
                WHERE DATE_FORMAT(a.applied_date,'%Y-%m') IN ('$curMonth', '$prevMonth')" . $extraFilters;
            $trendResult = $conn->query($trendSql)->fetch_assoc();

            $trends = [
                'totalApps' => ['current' => intval($trendResult['cur_total']), 'previous' => intval($trendResult['prev_total'])],
                'active' => ['current' => intval($trendResult['cur_active']), 'previous' => intval($trendResult['prev_active'])],
                'interview' => ['current' => intval($trendResult['cur_interview']), 'previous' => intval($trendResult['prev_interview'])],
                'offer' => ['current' => intval($trendResult['cur_offer']), 'previous' => intval($trendResult['prev_offer'])]
            ];

            // ---- Pipeline Stage Counts (for bar chart) ----
            $pipelineData = [];
            $pSql = "SELECT s.name, s.color, COUNT(a.id) as cnt
                FROM stages s
                LEFT JOIN applications a ON a.stage_id = s.id" . ($isAdmin ? "" : " AND a.assigned_to = $uid") . ($filterStatus > 0 ? " AND a.status_id = $filterStatus" : "") . "
                WHERE s.stage_type = 'pipeline' AND s.is_active = 1
                GROUP BY s.id, s.name, s.color
                ORDER BY s.display_order ASC";
            $pResult = $conn->query($pSql);
            while ($row = $pResult->fetch_assoc()) {
                $pipelineData[] = $row;
            }

            // ---- Status Distribution (for doughnut chart) ----
            $statusData = [];
            $sSql = "SELECT s.name, s.color, COUNT(a.id) as cnt
                FROM stages s
                LEFT JOIN applications a ON a.status_id = s.id" . ($isAdmin ? "" : " AND a.assigned_to = $uid") . ($filterStage > 0 ? " AND a.stage_id = $filterStage" : "") . "
                WHERE s.stage_type = 'status' AND s.is_active = 1
                GROUP BY s.id, s.name, s.color
                ORDER BY s.display_order ASC";
            $sResult = $conn->query($sSql);
            while ($row = $sResult->fetch_assoc()) {
                $statusData[] = $row;
            }

            // ---- Monthly Applications (for area chart - last 6 months) ----
            $monthlyIndex = [];
            $mSql = "SELECT DATE_FORMAT(a.applied_date, '%Y-%m') as month, COUNT(*) as cnt
                FROM applications a
                WHERE a.applied_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)" . $extraFilters . "
                GROUP BY DATE_FORMAT(a.applied_date, '%Y-%m')
                ORDER BY month ASC";
            $mResult = $conn->query($mSql);
            while ($row = $mResult->fetch_assoc()) {
                $monthlyIndex[$row['month']] = intval($row['cnt']);
            }

            // Fill in missing months using indexed lookup
            $filledMonths = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = date('Y-m', strtotime("-$i months"));
                $filledMonths[] = ['month' => $m, 'cnt' => isset($monthlyIndex[$m]) ? $monthlyIndex[$m] : 0];
            }

            // ---- Company Distribution (for bar chart) ----
            $companyData = [];
            $cSql = "SELECT COALESCE(a.company, 'Unspecified') as company, COUNT(*) as cnt
                FROM applications a WHERE 1=1" . $extraFilters . "
                GROUP BY a.company
                ORDER BY cnt DESC
                LIMIT 6";
            $cResult = $conn->query($cSql);
            while ($row = $cResult->fetch_assoc()) {
                $companyData[] = $row;
            }

            // ---- Upcoming Actions (next 7 days) ----
            $upcomingActions = [];
            $uSql = "SELECT a.id, a.candidate_name, a.position, a.next_action, a.next_action_date,
                s.name as stage_name, s.color as stage_color,
                st.name as status_name
                FROM applications a
                JOIN stages s ON a.stage_id = s.id
                LEFT JOIN stages st ON a.status_id = st.id
                WHERE a.next_action_date IS NOT NULL
                AND a.next_action_date >= CURDATE()
                AND a.next_action_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)" . $extraFilters . "
                ORDER BY a.next_action_date ASC
                LIMIT 10";
            $uResult = $conn->query($uSql);
            while ($row = $uResult->fetch_assoc()) {
                $upcomingActions[] = $row;
            }

            // ---- Recent Activity Logs ----
            $recentLogs = [];
            $lSql = "SELECT l.action, l.module, l.description, l.created_at,
                u.full_name as user_name
                FROM activity_logs l
                LEFT JOIN users u ON l.user_id = u.id" . ($isAdmin ? "" : " WHERE l.user_id = $uid") . "
                ORDER BY l.created_at DESC
                LIMIT 8";
            $lResult = $conn->query($lSql);
            while ($row = $lResult->fetch_assoc()) {
                $recentLogs[] = $row;
            }

            $conn->close();

            echo json_encode([
                'success' => true,
                'data' => [
                    'totalApps' => intval($totalApps),
                    'activeCount' => intval($activeCount),
                    'interviewCount' => intval($interviewCount),
                    'offerCount' => intval($offerCount),
                    'hiredCount' => intval($hiredCount),
                    'avgDays' => $avgDays,
                    'totalUsers' => intval($totalUsers),
                    'totalDocs' => intval($totalDocs),
                    'pipelineData' => $pipelineData,
                    'statusData' => $statusData,
                    'monthlyData' => $filledMonths,
                    'companyData' => $companyData,
                    'upcomingActions' => $upcomingActions,
                    'recentLogs' => $recentLogs,
                    'isAdmin' => $isAdmin,
                    'trends' => $trends
                ]
            ]);
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
    <title>Dashboard - Skyward Airlines</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.7">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        /* ===== Command Center Header ===== */
        .command-header {
            background: var(--bg-card);
            padding: 20px 24px;
            border-radius: 14px;
            margin-bottom: 22px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .command-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
            min-width: 0;
        }
        .command-header-left h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }
        .command-header-left h1 i {
            color: var(--navy-accent);
            font-size: 20px;
        }
        .command-header-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .cmd-search-box {
            position: relative;
        }
        .cmd-search-box input {
            padding: 9px 14px 9px 36px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px !important;
            width: 220px;
            background: var(--input-bg);
            color: var(--text-primary);
            transition: all 0.3s;
        }
        .cmd-search-box input:focus {
            outline: none;
            border-color: var(--navy-accent);
            box-shadow: 0 0 0 3px rgba(0,116,217,0.12);
            width: 260px;
        }
        .cmd-search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 13px;
        }
        .cmd-btn {
            padding: 9px 16px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.2s;
            touch-action: manipulation;
        }
        .cmd-btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border: 1.5px solid var(--border-color);
        }
        .cmd-btn-ghost:hover {
            background: var(--bg-primary);
            color: var(--text-primary);
            border-color: var(--navy-accent);
        }
        .cmd-btn-primary {
            background: var(--navy-accent);
            color: white;
        }
        .cmd-btn-primary:hover {
            background: #005fa3;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,116,217,0.25);
        }

        /* ===== 5-Card KPI Row (Modern Redesign) ===== */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
            margin-bottom: 22px;
        }
        .kpi-card {
            background: var(--bg-card);
            padding: 20px 20px 14px;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(0,0,0,0.1);
        }
        .kpi-card-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }
        .kpi-icon-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            flex-shrink: 0;
        }
        .kpi-info {
            flex: 1;
            min-width: 0;
        }
        .kpi-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            display: block;
            margin-bottom: 4px;
        }
        .kpi-value {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .kpi-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            border-top: 1px solid rgba(0,0,0,0.05);
            padding-top: 10px;
        }
        .kpi-trend {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }
        .kpi-trend-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        .kpi-trend-badge.trend-up {
            background: rgba(52,168,83,0.1);
            color: #34a853;
        }
        .kpi-trend-badge.trend-down {
            background: rgba(234,67,53,0.1);
            color: #ea4335;
        }
        .kpi-trend-badge.trend-neutral {
            background: rgba(107,114,128,0.1);
            color: #6B7280;
        }
        .kpi-trend-sub {
            font-size: 11px;
            color: var(--text-muted);
        }
        .kpi-sparkline {
            flex: 1;
            max-width: 90px;
            height: 40px;
            opacity: 0.85;
        }
        .kpi-sparkline canvas {
            width: 100% !important;
            height: 100% !important;
        }

        /* KPI Color Variants */
        .kpi-total .kpi-icon-circle { background: linear-gradient(135deg, #0074D9, #005fa3); }
        .kpi-active .kpi-icon-circle { background: linear-gradient(135deg, #34a853, #2d8f47); }
        .kpi-interview .kpi-icon-circle { background: linear-gradient(135deg, #F59E0B, #d48806); }
        .kpi-offer .kpi-icon-circle { background: linear-gradient(135deg, #8B5CF6, #6D28D9); }
        .kpi-avgdays .kpi-icon-circle { background: linear-gradient(135deg, #10B981, #059669); }

        /* Subtle background tint per card */
        .kpi-total { background: linear-gradient(135deg, var(--bg-card) 60%, rgba(0,116,217,0.04) 100%); }
        .kpi-active { background: linear-gradient(135deg, var(--bg-card) 60%, rgba(52,168,83,0.04) 100%); }
        .kpi-interview { background: linear-gradient(135deg, var(--bg-card) 60%, rgba(245,158,11,0.04) 100%); }
        .kpi-offer { background: linear-gradient(135deg, var(--bg-card) 60%, rgba(139,92,246,0.04) 100%); }
        .kpi-avgdays { background: linear-gradient(135deg, var(--bg-card) 60%, rgba(16,185,129,0.04) 100%); }

        /* ===== Filter Bar ===== */
        .filter-bar {
            background: var(--bg-card);
            padding: 14px 20px;
            border-radius: 14px;
            margin-bottom: 22px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .filter-bar-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .filter-bar-label i {
            color: var(--navy-accent);
        }
        .filter-bar select {
            padding: 8px 32px 8px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px !important;
            background: var(--input-bg);
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.2s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23666'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            min-width: 150px;
        }
        .filter-bar select:focus {
            outline: none;
            border-color: var(--navy-accent);
            box-shadow: 0 0 0 3px rgba(0,116,217,0.12);
        }
        .filter-bar-clear {
            padding: 8px 14px;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            background: transparent;
            color: var(--danger);
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-left: auto;
        }
        .filter-bar-clear:hover {
            background: rgba(234,67,53,0.08);
        }
        .filter-active-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(0,116,217,0.1);
            color: var(--navy-accent);
        }

        /* ===== Secondary Stats Row ===== */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 22px;
        }
        .stat-mini {
            background: var(--bg-card);
            padding: 18px 22px;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.3s;
        }
        .stat-mini:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }
        .stat-mini-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: white;
            flex-shrink: 0;
        }
        .stat-mini-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.1;
        }
        .stat-mini-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* ===== Charts Grid ===== */
        .charts-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 18px;
            margin-bottom: 22px;
        }
        .charts-grid-even {
            grid-template-columns: 1fr 1fr;
        }
        .chart-card {
            background: var(--bg-card);
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.06);
        }
        .chart-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .chart-card-title {
            color: var(--text-primary);
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-card-title i {
            color: var(--navy-accent);
            font-size: 14px;
        }
        .chart-container {
            position: relative;
            height: 280px;
        }

        /* ===== Bottom Grid: Upcoming + Recent ===== */
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 22px;
        }
        .info-card {
            background: var(--bg-card);
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.06);
        }
        .info-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-light);
        }
        .info-card-title {
            color: var(--text-primary);
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-card-title i {
            color: var(--navy-accent);
            font-size: 14px;
        }
        .info-card-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            background: rgba(0,116,217,0.1);
            color: var(--navy-accent);
        }

        /* ===== Upcoming Actions List ===== */
        .upcoming-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 340px;
            overflow-y: auto;
        }
        .upcoming-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 11px 8px;
            border-bottom: 1px solid var(--border-light);
            border-radius: 8px;
            transition: background 0.2s;
        }
        .upcoming-item:hover { background: rgba(0,116,217,0.03); }
        .upcoming-item:last-child { border-bottom: none; }
        .upcoming-date {
            flex-shrink: 0;
            width: 48px;
            text-align: center;
            background: linear-gradient(135deg, var(--navy-accent), #005fa3);
            color: white;
            border-radius: 10px;
            padding: 6px 4px;
            line-height: 1.2;
        }
        .upcoming-date .date-day {
            font-size: 17px;
            font-weight: 700;
            display: block;
        }
        .upcoming-date .date-month {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.9;
        }
        .upcoming-info {
            flex: 1;
            min-width: 0;
        }
        .upcoming-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .upcoming-action {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .upcoming-stage-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            color: white;
            margin-top: 4px;
        }
        .upcoming-empty, .activity-empty {
            text-align: center;
            padding: 30px 10px;
            color: var(--text-muted);
            font-size: 13px;
        }
        .upcoming-empty i, .activity-empty i {
            font-size: 28px;
            display: block;
            margin-bottom: 8px;
            opacity: 0.4;
        }

        /* ===== Activity List ===== */
        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 340px;
            overflow-y: auto;
        }
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 8px;
            border-bottom: 1px solid var(--border-light);
            border-radius: 8px;
            transition: background 0.2s;
        }
        .activity-item:hover { background: rgba(0,116,217,0.03); }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 13px;
            flex-shrink: 0;
        }
        .activity-icon.act-login { background: linear-gradient(135deg, #34a853, #2d8f47); }
        .activity-icon.act-logout { background: linear-gradient(135deg, #6B7280, #4B5563); }
        .activity-icon.act-create { background: linear-gradient(135deg, #0074D9, #005fa3); }
        .activity-icon.act-update { background: linear-gradient(135deg, #F59E0B, #d48806); }
        .activity-icon.act-delete { background: linear-gradient(135deg, #ea4335, #c5221f); }
        .activity-icon.act-default { background: linear-gradient(135deg, #8B5CF6, #6D28D9); }
        .activity-info {
            flex: 1;
            min-width: 0;
        }
        .activity-desc {
            font-size: 13px;
            color: var(--text-primary);
            line-height: 1.4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .activity-desc strong { color: var(--navy-accent); }
        .activity-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* ===== Skeleton Loaders ===== */
        .skeleton-row-5 {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
            margin-bottom: 22px;
        }
        .skeleton-card-box {
            background: var(--bg-card);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }
        .skeleton-bar {
            height: 42px;
            border-radius: 14px;
            margin-bottom: 22px;
        }

        /* ===== Dark Mode overrides ===== */
        body.dark-mode .kpi-card,
        body.dark-mode .stat-mini,
        body.dark-mode .chart-card,
        body.dark-mode .info-card,
        body.dark-mode .command-header,
        body.dark-mode .filter-bar {
            background: var(--bg-card);
            border-color: var(--border-color);
        }
        body.dark-mode .kpi-card {
            background: var(--bg-card) !important;
            border-color: rgba(255,255,255,0.06);
        }
        body.dark-mode .kpi-card:hover {
            box-shadow: 0 8px 28px rgba(0,0,0,0.4);
        }
        body.dark-mode .kpi-bottom {
            border-top-color: rgba(255,255,255,0.08);
        }
        body.dark-mode .filter-bar select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23888'/%3E%3C/svg%3E");
        }
        body.dark-mode .skeleton-card-box {
            background: var(--bg-card);
        }
        body.dark-mode .upcoming-item:hover,
        body.dark-mode .activity-item:hover {
            background: rgba(255,255,255,0.03);
        }

        /* ===== Mobile Responsive ===== */
        @media (max-width: 1400px) {
            .kpi-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 1024px) {
            .kpi-row { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .kpi-row { grid-template-columns: 1fr 1fr; }
            .bottom-grid { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: 1fr; }
            .command-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .command-header-right {
                width: 100%;
                flex-wrap: wrap;
            }
            .cmd-search-box { width: 100%; }
            .cmd-search-box input { width: 100%; }
            .cmd-search-box input:focus { width: 100%; }
            .filter-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            .filter-bar select { width: 100%; min-width: unset; }
            .filter-bar-clear { margin-left: 0; width: 100%; justify-content: center; }
            .skeleton-row-5 { grid-template-columns: 1fr 1fr; }
            .kpi-sparkline { max-width: 70px; }
        }
        @media (max-width: 480px) {
            .kpi-row { grid-template-columns: 1fr; }
            .skeleton-row-5 { grid-template-columns: 1fr; }
            .command-header-right { flex-direction: column; }
            .cmd-btn { width: 100%; justify-content: center; }
            .kpi-sparkline { max-width: 80px; }
        }
    </style>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">

            <!-- Command Bar Header -->
            <div class="command-header">
                <div class="command-header-left">
                    <h1>Dashboard</h1>
                </div>
                <div class="command-header-right">
                    <div class="cmd-search-box">
                        <i class="ri-search-line"></i>
                        <input type="text" id="globalSearch" placeholder="Search candidates..." autocomplete="off">
                    </div>
                    <button class="cmd-btn cmd-btn-ghost" onclick="refreshDashboard()" title="Refresh Data">
                        <i class="ri-refresh-line" id="refreshIcon"></i> Refresh
                    </button>
                    <button class="cmd-btn cmd-btn-primary" onclick="window.location.href='applications.php'" title="New Application">
                        <i class="ri-add-line"></i> New Application
                    </button>
                </div>
            </div>

            <!-- Skeleton Loader -->
            <div id="dashboardSkeleton">
                <div class="skeleton-row-5">
                    <?php for ($i = 0; $i < 5; $i++): ?>
                    <div class="skeleton-card-box">
                        <div class="skeleton" style="width:60%;height:12px;border-radius:3px;margin-bottom:12px;"></div>
                        <div class="skeleton" style="width:40%;height:30px;border-radius:3px;margin-bottom:8px;"></div>
                        <div class="skeleton" style="width:50%;height:10px;border-radius:3px;"></div>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="skeleton-card-box skeleton-bar"></div>
                <div class="charts-grid charts-grid-even">
                    <div class="skeleton-card-box"><div class="skeleton" style="height:280px;border-radius:6px;"></div></div>
                    <div class="skeleton-card-box"><div class="skeleton" style="height:280px;border-radius:6px;"></div></div>
                </div>
            </div>

            <!-- Dashboard Content (hidden until loaded) -->
            <div id="dashboardContent" style="display:none;">

                <!-- 5 KPI Cards -->
                <div class="kpi-row">
                    <div class="kpi-card kpi-total">
                        <div class="kpi-card-top">
                            <div class="kpi-icon-circle"><i class="ri-stack-line"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-label">Total Applications</span>
                                <div class="kpi-value" id="kpiTotalApps">0</div>
                            </div>
                        </div>
                        <div class="kpi-bottom">
                            <div class="kpi-trend" id="kpiTrendTotal"></div>
                            <div class="kpi-sparkline"><canvas id="sparkTotal" height="40"></canvas></div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-active">
                        <div class="kpi-card-top">
                            <div class="kpi-icon-circle"><i class="ri-user-follow-line"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-label">Active Candidates</span>
                                <div class="kpi-value" id="kpiActive">0</div>
                            </div>
                        </div>
                        <div class="kpi-bottom">
                            <div class="kpi-trend" id="kpiTrendActive"></div>
                            <div class="kpi-sparkline"><canvas id="sparkActive" height="40"></canvas></div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-interview">
                        <div class="kpi-card-top">
                            <div class="kpi-icon-circle"><i class="ri-chat-3-line"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-label">Interviews</span>
                                <div class="kpi-value" id="kpiInterview">0</div>
                            </div>
                        </div>
                        <div class="kpi-bottom">
                            <div class="kpi-trend" id="kpiTrendInterview"></div>
                            <div class="kpi-sparkline"><canvas id="sparkInterview" height="40"></canvas></div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-offer">
                        <div class="kpi-card-top">
                            <div class="kpi-icon-circle"><i class="ri-hand-coin-line"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-label">Offers Pending</span>
                                <div class="kpi-value" id="kpiOffer">0</div>
                            </div>
                        </div>
                        <div class="kpi-bottom">
                            <div class="kpi-trend" id="kpiTrendOffer"></div>
                            <div class="kpi-sparkline"><canvas id="sparkOffer" height="40"></canvas></div>
                        </div>
                    </div>
                    <div class="kpi-card kpi-avgdays">
                        <div class="kpi-card-top">
                            <div class="kpi-icon-circle"><i class="ri-hourglass-line"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-label">Avg Days to Join</span>
                                <div class="kpi-value" id="kpiAvgDays">—</div>
                            </div>
                        </div>
                        <div class="kpi-bottom">
                            <div class="kpi-trend"><span class="kpi-trend-sub">From application to hire</span></div>
                            <div class="kpi-sparkline"><canvas id="sparkAvg" height="40"></canvas></div>
                        </div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar" id="filterBar">
                    <span class="filter-bar-label"><i class="ri-filter-line"></i> Filter</span>
                    <select id="filterStage">
                        <option value="">All Stages</option>
                    </select>
                    <select id="filterStatus">
                        <option value="">All Statuses</option>
                    </select>
                    <span id="filterActiveTags"></span>
                    <button class="filter-bar-clear" id="filterClearBtn" style="display:none;" onclick="clearFilters()">
                        <i class="ri-close-circle-line"></i> Clear Filters
                    </button>
                </div>

                <!-- Secondary Stats Row -->
                <div class="stats-row">
                    <div class="stat-mini">
                        <div class="stat-mini-icon" style="background:linear-gradient(135deg,#10B981,#059669);">
                            <i class="ri-briefcase-line"></i>
                        </div>
                        <div>
                            <div class="stat-mini-value" id="kpiHired">0</div>
                            <div class="stat-mini-label">Hired</div>
                        </div>
                    </div>
                    <div class="stat-mini" id="statUsersCard">
                        <div class="stat-mini-icon" style="background:linear-gradient(135deg,var(--navy-accent),#005fa3);">
                            <i class="ri-group-line"></i>
                        </div>
                        <div>
                            <div class="stat-mini-value" id="kpiUsers">0</div>
                            <div class="stat-mini-label">Total Users</div>
                        </div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-mini-icon" style="background:linear-gradient(135deg,#F59E0B,#d48806);">
                            <i class="ri-attachment-line"></i>
                        </div>
                        <div>
                            <div class="stat-mini-value" id="kpiDocs">0</div>
                            <div class="stat-mini-label">Documents</div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row 1: Pipeline + Status -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <span class="chart-card-title"><i class="ri-list-unordered"></i> Pipeline Overview</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="pipelineChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <span class="chart-card-title"><i class="ri-pie-chart-line"></i> Status Distribution</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Charts Row 2: Monthly + Company -->
                <div class="charts-grid charts-grid-even">
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <span class="chart-card-title"><i class="ri-area-chart-line"></i> Monthly Applications</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <span class="chart-card-title"><i class="ri-building-line"></i> Top Companies</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="companyChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Bottom Row: Upcoming Actions + Recent Activity -->
                <div class="bottom-grid">
                    <div class="info-card">
                        <div class="info-card-header">
                            <span class="info-card-title"><i class="ri-calendar-line"></i> Upcoming Actions</span>
                            <span class="info-card-badge">Next 7 days</span>
                        </div>
                        <ul class="upcoming-list" id="upcomingList"></ul>
                    </div>
                    <div class="info-card">
                        <div class="info-card-header">
                            <span class="info-card-title"><i class="ri-history-line"></i> Recent Activity</span>
                            <span class="info-card-badge">Latest</span>
                        </div>
                        <ul class="activity-list" id="activityList"></ul>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <script>
    var userRole = '<?php echo $role; ?>';
    let chartInstances = {};
    let currentFilters = { stage_id: '', status_id: '' };

    // ---- Load Filter Options ----
    function loadFilterOptions() {
        $.ajax({
            url: '?action=getFilterOptions',
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    var stageSelect = $('#filterStage');
                    res.pipelineStages.forEach(function(s) {
                        stageSelect.append('<option value="' + s.id + '">' + escapeHtml(s.name) + '</option>');
                    });

                    var statusSelect = $('#filterStatus');
                    res.statusStages.forEach(function(s) {
                        statusSelect.append('<option value="' + s.id + '">' + escapeHtml(s.name) + '</option>');
                    });
                }
            }
        });
    }

    // ---- Filter Change Handlers ----
    $(document).on('change', '#filterStage, #filterStatus', function() {
        currentFilters.stage_id = $('#filterStage').val();
        currentFilters.status_id = $('#filterStatus').val();
        updateFilterTags();
        loadDashboard();
    });

    function clearFilters() {
        $('#filterStage').val('');
        $('#filterStatus').val('');
        currentFilters = { stage_id: '', status_id: '' };
        updateFilterTags();
        loadDashboard();
    }

    function updateFilterTags() {
        var tags = '';
        var hasFilter = false;
        if (currentFilters.stage_id) {
            var txt = $('#filterStage option:selected').text();
            tags += '<span class="filter-active-tag"><i class="ri-layout-column-line"></i> ' + escapeHtml(txt) + '</span> ';
            hasFilter = true;
        }
        if (currentFilters.status_id) {
            var txt = $('#filterStatus option:selected').text();
            tags += '<span class="filter-active-tag"><i class="ri-checkbox-circle-line"></i> ' + escapeHtml(txt) + '</span> ';
            hasFilter = true;
        }
        $('#filterActiveTags').html(tags);
        $('#filterClearBtn').toggle(hasFilter);
    }

    // ---- Global Search ----
    var searchTimer;
    $(document).on('input', '#globalSearch', function() {
        var query = $(this).val().trim().toLowerCase();
        clearTimeout(searchTimer);
        if (query.length >= 2) {
            searchTimer = setTimeout(function() {
                window.location.href = 'applications.php?search=' + encodeURIComponent(query);
            }, 600);
        }
    });

    // ---- Load Dashboard Data ----
    function loadDashboard() {
        var params = '?action=getDashboardStats';
        if (currentFilters.stage_id) params += '&stage_id=' + currentFilters.stage_id;
        if (currentFilters.status_id) params += '&status_id=' + currentFilters.status_id;

        $.ajax({
            url: params,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const d = response.data;

                    // KPI values
                    animateValue('kpiTotalApps', d.totalApps);
                    animateValue('kpiActive', d.activeCount);
                    animateValue('kpiInterview', d.interviewCount);
                    animateValue('kpiOffer', d.offerCount);
                    $('#kpiAvgDays').text(d.avgDays > 0 ? d.avgDays + 'd' : '—');

                    // Secondary stats
                    animateValue('kpiHired', d.hiredCount);
                    animateValue('kpiUsers', d.totalUsers);
                    animateValue('kpiDocs', d.totalDocs);

                    if (!d.isAdmin) {
                        $('#statUsersCard').hide();
                    }

                    // Show content
                    $('#dashboardSkeleton').hide();
                    $('#dashboardContent').show();

                    // Trends
                    renderTrendBadges(d.trends);

                    // Charts
                    setTimeout(function() {
                        initPipelineChart(d.pipelineData);
                        initStatusChart(d.statusData);
                        initMonthlyChart(d.monthlyData);
                        initCompanyChart(d.companyData);
                        initKpiSparklines(d.monthlyData);
                    }, 80);

                    // Lists
                    renderUpcomingActions(d.upcomingActions);
                    renderRecentActivity(d.recentLogs);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load dashboard data' });
            }
        });
    }

    // ---- Refresh Dashboard ----
    function refreshDashboard() {
        var icon = $('#refreshIcon');
        icon.addClass('ri-spin');
        loadDashboard();
        setTimeout(function() { icon.removeClass('ri-spin'); }, 1000);
    }

    // ---- Animated Counter ----
    function animateValue(id, target) {
        var el = document.getElementById(id);
        if (!el) return;
        var current = parseInt(el.textContent) || 0;
        if (current === target) { el.textContent = target; return; }
        var step = Math.ceil(Math.abs(target - current) / 20);
        var timer = setInterval(function() {
            current += (target > current) ? step : -step;
            if ((target > current - step && target < current + step) || step === 0) {
                current = target;
                clearInterval(timer);
            }
            el.textContent = current;
        }, 30);
    }

    // ---- Trend Badges ----
    function renderTrendBadges(trends) {
        if (!trends) return;
        var map = {
            'totalApps': 'kpiTrendTotal',
            'active': 'kpiTrendActive',
            'interview': 'kpiTrendInterview',
            'offer': 'kpiTrendOffer'
        };
        Object.keys(map).forEach(function(key) {
            var el = document.getElementById(map[key]);
            if (!el || !trends[key]) return;
            var cur = trends[key].current;
            var prev = trends[key].previous;
            var pct = prev > 0 ? Math.round(((cur - prev) / prev) * 100) : (cur > 0 ? 100 : 0);
            var cls = pct > 0 ? 'trend-up' : (pct < 0 ? 'trend-down' : 'trend-neutral');
            var icon = pct > 0 ? 'ri-arrow-up-line' : (pct < 0 ? 'ri-arrow-down-line' : 'ri-subtract-line');
            var label = pct === 0 ? 'No change' : (Math.abs(pct) + '%');
            el.innerHTML = '<span class="kpi-trend-badge ' + cls + '"><i class="' + icon + '"></i> ' + label + '</span><span class="kpi-trend-sub">vs last month</span>';
        });
    }

    // ---- KPI Sparklines ----
    let sparkInstances = {};
    function initKpiSparklines(monthlyData) {
        var counts = monthlyData.map(function(d) { return d.cnt; });
        var sparkConfigs = [
            { id: 'sparkTotal', color: '#0074D9' },
            { id: 'sparkActive', color: '#34a853' },
            { id: 'sparkInterview', color: '#F59E0B' },
            { id: 'sparkOffer', color: '#8B5CF6' },
            { id: 'sparkAvg', color: '#10B981' }
        ];

        sparkConfigs.forEach(function(cfg) {
            if (sparkInstances[cfg.id]) sparkInstances[cfg.id].destroy();
            var canvas = document.getElementById(cfg.id);
            if (!canvas) return;

            var ctx = canvas.getContext('2d');
            var grad = ctx.createLinearGradient(0, 0, 0, 40);
            grad.addColorStop(0, cfg.color + '40');
            grad.addColorStop(1, cfg.color + '05');

            sparkInstances[cfg.id] = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: counts.map(function(_, i) { return ''; }),
                    datasets: [{
                        data: counts,
                        borderColor: cfg.color,
                        backgroundColor: grad,
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: {
                        x: { display: false },
                        y: { display: false }
                    },
                    elements: { line: { borderCapStyle: 'round' } },
                    layout: { padding: 0 }
                }
            });
        });
    }

    // ---- Doughnut Center Text Plugin ----
    var doughnutCenterPlugin = {
        id: 'doughnutCenter',
        afterDraw: function(chart) {
            if (chart.config.type !== 'doughnut') return;
            var ctx = chart.ctx;
            var width = chart.width;
            var height = chart.height;
            ctx.save();
            var total = chart.data.datasets[0].data.reduce(function(a, b) { return a + b; }, 0);
            var isDark = document.body.classList.contains('dark-mode');
            ctx.font = '700 24px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            ctx.fillStyle = isDark ? '#e8eaf6' : '#1a1a1a';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            ctx.fillText(total, width / 2, height / 2 + 4);
            ctx.font = '500 11px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            ctx.fillStyle = isDark ? '#999' : '#888';
            ctx.textBaseline = 'top';
            ctx.fillText('Total', width / 2, height / 2 + 6);
            ctx.restore();
        }
    };

    // ---- Pipeline Bar Chart ----
    function initPipelineChart(data) {
        if (chartInstances.pipeline) chartInstances.pipeline.destroy();
        var ctx = document.getElementById('pipelineChart');
        if (!ctx) return;

        var isDark = document.body.classList.contains('dark-mode');
        var tickColor = isDark ? '#999' : '#666';

        chartInstances.pipeline = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(function(d) { return d.name; }),
                datasets: [{
                    label: 'Applications',
                    data: data.map(function(d) { return parseInt(d.cnt); }),
                    backgroundColor: data.map(function(d) { return d.color + 'CC'; }),
                    borderColor: data.map(function(d) { return d.color; }),
                    borderWidth: 2,
                    borderRadius: Number.MAX_VALUE,
                    borderSkipped: false,
                    barPercentage: 0.6,
                    categoryPercentage: 0.7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, title: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, color: tickColor }, grid: { color: 'rgba(128,128,128,0.08)' } },
                    x: { grid: { display: false }, ticks: { color: tickColor, maxRotation: 45, minRotation: 0 } }
                }
            }
        });
    }

    // ---- Status Doughnut Chart ----
    function initStatusChart(data) {
        if (chartInstances.status) chartInstances.status.destroy();
        var ctx = document.getElementById('statusChart');
        if (!ctx) return;

        var isDark = document.body.classList.contains('dark-mode');

        chartInstances.status = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(function(d) { return d.name; }),
                datasets: [{
                    data: data.map(function(d) { return parseInt(d.cnt); }),
                    backgroundColor: data.map(function(d) { return d.color; }),
                    borderColor: isDark ? 'rgba(30,30,46,0.95)' : '#fff',
                    borderWidth: 3,
                    hoverOffset: 12
                }]
            },
            plugins: [doughnutCenterPlugin],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                animation: { animateRotate: true },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 14,
                            usePointStyle: true,
                            font: { size: 12 },
                            color: isDark ? '#ccc' : '#555'
                        }
                    }
                }
            }
        });
    }

    // ---- Monthly Area Chart ----
    function initMonthlyChart(data) {
        if (chartInstances.monthly) chartInstances.monthly.destroy();
        var ctx = document.getElementById('monthlyChart');
        if (!ctx) return;

        var isDark = document.body.classList.contains('dark-mode');
        var grad = ctx.getContext('2d').createLinearGradient(0, 0, 0, 280);
        grad.addColorStop(0, 'rgba(0, 116, 217, 0.45)');
        grad.addColorStop(1, 'rgba(0, 116, 217, 0.02)');

        var labels = data.map(function(d) {
            var p = d.month.split('-');
            return new Date(p[0], p[1] - 1).toLocaleString('en-US', { month: 'short', year: '2-digit' });
        });

        chartInstances.monthly = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Applications',
                    data: data.map(function(d) { return d.cnt; }),
                    borderColor: '#0074D9',
                    backgroundColor: grad,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#0074D9',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, color: isDark ? '#999' : '#666' }, grid: { color: 'rgba(128,128,128,0.08)' } },
                    x: { grid: { display: false }, ticks: { color: isDark ? '#999' : '#666' } }
                },
                interaction: { intersect: false, mode: 'index' }
            }
        });
    }

    // ---- Company Horizontal Bar Chart ----
    function initCompanyChart(data) {
        if (chartInstances.company) chartInstances.company.destroy();
        var ctx = document.getElementById('companyChart');
        if (!ctx) return;

        var isDark = document.body.classList.contains('dark-mode');
        var colors = ['#001f3f', '#0074D9', '#34a853', '#F59E0B', '#8B5CF6', '#ea4335'];

        chartInstances.company = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(function(d) { return d.company; }),
                datasets: [{
                    label: 'Applications',
                    data: data.map(function(d) { return parseInt(d.cnt); }),
                    backgroundColor: data.map(function(_, i) { return colors[i % colors.length] + 'CC'; }),
                    borderColor: data.map(function(_, i) { return colors[i % colors.length]; }),
                    borderWidth: 2,
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { stepSize: 1, color: isDark ? '#999' : '#666' }, grid: { color: 'rgba(128,128,128,0.12)' } },
                    y: { grid: { display: false }, ticks: { color: isDark ? '#999' : '#666' } }
                }
            }
        });
    }

    // ---- Render Upcoming Actions ----
    function renderUpcomingActions(actions) {
        var list = $('#upcomingList');
        list.empty();

        if (!actions || actions.length === 0) {
            list.html('<li class="upcoming-empty"><i class="ri-calendar-check-line"></i>No upcoming actions in the next 7 days</li>');
            return;
        }

        actions.forEach(function(item) {
            var date = new Date(item.next_action_date);
            var day = date.getDate();
            var month = date.toLocaleString('en-US', { month: 'short' });

            list.append(
                '<li class="upcoming-item">' +
                    '<div class="upcoming-date">' +
                        '<span class="date-day">' + day + '</span>' +
                        '<span class="date-month">' + month + '</span>' +
                    '</div>' +
                    '<div class="upcoming-info">' +
                        '<div class="upcoming-name">' + escapeHtml(item.candidate_name) + ' — ' + escapeHtml(item.position) + '</div>' +
                        '<div class="upcoming-action">' + escapeHtml(item.next_action || 'No action specified') + '</div>' +
                        '<span class="upcoming-stage-badge" style="background:' + item.stage_color + ';">' + escapeHtml(item.stage_name) + '</span>' +
                    '</div>' +
                '</li>'
            );
        });
    }

    // ---- Render Recent Activity ----
    function renderRecentActivity(logs) {
        var list = $('#activityList');
        list.empty();

        if (!logs || logs.length === 0) {
            list.html('<li class="activity-empty"><i class="ri-history-line"></i>No recent activity</li>');
            return;
        }

        var actionIcons = {
            'LOGIN': { icon: 'ri-login-box-line', cls: 'act-login' },
            'LOGOUT': { icon: 'ri-logout-box-line', cls: 'act-logout' },
            'CREATE': { icon: 'ri-add-line', cls: 'act-create' },
            'UPDATE': { icon: 'ri-edit-line', cls: 'act-update' },
            'DELETE': { icon: 'ri-delete-bin-line', cls: 'act-delete' }
        };

        logs.forEach(function(log) {
            var ai = actionIcons[log.action] || { icon: 'ri-circle-line', cls: 'act-default' };
            var timeAgo = getTimeAgo(log.created_at);

            list.append(
                '<li class="activity-item">' +
                    '<div class="activity-icon ' + ai.cls + '"><i class="' + ai.icon + '"></i></div>' +
                    '<div class="activity-info">' +
                        '<div class="activity-desc"><strong>' + escapeHtml(log.user_name || 'System') + '</strong> — ' + escapeHtml(log.description || log.action) + '</div>' +
                        '<div class="activity-meta">' + escapeHtml(log.module || '') + ' · ' + timeAgo + '</div>' +
                    '</div>' +
                '</li>'
            );
        });
    }

    // ---- Utility Functions ----
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function getTimeAgo(dateStr) {
        var date = new Date(dateStr);
        var now = new Date();
        var seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        if (seconds < 604800) return Math.floor(seconds / 86400) + 'd ago';
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    // ---- Init ----
    $(document).ready(function() {
        loadFilterOptions();
        loadDashboard();
    });
    </script>
</body>
</html>
