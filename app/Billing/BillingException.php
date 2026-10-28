<?php

namespace App\Billing;

use App\Exceptions\ApiException;

class BillingException extends ApiException
{
    public static function notConfigured(string $store): self
    {
        return new self("{$store} billing is not configured on the server.", 503, 'billing_not_configured');
    }

    public static function storeUnavailable(string $store): self
    {
        return new self("{$store} could not be reached. Please try again.", 503, 'billing_store_unavailable');
    }

    public static function invalid(string $message = 'This purchase could not be verified.'): self
    {
        return new self($message, 422, 'billing_invalid_purchase');
    }

    public static function unknownProduct(): self
    {
        return new self('Unknown product.', 422, 'billing_unknown_product');
    }

    public static function inUse(): self
    {
        return new self('This purchase is already linked to another account.', 409, 'billing_purchase_in_use');
    }
}
