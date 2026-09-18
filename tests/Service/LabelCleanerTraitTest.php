<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use ThinkDigital\ContaoLivePreview\Service\LabelCleanerTrait;

/**
 * @covers \ThinkDigital\ContaoLivePreview\Service\LabelCleanerTrait
 */
final class LabelCleanerTraitTest extends TestCase
{
    /**
     * @dataProvider cleanLabelProvider
     */
    public function testCleanLabelStripsWrapperAndBoundaryMarkers(string $input, string $expected): void
    {
        $subject = new class {
            use LabelCleanerTrait;

            public function clean(string $label): string
            {
                return $this->cleanLabel($label);
            }
        };

        $this->assertSame($expected, $subject->clean($input));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cleanLabelProvider(): iterable
    {
        yield 'trailing Anfang' => ['Element-Gruppe Anfang', 'Element-Gruppe'];
        yield 'trailing Ende' => ['Element-Gruppe Ende', 'Element-Gruppe'];
        yield 'trailing Wrapper Start' => ['Spalten Wrapper Start', 'Spalten'];
        yield 'trailing Wrapper End' => ['Spalten Wrapper End', 'Spalten'];
        yield 'bare trailing Wrapper' => ['Grid Wrapper', 'Grid'];
        yield 'colon before marker' => ['Gruppe: Start', 'Gruppe'];
        yield 'collapses double spaces' => ['Grid   Spalte', 'Grid Spalte'];
        yield 'leaves unrelated labels untouched' => ['Bild', 'Bild'];
        yield 'case-insensitive marker match' => ['Gruppe ENDE', 'Gruppe'];
    }

    public function testResolveLabelReturnsCleanedTranslationWhenFound(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params, ?string $domain) => 'CTE.text.0' === $id ? 'Text Ende' : $id,
        );

        $subject = new class {
            use LabelCleanerTrait;

            public function resolve(string $type, TranslatorInterface $translator): string
            {
                return $this->resolveLabel($type, $translator);
            }
        };

        $this->assertSame('Text', $subject->resolve('text', $translator));
    }

    public function testResolveLabelReturnsEmptyStringWhenNoTranslationExistsInEitherDomain(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        // Contao's translator returns the key itself when no translation is found.
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $subject = new class {
            use LabelCleanerTrait;

            public function resolve(string $type, TranslatorInterface $translator): string
            {
                return $this->resolveLabel($type, $translator);
            }
        };

        $this->assertSame('', $subject->resolve('unknown_type', $translator));
    }

    public function testResolveLabelReturnsEmptyStringForEmptyType(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->never())->method('trans');

        $subject = new class {
            use LabelCleanerTrait;

            public function resolve(string $type, TranslatorInterface $translator): string
            {
                return $this->resolveLabel($type, $translator);
            }
        };

        $this->assertSame('', $subject->resolve('', $translator));
    }
}
