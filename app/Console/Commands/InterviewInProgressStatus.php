<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Interview;
use Carbon\Carbon;

class InterviewInProgressStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:interview-inProgress';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check interviews with status 1 or 4 and update to in-progress if the interview date has started, or to passed if the interview has ended';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $now = Carbon::now();

        // Fetch all interviews with status 1 or 4
        $interviews = Interview::whereIn('status_id', [1, 4])
            ->get();

        if ($interviews->isEmpty()) {
            $this->info('No interviews found to update.');
            return 0;
        }

        foreach ($interviews as $interview) {
            if ($interview->interview_date <= $now && $interview->status_id != 8) {
                $this->info("Updating Interview ID: {$interview->id} to status 'In Progress'.");
                $interview->update([
                    'status_id' => 8, // In Progress
                ]);
            }

            if ($interview->end_date && $interview->end_date <= $now && $interview->status_id != 2) {
                $this->info("Updating Interview ID: {$interview->id} to status 'Passed'.");
                $interview->update([
                    'status_id' => 2, 
                ]);
            }
        }

        $this->info('Interviews updated successfully.');
        return 0;
    }
}
