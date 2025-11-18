<?php

declare(strict_types=1);

use Illuminate\Pipeline\Pipeline;
use PHPeek\LaravelQueueMetrics\Contracts\MetricsHook;
use PHPeek\LaravelQueueMetrics\Support\HookPipeline;

it('runs hooks through Laravel Pipeline', function () {
    $pipeline = new Pipeline(app());
    $hookPipeline = new HookPipeline($pipeline);

    $hook = new class implements MetricsHook
    {
        public function handle(mixed $payload): mixed
        {
            if (is_array($payload)) {
                $payload['hook_executed'] = true;
            }

            return $payload;
        }

        public function shouldRun(string $context): bool
        {
            return true;
        }

        public function priority(): int
        {
            return 100;
        }
    };

    $data = ['test' => 'value'];
    $result = $hookPipeline->run([$hook], $data);

    expect($result)->toBeArray()
        ->and($result['test'])->toBe('value')
        ->and($result['hook_executed'])->toBeTrue();
});

it('preserves payload when hook returns null', function () {
    $pipeline = new Pipeline(app());
    $hookPipeline = new HookPipeline($pipeline);

    $hook = new class implements MetricsHook
    {
        public function handle(mixed $payload): mixed
        {
            return $payload; // Return as-is
        }

        public function shouldRun(string $context): bool
        {
            return true;
        }

        public function priority(): int
        {
            return 100;
        }
    };

    $data = ['original' => 'data'];
    $result = $hookPipeline->run([$hook], $data);

    expect($result)->toBe($data);
});

it('chains multiple hooks in sequence', function () {
    $pipeline = new Pipeline(app());
    $hookPipeline = new HookPipeline($pipeline);

    $hook1 = new class implements MetricsHook
    {
        public function handle(mixed $payload): mixed
        {
            if (is_array($payload)) {
                $payload['hook1'] = 'executed';
            }

            return $payload;
        }

        public function shouldRun(string $context): bool
        {
            return true;
        }

        public function priority(): int
        {
            return 100;
        }
    };

    $hook2 = new class implements MetricsHook
    {
        public function handle(mixed $payload): mixed
        {
            if (is_array($payload)) {
                $payload['hook2'] = 'executed';
            }

            return $payload;
        }

        public function shouldRun(string $context): bool
        {
            return true;
        }

        public function priority(): int
        {
            return 200;
        }
    };

    $data = ['test' => 'value'];
    $result = $hookPipeline->run([$hook1, $hook2], $data);

    expect($result)->toBeArray()
        ->and($result['hook1'])->toBe('executed')
        ->and($result['hook2'])->toBe('executed');
});

it('returns payload unchanged when no hooks provided', function () {
    $pipeline = new Pipeline(app());
    $hookPipeline = new HookPipeline($pipeline);

    $data = ['test' => 'value'];
    $result = $hookPipeline->run([], $data);

    expect($result)->toBe($data);
});
