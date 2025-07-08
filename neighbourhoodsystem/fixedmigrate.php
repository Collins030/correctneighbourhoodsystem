<?php
// migrate.php - Database migration script for neighbourhood system with OTP verification

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'neighbourhood_system';

try {
    // Create connection
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $database");
    $pdo->exec("USE $database");
    
    echo "Database '$database' created successfully or already exists.\n";
    
    // Create users table with OTP verification support
    $createUsersTable = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            address TEXT,
            phone VARCHAR(20),
            otp_code VARCHAR(6) DEFAULT NULL,
            otp_expiry DATETIME DEFAULT NULL,
            is_verified BOOLEAN DEFAULT FALSE,
            is_active BOOLEAN DEFAULT TRUE,
            email_verified_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_users_email (email),
            INDEX idx_users_username (username),
            INDEX idx_users_otp (otp_code),
            INDEX idx_users_verified (is_verified)
        )
    ";
    
    $pdo->exec($createUsersTable);
    echo "Users table created successfully with OTP support.\n";
    
    // If users table already exists, add OTP columns
    $alterUsersTable = "
        ALTER TABLE users 
        ADD COLUMN IF NOT EXISTS otp_code VARCHAR(6) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS otp_expiry DATETIME DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS is_verified BOOLEAN DEFAULT FALSE,
        ADD COLUMN IF NOT EXISTS email_verified_at DATETIME DEFAULT NULL
    ";
    
    try {
        $pdo->exec($alterUsersTable);
        echo "Added OTP columns to existing users table.\n";
    } catch(PDOException $e) {
        // Columns might already exist, continue
        echo "OTP columns may already exist in users table.\n";
    }
    
    // Add indexes for better performance
    $addIndexes = [
        "CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)",
        "CREATE INDEX IF NOT EXISTS idx_users_username ON users(username)",
        "CREATE INDEX IF NOT EXISTS idx_users_otp ON users(otp_code)",
        "CREATE INDEX IF NOT EXISTS idx_users_verified ON users(is_verified)"
    ];
    
    foreach ($addIndexes as $indexQuery) {
        try {
            $pdo->exec($indexQuery);
        } catch(PDOException $e) {
            // Index might already exist
        }
    }
    echo "Added indexes to users table.\n";
    
    // Create OTP attempts table for security logging
    $createOtpAttemptsTable = "
        CREATE TABLE IF NOT EXISTS otp_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45),
            attempt_type ENUM('send', 'verify', 'resend') NOT NULL,
            success BOOLEAN DEFAULT FALSE,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_otp_email (email),
            INDEX idx_otp_ip (ip_address),
            INDEX idx_otp_attempts_time (attempted_at)
        )
    ";
    
    $pdo->exec($createOtpAttemptsTable);
    echo "OTP attempts table created successfully.\n";
    
    // Create email templates table
    $createEmailTemplatesTable = "
        CREATE TABLE IF NOT EXISTS email_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_name VARCHAR(50) UNIQUE NOT NULL,
            subject VARCHAR(200) NOT NULL,
            body_html TEXT NOT NULL,
            body_text TEXT NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ";
    
    $pdo->exec($createEmailTemplatesTable);
    echo "Email templates table created successfully.\n";
    
    // Insert default OTP email template
    $insertEmailTemplate = "
        INSERT INTO email_templates (template_name, subject, body_html, body_text) 
        VALUES (
            'otp_verification',
            'Verify Your Email - Neighbourhood Connect',
            '<html><head><style>body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; } .container { max-width: 600px; margin: 0 auto; padding: 20px; } .header { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; } .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; } .otp-code { background: #fff; border: 2px solid #4facfe; padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0; } .otp-number { font-size: 32px; font-weight: bold; color: #4facfe; letter-spacing: 5px; } .footer { text-align: center; margin-top: 20px; color: #666; font-size: 14px; }</style></head><body><div class=\"container\"><div class=\"header\"><h1>🏘️ Neighbourhood Connect</h1><p>Email Verification</p></div><div class=\"content\"><h2>Hello {{FULL_NAME}}!</h2><p>Thank you for registering with Neighbourhood Connect. To complete your registration, please verify your email address using the code below:</p><div class=\"otp-code\"><div class=\"otp-number\">{{OTP_CODE}}</div><p><strong>This code will expire in 15 minutes</strong></p></div><p>If you didn\\'t create an account with us, please ignore this email.</p><div class=\"footer\"><p>Best regards,<br>The Neighbourhood Connect Team</p></div></div></div></body></html>',
            'Hello {{FULL_NAME}}!\\n\\nThank you for registering with Neighbourhood Connect. Your verification code is: {{OTP_CODE}}\\n\\nThis code will expire in 15 minutes.\\n\\nIf you didn\\'t create an account with us, please ignore this email.\\n\\nBest regards,\\nThe Neighbourhood Connect Team'
        ) ON DUPLICATE KEY UPDATE 
            subject = VALUES(subject),
            body_html = VALUES(body_html),
            body_text = VALUES(body_text)
    ";
    
    $pdo->exec($insertEmailTemplate);
    echo "Default OTP email template inserted successfully.\n";
    
    // Drop existing events table and related tables to recreate with proper structure
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("DROP TABLE IF EXISTS event_attendees");
    $pdo->exec("DROP TABLE IF EXISTS community_messages");
    $pdo->exec("DROP TABLE IF EXISTS message_replies");
    $pdo->exec("DROP TABLE IF EXISTS neighbourhood_locations");
    $pdo->exec("DROP TABLE IF EXISTS events");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "Dropped existing events-related tables.\n";
    
    // Create events table with complete structure
    $createEventsTable = "
        CREATE TABLE IF NOT EXISTS events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT,
            event_date DATETIME NOT NULL,
            end_date DATETIME,
            location VARCHAR(200),
            address TEXT,
            max_attendees INT DEFAULT NULL,
            current_attendees INT DEFAULT 0,
            image_url VARCHAR(500),
            status ENUM('active', 'cancelled', 'completed') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ";
    
    $pdo->exec($createEventsTable);
    echo "Events table created successfully.\n";
    
    // Create event_attendees table
    $createEventAttendeesTable = "
        CREATE TABLE IF NOT EXISTS event_attendees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            user_id INT NOT NULL,
            status ENUM('attending', 'maybe', 'not_attending') DEFAULT 'attending',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_event_user (event_id, user_id),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ";
    
    $pdo->exec($createEventAttendeesTable);
    echo "Event attendees table created successfully.\n";
    
    // Create community_messages table
    $createMessagesTable = "
        CREATE TABLE IF NOT EXISTS community_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            message_type ENUM('general', 'announcement', 'question', 'event_related') DEFAULT 'general',
            event_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
        )
    ";
    
    $pdo->exec($createMessagesTable);
    echo "Community messages table created successfully.\n";
    
    // Create message_replies table
    $createMessageRepliesTable = "
        CREATE TABLE IF NOT EXISTS message_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            reply_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (message_id) REFERENCES community_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ";
    
    $pdo->exec($createMessageRepliesTable);
    echo "Message replies table created successfully.\n";
    
    // Create neighbourhood_locations table (for map feature)
    $createLocationsTable = "
        CREATE TABLE IF NOT EXISTS neighbourhood_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            address TEXT,
            latitude DECIMAL(10, 8),
            longitude DECIMAL(11, 8),
            location_type ENUM('event', 'landmark', 'business', 'community_center') DEFAULT 'event',
            event_id INT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        )
    ";
    
    $pdo->exec($createLocationsTable);
    echo "Neighbourhood locations table created successfully.\n";
    
    // Create sessions table for session management
    $createSessionsTable = "
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_token VARCHAR(128) UNIQUE NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ";
    
    $pdo->exec($createSessionsTable);
    echo "Sessions table created successfully.\n";
    
    // Create notifications table
    $createNotificationsTable = "
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('event', 'message', 'system') DEFAULT 'system',
            reference_id INT NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ";
    
    $pdo->exec($createNotificationsTable);
    echo "Notifications table created successfully.\n";
    
    echo "\n=== Migration Summary ===\n";
    echo "✅ Database created/verified\n";
    echo "✅ Users table with OTP support\n";
    echo "✅ OTP attempts logging table\n";
    echo "✅ Email templates table\n";
    echo "✅ Default OTP email template\n";
    echo "✅ Events and related tables\n";
    echo "✅ Community messaging system\n";
    echo "✅ Location tracking system\n";
    echo "✅ Session management\n";
    echo "✅ Notifications system\n";
    echo "\nAll tables created successfully! Migration completed.\n";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>