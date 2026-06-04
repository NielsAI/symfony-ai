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
 * A byte-level pull cursor over an incrementally-fed buffer.
 *
 * Producers call {@see push()} as stream fragments arrive and {@see end()} when the stream is complete.
 * Consumers (the tokenizer/decoder) pull bytes through the coroutine methods {@see peek()} and {@see consume()},
 * which suspend (yield {@see Suspended}) when no byte is buffered yet and the stream has not ended.
 *
 * The cursor operates on raw bytes, not codepoints: JSON grammar outside string literals is ASCII, and string
 * contents are accumulated byte-by-byte by the tokenizer, so a multibyte UTF-8 sequence split across two
 * {@see push()} calls reassembles correctly without any codepoint-aware buffering here.
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class StreamCursor
{
    private const COMPACT_THRESHOLD = 8192;

    private string $buffer = '';
    private int $offset = 0;
    private bool $ended = false;

    public function push(string $fragment): void
    {
        $this->buffer .= $fragment;
    }

    public function end(): void
    {
        $this->ended = true;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function hasBuffered(): bool
    {
        return $this->offset < \strlen($this->buffer);
    }

    /**
     * Returns the next byte without consuming it, or null at end of stream.
     *
     * @return \Generator<int, Suspended, mixed, string|null>
     */
    public function peek(): \Generator
    {
        while ($this->offset >= \strlen($this->buffer)) {
            if ($this->ended) {
                return null;
            }

            yield Suspended::instance();
        }

        return $this->buffer[$this->offset];
    }

    /**
     * Returns and consumes the next byte, or null at end of stream.
     *
     * @return \Generator<int, Suspended, mixed, string|null>
     */
    public function consume(): \Generator
    {
        $char = yield from $this->peek();

        if (null === $char) {
            return null;
        }

        ++$this->offset;

        if ($this->offset >= self::COMPACT_THRESHOLD) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }

        return $char;
    }
}
