<?php

/**
 * ItchUser class representing a user on itch.io
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace ItchClaim;

use Exception;
use OTPHP\TOTP;

class ItchUser
{
    /** @var mixed cURL handle */
    private $ch;

    /** @var string Username */
    private $username;

    /** @var array Owned games */
    private $ownedGames = [];

    /** @var string|null User ID */
    private $userId = null;

    /** @var string Cookie file path */
    private $cookieFile;

    /**
     * Constructor
     *
     * @param string $username Username
     */
    public function __construct($username)
    {
        $this->username = $username;

        // Set up the cookie file
        $userDir = self::getUsersDir();
        if (!file_exists($userDir)) {
            mkdir($userDir, 0755, true);
        }

        $this->cookieFile = $userDir . '/cookies-' . preg_replace('/\W/', '_', $username) . '.txt';

        // We'll initialize cURL in each method to avoid any potential issues
    }

    /**
     * Log in to itch.io
     *
     * @param string|null $password Password
     * @param string|null $totp TOTP code or secret
     * @return void
     */
    public function login($password = null, $totp = null)
    {
        // Initialize a fresh cURL session for login
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'ItchClaim PHP ' . ITCHCLAIM_VERSION
        ];

        // Disable SSL verification in dev mode
        if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $curlOptions);

        // Step 1: Visit login page to get CSRF token
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

        if ($password === null) {
            echo "Enter password for user {$this->username}: ";
            system('stty -echo');
            $password = trim(fgets(STDIN));
            system('stty echo');
            echo "\n";
        }

        // Step 2: Submit login form
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://itch.io/login',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $csrfToken,
                'username' => $this->username,
                'password' => $password,
                'tz' => -120
            ])
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("Error during login: " . curl_error($ch));
        }

        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        // Check for errors
        if (preg_match('/<div class=[\'"]form_errors[\'"].*?<li>(.*?)<\/li>/s', $response, $matches)) {
            curl_close($ch);
            throw new Exception("Error while logging in: " . $matches[1]);
        }

        // Check if 2FA is needed
        if (strpos($finalUrl, 'totp/') !== false) {
            // Parse user ID from HTML
            if (preg_match('/<input.*?name="user_id".*?value="(.*?)"/s', $response, $matches)) {
                $this->userId = $matches[1];
            } else {
                curl_close($ch);
                throw new Exception("Could not find user ID in 2FA page");
            }

            if ($totp === null) {
                echo "Enter 2FA code: ";
                $totp = trim(fgets(STDIN));
            }

            // If TOTP is a secret (longer than 6 chars), generate code
            if (strlen($totp) != 6) {
                $totpSecret = $totp;
                $otp = TOTP::create($totpSecret);
                $totp = $otp->now();
            }

            // Send TOTP code
            curl_setopt_array($ch, [
                CURLOPT_URL => $finalUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'csrf_token' => $csrfToken,
                    'userid' => $this->userId,
                    'code' => (int) $totp
                ])
            ]);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                curl_close($ch);
                throw new Exception("Error sending TOTP: " . curl_error($ch));
            }

            // Check for errors
            if (preg_match('/<div class=[\'"]form_errors[\'"].*?<li>(.*?)<\/li>/s', $response, $matches)) {
                curl_close($ch);
                throw new Exception("Error with 2FA: " . $matches[1]);
            }
        }

        curl_close($ch);
        $this->saveSession();
    }

    /**
     * Save session to disk
     *
     * @return void
     */
    public function saveSession()
    {
        $userDir = self::getUsersDir();
        if (!file_exists($userDir)) {
            mkdir($userDir, 0755, true);
        }

        // Get CSRF token from cookies file
        $csrfToken = null;
        if (file_exists($this->cookieFile)) {
            $content = file_get_contents($this->cookieFile);
            if (preg_match('/itchio_token\s+([^\s]+)/', $content, $matches)) {
                $csrfToken = urldecode($matches[1]);
            }
        }

        $data = [
            'csrf_token' => $csrfToken,
            'cookie_file' => $this->cookieFile,
            'owned_games' => array_map(function ($game) {
                return $game->getId();
            }, $this->ownedGames)
        ];

        file_put_contents($this->getDefaultSessionFilename(), json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Load session from disk
     *
     * @return void
     * @throws Exception if session file not found
     */
    public function loadSession()
    {
        $sessionFile = $this->getDefaultSessionFilename();

        if (!file_exists($sessionFile)) {
            throw new Exception("Session file not found");
        }

        $data = json_decode(file_get_contents($sessionFile), true);

        // If cookie file path is stored, use it
        if (isset($data['cookie_file']) && file_exists($data['cookie_file'])) {
            $this->cookieFile = $data['cookie_file'];
        }

        // Verify session is valid
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'ItchClaim PHP ' . ITCHCLAIM_VERSION,
            CURLOPT_URL => 'https://itch.io/my-feed'
        ];

        // Disable SSL verification in dev mode
        if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $curlOptions);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($code !== 200 || strpos($url, 'login') !== false) {
            throw new Exception("Session expired");
        }

        // Load owned games
        if (isset($data['owned_games'])) {
            $this->ownedGames = array_map(function ($id) {
                return new ItchGame($id);
            }, $data['owned_games']);
        }
    }

    /**
     * Get the default session filename
     *
     * @return string
     */
    private function getDefaultSessionFilename()
    {
        $safeUsername = preg_replace('/\W/', '_', $this->username);
        $sessionFilename = "session-{$safeUsername}.json";
        return self::getUsersDir() . "/{$sessionFilename}";
    }

    /**
     * Check if user owns a game
     *
     * @param ItchGame $game Game to check
     * @return bool
     */
    public function ownsGame($game)
    {
        foreach ($this->ownedGames as $ownedGame) {
            if ($game->getId() === $ownedGame->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check online if user owns a game
     *
     * @param ItchGame $game Game to check
     * @return bool
     */
    public function ownsGameOnline($game)
    {
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'ItchClaim PHP ' . ITCHCLAIM_VERSION,
            CURLOPT_URL => $game->getUrl()
        ];

        // Disable SSL verification in dev mode
        if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $success = !curl_errno($ch);
        curl_close($ch);

        if (!$success) {
            return false;
        }

        // Check for ownership element on the page
        $hasOwnership = strpos($response, 'ownership_reason') !== false;

        // Log ownership check result for debugging
        echo $hasOwnership ? "Ownership verified for {$game->getName()}\n" : "No ownership found for {$game->getName()}\n";

        return $hasOwnership;
    }

    /**
     * Claim a game
     *
     * @param ItchGame $game Game to claim
     * @return bool True if claimed successfully
     */
    public function claimGame($game)
    {
        try {
            // Get CSRF token from cookies file
            $csrfToken = null;
            if (file_exists($this->cookieFile)) {
                $content = file_get_contents($this->cookieFile);
                if (preg_match('/itchio_token\s+([^\s]+)/', $content, $matches)) {
                    $csrfToken = urldecode($matches[1]);
                }
            }

            if (!$csrfToken) {
                throw new Exception("No CSRF token found in cookies");
            }

            // Step 1: Get download URL
            $ch = curl_init();
            $curlOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_COOKIEJAR => $this->cookieFile,
                CURLOPT_COOKIEFILE => $this->cookieFile,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'ItchClaim PHP ' . ITCHCLAIM_VERSION,
                CURLOPT_URL => $game->getUrl() . '/download_url',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['csrf_token' => $csrfToken]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json']
            ];

            // Disable SSL verification in dev mode
            if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
                $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
                $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
            }

            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                curl_close($ch);
                throw new Exception("Error getting download URL: " . curl_error($ch));
            }

            $data = json_decode($response, true);

            if (isset($data['errors'])) {
                curl_close($ch);
                if (in_array($data['errors'][0], ['invalid game', 'invalid user'])) {
                    if ($game->checkRedirectUrl()) {
                        return $this->claimGame($game);
                    }
                }

                echo "Error: {$data['errors'][0]}\n";
                return false;
            }

            if (!isset($data['url'])) {
                curl_close($ch);
                echo "No download URL found\n";
                return false;
            }

            // Step 2: Access download page
            curl_setopt_array($ch, [
                CURLOPT_URL => $data['url'],
                CURLOPT_POST => false,
                CURLOPT_HTTPHEADER => []
            ]);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                curl_close($ch);
                throw new Exception("Error accessing download page: " . curl_error($ch));
            }

            // Step 3: Check if game is claimable
            if (strpos($response, 'claim_to_download_box') === false) {
                curl_close($ch);
                echo "Game is not claimable\n";
                return false;
            }

            echo "Game is claimable\n";

            // Step 4: Extract claim form URL
            $dom = new \DOMDocument();
            @$dom->loadHTML($response);
            $xpath = new \DOMXPath($dom);

            $claimBoxes = $xpath->query('//div[contains(@class, "claim_to_download_box")]');
            if ($claimBoxes->length === 0) {
                curl_close($ch);
                echo "Could not find claim box\n";
                return false;
            }

            $claimBox = $claimBoxes->item(0);
            $forms = $xpath->query('.//form', $claimBox);

            if ($forms->length === 0) {
                curl_close($ch);
                echo "Could not find claim form in claim box\n";
                return false;
            }

            $form = $forms->item(0);
            if (!($form instanceof \DOMElement)) {
                curl_close($ch);
                echo "Form node is not a DOMElement\n";
                return false;
            }

            $claimUrl = $form->getAttribute('action');
            echo "Claiming game via $claimUrl\n";

            // Step 5: Submit claim
            curl_setopt_array($ch, [
                CURLOPT_URL => $claimUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $csrfToken]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
            ]);

            curl_exec($ch);

            if (curl_errno($ch)) {
                curl_close($ch);
                throw new Exception("Error claiming game: " . curl_error($ch));
            }

            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            // Step 6: Verify ownership
            if ($finalUrl === 'https://itch.io/') {
                // We got redirected to the homepage, which typically means the claim failed
                // Double-check if we own it anyway (might have been claimed previously)
                if ($this->ownsGameOnline($game)) {
                    $this->ownedGames[] = $game;
                    echo "Game already owned (verified online)\n";
                    return true;
                } else {
                    echo "Claim failed - redirected to homepage\n";
                    return false;
                }
            } else {
                // Not redirected to homepage, likely success
                // Add game to owned list and verify
                $this->ownedGames[] = $game;

                // Double-check ownership to be sure
                if ($this->ownsGameOnline($game)) {
                    echo "Game claimed successfully (ownership verified)\n";
                } else {
                    echo "Game claimed but ownership verification failed - please check manually\n";
                }
                return true;
            }
        } catch (Exception $e) {
            echo "Error claiming game: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Get one page of the user's library
     *
     * @param int $page Page number
     * @return array
     */
    public function getOneLibraryPage($page)
    {
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'ItchClaim PHP ' . ITCHCLAIM_VERSION,
            CURLOPT_URL => "https://itch.io/my-purchases?page={$page}&format=json"
        ];

        // Disable SSL verification in dev mode
        if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $success = !curl_errno($ch);
        curl_close($ch);

        if (!$success) {
            return [];
        }

        $data = json_decode($response, true);

        if (!isset($data['content'])) {
            return [];
        }

        $html = $data['content'];

        // Extract game IDs
        $games = [];
        if (preg_match_all('/data-game_id="(\d+)"/', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $games[] = new ItchGame((int)$id);
            }
        }

        return $games;
    }

    /**
     * Reload the cache of the user's library
     *
     * @return void
     */
    public function reloadOwnedGames()
    {
        $this->ownedGames = [];

        for ($i = 1; $i < PHP_INT_MAX; $i++) {
            $page = $this->getOneLibraryPage($i);

            if (empty($page)) {
                break;
            }

            $this->ownedGames = array_merge($this->ownedGames, $page);
            echo "Library page #{$i}: added " . count($page) . " games (total: " . count($this->ownedGames) . ")\n";
        }
    }

    /**
     * Get the users directory
     *
     * @return string
     */
    public static function getUsersDir()
    {
        if (defined('ITCHCLAIM_DOCKER') && ITCHCLAIM_DOCKER) {
            return '/data/';
        }

        $os = PHP_OS;

        if (strtoupper(substr($os, 0, 3)) === 'WIN') {
            $localAppData = getenv('LOCALAPPDATA');

            if ($localAppData !== false) {
                return $localAppData . DIRECTORY_SEPARATOR . 'ItchClaim' . DIRECTORY_SEPARATOR . 'users';
            }

            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.itchclaim' . DIRECTORY_SEPARATOR . 'users';
        }

        $xdgConfigHome = getenv('XDG_CONFIG_HOME');

        if ($xdgConfigHome !== false) {
            return $xdgConfigHome . DIRECTORY_SEPARATOR . 'itchclaim' . DIRECTORY_SEPARATOR . 'users';
        }

        return getenv('HOME') . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'itchclaim' . DIRECTORY_SEPARATOR . 'users';
    }

    /**
     * Get the HTTP client session
     *
     * @return mixed
     */
    public function getSession()
    {
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'ItchClaim PHP ' . ITCHCLAIM_VERSION
        ];

        // Disable SSL verification in dev mode
        if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $curlOptions);

        return $ch;
    }

    /**
     * Get the owned games
     *
     * @return array
     */
    public function getOwnedGames()
    {
        return $this->ownedGames;
    }
}
