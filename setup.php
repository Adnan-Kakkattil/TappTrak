<?php
/**
 * TappTrak Setup Page
 * Initial admin user creation and system setup
 */

require_once 'config.php';

// Check if admin user already exists
$db = Database::getInstance();
$sql = "SELECT COUNT(*) as count FROM users WHERE role = 'admin'";
$result = $db->query($sql);
$row = $result->fetch_assoc();

$admin_exists = $row['count'] > 0;

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$admin_exists) {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
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
        $errors[] = 'Please enter your full name.';
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
    
    if (empty($errors)) {
        // Create admin user
        $password_hash = hashPassword($password);
        
        $sql = "INSERT INTO users (email, password_hash, role, full_name, phone, is_active) 
                VALUES (?, ?, 'admin', ?, ?, 1)";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ssss", $email, $password_hash, $full_name, $phone);
        
        if ($stmt->execute()) {
            $message = 'Admin user created successfully! You can now login to the system.';
            $message_type = 'success';
            $admin_exists = true;
            
            // Log the setup activity
            logActivity('admin_user_created', 'users', $db->getLastInsertId());
        } else {
            $message = 'Error creating admin user: ' . $db->getConnection()->error;
            $message_type = 'error';
        }
        
        $stmt->close();
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Setup</title>
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
                <div class="mx-auto h-16 w-16 bg-primary rounded-full flex items-center justify-center">
                    <i class="ri-shield-check-line text-white text-2xl"></i>
                </div>
                <h2 class="mt-6 text-3xl font-bold text-gray-900">
                    <?php echo SITE_NAME; ?> Setup
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    <?php echo $admin_exists ? 'System is already configured' : 'Create your admin account to get started'; ?>
                </p>
            </div>

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

            <?php if ($admin_exists): ?>
                <div class="bg-white py-8 px-4 shadow rounded-lg sm:px-10">
                    <div class="text-center">
                        <div class="mx-auto h-12 w-12 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="ri-check-line text-green-600 text-xl"></i>
                        </div>
                        <h3 class="mt-4 text-lg font-medium text-gray-900">Setup Complete</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Your admin account has been created successfully. You can now access the system.
                        </p>
                        <div class="mt-6">
                            <a href="index.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                                <i class="ri-login-box-line mr-2"></i>
                                Go to Login
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <form class="mt-8 space-y-6" method="POST">
                    <div class="bg-white py-8 px-4 shadow rounded-lg sm:px-10">
                        <div class="space-y-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">
                                    Full Name
                                </label>
                                <div class="mt-1">
                                    <input id="full_name" name="full_name" type="text" required
                                           class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                           placeholder="Enter your full name">
                                </div>
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">
                                    Email Address
                                </label>
                                <div class="mt-1">
                                    <input id="email" name="email" type="email" required
                                           class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                           placeholder="Enter your email address">
                                </div>
                            </div>

                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">
                                    Phone Number
                                </label>
                                <div class="mt-1">
                                    <input id="phone" name="phone" type="tel"
                                           class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                           placeholder="Enter your phone number">
                                </div>
                            </div>

                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700">
                                    Password
                                </label>
                                <div class="mt-1">
                                    <input id="password" name="password" type="password" required
                                           class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                           placeholder="Enter your password">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    Password must be at least <?php echo PASSWORD_MIN_LENGTH; ?> characters long.
                                </p>
                            </div>

                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700">
                                    Confirm Password
                                </label>
                                <div class="mt-1">
                                    <input id="confirm_password" name="confirm_password" type="password" required
                                           class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                           placeholder="Confirm your password">
                                </div>
                            </div>
                        </div>

                        <div class="mt-6">
                            <button type="submit"
                                    class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                                <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                    <i class="ri-user-add-line text-primary group-hover:text-primary/80"></i>
                                </span>
                                Create Admin Account
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <div class="text-center">
                <p class="text-xs text-gray-500">
                    TappTrak Security Management System
                </p>
            </div>
        </div>
    </div>

    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const minLength = <?php echo PASSWORD_MIN_LENGTH; ?>;
            
            if (password.length < minLength) {
                this.setCustomValidity('Password must be at least ' + minLength + ' characters long');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
