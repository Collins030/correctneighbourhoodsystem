<?php
// browse_events.php - Event browsing feature with map integration

require_once 'config.php';

// Check if user is logged in
$user = verifyUserSession();
if (!$user) {
    header('Location: index.php');
    exit;
}

// Handle event actions (join/leave)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $event_id = $_POST['event_id'] ?? '';
    $action = $_POST['action'];
    
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=neighbourhood_system", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        if ($action === 'join') {
            // Check if user is already attending
            $stmt = $pdo->prepare("SELECT id FROM event_attendees WHERE event_id = ? AND user_id = ?");
            $stmt->execute([$event_id, $user['id']]);
            
            if (!$stmt->fetch()) {
                // Add user to event
                $stmt = $pdo->prepare("INSERT INTO event_attendees (event_id, user_id) VALUES (?, ?)");
                $stmt->execute([$event_id, $user['id']]);
                
                // Update event attendee count
                $stmt = $pdo->prepare("UPDATE events SET current_attendees = current_attendees + 1 WHERE id = ?");
                $stmt->execute([$event_id]);
                
                $success = "Successfully joined the event!";
            }
        } elseif ($action === 'leave') {
            // Remove user from event
            $stmt = $pdo->prepare("DELETE FROM event_attendees WHERE event_id = ? AND user_id = ?");
            $stmt->execute([$event_id, $user['id']]);
            
            // Update event attendee count
            $stmt = $pdo->prepare("UPDATE events SET current_attendees = current_attendees - 1 WHERE id = ?");
            $stmt->execute([$event_id]);
            
            $success = "Successfully left the event!";
        }
        
        // Return JSON response for AJAX requests
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $success ?? 'Action completed']);
            exit;
        }
        
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    }
}

