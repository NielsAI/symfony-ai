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
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\Delta\ObjectCompleteDelta;
use Symfony\AI\Platform\Result\Stream\Delta\PartialObjectDelta;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\Stream\StartEvent;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Bridges the push-based {@see \Symfony\AI\Platform\Result\StreamResult} pipeline (Approach #3 delivery) onto
 * the pull-based {@see JsonStreamDecoder} engine (Approach #2).
 *
 * For each JSON-bearing delta it pushes the fragment into the decoder, resumes it, and replaces the raw delta
 * with a generator of {@see PartialObjectDelta}s (the loose "DeepPartial" tier). When the root document closes
 * it appends exactly one {@see ObjectCompleteDelta} — the only place where typing and validation happen
 * (terminal denormalization). Non-JSON deltas (thinking, metadata, binary, …) pass through untouched.
 *
 * A fresh instance must be used per stream (it carries per-stream decode state, reset in {@see onStart()}).
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class StructuredOutputStreamListener extends AbstractStreamListener
{
    private JsonStreamDecoder $decoder;
    private bool $completed = false;
    private bool $sawJson = false;

    /**
     * @param class-string|null $outputType the target DTO type; null streams the loose array as-is
     */
    public function __construct(
        private readonly ChannelDemux $demux = new ChannelDemux(),
        private readonly ?DenormalizerInterface $denormalizer = null,
        private readonly ?string $outputType = null,
        private readonly ?object $objectToPopulate = null,
    ) {
    }

    public function onStart(StartEvent $event): void
    {
        $this->decoder = new JsonStreamDecoder(new StreamCursor(), new JsonTokenizer());
        $this->decoder->open();
        $this->completed = false;
        $this->sawJson = false;
    }

    public function onDelta(DeltaEvent $event): void
    {
        if ($this->completed) {
            $event->skipDelta();

            return;
        }

        $fragment = $this->demux->fragment($event->getDelta());

        if (null === $fragment) {
            return;
        }

        $this->sawJson = true;

        $progress = $this->decoder->feed($fragment);
        $deltas = [];

        foreach ($progress as $progressEvent) {
            $snapshot = $this->decoder->getTree()->root();

            if (\is_array($snapshot)) {
                $deltas[] = new PartialObjectDelta(
                    $snapshot,
                    $progressEvent->pointer,
                    ProgressKind::ContainerOpen !== $progressEvent->kind,
                );
            }
        }

        if ($this->decoder->isComplete()) {
            $this->completed = true;
            $deltas[] = new ObjectCompleteDelta($this->buildResult());
        }

        $event->setDelta((static function () use ($deltas): \Generator {
            yield from $deltas;
        })());
    }

    public function onComplete(CompleteEvent $event): void
    {
        if ($this->completed) {
            return;
        }

        $this->decoder->close();

        if ($this->decoder->isComplete()) {
            $this->completed = true;

            return;
        }

        if (!$this->sawJson) {
            throw new RuntimeException('The structured output stream produced no JSON content (possible refusal or empty response).');
        }

        throw new IncompleteJsonException('The structured output stream ended before the JSON document was complete.');
    }

    /**
     * @return object|array<string, mixed>
     */
    private function buildResult(): object|array
    {
        $tree = $this->decoder->result();

        if (null === $this->outputType || null === $this->denormalizer) {
            \assert(\is_array($tree));

            return $tree;
        }

        $context = [];
        if (null !== $this->objectToPopulate) {
            $context[AbstractNormalizer::OBJECT_TO_POPULATE] = $this->objectToPopulate;
        }

        return $this->denormalizer->denormalize($tree, $this->outputType, null, $context);
    }
}
