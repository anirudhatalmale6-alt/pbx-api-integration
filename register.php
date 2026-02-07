<?php
/**
 * PBX API - Agent Registration System
 *
 * Flow:
 * 1. "Enter your agent number" (4 digits)
 * 2. "Say your first name" (record)
 * 3. "Say your last name" (record)
 * 4. "Enter your phone number" (9-10 digits)
 * 5. Save all data to TXT file
 */

header('Content-Type: application/json; charset=utf-8');

// --- Get PBX parameters ---
$call_id     = $_GET['PBXcallId'] ?? '';
$phone       = $_GET['PBXphone'] ?? '';
$call_status = $_GET['PBXcallStatus'] ?? '';

// File to save registrations
$save_file = '/var/www/html/NE.txt';

// --- Handle hangup ---
if ($call_status === 'HANGUP') {
    exit;
}

// --- Step 1: Get agent number (4 digits) ---
if (!isset($_GET['agent_num'])) {
    $result = [
        "type" => "getDTMF",
        "name" => "agent_num",
        "min" => 4,
        "max" => 4,
        "timeout" => 10,
        "confirmType" => "digits",
        "setMusic" => "yes",
        "files" => [
            ["text" => "הקישו את מספר הנציג שלכם"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 2: Record first name ---
if (!isset($_GET['first_name'])) {
    $result = [
        "type" => "record",
        "name" => "first_name",
        "min" => 1,
        "max" => 10,
        "confirm" => "confirmOnly",
        "fileName" => "first_name_" . $_GET['agent_num'] . "_" . $call_id,
        "files" => [
            ["text" => "אמרו את השם הפרטי שלכם"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 3: Record last name ---
if (!isset($_GET['last_name'])) {
    $result = [
        "type" => "record",
        "name" => "last_name",
        "min" => 1,
        "max" => 10,
        "confirm" => "confirmOnly",
        "fileName" => "last_name_" . $_GET['agent_num'] . "_" . $call_id,
        "files" => [
            ["text" => "אמרו את שם המשפחה שלכם"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 4: Get phone number (9-10 digits) ---
if (!isset($_GET['phone_num'])) {
    $result = [
        "type" => "getDTMF",
        "name" => "phone_num",
        "min" => 9,
        "max" => 10,
        "timeout" => 15,
        "confirmType" => "digits",
        "setMusic" => "yes",
        "files" => [
            ["text" => "הקישו את מספר הטלפון שלכם"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 5: All data collected - save to file ---
$agent_num  = $_GET['agent_num'];
$first_name = $_GET['first_name'];  // This will be the recording confirmation status
$last_name  = $_GET['last_name'];   // This will be the recording confirmation status
$phone_num  = $_GET['phone_num'];
$caller_phone = $phone;

// Build record line
$timestamp = date('Y-m-d H:i:s');
$record_line = "$timestamp | Agent: $agent_num | Phone: $phone_num | Caller: $caller_phone | FirstName: first_name_{$agent_num}_{$call_id} | LastName: last_name_{$agent_num}_{$call_id}\n";

// Append to file
file_put_contents($save_file, $record_line, FILE_APPEND | LOCK_EX);

// --- Play success message and end ---
$result = [
    [
        "type" => "audioPlayer",
        "name" => "success",
        "files" => [
            ["text" => "תודה רבה. הנתונים נשמרו בהצלחה. נציג יחזור אליכם בהקדם"]
        ]
    ],
    [
        "type" => "goTo",
        "goTo" => ".."
    ]
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
