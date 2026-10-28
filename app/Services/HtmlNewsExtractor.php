<?php

namespace App\Services;

use App\Models\Source;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;

/**
 * @phpstan-type ArticleData array{title: string, url: string, snippet: ?string, published_at: ?string}
 */
class HtmlNewsExtractor
{
    private const array COMMON_FEED_PATHS = ['/feed', '/rss', '/rss.xml', '/feed.xml', '/atom.xml'];

    private const array ARTICLE_TYPES = ['newsarticle', 'article', 'blogposting', 'report', 'listitem'];

    private const array IGNORED_CONTAINERS = ['nav', 'header', 'footer', 'aside'];

    private const int MAX_LINKS = 50;

    /**
     * Try each strategy in order and return the first non-empty result.
     *
     * @return list<ArticleData>
     */
    public function extract(Source $source): array
    {
        $html = $this->fetch($source->url);

        if ($html === null) {
            return [];
        }

        $rules = $source->selector_rules ?? [];

        if (! empty($rules['container']) && ! empty($rules['title']) && ! empty($rules['link'])) {
            $articles = $this->extractWithSelectors($html, $source->url, $rules);

            if ($articles !== []) {
                return $articles;
            }
        }

        $feedUrl = $source->feed_url ?: $this->discoverFeedUrl($html, $source->url);

        if ($feedUrl !== null) {
            $articles = $this->parseFeed($feedUrl);

            if ($articles !== []) {
                if ($source->feed_url !== $feedUrl) {
                    $source->update(['feed_url' => $feedUrl]);
                }

                return $articles;
            }
        }

        $articles = $this->extractFromJsonLd($html, $source->url);

        if ($articles !== []) {
            return $articles;
        }

        return $this->extractFromLinks($html, $source->url);
    }

    /**
     * Fill in missing snippet/date from each article page's meta tags.
     *
     * @param  list<ArticleData>  $articles
     * @return list<ArticleData>
     */
    public function enrich(array $articles, int $limit = 15): array
    {
        $fetched = 0;

        foreach ($articles as $index => $article) {
            if ($fetched >= $limit) {
                break;
            }

            if ($article['snippet'] !== null && $article['published_at'] !== null) {
                continue;
            }

            $html = $this->fetch($article['url'], 15);
            $fetched++;

            if ($html === null) {
                continue;
            }

            $crawler = new Crawler($html, $article['url']);

            $articles[$index]['snippet'] ??= $this->firstAttribute($crawler, 'meta[property="og:description"], meta[name="description"]', 'content');

            $articles[$index]['published_at'] ??= $this->parseDate(
                $this->firstAttribute($crawler, 'meta[property="article:published_time"], meta[itemprop="datePublished"], meta[name="pubdate"]', 'content')
                    ?? $this->firstAttribute($crawler, 'time[datetime]', 'datetime')
            );
        }

        return $articles;
    }

    /**
     * @param  array<string, string>  $rules
     * @return list<ArticleData>
     */
    private function extractWithSelectors(string $html, string $pageUrl, array $rules): array
    {
        $crawler = new Crawler($html, $pageUrl);
        $articles = [];

        $crawler->filter($rules['container'])->each(function (Crawler $node) use ($rules, &$articles): void {
            $titleNode = $node->filter($rules['title']);
            $linkNode = $node->filter($rules['link']);

            if ($titleNode->count() === 0 || $linkNode->count() === 0) {
                return;
            }

            $dateNode = isset($rules['date']) ? $node->filter($rules['date']) : null;
            $snippetNode = isset($rules['snippet']) ? $node->filter($rules['snippet']) : null;

            $articles[] = [
                'title' => trim($titleNode->text()),
                'url' => $this->linkUri($linkNode->getNode(0)),
                'snippet' => $snippetNode?->count() ? trim($snippetNode->text()) : null,
                'published_at' => $dateNode?->count() ? $this->parseDate($dateNode->text()) : null,
            ];
        });

        return array_values(array_filter($articles, fn(array $a): bool => $a['title'] !== '' && $a['url'] !== ''));
    }

    private function discoverFeedUrl(string $html, string $pageUrl): ?string
    {
        $crawler = new Crawler($html, $pageUrl);

        foreach ($crawler->filter('link[rel="alternate"][href]') as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $type = strtolower($node->getAttribute('type'));

            if (str_contains($type, 'rss') || str_contains($type, 'atom')) {
                return (new Link($node, $pageUrl))->getUri();
            }
        }

        $parts = parse_url($pageUrl);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        foreach (self::COMMON_FEED_PATHS as $path) {
            $body = $this->fetch($origin . $path, 8);

            if ($body !== null && preg_match('/<(rss|feed)\b/i', substr($body, 0, 1000))) {
                return $origin . $path;
            }
        }

        return null;
    }

    /**
     * Parses RSS 2.0 and Atom.
     *
     * @return list<ArticleData>
     */
    private function parseFeed(string $body): array
    {
        $xml = @simplexml_load_string($body);

        if ($xml === false) {
            return [];
        }

        $articles = [];

        foreach ($xml->channel->item ?? [] as $item) {
            $articles[] = $this->makeArticle(
                (string) $item->title,
                (string) $item->link,
                (string) ($item->description ?? ''),
                (string) ($item->pubDate ?? ''),
            );
        }

        foreach ($xml->entry ?? [] as $entry) {
            $href = '';

            foreach ($entry->link as $link) {
                if ((string) ($link['rel'] ?? 'alternate') === 'alternate') {
                    $href = (string) $link['href'];
                    break;
                }
            }

            $articles[] = $this->makeArticle(
                (string) $entry->title,
                $href,
                (string) ($entry->summary ?? $entry->content ?? ''),
                (string) ($entry->published ?? $entry->updated ?? ''),
            );
        }

        return array_values(array_filter($articles));
    }

