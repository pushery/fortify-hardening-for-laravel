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
 * sign-in page and at the password reset, and it carries no remote image, font or tracking pixel.
 *
 * It is sent at once, the way Laravel sends the verification mail of a new account. An application
 * that queues that mail queues this one too, with a subclass that implements `ShouldQueue` named in
 * `fortify-hardening.registration.notification`, so both answers keep taking the same time.
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
        return new MailMessage()
            ->subject($this->line('fortify-hardening::messages.notice.subject'))
            ->line($this->line('fortify-hardening::messages.notice.attempt'))
            ->action($this->line('fortify-hardening::messages.notice.action'), $this->signInUrl())
            ->line($this->line('fortify-hardening::messages.notice.next'));
    }

    private function signInUrl(): string
    {
        $route = app(Settings::class)->signInRoute();

        return Route::has($route) ? URL::route($route) : URL::to('/');
    }

    private function line(string $key): string
    {
        $line = trans($key);

        return is_string($line) ? $line : $key;
    }
}
