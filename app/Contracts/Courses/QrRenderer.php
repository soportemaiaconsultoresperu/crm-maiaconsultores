<?php

namespace App\Contracts\Courses;

interface QrRenderer
{
    public function renderSvg(string $payload): string;
}
