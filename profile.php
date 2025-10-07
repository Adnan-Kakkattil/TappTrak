<?php
/**
 * TappTrak User Profile
 * User profile management and password change
 */

require_once 'config.php';

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

// Get user details
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $full_name = sanitizeInput($_POST['full_name']);
        $phone = sanitizeInput($_POST['phone']);
        
        // Validation
        $errors = [];
        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        }
        
        if (empty($errors)) {
            $sql = "UPDATE users SET full_name = ?, phone = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ssi", $full_name, $phone, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['user_name'] = $full_name;
                $message = 'Profile updated successfully!';
                $message_type = 'success';
                logActivity('profile_updated', 'users', $user_id);
            } else {
                $message = 'Error updating profile: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validation
        $errors = [];
        if (empty($current_password)) {
            $errors[] = 'Current password is required.';
        }
        if (empty($new_password) || strlen($new_password) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
        }
        if ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match.';
        }
        
        // Verify current password
        if (empty($errors) && !verifyPassword($current_password, $user['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        }
        
        if (empty($errors)) {
            $new_password_hash = hashPassword($new_password);
            
            $sql = "UPDATE users SET password_hash = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("si", $new_password_hash, $user_id);
            
            if ($stmt->execute()) {
                $message = 'Password changed successfully!';
                $message_type = 'success';
                logActivity('password_changed', 'users', $user_id);
            } else {
                $message = 'Error changing password: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
}

// Get user activity logs
$sql = "SELECT * FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$activity_logs = [];
while ($row = $result->fetch_assoc()) {
    $activity_logs[] = $row;
}
$stmt->close();

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
    <title><?php echo SITE_NAME; ?> - Profile</title>
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
                    <a href="visitors.php" class="flex items-center px-4 py-3 text-white/80 hover:bg-white/10 rounded-lg transition-colors">
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
                            <h1 class="text-2xl font-bold text-gray-900">User Profile</h1>
                            <p class="text-gray-600 mt-1">Manage your account settings and preferences</p>
                        </div>
                        <div class="flex items-center space-x-4">
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
                                    <a href="profile.php?logout=1" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
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

                <!-- Profile Information -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">Profile Information</h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 text-gray-500">
                                <p class="mt-1 text-xs text-gray-500">Email cannot be changed. Contact administrator if needed.</p>
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                <input type="tel" id="phone" name="phone"
                                       value="<?php echo htmlspecialchars($user['phone']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                                <input type="text" id="role" value="<?php echo ucfirst($user['role']); ?>" disabled
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 text-gray-500">
                            </div>
                            
                            <div>
                                <label for="created_at" class="block text-sm font-medium text-gray-700">Member Since</label>
                                <input type="text" id="created_at" value="<?php echo formatDate($user['created_at']); ?>" disabled
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 text-gray-500">
                            </div>
                            
                            <div>
                                <label for="last_login" class="block text-sm font-medium text-gray-700">Last Login</label>
                                <input type="text" id="last_login" value="<?php echo $user['last_login'] ? formatDateTime($user['last_login']) : 'Never'; ?>" disabled
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 text-gray-500">
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-primary/90">
                                Update Profile
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Change Password -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">Change Password</h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700">Current Password *</label>
                                <input type="password" id="current_password" name="current_password" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700">New Password *</label>
                                <input type="password" id="new_password" name="new_password" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                <p class="mt-1 text-xs text-gray-500">Password must be at least <?php echo PASSWORD_MIN_LENGTH; ?> characters long.</p>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password *</label>
                                <input type="password" id="confirm_password" name="confirm_password" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                                Change Password
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Recent Activity -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Activity</h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($activity_logs)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="ri-history-line text-4xl mb-2"></i>
                            <p>No recent activity found.</p>
                        </div>
                        <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($activity_logs as $log): ?>
                            <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center">
                                        <i class="ri-history-line text-white text-sm"></i>
                                    </div>
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo ucwords(str_replace('_', ' ', $log['action'])); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo formatDateTime($log['created_at']); ?>
                                    </div>
                                    <?php if ($log['ip_address']): ?>
                                    <div class="text-xs text-gray-400">
                                        IP: <?php echo htmlspecialchars($log['ip_address']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
            </main>
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

        // Password confirmation validation
        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            confirmPassword.addEventListener('input', function() {
                if (newPassword.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            });
            
            newPassword.addEventListener('input', function() {
                if (this.value.length < <?php echo PASSWORD_MIN_LENGTH; ?>) {
                    this.setCustomValidity('Password must be at least <?php echo PASSWORD_MIN_LENGTH; ?> characters long');
                } else {
                    this.setCustomValidity('');
                }
            });
        });
    </script>
</body>
</html>
