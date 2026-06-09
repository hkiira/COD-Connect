<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScrapeDoService
{
    /**
     * Get list of scrape.do tokens.
     *
     * @return array
     */
    public static function getTokens(): array
    {
        $envTokens = env('SCRAPEDO_TOKENS');
        if (!empty($envTokens)) {
            return array_map('trim', explode(',', $envTokens));
        }

        // Return default fallback tokens configured in the codebase
        return [
            'e6f0583d848a496aa3bde4e5b904f7abaaa95122f16',
            'a131d37b9ce84e4cb33949c2b721fa8f85a864ad869',
            '9e22ee93253245dcaa6c61e6cc6356b9c7b8819d597',
            '8a355170e5de449db59061cef47bb515405addc24cd',
            '89a03e999f5741e199a0aa9606da16e01c465e2a6f8',
            '4c000b0656f74b569089a69e9b6ad44420a8f5f0e57',
            '328893f698c34a058fd070d119731957b909c885d63',
            '8734cd2a710d4e79b53c7050684eb44e9ab013400af',
            '7b12e7dd677c473dad8f4d47673242acfbaf486491e',
        ];
    }

    /**
     * Get the currently active token index.
     *
     * @return int
     */
    public static function getActiveTokenIndex(): int
    {
        return (int) Cache::get('scrapedo_current_token_index', 0);
    }

    /**
     * Get the currently active token.
     *
     * @return string|null
     */
    public static function getActiveToken(): ?string
    {
        $tokens = self::getTokens();
        if (empty($tokens)) {
            return null;
        }

        $index = self::getActiveTokenIndex();
        if ($index >= count($tokens)) {
            $index = 0;
            Cache::put('scrapedo_current_token_index', 0);
        }

        return $tokens[$index];
    }

    /**
     * Rotate to the next token.
     *
     * @return string|null
     */
    public static function rotateToken(): ?string
    {
        $tokens = self::getTokens();
        if (empty($tokens)) {
            return null;
        }

        $index = self::getActiveTokenIndex();
        $nextIndex = ($index + 1) % count($tokens);
        Cache::put('scrapedo_current_token_index', $nextIndex);

        Log::info("Scrape.do token rotated from index {$index} to {$nextIndex}.");
        return $tokens[$nextIndex];
    }

    public static function executeCurl($curl, array $scrapeData)
    {
        // Extract the actual target URL from scrapeData
        $targetUrl = $scrapeData['url'] ?? '';
        if (empty($targetUrl)) {
            Log::error("executeCurl failed: No target URL provided.");
            return false;
        }

        // Set the actual target URL
        curl_setopt($curl, CURLOPT_URL, $targetUrl);

        Log::debug("Executing direct curl request to: " . $targetUrl);

        $response = curl_exec($curl);

        // Verify if there was a curl execution error
        if ($response === false) {
            $err = curl_error($curl);
            Log::error("Curl execution failed: " . $err);
            return false;
        }

        return $response;
    }
}
