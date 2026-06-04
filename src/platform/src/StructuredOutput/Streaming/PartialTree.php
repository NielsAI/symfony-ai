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
 * The loose, untyped accumulator for a streamed JSON document — the runtime equivalent of a "DeepPartial<T>".
 *
 * Values are written by RFC 6901 JSON Pointer as the decoder completes them; intermediate containers are
 * auto-vivified. No typing, coercion or validation happens here: that is strictly a terminal concern.
 *
 * Snapshots returned by {@see root()} are stable: PHP copy-on-write means a snapshot taken before a later
 * {@see set()} is not mutated by it. Note that taking a full snapshot after every write is O(n) per write
 * (O(n^2) overall); consumers that need to stay linear should react to the incremental pointer/value of each
 * {@see ProgressEvent} instead of copying the whole tree per tick.
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class PartialTree
{
    private mixed $root = null;
    private bool $initialized = false;

    public function set(string $pointer, mixed $value): void
    {
        if ('' === $pointer) {
            $this->root = $value;
            $this->initialized = true;

            return;
        }

        if (!\is_array($this->root)) {
            $this->root = [];
        }

        $this->initialized = true;

        $tokens = self::parsePointer($pointer);
        $lastIndex = array_key_last($tokens);

        $cursor = &$this->root;

        foreach ($tokens as $index => $token) {
            if ($index === $lastIndex) {
                $cursor[$token] = $value;

                return;
            }

            if (!isset($cursor[$token]) || !\is_array($cursor[$token])) {
                $cursor[$token] = [];
            }

            $cursor = &$cursor[$token];
        }
    }

    public function root(): mixed
    {
        return $this->root;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function reset(): void
    {
        $this->root = null;
        $this->initialized = false;
    }

    /**
     * Escapes a single object key for use as a JSON Pointer reference token (RFC 6901).
     */
    public static function escapeToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    /**
     * @return list<string>
     */
    private static function parsePointer(string $pointer): array
    {
        $tokens = explode('/', ltrim($pointer, '/'));

        return array_map(
            static fn (string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
            $tokens,
        );
    }
}
