<?php

namespace App\Services\VideoHealth;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

class EmbedHealthProbe
{
    private readonly ClientInterface $client;

    public function __construct(private readonly array $configuration, ?ClientInterface $client = null)
    {
        $settings = $configuration['probe'] ?? [];
        foreach (['connect_timeout', 'request_timeout', 'max_body_bytes'] as $key) {
            if (!isset($settings[$key]) || !is_numeric($settings[$key]) || $settings[$key] <= 0) {
                throw new InvalidArgumentException('Invalid probe setting: '.$key);
            }
        }
        if (!is_int($settings['max_body_bytes']) || !is_int($settings['max_redirects'] ?? null)
            || $settings['max_redirects'] < 0 || !is_string($settings['user_agent'] ?? null)
            || trim($settings['user_agent']) === '' || preg_match('/[\x00-\x1f\x7f]/', $settings['user_agent'])) {
            throw new InvalidArgumentException('Invalid probe configuration.');
        }
        $this->client = $client ?? new Client(['handler' => new CurlHandler()]);
    }

    public function probe(string $provider, string $url): array
    {
        $evidence = [
            'provider' => $provider,
            'requested_url' => $url,
            'effective_url' => $url,
            'redirect_urls' => [],
            'http_status' => 0,
            'response_complete' => false,
            'redirect_approved' => false,
            'body' => '',
            'truncated' => false,
            'timeout' => false,
            'transport_error' => null,
            'attempts' => 0,
        ];
        $hosts = $this->configuration['providers'][$provider]['approved_hosts'] ?? [];
        if (!$this->approved($url, $hosts)) {
            $evidence['transport_error'] = 'Provider or URL is not approved.';

            return $evidence;
        }
        $settings = $this->configuration['probe'];
        $current = $this->canonical($url);
        $seen = [$current => true];
        $evidence['redirect_approved'] = true;

        while (true) {
            $evidence['effective_url'] = $current;
            $evidence['attempts']++;
            $body = '';
            $truncated = false;
            $media = false;
            $status = 0;
            // cURL aborts on a short write. The decompressed body never grows past the cap.
            $sink = FnStream::decorate(Utils::streamFor(''), [
                'write' => function (string $chunk) use (&$body, &$truncated, $settings): int {
                    $remaining = $settings['max_body_bytes'] - strlen($body);
                    $body .= substr($chunk, 0, $remaining);
                    if (strlen($chunk) > $remaining) {
                        $truncated = true;

                        return 0;
                    }

                    return strlen($chunk);
                },
            ]);
            try {
                $response = $this->client->request('GET', $current, [
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'verify' => true,
                    'decode_content' => true,
                    'connect_timeout' => $settings['connect_timeout'],
                    'timeout' => $settings['request_timeout'],
                    'cookies' => false,
                    'auth' => null,
                    'proxy' => '',
                    'headers' => ['User-Agent' => $settings['user_agent'], 'Accept' => 'text/html'],
                    'sink' => $sink,
                    'on_headers' => function (ResponseInterface $response) use (&$status, &$media): void {
                        $status = $response->getStatusCode();
                        $type = strtolower($response->getHeaderLine('Content-Type'));
                        if (preg_match('~^(?:video/|audio/|image/|application/(?:octet-stream|vnd\.apple\.mpegurl|x-mpegurl))~', $type)) {
                            $media = true;
                            throw new \RuntimeException('Media response rejected.');
                        }
                    },
                ]);
                $evidence['http_status'] = $response->getStatusCode();
            } catch (ConnectException|RequestException $exception) {
                $evidence['http_status'] = $truncated || $media ? $status : 0;
                $evidence['body'] = $body;
                $evidence['truncated'] = $truncated;
                $evidence['timeout'] = ($exception->getHandlerContext()['errno'] ?? null) === 28;
                $evidence['transport_error'] = $truncated ? 'Response exceeded body limit.'
                    : ($media ? 'Media response rejected.' : 'HTTP transport failed.');

                return $evidence;
            } finally {
                $sink->close();
            }
            $evidence['body'] = $body;
            $evidence['truncated'] = $truncated;
            if ($truncated) {
                $evidence['transport_error'] = 'Response exceeded body limit.';

                return $evidence;
            }
            $location = $response->getHeaderLine('Location');
            if (in_array($evidence['http_status'], [301, 302, 303, 307, 308], true)) {
                if ($location === '' || preg_match('/[\x00-\x20\x7f\\\\]|%(?![a-f0-9]{2})/i', $location)) {
                    $target = null;
                } else {
                    try {
                        $target = (string) UriResolver::resolve(new Uri($current), new Uri($location));
                    } catch (InvalidArgumentException $exception) {
                        $target = null;
                    }
                }
                if ($target !== null) {
                    $evidence['redirect_urls'][] = $target;
                }
                if ($target === null || !$this->approved($target, $hosts)
                    || count($evidence['redirect_urls']) > $settings['max_redirects']
                    || isset($seen[$this->canonical($target)])) {
                    $evidence['redirect_approved'] = false;
                    $evidence['transport_error'] = 'Redirect rejected.';

                    return $evidence;
                }
                $current = $this->canonical($target);
                $seen[$current] = true;
                continue;
            }
            $evidence['response_complete'] = true;

            return $evidence;
        }
    }

    private function approved(string $url, array $hosts): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]|%(?![a-f0-9]{2})/i', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || $parts['port'] === 443)
            && filter_var(trim($parts['host'] ?? '', '[]'), FILTER_VALIDATE_IP) === false
            && in_array(strtolower($parts['host'] ?? ''), $hosts, true);
    }

    private function canonical(string $url): string
    {
        $uri = new Uri($url);

        return (string) $uri->withFragment('')->withPath($uri->getPath() ?: '/');
    }
}
