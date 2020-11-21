<?php
declare(strict_types=1);

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

trait CommandHelper
{
    protected function readAsJson(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function putAsJson(string $path, $data): void
    {
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function crawl(string $url): Crawler
    {
        return new Crawler($this->request($url), $url);
    }

    protected function request(string $url, string $body = null): string
    {
        $method = null === $body ? 'GET' : 'POST';

        return '' . (new Client())->request($method, $url, ['body' => $body])->getBody();
    }
}
