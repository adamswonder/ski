<?php
/**
 * Database Setup Script for Technical Skills
 * Run this once to add the technical_skills setting
 */

require_once 'config.php';

$conn = getDBConnection();

$sql = "INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('technical_skills', '[\"HTML/CSS\",\"JavaScript\",\"PHP\",\"Python\",\"Java\",\"SQL\",\"React\",\"Node.js\",\"WordPress\",\"MS Office\",\"Data Entry\",\"Graphic Design\"]')
        ON DUPLICATE KEY UPDATE setting_value = setting_value";

if ($conn->query($sql)) {
    echo "✅ Technical skills setting added successfully!<br>";
    echo "You can now delete this file (setup_skills.php)";
} else {
    echo "❌ Error: " . $conn->error;
}

$conn->close();
?>
