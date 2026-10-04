<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\PackagePayment;
use App\Models\GiftCard;
use App\Models\Expense;
use App\Models\ServicePackage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminFinancialReportController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tz = config('clinic.timezone', config('app.timezone'));
        $to = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : Carbon::now($tz)->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : (clone $to)->subDays(29)->startOfDay();
        $rate = ServicePackage::EUR_TO_MKD;

        // Commercial sales: packages are sold once; package-backed appointment rows are excluded
        // so a package is never counted again when one of its sessions is booked/completed.
        $appointmentSales = Appointment::query()
            ->with(['service:id,name,service_category_id', 'service.category:id,name'])
            ->whereNull('service_package_id')
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->get();

        $packageSales = ServicePackage::query()
            ->with(['service:id,name,service_category_id', 'service.category:id,name'])
            ->where('status', '!=', ServicePackage::STATUS_CANCELLED)
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->get();

        $salesRows = collect();
        foreach ($appointmentSales as $appointment) {
            $salesRows->push($this->saleRow(
                'appointment',
                $appointment->id,
                $appointment->created_at,
                $appointment->service?->name ?? 'Unknown service',
                $appointment->service?->category?->name ?? 'Uncategorized',
                (float) ($appointment->sale_original_price ?? $appointment->price ?? 0),
                (float) ($appointment->sale_final_price ?? $appointment->price ?? 0),
                (float) ($appointment->sale_discount_amount ?? 0),
            ));
        }
        foreach ($packageSales as $package) {
            $salesRows->push($this->saleRow(
                'package',
                $package->id,
                $package->created_at,
                $package->service_name ?: ($package->service?->name ?? 'Package'),
                $package->service?->category?->name ?? 'Uncategorized',
                (float) ($package->sale_original_price ?? $package->price_total ?? 0),
                (float) ($package->sale_final_price ?? $package->price_total ?? 0),
                (float) ($package->sale_discount_amount ?? 0),
            ));
        }

        $paymentQuery = PackagePayment::query()
            ->with(['appointment.service.category', 'package.service.category'])
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()]);

        $payments = $paymentQuery->get();
        $giftCardPaymentIds = DB::table('gift_card_redemptions')
            ->whereIn('package_payment_id', $payments->pluck('id'))
            ->whereNull('voided_at')
            ->pluck('package_payment_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $paymentRows = $payments->map(function (PackagePayment $payment) use ($giftCardPaymentIds, $rate) {
            $giftCard = $giftCardPaymentIds->has((int) $payment->id);
            $amountMkd = $this->paymentMkd($payment, $rate);
            $subject = $payment->package ?: $payment->appointment;
            $service = $payment->package?->service ?: $payment->appointment?->service;

            return [
                'id' => (int) $payment->id,
                'date' => $payment->created_at?->toIso8601String(),
                'method' => $giftCard ? 'gift_card' : ($payment->method ?: 'other'),
                'source' => $payment->service_package_id ? 'package' : ($payment->appointment_id ? 'appointment' : 'client'),
                'source_id' => $payment->service_package_id ?: ($payment->appointment_id ?: $payment->user_id),
                'service' => $service?->name ?? ($payment->package?->service_name ?? 'Unlinked payment'),
                'category' => $service?->category?->name ?? 'Uncategorized',
                'amount_mkd' => round($amountMkd, 2),
                'amount_eur_equivalent' => round($amountMkd / $rate, 2),
                'is_gift_card_redemption' => $giftCard,
                'is_voided' => $payment->voided_at !== null,
                'voided_at' => $payment->voided_at?->toIso8601String(),
            ];
        });

        $activePayments = $paymentRows->where('is_voided', false);
        $cashPayments = $activePayments->where('is_gift_card_redemption', false);
        $giftCardAllocated = $activePayments->where('is_gift_card_redemption', true);

        $salesFinal = round((float) $salesRows->sum('final_price_eur'), 2);
        $salesOriginal = round((float) $salesRows->sum('original_price_eur'), 2);
        $discounts = round((float) $salesRows->sum('discount_eur'), 2);
        $cashMkd = round((float) $cashPayments->sum('amount_mkd'), 2);
        $giftMkd = round((float) $giftCardAllocated->sum('amount_mkd'), 2);
        $voidedMkd = round((float) $paymentRows->where('is_voided', true)->sum('amount_mkd'), 2);

        // Current financial position is intentionally independent from the selected report period.
        // It answers "what is owed right now?", while the period above answers "what happened then?".
        $receivables = $this->currentReceivables($rate);
        $giftCardLiability = $this->currentGiftCardLiability($rate);
        $performance = $this->performanceReport($from, $to);

        // Expenses follow the business date entered by Admin, not the row creation timestamp.
        // The current expense schema stores no currency, so expense amounts are treated as MKD.
        $expenses = Expense::query()
            ->with('category:id,name')
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->get();
        $expenseMkd = round((float) $expenses->sum(fn (Expense $expense) => (float) $expense->amount), 2);
        $netCashMkd = round($cashMkd - $expenseMkd, 2);
        $expensesByCategory = $expenses
            ->groupBy(fn (Expense $expense) => $expense->category?->name ?? 'Uncategorized')
            ->map(fn ($rows, $category) => [
                'category' => $category,
                'count' => $rows->count(),
                'amount_mkd' => round((float) $rows->sum(fn (Expense $expense) => (float) $expense->amount), 2),
                'amount_eur_equivalent' => round((float) $rows->sum(fn (Expense $expense) => (float) $expense->amount) / $rate, 2),
            ])
            ->sortByDesc('amount_mkd')
            ->values();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $tz],
            'currency' => ['sales' => 'EUR', 'cash_base' => 'MKD', 'eur_to_mkd' => $rate],
            'summary' => [
                'sales_eur' => $salesFinal,
                'original_sales_eur' => $salesOriginal,
                'discounts_eur' => $discounts,
                'cash_collected_mkd' => $cashMkd,
                'cash_collected_eur_equivalent' => round($cashMkd / $rate, 2),
                'gift_card_allocated_mkd' => $giftMkd,
                'gift_card_allocated_eur_equivalent' => round($giftMkd / $rate, 2),
                'voided_payments_mkd' => $voidedMkd,
                'voided_payments_eur_equivalent' => round($voidedMkd / $rate, 2),
                'sale_count' => $salesRows->count(),
                'payment_count' => $cashPayments->count(),
                'expenses_mkd' => $expenseMkd,
                'expenses_eur_equivalent' => round($expenseMkd / $rate, 2),
                'expense_count' => $expenses->count(),
                'net_cash_mkd' => $netCashMkd,
                'net_cash_eur_equivalent' => round($netCashMkd / $rate, 2),
            ],
            'expenses_by_category' => $expensesByCategory,
            'sales_by_category' => $this->groupSales($salesRows, 'category'),
            'sales_by_service' => $this->groupSales($salesRows, 'service'),
            'performance' => $performance,
            'position' => [
                'as_of' => Carbon::now($tz)->toIso8601String(),
                'receivables' => $receivables,
                'gift_card_liability' => $giftCardLiability,
            ],
            'payments_by_method' => $cashPayments->groupBy('method')->map(fn ($rows, $method) => [
                'method' => $method,
                'count' => $rows->count(),
                'amount_mkd' => round((float) $rows->sum('amount_mkd'), 2),
                'amount_eur_equivalent' => round((float) $rows->sum('amount_mkd') / $rate, 2),
            ])->values()->sortByDesc('amount_mkd')->values(),
            'sales' => $salesRows->sortByDesc('date')->values(),
            'payments' => $paymentRows->sortByDesc('date')->values(),
        ]);
    }

    private function performanceReport(Carbon $from, Carbon $to): array
    {
        // Performance is based on treatments actually completed in the selected period.
        // Package sessions earn operational credit only; the package sale is never repeated here.
        $completed = Appointment::query()
            ->with(['staff:id,name', 'service:id,name,service_category_id', 'service.category:id,name'])
            ->where('status', Appointment::STATUS_COMPLETED)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $staff = $completed->groupBy(fn (Appointment $a) => $a->staff_id ?: 'unassigned')
            ->map(function ($items, $key) {
                $first = $items->first();
                $standalone = $items->whereNull('service_package_id');
                return [
                    'staff_id' => $key === 'unassigned' ? null : (int) $key,
                    'staff_name' => $first->staff?->name ?? 'Unassigned',
                    'completed_treatments' => $items->count(),
                    'unique_clients' => $items->map(fn ($a) => $a->user_id ?: ('guest:'.($a->customer_phone ?: $a->customer_email ?: $a->customer_name)))->unique()->count(),
                    'package_sessions' => $items->whereNotNull('service_package_id')->count(),
                    'standalone_treatments' => $standalone->count(),
                    'standalone_sales_eur' => round((float) $standalone->sum(fn ($a) => $a->finalSalePrice()), 2),
                ];
            })->sortByDesc('completed_treatments')->values();

        $services = $completed->groupBy(fn (Appointment $a) => $a->service_id ?: 'unknown')
            ->map(function ($items, $key) {
                $first = $items->first();
                $standalone = $items->whereNull('service_package_id');
                return [
                    'service_id' => $key === 'unknown' ? null : (int) $key,
                    'service_name' => $first->service?->name ?? 'Unknown service',
                    'category' => $first->service?->category?->name ?? 'Uncategorized',
                    'completed_treatments' => $items->count(),
                    'unique_clients' => $items->map(fn ($a) => $a->user_id ?: ('guest:'.($a->customer_phone ?: $a->customer_email ?: $a->customer_name)))->unique()->count(),
                    'package_sessions' => $items->whereNotNull('service_package_id')->count(),
                    'standalone_treatments' => $standalone->count(),
                    'standalone_sales_eur' => round((float) $standalone->sum(fn ($a) => $a->finalSalePrice()), 2),
                ];
            })->sortByDesc('completed_treatments')->values();

        return [
            'basis' => 'completed_appointment_date',
            'note' => 'Package sessions count as treatments performed, not additional sales. Standalone sales are attributed to the staff member who performed the completed appointment.',
            'summary' => [
                'completed_treatments' => $completed->count(),
                'unique_clients' => $completed->map(fn ($a) => $a->user_id ?: ('guest:'.($a->customer_phone ?: $a->customer_email ?: $a->customer_name)))->unique()->count(),
                'package_sessions' => $completed->whereNotNull('service_package_id')->count(),
                'standalone_treatments' => $completed->whereNull('service_package_id')->count(),
                'standalone_sales_eur' => round((float) $completed->whereNull('service_package_id')->sum(fn ($a) => $a->finalSalePrice()), 2),
            ],
            'by_staff' => $staff,
            'by_service' => $services,
        ];
    }

    private function currentReceivables(float $rate): array
    {
        $packages = ServicePackage::query()
            ->with(['user:id,name,phone,email', 'payments'])
            ->where('status', '!=', ServicePackage::STATUS_CANCELLED)
            ->get();

        $appointments = Appointment::query()
            ->with(['client:id,name,phone,email', 'payments', 'service:id,name'])
            ->whereNull('service_package_id')
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->get();

        $rows = collect();

        foreach ($packages as $package) {
            $saleMkd = strtoupper((string) ($package->currency ?: 'EUR')) === 'MKD'
                ? $package->finalSalePrice()
                : $package->finalSalePrice() * $rate;
            $paidMkd = $package->payments->whereNull('voided_at')
                ->sum(fn (PackagePayment $payment) => $this->paymentMkd($payment, $rate));
            $outstandingMkd = max($saleMkd - $paidMkd, 0);
            if ($outstandingMkd < 0.01) continue;

            $rows->push($this->receivableRow(
                'package', (int) $package->id, $package->user_id,
                $package->user?->name, $package->user?->phone, $package->user?->email,
                $package->service_name ?: 'Package', $saleMkd, $paidMkd, $outstandingMkd, $rate,
            ));
        }

        foreach ($appointments as $appointment) {
            $saleMkd = $appointment->finalSalePrice() * $rate;
            $paidMkd = $appointment->payments->whereNull('voided_at')
                ->sum(fn (PackagePayment $payment) => $this->paymentMkd($payment, $rate));
            $outstandingMkd = max($saleMkd - $paidMkd, 0);
            if ($outstandingMkd < 0.01) continue;

            $rows->push($this->receivableRow(
                'appointment', (int) $appointment->id, $appointment->user_id,
                $appointment->client?->name ?: $appointment->customer_name,
                $appointment->client?->phone ?: $appointment->customer_phone,
                $appointment->client?->email ?: $appointment->customer_email,
                $appointment->service?->name ?: 'Appointment', $saleMkd, $paidMkd, $outstandingMkd, $rate,
            ));
        }

        $byClient = $rows->groupBy(fn ($row) => $row['user_id'] ?: ('guest:'.($row['phone'] ?: $row['email'] ?: $row['client_name'])))
            ->map(function ($items) use ($rate) {
                $first = $items->first();
                $mkd = (float) $items->sum('outstanding_mkd');
                return [
                    'user_id' => $first['user_id'],
                    'client_name' => $first['client_name'],
                    'phone' => $first['phone'],
                    'email' => $first['email'],
                    'item_count' => $items->count(),
                    'outstanding_mkd' => round($mkd, 2),
                    'outstanding_eur_equivalent' => round($mkd / $rate, 2),
                ];
            })->sortByDesc('outstanding_mkd')->values();

        $packageMkd = (float) $rows->where('type', 'package')->sum('outstanding_mkd');
        $appointmentMkd = (float) $rows->where('type', 'appointment')->sum('outstanding_mkd');
        $totalMkd = $packageMkd + $appointmentMkd;

        return [
            'total_mkd' => round($totalMkd, 2),
            'total_eur_equivalent' => round($totalMkd / $rate, 2),
            'package_mkd' => round($packageMkd, 2),
            'package_eur_equivalent' => round($packageMkd / $rate, 2),
            'appointment_mkd' => round($appointmentMkd, 2),
            'appointment_eur_equivalent' => round($appointmentMkd / $rate, 2),
            'client_count' => $byClient->count(),
            'item_count' => $rows->count(),
            'by_client' => $byClient,
            'items' => $rows->sortByDesc('outstanding_mkd')->values(),
        ];
    }

    private function receivableRow(string $type, int $id, ?int $userId, ?string $name, ?string $phone, ?string $email, string $label, float $saleMkd, float $paidMkd, float $outstandingMkd, float $rate): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'user_id' => $userId,
            'client_name' => $name ?: 'Unknown client',
            'phone' => $phone,
            'email' => $email,
            'label' => $label,
            'sale_mkd' => round($saleMkd, 2),
            'paid_mkd' => round($paidMkd, 2),
            'outstanding_mkd' => round($outstandingMkd, 2),
            'outstanding_eur_equivalent' => round($outstandingMkd / $rate, 2),
        ];
    }

    private function currentGiftCardLiability(float $rate): array
    {
        $cards = GiftCard::query()->where('remaining_value', '>', 0)->get();
        $rows = $cards->map(function (GiftCard $card) use ($rate) {
            $remainingMkd = strtoupper((string) $card->currency) === 'MKD'
                ? (float) $card->remaining_value
                : (float) $card->remaining_value * $rate;
            return [
                'id' => (int) $card->id,
                'status' => $card->status,
                'currency' => $card->currency,
                'remaining_value' => (float) $card->remaining_value,
                'remaining_mkd' => round($remainingMkd, 2),
                'remaining_eur_equivalent' => round($remainingMkd / $rate, 2),
                'recipient_name' => $card->recipient_name,
            ];
        });
        $totalMkd = (float) $rows->sum('remaining_mkd');
        $disabledMkd = (float) $rows->where('status', GiftCard::STATUS_DISABLED)->sum('remaining_mkd');

        return [
            'total_mkd' => round($totalMkd, 2),
            'total_eur_equivalent' => round($totalMkd / $rate, 2),
            'card_count' => $rows->count(),
            'disabled_mkd' => round($disabledMkd, 2),
            'disabled_eur_equivalent' => round($disabledMkd / $rate, 2),
            'cards' => $rows->sortByDesc('remaining_mkd')->values(),
        ];
    }

    private function saleRow(string $type, int $id, $date, string $service, string $category, float $original, float $final, float $discount): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'date' => $date?->toIso8601String(),
            'service' => $service,
            'category' => $category,
            'original_price_eur' => round($original, 2),
            'final_price_eur' => round($final, 2),
            'discount_eur' => round($discount, 2),
        ];
    }

    private function paymentMkd(PackagePayment $payment, float $defaultRate): float
    {
        if ($payment->amount_mkd !== null) return (float) $payment->amount_mkd;
        if (strtoupper((string) $payment->currency) === 'EUR') {
            return (float) $payment->amount * (float) ($payment->exchange_rate ?: $defaultRate);
        }
        return (float) $payment->amount;
    }

    private function groupSales($rows, string $key)
    {
        return $rows->groupBy($key)->map(fn ($items, $label) => [
            $key => $label,
            'count' => $items->count(),
            'sales_eur' => round((float) $items->sum('final_price_eur'), 2),
            'discounts_eur' => round((float) $items->sum('discount_eur'), 2),
        ])->values()->sortByDesc('sales_eur')->values();
    }
}
