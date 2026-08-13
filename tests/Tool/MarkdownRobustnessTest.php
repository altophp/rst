<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Rst\Tests\Tool;

use Alto\Rst\Convert\ConversionOptions;
use Alto\Rst\Convert\Markdown\MarkdownReader;
use Alto\Rst\Convert\MarkdownToRst;
use Alto\Rst\Rst;
use PHPUnit\Framework\TestCase;

final class MarkdownRobustnessTest extends TestCase
{
    public function testDeterministicMalformedMarkdownMutationsStayConvertible(): void
    {
        $seeds = [
            '',
            "# Heading\n\nParagraph with *emphasis* and **strong text**.\n",
            "> quote\n>\n> - nested item\n",
            "1. ordered\n2. list\n",
            "```php\n<?php echo 'x';\n```\n",
            "[label]: <https://example.com/a\\>b> \"Title\"\n\n[link][label]\n",
            "| left | right |\n| :--- | ---: |\n| a | b |\n",
            "<!-- comment -->\n<div>block</div>\n",
            "[[[[[[\n",
            "[text](\n",
            "```\nno closing fence",
            str_repeat('`', 128) . "\n",
            "a\0b\n",
            "a\xFFb\n",
        ];
        $reader = new MarkdownReader();
        $converter = new MarkdownToRst();
        $count = 0;

        foreach ($seeds as $seed) {
            foreach (self::mutations($seed) as $markdown) {
                $document = $reader->read($markdown);
                $rst = $converter->convert($document, ConversionOptions::symfony())->output;
                Rst::symfony()->parse($rst);
                ++$count;
            }
        }

        self::assertGreaterThan(300, $count);
    }

    /**
     * @return list<string>
     */
    private static function mutations(string $input): array
    {
        $length = \strlen($input);
        $mutations = [$input, '', "\0", "\r", "\n", $input . $input];

        foreach ([0, intdiv($length, 3), intdiv(2 * $length, 3), $length] as $offset) {
            $mutations[] = substr($input, 0, $offset);

            foreach (["\0", "\xFF", "\n", "\r\n", '# ', '> ', '- ', '1. ', '`', '*', '[', ']', '(', ')', '|', '\\'] as $token) {
                $mutations[] = substr($input, 0, $offset) . $token . substr($input, $offset);
            }

            if ($offset < $length) {
                $mutations[] = substr($input, 0, $offset) . substr($input, $offset + 1);
            }
        }

        return array_values(array_unique($mutations));
    }
}
