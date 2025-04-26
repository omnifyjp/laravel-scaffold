<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use OmnifyJP\LaravelScaffold\OmnifyService;
use Illuminate\Console\Command;

class OmnifyLoginCommand extends Command
{
    protected $signature = 'omnify:login';

    protected $description = 'Command description';

    public function handle()
    {
        if (!OmnifyService::tokenExists()) {
            $this->info('No authentication token found.');
            if ($this->promptLogin()) {
                $this->info('Login successful. Token saved.');

                return 0;
            } else {
                $this->error('Login failed.');

                return 1;
            }
        }

        $this->info('Authentication token exists.');
    }

    /**
     * Prompt user for login credentials and get token
     */
    private function promptLogin(): bool
    {
        $email = $this->ask('Enter your email');
        $password = $this->secret('Enter your password');

        if (empty($email) || empty($password)) {
            $this->error('Email and password are required.');

            return false;
        }

        $token = OmnifyService::createToken($email, $password);

        if ($token) {
            OmnifyService::saveToken($token);
            return true;
        }

        $this->error('API Error: Failed to create token');
        return false;
    }
}
