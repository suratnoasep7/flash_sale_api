<?php

namespace App\Database;

use PDO;
use PDOException;

/**
 * Database – PDO singleton.
 *
 * Returns a single shared PDO connection for the lifetime of the request.
 * Throws a RuntimeException on connection failure so the caller can return
 * a proper 500 response.
 */
class Database
{
    private static ?PDO $instance = null;

    /** Prevent direct instantiation. */
    private function __construct() {}

    /**
     * Returns the shared PDO instance, creating it on first call.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $cfg = require __DIR__ . '/../../config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['name'],
                $cfg['charset']
            );

            try {
                self::$instance = new PDO($dsn, $cfg['user'], $cfg['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }
}
