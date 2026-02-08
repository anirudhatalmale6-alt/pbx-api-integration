<?php
/**
 * Admin Panel - Agent Registration Management
 * View, Edit, Delete agents and play their recordings
 */

// PBX API Configuration
$pbx_api_url = 'https://app.ipsales.co.il/ivrFilesApi.php';
$pbx_api_key = '061775724af4e4';
$extension_id = '7928';

// Data file
$data_file = '/var/www/html/NE.txt';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_GET['action']) {
        case 'get_data':
            echo json_encode(getData($data_file));
            exit;

        case 'delete':
            $id = $_GET['id'] ?? '';
            echo json_encode(deleteRecord($data_file, $id));
            exit;

        case 'delete_multiple':
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            echo json_encode(deleteMultipleRecords($data_file, $ids));
            exit;

        case 'update':
            $id = $_POST['id'] ?? '';
            $agent_num = $_POST['agent_num'] ?? '';
            $first_name = $_POST['first_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            echo json_encode(updateRecord($data_file, $id, $agent_num, $first_name, $last_name, $phone));
            exit;

        case 'get_audio':
            $filename = $_GET['filename'] ?? '';
            getAudioFile($pbx_api_url, $pbx_api_key, $filename);
            exit;

        case 'export_excel':
            exportToExcel($data_file);
            exit;
    }
}

