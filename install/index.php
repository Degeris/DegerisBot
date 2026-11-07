<?php
// Degeris Bot: restore-only web installer.
// It imports a backup of an existing bot database and upgrades it with the
// project's own db/ bootstrap (db/bootstrap.php -> db/tables.php, db/migrations, db/indexes.php).
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
@set_time_limit(900);
@ini_set('memory_limit', '1024M');

define('INST_ROOT', dirname(__DIR__));

// ---------------------------------------------------------------------------
// Helpers (prefixed with inst_ so they cannot collide with the bot's functions)
// ---------------------------------------------------------------------------

function inst_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inst_scrub(string $message, string ...$secrets): string
{
    foreach ($secrets as $secret) {
        if ($secret !== '') {
            $message = str_replace($secret, '********', $message);
        }
    }
    return $message;
}

function inst_telegram(string $token, string $method, array $params = []): array
{
    $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'DegerisBot-Installer',
    ]);
    $raw = curl_exec($ch);
    $error = (string) curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'description' => inst_scrub($error !== '' ? $error : 'Telegram connection failed', $token)];
    }
    $json = json_decode((string) $raw, true);
    return is_array($json) ? $json : ['ok' => false, 'description' => 'Invalid Telegram response'];
}

function inst_valid_token(string $token): bool
{
    return (bool) preg_match('/^\d{6,}:[A-Za-z0-9_-]{30,}$/', $token);
}

function inst_valid_admin(string $adminId): bool
{
    return (bool) preg_match('/^\d{5,15}$/', $adminId);
}

function inst_valid_host(string $host): bool
{
    return (bool) preg_match('/^(localhost|(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)$/', $host);
}

function inst_valid_db_name(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $name);
}

function inst_valid_db_user(string $user): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.\-]{1,32}$/', $user);
}

function inst_valid_db_password(string $password): bool
{
    return $password !== '' && strlen($password) <= 255 && strpos($password, "\n") === false && strpos($password, "\r") === false && strpos($password, "\0") === false;
}

function inst_valid_domain(string $domain): bool
{
    return strlen($domain) <= 253 && strpos($domain, '.') !== false && inst_valid_host($domain);
}

function inst_default_domain(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    return strtolower((string) preg_replace('/:\d+$/', '', $host));
}

// Reads the uploaded backup (.sql, or .zip containing a .sql) and returns the SQL text.
function inst_read_backup(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('فایل بکاپ آپلود نشد (کد خطا: ' . $error . ').');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $tmp = (string) $file['tmp_name'];

    if ($ext === 'sql') {
        $sql = file_get_contents($tmp);
    } elseif ($ext === 'zip') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive روی این هاست فعال نیست. فایل .sql را مستقیم آپلود کنید.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('باز کردن فایل ZIP ناموفق بود.');
        }
        $sql = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('/\.sql$/i', $entry) && strpos($entry, '__MACOSX') === false) {
                $sql = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        if ($sql === false) {
            throw new RuntimeException('داخل فایل ZIP هیچ فایل .sql پیدا نشد.');
        }
    } else {
        throw new RuntimeException('فقط فایل .sql یا .zip قابل قبول است.');
    }

    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('فایل بکاپ خالی است یا خوانده نشد.');
    }
    return $sql;
}

