<?php
// auth_handler.php - Handle registration and login requests with OTP verification

require_once 'config.php';
require_once 'vendor/autoload.php'; // PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';
$pdo = getDBConnection();

switch ($action) {
    case 'register':
        handleRegistration($pdo);
        break;
    case 'verify_otp':
        handleOTPVerification($pdo);
        break;
    case 'resend_otp':
        handleResendOTP($pdo);
        break;
    case 'login':
        handleLogin($pdo);
        break;
    case 'logout':
        handleLogout();
        break;
    case 'forgot_password':
        handleForgotPassword($pdo);
        break;
    case 'reset_password':
        handleResetPassword($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}

function handleRegistration($pdo) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    // Validation
    $errors = [];
    
    if (empty($username)) {
        $errors[] = 'Username is required';
    } elseif (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters long';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long';
    }
    
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }
    
    if (empty($fullName)) {
        $errors[] = 'Full name is required';
    }
    
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['error' => implode(', ', $errors)]);
        return;
    }
    
    try {
        // Check if username or email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Username or email already exists']);
            return;
        }
        
        // Generate OTP
        $otp = generateOTP();
        $otpExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes')); // OTP expires in 15 minutes
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user with is_verified = 0
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, full_name, address, phone, otp_code, otp_expiry, is_verified, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)
        ");
        $stmt->execute([$username, $email, $hashedPassword, $fullName, $address, $phone, $otp, $otpExpiry]);
        
        // Send OTP email
        if (sendOTPEmail($email, $fullName, $otp)) {
            echo json_encode([
                'success' => true,
                'message' => 'Registration successful! Please check your email for the verification code.',
                'email' => $email,
                'show_otp' => true
            ]);
        } else {
            // If email sending fails, delete the user record
            $stmt = $pdo->prepare("DELETE FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send verification email. Please try again.']);
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Registration failed. Please try again.']);
    }
}

function handleOTPVerification($pdo) {
    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    
    if (empty($email) || empty($otp)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and OTP are required']);
        return;
    }
    
    try {
        // Find user and check OTP
        $stmt = $pdo->prepare("
            SELECT id, username, full_name, otp_code, otp_expiry, is_verified 
            FROM users 
            WHERE email = ? AND is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }
        
        if ($user['is_verified']) {
            http_response_code(400);
            echo json_encode(['error' => 'Email is already verified']);
            return;
        }
        
        // Check if OTP has expired
        if (strtotime($user['otp_expiry']) < time()) {
            http_response_code(400);
            echo json_encode(['error' => 'OTP has expired. Please request a new one.']);
            return;
        }
        
        // Verify OTP
        if ($user['otp_code'] !== $otp) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid OTP. Please check and try again.']);
            return;
        }
        
        // Update user as verified and clear OTP
        $stmt = $pdo->prepare("
            UPDATE users 
            SET is_verified = 1, otp_code = NULL, otp_expiry = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        
        // Create session
        createUserSession($user['id']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Email verified successfully! Welcome to Neighbourhood Connect.',
            'redirect' => 'dashboard.php'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Verification failed. Please try again.']);
    }
}

function handleResendOTP($pdo) {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required']);
        return;
    }
    
    try {
        // Find user
        $stmt = $pdo->prepare("
            SELECT id, full_name, is_verified 
            FROM users 
            WHERE email = ? AND is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }
        
        if ($user['is_verified']) {
            http_response_code(400);
            echo json_encode(['error' => 'Email is already verified']);
            return;
        }
        
        // Generate new OTP
        $otp = generateOTP();
        $otpExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // Update user with new OTP
        $stmt = $pdo->prepare("
            UPDATE users 
            SET otp_code = ?, otp_expiry = ? 
            WHERE id = ?
        ");
        $stmt->execute([$otp, $otpExpiry, $user['id']]);
        
        // Send OTP email
        if (sendOTPEmail($email, $user['full_name'], $otp)) {
            echo json_encode([
                'success' => true,
                'message' => 'New verification code sent to your email.'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send verification email. Please try again.']);
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to resend OTP. Please try again.']);
    }
}

function handleLogin($pdo) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and password are required']);
        return;
    }
    
    try {
        // Find user by username or email
        $stmt = $pdo->prepare("
            SELECT id, username, email, password, full_name, is_active, is_verified 
            FROM users 
            WHERE (username = ? OR email = ?) AND is_active = 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($password, $user['password'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid username or password']);
            return;
        }
        
        // Check if email is verified
        if (!$user['is_verified']) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Please verify your email before logging in.',
                'email' => $user['email'],
                'show_otp' => true
            ]);
            return;
        }
        
        // Create session
        createUserSession($user['id']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => 'dashboard.php'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Login failed. Please try again.']);
    }
}

function handleForgotPassword($pdo) {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email is required']);
        return;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please enter a valid email address']);
        return;
    }
    
    try {
        // Find user
        $stmt = $pdo->prepare("
            SELECT id, full_name, is_verified 
            FROM users 
            WHERE email = ? AND is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // Don't reveal if email exists or not for security
            echo json_encode([
                'success' => true,
                'message' => 'If an account with this email exists, you will receive a password reset code shortly.'
            ]);
            return;
        }
        
        if (!$user['is_verified']) {
            http_response_code(400);
            echo json_encode(['error' => 'Please verify your email first before resetting your password.']);
            return;
        }
        
        // Generate reset OTP
        $otp = generateOTP();
        $otpExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // Update user with reset OTP
        $stmt = $pdo->prepare("
            UPDATE users 
            SET otp_code = ?, otp_expiry = ? 
            WHERE id = ?
        ");
        $stmt->execute([$otp, $otpExpiry, $user['id']]);
        
        // Send reset OTP email
        if (sendPasswordResetEmail($email, $user['full_name'], $otp)) {
            echo json_encode([
                'success' => true,
                'message' => 'If an account with this email exists, you will receive a password reset code shortly.',
                'email' => $email,
                'show_reset' => true
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send reset code. Please try again.']);
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to process request. Please try again.']);
    }
}

function handleResetPassword($pdo) {
    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($email) || empty($otp) || empty($newPassword) || empty($confirmPassword)) {
        http_response_code(400);
        echo json_encode(['error' => 'All fields are required']);
        return;
    }
    
    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters long']);
        return;
    }
    
    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['error' => 'Passwords do not match']);
        return;
    }
    
    try {
        // Find user and check OTP
        $stmt = $pdo->prepare("
            SELECT id, username, full_name, otp_code, otp_expiry, is_verified 
            FROM users 
            WHERE email = ? AND is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }
        
        if (!$user['is_verified']) {
            http_response_code(400);
            echo json_encode(['error' => 'Please verify your email first']);
            return;
        }
        
        // Check if OTP has expired
        if (strtotime($user['otp_expiry']) < time()) {
            http_response_code(400);
            echo json_encode(['error' => 'Reset code has expired. Please request a new one.']);
            return;
        }
        
        // Verify OTP
        if ($user['otp_code'] !== $otp) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid reset code. Please check and try again.']);
            return;
        }
        
        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password and clear OTP
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password = ?, otp_code = NULL, otp_expiry = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$hashedPassword, $user['id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Password reset successful! You can now login with your new password.',
            'redirect' => 'login'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Password reset failed. Please try again.']);
    }
}

function handleLogout() {
    destroyUserSession();
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully',
        'redirect' => 'index.php'
    ]);
}

