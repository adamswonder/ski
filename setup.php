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
    <title>Database Setup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=4.0">
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-database"></i> Database Setup</h2>
        <p class="subtitle">Setting up your dashboard database tables...</p>
        <hr>

        <?php
        require_once __DIR__ . '/config.php';

        // create db if missing
        $tmp = new mysqli(DB_HOST, DB_USER, DB_PASS);
        if ($tmp->connect_error) {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> MySQL connection failed: ' . $tmp->connect_error . '</div>';
            die();
        }
        $db_escaped = str_replace('`', '``', DB_NAME);
        if ($tmp->query("CREATE DATABASE IF NOT EXISTS `$db_escaped` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database "' . DB_NAME . '" ready</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create DB: ' . $tmp->error . '</div>';
            die();
        }
        $tmp->close();

        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Connection failed: ' . $conn->connect_error . '</div>';
            die();
        }

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database connection successful</div>';

        // ====== DROP ALL TABLES (clean slate) ======
        echo '<br><div class="log-item log-info"><i class="fas fa-trash-alt"></i> <strong>Dropping existing tables...</strong></div>';

        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        $tables_to_drop = ['application_documents', 'activity_logs', 'applications', 'login_attempts', 'system_settings', 'stages', 'users'];
        foreach ($tables_to_drop as $table) {
            if ($conn->query("DROP TABLE IF EXISTS `$table`")) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Dropped table: ' . $table . '</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error dropping ' . $table . ': ' . $conn->error . '</div>';
            }
        }

        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        // ====== CREATE TABLES ======
        echo '<br><div class="log-item log-info"><i class="fas fa-plus-circle"></i> <strong>Creating tables...</strong></div>';

        // 1. users (no FK dependencies)
        $conn->query("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            avatar_url VARCHAR(500) DEFAULT NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            password_reset_token VARCHAR(255) DEFAULT NULL,
            password_reset_expires DATETIME DEFAULT NULL,
            last_login_at DATETIME DEFAULT NULL,
            theme_primary VARCHAR(7) DEFAULT '#001f3f',
            theme_secondary VARCHAR(7) DEFAULT '#003366',
            theme_accent VARCHAR(7) DEFAULT '#0074D9',
            theme_mode ENUM('light', 'dark') DEFAULT 'light',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_role (role),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "users" created</div>';

        // 2. stages (no FK dependencies)
        $conn->query("CREATE TABLE stages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            stage_type ENUM('pipeline', 'status') NOT NULL,
            color VARCHAR(7) DEFAULT '#6B7280',
            icon VARCHAR(50) DEFAULT NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_stage_type (stage_type),
            INDEX idx_display_order (display_order),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "stages" created</div>';

        // 3. system_settings (no FK dependencies)
        $conn->query("CREATE TABLE system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "system_settings" created</div>';

        // 4. login_attempts (no FK dependencies)
        $conn->query("CREATE TABLE login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(150) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) NOT NULL,
            INDEX idx_username (username),
            INDEX idx_attempt_time (attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "login_attempts" created</div>';

        // 5. applications (FK → users, stages)
        $conn->query("CREATE TABLE applications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidate_name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL,
            contact_number VARCHAR(20) DEFAULT NULL,
            position VARCHAR(150) NOT NULL,
            company VARCHAR(150) DEFAULT NULL,
            experience VARCHAR(100) DEFAULT NULL,
            academic_qualification JSON DEFAULT NULL,
            technical_qualification JSON DEFAULT NULL,
            expected_salary VARCHAR(50) DEFAULT NULL,
            nationality VARCHAR(80) DEFAULT NULL,
            current_location VARCHAR(150) DEFAULT NULL,
            stage_id INT NOT NULL,
            status_id INT NOT NULL,
            next_action VARCHAR(255) DEFAULT NULL,
            next_action_date DATE DEFAULT NULL,
            applied_date DATE NOT NULL,
            joined_date DATE DEFAULT NULL,
            days_to_join INT GENERATED ALWAYS AS (DATEDIFF(joined_date, applied_date)) STORED,
            notes TEXT DEFAULT NULL,
            assigned_to INT DEFAULT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_stage_id (stage_id),
            INDEX idx_status_id (status_id),
            INDEX idx_assigned_to (assigned_to),
            INDEX idx_applied_date (applied_date),
            INDEX idx_next_action_date (next_action_date),
            INDEX idx_company (company),
            INDEX idx_position (position),
            FOREIGN KEY (stage_id) REFERENCES stages(id),
            FOREIGN KEY (status_id) REFERENCES stages(id),
            FOREIGN KEY (assigned_to) REFERENCES users(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "applications" created</div>';

        // 6. application_documents (FK → applications CASCADE, users RESTRICT)
        $conn->query("CREATE TABLE application_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_type VARCHAR(50) DEFAULT NULL,
            file_size_kb INT DEFAULT NULL,
            document_label VARCHAR(100) DEFAULT 'CV',
            uploaded_by INT NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_application_id (application_id),
            INDEX idx_uploaded_by (uploaded_by),
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "application_documents" created</div>';

        // 7. activity_logs (FK → users RESTRICT, applications SET NULL)
        $conn->query("CREATE TABLE activity_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            application_id INT DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            module VARCHAR(50) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            old_values JSON DEFAULT NULL,
            new_values JSON DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_application_id (application_id),
            INDEX idx_action (action),
            INDEX idx_module (module),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "activity_logs" created</div>';

        // ====== FK RELATIONSHIP SUMMARY ======
        echo '<br><div class="credentials-box">';
        echo '<strong><i class="fas fa-link"></i> Foreign Key Relationships:</strong><br><br>';
        echo '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        echo '<tr style="background:var(--navy-primary); color:white;">';
        echo '<th style="padding:8px; text-align:left;">Child Table → Column</th>';
        echo '<th style="padding:8px; text-align:left;">Parent Table</th>';
        echo '<th style="padding:8px; text-align:left;">ON DELETE</th>';
        echo '</tr>';
        $fk_rows = [
            ['applications → stage_id', 'stages', 'RESTRICT'],
            ['applications → status_id', 'stages', 'RESTRICT'],
            ['applications → assigned_to', 'users', 'RESTRICT'],
            ['applications → created_by', 'users', 'RESTRICT'],
            ['application_documents → application_id', 'applications', 'CASCADE'],
            ['application_documents → uploaded_by', 'users', 'RESTRICT'],
            ['activity_logs → user_id', 'users', 'RESTRICT'],
            ['activity_logs → application_id', 'applications', 'SET NULL'],
        ];
        foreach ($fk_rows as $i => $fk) {
            $bg = $i % 2 === 0 ? '#f8f9fa' : 'white';
            $color = $fk[2] === 'CASCADE' ? '#ea4335' : ($fk[2] === 'SET NULL' ? '#fbbc04' : '#34a853');
            echo '<tr style="background:' . $bg . ';">';
            echo '<td style="padding:6px 8px;">' . $fk[0] . '</td>';
            echo '<td style="padding:6px 8px;">' . $fk[1] . '</td>';
            echo '<td style="padding:6px 8px;"><span style="color:' . $color . '; font-weight:600;">' . $fk[2] . '</span></td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        // ====== INSERT DEFAULT DATA ======
        echo '<br><div class="log-item log-info"><i class="fas fa-user-shield"></i> <strong>Creating default admin user...</strong></div>';

        $default_name = 'Admin User';
        $default_email = 'admin@demo.com';
        $default_password = password_hash('admin123', PASSWORD_DEFAULT);
        $default_role = 'admin';

        $insert_stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $insert_stmt->bind_param("ssss", $default_name, $default_email, $default_password, $default_role);

        if ($insert_stmt->execute()) {
            $admin_id = $conn->insert_id;
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default admin user created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating admin user: ' . $insert_stmt->error . '</div>';
            $admin_id = 1;
        }
        $insert_stmt->close();

        // seed 39 demo users (admin + 39 = 40 total)
        $user_ids = [$admin_id];
        $user_pwd = password_hash('user123', PASSWORD_DEFAULT);
        $user_role = 'user';
        $insert_user = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        for ($i = 1; $i <= 39; $i++) {
            $name = "User $i";
            $email = "user$i@demo.com";
            $insert_user->bind_param("ssss", $name, $email, $user_pwd, $user_role);
            if ($insert_user->execute()) {
                $user_ids[] = $conn->insert_id;
            }
        }
        $insert_user->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded ' . count($user_ids) . ' users (1 admin + 39 users)</div>';

        echo '<div class="credentials-box">';
        echo '<strong><i class="fas fa-key"></i> Login Credentials:</strong><br><br>';
        echo '<strong>Admin:</strong> admin@demo.com / admin123<br>';
        echo '<strong>Users:</strong> user1@demo.com → user39@demo.com / user123<br><br>';
        echo '<em style="color: var(--warning);"><i class="fas fa-exclamation-triangle"></i> Please change the admin password after first login!</em>';
        echo '</div>';

        // Insert default system setting
        $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('allow_user_profile_uploads', '1')");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default settings created</div>';

        // ====== INSERT DEFAULT STAGES & STATUSES ======
        echo '<br><div class="log-item log-info"><i class="fas fa-stream"></i> <strong>Creating default stages & statuses...</strong></div>';

        $pipeline_stages = [
            ['Applied',     'pipeline', '#3B82F6', 'fa-paper-plane',    1],
            ['Screening',   'pipeline', '#8B5CF6', 'fa-search',         2],
            ['Shortlisted', 'pipeline', '#F59E0B', 'fa-star',           3],
            ['Interview',   'pipeline', '#0074D9', 'fa-comments',       4],
            ['Offer',       'pipeline', '#10B981', 'fa-handshake',      5],
            ['Joined',      'pipeline', '#34A853', 'fa-user-check',     6],
            ['Rejected',    'pipeline', '#EF4444', 'fa-times-circle',   7]
        ];

        $statuses = [
            ['Active',        'status', '#34A853', 'fa-check-circle',    1],
            ['On Hold',       'status', '#F59E0B', 'fa-pause-circle',    2],
            ['Withdrawn',     'status', '#6B7280', 'fa-arrow-left',      3],
            ['Hired',         'status', '#10B981', 'fa-briefcase',       4],
            ['Not Suitable',  'status', '#EF4444', 'fa-user-times',      5],
            ['Blacklisted',   'status', '#1F2937', 'fa-ban',             6]
        ];

        $insert_stage = $conn->prepare("INSERT INTO stages (name, stage_type, color, icon, display_order) VALUES (?, ?, ?, ?, ?)");
        $all_stages = array_merge($pipeline_stages, $statuses);
        $inserted = 0;

        foreach ($all_stages as $stage) {
            $insert_stage->bind_param("ssssi", $stage[0], $stage[1], $stage[2], $stage[3], $stage[4]);
            if ($insert_stage->execute()) {
                $inserted++;
            }
        }
        $insert_stage->close();

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Inserted ' . $inserted . ' default stages & statuses</div>';
        echo '<div class="credentials-box">';
        echo '<strong><i class="fas fa-stream"></i> Pipeline Stages:</strong><br>';
        echo 'Applied → Screening → Shortlisted → Interview → Offer → Joined → Rejected<br><br>';
        echo '<strong><i class="fas fa-tags"></i> Statuses:</strong><br>';
        echo 'Active, On Hold, Withdrawn, Hired, Not Suitable, Blacklisted';
        echo '</div>';

        // ====== INSERT DEMO APPLICATIONS ======
        echo '<br><div class="log-item log-info"><i class="fas fa-clipboard-list"></i> <strong>Inserting demo applications...</strong></div>';

        // Get stage & status IDs dynamically
        $stage_ids = [];
        $status_ids = [];
        $s_result = $conn->query("SELECT id, name, stage_type FROM stages ORDER BY id ASC");
        while ($s_row = $s_result->fetch_assoc()) {
            if ($s_row['stage_type'] === 'pipeline') {
                $stage_ids[$s_row['name']] = $s_row['id'];
            } else {
                $status_ids[$s_row['name']] = $s_row['id'];
            }
        }

        // generate 40 demo candidates programmatically - youtube safe
        $positions = ['Full Stack Developer', 'UI/UX Designer', 'Backend Developer', 'Data Entry Operator', 'WordPress Developer', 'Frontend Developer', 'Project Manager', 'Graphic Designer'];
        $companies = ['Company 1', 'Company 2', 'Company 3', 'Company 4', 'Company 5'];
        $experiences = ['6 months', '1 year', '2 years', '3 years', '4 years', '5+ years', '7 years'];
        $academic_pool = [['Bachelor\'s'], ['Master\'s'], ['Bachelor\'s', 'Diploma'], ['Intermediate'], ['Matric', 'Intermediate'], ['Bachelor\'s', 'Professional Certification']];
        $tech_pool = [
            ['HTML/CSS', 'JavaScript', 'PHP', 'React', 'SQL'],
            ['Graphic Design', 'HTML/CSS'],
            ['PHP', 'Python', 'SQL', 'Node.js'],
            ['MS Office', 'Data Entry'],
            ['WordPress', 'HTML/CSS', 'JavaScript', 'PHP'],
            ['HTML/CSS', 'JavaScript', 'React'],
            ['MS Office'],
            ['JavaScript', 'React', 'Node.js', 'SQL', 'Python']
        ];
        $pipeline_keys = ['Applied', 'Screening', 'Shortlisted', 'Interview', 'Offer', 'Joined', 'Rejected'];
        $status_keys = ['Active', 'On Hold', 'Withdrawn', 'Hired', 'Not Suitable', 'Blacklisted'];

        $insert_app = $conn->prepare("INSERT INTO applications (candidate_name, email, contact_number, position, company, experience, academic_qualification, technical_qualification, expected_salary, nationality, current_location, stage_id, status_id, next_action, next_action_date, applied_date, joined_date, notes, assigned_to, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $app_ids = [];
        for ($i = 1; $i <= 40; $i++) {
            $candidateName = "Candidate $i";
            $email = "candidate$i@demo.com";
            $contact = '0300' . str_pad(1000000 + $i, 7, '0', STR_PAD_LEFT);
            $position = $positions[($i - 1) % count($positions)];
            $company = $companies[($i - 1) % count($companies)];
            $exp = $experiences[($i - 1) % count($experiences)];
            $acad = json_encode($academic_pool[($i - 1) % count($academic_pool)]);
            $tech = json_encode($tech_pool[($i - 1) % count($tech_pool)]);
            $salary = (string)((($i % 10) + 3) * 10000); // 30000-130000
            $nationality = 'Demo Country';
            $location = 'Demo City';
            $stage = $pipeline_keys[($i - 1) % count($pipeline_keys)];
            $status = $status_keys[($i - 1) % count($status_keys)];
            $sid = $stage_ids[$stage] ?? 1;
            $stid = $status_ids[$status] ?? 8;
            $appliedDate = date('Y-m-d', strtotime('-' . ($i * 2) . ' days'));
            $joinedDate = ($stage === 'Joined') ? date('Y-m-d', strtotime('-' . max(1, ($i - 5)) . ' days')) : null;
            $nextAction = $joinedDate ? null : "Sample next action $i";
            $nextActionDate = $joinedDate ? null : date('Y-m-d', strtotime('+' . (($i % 14) + 1) . ' days'));
            $notes = "Sample note for Candidate $i";
            $assignedTo = $user_ids[($i - 1) % count($user_ids)];

            $insert_app->bind_param("sssssssssssiisssssii",
                $candidateName, $email, $contact, $position, $company, $exp,
                $acad, $tech, $salary, $nationality, $location,
                $sid, $stid, $nextAction, $nextActionDate, $appliedDate, $joinedDate,
                $notes, $assignedTo, $admin_id
            );
            if ($insert_app->execute()) {
                $app_ids[] = $conn->insert_id;
            }
        }
        $insert_app->close();

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Inserted ' . count($app_ids) . ' demo applications</div>';

        // ====== INSERT DEMO DOCUMENTS ======
        echo '<br><div class="log-item log-info"><i class="fas fa-file-upload"></i> <strong>Inserting demo documents...</strong></div>';

        $doc_labels = ['CV', 'Cover Letter', 'Certificate', 'Portfolio', 'ID Proof'];
        $doc_meta = [
            ['ext' => 'pdf', 'mime' => 'application/pdf'],
            ['ext' => 'jpg', 'mime' => 'image/jpeg'],
            ['ext' => 'doc', 'mime' => 'application/msword'],
            ['ext' => 'png', 'mime' => 'image/png'],
        ];

        $insert_doc = $conn->prepare("INSERT INTO application_documents (application_id, file_name, file_path, file_type, file_size_kb, document_label, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $docs_inserted = 0;

        for ($i = 1; $i <= 40; $i++) {
            $appId = $app_ids[($i - 1) % count($app_ids)];
            $label = $doc_labels[($i - 1) % count($doc_labels)];
            $meta = $doc_meta[($i - 1) % count($doc_meta)];
            $fileName = "sample_doc_$i." . $meta['ext'];
            $filePath = "uploads/documents/sample_doc_$i." . $meta['ext'];
            $size = ($i * 50) + 100;
            $uploadedBy = $user_ids[($i - 1) % count($user_ids)];

            $insert_doc->bind_param("isssisi", $appId, $fileName, $filePath, $meta['mime'], $size, $label, $uploadedBy);
            if ($insert_doc->execute()) {
                $docs_inserted++;
            }
        }
        $insert_doc->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Inserted ' . $docs_inserted . ' demo documents</div>';

        // ====== INSERT DEMO ACTIVITY LOGS ======
        echo '<br><div class="log-item log-info"><i class="fas fa-history"></i> <strong>Inserting demo activity logs...</strong></div>';

        $log_actions = ['CREATE', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'VIEW'];
        $log_modules = ['applications', 'users', 'stages', 'settings', 'auth', 'documents'];

        $insert_log = $conn->prepare("INSERT INTO activity_logs (user_id, application_id, action, module, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $logs_inserted = 0;
        $sample_ip = '127.0.0.1';

        for ($i = 1; $i <= 40; $i++) {
            $uid = $user_ids[($i - 1) % count($user_ids)];
            $appId = $app_ids[($i - 1) % count($app_ids)];
            $action = $log_actions[($i - 1) % count($log_actions)];
            $module = $log_modules[($i - 1) % count($log_modules)];
            $desc = "Sample activity log entry $i";

            $insert_log->bind_param("iissss", $uid, $appId, $action, $module, $desc, $sample_ip);
            if ($insert_log->execute()) {
                $logs_inserted++;
            }
        }
        $insert_log->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Inserted ' . $logs_inserted . ' demo activity logs</div>';

        echo '<div class="credentials-box">';
        echo '<strong><i class="fas fa-database"></i> Seed Summary:</strong><br><br>';
        echo '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        echo '<tr style="background:var(--navy-primary); color:white;"><th style="padding:8px; text-align:left;">Table</th><th style="padding:8px; text-align:left;">Records</th></tr>';
        $summary = [
            ['users', count($user_ids) . ' (1 admin + 39 users)'],
            ['stages', count($stage_ids) + count($status_ids) . ' (' . count($stage_ids) . ' pipeline + ' . count($status_ids) . ' status)'],
            ['applications', count($app_ids)],
            ['application_documents', $docs_inserted],
            ['activity_logs', $logs_inserted],
            ['system_settings', '1'],
        ];
        foreach ($summary as $i => $row) {
            $bg = $i % 2 === 0 ? '#f8f9fa' : 'white';
            echo '<tr style="background:' . $bg . ';">';
            echo '<td style="padding:6px 8px;"><strong>' . $row[0] . '</strong></td>';
            echo '<td style="padding:6px 8px;">' . $row[1] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        // ====== UPLOAD DIRECTORIES ======
        echo '<br><div class="log-item log-info"><i class="fas fa-folder"></i> <strong>Creating upload directories...</strong></div>';

        $upload_base = __DIR__ . '/uploads';
        $upload_profiles = __DIR__ . '/uploads/profiles';
        $upload_documents = __DIR__ . '/uploads/documents';

        foreach ([$upload_base, $upload_profiles, $upload_documents] as $dir) {
            $dir_name = str_replace(__DIR__ . '/', '', $dir);
            if (!file_exists($dir)) {
                if (mkdir($dir, 0755, true)) {
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Directory "' . $dir_name . '/" created</div>';
                } else {
                    echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create "' . $dir_name . '/"</div>';
                }
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Directory "' . $dir_name . '/" already exists</div>';
            }
        }

        // Test write permissions
        $test_file = $upload_profiles . '/test.txt';
        if (file_put_contents($test_file, 'test') !== false) {
            unlink($test_file);
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Upload directory is writable</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Warning: Upload directory may not be writable</div>';
        }

        // Create .htaccess for security
        $htaccess_content = "# Protect uploads directory\n";
        $htaccess_content .= "<Files ~ \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|htm|shtml|sh|cgi)$\">\n";
        $htaccess_content .= "    deny from all\n";
        $htaccess_content .= "</Files>\n";
        $htaccess_content .= "\n# Allow image and document files only\n";
        $htaccess_content .= "<FilesMatch \"\\.(jpg|jpeg|png|gif|webp|pdf|doc|docx)$\">\n";
        $htaccess_content .= "    allow from all\n";
        $htaccess_content .= "</FilesMatch>\n";

        $htaccess_file = $upload_base . '/.htaccess';
        if (file_put_contents($htaccess_file, $htaccess_content) !== false) {
            echo '<div class="log-item log-success"><i class="fas fa-shield-alt"></i> Security .htaccess created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Warning: Could not create .htaccess</div>';
        }

        $conn->close();

        echo '<br><div class="log-item log-success">';
        echo '<i class="fas fa-check-circle"></i> <strong>Setup completed successfully!</strong>';
        echo '</div>';
        ?>

        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Go to Login Page
        </a>
    </div>

    <!-- Theme Toggle Button -->
    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    </div>

    <script>
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.body.classList.add('dark-mode');
            updateThemeIcon(true);
        }
    }

    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    }

    function updateThemeIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    initTheme();
    </script>
</body>
</html>
