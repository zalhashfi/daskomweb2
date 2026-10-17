<?php

namespace App\Observers;

use App\Models\Nilai;
use App\Services\Jawaban\SubmissionLockService;

/**
 * Freezes the practicum answer once a grade exists.
 *
 * Whenever a Nilai row is created or updated for a (praktikan, modul) pair,
 * the latest 'tp' submission of that pair is locked by the system. 'tm'
 * submissions are never touched here.
 */
class NilaiObserver
{
    public function __construct(private readonly SubmissionLockService $locks) {}

    public function created(Nilai $nilai): void
    {
        $this->lockLatestTpSubmission($nilai);
    }

    public function updated(Nilai $nilai): void
    {
        $this->lockLatestTpSubmission($nilai);
    }

    private function lockLatestTpSubmission(Nilai $nilai): void
    {
        $this->locks->lockLatestForGrade((int) $nilai->praktikan_id, (int) $nilai->modul_id);
    }
}
