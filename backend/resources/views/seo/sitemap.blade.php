{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}

<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

    <url>
        <loc>{{ route('home') }}</loc>
    </url>

    <url>
        <loc>{{ route('videos.index') }}</loc>
    </url>

    @foreach($categories as $category)

        <url>

            <loc>{{ route('videos.category', $category->slug) }}</loc>

            @if($category->updated_at)

                <lastmod>{{ $category->updated_at->toAtomString() }}</lastmod>

            @endif

        </url>

    @endforeach

    @foreach($videos as $video)

        <url>

            <loc>{{ route('videos.show', $video->slug) }}</loc>

            @if($video->updated_at)

                <lastmod>{{ $video->updated_at->toAtomString() }}</lastmod>

            @endif

        </url>

    @endforeach

</urlset>