    /**
     * @return list<ArticleData>
     */
    private function extractFromJsonLd(string $html, string $pageUrl): array
    {
        $crawler = new Crawler($html, $pageUrl);
        $articles = [];

        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode(trim($script->textContent), true);

            if (is_array($data)) {
                $this->walkJsonLd($data, $articles);
            }
        }

        return array_values(array_filter($articles));
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<ArticleData|null>  $articles
     */
    private function walkJsonLd(array $data, array &$articles): void
    {
        $type = $data['@type'] ?? null;
        $types = array_map('strtolower', (array) $type);

        if (array_intersect($types, self::ARTICLE_TYPES) !== []) {
            $source = is_array($data['item'] ?? null) ? $data['item'] : $data;

            $url = $source['url'] ?? (is_array($source['mainEntityOfPage'] ?? null) ? ($source['mainEntityOfPage']['@id'] ?? null) : ($source['mainEntityOfPage'] ?? null));

            if (is_string($url)) {
                $articles[] = $this->makeArticle(
                    (string) ($source['headline'] ?? $source['name'] ?? ''),
                    $url,
                    (string) ($source['description'] ?? ''),
                    (string) ($source['datePublished'] ?? ''),
                );
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->walkJsonLd($value, $articles);
            }
        }
    }

    /**
     * Last resort: pick out article-looking links from a listing page.
     *
     * @return list<ArticleData>
     */
    private function extractFromLinks(string $html, string $pageUrl): array
    {
        $crawler = new Crawler($html, $pageUrl);
        $pageHost = $this->normalizeHost($pageUrl);
        $pagePath = rtrim((string) parse_url($pageUrl, PHP_URL_PATH), '/');
        $articles = [];

        foreach ($crawler->filter('a[href]') as $anchor) {
            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            $title = trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent));

            if (mb_strlen($title) < 25 || count(explode(' ', $title)) < 4) {
                continue;
            }

            $url = $this->linkUri($anchor, $pageUrl);

            if ($url === '' || ! preg_match('#^https?://#i', $url) || $this->normalizeHost($url) !== $pageHost) {
                continue;
            }

            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

            if ($path === '' || $path === $pagePath || isset($articles[$url])) {
                continue;
            }

            [$snippet, $date] = $this->contextFor($anchor);

            $articles[$url] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet,
                'published_at' => $date,
            ];

            if (count($articles) >= self::MAX_LINKS) {
                break;
            }
        }

        return array_values($articles);
    }

    /**
     * Look at the anchor's surrounding card/row for a date and a paragraph.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function contextFor(\DOMElement $anchor): array
    {
        $parent = $anchor->parentNode;

        for ($depth = 0; $depth < 4 && $parent instanceof \DOMElement; $depth++, $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->nodeName), ['body', 'html', 'main'], true)) {
                break;
            }

            $context = new Crawler($parent);
            $date = $this->firstAttribute($context, 'time[datetime]', 'datetime');
            $paragraph = $context->filter('p')->count() ? trim($context->filter('p')->first()->text()) : null;

            if ($date !== null || $paragraph) {
                return [$paragraph ?: null, $this->parseDate($date)];
            }
        }

        return [null, null];
    }

    private function isInsideIgnoredContainer(\DOMElement $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->nodeName), self::IGNORED_CONTAINERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ArticleData|null
     */
    private function makeArticle(string $title, string $url, string $snippet, string $date): ?array
    {
        $title = trim(strip_tags($title));
        $url = trim($url);

        if ($title === '' || $url === '') {
            return null;
        }

        $snippet = trim(strip_tags(html_entity_decode($snippet)));

        return [
            'title' => $title,
            'url' => $url,
            'snippet' => $snippet !== '' ? $snippet : null,
            'published_at' => $this->parseDate($date),
        ];
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Date::parse(trim($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function linkUri(?\DOMNode $node, string $base = 'http://localhost'): string
    {
        if (! $node instanceof \DOMElement) {
            return '';
        }

        try {
            $uri = (new Link($node, $base))->getUri();
        } catch (\Throwable) {
            return '';
        }

        return explode('#', $uri)[0];
    }

    private function normalizeHost(string $url): string
    {
        return strtolower((string) preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST)));
    }

    private function firstAttribute(Crawler $crawler, string $selector, string $attribute): ?string
    {
        $nodes = $crawler->filter($selector);

        if ($nodes->count() === 0) {
            return null;
        }

        $value = trim((string) $nodes->first()->attr($attribute));

        return $value !== '' ? $value : null;
    }

    private function fetch(string $url, int $timeout = 20): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; FinancialNewsBot/1.0)',
                'Accept-Language' => 'en,ne;q=0.8',
            ])->timeout($timeout)->get($url);
        } catch (ConnectionException $e) {
            logger()->warning("Fetch failed: {$url} => {$e->getMessage()}");
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }
}
