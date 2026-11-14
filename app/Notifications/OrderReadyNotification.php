<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\OptionSummary;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your order is ready" email. Sent once per order by SendOrderReadyNotification, in the
 * customer's language: the sender sets locale(), and Laravel switches the app locale
 * while toMail() runs, so the translated strings and the snapshot's localized names follow it.
 *
 * Not queued: there is no queue worker. The listener sends it during the Bump request.
 */
class OrderReadyNotification extends Notification
{
    /**
     * @param  Order  $order  with items.modifiers loaded (strict mode)
     */
    public function __construct(public readonly Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->order->order_number;

        $mail = (new MailMessage)
            ->subject(__('Your order :number is ready', ['number' => $number]))
            ->greeting(__('Your drinks are ready!'))
            ->line(__('Order :number is ready for pickup at the counter.', ['number' => $number]));

        foreach ($this->order->items as $item) {
            $options = OptionSummary::join($item->modifierSummary());
            $drink = $item->quantity.' × '.$item->localizedName();

            $mail->line($options === '' ? $drink : $drink.' — '.$options);
        }

        return $mail
            ->action(__('View order'), $this->receiptUrl())
            ->salutation(__('Thanks, :name', ['name' => config()->string('app.name')]));
    }

    /**
     * The receipt on APP_URL. Not route()'s absolute URL: that takes its host from the
     * current request, which is the barista's Bump. A forged Host header would put the
     * attacker's domain in the customer's email, and a barista on another host would
     * send a link the customer can't open.
     */
    public function receiptUrl(): string
    {
        return rtrim(config()->string('app.url'), '/').route('orders.show', $this->order, absolute: false);
    }
}
