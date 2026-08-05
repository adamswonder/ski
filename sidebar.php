<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Shared Sidebar Component
 * Include this file in all dashboard pages
 *
 * Required variables before including:
 * - $username: Current logged-in username
 * - $role: Current user role (Admin/User)
 * - $current_page: Current page identifier (dashboard/users/logs/account/settings)
 * - $user_id: Current user ID
 */

if (!isset($username) || !isset($role) || !isset($current_page) || !isset($user_id)) {
    die('Sidebar requires $username, $role, $current_page, and $user_id variables');
}

// Get user's profile image, fallback to default logo
$profile_image = getProfileImage($user_id);
$default_logo = "https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg";
$image_src = $profile_image ? $profile_image : $default_logo;

// Check if any submenu item is active (for My Account parent active state)
$account_submenu_active = in_array($current_page, ['account', 'settings', 'logs']);

// Get user's custom theme
$user_theme = getUserTheme($user_id);

// Output custom theme CSS
echo generateUserThemeCSS($user_id);
?>
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">
            <i class="fas fa-tachometer-alt"></i>
            <span class="sidebar-title-text">Dashboard</span>
        </div>
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
        </button>
    </div>
    <div class="sidebar-logo-section">
        <img src="<?php echo htmlspecialchars($image_src); ?>" alt="Profile" class="sidebar-logo" onerror="this.src='<?php echo $default_logo; ?>'">
    </div>
    <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Navigation</div>
        <ul class="sidebar-menu">
            <!-- Dashboard -->
            <li data-tooltip="Dashboard">
                <a href="dashboard.php" class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- Applications (All users) -->
            <li data-tooltip="Applications">
                <a href="applications.php" class="<?php echo $current_page === 'applications' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i>
                    <span>Applications</span>
                </a>
            </li>

            <!-- Pipeline / Kanban (All users) -->
            <li data-tooltip="Pipeline">
                <a href="pipeline.php" class="<?php echo $current_page === 'pipeline' ? 'active' : ''; ?>">
                    <i class="fas fa-columns"></i>
                    <span>Pipeline</span>
                </a>
            </li>

            <!-- Calendar (All users) -->
            <li data-tooltip="Calendar">
                <a href="calendar.php" class="<?php echo $current_page === 'calendar' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Calendar</span>
                </a>
            </li>

            <?php if ($role === 'admin'): ?>
            <!-- Stages -->
            <li data-tooltip="Stages">
                <a href="stages.php" class="<?php echo $current_page === 'stages' ? 'active' : ''; ?>">
                    <i class="fas fa-stream"></i>
                    <span>Stages</span>
                </a>
            </li>

            <!-- Users -->
            <li data-tooltip="Users">
                <a href="users.php" class="<?php echo $current_page === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- My Account (Parent with Submenu) -->
            <li class="has-submenu" data-tooltip="My Account">
                <a href="#" class="submenu-toggle <?php echo $account_submenu_active ? 'active' : ''; ?>" data-submenu="account-submenu">
                    <i class="fas fa-user-circle"></i>
                    <span>My Account</span>
                    <i class="fas fa-chevron-down submenu-arrow"></i>
                </a>

                <!-- Submenu -->
                <ul class="sidebar-submenu" id="account-submenu">
                    <li data-tooltip="My Profile">
                        <a href="account.php" class="<?php echo $current_page === 'account' ? 'active' : ''; ?>">
                            <i class="fas fa-user"></i>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <li data-tooltip="Settings">
                        <a href="settings.php" class="<?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                    </li>
                    <li data-tooltip="Activity Logs">
                        <a href="logs.php" class="<?php echo $current_page === 'logs' ? 'active' : ''; ?>">
                            <i class="fas fa-history"></i>
                            <span>Activity Logs</span>
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
    <div class="sidebar-theme">
        <button onclick="toggleTheme()">
            <i class="fas fa-moon" id="themeIcon"></i>
            <span id="themeText">Dark Mode</span>
        </button>
    </div>
    <div class="sidebar-logout">
        <button onclick="window.location.href='logout.php'">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </button>
    </div>
</div>

<!-- Theme Toggle JavaScript -->
<script>
/**
 * Theme Toggle Functionality
 * Handles light/dark mode switching with localStorage persistence
 * Also respects user's saved theme preference from database
 */
function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const userThemeMode = '<?php echo $user_theme['theme_mode']; ?>';
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    // Priority: localStorage > database setting > system preference
    let isDark = false;
    if (savedTheme) {
        isDark = savedTheme === 'dark';
    } else if (userThemeMode) {
        isDark = userThemeMode === 'dark';
        // Save to localStorage so it persists
        localStorage.setItem('theme', userThemeMode);
    } else {
        isDark = prefersDark;
    }

    if (isDark) {
        document.body.classList.add('dark-mode');
        updateThemeButton(true);
    } else {
        document.body.classList.remove('dark-mode');
        updateThemeButton(false);
    }
}

function toggleTheme() {
    const isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateThemeButton(isDark);
}

function updateThemeButton(isDark) {
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');

    if (icon && text) {
        if (isDark) {
            icon.className = 'fas fa-sun';
            text.textContent = 'Light Mode';
        } else {
            icon.className = 'fas fa-moon';
            text.textContent = 'Dark Mode';
        }
    }
}

// Initialize theme on page load
initTheme();

/**
 * Sidebar Collapse Functionality
 * Handles sidebar expand/collapse with localStorage persistence
 */
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
        icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
    }
}

// Initialize sidebar on page load
initSidebar();

// Listen for system theme changes
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
    if (!localStorage.getItem('theme')) {
        if (e.matches) {
            document.body.classList.add('dark-mode');
            updateThemeButton(true);
        } else {
            document.body.classList.remove('dark-mode');
            updateThemeButton(false);
        }
    }
});
</script>

<!-- Sidebar Submenu JavaScript -->
<script>
/**
 * Sidebar Submenu Toggle Functionality
 * Handles expanding/collapsing submenu items
 */
document.addEventListener('DOMContentLoaded', function() {
    const submenuToggles = document.querySelectorAll('.submenu-toggle');

    submenuToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();

            const submenuId = this.getAttribute('data-submenu');
            const submenu = document.getElementById(submenuId);

            if (!submenu) return;

            // Toggle open class on parent link
            this.classList.toggle('open');

            // Toggle open class on submenu
            submenu.classList.toggle('open');

            // Close other submenus (optional - for accordion behavior)
            // Uncomment below to allow only one submenu open at a time
            /*
            submenuToggles.forEach(function(otherToggle) {
                if (otherToggle !== toggle) {
                    otherToggle.classList.remove('open');
                    const otherSubmenuId = otherToggle.getAttribute('data-submenu');
                    const otherSubmenu = document.getElementById(otherSubmenuId);
                    if (otherSubmenu) {
                        otherSubmenu.classList.remove('open');
                    }
                }
            });
            */
        });
    });

    // Auto-open submenu if current page is a submenu item
    <?php if ($account_submenu_active): ?>
    const accountToggle = document.querySelector('[data-submenu="account-submenu"]');
    const accountSubmenu = document.getElementById('account-submenu');
    if (accountToggle && accountSubmenu) {
        accountToggle.classList.add('open');
        accountSubmenu.classList.add('open');
    }
    <?php endif; ?>
});
</script>
