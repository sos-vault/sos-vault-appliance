<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Sushi\Sushi;
use Wave\User;

class Invoice extends Model
{
    use Sushi;

    public function getRows(): array
    {
        // User invoices
        $records = [];
        $invoices = auth()->user()->invoices();

        if ($invoices) {
            foreach ($invoices as $i => $invoice) {
                $record = null;
                $items = 0;

                if (isset($invoice) && ! empty($invoice)) {
                    $items = count($invoice->details->line_items);
                    $record = [
                        'id' => ($i + 1),
                        'paddle_id' => $invoice->id,
                        'status' => $invoice->status,
                        'customer_id' => $invoice->customer_id,
                        'subscription_id' => $invoice->subscription_id,
                        'invoice_id' => $invoice->invoice_id,
                        'invoice_number' => $invoice->invoice_number,
                        'billing_start' => $invoice->billing_period?->starts_at ?? '',
                        'billing_end' => $invoice->billing_period?->ends_at ?? '',
                        'billing_period' => '',
                        'created_at' => $invoice->created_at,
                        'currency_code' => $invoice->currency_code,
                        'items' => $items,
                        'name' => '',
                        'description' => '',
                        'type' => '',
                        'price' => '',
                        'tax' => '',
                        'total' => '',
                        'payment' => '',
                        'card' => '',
                        'last4' => '',
                        'expiry_month' => '',
                        'expiry_year' => '',
                        'card_name' => '',
                    ];

                    if (isset($invoice->items) && isset($invoice->items[0])) {
                        $record['billing_period'] = $invoice->items[0]->price->billing_cycle->interval;
                    }

                    if (isset($invoice->details) && isset($invoice->details->totals)) {
                        $record['price'] = $invoice->details->totals->subtotal / 100;
                        $record['tax'] = $invoice->details->totals->tax / 100;
                        $record['total'] = $invoice->details->totals->total / 100;
                        $record['currency_code'] = $invoice->details->totals->currency_code;
                    }

                    if (isset($invoice->payments) && isset($invoice->payments[0])) {
                        if (isset($invoice->payments[0]->method_details)) {
                            $record['payment'] = $invoice->payments[0]->method_details->type;

                            if (isset($invoice->payments[0]->method_details->card)) {
                                $record['card'] = $invoice->payments[0]->method_details->card->type;
                                $record['last4'] = $invoice->payments[0]->method_details->card->last4;
                                $record['expiry_month'] = $invoice->payments[0]->method_details->card->expiry_month;
                                $record['expiry_year'] = $invoice->payments[0]->method_details->card->expiry_year;
                                $record['card_name'] = $invoice->payments[0]->method_details->card->cardholder_name;
                            }
                        }
                    }

                    if ($items > 0) {
                        foreach ($invoice->details->line_items as $i => $item) {
                            $record['name'] .= $invoice->details->line_items[$i]->product->name.'. ';
                            $record['description'] .= $invoice->details->line_items[$i]->product->description.'. ';
                            $record['type'] .= $invoice->details->line_items[$i]->product->type.'. ';
                        }
                    }

                    $records[] = $record;
                }
            }
        }

        // log::info(var_export($records, true));

        return collect($records)->toArray();
    }
}
