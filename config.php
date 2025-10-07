<?php
/**
 * TappTrak Configuration File
 * Database connection and system settings
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'tapptrak');

// System Configuration
define('SITE_NAME', 'TappTrak');
define('SITE_URL', 'http://localhost/tapptrak');
define('ADMIN_EMAIL', 'admin@tapptrak.com');

// Security Settings
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('PASSWORD_MIN_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

// File Upload Settings
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB in bytes
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error Reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Database Connection Class using MySQLi
 */
class Database {
    private $connection;
    private static $instance = null;
    
    private function __construct() {
        $this->connection = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
        
        if ($this->connection->connect_error) {
            die("Connection failed: " . $this->connection->connect_error);
        }
        
        // Set charset to utf8
        $this->connection->set_charset("utf8");
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql) {
        return $this->connection->query($sql);
    }
    
    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }
    
    public function escape($string) {
        return $this->connection->real_escape_string($string);
    }
    
    public function getLastInsertId() {
        return $this->connection->insert_id;
    }
    
    public function getAffectedRows() {
        return $this->connection->affected_rows;
    }
    
    public function beginTransaction() {
        return $this->connection->begin_transaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollback();
    }
    
    public function __destruct() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}

/**
 * Utility Functions
 */
function redirect($url) {
    header("Location: " . $url);
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isLoggedIn() && $_SESSION['user_role'] === 'admin';
}

function isSecurity() {
    return isLoggedIn() && $_SESSION['user_role'] === 'security';
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('index.php');
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        redirect('dashboard.php');
    }
}

function sanitizeInput($input) {
    $db = Database::getInstance();
    return $db->escape(trim($input));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
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

function formatDateTime($datetime) {
    return date('d M Y, h:i A', strtotime($datetime));
}

function formatDate($date) {
    return date('d M Y', strtotime($date));
}

function formatTime($time) {
    return date('h:i A', strtotime($time));
}

function getTimeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    return floor($time/31536000) . ' years ago';
}

function logActivity($action, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ississss", $user_id, $action, $table_name, $record_id, $old_values, $new_values, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();
}

function getSystemSetting($key, $default = null) {
    $db = Database::getInstance();
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['setting_value'];
    }
    
    return $default;
}

function setSystemSetting($key, $value, $description = null) {
    $db = Database::getInstance();
    $sql = "INSERT INTO system_settings (setting_key, setting_value, description) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("sss", $key, $value, $description);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function checkLoginAttempts($email) {
    $db = Database::getInstance();
    $sql = "SELECT COUNT(*) as attempts FROM audit_logs 
            WHERE action = 'login_failed' 
            AND new_values LIKE ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";
    
    $email_pattern = '%"email":"' . $email . '"%';
    $lockout_time = LOGIN_LOCKOUT_TIME;
    $stmt = $db->prepare($sql);
    $stmt->bind_param("si", $email_pattern, $lockout_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['attempts'] >= MAX_LOGIN_ATTEMPTS;
}

function recordLoginAttempt($email, $success = false) {
    $action = $success ? 'login_success' : 'login_failed';
    $new_values = json_encode(['email' => $email]);
    
    logActivity($action, 'users', null, null, $new_values);
}

// Auto-logout on session timeout
if (isLoggedIn() && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        redirect('index.php?timeout=1');
    }
}

// Update last activity time
if (isLoggedIn()) {
    $_SESSION['last_activity'] = time();
}

// Create upload directory if it doesn't exist
if (!file_exists(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0755, true);
}
?>
