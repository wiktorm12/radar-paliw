{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
        <news:news>
            <news:publication>
                <news:name>Radar Paliw</news:name>
                <news:language>pl</news:language>
            </news:publication>
            <news:publication_date>{{ $url['published'] }}</news:publication_date>
            <news:title>{{ $url['title'] }}</news:title>
        </news:news>
    </url>
@endforeach
</urlset>
