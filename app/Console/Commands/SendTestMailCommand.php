<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestMailCommand extends Command
{
    protected $signature = 'mail:test {email : Recipient address} {--subject=FoodHunts test email : Email subject}';

    protected $description = 'Send a test transactional email through the configured mailer (Resend in production)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $subject = $this->option('subject');

        try {
            Mail::raw(
                "This is a test email from the FoodHunts backend.\n\n" .
                'Mailer: ' . config('mail.default') . "\n" .
                'From: ' . config('mail.from.address') . "\n" .
                'Sent at: ' . now()->toDateTimeString() . "\n",
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
        } catch (\Throwable $e) {
            $this->error('Send failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Test email sent to ' . $email . ' via ' . config('mail.default'));

        return self::SUCCESS;
    }
}
