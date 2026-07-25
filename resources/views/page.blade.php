<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['canonical_url'] }}">
    <meta property="og:title" content="{{ $seo['og']['title'] }}">
    <meta property="og:description" content="{{ $seo['og']['description'] }}">
    <meta property="og:type" content="{{ $seo['og']['type'] }}">
    <meta property="og:url" content="{{ $seo['og']['url'] }}">
    <meta property="og:image" content="{{ $seo['og']['image'] }}">
    @if (! empty($seo['og']['site_name']))
        <meta property="og:site_name" content="{{ $seo['og']['site_name'] }}">
    @endif
    @if (! empty($seo['og']['locale']))
        <meta property="og:locale" content="{{ $seo['og']['locale'] }}">
    @endif
    <meta name="twitter:card" content="{{ $seo['twitter']['card'] }}">
    <meta name="twitter:title" content="{{ $seo['twitter']['title'] }}">
    <meta name="twitter:description" content="{{ $seo['twitter']['description'] }}">
    <meta name="twitter:image" content="{{ $seo['twitter']['image'] }}">
    @if (! empty($seo['twitter']['site']))
        <meta name="twitter:site" content="{{ $seo['twitter']['site'] }}">
    @endif
    @if (! ($seo['json_ld']['has_custom_schema'] ?? false))
        @php
            $jsonLd = [
                '@context' => 'https://schema.org',
                '@type' => $seo['json_ld']['type'],
                'name' => $seo['json_ld']['title'],
                'description' => $seo['json_ld']['description'],
                'url' => $seo['json_ld']['url'],
                'image' => $seo['json_ld']['image'],
                'datePublished' => $seo['json_ld']['published_at'],
                'dateModified' => $seo['json_ld']['updated_at'],
                'inLanguage' => $seo['json_ld']['language'],
                'isPartOf' => $seo['json_ld']['is_part_of'],
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode(array_filter($jsonLd), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    {!! $content['codeinjection_head'] ?? '' !!}
</head>
<body>
    <main>
        <article>
            <h1>{{ $content['title'] ?? '' }}</h1>
            {!! $content['html'] ?? '' !!}
        </article>
    </main>
    {!! $content['codeinjection_foot'] ?? '' !!}
</body>
</html>
