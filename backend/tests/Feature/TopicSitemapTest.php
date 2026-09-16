<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SeoTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicSitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_switch_excludes_unapproved_and_ineligible_topics(): void
    {
        $category = Category::create(['name' => 'Test Category', 'slug' => 'test-category', 'is_active' => true]);
        $inactive = Category::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);
        $publishedAt = '2026-09-01 12:00:00';
        $strong = $this->topic('approved-strong', $category->id, ['published_at' => $publishedAt]);
        $focused = $this->topic('approved-focused', $category->id, ['quality_status' => 'focused', 'published_at' => $publishedAt]);
        $excluded = [$this->topic('unpublished', $category->id)];

        foreach (['candidate', 'generic_hold', 'redundant_hold', 'retired'] as $status) {
            $excluded[] = $this->topic($status, $category->id, ['quality_status' => $status, 'published_at' => $publishedAt]);
        }
        $excluded[] = $this->topic('wrong-generator', $category->id, ['generator_version' => 'other', 'published_at' => $publishedAt]);
        $excluded[] = $this->topic('not-indexable', $category->id, ['is_indexable' => false, 'published_at' => $publishedAt]);
        $excluded[] = $this->topic('inactive-category', $inactive->id, ['published_at' => $publishedAt]);
        $excluded[] = $this->topic('no-category', null, ['published_at' => $publishedAt]);

        foreach ([false, true] as $enabled) {
            config(['seo.topic_index_exposure_enabled' => $enabled]);
            $response = $this->get('/sitemap.xml')
                ->assertOk()
                ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
            $xml = simplexml_load_string($response->getContent());
            $this->assertNotFalse($xml);
            $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $locations = array_map(fn ($node) => (string) $node, $xml->xpath('//s:url/s:loc'));

            foreach (['home', 'videos.index', 'guides.index'] as $route) {
                $this->assertContains(route($route), $locations);
            }
            $this->assertContains(route('videos.category', $category->slug), $locations);
            foreach (config('guide-seo.guides', []) as $slug => $guide) {
                if (is_array($guide) && ($guide['is_active'] ?? false) === true) {
                    $this->assertContains(route('guides.show', $slug), $locations);
                }
            }
            foreach ($excluded as $topic) {
                $this->assertNotContains(route('topics.show', $topic->slug), $locations);
            }
            $topicLocations = array_values(array_filter($locations, fn ($url) => str_contains($url, '/topics/')));
            $this->assertSame($enabled ? [route('topics.show', $strong->slug), route('topics.show', $focused->slug)] : [], $topicLocations);
            foreach ($xml->xpath('//s:url') as $entry) {
                if (in_array((string) $entry->loc, $topicLocations, true)) {
                    $this->assertSame($strong->published_at->toAtomString(), (string) $entry->lastmod);
                    $this->assertStringNotContainsString('?', (string) $entry->loc);
                    $this->assertFalse(isset($entry->priority));
                    $this->assertFalse(isset($entry->changefreq));
                }
            }
        }
    }

    private function topic(string $slug, ?int $categoryId, array $attributes = []): SeoTopic
    {
        return SeoTopic::create(array_replace([
            'slug' => $slug,
            'signature_hash' => hash('sha256', $slug),
            'title' => ucfirst($slug) . ' Videos',
            'topic_type' => 'category-concept',
            'primary_category_id' => $categoryId,
            'generator_version' => SeoTopic::GENERATOR_VERSION,
            'quality_status' => 'strong',
            'is_indexable' => true,
            'support_video_count' => 250,
        ], $attributes));
    }
}
