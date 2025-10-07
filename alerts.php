<?php
/**
 * TappTrak Alerts Management
 * View and manage security alerts
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'acknowledge_alert') {
        $alert_id = (int)$_POST['alert_id'];
        
        $sql = "UPDATE alerts SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ii", $user_id, $alert_id);
        
        if ($stmt->execute()) {
            $message = 'Alert acknowledged successfully!';
            $message_type = 'success';
            logActivity('alert_acknowledged', 'alerts', $alert_id);
        } else {
            $message = 'Error acknowledging alert: ' . $db->getConnection()->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
    
    elseif ($action === 'resolve_alert') {
        $alert_id = (int)$_POST['alert_id'];
        
        $sql = "UPDATE alerts SET status = 'resolved', resolved_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $alert_id);
        
        if ($stmt->execute()) {
            $message = 'Alert resolved successfully!';
            $message_type = 'success';
            logActivity('alert_resolved', 'alerts', $alert_id);
        } else {
            $message = 'Error resolving alert: ' . $db->getConnection()->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
    
    elseif ($action === 'dismiss_alert') {
        $alert_id = (int)$_POST['alert_id'];
        
        $sql = "UPDATE alerts SET status = 'dismissed' WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $alert_id);
        
        if ($stmt->execute()) {
            $message = 'Alert dismissed successfully!';
            $message_type = 'success';
            logActivity('alert_dismissed', 'alerts', $alert_id);
        } else {
            $message = 'Error dismissing alert: ' . $db->getConnection()->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$severity_filter = $_GET['severity'] ?? 'all';

// Build query
$where_conditions = [];
$params = [];
$param_types = '';

if ($status_filter !== 'all') {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if ($severity_filter !== 'all') {
    $where_conditions[] = "a.severity = ?";
    $params[] = $severity_filter;
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$sql = "SELECT 
            a.*,
            at.type_name,
            at.description as type_description,
            u1.full_name as created_by_name,
            u2.full_name as acknowledged_by_name,
            vl.id as visitor_log_id,
            v.full_name as visitor_name,
            f.flat_number,
            g.full_name as guard_name
        FROM alerts a
        JOIN alert_types at ON a.alert_type_id = at.id
        JOIN users u1 ON a.created_by = u1.id
        LEFT JOIN users u2 ON a.acknowledged_by = u2.id
        LEFT JOIN visitor_logs vl ON a.related_visitor_log_id = vl.id
        LEFT JOIN visitors v ON vl.visitor_id = v.id
        LEFT JOIN flats f ON a.related_flat_id = f.id
        LEFT JOIN guards g ON a.related_guard_id = g.id
        $where_clause
        ORDER BY 
            CASE a.severity 
                WHEN 'critical' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                WHEN 'low' THEN 4 
            END,
            a.created_at DESC";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$alerts = [];
while ($row = $result->fetch_assoc()) {
    $alerts[] = $row;
}
$stmt->close();

// Get alert counts
$sql = "SELECT 
            status,
            COUNT(*) as count
        FROM alerts 
        GROUP BY status";
$result = $db->query($sql);
$alert_counts = [];
while ($row = $result->fetch_assoc()) {
    $alert_counts[$row['status']] = $row['count'];
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
    <title><?php echo SITE_NAME; ?> - Alerts Management</title>
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
                    <a href="alerts.php" class="flex items-center px-4 py-3 text-white bg-white/20 rounded-lg">
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
                            <h1 class="text-2xl font-bold text-gray-900">Security Alerts</h1>
                            <p class="text-gray-600 mt-1">Monitor and manage security alerts</p>
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
                                    <a href="alerts.php?logout=1" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
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

                <!-- Alert Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-2 bg-red-100 rounded-lg">
                                <i class="ri-alarm-warning-line text-red-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Active Alerts</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo $alert_counts['active'] ?? 0; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-2 bg-yellow-100 rounded-lg">
                                <i class="ri-time-line text-yellow-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Acknowledged</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo $alert_counts['acknowledged'] ?? 0; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-2 bg-green-100 rounded-lg">
                                <i class="ri-check-line text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Resolved</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo $alert_counts['resolved'] ?? 0; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-2 bg-gray-100 rounded-lg">
                                <i class="ri-close-line text-gray-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Dismissed</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo $alert_counts['dismissed'] ?? 0; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex flex-wrap gap-4">
                        <div>
                            <label for="statusFilter" class="block text-sm font-medium text-gray-700">Status</label>
                            <select id="statusFilter" onchange="applyFilters()" class="mt-1 block px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="acknowledged" <?php echo $status_filter === 'acknowledged' ? 'selected' : ''; ?>>Acknowledged</option>
                                <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="dismissed" <?php echo $status_filter === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="severityFilter" class="block text-sm font-medium text-gray-700">Severity</label>
                            <select id="severityFilter" onchange="applyFilters()" class="mt-1 block px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary focus:border-primary">
                                <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severity</option>
                                <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                <option value="high" <?php echo $severity_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $severity_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $severity_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        
                        <div class="flex items-end">
                            <button onclick="clearFilters()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                                Clear Filters
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Alerts List -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Security Alerts</h2>
                                <p class="text-gray-600 text-sm mt-1">Monitor and manage all security alerts</p>
                            </div>
                            <div class="text-sm text-gray-500">
                                Total: <?php echo count($alerts); ?> alerts
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alert</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($alerts)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No alerts found matching your criteria.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($alerts as $alert): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-start">
                                            <div class="flex-shrink-0">
                                                <?php
                                                $icon_class = '';
                                                $icon = '';
                                                switch ($alert['severity']) {
                                                    case 'critical':
                                                        $icon_class = 'text-red-600';
                                                        $icon = 'ri-alarm-warning-line';
                                                        break;
                                                    case 'high':
                                                        $icon_class = 'text-orange-600';
                                                        $icon = 'ri-error-warning-line';
                                                        break;
                                                    case 'medium':
                                                        $icon_class = 'text-yellow-600';
                                                        $icon = 'ri-time-line';
                                                        break;
                                                    case 'low':
                                                        $icon_class = 'text-blue-600';
                                                        $icon = 'ri-information-line';
                                                        break;
                                                }
                                                ?>
                                                <i class="<?php echo $icon; ?> <?php echo $icon_class; ?> text-lg"></i>
                                            </div>
                                            <div class="ml-3">
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($alert['title']); ?></div>
                                                <div class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($alert['message']); ?></div>
                                                <?php if ($alert['visitor_name']): ?>
                                                <div class="text-xs text-gray-400 mt-1">
                                                    Visitor: <?php echo htmlspecialchars($alert['visitor_name']); ?>
                                                    <?php if ($alert['flat_number']): ?>
                                                    | Flat: <?php echo htmlspecialchars($alert['flat_number']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($alert['type_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $severity_class = '';
                                        switch ($alert['severity']) {
                                            case 'critical':
                                                $severity_class = 'bg-red-100 text-red-800';
                                                break;
                                            case 'high':
                                                $severity_class = 'bg-orange-100 text-orange-800';
                                                break;
                                            case 'medium':
                                                $severity_class = 'bg-yellow-100 text-yellow-800';
                                                break;
                                            case 'low':
                                                $severity_class = 'bg-blue-100 text-blue-800';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $severity_class; ?>">
                                            <?php echo ucfirst($alert['severity']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status_class = '';
                                        switch ($alert['status']) {
                                            case 'active':
                                                $status_class = 'bg-red-100 text-red-800';
                                                break;
                                            case 'acknowledged':
                                                $status_class = 'bg-yellow-100 text-yellow-800';
                                                break;
                                            case 'resolved':
                                                $status_class = 'bg-green-100 text-green-800';
                                                break;
                                            case 'dismissed':
                                                $status_class = 'bg-gray-100 text-gray-800';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                            <?php echo ucfirst($alert['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div><?php echo formatDateTime($alert['created_at']); ?></div>
                                        <div class="text-xs text-gray-500">by <?php echo htmlspecialchars($alert['created_by_name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <?php if ($alert['status'] === 'active'): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="acknowledge_alert">
                                                <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                                <button type="submit" class="text-yellow-600 hover:text-yellow-800" title="Acknowledge">
                                                    <i class="ri-time-line"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="resolve_alert">
                                                <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                                <button type="submit" class="text-green-600 hover:text-green-800" title="Resolve">
                                                    <i class="ri-check-line"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($alert['status'] !== 'dismissed'): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="dismiss_alert">
                                                <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                                <button type="submit" class="text-gray-600 hover:text-gray-800" title="Dismiss">
                                                    <i class="ri-close-line"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
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

        // Apply filters
        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const severity = document.getElementById('severityFilter').value;
            
            const params = new URLSearchParams();
            if (status !== 'all') params.append('status', status);
            if (severity !== 'all') params.append('severity', severity);
            
            window.location.href = 'alerts.php?' + params.toString();
        }

        // Clear filters
        function clearFilters() {
            window.location.href = 'alerts.php';
        }

        // Auto-refresh page every 30 seconds for real-time updates
        setTimeout(function() {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>
