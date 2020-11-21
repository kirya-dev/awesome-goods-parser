<?php
declare(strict_types=1);

namespace App\Console\Commands;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Symfony\Component\DomCrawler\Crawler;

class OlxParseCommand extends Command
{
    use CommandHelper;

    protected $signature = 'app:olx';

    public function handle(): int
    {
        $file = 'olx.json';

        if (!file_exists($file)) {
            $this->step1($file);
        }

        $this->step2($file);

        $this->info('Done');

        return 0;
    }

    private function step1(string $file): void
    {
        // Здесь парсим пагинацию с лимитом в 20 страниц.

        $links = [];
        $page = 1;
        $maxPages = 24;
        $nextUrl = 'https://www.olx.ua/detskiy-mir/?search[order]=created_at:desc';

        while ($page <= $maxPages) {
            $this->info('Parse page: ' . $page . '/' . $maxPages);

            $crawler = $this->crawl($nextUrl);

            $links[] = $crawler->filter('#offers_table .title-cell .detailsLink')->each(function (Crawler $node) {
                return [
                    'name' => $node->text(),
                    'url' => $node->link()->getUri(),
                ];
            });

            $nextUrl = $crawler->filter('a.pageNextPrev[data-cy=page-link-next]')->link()->getUri();
            ++$page;
        }

        $links = Arr::flatten($links, 1);

        $this->putAsJson($file, $links);
    }

    private function step2(string $file): void
    {
        // А здесь парсим с каждой страницы сведенья о продавце.
        // Скрипт умеет продолжать с того места где был остановлен.

        $data = $this->readAsJson($file);

        $newClient = function () {
            $jar = new CookieJar();

            return new \GuzzleHttp\Client(['cookies' => $jar]);
        };
        $client = $newClient();

        foreach ($data as $index => &$item) {
            if (isset($item['phone'])) {
                continue;
            }
            $this->info('Parsing data: ' . $index . '/' . count($data));

            $productUrl = $item['url'];

            $resp = '' . $client->request('GET', $productUrl)->getBody();
            $crawler = new Crawler($resp, $productUrl);

            $userNode = $crawler->filter('.quickcontact__user-name');
            $item['user1'] = $userNode->count() > 0 ? $userNode->text() : '';

            $phoneNode = $crawler->filter('.contactbox__methods .link-phone');
            if ($phoneNode->count() < 1) {
                unset($data[$index]);
                continue;
            }

            $phoneData = $phoneNode->attr('class');
            $phoneData = str_replace(['contactitem', 'link-phone', 'atClickTracking', 'contact-a'], '', $phoneData);
            $phoneData = trim($phoneData);
            $phoneData = str_replace("'", '"', $phoneData);
            $phoneData = json_decode($phoneData, true);

            $rawPhoneToken = $crawler->filter('#body-container script')->text();
            $phoneToken1 = str_replace(["var phoneToken = '", "';"], '', $rawPhoneToken);

            $phoneToken2 = '4ff37f6bd183872933e9bf3498cdab0757fe0b3dc92ebcdb5be46726cbcf1ff8c0b2c39c950e496813db19a73cc77d755e8ad3c94640fe38e0bc9205859e61ea';
            $fingerPrint = 'MTI1NzY4MzI5MTs4OzA7MDswOzA7MDswOzA7MDswOzE7MTsxOzE7MTsxOzE7MTsxOzE7MTsxOzE7MTswOzE7MDswOzA7MDswOzA7MDswOzA7MDsxOzE7MTsxOzA7MTswOzA7MDsxOzA7MDswOzA7MDswOzA7MDswOzE7MDswOzA7MTswOzA7MTsxOzE7MDswOzE7MTswOzE7MDswOzI5NjI3MDg5MDA7MjsyOzI7MjsyOzI7Mjs0ODIwMDYyMzc7MzcyNDc2MDA1NTsxOzE7MTsxOzE7MTsxOzE7MTsxOzE7MTsxOzE7MTsxOzE7MDsxOzA7MzU2MzYzNjY3OzE1MTI4MjU4MTY7NTcxMjk3MjY3OzMzMDgzODg0MTszMDIxMDU5MzM2OzI1NjA7MTQ0MDsyNDsyNDsyNDA7MTgwOzI0MDsxODA7MjQwOzE4MDsyNDA7MTgwOzI0MDsyNDA7MjQwOzI0MDsyNDA7MjQwOzI0MDsxODA7MTgwOzE4MDsxODA7MTgwOzA7MDsw';

            $url = 'https://www.olx.ua/ajax/misc/contact/' . $phoneData['path'] . '/' . $phoneData['id'] . '/?pt=' . $phoneToken1;
            $headers = [
                'accept' => '*/*',
                'connection' => 'keep-alive',
                'accept-encoding' => 'gzip, deflate, br',
                'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'cookie' => 'newrelic_cdn_name=CF; mobile_default=desktop; dfp_segment_test=36; dfp_segment_test_v3=62; dfp_segment_test_v4=67; dfp_segment_test_oa=32; pt=' . $phoneToken2 . '; fingerprint=' . $fingerPrint . '; user_adblock_status=false; lister_lifecycle=' . '1605909626' . '; from_detail=1',
                'referer' => explode('#', $productUrl)[0],
                'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.66 Safari/537.36',
                'x-requested-with' => 'XMLHttpRequest',
            ];

            usleep(1500); // For avoid blocking

            while (true) {
                try {
                    $content = '' . $client->request('GET', $url, ['headers' => $headers])->getBody();
                    break;
                } catch (RequestException $e) {
                    $this->error('Wasted: '. $e->getMessage());
                    $client = $newClient();
                    sleep(60);
                }
            }

            $parseNumber = static fn(string $content) => json_decode($content, true, 512, JSON_THROW_ON_ERROR)['value'];

            $item['phone'] = $parseNumber($content);

            if ($index && $index % 10 === 0) {
                $this->putAsJson($file, array_values($data));

                $client = $newClient();
            }
        }

        // TODO save as CSV?
    }
}
