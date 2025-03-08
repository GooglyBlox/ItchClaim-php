<?php

/**
 * ItchClaim Cron Script
 * 
 * @author GooglyBlox
 * @version 1.0
 */

define('ITCHCLAIM_VERSION', '1.6.0');

// Include Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Set up autoloading for our classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'ItchClaim\\') === 0) {
        $path = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});

// Configuration
$username = getenv('ITCH_USERNAME') ?: '';
$password = getenv('ITCH_PASSWORD') ?: '';
$totp = getenv('ITCH_TOTP') ?: null; // This won't really work as intended, given the whole point of TOTP is to be time-based. But it's here for completeness.
$logFile = __DIR__ . '/itchclaim-log.txt';

file_put_contents($logFile, "\n\n" . date('Y-m-d H:i:s') . " - ItchClaim cron job started\n", FILE_APPEND);

function log_message($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
    echo "$timestamp - $message\n";
}

if (empty($username) || empty($password)) {
    log_message("ERROR: Missing username or password. Please set ITCH_USERNAME and ITCH_PASSWORD environment variables.");
    exit(1);
}

$usersDir = ItchClaim\ItchUser::getUsersDir();
if (!file_exists($usersDir)) {
    log_message("Creating users directory: $usersDir");
    if (!mkdir($usersDir, 0755, true)) {
        log_message("ERROR: Failed to create users directory at $usersDir");
        exit(1);
    }
}

try {
    $itchClaim = new ItchClaim\ItchClaim();
    
    log_message("Starting login process for $username");
    $itchClaim->login($username, $password, $totp);
    
    log_message("Starting claim process");
    $itchClaim->claim();
    
    log_message("ItchClaim cron job completed successfully");
} catch (Exception $e) {
    log_message("ERROR: " . $e->getMessage());
    log_message("Stack trace: " . $e->getTraceAsString());
}