// Functions
function getData($file) {
    if (!file_exists($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $data = [];
    foreach ($lines as $line) {
        $record = parseRecord($line);
        if ($record) {
            $data[] = $record;
        }
    }
    return $data;
}

function parseRecord($line) {
    // Format: ID:ID001,מספר נציג:1234,שם פרטי:משה,שם משפחה:כהן,טלפון:0501234567
    $parts = explode(',', $line);
    $record = [];
    foreach ($parts as $part) {
        $kv = explode(':', $part, 2);
        if (count($kv) == 2) {
            $key = trim($kv[0]);
            $value = trim($kv[1]);
            switch ($key) {
                case 'ID': $record['id'] = $value; break;
                case 'מספר נציג': $record['agent_num'] = $value; break;
                case 'שם פרטי': $record['first_name'] = $value; break;
                case 'שם משפחה': $record['last_name'] = $value; break;
                case 'טלפון': $record['phone'] = $value; break;
            }
        }
    }
    return !empty($record['id']) ? $record : null;
}

function deleteRecord($file, $id) {
    if (!file_exists($file)) {
        return ['success' => false, 'message' => 'File not found'];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $new_lines = [];
    $found = false;
    foreach ($lines as $line) {
        if (strpos($line, "ID:$id,") === false) {
            $new_lines[] = $line;
        } else {
            $found = true;
        }
    }
    if ($found) {
        file_put_contents($file, implode("\n", $new_lines) . "\n");
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Record not found'];
}

function deleteMultipleRecords($file, $ids) {
    if (!file_exists($file)) {
        return ['success' => false, 'message' => 'File not found'];
    }
    if (empty($ids)) {
        return ['success' => false, 'message' => 'No IDs provided'];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $new_lines = [];
    $deleted_count = 0;
    foreach ($lines as $line) {
        $should_delete = false;
        foreach ($ids as $id) {
            if (strpos($line, "ID:$id,") !== false) {
                $should_delete = true;
                $deleted_count++;
                break;
            }
        }
        if (!$should_delete) {
            $new_lines[] = $line;
        }
    }
    file_put_contents($file, implode("\n", $new_lines) . "\n");
    return ['success' => true, 'deleted' => $deleted_count];
}

function updateRecord($file, $id, $agent_num, $first_name, $last_name, $phone) {
    if (!file_exists($file)) {
        return ['success' => false, 'message' => 'File not found'];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $new_lines = [];
    $found = false;
    foreach ($lines as $line) {
        if (strpos($line, "ID:$id,") !== false) {
            $new_line = "ID:$id,מספר נציג:$agent_num,שם פרטי:$first_name,שם משפחה:$last_name,טלפון:$phone";
            $new_lines[] = $new_line;
            $found = true;
        } else {
            $new_lines[] = $line;
        }
    }
    if ($found) {
        file_put_contents($file, implode("\n", $new_lines) . "\n");
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Record not found'];
}

function getAudioFile($api_url, $api_key, $filename) {
    $post_data = [
        'action' => 'fileDownload',
        'apiKey' => $api_key,
        'fileName' => $filename
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    // Check if it's audio
    if (strpos($content_type, 'audio') !== false || strpos($content_type, 'octet-stream') !== false) {
        header('Content-Type: audio/mpeg');
        header('Content-Disposition: inline; filename="' . $filename . '.mp3"');
        echo $response;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Could not fetch audio file', 'response' => $response]);
    }
}

function exportToExcel($file) {
    $data = getData($file);

    // Create Excel XML (works without external libraries)
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
    $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    $xml .= '<Worksheet ss:Name="נציגים">' . "\n";
    $xml .= '<Table>' . "\n";

    // Header row
    $xml .= '<Row>' . "\n";
    $xml .= '<Cell><Data ss:Type="String">ID</Data></Cell>' . "\n";
    $xml .= '<Cell><Data ss:Type="String">מספר נציג</Data></Cell>' . "\n";
    $xml .= '<Cell><Data ss:Type="String">שם פרטי</Data></Cell>' . "\n";
    $xml .= '<Cell><Data ss:Type="String">שם משפחה</Data></Cell>' . "\n";
    $xml .= '<Cell><Data ss:Type="String">טלפון</Data></Cell>' . "\n";
    $xml .= '</Row>' . "\n";

    // Data rows
    foreach ($data as $row) {
        $xml .= '<Row>' . "\n";
        $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['id'] ?? '') . '</Data></Cell>' . "\n";
        $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['agent_num'] ?? '') . '</Data></Cell>' . "\n";
        $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['first_name'] ?? '') . '</Data></Cell>' . "\n";
        $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['last_name'] ?? '') . '</Data></Cell>' . "\n";
        $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['phone'] ?? '') . '</Data></Cell>' . "\n";
        $xml .= '</Row>' . "\n";
    }

    $xml .= '</Table>' . "\n";
    $xml .= '</Worksheet>' . "\n";
    $xml .= '</Workbook>';

    // Send as download
    $filename = 'agents_' . date('Y-m-d_H-i-s') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // Add BOM for UTF-8
    echo "\xEF\xBB\xBF";
    echo $xml;
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ניהול נציגים</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            font-size: 2em;
        }
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .card-header {
            background: #4fb3d9;
            color: white;
            padding: 20px;
            font-size: 1.2em;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-actions {
            display: flex;
            gap: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 15px;
            text-align: right;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        tr:hover {
            background: #f5f5f5;
        }
        tr.selected {
            background: #e3f2fd;
        }
        .actions {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-edit {
            background: #4fb3d9;
            color: white;
        }
        .btn-edit:hover {
            background: #3a9fc5;
        }
        .btn-delete {
            background: #e74c3c;
            color: white;
        }
        .btn-delete:hover {
            background: #c0392b;
        }
        .btn-play {
            background: #27ae60;
            color: white;
        }
        .btn-play:hover {
            background: #1e8449;
        }
        .btn-save {
            background: #2ecc71;
            color: white;
        }
        .btn-cancel {
            background: #95a5a6;
            color: white;
        }
        .btn-delete-selected {
            background: #c0392b;
            color: white;
            display: none;
        }
        .btn-delete-selected.visible {
            display: inline-block;
        }
        .btn-export {
            background: #27ae60;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-export:hover {
            background: #1e8449;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
        }
        .modal-header {
            font-size: 1.3em;
            font-weight: bold;
            margin-bottom: 20px;
            color: #1e3a5f;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .audio-modal audio {
            width: 100%;
            margin: 20px 0;
        }
        .empty-state {
            text-align: center;
            padding: 50px;
            color: #666;
        }
        .toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .refresh-btn {
            background: #1e3a5f;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .audio-buttons {
            display: flex;
            gap: 10px;
            flex-direction: column;
        }
        .checkbox-cell {
            width: 40px;
            text-align: center;
        }
        .checkbox-cell input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .selected-count {
            color: white;
            background: #e74c3c;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: none;
        }
        .selected-count.visible {
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>ניהול נציגים</h1>

        <div class="toolbar">
            <button class="refresh-btn" onclick="loadData()">🔄 רענן נתונים</button>
            <button class="btn btn-export" onclick="exportExcel()">📊 ייצוא לאקסל</button>
            <button class="btn btn-delete-selected" id="deleteSelectedBtn" onclick="openDeleteMultiple()">🗑️ מחק נבחרים</button>
            <span class="selected-count" id="selectedCount">נבחרו: 0</span>
        </div>

        <div class="card">
            <div class="card-header">
                <span>רשימת נציגים רשומים</span>
            </div>
            <table id="agentsTable">
                <thead>
                    <tr>
                        <th class="checkbox-cell"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                        <th>ID</th>
                        <th>מספר נציג</th>
                        <th>שם פרטי</th>
                        <th>שם משפחה</th>
                        <th>טלפון</th>
                        <th>פעולות</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr>
                        <td colspan="7" class="empty-state">טוען נתונים...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">עריכת נציג</div>
            <input type="hidden" id="editId">
            <div class="form-group">
                <label>מספר נציג</label>
                <input type="text" id="editAgentNum">
            </div>
            <div class="form-group">
                <label>שם פרטי</label>
                <input type="text" id="editFirstName">
            </div>
            <div class="form-group">
                <label>שם משפחה</label>
                <input type="text" id="editLastName">
            </div>
            <div class="form-group">
                <label>טלפון</label>
                <input type="text" id="editPhone">
            </div>
            <div class="modal-actions">
                <button class="btn btn-cancel" onclick="closeModal('editModal')">ביטול</button>
                <button class="btn btn-save" onclick="saveEdit()">שמור</button>
            </div>
        </div>
    </div>

    <!-- Audio Modal -->
    <div class="modal" id="audioModal">
        <div class="modal-content audio-modal">
            <div class="modal-header">השמעת הקלטות - <span id="audioAgentName"></span></div>
            <div class="audio-buttons">
                <div>
                    <strong>שם פרטי:</strong>
                    <audio id="audioFirstName" controls></audio>
                </div>
                <div>
                    <strong>שם משפחה:</strong>
                    <audio id="audioLastName" controls></audio>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-cancel" onclick="closeModal('audioModal')">סגור</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">אישור מחיקה</div>
            <p>האם אתה בטוח שברצונך למחוק את הנציג <strong id="deleteAgentName"></strong>?</p>
            <input type="hidden" id="deleteId">
            <div class="modal-actions">
                <button class="btn btn-cancel" onclick="closeModal('deleteModal')">ביטול</button>
                <button class="btn btn-delete" onclick="confirmDelete()">מחק</button>
            </div>
        </div>
    </div>

    <!-- Delete Multiple Confirmation Modal -->
    <div class="modal" id="deleteMultipleModal">
        <div class="modal-content">
            <div class="modal-header">אישור מחיקה מרובה</div>
            <p>האם אתה בטוח שברצונך למחוק <strong id="deleteMultipleCount">0</strong> נציגים?</p>
            <div class="modal-actions">
                <button class="btn btn-cancel" onclick="closeModal('deleteMultipleModal')">ביטול</button>
                <button class="btn btn-delete" onclick="confirmDeleteMultiple()">מחק הכל</button>
            </div>
        </div>
    </div>

    <script>
        let selectedIds = [];

        function loadData() {
            fetch('?action=get_data')
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('tableBody');
                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">אין נציגים רשומים</td></tr>';
                        return;
                    }
                    tbody.innerHTML = data.map(row => `
                        <tr data-id="${row.id}">
                            <td class="checkbox-cell"><input type="checkbox" class="row-checkbox" value="${row.id}" onchange="updateSelection()"></td>
                            <td>${row.id || ''}</td>
                            <td>${row.agent_num || ''}</td>
                            <td>${row.first_name || ''}</td>
                            <td>${row.last_name || ''}</td>
                            <td>${row.phone || ''}</td>
                            <td class="actions">
                                <button class="btn btn-edit" onclick="openEdit('${row.id}', '${row.agent_num}', '${row.first_name}', '${row.last_name}', '${row.phone}')">✏️ עריכה</button>
                                <button class="btn btn-delete" onclick="openDelete('${row.id}', '${row.first_name} ${row.last_name}')">🗑️ מחיקה</button>
                                <button class="btn btn-play" onclick="openAudio('${row.id}', '${row.first_name} ${row.last_name}')">🔊 השמעה</button>
                            </td>
                        </tr>
                    `).join('');
                    selectedIds = [];
                    updateSelectionUI();
                });
        }

        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll').checked;
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = selectAll;
                const row = cb.closest('tr');
                if (selectAll) {
                    row.classList.add('selected');
                } else {
                    row.classList.remove('selected');
                }
            });
            updateSelection();
        }

        function updateSelection() {
            selectedIds = [];
            document.querySelectorAll('.row-checkbox:checked').forEach(cb => {
                selectedIds.push(cb.value);
                cb.closest('tr').classList.add('selected');
            });
            document.querySelectorAll('.row-checkbox:not(:checked)').forEach(cb => {
                cb.closest('tr').classList.remove('selected');
            });
            updateSelectionUI();
        }

        function updateSelectionUI() {
            const count = selectedIds.length;
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            const countSpan = document.getElementById('selectedCount');

            if (count > 0) {
                deleteBtn.classList.add('visible');
                countSpan.classList.add('visible');
                countSpan.textContent = 'נבחרו: ' + count;
            } else {
                deleteBtn.classList.remove('visible');
                countSpan.classList.remove('visible');
            }
        }

        function openDeleteMultiple() {
            if (selectedIds.length === 0) return;
            document.getElementById('deleteMultipleCount').textContent = selectedIds.length;
            document.getElementById('deleteMultipleModal').classList.add('active');
        }

        function confirmDeleteMultiple() {
            const formData = new FormData();
            formData.append('ids', JSON.stringify(selectedIds));

            fetch('?action=delete_multiple', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    closeModal('deleteMultipleModal');
                    document.getElementById('selectAll').checked = false;
                    loadData();
                } else {
                    alert('שגיאה במחיקה: ' + result.message);
                }
            });
        }

        function openEdit(id, agentNum, firstName, lastName, phone) {
            document.getElementById('editId').value = id;
            document.getElementById('editAgentNum').value = agentNum;
            document.getElementById('editFirstName').value = firstName;
            document.getElementById('editLastName').value = lastName;
            document.getElementById('editPhone').value = phone;
            document.getElementById('editModal').classList.add('active');
        }

        function saveEdit() {
            const formData = new FormData();
            formData.append('id', document.getElementById('editId').value);
            formData.append('agent_num', document.getElementById('editAgentNum').value);
            formData.append('first_name', document.getElementById('editFirstName').value);
            formData.append('last_name', document.getElementById('editLastName').value);
            formData.append('phone', document.getElementById('editPhone').value);

            fetch('?action=update', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    closeModal('editModal');
                    loadData();
                } else {
                    alert('שגיאה בעדכון: ' + result.message);
                }
            });
        }

        function openDelete(id, name) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteAgentName').textContent = name;
            document.getElementById('deleteModal').classList.add('active');
        }

        function confirmDelete() {
            const id = document.getElementById('deleteId').value;
            fetch('?action=delete&id=' + encodeURIComponent(id))
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        closeModal('deleteModal');
                        loadData();
                    } else {
                        alert('שגיאה במחיקה: ' + result.message);
                    }
                });
        }

        function openAudio(id, name) {
            document.getElementById('audioAgentName').textContent = name;
            document.getElementById('audioFirstName').src = '?action=get_audio&filename=' + id + '-firstname';
            document.getElementById('audioLastName').src = '?action=get_audio&filename=' + id + '-lastname';
            document.getElementById('audioModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            // Stop audio when closing
            if (modalId === 'audioModal') {
                document.getElementById('audioFirstName').pause();
                document.getElementById('audioLastName').pause();
            }
        }

        // Close modal on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        function exportExcel() {
            window.location.href = '?action=export_excel';
        }

        // Load data on page load
        loadData();
    </script>
</body>
</html>
