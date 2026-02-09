<?php
/**
 * PBX API Integration - Last Calls IVR
 *
 * Flow:
 * 1. Intro: "שלום הגעתם לשירות מי התקשר אלי"
 * 2. Language selection: Hebrew=1, English=2
 * 3. Fetch last calls from CellStation API
 * 4. Play call details in selected language
 * 5. Navigate: 4=next, 6=previous, *=exit
 * 6. On hangup -> cleanup temp file
 */

header('Content-Type: application/json; charset=utf-8');

// --- Get PBX parameters ---
$call_id    = $_GET['PBXcallId'] ?? '';
$phone      = $_GET['PBXphone'] ?? '';
$call_status = $_GET['PBXcallStatus'] ?? '';
$extension_id = $_GET['PBXextensionId'] ?? '';

// --- Handle hangup ---
if ($call_status === 'HANGUP') {
    if ($call_id && file_exists("$call_id.call")) {
        unlink("$call_id.call");
    }
    exit;
}

// --- Step 1: Language selection ---
if (!isset($_GET['lang'])) {
    // Fetch data from CellStation and save
    $apikey = 'SDd4567$ghjgfSA678@dfhhyASDS';
    $api_url = "https://cellstation.co.il/meser/last_calls.php?apikey=$apikey&cid=$phone";
    $response = file_get_contents($api_url);
    file_put_contents("$call_id.call", $response);

    // Play intro + language menu
    $result = [
        "type" => "simpleMenu",
        "name" => "lang",
        "times" => 2,
        "timeout" => 5,
        "enabledKeys" => "1,2,*",
        "files" => [
            ["text" => "שלום, הגעתם לשירות מי התקשר אלי."],
            ["text" => "לעברית הקישו 1"],
            ["text" => "For English press 2"]
        ]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Handle exit (* key) ---
$lang = $_GET['lang'] ?? '1';
if ($lang === '*') {
    echo json_encode(["type" => "goTo", "goTo" => ".."], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Step 2: Load saved data and navigate ---
$last_calls = [];
if (file_exists("$call_id.call")) {
    $last_calls = json_decode(file_get_contents("$call_id.call"), true);
}
if (!is_array($last_calls)) {
    $last_calls = [];
}

// Calculate current position
// Count nav=4 (next) and nav=6 (prev)
// Timeout (nav= empty) counts as next (auto-advance)
$query = $_SERVER['QUERY_STRING'] ?? '';
$next_count = substr_count($query, 'nav=4');
$prev_count = substr_count($query, 'nav=6');
// Count total nav params minus next/prev/exit = timeout auto-advances
preg_match_all('/nav=/', $query, $all_nav);
$total_nav = count($all_nav[0]);
$star_count = substr_count($query, 'nav=*');
$auto_next = $total_nav - $next_count - $prev_count - $star_count;
$hold = ($next_count + $auto_next) - $prev_count;

// --- No calls ---
if (count($last_calls) < 1) {
    $msg = ($lang === '2') ? "You have no missed calls" : "אין שיחות שלא נענו";
    $result = [
        ["type" => "audioPlayer", "name" => "noData", "files" => [["text" => $msg]]],
        ["type" => "goTo", "goTo" => ".."]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- End of list ---
if ($hold >= count($last_calls)) {
    $msg = ($lang === '2') ? "No more calls" : "אין עוד שיחות";
    $result = [
        ["type" => "audioPlayer", "name" => "endList", "files" => [["text" => $msg]]],
        ["type" => "goTo", "goTo" => ".."]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($hold < 0) {
    echo json_encode(["type" => "goTo", "goTo" => ".."], JSON_UNESCAPED_UNICODE);
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

// --- Function to convert number to English digit words separated by commas ---
function numberToWords($num) {
    $digits = ['zero','one','two','three','four','five','six','seven','eight','nine'];
    $str = (string)$num;
    $words = [];
    for ($i = 0; $i < strlen($str); $i++) {
        $ch = $str[$i];
        if (is_numeric($ch)) {
            $words[] = $digits[(int)$ch];
        }
    }
    return implode(',', $words);
}

$play_files = [];

if ($lang === '2') {
    // --- ENGLISH: All text ---
    if (!isset($_GET['nav'])) {
        $count = count($last_calls);
        $play_files[] = ["text" => "You have " . numberToWords($count) . " calls"];
    }
    $play_files[] = ["text" => "From number"];
    $play_files[] = ["text" => numberToWords($data['src'])];
    $play_files[] = ["text" => "Duration " . numberToWords($minutes) . " minutes and " . numberToWords($seconds) . " seconds"];
    $play_files[] = ["text" => "On $day $month at $hour $minute"];
} else {
    // --- HEBREW: text for words, digits for phone, text for date/time ---
    if (!isset($_GET['nav'])) {
        $count = count($last_calls);
        $play_files[] = ["text" => "יש לך"];
        $play_files[] = ["digits" => "$count"];
        $play_files[] = ["text" => "שיחות"];
    }
    $play_files[] = ["text" => "ממספר"];
    $play_files[] = ["digits" => $data['src']];
    $play_files[] = ["text" => "משך שיחה"];
    $play_files[] = ["digits" => "$minutes"];
    $play_files[] = ["text" => "דקות ו"];
    $play_files[] = ["digits" => "$seconds"];
    $play_files[] = ["text" => "שניות"];
    $play_files[] = ["text" => "בתאריך $day ל $month בשעה $hour $minute"];
}

// --- Step 5: Navigation menu ---
$result = [
    "type" => "simpleMenu",
    "name" => "nav",
    "times" => 1,
    "timeout" => 1,
    "enabledKeys" => "0,4,6,*",
    "setMusic" => "no",
    "extensionChange" => ".",
    "files" => $play_files
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
