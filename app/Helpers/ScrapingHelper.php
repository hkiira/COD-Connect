<?php

namespace App\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class ScrapingHelper
{
    public static function bypassCloudflare($url, callable $callback)
    {
        $jar = new CookieJar();
        $client = new Client(['cookies' => $jar]);

        // First, make a request to the site to get the Cloudflare challenge
        $response = $client->get($url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.190 Safari/537.36',
            ]
        ]);

        $html = (string) $response->getBody();

        // Check if we are being challenged
        if (strpos($html, 'jschl_vc') !== false) {
            // Solve the challenge
            $solution = self::solveChallenge($html);

            // Wait for the challenge to be solved
            sleep(5);

            // Submit the solution
            $client->get(self::getChallengeUrl($html) . '?' . http_build_query($solution), [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.190 Safari/537.36',
                    'Referer' => $url,
                ]
            ]);
        }

        return $callback($jar);
    }

    private static function getChallengeUrl($html)
    {
        preg_match('/<form id="challenge-form" action="(.+?)"/', $html, $matches);
        return 'https://app.asapdelivery.ma' . $matches[1];
    }

    private static function solveChallenge($html)
    {
        preg_match('/name="jschl_vc" value="(\w+)"/', $html, $vc);
        preg_match('/name="pass" value="(.+?)"/', $html, $pass);
        preg_match('/getElementById\(\'jschl_answer\'\)\.value = (.+?);/', $html, $js);

        $vc = $vc[1];
        $pass = $pass[1];
        $js = $js[1];

        // This is a simplified JavaScript interpreter. It may not work for all challenges.
        $js = str_replace(['a', 't', 't', 'l', 'e', ' ', ';'], '', $js);
        $js = str_replace('function(p){return p.toFixed(10)}', '', $js);
        $js = str_replace('+(function(){var t = document.createElement(\'div\');t.innerHTML="<a href=\'/\'>x</a>";t=t.firstChild.href;return t.match(/https?:\/\//)[0].length})()', 8, $js);

        $result = 0;
        @eval('$result = ' . $js . ';');

        return [
            'jschl_vc' => $vc,
            'pass' => $pass,
            'jschl_answer' => $result + strlen('app.asapdelivery.ma')
        ];
    }
}
