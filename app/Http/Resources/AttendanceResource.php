<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\Attendance
 */
class AttendanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store' => $this->whenLoaded('store', function () {
                return [
                    'id' => $this->store?->id,
                    'name' => $this->store?->name,
                    'nickname' => $this->store?->nickname,
                    'phone' => $this->store?->phone,
                    'email' => $this->store?->email,
                    'status' => $this->store?->status,
                    'radius' => $this->store?->radius,
                    'latitude' => $this->store?->latitude,
                    'longitude' => $this->store?->longitude,
                ];
            }),
            'store_id' => $this->store_id,
            'shift_store' => $this->whenLoaded('shiftStore', function () {
                return [
                    'id' => $this->shiftStore?->id,
                    'name' => $this->shiftStore?->name,
                    'shift_start_time' => $this->shiftStore?->shift_start_time,
                    'shift_end_time' => $this->shiftStore?->shift_end_time,
                    'duration' => $this->shiftStore?->duration,
                ];
            }),
            'shift_store_id' => $this->shift_store_id,
            'status' => $this->status,
            'was_late' => (bool) $this->was_late,
            'image_in' => $this->resolveImageUrl($this->image_in),
            'check_in' => $this->check_in?->toISOString(),
            'latitude_in' => $this->latitude_in,
            'longitude_in' => $this->longitude_in,
            'location_in' => $this->when(
                ! is_null($this->latitude_in) && ! is_null($this->longitude_in),
                fn () => [
                    'latitude' => $this->latitude_in,
                    'longitude' => $this->longitude_in,
                ],
            ),
            'image_out' => $this->resolveImageUrl($this->image_out),
            'check_out' => $this->check_out?->toISOString(),
            'latitude_out' => $this->latitude_out,
            'longitude_out' => $this->longitude_out,
            'location_out' => $this->when(
                ! is_null($this->latitude_out) && ! is_null($this->longitude_out),
                fn () => [
                    'latitude' => $this->latitude_out,
                    'longitude' => $this->longitude_out,
                ],
            ),
            'auto_checked_out_at' => $this->auto_checked_out_at?->toISOString(),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator?->uuid ?: $this->creator?->id,
                    'uuid' => $this->creator?->uuid,
                    'name' => $this->creator?->name,
                    'email' => $this->creator?->email,
                ];
            }),
            'approved_by' => $this->whenLoaded('approver', function () {
                return [
                    'id' => $this->approver?->uuid ?: $this->approver?->id,
                    'uuid' => $this->approver?->uuid,
                    'name' => $this->approver?->name,
                    'email' => $this->approver?->email,
                ];
            }),
            'created_by_id' => $this->created_by_id,
            'approved_by_id' => $this->approved_by_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }

    private function resolveImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Image stored in the dedicated img service
        $imgBaseUrl = rtrim(env('IMG_SERVICE_URL', 'https://img.sagansa.id'), '/');

        return "{$imgBaseUrl}/storage/{$path}";
    }
}
