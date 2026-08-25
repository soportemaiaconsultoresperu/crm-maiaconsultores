<?php

namespace App\Services\DemoData;

use App\Models\DemoDataBatch;

class DemoDataContext
{
    private ?DemoDataBatch $batch = null;

    public function currentBatch(): ?DemoDataBatch
    {
        return $this->batch;
    }

    public function isGenerating(): bool
    {
        return $this->batch !== null;
    }

    /**
     * @template TReturn
     * @param callable():TReturn $callback
     * @return TReturn
     */
    public function runForBatch(DemoDataBatch $batch, callable $callback): mixed
    {
        $previous = $this->batch;
        $this->batch = $batch;

        try {
            return $callback();
        } finally {
            $this->batch = $previous;
        }
    }
}
