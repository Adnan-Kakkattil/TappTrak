<?php
/**
 * TappTrak Visitors Management
 * Visitor check-in/check-out with QR codes and timer functionality
 */

require_once 'config.php';
require_once 'mailservice.php';

// Require login
requireLogin();

// Get database instance
$db = Database::getInstance();

// Get current user info
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_visitor') {
        $full_name = sanitizeInput($_POST['full_name']);
        $phone = sanitizeInput($_POST['phone']);
        $email = sanitizeInput($_POST['email']);
        $company = sanitizeInput($_POST['company']);
        $purpose = sanitizeInput($_POST['purpose']);
        $id_proof_type = sanitizeInput($_POST['id_proof_type']);
        $id_proof_number = sanitizeInput($_POST['id_proof_number']);
        
        // Validation
        $errors = [];
        if (empty($full_name)) $errors[] = 'Full name is required.';
        if (empty($phone)) $errors[] = 'Phone number is required.';
        if (empty($id_proof_type)) $errors[] = 'ID proof type is required.';
        if (empty($id_proof_number)) $errors[] = 'ID proof number is required.';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if (empty($errors)) {
            $sql = "INSERT INTO visitors (full_name, phone, email, company, purpose, id_proof_type, id_proof_number) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("sssssss", $full_name, $phone, $email, $company, $purpose, $id_proof_type, $id_proof_number);
            
            if ($stmt->execute()) {
                $visitor_id = $db->getLastInsertId();
                $message = 'Visitor added successfully!';
                $message_type = 'success';
                logActivity('visitor_added', 'visitors', $visitor_id);
                
                // Store visitor ID for QR code generation
                $_SESSION['new_visitor_id'] = $visitor_id;
            } else {
                $message = 'Error adding visitor: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'checkin_visitor') {
        $visitor_id = (int)$_POST['visitor_id'];
        $flat_id = (int)$_POST['flat_id'];
        $guard_id = (int)$_POST['guard_id'];
        $purpose = sanitizeInput($_POST['purpose']);
        $expected_duration = (int)$_POST['expected_duration'];
        $vehicle_number = sanitizeInput($_POST['vehicle_number']);
        $items_carried = sanitizeInput($_POST['items_carried']);
        
        // Validation
        $errors = [];
        if (empty($visitor_id)) $errors[] = 'Please select a visitor.';
        if (empty($flat_id)) $errors[] = 'Please select a flat.';
        if (empty($guard_id)) $errors[] = 'Please select a guard.';
        if (empty($expected_duration)) $errors[] = 'Expected duration is required.';
        
        if (empty($errors)) {
            $sql = "INSERT INTO visitor_logs (visitor_id, flat_id, guard_id, check_in_time, purpose, expected_duration, vehicle_number, items_carried, status) 
                    VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, 'inside')";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("iiisiss", $visitor_id, $flat_id, $guard_id, $purpose, $expected_duration, $vehicle_number, $items_carried);
            
            if ($stmt->execute()) {
                $visitor_log_id = $db->getLastInsertId();
                $message = 'Visitor checked in successfully!';
                $message_type = 'success';
                logActivity('visitor_checkin', 'visitor_logs', $visitor_log_id);
                
                // Store visitor log ID for QR code generation
                $_SESSION['new_visitor_log_id'] = $visitor_log_id;
                $_SESSION['new_visitor_log_data'] = [
                    'id' => $visitor_log_id,
                    'visitor_id' => $visitor_id,
                    'flat_id' => $flat_id,
                    'guard_id' => $guard_id,
                    'expected_duration' => $expected_duration
                ];
                
                // Send check-in notification
                sendCheckinNotification($visitor_log_id);
            } else {
                $message = 'Error checking in visitor: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'checkout_visitor') {
        $visitor_log_id = (int)$_POST['visitor_log_id'];
        $notes = sanitizeInput($_POST['notes']);
        
        $sql = "UPDATE visitor_logs SET check_out_time = NOW(), status = 'exited', notes = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("si", $notes, $visitor_log_id);
        
        if ($stmt->execute()) {
            $message = 'Visitor checked out successfully!';
            $message_type = 'success';
            logActivity('visitor_checkout', 'visitor_logs', $visitor_log_id);
            
            // Send check-out notification
            sendCheckoutNotification($visitor_log_id);
        } else {
            $message = 'Error checking out visitor: ' . $db->getConnection()->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Get all visitors
$sql = "SELECT * FROM visitors ORDER BY created_at DESC";
$visitors_result = $db->query($sql);
$visitors = [];
if ($visitors_result) {
    while ($row = $visitors_result->fetch_assoc()) {
        $visitors[] = $row;
    }
}

// Get all flats
$sql = "SELECT * FROM flats WHERE is_active = 1 ORDER BY flat_number";
$flats_result = $db->query($sql);
$flats = [];
if ($flats_result) {
    while ($row = $flats_result->fetch_assoc()) {
        $flats[] = $row;
    }
}

// Get all guards
$sql = "SELECT * FROM guards WHERE is_active = 1 ORDER BY guard_id";
$guards_result = $db->query($sql);
$guards = [];
if ($guards_result) {
    while ($row = $guards_result->fetch_assoc()) {
        $guards[] = $row;
    }
}

// Get current visitors (inside)
$sql = "SELECT 
            vl.*,
            v.full_name as visitor_name,
            v.phone as visitor_phone,
            f.flat_number,
            g.full_name as guard_name,
            TIMESTAMPDIFF(MINUTE, vl.check_in_time, NOW()) as minutes_inside
        FROM visitor_logs vl
        JOIN visitors v ON vl.visitor_id = v.id
        JOIN flats f ON vl.flat_id = f.id
        JOIN guards g ON vl.guard_id = g.id
        WHERE vl.status IN ('inside', 'overstayed') AND vl.check_out_time IS NULL
        ORDER BY vl.check_in_time DESC";

$current_visitors_result = $db->query($sql);
$current_visitors = [];
if ($current_visitors_result) {
    while ($row = $current_visitors_result->fetch_assoc()) {
        $current_visitors[] = $row;
    }
}

// Get recent visitor logs
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
        ORDER BY vl.check_in_time DESC
        LIMIT 20";

$recent_logs_result = $db->query($sql);
$recent_logs = [];
if ($recent_logs_result) {
    while ($row = $recent_logs_result->fetch_assoc()) {
        $recent_logs[] = $row;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    logActivity('logout', 'users', $user_id);
    session_unset();
    session_destroy();
    redirect('index.php?logout=1');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Visitors Management</title>
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
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <div class="w-64 bg-primary shadow-lg">
            <div class="p-6 border-b border-white/20">
                <h1 class="text-white text-xl font-bold"><?php echo SITE_NAME; ?></h1>
                <p class="text-white/80 text-sm mt-1"><?php echo ucfirst($user_role); ?> Panel</p>
            </div>
            <nav class="mt-6">
                <div class="px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-white/80 hover:bg-white/10 rounded-lg transition-colors">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-dashboard-line"></i>
                        </div>
                        Dashboard
                    </a>
                    <a href="guards.php" class="flex items-center px-4 py-3 text-white/80 hover:bg-white/10 rounded-lg transition-colors">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-shield-user-line"></i>
                        </div>
                        Guards
                    </a>
                    <a href="alerts.php" class="flex items-center px-4 py-3 text-white/80 hover:bg-white/10 rounded-lg transition-colors">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-alarm-warning-line"></i>
                        </div>
                        Alerts
                    </a>
                    <a href="visitors.php" class="flex items-center px-4 py-3 text-white bg-white/20 rounded-lg">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-file-list-3-line"></i>
                        </div>
                        Visitor Logs
                    </a>
                    <?php if (isAdmin()): ?>
                    <a href="buildings_flats.php" class="flex items-center px-4 py-3 text-white/80 hover:bg-white/10 rounded-lg transition-colors">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-building-line"></i>
                        </div>
                        Buildings & Flats
                    </a>
                    <a href="settings.php" class="flex items-center px-4 py-3 text-white/80 hover:bg-white/10 rounded-lg transition-colors">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-settings-line"></i>
                        </div>
                        Settings
                    </a>
                    <?php endif; ?>
                </div>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-1 overflow-hidden">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b">
                <div class="px-8 py-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">Visitors Management</h1>
                            <p class="text-gray-600 mt-1">Manage visitor check-ins and check-outs</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <button onclick="toggleAddVisitorForm()" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition-colors">
                                <i class="ri-user-add-line mr-2"></i>Add Visitor
                            </button>
                            <button onclick="toggleCheckinForm()" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                                <i class="ri-login-box-line mr-2"></i>Check In
                            </button>
                            <div class="relative">
                                <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center cursor-pointer" onclick="toggleUserMenu()">
                                    <i class="ri-user-line text-white"></i>
                                </div>
                                <div id="userMenu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                                    <div class="px-4 py-2 text-sm text-gray-700 border-b">
                                        <div class="font-medium"><?php echo htmlspecialchars($user_name); ?></div>
                                        <div class="text-gray-500"><?php echo ucfirst($user_role); ?></div>
                                    </div>
                                    <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="ri-user-settings-line mr-2"></i>Profile
                                    </a>
                                    <a href="visitors.php?logout=1" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="ri-logout-box-line mr-2"></i>Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="p-8 space-y-8">
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="rounded-md p-4 <?php echo $message_type === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?>">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <?php if ($message_type === 'success'): ?>
                                    <i class="ri-check-line text-green-400"></i>
                                <?php else: ?>
                                    <i class="ri-error-warning-line text-red-400"></i>
                                <?php endif; ?>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm <?php echo $message_type === 'success' ? 'text-green-800' : 'text-red-800'; ?>">
                                    <?php echo $message; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- QR Code Display for New Visitor -->
                <?php if (isset($_SESSION['new_visitor_id']) && $message_type === 'success'): ?>
                    <?php
                    $new_visitor_id = $_SESSION['new_visitor_id'];
                    $sql = "SELECT * FROM visitors WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("i", $new_visitor_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $new_visitor = $result->fetch_assoc();
                    $stmt->close();
                    unset($_SESSION['new_visitor_id']);
                    ?>
                    <div class="bg-white rounded-xl shadow-sm border">
                        <div class="p-6 border-b">
                            <h2 class="text-lg font-semibold text-gray-900">Visitor QR Code Generated</h2>
                            <p class="text-gray-600 text-sm mt-1">QR code for <?php echo htmlspecialchars($new_visitor['full_name']); ?></p>
                        </div>
                        <div class="p-6 text-center">
                            <div id="visitorQRCode" class="mb-4 flex justify-center"></div>
                            <div class="space-y-2 text-sm text-gray-600">
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($new_visitor['full_name']); ?></p>
                                <p><strong>Phone:</strong> <?php echo htmlspecialchars($new_visitor['phone']); ?></p>
                                <p><strong>ID Proof:</strong> <?php echo htmlspecialchars($new_visitor['id_proof_type'] . ' - ' . $new_visitor['id_proof_number']); ?></p>
                            </div>
                            <div class="mt-4 flex justify-center space-x-3">
                                <button onclick="printQRCode()" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90">
                                    <i class="ri-printer-line mr-2"></i>Print QR Code
                                </button>
                                <button onclick="closeQRDisplay()" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                                    <i class="ri-close-line mr-2"></i>Close
                                </button>
                            </div>
                        </div>
                    </div>
                    <script>
                        // Generate QR code for visitor
                        document.addEventListener('DOMContentLoaded', function() {
                            const visitorData = {
                                type: 'visitor',
                                id: <?php echo $new_visitor['id']; ?>,
                                name: '<?php echo addslashes($new_visitor['full_name']); ?>',
                                phone: '<?php echo addslashes($new_visitor['phone']); ?>',
                                id_proof_type: '<?php echo addslashes($new_visitor['id_proof_type']); ?>',
                                id_proof_number: '<?php echo addslashes($new_visitor['id_proof_number']); ?>',
                                created_at: '<?php echo date('Y-m-d H:i:s'); ?>',
                                valid_until: '<?php echo date('Y-m-d H:i:s', strtotime('+1 year')); ?>'
                            };
                            
                            const qrElement = document.getElementById('visitorQRCode');
                            if (qrElement) {
                                QRCode.toCanvas(qrElement, JSON.stringify(visitorData), {
                                    width: 200,
                                    height: 200,
                                    color: {
                                        dark: '#000000',
                                        light: '#FFFFFF'
                                    },
                                    errorCorrectionLevel: 'M'
                                }, function (error) {
                                    if (error) {
                                        console.error('QR Code generation error:', error);
                                        qrElement.innerHTML = '<p class="text-red-500">Error generating QR code</p>';
                                    } else {
                                        console.log('QR Code generated successfully');
                                    }
                                });
                            }
                        });
                        
                        function printQRCode() {
                            window.print();
                        }
                        
                        function closeQRDisplay() {
                            const qrContainer = document.querySelector('.bg-white.rounded-xl.shadow-sm.border');
                            if (qrContainer) {
                                qrContainer.remove();
                            }
                        }
                    </script>
                <?php endif; ?>

                <!-- Check-in Success Message with QR Code Button -->
                <?php if (isset($_SESSION['new_visitor_log_id']) && $message_type === 'success'): ?>
                    <?php
                    $new_visitor_log_id = $_SESSION['new_visitor_log_id'];
                    $new_visitor_log_data = $_SESSION['new_visitor_log_data'];
                    
                    // Get visitor and flat details
                    $sql = "SELECT 
                                v.full_name as visitor_name,
                                f.flat_number
                            FROM visitors v, flats f
                            WHERE v.id = ? AND f.id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("ii", $new_visitor_log_data['visitor_id'], $new_visitor_log_data['flat_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $details = $result->fetch_assoc();
                    $stmt->close();
                    
                    unset($_SESSION['new_visitor_log_id']);
                    unset($_SESSION['new_visitor_log_data']);
                    ?>
                    <div class="bg-white rounded-xl shadow-sm border">
                        <div class="p-6 border-b">
                            <h2 class="text-lg font-semibold text-gray-900">Check-in Successful!</h2>
                            <p class="text-gray-600 text-sm mt-1">Visitor checked in successfully</p>
                        </div>
                        <div class="p-6 text-center">
                            <div class="mb-4">
                                <i class="ri-check-line text-6xl text-green-500"></i>
                            </div>
                            <div class="space-y-2 text-sm text-gray-600 mb-4">
                                <p><strong>Visitor:</strong> <?php echo htmlspecialchars($details['visitor_name']); ?></p>
                                <p><strong>Flat:</strong> <?php echo htmlspecialchars($details['flat_number']); ?></p>
                                <p><strong>Expected Duration:</strong> <?php echo $new_visitor_log_data['expected_duration']; ?> minutes</p>
                            </div>
                            <button onclick="generateCheckinQR(<?php echo $new_visitor_log_id; ?>, '<?php echo addslashes($details['visitor_name']); ?>', '<?php echo addslashes($details['flat_number']); ?>', <?php echo $new_visitor_log_data['expected_duration']; ?>)" 
                                    class="bg-primary text-white px-6 py-3 rounded-lg hover:bg-primary/90">
                                <i class="ri-qr-code-line mr-2"></i>Generate QR Code
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Add Visitor Form -->
                <div id="addVisitorForm" class="bg-white rounded-xl shadow-sm border hidden">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">Add New Visitor</h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="add_visitor">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                <input type="email" id="email" name="email"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="company" class="block text-sm font-medium text-gray-700">Company</label>
                                <input type="text" id="company" name="company"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="purpose" class="block text-sm font-medium text-gray-700">Purpose</label>
                                <select id="purpose" name="purpose" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="personal">Personal</option>
                                    <option value="business">Business</option>
                                    <option value="delivery">Delivery</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="id_proof_type" class="block text-sm font-medium text-gray-700">ID Proof Type *</label>
                                <select id="id_proof_type" name="id_proof_type" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="">Select ID Type</option>
                                    <option value="aadhar">Aadhar Card</option>
                                    <option value="pan">PAN Card</option>
                                    <option value="driving_license">Driving License</option>
                                    <option value="passport">Passport</option>
                                    <option value="voter_id">Voter ID</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="id_proof_number" class="block text-sm font-medium text-gray-700">ID Proof Number *</label>
                                <input type="text" id="id_proof_number" name="id_proof_number" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="toggleAddVisitorForm()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-primary/90">
                                Add Visitor
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Check-in Form -->
                <div id="checkinForm" class="bg-white rounded-xl shadow-sm border hidden">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">Check In Visitor</h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="checkin_visitor">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="visitor_id" class="block text-sm font-medium text-gray-700">Select Visitor *</label>
                                <select id="visitor_id" name="visitor_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="">Select Visitor</option>
                                    <?php foreach ($visitors as $visitor): ?>
                                    <option value="<?php echo $visitor['id']; ?>"><?php echo htmlspecialchars($visitor['full_name'] . ' - ' . $visitor['phone']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="flat_id" class="block text-sm font-medium text-gray-700">Select Flat *</label>
                                <select id="flat_id" name="flat_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="">Select Flat</option>
                                    <?php foreach ($flats as $flat): ?>
                                    <option value="<?php echo $flat['id']; ?>"><?php echo htmlspecialchars($flat['flat_number'] . ' - ' . $flat['owner_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="guard_id" class="block text-sm font-medium text-gray-700">Guard on Duty *</label>
                                <select id="guard_id" name="guard_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="">Select Guard</option>
                                    <?php foreach ($guards as $guard): ?>
                                    <option value="<?php echo $guard['id']; ?>"><?php echo htmlspecialchars($guard['full_name'] . ' - ' . $guard['guard_id']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="expected_duration" class="block text-sm font-medium text-gray-700">Expected Duration (minutes) *</label>
                                <input type="number" id="expected_duration" name="expected_duration" required min="15" max="480"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="purpose" class="block text-sm font-medium text-gray-700">Visit Purpose</label>
                                <textarea id="purpose" name="purpose" rows="2"
                                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary"></textarea>
                            </div>
                            
                            <div>
                                <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number</label>
                                <input type="text" id="vehicle_number" name="vehicle_number"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="items_carried" class="block text-sm font-medium text-gray-700">Items Carried</label>
                                <input type="text" id="items_carried" name="items_carried"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="toggleCheckinForm()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                Check In Visitor
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Current Visitors -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Current Visitors</h2>
                                <p class="text-gray-600 text-sm mt-1">Visitors currently inside the premises</p>
                            </div>
                            <div class="text-sm text-gray-500">
                                Total: <?php echo count($current_visitors); ?>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visitor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flat</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Check-in Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($current_visitors)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No visitors currently inside the premises.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($current_visitors as $visitor): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center mr-3">
                                                <i class="ri-user-line text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($visitor['visitor_name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($visitor['visitor_phone']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($visitor['flat_number']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo formatDateTime($visitor['check_in_time']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div><?php echo $visitor['minutes_inside']; ?> min</div>
                                        <div class="text-xs text-gray-500">Expected: <?php echo $visitor['expected_duration']; ?> min</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status = $visitor['status'];
                                        $status_class = '';
                                        $status_text = '';
                                        
                                        if ($status === 'overstayed' || $visitor['minutes_inside'] > $visitor['expected_duration']) {
                                            $status_class = 'bg-red-100 text-red-800';
                                            $status_text = 'Overstayed';
                                        } else {
                                            $status_class = 'bg-blue-100 text-blue-800';
                                            $status_text = 'Inside';
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="generateVisitorQR(<?php echo $visitor['id']; ?>, '<?php echo htmlspecialchars($visitor['visitor_name']); ?>')" class="text-primary hover:text-primary/80" title="Generate QR Code">
                                                <i class="ri-qr-code-line"></i>
                                            </button>
                                            <button onclick="checkoutVisitor(<?php echo $visitor['id']; ?>)" class="text-red-600 hover:text-red-800" title="Check Out">
                                                <i class="ri-logout-box-line"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Recent Visitor Logs -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Recent Visitor Logs</h2>
                                <p class="text-gray-600 text-sm mt-1">Last 20 visitor activities</p>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visitor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flat</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Check-in</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Check-out</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($recent_logs)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                        No visitor logs found.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($recent_logs as $log): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center mr-3">
                                                <i class="ri-user-line text-white text-sm"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($log['visitor_name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($log['visitor_phone']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($log['flat_number']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo formatDateTime($log['check_in_time']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $log['check_out_time'] ? formatDateTime($log['check_out_time']) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status = $log['status'];
                                        $status_class = '';
                                        $status_text = '';
                                        
                                        switch ($status) {
                                            case 'inside':
                                                $status_class = 'bg-blue-100 text-blue-800';
                                                $status_text = 'Inside';
                                                break;
                                            case 'exited':
                                                $status_class = 'bg-green-100 text-green-800';
                                                $status_text = 'Exited';
                                                break;
                                            case 'overstayed':
                                                $status_class = 'bg-red-100 text-red-800';
                                                $status_text = 'Overstayed';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <!-- Checkout Modal -->
    <div id="checkoutModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Check Out Visitor</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="checkout_visitor">
                    <input type="hidden" name="visitor_log_id" id="checkoutVisitorLogId">
                    
                    <div class="mb-4">
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes (Optional)</label>
                        <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeCheckoutModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                            Check Out
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script>
        // Toggle user menu
        function toggleUserMenu() {
            const menu = document.getElementById('userMenu');
            menu.classList.toggle('hidden');
        }

        // Close user menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('userMenu');
            const button = event.target.closest('.cursor-pointer');
            if (!button && !menu.contains(event.target)) {
                menu.classList.add('hidden');
            }
        });

        // Toggle add visitor form
        function toggleAddVisitorForm() {
            const form = document.getElementById('addVisitorForm');
            form.classList.toggle('hidden');
            
            if (!form.classList.contains('hidden')) {
                document.getElementById('full_name').focus();
            }
        }

        // Toggle checkin form
        function toggleCheckinForm() {
            const form = document.getElementById('checkinForm');
            form.classList.toggle('hidden');
            
            if (!form.classList.contains('hidden')) {
                document.getElementById('visitor_id').focus();
            }
        }

        // Checkout visitor
        function checkoutVisitor(visitorLogId) {
            document.getElementById('checkoutVisitorLogId').value = visitorLogId;
            document.getElementById('checkoutModal').classList.remove('hidden');
        }

        function closeCheckoutModal() {
            document.getElementById('checkoutModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('checkoutModal');
            if (event.target === modal) {
                closeCheckoutModal();
            }
        });

        // Generate QR code for visitor
        function generateVisitorQR(visitorId, visitorName) {
            console.log('generateVisitorQR called with:', visitorId, visitorName);
            
            // Open QR generator in new window
            const url = `qr_generator.php?action=visitor_qr&visitor_id=${visitorId}`;
            window.open(url, '_blank', 'width=600,height=700');
        }

        // Generate QR code for check-in
        function generateCheckinQR(logId, visitorName, flatNumber, expectedDuration) {
            console.log('generateCheckinQR called with:', logId, visitorName, flatNumber, expectedDuration);
            
            // Open QR generator in new window
            const url = `qr_generator.php?action=visitor_log_qr&log_id=${logId}`;
            window.open(url, '_blank', 'width=600,height=700');
        }




        // Auto-refresh page every 2 minutes to update timers
        setTimeout(function() {
            window.location.reload();
        }, 120000);
    </script>
</body>
</html>
