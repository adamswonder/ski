<?php
require_once 'applicant_config.php';

if (!isset($_SESSION['applicant_id'])) {
    header("Location: applicant-login.php?redirect=applicant-account.php");
    exit();
}

if (!checkSessionTimeout()) {
    header("Location: applicant-login.php?redirect=applicant-account.php");
    exit();
}

$applicantId = $_SESSION['applicant_id'];
$applicant_name = $_SESSION['applicant_name'];
$current_page = 'applicant-account';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getAccountInfo':
                $stmt = $conn->prepare("SELECT id, full_name, email, phone, date_of_birth, avatar_url, created_at FROM applicants WHERE id = ?");
                $stmt->bind_param("i", $applicantId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $applicant = $result->fetch_assoc();
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'full_name' => $applicant['full_name'],
                            'email' => $applicant['email'],
                            'phone' => $applicant['phone'],
                            'date_of_birth' => $applicant['date_of_birth'],
                            'avatar_url' => $applicant['avatar_url'],
                            'created_at' => date('M d, Y', strtotime($applicant['created_at']))
                        ]
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Account not found']);
                }
                $stmt->close();
                $conn->close();
                exit();

            case 'updateProfile':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $newFullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
                $dateOfBirth = isset($_POST['date_of_birth']) ? trim($_POST['date_of_birth']) : '';

                if (empty($newFullName) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Full name and email are required']);
                    exit();
                }

                if (strlen($newFullName) < 2 || strlen($newFullName) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Full name must be 2-150 characters']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                if ($dateOfBirth !== '') {
                    $dob = DateTime::createFromFormat('Y-m-d', $dateOfBirth);
                    if (!$dob || $dob->format('Y-m-d') !== $dateOfBirth || $dob > new DateTime()) {
                        echo json_encode(['success' => false, 'message' => 'Invalid date of birth']);
                        exit();
                    }
                }

                // Check if email is already taken by another applicant
                $stmt = $conn->prepare("SELECT id FROM applicants WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $email, $applicantId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already taken by another account']);
                    exit();
                }
                $stmt->close();

                $phoneValue = $phone !== '' ? $phone : null;
                $dobValue = $dateOfBirth !== '' ? $dateOfBirth : null;
                $stmt = $conn->prepare("UPDATE applicants SET full_name = ?, email = ?, phone = ?, date_of_birth = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $newFullName, $email, $phoneValue, $dobValue, $applicantId);

                if ($stmt->execute()) {
                    $_SESSION['applicant_name'] = $newFullName;
                    $_SESSION['applicant_email'] = $email;

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

                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if ($newPassword !== $confirmPassword) {
                    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
                    exit();
                }

                $validatedPassword = validatePassword($newPassword);
                if ($validatedPassword === false) {
                    echo json_encode(['success' => false, 'message' => 'Password must be 6-255 characters']);
                    exit();
                }

                $stmt = $conn->prepare("SELECT password_hash FROM applicants WHERE id = ?");
                $stmt->bind_param("i", $applicantId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $applicant = $result->fetch_assoc();

                    if (!password_verify($currentPassword, $applicant['password_hash'])) {
                        $stmt->close();
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                        exit();
                    }
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Account not found']);
                    exit();
                }
                $stmt->close();

                $hashedPassword = password_hash($validatedPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE applicants SET password_hash = ? WHERE id = ?");
                $stmt->bind_param("si", $hashedPassword, $applicantId);

                if ($stmt->execute()) {
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
        error_log("applicant-account.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']);
        exit();
    }
}

// Handle profile photo upload (separate from AJAX JSON responses above, since it's multipart/form-data)
if (isset($_POST['action']) && $_POST['action'] === 'uploadProfileImage') {
    header('Content-Type: application/json');

    try {
        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
            exit();
        }

        $uploadResult = uploadProfileImage($_FILES['profile_image'], $applicantId);

        if (!$uploadResult['success']) {
            echo json_encode($uploadResult);
            exit();
        }

        $oldImage = getApplicantProfileImage($applicantId);

        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE applicants SET avatar_url = ? WHERE id = ?");
        $stmt->bind_param("si", $uploadResult['filename'], $applicantId);

        if ($stmt->execute()) {
            if ($oldImage) {
                deleteProfileImage($oldImage);
            }

            $stmt->close();
            $conn->close();
            echo json_encode(['success' => true, 'message' => 'Profile photo updated successfully', 'avatar_url' => $uploadResult['filename']]);
        } else {
            $stmt->close();
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Failed to save profile photo']);
        }
        exit();
    } catch (Exception $e) {
        error_log("applicant-account.php avatar upload error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']);
        exit();
    }
}

$loginLogo = getSetting('login_logo', '');
$logoUrl = !empty($loginLogo) ? htmlspecialchars($loginLogo) : 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>My Account - Skyward Airlines</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4/fonts/remixicon.css">
    <link rel="stylesheet" href="styles.css?v=5.12">
    <?php echo generateBrandAccentCSS(); ?>
</head>
<body>
    <?php include 'applicant-mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'applicant-sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <h1><i class="ri-user-settings-line"></i> My Account</h1>
                <div>Welcome, <?php echo htmlspecialchars($applicant_name); ?></div>
            </div>

            <div class="data-section" style="margin-bottom: 24px; max-width: 700px;">
                <h3 style="margin: 0 0 20px;"><i class="ri-user-line"></i> Profile Information</h3>

                <div style="display:flex; align-items:center; gap:20px; margin-bottom: 24px;">
                    <img id="avatarPreview" src="<?php echo $logoUrl; ?>" alt="Profile photo" style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:2px solid var(--border-color);">
                    <div>
                        <input type="file" id="avatarInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('avatarInput').click()">
                            <i class="ri-camera-line"></i> Change Photo
                        </button>
                        <p style="font-size: 12px; color: var(--text-muted); margin-top: 6px;">JPG, PNG, GIF, or WEBP. Max 2MB.</p>
                    </div>
                </div>

                <form id="profileForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-user-line"></i> Full Name *</label>
                            <input type="text" id="full_name" name="full_name" required minlength="2" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-mail-line"></i> Email *</label>
                            <input type="email" id="email" name="email" required maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-phone-line"></i> Phone</label>
                            <input type="text" id="phone" name="phone" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-cake-2-line"></i> Date of Birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Changes</button>
                </form>
            </div>

            <div class="data-section" style="max-width: 700px;">
                <h3 style="margin: 0 0 20px;"><i class="ri-lock-line"></i> Change Password</h3>
                <form id="passwordForm">
                    <div class="form-group">
                        <label><i class="ri-lock-line"></i> Current Password *</label>
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="ri-lock-password-line"></i> New Password *</label>
                            <input type="password" id="new_password" name="new_password" required minlength="6" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label><i class="ri-lock-password-line"></i> Confirm New Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
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
                        document.getElementById('full_name').value = response.data.full_name || '';
                        document.getElementById('email').value = response.data.email || '';
                        document.getElementById('phone').value = response.data.phone || '';
                        document.getElementById('date_of_birth').value = response.data.date_of_birth || '';
                        if (response.data.avatar_url) {
                            document.getElementById('avatarPreview').src = response.data.avatar_url;
                        }
                    }
                }
            });
        }

        document.getElementById('avatarInput').addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;

            if (file.size > 2 * 1024 * 1024) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'File size must be less than 2MB' });
                this.value = '';
                return;
            }

            var formData = new FormData();
            formData.append('action', 'uploadProfileImage');
            formData.append('profile_image', file);

            Swal.fire({ title: 'Uploading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        document.getElementById('avatarPreview').src = response.avatar_url + '?t=' + Date.now();
                        Swal.fire({ icon: 'success', title: 'Updated!', timer: 1200, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);

            Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '?action=updateProfile',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Saved!', text: response.message, timer: 1500, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);

            Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '?action=changePassword',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Saved!', text: response.message, timer: 1500, showConfirmButton: false });
                        document.getElementById('passwordForm').reset();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });
    </script>
</body>
</html>
