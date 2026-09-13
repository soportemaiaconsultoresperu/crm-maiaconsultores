<?php

namespace App\Services\Courses;

use App\Contracts\Courses\QrRenderer;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

class EndroidQrRenderer implements QrRenderer
{
    public function renderSvg(string $payload): string
    {
        return (new SvgWriter())->write(new QrCode(data: $payload))->getString();
    }
}
