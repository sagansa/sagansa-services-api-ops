<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Role;
use App\Models\ShiftStore;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';

        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'api']);

        Storage::fake('public');
    }

    public function test_user_can_check_in_within_store_radius(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $store = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'latitude' => -6.1751,
            'longitude' => 106.8650,
        ]);
        $shift = ShiftStore::factory()->create([
            'tenant_id' => $tenant->id,
            'shift_start_time' => '08:00',
            'shift_end_time' => '16:00',
            'duration' => 480,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/attendance/check-in', [
            'store_id' => $store->id,
            'shift_store_id' => $shift->id,
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('selfie.jpg'),
            'check_in' => CarbonImmutable::create(2024, 1, 1, 8, 5, 0, 'UTC')->toISOString(),
            'latitude' => -6.1753,
            'longitude' => 106.8652,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('attendance.status', Attendance::STATUS_PENDING);
        $response->assertJsonPath('attendance.was_late', true);
        $response->assertJsonPath('attendance.created_by_id', $user->id);
        $response->assertJsonPath('attendance.shift_store_id', $shift->id);
    }

    public function test_check_in_fails_when_user_is_outside_radius(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $store = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'latitude' => -6.1751,
            'longitude' => 106.8650,
        ]);
        $shift = ShiftStore::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/attendance/check-in', [
            'store_id' => $store->id,
            'shift_store_id' => $shift->id,
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('selfie.jpg'),
            'latitude' => -6.0,
            'longitude' => 107.0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('latitude');
    }

    public function test_user_cannot_check_in_twice_without_checking_out(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $store = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'latitude' => -6.1751,
            'longitude' => 106.8650,
        ]);
        $shift = ShiftStore::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/attendance/check-in', [
            'store_id' => $store->id,
            'shift_store_id' => $shift->id,
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('selfie.jpg'),
            'latitude' => -6.1751,
            'longitude' => 106.8650,
        ])->assertCreated();

        $response = $this->postJson('/api/attendance/check-in', [
            'store_id' => $store->id,
            'shift_store_id' => $shift->id,
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('selfie2.jpg'),
            'latitude' => -6.1751,
            'longitude' => 106.8650,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('attendance');
    }

    public function test_user_can_check_out_without_admin_approval(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $store = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'latitude' => -6.1751,
            'longitude' => 106.8650,
        ]);
        $shift = ShiftStore::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($user);

        $checkInResponse = $this->postJson('/api/attendance/check-in', [
            'store_id' => $store->id,
            'shift_store_id' => $shift->id,
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('selfie.jpg'),
            'latitude' => -6.1751,
            'longitude' => 106.8650,
        ])->assertCreated();

        $attendanceId = $checkInResponse->json('attendance.id');

        $checkOutResponse = $this->postJson('/api/attendance/check-out', [
            'attendance_id' => $attendanceId,
            'store_id' => $store->id,
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('checkout.jpg'),
            'latitude' => -6.1752,
            'longitude' => 106.8651,
        ]);

        $checkOutResponse->assertOk();
        $checkOutResponse->assertJsonPath('attendance.status', Attendance::STATUS_APPROVED);
        $checkOutResponse->assertJsonPath('attendance.approved_by_id', null);
        $checkOutResponse->assertJsonPath('attendance.check_out', fn ($value) => ! empty($value));
    }

    public function test_auto_checkout_command_marks_overdue_attendance(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $store = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'latitude' => -6.1751,
            'longitude' => 106.8650,
        ]);

        $attendance = Attendance::factory()->create([
            'store_id' => $store->id,
            'status' => Attendance::STATUS_PENDING,
            'check_in' => CarbonImmutable::now(config('app.timezone'))->subHours(5),
            'check_out' => null,
            'created_by_id' => $user->id,
            'auto_checked_out_at' => null,
        ]);

        Artisan::call('attendance:auto-checkout');

        $attendance->refresh();

        $this->assertNotNull($attendance->check_out);
        $this->assertEquals(Attendance::STATUS_APPROVED, $attendance->status);
        $this->assertNotNull($attendance->auto_checked_out_at);
    }

    public function test_non_admin_cannot_update_attendance_status(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $attendance = Attendance::factory()->create([
            'store_id' => $store->id,
            'created_by_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/attendance/{$attendance->id}/status", [
            'status' => Attendance::STATUS_APPROVED,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => Attendance::STATUS_PENDING,
        ]);
    }

    public function test_admin_can_update_attendance_status(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $creator = User::factory()->create();
        $store = Store::factory()->create();

        $attendance = Attendance::factory()->create([
            'store_id' => $store->id,
            'created_by_id' => $creator->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/attendance/{$attendance->id}/status", [
            'status' => Attendance::STATUS_APPROVED,
        ]);

        $response->assertOk();
        $response->assertJsonPath('attendance.status', Attendance::STATUS_APPROVED);
        $response->assertJsonPath('attendance.approved_by_id', $admin->id);

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => Attendance::STATUS_APPROVED,
            'approved_by_id' => $admin->id,
        ]);
    }

    public function test_non_admin_sees_only_their_attendance_entries(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = Store::factory()->create();

        Attendance::factory()->create([
            'store_id' => $store->id,
            'created_by_id' => $user->id,
        ]);

        Attendance::factory()->create([
            'store_id' => $store->id,
            'created_by_id' => $otherUser->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/attendance');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.created_by_id', (string) $user->id);
    }

    private function fakeImageData(): string
    {
        return 'data:image/png;base64,' . base64_encode('fake-image');
    }
}
