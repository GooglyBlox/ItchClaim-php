<?php

/**
 * ItchClaim Cron Script
 * 
 * @author GooglyBlox
 * @version 1.0
 */

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
$username = ''; // Your itch.io username or email
$password = ''; // Your itch.io password
$logFile = __DIR__ . '/itchclaim-log.txt';
$cookieFile = __DIR__ . '/itchclaim-cookies.txt';
$sessionDir = __DIR__ . '/sessions';
$requestDelay = 1; // Delay between requests in seconds
$maxRetries = 3;   // Maximum retries for 429 errors

// Initialize log
file_put_contents($logFile, "\n\n" . date('Y-m-d H:i:s') . " - ItchClaim cron job started\n", FILE_APPEND);

// Helper function for logging
function log_message($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
    echo "$timestamp - $message\n";
}

// Make sure session directory exists
if (!file_exists($sessionDir)) {
    mkdir($sessionDir, 0755, true);
    log_message("Created sessions directory");
}

// We'll implement a direct cURL approach for more control
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'ItchClaim PHP 1.0');

try {
    // Start login process
    log_message("Starting login process for $username");

    // Get login page to retrieve CSRF token
    curl_setopt($ch, CURLOPT_URL, 'https://itch.io/login');
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception("Error accessing login page: " . curl_error($ch));
    }

    // Extract CSRF token
    if (!preg_match('/<input[^>]*name="csrf_token"[^>]*value="([^"]*)"/', $response, $matches)) {
        throw new Exception("Failed to extract CSRF token from login page");
    }

    $csrfToken = $matches[1];
    log_message("CSRF token extracted successfully");

    // Submit login form
    curl_setopt($ch, CURLOPT_URL, 'https://itch.io/login');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'csrf_token' => $csrfToken,
        'username' => $username,
        'password' => $password,
        'tz' => -120
    ]));

    $loginResponse = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception("Error during login: " . curl_error($ch));
    }

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    // Check login result
    if (strpos($loginResponse, 'form_errors') !== false) {
        if (preg_match('/<div class=[\'"]form_errors[\'"].*?<li>(.*?)<\/li>/s', $loginResponse, $errorMatches)) {
            throw new Exception("Login error: " . $errorMatches[1]);
        } else {
            throw new Exception("Unknown login error");
        }
    } elseif (strpos($finalUrl, '/totp/') !== false) {
        throw new Exception("2FA required but not implemented in this script");
    } elseif ($finalUrl !== 'https://itch.io/' && strpos($finalUrl, '/my-feed') === false) {
        throw new Exception("Login unsuccessful. Redirected to: $finalUrl");
    }

    log_message("Login successful");

    // Download list of free games
    log_message("Downloading list of free games");
    curl_setopt($ch, CURLOPT_URL, 'https://itchclaim.tmbpeter.com/api/active.json');
    curl_setopt($ch, CURLOPT_POST, false);
    $gamesResponse = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception("Error downloading games list: " . curl_error($ch));
    }

    $games = json_decode($gamesResponse, true);
    if (!is_array($games)) {
        throw new Exception("Invalid games list format");
    }

    log_message("Found " . count($games) . " games in list");

    // Load owned games to avoid claiming already owned games
    $ownedGames = [];

    // Try to fetch user's library first page to identify owned games
    curl_setopt($ch, CURLOPT_URL, 'https://itch.io/my-purchases?page=1&format=json');
    curl_setopt($ch, CURLOPT_POST, false);
    $response = curl_exec($ch);

    if (!curl_errno($ch)) {
        $library = json_decode($response, true);
        if (isset($library['content'])) {
            preg_match_all('/data-game_id="(\d+)"/', $library['content'], $matches);
            if (!empty($matches[1])) {
                $ownedGames = array_flip($matches[1]);
                log_message("Loaded " . count($ownedGames) . " owned games from library");
            }
        }
    }

    // Process each game
    $claimedCount = 0;
    $alreadyOwnedCount = 0;
    $errorCount = 0;
    $notClaimableCount = 0;
    $skippedCount = 0;

    foreach ($games as $index => $game) {
        log_message("Processing game: {$game['name']} ({$game['url']})");

        // Add delay between requests to avoid rate limiting
        if ($index > 0) {
            sleep($requestDelay);
        }

        // Check if already owned
        if (isset($ownedGames[$game['id']])) {
            log_message("Game already owned");
            $alreadyOwnedCount++;
            continue;
        }

        // Only process games marked as claimable
        if ($game['claimable'] !== true) {
            log_message("Game is not claimable");
            $notClaimableCount++;
            continue;
        }

        // Get download URL
        $retries = 0;
        $success = false;

        while ($retries <= $maxRetries && !$success) {
            curl_setopt($ch, CURLOPT_URL, $game['url'] . '/download_url');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['csrf_token' => $csrfToken]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $downloadResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode === 429) {
                $retries++;
                $waitTime = pow(2, $retries);
                log_message("Rate limited. Waiting $waitTime seconds before retry #$retries...");
                sleep($waitTime);
                continue;
            }

            if (curl_errno($ch)) {
                log_message("Error getting download URL: " . curl_error($ch));
                $errorCount++;
                break;
            }

            $downloadData = json_decode($downloadResponse, true);

            if (isset($downloadData['errors'])) {
                log_message("Error: {$downloadData['errors'][0]}");
                $errorCount++;
                break;
            }

            if (!isset($downloadData['url'])) {
                log_message("No download URL found");
                $errorCount++;
                break;
            }

            $success = true;
        }

        if (!$success) {
            if ($retries > $maxRetries) {
                log_message("Too many rate limits, skipping for now");
                $skippedCount++;
            }
            continue;
        }

        // Access download page
        curl_setopt($ch, CURLOPT_URL, $downloadData['url']);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
        $downloadPageResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 429) {
            log_message("Rate limited on download page. Skipping for now.");
            $skippedCount++;
            continue;
        }

        if (curl_errno($ch)) {
            log_message("Error accessing download page: " . curl_error($ch));
            $errorCount++;
            continue;
        }

        // Check if game is claimable
        if (strpos($downloadPageResponse, 'claim_to_download_box') !== false) {
            log_message("Game is claimable");

            if (!preg_match('/<div[^>]*class="[^"]*claim_to_download_box[^"]*".*?<form[^>]*action="([^"]*)".*?<\/form>/s', $downloadPageResponse, $formMatches)) {
                log_message("Could not find claim form within claim box");
                $errorCount++;
                continue;
            }

            $claimUrl = $formMatches[1];
            log_message("Claiming game via $claimUrl");

            // Submit claim
            curl_setopt($ch, CURLOPT_URL, $claimUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['csrf_token' => $csrfToken]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

            $claimResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

            if ($httpCode === 429) {
                log_message("Rate limited on claim submission. Skipping for now.");
                $skippedCount++;
                continue;
            }

            if (curl_errno($ch)) {
                log_message("Error claiming game: " . curl_error($ch));
                $errorCount++;
                continue;
            }

            $claimedCount++;
            log_message("Game claimed successfully");

            // Let's be nice to itch.io and wait a bit longer after a successful claim
            sleep($requestDelay * 2);
        } else {
            if (strpos($downloadPageResponse, 'ownership_reason') !== false) {
                log_message("Game already owned");
                $alreadyOwnedCount++;
            } else {
                log_message("Game is not claimable");
                $notClaimableCount++;
            }
        }
    }

    // Log summary
    log_message("Summary:");
    log_message("- Games found: " . count($games));
    log_message("- Games claimed: $claimedCount");
    log_message("- Games already owned: $alreadyOwnedCount");
    log_message("- Games not claimable: $notClaimableCount");
    log_message("- Games skipped (rate limits): $skippedCount");
    log_message("- Errors encountered: $errorCount");
} catch (Exception $e) {
    log_message("ERROR: " . $e->getMessage());
}

// Clean up
curl_close($ch);
log_message("ItchClaim cron job completed");
