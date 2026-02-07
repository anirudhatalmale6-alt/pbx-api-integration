<?php
/**
 * PBX API - Agent Registration System
 *
 * Flow:
 * 1. "Enter your agent number" (4 digits)
 * 2. Check if agent already exists -> if yes, say "already registered" and exit
 * 3. "Say your first name" (STT - speech to text) - retry on error
 * 4. "Say your last name" (STT - speech to text) - retry on error
 * 5. "Enter your phone number" (9-10 digits)
 * 6. Save all data to TXT file with unique ID
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

// --- Function to check if agent number exists ---
function agentExists($agent_num, $file_path) {
    if (!file_exists($file_path)) {
        return false;
    }
    $content = file_get_contents($file_path);
    // Search for "מספר נציג:XXXX," pattern
    return strpos($content, "מספר נציג:$agent_num,") !== false;
}

// --- Function to check if STT failed ---
function sttFailed($value) {
    // STT returns error text if failed
    $errors = ['error', 'שגיאה', 'fail', 'timeout', 'no_speech', 'לא זוהה'];
    $value_lower = mb_strtolower($value);
    foreach ($errors as $err) {
        if (strpos($value_lower, $err) !== false) {
            return true;
        }
    }
    // Also check if empty or very short
    if (empty($value) || mb_strlen($value) < 2) {
        return true;
    }
    return false;
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

// --- Step 1.5: Check if agent already exists ---
if (!isset($_GET['checked'])) {
    $agent_num = $_GET['agent_num'];

    // Check if agent exists in file
    if (agentExists($agent_num, $save_file)) {
        // Agent already registered - play message and go back (using simpleMenu for text)
        $result = [
            [
                "type" => "simpleMenu",
                "name" => "msg",
                "times" => 1,
                "timeout" => 1,
                "enabledKeys" => "",
                "files" => [
                    ["text" => "מספר הנציג כבר רשום במערכת"]
                ]
            ],
            [
                "type" => "goTo",
                "goTo" => ".."
            ]
        ];
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Agent not found - continue to registration with "please wait" message
    $result = [
        "type" => "simpleMenu",
        "name" => "checked",
        "times" => 1,
        "timeout" => 1,
        "enabledKeys" => "1,2,3,4,5,6,7,8,9,0",
        "setMusic" => "yes",
        "files" => [
            ["text" => "אנא המתן"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 2: Record first name with STT (Speech-to-Text) ---
if (!isset($_GET['first_name']) || sttFailed($_GET['first_name'])) {
    $result = [
        "type" => "stt",
        "name" => "first_name",
        "min" => 1,
        "max" => 10,
        "fileName" => "first_name_" . $_GET['agent_num'] . "_" . $call_id,
        "files" => [
            ["text" => "אמרו את השם הפרטי שלכם"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 3: Record last name with STT (Speech-to-Text) ---
if (!isset($_GET['last_name']) || sttFailed($_GET['last_name'])) {
    $result = [
        "type" => "stt",
        "name" => "last_name",
        "min" => 1,
        "max" => 10,
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
$agent_num  = $_GET['agent_num'] ?? '';
$first_name = $_GET['first_name'] ?? '';  // STT returns the transcribed text
$last_name  = $_GET['last_name'] ?? '';   // STT returns the transcribed text
$phone_num  = $_GET['phone_num'] ?? '';
$caller_phone = $phone;

// Generate unique ID (timestamp based)
$unique_id = date('YmdHis') . rand(100, 999);

// Build record in requested format
$record_line = "ID:$unique_id,";
$record_line .= "מספר נציג:$agent_num,";
$record_line .= "שם פרטי:$first_name,";
$record_line .= "שם משפחה:$last_name,";
$record_line .= "טלפון:$phone_num\n";

// Append to file
file_put_contents($save_file, $record_line, FILE_APPEND | LOCK_EX);

// --- Play success message and end (using simpleMenu for text) ---
$result = [
    [
        "type" => "simpleMenu",
        "name" => "done",
        "times" => 1,
        "timeout" => 1,
        "enabledKeys" => "",
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
