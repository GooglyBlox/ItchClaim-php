<?php
/**
 * Web class for generating static website files
 * 
 * @author GooglyBlox
 * @version 1.0
 */

namespace ItchClaim;

class Web {
    /** @var string Date format for HTML output */
    const DATE_FORMAT = '<span>Y-m-d</span> <span>H:i</span>';
    
    /** @var string HTML template for table rows */
    const ROW_TEMPLATE = <<<HTML
<tr>
    <td>%s</td>
    <td style="text-align:center">%s</td>
    <td style="text-align:center" title="First sale">%s</td>
    <td style="text-align:center" title="%s">%s</td>
    <td><a href="%s" title="URL">&#x1F310;</a></td>
    <td><a href="./data/%s.json" title="JSON data">&#x1F4DC;</a></td>
</tr>
HTML;
    
    /**
     * Generate static website files
     *
     * @param array $games List of ItchGame objects
     * @param string $webDir Output directory
     * @return void
     */
    public static function generateWeb($games, $webDir) {
        // Load HTML template
        $templatePath = __DIR__ . '/index.template.html';
        $template = file_get_contents($templatePath);
        
        // Sort games by sale ID (descending) and name
        usort($games, function($a, $b) {
            $salesA = $a->getSales();
            $salesB = $b->getSales();
            
            if (empty($salesA) || empty($salesB)) {
                return empty($salesA) ? 1 : -1;
            }
            
            $lastSaleA = end($salesA);
            $lastSaleB = end($salesB);
            
            $idCompare = $lastSaleB->getId() <=> $lastSaleA->getId();
            
            if ($idCompare !== 0) {
                return $idCompare;
            }
            
            return $a->getName() <=> $b->getName();
        });
        
        // Load resume index
        $resumeIndex = 0;
        $resumeIndexPath = $webDir . '/data/resume_index.txt';
        
        if (file_exists($resumeIndexPath)) {
            $resumeIndex = (int) file_get_contents($resumeIndexPath);
        }
        
        // Filter games for active sales
        $activeSales = array_filter($games, function($game) {
            return $game->getActiveSale() !== null;
        });
        
        $activeSalesRows = self::generateRows($activeSales, 'active');
        
        // Filter games for upcoming sales
        $upcomingSales = array_filter($games, function($game) {
            return $game->getLastUpcomingSale() !== null;
        });
        
        $upcomingSalesRows = self::generateRows($upcomingSales, 'upcoming');
        
        // Replace placeholders in template
        $html = str_replace(
            ['$active_sales_rows', '$upcoming_sales_rows', '$last_update', '$last_sale'],
            [
                implode("\n", $activeSalesRows),
                implode("\n", $upcomingSalesRows),
                date(self::DATE_FORMAT),
                $resumeIndex
            ],
            $template
        );
        
        // Write HTML file
        file_put_contents($webDir . '/index.html', $html);
        
        // Create API directory if it doesn't exist
        if (!file_exists($webDir . '/api')) {
            mkdir($webDir . '/api', 0755, true);
        }
        
        // Generate JSON for active sales
        $activeSalesMin = array_map(function($game) {
            return $game->serializeMin();
        }, $activeSales);
        
        file_put_contents(
            $webDir . '/api/active.json',
            json_encode($activeSalesMin, JSON_PRETTY_PRINT)
        );
        
        // Generate JSON for upcoming sales
        $upcomingSalesMin = array_map(function($game) {
            return $game->serializeMin();
        }, $upcomingSales);
        
        file_put_contents(
            $webDir . '/api/upcoming.json',
            json_encode($upcomingSalesMin, JSON_PRETTY_PRINT)
        );
        
        // Generate JSON for all sales
        $allSales = array_map(function($game) {
            return $game->serialize();
        }, $games);
        
        file_put_contents(
            $webDir . '/api/all.json',
            json_encode($allSales, JSON_PRETTY_PRINT)
        );
    }
    
    /**
     * Generate HTML table rows for games
     *
     * @param array $games List of ItchGame objects
     * @param string $type Type of sales ('active' or 'upcoming')
     * @return array List of HTML rows
     */
    private static function generateRows($games, $type) {
        $rows = [];
        
        foreach ($games as $game) {
            // Determine claimability status
            if ($game->isClaimable() === false) {
                $claimableText = 'Not claimable';
                $claimableIcon = '&#x274C;';
            } elseif ($game->isClaimable() === true) {
                $claimableText = 'claimable';
                $claimableIcon = '&#x2714;';
            } else {
                $claimableText = 'Unknown';
                $claimableIcon = '&#x1F551;';
            }
            
            // Get sale date based on type
            if ($type === 'active') {
                $saleDate = $game->getActiveSale()->getEnd();
            } elseif ($type === 'upcoming') {
                $saleDate = $game->getLastUpcomingSale()->getStart();
            } else {
                $saleDate = 0;
            }
            
            // Format date
            $saleDateFormatted = date(self::DATE_FORMAT, $saleDate);
            
            // Format row
            $rows[] = sprintf(
                self::ROW_TEMPLATE,
                htmlspecialchars($game->getName()),
                $saleDateFormatted,
                $game->isFirstSale() ? '&#x1F947;' : '',
                $claimableText,
                $claimableIcon,
                htmlspecialchars($game->getUrl()),
                $game->getId()
            );
        }
        
        return $rows;
    }
}