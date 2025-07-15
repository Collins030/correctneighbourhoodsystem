<?php
require 'config.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Make sure you have PHPMailer installed via Composer

// Connect to DB
$pdo = new PDO("mysql:host=localhost;dbname=neighbourhood_system", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Function to send email using SMTP
function sendSMTPEmail($to, $toName, $subject, $body) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to, $toName);
        
        // Content
        $mail->isHTML(false); // Set to true if you want HTML email
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: {$mail->ErrorInfo}");
        return false;
    }
}

// Get latest event
$eventStmt = $pdo->query("SELECT * FROM events ORDER BY created_at DESC LIMIT 1");
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("<h2>No event found</h2>");
}

$eventId = $event['id'];
$eventTitle = htmlspecialchars($event['title']);
$eventDate = date("F j, Y g:i A", strtotime($event['event_date']));
$eventLocation = htmlspecialchars($event['location']);

// Check if notifications already sent for this event
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE type = 'event' AND reference_id = ?");
$checkStmt->execute([$eventId]);
$notificationCount = $checkStmt->fetchColumn();

$newNotificationsSent = false;
$sendResults = [];

// If no notifications sent yet, send them now
if ($notificationCount == 0) {
    // Get all active users who should receive notifications
    $usersStmt = $pdo->query("SELECT id, full_name, email FROM users WHERE is_active = 1 AND is_verified = 1");
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($users) > 0) {
        foreach ($users as $user) {
            try {
                // Insert notification record into database
                $insertStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, reference_id, title, message, created_at) 
                    VALUES (?, 'event', ?, ?, ?, NOW())
                ");
                $title = "New Event: " . $eventTitle;
                $message = "New event: " . $eventTitle . " on " . $eventDate . " at " . $eventLocation;
                $insertStmt->execute([$user['id'], $eventId, $title, $message]);
                
                // Send actual email using SMTP
                $subject = "🎉 New Neighbourhood Event: " . $eventTitle;
                $body = "Hello " . $user['full_name'] . ",\n\n";
                $body .= "You have a new event notification from your neighbourhood system:\n\n";
                $body .= "📅 Event: " . $eventTitle . "\n";
                $body .= "🕐 Date & Time: " . $eventDate . "\n";
                $body .= "📍 Location: " . $eventLocation . "\n\n";
                $body .= "We look forward to seeing you there!\n\n";
                $body .= "Best regards,\nNeighbourhood System";
                
                $emailSent = sendSMTPEmail($user['email'], $user['full_name'], $subject, $body);
                
                $sendResults[] = [
                    'name' => $user['full_name'],
                    'email' => $user['email'],
                    'status' => $emailSent ? 'success' : 'failed',
                    'time' => date("g:i A, F j")
                ];
                
            } catch (Exception $e) {
                $sendResults[] = [
                    'name' => $user['full_name'],
                    'email' => $user['email'],
                    'status' => 'error',
                    'time' => date("g:i A, F j"),
                    'error' => $e->getMessage()
                ];
            }
        }
        $newNotificationsSent = true;
    }
}

// Get all users who received this notification (including newly sent ones)
$notifStmt = $pdo->prepare("
    SELECT users.full_name, users.email, notifications.created_at
    FROM notifications
    JOIN users ON notifications.user_id = users.id
    WHERE notifications.type = 'event' AND notifications.reference_id = ?
    ORDER BY notifications.created_at DESC
");
$notifStmt->execute([$eventId]);
$recipients = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>📢 Event Notification System</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            padding: 30px; 
            background: #f4f4f4; 
        }
        .card { 
            background: #fff; 
            padding: 20px; 
            border-radius: 10px; 
            max-width: 800px; 
            margin: auto; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        h2 { 
            margin-top: 0; 
            color: #333;
        }
        h3 {
            color: #555;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        ul { 
            padding-left: 20px; 
        }
        li {
            margin: 8px 0;
            padding: 8px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        .success { 
            color: green; 
            font-weight: bold;
        }
        .fail { 
            color: red; 
            font-weight: bold;
        }
        .error {
            color: red;
            font-weight: bold;
        }
        .new-notification {
            background: #e7f5e7;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .event-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-error {
            background: #f8d7da;
            color: #721c24;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .smtp-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="card">
        <h2>📅 Event Notification System</h2>
        
        <div class="smtp-info">
            <h3>📧 SMTP Configuration</h3>
            <p><strong>Server:</strong> <?= SMTP_HOST ?>:<?= SMTP_PORT ?></p>
            <p><strong>From:</strong> <?= SMTP_FROM_NAME ?> &lt;<?= SMTP_FROM_EMAIL ?>&gt;</p>
            <p><strong>Status:</strong> Using secure SMTP connection</p>
        </div>
        
        <?php if ($newNotificationsSent): ?>
            <div class="new-notification">
                <h3>✅ New Notifications Sent Successfully!</h3>
                <p>Notifications have been sent to <?= count($sendResults) ?> users for the latest event using SMTP.</p>
            </div>
        <?php endif; ?>
        
        <div class="event-info">
            <h3>📌 Latest Event Details</h3>
            <p><strong>Title:</strong> <?= $eventTitle ?></p>
            <p><strong>Date & Time:</strong> <?= $eventDate ?></p>
            <p><strong>Location:</strong> <?= $eventLocation ?></p>
        </div>

        <?php if (!$newNotificationsSent && $notificationCount == 0): ?>
            <div class="warning">
                <h3>⚠️ No Verified Users Found</h3>
                <p>No notifications were sent because there are no active and verified users in the system. Users need to be both active (<code>is_active = 1</code>) and verified (<code>is_verified = 1</code>) to receive notifications.</p>
            </div>
        <?php endif; ?>

        <?php if ($newNotificationsSent && count($sendResults) > 0): ?>
            <h3>📤 Notification Send Results</h3>
            <ul>
                <?php foreach ($sendResults as $result): ?>
                    <li>
                        <?= htmlspecialchars($result['name']) ?> 
                        (<?= htmlspecialchars($result['email']) ?>)
                        <?php if ($result['status'] == 'success'): ?>
                            <span class="status-badge badge-success">✔ Sent at <?= $result['time'] ?></span>
                        <?php else: ?>
                            <span class="status-badge badge-error">✗ Failed at <?= $result['time'] ?></span>
                            <?php if (isset($result['error'])): ?>
                                <br><small style="color: red;">Error: <?= htmlspecialchars($result['error']) ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>📬 All Notification Recipients</h3>
        <?php if (count($recipients) > 0): ?>
            <ul>
                <?php foreach ($recipients as $r): ?>
                    <li>
                        <?= htmlspecialchars($r['full_name']) ?> 
                        (<?= htmlspecialchars($r['email']) ?>) 
                        <small class="success">✔ Sent at <?= date("g:i A, F j", strtotime($r['created_at'])) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><strong>Total Recipients:</strong> <?= count($recipients) ?></p>
        <?php else: ?>
            <p class="fail">No notifications have been sent for this event yet.</p>
        <?php endif; ?>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 14px;">
            <p><strong>How it works:</strong> This system automatically sends notifications via SMTP to all active and verified users when you visit this page, but only once per event. Users must have both <code>is_active = 1</code> and <code>is_verified = 1</code> to receive notifications.</p>
        </div>
    </div>
</body>
</html>