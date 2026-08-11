<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;

class CleanExpiredMessages extends Command
{
    protected $signature = 'messages:clean';

    protected $description = 'Remove expired temporary messages';

    public function handle()
    {
        $count = Message::where('expires_at', '<=', now())->delete();
        $this->info("Deleted {$count} expired messages.");

        return 0;
    }
}
