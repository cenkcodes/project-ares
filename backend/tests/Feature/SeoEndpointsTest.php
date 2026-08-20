<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_can_be_rendered(): void
    {
        $response =
            $this->get('/sitemap.xml');

        $response
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'application/xml; charset=UTF-8'
            );

        $response->assertSee(
            route('home'),
            false
        );

        $response->assertSee(
            route('videos.index'),
            false
        );
    }

    public function test_placeholder_information_pages_are_not_in_sitemap(): void
    {
        $response =
            $this->get('/sitemap.xml');

        $response->assertOk();

        $response->assertDontSee(
            route('pages.about'),
            false
        );

        $response->assertDontSee(
            route('pages.contact'),
            false
        );

        $response->assertDontSee(
            route('pages.privacy'),
            false
        );

        $response->assertDontSee(
            route('pages.terms'),
            false
        );

        $response->assertDontSee(
            route('pages.content-removal'),
            false
        );
    }

    public function test_non_production_robots_blocks_crawlers(): void
    {
        $response =
            $this->get('/robots.txt');

        $response
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'text/plain; charset=UTF-8'
            );

        $response->assertSeeText(
            'User-agent: *'
        );

        $response->assertSeeText(
            'Disallow: /'
        );
    }
}
