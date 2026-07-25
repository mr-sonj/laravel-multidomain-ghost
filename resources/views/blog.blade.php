<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['canonical_url'] }}">
</head>
<body>
    <main>
        <h1>{{ $content['title'] ?? 'Blog' }}</h1>
        @forelse (($dataBlog['posts'] ?? []) as $post)
            <article>
                <h2>
                    <a href="{{ $post['canonical_url'] ?? '#' }}">{{ $post['title'] ?? '' }}</a>
                </h2>
                @if (! empty($post['excerpt']))
                    <p>{{ $post['excerpt'] }}</p>
                @endif
            </article>
        @empty
            <p>No posts found.</p>
        @endforelse
    </main>
</body>
</html>
