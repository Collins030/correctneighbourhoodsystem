<?php
// get_attendees.php - Get attendees for a specific event

require_once 'config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
$user = verifyUserSession();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get event ID from POST data
$event_id = $_POST['event_id'] ?? '';

if (empty($event_id) || !is_numeric($event_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid event ID']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=neighbourhood_system", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // First, check if the event exists and get event details
    $eventStmt = $pdo->prepare("
        SELECT e.*, u.full_name as organizer_name 
        FROM events e 
        JOIN users u ON e.user_id = u.id 
        WHERE e.id = ?
    ");
    $eventStmt->execute([$event_id]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        echo json_encode(['success' => false, 'error' => 'Event not found']);
        exit;
    }
    
    // Get all attendees for the event
    $attendeesStmt = $pdo->prepare("
        SELECT 
            ea.id as attendance_id,
            ea.user_id,
            ea.status,
            ea.joined_at as created_at,
            u.full_name as name,
            u.email,
            u.phone,
            u.username
        FROM event_attendees ea
        JOIN users u ON ea.user_id = u.id
        WHERE ea.event_id = ?
        ORDER BY ea.joined_at ASC
    ");
    $attendeesStmt->execute([$event_id]);
    $attendees = $attendeesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $formattedAttendees = [];
    foreach ($attendees as $attendee) {
        $formattedAttendees[] = [
            'attendance_id' => $attendee['attendance_id'],
            'user_id' => $attendee['user_id'],
            'name' => $attendee['name'],
            'email' => $attendee['email'],
            'phone' => $attendee['phone'],
            'username' => $attendee['username'],
            'status' => $attendee['status'],
            'created_at' => $attendee['created_at'],
            'joined_date' => date('M j, Y', strtotime($attendee['created_at'])),
            'joined_time' => date('g:i A', strtotime($attendee['created_at']))
        ];
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'attendees' => $formattedAttendees,
        'event' => [
            'id' => $event['id'],
            'title' => $event['title'],
            'organizer' => $event['organizer_name'],
            'event_date' => $event['event_date'],
            'max_attendees' => $event['max_attendees'],
            'current_attendees' => count($attendees)
        ],
        'total_count' => count($attendees)
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in get_attendees.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    error_log("General error in get_attendees.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'An error occurred while fetching attendees'
    ]);
}
?>