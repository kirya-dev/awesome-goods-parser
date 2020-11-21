<?php
declare(strict_types=1);

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Symfony\Component\DomCrawler\Crawler;

class KidstaffParseCommand extends Command
{
    use CommandHelper;

    protected $signature = 'app:kidstaff';

    public function handle(): int
    {
        $file = 'kidstaff.json';

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
        $maxPages = 20;
        $nextUrl = 'https://www.kidstaff.com.ua/goods/kids-clothing';

        while ($page <= $maxPages) {
            $this->info('Parse page: ' . $page . '/' . $maxPages);

            $crawler = $this->crawl($nextUrl);

            $links[] = $crawler->filter('.card-img-wr a')->each(function (Crawler $node) {
                return ['url' => $node->link()->getUri()];
            });

            $nextWillBeNextPage = false;
            $crawler->filter('#listalka td')->each(function (Crawler $node) use (&$nextWillBeNextPage, &$nextUrl) {
                if ($nextWillBeNextPage) {
                    $nextUrl = $node->filter('a')->link()->getUri();
                    $nextWillBeNextPage = null;
                }

                if (null !== $nextWillBeNextPage && $node->filter('.listalka_div_active')->count() > 0) {
                    $nextWillBeNextPage = true;
                }
            });

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

        foreach ($data as $index => &$item) {
            if (isset($item['raw'], $item['phone'])) {
                continue;
            }

            $this->info('Parsing data: ' . $index . '/' . count($data));

            $node = $this->crawl($item['url']);
            $item['raw'] = $node->filter('.sellerinfob')->text();
            $item['raw'] = str_replace('Информация о продавце ', '', $item['raw']);
            $item['raw'] = str_replace('(показать)Все объявления Отзывы о продавце', '', $item['raw']);
            $item['raw'] = str_replace('Все объявления', '', $item['raw']);
            $item['raw'] = str_replace('Отзывы о продавце', '', $item['raw']);

            $suid = $node->filter('.picshowhere a')->attr('data-id');
            $phone = '' . (new Client())
                    ->post(
                        'https://www.kidstaff.com.ua/ajax/showmeuserphone.php',
                        [
                            'form_params' => ['suid' => $suid]
                        ]
                    )->getBody();

            $item['phone'] = $phone;

            if ($index && $index % 10 === 0) {
                $this->putAsJson($file, $data);
            }
        }

        // TODO save as CSV?
    }
}
