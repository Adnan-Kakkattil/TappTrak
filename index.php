<?php
/**
 * TappTrak Login Page
 * User authentication for Admin and Security roles
 */

require_once 'config.php';

// Get database instance
$db = Database::getInstance();

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$message = '';
$message_type = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    
    // Validation
    if (empty($email) || empty($password)) {
        $message = 'Please enter both email and password.';
        $message_type = 'error';
    } else {
        // Check if user is locked out
        if (checkLoginAttempts($email)) {
            $message = 'Too many failed login attempts. Please try again later.';
            $message_type = 'error';
        } else {
            // Authenticate user
            $sql = "SELECT id, email, password_hash, role, full_name, phone, is_active FROM users WHERE email = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                if (!$row['is_active']) {
                    $message = 'Your account has been deactivated. Please contact administrator.';
                    $message_type = 'error';
                    recordLoginAttempt($email, false);
                } elseif (verifyPassword($password, $row['password_hash'])) {
                    // Login successful
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['user_email'] = $row['email'];
                    $_SESSION['user_role'] = $row['role'];
                    $_SESSION['user_name'] = $row['full_name'];
                    $_SESSION['user_phone'] = $row['phone'];
                    $_SESSION['last_activity'] = time();
                    
                    // Update last login time
                    $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->bind_param("i", $row['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Log successful login
                    recordLoginAttempt($email, true);
                    logActivity('login_success', 'users', $row['id']);
                    
                    // Redirect to dashboard
                    redirect('dashboard.php');
                } else {
                    $message = 'Invalid email or password.';
                    $message_type = 'error';
                    recordLoginAttempt($email, false);
                }
            } else {
                $message = 'Invalid email or password.';
                $message_type = 'error';
                recordLoginAttempt($email, false);
            }
            
            $stmt->close();
        }
    }
}

// Handle timeout message
if (isset($_GET['timeout'])) {
    $message = 'Your session has expired. Please login again.';
    $message_type = 'warning';
}

// Handle logout message
if (isset($_GET['logout'])) {
    $message = 'You have been logged out successfully.';
    $message_type = 'success';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Login</title>
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
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div class="text-center">
                <div class="mx-auto h-20 w-20 bg-primary rounded-full flex items-center justify-center shadow-lg">
                    <i class="ri-shield-check-line text-white text-3xl"></i>
                </div>
                <h2 class="mt-6 text-4xl font-bold text-gray-900">
                    <?php echo SITE_NAME; ?>
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    Security Management System
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    Sign in to your account
                </p>
            </div>

            <?php if ($message): ?>
                <div class="rounded-md p-4 <?php 
                    echo $message_type === 'success' ? 'bg-green-50 border border-green-200' : 
                        ($message_type === 'warning' ? 'bg-yellow-50 border border-yellow-200' : 'bg-red-50 border border-red-200'); 
                ?>">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <?php if ($message_type === 'success'): ?>
                                <i class="ri-check-line text-green-400"></i>
                            <?php elseif ($message_type === 'warning'): ?>
                                <i class="ri-alert-line text-yellow-400"></i>
                            <?php else: ?>
                                <i class="ri-error-warning-line text-red-400"></i>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm <?php 
                                echo $message_type === 'success' ? 'text-green-800' : 
                                    ($message_type === 'warning' ? 'text-yellow-800' : 'text-red-800'); 
                            ?>">
                                <?php echo $message; ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form class="mt-8 space-y-6" method="POST">
                <div class="bg-white py-8 px-4 shadow rounded-lg sm:px-10">
                    <div class="space-y-6">
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">
                                Email Address
                            </label>
                            <div class="mt-1 relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="ri-mail-line text-gray-400"></i>
                                </div>
                                <input id="email" name="email" type="email" required
                                       class="appearance-none block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                       placeholder="Enter your email address"
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                            </div>
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">
                                Password
                            </label>
                            <div class="mt-1 relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="ri-lock-line text-gray-400"></i>
                                </div>
                                <input id="password" name="password" type="password" required
                                       class="appearance-none block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                       placeholder="Enter your password">
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <input id="remember-me" name="remember-me" type="checkbox"
                                       class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                                <label for="remember-me" class="ml-2 block text-sm text-gray-900">
                                    Remember me
                                </label>
                            </div>

                            <div class="text-sm">
                                <a href="#" class="font-medium text-primary hover:text-primary/80">
                                    Forgot your password?
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <button type="submit"
                                class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors duration-200">
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                <i class="ri-login-box-line text-primary group-hover:text-primary/80"></i>
                            </span>
                            Sign in
                        </button>
                    </div>
                </div>
            </form>

            <div class="text-center">
                <div class="flex items-center justify-center space-x-4 text-xs text-gray-500">
                    <div class="flex items-center">
                        <i class="ri-shield-user-line mr-1"></i>
                        <span>Admin Access</span>
                    </div>
                    <div class="flex items-center">
                        <i class="ri-shield-check-line mr-1"></i>
                        <span>Security Access</span>
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    TappTrak Security Management System
                </p>
            </div>
        </div>
    </div>

    <script>
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                alert('Please enter both email and password.');
                return false;
            }
            
            if (!isValidEmail(email)) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return false;
            }
        });

        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        // Auto-focus on email field
        document.getElementById('email').focus();

        // Enter key handling
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('form').submit();
            }
        });

        // Remember me functionality
        document.getElementById('remember-me').addEventListener('change', function() {
            if (this.checked) {
                // Store email in localStorage for next time
                const email = document.getElementById('email').value;
                if (email) {
                    localStorage.setItem('tapptrak_email', email);
                }
            } else {
                localStorage.removeItem('tapptrak_email');
            }
        });

        // Load remembered email
        window.addEventListener('load', function() {
            const rememberedEmail = localStorage.getItem('tapptrak_email');
            if (rememberedEmail && !document.getElementById('email').value) {
                document.getElementById('email').value = rememberedEmail;
                document.getElementById('remember-me').checked = true;
                document.getElementById('password').focus();
            }
        });
    </script>
</body>
</html>
