<?php
/**
 * TappTrak Guards Management
 * CRUD operations for guards and their shifts
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
    
    if ($action === 'add_guard') {
        $full_name = sanitizeInput($_POST['full_name']);
        $phone = sanitizeInput($_POST['phone']);
        $email = sanitizeInput($_POST['email']);
        $address = sanitizeInput($_POST['address']);
        $emergency_contact = sanitizeInput($_POST['emergency_contact']);
        $emergency_contact_name = sanitizeInput($_POST['emergency_contact_name']);
        $hire_date = $_POST['hire_date'];
        $salary = $_POST['salary'];
        
        // Validation
        $errors = [];
        if (empty($full_name)) $errors[] = 'Full name is required.';
        if (empty($phone)) $errors[] = 'Phone number is required.';
        if (empty($hire_date)) $errors[] = 'Hire date is required.';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if (empty($errors)) {
            // Generate guard ID
            $sql = "SELECT COUNT(*) as count FROM guards";
            $result = $db->query($sql);
            $row = $result->fetch_assoc();
            $guard_id = 'GRD-' . str_pad($row['count'] + 1, 3, '0', STR_PAD_LEFT);
            
            $sql = "INSERT INTO guards (guard_id, full_name, phone, email, address, emergency_contact, emergency_contact_name, hire_date, salary) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ssssssssd", $guard_id, $full_name, $phone, $email, $address, $emergency_contact, $emergency_contact_name, $hire_date, $salary);
            
            if ($stmt->execute()) {
                $message = 'Guard added successfully!';
                $message_type = 'success';
                logActivity('guard_added', 'guards', $db->getLastInsertId());
            } else {
                $message = 'Error adding guard: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'edit_guard') {
        $guard_id = (int)$_POST['guard_id'];
        $full_name = sanitizeInput($_POST['full_name']);
        $phone = sanitizeInput($_POST['phone']);
        $email = sanitizeInput($_POST['email']);
        $address = sanitizeInput($_POST['address']);
        $emergency_contact = sanitizeInput($_POST['emergency_contact']);
        $emergency_contact_name = sanitizeInput($_POST['emergency_contact_name']);
        $hire_date = $_POST['hire_date'];
        $salary = $_POST['salary'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (empty($full_name)) $errors[] = 'Full name is required.';
        if (empty($phone)) $errors[] = 'Phone number is required.';
        if (empty($hire_date)) $errors[] = 'Hire date is required.';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if (empty($errors)) {
            $sql = "UPDATE guards SET full_name = ?, phone = ?, email = ?, address = ?, emergency_contact = ?, emergency_contact_name = ?, hire_date = ?, salary = ?, is_active = ? WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("sssssssdsi", $full_name, $phone, $email, $address, $emergency_contact, $emergency_contact_name, $hire_date, $salary, $is_active, $guard_id);
            
            if ($stmt->execute()) {
                $message = 'Guard updated successfully!';
                $message_type = 'success';
                logActivity('guard_updated', 'guards', $guard_id);
            } else {
                $message = 'Error updating guard: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'delete_guard') {
        $guard_id = (int)$_POST['guard_id'];
        
        $sql = "DELETE FROM guards WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $guard_id);
        
        if ($stmt->execute()) {
            $message = 'Guard deleted successfully!';
            $message_type = 'success';
            logActivity('guard_deleted', 'guards', $guard_id);
        } else {
            $message = 'Error deleting guard: ' . $db->getConnection()->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
    
    elseif ($action === 'add_shift') {
        $guard_id = (int)$_POST['guard_id'];
        $shift_name = sanitizeInput($_POST['shift_name']);
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        
        // Validation
        $errors = [];
        if (empty($shift_name)) $errors[] = 'Shift name is required.';
        if (empty($start_time)) $errors[] = 'Start time is required.';
        if (empty($end_time)) $errors[] = 'End time is required.';
        
        if (empty($errors)) {
            $sql = "INSERT INTO guard_shifts (guard_id, shift_name, start_time, end_time) VALUES (?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("isss", $guard_id, $shift_name, $start_time, $end_time);
            
            if ($stmt->execute()) {
                $message = 'Shift added successfully!';
                $message_type = 'success';
                logActivity('shift_added', 'guard_shifts', $db->getLastInsertId());
            } else {
                $message = 'Error adding shift: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
}

// Get all guards with their shifts
$sql = "SELECT 
            g.*,
            COUNT(gs.id) as shift_count,
            GROUP_CONCAT(gs.shift_name SEPARATOR ', ') as shifts
        FROM guards g
        LEFT JOIN guard_shifts gs ON g.id = gs.guard_id AND gs.is_active = 1
        GROUP BY g.id
        ORDER BY g.guard_id";

$guards_result = $db->query($sql);
$guards = [];
if ($guards_result) {
    while ($row = $guards_result->fetch_assoc()) {
        $guards[] = $row;
    }
}

// Get guard for editing
$edit_guard = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $sql = "SELECT * FROM guards WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_guard = $result->fetch_assoc();
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
    <title><?php echo SITE_NAME; ?> - Guards Management</title>
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
                    },
                    borderRadius: {
                        'none': '0px',
                        'sm': '4px',
                        DEFAULT: '8px',
                        'md': '12px',
                        'lg': '16px',
                        'xl': '20px',
                        '2xl': '24px',
                        '3xl': '32px',
                        'full': '9999px',
                        'button': '8px'
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
                    <a href="guards.php" class="flex items-center px-4 py-3 text-white bg-white/20 rounded-lg">
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
                    <?php if (isAdmin()): ?>
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
                            <h1 class="text-2xl font-bold text-gray-900">Guards Management</h1>
                            <p class="text-gray-600 mt-1">Manage security guards and their shifts</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <button onclick="toggleAddForm()" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition-colors">
                                <i class="ri-add-line mr-2"></i>Add Guard
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
                                    <a href="guards.php?logout=1" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
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

                <!-- Add/Edit Guard Form -->
                <div id="guardForm" class="bg-white rounded-xl shadow-sm border <?php echo $edit_guard ? '' : 'hidden'; ?>">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <?php echo $edit_guard ? 'Edit Guard' : 'Add New Guard'; ?>
                        </h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="<?php echo $edit_guard ? 'edit_guard' : 'add_guard'; ?>">
                        <?php if ($edit_guard): ?>
                            <input type="hidden" name="guard_id" value="<?php echo $edit_guard['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required
                                       value="<?php echo $edit_guard ? htmlspecialchars($edit_guard['full_name']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" required
                                       value="<?php echo $edit_guard ? htmlspecialchars($edit_guard['phone']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                <input type="email" id="email" name="email"
                                       value="<?php echo $edit_guard ? htmlspecialchars($edit_guard['email']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="hire_date" class="block text-sm font-medium text-gray-700">Hire Date *</label>
                                <input type="date" id="hire_date" name="hire_date" required
                                       value="<?php echo $edit_guard ? $edit_guard['hire_date'] : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="salary" class="block text-sm font-medium text-gray-700">Salary</label>
                                <input type="number" id="salary" name="salary" step="0.01"
                                       value="<?php echo $edit_guard ? $edit_guard['salary'] : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="emergency_contact" class="block text-sm font-medium text-gray-700">Emergency Contact</label>
                                <input type="tel" id="emergency_contact" name="emergency_contact"
                                       value="<?php echo $edit_guard ? htmlspecialchars($edit_guard['emergency_contact']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700">Emergency Contact Name</label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name"
                                       value="<?php echo $edit_guard ? htmlspecialchars($edit_guard['emergency_contact_name']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                                <textarea id="address" name="address" rows="3"
                                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary"><?php echo $edit_guard ? htmlspecialchars($edit_guard['address']) : ''; ?></textarea>
                            </div>
                            
                            <?php if ($edit_guard): ?>
                            <div class="md:col-span-2">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_active" <?php echo $edit_guard['is_active'] ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                                    <span class="ml-2 text-sm text-gray-700">Active</span>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="toggleAddForm()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-primary/90">
                                <?php echo $edit_guard ? 'Update Guard' : 'Add Guard'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Guards List -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Guards List</h2>
                                <p class="text-gray-600 text-sm mt-1">Manage all security guards</p>
                            </div>
                            <div class="text-sm text-gray-500">
                                Total Guards: <?php echo count($guards); ?>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guard</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hire Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Shifts</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($guards)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No guards found. Add your first guard to get started.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($guards as $guard): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center mr-3">
                                                <i class="ri-user-line text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($guard['full_name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($guard['guard_id']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($guard['phone']); ?></div>
                                        <?php if ($guard['email']): ?>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($guard['email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo formatDate($guard['hire_date']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo $guard['shift_count'] > 0 ? $guard['shift_count'] . ' shift(s)' : 'No shifts'; ?>
                                        </div>
                                        <?php if ($guard['shifts']): ?>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($guard['shifts']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $guard['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <div class="w-2 h-2 <?php echo $guard['is_active'] ? 'bg-green-400' : 'bg-red-400'; ?> rounded-full mr-1"></div>
                                            <?php echo $guard['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="?edit=<?php echo $guard['id']; ?>" class="text-primary hover:text-primary/80">
                                                <i class="ri-edit-line"></i>
                                            </a>
                                            <button onclick="confirmDelete(<?php echo $guard['id']; ?>, '<?php echo htmlspecialchars($guard['full_name']); ?>')" class="text-red-600 hover:text-red-800">
                                                <i class="ri-delete-bin-line"></i>
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
            </main>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                    <i class="ri-delete-bin-line text-red-600 text-xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mt-4">Delete Guard</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500">
                        Are you sure you want to delete <span id="guardName" class="font-medium"></span>? This action cannot be undone.
                    </p>
                </div>
                <div class="items-center px-4 py-3">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="delete_guard">
                        <input type="hidden" name="guard_id" id="deleteGuardId">
                        <button type="button" onclick="closeDeleteModal()" class="bg-gray-500 text-white px-4 py-2 rounded-md mr-2 hover:bg-gray-600">
                            Cancel
                        </button>
                        <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                            Delete
                        </button>
                    </form>
                </div>
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

        // Toggle add form
        function toggleAddForm() {
            const form = document.getElementById('guardForm');
            form.classList.toggle('hidden');
            
            // Clear form if not editing
            if (form.classList.contains('hidden')) {
                window.location.href = 'guards.php';
            }
        }

        // Delete confirmation
        function confirmDelete(guardId, guardName) {
            document.getElementById('deleteGuardId').value = guardId;
            document.getElementById('guardName').textContent = guardName;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        });

        // Auto-focus on first input when form is shown
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('guardForm');
            if (!form.classList.contains('hidden')) {
                document.getElementById('full_name').focus();
            }
        });
    </script>
</body>
</html>
