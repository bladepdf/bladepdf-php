<?php

declare(strict_types=1);

namespace BladePDF\Contracts;

use BladePDF\RenderRequest;
use BladePDF\RenderResult;
use BladePDF\RenderSubmission;

interface RenderClient
{
    public function render(RenderRequest $request): RenderResult;

    public function renderAsync(RenderRequest $request): RenderSubmission;
}
