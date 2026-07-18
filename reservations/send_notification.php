<?php
/**
 * Updated Notification System - Sends Reservation ID and Status to n8n for Emailing
 */
function notifyCustomer($reservation_id, $status) {
    // 1. YOUR N8N WEBHOOK URL (Ensure this matches your new n8n Webhook node)
    $webhook_url = "https://reuseable-paulita-reductional.ngrok-free.dev/webhook/notification";

    // 2. Prepare the simple payload for n8n
    // We only send the ID and status; n8n will fetch the email from your DB
    $payload = [
        "reservation_id" => (int)$reservation_id,
        "status" => $status,
        "type" => "email_notification"
    ];

    // 3. Send to n8n via cURL
    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    
    if ($response === false) {
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Log errors to your local debug file
        file_put_contents(__DIR__ . '/notification_debug.log', 
            "[" . date('Y-m-d H:i:s') . "] cURL Error: $curl_error\n", FILE_APPEND);
        return false;
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 4. Debug Logging
    file_put_contents(__DIR__ . '/notification_debug.log', 
        "[" . date('Y-m-d H:i:s') . "] ID: $reservation_id | Status: $status | Code: $httpCode\n", FILE_APPEND);

    return ($httpCode === 200);
}
?>