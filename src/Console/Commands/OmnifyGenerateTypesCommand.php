<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use OmnifyJP\LaravelScaffold\Services\TypescriptModelBuilder;
use Illuminate\Console\Command;

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
