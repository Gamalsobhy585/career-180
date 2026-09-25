<?php
namespace App\Payment;

use App\Payment\PaymentResult;

interface PaymentProviderInterface
{
    /**
     * Attempt to send money to an instructor. May return a result indicating
     * the outcome is unknown (e.g. request timed out after the transfer
     * may have already succeeded on the provider's side).
     */
    public function pay(string $idempotencyKey, int $amountCents): PaymentResult;

    /**
     * Ask the provider for the true current status of a previously
     * attempted payment, keyed by the same idempotency key.
     */
    public function checkStatus(string $idempotencyKey): PaymentResult;
}