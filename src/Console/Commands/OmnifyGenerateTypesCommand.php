<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use OmnifyJP\LaravelScaffold\Services\TypescriptModelBuilder;

class OmnifyGenerateTypesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'omnify:gen-types';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        (new TypescriptModelBuilder)->build();
    }
}
