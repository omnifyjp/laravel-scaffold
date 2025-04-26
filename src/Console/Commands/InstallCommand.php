<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use OmnifyJP\LaravelScaffold\Installers\ComposerConfigUpdater;
use Symfony\Component\Console\Command\Command as CommandAlias;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'famm:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the FAMM framework';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Installing FAMM Framework...');

        $status = ComposerConfigUpdater::update(true);

        foreach ($status['changes'] as $change) {
            $this->info($change);
        }

        foreach ($status['messages'] as $message) {
            $this->info($message);
        }

        if ($status['success']) {
            $this->info('FAMM Framework installed successfully!');
            return CommandAlias::SUCCESS;
        } else {
            $this->error('Failed to install FAMM Framework.');
            return CommandAlias::FAILURE;
        }
    }
}
