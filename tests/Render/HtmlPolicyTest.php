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

namespace Alto\Rst\Tests\Render;

use Alto\Rst\Render\HtmlPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlPolicy::class)]
final class HtmlPolicyTest extends TestCase
{
    public function testSafeAllowedSchemes(): void
    {
        self::assertSame(['http', 'https', 'mailto', 'tel'], HtmlPolicy::safe()->allowedSchemes());
    }

    public function testSafeReturnsSameInstance(): void
    {
        self::assertSame(HtmlPolicy::safe(), HtmlPolicy::safe());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedUrls(): iterable
    {
        yield 'http' => ['http://example.com/'];
        yield 'https' => ['https://example.com/page'];
        yield 'mailto' => ['mailto:user@example.com'];
        yield 'tel' => ['tel:+33123456789'];
        yield 'uppercase https' => ['HTTPS://example.com/'];
        yield 'relative path' => ['docs/index.html'];
        yield 'absolute path' => ['/docs/index.html'];
        yield 'fragment' => ['#section-title'];
        yield 'query only' => ['?page=2'];
        yield 'empty' => [''];
        yield 'scheme-relative' => ['//example.com/'];
        yield 'dot segment with colon later' => ['./path:with:colons'];
    }

    #[DataProvider('allowedUrls')]
    public function testSafeAllowsUrl(string $url): void
    {
        self::assertTrue(HtmlPolicy::safe()->isUrlAllowed($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'javascript uppercase' => ['JAVASCRIPT:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'data mixed case' => ['DaTa:text/html;base64,PHNjcmlwdD4='];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'vbscript mixed case' => ['VbScRiPt:msgbox(1)'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'ftp' => ['ftp://example.com/file'];
        yield 'leading whitespace' => [' javascript:alert(1)'];
        yield 'tab inside scheme' => ["java\tscript:alert(1)"];
        yield 'newline inside scheme' => ["java\nscript:alert(1)"];
        yield 'carriage return inside scheme' => ["java\rscript:alert(1)"];
        yield 'null byte inside scheme' => ["java\0script:alert(1)"];
        yield 'vertical tab inside scheme' => ["java\x0Bscript:alert(1)"];
        yield 'form feed inside scheme' => ["java\x0Cscript:alert(1)"];
        yield 'unit separator inside scheme' => ["java\x1Fscript:alert(1)"];
        yield 'percent-encoded tab' => ['java%09script:alert(1)'];
        yield 'percent-encoded newline' => ['java%0Ascript:alert(1)'];
        yield 'percent-encoded newline lowercase' => ['java%0ascript:alert(1)'];
        yield 'percent-encoded vertical tab' => ['java%0Bscript:alert(1)'];
        yield 'percent-encoded form feed' => ['java%0Cscript:alert(1)'];
        yield 'percent-encoded carriage return' => ['java%0Dscript:alert(1)'];
        yield 'percent-encoded null' => ['java%00script:alert(1)'];
        yield 'percent-encoded unit separator' => ['java%1Fscript:alert(1)'];
        yield 'percent-encoded space' => ['java%20script:alert(1)'];
        yield 'whitespace and case mix' => ["  \tJaVa%0DScRiPt:alert(1)"];
    }

    #[DataProvider('rejectedUrls')]
    public function testSafeRejectsUrl(string $url): void
    {
        self::assertFalse(HtmlPolicy::safe()->isUrlAllowed($url));
    }

    public function testWithAllowedSchemesReplacesTheAllowlist(): void
    {
        $policy = HtmlPolicy::safe()->withAllowedSchemes('ftp', 'HTTPS');

        self::assertSame(['ftp', 'https'], $policy->allowedSchemes());
        self::assertTrue($policy->isUrlAllowed('ftp://example.com/'));
        self::assertTrue($policy->isUrlAllowed('https://example.com/'));
        self::assertFalse($policy->isUrlAllowed('http://example.com/'));
        self::assertFalse($policy->isUrlAllowed('javascript:alert(1)'));
    }

    public function testWithAllowedSchemesDoesNotMutateTheOriginal(): void
    {
        $safe = HtmlPolicy::safe();
        $safe->withAllowedSchemes('ftp');

        self::assertSame(['http', 'https', 'mailto', 'tel'], $safe->allowedSchemes());
        self::assertFalse($safe->isUrlAllowed('ftp://example.com/'));
    }
}
