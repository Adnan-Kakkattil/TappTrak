<?php
/**
 * TappTrak Mail Service
 * Email notification system for alerts and notifications
 */

require_once 'config.php';

class MailService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Send email notification
     */
    public function sendEmail($to, $subject, $message, $headers = null) {
        if (!$headers) {
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: " . SITE_NAME . " <noreply@" . parse_url(SITE_URL, PHP_URL_HOST) . ">" . "\r\n";
        }
        
        $result = mail($to, $subject, $message, $headers);
        
        // Log email attempt
        logActivity('email_sent', 'mail_logs', null, null, json_encode([
            'to' => $to,
            'subject' => $subject,
            'success' => $result
        ]));
        
        return $result;
    }
    
    /**
     * Send visitor overstay alert
     */
    public function sendVisitorOverstayAlert($visitor_log_id) {
        $sql = "SELECT 
                    vl.*,
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
                WHERE vl.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $visitor_log_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $visitor_log = $result->fetch_assoc();
        $stmt->close();
        
        if (!$visitor_log) {
            return false;
        }
        
        // Calculate overstay time
        $checkin_time = strtotime($visitor_log['check_in_time']);
        $current_time = time();
        $overstay_minutes = floor(($current_time - $checkin_time) / 60);
        $expected_duration = $visitor_log['expected_duration'];
        
        // Get admin and security emails
        $admin_emails = $this->getAdminEmails();
        $security_emails = $this->getSecurityEmails();
        $all_emails = array_merge($admin_emails, $security_emails);
        
        $subject = "🚨 Visitor Overstay Alert - " . SITE_NAME;
        
        $message = $this->getOverstayEmailTemplate($visitor_log, $overstay_minutes, $expected_duration);
        
        $success_count = 0;
        foreach ($all_emails as $email) {
            if ($this->sendEmail($email, $subject, $message)) {
                $success_count++;
            }
        }
        
        return $success_count > 0;
    }
    
    /**
     * Send visitor check-in notification
     */
    public function sendVisitorCheckinNotification($visitor_log_id) {
        $sql = "SELECT 
                    vl.*,
                    v.full_name as visitor_name,
                    v.phone as visitor_phone,
                    f.flat_number,
                    f.owner_name,
                    f.owner_email,
                    g.full_name as guard_name
                FROM visitor_logs vl
                JOIN visitors v ON vl.visitor_id = v.id
                JOIN flats f ON vl.flat_id = f.id
                JOIN guards g ON vl.guard_id = g.id
                WHERE vl.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $visitor_log_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $visitor_log = $result->fetch_assoc();
        $stmt->close();
        
        if (!$visitor_log) {
            return false;
        }
        
        // Send to flat owner if email exists
        if ($visitor_log['owner_email']) {
            $subject = "👤 Visitor Check-in Notification - " . SITE_NAME;
            $message = $this->getCheckinEmailTemplate($visitor_log);
            $this->sendEmail($visitor_log['owner_email'], $subject, $message);
        }
        
        return true;
    }
    
    /**
     * Send visitor check-out notification
     */
    public function sendVisitorCheckoutNotification($visitor_log_id) {
        $sql = "SELECT 
                    vl.*,
                    v.full_name as visitor_name,
                    v.phone as visitor_phone,
                    f.flat_number,
                    f.owner_name,
                    f.owner_email,
                    g.full_name as guard_name
                FROM visitor_logs vl
                JOIN visitors v ON vl.visitor_id = v.id
                JOIN flats f ON vl.flat_id = f.id
                JOIN guards g ON vl.guard_id = g.id
                WHERE vl.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $visitor_log_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $visitor_log = $result->fetch_assoc();
        $stmt->close();
        
        if (!$visitor_log) {
            return false;
        }
        
        // Send to flat owner if email exists
        if ($visitor_log['owner_email']) {
            $subject = "👋 Visitor Check-out Notification - " . SITE_NAME;
            $message = $this->getCheckoutEmailTemplate($visitor_log);
            $this->sendEmail($visitor_log['owner_email'], $subject, $message);
        }
        
        return true;
    }
    
    /**
     * Get admin emails
     */
    private function getAdminEmails() {
        $sql = "SELECT email FROM users WHERE role = 'admin' AND is_active = 1";
        $result = $this->db->query($sql);
        $emails = [];
        while ($row = $result->fetch_assoc()) {
            $emails[] = $row['email'];
        }
        return $emails;
    }
    
    /**
     * Get security emails
     */
    private function getSecurityEmails() {
        $sql = "SELECT email FROM users WHERE role = 'security' AND is_active = 1";
        $result = $this->db->query($sql);
        $emails = [];
        while ($row = $result->fetch_assoc()) {
            $emails[] = $row['email'];
        }
        return $emails;
    }
    
    /**
     * Get overstay email template
     */
    private function getOverstayEmailTemplate($visitor_log, $overstay_minutes, $expected_duration) {
        $checkin_time = date('d M Y, h:i A', strtotime($visitor_log['check_in_time']));
        $current_time = date('d M Y, h:i A');
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4FD1C7; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
                .alert { background: #fee; border: 1px solid #fcc; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .info { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🚨 Visitor Overstay Alert</h1>
                    <p>" . SITE_NAME . " Security System</p>
                </div>
                <div class='content'>
                    <div class='alert'>
                        <h2>⚠️ Immediate Attention Required</h2>
                        <p>A visitor has exceeded their allocated time and is still on the premises.</p>
                    </div>
                    
                    <div class='info'>
                        <h3>Visitor Information:</h3>
                        <p><strong>Name:</strong> " . htmlspecialchars($visitor_log['visitor_name']) . "</p>
                        <p><strong>Phone:</strong> " . htmlspecialchars($visitor_log['visitor_phone']) . "</p>
                        <p><strong>Flat:</strong> " . htmlspecialchars($visitor_log['flat_number']) . "</p>
                        <p><strong>Flat Owner:</strong> " . htmlspecialchars($visitor_log['owner_name']) . "</p>
                        <p><strong>Purpose:</strong> " . htmlspecialchars($visitor_log['purpose']) . "</p>
                    </div>
                    
                    <div class='info'>
                        <h3>Time Information:</h3>
                        <p><strong>Check-in Time:</strong> " . $checkin_time . "</p>
                        <p><strong>Expected Duration:</strong> " . $expected_duration . " minutes</p>
                        <p><strong>Overstay Time:</strong> " . $overstay_minutes . " minutes</p>
                        <p><strong>Current Time:</strong> " . $current_time . "</p>
                    </div>
                    
                    <div class='info'>
                        <h3>Security Information:</h3>
                        <p><strong>Guard on Duty:</strong> " . htmlspecialchars($visitor_log['guard_name']) . "</p>
                        <p><strong>Guard Phone:</strong> " . htmlspecialchars($visitor_log['guard_phone']) . "</p>
                    </div>
                    
                    <p><strong>Action Required:</strong> Please contact the security guard or visit the premises to verify the visitor's status.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated alert from " . SITE_NAME . " Security Management System</p>
                    <p>Generated on " . date('d M Y, h:i A') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Get check-in email template
     */
    private function getCheckinEmailTemplate($visitor_log) {
        $checkin_time = date('d M Y, h:i A', strtotime($visitor_log['check_in_time']));
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4FD1C7; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
                .info { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>👤 Visitor Check-in Notification</h1>
                    <p>" . SITE_NAME . " Security System</p>
                </div>
                <div class='content'>
                    <p>Hello " . htmlspecialchars($visitor_log['owner_name']) . ",</p>
                    
                    <p>A visitor has checked in to visit your flat.</p>
                    
                    <div class='info'>
                        <h3>Visitor Information:</h3>
                        <p><strong>Name:</strong> " . htmlspecialchars($visitor_log['visitor_name']) . "</p>
                        <p><strong>Phone:</strong> " . htmlspecialchars($visitor_log['visitor_phone']) . "</p>
                        <p><strong>Purpose:</strong> " . htmlspecialchars($visitor_log['purpose']) . "</p>
                        <p><strong>Expected Duration:</strong> " . $visitor_log['expected_duration'] . " minutes</p>
                        <p><strong>Check-in Time:</strong> " . $checkin_time . "</p>
                        <p><strong>Guard on Duty:</strong> " . htmlspecialchars($visitor_log['guard_name']) . "</p>
                    </div>
                    
                    <p>If you are not expecting this visitor, please contact security immediately.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated notification from " . SITE_NAME . " Security Management System</p>
                    <p>Generated on " . date('d M Y, h:i A') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Get check-out email template
     */
    private function getCheckoutEmailTemplate($visitor_log) {
        $checkin_time = date('d M Y, h:i A', strtotime($visitor_log['check_in_time']));
        $checkout_time = date('d M Y, h:i A', strtotime($visitor_log['check_out_time']));
        $duration = floor((strtotime($visitor_log['check_out_time']) - strtotime($visitor_log['check_in_time'])) / 60);
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4FD1C7; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
                .info { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>👋 Visitor Check-out Notification</h1>
                    <p>" . SITE_NAME . " Security System</p>
                </div>
                <div class='content'>
                    <p>Hello " . htmlspecialchars($visitor_log['owner_name']) . ",</p>
                    
                    <p>Your visitor has checked out of the premises.</p>
                    
                    <div class='info'>
                        <h3>Visitor Information:</h3>
                        <p><strong>Name:</strong> " . htmlspecialchars($visitor_log['visitor_name']) . "</p>
                        <p><strong>Phone:</strong> " . htmlspecialchars($visitor_log['visitor_phone']) . "</p>
                        <p><strong>Purpose:</strong> " . htmlspecialchars($visitor_log['purpose']) . "</p>
                        <p><strong>Check-in Time:</strong> " . $checkin_time . "</p>
                        <p><strong>Check-out Time:</strong> " . $checkout_time . "</p>
                        <p><strong>Total Duration:</strong> " . $duration . " minutes</p>
                        <p><strong>Guard on Duty:</strong> " . htmlspecialchars($visitor_log['guard_name']) . "</p>
                    </div>
                    
                    <p>Thank you for using " . SITE_NAME . " Security Management System.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated notification from " . SITE_NAME . " Security Management System</p>
                    <p>Generated on " . date('d M Y, h:i A') . "</p>
                </div>
            </div>
        </body>
        </html>";
    }
}

// Function to check for overstays and send alerts
function checkVisitorOverstays() {
    $db = Database::getInstance();
    $mailService = new MailService();
    
    // Get visitors who have overstayed
    $sql = "SELECT 
                vl.id,
                vl.visitor_id,
                vl.flat_id,
                vl.check_in_time,
                vl.expected_duration,
                vl.status
            FROM visitor_logs vl
            WHERE vl.status = 'inside' 
            AND vl.check_out_time IS NULL
            AND TIMESTAMPDIFF(MINUTE, vl.check_in_time, NOW()) > vl.expected_duration";
    
    $result = $db->query($sql);
    $overstays = [];
    while ($row = $result->fetch_assoc()) {
        $overstays[] = $row;
    }
    
    foreach ($overstays as $overstay) {
        // Update status to overstayed
        $update_sql = "UPDATE visitor_logs SET status = 'overstayed' WHERE id = ?";
        $stmt = $db->prepare($update_sql);
        $stmt->bind_param("i", $overstay['id']);
        $stmt->execute();
        $stmt->close();
        
        // Create alert
        $alert_sql = "INSERT INTO alerts (alert_type_id, title, message, severity, related_visitor_log_id, related_flat_id, created_by) 
                      VALUES (1, 'Visitor Overstayed', ?, 'high', ?, ?, 1)";
        
        $message = "Visitor has exceeded their allocated time and is still on the premises.";
        $stmt = $db->prepare($alert_sql);
        $stmt->bind_param("sii", $message, $overstay['id'], $overstay['flat_id']);
        $stmt->execute();
        $stmt->close();
        
        // Send email alert
        $mailService->sendVisitorOverstayAlert($overstay['id']);
        
        // Log activity
        logActivity('overstay_alert_sent', 'visitor_logs', $overstay['id']);
    }
    
    return count($overstays);
}

// Function to send check-in notification
function sendCheckinNotification($visitor_log_id) {
    $mailService = new MailService();
    return $mailService->sendVisitorCheckinNotification($visitor_log_id);
}

// Function to send check-out notification
function sendCheckoutNotification($visitor_log_id) {
    $mailService = new MailService();
    return $mailService->sendVisitorCheckoutNotification($visitor_log_id);
}
?>
