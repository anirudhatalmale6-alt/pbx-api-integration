<?php
/**
 * PBX API Integration - Last Calls IVR
 *
 * Plays all calls continuously. User can press 4=skip next, 6=previous, *=exit during playback.
 */

header('Content-Type: application/json; charset=utf-8');

$call_id    = $_GET['PBXcallId'] ?? '';
$phone      = $_GET['PBXphone'] ?? '';
$call_status = $_GET['PBXcallStatus'] ?? '';

// --- Handle hangup ---
if ($call_status === 'HANGUP') {
    if ($call_id && file_exists("$call_id.call")) unlink("$call_id.call");
    if ($call_id && file_exists("$call_id.pos")) unlink("$call_id.pos");
    exit;
}

// --- Step 1: Language selection ---
if (!isset($_GET['lang'])) {
    $apikey = 'SDd4567$ghjgfSA678@dfhhyASDS';
    $api_url = "https://cellstation.co.il/meser/last_calls.php?apikey=$apikey&cid=$phone";
    $response = file_get_contents($api_url);
    file_put_contents("$call_id.call", $response);

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

$lang = $_GET['lang'] ?? '1';
if ($lang === '*') {
    if ($call_id && file_exists("$call_id.call")) unlink("$call_id.call");
    echo json_encode(["type" => "goTo", "goTo" => ".."], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Load data ---
$last_calls = [];
if (file_exists("$call_id.call")) {
    $last_calls = json_decode(file_get_contents("$call_id.call"), true);
}
if (!is_array($last_calls)) $last_calls = [];
$total = count($last_calls);

if ($total < 1) {
    $msg = ($lang === '2') ? "You have no missed calls" : "אין שיחות שלא נענו";
    $result = [
        ["type" => "audioPlayer", "name" => "noData", "files" => [["text" => $msg]]],
        ["type" => "goTo", "goTo" => ".."]
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Handle navigation (when user presses key during playback) ---
$nav = $_GET['nav'] ?? '';
if ($nav === '*') {
    if ($call_id && file_exists("$call_id.call")) unlink("$call_id.call");
    if ($call_id && file_exists("$call_id.pos")) unlink("$call_id.pos");
    echo json_encode(["type" => "goTo", "goTo" => ".."], JSON_UNESCAPED_UNICODE);
    exit;
}

// Position tracking
$pos_file = "$call_id.pos";
$hold = 0;
if (file_exists($pos_file)) {
    $hold = (int)file_get_contents($pos_file);
}

if ($nav === '6' && $hold > 0) {
    $hold--;
} elseif ($nav === '4' && $hold < $total - 1) {
    $hold++;
}

file_put_contents($pos_file, $hold);

// --- Helper functions ---
function numberToWords($num) {
    $digits = ['zero','one','two','three','four','five','six','seven','eight','nine'];
    $str = (string)$num;
    $words = [];
    for ($i = 0; $i < strlen($str); $i++) {
        if (is_numeric($str[$i])) $words[] = $digits[(int)$str[$i]];
    }
    return implode(',', $words);
}

function buildCallFiles($data, $lang) {
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

    $files = [];
    if ($lang === '2') {
        $files[] = ["text" => "From number"];
        $files[] = ["text" => numberToWords($data['src'])];
        $files[] = ["text" => "Duration " . numberToWords($minutes) . " minutes and " . numberToWords($seconds) . " seconds"];
        $files[] = ["text" => "On $day $month at $hour $minute"];
    } else {
        $files[] = ["text" => "ממספר"];
        $files[] = ["digits" => $data['src']];
        $files[] = ["text" => "משך שיחה"];
        $files[] = ["digits" => "$minutes"];
        $files[] = ["text" => "דקות ו"];
        $files[] = ["digits" => "$seconds"];
        $files[] = ["text" => "שניות"];
        $files[] = ["text" => "בתאריך $day ל $month בשעה $hour $minute"];
    }
    return $files;
}

// --- Build continuous stream from current position to end ---
$play_files = [];

// Show count only on first play (position 0, no nav yet)
if ($hold === 0 && !isset($_GET['nav'])) {
    if ($lang === '2') {
        $play_files[] = ["text" => "You have " . numberToWords($total) . " calls"];
    } else {
        $play_files[] = ["text" => "יש לך"];
        $play_files[] = ["digits" => "$total"];
        $play_files[] = ["text" => "שיחות"];
    }
}

// Add all calls from current position to end
for ($i = $hold; $i < $total; $i++) {
    $play_files = array_merge($play_files, buildCallFiles($last_calls[$i], $lang));
}

// --- Play with navigation keys enabled ---
$result = [
    "type" => "simpleMenu",
    "name" => "nav",
    "times" => 1,
    "timeout" => 1,
    "enabledKeys" => "4,6,*",
    "setMusic" => "no",
    "extensionChange" => ".",
    "files" => $play_files
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
