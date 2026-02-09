<?php
/**
 * PBX API Integration - Last Calls IVR
 * Converted from old CellStation format to new JSON-based PBX API
 *
 * Flow:
 * 1. First call -> fetch last calls from CellStation API, save to file
 * 2. Play intro message with call count
 * 3. Navigate through calls with keys (4=next, 6=previous, *=exit)
 * 4. For each call: read source number, duration, date, time
 * 5. On hangup -> cleanup temp file
 */

header('Content-Type: application/json; charset=utf-8');

// --- Get PBX parameters ---
$call_id    = $_GET['PBXcallId'] ?? '';
$phone      = $_GET['PBXphone'] ?? '';
$call_status = $_GET['PBXcallStatus'] ?? '';
$extension_id = $_GET['PBXextensionId'] ?? '';

// --- Handle hangup ---
if ($call_status === 'HANGUP') {
    // Cleanup temp file
    if ($call_id && file_exists("$call_id.call")) {
        unlink("$call_id.call");
    }
    exit;
}

// --- Step 1: First call - fetch data from CellStation ---
if (!isset($_GET['getList'])) {

    $apikey = 'SDd4567$ghjgfSA678@dfhhyASDS';
    $api_url = "https://cellstation.co.il/meser/last_calls.php?apikey=$apikey&cid=$phone";

    // Fetch and save last calls data
    $response = file_get_contents($api_url);
    file_put_contents("$call_id.call", $response);

    // Play intro file and get menu selection (simpleMenu to set getList)
    $result = [
        "type" => "simpleMenu",
        "name" => "getList",
        "times" => 1,
        "timeout" => 1,
        "enabledKeys" => "1,2,3,4,5,6,7,8,9,0,*,#",
        "setMusic" => "yes",
        "files" => [
            [
                "fileId" => "SSAA",
                "extensionId" => ""
            ]
        ]
    ];

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 2: Handle exit (* key) ---
if (isset($_GET['getList']) && $_GET['getList'] === '*') {
    $result = [
        "type" => "goTo",
        "goTo" => ".."
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 3: Load saved data and navigate ---
$last_calls = [];
if (file_exists("$call_id.call")) {
    $last_calls = json_decode(file_get_contents("$call_id.call"), true);
}

if (!is_array($last_calls)) {
    $last_calls = [];
}

// Calculate current position based on navigation keys
// Key 4 = next, Key 6 = previous
$query = $_SERVER['QUERY_STRING'] ?? '';
$next_count = substr_count($query, 'nav=4');
$prev_count = substr_count($query, 'nav=6');
$hold = $next_count - $prev_count;

// --- Handle edge cases ---
if (count($last_calls) < 1) {
    // No calls - play "no calls" message and go back
    $result = [
        [
            "type" => "audioPlayer",
            "name" => "noData",
            "files" => [
                ["fileId" => "1", "extensionId" => ""]
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

if ($hold >= count($last_calls)) {
    // End of list - play "no more calls" message and go back
    $result = [
        [
            "type" => "audioPlayer",
            "name" => "endList",
            "files" => [
                ["fileId" => "2", "extensionId" => ""]
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

if ($hold < 0) {
    $result = [
        "type" => "goTo",
        "goTo" => ".."
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 4: Build call info playback ---
$data = $last_calls[$hold];
$sec = gmdate('i:s', $data['sec']);
$sec_arr = explode(':', $sec);

$date_time = explode(' ', $data['datetime']);
$date_arr = explode('-', $date_time[0]);
$time_arr = explode(':', $date_time[1]);

$minutes = $sec_arr[0] + 0;
$seconds = $sec_arr[1] + 0;
$day     = $date_arr[2] + 0;
$month   = $date_arr[1] + 0;
$hour    = $time_arr[0] + 0;
$minute  = $time_arr[1] + 0;

// Build files array for playback
$play_files = [];

// If first entry, play count intro
if (!isset($_GET['nav'])) {
    $count = count($last_calls);
    // "You have X calls" intro
    $play_files[] = ["fileId" => "3", "extensionId" => ""];       // "You have"
    $play_files[] = ["text" => "$count"];                           // number of calls
    $play_files[] = ["fileId" => "4", "extensionId" => ""];       // "calls"
}

// Call details:
$play_files[] = ["fileId" => "5", "extensionId" => ""];           // "From number"
$play_files[] = ["text" => $data['src']];                          // source phone number
$play_files[] = ["fileId" => "6", "extensionId" => ""];           // "Duration"
$play_files[] = ["text" => "$minutes"];                            // minutes
$play_files[] = ["fileId" => "7", "extensionId" => ""];           // "minutes and"
$play_files[] = ["text" => "$seconds"];                            // seconds
$play_files[] = ["fileId" => "8", "extensionId" => ""];           // "seconds"
$play_files[] = ["fileId" => "9", "extensionId" => ""];           // "Date"
$play_files[] = ["text" => "$day"];                                // day
$play_files[] = ["fileId" => "10", "extensionId" => ""];          // "month"
$play_files[] = ["text" => "$month"];                              // month number
$play_files[] = ["fileId" => "11", "extensionId" => ""];          // "Hour"
$play_files[] = ["text" => "$hour"];                               // hour
$play_files[] = ["fileId" => "12", "extensionId" => ""];          // "and"
$play_files[] = ["text" => "$minute"];                             // minute
$play_files[] = ["fileId" => "13", "extensionId" => ""];          // closing message

// --- Step 5: Play call info + navigation menu ---
// After playing, present menu: 4=next call, 6=previous call, *=exit
$result = [
    "type" => "simpleMenu",
    "name" => "nav",
    "times" => 3,
    "timeout" => 5,
    "enabledKeys" => "0,4,6,*",
    "setMusic" => "no",
    "extensionChange" => ".",
    "files" => $play_files
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
