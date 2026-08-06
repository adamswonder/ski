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
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
$user_id = $_SESSION['user_id'];
$current_page = 'account';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getAccountInfo':
                $stmt = $conn->prepare("SELECT id, full_name, email, role, avatar_url, created_at FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';

                    // Get theme from getUserTheme (handles missing columns gracefully)
                    $theme = getUserTheme($user_id);

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'id' => $user['id'],
                            'full_name' => $user['full_name'],
                            'email' => $user['email'],
                            'role' => $user['role'],
                            'avatar_url' => $user['avatar_url'],
                            'created_at' => date('M d, Y H:i:s', strtotime($user['created_at'])),
                            'allow_user_uploads' => $allowUserUploads,
                            'theme_primary' => $theme['theme_primary'],
                            'theme_secondary' => $theme['theme_secondary'],
                            'theme_accent' => getBrandAccentColor(),
                            'theme_mode' => $theme['theme_mode']
                        ]
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                }

                $stmt->close();
                $conn->close();
                exit();

            case 'saveTheme':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $theme_primary = isset($_POST['theme_primary']) ? trim($_POST['theme_primary']) : '#001f3f';
                $theme_secondary = isset($_POST['theme_secondary']) ? trim($_POST['theme_secondary']) : '#003366';
                // Accent is a fixed, admin-set brand color - never accepted from the per-user form
                $theme_accent = getBrandAccentColor();
                $theme_mode = isset($_POST['theme_mode']) ? trim($_POST['theme_mode']) : 'light';

                // Validate hex colors
                $color_pattern = '/^#[0-9A-Fa-f]{6}$/';
                if (!preg_match($color_pattern, $theme_primary) ||
                    !preg_match($color_pattern, $theme_secondary)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid color format. Use hex colors like #001f3f']);
                    exit();
                }

                // Validate theme mode
                if (!in_array($theme_mode, ['light', 'dark'])) {
                    $theme_mode = 'light';
                }

                $result = setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode);

                if ($result) {
                    logActivity($user_id, 'UPDATE', "Updated UI colors: Primary=$theme_primary, Secondary=$theme_secondary, Accent=$theme_accent, Mode=$theme_mode", ['module' => 'settings']);
                    echo json_encode(['success' => true, 'message' => 'Theme settings saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save theme settings']);
                }
                exit();

            case 'resetTheme':
                $result = setUserTheme($user_id, '#001f3f', '#003366', getBrandAccentColor(), 'light');

                if ($result) {
                    logActivity($user_id, 'UPDATE', 'Reset UI colors to default', ['module' => 'settings']);
                    echo json_encode(['success' => true, 'message' => 'Theme reset to default']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to reset theme']);
                }
                exit();

            case 'updateProfile':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $newFullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';

                // Validate inputs
                if (empty($newFullName) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if (strlen($newFullName) < 2 || strlen($newFullName) > 100) {
                    echo json_encode(['success' => false, 'message' => 'Full name must be 2-100 characters']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // Check if email is already taken by another user
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $email, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already taken by another user']);
                    exit();
                }
                $stmt->close();

                // Update profile
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $newFullName, $email, $user_id);

                if ($stmt->execute()) {
                    // Update session
                    $_SESSION['username'] = $newFullName;
                    $_SESSION['email'] = $email;

                    // Log activity
                    logActivity($user_id, 'UPDATE', 'Updated name and email', ['module' => 'users']);

                    $stmt->close();
                    $conn->close();

                    echo json_encode(['success' => true, 'message' => 'Profile updated successfully', 'new_full_name' => $newFullName]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
                }
                exit();

            case 'changePassword':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
                $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
                $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

                // Validate inputs
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if ($newPassword !== $confirmPassword) {
                    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
                    exit();
                }

                $newPassword = validatePassword($newPassword);
                if ($newPassword === false) {
                    echo json_encode(['success' => false, 'message' => 'Password must be 6-255 characters']);
                    exit();
                }

                // Verify current password
                $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();

                    if (!password_verify($currentPassword, $user['password_hash'])) {
                        $stmt->close();
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                        exit();
                    }
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }
                $stmt->close();

                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->bind_param("si", $hashedPassword, $user_id);

                if ($stmt->execute()) {
                    // Log activity
                    logActivity($user_id, 'UPDATE', 'User changed their password', ['module' => 'users']);

                    $stmt->close();
                    $conn->close();

                    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to change password']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Account.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle profile image upload (separate from AJAX JSON responses)
if (isset($_POST['action']) && $_POST['action'] === 'uploadProfileImage') {
    header('Content-Type: application/json');

    try {
        // Check if user uploads are allowed
        $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';
        if (!$allowUserUploads && $role !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Profile uploads are currently disabled']);
            exit();
        }

        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
            exit();
        }

        // Upload the file
        $uploadResult = uploadProfileImage($_FILES['profile_image'], $user_id);

        if (!$uploadResult['success']) {
            echo json_encode($uploadResult);
            exit();
        }

        // Get old profile image
        $oldImage = getProfileImage($user_id);

        // Update database
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE users SET avatar_url = ? WHERE id = ?");
        $stmt->bind_param("si", $uploadResult['filename'], $user_id);

        if ($stmt->execute()) {
            // Delete old image if exists
            if ($oldImage) {
                deleteProfileImage($oldImage);
            }

            // Log activity
            logActivity($user_id, 'UPDATE', 'User uploaded a new profile image', ['module' => 'users']);

            $stmt->close();
            $conn->close();

            echo json_encode([
                'success' => true,
                'message' => 'Profile image uploaded successfully',
                'image_url' => $uploadResult['filename']
            ]);
        } else {
            $stmt->close();
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Failed to update profile image']);
        }
    } catch (Exception $e) {
        error_log("Profile image upload error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
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
    <title>My Account Skyward Airlines</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.7">

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
                <h1><i class="ri-account-circle-line"></i> My Account</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <!-- Profile Image Section -->
            <div class="data-section" style="margin-bottom: 30px;">
                <div class="section-header">
                    <h2><i class="ri-image-line"></i> Profile Image</h2>
                </div>

                <div class="profile-section-grid">
                    <!-- Current Profile Image Display -->
                    <div class="profile-image-display">
                        <div class="profile-image-container">
                            <img id="currentProfileImage" src="" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                            <i id="defaultProfileIcon" class="ri-user-line" style="display: none;"></i>
                        </div>
                        <div class="profile-image-label">Current Image</div>
                    </div>

                    <!-- Upload Form -->
                    <div class="profile-upload-form">
                        <form id="profileImageForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label><i class="ri-upload-line"></i> Upload New Profile Image</label>
                                <input type="file" id="profileImageInput" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" class="file-input-styled">
                                <div class="help-text">
                                    <i class="ri-information-line"></i> Accepted: JPG, PNG, GIF, WEBP (Max 2MB)
                                </div>
                            </div>

                            <div class="form-actions" id="uploadSection" style="display: none;">
                                <button type="submit" class="btn btn-primary" id="uploadBtn">
                                    <i class="ri-upload-line"></i> Upload Image
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="cancelUpload()">
                                    <i class="ri-close-line"></i> Cancel
                                </button>
                            </div>
                        </form>

                        <div id="uploadDisabledMessage" class="warning-message" style="display: none;">
                            <i class="ri-alert-line"></i> Profile image uploads are currently disabled by administrator.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Information Card -->
            <div class="account-info-card">
                <h3><i class="ri-information-line"></i> Account Information</h3>
                <div class="info-row">
                    <div class="info-label"><i class="ri-user-line"></i> Full Name:</div>
                    <div class="info-value" id="display-fullname">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="ri-mail-line"></i> Email:</div>
                    <div class="info-value" id="display-email">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="ri-user-settings-line"></i> Role:</div>
                    <div class="info-value" id="display-role">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="ri-calendar-line"></i> Member Since:</div>
                    <div class="info-value" id="display-created">Loading...</div>
                </div>
            </div>

            <!-- Update Profile Section -->
            <div class="data-section" style="margin-bottom: 30px;">
                <div class="section-header">
                    <h2><i class="ri-edit-line"></i> Update Profile</h2>
                </div>

                <form id="profileForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-user-line"></i> Full Name *</label>
                            <input type="text" id="full_name" name="full_name" required minlength="2" maxlength="100">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-mail-line"></i> Email *</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-save-line"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password Section -->
            <div class="data-section" style="margin-bottom: 30px;">
                <div class="section-header">
                    <h2><i class="ri-lock-line"></i> Change Password</h2>
                </div>

                <form id="passwordForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-lock-line"></i> Current Password *</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-key-2-line"></i> New Password *</label>
                            <input type="password" id="new_password" name="new_password" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-key-2-line"></i> Confirm New Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-key-2-line"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- UI Customization Section -->
            <div class="data-section">
                <div class="section-header">
                    <h2><i class="ri-palette-line"></i> UI Customization</h2>
                    <button class="btn btn-secondary" onclick="resetTheme()">
                        <i class="ri-arrow-go-back-line"></i> Reset to Default
                    </button>
                </div>

                <p class="help-text" style="margin-bottom: 20px; color: var(--text-muted);">
                    <i class="ri-information-line"></i> Customize your dashboard colors. Changes are saved per user and will apply across all pages.
                </p>

                <!-- Color Preview -->
                <div class="theme-preview" id="themePreview" style="margin-bottom: 25px; padding: 20px; border-radius: 8px; border: 2px solid var(--border-color);">
                    <h4 style="margin-bottom: 15px; color: var(--text-primary);"><i class="ri-eye-line"></i> Live Preview</h4>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                        <div id="previewPrimary" style="width: 80px; height: 50px; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: 600;">Primary</div>
                        <div id="previewSecondary" style="width: 80px; height: 50px; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: 600;">Secondary</div>
                        <div id="previewAccent" style="width: 80px; height: 50px; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: 600;">Accent</div>
                        <button class="btn" id="previewButton" style="margin-left: 20px;">
                            <i class="ri-check-line"></i> Sample Button
                        </button>
                    </div>
                </div>

                <form id="themeForm">
                    <div class="form-grid" style="grid-template-columns: repeat(3, 1fr);">
                        <div class="form-group">
                            <label><i class="ri-square-line" id="primaryColorIcon"></i> Primary Color</label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input type="color" id="theme_primary" name="theme_primary" value="#001f3f" style="width: 60px; height: 45px; padding: 2px; cursor: pointer; border: 2px solid var(--border-color); border-radius: 4px;">
                                <input type="text" id="theme_primary_hex" value="#001f3f" maxlength="7" style="flex: 1; text-transform: uppercase;">
                            </div>
                            <small class="help-text" style="color: var(--text-muted); margin-top: 5px; display: block;">Sidebar & headers</small>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-square-line" id="secondaryColorIcon"></i> Secondary Color</label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input type="color" id="theme_secondary" name="theme_secondary" value="#003366" style="width: 60px; height: 45px; padding: 2px; cursor: pointer; border: 2px solid var(--border-color); border-radius: 4px;">
                                <input type="text" id="theme_secondary_hex" value="#003366" maxlength="7" style="flex: 1; text-transform: uppercase;">
                            </div>
                            <small class="help-text" style="color: var(--text-muted); margin-top: 5px; display: block;">Hover states & gradients</small>
                        </div>
                        <div class="form-group">
                            <label><i class="ri-square-line" id="accentColorIcon"></i> Accent Color</label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input type="color" id="theme_accent" name="theme_accent" value="<?php echo htmlspecialchars(getBrandAccentColor()); ?>" disabled style="width: 60px; height: 45px; padding: 2px; cursor: not-allowed; border: 2px solid var(--border-color); border-radius: 4px; opacity: 0.7;">
                                <input type="text" id="theme_accent_hex" value="<?php echo htmlspecialchars(getBrandAccentColor()); ?>" maxlength="7" disabled style="flex: 1; text-transform: uppercase; cursor: not-allowed; opacity: 0.7;">
                            </div>
                            <small class="help-text" style="color: var(--text-muted); margin-top: 5px; display: block;"><i class="ri-lock-line"></i> Set by your administrator in System Settings</small>
                        </div>
                    </div>

                    <!-- Theme Mode -->
                    <div class="form-group" style="margin-top: 20px;">
                        <label><i class="ri-contrast-2-line"></i> Default Theme Mode</label>
                        <div style="display: flex; gap: 20px; margin-top: 10px;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 12px 20px; border: 2px solid var(--border-color); border-radius: 4px; transition: all 0.3s;" class="theme-mode-option" id="lightModeOption">
                                <input type="radio" name="theme_mode" value="light" id="theme_mode_light" checked style="width: 18px; height: 18px;">
                                <i class="ri-sun-line" style="color: #fbbc04;"></i>
                                <span>Light Mode</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 12px 20px; border: 2px solid var(--border-color); border-radius: 4px; transition: all 0.3s;" class="theme-mode-option" id="darkModeOption">
                                <input type="radio" name="theme_mode" value="dark" id="theme_mode_dark" style="width: 18px; height: 18px;">
                                <i class="ri-moon-line" style="color: #5dade2;"></i>
                                <span>Dark Mode</span>
                            </label>
                        </div>
                    </div>

                    <!-- Preset Colors -->
                    <div class="form-group" style="margin-top: 25px;">
                        <label><i class="ri-contrast-2-line"></i> Quick Presets</label>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px;">
                            <button type="button" class="preset-btn" onclick="applyPreset('#001f3f', '#003366', '#0074D9')" style="background: linear-gradient(135deg, #001f3f 50%, #0074D9 50%); width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--border-color); cursor: pointer;" title="Navy Blue (Default)"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#1a1a2e', '#16213e', '#e94560')" style="background: linear-gradient(135deg, #1a1a2e 50%, #e94560 50%); width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--border-color); cursor: pointer;" title="Dark Rose"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#2d3436', '#636e72', '#00b894')" style="background: linear-gradient(135deg, #2d3436 50%, #00b894 50%); width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--border-color); cursor: pointer;" title="Emerald Dark"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#4a0e4e', '#810e7a', '#c92bc8')" style="background: linear-gradient(135deg, #4a0e4e 50%, #c92bc8 50%); width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--border-color); cursor: pointer;" title="Purple Magic"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#1b4332', '#2d6a4f', '#40916c')" style="background: linear-gradient(135deg, #1b4332 50%, #40916c 50%); width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--border-color); cursor: pointer;" title="Forest Green"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#7f5539', '#9c6644', '#dda15e')" style="background: linear-gradient(135deg, #7f5539 50%, #dda15e 50%); width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--border-color); cursor: pointer;" title="Warm Brown"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#03045e', '#0077b6', '#00b4d8')" style="background: linear-gradient(135deg, #03045e 50%, #00b4d8 50%); width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--border-color); cursor: pointer;" title="Ocean Blue"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#3d0066', '#7b2cbf', '#c77dff')" style="background: linear-gradient(135deg, #3d0066 50%, #c77dff 50%); width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--border-color); cursor: pointer;" title="Violet Dream"></button>
                        </div>
                    </div>

                    <div class="form-actions" style="margin-top: 30px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-save-line"></i> Save Theme Settings
                        </button>
                        <button type="button" class="btn btn-success" onclick="applyThemePreview()">
                            <i class="ri-eye-line"></i> Preview Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let allowUserUploads = true;

        $(document).ready(function() {
            loadAccountInfo();
        });

        function loadAccountInfo() {
            $.ajax({
                url: '?action=getAccountInfo',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;

                        // Display account info
                        document.getElementById('display-fullname').textContent = data.full_name;
                        document.getElementById('display-email').textContent = data.email;
                        document.getElementById('display-role').innerHTML =
                            `<span class="status-badge ${data.role === 'admin' ? 'status-admin' : 'status-user'}">${data.role}</span>`;
                        document.getElementById('display-created').textContent = data.created_at;

                        // Populate form
                        document.getElementById('full_name').value = data.full_name;
                        document.getElementById('email').value = data.email;

                        // Handle profile image
                        allowUserUploads = data.allow_user_uploads;
                        if (data.avatar_url) {
                            document.getElementById('currentProfileImage').src = data.avatar_url;
                            document.getElementById('currentProfileImage').style.display = 'block';
                            document.getElementById('defaultProfileIcon').style.display = 'none';
                        } else {
                            document.getElementById('currentProfileImage').style.display = 'none';
                            document.getElementById('defaultProfileIcon').style.display = 'block';
                        }

                        // Check if uploads are allowed
                        if (!allowUserUploads && data.role !== 'admin') {
                            document.getElementById('profileImageInput').disabled = true;
                            document.getElementById('uploadDisabledMessage').style.display = 'block';
                        }

                        // Load theme values
                        loadThemeValues(data);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load account info'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not load account information'
                    });
                }
            });
        }

        // Profile image file selection
        document.getElementById('profileImageInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const file = this.files[0];

                // Validate file size
                if (file.size > 2 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'Profile image must be less than 2MB'
                    });
                    this.value = '';
                    return;
                }

                // Show upload button
                document.getElementById('uploadSection').style.display = 'flex';

                // Preview image
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('currentProfileImage').src = e.target.result;
                    document.getElementById('currentProfileImage').style.display = 'block';
                    document.getElementById('defaultProfileIcon').style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });

        // Cancel upload
        function cancelUpload() {
            document.getElementById('profileImageInput').value = '';
            document.getElementById('uploadSection').style.display = 'none';
            loadAccountInfo(); // Reload to show original image
        }

        // Profile image upload form
        document.getElementById('profileImageForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData();
            const fileInput = document.getElementById('profileImageInput');

            if (!fileInput.files || !fileInput.files[0]) {
                Swal.fire({
                    icon: 'error',
                    title: 'No File Selected',
                    text: 'Please select an image to upload'
                });
                return;
            }

            formData.append('profile_image', fileInput.files[0]);
            formData.append('action', 'uploadProfileImage');

            Swal.fire({
                title: 'Uploading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '',
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

                        // Reset form and hide upload buttons
                        document.getElementById('profileImageInput').value = '';
                        document.getElementById('uploadSection').style.display = 'none';

                        // Reload account info to show new image
                        setTimeout(() => loadAccountInfo(), 500);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Upload Failed',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Upload Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Upload Failed',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Profile Update Form
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            Swal.fire({
                title: 'Updating Profile...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=updateProfile',
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

                        // Update displayed name if it changed
                        if (response.new_full_name) {
                            document.getElementById('display-fullname').textContent = response.new_full_name;
                        }

                        setTimeout(() => loadAccountInfo(), 100);
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

        // Password Change Form
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'New passwords do not match'
                });
                return;
            }

            const formData = new FormData(this);

            Swal.fire({
                title: 'Changing Password...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=changePassword',
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

                        // Clear password form
                        document.getElementById('passwordForm').reset();
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

        // =============================================
        // THEME CUSTOMIZATION FUNCTIONS
        // =============================================

        // Sync color picker with hex input
        document.getElementById('theme_primary').addEventListener('input', function() {
            document.getElementById('theme_primary_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        document.getElementById('theme_secondary').addEventListener('input', function() {
            document.getElementById('theme_secondary_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        document.getElementById('theme_accent').addEventListener('input', function() {
            document.getElementById('theme_accent_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        // Sync hex input with color picker
        document.getElementById('theme_primary_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_primary').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        document.getElementById('theme_secondary_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_secondary').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        document.getElementById('theme_accent_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_accent').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        // Update preview colors
        function updatePreview() {
            const primary = document.getElementById('theme_primary').value;
            const secondary = document.getElementById('theme_secondary').value;
            const accent = document.getElementById('theme_accent').value;

            document.getElementById('previewPrimary').style.background = primary;
            document.getElementById('previewSecondary').style.background = secondary;
            document.getElementById('previewAccent').style.background = accent;
            document.getElementById('previewButton').style.background = primary;
            document.getElementById('previewButton').style.color = 'white';
        }

        // Update color icons
        function updateColorIcons() {
            document.getElementById('primaryColorIcon').style.color = document.getElementById('theme_primary').value;
            document.getElementById('secondaryColorIcon').style.color = document.getElementById('theme_secondary').value;
            document.getElementById('accentColorIcon').style.color = document.getElementById('theme_accent').value;
        }

        // Apply preset colors (accent is fixed brand-wide and not part of presets)
        function applyPreset(primary, secondary) {
            document.getElementById('theme_primary').value = primary;
            document.getElementById('theme_primary_hex').value = primary.toUpperCase();
            document.getElementById('theme_secondary').value = secondary;
            document.getElementById('theme_secondary_hex').value = secondary.toUpperCase();
            updatePreview();
            updateColorIcons();

            Swal.fire({
                icon: 'info',
                title: 'Preset Applied',
                text: 'Click "Save Theme Settings" to apply permanently',
                timer: 2000,
                showConfirmButton: false
            });
        }

        // Apply theme preview (live preview without saving)
        function applyThemePreview() {
            const primary = document.getElementById('theme_primary').value;
            const secondary = document.getElementById('theme_secondary').value;
            const accent = document.getElementById('theme_accent').value;

            document.documentElement.style.setProperty('--navy-primary', primary);
            document.documentElement.style.setProperty('--navy-light', secondary);
            document.documentElement.style.setProperty('--navy-dark', primary);
            document.documentElement.style.setProperty('--navy-hover', secondary);
            document.documentElement.style.setProperty('--navy-accent', accent);

            Swal.fire({
                icon: 'success',
                title: 'Preview Applied!',
                text: 'This is a temporary preview. Save to make it permanent.',
                timer: 3000,
                showConfirmButton: false
            });
        }

        // Save theme settings
        document.getElementById('themeForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData();
            formData.append('theme_primary', document.getElementById('theme_primary').value);
            formData.append('theme_secondary', document.getElementById('theme_secondary').value);
            formData.append('theme_accent', document.getElementById('theme_accent').value);
            formData.append('theme_mode', document.querySelector('input[name="theme_mode"]:checked').value);

            Swal.fire({
                title: 'Saving Theme...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=saveTheme',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Apply the theme
                        applyThemePreview();

                        // Save theme mode to localStorage
                        const themeMode = document.querySelector('input[name="theme_mode"]:checked').value;
                        localStorage.setItem('theme', themeMode);
                        if (themeMode === 'dark') {
                            document.body.classList.add('dark-mode');
                        } else {
                            document.body.classList.remove('dark-mode');
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Theme Saved!',
                            text: 'Your custom theme has been saved.',
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
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Reset theme to default
        function resetTheme() {
            Swal.fire({
                icon: 'warning',
                title: 'Reset Theme?',
                text: 'This will reset your colors to the default Navy Blue theme.',
                showCancelButton: true,
                confirmButtonColor: '#001f3f',
                confirmButtonText: 'Yes, Reset',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '?action=resetTheme',
                        method: 'POST',
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Reset form values
                                document.getElementById('theme_primary').value = '#001f3f';
                                document.getElementById('theme_primary_hex').value = '#001F3F';
                                document.getElementById('theme_secondary').value = '#003366';
                                document.getElementById('theme_secondary_hex').value = '#003366';
                                document.getElementById('theme_accent').value = '#0074D9';
                                document.getElementById('theme_accent_hex').value = '#0074D9';
                                document.getElementById('theme_mode_light').checked = true;

                                // Apply default theme
                                document.documentElement.style.setProperty('--navy-primary', '#001f3f');
                                document.documentElement.style.setProperty('--navy-light', '#003366');
                                document.documentElement.style.setProperty('--navy-dark', '#001f3f');
                                document.documentElement.style.setProperty('--navy-hover', '#003366');
                                document.documentElement.style.setProperty('--navy-accent', '#0074D9');

                                // Set light mode
                                localStorage.setItem('theme', 'light');
                                document.body.classList.remove('dark-mode');

                                updatePreview();
                                updateColorIcons();

                                Swal.fire({
                                    icon: 'success',
                                    title: 'Theme Reset!',
                                    text: 'Colors have been reset to default.',
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

        // Load theme values into form when account info is loaded
        function loadThemeValues(data) {
            if (data.theme_primary) {
                document.getElementById('theme_primary').value = data.theme_primary;
                document.getElementById('theme_primary_hex').value = data.theme_primary.toUpperCase();
            }
            if (data.theme_secondary) {
                document.getElementById('theme_secondary').value = data.theme_secondary;
                document.getElementById('theme_secondary_hex').value = data.theme_secondary.toUpperCase();
            }
            if (data.theme_accent) {
                document.getElementById('theme_accent').value = data.theme_accent;
                document.getElementById('theme_accent_hex').value = data.theme_accent.toUpperCase();
            }
            if (data.theme_mode === 'dark') {
                document.getElementById('theme_mode_dark').checked = true;
            } else {
                document.getElementById('theme_mode_light').checked = true;
            }

            updatePreview();
            updateColorIcons();
        }
    </script>
</body>
</html>
