<?php

namespace Hnllyrp;

use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem;

final class Packagist
{
    protected $savePath;

    protected $keyword;

    protected $perPage = 50;

    protected $applicationId;

    protected $apiKey;

    protected $exceptPackage = [];

    protected $orderBy = ['downloads' => 'desc'];

    protected $defaultUrl = '';

    protected $translator;

    protected $want_page = 0;

    /**
     * Register Client Instance
     *
     * @param string $applicationId
     * @param string $apiKey
     */
    public function __construct($applicationId, $apiKey, $savePath = null, $requestUrl = '')
    {
        $this->applicationId = $applicationId;
        $this->apiKey = $apiKey;

        $this->defaultUrl = $requestUrl;
        $this->savePath = $savePath;
    }

    /**
     * Search top from Packagist
     *
     * @param string $keyword
     * @param int $perPage
     *
     * @return array|bool
     */
    public function search($keyword = 'laravel', $perPage = null)
    {
        $this->keyword = $keyword;

        $this->want_page = $perPage;

        $except = 0;
        if ($keyword == 'laravel') {
            $except = 21; // 排除了官方的21个包
        }
        // 实际查询的包
        $actual_page = $perPage + $except + count($this->exceptPackage);

        $this->perPage = 0 | $actual_page;

        $this->viaPackageFilter($keyword);

        $requestData = [
            'headers' => ['content-type' => 'application/json'],
            'query' => $this->buildQueryString(),
            'body' => $this->buildFormData(),
            'verify' => false
        ];

        echo "Start search,the keyword: '{$keyword}', per-page: '{$this->want_page}'\r\n";

        try {
            $response = $this->getHttpClient()->request('POST', $this->getRequestUrl(), $requestData);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->hasResponse()) {
                $response = (string)$e->getResponse();
            } else {
                $response = $e->getMessage();
            }

            echo "Request failure,response: \r\n{$response}\r\n\r\n";
            return false;
        }

        if ($response) {
            $body = $response->getBody()->getContents();

            $result = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "Formatted json failed.\r\n";
                return false;
            }

            $results = $result['results'][0]['hits'] ?? [];

