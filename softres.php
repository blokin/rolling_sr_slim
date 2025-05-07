<?php
ob_start();
header_remove();

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

$csvDir = __DIR__ . "/exports";
if (!is_dir($csvDir)) {
    mkdir($csvDir, 0777, true);
} else {
    // Delete CSVs older than 10 minutes
    foreach (glob("$csvDir/*.csv") as $file) {
        if (filemtime($file) < time() - 600) {
            @unlink($file);
        }
    }
}

function loadCredentials() {
    $iniPath = '/var/www/secure/credentials.ini';
    if (!file_exists($iniPath)) {
        return ['CLIENT_ID' => '', 'CLIENT_SECRET' => '', 'DISCORD_WEBHOOK' => ''];
    }
    return parse_ini_file($iniPath);
}

function parseUploadedCSV($filePath) {
    $data = [];
    if (($handle = fopen($filePath, "r")) !== false) {
        $headers = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $data[] = array_combine($headers, $row);
        }
        fclose($handle);
    }
    return $data;
}

function writeCSV($filename, $rows) {
    $file = fopen($filename, 'w');
    if (!empty($rows)) {
        fputcsv($file, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($file, $row);
        }
    }
    fclose($file);
}

function getAccessToken($clientId, $clientSecret) {
    $ch = curl_init('https://oauth.battle.net/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "$clientId:$clientSecret",
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials'
    ]);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

function getItemName($itemId, $accessToken, &$cache) {
    if (isset($cache[$itemId])) return $cache[$itemId];
    $url = "https://us.api.blizzard.com/data/wow/item/$itemId?namespace=static-classic-us&locale=en_US";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
        CURLOPT_TIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    $name = $data['name'] ?? '[Unknown Item]';
    $cache[$itemId] = $name;
    return $name;
}

