<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdultConsent;
use App\Models\Category;
use App\Models\SeoTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TopicIndexExposureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'production');
        config(['app.env' => 'production', 'seo.topic_index_exposure_enabled' => false]);
        $this->withCookie(RequireAdultConsent::COOKIE_NAME, RequireAdultConsent::COOKIE_VALUE);
    }

    public static function robotsCases(): array
    {
        return [
            'unpublished with switch on' => [true, false, '', 'production', 'noindex,follow'],
            'published with switch off' => [false, true, '', 'production', 'noindex,follow'],
            'approved production page' => [true, true, '', 'production', 'index,follow'],
            'query variant' => [true, true, '?utm_source=test', 'production', 'noindex,follow'],
            'pagination variant' => [true, true, '?page=2', 'production', 'noindex,follow'],
            'non production' => [true, true, '', 'testing', 'noindex,nofollow'],
        ];
    }

    #[DataProvider('robotsCases')]
    public function test_robots_and_clean_canonical(
        bool $enabled,
        bool $published,
        string $query,
        string $environment,
        string $robots
    ): void {
        config(['seo.topic_index_exposure_enabled' => $enabled, 'app.env' => $environment]);
        $this->app->instance('env', $environment);
        $topic = $this->topic('main', ['published_at' => $published ? now() : null]);
        $canonical = route('topics.show', $topic->slug);

        $response = $this->get($canonical . $query)->assertOk();
        $this->assertHead($response, $robots, $canonical);
    }

    public static function ineligibleCases(): array
    {
        return [
            'candidate' => [['quality_status' => 'candidate']],
            'generic hold' => [['quality_status' => 'generic_hold']],
            'redundant hold' => [['quality_status' => 'redundant_hold']],
            'retired' => [['quality_status' => 'retired']],
            'wrong generator' => [['generator_version' => 'other-version']],
            'not indexable' => [['is_indexable' => false]],
            'missing category' => [['primary_category_id' => null]],
        ];
    }

    #[DataProvider('ineligibleCases')]
    public function test_ineligible_published_topic_is_not_public(array $attributes): void
    {
        config(['seo.topic_index_exposure_enabled' => true]);
        $topic = $this->topic('excluded', $attributes + ['published_at' => now()]);

        $this->get(route('topics.show', $topic->slug))->assertNotFound();
        $this->assertFalse(SeoTopic::searchExposureEligible()->whereKey($topic->id)->exists());
    }

    public function test_inactive_category_excludes_published_topic(): void
    {
        config(['seo.topic_index_exposure_enabled' => true]);
        $topic = $this->topic('inactive', ['published_at' => now()]);
        $topic->primaryCategory->update(['is_active' => false]);

        $this->get(route('topics.show', $topic->slug))->assertNotFound();
        $this->assertFalse(SeoTopic::searchExposureEligible()->whereKey($topic->id)->exists());
    }

    public function test_focused_topic_can_be_search_exposed(): void
    {
        config(['seo.topic_index_exposure_enabled' => true]);
        $topic = $this->topic('focused', ['quality_status' => 'focused', 'published_at' => now()]);
        $canonical = route('topics.show', $topic->slug);

        $this->assertHead($this->get($canonical)->assertOk(), 'index,follow', $canonical);
    }

    public static function editorialCases(): array
    {
        return [
            'draft with old timestamp' => ['draft', true, false],
            'hold with old timestamp' => ['quality_hold', true, false],
            'published without timestamp' => ['published', false, false],
            'published content' => ['published', true, true],
        ];
    }

    #[DataProvider('editorialCases')]
    public function test_editorial_requires_status_and_timestamp(string $status, bool $dated, bool $visible): void
    {
        $topic = $this->topic('editorial');
        DB::table('seo_topic_contents')->insert([
            'topic_id' => $topic->id,
            'quality_status' => $status,
            'published_at' => $dated ? now()->subDay() : null,
            'intro' => 'Reviewed topic introduction',
            'body' => 'Reviewed topic body',
            'meta_description' => 'Reviewed topic metadata',
            'faq' => json_encode([['question' => 'Reviewed question?', 'answer' => 'Reviewed answer.']]),
        ]);

        $response = $this->get(route('topics.show', $topic->slug))->assertOk();
        foreach (['Reviewed topic introduction', 'Reviewed topic body', 'Reviewed topic metadata', 'Reviewed question?', 'Reviewed answer.'] as $text) {
            if ($visible) {
                $response->assertSee($text);
            } else {
                $response->assertDontSee($text);
            }
        }
    }

    public function test_related_topics_require_approval_only_when_global_switch_is_on(): void
    {
        $current = $this->topic('current', ['published_at' => now()]);
        $approved = $this->topic('approved', ['published_at' => now()]);
        $unapproved = $this->topic('unapproved');
        $held = $this->topic('held', ['quality_status' => 'generic_hold', 'published_at' => now()]);
        $other = $this->topic('other-category', [
            'published_at' => now(),
            'primary_category_id' => Category::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true])->id,
        ]);

        foreach ([false, true] as $enabled) {
            config(['seo.topic_index_exposure_enabled' => $enabled]);
            $response = $this->get(route('topics.show', $current->slug))->assertOk();
            $expected = $enabled ? [$approved->id] : [$approved->id, $unapproved->id];
            $response->assertViewHas('relatedTopics', fn ($topics) => $topics->pluck('id')->all() === $expected);
            $response->assertSee(route('topics.show', $approved->slug), false);
            $response->assertDontSee(route('topics.show', $held->slug), false);
            $response->assertDontSee(route('topics.show', $other->slug), false);
            if ($enabled) {
                $response->assertDontSee(route('topics.show', $unapproved->slug), false);
            } else {
                $response->assertSee(route('topics.show', $unapproved->slug), false);
            }
        }
    }

    private function topic(string $slug, array $attributes = []): SeoTopic
    {
        $category = Category::firstOrCreate(
            ['slug' => 'test-category'],
            ['name' => 'Test Category', 'is_active' => true]
        );

        return SeoTopic::create(array_replace([
            'slug' => $slug,
            'signature_hash' => hash('sha256', $slug),
            'title' => ucfirst($slug) . ' Videos',
            'topic_type' => 'category-concept',
            'primary_category_id' => $category->id,
            'generator_version' => SeoTopic::GENERATOR_VERSION,
            'quality_status' => 'strong',
            'is_indexable' => true,
            'support_video_count' => 250,
        ], $attributes));
    }

    private function assertHead(TestResponse $response, string $robots, string $canonical): void
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame($robots, $xpath->evaluate('string(//meta[@name="robots"]/@content)'));
            $this->assertSame($canonical, $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
            $this->assertSame(1, $xpath->query('//link[@rel="canonical"]')->length);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
