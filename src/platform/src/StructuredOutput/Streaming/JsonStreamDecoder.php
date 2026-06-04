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
 * Pull-based, recursive-descent JSON decoder driving a {@see StreamCursor} through a {@see JsonTokenizer}.
 *
 * The decoder is a coroutine: each decode step pulls characters from the cursor and suspends (propagating
 * {@see Suspended}) whenever the cursor starves, resuming once more bytes are pushed. This lets a pull parser
 * live inside a push pipeline (the stream listener feeds fragments and drains progress).
 *
 * Per the two-tier data contract, decoding is deliberately LOOSE and untyped: as the document arrives it is
 * assembled into a {@see PartialTree} (the runtime "DeepPartial") of plain arrays and scalars, identical to
 * `json_decode($json, true)`, and {@see ProgressEvent}s are emitted as nodes complete. No typing, coercion or
 * validation happens here — that is a strictly terminal concern (the listener denormalizes the completed tree
 * into the validated DTO once the stream ends).
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class JsonStreamDecoder
{
    public function __construct(
        private readonly StreamCursor $cursor,
        private readonly JsonTokenizer $tokenizer,
        private readonly PartialTree $tree = new PartialTree(),
    ) {
    }

    /**
     * The loose accumulator being assembled. Consumers may snapshot it via {@see PartialTree::root()}.
     */
    public function getTree(): PartialTree
    {
        return $this->tree;
    }

    /**
     * Convenience driver: feeds all fragments, then closes the stream, and returns the decoded loose value.
     *
     * @param iterable<string>                    $chunks
     * @param (callable(ProgressEvent):void)|null $onProgress invoked for each progress event as it occurs
     *
     * @throws IncompleteJsonException when the fragments do not form a complete JSON document
     */
    public function decodeAll(iterable $chunks, ?callable $onProgress = null): mixed
    {
        $run = $this->decode();

        foreach ($chunks as $chunk) {
            $this->cursor->push($chunk);
            $this->drain($run, $onProgress);
        }

        $this->cursor->end();
        $this->drain($run, $onProgress);

        if ($run->valid()) {
            throw new IncompleteJsonException('Streamed JSON ended before the document was complete.');
        }

        return $run->getReturn();
    }

    /**
     * @return \Generator<int, ProgressEvent|Suspended, mixed, mixed>
     */
    public function decode(): \Generator
    {
        $this->tree->reset();

        yield from $this->tokenizer->skipWhitespace($this->cursor);
        yield from $this->decodeValue('');

        return $this->tree->root();
    }

    /**
     * Resumes the decode coroutine until it either completes or genuinely starves (suspended with no buffered
     * input and no end-of-stream signal yet). Progress events are forwarded to the optional callback.
     *
     * @param \Generator<int, ProgressEvent|Suspended, mixed, mixed> $run
     * @param (callable(ProgressEvent):void)|null                    $onProgress
     */
    private function drain(\Generator $run, ?callable $onProgress): void
    {
        while ($run->valid()) {
            $yielded = $run->current();

            if ($yielded instanceof Suspended) {
                if (!$this->cursor->hasBuffered() && !$this->cursor->isEnded()) {
                    return;
                }

                $run->next();

                continue;
            }

            if ($yielded instanceof ProgressEvent && null !== $onProgress) {
                $onProgress($yielded);
            }

            $run->next();
        }
    }

    /**
     * @return \Generator<int, ProgressEvent|Suspended, mixed, void>
     */
    private function decodeValue(string $pointer): \Generator
    {
        yield from $this->tokenizer->skipWhitespace($this->cursor);

        $char = yield from $this->cursor->peek();

        if (null === $char) {
            throw new IncompleteJsonException('Unexpected end of streamed JSON; expected a value.');
        }

        if ('{' === $char) {
            yield from $this->decodeObject($pointer);

            return;
        }

        if ('[' === $char) {
            yield from $this->decodeArray($pointer);

            return;
        }

        if ('"' === $char) {
            $string = yield from $this->tokenizer->readString($this->cursor);
            $this->tree->set($pointer, $string);
            yield new ProgressEvent($pointer, ProgressKind::ScalarSet, $string);

            return;
        }

        $token = yield from $this->tokenizer->readBareToken($this->cursor);
        $value = $this->interpretBareToken($token);
        $this->tree->set($pointer, $value);
        yield new ProgressEvent($pointer, ProgressKind::ScalarSet, $value);
    }

    /**
     * @return \Generator<int, ProgressEvent|Suspended, mixed, void>
     */
    private function decodeObject(string $pointer): \Generator
    {
        yield from $this->cursor->consume();

        $this->tree->set($pointer, []);
        yield new ProgressEvent($pointer, ProgressKind::ContainerOpen);

        yield from $this->tokenizer->skipWhitespace($this->cursor);
        $char = yield from $this->cursor->peek();

        if (null === $char) {
            throw new IncompleteJsonException('Unterminated object in streamed JSON.');
        }

        if ('}' === $char) {
            yield from $this->cursor->consume();
            yield new ProgressEvent($pointer, ProgressKind::ContainerClose);

            return;
        }

        while (true) {
            yield from $this->tokenizer->skipWhitespace($this->cursor);
            $key = yield from $this->tokenizer->readString($this->cursor);

            yield from $this->tokenizer->skipWhitespace($this->cursor);
            $colon = yield from $this->cursor->consume();

            if (null === $colon) {
                throw new IncompleteJsonException('Unterminated object in streamed JSON; expected ":".');
            }

            if (':' !== $colon) {
                throw new InvalidArgumentException(\sprintf('Expected ":" after object key, got "%s".', $colon));
            }

            yield from $this->decodeValue($pointer.'/'.PartialTree::escapeToken($key));

            yield from $this->tokenizer->skipWhitespace($this->cursor);
            $separator = yield from $this->cursor->consume();

            if (null === $separator) {
                throw new IncompleteJsonException('Unterminated object in streamed JSON; expected "," or "}".');
            }

            if ('}' === $separator) {
                yield new ProgressEvent($pointer, ProgressKind::ContainerClose);

                return;
            }

            if (',' !== $separator) {
                throw new InvalidArgumentException(\sprintf('Expected "," or "}" in object, got "%s".', $separator));
            }
        }
    }

    /**
     * @return \Generator<int, ProgressEvent|Suspended, mixed, void>
     */
    private function decodeArray(string $pointer): \Generator
    {
        yield from $this->cursor->consume();

        $this->tree->set($pointer, []);
        yield new ProgressEvent($pointer, ProgressKind::ContainerOpen);

        yield from $this->tokenizer->skipWhitespace($this->cursor);
        $char = yield from $this->cursor->peek();

        if (null === $char) {
            throw new IncompleteJsonException('Unterminated array in streamed JSON.');
        }

        if (']' === $char) {
            yield from $this->cursor->consume();
            yield new ProgressEvent($pointer, ProgressKind::ContainerClose);

            return;
        }

        $index = 0;

        while (true) {
            yield from $this->decodeValue($pointer.'/'.$index);
            ++$index;

            yield from $this->tokenizer->skipWhitespace($this->cursor);
            $separator = yield from $this->cursor->consume();

            if (null === $separator) {
                throw new IncompleteJsonException('Unterminated array in streamed JSON; expected "," or "]".');
            }

            if (']' === $separator) {
                yield new ProgressEvent($pointer, ProgressKind::ContainerClose);

                return;
            }

            if (',' !== $separator) {
                throw new InvalidArgumentException(\sprintf('Expected "," or "]" in array, got "%s".', $separator));
            }
        }
    }

    private function interpretBareToken(string $token): int|float|bool|null
    {
        if ('true' === $token) {
            return true;
        }

        if ('false' === $token) {
            return false;
        }

        if ('null' === $token) {
            return null;
        }

        return $this->parseNumber($token);
    }

    private function parseNumber(string $token): int|float
    {
        if ('' === $token) {
            throw new IncompleteJsonException('Expected a value in streamed JSON.');
        }

        // Integer form: keep as int when it round-trips, otherwise fall back to float on overflow
        // (this matches json_decode()'s default behaviour without JSON_BIGINT_AS_STRING).
        if (1 === preg_match('/^-?(?:0|[1-9]\d*)$/', $token)) {
            $asInt = (int) $token;

            if ((string) $asInt === $token) {
                return $asInt;
            }

            return (float) $token;
        }

        if (1 === preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/', $token)) {
            return (float) $token;
        }

        throw new InvalidArgumentException(\sprintf('Invalid JSON token "%s" in streamed JSON.', $token));
    }
}
