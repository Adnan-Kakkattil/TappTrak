<?php
/**
 * TappTrak Settings
 * System configuration and settings management
 */

require_once 'config.php';

// Require admin access
requireAdmin();

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
    
    if ($action === 'update_settings') {
        $settings = [
            'max_visit_duration' => (int)$_POST['max_visit_duration'],
            'overstay_warning_time' => (int)$_POST['overstay_warning_time'],
            'auto_checkout_time' => (int)$_POST['auto_checkout_time'],
            'system_name' => sanitizeInput($_POST['system_name']),
            'timezone' => sanitizeInput($_POST['timezone']),
            'enable_notifications' => isset($_POST['enable_notifications']) ? '1' : '0',
            'email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
            'sms_notifications' => isset($_POST['sms_notifications']) ? '1' : '0'
        ];
        
        $success_count = 0;
        foreach ($settings as $key => $value) {
            if (setSystemSetting($key, $value)) {
                $success_count++;
            }
        }
        
        if ($success_count === count($settings)) {
            $message = 'Settings updated successfully!';
            $message_type = 'success';
            logActivity('settings_updated', 'system_settings', null, null, json_encode($settings));
        } else {
            $message = 'Some settings could not be updated.';
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'add_user') {
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $role = sanitizeInput($_POST['role']);
        $full_name = sanitizeInput($_POST['full_name']);
        $phone = sanitizeInput($_POST['phone']);
        
        // Validation
        $errors = [];
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (empty($password) || strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
        }
        if ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match.';
        }
        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        }
        if (!in_array($role, ['admin', 'security'])) {
            $errors[] = 'Invalid role selected.';
        }
        
        // Check if email already exists
        $sql = "SELECT id FROM users WHERE email = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = 'Email address already exists.';
        }
        $stmt->close();
        
        if (empty($errors)) {
            $password_hash = hashPassword($password);
            
            $sql = "INSERT INTO users (email, password_hash, role, full_name, phone, is_active) 
                    VALUES (?, ?, ?, ?, ?, 1)";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("sssss", $email, $password_hash, $role, $full_name, $phone);
            
            if ($stmt->execute()) {
                $message = 'User added successfully!';
                $message_type = 'success';
                logActivity('user_added', 'users', $db->getLastInsertId());
            } else {
                $message = 'Error adding user: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'update_user') {
        $user_id = (int)$_POST['user_id'];
        $email = sanitizeInput($_POST['email']);
        $role = sanitizeInput($_POST['role']);
        $full_name = sanitizeInput($_POST['full_name']);
        $phone = sanitizeInput($_POST['phone']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        }
        if (!in_array($role, ['admin', 'security'])) {
            $errors[] = 'Invalid role selected.';
        }
        
        if (empty($errors)) {
            $sql = "UPDATE users SET email = ?, role = ?, full_name = ?, phone = ?, is_active = ? WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ssssii", $email, $role, $full_name, $phone, $is_active, $user_id);
            
            if ($stmt->execute()) {
                $message = 'User updated successfully!';
                $message_type = 'success';
                logActivity('user_updated', 'users', $user_id);
            } else {
                $message = 'Error updating user: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
}

// Get system settings
$settings = [];
$sql = "SELECT setting_key, setting_value FROM system_settings";
$result = $db->query($sql);
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get all users
$sql = "SELECT * FROM users ORDER BY created_at DESC";
$users_result = $db->query($sql);
$users = [];
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Get user for editing
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $sql = "SELECT * FROM users WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_user = $result->fetch_assoc();
    $stmt->close();
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
    <title><?php echo SITE_NAME; ?> - Settings</title>
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
                    <a href="buildings_flats.php" class="flex items-center px-4 py-3 text-white/80 hover:bg-white/10 rounded-lg transition-colors">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-building-line"></i>
                        </div>
                        Buildings & Flats
                    </a>
                    <a href="settings.php" class="flex items-center px-4 py-3 text-white bg-white/20 rounded-lg">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-settings-line"></i>
                        </div>
                        Settings
                    </a>
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
                            <h1 class="text-2xl font-bold text-gray-900">System Settings</h1>
                            <p class="text-gray-600 mt-1">Configure system parameters and user management</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <button onclick="toggleAddUserForm()" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition-colors">
                                <i class="ri-user-add-line mr-2"></i>Add User
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
                                    <a href="settings.php?logout=1" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
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

                <!-- System Settings -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">System Configuration</h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="update_settings">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="system_name" class="block text-sm font-medium text-gray-700">System Name</label>
                                <input type="text" id="system_name" name="system_name" 
                                       value="<?php echo htmlspecialchars($settings['system_name'] ?? SITE_NAME); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                                <select id="timezone" name="timezone" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="Asia/Kolkata" <?php echo ($settings['timezone'] ?? 'Asia/Kolkata') === 'Asia/Kolkata' ? 'selected' : ''; ?>>Asia/Kolkata</option>
                                    <option value="UTC" <?php echo ($settings['timezone'] ?? '') === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                    <option value="America/New_York" <?php echo ($settings['timezone'] ?? '') === 'America/New_York' ? 'selected' : ''; ?>>America/New_York</option>
                                    <option value="Europe/London" <?php echo ($settings['timezone'] ?? '') === 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="max_visit_duration" class="block text-sm font-medium text-gray-700">Max Visit Duration (minutes)</label>
                                <input type="number" id="max_visit_duration" name="max_visit_duration" min="30" max="480"
                                       value="<?php echo $settings['max_visit_duration'] ?? 240; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="overstay_warning_time" class="block text-sm font-medium text-gray-700">Overstay Warning Time (minutes)</label>
                                <input type="number" id="overstay_warning_time" name="overstay_warning_time" min="15" max="240"
                                       value="<?php echo $settings['overstay_warning_time'] ?? 180; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="auto_checkout_time" class="block text-sm font-medium text-gray-700">Auto Checkout Time (minutes)</label>
                                <input type="number" id="auto_checkout_time" name="auto_checkout_time" min="60" max="1440"
                                       value="<?php echo $settings['auto_checkout_time'] ?? 480; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div class="md:col-span-2">
                                <div class="space-y-4">
                                    <div class="flex items-center">
                                        <input type="checkbox" id="enable_notifications" name="enable_notifications" 
                                               <?php echo ($settings['enable_notifications'] ?? '1') === '1' ? 'checked' : ''; ?>
                                               class="rounded border-gray-300 text-primary focus:ring-primary">
                                        <label for="enable_notifications" class="ml-2 text-sm text-gray-700">Enable System Notifications</label>
                                    </div>
                                    
                                    <div class="flex items-center">
                                        <input type="checkbox" id="email_notifications" name="email_notifications" 
                                               <?php echo ($settings['email_notifications'] ?? '1') === '1' ? 'checked' : ''; ?>
                                               class="rounded border-gray-300 text-primary focus:ring-primary">
                                        <label for="email_notifications" class="ml-2 text-sm text-gray-700">Enable Email Notifications</label>
                                    </div>
                                    
                                    <div class="flex items-center">
                                        <input type="checkbox" id="sms_notifications" name="sms_notifications" 
                                               <?php echo ($settings['sms_notifications'] ?? '0') === '1' ? 'checked' : ''; ?>
                                               class="rounded border-gray-300 text-primary focus:ring-primary">
                                        <label for="sms_notifications" class="ml-2 text-sm text-gray-700">Enable SMS Notifications</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-primary/90">
                                Update Settings
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Add/Edit User Form -->
                <div id="userForm" class="bg-white rounded-xl shadow-sm border <?php echo $edit_user ? '' : 'hidden'; ?>">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <?php echo $edit_user ? 'Edit User' : 'Add New User'; ?>
                        </h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="<?php echo $edit_user ? 'update_user' : 'add_user'; ?>">
                        <?php if ($edit_user): ?>
                            <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required
                                       value="<?php echo $edit_user ? htmlspecialchars($edit_user['full_name']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email Address *</label>
                                <input type="email" id="email" name="email" required
                                       value="<?php echo $edit_user ? htmlspecialchars($edit_user['email']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                <input type="tel" id="phone" name="phone"
                                       value="<?php echo $edit_user ? htmlspecialchars($edit_user['phone']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="role" class="block text-sm font-medium text-gray-700">Role *</label>
                                <select id="role" name="role" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="">Select Role</option>
                                    <option value="admin" <?php echo $edit_user && $edit_user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="security" <?php echo $edit_user && $edit_user['role'] === 'security' ? 'selected' : ''; ?>>Security</option>
                                </select>
                            </div>
                            
                            <?php if (!$edit_user): ?>
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700">Password *</label>
                                <input type="password" id="password" name="password" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password *</label>
                                <input type="password" id="confirm_password" name="confirm_password" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($edit_user): ?>
                            <div class="md:col-span-2">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_active" <?php echo $edit_user['is_active'] ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                                    <span class="ml-2 text-sm text-gray-700">Active</span>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="toggleAddUserForm()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-primary/90">
                                <?php echo $edit_user ? 'Update User' : 'Add User'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Users List -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">System Users</h2>
                                <p class="text-gray-600 text-sm mt-1">Manage system users and their roles</p>
                            </div>
                            <div class="text-sm text-gray-500">
                                Total Users: <?php echo count($users); ?>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                        No users found.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center mr-3">
                                                <i class="ri-user-line text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $user['role'] === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <div class="w-2 h-2 <?php echo $user['is_active'] ? 'bg-green-400' : 'bg-red-400'; ?> rounded-full mr-1"></div>
                                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $user['last_login'] ? formatDateTime($user['last_login']) : 'Never'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="?edit=<?php echo $user['id']; ?>" class="text-primary hover:text-primary/80">
                                            <i class="ri-edit-line"></i>
                                        </a>
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

        // Toggle add user form
        function toggleAddUserForm() {
            const form = document.getElementById('userForm');
            form.classList.toggle('hidden');
            
            if (form.classList.contains('hidden')) {
                window.location.href = 'settings.php';
            } else {
                document.getElementById('full_name').focus();
            }
        }

        // Password confirmation validation
        document.addEventListener('DOMContentLoaded', function() {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (password && confirmPassword) {
                confirmPassword.addEventListener('input', function() {
                    if (password.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity('Passwords do not match');
                    } else {
                        confirmPassword.setCustomValidity('');
                    }
                });
            }
        });
    </script>
</body>
</html>
