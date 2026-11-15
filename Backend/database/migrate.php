
<?php

if (PHP_SAPI !== 'cli') {
    exit("Script hanya boleh dijalankan melalui terminal.\n");
}

require __DIR__ . '/../includes/db.php';

/** @var PDO $pdo */

function ensure_tracking_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(255) PRIMARY KEY,
            ran_at TIMESTAMPTZ NOT NULL DEFAULT now()
        )"
    );
}

function run_folder(PDO $pdo, string $type): void
{
    $dir = __DIR__ . '/' . $type;

    if (!is_dir($dir)) {
        exit("Folder tidak ditemukan: {$dir}\n");
    }

    $files = glob($dir . '/*.sql');
    sort($files);

    foreach ($files as $path) {
        $name = $type . '/' . basename($path);

        $check = $pdo->prepare(
            'SELECT 1 FROM schema_migrations WHERE filename = ?'
        );
        $check->execute([$name]);

        if ($check->fetchColumn()) {
            echo "SKIP   {$name}\n";
            continue;
        }

        try {
            $pdo->beginTransaction();

            $sql = file_get_contents($path);

            if ($sql === false) {
                throw new RuntimeException(
                    "Gagal membaca file {$name}"
                );
            }

            $pdo->exec($sql);

            $log = $pdo->prepare(
                'INSERT INTO schema_migrations (filename) VALUES (?)'
            );
            $log->execute([$name]);

            $pdo->commit();

            echo "OK     {$name}\n";

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            exit(
                "GAGAL  {$name}\n" .
                $e->getMessage() . "\n"
            );
        }
    }
}

function confirm_fresh(): void
{
    if (env('APP_ENV') !== 'local') {
        exit(
            "Perintah fresh hanya boleh dijalankan " .
            "jika APP_ENV=local.\n"
        );
    }

    $host = env('DB_HOST', 'unknown');
    $database = env('DB_NAME', 'unknown');

    echo "\nPERINGATAN: DATABASE AKAN DI-RESET!\n";
    echo "Host     : {$host}\n";
    echo "Database : {$database}\n";
    echo "Semua data users, booking, rental, payment, "
       . "dan device akan hilang.\n";

    echo "\nKetik RESET DATABASE untuk melanjutkan: ";

    $confirmation = trim(
        fgets(STDIN) ?: ''
    );

    if ($confirmation !== 'RESET DATABASE') {
        exit("Dibatalkan. Database tidak diubah.\n");
    }
}

function fresh_database(
    PDO $pdo,
    bool $withDemo
): void {
    confirm_fresh();

    $sql = file_get_contents(__DIR__ . '/reset.sql');

    if ($sql === false) {
        exit("File reset.sql tidak ditemukan.\n");
    }

    $pdo->exec($sql);
    $pdo->exec(
        'DROP TABLE IF EXISTS schema_migrations CASCADE'
    );

    echo "Semua tabel lama telah dihapus.\n";

    ensure_tracking_table($pdo);

    run_folder($pdo, 'migrations');
    run_folder($pdo, 'seeders');

    if ($withDemo) {
        run_folder($pdo, 'demo_seeders');
    }
}

$command = $argv[1] ?? 'migrations';

switch ($command) {
    case 'migrations':
        ensure_tracking_table($pdo);
        run_folder($pdo, 'migrations');
        break;

    case 'seeders':
        ensure_tracking_table($pdo);
        run_folder($pdo, 'seeders');
        break;

    case 'fresh':
        fresh_database($pdo, false);
        break;

    case 'fresh-demo':
        fresh_database($pdo, true);
        break;

    default:
        exit(
            "Perintah tidak dikenal.\n" .
            "Gunakan: migrations | seeders | " .
            "fresh | fresh-demo\n"
        );
}

echo "Selesai.\n";
