<?php

use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionConflict;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\User;
use App\Notifications\OrderReadyNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * An order in $status for a customer with $locale, with one drink and two options.
 */
function readyTestOrder(OrderStatus $status, ?string $locale = null): Order
{
    $customer = User::factory()->create(['email' => 'mei@example.com', 'locale' => $locale]);
    $order = Order::factory()->for($customer)->create(['status' => $status]);

    $item = OrderItem::factory()->for($order)->create(['name_en' => 'Classic Milk Tea', 'name_zh' => '經典奶茶', 'quantity' => 2]);
    OrderItemModifier::factory()->for($item)->create(['group_name_en' => 'Sugar', 'group_name_zh' => '甜度', 'name_en' => 'Half', 'name_zh' => '半糖']);
    OrderItemModifier::factory()->for($item)->create(['group_name_en' => 'Toppings', 'group_name_zh' => '加料', 'name_en' => 'Pearls', 'name_zh' => '珍珠']);

    return $order;
}

/**
 * The emails the suite's "array" mailer captured.
 *
 * @return list<Email>
 */
function sentEmails(): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()
        ->map(fn (SentMessage $sent) => $sent->getOriginalMessage())
        ->values()
        ->all();
}

describe('when it is sent', function () {
    beforeEach(fn () => Notification::fake());

    test('the first move into ready emails the order\'s customer', function () {
        $order = readyTestOrder(OrderStatus::Preparing);

        $order->transitionTo(OrderStatus::Ready);

        Notification::assertSentToTimes($order->user, OrderReadyNotification::class, 1);
        Notification::assertSentTo($order->user, fn (OrderReadyNotification $n) => $n->order->is($order));
        Notification::assertCount(1);
    });

    test('no other move sends anything', function (string $move) {
        [$from, $to] = array_map(OrderStatus::from(...), explode('>', $move));
        $order = readyTestOrder($from);

        $order->transitionTo($to);

        Notification::assertNothingSent();
    })->with(array_values(array_diff(legalOrderTransitions(), ['preparing>ready'])));

    test('Bump after a Recall does not email again', function () {
        $order = readyTestOrder(OrderStatus::Paid);

        $order->transitionTo(OrderStatus::Preparing);  // Start
        $order->transitionTo(OrderStatus::Ready);      // Bump: emails
        $order->transitionTo(OrderStatus::Preparing);  // Recall
        $order->transitionTo(OrderStatus::Ready);      // Bump: no email
        $order->transitionTo(OrderStatus::Preparing);  // Recall
        $order->transitionTo(OrderStatus::Ready);      // Bump: no email
        $order->transitionTo(OrderStatus::PickedUp);

        Notification::assertSentToTimes($order->user, OrderReadyNotification::class, 1);
    });

    test('a move that loses a race sends nothing', function () {
        $order = readyTestOrder(OrderStatus::Preparing);
        // Another tablet bumped it first (and that request sent its own email).
        DB::table('orders')->where('id', $order->id)->update(['status' => 'ready']);

        expect(fn () => $order->transitionTo(OrderStatus::Ready))->toThrow(OrderTransitionConflict::class);

        Notification::assertNothingSent();
    });

    test('inside a transaction it waits for the commit, and a rollback sends nothing', function () {
        $order = readyTestOrder(OrderStatus::Preparing);

        try {
            DB::transaction(function () use ($order) {
                $order->transitionTo(OrderStatus::Ready);
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
        Notification::assertNothingSent();
    });

    test('goes out in the customer\'s saved language, English for none or unsupported', function (?string $saved, string $sent) {
        $order = readyTestOrder(OrderStatus::Preparing, $saved);

        $order->transitionTo(OrderStatus::Ready);

        Notification::assertSentTo($order->user, fn (OrderReadyNotification $n) => $n->locale === $sent);
    })->with([
        'zh_TW' => ['zh_TW', 'zh_TW'],
        'en' => ['en', 'en'],
        'none saved' => [null, 'en'],
        'unsupported' => ['klingon', 'en'],
    ]);
});

describe('what the email says', function () {
    test('in Traditional Chinese for a zh_TW customer, without changing the request\'s language', function () {
        $order = readyTestOrder(OrderStatus::Preparing, 'zh_TW');
        app()->setLocale('en');  // the barista's request

        $order->transitionTo(OrderStatus::Ready);

        [$email] = sentEmails();
        $text = $email->getTextBody();

        expect(app()->getLocale())->toBe('en')
            ->and($email->getTo()[0]->getAddress())->toBe('mei@example.com')
            ->and($email->getSubject())->toBe("你的訂單 {$order->order_number} 可以取餐了")
            ->and($text)
            ->toContain('飲料做好了！')
            ->toContain("訂單 {$order->order_number} 已完成，請到櫃台取餐。")
            ->toContain('2 × 經典奶茶 — 甜度：半糖 · 加料：珍珠')
            ->toContain('查看訂單')
            ->toContain(route('orders.show', $order))
            // Laravel's own template strings, translated too.
            ->toContain('如果無法點擊「查看訂單」按鈕')
            ->toContain('版權所有。')
            ->not->toContain('Classic Milk Tea')
            ->not->toContain('Regards')
            ->not->toContain('trouble clicking');
    });

    test('in English for an English customer', function () {
        $order = readyTestOrder(OrderStatus::Preparing, null);
        app()->setLocale('zh_TW');  // a barista working in Chinese

        $order->transitionTo(OrderStatus::Ready);

        [$email] = sentEmails();

        expect($email->getSubject())->toBe("Your order {$order->order_number} is ready")
            ->and($email->getTextBody())
            ->toContain('Your drinks are ready!')
            ->toContain('2 × Classic Milk Tea — Sugar: Half · Toppings: Pearls')
            ->toContain('View order')
            ->not->toContain('經典奶茶')
            ->and(app()->getLocale())->toBe('zh_TW');
    });

    test('is one email for the whole Start → Bump → Recall → Bump → Picked up run', function () {
        $order = readyTestOrder(OrderStatus::Paid);

        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::PickedUp] as $to) {
            $order->transitionTo($to);
        }

        expect(sentEmails())->toHaveCount(1);
    });
});

/*
 * The mail server is down: the transport throws. Bump must still succeed, and the failure
 * must be logged.
 */
describe('mail failure', function () {
    beforeEach(function () {
        Mail::extend('exploding', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Mail server is down');
            }

            public function __toString(): string
            {
                return 'exploding';
            }
        });
        config([
            'mail.mailers.exploding' => ['transport' => 'exploding'],
            'mail.default' => 'exploding',
            'logging.default' => 'null',
        ]);
        Mail::forgetMailers();

        $this->logged = [];
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logged[] = $log);
    });

    test('the transition still succeeds and the failure is logged', function () {
        $order = readyTestOrder(OrderStatus::Preparing);

        $order->transitionTo(OrderStatus::Ready);

        expect($order->fresh()->status)->toBe(OrderStatus::Ready)
            ->and($order->status)->toBe(OrderStatus::Ready)
            ->and($this->logged)->toHaveCount(1)
            ->and($this->logged[0]->level)->toBe('error')
            ->and($this->logged[0]->context['exception'])->toBeInstanceOf(TransportException::class);
    });

    test('Bump on the barista board still works, with no toast', function () {
        $this->seed(RoleSeeder::class);
        $order = readyTestOrder(OrderStatus::Preparing);
        $attempts = 0;
        Event::listen(MessageSending::class, function () use (&$attempts) {
            $attempts++;
        });

        Livewire::actingAs(userWithRole('barista'))->test('pages::barista-board')
            ->call('bump', $order->id)
            ->assertOk()
            ->assertNotDispatched('toast-show');

        // The email was attempted (and the transport threw) inside the Bump request. Livewire's
        // test harness runs calls without exception handling, so report() is a no-op here and
        // the log line can't be seen; the test above covers the logging.
        expect($attempts)->toBe(1)
            ->and($order->fresh()->status)->toBe(OrderStatus::Ready);
    });
});

/*
 * The link is built from APP_URL, never from the request host: the request is the
 * barista's Bump, and its Host header can be forged.
 */
describe('the link host', function () {
    test('a forged request host still gives a link on APP_URL', function () {
        config(['app.url' => 'https://tea-alley.example']);
        $order = readyTestOrder(OrderStatus::Preparing);

        // The Bump request arrives with a forged Host header.
        app('url')->setRequest(Request::create('https://evil.example/barista'));
        expect(route('orders.show', $order))->toStartWith('https://evil.example/');  // what route() alone would give

        $order->transitionTo(OrderStatus::Ready);

        [$email] = sentEmails();
        $receipt = 'https://tea-alley.example/orders/'.$order->order_number;

        expect($email->getTextBody())->toContain($receipt)->not->toContain('evil.example')
            ->and($email->getHtmlBody())->toContain('href="'.$receipt.'"')->not->toContain('evil.example');
    });

    test('a trailing slash on APP_URL does not double the slash', function () {
        config(['app.url' => 'https://tea-alley.example/']);
        $order = readyTestOrder(OrderStatus::Preparing);

        expect((new OrderReadyNotification($order))->receiptUrl())
            ->toBe('https://tea-alley.example/orders/'.$order->order_number);
    });
});