// Splits a MySQL/MariaDB dump into statements. Respects '…', "…", `…` strings,
// -- / # / block comments, executable /*! … */ comments and DELIMITER blocks.
function inst_split_sql(string $sql): array
{
    $statements = [];
    $delimiter = ';';
    $buffer = '';
    $state = 'normal';
    $length = strlen($sql);
    $i = 0;

    while ($i < $length) {
        $ch = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        switch ($state) {
            case 'single':
            case 'double':
            case 'backtick':
                $quote = $state === 'single' ? "'" : ($state === 'double' ? '"' : '`');
                if ($ch === '\\' && $state !== 'backtick') {
                    $buffer .= $ch . $next;
                    $i += 2;
                    continue 2;
                }
                if ($ch === $quote) {
                    if ($next === $quote) {
                        $buffer .= $ch . $next;
                        $i += 2;
                        continue 2;
                    }
                    $state = 'normal';
                }
                $buffer .= $ch;
                $i++;
                continue 2;

            case 'line_comment':
                if ($ch === "\n") {
                    $state = 'normal';
                    $buffer .= $ch;
                }
                $i++;
                continue 2;

            case 'block_comment':
                if ($ch === '*' && $next === '/') {
                    $state = 'normal';
                    $i += 2;
                    continue 2;
                }
                $i++;
                continue 2;

            case 'exec_comment':
                if ($ch === '*' && $next === '/') {
                    $buffer .= '*/';
                    $state = 'normal';
                    $i += 2;
                    continue 2;
                }
                $buffer .= $ch;
                $i++;
                continue 2;

            default:
                if (trim($buffer) === '' && ($ch === 'D' || $ch === 'd')) {
                    if (preg_match('/\GDELIMITER[ \t]+(\S+)[ \t]*(?:\r?\n|\z)/i', $sql, $m, 0, $i)) {
                        $delimiter = $m[1];
                        $buffer = '';
                        $i += strlen($m[0]);
                        continue 2;
                    }
                }
                if ($ch === "'") {
                    $state = 'single';
                } elseif ($ch === '"') {
                    $state = 'double';
                } elseif ($ch === '`') {
                    $state = 'backtick';
                } elseif ($ch === '#') {
                    $state = 'line_comment';
                    $i++;
                    continue 2;
                } elseif ($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                    $state = 'line_comment';
                    $i += 2;
                    continue 2;
                } elseif ($ch === '/' && $next === '*') {
                    if ($i + 2 < $length && $sql[$i + 2] === '!') {
                        $state = 'exec_comment';
                        $buffer .= '/*!';
                        $i += 3;
                        continue 2;
                    }
                    $state = 'block_comment';
                    $i += 2;
                    continue 2;
                } elseif (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                    $statements[] = $buffer;
                    $buffer = '';
                    $i += strlen($delimiter);
                    continue 2;
                }
                $buffer .= $ch;
                $i++;
                continue 2;
        }
    }

    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }

    $clean = [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $clean[] = $statement;
        }
    }
    return $clean;
}

function inst_strip_definer(string $statement): string
{
    $pattern = '/DEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[^\s@`\'"]+)\s*@\s*(?:`[^`]*`|\'[^\']*\'|[^\s`\'"(*]+)\s*/i';
    return preg_replace($pattern, '', $statement) ?? $statement;
}

