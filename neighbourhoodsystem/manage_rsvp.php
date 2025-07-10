<?php
// manage_rsvp.php - Manage RSVP for event organizers

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

// Get parameters from POST data
$event_id = $_POST['event_id'] ?? '';
$action = $_POST['action'] ?? '';

if (empty($event_id) || !is_numeric($event_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid event ID']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=neighbourhood_system", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // First, verify that the user is the organizer of this event
    $eventStmt = $pdo->prepare("
        SELECT e.*, u.full_name as organizer_name 
        FROM events e 
        JOIN users u ON e.user_id = u.id 
        WHERE e.id = ? AND e.user_id = ?
    ");
    $eventStmt->execute([$event_id, $user['id']]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        echo json_encode(['success' => false, 'error' => 'Event not found or you are not the organizer']);
        exit;
    }
    
    switch ($action) {
        case 'get_full_data':
            // Get comprehensive event and attendee data
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
            
            // Format attendees data
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
            
            // Get recent activity (last 7 days)
            $recentStmt = $pdo->prepare("
                SELECT COUNT(*) as recent_count 
                FROM event_attendees 
                WHERE event_id = ? AND joined_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $recentStmt->execute([$event_id]);
            $recentActivity = $recentStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'event' => [
                    'id' => $event['id'],
                    'title' => $event['title'],
                    'description' => $event['description'],
                    'event_date' => $event['event_date'],
                    'location' => $event['location'],
                    'address' => $event['address'],
                    'max_attendees' => $event['max_attendees'],
                    'current_attendees' => count($attendees),
                    'status' => $event['status'],
                    'organizer_name' => $event['organizer_name'],
                    'created_at' => $event['created_at']
                ],
                'attendees' => $formattedAttendees,
                'statistics' => [
                    'total_attendees' => count($attendees),
                    'recent_signups' => $recentActivity['recent_count'],
                    'capacity_percentage' => $event['max_attendees'] ? round((count($attendees) / $event['max_attendees']) * 100, 1) : 0,
                    'spots_remaining' => $event['max_attendees'] ? max(0, $event['max_attendees'] - count($attendees)) : 'unlimited'
                ]
            ]);
            break;
            
        case 'remove_attendee':
            $attendee_id = $_POST['attendee_id'] ?? '';
            
            if (empty($attendee_id) || !is_numeric($attendee_id)) {
                echo json_encode(['success' => false, 'error' => 'Invalid attendee ID']);
                exit;
            }
            
            // Remove the attendee
            $removeStmt = $pdo->prepare("
                DELETE FROM event_attendees 
                WHERE id = ? AND event_id = ?
            ");
            $removeStmt->execute([$attendee_id, $event_id]);
            
            if ($removeStmt->rowCount() > 0) {
                // Update event attendee count
                $updateStmt = $pdo->prepare("
                    UPDATE events 
                    SET current_attendees = current_attendees - 1 
                    WHERE id = ?
                ");
                $updateStmt->execute([$event_id]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Attendee removed successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Attendee not found or already removed'
                ]);
            }
            break;
            
        case 'update_attendee_status':
            $attendee_id = $_POST['attendee_id'] ?? '';
            $new_status = $_POST['status'] ?? '';
            
            if (empty($attendee_id) || !is_numeric($attendee_id)) {
                echo json_encode(['success' => false, 'error' => 'Invalid attendee ID']);
                exit;
            }
            
            if (!in_array($new_status, ['attending', 'maybe', 'not_attending'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid status']);
                exit;
            }
            
            // Update attendee status
            $updateStatusStmt = $pdo->prepare("
                UPDATE event_attendees 
                SET status = ? 
                WHERE id = ? AND event_id = ?
            ");
            $updateStatusStmt->execute([$new_status, $attendee_id, $event_id]);
            
            if ($updateStatusStmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Attendee status updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to update attendee status'
                ]);
            }
            break;
            
        case 'get_attendee_details':
            $attendee_user_id = $_POST['attendee_user_id'] ?? '';
            
            if (empty($attendee_user_id) || !is_numeric($attendee_user_id)) {
                echo json_encode(['success' => false, 'error' => 'Invalid attendee user ID']);
                exit;
            }
            
            // Get detailed attendee information
            $detailStmt = $pdo->prepare("
                SELECT 
                    ea.id as attendance_id,
                    ea.status,
                    ea.joined_at,
                    u.full_name,
                    u.email,
                    u.phone,
                    u.username,
                    u.address,
                    u.created_at as user_since
                FROM event_attendees ea
                JOIN users u ON ea.user_id = u.id
                WHERE ea.event_id = ? AND ea.user_id = ?
            ");
            $detailStmt->execute([$event_id, $attendee_user_id]);
            $attendeeDetails = $detailStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($attendeeDetails) {
                // Get other events this user has attended
                $otherEventsStmt = $pdo->prepare("
                    SELECT 
                        e.title,
                        e.event_date,
                        ea.status
                    FROM event_attendees ea
                    JOIN events e ON ea.event_id = e.id
                    WHERE ea.user_id = ? AND ea.event_id != ?
                    ORDER BY e.event_date DESC
                    LIMIT 5
                ");
                $otherEventsStmt->execute([$attendee_user_id, $event_id]);
                $otherEvents = $otherEventsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'attendee' => [
                        'attendance_id' => $attendeeDetails['attendance_id'],
                        'full_name' => $attendeeDetails['full_name'],
                        'email' => $attendeeDetails['email'],
                        'phone' => $attendeeDetails['phone'],
                        'username' => $attendeeDetails['username'],
                        'address' => $attendeeDetails['address'],
                        'status' => $attendeeDetails['status'],
                        'joined_at' => $attendeeDetails['joined_at'],
                        'user_since' => $attendeeDetails['user_since'],
                        'other_events' => $otherEvents
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Attendee details not found'
                ]);
            }
            break;
            
        case 'send_message_to_attendees':
            $message = $_POST['message'] ?? '';
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                exit;
            }
            
            // Get all attendees for the event
            $attendeesStmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.email 
                FROM event_attendees ea
                JOIN users u ON ea.user_id = u.id
                WHERE ea.event_id = ? AND ea.status = 'attending'
            ");
            $attendeesStmt->execute([$event_id]);
            $attendees = $attendeesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create notifications for all attendees
            $notificationStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, reference_id)
                VALUES (?, ?, ?, 'event', ?)
            ");
            
            $notificationTitle = "Message from " . $event['organizer_name'] . " - " . $event['title'];
            $sentCount = 0;
            
            foreach ($attendees as $attendee) {
                try {
                    $notificationStmt->execute([
                        $attendee['id'],
                        $notificationTitle,
                        $message,
                        $event_id
                    ]);
                    $sentCount++;
                } catch (Exception $e) {
                    error_log("Failed to send notification to user " . $attendee['id'] . ": " . $e->getMessage());
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => "Message sent to $sentCount attendees",
                'sent_count' => $sentCount,
                'total_attendees' => count($attendees)
            ]);
            break;
            
        case 'export_attendees':
            // Get all attendees for export
            $exportStmt = $pdo->prepare("
                SELECT 
                    u.full_name,
                    u.email,
                    u.phone,
                    u.username,
                    ea.status,
                    ea.joined_at
                FROM event_attendees ea
                JOIN users u ON ea.user_id = u.id
                WHERE ea.event_id = ?
                ORDER BY ea.joined_at ASC
            ");
            $exportStmt->execute([$event_id]);
            $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format data for export
            $formattedData = [];
            foreach ($exportData as $row) {
                $formattedData[] = [
                    'name' => $row['full_name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'] ?: 'N/A',
                    'username' => $row['username'],
                    'status' => ucfirst($row['status']),
                    'rsvp_date' => date('Y-m-d H:i:s', strtotime($row['joined_at']))
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $formattedData,
                'event_title' => $event['title'],
                'export_timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Database error in manage_rsvp.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    error_log("General error in manage_rsvp.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'An error occurred while processing your request'
    ]);
}
?>