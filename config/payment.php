<?php

return [

    /*
     | Which App\Contracts\PaymentGateway is bound: "fake" marks orders paid immediately,
     | "stripe" sends the customer to Stripe Checkout. Tests and CI always use "fake"
     | (phpunit.xml), so they never need Stripe keys.
     */
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

];
