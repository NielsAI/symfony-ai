<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\StructuredOutput\Validator;

use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ObjectCompleteDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 *
 * @author Valtteri R <valtzu@gmail.com>
 */
final class ValidatorResultConverter implements ResultConverterInterface
{
    public function __construct(
        private readonly ResultConverterInterface $innerConverter,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function supports(Model $model): bool
    {
        return $this->innerConverter->supports($model);
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $innerResult = $this->innerConverter->convert($result, $options);

        if ($innerResult instanceof StreamResult) {
            return $this->validateStream($innerResult, $result);
        }

        if (!$innerResult instanceof ObjectResult) {
            return $innerResult;
        }

        $structure = $innerResult->getContent();
        $violations = $this->validator->validate($structure);

        if (0 !== \count($violations)) {
            throw new ValidationException($violations);
        }

        return $innerResult;
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return $this->innerConverter->getTokenUsageExtractor();
    }

    /**
     * Validation for streamed structured output is strictly terminal: it runs only on the single
     * {@see ObjectCompleteDelta} emitted at stream end, never on the loose {@see \Symfony\AI\Platform\Result\Stream\Delta\PartialObjectDelta}
     * snapshots (which are half-built graphs that would spuriously fail NotBlank/NotNull-style constraints).
     *
     * The wrapping consumes the inner stream's already-decoded deltas, so the terminal object is visible here as a
     * concrete delta (it is otherwise fanned out inside a generator and invisible to a sibling listener).
     */
    private function validateStream(StreamResult $stream, RawResultInterface $result): StreamResult
    {
        $validator = $this->validator;

        $generator = (static function () use ($stream, $validator): \Generator {
            foreach ($stream->getContent() as $delta) {
                if ($delta instanceof ObjectCompleteDelta && \is_object($delta->object)) {
                    $violations = $validator->validate($delta->object);

                    if (0 !== \count($violations)) {
                        throw new ValidationException($violations);
                    }
                }

                yield $delta;
            }
        })();

        $validated = new StreamResult($generator);
        $validated->setRawResult($result);

        return $validated;
    }
}
