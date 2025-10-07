<?php
/**
 * TappTrak Cron Job - Overstay Monitor
 * This script should be run every 5-10 minutes via cron job
 * 
 * To set up cron job, add this line to your crontab:
 * */5 * * * * /usr/bin/php /path/to/your/tapptrak/cron_monitor.php
 * 
 * Or for Windows Task Scheduler:
 * Run every 5 minutes: php.exe "C:\xampp\htdocs\tapptrak\cron_monitor.php"
 */

// Set execution time limit
set_time_limit(300); // 5 minutes

// Log file for cron job monitoring
$log_file = __DIR__ . '/logs/cron_monitor.log';

// Create logs directory if it doesn't exist
if (!file_exists(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Function to write to log file
function writeLog($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
}

// Start monitoring
writeLog("Starting TappTrak overstay monitoring...");

try {
    // Include required files
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/mailservice.php';
    
    $db = Database::getInstance();
    writeLog("Database connection established");
    
    // Get system settings
    $max_visit_duration = (int)getSystemSetting('max_visit_duration', 240);
    $overstay_warning_time = (int)getSystemSetting('overstay_warning_time', 180);
    $enable_notifications = getSystemSetting('enable_notifications', '1') === '1';
    $email_notifications = getSystemSetting('email_notifications', '1') === '1';
    
    writeLog("System settings loaded - Max duration: {$max_visit_duration}min, Warning time: {$overstay_warning_time}min");
    
    // Check for visitors who have overstayed
    $sql = "SELECT 
                vl.id,
                vl.visitor_id,
                vl.flat_id,
                vl.check_in_time,
                vl.expected_duration,
                vl.status,
                v.full_name as visitor_name,
                v.phone as visitor_phone,
                f.flat_number,
                f.owner_name,
                f.owner_email,
                g.full_name as guard_name,
                g.phone as guard_phone
            FROM visitor_logs vl
            JOIN visitors v ON vl.visitor_id = v.id
            JOIN flats f ON vl.flat_id = f.id
            JOIN guards g ON vl.guard_id = g.id
            WHERE vl.status = 'inside' 
            AND vl.check_out_time IS NULL
            AND TIMESTAMPDIFF(MINUTE, vl.check_in_time, NOW()) > vl.expected_duration";
    
    $result = $db->query($sql);
    $overstays = [];
    while ($row = $result->fetch_assoc()) {
        $overstays[] = $row;
    }
    
    writeLog("Found " . count($overstays) . " overstayed visitors");
    
    $mailService = new MailService();
    $alerts_created = 0;
    $emails_sent = 0;
    
    foreach ($overstays as $overstay) {
        $checkin_time = strtotime($overstay['check_in_time']);
        $current_time = time();
        $overstay_minutes = floor(($current_time - $checkin_time) / 60);
        
        writeLog("Processing overstay: {$overstay['visitor_name']} (ID: {$overstay['id']}) - {$overstay_minutes} minutes");
        
        // Update status to overstayed if not already
        if ($overstay['status'] !== 'overstayed') {
            $update_sql = "UPDATE visitor_logs SET status = 'overstayed' WHERE id = ?";
            $stmt = $db->prepare($update_sql);
            $stmt->bind_param("i", $overstay['id']);
            $stmt->execute();
            $stmt->close();
            writeLog("Updated visitor status to overstayed");
        }
        
        // Check if alert already exists for this visitor log
        $alert_check_sql = "SELECT id FROM alerts WHERE related_visitor_log_id = ? AND status = 'active'";
        $stmt = $db->prepare($alert_check_sql);
        $stmt->bind_param("i", $overstay['id']);
        $stmt->execute();
        $alert_result = $stmt->get_result();
        
        if ($alert_result->num_rows === 0) {
            // Create alert
            $alert_sql = "INSERT INTO alerts (alert_type_id, title, message, severity, related_visitor_log_id, related_flat_id, created_by) 
                          VALUES (1, 'Visitor Overstayed', ?, 'high', ?, ?, 1)";
            
            $message = "Visitor {$overstay['visitor_name']} has exceeded their allocated time and is still on the premises.";
            $stmt = $db->prepare($alert_sql);
            $stmt->bind_param("sii", $message, $overstay['id'], $overstay['flat_id']);
            $stmt->execute();
            $stmt->close();
            $alerts_created++;
            writeLog("Created alert for overstayed visitor");
        }
        
        // Send email notification if enabled
        if ($enable_notifications && $email_notifications) {
            try {
                if ($mailService->sendVisitorOverstayAlert($overstay['id'])) {
                    $emails_sent++;
                    writeLog("Email notification sent for overstayed visitor");
                } else {
                    writeLog("Failed to send email notification");
                }
            } catch (Exception $e) {
                writeLog("Email error: " . $e->getMessage());
            }
        }
    }
    
    // Check for visitors approaching overstay warning time
    $warning_sql = "SELECT 
                        vl.id,
                        vl.visitor_id,
                        vl.flat_id,
                        vl.check_in_time,
                        vl.expected_duration,
                        v.full_name as visitor_name,
                        f.flat_number
                    FROM visitor_logs vl
                    JOIN visitors v ON vl.visitor_id = v.id
                    JOIN flats f ON vl.flat_id = f.id
                    WHERE vl.status = 'inside' 
                    AND vl.check_out_time IS NULL
                    AND TIMESTAMPDIFF(MINUTE, vl.check_in_time, NOW()) >= vl.expected_duration - 30
                    AND TIMESTAMPDIFF(MINUTE, vl.check_in_time, NOW()) < vl.expected_duration";
    
    $warning_result = $db->query($warning_sql);
    $warnings = [];
    while ($row = $warning_result->fetch_assoc()) {
        $warnings[] = $row;
    }
    
    writeLog("Found " . count($warnings) . " visitors approaching overstay");
    
    // Create warning alerts for visitors approaching overstay
    foreach ($warnings as $warning) {
        $checkin_time = strtotime($warning['check_in_time']);
        $current_time = time();
        $minutes_inside = floor(($current_time - $checkin_time) / 60);
        $remaining_minutes = $warning['expected_duration'] - $minutes_inside;
        
        // Check if warning alert already exists
        $warning_alert_sql = "SELECT id FROM alerts WHERE related_visitor_log_id = ? AND alert_type_id = 2 AND status = 'active'";
        $stmt = $db->prepare($warning_alert_sql);
        $stmt->bind_param("i", $warning['id']);
        $stmt->execute();
        $warning_alert_result = $stmt->get_result();
        
        if ($warning_alert_result->num_rows === 0) {
            $warning_alert_sql = "INSERT INTO alerts (alert_type_id, title, message, severity, related_visitor_log_id, related_flat_id, created_by) 
                                  VALUES (2, 'Visitor Approaching Overstay', ?, 'medium', ?, ?, 1)";
            
            $warning_message = "Visitor {$warning['visitor_name']} will exceed their allocated time in {$remaining_minutes} minutes.";
            $stmt = $db->prepare($warning_alert_sql);
            $stmt->bind_param("sii", $warning_message, $warning['id'], $warning['flat_id']);
            $stmt->execute();
            $stmt->close();
            writeLog("Created warning alert for visitor approaching overstay");
        }
    }
    
    // Clean up old resolved alerts (older than 30 days)
    $cleanup_sql = "DELETE FROM alerts WHERE status IN ('resolved', 'dismissed') AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $cleanup_result = $db->query($cleanup_sql);
    $cleaned_alerts = $db->getAffectedRows();
    
    if ($cleaned_alerts > 0) {
        writeLog("Cleaned up {$cleaned_alerts} old alerts");
    }
    
    // Clean up old audit logs (older than 90 days)
    $cleanup_audit_sql = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)";
    $cleanup_audit_result = $db->query($cleanup_audit_sql);
    $cleaned_audit = $db->getAffectedRows();
    
    if ($cleaned_audit > 0) {
        writeLog("Cleaned up {$cleaned_audit} old audit logs");
    }
    
    // Summary
    writeLog("Monitoring completed successfully:");
    writeLog("- Overstayed visitors processed: " . count($overstays));
    writeLog("- Warning alerts created: " . count($warnings));
    writeLog("- New alerts created: " . $alerts_created);
    writeLog("- Emails sent: " . $emails_sent);
    writeLog("- Old alerts cleaned: " . $cleaned_alerts);
    writeLog("- Old audit logs cleaned: " . $cleaned_audit);
    
} catch (Exception $e) {
    writeLog("ERROR: " . $e->getMessage());
    writeLog("Stack trace: " . $e->getTraceAsString());
}

writeLog("TappTrak overstay monitoring finished");
writeLog("=====================================");
?>
