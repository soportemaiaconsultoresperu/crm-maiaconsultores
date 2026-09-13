<?php

namespace App\Support\Quotations;

use Illuminate\Contracts\Validation\Validator;

/**
 * D-4 — single source of truth for the line-discount rule.
 *
 * A quotation line discount can never exceed its OWN line subtotal
 * (quantity × unit_price). Before this rule the form preview clamped the
 * taxable base at zero while the server multiplied the negative remainder by
 * the IGV rate: for `1 × 100` with a `1000` discount the preview showed
 * `0.00` and the database stored `line_tax = -162.00`, `total = -1062.00`.
 *
 * The rule lives here once because it has three consumers that MUST agree:
 *   - `QuotationStoreRequest`  → visible field error on the create form;
 *   - `QuotationUpdateRequest` → visible field error on the edit form;
 *   - `QuotationService`       → exception for callers that bypass HTTP.
 * Two copies of the comparison is exactly how the server, the requests and
 * the form preview diverged in the first place; a future third write path
 * calls this class instead of copying an eighth line.
 *
 * Boundary, on purpose: a discount EXACTLY equal to the subtotal is valid.
 * That is a fully discounted zero line (subtotal 100, discount 100, tax 0,
 * total 0), not an error. Only a discount strictly LARGER than the subtotal
 * is rejected — by then it is no longer a discount but negative money.
 *
 * Money is compared in integer cents so `7 × 0.29` (2.0299999999999998 as a
 * float) still accepts a discount of exactly `2.03`.
 */
final class LineDiscountRule
{
    /**
     * Every item whose discount exceeds its subtotal, keyed by the item index
     * and carrying the message the user must see.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<array-key, string>
     */
    public static function violations(array $items): array
    {
        $violations = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $subtotalCents = self::cents(
                ((float) ($item['quantity'] ?? 0)) * ((float) ($item['unit_price'] ?? 0))
            );
            $discountCents = self::cents((float) ($item['discount_amount'] ?? 0));

            if ($discountCents > $subtotalCents) {
                $violations[$index] = self::message($index, $subtotalCents);
            }
        }

        return $violations;
    }

    /**
     * Register the violations on a validator, one error per offending line.
     *
     * @param  array<array-key, mixed>  $items
     */
    public static function addTo(Validator $validator, array $items): void
    {
        foreach (self::violations($items) as $index => $message) {
            $validator->errors()->add('items.'.$index.'.discount_amount', $message);
        }
    }

    public static function message(int|string $index, int $subtotalCents): string
    {
        return 'El descuento de la línea '.((int) $index + 1)
            .' no puede superar su subtotal ('.self::format($subtotalCents).').';
    }

    /**
     * Round a money amount to integer cents. Both sides of the comparison go
     * through this, which is what keeps the boundary exact.
     */
    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
