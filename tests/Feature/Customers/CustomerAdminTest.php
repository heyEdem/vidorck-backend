<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

const CU_PASSWORD = 'Long-Staff-Password9!';

function cuLogin(string $role): void
{
    test()->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    test()->postJson('/api/v1/admin/auth/login', ['email' => strtolower($role).'@example.com', 'password' => CU_PASSWORD])->assertOk();
}

/** @param array<string, mixed> $overrides */
function cuManualOrder(string $phone, string $reference, array $overrides = []): void
{
    test()->postJson('/api/v1/admin/orders/manual', array_replace([
        'confirm' => true, 'fullName' => 'Ama Mensah', 'phone' => $phone, 'region' => 'Greater Accra', 'area' => 'Tema',
        'address' => '12 Oxford Street', 'paymentMethod' => 'MOMO', 'paymentReference' => $reference, 'amountPaidMinor' => 1300,
        'items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 1]],
    ], $overrides))->assertCreated();
}

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    foreach (StaffRole::cases() as $role) {
        StaffUser::create(['name' => ucfirst(strtolower($role->value)), 'email' => strtolower($role->value).'@example.com', 'password' => CU_PASSWORD, 'role' => $role]);
    }
    $category = Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => $category->id, 'is_published' => true]);
    $product->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1400, 'price_minor' => 1300, 'is_active' => true]);
});

it('requires authentication on every customer route', function () {
    $this->getJson('/api/v1/admin/customers')->assertUnauthorized();
    $this->getJson('/api/v1/admin/customers/abc')->assertUnauthorized();
    $this->postJson('/api/v1/admin/customers/abc/notes', ['body' => 'x'])->assertUnauthorized();
});

it('customer_upserted_by_phone', function () {
    cuLogin('SALES_ORDERS');
    cuManualOrder('0244000000', 'REF-1');
    cuManualOrder('+233244000000', 'REF-2', ['fullName' => 'Ama M. Mensah']);

    expect(Customer::query()->count())->toBe(1);
    $res = $this->getJson('/api/v1/admin/customers')->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ordersCount', 2)->assertJsonPath('data.0.lifetimePaidMinor', 2600)
        ->assertJsonPath('data.0.phoneE164', '+233244000000')->assertJsonPath('data.0.fullName', 'Ama M. Mensah');
    $this->getJson('/api/v1/admin/customers/'.$res->json('data.0.publicId'))->assertOk()
        ->assertJsonCount(2, 'data.orders')->assertJsonPath('data.ordersCount', 2);
});

it('keeps a known email when a later manual order omits it', function () {
    cuLogin('ADMIN');
    cuManualOrder('0244000000', 'REF-1', ['email' => 'ama@example.com']);
    cuManualOrder('0244000000', 'REF-2');
    expect(Customer::query()->sole()->email)->toBe('ama@example.com');
});

it('searches by name, phone in either format, and email for both roles', function () {
    cuLogin('ADMIN');
    cuManualOrder('0244000000', 'REF-1', ['fullName' => 'Ama Mensah', 'email' => 'ama@example.com']);
    cuManualOrder('0201112222', 'REF-2', ['fullName' => 'Kofi Boateng', 'email' => 'kofi@example.org']);
    foreach (['SALES_ORDERS', 'ADMIN'] as $role) {
        cuLogin($role);
        foreach (['mensah' => 'Ama Mensah', '0244000000' => 'Ama Mensah', '+233244000000' => 'Ama Mensah', 'kofi@example.org' => 'Kofi Boateng', '0201112222' => 'Kofi Boateng'] as $term => $name) {
            $this->getJson('/api/v1/admin/customers?search='.urlencode($term))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.fullName', $name);
        }
        $this->getJson('/api/v1/admin/customers?search=nobody')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/customers?search=%25')->assertOk()->assertJsonPath('meta.total', 0);
    }
    $this->getJson('/api/v1/admin/customers?perPage=1000')->assertUnprocessable();
});

it('shows unknown customers as 404 and never exposes numeric ids', function () {
    cuLogin('ADMIN');
    cuManualOrder('0244000000', 'REF-1');
    $this->getJson('/api/v1/admin/customers/'.Customer::query()->sole()->id)->assertNotFound();
    $this->getJson('/api/v1/admin/customers/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    expect(json_encode($this->getJson('/api/v1/admin/customers')->json()))->not->toContain('"id"');
});

it('appends customer notes for both roles with audit and no edit or delete path', function () {
    cuLogin('ADMIN');
    cuManualOrder('0244000000', 'REF-1');
    $customer = Customer::query()->sole();
    foreach (['SALES_ORDERS', 'ADMIN'] as $role) {
        cuLogin($role);
        $this->postJson("/api/v1/admin/customers/{$customer->public_id}/notes", ['body' => " Prefers WhatsApp ($role) "])->assertCreated()
            ->assertJsonPath('data.body', "Prefers WhatsApp ($role)")->assertJsonPath('data.staffName', ucfirst(strtolower($role)));
    }
    $this->postJson("/api/v1/admin/customers/{$customer->public_id}/notes", ['body' => ''])->assertUnprocessable();
    $this->postJson('/api/v1/admin/customers/01ARZ3NDEKTSV4RRFFQ69G5FAV/notes', ['body' => 'x'])->assertNotFound();
    $this->getJson("/api/v1/admin/customers/{$customer->public_id}")->assertOk()->assertJsonCount(2, 'data.notes');
    expect(AuditLog::query()->where('action', 'customer.note.added')->count())->toBe(2);
    $this->patchJson("/api/v1/admin/customers/{$customer->public_id}/notes/1", ['body' => 'x'])->assertStatus(404);
    $this->deleteJson("/api/v1/admin/customers/{$customer->public_id}/notes/1")->assertStatus(404);
    expect(fn () => DB::table('customer_notes')->update(['body' => 'edited']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('customer_notes')->delete())->toThrow(QueryException::class)
        ->and(fn () => CustomerNote::query()->first()->update(['body' => 'edited']))->toThrow(LogicException::class)
        ->and(CustomerNote::query()->count())->toBe(2);
});
