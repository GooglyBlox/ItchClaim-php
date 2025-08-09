<?php

/**
 * DiskManager class for handling file operations
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace ItchClaim;

use Exception;
use GuzzleHttp\Client;

class DiskManager
{
    /**
     * Download details about every sale posted on itch.io
     *
     * @param int $start The ID of the first sale to download
     * @param int $maxPages The maximum number of pages to download (-1 for unlimited)
     * @param bool $noFail Set to true to continue execution even if a connection error occurs
     * @param int $maxNotFoundPages The maximum number of consecutive pages that return 404
     * @return array
     */
    public static function getAllSales(
        $start,
        $maxPages = -1,
        $noFail = false,
        $maxNotFoundPages = 25
    ) {
        if ($maxPages == -1) {
            $maxPages = 10e7;
        }

        $page = $start - 1;
        $gamesNum = 0;
        $pageNotFoundNum = 0;

        while ($page < $start + $maxPages) {
            $page++;

            usleep(500000);

            try {
                $gamesAdded = self::getOneSale($page, false);

                // If games_added is -1 it means that the sale page returned 404
                if ($gamesAdded == -1) {
                    // Sometimes there are sales even after multiple 404 pages
                    $pageNotFoundNum++;

                    if ($pageNotFoundNum > $maxNotFoundPages) {
                        echo "No more sales available at the moment.\n";
                        break;
                    } else {
                        echo "Sale page {$page} returned 404 without URL redirection. ";
                        echo "Seems like the end of the sales list. ";
                        echo "({$pageNotFoundNum}/{$maxNotFoundPages})\n";
                        continue;
                    }
                } else {
                    $pageNotFoundNum = 0;
                    $gamesNum += $gamesAdded;
                }
            } catch (Exception $e) {
                echo "A connection error has occurred while parsing sale page {$page}. ";
                echo "Reason: " . $e->getMessage() . "\n";
                echo "Aborting current sale refresh.\n";

                if (!$noFail) {
                    exit(1);
                }
            }

            file_put_contents(
                ItchGame::$gamesDir . '/resume_index.txt',
                (string) ($page - $pageNotFoundNum)
            );
        }

        if ($page >= $start + $maxPages) {
            echo "Execution stopped because the maximum number of {$maxPages} pages was reached\n";
        }

        if ($gamesNum == 0) {
            echo "No new free games found\n";
        } else {
            echo "Execution finished. Added a total of {$gamesNum} games\n";
        }
    }

    /**
     * Downloads one sale page, and saves the results to the disk
     *
     * @param int $page The sale_id to be downloaded
     * @param bool $force Set to true if method is not called from refresh_sale_cache
     * @return int The number of games saved (-1 for 404, 0 for error)
     */
    public static function getOneSale($page, $force = true)
    {
        $gamesNum = 0;
        $currentSale = new ItchSale($page);

        if ($currentSale->getError() === 'NO_MORE_SALES_AVAILABLE' && $currentSale->getId() > 90000) {
            // Return -1 if it seems like we have reached the last sale
            return -1;
        } elseif ($currentSale->getError()) {
            return 0;
        }

        // Parse the HTML content
        $html = $currentSale->getHtml();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $gamesRaw = $xpath->query('//div[@class="game_cell"]');

        if ($gamesRaw->length == 0) {
            echo "Sale page #{$page}: empty page\n";
            return 0;
        }

        foreach ($gamesRaw as $div) {
            $game = ItchGame::fromDiv($div, true);

            if ($game->getPrice() != 0) {
                echo "Sale page #{$page}: games are not discounted by 100%\n";
                break;
            }

            // If the sale is not active, we can't check if it's claimable
            if (!$currentSale->isActive()) {
                $game->setClaimable(null);
            }

            // Load previously saved sales
            $gameFilename = $game->getDefaultGameFilename();
            if (file_exists($gameFilename)) {
                $diskGame = ItchGame::loadFromDisk($gameFilename, true);
                $game->setSales($diskGame->getSales());

                $lastSale = end($game->getSales());
                if ($lastSale && $lastSale->getId() == $page && !$force) {
                    echo "Sale {$page} has been already saved for game {$game->getName()} (wrong resume index?)\n";
                    continue;
                }
            }

            if (!$force) {
                $game->addSale($currentSale);
            } else {
                $saleAlreadyExists = false;

                foreach ($game->getSales() as $i => $sale) {
                    if ($sale->getId() == $page) {
                        $saleAlreadyExists = true;
                        $game->replaceSale($i, $currentSale);
                        echo "Sale page {$page}: Updated values for game {$game->getName()} ({$game->getId()})\n";
                        break;
                    }
                }

                if (!$saleAlreadyExists) {
                    $game->addSale($currentSale);
                    // Sort sales by ID
                    $game->sortSales();
                }
            }

            $gamesNum++;
            $game->saveToDisk();
        }

        if ($game->getPrice() == 0) {
            $expiredStr = $currentSale->isActive() ? '' : '(inactive)';
            echo "Sale page #{$page}: added {$gamesRaw->length} games {$expiredStr}\n";
        }

        return $gamesNum;
    }

    /**
     * Gets all the pages of the sales feed from itch.io, and saves the missing games
     *
     * @param string $category The category of the items
     * @param bool $noFail Set to true to continue execution even if a connection error occurs
     * @return array
     */
    public static function getAllSalePages($category = 'games', $noFail = false)
    {
        $page = 0;
        $gamesNum = 0;

        while (true) {
            $page++;

            try {
                $gamesAdded = self::getOnlineSalePage($page, $category);

                if ($gamesAdded == -1) {
                    break;
                } else {
                    $gamesNum += $gamesAdded;
                }
            } catch (Exception $e) {
                echo "A connection error has occurred while parsing {$category} sale page {$page}. ";
                echo "Reason: " . $e->getMessage() . "\n";
                echo "Aborting current sale refresh.\n";

                if (!$noFail) {
                    exit(1);
                }
            }
        }

        echo "Collecting sales from category {$category} finished. ";
        echo "Added a total of {$gamesNum} {$category}\n";
    }

    /**
     * Get a page of the sales feed from itch.io, and save the missing ones to the disk
     *
     * @param int $page The id of the page to load
     * @param string $category The category of the items
     * @return int The number of games updated (-1 if end of list)
     */
    public static function getOnlineSalePage($page, $category = 'games')
    {
        echo "Processing {$category} sale page #{$page}\n";

        $clientOptions = [
            'headers' => [
                'User-Agent' => 'ItchClaim ' . ITCHCLAIM_VERSION
            ],
            'timeout' => 8
        ];

        // Disable SSL verification in dev mode
        if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
            $clientOptions['verify'] = false;
        }

        $client = new Client($clientOptions);

        try {
            $response = $client->request('GET', "https://itch.io/{$category}/newest/on-sale?page={$page}&format=json");

            if ($response->getStatusCode() == 404) {
                echo "Page returned 404.\n";
                return -1;
            }

            $responseData = json_decode((string) $response->getBody(), true);
            $html = $responseData['content'];

            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            $gamesRaw = $xpath->query('//div[@class="game_cell"]');
            $gamesAdded = 0;

            foreach ($gamesRaw as $div) {
                $game = ItchGame::fromDiv($div, true);

                if ($game->getPrice() == 0) {
                    // Save game if it's new to us
                    $gameFilename = $game->getDefaultGameFilename();

                    if (!file_exists($gameFilename)) {
                        // Call API to get active sale
                        $apiGame = ItchGame::fromApi($game->getUrl());

                        if ($apiGame) {
                            $apiGame->saveToDisk();
                            echo "Saved new {$category} {$game->getName()} ({$game->getUrl()})\n";
                            $gamesAdded++;
                        }

                        continue;
                    }

                    // load previously saved sales
                    $diskGame = ItchGame::loadFromDisk($gameFilename, true);

                    if ($diskGame->getActiveSale()) {
                        echo "Skipping {$category} {$game->getName()} ({$game->getUrl()}): already active sale found on disk\n";
                        continue;
                    }

                    // Call API to get active sale
                    $apiGame = ItchGame::fromApi($game->getUrl());

                    if ($apiGame && $apiGame->getActiveSale()) {
                        $diskGame->addSale($apiGame->getActiveSale());
                        $diskGame->sortSales();
                        $diskGame->saveToDisk();
                        echo "Updated values for {$category} {$game->getName()} ({$game->getUrl()})\n";
                        $gamesAdded++;
                    }
                }
            }

            if ($gamesRaw->length == 0 && $responseData['num_items'] == 0) {
                return -1;
            }

            return $gamesAdded;
        } catch (Exception $e) {
            echo "Error getting sale page: " . $e->getMessage() . "\n";
            return 0;
        }
    }

    /**
     * Load all games cached on the disk
     *
     * @return array
     */
    public static function loadAllGames()
    {
        $games = [];

        if (!file_exists(ItchGame::$gamesDir)) {
            return $games;
        }

        $files = scandir(ItchGame::$gamesDir);

        foreach ($files as $file) {
            if (substr($file, -5) !== '.json') {
                continue;
            }

            $path = ItchGame::$gamesDir . '/' . $file;
            $games[] = ItchGame::loadFromDisk($path);
        }

        return $games;
    }

    /**
     * Download game data from remote cache
     *
     * @param string $url URL to download from
     * @return array
     */
    public static function downloadFromRemoteCache($url)
    {
        $clientOptions = [
            'timeout' => 8
        ];

        // Disable SSL verification in dev mode
        if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
            $clientOptions['verify'] = false;
        }

        $client = new \GuzzleHttp\Client($clientOptions);

        try {
            $response = $client->request('GET', $url);
            $gamesRaw = json_decode((string) $response->getBody(), true);
            $games = [];

            foreach ($gamesRaw as $gameJson) {
                $game = new ItchGame($gameJson['id']);
                $game->setUrl($gameJson['url']);
                $game->setName($gameJson['name']);
                $game->setClaimable($gameJson['claimable']);
                $games[] = $game;
            }

            return $games;
        } catch (Exception $e) {
            echo "Error downloading from remote cache: " . $e->getMessage() . "\n";
            return [];
        }
    }
}
