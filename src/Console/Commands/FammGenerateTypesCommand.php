<?php

namespace FammSupport\Console\Commands;

use FammSupport\Services\TypescriptModelBuilder;
use Illuminate\Console\Command;

class FammGenerateTypesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'famm:gen-types';

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
