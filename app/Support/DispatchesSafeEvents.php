<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

trait DispatchesSafeEvents
{
    protected function dispatchSafeEvent(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $exception) {
            Log::warning('Event dispatch failed but request will continue.', [
                'event' => $event::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
