<?php
/**
 * TappTrak Dashboard
 * Main dashboard for security management system
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

// Get live guard activity data
$sql = "SELECT 
            g.id,
            g.guard_id,
            g.full_name,
            g.phone,
            gs.shift_name,
            gs.start_time,
            gs.end_time,
            ga.status,
            ga.check_in_time,
            ga.check_out_time,
            ga.attendance_date
        FROM guards g
        JOIN guard_shifts gs ON g.id = gs.guard_id
        LEFT JOIN guard_attendance ga ON g.id = ga.guard_id AND ga.attendance_date = CURDATE()
        WHERE g.is_active = TRUE AND gs.is_active = TRUE
        ORDER BY g.guard_id";

$guards_result = $db->query($sql);
$guards = [];
if ($guards_result) {
    while ($row = $guards_result->fetch_assoc()) {
        $guards[] = $row;
    }
}

// Get current visitors data
$sql = "SELECT 
            vl.id,
            v.full_name,
            v.phone,
            f.flat_number,
            vl.check_in_time,
            vl.expected_duration,
            vl.status,
            vl.purpose,
            g.full_name as guard_name,
            TIMESTAMPDIFF(MINUTE, vl.check_in_time, NOW()) as minutes_inside
        FROM visitor_logs vl
        JOIN visitors v ON vl.visitor_id = v.id
        JOIN flats f ON vl.flat_id = f.id
        JOIN guards g ON vl.guard_id = g.id
        WHERE vl.status IN ('inside', 'overstayed') AND vl.check_out_time IS NULL
        ORDER BY vl.check_in_time DESC";

$visitors_result = $db->query($sql);
$current_visitors = [];
if ($visitors_result) {
    while ($row = $visitors_result->fetch_assoc()) {
        $current_visitors[] = $row;
    }
}

// Get recent visitor logs (last 10)
$sql = "SELECT 
            vl.id,
            v.full_name,
            v.phone,
            f.flat_number,
            vl.check_in_time,
            vl.check_out_time,
            vl.status,
            vl.purpose,
            g.full_name as guard_name
        FROM visitor_logs vl
        JOIN visitors v ON vl.visitor_id = v.id
        JOIN flats f ON vl.flat_id = f.id
        JOIN guards g ON vl.guard_id = g.id
        ORDER BY vl.check_in_time DESC
        LIMIT 10";

$recent_visitors_result = $db->query($sql);
$recent_visitors = [];
if ($recent_visitors_result) {
    while ($row = $recent_visitors_result->fetch_assoc()) {
        $recent_visitors[] = $row;
    }
}

// Get active alerts
$sql = "SELECT 
            a.id,
            a.title,
            a.message,
            a.severity,
            a.status,
            at.type_name,
            a.created_at,
            u.full_name as created_by_name
        FROM alerts a
        JOIN alert_types at ON a.alert_type_id = at.id
        JOIN users u ON a.created_by = u.id
        WHERE a.status = 'active'
        ORDER BY 
            CASE a.severity 
                WHEN 'critical' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                WHEN 'low' THEN 4 
            END,
            a.created_at DESC
        LIMIT 5";

$alerts_result = $db->query($sql);
$active_alerts = [];
if ($alerts_result) {
    while ($row = $alerts_result->fetch_assoc()) {
        $active_alerts[] = $row;
    }
}

// Get alert count
$sql = "SELECT COUNT(*) as count FROM alerts WHERE status = 'active'";
$alert_count_result = $db->query($sql);
$alert_count = 0;
if ($alert_count_result) {
    $row = $alert_count_result->fetch_assoc();
    $alert_count = $row['count'];
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
    <title><?php echo SITE_NAME; ?> - Dashboard</title>
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
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-white bg-white/20 rounded-lg">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-dashboard-line"></i>
                        </div>
                        Dashboard
                    </a>
                    <?php if (isAdmin()): ?>
                    <a href="guards.php" class="flex items-center px-4 py-3 text-white/80 hover:bg-white/10 rounded-lg transition-colors">
                        <div class="w-5 h-5 flex items-center justify-center mr-3">
                            <i class="ri-shield-user-line"></i>
                        </div>
                        Guards
                    </a>
                    <?php endif; ?>
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
                            <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
                            <p class="text-gray-600 mt-1">Security Management Overview</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div class="w-8 h-8 flex items-center justify-center text-gray-600">
                                <i class="ri-notification-3-line ri-lg"></i>
                            </div>
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
                                    <a href="dashboard.php?logout=1" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="ri-logout-box-line mr-2"></i>Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <main class="p-8 space-y-8">
                <!-- Live Guard Activity -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Live Guard Activity</h2>
                                <p class="text-gray-600 text-sm mt-1">Current guard status and shift information</p>
                            </div>
                            <div class="flex items-center space-x-2 text-sm text-gray-500">
                                <div class="w-4 h-4 flex items-center justify-center">
                                    <i class="ri-time-line"></i>
                                </div>
                                <span>Last updated: <?php echo getTimeAgo(date('Y-m-d H:i:s')); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guard Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guard ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Shift</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($guards)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                                        No guards found. Please add guards to the system.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($guards as $guard): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center mr-3">
                                                <i class="ri-user-line text-white text-sm"></i>
                                            </div>
                                            <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($guard['full_name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($guard['guard_id']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($guard['shift_name']); ?><br>
                                        <span class="text-xs text-gray-500">
                                            <?php echo formatTime($guard['start_time']); ?> - <?php echo formatTime($guard['end_time']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status = $guard['status'] ?? 'scheduled';
                                        $status_class = '';
                                        $status_text = '';
                                        $status_icon = '';
                                        
                                        switch ($status) {
                                            case 'checked_in':
                                                $status_class = 'bg-green-100 text-green-800';
                                                $status_text = 'Active';
                                                $status_icon = 'bg-green-400';
                                                break;
                                            case 'checked_out':
                                                $status_class = 'bg-gray-100 text-gray-800';
                                                $status_text = 'Off Duty';
                                                $status_icon = 'bg-gray-400';
                                                break;
                                            case 'late':
                                                $status_class = 'bg-yellow-100 text-yellow-800';
                                                $status_text = 'Late';
                                                $status_icon = 'bg-yellow-400';
                                                break;
                                            case 'absent':
                                                $status_class = 'bg-red-100 text-red-800';
                                                $status_text = 'Absent';
                                                $status_icon = 'bg-red-400';
                                                break;
                                            default:
                                                $status_class = 'bg-gray-100 text-gray-800';
                                                $status_text = 'Scheduled';
                                                $status_icon = 'bg-gray-400';
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                            <div class="w-2 h-2 <?php echo $status_icon; ?> rounded-full mr-1"></div>
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

                <!-- Live Visitor Logs -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Live Visitor Logs</h2>
                                <p class="text-gray-600 text-sm mt-1">Real-time visitor tracking and management</p>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="relative">
                                    <input type="text" id="visitorSearch" placeholder="Search visitors..." class="pl-8 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                                    <div class="absolute left-2.5 top-2.5 w-4 h-4 flex items-center justify-center text-gray-400">
                                        <i class="ri-search-line text-sm"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visitor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flat</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Entry Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Checkout Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="visitorTableBody">
                                <?php if (empty($recent_visitors)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No visitor logs found.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($recent_visitors as $visitor): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center mr-3">
                                                <i class="ri-user-line text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($visitor['full_name']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($visitor['phone']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($visitor['flat_number']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo formatDateTime($visitor['check_in_time']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $visitor['check_out_time'] ? formatDateTime($visitor['check_out_time']) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status = $visitor['status'];
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
                                                $status_class = 'bg-yellow-100 text-yellow-800';
                                                $status_text = 'Overstayed';
                                                break;
                                            case 'forced_exit':
                                                $status_class = 'bg-red-100 text-red-800';
                                                $status_text = 'Forced Exit';
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

                <!-- Alerts Section -->
                <section class="bg-white rounded-xl shadow-sm border">
                    <div class="p-6 border-b">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Security Alerts</h2>
                                <p class="text-gray-600 text-sm mt-1">Critical notifications requiring attention</p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    <?php echo $alert_count; ?> Active Alerts
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="p-6 space-y-4">
                        <?php if (empty($active_alerts)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="ri-check-line text-4xl text-green-500 mb-2"></i>
                            <p>No active alerts. All systems are running smoothly.</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($active_alerts as $alert): ?>
                        <div class="flex items-start space-x-4 p-4 <?php 
                            echo $alert['severity'] === 'critical' ? 'bg-red-50 border border-red-200' : 
                                ($alert['severity'] === 'high' ? 'bg-orange-50 border border-orange-200' : 
                                ($alert['severity'] === 'medium' ? 'bg-yellow-50 border border-yellow-200' : 'bg-blue-50 border border-blue-200')); 
                        ?> rounded-lg">
                            <div class="w-6 h-6 flex items-center justify-center <?php 
                                echo $alert['severity'] === 'critical' ? 'text-red-600' : 
                                    ($alert['severity'] === 'high' ? 'text-orange-600' : 
                                    ($alert['severity'] === 'medium' ? 'text-yellow-600' : 'text-blue-600')); 
                            ?> mt-0.5">
                                <i class="ri-alert-line"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-sm font-medium <?php 
                                        echo $alert['severity'] === 'critical' ? 'text-red-900' : 
                                            ($alert['severity'] === 'high' ? 'text-orange-900' : 
                                            ($alert['severity'] === 'medium' ? 'text-yellow-900' : 'text-blue-900')); 
                                    ?>"><?php echo htmlspecialchars($alert['title']); ?></h3>
                                    <span class="text-xs <?php 
                                        echo $alert['severity'] === 'critical' ? 'text-red-600' : 
                                            ($alert['severity'] === 'high' ? 'text-orange-600' : 
                                            ($alert['severity'] === 'medium' ? 'text-yellow-600' : 'text-blue-600')); 
                                    ?>"><?php echo getTimeAgo($alert['created_at']); ?></span>
                                </div>
                                <p class="text-sm <?php 
                                    echo $alert['severity'] === 'critical' ? 'text-red-700' : 
                                        ($alert['severity'] === 'high' ? 'text-orange-700' : 
                                        ($alert['severity'] === 'medium' ? 'text-yellow-700' : 'text-blue-700')); 
                                ?> mt-1"><?php echo htmlspecialchars($alert['message']); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
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

        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('visitorSearch');
            const visitorRows = document.querySelectorAll('#visitorTableBody tr');
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                
                visitorRows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    let found = false;
                    
                    cells.forEach(cell => {
                        if (cell.textContent.toLowerCase().includes(searchTerm)) {
                            found = true;
                        }
                    });
                    
                    row.style.display = found ? '' : 'none';
                });
            });
        });

        // Real-time updates
        document.addEventListener('DOMContentLoaded', function() {
            function updateTimestamp() {
                const timestampElements = document.querySelectorAll('[class*="Last updated"]');
                timestampElements.forEach(element => {
                    element.innerHTML = `
                        <div class="w-4 h-4 flex items-center justify-center">
                            <i class="ri-time-line"></i>
                        </div>
                        <span>Last updated: just now</span>
                    `;
                });
            }
            
            // Update every minute
            setInterval(updateTimestamp, 60000);
        });

        // Auto-refresh page every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);
    </script>
</body>
</html>
