<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Pushery\FortifyHardening\Support\Settings;

/**
 * Tells the holder of an address that somebody tried to register an account with it.
 *
 * The registration form answers the person who submitted it the same way whether the address was
 * free or taken, so this mail is where the real holder learns of the attempt. It points at the
 * sign-in page, and at the password reset where the application has one to offer, and it carries
 * no remote image, font or tracking pixel.
 *
 * It is sent at once, the way Laravel sends the verification mail of a new account. An application
 * that queues that mail queues this one too, with a subclass that implements `ShouldQueue` named in
 * `fortify-hardening.registration.notification`, so both answers keep taking the same time.
 *
 * The same subclass can name the application in the subject and greet the holder, by overriding
 * applicationName() and greeting(), for instance where one installation serves several instances.
 */
class AccountAlreadyExistsNotification extends Notification
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(Settings::class)->noticeChannels();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject($this->line('fortify-hardening::messages.notice.subject', ['app' => $this->applicationName($notifiable)]));

        $greeting = $this->greeting($notifiable);

        if ($greeting !== null) {
            $mail->greeting($greeting);
        }

        $next = app(Settings::class)->noticeMentionsPasswordReset() ? 'next' : 'next_without_password';

        return $mail
            ->line($this->line('fortify-hardening::messages.notice.attempt'))
            ->action($this->line('fortify-hardening::messages.notice.action'), $this->signInUrl())
            ->line($this->line("fortify-hardening::messages.notice.{$next}"));
    }

    /**
     * The name the subject gives the application: `app.name`, unless a subclass names another.
     */
    protected function applicationName(object $notifiable): string
    {
        $name = config('app.name');

        return is_string($name) ? $name : '';
    }

    /**
     * The greeting the mail opens with. Null, the default, leaves Laravel's own.
     */
    protected function greeting(object $notifiable): ?string
    {
        return null;
    }

    private function signInUrl(): string
    {
        $route = app(Settings::class)->signInRoute();

        return Route::has($route) ? URL::route($route) : URL::to('/');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        $line = trans($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
