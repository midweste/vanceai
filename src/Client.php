<?php

namespace VanceAi;

// https://vanceai.com/api-docs/

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

class Client
{
    private $key;
    private $endpoint;
    private $codes = [
        10001 => 'Illegal parameter',
        10010 => 'Internal error',
        10011 => 'File does not exist',
        10012 => 'Job exceeds limitation',
        10013 => 'jparam parse error',
        10014 => 'Job failed and existed for unknown reason',
        30001 => 'Invalid api token',
        30004 => 'Limit exceeded'
    ];
    private $enlargeScales = [2, 4, 6, 8];
    private $client;
    private $clientConfig = [];

    public function __construct(string $apiKey)
    {
        $this->setKey($apiKey);
        $this->setEndpoint('https://api-service.vanceai.com/web_api/v1/');
        $this->setClientConfig([
            'base_uri' => $this->getEndpoint(),
            'timeout'  => 5,
            'allow_redirects' => true,
            'connect_timeout' => 3.0,
            // 'verify'  => false,
        ]);
    }

    public function getKey()
    {
        return $this->key;
    }

    public function setKey($key)
    {
        $this->key = $key;
        return $this;
    }

    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getCodes(): array
    {
        return $this->codes;
    }

    public function setCodes(array $codes): self
    {
        $this->codes = $codes;
        return $this;
    }

    public function getEnlargeScales(): array
    {
        return $this->enlargeScales;
    }

    public function setEnlargeScales(array $enlargeScales): self
    {
        $this->enlargeScales = $enlargeScales;
        return $this;
    }

    /* main api */

    protected function getClient(): GuzzleClient
    {
        if (!$this->client instanceof GuzzleClient) {
            $this->client = new GuzzleClient($this->getClientConfig());
        }
        return $this->client;
    }

    public function getClientConfig(): array
    {
        return $this->clientConfig;
    }

    public function setClientConfig(array $clientConfig): self
    {
        $this->clientConfig = $clientConfig;
        return $this;
    }

    protected function responseHandler(Response $response): object
    {
        $code = $response->getStatusCode();
        if ($code <> 200) {
            $error = (isset($this->getCodes()[$code])) ? $this->getCodes()[$code] : "Unknown error";
            throw new \Exception($error);
        }
        return json_decode($response->getBody()->getContents())->data;
    }

    protected function request(string $endpoint, array $params = [], string $method = 'POST')
    {
        return $this->responseHandler($this->getClient()->request($method, $endpoint, $params));
    }

    protected function getConfig(string $filename): string
    {
        $config = file_get_contents(__DIR__ . '/config/' . $filename);
        $config = trim($config, chr(239) . chr(187) . chr(191));
        return $config;
    }

    protected function configEncode(object $config): string
    {
        $encoded = json_encode($config);
        $config = trim($encoded, chr(239) . chr(187) . chr(191));
        return $config;
    }

    protected function upload(string $filepath): string
    {
        if (!is_file($filepath) || !is_readable($filepath)) {
            throw new \Exception(sprintf('Could not read file %s', $filepath));
        }

        $data = $this->request(
            'upload',
            [
                'multipart' => [
                    [
                        'name' => 'api_token',
                        'contents' => $this->getKey(),
                    ],
                    [
                        'name' => 'file',
                        'contents' => fopen($filepath, 'r'),
                    ]
                ]
            ]
        );
        return $data->uid;
    }

    public function transform(string $filepath, object $config): object
    {
        $uid = $this->upload($filepath);
        $data =  $this->request('transform', [
            'form_params' => [
                'api_token' =>  $this->getKey(),
                'uid' => $uid,
                'jconfig' => $this->configEncode($config),
            ]
        ]);
        return $data;
    }

    public function progress(string $transId): object
    {
        $data =  $this->request('progress', [
            'multipart' => [
                [
                    'name' => 'api_token',
                    'contents' => $this->getKey(),
                ],
                [
                    'name' => 'trans_id',
                    'contents' => $transId,
                ]
            ]
        ]);
        return $data;
    }