// Initialize database and ensure required columns exist
try {
    $pdo = new PDO("mysql:host=localhost;dbname=neighbourhood_system", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Ensure required columns exist
    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'latitude'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE events ADD COLUMN latitude DECIMAL(10, 8) NULL");
        $pdo->exec("ALTER TABLE events ADD COLUMN longitude DECIMAL(11, 8) NULL");
    }
    
    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'status'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE events ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
        $pdo->exec("UPDATE events SET status = 'active' WHERE status IS NULL");
    }
    
    // Fetch events
    $stmt = $pdo->prepare("
        SELECT e.*, u.full_name as organizer_name,
               CASE WHEN ea.user_id IS NOT NULL THEN 1 ELSE 0 END as is_attending,
               COUNT(ea2.user_id) as attendee_count
        FROM events e
        JOIN users u ON e.user_id = u.id
        LEFT JOIN event_attendees ea ON e.id = ea.event_id AND ea.user_id = ?
        LEFT JOIN event_attendees ea2 ON e.id = ea2.event_id
        WHERE e.status = 'active' AND e.event_date >= NOW()
        GROUP BY e.id, u.full_name, ea.user_id
        ORDER BY e.event_date ASC
    ");
    $stmt->execute([$user['id']]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Separate events with and without location data
    $eventsWithLocation = array_filter($events, function($event) {
        return !empty($event['latitude']) && !empty($event['longitude']);
    });
    
} catch (PDOException $e) {
    $error = "Error fetching events: " . $e->getMessage();
    $events = [];
    $eventsWithLocation = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Events - Neighbourhood Connect</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
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

        .nav-links {
            display: flex;
            gap: 20px;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 20px;
            transition: background 0.3s ease;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .page-header {
            background: white;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .page-header h1 {
            color: #333;
            font-size: 2.5em;
            margin-bottom: 15px;
        }

        .page-header p {
            color: #666;
            font-size: 1.1em;
        }

        .view-toggle {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 30px;
        }

        .view-btn {
            padding: 12px 24px;
            border: 2px solid #4facfe;
            background: white;
            color: #4facfe;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .view-btn.active {
            background: #4facfe;
            color: white;
        }

        .view-btn:hover {
            background: #4facfe;
            color: white;
        }

        .content-container {
            position: relative;
            min-height: 500px;
        }

        .list-view {
            display: block;
        }

        .map-view {
            display: none;
        }

        .map-view.active {
            display: block;
        }

        .list-view.active {
            display: block;
        }

        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .event-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            position: relative;
        }

        .event-card:hover {
            transform: translateY(-5px);
        }

        .event-card.highlighted {
            border: 3px solid #4facfe;
            box-shadow: 0 8px 25px rgba(79, 172, 254, 0.3);
        }

        .event-card h3 {
            color: #333;
            font-size: 1.5em;
            margin-bottom: 15px;
        }

        .event-meta {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }

        .event-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
        }

        .event-meta-item .icon {
            font-size: 1.2em;
        }

        .event-description {
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .event-actions {
            display: flex;
            gap: 10px;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.875rem;
        }

        .attendee-count {
            color: #666;
            font-size: 0.9em;
        }

        .organizer-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .organizer-info small {
            color: #666;
        }

        .location-info {
            background: #e3f2fd;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .location-info:hover {
            background: #bbdefb;
        }

        .location-info.clickable {
            border: 1px solid #4facfe;
        }

        .no-events {
            text-align: center;
            color: #666;
            font-size: 1.2em;
            margin-top: 40px;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .create-event-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border: none;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .create-event-btn:hover {
            transform: scale(1.1);
        }

        /* Map View Styles */
        .map-container {
            position: relative;
            height: 600px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        #map {
            height: 100%;
            width: 100%;
        }

        .map-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .control-panel {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            min-width: 200px;
        }

        .control-panel h4 {
            margin-bottom: 10px;
            color: #333;
            font-size: 1em;
        }

        .control-btn {
            width: 100%;
            padding: 8px 12px;
            margin-bottom: 5px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .control-btn:hover {
            background: #f8f9fa;
            border-color: #4facfe;
        }

        .legend {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }

        .legend-marker {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            margin-right: 8px;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }

        .upcoming-marker {
            background: #28a745;
        }

        .my-event-marker {
            background: #ffc107;
        }

        .legend-text {
            font-size: 0.9em;
            color: #555;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .events-grid {
                grid-template-columns: 1fr;
            }
            
            .event-card {
                padding: 20px;
            }

            .view-toggle {
                flex-direction: column;
                align-items: center;
            }

            .map-controls {
                position: static;
                background: white;
                border-radius: 10px;
                padding: 15px;
                margin-bottom: 20px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            .control-panel {
                box-shadow: none;
                padding: 0;
            }

            .map-container {
                height: 400px;
            }
        }

        /* Loading spinner */
        .loading-spinner {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #4facfe;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Event card location status */
        .location-status {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .location-available {
            background: #d4edda;
            color: #155724;
        }

        .location-unavailable {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo">🏘️ Neighbourhood Connect</div>
            <div class="nav-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="create_events.php">Create Event</a>
                <a href="community_chat.php">Community Chat</a>
                <a href="neighbourhood_map.php">Map</a>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h1>🔍 Browse Events</h1>
            <p>Discover what's happening in your neighbourhood and join the fun!</p>
        </div>

        <div class="view-toggle">
            <button class="view-btn active" onclick="switchView('list')">📋 List View</button>
            <button class="view-btn" onclick="switchView('map')">🗺️ Map View</button>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="content-container">
            <!-- List View -->
            <div id="listView" class="list-view active">
                <?php if (empty($events)): ?>
                    <div class="no-events">
                        <p>No events available at the moment.</p>
                        <p>Why not <a href="create_events.php">create one</a> yourself?</p>
                    </div>
                <?php else: ?>
                    <div class="events-grid">
                        <?php foreach ($events as $event): ?>
                            <div class="event-card" id="event-card-<?php echo $event['id']; ?>">
                                <div class="location-status <?php echo (!empty($event['latitude']) && !empty($event['longitude'])) ? 'location-available' : 'location-unavailable'; ?>">
                                    <?php echo (!empty($event['latitude']) && !empty($event['longitude'])) ? '📍 Mapped' : '📍 No Map'; ?>
                                </div>
                                
                                <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                                
                                <div class="organizer-info">
                                    <small>Organized by: <strong><?php echo htmlspecialchars($event['organizer_name']); ?></strong></small>
                                </div>
                                
                                <div class="event-meta">
                                    <div class="event-meta-item">
                                        <span class="icon">📅</span>
                                        <span><?php echo date('F j, Y \a\t g:i A', strtotime($event['event_date'])); ?></span>
                                    </div>
                                    <div class="event-meta-item">
                                        <span class="icon">📍</span>
                                        <span><?php echo htmlspecialchars($event['location']); ?></span>
                                    </div>
                                    <div class="event-meta-item">
                                        <span class="icon">👥</span>
                                        <span class="attendee-count">
                                            <span id="attendee-count-<?php echo $event['id']; ?>">
                                                <?php echo $event['attendee_count']; ?>
                                            </span> attending
                                            <?php if ($event['max_attendees']): ?>
                                                / <?php echo $event['max_attendees']; ?> max
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($event['latitude']) && !empty($event['longitude'])): ?>
                                    <div class="location-info clickable" onclick="showOnMap(<?php echo $event['id']; ?>, <?php echo $event['latitude']; ?>, <?php echo $event['longitude']; ?>)">
                                        <strong>📍 Click to view on map</strong>
                                        <?php if ($event['address']): ?>
                                            <br><small><?php echo htmlspecialchars($event['address']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($event['description']): ?>
                                    <div class="event-description">
                                        <?php echo nl2br(htmlspecialchars($event['description'])); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="event-actions">
                                    <div class="attendee-count">
                                        <span id="attendee-display-<?php echo $event['id']; ?>">
                                            <?php echo $event['attendee_count']; ?>
                                        </span> attending
                                        <?php if ($event['max_attendees']): ?>
                                            / <?php echo $event['max_attendees']; ?> max
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <?php if (!empty($event['latitude']) && !empty($event['longitude'])): ?>
                                            <button class="btn btn-info btn-sm" onclick="showOnMap(<?php echo $event['id']; ?>, <?php echo $event['latitude']; ?>, <?php echo $event['longitude']; ?>)">
                                                🗺️ Map
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($event['user_id'] == $user['id']): ?>
                                            <button class="btn btn-secondary" disabled>Your Event</button>
                                        <?php elseif ($event['is_attending']): ?>
                                            <button class="btn btn-danger" id="action-btn-<?php echo $event['id']; ?>" 
                                                    onclick="toggleAttendance(<?php echo $event['id']; ?>, 'leave')">
                                                Leave Event
                                            </button>
                                        <?php else: ?>
                                            <?php if ($event['max_attendees'] && $event['attendee_count'] >= $event['max_attendees']): ?>
                                                <button class="btn btn-secondary" disabled>Event Full</button>
                                            <?php else: ?>
                                                <button class="btn btn-primary" id="action-btn-<?php echo $event['id']; ?>" 
                                                        onclick="toggleAttendance(<?php echo $event['id']; ?>, 'join')">
                                                    Join Event
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Map View -->
            <div id="mapView" class="map-view">
                <div class="map-controls">
                    <div class="control-panel">
                        <h4>🎯 Quick Actions</h4>
                        <button class="control-btn" onclick="centerOnUser()">📍 My Location</button>
                        <button class="control-btn" onclick="fitAllMarkers()">🔍 Show All Events</button>
                        <button class="control-btn" onclick="clearHighlight()">✨ Clear Highlight</button>
                    </div>
                    
                    <div class="legend">
                        <h4>📍 Legend</h4>
                        <div class="legend-item">
                            <div class="legend-marker upcoming-marker"></div>
                            <div class="legend-text">Upcoming Events</div>
                        </div>
                        <div class="legend-item">
                            <div class="legend-marker my-event-marker"></div>
                            <div class="legend-text">My Events</div>
                        </div>
                    </div>
                </div>
                
                <div class="map-container">
                    <div id="map"></div>
                </div>
            </div>
        </div>
    </div>

    <button class="create-event-btn" onclick="window.location.href='create_events.php'" title="Create Event">
        ➕
    </button>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
    <script>
        // Global variables
        let map;
        let markersGroup;
        let allMarkers = [];
        let currentView = 'list';
        let eventsData = <?php echo json_encode($events); ?>;
        let eventsWithLocation = <?php echo json_encode($eventsWithLocation); ?>;
        let currentUserId = <?php echo $user['id']; ?>;

        // Initialize map
        function initMap() {
            map = L.map('map').setView([-1.2921, 36.8219], 13);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            markersGroup = L.markerClusterGroup({
                maxClusterRadius: 50,
                disableClusteringAtZoom: 16
            });

            // Add markers for events with location
            eventsWithLocation.forEach(event => {
                const lat = parseFloat(event.latitude);
                const lng = parseFloat(event.longitude);
                
                if (isNaN(lat) || isNaN(lng)) return;

                // Determine marker color
                let markerColor = '#28a745'; // Green for upcoming
                if (event.user_id == currentUserId) {
                    markerColor = '#ffc107'; // Yellow for my events
                }

                const marker = L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: 'custom-div-icon',
                        html: `<div style="background-color: ${markerColor}; width: 25px; height: 25px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">📅</div>`,
                        iconSize: [25, 25],
                        iconAnchor: [12, 12]
                    })
                });

                marker.eventId = event.id;
                marker.bindPopup(createPopupContent(event));
                
                allMarkers.push(marker);
                markersGroup.addLayer(marker);
            });

            map.addLayer(markersGroup);
        }

        // Create popup content
        function createPopupContent(event) {
            const eventDate = new Date(event.event_date);
            const isMyEvent = event.user_id == currentUserId;
            const isAttending = event.is_attending == 1;

            return `
                <div style="max-width: 300px;">
                    <h3 style="margin: 0 0 10px 0; color: #333;">${event.title}</h3>
                    <p style="margin: 0 0 10px 0; color: #666;"><strong>By:</strong> ${event.organizer_name}</p>
                    <p style="margin: 0 0 10px 0; color: #666;"><strong>📅 Date:</strong> ${eventDate.toLocaleDateString()} at ${eventDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>
                    <p style="margin: 0 0 10px 0; color: #666;"><strong>📍 Location:</strong> ${event.location}</p>
                    <p style="margin: 0 0 15px 0; color: #666;"><strong>👥 Attending:</strong> ${event.attendee_count}</p>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="highlightEventCard(${event.id})" style="padding: 6px 12px; background: #17a2b8; color: white; border: none; border-radius: 4 px; cursor: pointer;">📋 View Details</button>
                        ${isMyEvent ? 
                            '<button style="padding: 6px 12px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: not-allowed;" disabled>Your Event</button>' :
                            (isAttending ? 
                                `<button onclick="toggleAttendanceFromMap(${event.id}, 'leave')" style="padding: 6px 12px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">Leave</button>` :
                                `<button onclick="toggleAttendanceFromMap(${event.id}, 'join')" style="padding: 6px 12px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">Join</button>`
                            )
                        }
                    </div>
                </div>
            `;
        }

        // Switch between views
        function switchView(view) {
            const listView = document.getElementById('listView');
            const mapView = document.getElementById('mapView');
            const viewBtns = document.querySelectorAll('.view-btn');

            viewBtns.forEach(btn => btn.classList.remove('active'));

            if (view === 'list') {
                listView.classList.add('active');
                mapView.classList.remove('active');
                currentView = 'list';
                event.target.classList.add('active');
            } else if (view === 'map') {
                listView.classList.remove('active');
                mapView.classList.add('active');
                currentView = 'map';
                event.target.classList.add('active');
                
                // Initialize map if not already done
                if (!map) {
                    setTimeout(initMap, 100);
                } else {
                    // Refresh map size
                    setTimeout(() => {
                        map.invalidateSize();
                    }, 100);
                }
            }
        }

        // Show event on map
        function showOnMap(eventId, lat, lng) {
            switchView('map');
            
            setTimeout(() => {
                if (map) {
                    map.setView([lat, lng], 16);
                    
                    // Find and open the marker popup
                    allMarkers.forEach(marker => {
                        if (marker.eventId === eventId) {
                            marker.openPopup();
                        }
                    });
                    
                    // Highlight the event card
                    highlightEventCard(eventId);
                }
            }, 200);
        }

        // Highlight event card
        function highlightEventCard(eventId) {
            // Clear previous highlights
            clearHighlight();
            
            // Highlight the specific card
            const card = document.getElementById(`event-card-${eventId}`);
            if (card) {
                card.classList.add('highlighted');
                
                // If in list view, scroll to the card
                if (currentView === 'list') {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }

        // Clear highlight
        function clearHighlight() {
            document.querySelectorAll('.event-card.highlighted').forEach(card => {
                card.classList.remove('highlighted');
            });
        }

        // Center map on user location
        function centerOnUser() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        map.setView([lat, lng], 15);
                        
                        // Add user location marker
                        L.marker([lat, lng], {
                            icon: L.divIcon({
                                className: 'user-location-icon',
                                html: '<div style="background-color: #007bff; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; color: white; font-size: 12px;">📍</div>',
                                iconSize: [20, 20],
                                iconAnchor: [10, 10]
                            })
                        }).addTo(map).bindPopup('Your Location').openPopup();
                    },
                    (error) => {
                        alert('Unable to get your location. Please enable location services.');
                    }
                );
            } else {
                alert('Geolocation is not supported by this browser.');
            }
        }

        // Fit all markers in view
        function fitAllMarkers() {
            if (allMarkers.length > 0) {
                const group = new L.featureGroup(allMarkers);
                map.fitBounds(group.getBounds().pad(0.1));
            }
        }

        // Toggle attendance
        function toggleAttendance(eventId, action) {
            const button = document.getElementById(`action-btn-${eventId}`);
            const originalText = button.textContent;
            
            button.disabled = true;
            button.textContent = action === 'join' ? 'Joining...' : 'Leaving...';
            
            fetch('browse_events.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `event_id=${eventId}&action=${action}&ajax=1`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update button state
                    if (action === 'join') {
                        button.textContent = 'Leave Event';
                        button.className = 'btn btn-danger';
                        button.onclick = () => toggleAttendance(eventId, 'leave');
                        
                        // Update attendee count
                        updateAttendeeCount(eventId, 1);
                    } else {
                        button.textContent = 'Join Event';
                        button.className = 'btn btn-primary';
                        button.onclick = () => toggleAttendance(eventId, 'join');
                        
                        // Update attendee count
                        updateAttendeeCount(eventId, -1);
                    }
                    
                    // Show success message
                    showNotification(data.message, 'success');
                } else {
                    showNotification(data.error || 'An error occurred', 'error');
                    button.textContent = originalText;
                }
                
                button.disabled = false;
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'error');
                button.textContent = originalText;
                button.disabled = false;
            });
        }

        // Toggle attendance from map popup
        function toggleAttendanceFromMap(eventId, action) {
            toggleAttendance(eventId, action);
            
            // Update popup content after a short delay
            setTimeout(() => {
                const event = eventsData.find(e => e.id == eventId);
                if (event) {
                    // Update the event data
                    event.is_attending = action === 'join' ? 1 : 0;
                    event.attendee_count = parseInt(event.attendee_count) + (action === 'join' ? 1 : -1);
                    
                    // Find and update the marker popup
                    allMarkers.forEach(marker => {
                        if (marker.eventId === eventId) {
                            marker.setPopupContent(createPopupContent(event));
                        }
                    });
                }
            }, 1000);
        }

        // Update attendee count display
        function updateAttendeeCount(eventId, change) {
            const countElement = document.getElementById(`attendee-count-${eventId}`);
            const displayElement = document.getElementById(`attendee-display-${eventId}`);
            
            if (countElement) {
                const currentCount = parseInt(countElement.textContent);
                const newCount = currentCount + change;
                countElement.textContent = newCount;
                
                if (displayElement) {
                    displayElement.textContent = newCount;
                }
            }
        }

        // Show notification
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type === 'success' ? 'success' : 'error'}`;
            notification.textContent = message;
            notification.style.position = 'fixed';
            notification.style.top = '20px';
            notification.style.right = '20px';
            notification.style.zIndex = '9999';
            notification.style.maxWidth = '300px';
            notification.style.boxShadow = '0 4px 12px rgba(0,0,0,0.2)';
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set up view toggle buttons
            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const view = this.textContent.includes('List') ? 'list' : 'map';
                    switchView(view);
                });
            });
            
            // Initialize map if starting in map view
            if (currentView === 'map') {
                setTimeout(initMap, 100);
            }
        });

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function(event) {
            if (event.state && event.state.view) {
                switchView(event.state.view);
            }
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.ctrlKey || event.metaKey) {
                switch(event.key) {
                    case '1':
                        event.preventDefault();
                        switchView('list');
                        break;
                    case '2':
                        event.preventDefault();
                        switchView('map');
                        break;
                    case 'n':
                        event.preventDefault();
                        window.location.href = 'create_events.php';
                        break;
                }
            }
        });
    </script>
</body>
</html>