<?php

/**
 * SAGANSA — Setup Super-Admin untuk services/api-ops
 * ===================================================
 *
 * Script ini membuat role "super-admin" (guard=api) dan men-assign-nya
 * ke user tertentu. Berguna ketika seeder belum dijalankan atau untuk
 * setup di production via SSH.
 *
 * CARA PAKAI (jalankan dari folder services/api-ops):
 *
 *   # Email default (dari .env SUPER_ADMIN_EMAIL atau asapanganbangsa@gmail.com)
 *   php setup-superadmin.php
 *
 *   # Email custom
 *   php setup-superadmin.php user@contoh.com
 *
 *   # Tanpa argumen — tampilkan daftar user yang ada (dry-run)
 *   php setup-superadmin.php --list
 *
 * Tidak butuh dependency Laravel — cukup extension PDO MySQL.
 * Membaca konfigurasi DB dari .env secara manual.
 */

// ----------------------------------------------------------------------------
// 1. Parse arguments
// ----------------------------------------------------------------------------
$options = getopt('', ['list'], $rest);
$email = $argv[$rest] ?? null;
$dryRunList = isset($options['list']);

// ----------------------------------------------------------------------------
// 2. Load .env (manual parse, no framework dependency)
// ----------------------------------------------------------------------------
$envPath = __DIR__ . '/.env';
if (! file_exists($envPath)) {
    fwrite(STDERR, "ERROR: File .env tidak ditemukan di {$envPath}\n");
    fwrite(STDERR, "Jalankan script ini dari folder services/api-ops.\n");
    exit(1);
}

$env = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (! str_contains($line, '=')) {
        continue;
    }
    [$key, $val] = explode('=', $line, 2);
    $key = trim($key);
    $val = trim($val);
    // Strip surrounding quotes
    if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
        || (str_starts_with($val, "'") && str_ends_with($val, "'"))
    ) {
        $val = substr($val, 1, -1);
    }
    $env[$key] = $val;
}

// ----------------------------------------------------------------------------
// 3. Resolve DB credentials (auth DB = sagansa_user)
// ----------------------------------------------------------------------------
$host = $env['DB_AUTH_HOST'] ?? $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_AUTH_PORT'] ?? $env['DB_PORT'] ?? '3306';
// Auth DB (tempat tabel users, roles, model_has_roles)
$dbAuthName = $env['DB_AUTH_DATABASE'] ?? $env['DB_USER_DATABASE'] ?? 'sagansa_user';
$dbUser = $env['DB_AUTH_USERNAME'] ?? $env['DB_USERNAME'] ?? 'root';
$dbPass = $env['DB_AUTH_PASSWORD'] ?? $env['DB_PASSWORD'] ?? '';
// Ops DB (untuk cek tenant)
$dbOpsName = $env['DB_DATABASE'] ?? $env['DB_OPS_DATABASE'] ?? 'sagansa_ops';

// Default email dari env atau fallback
$defaultEmail = $env['SUPER_ADMIN_EMAIL'] ?? 'asapanganbangsa@gmail.com';

echo "SAGANSA — Setup Super-Admin (services/api-ops)\n";
echo str_repeat('=', 50) . "\n";
echo "Auth DB : {$dbAuthName} @ {$host}:{$port}\n";
echo "Ops DB  : {$dbOpsName}\n";

