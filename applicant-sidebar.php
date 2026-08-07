<?php
/**
 * Shared Applicant Sidebar Component
 * Include this file in all applicant-portal pages
 *
 * Required variables before including:
 * - $applicant_name: Current logged-in applicant's name
 * - $current_page: Current page identifier (applicant-dashboard/applicant-application)
 */

if (!isset($applicant_name) || !isset($current_page)) {
    die('applicant-sidebar.php requires $applicant_name and $current_page variables');
}

$default_logo = "https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg";
$image_src = getSetting('login_logo', '') ?: $default_logo;
?>
<!-- Applicant Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">
            <span class="sidebar-title-text">Skyward Airlines</span>
        </div>
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="ri-arrow-left-s-line" id="sidebarToggleIcon"></i>
        </button>
    </div>
    <div class="sidebar-logo-section">
        <img src="<?php echo htmlspecialchars($image_src); ?>" alt="Logo" class="sidebar-logo" onerror="this.src='<?php echo $default_logo; ?>'">
    </div>
    <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Navigation</div>
        <ul class="sidebar-menu">
            <li data-tooltip="My Applications">
                <a href="applicant-dashboard.php" class="<?php echo $current_page === 'applicant-dashboard' ? 'active' : ''; ?>">
                    <i class="ri-file-text-line"></i>
                    <span>My Applications</span>
                </a>
            </li>

            <li data-tooltip="Browse Openings">
                <a href="careers.php" class="<?php echo $current_page === 'careers' ? 'active' : ''; ?>">
                    <i class="ri-briefcase-line"></i>
                    <span>Browse Openings</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="sidebar-theme">
        <div class="mode-toggle-group" role="group" aria-label="Theme mode">
            <button type="button" class="mode-toggle-btn" data-mode="system" title="System" onclick="setThemeMode('system')">
                <i class="ri-computer-line"></i>
            </button>
            <button type="button" class="mode-toggle-btn" data-mode="light" title="Light" onclick="setThemeMode('light')">
                <i class="ri-sun-line"></i>
            </button>
            <button type="button" class="mode-toggle-btn" data-mode="dark" title="Dark" onclick="setThemeMode('dark')">
                <i class="ri-moon-line"></i>
            </button>
        </div>
    </div>
    <div class="sidebar-logout">
        <button onclick="window.location.href='applicant-logout.php'">
            <i class="ri-logout-box-line"></i>
            <span>Logout</span>
        </button>
    </div>
</div>

<!-- Theme Toggle JavaScript -->
<script>
function getActiveMode() {
    return localStorage.getItem('themeMode') || null;
}

function applyMode(mode) {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = mode === 'dark' || (mode === 'system' && prefersDark);

    document.body.classList.toggle('dark-mode', isDark);

    document.querySelectorAll('.mode-toggle-btn').forEach(function(btn) {
        btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
    });

    localStorage.setItem('theme', isDark ? 'dark' : 'light');
}

function setThemeMode(mode) {
    localStorage.setItem('themeMode', mode);
    applyMode(mode);
}

function initTheme() {
    const savedMode = localStorage.getItem('themeMode');
    applyMode(savedMode || 'system');
    if (!savedMode) localStorage.setItem('themeMode', 'system');
}

initTheme();

function initSidebar() {
    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true') {
        document.getElementById('sidebar').classList.add('collapsed');
        updateSidebarIcon(true);
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    updateSidebarIcon(isCollapsed);
}

function updateSidebarIcon(isCollapsed) {
    const icon = document.getElementById('sidebarToggleIcon');
    if (icon) {
        icon.className = isCollapsed ? 'ri-arrow-right-s-line' : 'ri-arrow-left-s-line';
    }
}

initSidebar();

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
    if (getActiveMode() === 'system') {
        applyMode('system');
    }
});
</script>
