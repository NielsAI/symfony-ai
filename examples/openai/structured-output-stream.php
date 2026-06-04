<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\ObjectCompleteDelta;
use Symfony\AI\Platform\Result\Stream\Delta\PartialObjectDelta;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Tests\Fixtures\StructuredOutput\MathReasoning;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once dirname(__DIR__).'/bootstrap.php';

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new PlatformSubscriber());

$platform = Factory::createPlatform(env('OPENAI_API_KEY'), http_client(), eventDispatcher: $dispatcher);
$messages = new MessageBag(
    Message::forSystem('You are a helpful math tutor. Guide the user through the solution step by step.'),
    Message::ofUser('how can I solve 8x + 7 = -23'),
);

// Combine streaming with structured output.
$result = $platform->invoke('gpt-5-mini', $messages, [
    'stream' => true,
    'response_format' => MathReasoning::class,
]);

// During the stream you receive loose "deep partial" snapshots (plain arrays, no validation);
// at the very end you receive exactly one fully typed and validated DTO.
foreach ($result->asObjectStream() as $delta) {
    if ($delta instanceof PartialObjectDelta) {
        echo "partial @ {$delta->pointer}: ".json_encode($delta->snapshot).\PHP_EOL;
    }

    if ($delta instanceof ObjectCompleteDelta) {
        echo \PHP_EOL.'final object:'.\PHP_EOL;
        dump($delta->object);
    }
}
