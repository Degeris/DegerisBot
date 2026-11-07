<?php
ignore_user_abort(true);
set_time_limit(120);
@unlink(__DIR__ . '/cron.lock');

$functionBootstrap = __DIR__ . '/function.php';
if (!is_readable($functionBootstrap)) {
    $functionBootstrap = __DIR__ . '/../function.php';
}

$bootstrapLoaded = false;
if (is_readable($functionBootstrap)) {
    try {
        require_once $functionBootstrap;
        $bootstrapLoaded = true;
    } catch (Throwable $e) {
        
    }
}

 
if (isset($conn) && $conn instanceof mysqli) {
    try { $conn->close(); } catch (Throwable $e) {}
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    try { $mysqli->close(); } catch (Throwable $e) {}
} elseif (isset($db) && $db instanceof PDO) {
    $db = null;
}

if (function_exists('mysqli_close') && isset($GLOBALS['conn'])) {
    try { @mysqli_close($GLOBALS['conn']); } catch (Throwable $e) {}
}

 
$host = null;
if (isset($domainhosts) && is_string($domainhosts) && trim($domainhosts) !== '') {
    $host = $domainhosts;
}
if ($host === null || trim((string) $host) === '') {
    $host = $_SERVER['HTTP_HOST'] ?? null;
}
if ($host === null || trim((string) $host) === '') {
    $host = 'localhost';
}

$hostConfig = $host;
if (!preg_match('~^https?://~i', $hostConfig)) {
    $hostConfig = 'https://' . ltrim($hostConfig);
}

$parts    = parse_url($hostConfig);
$scheme   = $parts['scheme'] ?? 'https';
$hostOnly = $parts['host']   ?? 'localhost';
$basePath = rtrim($parts['path'] ?? '', '/');

 
$buildCronUrl = static function (string $script) use ($scheme, $hostOnly, $basePath): string {
    $script = ltrim($script, '/');
    $prefix = 'cronbot';
    $path = $basePath === '' ? '' : $basePath . '/';
    return $scheme . '://' . $hostOnly . $path . $prefix . '/' . $script;
};

 
function callEndpoint(string $url): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FORBID_REUSE   => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
    
    sleep(1);
}

 
$now       = time();
$minute    = (int) date('i', $now);
$hour      = (int) date('G', $now);
$dayOfYear = (int) date('z', $now);

 
if (!defined('APP_ROOT_PATH')) {
    define('APP_ROOT_PATH', dirname(__DIR__));
}

$pdo = getDatabaseConnection();
$runtimeState = [];

if ($pdo instanceof PDO) {
    ensureCronRuntimeStateTable($pdo);
    $runtimeState = loadCronRuntimeState($pdo);
}

$getIntervalSeconds = static function (array $schedule): int {
    $unit  = isset($schedule['unit']) ? strtolower((string) $schedule['unit']) : 'minute';
    $value = isset($schedule['value']) ? (int) $schedule['value'] : 1;
    if ($value < 1) $value = 1;

    switch ($unit) {
        case 'minute':   return 0;
        case 'hour':     return $value * 3600;
        case 'day':      return $value * 86400;
        case 'disabled': return 0;
        default:         return 0;
    }
};

 
if ($bootstrapLoaded && function_exists('getCronJobDefinitions') && function_exists('shouldRunCronJob')) {
    $definitions = getCronJobDefinitions();
    $schedules   = function_exists('loadCronSchedules') ? loadCronSchedules() : [];
    foreach ($definitions as $key => $definition) {
        if (empty($definition['script'])) {
            continue;
        }
        $defaultConfig = $definition['default'] ?? ['unit' => 'minute', 'value' => 1];
        $schedule      = $schedules[$key] ?? $defaultConfig;
        $unit          = strtolower($schedule['unit'] ?? 'minute');
        if ($unit === 'disabled') {
            continue;
        }
        if ($unit === 'minute') {
            if (!shouldRunCronJob($schedule, $minute, $hour, $dayOfYear)) {
                continue;
            }
            callEndpoint($buildCronUrl($definition['script']));
            continue;
        }
        $intervalSeconds = $getIntervalSeconds($schedule);
        if ($intervalSeconds <= 0) {
            continue;
        }
        $lastRun = isset($runtimeState[$key]) ? (int) $runtimeState[$key] : 0;
        if (($now - $lastRun) < $intervalSeconds) {
            continue;
        }
        callEndpoint($buildCronUrl($definition['script']));
        $runtimeState[$key] = $now;
        if ($pdo instanceof PDO) {
            setCronJobLastRun($pdo, $key, $now);
        }
    }

    
    $extraScripts = ['index.php', 'lottery.php'];
    $definedScripts = [];
    foreach ($definitions as $definition) {
        if (isset($definition['script']) && is_string($definition['script'])) {
            
            $definedScripts[] = ltrim($definition['script'], '/');
        }
    }
    foreach ($extraScripts as $extraScript) {
        if (!in_array($extraScript, $definedScripts, true)) {
            
            callEndpoint($buildCronUrl($extraScript));
        }
    }
} else {
    
    $everyMinute = [
        'croncard.php', 'sendmessage.php',
        'activeconfig.php', 'disableconfig.php', 'iranpay1.php',
        'index.php', 'lottery.php',
    ];
    foreach ($everyMinute as $script) {
        callEndpoint($buildCronUrl($script));
    }
    
    if ($minute % 2 === 0) {
        foreach (['gift.php', 'configtest.php'] as $script) {
            callEndpoint($buildCronUrl($script));
        }
    }
    
    if ($minute % 3 === 0) {
        callEndpoint($buildCronUrl('plisio.php'));
    }
    
    if ($minute % 5 === 0) {
        callEndpoint($buildCronUrl('payment_expire.php'));
    }
    
    if ($minute % 15 === 0) {
        callEndpoint($buildCronUrl('NoticationsService.php'));
        foreach (['statusday.php', 'on_hold.php', 'uptime_node.php', 'uptime_panel.php'] as $script) {
            callEndpoint($buildCronUrl($script));
        }
    }
    
    if ($minute % 30 === 0) {
        callEndpoint($buildCronUrl('expireagent.php'));
    }
    
    if ($minute === 0 && $hour % 5 === 0) {
        callEndpoint($buildCronUrl('backupbot.php'));
    }
}

 
echo "OK\n";