// Drops every existing view and table in the connected database.
function inst_drop_all(PDO $pdo): int
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $rows = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        // Views first, so they never block the table drops.
        usort($rows, static function ($a, $b) {
            $av = strtoupper((string) ($a[1] ?? '')) === 'VIEW' ? 0 : 1;
            $bv = strtoupper((string) ($b[1] ?? '')) === 'VIEW' ? 0 : 1;
            return $av <=> $bv;
        });
        $count = 0;
        foreach ($rows as $row) {
            $name = (string) $row[0];
            $isView = strtoupper((string) ($row[1] ?? '')) === 'VIEW';
            $quoted = '`' . str_replace('`', '``', $name) . '`';
            $pdo->exec(($isView ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $quoted);
            $count++;
        }
        return $count;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

// Runs the backup SQL. Returns counts of executed and skipped statements.
function inst_import_sql(PDO $pdo, string $sql): array
{
    $statements = inst_split_sql($sql);
    $executed = 0;
    $skipped = 0;
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($statements as $statement) {
            // The installer already selected the target database: no CREATE/USE/DROP DATABASE.
            if (preg_match('/^\s*(?:\/\*!\d*\s*)?(?:CREATE\s+(?:DATABASE|SCHEMA)|DROP\s+(?:DATABASE|SCHEMA)|USE)\b/i', $statement)) {
                $skipped++;
                continue;
            }
            // MySQL 8 collations are not available on MariaDB and older MySQL.
            $statement = preg_replace('/utf8mb4_0900_[a-z0-9_]+/i', 'utf8mb4_unicode_ci', $statement) ?? $statement;
            $statement = inst_strip_definer($statement);
            if (trim($statement) === '') {
                $skipped++;
                continue;
            }
            $pdo->exec($statement);
            $executed++;
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    return ['executed' => $executed, 'skipped' => $skipped];
}

// Writes the installer values into the existing config.php template.
function inst_write_config(string $configFile, array $values): void
{
    $content = file_get_contents($configFile);
    if ($content === false || $content === '') {
        throw new RuntimeException('config.php خوانده نشد.');
    }
    foreach ($values as $variable => $value) {
        $pattern = '#(\$' . preg_quote($variable, '#') . '\s*=\s*)\'(?:[^\'\\\\]|\\\\.)*\'\s*;#';
        $literal = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
        $count = 0;
        $content = preg_replace_callback(
            $pattern,
            static function ($m) use ($literal) {
                return $m[1] . $literal . ';';
            },
            $content,
            1,
            $count
        );
        if ($content === null || $count !== 1) {
            throw new RuntimeException('ساختار config.php با قالب انتظاری مطابقت ندارد (' . $variable . ').');
        }
    }
    if (file_put_contents($configFile, $content, LOCK_EX) === false) {
        throw new RuntimeException('نوشتن config.php ناموفق بود. دسترسی نوشتن را بررسی کنید.');
    }
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$errors = [];
$steps = [];
$finished = false;
$migrationReport = [];
$statementReport = '';
$botUsername = '';
$installMode = 'fresh';

$formValues = [
    'bot_token' => '',
    'admin_id' => '',
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'domain' => inst_default_domain(),
];

if ($isPost) {
    $formValues['bot_token'] = trim((string) ($_POST['bot_token'] ?? ''));
    $formValues['admin_id'] = trim((string) ($_POST['admin_id'] ?? ''));
    $formValues['db_host'] = trim((string) ($_POST['db_host'] ?? ''));
    $formValues['db_name'] = trim((string) ($_POST['db_name'] ?? ''));
    $formValues['db_user'] = trim((string) ($_POST['db_user'] ?? ''));
    $formValues['domain'] = strtolower(trim((string) ($_POST['domain'] ?? '')));
    // Password is kept exactly as typed (no trimming) and is never echoed back.
    $dbPassword = (string) ($_POST['db_password'] ?? '');
    $confirmed = !empty($_POST['confirm_wipe']);
    $installMode = (($_POST['install_mode'] ?? 'fresh') === 'restore') ? 'restore' : 'fresh';

    $postTooLarge = empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

    do {
        if ($postTooLarge) {
            $errors[] = 'حجم فایل ارسالی از حد مجاز سرور بیشتر است (post_max_size / upload_max_filesize).';
            break;
        }

        // Step 1: validate every field.
        $domain = preg_replace('#^https?://#i', '', $formValues['domain']);
        $domain = rtrim((string) $domain, '/');
        $formValues['domain'] = $domain;

        if (!inst_valid_token($formValues['bot_token'])) {
            $errors[] = 'توکن ربات معتبر نیست (مثال: 123456:ABC...).';
        }
        if (!inst_valid_admin($formValues['admin_id'])) {
            $errors[] = 'آیدی عددی ادمین معتبر نیست. فقط عدد وارد کنید.';
        }
        if (!inst_valid_host($formValues['db_host'])) {
            $errors[] = 'آدرس هاست دیتابیس معتبر نیست.';
        }
        if (!inst_valid_db_name($formValues['db_name'])) {
            $errors[] = 'نام دیتابیس معتبر نیست (فقط حروف انگلیسی، عدد و _ تا ۶۴ کاراکتر).';
        }
        if (!inst_valid_db_user($formValues['db_user'])) {
            $errors[] = 'نام کاربری دیتابیس معتبر نیست.';
        }
        if (!inst_valid_db_password($dbPassword)) {
            $errors[] = 'رمز عبور دیتابیس خالی یا نامعتبر است.';
        }
        if (!inst_valid_domain($domain)) {
            $errors[] = 'دامنه ربات معتبر نیست (مثال: bot.example.com، بدون https:// و بدون مسیر).';
        }
        if ($errors) {
            break;
        }

        // Step 2 and 3 (restore mode only): backup file and explicit confirmation.
        $backupSql = null;
        if ($installMode === 'restore') {
            if (!isset($_FILES['backup_file'])) {
                $errors[] = 'فایل بکاپ انتخاب نشده است.';
                break;
            }
            try {
                $backupSql = inst_read_backup($_FILES['backup_file']);
            } catch (Throwable $e) {
                $errors[] = inst_scrub($e->getMessage(), $dbPassword);
                break;
            }
            if (!$confirmed) {
                $errors[] = 'برای ادامه باید تیک تأیید را بزنید.';
                break;
            }
        }

        // Telegram token check (needed for the bot username and webhook).
        $bot = inst_telegram($formValues['bot_token'], 'getMe');
        if (empty($bot['ok'])) {
            $errors[] = 'توکن ربات پذیرفته نشد یا اتصال به تلگرام برقرار نشد: ' . inst_scrub((string) ($bot['description'] ?? ''), $formValues['bot_token'], $dbPassword);
            break;
        }
        $botUsername = ltrim((string) ($bot['result']['username'] ?? ''), '@');
        $steps[] = 'ربات تلگرام شناسایی شد: @' . $botUsername;

        // Step 4: connect first, then drop everything.
        $dsn = 'mysql:host=' . $formValues['db_host'] . ';dbname=' . $formValues['db_name'] . ';charset=utf8mb4';
        try {
            $restorePdo = new PDO($dsn, $formValues['db_user'], $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ]);
        } catch (Throwable $e) {
            $errors[] = 'اتصال به دیتابیس ناموفق بود: ' . inst_scrub($e->getMessage(), $dbPassword);
            break;
        }
        $steps[] = 'اتصال به دیتابیس برقرار شد.';

        try {
            $existingCount = (int) count($restorePdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM));
            if ($installMode === 'fresh' && $existingCount > 0 && !$confirmed) {
                $errors[] = 'این دیتابیس ' . $existingCount . ' جدول دارد. برای نصب تازه باید دیتابیس خالی باشد، یا تیک تأیید را بزنید تا جدول‌ها پاک شوند.';
                break;
            }
            if ($existingCount > 0) {
                $droppedCount = inst_drop_all($restorePdo);
                $remaining = (int) count($restorePdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM));
                if ($remaining !== 0) {
                    throw new RuntimeException('بعد از پاک‌سازی هنوز ' . $remaining . ' جدول یا ویو باقی مانده است.');
                }
                $steps[] = $droppedCount . ' جدول و ویو قبلی پاک شد.';
            } else {
                $steps[] = 'دیتابیس خالی بود.';
            }
        } catch (Throwable $e) {
            $errors[] = 'پاک‌سازی دیتابیس ناموفق بود: ' . inst_scrub($e->getMessage(), $dbPassword);
            break;
        }

        // Step 5 (restore mode only): import the old backup. Fresh mode skips this; db/ creates everything.
        if ($installMode === 'restore') try {
            $importStats = inst_import_sql($restorePdo, $backupSql);
            $statementReport = $importStats['executed'] . ' دستور SQL اجرا شد و ' . $importStats['skipped'] . ' دستور (CREATE/USE/DROP DATABASE) نادیده گرفته شد.';
            $steps[] = $statementReport;
        } catch (Throwable $e) {
            $errors[] = 'Import بکاپ ناموفق بود: ' . inst_scrub($e->getMessage(), $dbPassword);
            break;
        }

        // db/bootstrap.php loads config.php, so the new database values must be in place first.
        try {
            inst_write_config(INST_ROOT . '/config.php', [
                'dbhost' => $formValues['db_host'],
                'dbname' => $formValues['db_name'],
                'usernamedb' => $formValues['db_user'],
                'passworddb' => $dbPassword,
                'APIKEY' => $formValues['bot_token'],
                'adminnumber' => $formValues['admin_id'],
                'domainhosts' => $domain,
                'usernamebot' => $botUsername,
            ]);
            $steps[] = 'config.php با اطلاعات جدید نوشته شد.';
        } catch (Throwable $e) {
            $errors[] = inst_scrub($e->getMessage(), $dbPassword);
            break;
        }

        if (!is_file(INST_ROOT . '/vendor/autoload.php') || !is_file(INST_ROOT . '/db/bootstrap.php')) {
            $errors[] = 'فایل‌های vendor/autoload.php یا db/bootstrap.php پیدا نشدند. پروژه کامل آپلود نشده است.';
            break;
        }

        // Step 6: upgrade the imported data with the project's own db/ code.
        try {
            $schema = require INST_ROOT . '/db/bootstrap.php';
        } catch (Throwable $e) {
            $errors[] = 'به‌روزرسانی ساختار دیتابیس ناموفق بود: ' . inst_scrub($e->getMessage(), $dbPassword);
            break;
        }
        if (!($schema instanceof Schema)) {
            $errors[] = 'db/bootstrap.php یک Schema معتبر برنگرداند.';
            break;
        }

        $failureScopes = [];
        foreach ($schema->failures() as $failure) {
            $failureScopes[$failure[0]] = inst_scrub((string) ($failure[1] ?? 'unknown error'), $dbPassword);
        }

        foreach (glob(INST_ROOT . '/db/migrations/*.php') ?: [] as $migrationFile) {
            $name = basename($migrationFile, '.php');
            $key = 'migration:' . $name;
            $migrationReport[] = [
                'name' => $name,
                'ok' => !array_key_exists($key, $failureScopes),
                'error' => $failureScopes[$key] ?? '',
            ];
        }
        sort($migrationReport);

        if ($failureScopes) {
            foreach ($failureScopes as $scope => $reason) {
                $errors[] = $scope . ': ' . $reason;
            }
            break;
        }

        $tableOrder = require INST_ROOT . '/db/tables.php';
        $missing = [];
        foreach ($tableOrder as $table) {
            if (is_string($table) && $table !== '' && !$schema->tableExists($table)) {
                $missing[] = $table;
            }
        }
        if ($missing) {
            $errors[] = 'جدول‌های ساخته‌نشده: ' . implode(', ', $missing);
            break;
        }
        $steps[] = count($migrationReport) . ' Migration اجرا شد و هیچ‌کدام با خطا مواجه نشد.';

        // Step 7: make sure the admin ID is an administrator.
        try {
            $adminStmt = $restorePdo->prepare("INSERT INTO admin (id_admin, username, password, rule) VALUES (:id, 'admin', :pass, 'administrator') ON DUPLICATE KEY UPDATE rule = 'administrator'");
            $adminStmt->execute([':id' => $formValues['admin_id'], ':pass' => bin2hex(random_bytes(8))]);
            $steps[] = 'ادمین با آیدی وارد شده با نقش administrator ثبت شد.';
        } catch (Throwable $e) {
            $errors[] = 'ثبت ادمین در دیتابیس ناموفق بود: ' . inst_scrub($e->getMessage(), $dbPassword);
            break;
        }

        // Step 8: webhook and start message.
        $webhook = inst_telegram($formValues['bot_token'], 'setWebhook', ['url' => 'https://' . $domain . '/index.php']);
        if (empty($webhook['ok'])) {
            $errors[] = 'تنظیم Webhook ناموفق بود: ' . inst_scrub((string) ($webhook['description'] ?? 'Unknown error'), $formValues['bot_token']);
            break;
        }
        $steps[] = 'Webhook روی https://' . $domain . '/index.php تنظیم شد.';

        $startText = "✅ ربات Degeris با موفقیت از بکاپ بازیابی شد.\n\nبرای ورود به ربات روی دکمه Start بزنید.";
        $sent = inst_telegram($formValues['bot_token'], 'sendMessage', [
            'chat_id' => $formValues['admin_id'],
            'text' => $startText,
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🚀 Start', 'url' => 'https://t.me/' . rawurlencode($botUsername) . '?start=admin_start']]]], JSON_UNESCAPED_UNICODE),
        ]);
        if (empty($sent['ok'])) {
            $errors[] = 'پیام Start برای ادمین ارسال نشد: ' . inst_scrub((string) ($sent['description'] ?? 'Unknown error'), $formValues['bot_token']);
            break;
        }
        $steps[] = 'پیام Start برای ادمین ارسال شد.';

        $finished = true;
    } while (false);
}

