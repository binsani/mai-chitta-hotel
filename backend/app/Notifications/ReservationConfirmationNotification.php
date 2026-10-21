<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationConfirmationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private Reservation $reservation,
        private string $hotelName = 'Mai Chitta Hotel',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $res = $this->reservation;
        $lookupUrl = url('/booking?reference='.urlencode($res->reference).'&token='.urlencode($res->public_access_token));

        return (new MailMessage)
            ->subject("Reservation Confirmation {$res->reference} — {$this->hotelName}")
            ->greeting("Dear {$res->guest_name},")
            ->line("Thank you for choosing {$this->hotelName}. Your reservation has been recorded.")
            ->line("Booking Reference: {$res->reference}")
            ->line("Check-in Date: {$res->check_in_date->format('Y-m-d')}")
            ->line("Check-out Date: {$res->check_out_date->format('Y-m-d')} ({$res->nights} night(s))")
            ->line("Total Quoted Amount: {$res->currency_code} ".number_format($res->total_amount, 2))
            ->line("Reservation Status: ".strtoupper(str_replace('_', ' ', $res->status)))
            ->action('View Reservation Summary', $lookupUrl)
            ->line('We look forward to welcoming you to Zaria, Kaduna State.');
    }
}
