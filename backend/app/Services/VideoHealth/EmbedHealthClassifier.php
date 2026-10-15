<?php

namespace App\Services\VideoHealth;

class EmbedHealthClassifier
{
    public const PASS = 'PASS';

    public const BROKEN = 'BROKEN';

    public const REVIEW = 'REVIEW';

    public const UNVERIFIABLE = 'UNVERIFIABLE';

    public function __construct(
        private readonly array $configuration,
    ) {}

    /**
     * Classify trusted probe evidence without HTTP, database or container access.
     *
     * Required: provider, http_status (integer), effective_url, response_complete
     * and redirect_approved (both explicitly true). The probe must approve the
     * entire redirect chain, including transport/DNS safety, before setting the
     * latter flag. This class additionally checks configured URL hosts.
     *
     * Optional: body (string), redirect_urls (array of absolute URLs), timeout,
     * transport_error, truncated and has_valid_player (an explicit boolean from
     * the probe). Configured body signatures supplement has_valid_player.
     * Missing completeness or approval evidence fails closed.
     *
     * @param array{
     *     provider: string,
     *     http_status: int,
     *     effective_url: string,
     *     response_complete: bool,
     *     redirect_approved: bool,
     *     body?: string,
     *     redirect_urls?: array<string>,
     *     timeout?: bool,
     *     transport_error?: mixed,
     *     truncated?: bool,
     *     has_valid_player?: bool
     * } $evidence
     */
    public function classify(array $evidence): string
    {
        $transportError = $evidence['transport_error'] ?? null;
        $status = $evidence['http_status'] ?? null;

        if (
            ($transportError !== null && $transportError !== false && $transportError !== '')
            || ($evidence['timeout'] ?? false) !== false
            || ($evidence['truncated'] ?? false) !== false
            || ($evidence['response_complete'] ?? false) !== true
            || ($evidence['redirect_approved'] ?? false) !== true
            || ! is_int($status)
            || $status < 100
            || $status > 599
            || in_array($status, [403, 429], true)
            || $status >= 500
        ) {
            return self::UNVERIFIABLE;
        }

        $provider = $evidence['provider'] ?? null;
        $providerConfiguration = is_string($provider)
            ? ($this->configuration['providers'][$provider] ?? null)
            : null;

        if (! is_array($providerConfiguration)) {
            return self::UNVERIFIABLE;
        }

        $effectiveUrl = $evidence['effective_url'] ?? null;
        $redirectUrls = $evidence['redirect_urls'] ?? [];

        if (! is_array($redirectUrls)) {
            return self::UNVERIFIABLE;
        }

        foreach ([$effectiveUrl, ...$redirectUrls] as $url) {
            if (! $this->isApprovedUrl($url, $providerConfiguration)) {
                return self::UNVERIFIABLE;
            }
        }

        $body = $evidence['body'] ?? '';

        if (! is_string($body)) {
            return self::UNVERIFIABLE;
        }

        // Unescape JSON URL slashes and HTML entities without executing content.
        $body = html_entity_decode(
            str_replace('\\/', '/', $body),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        $hasPlayer = ($evidence['has_valid_player'] ?? false) === true;

        foreach ($providerConfiguration['player_indicators'] ?? [] as $pattern) {
            $matched = preg_match($pattern, $body);

            if ($matched === false) {
                return self::UNVERIFIABLE;
            }

            $hasPlayer = $hasPlayer || $matched === 1;
        }

        $text = preg_replace('/\s+/', ' ', strip_tags($body)) ?? '';

        // Match redirect signatures only in the effective path, not query text.
        $path = (string) parse_url($effectiveUrl, PHP_URL_PATH);
        $hasDeadEvidence = in_array($status, [404, 410], true)
            || $this->containsSignature(
                $path,
                $providerConfiguration['dead_url_fragments'] ?? [],
            )
            || $this->containsSignature(
                $text,
                $providerConfiguration['strong_dead_markers'] ?? [],
            );

        if ($hasDeadEvidence && $hasPlayer) {
            return self::REVIEW;
        }

        if ($hasDeadEvidence) {
            return self::BROKEN;
        }

        if ($status >= 200 && $status < 300 && $hasPlayer) {
            return self::PASS;
        }

        return self::REVIEW;
    }

    private function isApprovedUrl(mixed $url, array $configuration): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($parts['port'] ?? 443) === 443
            && in_array(
                strtolower($parts['host'] ?? ''),
                $configuration['approved_hosts'] ?? [],
                true,
            );
    }

    private function containsSignature(string $value, array $signatures): bool
    {
        foreach ($signatures as $signature) {
            if ($signature !== '' && stripos($value, $signature) !== false) {
                return true;
            }
        }

        return false;
    }
}
