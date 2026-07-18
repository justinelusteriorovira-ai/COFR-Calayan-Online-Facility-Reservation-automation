# Implementation Plan: Replace n8n with OpenClaw + Telegram + Gemini (COFR)

## Overview

Replace the existing **n8n + Facebook Messenger** notification system with **OpenClaw + Telegram + Google Gemini (free)**. OpenClaw will run locally on Windows, receive events from the COFR PHP app, send Telegram notifications, and allow admins to query the reservation system by chatting with a Telegram bot.

---

## Current State (what we're replacing)

| File | Current Role | What Changes |
|---|---|---|
| `reservations/send_notification.php` | POSTs to n8n webhook URL | Replace with OpenClaw POST |
| `api/webhook_notify.php` | Receives from n8n, references `fb_user_id` | Rewrite for Telegram `chat_id` |

---

## Prerequisites

> [!IMPORTANT]
> Before starting, you need two free credentials:
> 1. **Gemini API Key** — Get free at [aistudio.google.com](https://aistudio.google.com) → "Get API Key" → "Create API Key"
> 2. **Telegram Bot Token** — Open Telegram → message `@BotFather` → `/newbot` → follow prompts → copy token like `7123456789:AAExxx...`
> 3. **Your Telegram Chat ID** — After creating bot, message it once, then visit `https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates` — find `"chat":{"id":XXXXXXXXX}` — that's your admin chat ID

---

## Proposed Changes

### Phase 1 — Install & Configure OpenClaw

#### No files to create — run these commands in PowerShell:

```powershell
# Install OpenClaw globally (Node.js already installed)
npm i -g openclaw

# Run the setup wizard
openclaw onboard
```

During `openclaw onboard`, choose:
- **AI Provider** → Google Gemini
- **API Key** → (paste your Gemini key)
- **Chat Platform** → Telegram
- **Bot Token** → (paste your BotFather token)

Then start it:
```powershell
openclaw start
# Runs at http://localhost:19001
```

---

### Phase 2 — New Files

---

#### [NEW] `config/openclaw.php`
Central config + helper for all OpenClaw calls. Replaces the old n8n webhook URL.

```php
<?php
/**
 * OpenClaw Configuration & Helper
 * Replaces n8n notification system.
 */

define('OPENCLAW_URL',    'http://localhost:19001/hooks/wake');
define('OPENCLAW_SECRET', 'your_secret_token_here'); // Set a secret in openclaw config

/**
 * Send an event to OpenClaw (fire-and-forget).
 * OpenClaw will notify admins via Telegram and/or take action.
 *
 * @param string $event   e.g. 'reservation_created', 'reservation_approved'
 * @param array  $data    Event payload
 */
function notifyOpenClaw(string $event, array $data): void {
    $payload = json_encode([
        'event' => $event,
        'data'  => $data,
        'ts'    => date('c'),
    ]);

    $ch = curl_init(OPENCLAW_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-OpenClaw-Secret: ' . OPENCLAW_SECRET,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,  // Non-blocking; don't slow down the page
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Log failures silently
    if ($response === false || $httpCode !== 200) {
        $logMsg = "[" . date('Y-m-d H:i:s') . "] OpenClaw notify FAILED"
                . " | event=$event | code=$httpCode | err=$curlErr\n";
        file_put_contents(__DIR__ . '/../reservations/notification_debug.log', $logMsg, FILE_APPEND);
    }
}
```

---

### Phase 3 — Modified Files

---

#### [MODIFY] `reservations/send_notification.php`
Currently points to n8n. Replace entirely to call OpenClaw instead.

```php
<?php
/**
 * Notification System — now uses OpenClaw + Telegram (replaces n8n).
 */
require_once __DIR__ . '/../config/openclaw.php';

/**
 * Notify admin via Telegram when a reservation status changes.
 * Called after DB updates in create.php, edit.php, cancel.php.
 *
 * @param int    $reservation_id
 * @param string $status  e.g. 'APPROVED', 'REJECTED', 'CANCELLED', 'PENDING'
 * @return bool
 */
function notifyCustomer(int $reservation_id, string $status): bool {
    global $conn;

    // Fetch reservation details for the message
    $stmt = $conn->prepare(
        "SELECT r.id, r.reservation_date, r.start_time,
                u.name AS user_name, u.email,
                f.name AS facility_name
         FROM reservations r
         LEFT JOIN users u ON r.user_id = u.id
         LEFT JOIN facilities f ON r.facility_id = f.id
         WHERE r.id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $reservation_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) return false;

    notifyOpenClaw('reservation_status_changed', [
        'reservation_id' => $reservation_id,
        'status'         => $status,
        'user_name'      => $res['user_name'],
        'email'          => $res['email'],
        'facility'       => $res['facility_name'],
        'date'           => $res['reservation_date'],
        'time'           => $res['start_time'],
    ]);

    // Also log locally
    file_put_contents(
        __DIR__ . '/notification_debug.log',
        "[" . date('Y-m-d H:i:s') . "] Notified OpenClaw | ID: $reservation_id | Status: $status\n",
        FILE_APPEND
    );

    return true;
}
```

---

#### [MODIFY] `api/webhook_notify.php`
Currently references `fb_user_id` and n8n. Rewrite to accept Telegram `chat_id` and work with OpenClaw-style payloads.

```php
<?php
/**
 * API: Webhook Notify (Updated for OpenClaw + Telegram)
 *
 * Receives POST from OpenClaw or direct calls to confirm delivery.
 * Logs audit trail. No longer references Facebook Messenger.
 *
 * POST body (JSON):
 *   event          — "APPROVED" | "REJECTED" | "CANCELLED" | "PENDING"
 *   reservation_id — integer
 *   telegram_chat_id — string (admin's Telegram chat ID, optional)
 *   message        — string
 */
require_once("../config/db.php");
require_once("../config/api_auth.php");

requireAPIAuth();
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "POST method required."]);
    exit;
}

$data              = getRequestData();
$event             = strtoupper($data["event"] ?? '');
$reservation_id    = isset($data["reservation_id"]) ? intval($data["reservation_id"]) : 0;
$telegram_chat_id  = $data["telegram_chat_id"] ?? '';
$message           = $data["message"] ?? '';

$valid_events = ["APPROVED", "REJECTED", "CANCELLED", "PENDING", "EXPIRED"];
if (!in_array($event, $valid_events) || $reservation_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Provide valid 'event' and 'reservation_id'."]);
    exit;
}

// Verify reservation exists
$check = $conn->prepare("SELECT id, status FROM reservations WHERE id = ? LIMIT 1");
$check->bind_param("i", $reservation_id);
$check->execute();
$res = $check->get_result()->fetch_assoc();
$check->close();

if (!$res) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Reservation #$reservation_id not found."]);
    exit;
}

// Log to audit trail
require_once("../config/audit_helper.php");
$details = "Webhook received: event=$event, telegram_chat_id=$telegram_chat_id, msg=" . substr($message, 0, 100);
logActivity($conn, 'WEBHOOK', 'RESERVATION', $reservation_id, $details, null, [
    'event'            => $event,
    'telegram_chat_id' => $telegram_chat_id,
    'message'          => $message,
]);

echo json_encode([
    "success"        => true,
    "received_at"    => date("c"),
    "reservation_id" => $reservation_id,
    "event"          => $event,
    "note"           => "Webhook logged. OpenClaw will send Telegram notification.",
]);

$conn->close();
```

---

## Admin Telegram Commands (after setup)

Once running, message your Telegram bot directly:

| Message to Bot | What happens |
|---|---|
| `How many reservations today?` | OpenClaw queries COFR DB, replies with count |
| `List pending reservations` | Returns formatted pending list |
| `Any reservations tomorrow?` | Shows next-day bookings |
| `Cancel reservation #45` | Marks cancelled in DB + confirms |

---

## Verification Plan

### Automated
- Run `openclaw start` and confirm gateway is live at `http://localhost:19001`
- Create a test reservation in COFR → verify Telegram message arrives on your phone

### Manual
- Check `reservations/notification_debug.log` for any failures
- Approve/reject a reservation and verify the Telegram notification shows the correct details
- Send an admin query in Telegram and verify OpenClaw responds with live data

---

## Execution Checklist

- [ ] Get Gemini API key from [aistudio.google.com](https://aistudio.google.com)
- [ ] Get Telegram bot token from @BotFather
- [ ] Get your Telegram admin chat ID from `getUpdates`
- [ ] Run `npm i -g openclaw` in PowerShell
- [ ] Run `openclaw onboard` (select Gemini + Telegram)
- [ ] Run `openclaw start`
- [ ] Create `config/openclaw.php`
- [ ] Replace `reservations/send_notification.php`
- [ ] Update `api/webhook_notify.php`
- [ ] Test with a real reservation — confirm Telegram message received
