<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GiftCard;
use App\Models\PackagePayment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task63FinancialReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'Europe/Skopje'));

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $this->admin = User::create([
            'name' => 'Task 63 Admin',
            'email' => 'task63-admin@example.com',
            'password' => 'password',
        ]);
        $this->admin->assignRole('admin');

        $this->client = User::create([
            'name' => 'Task 63 Client',
            'email' => 'task63-client@example.com',
            'phone' => '+38970123456',
            'password' => null,
        ]);
        $this->client->assignRole('client');

        $category = ServiceCategory::create([
            'name' => 'Task 63 Laser',
            'slug' => 'task-63-laser',
            'is_active' => true,
        ]);

        $this->service = Service::create([
            'service_category_id' => $category->id,
            'name' => 'Task 63 Package',
            'slug' => 'task-63-package',
            'duration_minutes' => 60,
            'price' => 450,
            'is_active' => true,
            'is_bookable' => true,
            'is_package' => true,
            'total_sessions' => 6,
            'usage_type' => Service::USAGE_SESSION,
            'minimum_interval_days' => 0,
            'deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => Service::STAFF_ANY_QUALIFIED,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_discount_deposit_gift_card_expense_and_void_reconcile(): void
    {
        $package = $this->sellPackage();

        $card = GiftCard::create([
            'initial_value' => '100.00',
            'remaining_value' => '100.00',
            'currency' => 'EUR',
            'recipient_user_id' => $this->client->id,
            'created_by_id' => $this->admin->id,
        ]);

        $gift = $this->postJson("/api/v1/admin/gift-cards/{$card->id}/redeem", [
            'amount_eur' => '40.00',
            'service_package_id' => $package->id,
        ])->assertCreated();

        $category = ExpenseCategory::create([
            'name' => 'Task 63 Utilities',
            'slug' => 'task-63-utilities',
            'is_active' => true,
        ]);
        Expense::create([
            'expense_category_id' => $category->id,
            'amount' => 1000,
            'expense_date' => '2026-10-04',
            'entered_by' => $this->admin->id,
        ]);

        dump(
            Expense::query()->get()->map(fn ($e) => [
                'id' => $e->id,
                'amount' => $e->amount,
                'expense_date' => $e->expense_date?->format('Y-m-d'),
            ])->toArray()
        );

        $report = $this->report();
       dump(
    Expense::query()->get()->map(fn ($e) => [
        'id' => $e->id,
        'amount' => $e->amount,
        'expense_date' => $e->expense_date?->format('Y-m-d'),
    ])->toArray()
);

dump([
    'where_date' => Expense::query()
        ->whereDate('expense_date', '2026-10-04')
        ->count(),

    'where_between_strings' => Expense::query()
        ->whereBetween('expense_date', ['2026-10-04', '2026-10-04'])
        ->count(),

    'raw_value' => \DB::table('expenses')
        ->value('expense_date'),
]);

$report = $this->report();
        $report
            ->assertJsonPath('summary.original_sales_eur', 450)
            ->assertJsonPath('summary.sales_eur', 390)
            ->assertJsonPath('summary.discounts_eur', 60)
            ->assertJsonPath('summary.sale_count', 1)
            ->assertJsonPath('summary.cash_collected_mkd', 6160)
            ->assertJsonPath('summary.gift_card_allocated_mkd', 2464)
            ->assertJsonPath('position.receivables.total_mkd', 15400)
            ->assertJsonPath('position.gift_card_liability.total_mkd', 3696)
            ->assertJsonPath('summary.expenses_mkd', 1000)
            ->assertJsonPath('summary.net_cash_mkd', 5160);

        // Gift-card void restores stored value and receivable, never cash.
        $this->patchJson('/api/v1/admin/payments/'.$gift->json('data.payment_id').'/void', [
            'reason' => 'Task 63 gift-card reversal',
        ])->assertOk();

        $report = $this->report();
        $report
            ->assertJsonPath('summary.cash_collected_mkd', 6160)
            ->assertJsonPath('summary.gift_card_allocated_mkd', 0)
            ->assertJsonPath('position.receivables.total_mkd', 17864)
            ->assertJsonPath('position.gift_card_liability.total_mkd', 6160)
            ->assertJsonPath('summary.net_cash_mkd', 5160);

        $this->assertSame('100.00', $card->fresh()->remaining_value);

        // Cash-payment void leaves sale/discount intact but restores receivable.
        $cash = PackagePayment::where('service_package_id', $package->id)
            ->whereNull('voided_at')
            ->firstOrFail();

        $this->patchJson("/api/v1/admin/payments/{$cash->id}/void", [
            'reason' => 'Task 63 cash reversal',
        ])->assertOk();

        $report = $this->report();
        $report
            ->assertJsonPath('summary.sales_eur', 390)
            ->assertJsonPath('summary.discounts_eur', 60)
            ->assertJsonPath('summary.cash_collected_mkd', 0)
            ->assertJsonPath('position.receivables.total_mkd', 24024)
            ->assertJsonPath('summary.net_cash_mkd', -1000);

        $this->assertSame(0.0, $package->fresh()->amount_paid);
        $this->assertSame(390.0, $package->fresh()->remaining_to_pay);
    }

    public function test_expenses_use_business_date_not_created_at(): void
    {
        $category = ExpenseCategory::create([
            'name' => 'Task 63 Backdated',
            'slug' => 'task-63-backdated',
            'is_active' => true,
        ]);

        Expense::create([
            'expense_category_id' => $category->id,
            'amount' => 1200,
            'expense_date' => '2026-09-20',
            'entered_by' => $this->admin->id,
        ]);

        $this->getJson('/api/v1/admin/financial-reports?from=2026-10-04&to=2026-10-04')
            ->assertOk()
            ->assertJsonPath('summary.expenses_mkd', 0);

        $this->getJson('/api/v1/admin/financial-reports?from=2026-09-20&to=2026-09-20')
            ->assertOk()
            ->assertJsonPath('summary.expenses_mkd', 1200)
            ->assertJsonPath('summary.net_cash_mkd', -1200);
    }

    private function sellPackage(): ServicePackage
    {
        $response = $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'currency' => 'EUR',
            'starts_on' => '2026-10-04',
            'sale_discount_type' => 'fixed',
            'sale_discount_value' => 60,
            'initial_payment' => [
                'amount' => 100,
                'method' => 'cash',
                'currency' => 'EUR',
                'note' => 'Task 63 deposit',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.sale_original_price', 450)
            ->assertJsonPath('data.sale_final_price', 390)
            ->assertJsonPath('data.sale_discount_amount', 60)
            ->assertJsonPath('data.amount_paid', 100)
            ->assertJsonPath('data.remaining_balance', 290);

        return ServicePackage::findOrFail((int) $response->json('data.id'));
    }

    private function report()
    {
        return $this->getJson('/api/v1/admin/financial-reports?from=2026-10-04&to=2026-10-04')
            ->assertOk();
    }
}
