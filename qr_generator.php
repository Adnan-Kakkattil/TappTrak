<?php
/**
 * QR Code Generator using PHP
 * Simple QR code generation without external dependencies
 */

require_once 'config.php';

// Require login
requireLogin();

// Get database instance
$db = Database::getInstance();

// Function to generate QR code using simple method
function generateQRCode($data, $size = 200) {
    // For now, we'll create a simple text-based QR representation
    // In a real implementation, you would use a proper QR code library
    
    $qrData = json_encode($data);
    $hash = md5($qrData);
    
    // Create a simple visual representation
    $qrCode = "
    <div style='border: 2px solid #000; padding: 20px; text-align: center; background: white; width: {$size}px; height: {$size}px; display: flex; flex-direction: column; justify-content: center;'>
        <div style='font-size: 12px; font-weight: bold; margin-bottom: 10px;'>QR CODE</div>
        <div style='font-size: 10px; word-break: break-all; margin-bottom: 10px;'>" . substr($hash, 0, 16) . "</div>
        <div style='font-size: 8px; color: #666;'>TappTrak System</div>
    </div>";
    
    return $qrCode;
}

// Handle QR code generation requests
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'visitor_qr' && isset($_GET['visitor_id'])) {
        $visitor_id = (int)$_GET['visitor_id'];
        
        // Get visitor data
        $sql = "SELECT * FROM visitors WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $visitor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $visitor = $result->fetch_assoc();
        $stmt->close();
        
        if ($visitor) {
            $qrData = [
                'type' => 'visitor',
                'id' => $visitor['id'],
                'name' => $visitor['full_name'],
                'phone' => $visitor['phone'],
                'id_proof_type' => $visitor['id_proof_type'],
                'id_proof_number' => $visitor['id_proof_number'],
                'created_at' => date('Y-m-d H:i:s'),
                'valid_until' => date('Y-m-d H:i:s', strtotime('+1 year'))
            ];
            
            $qrCode = generateQRCode($qrData);
            $title = $visitor['full_name'] . ' - Visitor QR Code';
        } else {
            die('Visitor not found');
        }
    } elseif ($action === 'visitor_log_qr' && isset($_GET['log_id'])) {
        $log_id = (int)$_GET['log_id'];
        
        // Get visitor log data
        $sql = "SELECT 
                    vl.*,
                    v.full_name as visitor_name,
                    v.phone as visitor_phone,
                    f.flat_number,
                    g.full_name as guard_name
                FROM visitor_logs vl
                JOIN visitors v ON vl.visitor_id = v.id
                JOIN flats f ON vl.flat_id = f.id
                JOIN guards g ON vl.guard_id = g.id
                WHERE vl.id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $log_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $visitor_log = $result->fetch_assoc();
        $stmt->close();
        
        if ($visitor_log) {
            $qrData = [
                'type' => 'visitor_checkin',
                'log_id' => $visitor_log['id'],
                'visitor_name' => $visitor_log['visitor_name'],
                'visitor_phone' => $visitor_log['visitor_phone'],
                'flat_number' => $visitor_log['flat_number'],
                'checkin_time' => $visitor_log['check_in_time'],
                'expected_duration' => $visitor_log['expected_duration'],
                'valid_until' => date('Y-m-d H:i:s', strtotime($visitor_log['check_in_time'] . ' +' . $visitor_log['expected_duration'] . ' minutes')),
                'guard_name' => $visitor_log['guard_name']
            ];
            
            $qrCode = generateQRCode($qrData);
            $title = $visitor_log['visitor_name'] . ' - Check-in QR Code';
        } else {
            die('Visitor log not found');
        }
    } else {
        die('Invalid request');
    }
} else {
    die('No action specified');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - QR Code</title>
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css">
    <style>
        :where([class^="ri-"])::before { content: "\f3c2"; }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4FD1C7',
                        secondary: '#81E6D9'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-lg border max-w-md w-full">
            <div class="p-6 border-b">
                <h2 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($title); ?></h2>
                <p class="text-gray-600 text-sm mt-1">Generated: <?php echo date('d M Y, h:i A'); ?></p>
            </div>
            <div class="p-6 text-center">
                <div class="mb-4 flex justify-center">
                    <?php echo $qrCode; ?>
                </div>
                <div class="space-y-2 text-sm text-gray-600 mb-4">
                    <?php if (isset($visitor)): ?>
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($visitor['full_name']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($visitor['phone']); ?></p>
                        <p><strong>ID Proof:</strong> <?php echo htmlspecialchars($visitor['id_proof_type']); ?> - <?php echo htmlspecialchars($visitor['id_proof_number']); ?></p>
                        <p><strong>Valid Until:</strong> <?php echo date('d M Y', strtotime('+1 year')); ?></p>
                    <?php elseif (isset($visitor_log)): ?>
                        <p><strong>Visitor:</strong> <?php echo htmlspecialchars($visitor_log['visitor_name']); ?></p>
                        <p><strong>Flat:</strong> <?php echo htmlspecialchars($visitor_log['flat_number']); ?></p>
                        <p><strong>Check-in:</strong> <?php echo formatDateTime($visitor_log['check_in_time']); ?></p>
                        <p><strong>Duration:</strong> <?php echo $visitor_log['expected_duration']; ?> minutes</p>
                        <p><strong>Valid Until:</strong> <?php echo formatDateTime(date('Y-m-d H:i:s', strtotime($visitor_log['check_in_time'] . ' +' . $visitor_log['expected_duration'] . ' minutes'))); ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex justify-center space-x-3">
                    <button onclick="window.print()" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90">
                        <i class="ri-printer-line mr-2"></i>Print
                    </button>
                    <button onclick="window.close()" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                        <i class="ri-close-line mr-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
