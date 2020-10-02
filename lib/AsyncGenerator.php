<?php

namespace Amp;

/**
 * @template TValue
 * @template TSend
 * @template TReturn
 */
final class AsyncGenerator implements Pipeline
{
    /** @var Internal\EmitSource<TValue, TSend> */
    private Internal\EmitSource $source;

    /** @var Promise<TReturn> */
    private Promise $promise;

    /**
     * @param callable(mixed ...$args):\Generator $callable
     * @param mixed ...$args Arguments passed to callback.
     *
     * @throws \Error Thrown if the callable throws any exception.
     * @throws \TypeError Thrown if the callable does not return a Generator.
     */
    public function __construct(callable $callable, mixed ...$args)
    {
        $this->source = $source = new Internal\EmitSource;

        try {
            $generator = $callable(...$args);
        } catch (\Throwable $exception) {
            throw new \Error("The callable threw an exception", 0, $exception);
        }

        if (!$generator instanceof \Generator) {
            throw new \TypeError("The callable did not return a Generator");
        }

        $this->promise = async(static function () use ($generator, $source): mixed {
            $yielded = $generator->current();

            while ($generator->valid()) {
                try {
                    $yielded = $generator->send(await($source->emit($yielded)));
                } catch (DisposedException $exception) {
                    throw $exception; // Destroys generator and fails pipeline.
                } catch (\Throwable $exception) {
                    $yielded = $generator->throw($exception);
                }
            }

            return $generator->getReturn();
        });

        $this->promise->onResolve(static function (?\Throwable $exception) use ($source): void {
            if ($source->isDisposed()) {
                return; // AsyncGenerator object was destroyed.
            }

            if ($exception) {
                $source->fail($exception);
                return;
            }

            $source->complete();
        });
    }

    public function __destruct()
    {
        $this->source->destroy();
    }

    /**
     * @inheritDoc
     */
    public function continue(): mixed
    {
        return $this->source->continue();
    }

    /**
     * Sends a value to the async generator, resolving the back-pressure promise with the given value.
     * The first emitted value must be retrieved using {@see continue()}.
     *
     * @param mixed $value Value to send to the async generator.
     *
     * @psalm-param TSend $value
     *
     * @return Promise<mixed|null> Resolves with null if the pipeline has completed.
     *
     * @psalm-return Promise<TValue|null>
     *
     * @throws \Error If the first emitted value has not been retrieved using {@see continue()}.
     */
    public function send(mixed $value): mixed
    {
        return $this->source->send($value);
    }

    /**
     * Throws an exception into the async generator, failing the back-pressure promise with the given exception.
     * The first emitted value must be retrieved using {@see continue()}.
     *
     * @param \Throwable $exception Exception to throw into the async generator.
     *
     * @return Promise<mixed|null> Resolves with null if the pipeline has completed.
     *
     * @psalm-return Promise<TValue|null>
     *
     * @throws \Error If the first emitted value has not been retrieved using {@see continue()}.
     */
    public function throw(\Throwable $exception): mixed
    {
        return $this->source->throw($exception);
    }

    /**
     * Notifies the generator that the consumer is no longer interested in the generator output.
     *
     * @return void
     */
    public function dispose(): void
    {
        $this->source->dispose();
    }

    /**
     * @psalm-return TReturn
     */
    public function getReturn(): mixed
    {
        return await($this->promise);
    }
}
