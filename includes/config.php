<?php
/**
 * Application configuration.
 * Values are read from environment variables so deployment (AlwaysData) only needs
 * to define those variables without touching the source code.
 */

// Database
define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost');
define('DB_PORT', getenv('DB_PORT') !== false ? getenv('DB_PORT') : '5432');
define('DB_NAME', getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'qui_est_ce');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : 'postgres');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// Application
define('BASE_URL',   rtrim(getenv('BASE_URL')   !== false ? getenv('BASE_URL')   : 'http://localhost', '/'));
define('MAIL_FROM',  getenv('MAIL_FROM')  !== false ? getenv('MAIL_FROM')  : 'noreply@qui-est-ce.local');
define('MAIL_FROM_NAME', 'Qui Est-Ce ?');
