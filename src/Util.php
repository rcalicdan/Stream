<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

final class Util
{
    /**
     * Pipes all the data from the given $source into the $dest
     *
     * @param ReadableStreamInterface $source
     * @param WritableStreamInterface $dest
     * @param array{end?:bool} $options
     * @return WritableStreamInterface $dest stream as-is
     */
    public static function pipe(ReadableStreamInterface $source, WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $source->pipe($dest, $options);
    }

    /**
     * Forwards events from source to target
     *
     * @param ReadableStreamInterface|WritableStreamInterface $source
     * @param ReadableStreamInterface|WritableStreamInterface $target
     * @param string[] $events
     * @return void
     */
    public static function forwardEvents($source, $target, array $events): void
    {
        foreach ($events as $event) {
            $source->on($event, function (...$args) use ($event, $target): void {
                $target->emit($event, $args);
            });
        }
    }
}
