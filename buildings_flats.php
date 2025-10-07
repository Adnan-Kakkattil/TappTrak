<?php
/**
 * TappTrak Buildings & Flats Management
 * Manage buildings and flats with CRUD operations
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
    
    if ($action === 'add_building') {
        $name = sanitizeInput($_POST['name']);
        $address = sanitizeInput($_POST['address']);
        
        // Validation
        $errors = [];
        if (empty($name)) {
            $errors[] = 'Building name is required.';
        }
        if (empty($address)) {
            $errors[] = 'Building address is required.';
        }
        
        // Check if building name already exists
        $sql = "SELECT id FROM buildings WHERE name = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = 'Building name already exists.';
        }
        $stmt->close();
        
        if (empty($errors)) {
            $sql = "INSERT INTO buildings (name, address) VALUES (?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ss", $name, $address);
            
            if ($stmt->execute()) {
                $message = 'Building added successfully!';
                $message_type = 'success';
                logActivity('building_added', 'buildings', $db->getLastInsertId());
            } else {
                $message = 'Error adding building: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'edit_building') {
        $building_id = (int)$_POST['building_id'];
        $name = sanitizeInput($_POST['name']);
        $address = sanitizeInput($_POST['address']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (empty($name)) {
            $errors[] = 'Building name is required.';
        }
        if (empty($address)) {
            $errors[] = 'Building address is required.';
        }
        
        // Check if building name already exists (excluding current building)
        $sql = "SELECT id FROM buildings WHERE name = ? AND id != ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("si", $name, $building_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = 'Building name already exists.';
        }
        $stmt->close();
        
        if (empty($errors)) {
            $sql = "UPDATE buildings SET name = ?, address = ?, is_active = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ssii", $name, $address, $is_active, $building_id);
            
            if ($stmt->execute()) {
                $message = 'Building updated successfully!';
                $message_type = 'success';
                logActivity('building_updated', 'buildings', $building_id);
            } else {
                $message = 'Error updating building: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'delete_building') {
        $building_id = (int)$_POST['building_id'];
        
        // Check if building has flats
        $sql = "SELECT COUNT(*) as flat_count FROM flats WHERE building_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $building_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['flat_count'] > 0) {
            $message = 'Cannot delete building. It contains ' . $row['flat_count'] . ' flat(s). Please delete all flats first.';
            $message_type = 'error';
        } else {
            $sql = "DELETE FROM buildings WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $building_id);
            
            if ($stmt->execute()) {
                $message = 'Building deleted successfully!';
                $message_type = 'success';
                logActivity('building_deleted', 'buildings', $building_id);
            } else {
                $message = 'Error deleting building: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        }
    }
    
    elseif ($action === 'add_flat') {
        $building_id = (int)$_POST['building_id'];
        $flat_number = sanitizeInput($_POST['flat_number']);
        $floor_number = (int)$_POST['floor_number'];
        $flat_type = sanitizeInput($_POST['flat_type']);
        $owner_name = sanitizeInput($_POST['owner_name']);
        $owner_phone = sanitizeInput($_POST['owner_phone']);
        $owner_email = sanitizeInput($_POST['owner_email']);
        $is_occupied = isset($_POST['is_occupied']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (empty($building_id)) {
            $errors[] = 'Please select a building.';
        }
        if (empty($flat_number)) {
            $errors[] = 'Flat number is required.';
        }
        if (empty($flat_type)) {
            $errors[] = 'Flat type is required.';
        }
        if (!empty($owner_email) && !filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        // Check if flat number already exists in the same building
        $sql = "SELECT id FROM flats WHERE building_id = ? AND flat_number = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("is", $building_id, $flat_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = 'Flat number already exists in this building.';
        }
        $stmt->close();
        
        if (empty($errors)) {
            $sql = "INSERT INTO flats (building_id, flat_number, floor_number, flat_type, owner_name, owner_phone, owner_email, is_occupied) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("isissssi", $building_id, $flat_number, $floor_number, $flat_type, $owner_name, $owner_phone, $owner_email, $is_occupied);
            
            if ($stmt->execute()) {
                // Update building's total flats count
                $update_sql = "UPDATE buildings SET total_flats = (SELECT COUNT(*) FROM flats WHERE building_id = ?) WHERE id = ?";
                $update_stmt = $db->prepare($update_sql);
                $update_stmt->bind_param("ii", $building_id, $building_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                $message = 'Flat added successfully!';
                $message_type = 'success';
                logActivity('flat_added', 'flats', $db->getLastInsertId());
            } else {
                $message = 'Error adding flat: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'edit_flat') {
        $flat_id = (int)$_POST['flat_id'];
        $building_id = (int)$_POST['building_id'];
        $flat_number = sanitizeInput($_POST['flat_number']);
        $floor_number = (int)$_POST['floor_number'];
        $flat_type = sanitizeInput($_POST['flat_type']);
        $owner_name = sanitizeInput($_POST['owner_name']);
        $owner_phone = sanitizeInput($_POST['owner_phone']);
        $owner_email = sanitizeInput($_POST['owner_email']);
        $is_occupied = isset($_POST['is_occupied']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (empty($building_id)) {
            $errors[] = 'Please select a building.';
        }
        if (empty($flat_number)) {
            $errors[] = 'Flat number is required.';
        }
        if (empty($flat_type)) {
            $errors[] = 'Flat type is required.';
        }
        if (!empty($owner_email) && !filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        // Check if flat number already exists in the same building (excluding current flat)
        $sql = "SELECT id FROM flats WHERE building_id = ? AND flat_number = ? AND id != ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("isi", $building_id, $flat_number, $flat_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = 'Flat number already exists in this building.';
        }
        $stmt->close();
        
        if (empty($errors)) {
            $sql = "UPDATE flats SET building_id = ?, flat_number = ?, floor_number = ?, flat_type = ?, owner_name = ?, owner_phone = ?, owner_email = ?, is_occupied = ?, is_active = ? WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("isissssiii", $building_id, $flat_number, $floor_number, $flat_type, $owner_name, $owner_phone, $owner_email, $is_occupied, $is_active, $flat_id);
            
            if ($stmt->execute()) {
                // Update building's total flats count
                $update_sql = "UPDATE buildings SET total_flats = (SELECT COUNT(*) FROM flats WHERE building_id = ?) WHERE id = ?";
                $update_stmt = $db->prepare($update_sql);
                $update_stmt->bind_param("ii", $building_id, $building_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                $message = 'Flat updated successfully!';
                $message_type = 'success';
                logActivity('flat_updated', 'flats', $flat_id);
            } else {
                $message = 'Error updating flat: ' . $db->getConnection()->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
    }
    
    elseif ($action === 'delete_flat') {
        $flat_id = (int)$_POST['flat_id'];
        
        // Get building_id before deletion
        $sql = "SELECT building_id FROM flats WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $flat_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $flat = $result->fetch_assoc();
        $building_id = $flat['building_id'];
        $stmt->close();
        
        $sql = "DELETE FROM flats WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $flat_id);
        
        if ($stmt->execute()) {
            // Update building's total flats count
            $update_sql = "UPDATE buildings SET total_flats = (SELECT COUNT(*) FROM flats WHERE building_id = ?) WHERE id = ?";
            $update_stmt = $db->prepare($update_sql);
            $update_stmt->bind_param("ii", $building_id, $building_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $message = 'Flat deleted successfully!';
            $message_type = 'success';
            logActivity('flat_deleted', 'flats', $flat_id);
        } else {
            $message = 'Error deleting flat: ' . $db->getConnection()->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Get all buildings
$sql = "SELECT * FROM buildings ORDER BY name";
$buildings_result = $db->query($sql);
$buildings = [];
if ($buildings_result) {
    while ($row = $buildings_result->fetch_assoc()) {
        $buildings[] = $row;
    }
}

// Get all flats with building information
$sql = "SELECT 
            f.*,
            b.name as building_name
        FROM flats f
        JOIN buildings b ON f.building_id = b.id
        ORDER BY b.name, f.floor_number, f.flat_number";

$flats_result = $db->query($sql);
$flats = [];
if ($flats_result) {
    while ($row = $flats_result->fetch_assoc()) {
        $flats[] = $row;
    }
}

// Get building for editing
$edit_building = null;
if (isset($_GET['edit_building'])) {
    $edit_id = (int)$_GET['edit_building'];
    $sql = "SELECT * FROM buildings WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_building = $result->fetch_assoc();
    $stmt->close();
}

// Get flat for editing
$edit_flat = null;
if (isset($_GET['edit_flat'])) {
    $edit_id = (int)$_GET['edit_flat'];
    $sql = "SELECT * FROM flats WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_flat = $result->fetch_assoc();
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
    <title><?php echo SITE_NAME; ?> - Buildings & Flats Management</title>
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
                    <a href="buildings_flats.php" class="flex items-center px-4 py-3 text-white bg-white/20 rounded-lg">
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
                            <h1 class="text-2xl font-bold text-gray-900">Buildings & Flats Management</h1>
                            <p class="text-gray-600 mt-1">Manage buildings and flats in your property</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <button onclick="toggleAddBuildingForm()" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition-colors">
                                <i class="ri-building-line mr-2"></i>Add Building
                            </button>
                            <button onclick="toggleAddFlatForm()" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                                <i class="ri-home-line mr-2"></i>Add Flat
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
                                    <a href="buildings_flats.php?logout=1" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
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

                <!-- Add Building Form -->
                <div id="addBuildingForm" class="bg-white rounded-xl shadow-sm border <?php echo $edit_building ? '' : 'hidden'; ?>">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <?php echo $edit_building ? 'Edit Building' : 'Add New Building'; ?>
                        </h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="<?php echo $edit_building ? 'edit_building' : 'add_building'; ?>">
                        <?php if ($edit_building): ?>
                            <input type="hidden" name="building_id" value="<?php echo $edit_building['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">Building Name *</label>
                                <input type="text" id="name" name="name" required
                                       value="<?php echo $edit_building ? htmlspecialchars($edit_building['name']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="address" class="block text-sm font-medium text-gray-700">Address *</label>
                                <textarea id="address" name="address" required rows="3"
                                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary"><?php echo $edit_building ? htmlspecialchars($edit_building['address']) : ''; ?></textarea>
                            </div>
                            
                            <?php if ($edit_building): ?>
                            <div class="md:col-span-2">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_active" <?php echo $edit_building['is_active'] ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                                    <span class="ml-2 text-sm text-gray-700">Active</span>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="toggleAddBuildingForm()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-primary/90">
                                <?php echo $edit_building ? 'Update Building' : 'Add Building'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Add Flat Form -->
                <div id="addFlatForm" class="bg-white rounded-xl shadow-sm border <?php echo $edit_flat ? '' : 'hidden'; ?>">
                    <div class="p-6 border-b">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <?php echo $edit_flat ? 'Edit Flat' : 'Add New Flat'; ?>
                        </h2>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="<?php echo $edit_flat ? 'edit_flat' : 'add_flat'; ?>">
                        <?php if ($edit_flat): ?>
                            <input type="hidden" name="flat_id" value="<?php echo $edit_flat['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="building_id" class="block text-sm font-medium text-gray-700">Building *</label>
                                <select id="building_id" name="building_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="">Select Building</option>
                                    <?php foreach ($buildings as $building): ?>
                                    <option value="<?php echo $building['id']; ?>" <?php echo ($edit_flat && $edit_flat['building_id'] == $building['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($building['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="flat_number" class="block text-sm font-medium text-gray-700">Flat Number *</label>
                                <input type="text" id="flat_number" name="flat_number" required
                                       value="<?php echo $edit_flat ? htmlspecialchars($edit_flat['flat_number']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="floor_number" class="block text-sm font-medium text-gray-700">Floor Number</label>
                                <input type="number" id="floor_number" name="floor_number" min="0" max="100"
                                       value="<?php echo $edit_flat ? $edit_flat['floor_number'] : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="flat_type" class="block text-sm font-medium text-gray-700">Flat Type *</label>
                                <select id="flat_type" name="flat_type" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                    <option value="">Select Type</option>
                                    <option value="1BHK" <?php echo ($edit_flat && $edit_flat['flat_type'] === '1BHK') ? 'selected' : ''; ?>>1BHK</option>
                                    <option value="2BHK" <?php echo ($edit_flat && $edit_flat['flat_type'] === '2BHK') ? 'selected' : ''; ?>>2BHK</option>
                                    <option value="3BHK" <?php echo ($edit_flat && $edit_flat['flat_type'] === '3BHK') ? 'selected' : ''; ?>>3BHK</option>
                                    <option value="4BHK" <?php echo ($edit_flat && $edit_flat['flat_type'] === '4BHK') ? 'selected' : ''; ?>>4BHK</option>
                                    <option value="Penthouse" <?php echo ($edit_flat && $edit_flat['flat_type'] === 'Penthouse') ? 'selected' : ''; ?>>Penthouse</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="owner_name" class="block text-sm font-medium text-gray-700">Owner Name</label>
                                <input type="text" id="owner_name" name="owner_name"
                                       value="<?php echo $edit_flat ? htmlspecialchars($edit_flat['owner_name']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div>
                                <label for="owner_phone" class="block text-sm font-medium text-gray-700">Owner Phone</label>
                                <input type="tel" id="owner_phone" name="owner_phone"
                                       value="<?php echo $edit_flat ? htmlspecialchars($edit_flat['owner_phone']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="owner_email" class="block text-sm font-medium text-gray-700">Owner Email</label>
                                <input type="email" id="owner_email" name="owner_email"
                                       value="<?php echo $edit_flat ? htmlspecialchars($edit_flat['owner_email']) : ''; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                            </div>
                            
                            <div class="md:col-span-2">
                                <div class="flex space-x-6">
                                    <label class="flex items-center">
                                        <input type="checkbox" name="is_occupied" <?php echo ($edit_flat && $edit_flat['is_occupied']) ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                                        <span class="ml-2 text-sm text-gray-700">Occupied</span>
                                    </label>
                                    
                                    <?php if ($edit_flat): ?>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="is_active" <?php echo $edit_flat['is_active'] ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                                        <span class="ml-2 text-sm text-gray-700">Active</span>
                                    </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="toggleAddFlatForm()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                <?php echo $edit_flat ? 'Update Flat' : 'Add Flat'; ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Buildings List -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Buildings</h2>
                                <p class="text-gray-600 text-sm mt-1">Manage all buildings in your property</p>
                            </div>
                            <div class="text-sm text-gray-500">
                                Total Buildings: <?php echo count($buildings); ?>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Building</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Address</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Flats</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($buildings)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No buildings found. Add a building to get started.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($buildings as $building): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center mr-3">
                                                <i class="ri-building-line text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($building['name']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <div class="max-w-xs truncate"><?php echo htmlspecialchars($building['address']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $building['total_flats']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $building['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <div class="w-2 h-2 <?php echo $building['is_active'] ? 'bg-green-400' : 'bg-red-400'; ?> rounded-full mr-1"></div>
                                            <?php echo $building['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo formatDate($building['created_at']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="?edit_building=<?php echo $building['id']; ?>" class="text-primary hover:text-primary/80">
                                                <i class="ri-edit-line"></i>
                                            </a>
                                            <button onclick="deleteBuilding(<?php echo $building['id']; ?>, '<?php echo htmlspecialchars($building['name']); ?>')" class="text-red-600 hover:text-red-800">
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

                <!-- Flats List -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Flats</h2>
                                <p class="text-gray-600 text-sm mt-1">Manage all flats across all buildings</p>
                            </div>
                            <div class="text-sm text-gray-500">
                                Total Flats: <?php echo count($flats); ?>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flat</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Building</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Floor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Owner</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($flats)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                        No flats found. Add a flat to get started.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($flats as $flat): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-green-600 rounded-full flex items-center justify-center mr-3">
                                                <i class="ri-home-line text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($flat['flat_number']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($flat['building_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $flat['floor_number'] ?: '-'; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $flat['flat_type']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div><?php echo $flat['owner_name'] ? htmlspecialchars($flat['owner_name']) : '-'; ?></div>
                                        <?php if ($flat['owner_phone']): ?>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($flat['owner_phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col space-y-1">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $flat['is_occupied'] ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                <?php echo $flat['is_occupied'] ? 'Occupied' : 'Vacant'; ?>
                                            </span>
                                            <?php if (!$flat['is_active']): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                Inactive
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="?edit_flat=<?php echo $flat['id']; ?>" class="text-primary hover:text-primary/80">
                                                <i class="ri-edit-line"></i>
                                            </a>
                                            <button onclick="deleteFlat(<?php echo $flat['id']; ?>, '<?php echo htmlspecialchars($flat['flat_number']); ?>')" class="text-red-600 hover:text-red-800">
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

    <!-- Delete Building Modal -->
    <div id="deleteBuildingModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Delete Building</h3>
                <p class="text-sm text-gray-600 mb-4">Are you sure you want to delete this building? This action cannot be undone.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_building">
                    <input type="hidden" name="building_id" id="deleteBuildingId">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeDeleteBuildingModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                            Delete Building
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Flat Modal -->
    <div id="deleteFlatModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Delete Flat</h3>
                <p class="text-sm text-gray-600 mb-4">Are you sure you want to delete this flat? This action cannot be undone.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_flat">
                    <input type="hidden" name="flat_id" id="deleteFlatId">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeDeleteFlatModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                            Delete Flat
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

        // Toggle add building form
        function toggleAddBuildingForm() {
            const form = document.getElementById('addBuildingForm');
            form.classList.toggle('hidden');
            
            if (form.classList.contains('hidden')) {
                window.location.href = 'buildings_flats.php';
            } else {
                document.getElementById('name').focus();
            }
        }

        // Toggle add flat form
        function toggleAddFlatForm() {
            const form = document.getElementById('addFlatForm');
            form.classList.toggle('hidden');
            
            if (form.classList.contains('hidden')) {
                window.location.href = 'buildings_flats.php';
            } else {
                document.getElementById('building_id').focus();
            }
        }

        // Delete building
        function deleteBuilding(buildingId, buildingName) {
            document.getElementById('deleteBuildingId').value = buildingId;
            document.getElementById('deleteBuildingModal').classList.remove('hidden');
        }

        function closeDeleteBuildingModal() {
            document.getElementById('deleteBuildingModal').classList.add('hidden');
        }

        // Delete flat
        function deleteFlat(flatId, flatNumber) {
            document.getElementById('deleteFlatId').value = flatId;
            document.getElementById('deleteFlatModal').classList.remove('hidden');
        }

        function closeDeleteFlatModal() {
            document.getElementById('deleteFlatModal').classList.add('hidden');
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(event) {
            const buildingModal = document.getElementById('deleteBuildingModal');
            const flatModal = document.getElementById('deleteFlatModal');
            
            if (event.target === buildingModal) {
                closeDeleteBuildingModal();
            }
            if (event.target === flatModal) {
                closeDeleteFlatModal();
            }
        });
    </script>
</body>
</html>
