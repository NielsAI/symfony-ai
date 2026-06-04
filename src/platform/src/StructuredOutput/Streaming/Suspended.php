<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\StructuredOutput\Streaming;

/**
 * Coroutine convention marker.
 *
 * The streaming decoder is a pull parser: it pulls characters from a {@see StreamCursor}. When the cursor
 * has no byte available yet (and the stream has not ended), the pull chain yields this singleton to signal
 * "I am starved, feed me more bytes and resume". The driver (see the stream listener / {@see JsonStreamDecoder::decodeAll()})
 * distinguishes a suspension (this marker) from any real value a generator may yield (e.g. progress events)
 * and from the generator's return value (the decoded result).
 *
 * Rationale for a dedicated marker instead of `yield null`: `null` is a legitimate decoded value and a
 * legitimate end-of-stream char, so reusing it would be ambiguous.
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class Suspended
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