    public function download(string $transId, string $filepath): bool
    {
        if (!is_writable(dirname($filepath))) {
            throw new \Exception(sprintf('Could not write file %s', $filepath));
        }

        $resource = fopen($filepath, 'w+');
        $stream = Utils::streamFor($resource);

        $this->getClient()->request('POST', 'download', [
            'save_to' => $stream,
            'multipart' => [
                [
                    'name' => 'api_token',
                    'contents' => $this->getKey(),
                ],
                [
                    'name' => 'trans_id',
                    'contents' => $transId,
                ]
            ]
        ]);
        // clearstatcache(); //be carefull when using filesize, cause it's results are cached for better performance.
        return (is_file($filepath) && filesize($filepath) > 0) ? true : false;
    }

    /* Helper methods */

    protected function transformAndDownload(string $filepath, object $config): string
    {
        $data = $this->transform($filepath, $config);
        if (empty($data->trans_id) || empty($data->status)) {
            throw new \Exception(sprintf('Could not transform %s', $filepath));
        }

        $pathinfo = pathinfo($filepath);
        $tempFilePath = sprintf('%s/%s', sys_get_temp_dir(), $pathinfo['basename']);

        // finished processing
        if ($data->status == 'finish' && $this->download($data->trans_id, $tempFilePath)) {
            return $tempFilePath;
        }

        // not finished check progress x times
        if ($data->status <> 'fatal') {
            for ($i = 1; $i <= 10; $i++) {
                $progress = $this->progress($data->trans_id);
                if ($progress->status == 'finish' && $this->download($data->trans_id, $tempFilePath)) {
                    return $tempFilePath;
                }
                usleep(500000);
            }
        }

        throw new \Exception(sprintf('Timeout exceeded for transform id %s', $data->trans_id));
    }

    /**
     * Enlarge an image by scale
     *
     * @param string $filepath
     * @param integer $scale
     * @param integer $suppress_noise
     * @param integer $remove_blur
     * @return string Transaction ID
     */
    public function enlargeByScale(string $filepath, int $scale = 2, int $suppress_noise = 26, int $remove_blur = 26): string
    {
        if (!array_search($scale, $this->getEnlargeScales())) {
            throw new \Exception(sprintf('Scale %s not available. Use %s', $scale, implode(', ', $this->enlargeScales)));
        }
        $config = json_decode($this->getConfig('image-enlarger.json'));
        $config->config->module_params->scale = $scale . 'x';
        $config->config->module_params->suppress_noise = $suppress_noise;
        $config->config->module_params->remove_blur = $remove_blur;

        return $this->transformAndDownload($filepath, $config);
    }

    /**
     * Enlarge an image to the minimum scale required to meet height or width requirements
     *
     * @param string $filepath
     * @param integer $minWidth
     * @param integer $minHeight
     * @param integer $suppress_noise
     * @param integer $remove_blur
     * @return string Transaction ID
     */
    public function enlargeByDimensions(string $filepath, int $minWidth, int $minHeight, int $suppress_noise = 26, int $remove_blur = 26): string
    {
        if (!is_file($filepath)) {
            throw new \Exception(sprintf('%s is not a valid file', $filepath));
        }

        list($currentWidth, $currentHeight, $type, $attr) = \getimagesize($filepath);
        if (empty($currentWidth) || empty($currentHeight)) {
            throw new \Exception(sprintf('Could not get image information from %s ', $filepath));
        }

        $computedScale = null;
        foreach ($this->getEnlargeScales() as $scale) {
            $scaledHeight = $currentWidth * $scale;
            $scaledWidth = $currentHeight * $scale;
            if ($scaledHeight > $minHeight || $scaledWidth > $minWidth) {
                $computedScale = $scale;
                break;
            }
        }
        if (empty($computedScale)) {
            throw new \Exception(sprintf('%s is too small. Max scale could not reach desired width/height', $filepath));
        }
        return $this->enlargeByScale($filepath, $computedScale, $suppress_noise, $remove_blur);
    }
}