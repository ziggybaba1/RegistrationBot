<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\RegisterUser;

class DispatchRegistrationJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'registration:dispatch';

    protected $description = 'Dispatches the RegisterUser job for registration process';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Dispatching registration job...');

        // Dispatch RegisterUser job
        RegisterUser::dispatch();

        $this->info('Registration job dispatched successfully!');
    }
}
