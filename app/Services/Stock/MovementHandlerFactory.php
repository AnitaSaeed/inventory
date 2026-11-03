<?php

namespace App\Services\Stock;

use App\Services\Stock\Handlers\InHandler;
use App\Services\Stock\Handlers\OutHandler;
use App\Services\Stock\Handlers\TransferHandler;

class MovementHandlerFactory
{
    public static function make(string $type): MovementHandler
    {
        return match ($type) {
            'in' => new InHandler(),
            'out' => new OutHandler(),
            'transfer' => new TransferHandler(),
            default => throw new \InvalidArgumentException("Unknown movement type: {$type}"),
        };
    }
}
