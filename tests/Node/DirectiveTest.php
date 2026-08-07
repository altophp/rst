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

namespace Alto\Rst\Tests\Node;

use Alto\Rst\Exception\InvalidArgumentException;
use Alto\Rst\Node\Directive;
use Alto\Rst\Node\DirectiveBodyKind;
use Alto\Rst\Node\Paragraph;
use Alto\Rst\Node\Text;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Directive::class)]
final class DirectiveTest extends TestCase
{
    public function testConstruction(): void
    {
        $body = ByteSpan::between(30, 52);
        $directive = new Directive(
            ByteSpan::between(0, 52),
            'code-block',
            ['php'],
            ['linenos' => '', 'caption' => 'Example'],
            $body,
        );

        self::assertSame(0, $directive->span()->start);
        self::assertSame('code-block', $directive->name);
        self::assertSame(['php'], $directive->arguments);
        self::assertSame(['linenos' => '', 'caption' => 'Example'], $directive->options);
        self::assertSame($body, $directive->rawBody);
        self::assertSame(DirectiveBodyKind::Opaque, $directive->bodyKind);
        self::assertSame([], $directive->children());
    }

    public function testOptionOrderIsPreserved(): void
    {
        $directive = new Directive(ByteSpan::of(0, 10), 'image', [], ['width' => '10', 'alt' => 'x']);

        self::assertSame(['width', 'alt'], array_keys($directive->options));
    }

    public function testDefaults(): void
    {
        $directive = new Directive(ByteSpan::of(0, 10), 'note');

        self::assertSame([], $directive->arguments);
        self::assertSame([], $directive->options);
        self::assertNull($directive->rawBody);
    }

    public function testBlockBodyExposesTypedChildren(): void
    {
        $span = ByteSpan::of(20, 5);
        $paragraph = new Paragraph($span, new Text($span, 'Body.'));
        $directive = new Directive(
            ByteSpan::of(0, 25),
            'note',
            rawBody: $span,
            bodyKind: DirectiveBodyKind::Blocks,
            body: [$paragraph],
        );

        self::assertSame([$paragraph], $directive->children());
    }

    public function testNonBlockBodyRejectsChildren(): void
    {
        $span = ByteSpan::of(20, 5);
        $paragraph = new Paragraph($span, new Text($span, 'Body.'));

        $this->expectException(InvalidArgumentException::class);

        new Directive(
            ByteSpan::of(0, 25),
            'code',
            rawBody: $span,
            bodyKind: DirectiveBodyKind::Literal,
            body: [$paragraph],
        );
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Directive(ByteSpan::of(0, 10), '');
    }
}
