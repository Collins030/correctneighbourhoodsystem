
<?php
// config.php - Database and SMTP configuration

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'neighbourhood_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// SMTP configuration for email sending
define('SMTP_HOST', 'smtp.gmail.com'); // Change to your SMTP server
define('SMTP_PORT', 587); // 587 for TLS, 465 for SSL
define('SMTP_USERNAME', ''); // Your email
define('SMTP_PASSWORD', ''); // Your app password (not regular password)
define('SMTP_FROM_EMAIL', ''); // From email address
define('SMTP_FROM_NAME', 'Neighbourhood Connect'); // From name

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Database connection
function getDBConnection() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch(PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        die("Database connection failed");
    }
}

// Session management functions
function createUserSession($userId) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
}

function verifyUserSession() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
        return false;
    }
    
    // Check if session is too old (24 hours)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 86400) {
        destroyUserSession();
        return false;
    }
    
    // Get user data
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, username, email, full_name, is_active, is_verified 
        FROM users 
        WHERE id = ? AND is_active = 1 AND is_verified = 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        destroyUserSession();
        return false;
    }
    
    return $user;
}

function destroyUserSession() {
    $_SESSION = array();
    
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    session_destroy();
}

// Security functions
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Error logging
function logError($message) {
    error_log(date('Y-m-d H:i:s') . " - " . $message . PHP_EOL, 3, 'errors.log');
}

// Email validation
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Rate limiting (simple implementation)
function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 900) { // 15 minutes
    $rateLimitFile = 'rate_limits.json';
    $rateLimits = [];
    
    if (file_exists($rateLimitFile)) {
        $rateLimits = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    }
    
    $now = time();
    $identifier = md5($identifier);
    
    // Clean old entries
    foreach ($rateLimits as $id => $data) {
        if ($now - $data['time'] > $timeWindow) {
            unset($rateLimits[$id]);
        }
    }
    
    // Check current attempts
    if (isset($rateLimits[$identifier])) {
        if ($rateLimits[$identifier]['attempts'] >= $maxAttempts) {
            return false;
        }
        $rateLimits[$identifier]['attempts']++;
    } else {
        $rateLimits[$identifier] = ['attempts' => 1, 'time' => $now];
    }
    
    // Save rate limits
    file_put_contents($rateLimitFile, json_encode($rateLimits));
    
    return true;
}
?>