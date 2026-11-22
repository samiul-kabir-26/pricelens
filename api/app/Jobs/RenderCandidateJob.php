<?php

namespace App\Jobs;

/**
 * Same pipeline as FetchCandidateJob, but through the renderer service (queue `render`).
 */
class RenderCandidateJob extends FetchCandidateJob
{
    public int $timeout = 60;

    public int $tries = 3;

    protected bool $render = true;
}
