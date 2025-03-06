<?php

/**
 * ItchSale class representing a sale on itch.io
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace ItchClaim;

use DateTime;
use Exception;

class ItchSale
{
    /** @var int Sale ID */
    private $id;

    /** @var int|null End timestamp */
    private $end = null;

    /** @var int|null Start timestamp */
    private $start = null;

    /** @var string|null Error message */
    private $err = null;

    /** @var string|null HTML content of the sale page */
    private $html = null;

    /**
     * Constructor
     *
     * @param int $id Sale ID
     * @param int|null $end End timestamp
     * @param int|null $start Start timestamp
     */
    public function __construct($id, $end = null, $start = null)
    {
        $this->id = $id;
        $this->end = $end;
        $this->start = $start;

        if ($end === null || $start === null) {
            $this->getDataOnline();
        }
    }

    /**
     * Get sale data from itch.io
     *
     * @return void
     */
    private function getDataOnline()
    {
        $saleUrl = "https://itch.io/s/{$this->id}";
        $finalUrl = '';

        try {
            $clientOptions = [
                'headers' => [
                    'User-Agent' => 'ItchClaim ' . ITCHCLAIM_VERSION,
                    'Accept-Language' => 'en-GB,en;q=0.9'
                ],
                'timeout' => 8,
                'allow_redirects' => true,
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) use (&$finalUrl) {
                    $finalUrl = (string) $stats->getEffectiveUri();
                }
            ];

            // Disable SSL verification in dev mode
            if (defined('ITCHCLAIM_DEV_MODE') && ITCHCLAIM_DEV_MODE) {
                $clientOptions['verify'] = false;
            }

            $client = new \GuzzleHttp\Client($clientOptions);
            $response = $client->request('GET', $saleUrl);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                if ($finalUrl === $saleUrl) {
                    $this->err = 'NO_MORE_SALES_AVAILABLE';
                } else {
                    echo "Sale page #{$this->id}: 404 Not Found\n";
                    $this->err = '404_NOT_FOUND';
                }
                return;
            }

            $html = (string) $response->getBody();
            $this->html = $html;

            // Extract sale data from script tags
            if (preg_match('/init_Sale.+, (.+)\);i/', $html, $matches)) {
                $saleData = json_decode($matches[1], true);

                $dateFormat = 'Y-m-d\TH:i:s\Z';
                $this->start = DateTime::createFromFormat($dateFormat, $saleData['start_date'])->getTimestamp();
                $this->end = DateTime::createFromFormat($dateFormat, $saleData['end_date'])->getTimestamp();

                if ($this->id != $saleData['id']) {
                    throw new Exception("Sale ID mismatch in parsed script tag. Expected {$this->id}");
                }
            } else {
                throw new Exception("Could not parse sale data from HTML");
            }
        } catch (Exception $e) {
            echo "Error getting sale data: " . $e->getMessage() . "\n";
            $this->err = 'ERROR_FETCHING_SALE';
        }
    }

    /**
     * Serialize the sale
     *
     * @return array
     */
    public function serialize()
    {
        return [
            'id' => $this->id,
            'start' => $this->start,
            'end' => $this->end
        ];
    }

    /**
     * Create a sale from an array
     *
     * @param array $data
     * @return ItchSale
     */
    public static function fromArray($data)
    {
        return new self($data['id'], $data['end'], $data['start']);
    }

    /**
     * Check if the sale is active
     *
     * @return bool
     */
    public function isActive()
    {
        $now = time();
        return ($now < $this->end && $now > $this->start);
    }

    /**
     * Check if the sale is upcoming
     *
     * @return bool
     */
    public function isUpcoming()
    {
        return time() < $this->start;
    }

    /**
     * Get the HTML content of the sale page
     *
     * @return string|null
     */
    public function getHtml()
    {
        return $this->html;
    }

    /**
     * Get the error message
     *
     * @return string|null
     */
    public function getError()
    {
        return $this->err;
    }

    /**
     * Get the sale ID
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the start timestamp
     *
     * @return int|null
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * Get the end timestamp
     *
     * @return int|null
     */
    public function getEnd()
    {
        return $this->end;
    }
}
