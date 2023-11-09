<?php

namespace App\Console\Commands;

use Carbon\CarbonInterval;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Panther\Client as PantherClient;

class WorkSites extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:work-sites';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retrieve sites to be actioned and process them.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Retrieving work sites...');

        try {

            $base_uri = rtrim(env('SCRAPPY_REMOTE_URL'), '\\/').'/api/';

            $options = [
                'base_uri' => $base_uri,
                'verify' => false,
            ];

            $client = new Client($options);

            $response = $client->get('query');

            $data = json_decode($response->getBody()->getContents(), true);

            $time = microtime(true);

            $content = $this->scrapeSite($data);

            if ($content !== 'false') {
                $content = $this->scrapeFilter($data['data'], $content);
            }

            // Time
            $time = microtime(true) - $time;

            $this->info("Scanned {$data['data']['id']} {$data['data']['url']} in {$time} seconds");

            $body = [
                'id' => $data['data']['id'],
                'response' => trim($content),
                'time' => $time,
            ];

            $client->post('update', [
                'form_params' => $body,
            ]);

            $this->info('Done!!');

        } catch (Exception|GuzzleException $e) {
            $this->error($e->getMessage());
        }

    }

    /**
     * @throws GuzzleException
     */
    protected function scrapeSite(array $data): string
    {
        if (! Cache::has($data['data']['url'])) {

            if (Str::of($data['data']['javascript'])->toBoolean()) {
                $this->info("Scanning {$data['data']['url']} using javascript...");

                $content = $this->scrapeJavascript($data['data']);
            } else {
                $this->info("Scanning {$data['data']['url']} using plain...");

                $content = $this->scrapePlain($data['data']);
            }

            Cache::put($data['data']['url'], $content, CarbonInterval::minutes(5));
        } else {
            $this->info("Scanning {$data['data']['url']} from cache...");
        }

        return Cache::get($data['data']['url']);
    }

    /**
     * Scrape the rate.
     */
    protected function scrapeJavascript(array $data): string|false
    {
        ['user_agent' => $user_agent, 'url' => $url] = $data;

        //$_SERVER["PANTHER_FIREFOX_BINARY"] = ROOTPATH . "drivers/firefox/firefox-bin";
        //        $_SERVER["PANTHER_CHROME_BINARY"] = ROOTPATH . "drivers/chrome-linux/chrome";

        $_SERVER['PANTHER_CHROME_ARGUMENTS'] = '--no-sandbox'; // - -port=5001";
        /**
         * @see https://peter.sh/experiments/chromium-command-line-switches/
         */
        $args = [
            '--no-sandbox', '--disable-gpu', '--incognito', '--window-size=1920,1080', 'start-maximized', '--user-agent='.$user_agent, '--headless',
        ];

        $options = [
            'connection_timeout_in_ms' => 60 * 1000 * 3,
            'request_timeout_in_ms' => 60 * 1000 * 3,
        ];

        $tries = 0;

        $client = null;
        $response = 'false';

        try {

            $client = PantherClient::createChromeClient(null, $args, $options);

            $crawler = $client->request('GET', $url);

            do {

                try {

                    $response = $crawler->html();
                    break;
                } catch (Exception) {

                    $client->reload();
                } finally {
                    $tries++;
                }
            } while ($tries < 5);
        } catch (Exception) {
            //driver does not exist
            //            if ($this->status) { //first time only
            //                $this->mail($e->getMessage());
            //            }
        } finally {
            $client?->quit();
        }

        return $response ?: 'false';
    }

    /**
     * Scrape the rate.
     *
     * @throws GuzzleException
     */
    protected function scrapePlain(array $data): string|false
    {
        ['user_agent' => $user_agent, 'url' => $url] = $data;

        $tries = 0;

        $response = 'false';

        try {

            $headers = [
                'User-Agent' => $user_agent,
            ];

            $options = [
                'verify' => false,
            ];

            $client = new Client($options);

            do {
                try {

                    $response = $client->get($url, [
                        RequestOptions::HEADERS => $headers,
                    ])->getBody()->getContents();
                    break;
                } catch (Exception) {
                    $tries++;
                }
            } while ($tries < 5);

        } catch (Exception) {
        }

        return $response ?: 'false';
    }

    /**
     * Filter scraped data.
     */
    protected function scrapeFilter(array $data, string $content): string
    {
        ['xpath' => $xpath, 'css' => $css, 'format' => $format] = $data;

        // Filter
        $crawler = new Crawler($content);

        if (empty($xpath)) {
            $content = $crawler->filter($css);
        } else {
            $content = $crawler->filterXPath($xpath);
        }

        return $content->{$format ?: 'html'}();
    }
}
