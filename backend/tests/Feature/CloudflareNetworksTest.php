<?php

namespace Tests\Feature;

use App\Support\CloudflareNetworks;
use Tests\TestCase;

class CloudflareNetworksTest extends TestCase
{
    public function test_cloudflare_proxy_ranges_match_expected_inventory(): void
    {
        $this->assertCount(
            15,
            CloudflareNetworks::IPV4
        );

        $this->assertCount(
            7,
            CloudflareNetworks::IPV6
        );

        $this->assertCount(
            22,
            CloudflareNetworks::trustedProxies()
        );
    }

    public function test_cloudflare_proxy_ranges_do_not_use_broad_wildcards(): void
    {
        $proxies =
            CloudflareNetworks::trustedProxies();

        $this->assertNotContains(
            '*',
            $proxies
        );

        $this->assertNotContains(
            '0.0.0.0/0',
            $proxies
        );

        $this->assertNotContains(
            '::/0',
            $proxies
        );
    }
}
