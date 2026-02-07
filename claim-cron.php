<?php

/**
 * ItchClaim Cron Script
 * 
 * @author GooglyBlox
 * @version 1.1
 */

define('ITCHCLAIM_VERSION', '1.9.2');

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

// Discord webhook configuration
$discordWebhookUrl = getenv('DISCORD_WEBHOOK_URL') ?: '';
$discordNotifyMode = getenv('DISCORD_NOTIFY_MODE') ?: 'claimed'; // 'always' or 'claimed'
$discordUsername = getenv('DISCORD_USERNAME') ?: 'ItchClaim Bot';
$discordAvatarUrl = getenv('DISCORD_AVATAR_URL') ?: '';
$discordEmbedColor = getenv('DISCORD_EMBED_COLOR') ?: '7289DA'; // Default Discord blurple color

file_put_contents($logFile, "\n\n" . date('Y-m-d H:i:s') . " - ItchClaim cron job started\n", FILE_APPEND);

/**
 * Log a message to both console and log file
 * 
 * @param string $message The message to log
 * @return void
 */
function log_message($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
    echo "$timestamp - $message\n";
}

/**
 * Send a message to Discord webhook
 * 
 * @param string $content The main message content
 * @param array $embeds Optional embeds for the webhook
 * @return bool Success status
 */
function send_discord_notification($content, $embeds = [])
{
    global $discordWebhookUrl, $discordUsername, $discordAvatarUrl;

    if (empty($discordWebhookUrl)) {
        return false;
    }

    $data = [
        'content' => $content,
        'username' => $discordUsername,
    ];

    if (!empty($discordAvatarUrl)) {
        $data['avatar_url'] = $discordAvatarUrl;
    }

    if (!empty($embeds)) {
        $data['embeds'] = $embeds;
    }

    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($discordWebhookUrl, false, $context);

    if ($result === false) {
        log_message("ERROR: Failed to send Discord notification");
        return false;
    }

    log_message("Discord notification sent successfully");
    return true;
}

if (empty($username) || empty($password)) {
    $errorMsg = "ERROR: Missing username or password. Please set ITCH_USERNAME and ITCH_PASSWORD environment variables.";
    log_message($errorMsg);

    if ($discordWebhookUrl && $discordNotifyMode === 'always') {
        send_discord_notification('', [
            [
                'title' => 'ItchClaim Error',
                'description' => $errorMsg,
                'color' => hexdec('FF0000'),
                'timestamp' => date('c')
            ]
        ]);
    }

    exit(1);
}

$usersDir = ItchClaim\ItchUser::getUsersDir();
if (!file_exists($usersDir)) {
    log_message("Creating users directory: $usersDir");
    if (!mkdir($usersDir, 0755, true)) {
        $errorMsg = "ERROR: Failed to create users directory at $usersDir";
        log_message($errorMsg);

        if ($discordWebhookUrl && $discordNotifyMode === 'always') {
            send_discord_notification('', [
                [
                    'title' => 'ItchClaim Error',
                    'description' => $errorMsg,
                    'color' => hexdec('FF0000'),
                    'timestamp' => date('c')
                ]
            ]);
        }

        exit(1);
    }
}

try {
    $itchClaim = new ItchClaim\ItchClaim();

    log_message("Starting login process for $username");
    $itchClaim->login($username, $password, $totp);

    log_message("Starting claim process");
    $claimResult = $itchClaim->claim();

    $claimedCount = $claimResult['claimed'] ?? 0;
    $alreadyOwnedCount = $claimResult['already_owned'] ?? 0;
    $errorCount = $claimResult['errors'] ?? 0;
    $notClaimableCount = $claimResult['not_claimable'] ?? 0;
    $failedClaimCount = $claimResult['failed_claims'] ?? 0;
    $claimedGames = $claimResult['claimed_games'] ?? [];

    log_message("ItchClaim cron job completed successfully");

    // Send Discord notification based on notification mode
    if ($discordWebhookUrl) {
        // Only send notification if games were claimed or if notify mode is 'always'
        if ($discordNotifyMode === 'always' || ($discordNotifyMode === 'claimed' && $claimedCount > 0)) {
            $embed = [
                'title' => 'ItchClaim Run Summary',
                'color' => hexdec($discordEmbedColor),
                'timestamp' => date('c'),
                'fields' => [
                    [
                        'name' => 'Status',
                        'value' => 'Success',
                        'inline' => true
                    ],
                    [
                        'name' => 'Games Claimed',
                        'value' => $claimedCount,
                        'inline' => true
                    ],
                    [
                        'name' => 'Already Owned',
                        'value' => $alreadyOwnedCount,
                        'inline' => true
                    ],
                    [
                        'name' => 'Not Claimable',
                        'value' => $notClaimableCount,
                        'inline' => true
                    ],
                    [
                        'name' => 'Failed Claims',
                        'value' => $failedClaimCount,
                        'inline' => true
                    ],
                    [
                        'name' => 'Errors',
                        'value' => $errorCount,
                        'inline' => true
                    ]
                ],
                'footer' => [
                    'text' => 'ItchClaim v' . ITCHCLAIM_VERSION
                ]
            ];

            // Add claimed games to the embed if any were claimed
            if ($claimedCount > 0 && !empty($claimedGames)) {
                $gamesText = "";
                foreach ($claimedGames as $game) {
                    $gamesText .= "• [{$game['name']}]({$game['url']})\n";
                }

                $embed['fields'][] = [
                    'name' => 'Newly Claimed Games',
                    'value' => $gamesText
                ];
            }

            send_discord_notification('', [$embed]);
        }
    }
} catch (Exception $e) {
    $errorMsg = "ERROR: " . $e->getMessage();
    log_message($errorMsg);
    log_message("Stack trace: " . $e->getTraceAsString());

    if ($discordWebhookUrl && $discordNotifyMode === 'always') {
        send_discord_notification('', [
            [
                'title' => 'ItchClaim Error',
                'description' => $errorMsg,
                'color' => hexdec('FF0000'),
                'timestamp' => date('c'),
                'footer' => [
                    'text' => 'ItchClaim v' . ITCHCLAIM_VERSION
                ]
            ]
        ]);
    }
}