if ($dryRunList) {
    $email = $email ?: $defaultEmail;
    echo "\n[DRY RUN] Daftar user di auth DB:\n";
    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbAuthName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $rows = $pdo->query("SELECT id, uuid, name, email FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $marker = ($row['email'] === $email) ? ' <== target' : '';
            printf("  id=%-4s email=%-40s name=%s%s\n", $row['id'], $row['email'], $row['name'], $marker);
        }
        echo "\nTotal: " . count($rows) . " user\n";
        exit(0);
    } catch (PDOException $e) {
        fwrite(STDERR, "ERROR koneksi DB: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Resolve target email
$targetEmail = $email ?: $defaultEmail;
echo "Target  : {$targetEmail}\n\n";

// ----------------------------------------------------------------------------
// 4. Connect to auth DB
// ----------------------------------------------------------------------------
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbAuthName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR koneksi DB gagal: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Cek DB_HOST, DB_USERNAME, DB_PASSWORD di .env\n");
    exit(1);
}

// ----------------------------------------------------------------------------
// 5. Find user
// ----------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT id, uuid, name, email FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$targetEmail]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (! $user) {
    fwrite(STDERR, "ERROR: User dengan email '{$targetEmail}' tidak ditemukan di {$dbAuthName}.users\n");
    fwrite(STDERR, "Jalankan: php setup-superadmin.php --list  untuk melihat user yang ada.\n");
    exit(1);
}

echo "User ditemukan:\n";
printf("  id=%s  uuid=%s\n  name=%s  email=%s\n\n",
    $user['id'], $user['uuid'], $user['name'], $user['email']);

// ----------------------------------------------------------------------------
// 6. Cek role 'super-admin' (guard=api)
// ----------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'super-admin' AND guard_name = 'api' LIMIT 1");
$stmt->execute();
$role = $stmt->fetch(PDO::FETCH_ASSOC);

if (! $role) {
    // Buat role
    $pdo->exec("INSERT INTO roles (name, guard_name, created_at, updated_at) VALUES ('super-admin', 'api', NOW(), NOW())");
    $roleId = $pdo->lastInsertId();
    echo "✓ Role 'super-admin' (guard=api) DIBUAT — id={$roleId}\n";
} else {
    $roleId = $role['id'];
    echo "✓ Role 'super-admin' (guard=api) sudah ada — id={$roleId}\n";
}

// ----------------------------------------------------------------------------
// 7. Assign role ke user (jika belum)
// ----------------------------------------------------------------------------
$modelType = 'App\\Models\\User';
$stmt = $pdo->prepare('SELECT 1 FROM model_has_roles WHERE role_id = ? AND model_id = ? AND model_type = ? LIMIT 1');
$stmt->execute([$roleId, $user['id'], $modelType]);

if ($stmt->fetch()) {
    echo "✓ User sudah punya role 'super-admin' — tidak ada perubahan\n";
} else {
    $stmt = $pdo->prepare('INSERT INTO model_has_roles (role_id, model_id, model_type) VALUES (?, ?, ?)');
    $stmt->execute([$roleId, $user['id'], $modelType]);
    echo "✓ Role 'super-admin' DITETAPKAN ke user {$user['email']}\n";
}

// ----------------------------------------------------------------------------
// 8. Verifikasi (query yang sama persis dengan AuthController::login)
// ----------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT 1 FROM model_has_roles mhr
     JOIN roles r ON mhr.role_id = r.id
     WHERE mhr.model_id = ? AND mhr.model_type = ? AND r.name = 'super-admin'
     LIMIT 1"
);
$stmt->execute([$user['id'], $modelType]);
$isSuperAdmin = (bool) $stmt->fetch();

echo "\n" . str_repeat('=', 50) . "\n";
if ($isSuperAdmin) {
    echo "✅ BERHASIL — User {$user['email']} sekarang super-admin.\n";
    echo "   Login ke apps/ops akan langsung masuk dashboard (tanpa ditanya tenant).\n";
} else {
    echo "❌ GAGAL — Role tidak ter-assign dengan benar. Cek tabel model_has_roles.\n";
    exit(1);
}

// ----------------------------------------------------------------------------
// 9. Info tambahan
// ----------------------------------------------------------------------------
echo "\nCatatan: Super-admin adalah role cross-tenant (platform-level).\n";
echo "User ini tetap bisa mengelola semua tenant tanpa perlu tenant_id.\n";

echo "\nSelesai.\n";
exit(0);
