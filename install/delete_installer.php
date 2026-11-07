<?php

declare(strict_types=1);

ignore_user_abort(true);

@set_time_limit(60);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseDir      = __DIR__;
$selfPath     = __FILE__;
$redirectUrl  = '../index.php'; 

$filesToDelete = [
    $baseDir . '/index.php',
    $baseDir . '/style.css',
];

$dirsToDelete = [
    $baseDir . '/fonts',
    $baseDir . '/img',
];

$deleteErrors = [];

function rrmdir_safe(string $dir, array &$errors): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = @scandir($dir);
    if ($items === false) {
        $errors[] = 'امکان خواندن پوشه وجود ندارد: ' . basename($dir);
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            rrmdir_safe($path, $errors);
        } else {
            if ((is_file($path) || is_link($path)) && !@unlink($path)) {
                $errors[] = 'امکان حذف فایل زیر وجود نداشت: ' . $path;
            }
        }
    }

    if (!@rmdir($dir)) {
        $errors[] = 'امکان حذف پوشه زیر وجود نداشت: ' . $dir;
    }
}

function perform_cleanup(array $files, array $dirs, string $baseDir, array &$errors): void
{
    if (!is_writable($baseDir)) {
        $errors[] = 'پوشه نصب‌کننده برای PHP قابل نوشتن نیست: ' . $baseDir
                  . ' . روی سرور اوبونتو / PHP-FPM احتمالاً باید مالکیت (chown) یا سطح دسترسی (chmod) را اصلاح کنید.';
        return;
    }

    foreach ($files as $file) {
        if (is_file($file)) {
            if (!@unlink($file)) {
                $errors[] = 'امکان حذف فایل زیر وجود نداشت: ' . $file;
            }
        }
    }

    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            rrmdir_safe($dir, $errors);
        }
    }
}

function schedule_self_delete(string $selfPath): void
{
    register_shutdown_function(static function () use ($selfPath): void {
        $dir = dirname($selfPath);
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        if (is_file($selfPath) && is_writable($selfPath)) {
            @unlink($selfPath);
        }
    });
}

