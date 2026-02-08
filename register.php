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
 *
 * Audio files from extension 7929:
 * 001 = הקישו מספר נציג
 * 002 = אנא המתן
 * 003 = מספר הנציג כבר רשום במערכת
 * 004 = אמרו את השם הפרטי שלכם
 * 005 = אמרו את שם המשפחה שלכם
 * 006 = הקישו את מספר הטלפון שלכם
 * 007 = תודה רבה, הנתונים נשמרו בהצלחה
 */

header('Content-Type: application/json; charset=utf-8');

// --- Get PBX parameters ---
$call_id     = $_GET['PBXcallId'] ?? '';
$phone       = $_GET['PBXphone'] ?? '';
$call_status = $_GET['PBXcallStatus'] ?? '';

// File to save registrations
$save_file = '/var/www/html/NE.txt';

// Folder to save recordings (PBX saveFolder ID)
$recordings_folder = 7928;  // Extension ID for recordings

// Audio files extension
$audio_extension = 7929;  // Extension ID for audio prompts

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

// --- Function to get next ID ---
// If all records are deleted, starts from ID001 again
function getNextId($file_path) {
    $current_id = 0;
    if (file_exists($file_path)) {
        $content = trim(file_get_contents($file_path));
        if (!empty($content)) {
            preg_match_all('/ID:ID(\d+),/', $content, $matches);
            if (!empty($matches[1])) {
                $current_id = max(array_map('intval', $matches[1]));
            }
        }
    }
    $next_id = $current_id + 1;
    return 'ID' . str_pad($next_id, 3, '0', STR_PAD_LEFT);
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
            ["fileName" => "001", "extensionId" => "7929"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 1.5: Check if agent already exists and generate ID ---
if (!isset($_GET['checked'])) {
    $agent_num = $_GET['agent_num'];

    // Check if agent exists in file
    if (agentExists($agent_num, $save_file)) {
        // Agent already registered - play message and go back
        $result = [
            [
                "type" => "simpleMenu",
                "name" => "msg",
                "times" => 1,
                "timeout" => 1,
                "enabledKeys" => "",
                "files" => [
                    ["fileName" => "003", "extensionId" => "7929"]
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
            ["fileName" => "002", "extensionId" => "7929"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// Use call_id for filenames (consistent across all steps of the same call)
// ID will only be generated once at save time (step 5)

// --- Step 2: Record first name with STT (Speech-to-Text) ---
if (!isset($_GET['first_name']) || sttFailed($_GET['first_name'])) {
    $result = [
        "type" => "stt",
        "name" => "first_name",
        "min" => 1,
        "max" => 10,
        "fileName" => $call_id . "-firstname",
        "saveFolder" => $recordings_folder,
        "files" => [
            ["fileName" => "004", "extensionId" => "7929"]
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
        "fileName" => $call_id . "-lastname",
        "saveFolder" => $recordings_folder,
        "files" => [
            ["fileName" => "005", "extensionId" => "7929"]
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
            ["fileName" => "006", "extensionId" => "7929"]
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

// Generate unique ID only once at save time
$unique_id = getNextId($save_file);

// Build file names for recordings (using call_id - same as used during recording)
$firstname_file = $call_id . "-firstname";
$lastname_file = $call_id . "-lastname";

// Build record in requested format (including file names and date)
$reg_date = date('Y-m-d H:i:s');
$record_line = "ID:$unique_id,";
$record_line .= "מספר נציג:$agent_num,";
$record_line .= "שם פרטי:$first_name,";
$record_line .= "שם משפחה:$last_name,";
$record_line .= "טלפון:$phone_num,";
$record_line .= "תאריך:$reg_date,";
$record_line .= "קובץ שם פרטי:$firstname_file,";
$record_line .= "קובץ שם משפחה:$lastname_file\n";

// Append to file
file_put_contents($save_file, $record_line, FILE_APPEND | LOCK_EX);

// --- Play success message and end ---
$result = [
    [
        "type" => "simpleMenu",
        "name" => "done",
        "times" => 1,
        "timeout" => 1,
        "enabledKeys" => "",
        "files" => [
            ["fileName" => "007", "extensionId" => "7929"]
        ]
    ],
    [
        "type" => "goTo",
        "goTo" => ".."
    ]
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
