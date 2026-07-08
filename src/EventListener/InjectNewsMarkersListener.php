<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects data-contao-table="tl_news", data-contao-id="{N}", and
 * data-contao-label="Nachrichten" into news teaser wrappers when the page is
 * loaded inside the live-preview iframe (?_clp=1).
 *
 * News teasers are rendered by ModuleNewsList/ModuleNewsReader using the
 * parseArticles hook chain. The teaser wrapper typically has a "news" CSS class
 * (e.g. <div class="news news--card">). This listener runs after the full page
 * is rendered (KernelEvents::RESPONSE) and annotates each ".news" element by
 * matching its contained link href to the tl_news database record.
 *
 * Matching strategy:
 *   The first <a href> inside each ".news" element is resolved to a tl_news
 *   record. Contao generates news URLs as /{page-alias}/{news-alias-or-id}, so
 *   we query tl_news for the alias or id matching the last URL segment. If the
 *   news archive uses a jumpTo page with a reader module, the URL may also be
 *   a direct alias like /news/my-news-article.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -196)]
class InjectNewsMarkersListener
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if ('backend' === $request->attributes->get('_scope')) {
            return;
        }

        if (!$request->query->getBoolean('_clp')) {
            return;
        }

        $response = $event->getResponse();

        if (!str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }

        $content = $response->getContent();

        if (false === $content || !str_contains($content, '</body>')) {
            return;
        }

        if (!str_contains($content, 'class="news')) {
            return;
        }

        $this->framework->initialize();

        // Find all <div class="news ..."> elements that don't already have markers.
        // The regex matches the opening tag of any element with a "news" class.
        $pattern = '/(<[a-z][a-z0-9]*\b[^>]*\bclass="[^"]*\bnews\b[^"]*"[^>]*>)/i';
        if (!preg_match_all($pattern, $content, $tagMatches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return;
        }

        // For each match, find the first <a href> inside it and resolve to a news ID.
        $injections = [];
        $label = 'Nachrichten';
        $attrString = ' data-contao-table="tl_news" data-contao-id="%d" data-contao-label="' . htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';

        foreach ($tagMatches as $m) {
            $fullTag = $m[1][0];
            $offset  = $m[1][1];

            // Skip if already annotated.
            if (str_contains($fullTag, 'data-contao-table=')) {
                continue;
            }

            // Find the closing tag to get the inner HTML.
            $tagEnd = strpos($content, '>', $offset);
            if (false === $tagEnd) {
                continue;
            }

            // Search for the first <a href="..."> within a reasonable window after this tag.
            $searchWindow = substr($content, $tagEnd, 2000);
            if (!preg_match('/<a\s[^>]*\bhref="([^"]+)"/i', $searchWindow, $linkMatch)) {
                continue;
            }

            $href = $linkMatch[1];
            $newsId = $this->resolveNewsIdFromHref($href);

            if (null === $newsId) {
                continue;
            }

            $newTag = preg_replace(
                '/(<[a-z][a-z0-9]*\b)/i',
                '$1' . sprintf($attrString, $newsId),
                $fullTag,
                1,
            ) ?? $fullTag;

            $injections[$offset] = [$offset, \strlen($fullTag), $newTag];
        }

        if ([] === $injections) {
            return;
        }

        // Apply replacements from end to start to keep byte offsets valid.
        krsort($injections);
        foreach ($injections as [$offset, $length, $newTag]) {
            $content = substr_replace($content, $newTag, $offset, $length);
        }

        $response->setContent($content);
    }

    /**
     * Resolve a news ID from a frontend URL.
     *
     * Contao news URLs look like:
     *   /aktuelles/17              → news ID 17
     *   /happy-end/ziggy-zieht...   → alias "ziggy-zieht..."
     *   /news/my-article            → alias "my-article"
     *
     * The last URL segment is either a numeric ID or an alias.
     */
    private function resolveNewsIdFromHref(string $href): ?int
    {
        // Strip query string and fragment.
        $href = preg_replace('/[?#].*$/', '', $href);

        // Get the last path segment.
        $segments = array_filter(explode('/', rtrim($href, '/')));
        $lastSegment = end($segments);

        if (false === $lastSegment || '' === $lastSegment) {
            return null;
        }

        // If the last segment is numeric, it's the news ID.
        if (ctype_digit($lastSegment)) {
            return (int) $lastSegment;
        }

        // Otherwise, treat it as an alias and look up in the database.
        $newsId = $this->connection->fetchOne(
            'SELECT id FROM tl_news WHERE alias = ? LIMIT 1',
            [$lastSegment],
        );

        return false !== $newsId ? (int) $newsId : null;
    }
}