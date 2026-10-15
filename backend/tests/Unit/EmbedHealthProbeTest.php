<?php

use App\Services\VideoHealth\EmbedHealthProbe;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    $this->configuration = require __DIR__.'/../../config/video-health.php';
    $this->requests = [];
    $this->bytesRead = 0;
    $this->makeProbe = function (array $queue): EmbedHealthProbe {
        // Emulate cURL's header callback and bounded write callback without internet access.
        $handler = function (RequestInterface $request, array $options) use (&$queue) {
            $this->requests[] = [(string) $request->getUri(), $request->getMethod(), $options, $request->getHeaderLine('User-Agent')];
            if ($queue === []) {
                throw new LogicException('Unexpected HTTP request.');
            }
            $item = array_shift($queue);
            if (is_int($item)) {
                $exception = new ConnectException('Sensitive remote diagnostic', $request, null, ['errno' => $item]);

                return Create::rejectionFor($exception);
            }
            try {
                $options['on_headers']($item);
            } catch (RuntimeException $exception) {
                return Create::rejectionFor(new RequestException('Header callback failed', $request, $item, $exception));
            }
            $stream = $item->getBody();
            while (!$stream->eof()) {
                $chunk = $stream->read(4);
                $this->bytesRead += strlen($chunk);
                if ($options['sink']->write($chunk) !== strlen($chunk)) {
                    return Create::rejectionFor(new RequestException('Short write', $request, $item, null, ['errno' => 23]));
                }
            }

            return Create::promiseFor($item);
        };

        return new EmbedHealthProbe($this->configuration, new Client(['handler' => $handler]));
    };
});

it('captures complete status and body evidence without classifying', function (int $status) {
    $probe = ($this->makeProbe)([new Response($status, [], '<html>Evidence</html>')]);
    $result = $probe->probe('eporner', 'https://www.eporner.com/embed/ABC/');
    expect($result)->toHaveKeys(['provider', 'requested_url', 'effective_url', 'redirect_urls', 'http_status',
        'response_complete', 'redirect_approved', 'body', 'truncated', 'timeout', 'transport_error', 'attempts']);
    expect($result['http_status'])->toBe($status)
        ->and($result['body'])->toBe('<html>Evidence</html>')
        ->and($result['response_complete'])->toBeTrue()
        ->and($result['redirect_approved'])->toBeTrue()
        ->and($result['attempts'])->toBe(1)
        ->and($result['transport_error'])->toBeNull();
})->with([200, 404, 410, 403, 429, 500]);

it('rejects unsafe initial URLs without HTTP', function (string $url) {
    $result = ($this->makeProbe)([])->probe('redtube', $url);
    expect($this->requests)->toBe([])
        ->and($result['attempts'])->toBe(0)
        ->and($result['http_status'])->toBe(0)
        ->and($result['redirect_approved'])->toBeFalse()
        ->and($result['response_complete'])->toBeFalse();
})->with([
    'malformed' => 'not a URL',
    'http' => 'http://embed.redtube.com/?id=1',
    'unapproved' => 'https://example.com/',
    'lookalike' => 'https://embed.redtube.com.example/?id=190868481',
    'IPv4' => 'https://127.0.0.1/',
    'IPv6' => 'https://[::1]/',
    'userinfo' => 'https://user:password@embed.redtube.com/',
    'username' => 'https://user@embed.redtube.com/',
    'port' => 'https://embed.redtube.com:444/',
    'relative' => '/embed/1',
    'bad escape' => 'https://embed.redtube.com/%ZZ',
    'backslash' => 'https://embed.redtube.com/\\evil',
]);

it('rejects unknown providers without HTTP', function () {
    $result = ($this->makeProbe)([])->probe('unknown', 'https://www.eporner.com/embed/ABC/');
    expect($result['attempts'])->toBe(0)->and($this->requests)->toBe([]);
});

it('accepts the observed RedTube embed URL and default HTTPS port', function (string $url) {
    $result = ($this->makeProbe)([new Response(200)])->probe('redtube', $url);
    expect($result['response_complete'])->toBeTrue()->and($result['attempts'])->toBe(1);
})->with(['https://embed.redtube.com/?id=190868481', 'https://embed.redtube.com:443/?id=190868481']);

it('follows approved absolute and relative redirects manually', function (string $location, string $expected) {
    $probe = ($this->makeProbe)([new Response(302, ['Location' => $location]), new Response(200, [], 'final')]);
    $result = $probe->probe('eporner', 'https://www.eporner.com/embed/ABC/');
    expect($result['effective_url'])->toBe($expected)
        ->and($result['redirect_urls'])->toBe([$expected])
        ->and($result['body'])->toBe('final')
        ->and($result['attempts'])->toBe(2)
        ->and($result['response_complete'])->toBeTrue();
})->with([
    ['https://www.eporner.com/embed404/ABC/', 'https://www.eporner.com/embed404/ABC/'],
    ['/embed404/ABC/', 'https://www.eporner.com/embed404/ABC/'],
    ['../../embed404/ABC/', 'https://www.eporner.com/embed404/ABC/'],
    ['//eporner.com/embed404/ABC/', 'https://eporner.com/embed404/ABC/'],
]);

