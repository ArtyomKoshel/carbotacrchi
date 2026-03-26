<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            try {
                self::$instance = new PDO(
                    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log('[Database] Connection failed: ' . $e->getMessage());
                throw $e;
            }
        }

        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
}
