<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// This command only applies additive admin changes to the existing project DB.
require_once __DIR__ . '/../app/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
$pdo = db();
$locked = false;
try {
    $locked = (int) $pdo->query("SELECT GET_LOCK('ala_eh_admin_integration', 10)")->fetchColumn() === 1;
    if (!$locked) throw new RuntimeException('Another admin migration is running.');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_schema_migrations (version VARCHAR(191) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    foreach (glob(__DIR__ . '/../database/admin-migrations/*.sql') as $file) {
        $version = basename($file);
        $check = $pdo->prepare('SELECT 1 FROM admin_schema_migrations WHERE version = ?');
        $check->execute([$version]);
        if ($check->fetchColumn()) continue;
        $pdo->exec((string) file_get_contents($file));
        $pdo->prepare('INSERT INTO admin_schema_migrations (version) VALUES (?)')->execute([$version]);
        echo 'Applied ' . $version . PHP_EOL;
    }
    // Preserve commuter IDs, passwords, and sign-in; the module manages real accounts.
    foreach (['phone' => 'VARCHAR(32) NULL', 'account_status' => "ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active'"] as $column => $definition) {
        $check = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $check->execute(['commuter_accounts', $column]);
        if (!$check->fetchColumn()) $pdo->exec('ALTER TABLE commuter_accounts ADD COLUMN ' . $column . ' ' . $definition);
    }
    echo "Admin database integration is up to date.\n";
} finally {
    if ($locked) $pdo->query("SELECT RELEASE_LOCK('ala_eh_admin_integration')");
}
