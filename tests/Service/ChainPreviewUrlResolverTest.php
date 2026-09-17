<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Tests\Service;

use PHPUnit\Framework\TestCase;
use ThinkDigital\ContaoLivePreview\Service\ChainPreviewUrlResolver;
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolver;
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolverInterface;

/**
 * @covers \ThinkDigital\ContaoLivePreview\Service\ChainPreviewUrlResolver
 */
final class ChainPreviewUrlResolverTest extends TestCase
{
    public function testReturnsFirstNonNullResultInPriorityOrder(): void
    {
        $first = $this->createMock(PreviewUrlResolverInterface::class);
        $first->method('resolve')->with('tl_news', 42)->willReturn(null);

        $second = $this->createMock(PreviewUrlResolverInterface::class);
        $second->method('resolve')->with('tl_news', 42)->willReturn(['pageId' => 1, 'alias' => 'news-page']);

        $third = $this->createMock(PreviewUrlResolverInterface::class);
        $third->expects($this->never())->method('resolve');

        $core = $this->createMock(PreviewUrlResolver::class);

        $chain = new ChainPreviewUrlResolver([$first, $second, $third], $core);

        $this->assertSame(['pageId' => 1, 'alias' => 'news-page'], $chain->resolve('tl_news', 42));
    }

    public function testReturnsNullWhenNoResolverClaimsTheTable(): void
    {
        $resolver = $this->createMock(PreviewUrlResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $core = $this->createMock(PreviewUrlResolver::class);

        $chain = new ChainPreviewUrlResolver([$resolver], $core);

        $this->assertNull($chain->resolve('tl_unknown_table', 1));
    }

    public function testSkipsItselfWhenPresentInTheTaggedCollection(): void
    {
        // The chain service is tagged with its own interface (AutoconfigureTag),
        // so it can legally appear in its own $resolvers collection — it must
        // not call back into itself and cause infinite recursion.
        $core = $this->createMock(PreviewUrlResolver::class);
        $chain = new ChainPreviewUrlResolver([], $core);

        $resolvers = [$chain, $this->createMock(PreviewUrlResolverInterface::class)];
        $outer = new ChainPreviewUrlResolver($resolvers, $core);

        $this->assertNull($outer->resolve('tl_content', 1));
    }

    public function testResolveRootPageAlwaysDelegatesToTheCoreResolver(): void
    {
        $core = $this->createMock(PreviewUrlResolver::class);
        $core->expects($this->once())
            ->method('resolveRootPage')
            ->willReturn(['pageId' => 7, 'alias' => 'home']);

        // Even a third-party resolver claiming a non-null resolveRootPage()
        // must be ignored — only the bundle's own core resolver may answer this.
        $thirdParty = $this->createMock(PreviewUrlResolverInterface::class);
        $thirdParty->expects($this->never())->method('resolveRootPage');

        $chain = new ChainPreviewUrlResolver([$thirdParty], $core);

        $this->assertSame(['pageId' => 7, 'alias' => 'home'], $chain->resolveRootPage());
    }
}
