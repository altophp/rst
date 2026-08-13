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

namespace Alto\Rst\Exception;

/**
 * Raised when a source patch cannot address the original input safely.
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class SourcePatchException extends \InvalidArgumentException implements RstExceptionInterface {}
