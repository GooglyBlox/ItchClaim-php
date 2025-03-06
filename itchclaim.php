<?php

/**
 * ItchClaim - Automatically claim free games from itch.io
 * 
 * @author GooglyBlox
 * @version 1.0
 */

// Set up autoloading
require_once __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function ($class) {
    // Only load our own classes
    if (strpos($class, 'ItchClaim\\') === 0) {
        $path = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});

// Parse command line arguments
$options = getopt("", [
    "version::",
    "login:",
    "password:",
    "totp:",
    "cron::",
    "url:",
    "games_dir:",
    "web_dir:",
    "max_pages:",
    "no_fail::",
    "max_not_found_pages:",
    "dev::",
    "help::"
]);

// Check if no arguments or help is requested
if (empty($options) || isset($options['help'])) {
    echo "ItchClaim - Automatically claim free games from itch.io\n\n";
    echo "Usage: php itchclaim.php [options] <command>\n\n";
    echo "Commands:\n";
    echo "  claim                   Claim all unowned games\n";
    echo "  refresh_library         Refresh the list of owned games\n";
    echo "  refresh_sale_cache      Refresh the cache about game sales\n";
    echo "  download_urls <url>     Get details about a game, including download URLs\n";
    echo "  generate_web            Generates static website files\n";
    echo "  version                 Display the version of the script and exit\n\n";
    echo "Options:\n";
    echo "  --version               Show version information\n";
    echo "  --login <username>      Username or email to log in with\n";
    echo "  --password <password>   Password for login\n";
    echo "  --totp <code>           2FA code or secret\n";
    echo "  --url <url>             URL for game list (default: https://itchclaim.tmbpeter.com/api/active.json)\n";
    echo "  --games_dir <dir>       Directory for game data\n";
    echo "  --web_dir <dir>         Directory for website files\n";
    echo "  --max_pages <num>       Maximum number of pages to download\n";
    echo "  --no_fail               Continue even if errors occur\n";
    echo "  --max_not_found_pages <num>  Maximum consecutive 404 pages before stopping\n";
    echo "  --dev                   Development mode (disables SSL verification)\n";
    echo "  --help                  Show this help message\n";
    exit(0);
}

// Define constants
define('ITCHCLAIM_VERSION', '1.6.0');

// Check for Docker environment
if (getenv('ITCHCLAIM_DOCKER') !== false) {
    define('ITCHCLAIM_DOCKER', true);
} else {
    define('ITCHCLAIM_DOCKER', false);
}

// Check for development mode
define('ITCHCLAIM_DEV_MODE', isset($options['dev']));

// Check for version flag
if (isset($options['version'])) {
    echo ITCHCLAIM_VERSION . "\n";
    exit(0);
}

// Main entry point
use ItchClaim\ItchClaim;

// Get the command from remaining arguments
$command = null;
$args = [];
if (isset($argv) && count($argv) > 1) {
    foreach ($argv as $index => $arg) {
        if ($index === 0) continue;

        // Skip options and their values
        if (substr($arg, 0, 2) === '--') {
            // Skip the next arg if it's an option value
            if ($index < count($argv) - 1 && substr($argv[$index + 1], 0, 2) !== '--') {
                continue;
            }
            continue;
        }

        // If previous arg was an option, skip this one as it's a value
        if (
            $index > 1 && substr($argv[$index - 1], 0, 2) === '--' &&
            !in_array($argv[$index - 1], ['--version', '--no_fail', '--help', '--dev'])
        ) {
            continue;
        }

        // First non-option arg is the command
        if ($command === null) {
            $command = $arg;
        } else {
            // Additional args
            $args[] = $arg;
        }
    }
}

// Initialize ItchClaim
$itchClaim = new ItchClaim();

// Set username from args or environment
$username = $options['login'] ?? getenv('ITCH_USERNAME') ?? null;
$password = $options['password'] ?? getenv('ITCH_PASSWORD') ?? null;
$totp = $options['totp'] ?? getenv('ITCH_TOTP') ?? null;

// Login if username provided
if ($username !== null) {
    $itchClaim->login($username, $password, $totp);
}

// Execute the command
switch ($command) {
    case 'claim':
        $url = $options['url'] ?? 'https://itchclaim.tmbpeter.com/api/active.json';
        $itchClaim->claim($url);
        break;

    case 'refresh_library':
        $itchClaim->refreshLibrary();
        break;

    case 'refresh_sale_cache':
        $gamesDir = $options['games_dir'] ?? 'web/data/';
        $maxPages = isset($options['max_pages']) ? (int)$options['max_pages'] : -1;
        $noFail = isset($options['no_fail']) ? true : false;
        $maxNotFoundPages = isset($options['max_not_found_pages']) ? (int)$options['max_not_found_pages'] : 25;
        $itchClaim->refreshSaleCache($gamesDir, null, $maxPages, $noFail, $maxNotFoundPages);
        break;

    case 'download_urls':
        if (empty($args)) {
            echo "Error: Game URL is required\n";
            exit(1);
        }
        $itchClaim->downloadUrls($args[0]);
        break;

    case 'generate_web':
        $webDir = $options['web_dir'] ?? 'web';
        $itchClaim->generateWeb($webDir);
        break;

    default:
        if ($command !== null) {
            echo "Unknown command: $command\n";
        } else {
            echo "No command specified. Use --help for usage information.\n";
        }
        exit(1);
}