            return $this->write($this->parseAndExceptPackages($results));
        }
    }

    /**
     * Write Result to path
     *
     * @param array $result
     *
     * @return bool
     */
    protected function write(array $results)
    {
        echo "Fetch done,start write...\r\n";

        $fs = new Filesystem();

        if (!empty($this->orderBy)) {
            $results = $this->sortBy($results, key($this->orderBy), current($this->orderBy));
        }

        $order_by = key($this->orderBy);

        $str = "# {$this->keyword} package top {$this->want_page} by {$order_by} \r\n\r\n";

        $str .= "> Last update: " . date('Y-m-d H:i:s') . " UTC \r\n\r\n";

        $str .= "| 排名 | 包地址 | 下载次数 | Star | 描述 |\r\n";
        $str .= "| ---- | ------ | -------- | ---- | ---- |\r\n";

        foreach ($results as $index => $result) {
            $name = $result['name'];
            $description = is_null($this->translator) ? $result['description'] : $this->translator->trans($result['description']);
            $repository = $result['repository'];
            $downloads = $result['downloads'];
            $favers = $result['favers'];
            $number = $index + 1;

            $str .= "| {$number} | [{$name}]({$repository}) | {$downloads} | {$favers} | {$description} |\r\n";
        }

        // 每次会更新覆盖
        $fs->dumpFile($this->getResultSavePath(), $str);
    }

    /**
     * Parse Response To array
     *
     * @param $results
     *
     * @return array
     */
    public function parseAndExceptPackages($results)
    {
        $data = [];
        foreach ($results as $package) {
            if ($this->shouldExceptPakage($package['name'])) {
                continue;
            }

            $data[] = [
                'description' => $package['description'],
                'repository' => $package['repository'],
                'downloads' => $package['meta']['downloads'],
                'favers' => $package['meta']['favers'],
                'name' => $package['name']
            ];
        }

        return $data;
    }

    /**
     * Except Package
     *
     * @param array $packages
     *
     * @return static
     */
    public function except(array $packages = [])
    {
        $this->exceptPackage = array_merge($this->exceptPackage, $packages);

        return $this;
    }

    /**
     * Order BY
     *
     * @param string $key
     * @param string $order
     *
     * @return static
     */
    public function orderBy($key, $order = 'desc')
    {
        $this->orderBy = [$key => $order];

        return $this;
    }

    /**
     * sortBy
     *
     * @param array $results
     * @param string $name
     * @param string $order
     *
     * @return array
     */
    protected function sortBy(array $results, $name, $order = 'desc')
    {
        usort($results, function ($package1, $package2) use ($order, $name) {
            if ($package1[$name] == $package2[$name]) {
                return 0;
            }

            if (strtolower($order) === 'desc') {
                return ($package1[$name] < $package2[$name]) ? 1 : -1;
            }

            return ($package1[$name] < $package2[$name]) ? -1 : 1;
        });

        return $results;
    }

    /**
     * Laravel package filter
     *
     * @param string $keyword
     *
     * @return void
     */
    protected function viaPackageFilter($keyword)
    {
        if (strtolower($keyword) == 'laravel') {
            $this->except($this->exceptDefaultLaravelPackage());
        }
    }

    /**
     * Default laravel except package
     *
     * @return array
     */
    public function exceptDefaultLaravelPackage(): array
    {
        return [
            'laravel/*'
        ];
    }

    /**
     * Set result path
     *
     * @param string $path
     *
     * @return static
     */
    public function setResultPath($path)
    {
        $this->savePath = $path;

        return $this;
    }

    /**
     * Should except  排除的包
     *
     * @param string $name like composer/installers
     *
     * @return bool
     */
    public function shouldExceptPakage($name)
    {
        list($firstName, $secondName) = explode('/', $name);

        foreach ($this->exceptPackage as $ePackage) {
            list($eFirstName, $eSecondName) = explode('/', $ePackage);

            if (($ePackage == $name) || ($eSecondName == '*' && $eFirstName == $firstName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build Request Body Form data
     *
     * @return string
     */
    protected function buildFormData(): string
    {
        return sprintf(
            '{"requests":[{"indexName":"packagist","params":"hitsPerPage=%s&facetFilters=%s"}]}',
            ($this->perPage <= 0 ? 50 : $this->perPage),
            urlencode(json_encode([["tags:{$this->keyword}"]]))
        );
    }

    /**
     * Build Request Query String
     *
     * @return array
     */
    protected function buildQueryString(): array
    {
        return [
            'x-algolia-application-id' => $this->applicationId,
            'x-algolia-api-key' => $this->apiKey,
        ];
    }

    /**
     * Get Http Client Instance
     *
     * @return \GuzzleHttp\Client
     */
    protected function getHttpClient()
    {
        return new Client();
    }

    /**
     * Get Save Path
     *
     * @return string
     */
    protected function getResultSavePath(): string
    {
        return $this->savePath ?: $this->getDefaultResultFilePath();
    }

    /**
     * Get Request url
     *
     * @return string
     */
    protected function getRequestUrl(): string
    {
        return empty($this->defaultUrl) ? 'http://m58222sh95-2.algolianet.com/1/indexes/*/queries' : $this->defaultUrl;
    }

    /**
     * Get Default Result file path
     *
     */
    protected function getDefaultResultFilePath(): string
    {
        return __DIR__ . '/../result.md';
    }

    /**
     * Set Translator
     *
     * @param string $appid
     * @param string $secret
     *
     * @return
     */
    public function translate(string $appid, string $secret)
    {
        $this->translator = new Service\YoudaoTranslate($appid, $secret);

        return $this;
    }
}
