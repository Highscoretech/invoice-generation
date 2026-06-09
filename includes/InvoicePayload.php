<?php
/**
 * InvoicePayload — maps our database rows into the exact FIRS BIS 3.0 invoice
 * payload that the /invoice/validate, /sign and /transmit endpoints accept.
 *
 * The field shape here was reverse-engineered and verified field-by-field
 * against the live sandbox (validate -> 200 {ok:true}, sign -> 201 {ok:true}).
 * Keep this file as the single source of truth for the wire format so the rest
 * of the app never has to know FIRS internals.
 */
require_once __DIR__ . '/Crypto.php';

class InvoicePayload
{
    /** UN/CEFACT-ish country normalisation (FIRS expects ISO-2). */
    private static function country(?string $c): string
    {
        $c = strtoupper(trim((string) $c));
        $map = ['NIGERIA' => 'NG', 'UNITED STATES' => 'US', 'USA' => 'US', 'INDIA' => 'IN'];
        if (isset($map[$c])) {
            return $map[$c];
        }
        return $c !== '' && strlen($c) === 2 ? $c : 'NG';
    }

    /**
     * First non-empty value. Unlike `??`, this also treats "" (and whitespace)
     * as missing, so empty DB columns fall back to a valid default instead of
     * being sent to FIRS as an empty required field.
     */
    private static function nz(...$vals): string
    {
        foreach ($vals as $v) {
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') {
                return (string) $v;
            }
        }
        return '';
    }

    /**
     * FIRS requires the HS/HSN code in dotted form (e.g. 8517.12). Anything that
     * doesn't match falls back to 0000.00 so an invoice never fails on format.
     */
    private static function hsn($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}\.\d{2,4}$/', $v) ? $v : '0000.00';
    }

    private static function party(array $p): array
    {
        return [
            'party_name'          => self::nz($p['name'] ?? '', 'N/A'),
            'tin'                 => self::nz($p['tin'] ?? '', '00000000-0001'),
            'email'               => self::nz($p['email'] ?? '', 'no-reply@example.com'),
            'telephone'           => self::nz($p['phone'] ?? '', '+2348000000000'),
            'business_description' => self::nz($p['description'] ?? '', 'General trade'),
            'postal_address'      => [
                'street_name' => self::nz($p['address'] ?? '', 'N/A'),
                'city_name'   => self::nz($p['city'] ?? '', 'Lagos'),
                'postal_zone' => self::nz($p['postal_zone'] ?? '', '100001'),
                'country'     => self::country(self::nz($p['country'] ?? '', 'Nigeria')),
            ],
        ];
    }

    /**
     * @param array  $invoice   invoices row
     * @param array  $items     invoice_items joined with items
     * @param array  $company   supplier (companies row)
     * @param array  $customer  buyer (customers row)
     * @param string $irn       pre-built IRN
     * @param string $businessId FIRS business UUID
     */
    public static function build(array $invoice, array $items, array $company, array $customer, string $irn, string $businessId): array
    {
        // These now come from the invoice row (with FIRS defaults as fallback).
        $currency    = self::nz($invoice['document_currency_code'] ?? '', 'NGN');
        $typeCode    = self::nz($invoice['invoice_type_code'] ?? '', '381'); // 381 = Commercial Invoice
        $paymentStat = self::nz($invoice['payment_status'] ?? '', 'PENDING');
        $taxRate  = (float) ($invoice['tax_rate'] ?? 7.5);
        if ($taxRate <= 0) {
            $taxRate = 7.5;
        }

        $lines = [];
        foreach ($items as $it) {
            $lines[] = [
                'hsn_code'              => self::hsn($it['hsn_code'] ?? ''),
                'product_category'      => self::nz($it['category'] ?? '', 'General'),
                'invoiced_quantity'     => (float) ($it['quantity'] ?? 1),
                'line_extension_amount' => round((float) ($it['amount'] ?? 0), 2),
                'item' => [
                    'name'        => self::nz($it['item_name'] ?? '', $it['name'] ?? '', 'Item'),
                    'description' => self::nz($it['description'] ?? '', $it['item_name'] ?? '', $it['name'] ?? '', 'Item'),
                ],
                'price' => [
                    'price_amount'  => round((float) ($it['rate'] ?? 0), 2),
                    'base_quantity' => (float) ($it['quantity'] ?? 1),
                    'price_unit'    => $currency . ' per 1',
                ],
            ];
        }

        // Recompute consistently so the discount flows through correctly and the
        // amounts always reconcile (FIRS validates this): tax is charged on the
        // post-discount base.
        $subtotal     = round((float) ($invoice['subtotal'] ?? 0), 2);            // sum of lines (pre-discount)
        $discount     = round((float) ($invoice['discount_amount'] ?? 0), 2);
        $discountRate = (float) ($invoice['discount_rate'] ?? 0);
        $taxExclusive = round($subtotal - $discount, 2);
        $taxAmount    = round($taxExclusive * $taxRate / 100, 2);
        $taxInclusive = round($taxExclusive + $taxAmount, 2);
        $total        = $taxInclusive;

        $payload = [
            'business_id'            => $businessId,
            'irn'                    => $irn,
            'issue_date'             => date('Y-m-d', strtotime($invoice['date'] ?? 'now')),
            'due_date'               => !empty($invoice['due_date']) ? date('Y-m-d', strtotime($invoice['due_date'])) : date('Y-m-d', strtotime('+30 days')),
            'issue_time'             => substr((string) ($invoice['time'] ?? '09:00:00'), 0, 8),
            'invoice_type_code'      => $typeCode,
            'payment_status'         => $paymentStat,
            'document_currency_code' => $currency,
            'tax_currency_code'      => $currency,
            'accounting_supplier_party' => self::party([
                'name'    => $company['name'] ?? '',
                'tin'     => $company['tin_number'] ?? $company['tax_id'] ?? '',
                'email'   => $company['email'] ?? '',
                'phone'   => $company['phone'] ?? '',
                'address' => $company['address'] ?? '',
                'city'    => $company['city'] ?? '',
                'postal_zone' => $company['postal_code'] ?? '',
                'country' => $company['country'] ?? 'Nigeria',
                'description' => $company['industry'] ?? 'General trade',
            ]),
            'accounting_customer_party' => self::party([
                'name'    => $customer['name'] ?? '',
                'tin'     => $customer['tax_id'] ?? '',
                'email'   => $customer['email'] ?? '',
                'phone'   => $customer['phone'] ?? '',
                'address' => $customer['billing_address'] ?? $customer['address'] ?? '',
                'city'    => $customer['billing_city'] ?? '',
                'postal_zone' => $customer['billing_postal_code'] ?? '',
                'country' => $customer['billing_country'] ?? 'Nigeria',
            ]),
            'legal_monetary_total' => [
                'line_extension_amount'  => $subtotal,
                'allowance_total_amount' => $discount,
                'charge_total_amount'    => 0,
                'tax_exclusive_amount'   => $taxExclusive,
                'tax_inclusive_amount'   => $taxInclusive,
                'payable_amount'         => $total,
            ],
            'invoice_line' => $lines,
            'tax_total'    => [[
                'tax_amount'   => $taxAmount,
                'tax_subtotal' => [[
                    'taxable_amount' => $taxExclusive,
                    'tax_amount'     => $taxAmount,
                    'tax_category'   => [
                        'id'      => 'STANDARD_VAT',
                        'percent' => $taxRate,
                    ],
                ]],
            ]],
        ];

        // Document-level discount (allowance). Only included when there is one.
        if ($discount > 0) {
            $payload['allowance_charge'] = [[
                'charge_indicator'          => false, // false = allowance/discount
                'allowance_charge_reason'   => 'Discount',
                'amount'                    => $discount,
                'multiplier_factor_numeric' => $discountRate,
                'base_amount'               => $subtotal,
            ]];
        }

        // Optional FIRS fields — included only when present. tax_point_date
        // defaults to the issue date; note is decrypted from storage.
        $payload['tax_point_date'] = !empty($invoice['tax_point_date'])
            ? date('Y-m-d', strtotime($invoice['tax_point_date']))
            : $payload['issue_date'];
        $note = Crypto::decrypt($invoice['notes'] ?? null);
        if (self::nz((string) $note) !== '') {
            $payload['note'] = $note;
        }

        return $payload;
    }
}
