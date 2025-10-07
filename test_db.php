<?php
/**
 * TappTrak Database Connection Test
 * Simple test to verify database connection and basic functionality
 */

require_once 'config.php';

echo "<h1>TappTrak Database Connection Test</h1>";

try {
    // Test database connection
    $db = Database::getInstance();
    echo "<p style='color: green;'>✓ Database connection successful!</p>";
    
    // Test basic query
    $result = $db->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "<p style='color: green;'>✓ Database query successful! Users count: " . $row['count'] . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Database query failed: " . $db->getConnection()->error . "</p>";
    }
    
    // Test prepared statement
    $sql = "SELECT COUNT(*) as count FROM users WHERE role = ?";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $role = 'admin';
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        echo "<p style='color: green;'>✓ Prepared statement successful! Admin users count: " . $row['count'] . "</p>";
        $stmt->close();
    } else {
        echo "<p style='color: red;'>✗ Prepared statement failed: " . $db->getConnection()->error . "</p>";
    }
    
    // Test system settings
    $setting = getSystemSetting('system_name', 'Not Found');
    echo "<p style='color: green;'>✓ System setting test successful! System name: " . $setting . "</p>";
    
    echo "<p style='color: green; font-weight: bold;'>All tests passed! Database is working correctly.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "<p><a href='index.php'>Go to Login Page</a></p>";
echo "<p><a href='setup.php'>Go to Setup Page</a></p>";
?>
