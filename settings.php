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
$current_page = 'settings';

// Only admins can access settings
if ($role !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'getSettings') {
        try {
            $allowUserUploads = getSetting('allow_user_profile_uploads', '1');
            $loginLogo = getSetting('login_logo', '');
            $brandAccentColor = getBrandAccentColor();

            echo json_encode([
                'success' => true,
                'data' => [
                    'allow_user_profile_uploads' => $allowUserUploads,
                    'login_logo' => $loginLogo,
                    'brand_accent_color' => $brandAccentColor
                ]
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'saveSettings') {
        try {
            $allowUserUploads = isset($_POST['allow_user_profile_uploads']) ? $_POST['allow_user_profile_uploads'] : '0';
            $brandAccentColor = isset($_POST['brand_accent_color']) ? trim($_POST['brand_accent_color']) : '#023f57';

            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $brandAccentColor)) {
                echo json_encode(['success' => false, 'message' => 'Invalid accent color format. Use a hex color like #E31E24']);
                exit();
            }

            // Save settings
            $result = setSetting('allow_user_profile_uploads', $allowUserUploads);
            $result = setSetting('brand_accent_color', $brandAccentColor) && $result;

            if ($result) {
                // Log activity
                $settingText = $allowUserUploads === '1' ? 'enabled' : 'disabled';
                logActivity($user_id, 'UPDATE', "User profile uploads $settingText, brand accent set to $brandAccentColor", ['module' => 'settings']);

                echo json_encode([
                    'success' => true,
                    'message' => 'Settings saved successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to save settings'
                ]);
            }
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'getTechnicalSkills') {
        try {
            $skills = getSetting('technical_skills', '[]');
            $skillsArray = json_decode($skills, true);

            echo json_encode([
                'success' => true,
                'data' => $skillsArray
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading skills: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'saveTechnicalSkills') {
        try {
            $skills = isset($_POST['skills']) ? json_decode($_POST['skills'], true) : [];

            // Validate and sanitize
            $skills = array_filter($skills, function($skill) {
                return !empty(trim($skill));
            });
            $skills = array_values(array_unique($skills)); // Remove duplicates, reindex

            $result = setSetting('technical_skills', json_encode($skills));

            if ($result) {
                logActivity($user_id, 'UPDATE', 'Updated technical skills list (' . count($skills) . ' skills)', ['module' => 'settings']);
                echo json_encode(['success' => true, 'message' => 'Technical skills updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update skills']);
            }
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving skills: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'uploadLogo') {
        try {
            if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                exit();
            }

            $uploadResult = uploadLogoImage($_FILES['logo_file']);

            if ($uploadResult['success']) {
                // Delete old logo file if it exists
                $oldLogo = getSetting('login_logo', '');
                if ($oldLogo && strpos($oldLogo, 'uploads/logos/') === 0 && file_exists(__DIR__ . '/' . $oldLogo)) {
                    unlink(__DIR__ . '/' . $oldLogo);
                }

                setSetting('login_logo', $uploadResult['filename']);
                logActivity($user_id, 'UPDATE', 'Updated login page logo', ['module' => 'settings']);

                echo json_encode(['success' => true, 'message' => 'Logo updated successfully', 'logo_url' => $uploadResult['filename']]);
            } else {
                echo json_encode($uploadResult);
            }
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error uploading logo: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'resetLogo') {
        try {
            $oldLogo = getSetting('login_logo', '');
            if ($oldLogo && strpos($oldLogo, 'uploads/logos/') === 0 && file_exists(__DIR__ . '/' . $oldLogo)) {
                unlink(__DIR__ . '/' . $oldLogo);
            }
            setSetting('login_logo', '');
            logActivity($user_id, 'UPDATE', 'Reset login page logo to default', ['module' => 'settings']);
            echo json_encode(['success' => true, 'message' => 'Logo reset to default']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error resetting logo: ' . $e->getMessage()]);
            exit();
        }
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
    <title>System Settings Skyward Airlines</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.11">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1><i class="ri-settings-3-line"></i> System Settings</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card" style="margin-bottom: 20px;">
                    <div class="skeleton skeleton-text-large" style="width: 60%; margin-bottom: 16px;"></div>
                    <div class="skeleton skeleton-text" style="width: 80%; margin-bottom: 12px;"></div>
                    <div class="skeleton skeleton-text" style="width: 70%;"></div>
                </div>
                <div class="dashboard-grid-4" style="margin-bottom: 30px;">
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                </div>
            </div>

            <!-- Settings Content -->
            <div id="settingsContent" style="display: none;">
                <!-- Quick Actions -->
                <div style="margin-bottom: 25px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn btn-success" onclick="saveSettings()">
                        <i class="ri-save-line"></i> Save Settings
                    </button>
                    <button class="btn btn-secondary" onclick="loadSettings()">
                        <i class="ri-refresh-line"></i> Reload
                    </button>
                </div>

                <!-- 2x2 Grid Layout -->
                <div class="settings-grid-2x2">

                    <!-- Card 1: System Overview -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, var(--navy-primary) 0%, var(--navy-light) 100%);">
                                <i class="ri-server-line"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">System Overview</h3>
                                <p class="settings-card-subtitle">Core system configuration</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(52, 168, 83, 0.1); color: var(--success);">
                                    <i class="ri-database-2-line"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Database</div>
                                    <div class="stat-item-value"><?php echo DB_NAME; ?></div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(2, 63, 87, 0.1); color: var(--navy-accent);">
                                    <i class="ri-time-line"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Session Timeout</div>
                                    <div class="stat-item-value"><?php echo SESSION_TIMEOUT / 60; ?> minutes</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(251, 188, 4, 0.1); color: var(--warning);">
                                    <i class="ri-shield-check-line"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Max Login Attempts</div>
                                    <div class="stat-item-value"><?php echo MAX_LOGIN_ATTEMPTS; ?> attempts</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(234, 67, 53, 0.1); color: var(--danger);">
                                    <i class="ri-lock-line"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Lockout Duration</div>
                                    <div class="stat-item-value"><?php echo LOGIN_LOCKOUT_TIME / 60; ?> minutes</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 2: User Management -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #34a853 0%, #2d9148 100%);">
                                <i class="ri-group-2-line"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">User Management</h3>
                                <p class="settings-card-subtitle">Control user permissions</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="control-group">
                                <div class="control-group-header">
                                    <div class="control-icon">
                                        <i class="ri-image-line"></i>
                                    </div>
                                    <div class="control-info">
                                        <div class="control-title">Profile Image Uploads</div>
                                        <div class="control-desc">Allow users to upload profile pictures</div>
                                    </div>
                                </div>
                                <div class="control-toggle-wrapper">
                                    <div class="toggle-switch-large">
                                        <input type="checkbox" id="allowUserUploads" class="toggle-input-large">
                                        <label for="allowUserUploads" class="toggle-label-large">
                                            <span class="toggle-slider-large"></span>
                                        </label>
                                    </div>
                                    <div class="toggle-status" id="uploadToggleStatus">
                                        <span class="status-dot status-disabled"></span>
                                        <span class="status-text">Disabled</span>
                                    </div>
                                </div>
                            </div>
                            <div class="info-banner">
                                <i class="ri-information-line"></i>
                                <span>When disabled, only administrators can manage all profile images</span>
                            </div>
                        </div>
                    </div>

                    <!-- Card 3: Security Status -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #023f57 0%, #0056a8 100%);">
                                <i class="ri-shield-check-line"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Security Status</h3>
                                <p class="settings-card-subtitle">Active security features</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="ri-checkbox-circle-line"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Session Security</div>
                                    <div class="security-feature-desc">HTTPOnly cookies & Strict SameSite</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="ri-shield-check-line"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="ri-lock-line"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Password Encryption</div>
                                    <div class="security-feature-desc">Bcrypt (PASSWORD_DEFAULT)</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="ri-shield-check-line"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="ri-time-line"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Rate Limiting</div>
                                    <div class="security-feature-desc">User & IP tracking enabled</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="ri-shield-check-line"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="ri-code-line"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">CSRF Protection</div>
                                    <div class="security-feature-desc">Token-based validation</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="ri-shield-check-line"></i> Active
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 4: Server Information -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #fbbc04 0%, #e0a800 100%);">
                                <i class="ri-server-line"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Server Information</h3>
                                <p class="settings-card-subtitle">Environment details</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="ri-database-2-line"></i>
                                    <span>Database Host</span>
                                </div>
                                <div class="server-info-value"><?php echo DB_HOST; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="ri-user-line"></i>
                                    <span>Database User</span>
                                </div>
                                <div class="server-info-value"><?php echo DB_USER; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="ri-code-line"></i>
                                    <span>PHP Version</span>
                                </div>
                                <div class="server-info-value">
                                    <span class="version-badge"><?php echo phpversion(); ?></span>
                                </div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="ri-hard-drive-line"></i>
                                    <span>Max Upload</span>
                                </div>
                                <div class="server-info-value">
                                    <span class="upload-badge"><?php echo ini_get('upload_max_filesize'); ?></span>
                                </div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="ri-time-line"></i>
                                    <span>Server Time</span>
                                </div>
                                <div class="server-info-value time-value"><?php echo date('Y-m-d H:i:s'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 5: Technical Skills Management -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);">
                                <i class="ri-terminal-window-line"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Technical Skills Management</h3>
                                <p class="settings-card-subtitle">Configure application form skills</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <p class="settings-description">
                                Manage the technical skills that appear in the application form. Add or remove skills to match your organization's needs.
                            </p>

                            <div id="skillsList" class="skills-list">
                                <div style="text-align:center;padding:20px;color:#999;">
                                    <i class="ri-loader-4-line ri-spin"></i> Loading skills...
                                </div>
                            </div>

                            <div class="add-skill-section">
                                <input type="text" id="newSkillInput" class="filter-input" placeholder="Enter new skill name..." style="flex:1;">
                                <button type="button" class="btn btn-success" onclick="addSkill()">
                                    <i class="ri-add-line"></i> Add Skill
                                </button>
                            </div>

                            <div style="margin-top:15px;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="restoreDefaultSkills()">
                                    <i class="ri-arrow-go-back-line"></i> Restore Defaults
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Card 6: Login Page Branding -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #ea4335 0%, #c62828 100%);">
                                <i class="ri-image-line"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Login Page Branding</h3>
                                <p class="settings-card-subtitle">Customize login page logo</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <p class="settings-description">
                                Upload a custom logo that will be displayed on the login page. Recommended size: 160x160px square image.
                            </p>
                            <div style="text-align: center; margin-bottom: 20px;">
                                <img id="logoPreview" src="" alt="Login Logo" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid var(--navy-primary); box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                            </div>
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label><i class="ri-upload-line"></i> Upload New Logo</label>
                                <input type="file" id="logoFileInput" accept=".jpg,.jpeg,.png,.gif,.webp" class="filter-input">
                                <p style="margin-top: 8px; font-size: 13px; color: #888;"><i class="ri-information-line"></i> Allowed: JPG, PNG, GIF, WEBP (Max 2MB)</p>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="button" class="btn btn-primary" onclick="uploadLogo()">
                                    <i class="ri-upload-line"></i> Upload Logo
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="resetLogo()">
                                    <i class="ri-arrow-go-back-line"></i> Reset to Default
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Card 7: Brand Accent Color -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #E31E24 0%, #a01319 100%);">
                                <i class="ri-palette-line"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Brand Accent Color</h3>
                                <p class="settings-card-subtitle">Fixed accent color used across the whole system</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <p class="settings-description">
                                This accent color applies site-wide (buttons, links, highlights) for every user, including on the public careers pages and login screens. It overrides the accent slot in each user's personal theme customizer.
                            </p>
                            <div class="form-group">
                                <label><i class="ri-square-line" id="brandAccentIcon"></i> Accent Color</label>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="color" id="brand_accent_color" value="#023f57" style="width: 60px; height: 45px; padding: 2px; cursor: pointer; border: 2px solid var(--border-color); border-radius: 4px;">
                                    <input type="text" id="brand_accent_color_hex" value="#023f57" maxlength="7" style="flex: 1; text-transform: uppercase;">
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <button type="button" class="btn btn-primary" onclick="saveBrandAccentColor()">
                                    <i class="ri-save-line"></i> Save Accent Color
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            loadSettings();
        });

        document.getElementById('brand_accent_color').addEventListener('input', function() {
            document.getElementById('brand_accent_color_hex').value = this.value.toUpperCase();
            document.getElementById('brandAccentIcon').style.color = this.value;
        });

        document.getElementById('brand_accent_color_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('brand_accent_color').value = this.value;
                document.getElementById('brandAccentIcon').style.color = this.value;
            }
        });

        function saveBrandAccentColor() {
            const accentColor = document.getElementById('brand_accent_color_hex').value;
            if (!/^#[0-9A-Fa-f]{6}$/.test(accentColor)) {
                Swal.fire({ icon: 'error', title: 'Invalid Color', text: 'Please enter a valid hex color like #E31E24' });
                return;
            }

            Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'saveSettings',
                    allow_user_profile_uploads: document.getElementById('allowUserUploads').checked ? '1' : '0',
                    brand_accent_color: accentColor
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Saved!', text: 'Reload other pages to see the accent color everywhere.', timer: 2500, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        }

        function loadSettings() {
            // Show skeleton, hide content
            $('#loadingSkeleton').show();
            $('#settingsContent').hide();

            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'getSettings' },
                dataType: 'json',
                success: function(response) {
                    // Hide skeleton, show content
                    setTimeout(() => {
                        $('#loadingSkeleton').hide();
                        $('#settingsContent').fadeIn(300);
                    }, 500);

                    if (response.success) {
                        const data = response.data;

                        // Set toggle states
                        const isEnabled = data.allow_user_profile_uploads === '1';
                        document.getElementById('allowUserUploads').checked = isEnabled;
                        updateToggleStatus(isEnabled);

                        // Set logo preview
                        var defaultLogo = 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
                        document.getElementById('logoPreview').src = data.login_logo || defaultLogo;

                        // Set brand accent color
                        const brandAccent = data.brand_accent_color || '#023f57';
                        document.getElementById('brand_accent_color').value = brandAccent;
                        document.getElementById('brand_accent_color_hex').value = brandAccent.toUpperCase();
                        document.getElementById('brandAccentIcon').style.color = brandAccent;
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load settings'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    $('#loadingSkeleton').hide();
                    $('#settingsContent').show();

                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        function saveSettings() {
            const allowUserUploads = document.getElementById('allowUserUploads').checked ? '1' : '0';
            const brandAccentColor = document.getElementById('brand_accent_color_hex').value;

            Swal.fire({
                title: 'Saving Settings...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'saveSettings',
                    allow_user_profile_uploads: allowUserUploads,
                    brand_accent_color: brandAccentColor
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to save settings. Please try again.'
                    });
                }
            });
        }

        function updateToggleStatus(isEnabled) {
            const statusElement = document.getElementById('uploadToggleStatus');
            const statusDot = statusElement.querySelector('.status-dot');
            const statusText = statusElement.querySelector('.status-text');

            if (isEnabled) {
                statusDot.classList.remove('status-disabled');
                statusDot.classList.add('status-enabled');
                statusText.textContent = 'Enabled';
                statusText.style.color = 'var(--success)';
            } else {
                statusDot.classList.remove('status-enabled');
                statusDot.classList.add('status-disabled');
                statusText.textContent = 'Disabled';
                statusText.style.color = 'var(--text-muted)';
            }
        }

        // Add event listener to toggle
        $(document).on('change', '#allowUserUploads', function() {
            updateToggleStatus(this.checked);
        });

        // ===== Technical Skills Management =====
        let currentSkills = [];

        // Load technical skills on page ready
        $(document).ready(function() {
            loadTechnicalSkills();
        });

        function loadTechnicalSkills() {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'getTechnicalSkills' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        currentSkills = response.data;
                        renderSkillsList();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                }
            });
        }

        function renderSkillsList() {
            const container = document.getElementById('skillsList');
            if (currentSkills.length === 0) {
                container.innerHTML = '<p style="text-align:center;color:#999;padding:20px;">No skills configured. Add your first skill below.</p>';
                return;
            }

            container.innerHTML = currentSkills.map((skill, index) => `
                <div class="skill-item">
                    <span class="skill-name">${escapeHtml(skill)}</span>
                    <button type="button" class="skill-delete-btn" onclick="deleteSkill(${index})" title="Delete skill">
                        <i class="ri-delete-bin-line"></i>
                    </button>
                </div>
            `).join('');
        }

        function addSkill() {
            const input = document.getElementById('newSkillInput');
            const skillName = input.value.trim();

            if (!skillName) {
                Swal.fire({ icon: 'warning', title: 'Empty Skill', text: 'Please enter a skill name' });
                return;
            }

            if (currentSkills.includes(skillName)) {
                Swal.fire({ icon: 'warning', title: 'Duplicate', text: 'This skill already exists' });
                return;
            }

            currentSkills.push(skillName);
            saveTechnicalSkills();
            input.value = '';
        }

        function deleteSkill(index) {
            const skillName = currentSkills[index];

            Swal.fire({
                icon: 'warning',
                title: 'Delete Skill?',
                text: `Remove "${skillName}" from the list?`,
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    currentSkills.splice(index, 1);
                    saveTechnicalSkills();
                }
            });
        }

        function restoreDefaultSkills() {
            Swal.fire({
                icon: 'question',
                title: 'Restore Defaults?',
                text: 'This will replace your current skills with the default list.',
                showCancelButton: true,
                confirmButtonText: 'Restore'
            }).then((result) => {
                if (result.isConfirmed) {
                    currentSkills = ["HTML/CSS","JavaScript","PHP","Python","Java","SQL","React","Node.js","WordPress","MS Office","Data Entry","Graphic Design"];
                    saveTechnicalSkills();
                }
            });
        }

        function saveTechnicalSkills() {
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'saveTechnicalSkills',
                    skills: JSON.stringify(currentSkills)
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', text: response.message, timer: 1500, showConfirmButton: false });
                        renderSkillsList();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                }
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ---- Logo Management ----
        var DEFAULT_LOGO = 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';

        function uploadLogo() {
            var fileInput = document.getElementById('logoFileInput');
            if (!fileInput.files || fileInput.files.length === 0) {
                Swal.fire({ icon: 'warning', title: 'No File', text: 'Please select a logo image to upload' });
                return;
            }

            var formData = new FormData();
            formData.append('action', 'uploadLogo');
            formData.append('logo_file', fileInput.files[0]);

            Swal.fire({ title: 'Uploading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        document.getElementById('logoPreview').src = response.logo_url + '?t=' + Date.now();
                        fileInput.value = '';
                        Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error. Please try again.' });
                }
            });
        }

        function resetLogo() {
            Swal.fire({
                icon: 'question',
                title: 'Reset Logo?',
                text: 'This will restore the default logo on the login page.',
                showCancelButton: true,
                confirmButtonColor: '#e8262c',
                confirmButtonText: 'Reset'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'resetLogo' },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                document.getElementById('logoPreview').src = DEFAULT_LOGO;
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                            }
                        },
                        error: function() {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>
