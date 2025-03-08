<?php

/**
 * Main ItchClaim class handling all major functions
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace ItchClaim;

use ItchClaim\ItchUser;
use ItchClaim\ItchGame;
use ItchClaim\DiskManager;

class ItchClaim
{
    /** @var ItchUser|null */
    private $user = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize
    }

    /**
     * Login to itch.io
     *
     * @param string $username Username or email
     * @param string|null $password Password (optional if session exists)
     * @param string|null $totp TOTP code or secret (optional)
     * @return ItchUser Logged in user
     */
    public function login($username, $password = null, $totp = null)
    {
        $this->user = new ItchUser($username);

        try {
            $this->user->loadSession();
            echo "Session $username loaded successfully\n";
        } catch (\Exception $e) {
            // Try loading password from environment variables if not provided
            if ($password === null) {
                $password = getenv('ITCH_PASSWORD');
            }

            // Try loading TOTP from environment variables if not provided
            if ($totp === null) {
                $totp = getenv('ITCH_TOTP');
            }

            $this->user->login($password, $totp);
            echo "Logged in as $username\n";
        }

        return $this->user;
    }

    /**
     * Claim all unowned games
     *
     * @param string $url URL to download the file from
     * @return array Statistics about the claim process
     */
    public function claim($url = 'https://itchclaim.tmbpeter.com/api/active.json')
    {
        if ($this->user === null) {
            echo "You must be logged in\n";
            return [
                'claimed' => 0,
                'already_owned' => 0,
                'errors' => 1,
                'not_claimable' => 0,
                'failed_claims' => 0,
                'total_games' => 0,
                'claimed_games' => []
            ];
        }

        if (count($this->user->getOwnedGames()) === 0) {
            echo "User's library not found in cache. Downloading it now\n";
            $this->user->reloadOwnedGames();
            $this->user->saveSession();
        }

        echo "Downloading free games list from $url\n";
        $games = DiskManager::downloadFromRemoteCache($url);

        echo "Found " . count($games) . " games in list\n";

        echo "Claiming games\n";
        $claimedCount = 0;
        $alreadyOwnedCount = 0;
        $errorCount = 0;
        $notClaimableCount = 0;
        $failedClaimCount = 0;
        $claimedGames = [];

        foreach ($games as $game) {
            echo "Processing game: {$game->getName()} ({$game->getUrl()})\n";

            // Check if already owned first
            if ($this->user->ownsGame($game)) {
                echo "Game already owned\n";
                $alreadyOwnedCount++;
                continue;
            }

            // Check if claimable
            $claimable = $game->isClaimable();

            if ($claimable === true) {
                $claimResult = $this->user->claimGame($game);
                if ($claimResult) {
                    $this->user->saveSession();
                    $claimedCount++;
                    $claimedGames[] = [
                        'id' => $game->getId(),
                        'name' => $game->getName(),
                        'url' => $game->getUrl()
                    ];
                } else {
                    $failedClaimCount++;
                }
            } elseif ($claimable === false) {
                echo "Game is not claimable\n";
                $notClaimableCount++;
            } else {
                echo "Claimability unknown, skipping\n";
                $errorCount++;
            }
        }

        // Print summary
        echo "Summary:\n";
        echo "- Games found: " . count($games) . "\n";
        echo "- Games claimed: $claimedCount\n";
        echo "- Games already owned: $alreadyOwnedCount\n";
        echo "- Games not claimable: $notClaimableCount\n";
        echo "- Claim attempts failed: $failedClaimCount\n";
        echo "- Errors encountered: $errorCount\n";

        if ($claimedCount === 0) {
            echo "No new games can be claimed.\n";
        }

        // Return statistics
        return [
            'claimed' => $claimedCount,
            'already_owned' => $alreadyOwnedCount,
            'errors' => $errorCount,
            'not_claimable' => $notClaimableCount,
            'failed_claims' => $failedClaimCount,
            'total_games' => count($games),
            'claimed_games' => $claimedGames
        ];
    }

    /**
     * Refresh the list of owned games
     *
     * @return void
     */
    public function refreshLibrary()
    {
        if ($this->user === null) {
            echo "You must be logged in\n";
            return;
        }

        $this->user->reloadOwnedGames();
        $this->user->saveSession();
    }

    /**
     * Refresh the cache about game sales
     *
     * @param string $gamesDir Output directory
     * @param array|null $sales Only refresh the sales specified in this list
     * @param int $maxPages Maximum number of pages to download (-1 for unlimited)
     * @param bool $noFail Continue downloading sales even if a page fails to load
     * @param int $maxNotFoundPages Maximum number of consecutive pages that return 404
     * @return void
     */
    public function refreshSaleCache(
        $gamesDir = 'web/data/',
        $sales = null,
        $maxPages = -1,
        $noFail = false,
        $maxNotFoundPages = 25
    ) {
        $resume = 1;
        ItchGame::$gamesDir = $gamesDir;

        if (!file_exists($gamesDir)) {
            mkdir($gamesDir, 0755, true);
        }

        if ($sales) {
            echo "--sales flag found - refreshing only select sale pages\n";
            foreach ($sales as $saleId) {
                DiskManager::getOneSale($saleId);
            }
            return;
        }

        try {
            $resumeIndexPath = $gamesDir . '/resume_index.txt';
            if (file_exists($resumeIndexPath)) {
                $resume = (int) file_get_contents($resumeIndexPath);
                echo "Resuming sale downloads from $resume\n";
            } else {
                echo "Resume index not found. Downloading sales from beginning\n";
            }
        } catch (\Exception $e) {
            echo "Resume index not found. Downloading sales from beginning\n";
        }

        DiskManager::getAllSales(
            $resume,
            $maxPages,
            $noFail,
            $maxNotFoundPages
        );

        echo "Updating games from sale lists, to catch updates of already known sales.\n";

        $categories = [
            'games',
            'tools',
            'game-assets',
            'comics',
            'books',
            'physical-games',
            'soundtracks',
            'game-mods',
            'misc'
        ];

        foreach ($categories as $category) {
            echo "Collecting sales from $category list\n";
            DiskManager::getAllSalePages($category, $noFail);
        }
    }

    /**
     * Get details about a game, including download URLs
     *
     * @param string $gameUrl URL of the game
     * @return void
     */
    public function downloadUrls($gameUrl)
    {
        $game = ItchGame::fromApi($gameUrl);
        $session = $this->user ? $this->user->getSession() : null;
        echo json_encode($game->downloadableFiles($session), JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Generates files that can be served as a static website
     *
     * @param string $webDir Output directory
     * @return void
     */
    public function generateWeb($webDir = 'web')
    {
        ItchGame::$gamesDir = $webDir . '/data';

        if (!file_exists($webDir . '/api')) {
            mkdir($webDir . '/api', 0755, true);
        }

        if (!file_exists(ItchGame::$gamesDir)) {
            mkdir(ItchGame::$gamesDir, 0755, true);
        }

        $games = DiskManager::loadAllGames();
        Web::generateWeb($games, $webDir);
    }
}
