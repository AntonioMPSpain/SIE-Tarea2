<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Config;

use PDO;
use PDOException;

class Database
{
    /** Versión de database/schema.sql (PRAGMA user_version). Subirla en cada cambio del esquema. */
    public const SCHEMA_VERSION = 2;

    private static ?PDO $pdo = null;
    private static array $env = [];

    public static function loadEnv(): void
    {
        if (!empty(self::$env)) {
            return;
        }

        $baseDir = dirname(__DIR__);
        $envFile = file_exists($baseDir . '/.env') ? $baseDir . '/.env' : $baseDir . '/.env.example';

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $val] = explode('=', $line, 2);
                    $key = trim($key);
                    $val = trim($val, " \t\n\r\0\x0B\"'");
                    self::$env[$key] = $val;
                }
            }
        }
    }

    public static function env(string $key, mixed $default = null): mixed
    {
        self::loadEnv();
        $sysVal = getenv($key);
        if ($sysVal !== false && $sysVal !== '') {
            return $sysVal;
        }
        return self::$env[$key] ?? $default;
    }

    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        self::loadEnv();
        $driver = strtolower((string) self::env('DB_CONNECTION', 'sqlite'));
        $baseDir = dirname(__DIR__);

        try {
            if ($driver === 'mysql') {
                $host = self::env('DB_HOST', '127.0.0.1');
                $port = self::env('DB_PORT', '3306');
                $db   = self::env('DB_DATABASE', 'piquantum_erp_db');
                $user = self::env('DB_USERNAME', 'root');
                $pass = self::env('DB_PASSWORD', '');
                $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } else {
                $relPath = self::env('DB_SQLITE_PATH', 'storage/database/piquantum_erp.sqlite');
                $fullPath = $baseDir . '/' . ltrim($relPath, '/\\');
                $dir = dirname($fullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                self::$pdo = new PDO('sqlite:' . $fullPath, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$pdo->exec('PRAGMA foreign_keys = ON;');
            }
        } catch (PDOException $e) {
            throw new PDOException('Error conectando a la base de datos del Back-Office: ' . $e->getMessage());
        }

        self::ensureSchema();
        return self::$pdo;
    }

    private static function ensureSchema(): void
    {
        $pdo = self::$pdo;
        // Comprobar si la tabla erp_inventory ya existe
        try {
            $pdo->query('SELECT 1 FROM erp_inventory LIMIT 1');
            // Base de datos ya creada: tiene que ser de la versión de esquema actual
            $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            if ($version !== self::SCHEMA_VERSION) {
                throw new \RuntimeException(
                    "La base de datos del Back-Office tiene un esquema antiguo (versión {$version}; se espera la "
                    . self::SCHEMA_VERSION . '). En desarrollo, borra el archivo .sqlite para que se cree de nuevo.'
                );
            }
        } catch (PDOException) {
            $schemaFile = dirname(__DIR__) . '/database/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
            }
            // Poblar catálogo base de almacén en primera inicialización
            \Piquantum\BackOffice\Database\Seeder::seedInventoryOnly($pdo);
        }
    }
}
