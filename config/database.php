<?php

/**
 * Database configuration
 * Values can be overridden via environment variables.
 */
return [
    'host'     => getenv('DB_HOST')     ?: 'localhost',
    'port'     => getenv('DB_PORT')     ?: '3306',
    'name'     => getenv('DB_NAME')     ?: 'flash_sale',
    'user'     => getenv('DB_USER')     ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset'  => 'utf8mb4',
];
