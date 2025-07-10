<?php
require 'config.php';

// Connect to DB
$pdo = new PDO("mysql:host=localhost;dbname=neighbourhood_system", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

// Get all users who received this notification
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
    <title>📢 Notification Summary</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 30px; background: #f4f4f4; }
        .card { background: #fff; padding: 20px; border-radius: 10px; max-width: 700px; margin: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; }
        ul { padding-left: 20px; }
        .success { color: green; }
        .fail { color: red; }
    </style>
</head>
<body>
    <div class="card">
        <h2>📅 Latest Event Notification</h2>
        <p><strong>Title:</strong> <?= $eventTitle ?></p>
        <p><strong>Date & Time:</strong> <?= $eventDate ?></p>
        <p><strong>Location:</strong> <?= $eventLocation ?></p>

        <h3>📬 Notification Sent To:</h3>
        <?php if (count($recipients) > 0): ?>
            <ul>
                <?php foreach ($recipients as $r): ?>
                    <li><?= htmlspecialchars($r['full_name']) ?> (<?= htmlspecialchars($r['email']) ?>) <small class="success">✔ Sent at <?= date("g:i A, F j", strtotime($r['created_at'])) ?></small></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="fail">No notifications have been sent for this event yet.</p>
        <?php endif; ?>
    </div>
</body>
</html>
