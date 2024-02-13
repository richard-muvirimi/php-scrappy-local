<?php

namespace App\Console\Commands;

use App\Support\Composer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;

class Bdi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:bdi';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Insert or update Browser Driver';

    /**
     * Execute the console command.
     *
     * @throws BindingResolutionException
     */
    public function handle(): void
    {
        app()->make(Composer::class)->run(['run', 'bdi']);
    }
}
