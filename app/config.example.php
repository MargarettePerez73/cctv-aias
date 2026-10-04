<?php
declare(strict_types=1);

// Copy to app/config.php to change these values. config.php is ignored by git.
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'cctv_media',
    'username' => 'root',
    'password' => '',
    'session_name' => 'cctv_session',
    'timezone' => 'Asia/Manila',
    // Recordings, snapshots and relay frames. Protected by storage/.htaccess and served only through api/.
    'storage_path' => dirname(__DIR__) . '/storage',
    // Cloud copy: a folder that Google Drive / OneDrive for desktop syncs, e.g. 'G:/My Drive/CCTV'.
    // Every snapshot and recording is also copied there, named by date and time. Empty = local only.
    'cloud_sync_path' => '',
];