it('records every approved redirect in a chain', function () {
    $probe = ($this->makeProbe)([new Response(301, ['Location' => '/second']),
        new Response(307, ['Location' => '/third']), new Response(200)]);
    $result = $probe->probe('eporner', 'https://www.eporner.com/first');
    expect($result['redirect_urls'])->toBe(['https://www.eporner.com/second', 'https://www.eporner.com/third'])
        ->and($result['attempts'])->toBe(3)->and($result['response_complete'])->toBeTrue();
});

it('rejects unsafe redirects without requesting their destinations', function (string $location) {
    $probe = ($this->makeProbe)([new Response(302, ['Location' => $location])]);
    $result = $probe->probe('redtube', 'https://embed.redtube.com/?id=190868481');
    expect($this->requests)->toHaveCount(1)
        ->and($result['effective_url'])->toBe('https://embed.redtube.com/?id=190868481')
        ->and($result['redirect_approved'])->toBeFalse()
        ->and($result['response_complete'])->toBeFalse();
})->with([
    'unapproved' => 'https://example.com/',
    'lookalike' => 'https://embed.redtube.com.example/',
    'downgrade' => 'http://embed.redtube.com/',
    'userinfo' => 'https://user:password@embed.redtube.com/',
    'port' => 'https://embed.redtube.com:444/',
    'malformed' => 'https://[invalid/',
    'whitespace' => '/bad path',
    'escape' => '/%ZZ',
    'IPv4' => 'https://127.0.0.1/',
    'IPv6' => 'https://[::1]/',
    'missing' => '',
]);

it('detects loops including fragment-only changes', function (string $location) {
    $result = ($this->makeProbe)([new Response(302, ['Location' => $location])])
        ->probe('eporner', 'https://www.eporner.com/start');
    expect($result['redirect_approved'])->toBeFalse()->and($result['response_complete'])->toBeFalse()
        ->and($result['attempts'])->toBe(1);
})->with(['/start', '#fragment']);

it('enforces the configured redirect limit before the next request', function () {
    $this->configuration['probe']['max_redirects'] = 1;
    $probe = ($this->makeProbe)([new Response(302, ['Location' => '/second']), new Response(302, ['Location' => '/third'])]);
    $result = $probe->probe('eporner', 'https://www.eporner.com/first');
    expect($result['attempts'])->toBe(2)->and($this->requests)->toHaveCount(2)
        ->and($result['redirect_urls'])->toHaveCount(2)->and($result['redirect_approved'])->toBeFalse();
});

it('bounds actual body bytes regardless of Content-Length', function (string $body, bool $truncated) {
    $this->configuration['probe']['max_body_bytes'] = 8;
    $result = ($this->makeProbe)([new Response(200, ['Content-Length' => '1'], $body)])
        ->probe('eporner', 'https://www.eporner.com/embed/ABC/');
    expect($result['body'])->toBe(substr($body, 0, 8))
        ->and($result['truncated'])->toBe($truncated)
        ->and($result['response_complete'])->toBe(!$truncated)
        ->and($this->bytesRead)->toBeLessThanOrEqual(12);
})->with([['', false], ['1234', false], ['12345678', false], [str_repeat('x', 10000), true]]);

it('returns safe evidence for transport failures without throwing', function (int $errno, bool $timeout) {
    $result = ($this->makeProbe)([$errno])->probe('eporner', 'https://www.eporner.com/embed/ABC/');
    expect($result['transport_error'])->toBe('HTTP transport failed.')
        ->and($result['timeout'])->toBe($timeout)->and($result['http_status'])->toBe(0)
        ->and($result['response_complete'])->toBeFalse()
        ->and($result['effective_url'])->toBe('https://www.eporner.com/embed/ABC/');
})->with([[7, false], [35, false], [6, false], [28, true], [18, false]]);

it('retains the last approved URL when a redirect destination fails', function () {
    $result = ($this->makeProbe)([new Response(302, ['Location' => '/final']), 7])
        ->probe('eporner', 'https://www.eporner.com/start');
    expect($result['effective_url'])->toBe('https://www.eporner.com/final')
        ->and($result['attempts'])->toBe(2)->and($result['response_complete'])->toBeFalse();
});

it('sets conservative HTTP options on every request', function () {
    ($this->makeProbe)([new Response(200)])->probe('eporner', 'https://www.eporner.com/');
    [$url, $method, $options, $userAgent] = $this->requests[0];
    expect($method)->toBe('GET')->and($options['allow_redirects'])->toBeFalse()
        ->and($options['http_errors'])->toBeFalse()->and($options['verify'])->toBeTrue()
        ->and($options['decode_content'])->toBeTrue()->and($options['cookies'])->toBeFalse()
        ->and($options['auth'] ?? null)->toBeNull()->and($options['proxy'])->toBe('')
        ->and($options['timeout'])->toBe(15)->and($options['connect_timeout'])->toBe(5)
        ->and($userAgent)->toBe('VideoHealthMonitor/1.0');
});

it('rejects media at headers before consuming body bytes', function () {
    $result = ($this->makeProbe)([new Response(200, ['Content-Type' => 'video/mp4'], str_repeat('x', 1000))])
        ->probe('eporner', 'https://www.eporner.com/');
    expect($this->bytesRead)->toBe(0)->and($result['response_complete'])->toBeFalse()
        ->and($result['transport_error'])->toBe('Media response rejected.');
});

it('fails explicitly on invalid configuration', function () {
    $this->configuration['probe']['max_body_bytes'] = 0;
    expect(fn () => ($this->makeProbe)([]))->toThrow(InvalidArgumentException::class);
});
