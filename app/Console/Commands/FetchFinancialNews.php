<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\Article;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;


#[Signature('app:fetch-financial-news')]
#[Description('Command description')]
class FetchFinancialNews extends Command
{
    /**
     * Execute the console command.
     */
    protected $signature   = 'news:fetch';
    protected $description = 'Fetch financial news and notices from active RSS and HTMl feeds';

    public function handle()
    {
        //
        $sources = Source::where('is_active', true)->get();

        if ($sources->isEmpty()) {
            $this->info('No active sources found.');
            return;
        }

        foreach ($sources as $source) {
            $this->info("Fetching from: {$source->name} ({$source->type})");

            try {
                if ($source->type == 'rss') {
                    $this->fetchRss($source);
                } else if ($source->type == 'html') {
                    $this->fetchHtml($source);
                }
            } catch (\Exception $e) {
                $this->error("Failed to fetch from {$source->name}: ", $e->getMessage());
            }
        }

        $this->info('News ingestion completed successfully.');
    }

    /**
     * Parse and save items from an RSS feed
     * 
     */
    protected function fetchRss(Source $source)
    {
        $response = Http::timeout(15)->get($source->url);

        if ($response->failed()) {
            $this->warn("HTTP request failed for RSS source: {$source->url}");
            return;
        }

        $xml = @simplexml_load_string($response->body());
        if ($xml === false) {
            $this->warn("Invalid XML payload for source: {$source->name}");
            return;
        }

        $items = $xml->channel->item ?? [];
        foreach ($items as $item) {
            $title       = (string) $item->title;
            $url         = (string) $item->link;
            $snippet     = (string) ($item->description ?? '');
            $publishedAt = isset($item->pubDate) ? date('Y-m-d H:i:s', strtotime($item->pubDate)) : now();

            if (empty($url) || empty($title)) {
                continue;
            }

            Article::updateOrCreate(
                ['url' => $url],
                [
                    'source_id'    => $source->id,
                    'title'        => trim($title),
                    'snippet'      => trim(strip_tags($snippet)),
                    'published_at' => $publishedAt,
                ]
            );
        }
    }

    /**
     * Parse and save items from HTML pages using DomCrawler
     */
    protected function fetchHtml(Source $source)
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ])->timeout(20)->get($source->url);

        if ($response->failed()) {
            $this->warn("HTTP request failed for HTML source: {$source->url}");
            return;
        }

        $crawler = new Crawler($response->body());
        $rules = $source->selector_rules ?? [];

        $containerSelector = $rules['container'] ?? null;
        $titleSelector = $rules['title'] ?? null;
        $linkSelector = $rules['link'] ?? null;
        $dateSelector = $rules['date'] ?? null;
        $snippetSelector = $rules['snippet'] ?? null;

        if (!$containerSelector || !$titleSelector || !$linkSelector) {
            $this->warn("Missing required CSS selector rules for source: {$source->name}");
            return;
        }

        $crawler->filter($containerSelector)->each(function (Crawler $node) use ($source, $titleSelector, $linkSelector, $dateSelector, $snippetSelector) {
            try {
                $titleNode = $node->filter($titleSelector);
                $linkNode = $node->filter($linkSelector);

                if ($titleNode->count() === 0 || $linkNode->count() === 0) {
                    return;
                }

                $title = trim($titleNode->text());
                $rawLink = trim($linkNode->attr('href'));
                $url = $this->resolveUrl($source->url, $rawLink);

                $snippet = $snippetSelector && $node->filter($snippetSelector)->count() > 0
                    ? trim($node->filter($snippetSelector)->text())
                    : null;

                $publishedAt = $dateSelector && $node->filter($dateSelector)->count() > 0
                    ? date('Y-m-d H:i:s', strtotime($node->filter($dateSelector)->text()))
                    : now();

                Article::updateOrCreate(
                    ['url' => $url],
                    [
                        'source_id' => $source->id,
                        'title' => $title,
                        'snippet' => $snippet,
                        'published_at' => $publishedAt,
                    ]
                );
            } catch (\Exception $e) {
                // Skip malformed individual list items gracefully
            }
        });
    }

    /**
     * Normalize relative links into absolute URLs
     */
    protected function resolveUrl(string $baseUrl, string $relativeUrl): string
    {
        if (parse_url($relativeUrl, PHP_URL_SCHEME) !== null) {
            return $relativeUrl;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        if (str_starts_with($relativeUrl, '/')) {
            return "{$scheme}://{$host}{$relativeUrl}";
        }

        $path = dirname($parsed['path'] ?? '');
        return "{$scheme}://{$host}" . rtrim($path, '/') . '/' . ltrim($relativeUrl, '/');
    }
}