function sendToDiscord($webhookUrl, $filename) {
    if (!file_exists($filename)) return false;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $webhookUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($filename, 'text/csv', basename($filename)),
            'payload_json' => json_encode(['content' => '📄 New softres data has been posted.'])
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    return $response !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['VIEW_ONLY']) && isset($_FILES['CSV_UPLOAD']) && is_uploaded_file($_FILES['CSV_UPLOAD']['tmp_name'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $csvData = parseUploadedCSV($_FILES['CSV_UPLOAD']['tmp_name']);
    $diff = [];

    foreach ($csvData as $row) {
        $entry = [
            'Username' => $row['Username'] ?? '[Unknown]',
            'Status' => 'current'
        ];
        for ($i = 1; $i <= 10; $i++) {
            $entry["Item$i"] = $row["Item$i"] ?? '';
            $entry["OldBonus$i"] = $row["Bonus$i"] ?? '';
            $entry["NewBonus$i"] = $row["Bonus$i"] ?? '';
        }
        $diff[] = $entry;
    }
    echo json_encode(['success' => true, 'diff' => $diff]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['VIEW_ONLY'])) {
    while (ob_get_level()) ob_end_clean();  // clean all output buffers
    header('Content-Type: application/json');
    $url = trim($_POST['URL'] ?? '');
    $inc = intval($_POST['INCREMENT'] ?? 0);

    if (!$url || !$inc) {
        echo json_encode(['error' => 'Missing URL or increment value.']);
        exit;
    }

    $originalData = [];
    if (!empty($_FILES['CSV_UPLOAD']['tmp_name'])) {
        $originalData = parseUploadedCSV($_FILES['CSV_UPLOAD']['tmp_name']);
    }

    $previousBonuses = [];
    foreach ($originalData as $entry) {
        $username = strtolower(trim($entry['Username'] ?? ''));
        for ($i = 1; $i <= 10; $i++) {
            $item = trim($entry["Item$i"] ?? '');
            $bonus = (int)($entry["Bonus$i"] ?? 0);
            if ($item !== '') {
                $previousBonuses[$username][$item] = $bonus;
            }
        }
    }

    $credentials = loadCredentials();
    $accessToken = getAccessToken($credentials['CLIENT_ID'], $credentials['CLIENT_SECRET']);
    if (!$accessToken) {
        echo json_encode(['error' => 'Failed to retrieve Blizzard API token.']);
        exit;
    }

    $apiUrl = "https://softres.it/api/raid/" . urlencode($url);
    $response = @file_get_contents($apiUrl);
    $data = json_decode($response, true);

    if (!isset($data['reserved']) || !is_array($data['reserved'])) {
        echo json_encode(['error' => 'Invalid SoftRes data or raid not found.']);
        exit;
    }

    $cache = [];
    $resMap = [];
    $finalRows = [];
    $changes = [];

    foreach ($data['reserved'] as $entry) {
        $username = trim($entry['name'] ?? '[Unknown]');
        $key = strtolower($username);
        $items = $entry['items'] ?? [];

        $userRow = ['Username' => $username];
        $changeEntry = ['Username' => $username];
        $resMap[$key] = [];

        foreach ($items as $index => $itemId) {
            if ($index >= 10) break;
            $itemName = getItemName($itemId, $accessToken, $cache);
            $prevBonus = $previousBonuses[$key][$itemName] ?? 0;
            $newBonus = $prevBonus + $inc;

            $userRow["Item" . ($index + 1)] = $itemName;
            $userRow["Bonus" . ($index + 1)] = $newBonus;

            $changeEntry["Item" . ($index + 1)] = $itemName;
            $changeEntry["OldBonus" . ($index + 1)] = $prevBonus;
            $changeEntry["NewBonus" . ($index + 1)] = $newBonus;
            $resMap[$key][] = $itemName;
        }

        for ($j = count($items) + 1; $j <= 10; $j++) {
            $userRow["Item$j"] = '';
            $userRow["Bonus$j"] = '';
        }

        $changeEntry['Status'] = empty($previousBonuses[$key]) ? 'added' : 'updated';
        $finalRows[] = $userRow;
        $changes[] = $changeEntry;
    }

    foreach ($previousBonuses as $name => $items) {
        if (!isset($resMap[$name])) {
            $userRow = ['Username' => ucfirst($name)];
            $changeEntry = ['Username' => ucfirst($name)];
            $nonZero = false;

            $i = 1;
            foreach ($items as $item => $bonus) {
                $newBonus = max(0, $bonus - $inc);
                if ($newBonus > 0) $nonZero = true;
                $userRow["Item$i"] = $item;
                $userRow["Bonus$i"] = $newBonus;
                $changeEntry["Item$i"] = $item;
                $changeEntry["OldBonus$i"] = $bonus;
                $changeEntry["NewBonus$i"] = $newBonus;
                $i++;
            }

            for (; $i <= 10; $i++) {
                $userRow["Item$i"] = '';
                $userRow["Bonus$i"] = '';
            }

            if ($nonZero) {
                $finalRows[] = $userRow;
                $changeEntry['Status'] = 'penalized';
                $changes[] = $changeEntry;
            }
        }
    }

$timestamp = time();
$filename = "$csvDir/softres_export_$timestamp.csv";
writeCSV($filename, $finalRows);

// ✅ Only send to Discord if URL was provided
if (!empty($_POST['WEBHOOK_URL'])) {
    sendToDiscord(trim($_POST['WEBHOOK_URL']), $filename);
}

echo json_encode([
    'success' => true,
    'file' => "exports/softres_export_$timestamp.csv",
    'diff' => $changes
]);
exit;

} 
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>SoftRes CSV Exporter</title>
  <style>
    #spinner {
      display: none;
      font-weight: bold;
      margin-top: 1em;
      color: #555;
    }
    .spinner-icon {
      display: inline-block;
      animation: spin 1s linear infinite;
      margin-right: 8px;
    }
    @keyframes spin {
      0%   { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    #message { margin-top: 1em; }
    #search-box {
      display: none;
      margin: 1em 0;
      padding: 0.5em;
      width: 100%;
      max-width: 400px;
    }
    #diff-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      margin-top: 1em;
    }
    .diff-card {
      background-color: #f9f9f9;
      border: 2px solid #ccc;
      border-left: 8px solid;
      padding: 1em;
      width: 280px;
      border-radius: 8px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .diff-card.added, .diff-card.updated { border-left-color: #22c55e; }
    .diff-card.penalized { border-left-color: #ef4444; }
    .diff-card.current { border-left-color: #3b82f6; }
    .diff-card h4 { margin: 0 0 0.5em 0; }
    .diff-card table { width: 100%; font-size: 0.9em; border-collapse: collapse; }
    .diff-card table td { padding: 0.2em 0.5em; }
  </style>
</head>
<body>
  <center>

<form id="softres-form" method="POST" enctype="multipart/form-data">
  <label>Softres.it/raid/:
    <input type="text" name="URL" required placeholder="e.g. pohd9n">
  </label>
  <label>Bonus Increment:
    <input type="number" name="INCREMENT" min="1" required>
  </label>

  <label><input type="checkbox" id="use-previous"> Use previous CSV data</label><br>
  <div id="csv-upload" style="display:none;">
    <label>Upload previous CSV:
      <input type="file" name="CSV_UPLOAD" accept=".csv">
    </label><br>
  </div>

  <label><input type="checkbox" id="send-discord"> Send to Discord</label><br>
  <div id="webhook-field" style="display:none;">
    <label>Discord Webhook URL:
      <input type="text" name="WEBHOOK_URL" placeholder="https://discord.com/api/webhooks/...">
    </label><br>
  </div>

  <input type="submit" id="submit-button" value="Generate CSV">
</form>

  <div id="spinner"><span class="spinner-icon">⏳</span> Processing... please wait.</div>
  <div id="message"></div>
  <input type="text" id="search-box" placeholder="Search by raider or item...">
  <div id="diff-grid"></div>
  </center>
  <script>
    const form = document.getElementById('softres-form');
    const submitBtn = document.getElementById('submit-button');
    const spinner = document.getElementById('spinner');
    const message = document.getElementById('message');
    const grid = document.getElementById('diff-grid');
    const search = document.getElementById('search-box');
    const csvUpload = document.getElementById('csv-upload');

    let viewMode = false;

//fileInput.addEventListener('change', () => {
//  if (usePrevious.checked && fileInput.files.length > 0) {
//    submitBtn.value = 'View Current Data';
//    submitBtn.disabled = false;
//    viewMode = true;
//  } else {
//    submitBtn.value = 'Generate CSV';
//    viewMode = false;
//  }
//});

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      message.innerHTML = '';
      grid.innerHTML = '';
      spinner.style.display = 'block';
      search.style.display = 'none';

      const formData = new FormData(form);
      if (viewMode) {
        formData.append('VIEW_ONLY', '1');
      }

      try {
        const response = await fetch(window.location.href, {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const text = await response.text();
        const result = JSON.parse(text);
        spinner.style.display = 'none';

        if (result.success) {
          if (!viewMode) {
            message.innerHTML = `✅ CSV generated. <a href="${result.file}" download>Download CSV</a>`;
          }
          displayDiff(result.diff);
          search.style.display = 'block';
          if (viewMode) {
            submitBtn.value = 'Apply Bonuses';
            viewMode = false;
          } else {
            submitBtn.value = 'Generate CSV';
          }
        } else {
          message.innerHTML = `❌ ${result.error || 'Unknown error'}`;
        }
      } catch (err) {
        spinner.style.display = 'none';
        message.innerHTML = '❌ Failed to process request. Check console.';
        console.error(err);
      }
    });

    function displayDiff(diffArray) {
      grid.innerHTML = '';
      if (!Array.isArray(diffArray)) return;

      diffArray.forEach(entry => {
        const card = document.createElement('div');
        card.classList.add('diff-card');
        if (entry.Status === 'added' || entry.Status === 'updated') {
          card.classList.add('added');
        } else if (entry.Status === 'penalized') {
          card.classList.add('penalized');
        } else {
          card.classList.add('current');
        }
        card.dataset.username = entry.Username.toLowerCase();
        card.dataset.items = [...Array(10).keys()]
          .map(i => entry[`Item${i+1}`]?.toLowerCase() || '')
          .filter(Boolean)
          .join(' ');

        const title = document.createElement('h4');
        title.textContent = `${entry.Status.toUpperCase()}: ${entry.Username}`;
        card.appendChild(title);

        const table = document.createElement('table');
        for (let i = 1; i <= 10; i++) {
          const item = entry[`Item${i}`];
          const oldBonus = entry[`OldBonus${i}`];
          const newBonus = entry[`NewBonus${i}`];

          if (item) {
            const row = document.createElement('tr');
            const itemCell = document.createElement('td');
            const bonusCell = document.createElement('td');
            itemCell.textContent = item;
            bonusCell.textContent = entry.Status === 'current' ? newBonus : `${oldBonus} ➔ ${newBonus}`;
            row.appendChild(itemCell);
            row.appendChild(bonusCell);
            table.appendChild(row);
          }
        }
        card.appendChild(table);
        grid.appendChild(card);
      });

search.addEventListener('input', () => {
  const query = search.value.toLowerCase().trim();

  document.querySelectorAll('.diff-card').forEach(card => {
    const matchUser = card.dataset.username.includes(query);
    const matchItem = card.dataset.items.includes(query);

    // show all cards and rows if empty
    if (!query) {
      card.style.display = 'block';
      card.querySelectorAll('tr').forEach(row => row.style.display = '');
      return;
    }

    if (matchUser) {
      card.style.display = 'block';
      card.querySelectorAll('tr').forEach(row => row.style.display = '');
    } else if (matchItem) {
      card.style.display = 'block';
      card.querySelectorAll('tr').forEach(row => {
        const item = row.cells[0]?.textContent.toLowerCase() || '';
        row.style.display = item.includes(query) ? '' : 'none';
      });
    } else {
      card.style.display = 'none';
    }
  });
});


    }
  const usePrevious = document.getElementById('use-previous');
//  const csvUpload = document.getElementById('csv-upload');
  const sendDiscord = document.getElementById('send-discord');
  const webhookField = document.getElementById('webhook-field');
  const fileInput = document.querySelector('input[name="CSV_UPLOAD"]');
  const submitButton = document.getElementById('submit-button');

  usePrevious.addEventListener('change', () => {
    csvUpload.style.display = usePrevious.checked ? 'block' : 'none';
    submitButton.value = usePrevious.checked ? 'View Current Data' : 'Generate CSV';
    submitButton.disabled = usePrevious.checked && !fileInput.files.length;
    viewMode = usePrevious.checked && fileInput.files.length > 0;
  });

fileInput.addEventListener('change', () => {
  if (usePrevious.checked && fileInput.files.length > 0) {
    submitBtn.value = 'View Current Data';
    submitBtn.disabled = false;
    viewMode = true;
  } else {
    submitBtn.value = usePrevious.checked ? 'View Current Data' : 'Generate CSV';
    submitBtn.disabled = usePrevious.checked;
    viewMode = false;
  }
});

  sendDiscord.addEventListener('change', () => {
    webhookField.style.display = sendDiscord.checked ? 'block' : 'none';
  });
  </script>
</body>
</html>