?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5">
    <link rel="icon" href="./img/logo.jpg" type="image/jpeg">
    <title>بازیابی بکاپ Degeris Bot</title>
    <style>
        @font-face { font-family: 'Vazir'; src: url('../app/fonts/Vazir-Medium.woff2') format('woff2'); font-weight: 500; font-display: swap; }
        @font-face { font-family: 'Vazir'; src: url('../app/fonts/Vazir-Bold.woff2') format('woff2'); font-weight: 700; font-display: swap; }
        :root { --primary: #4f46e5; --primary-2: #7c3aed; --bg: #f5f6fb; --card: #ffffff; --text: #101426; --muted: #667085; --border: #e6e8f0; --danger: #e11d48; --ok: #16a34a; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b0f1c; --card: #131a2c; --text: #f5f7ff; --muted: #97a3bd; --border: rgba(255,255,255,.08); }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; font-family: Vazir, Tahoma, system-ui, sans-serif; color: var(--text);
            background-color: var(--bg);
            background-image: radial-gradient(900px 480px at 8% -10%, rgba(99,102,241,.18), transparent 60%), radial-gradient(700px 420px at 100% 0%, rgba(14,165,233,.12), transparent 60%);
            padding: 28px 16px 40px;
        }
        .wrap { max-width: 640px; margin: 0 auto; }
        .hero { text-align: center; margin-bottom: 20px; }
        .logo { width: 68px; height: 68px; margin: 0 auto 12px; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 28px; color: #fff; background: linear-gradient(135deg, var(--primary), var(--primary-2)); box-shadow: 0 16px 34px -14px rgba(79,70,229,.7); }
        .hero h1 { margin: 0 0 6px; font-size: 22px; }
        .hero p { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.8; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 22px; box-shadow: 0 1px 2px rgba(16,20,38,.04), 0 16px 40px -22px rgba(79,70,229,.3); }
        .steps { display: flex; gap: 8px; margin-bottom: 18px; font-size: 12px; color: var(--muted); justify-content: center; flex-wrap: wrap; }
        .steps span { padding: 6px 10px; border-radius: 999px; background: var(--bg); border: 1px solid var(--border); }
        .section-title { font-size: 14px; font-weight: 700; margin: 18px 0 10px; color: var(--muted); }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field.full { grid-column: 1 / -1; }
        label { font-size: 13px; font-weight: 500; }
        .hint { font-size: 12px; color: var(--muted); }
        input[type=text], input[type=password] {
            width: 100%; padding: 11px 12px; border-radius: 12px; border: 1px solid var(--border); background: var(--bg); color: var(--text);
            font: inherit; font-size: 14px; direction: ltr; text-align: left; transition: border-color .2s, box-shadow .2s;
        }
        input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129,140,248,.3); }
        input[type=file] { width: 100%; padding: 12px; border: 1.5px dashed var(--border); border-radius: 14px; background: var(--bg); font: inherit; font-size: 13px; }
        .warning { margin: 18px 0 12px; padding: 14px 16px; border-radius: 14px; background: rgba(225,29,72,.08); border: 1px solid rgba(225,29,72,.35); color: var(--danger); font-weight: 700; font-size: 14px; line-height: 1.8; }
        .check { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; line-height: 1.8; cursor: pointer; }
        .check input { margin-top: 5px; width: 18px; height: 18px; accent-color: var(--primary); flex-shrink: 0; }
        .btn {
            margin-top: 18px; width: 100%; border: 0; border-radius: 14px; padding: 14px; font: inherit; font-size: 15px; font-weight: 700; color: #fff; cursor: pointer;
            background: linear-gradient(135deg, var(--primary), var(--primary-2)); box-shadow: 0 12px 26px -12px rgba(79,70,229,.8); transition: transform .15s, filter .2s, opacity .2s;
        }
        .btn:hover:not(:disabled) { filter: brightness(1.06); }
        .btn:active:not(:disabled) { transform: scale(.98); }
        .btn:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
        .alert { border-radius: 16px; padding: 16px 18px; margin-bottom: 16px; font-size: 14px; line-height: 1.9; }
        .alert-danger { background: rgba(225,29,72,.08); border: 1px solid rgba(225,29,72,.35); color: var(--danger); }
        .alert-success { background: rgba(22,163,74,.09); border: 1px solid rgba(22,163,74,.35); color: var(--ok); }
        .alert h3 { margin: 14px 0 6px; font-size: 14px; color: var(--text); }
        .list { margin: 6px 0; padding-right: 20px; }
        .list li { margin: 4px 0; }
        .list .bad { color: var(--danger); }
        .list .good { color: var(--ok); }
        .done { text-align: center; margin-top: 14px; }
        .done a { display: inline-block; margin-top: 8px; padding: 10px 18px; border-radius: 12px; background: var(--bg); border: 1px solid var(--border); color: var(--text); text-decoration: none; font-weight: 500; font-size: 14px; }
        .modes { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        @media (max-width: 560px) { .modes { grid-template-columns: 1fr; } }
        .mode-card { display: flex; flex-direction: column; gap: 6px; padding: 14px; border-radius: 16px; border: 1.5px solid var(--border); background: var(--bg); cursor: pointer; position: relative; }
        .mode-card input { position: absolute; top: 14px; left: 14px; accent-color: var(--primary); width: 16px; height: 16px; }
        .mode-card:has(input:checked) { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
        .mode-title { font-weight: 700; font-size: 14px; }
        footer.site { margin-top: 26px; text-align: center; font-size: 13px; color: var(--muted); line-height: 2; }
        footer.site nav { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-bottom: 8px; }
        footer.site nav a { color: var(--text); text-decoration: none; padding: 6px 12px; border-radius: 999px; background: var(--card); border: 1px solid var(--border); font-size: 13px; }
        footer.site nav a:hover { border-color: #818cf8; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <img class="logo" src="./img/logo.jpg" alt="Degeris" style="object-fit:cover;background:none;padding:0;">
        <h1>نصب‌کننده Degeris Bot</h1>
        <p>نصب تازه، یا بازیابی بکاپ ربات قدیمی با ساختار جدید.</p>
    </div>

    <div class="card">
        <div class="steps">
            <span>۱. اطلاعات ربات و دیتابیس</span>
            <span>۲. حالت نصب</span>
            <span>۳. تأیید و اجرا</span>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <strong>عملیات متوقف شد</strong>
                <ul class="list">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo inst_h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($finished): ?>
            <div class="alert alert-success">
                <strong><?php echo $installMode === 'restore' ? 'بازیابی با موفقیت انجام شد ✅' : 'نصب با موفقیت انجام شد ✅'; ?></strong>
                <ul class="list">
                    <?php foreach ($steps as $step): ?>
                        <li class="good"><?php echo inst_h($step); ?></li>
                    <?php endforeach; ?>
                </ul>
                <h3>Migrationها</h3>
                <ul class="list">
                    <?php foreach ($migrationReport as $migration): ?>
                        <li class="<?php echo $migration['ok'] ? 'good' : 'bad'; ?>">
                            <?php echo inst_h($migration['name']); ?>: <?php echo $migration['ok'] ? 'اجرا شد' : 'ناموفق'; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="done">
                <p class="hint">برای امنیت، پوشه <code>install</code> را حذف کنید.</p>
                <a href="delete_installer.php">حذف نصب‌کننده</a>
            </div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" id="restore-form" autocomplete="off">
                <div class="section-title">حالت نصب</div>
                <div class="modes">
                    <label class="mode-card">
                        <input type="radio" name="install_mode" value="fresh" <?php echo $installMode === 'fresh' ? 'checked' : ''; ?>>
                        <span class="mode-title">🆕 نصب تازه</span>
                        <span class="hint">بدون بکاپ. ساختار دیتابیس از صفر ساخته می‌شود.</span>
                    </label>
                    <label class="mode-card">
                        <input type="radio" name="install_mode" value="restore" <?php echo $installMode === 'restore' ? 'checked' : ''; ?>>
                        <span class="mode-title">♻️ بازیابی بکاپ</span>
                        <span class="hint">با فایل .sql یا .zip از ربات قدیمی.</span>
                    </label>
                </div>

                <div class="section-title">اطلاعات ربات</div>
                <div class="grid">
                    <div class="field full">
                        <label for="bot_token">توکن ربات تلگرام</label>
                        <input type="text" id="bot_token" name="bot_token" value="<?php echo inst_h($formValues['bot_token']); ?>" placeholder="123456:ABC..." required>
                    </div>
                    <div class="field">
                        <label for="admin_id">آیدی عددی ادمین</label>
                        <input type="text" id="admin_id" name="admin_id" value="<?php echo inst_h($formValues['admin_id']); ?>" placeholder="123456789" required>
                    </div>
                    <div class="field">
                        <label for="domain">دامنه ربات</label>
                        <input type="text" id="domain" name="domain" value="<?php echo inst_h($formValues['domain']); ?>" placeholder="bot.example.com" required>
                        <span class="hint">بدون https:// و بدون مسیر</span>
                    </div>
                </div>

                <div class="section-title">اتصال به دیتابیس</div>
                <div class="grid">
                    <div class="field">
                        <label for="db_host">هاست</label>
                        <input type="text" id="db_host" name="db_host" value="<?php echo inst_h($formValues['db_host']); ?>" required>
                    </div>
                    <div class="field">
                        <label for="db_name">نام دیتابیس</label>
                        <input type="text" id="db_name" name="db_name" value="<?php echo inst_h($formValues['db_name']); ?>" required>
                    </div>
                    <div class="field">
                        <label for="db_user">نام کاربری</label>
                        <input type="text" id="db_user" name="db_user" value="<?php echo inst_h($formValues['db_user']); ?>" required>
                    </div>
                    <div class="field">
                        <label for="db_password">رمز عبور</label>
                        <input type="password" id="db_password" name="db_password" value="" required>
                    </div>
                </div>

                <div id="backup-wrap">
                    <div class="section-title">فایل بکاپ</div>
                    <div class="field">
                        <input type="file" id="backup_file" name="backup_file" accept=".sql,.zip">
                        <span class="hint">فایل .sql یا .zip حاوی .sql از دیتابیس ربات قدیمی</span>
                    </div>
                </div>

                <div class="warning">هشدار: تمام جدول‌ها و ویوهای این دیتابیس پاک می‌شوند. این عملیات برگشت‌ناپذیر است.</div>

                <label class="check">
                    <input type="checkbox" id="confirm_wipe" name="confirm_wipe" value="1">
                    <span>متوجه هستم که تمام داده‌های فعلی این دیتابیس پاک می‌شود و این کار برگشت‌پذیر نیست.</span>
                </label>

                <button type="submit" id="restore-btn" name="submit" value="install" class="btn" disabled>شروع نصب</button>
            </form>

            <script>
                (function () {
                    var checkbox = document.getElementById('confirm_wipe');
                    var button = document.getElementById('restore-btn');
                    var backupWrap = document.getElementById('backup-wrap');
                    var backupInput = document.getElementById('backup_file');
                    function mode() {
                        var m = document.querySelector('input[name="install_mode"]:checked');
                        var restore = !!m && m.value === 'restore';
                        backupWrap.style.display = restore ? '' : 'none';
                        backupInput.required = restore;
                        button.textContent = restore ? 'شروع بازیابی' : 'شروع نصب';
                    }
                    function sync() { button.disabled = !checkbox.checked; }
                    checkbox.addEventListener('change', sync);
                    document.querySelectorAll('input[name="install_mode"]').forEach(function (r) { r.addEventListener('change', mode); });
                    mode();
                    sync();
                })();
            </script>
        <?php endif; ?>
    </div>

    <footer class="site">
        <nav>
            <a href="https://github.com/Degeris/DegerisBot">⭐ GitHub</a>
            <a href="https://github.com/Degeris/DegerisBot/issues">🐞 گزارش باگ</a>
            <a href="https://t.me/DegerisStudio">📢 کانال</a>
            <a href="https://t.me/DegerisStudioGap">💬 گروه</a>
        </nav>
        <div>Degeris Bot v1.0.0 · Made with ❤️ by Degeris Studio</div>
    </footer>
</div>
</body>
</html>