function generateOTP($length = 6) {
    return str_pad(mt_rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

function sendOTPEmail($email, $fullName, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST; // Set in config.php
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME; // Set in config.php
        $mail->Password   = SMTP_PASSWORD; // Set in config.php
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT; // Set in config.php
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME); // Set in config.php
        $mail->addAddress($email, $fullName);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email - Neighbourhood Connect';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .otp-code { background: #fff; border: 2px solid #4facfe; padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0; }
                .otp-number { font-size: 32px; font-weight: bold; color: #4facfe; letter-spacing: 5px; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🏘️ Neighbourhood Connect</h1>
                    <p>Email Verification</p>
                </div>
                <div class='content'>
                    <h2>Hello $fullName!</h2>
                    <p>Thank you for registering with Neighbourhood Connect. To complete your registration, please verify your email address using the code below:</p>
                    
                    <div class='otp-code'>
                        <div class='otp-number'>$otp</div>
                        <p><strong>This code will expire in 15 minutes</strong></p>
                    </div>
                    
                    <p>If you didn't create an account with us, please ignore this email.</p>
                    
                    <div class='footer'>
                        <p>Best regards,<br>The Neighbourhood Connect Team</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Hello $fullName!\n\nThank you for registering with Neighbourhood Connect. Your verification code is: $otp\n\nThis code will expire in 15 minutes.\n\nIf you didn't create an account with us, please ignore this email.\n\nBest regards,\nThe Neighbourhood Connect Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

function sendPasswordResetEmail($email, $fullName, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $fullName);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset - Neighbourhood Connect';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .otp-code { background: #fff; border: 2px solid #ff6b6b; padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0; }
                .otp-number { font-size: 32px; font-weight: bold; color: #ff6b6b; letter-spacing: 5px; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 14px; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🏘️ Neighbourhood Connect</h1>
                    <p>Password Reset Request</p>
                </div>
                <div class='content'>
                    <h2>Hello $fullName!</h2>
                    <p>We received a request to reset your password for your Neighbourhood Connect account. Use the code below to reset your password:</p>
                    
                    <div class='otp-code'>
                        <div class='otp-number'>$otp</div>
                        <p><strong>This code will expire in 15 minutes</strong></p>
                    </div>
                    
                    <div class='warning'>
                        <strong>Security Notice:</strong> If you didn't request this password reset, please ignore this email and your password will remain unchanged.
                    </div>
                    
                    <div class='footer'>
                        <p>Best regards,<br>The Neighbourhood Connect Team</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Hello $fullName!\n\nWe received a request to reset your password for your Neighbourhood Connect account. Your reset code is: $otp\n\nThis code will expire in 15 minutes.\n\nIf you didn't request this password reset, please ignore this email.\n\nBest regards,\nThe Neighbourhood Connect Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Password reset email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>