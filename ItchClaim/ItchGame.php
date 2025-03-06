<?php

/**
 * ItchGame class representing a game on itch.io
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace ItchClaim;

use DOMElement;
use Exception;

class ItchGame
{
    /** @var string Directory for game data files */
    public static $gamesDir = 'web/data/';

    /** @var int Game ID on itch.io */
    private $id;

    /** @var string|null Game name */
    private $name = null;

    /** @var string|null Game URL */
    private $url = null;

    /** @var float|null Game price */
    private $price = null;

    /** @var array List of sales for this game */
    private $sales = [];

    /** @var string|null Cover image URL */
    private $coverImage = null;

    /** @var bool|null Whether the game is claimable or not */
    private $claimable = null;

    /**
     * Constructor
     *
     * @param int $id Game ID
     */
    public function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * Create an ItchGame instance from a div element
     *
     * @param DOMElement $div HTML div element containing game data
     * @param bool $priceNeeded Whether to fetch price data if not in the div
     * @return ItchGame
     */
    public static function fromDiv($div, $priceNeeded = false)
    {
        $id = (int) $div->getAttribute('data-game_id');
        $game = new self($id);

        $anchor = self::findElement($div, 'a', 'title game_link');
        $game->name = $anchor->textContent;
        $game->url = $anchor->getAttribute('href');

        try {
            $thumb = self::findElement($div, 'div', 'game_thumb');
            $img = $thumb->getElementsByTagName('img')->item(0);
            $game->coverImage = $img->getAttribute('data-lazy_src');
        } catch (Exception $e) {
            $game->coverImage = null;
        }

        $priceElement = self::findElement($div, 'div', 'price_value', true);

        if ($priceElement !== null) {
            preg_match('/[-+]?(?:\d*\.\d+|\d+)/', $priceElement->textContent, $matches);
            $game->price = isset($matches[0]) ? (float) $matches[0] : null;
        } elseif ($priceNeeded) {
            // Some games have no price (always free) but are also discounted and claimable
            $apiData = self::fromApi($game->url);

            if ($apiData !== null && count($apiData->sales) > 0) {
                $game->price = $apiData->price;
            }
        }

        return $game;
    }

    /**
     * Helper function to find elements in a DOMElement
     *
     * @param DOMElement $element The parent element
     * @param string $tagName Tag name to find
     * @param string $className Class name to match
     * @param bool $allowNull Whether to allow null return
     * @return DOMElement|null
     * @throws Exception If element not found and $allowNull is false
     */
    private static function findElement($element, $tagName, $className, $allowNull = false)
    {
        $elements = $element->getElementsByTagName($tagName);

        foreach ($elements as $el) {
            if ($el->getAttribute('class') === $className) {
                return $el;
            }
        }

        if ($allowNull) {
            return null;
        }

        throw new Exception("Could not find $tagName with class $className");
    }

    /**
     * Create an ItchGame instance from the itch.io API
     *
     * @param string $url Game URL
     * @return ItchGame|null
     */
    public static function fromApi($url)
    {
        // Remove trailing slash from URL
        if (substr($url, -1) === '/') {
            $url = substr($url, 0, -1);
        }

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

        $client = new \GuzzleHttp\Client($clientOptions);

        try {
            $response = $client->request('GET', $url . '/data.json');

            $data = json_decode($response->getBody(), true);

            if (isset($data['errors'])) {
                if (in_array($data['errors'][0], ['invalid game', 'invalid user'])) {
                    // Check if the game's URL has been changed
                    $mockGame = new self(-1);
                    $mockGame->url = $url;

                    if ($mockGame->checkRedirectUrl()) {
                        return self::fromApi($mockGame->url);
                    }
                }

                echo "Failed to get game {$url} from API: {$data['errors'][0]}\n";
                return null;
            }

            $gameId = $data['id'];
            $game = new self($gameId);
            $game->url = $url;

            // Check for redirects in the request history
            if ($response->hasHeader('X-Guzzle-Redirect-History')) {
                $history = $response->getHeader('X-Guzzle-Redirect-History');
                if (!empty($history)) {
                    $game->url = str_replace('/data.json', '', $history[0]);
                }
            }

            try {
                $game->price = isset($data['price']) ? (float) substr($data['price'], 1) : null;
            } catch (Exception $e) {
                $game->price = null;
            }

            $game->name = $data['title'];
            $game->coverImage = $data['cover_image'];

            if (isset($data['sale']) && $data['sale']['rate'] === 100) {
                // Create sale object
                $game->sales[] = new ItchSale($data['sale']['id']);
            }

            return $game;
        } catch (Exception $e) {
            echo "Failed to get game from API: " . $e->getMessage() . "\n";
            return null;
        }
    }
    /**
     * Save game details to disk
     *
     * @return void
     */
    public function saveToDisk()
    {
        if (!file_exists(self::$gamesDir)) {
            mkdir(self::$gamesDir, 0755, true);
        }

        file_put_contents(
            $this->getDefaultGameFilename(),
            json_encode($this->serialize(), JSON_PRETTY_PRINT)
        );
    }

    /**
     * Load game details from disk
     *
     * @param string $path File path
     * @param bool $refreshClaimable Whether to check claimability online
     * @return ItchGame
     */
    public static function loadFromDisk($path, $refreshClaimable = false)
    {
        $data = json_decode(file_get_contents($path), true);
        $id = $data['id'];

        $game = new self($id);
        $game->name = $data['name'];
        $game->url = $data['url'];
        $game->price = $data['price'];

        $game->sales = [];
        foreach ($data['sales'] as $saleData) {
            $game->sales[] = ItchSale::fromArray($saleData);
        }

        if ($data['claimable'] !== null && !$refreshClaimable) {
            $game->claimable = $data['claimable'];
        }

        $game->coverImage = $data['cover_image'];

        return $game;
    }

    /**
     * Get the default filename for this game
     *
     * @return string
     */
    public function getDefaultGameFilename()
    {
        return self::$gamesDir . "/{$this->id}.json";
    }

    /**
     * Check if the game is claimable
     *
     * @param bool $forceRefresh Force refreshing the claimable state
     * @return bool|null
     */
    public function isClaimable($forceRefresh = false)
    {
        if ($this->claimable !== null && !$forceRefresh) {
            return $this->claimable;
        }

        if (!$this->getActiveSale()) {
            $this->claimable = null;
            return null;
        }

        $client = new \GuzzleHttp\Client();

        try {
            $response = $client->request('GET', $this->url, ['timeout' => 8]);
            $html = (string) $response->getBody();

            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            $buyRow = $xpath->query('//div[@class="buy_row"]')->item(0);
            if ($buyRow === null) {
                // Game is probably WebGL or HTML5 only
                $this->claimable = false;
                return $this->claimable;
            }

            $buyBox = $xpath->query('.//a[@class="button buy_btn"]', $buyRow)->item(0);
            if ($buyBox === null) {
                // No buy button is visible, so it's probably not claimable
                $this->claimable = false;
                return $this->claimable;
            }

            if (strpos($buyBox->textContent, 'Buy Now') !== false) {
                $this->claimable = null;
                return $this->claimable;
            }

            $this->claimable = (strpos($buyBox->textContent, 'Download or claim') !== false);
            return $this->claimable;
        } catch (Exception $e) {
            echo "Error checking if game is claimable: " . $e->getMessage() . "\n";
            $this->claimable = null;
            return null;
        }
    }

    /**
     * Get the active sale for this game
     *
     * @return ItchSale|null
     */
    public function getActiveSale()
    {
        $activeSales = array_filter($this->sales, function ($sale) {
            return $sale->isActive();
        });

        if (empty($activeSales)) {
            return null;
        }

        usort($activeSales, function ($a, $b) {
            return $a->getEnd() - $b->getEnd();
        });

        return $activeSales[0];
    }

    /**
     * Get the last upcoming sale for this game
     *
     * @return ItchSale|null
     */
    public function getLastUpcomingSale()
    {
        $upcomingSales = array_filter($this->sales, function ($sale) {
            return $sale->isUpcoming();
        });

        if (empty($upcomingSales)) {
            return null;
        }

        usort($upcomingSales, function ($a, $b) {
            return $b->getStart() - $a->getStart();
        });

        return $upcomingSales[0];
    }

    /**
     * Check if this is the first sale for this game
     *
     * @return bool
     */
    public function isFirstSale()
    {
        return count($this->sales) === 1;
    }

    /**
     * Get downloadable files for this game
     *
     * @param \GuzzleHttp\Client|null $session Optional authenticated session
     * @return array
     */
    public function downloadableFiles($session = null)
    {
        if ($session === null) {
            $session = new \GuzzleHttp\Client([
                'headers' => [
                    'User-Agent' => 'ItchClaim ' . ITCHCLAIM_VERSION
                ],
                'cookies' => true
            ]);

            $session->request('GET', 'https://itch.io/');
        }

        try {
            // Get CSRF token from cookies
            $cookieJar = $session->getHandlerStack()->resolve()->getCookieJar();
            $cookies = $cookieJar->toArray();
            $csrfToken = null;

            foreach ($cookies as $cookie) {
                if ($cookie['Name'] === 'itchio_token') {
                    $csrfToken = urldecode($cookie['Value']);
                    break;
                }
            }

            if ($csrfToken === null) {
                throw new Exception("CSRF token not found");
            }

            $response = $session->request('POST', $this->url . '/download_url', [
                'json' => ['csrf_token' => $csrfToken]
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (isset($data['errors'])) {
                echo "ERROR: Failed to get download links for game {$this->name} (url: {$this->url})\n";
                echo "\t{$data['errors'][0]}\n";
                return [];
            }

            $downloadPage = $data['url'];
            $response = $session->request('GET', $downloadPage);
            $html = (string) $response->getBody();

            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            $uploads = [];
            $uploadDivs = $xpath->query('//div[@class="upload"]');

            foreach ($uploadDivs as $div) {
                $uploads[] = $this->parseDownloadDiv($div, $session);
            }

            return $uploads;
        } catch (Exception $e) {
            echo "Error getting download URLs: " . $e->getMessage() . "\n";
            return [];
        }
    }

    /**
     * Parse a download div to extract file details
     *
     * @param DOMElement $div
     * @param \GuzzleHttp\Client $session
     * @return array
     */
    private function parseDownloadDiv($div, $session)
    {
        $xpath = new \DOMXPath($div->ownerDocument);

        // Find ID
        $downloadBtn = $xpath->query('.//a[@class="button download_btn"]', $div)->item(0);
        if ($downloadBtn instanceof \DOMElement) {
            $id = (int) $downloadBtn->getAttribute('data-upload_id');
        } else {
            throw new Exception("Download button element not found");
        }

        // Upload date
        $uploadDateElement = $xpath->query('.//div[@class="upload_date"]/abbr', $div)->item(0);
        if ($uploadDateElement instanceof \DOMElement) {
            $uploadDateRaw = $uploadDateElement->getAttribute('title');
            $uploadDate = \DateTime::createFromFormat('d F Y @ H:i', $uploadDateRaw);
        } else {
            throw new Exception("Upload date element not found");
        }

        // Platforms
        $platforms = [];
        $itchioPlatforms = ['windows8', 'android', 'tux', 'apple'];
        $platformsSpan = $xpath->query('.//span[@class="download_platforms"]', $div)->item(0);

        if ($platformsSpan !== null) {
            foreach ($itchioPlatforms as $platform) {
                $platformIcon = $xpath->query('.//span[@class="icon icon-' . $platform . '"]', $platformsSpan)->item(0);
                if ($platformIcon !== null) {
                    $platforms[] = $platform;
                }
            }
        }

        // Get download URL
        $cookieJar = $session->getHandlerStack()->resolve()->getCookieJar();
        $cookies = $cookieJar->toArray();
        $csrfToken = null;

        foreach ($cookies as $cookie) {
            if ($cookie['Name'] === 'itchio_token') {
                $csrfToken = urldecode($cookie['Value']);
                break;
            }
        }

        $response = $session->request('POST', $this->url . "/file/{$id}", [
            'json' => ['csrf_token' => $csrfToken],
            'query' => ['source' => 'game_download']
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $downloadUrl = $data['url'];

        // Name and file size
        $name = $xpath->query('.//strong[@class="name"]', $div)->item(0)->textContent;
        $fileSizeElement = $xpath->query('.//span[@class="file_size"]', $div)->item(0);
        $fileSize = $fileSizeElement->childNodes->item(0)->textContent;

        return [
            'id' => $id,
            'name' => $name,
            'file_size' => $fileSize,
            'upload_date' => $uploadDate->getTimestamp(),
            'platforms' => $platforms,
            'url' => $downloadUrl,
        ];
    }

    /**
     * Check if a redirect URL is available for the game
     *
     * @return bool True if a new URL is found
     */
    public function checkRedirectUrl()
    {
        $finalUrl = '';

        $client = new \GuzzleHttp\Client([
            'allow_redirects' => ['track_redirects' => true]
        ]);

        try {
            $response = $client->request('HEAD', $this->url, [
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) use (&$finalUrl) {
                    $finalUrl = (string) $stats->getEffectiveUri();
                }
            ]);

            if ($finalUrl === '' || $finalUrl === $this->url) {
                return false;
            }

            $this->url = $finalUrl;
            if (isset($this->claimable)) {
                unset($this->claimable);
            }

            echo "WARN: URL of game {$this->name} has changed to {$this->url}\n";
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Serialize the game object
     *
     * @return array
     */
    public function serialize()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'price' => $this->price,
            'claimable' => $this->claimable,
            'sales' => array_map(function ($sale) {
                return $sale->serialize();
            }, $this->sales),
            'cover_image' => $this->coverImage,
        ];
    }

    /**
     * Serialize with minimal information
     *
     * @return array
     */
    public function serializeMin()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'claimable' => $this->claimable,
            'sales' => array_map(function ($sale) {
                return $sale->serialize();
            }, $this->sales),
        ];
    }

    /**
     * Set the sales list for this game
     *
     * @param array $sales List of ItchSale objects
     * @return void
     */
    public function setSales($sales)
    {
        $this->sales = $sales;
    }

    /**
     * Replace a sale at a specific index
     *
     * @param int $index Index of the sale to replace
     * @param ItchSale $sale New sale object
     * @return void
     */
    public function replaceSale($index, $sale)
    {
        if (isset($this->sales[$index])) {
            $this->sales[$index] = $sale;
        }
    }

    /**
     * Sort sales by ID
     *
     * @return void
     */
    public function sortSales()
    {
        usort($this->sales, function ($a, $b) {
            return $a->getId() - $b->getId();
        });
    }

    /**
     * Get game ID
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get game name
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set game name
     *
     * @param string $name
     * @return void
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Get game URL
     *
     * @return string|null
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Set game URL
     *
     * @param string $url
     * @return void
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * Get game price
     *
     * @return float|null
     */
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Set game price
     *
     * @param float $price
     * @return void
     */
    public function setPrice($price)
    {
        $this->price = $price;
    }

    /**
     * Get game sales
     *
     * @return array
     */
    public function getSales()
    {
        return $this->sales;
    }

    /**
     * Add a sale
     *
     * @param ItchSale $sale
     * @return void
     */
    public function addSale($sale)
    {
        $this->sales[] = $sale;
    }

    /**
     * Set the claimable status of the game
     *
     * @param bool|null $claimable
     * @return void
     */
    public function setClaimable($claimable)
    {
        $this->claimable = $claimable;
    }
}
