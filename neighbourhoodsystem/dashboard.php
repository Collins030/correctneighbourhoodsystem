<?php
// dashboard.php - User dashboard with dynamic event counts

require_once 'config.php';

// Check if user is logged in
$user = verifyUserSession();
if (!$user) {
    header('Location: index.php');
    exit;
}

// Get user statistics
$stats = [
    'events_created' => 0,
    'neighbours_connected' => 0,
    'events_attended' => 0
];

try {
    $pdo = new PDO("mysql:host=localhost;dbname=neighbourhood_system", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get count of events created by the user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user['id']]);
    $stats['events_created'] = $stmt->fetchColumn();
    
    // Get count of events attended (if you have an event_attendees table)
    // First check if the table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'event_attendees'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_attendees WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $stats['events_attended'] = $stmt->fetchColumn();
    }
    
    // Get count of unique users who have created events (as a proxy for neighbours connected)
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM events WHERE user_id != ? AND status = 'active'");
    $stmt->execute([$user['id']]);
    $stats['neighbours_connected'] = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    // If there's an error, keep default values
    error_log("Dashboard stats error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Neighbourhood Connect</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 1000;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5em;
            font-weight: 700;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2em;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .main-layout {
            display: flex;
            min-height: calc(100vh - 80px);
        }

        .sidebar {
            width: 320px;
            background: white;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            transition: width 0.3s ease, transform 0.3s ease;
            position: fixed;
            height: calc(100vh - 80px);
            overflow-y: auto;
            z-index: 999;
        }

        .sidebar.minimized {
            width: 60px;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }

        .sidebar-title {
            font-size: 1.2em;
            font-weight: 600;
            white-space: nowrap;
            opacity: 1;
            transition: opacity 0.3s ease;
        }

        .sidebar.minimized .sidebar-title {
            opacity: 0;
            pointer-events: none;
        }

        .sidebar-toggle {
            background: none;
            border: none;
            color: white;
            font-size: 1.5em;
            cursor: pointer;
            padding: 5px;
            border-radius: 5px;
            transition: background 0.3s ease;
        }

        .sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .sidebar-content {
            padding: 20px;
        }

        .sidebar.minimized .sidebar-content {
            padding: 20px 10px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #333;
        }

        .nav-item:hover {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            transform: translateX(5px);
        }

        .nav-icon {
            font-size: 1.5em;
            margin-right: 15px;
            min-width: 30px;
            text-align: center;
        }

        .nav-text {
            flex: 1;
            opacity: 1;
            transition: opacity 0.3s ease;
        }

        .sidebar.minimized .nav-text {
            opacity: 0;
            pointer-events: none;
        }

        .nav-description {
            font-size: 0.85em;
            opacity: 0.8;
            margin-top: 5px;
            line-height: 1.3;
        }

        .sidebar.minimized .nav-description {
            display: none;
        }

        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 40px 20px;
            transition: margin-left 0.3s ease;
        }

        .sidebar.minimized + .main-content {
            margin-left: 60px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-section {
            background: white;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .welcome-section h1 {
            color: #333;
            font-size: 2.5em;
            margin-bottom: 15px;
        }

        .welcome-section p {
            color: #666;
            font-size: 1.1em;
            line-height: 1.6;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 3em;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: 700;
            color: #4facfe;
            margin-bottom: 10px;
        }

        .stat-label {
            color: #666;
            font-size: 1.1em;
        }

        .user-profile {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .user-profile h2 {
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .profile-field {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .profile-field label {
            display: block;
            color: #666;
            font-size: 0.9em;
            margin-bottom: 5px;
        }

        .profile-field span {
            color: #333;
            font-weight: 500;
        }

        .recent-activity {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .recent-activity h2 {
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .activity-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .activity-icon {
            font-size: 1.5em;
        }

        .activity-text {
            flex: 1;
        }

        .activity-text h4 {
            color: #333;
            margin-bottom: 5px;
        }

        .activity-text p {
            color: #666;
            font-size: 0.9em;
        }

        .no-activity {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 20px;
        }

        /* Tooltip for minimized sidebar */
        .nav-item {
            position: relative;
        }

        .nav-item::before {
            content: attr(data-tooltip);
            position: absolute;
            left: 70px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85em;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 1000;
        }

        .sidebar.minimized .nav-item:hover::before {
            opacity: 1;
        }

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .sidebar.minimized + .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: block;
                position: fixed;
                top: 20px;
                left: 20px;
                z-index: 1001;
                background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
                color: white;
                border: none;
                padding: 10px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 1.5em;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            }
        }

        @media (min-width: 1025px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 15px;
            }

            .user-info {
                gap: 15px;
            }

            .welcome-section {
                padding: 20px;
            }

            .welcome-section h1 {
                font-size: 2em;
            }

            .stat-card {
                padding: 20px;
            }

            .main-content {
                padding: 20px 10px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo">🏘️ Neighbourhood Connect</div>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                </div>
                <span>Welcome, <?php echo htmlspecialchars($user['full_name']); ?></span>
                <button class="logout-btn" onclick="logout()">Logout</button>
            </div>
        </div>
    </div>

    <button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>

    <div class="main-layout">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-title">Quick Actions</div>
                <button class="sidebar-toggle" onclick="toggleSidebar()">
                    <span id="toggle-icon">←</span>
                </button>
            </div>
            <div class="sidebar-content">
                <div class="nav-item" onclick="window.location.href='create_events.php'" data-tooltip="Create Events">
                    <div class="nav-icon">📅</div>
                    <div class="nav-text">
                        <div>Create Events</div>
                        <div class="nav-description">Organize neighbourhood gatherings and community meetings</div>
                    </div>
                </div>
                <div class="nav-item" onclick="window.location.href='browse_events.php'" data-tooltip="Browse Events">
                    <div class="nav-icon">🔍</div>
                    <div class="nav-text">
                        <div>Browse Events</div>
                        <div class="nav-description">Discover what's happening in your neighbourhood</div>
                    </div>
                </div>
                <div class="nav-item" onclick="window.location.href='community_chat.php'" data-tooltip="Community Chat">
                    <div class="nav-icon">💬</div>
                    <div class="nav-text">
                        <div>Community Chat</div>
                        <div class="nav-description">Connect with neighbours and share updates</div>
                    </div>
                </div>
                <div class="nav-item" onclick="window.location.href='neighbourhood_map.php'" data-tooltip="Neighbourhood Map">
                    <div class="nav-icon">🗺️</div>
                    <div class="nav-text">
                        <div>Neighbourhood Map</div>
                        <div class="nav-description">View events and activities on an interactive map</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="container">
                <div class="welcome-section">
                    <h1>Welcome to Your Neighbourhood Dashboard</h1>
                    <p>Connect with your community, share events, and build stronger relationships with your neighbours. Your local network starts here!</p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">🏠</div>
                        <div class="stat-number" data-count="<?php echo $stats['events_created']; ?>">0</div>
                        <div class="stat-label">Events Created</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-number" data-count="<?php echo $stats['neighbours_connected']; ?>">0</div>
                        <div class="stat-label">Neighbours Connected</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📅</div>
                        <div class="stat-number" data-count="<?php echo $stats['events_attended']; ?>">0</div>
                        <div class="stat-label">Events Attended</div>
                    </div>
                </div>

                <?php if ($stats['events_created'] > 0): ?>
                <div class="recent-activity">
                    <h2>📊 Recent Activity</h2>
                    <?php
                    try {
                        // Get recent events created by the user
                        $stmt = $pdo->prepare("
                            SELECT title, event_date, location, created_at 
                            FROM events 
                            WHERE user_id = ? AND status = 'active' 
                            ORDER BY created_at DESC 
                            LIMIT 5
                        ");
                        $stmt->execute([$user['id']]);
                        $recent_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if ($recent_events):
                            foreach ($recent_events as $event):
                    ?>
                            <div class="activity-item">
                                <div class="activity-icon">📅</div>
                                <div class="activity-text">
                                    <h4><?php echo htmlspecialchars($event['title']); ?></h4>
                                    <p>Event at <?php echo htmlspecialchars($event['location']); ?> on <?php echo date('M j, Y g:i A', strtotime($event['event_date'])); ?></p>
                                </div>
                            </div>
                    <?php
                            endforeach;
                        else:
                    ?>
                            <div class="no-activity">No recent activity</div>
                    <?php
                        endif;
                    } catch (PDOException $e) {
                        echo '<div class="no-activity">Unable to load recent activity</div>';
                    }
                    ?>
                </div>
                <?php endif; ?>
            
                <div class="user-profile">
                    <h2>👤 Your Profile</h2>
                    <div class="profile-info">
                        <div class="profile-field">
                            <label>Full Name</label>
                            <span><?php echo htmlspecialchars($user['full_name']); ?></span>
                        </div>
                        <div class="profile-field">
                            <label>Username</label>
                            <span><?php echo htmlspecialchars($user['username']); ?></span>
                        </div>
                        <div class="profile-field">
                            <label>Email</label>
                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                        </div>
                        <div class="profile-field">
                            <label>Member Since</label>
                            <span><?php echo date('F j, Y'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let sidebarMinimized = false;

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const toggleIcon = document.getElementById('toggle-icon');
            
            if (window.innerWidth <= 1024) {
                // Mobile behavior
                sidebar.classList.toggle('open');
            } else {
                // Desktop behavior
                sidebar.classList.toggle('minimized');
                sidebarMinimized = !sidebarMinimized;
                toggleIcon.textContent = sidebarMinimized ? '→' : '←';
            }
        }

        async function logout() {
            if (confirm('Are you sure you want to logout?')) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'logout');
                    
                    const response = await fetch('auth_handler.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        window.location.href = data.redirect;
                    } else {
                        alert('Logout failed. Please try again.');
                    }
                } catch (error) {
                    alert('Network error. Please try again.');
                }
            }
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024) {
                const sidebar = document.getElementById('sidebar');
                const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
                
                if (!sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 1024) {
                sidebar.classList.remove('open');
            }
        });

        // Add some interactive animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stat numbers
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const finalValue = parseInt(stat.getAttribute('data-count'));
                let currentValue = 0;
                
                if (finalValue === 0) {
                    stat.textContent = '0';
                    return;
                }
                
                const increment = Math.ceil(finalValue / 20);
                
                const updateCounter = () => {
                    if (currentValue < finalValue) {
                        currentValue += increment;
                        if (currentValue > finalValue) {
                            currentValue = finalValue;
                        }
                        stat.textContent = currentValue;
                        setTimeout(updateCounter, 50);
                    } else {
                        stat.textContent = finalValue;
                    }
                };
                
                // Start animation after a delay
                setTimeout(updateCounter, 500);
            });
        });
    </script>
</body>
</html>