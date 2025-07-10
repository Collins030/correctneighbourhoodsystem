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
    <link rel="stylesheet" href="browse_events.css">
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
                                            </span> 
                                            <span class="rsvp-status" id="rsvp-status-<?php echo $event['id']; ?>">
                                                <?php echo $event['is_attending'] ? 'RSVP\'d' : 'attending'; ?>
                                            </span>
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
                                
                                <!-- RSVP Section -->
                                <div class="rsvp-section">
                                    <div class="rsvp-header">
                                        <h4>👥 RSVP Status</h4>
                                        <span class="rsvp-count">
                                            <span id="rsvp-count-<?php echo $event['id']; ?>">
                                                <?php echo $event['attendee_count']; ?>
                                            </span> people attending
                                        </span>
                                    </div>
                                    
                                    <div class="rsvp-attendees" id="rsvp-attendees-<?php echo $event['id']; ?>">
                                        <!-- Attendees will be loaded here -->
                                    </div>
                                    
                                    <div class="rsvp-actions">
                                        <button class="btn btn-sm btn-outline" onclick="toggleAttendeesList(<?php echo $event['id']; ?>)">
                                            <span id="toggle-text-<?php echo $event['id']; ?>">👁️ Show Attendees</span>
                                        </button>
                                        
                                        <?php if ($event['user_id'] == $user['id']): ?>
                                            <button class="btn btn-sm btn-info" onclick="manageRSVP(<?php echo $event['id']; ?>)">
                                                📋 Manage RSVPs
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
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
                                                    onclick="toggleRSVP(<?php echo $event['id']; ?>, 'leave')">
                                                ✖️ Cancel RSVP
                                            </button>
                                        <?php else: ?>
                                            <?php if ($event['max_attendees'] && $event['attendee_count'] >= $event['max_attendees']): ?>
                                                <button class="btn btn-secondary" disabled>Event Full</button>
                                            <?php else: ?>
                                                <button class="btn btn-primary" id="action-btn-<?php echo $event['id']; ?>" 
                                                        onclick="toggleRSVP(<?php echo $event['id']; ?>, 'join')">
                                                    ✅ RSVP
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
                        <div class="legend-item">
                            <div class="legend-marker rsvp-marker"></div>
                            <div class="legend-text">RSVP'd Events</div>
                        </div>
                    </div>
                </div>
                
                <div class="map-container">
                    <div id="map"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- RSVP Management Modal -->
    <div id="rsvpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📋 RSVP Management</h3>
                <button class="close-btn" onclick="closeRSVPModal()">×</button>
            </div>
            <div class="modal-body">
                <div id="rsvpModalContent">
                    <!-- Content will be loaded here -->
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
        let attendeesCache = {};

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

                // Determine marker color based on user's relationship with event
                let markerColor = '#28a745'; // Green for upcoming
                if (event.user_id == currentUserId) {
                    markerColor = '#ffc107'; // Yellow for my events
                } else if (event.is_attending) {
                    markerColor = '#17a2b8'; // Blue for RSVP'd events
                }

                const marker = L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: 'custom-div-icon',
                        html: `<div style="background-color: ${markerColor}; width: 25px; height: 25px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">${event.is_attending ? '✅' : '📅'}</div>`,
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
                    <p style="margin: 0 0 10px 0; color: #666;"><strong>👥 RSVP'd:</strong> ${event.attendee_count} people</p>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                        <button onclick="highlightEventCard(${event.id})" style="padding: 6px 12px; background: #17a2b8; color: white; border: none; border-radius: 4px; cursor: pointer;">📋 View Details</button>
                        <button onclick="showAttendeesFromMap(${event.id})" style="padding: 6px 12px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">👥 Attendees</button>
                        ${isMyEvent ? 
                            '<button style="padding: 6px 12px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: not-allowed;" disabled>Your Event</button>' :
                            (isAttending ? 
                                `<button onclick="toggleRSVPFromMap(${event.id}, 'leave')" style="padding: 6px 12px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">✖️ Cancel RSVP</button>` :
                                `<button onclick="toggleRSVPFromMap(${event.id}, 'join')" style="padding: 6px 12px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">✅ RSVP</button>`
                            )
                        }
                    </div>
                </div>
            `;
        }

        // Toggle RSVP functionality
        function toggleRSVP(eventId, action) {
            const button = document.getElementById(`action-btn-${eventId}`);
            const originalText = button.textContent;
            
            button.disabled = true;
            button.textContent = action === 'join' ? 'RSVPing...' : 'Cancelling...';
            
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
                        button.textContent = '✖️ Cancel RSVP';
                        button.className = 'btn btn-danger';
                        button.onclick = () => toggleRSVP(eventId, 'leave');
                        
                        // Update RSVP status
                        updateRSVPStatus(eventId, true);
                        updateAttendeeCount(eventId, 1);
                    } else {
                        button.textContent = '✅ RSVP';
                        button.className = 'btn btn-primary';
                        button.onclick = () => toggleRSVP(eventId, 'join');
                        
                        // Update RSVP status
                        updateRSVPStatus(eventId, false);
                        updateAttendeeCount(eventId, -1);
                    }
                    
                    // Clear attendees cache for this event
                    delete attendeesCache[eventId];
                    
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

        // Toggle RSVP from map popup
        function toggleRSVPFromMap(eventId, action) {
            toggleRSVP(eventId, action);
            
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

        // Update RSVP status display
        function updateRSVPStatus(eventId, isRSVPd) {
            const statusElement = document.getElementById(`rsvp-status-${eventId}`);
            if (statusElement) {
                statusElement.textContent = isRSVPd ? 'RSVP\'d' : 'attending';
            }
        }

        // Update attendee count display
        function updateAttendeeCount(eventId, change) {
            const countElement = document.getElementById(`attendee-count-${eventId}`);
            const displayElement = document.getElementById(`attendee-display-${eventId}`);
            const rsvpCountElement = document.getElementById(`rsvp-count-${eventId}`);
            
            if (countElement) {
                const currentCount = parseInt(countElement.textContent);
                const newCount = currentCount + change;
                countElement.textContent = newCount;
                
                if (displayElement) {
                    displayElement.textContent = newCount;
                }
                
                if (rsvpCountElement) {
                    rsvpCountElement.textContent = newCount;
                }
            }
        }

        // Toggle attendees list visibility
        function toggleAttendeesList(eventId) {
            const attendeesDiv = document.getElementById(`rsvp-attendees-${eventId}`);
            const toggleText = document.getElementById(`toggle-text-${eventId}`);
            
            if (attendeesDiv.style.display === 'none' || attendeesDiv.style.display === '') {
                loadAttendees(eventId);
                attendeesDiv.style.display = 'block';
                toggleText.textContent = '👁️ Hide Attendees';
            } else {
                attendeesDiv.style.display = 'none';
                toggleText.textContent = '👁️ Show Attendees';
            }
        }

        // Load attendees for an event
        function loadAttendees(eventId) {
            if (attendeesCache[eventId]) {
                displayAttendees(eventId, attendeesCache[eventId]);
                return;
            }
            
            const attendeesDiv = document.getElementById(`rsvp-attendees-${eventId}`);
            attendeesDiv.innerHTML = '<p>Loading attendees...</p>';
            
            fetch('get_attendees.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `event_id=${eventId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    attendeesCache[eventId] = data.attendees;
                    displayAttendees(eventId, data.attendees);
                } else {
                    attendeesDiv.innerHTML = '<p>Error loading attendees</p>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                attendeesDiv.innerHTML = '<p>Error loading attendees</p>';
            });
        }

        // Display attendees list
        function displayAttendees(eventId, attendees) {
            const attendeesDiv = document.getElementById(`rsvp-attendees-${eventId}`);
            
            if (attendees.length === 0) {
                attendeesDiv.innerHTML = '<p>No attendees yet</p>';
                return;
            }
            
            let html = '<div class="attendees-list">';
            attendees.forEach(attendee => {
                const isCurrentUser = attendee.user_id == currentUserId;
                html += `
                    <div class="attendee-item ${isCurrentUser ? 'current-user' : ''}">
                        <div class="attendee-avatar">
                            ${attendee.name.charAt(0).toUpperCase()}
                        </div>
                        <div class="attendee-info">
                            <div class="attendee-name">
                                ${attendee.name} ${isCurrentUser ? '(You)' : ''}
                            </div>
                            <div class="attendee-rsvp-date">
                                RSVP'd on ${new Date(attendee.created_at).toLocaleDateString()}
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            attendeesDiv.innerHTML = html;
        }

        // Show attendees from map
        function showAttendeesFromMap(eventId) {
            // Switch to list view and show attendees
            switchView('list');
            setTimeout(() => {
                const card = document.getElementById(`event-card-${eventId}`);
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Auto-expand attendees list
                    setTimeout(() => {
                        const attendeesDiv = document.getElementById(`rsvp-attendees-${eventId}`);
                        if (attendeesDiv.style.display === 'none' || attendeesDiv.style.display === '') {
                            toggleAttendeesList(eventId);
                        }
                    }, 500);
                }
            }, 200);
        }

        // Manage RSVP modal for event organizers
        function manageRSVP(eventId) {
            const modal = document.getElementById('rsvpModal');
            const modalContent = document.getElementById('rsvpModalContent');
            
            modalContent.innerHTML = '<p>Loading RSVP data...</p>';
            modal.style.display = 'block';
            
            fetch('manage_rsvp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `event_id=${eventId}&action=get_full_data`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayRSVPManagement(data.event, data.attendees);
                } else {
                    modalContent.innerHTML = '<p>Error loading RSVP data</p>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                modalContent.innerHTML = '<p>Error loading RSVP data</p>';
            });
        }

        // Display RSVP management interface
        function displayRSVPManagement(event, attendees) {
            const modalContent = document.getElementById('rsvpModalContent');
            
            let html = `
                <div class="rsvp-management">
                    <h4>${event.title}</h4>
                    <p><strong>Date:</strong> ${new Date(event.event_date).toLocaleDateString()}</p>
                    <p><strong>Total RSVPs:</strong> ${attendees.length}</p>
                    ${event.max_attendees ? `<p><strong>Capacity:</strong> ${attendees.length}/${event.max_attendees}</p>` : ''}
                    
                    <div class="rsvp-stats">
                        <div class="stat-item">
                            <div class="stat-number">${attendees.length}</div>
                            <div class="stat-label">Total Attendees</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${attendees.filter(a => new Date(a.created_at) >= new Date(Date.now() - 24*60*60*1000)).length}</div>
                            <div class="stat-label">New (24h)</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${event.max_attendees ? Math.max(0, event.max_attendees - attendees.length) : '∞'}</div>
                            <div class="stat-label">Spots Left</div>
                        </div>
                    </div>
                    
                    <div class="attendees-full-list">
                        <h5>All Attendees:</h5>
            `;
            
            if (attendees.length > 0) {
                attendees.forEach(attendee => {
                    html += `
                        <div class="attendee-item-full">
                            <div class="attendee-avatar">
                                ${attendee.name.charAt(0).toUpperCase()}
                            </div>
                            <div class="attendee-info">
                                <div class="attendee-name">${attendee.name}</div>
                                <div class="attendee-email">${attendee.email || 'No email'}</div>
                                <div class="attendee-rsvp-date">
                                    RSVP'd: ${new Date(attendee.created_at).toLocaleDateString()} at ${new Date(attendee.created_at).toLocaleTimeString()}
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                html += '<p>No attendees yet</p>';
            }
            
            html += `
                    </div>
                    
                    <div class="rsvp-actions">
                        <button class="btn btn-primary" onclick="exportAttendees(${event.id})">📧 Export List</button>
                        <button class="btn btn-secondary" onclick="closeRSVPModal()">Close</button>
                    </div>
                </div>
            `;
            
            modalContent.innerHTML = html;
        }

        // Export attendees list
        function exportAttendees(eventId) {
            const attendees = attendeesCache[eventId] || [];
            if (attendees.length === 0) {
                showNotification('No attendees to export', 'error');
                return;
            }
            
            let csvContent = "Name,Email,RSVP Date\n";
            attendees.forEach(attendee => {
                csvContent += `"${attendee.name}","${attendee.email || 'N/A'}","${new Date(attendee.created_at).toLocaleDateString()}"\n`;
            });
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `event_${eventId}_attendees.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
            
            showNotification('Attendees list exported successfully!', 'success');
        }

        // Close RSVP modal
        function closeRSVPModal() {
            const modal = document.getElementById('rsvpModal');
            modal.style.display = 'none';
        }

        // Show notification
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 5px;
                color: white;
                font-weight: bold;
                z-index: 10000;
                background-color: ${type === 'success' ? '#28a745' : '#dc3545'};
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                animation: slideIn 0.3s ease-out;
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // View switching functionality
        function switchView(view) {
            const listView = document.getElementById('listView');
            const mapView = document.getElementById('mapView');
            const listBtn = document.querySelector('.view-btn[onclick="switchView(\'list\')"]');
            const mapBtn = document.querySelector('.view-btn[onclick="switchView(\'map\')"]');
            
            currentView = view;
            
            if (view === 'list') {
                listView.classList.add('active');
                mapView.classList.remove('active');
                listBtn.classList.add('active');
                mapBtn.classList.remove('active');
            } else {
                listView.classList.remove('active');
                mapView.classList.add('active');
                listBtn.classList.remove('active');
                mapBtn.classList.add('active');
                
                // Initialize map if not already done
                if (!map) {
                    setTimeout(initMap, 100);
                } else {
                    // Resize map to ensure proper display
                    setTimeout(() => {
                        map.invalidateSize();
                    }, 100);
                }
            }
        }

        // Highlight event card from map
        function highlightEventCard(eventId) {
            // Switch to list view
            switchView('list');
            
            // Scroll to and highlight the event card
            setTimeout(() => {
                const card = document.getElementById(`event-card-${eventId}`);
                if (card) {
                    // Remove previous highlights
                    document.querySelectorAll('.event-card.highlighted').forEach(el => {
                        el.classList.remove('highlighted');
                    });
                    
                    // Add highlight to current card
                    card.classList.add('highlighted');
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    
                    // Remove highlight after 3 seconds
                    setTimeout(() => {
                        card.classList.remove('highlighted');
                    }, 3000);
                }
            }, 200);
        }

        // Show event on map
        function showOnMap(eventId, lat, lng) {
            // Switch to map view
            switchView('map');
            
            // Wait for map to be ready and center on the event
            setTimeout(() => {
                if (map) {
                    map.setView([lat, lng], 16);
                    
                    // Find and open popup for this event
                    allMarkers.forEach(marker => {
                        if (marker.eventId === eventId) {
                            marker.openPopup();
                        }
                    });
                }
            }, 200);
        }

        // Map control functions
        function centerOnUser() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    position => {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        map.setView([lat, lng], 15);
                        
                        // Add user location marker
                        const userMarker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                className: 'user-location-marker',
                                html: '<div style="background-color: #007bff; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>',
                                iconSize: [20, 20],
                                iconAnchor: [10, 10]
                            })
                        }).addTo(map);
                        
                        userMarker.bindPopup('📍 Your Location').openPopup();
                        
                        // Remove user marker after 5 seconds
                        setTimeout(() => {
                            map.removeLayer(userMarker);
                        }, 5000);
                    },
                    error => {
                        showNotification('Unable to get your location', 'error');
                    }
                );
            } else {
                showNotification('Geolocation is not supported by this browser', 'error');
            }
        }

        function fitAllMarkers() {
            if (allMarkers.length > 0) {
                const group = new L.featureGroup(allMarkers);
                map.fitBounds(group.getBounds().pad(0.1));
            } else {
                showNotification('No events with locations to display', 'error');
            }
        }

        function clearHighlight() {
            // Close all popups
            map.closePopup();
            
            // Remove highlights from cards
            document.querySelectorAll('.event-card.highlighted').forEach(el => {
                el.classList.remove('highlighted');
            });
            
            showNotification('Highlights cleared', 'success');
        }

        // Modal click outside to close
        window.onclick = function(event) {
            const modal = document.getElementById('rsvpModal');
            if (event.target === modal) {
                closeRSVPModal();
            }
        }

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Add CSS animations
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
                
                .event-card.highlighted {
                    border: 2px solid #007bff;
                    box-shadow: 0 0 15px rgba(0, 123, 255, 0.3);
                    transform: scale(1.02);
                    transition: all 0.3s ease;
                }
                
                .list-view, .map-view {
                    display: none;
                }
                
                .list-view.active, .map-view.active {
                    display: block;
                }
                
                .view-btn {
                    padding: 10px 20px;
                    margin: 0 5px;
                    border: 2px solid #007bff;
                    background: white;
                    color: #007bff;
                    border-radius: 5px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                }
                
                .view-btn.active {
                    background: #007bff;
                    color: white;
                }
                
                .view-btn:hover {
                    background: #007bff;
                    color: white;
                }
                
                .modal {
                    display: none;
                    position: fixed;
                    z-index: 1000;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0,0,0,0.5);
                }
                
                .modal-content {
                    background-color: #fefefe;
                    margin: 5% auto;
                    padding: 0;
                    border: none;
                    border-radius: 10px;
                    width: 90%;
                    max-width: 600px;
                    max-height: 80vh;
                    overflow-y: auto;
                }
                
                .modal-header {
                    padding: 20px;
                    border-bottom: 1px solid #eee;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                
                .modal-body {
                    padding: 20px;
                }
                
                .close-btn {
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: #999;
                }
                
                .close-btn:hover {
                    color: #333;
                }
            `;
            document.head.appendChild(style);
            
            // Initialize with list view
            switchView('list');
        });
    </script>