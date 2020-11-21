<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PromUaParseCommand extends Command
{
    use CommandHelper;

    protected $signature = 'app:prom_ua';

    public function handle(): int
    {
        $file = 'prom_ua.json';

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
        $nextUrl = 'https://prom.ua/graphql';

        while ($page <= $maxPages) {
            $this->info('Parse page: ' . $page . '/' . $maxPages);

            $data = $this->request($nextUrl, $this->buildBody($page));
            $products = json_decode($data, true)[0]['data']['listing']['page']['products'];

            $links[] = array_map(static fn ($item) => [
                'id' => $item['product']['id'],
                'name' => $item['product']['name'],
                'url' => $item['product']['urlForProductCatalog'],
            ], $products);

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
            if (isset($item['phone'])) {
                continue;
            }

            $this->info('Parsing data: ' . $index . '/' . count($data));

            $raw = $this->crawl($item['url'])->filter('script[type="application/javascript"]')->text();
            $raw = str_replace("}; ", "};\n", $raw);
            $raw = explode("\n", $raw)[0];
            $raw = str_replace('window.ApolloCacheState = ', '', $raw);
            $raw = rtrim($raw, ';');

            $companyData = $this->findCompany(json_decode($raw, true));

            $phoneData = $companyData['phones']['json'][1];

            $item['user1'] = html_entity_decode($companyData['name']);
            $item['user2'] = html_entity_decode($phoneData['description']);
            $item['phone'] = $phoneData['number'];

            if ($index && $index % 10 === 0) {
                $this->putAsJson($file, $data);
            }
        }

        // TODO save as CSV?
    }

    private function findCompany(array $data): array
    {
        foreach ($data as $key => $item) {
            if (Str::startsWith($key, 'Company:')) {
                return $item;
            }
        }

        throw new \RuntimeException('Cannot find Company key.');
    }

    private function buildBody(int $page): string
    {
        return '[{
        "variables": {
            "isClassified": false,
            "wholesale": false,
            "discount": false,
            "best_deal": false,
            "search_term": "",
            "params": {
                "page": ' . $page . '
            },
            "alias": "Detskie-veschi-b-u",
            "limit": 90,
            "offset": '. (90 * $page) .'
        },
        "extensions": {},
        "operationName": "TagListingQuery",
        "query": "query TagListingQuery($alias: String!, $best_deal: Boolean!, $search_term: String, $wholesale: Boolean!, $discount: Boolean!, $delivery_type: String, $offset: Int, $limit: Int, $show: String, $params: Any, $sort: String) {\n  allFavorites {\n    products\n    __typename\n  }\n  context {\n    ...ProductContextFragment\n    promOplataEnabled\n    __typename\n  }\n  listing: tagListing(alias: $alias, best_deal: $best_deal, search_term: $search_term, wholesale: $wholesale, discount: $discount, delivery_type: $delivery_type, offset: $offset, limit: $limit, show: $show, params: $params, sort: $sort) {\n    substitutedTagCategoryRedirect {\n      redirectUrl(params: $params)\n      __typename\n    }\n    substitutedTagRedirect {\n      redirectUrl(params: $params)\n      notFound\n      __typename\n    }\n    tagCategoryRedirect {\n      redirectUrl(params: $params)\n      __typename\n    }\n    tagBestDealRedirect {\n      redirectUrl(params: $params)\n      __typename\n    }\n    page {\n      ...ProductsListFragment\n      __typename\n    }\n    tag {\n      id\n      name\n      customMeta\n      __typename\n    }\n    tagRequested {\n      name\n      __typename\n    }\n    searchTerm\n    isAdultSearchTerm\n    elasticCats\n    searchMainWord\n    attributes\n    category {\n      id\n      caption\n      path {\n        id\n        caption\n        __typename\n      }\n      isService\n      __typename\n    }\n    productType\n    breadCrumbs {\n      items {\n        caption\n        url\n        __typename\n      }\n      lastItemClickable\n      __typename\n    }\n    topCategories {\n      name: caption\n      url\n      __typename\n    }\n    pageLinks {\n      name\n      url\n      __typename\n    }\n    isCpaOnly\n    advSource\n    __typename\n  }\n  region {\n    id\n    name\n    nameF2\n    __typename\n  }\n  country {\n    name\n    nameF2\n    domain\n    __typename\n  }\n  proSaleNetwork {\n    ...ProSaleNetworkFragment\n    __typename\n  }\n}\n\nfragment ProductsListFragment on ListingPage {\n  total\n  isPaidListing\n  esQueryHash\n  isCpaOnlySearch\n  topHitsCategory {\n    id\n    path {\n      id\n      caption\n      __typename\n    }\n    __typename\n  }\n  seoTags {\n    name\n    url\n    __typename\n  }\n  seoManufacturers {\n    name\n    url\n    __typename\n  }\n  seoCountries {\n    name\n    url\n    __typename\n  }\n  seoCategories {\n    name\n    url\n    __typename\n  }\n  seoNavigation {\n    name\n    url\n    __typename\n  }\n  seoPromotions {\n    name\n    url\n    __typename\n  }\n  products {\n    product_item_id\n    product {\n      ...ProductTilePreloadFragment\n      __typename\n    }\n    advert {\n      ...ProductItemAdvertFragment\n      __typename\n    }\n    advDebug {\n      productWeightEs\n      productScore\n      __typename\n    }\n    __typename\n  }\n  companies {\n    id\n    name\n    mainLogoUrl(width: 150, height: 150)\n    urlForCompanyProducts\n    opinionPositivePercent\n    __typename\n  }\n  __typename\n}\n\nfragment ProductTilePreloadFragment on Product {\n  id\n  name: nameForCatalog\n  image(width: 200, height: 200)\n  imageAlt: image(width: 640, height: 640)\n  images(width: 200, height: 200)\n  urlForProductCatalog\n  isAdult\n  __typename\n}\n\nfragment ProductItemAdvertFragment on Prosale {\n  clickUrl\n  categoryId\n  token\n  campaignId\n  source\n  price\n  ctr\n  otr\n  commission_rate_kind\n  advert_weight_adv\n  hash\n  __typename\n}\n\nfragment ProductContextFragment on Context {\n  context_meta\n  countryCode\n  domain\n  currentOrigin\n  langUrlPrefix\n  currentLang\n  defaultCurrencyCode\n  countryCurrency\n  currentUserPersonal {\n    id\n    email\n    __typename\n  }\n  currentRegionId\n  __typename\n}\n\nfragment ProSaleNetworkFragment on ProSaleNetwork {\n  criteo {\n    account\n    __typename\n  }\n  criteoCategory {\n    account\n    __typename\n  }\n  rtbHouse {\n    account\n    __typename\n  }\n  __typename\n}\n"
    }
]';
    }
}
