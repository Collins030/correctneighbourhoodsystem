<?php
require 'config.php'; // Contains SMTP config and DB connection
require 'vendor/autoload.php'; // For PHPMailer, adjust path as needed

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// DB connection
$pdo = new PDO("mysql:host=localhost;dbname=neighbourhood_system", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Sample: Fetch the latest event (replace this with your event creation logic)
$eventStmt = $pdo->query("SELECT * FROM events ORDER BY created_at DESC LIMIT 1");
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("❌ No event found.");
}

$eventTitle = $event['title'];
$eventDate = date("F j, Y g:i A", strtotime($event['event_date']));
$eventId = $event['id'];

// Fetch all verified users to notify
$userStmt = $pdo->query("SELECT id, full_name, email FROM users WHERE is_verified = 1 AND is_active = 1");
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $user) {
    $userId = $user['id'];
    $userEmail = $user['email'];
    $userName = $user['full_name'];

    // Insert into notifications table
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, ?, ?, ?, ?)");
    $notifStmt->execute([
        $userId,
        "New Event: $eventTitle",
        "You are invited to an upcoming event: \"$eventTitle\" on $eventDate.",
        "event",
        $eventId
    ]);

    // Send email
    $mail = new PHPMailer(true);
    try {
        // SMTP config
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        // Sender
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($userEmail, $userName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = "📅 New Event: $eventTitle";
        $mail->Body = "
            <h2>🏘️ Neighbourhood Connect - New Event Alert</h2>
            <p>Hello {$userName},</p>
            <p>You're invited to a new event: <strong>{$eventTitle}</strong></p>
            <p>Date & Time: <strong>{$eventDate}</strong></p>
            <p>Location: " . htmlspecialchars($event['location']) . "</p>
            <br><p><a href='https://yourdomain.com/event_details.php?id={$eventId}'>View Event Details</a></p>
            <p>Regards,<br>The Neighbourhood Connect Team</p>
        ";
        $mail->AltBody = "Hello {$userName},\nYou're invited to a new event: {$eventTitle} on {$eventDate}.";

        $mail->send();
        echo "✅ Notification sent to {$userEmail}\n";
    } catch (Exception $e) {
        echo "❌ Email to {$userEmail} failed: {$mail->ErrorInfo}\n";
    }
}
?>