if (empty($_SESSION['installer_delete_token'])) {
    $_SESSION['installer_delete_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['installer_delete_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_delete']) && $_POST['do_delete'] === '1'
) {

    $postedToken = $_POST['token'] ?? '';
    if (!hash_equals($csrfToken, $postedToken)) {
        http_response_code(400);
        echo 'درخواست نامعتبر است. لطفاً صفحه را مجدداً بارگذاری کرده و دوباره تلاش کنید.';
        exit;
    }

    perform_cleanup($filesToDelete, $dirsToDelete, $baseDir, $deleteErrors);

    schedule_self_delete($selfPath);

    if (empty($deleteErrors)) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>پاکسازی فایل‌های نصب</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #2b5876 0%, #4e4376 100%);

            --card-bg: rgba(16, 26, 38, 0.65);
            --text-color: #ecf5f7;
            --btn-primary: linear-gradient(120deg, #1d9fbf, #477adb);
            --btn-secondary: rgba(255, 255, 255, 0.08);
            --danger-bg: rgba(202, 60, 78, 0.15);
            --danger-text: #ffc7ce;
            --success-bg: rgba(46, 134, 171, 0.15);
            --success-text: #d1f1ff;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        @font-face {
            font-family: 'AradInstaller';
            src: url('./fonts/AradFD-ExtraBold.ttf') format('truetype');
            font-weight: 800;
            font-display: swap;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: 'AradInstaller', 'Tahoma', sans-serif;
            background: #03060a;
            background-image: 
                radial-gradient(circle at 10% 20%, #1a3c4b 0%, transparent 40%),
                radial-gradient(circle at 90% 10%, #553775 0%, transparent 40%),
                radial-gradient(circle at 50% 90%, #17404a 0%, transparent 50%);
            color: var(--text-color);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.05'/%3E%3C/svg%3E");
            opacity: 0.1;
            pointer-events: none;
            z-index: -1;
        }

        .container {
            width: 100%;
            max-width: 600px;
            padding: 20px;
            position: relative;
            z-index: 10;
        }

        .installer-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.15); 
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);

            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            text-align: center;
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header-content h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            background: linear-gradient(to right, #fff, #a5b4fc);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .header-content p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.95rem;
            margin-bottom: 30px;
        }

        .alert {
            text-align: right;
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            line-height: 1.6;
            border: 1px solid rgba(255,255,255,0.05);
            backdrop-filter: blur(5px); 
        }

        .alert-danger {
            background: var(--danger-bg);
            color: var(--danger-text);
            border-color: rgba(202, 60, 78, 0.3);
        }

        .alert-success {
            background: var(--success-bg);
            color: var(--success-text);
            border-color: rgba(92, 184, 208, 0.3);
        }

        .error-list {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 10px;
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .action-wrapper {
            margin-top: 30px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn {
            padding: 14px 24px;
            border-radius: 14px;
            border: none;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background: var(--btn-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(29, 159, 191, 0.3);
            flex: 2;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(29, 159, 191, 0.4);
        }

        .btn-secondary {
            background: var(--btn-secondary);
            color: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            flex: 1;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.12);
            color: white;
        }

        @media (max-width: 768px) {
            body {
                align-items: flex-start;
                padding: 0;
                display: block;
            }

            .container {
                max-width: 100%;
                padding: 0;
            }

            .mobile-header-bg {
                background: linear-gradient(135deg, #14334b 0%, #4f2c6d 100%);
                padding: 60px 20px 80px;
                border-bottom-left-radius: 40px;
                border-bottom-right-radius: 40px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            .mobile-header-bg::before, .mobile-header-bg::after {
                content: '';
                position: absolute;
                border-radius: 50%;
                background: rgba(255,255,255,0.05);
            }
            .mobile-header-bg::before { width: 150px; height: 150px; top: -20px; right: -20px; }
            .mobile-header-bg::after { width: 100px; height: 100px; bottom: 20px; left: -10px; }

            .installer-card {
                margin: -50px 20px 40px;
                border-radius: 20px;
                padding: 25px;
                backdrop-filter: blur(35px); 

                background: rgba(16, 26, 38, 0.80);
            }

            .header-content h1 {
                font-size: 1.5rem;
            }

            .action-buttons {
                flex-direction: column;
                gap: 12px;
            }

            .btn {
                width: 100%;
                padding: 16px;
            }
        }

        .is-hidden { display: none; }
    </style>
</head>
<body>

<div class="container">
    <div class="mobile-header-bg" aria-hidden="true">
        <div style="opacity: 0.8; font-size: 0.9rem; margin-bottom: 5px;">مرحله پایانی</div>
        <h2 style="margin: 0; color: white;">پاکسازی نصب‌کننده</h2>
    </div>

    <div class="installer-card">
        <div class="header-content">
            <h1 class="desktop-only-title">عملیات پاکسازی</h1>
            <p>با حذف فایل‌های نصب، امنیت ربات  خود را تضمین کنید.</p>
        </div>

        <?php if (!empty($deleteErrors)): ?>
            <div class="alert alert-danger">
                <strong>خطا در حذف خودکار!</strong>
                <div class="error-list">
                    <?php foreach ($deleteErrors as $err): ?>
                        <span>• <?php echo htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                    <?php endforeach; ?>
                </div>
                <p style="margin-top: 10px; opacity: 0.8;">لطفاً موارد بالا را به صورت دستی حذف کنید.</p>
            </div>
        <?php endif; ?>

        <div class="alert alert-success">
            <strong>نصب با موفقیت انجام شد!</strong>
            <br>
            آیا مایل هستید فایل‌های نصب‌کننده (Installer) همین حالا حذف شوند؟
        </div>

        <div class="action-wrapper">
            <div class="action-buttons">
                <button type="button" class="btn btn-primary" onclick="confirmDelete()">
                    بله، فایل‌ها پاک شوند
                </button>

                <button type="button" class="btn btn-secondary"
                        onclick="location.href='<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>'">
                    خیر، انتقال به سایت
                </button>
            </div>
        </div>

        <noscript>
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1);">
                <form method="post" action="">
                    <input type="hidden" name="do_delete" value="1">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-primary" style="width: 100%">حذف فایل‌ها (نسخه بدون جاوااسکریپت)</button>
                </form>
            </div>
        </noscript>

        <form id="deleteForm" method="post" action="" class="is-hidden">
            <input type="hidden" name="do_delete" value="1">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </form>
    </div>
</div>

<script>
    function confirmDelete() {
        if (confirm('آیا مطمئن هستید؟ فایل‌های نصب‌کننده و این صفحه برای همیشه حذف خواهند شد.')) {
            var form = document.getElementById('deleteForm');
            if (form) form.submit();
        }
    }

    const updateLayout = () => {
        const isMobile = window.innerWidth <= 768;
        const desktopTitle = document.querySelector('.desktop-only-title');
        const mobileHeader = document.querySelector('.mobile-header-bg');

        if (isMobile) {
            if(desktopTitle) desktopTitle.style.display = 'none';
            if(mobileHeader) mobileHeader.style.display = 'block';
        } else {
            if(desktopTitle) desktopTitle.style.display = 'block';
            if(mobileHeader) mobileHeader.style.display = 'none';
        }
    };

    window.addEventListener('resize', updateLayout);
    window.addEventListener('load', updateLayout);
</script>

</body>
</html>
