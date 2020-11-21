<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Symfony\Component\DomCrawler\Crawler;

class KupiProdayParseCommand extends Command
{
    use CommandHelper;

    protected $signature = 'app:kupiproday';

    public function handle(): int
    {
        $file = 'kupiproday.json';

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
        $nextUrl = 'https://kupiproday.com.ua/detskie-veshchi';

        while ($page <= $maxPages) {
            $this->info('Parse page: ' . $page . '/' . $maxPages);

            $crawler = $this->crawl($nextUrl);

            $links[] = $crawler->filter('.catalog_link')->each(function (Crawler $node) {
                return ['url' => $node->link()->getUri()];
            });

            $nextUrl = $crawler->filter('.pagination li.active + li a')->link()->getUri();
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
            if (isset($item['raw'])) {
                continue;
            }

            $this->info('Parsing data: ' . $index . '/' . count($data));

            $item['raw'] = $this->crawl($item['url'])->filter('.panel-author')->text();

            if ($index && $index % 10 === 0) {
                $this->putAsJson($file, $data);
            }
        }

        // TODO save as CSV?
    }
}
