{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}

<urlset
    xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
>

    @foreach($videos as $video)

        @php
            $rawDescription =
                $video->description
                    ?: 'Watch ' .
                        $video->title .
                        ' on Xurvexa.';

            $videoDescription =
                \Illuminate\Support\Str::limit(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        strip_tags(
                            $rawDescription
                        )
                    ),
                    2048,
                    ''
                );
        @endphp

        <url>

            <loc>{{ route(
                'videos.show',
                $video->slug
            ) }}</loc>

            @if($video->updated_at)

                <lastmod>{{ $video->updated_at->toAtomString() }}</lastmod>

            @endif

            <video:video>

                <video:thumbnail_loc>{{ $video->thumbnail }}</video:thumbnail_loc>

                <video:title>{{ $video->title }}</video:title>

                <video:description>{{ $videoDescription }}</video:description>

                <video:player_loc>{{ $video->embed_url }}</video:player_loc>

                <video:duration>{{ (int) $video->duration }}</video:duration>

                <video:publication_date>{{ $video->created_at->toAtomString() }}</video:publication_date>

                <video:family_friendly>no</video:family_friendly>

            </video:video>

        </url>

    @endforeach

</urlset>
