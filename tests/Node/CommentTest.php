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

use Alto\Rst\Node\Comment;
use Alto\Rst\Source\ByteSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Comment::class)]
final class CommentTest extends TestCase
{
    public function testConstruction(): void
    {
        $comment = new Comment(ByteSpan::of(0, 12), 'a comment');

        self::assertSame(0, $comment->span()->start);
        self::assertSame('a comment', $comment->text);
    }

    public function testEmptyComment(): void
    {
        $comment = new Comment(ByteSpan::of(0, 2));

        self::assertSame('', $comment->text);
    }
}
