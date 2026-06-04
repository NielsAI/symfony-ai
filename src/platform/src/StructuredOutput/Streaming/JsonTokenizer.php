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

use Symfony\AI\Platform\Exception\IncompleteJsonException;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * Low-level JSON token reader. Pulls bytes from a {@see StreamCursor}, suspending transparently across
 * fragment boundaries (a token split over several {@see StreamCursor::push()} calls is read seamlessly).
 *
 * This is the only byte-level state of the streaming engine; it is deliberately structure-agnostic
 * (no objects/arrays) — recursion lives in {@see JsonStreamDecoder}.
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class JsonTokenizer
{
    private const WHITESPACE = [' ' => true, "\t" => true, "\n" => true, "\r" => true];
    private const TOKEN_DELIMITERS = [
        ',' => true, ']' => true, '}' => true, ':' => true,
        ' ' => true, "\t" => true, "\n" => true, "\r" => true,
    ];

    /**
     * Consumes any leading insignificant whitespace.
     *
     * @return \Generator<int, Suspended, mixed, void>
     */
    public function skipWhitespace(StreamCursor $cursor): \Generator
    {
        while (true) {
            $char = yield from $cursor->peek();

            if (null === $char || !isset(self::WHITESPACE[$char])) {
                return;
            }

            yield from $cursor->consume();
        }
    }

    /**
     * Reads a JSON string literal. The next buffered byte must be the opening quote.
     *
     * @return \Generator<int, Suspended, mixed, string>
     */
    public function readString(StreamCursor $cursor): \Generator
    {
        $open = yield from $cursor->consume();

        if ('"' !== $open) {
            throw new InvalidArgumentException(\sprintf('Expected start of string, got "%s".', $open ?? 'EOF'));
        }

        $result = '';

        while (true) {
            $char = yield from $cursor->consume();

            if (null === $char) {
                throw new IncompleteJsonException('Unterminated string literal in streamed JSON.');
            }

            if ('"' === $char) {
                return $result;
            }

            if ('\\' === $char) {
                $result .= yield from $this->readEscape($cursor);

                continue;
            }

            $result .= $char;
        }
    }

    /**
     * Reads a bare token (number, true, false, null) up to the next delimiter or end of stream.
     *
     * @return \Generator<int, Suspended, mixed, string>
     */
    public function readBareToken(StreamCursor $cursor): \Generator
    {
        $result = '';

        while (true) {
            $char = yield from $cursor->peek();

            if (null === $char || isset(self::TOKEN_DELIMITERS[$char])) {
                return $result;
            }

            $result .= yield from $cursor->consume();
        }
    }

    /**
     * @return \Generator<int, Suspended, mixed, string>
     */
    private function readEscape(StreamCursor $cursor): \Generator
    {
        $char = yield from $cursor->consume();

        if (null === $char) {
            throw new IncompleteJsonException('Unterminated escape sequence in streamed JSON.');
        }

        return match ($char) {
            '"' => '"',
            '\\' => '\\',
            '/' => '/',
            'b' => "\x08",
            'f' => "\f",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'u' => yield from $this->readUnicodeEscape($cursor),
            default => throw new InvalidArgumentException(\sprintf('Invalid escape sequence "\\%s" in streamed JSON.', $char)),
        };
    }

    /**
     * @return \Generator<int, Suspended, mixed, string>
     */
    private function readUnicodeEscape(StreamCursor $cursor): \Generator
    {
        $code = yield from $this->readHex4($cursor);

        // High surrogate: a low surrogate (\uDC00-\uDFFF) must follow to form a single codepoint.
        if (0xD800 <= $code && $code <= 0xDBFF) {
            $backslash = yield from $cursor->consume();
            $marker = yield from $cursor->consume();

            if (null === $backslash || null === $marker) {
                throw new IncompleteJsonException('Unterminated surrogate pair in streamed JSON.');
            }

            if ('\\' !== $backslash || 'u' !== $marker) {
                throw new InvalidArgumentException('Invalid surrogate pair in streamed JSON.');
            }

            $low = yield from $this->readHex4($cursor);

            if ($low < 0xDC00 || $low > 0xDFFF) {
                throw new InvalidArgumentException('Invalid low surrogate in streamed JSON.');
            }

            $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
        }

        return $this->encodeCodepoint($code);
    }

    /**
     * @return \Generator<int, Suspended, mixed, int>
     */
    private function readHex4(StreamCursor $cursor): \Generator
    {
        $hex = '';

        for ($i = 0; $i < 4; ++$i) {
            $char = yield from $cursor->consume();

            if (null === $char) {
                throw new IncompleteJsonException('Unterminated unicode escape in streamed JSON.');
            }

            if (1 !== preg_match('/[0-9a-fA-F]/', $char)) {
                throw new InvalidArgumentException(\sprintf('Invalid unicode escape digit "%s" in streamed JSON.', $char));
            }

            $hex .= $char;
        }

        return (int) hexdec($hex);
    }

    private function encodeCodepoint(int $codepoint): string
    {
        if ($codepoint < 0x80) {
            return \chr($codepoint);
        }

        if ($codepoint < 0x800) {
            return \chr(0xC0 | ($codepoint >> 6))
                .\chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint < 0x10000) {
            return \chr(0xE0 | ($codepoint >> 12))
                .\chr(0x80 | (($codepoint >> 6) & 0x3F))
                .\chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr(0xF0 | ($codepoint >> 18))
            .\chr(0x80 | (($codepoint >> 12) & 0x3F))
            .\chr(0x80 | (($codepoint >> 6) & 0x3F))
            .\chr(0x80 | ($codepoint & 0x3F));
    }
}
