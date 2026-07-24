<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$workPath = __DIR__ . '/openclaw.json';
$backupDir = __DIR__ . '/backups';
$envFile = __DIR__ . '/.env';
$flagLoad = __DIR__ . '/.request_load';
$flagSave = __DIR__ . '/.request_save';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

function readConfig($path) {
    $fp = fopen($path, 'r');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open config file']);
        exit;
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['error' => 'Cannot lock config file']);
        exit;
    }
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $content;
}

function writeConfig($path, $content) {
    $decoded = json_decode($content, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    $fp = fopen($path, 'c+');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open config file for writing']);
        exit;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['error' => 'Cannot lock config file']);
        exit;
    }
    ftruncate($fp, 0);
    rewind($fp);
    $written = fwrite($fp, $content);
    if ($written === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to write config file']);
        exit;
    }
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function createBackup($path, $backupDir) {
    $ts = date('Ymd-His');
    $backupFile = "$backupDir/openclaw-$ts.json";
    if (file_exists($path)) {
        copy($path, $backupFile);
    }
    $backups = glob("$backupDir/openclaw-*.json");
    if (count($backups) > 20) {
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        foreach (array_slice($backups, 0, count($backups) - 20) as $old) {
            unlink($old);
        }
    }
    return $backupFile;
}

function syncDefaultsModels(&$config) {
    if (!isset($config['models']['providers']) || !isset($config['agents']['defaults']['models'])) {
        return;
    }
    $knownModels = [];
    foreach ($config['models']['providers'] as $providerId => $provider) {
        if (!isset($provider['models'])) continue;
        foreach ($provider['models'] as $model) {
            if (isset($model['id'])) {
                $knownModels[$providerId . '/' . $model['id']] = true;
            }
        }
    }
    $defaultsModels = &$config['agents']['defaults']['models'];
    
    // Fix: convert any empty arrays to objects (artefact of json_decode(..., true))
    foreach ($defaultsModels as $key => &$entry) {
        if (is_array($entry) && empty($entry)) {
            $entry = new stdClass();
        }
    }
    unset($entry);
    
    foreach (array_keys($defaultsModels) as $key) {
        $parts = explode('/', $key);
        if (count($parts) >= 2 && isset($config['models']['providers'][$parts[0]])) {
            if (!isset($knownModels[$key])) unset($defaultsModels[$key]);
        }
    }
    foreach ($knownModels as $key => $_) {
        if (!isset($defaultsModels[$key])) $defaultsModels[$key] = new stdClass();
    }
    ksort($defaultsModels);
}

function readEnvVar($envFile, $key) {
    if (!file_exists($envFile)) return '';
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^' . preg_quote($key, '/') . '=(.+)$/', $line, $m)) {
            return $m[1];
        }
    }
    return '';
}

// ================================================================
// ROUTER
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo readConfig($workPath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body']);
        exit;
    }
    $action = $input['action'] ?? '';

    // ----- GET CONFIG (project file) -----
    if ($action === 'getConfig') {
        echo readConfig($workPath);
        exit;
    }

    // ----- SAVE CONFIG (project file only) -----
    if ($action === 'saveConfig') {
        $newConfig = $input['config'] ?? null;
        if (!$newConfig) {
            http_response_code(400);
            echo json_encode(['error' => 'No config data provided']);
            exit;
        }
        if ($input['autoSync'] ?? true) {
            syncDefaultsModels($newConfig);
        }
        createBackup($workPath, $backupDir);
        $encoded = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = preg_replace_callback('/^(    )+/m', function($m) { return str_repeat('  ', strlen($m[0]) / 4); }, $encoded);
        $encoded = preg_replace('/^(  +"[^"]+"): \[\]$/m', '$1: {}', $encoded);
        writeConfig($workPath, $encoded);
        echo json_encode(['success' => true, 'message' => 'Saved']);
        exit;
    }

    // ----- REQUEST LOAD (set flag → cron handles live→project) -----
    if ($action === 'requestLoad') {
        touch($flagLoad);
        echo json_encode(['success' => true, 'message' => 'Load requested']);
        exit;
    }

    // ----- REQUEST SAVE (save to project + set flag → cron handles project→live) -----
    if ($action === 'requestSave') {
        $newConfig = $input['config'] ?? null;
        if ($newConfig) {
            if ($input['autoSync'] ?? true) {
                syncDefaultsModels($newConfig);
            }
            $encoded = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $encoded = preg_replace_callback('/^(    )+/m', function($m) { return str_repeat('  ', strlen($m[0]) / 4); }, $encoded);
            $encoded = preg_replace('/^(  +"[^"]+"): \[\]$/m', '$1: {}', $encoded);
            writeConfig($workPath, $encoded);
        }
        touch($flagSave);
        echo json_encode(['success' => true, 'message' => 'Save to live requested']);
        exit;
    }

    // ----- CHECK FLAGS (polling: are they done?) -----
    if ($action === 'checkFlags') {
        echo json_encode([
            'loadPending' => file_exists($flagLoad),
            'savePending' => file_exists($flagSave),
        ]);
        exit;
    }

    // ----- FETCH MODELS -----
    if ($action === 'fetchModels') {
        $apiKey = readEnvVar($envFile, 'OMNIROUTE_API_KEY');
        $ch = curl_init('http://192.168.0.53:20128/v1/models');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            http_response_code(502);
            echo json_encode(['error' => 'Failed to fetch models', 'httpCode' => $httpCode]);
            exit;
        }
        header('Content-Type: application/json');
        echo $response;
        exit;
    }

    // ----- LIST BACKUPS -----
    if ($action === 'listBackups') {
        $backups = glob("$backupDir/openclaw-*.json");
        rsort($backups);
        $result = [];
        foreach ($backups as $b) {
            $result[] = ['path' => basename($b), 'size' => filesize($b), 'time' => filemtime($b)];
        }
        echo json_encode(['backups' => $result]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
