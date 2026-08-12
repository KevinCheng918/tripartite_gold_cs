<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * VM Server API 回傳格式
 */
class VmServerResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'station_id'   => $this->station_id,
            'station'      => $this->whenLoaded('station', function () {
                return ['id' => $this->station->id, 'name' => $this->station->name];
            }),
            'hostname'     => $this->hostname,
            'internal_ip'  => $this->internal_ip,
            'external_ip'  => $this->external_ip,
            'model_type'   => $this->model_type,
            'spec'         => $this->spec,
            'monthly_fee'  => $this->monthly_fee,
            'vpn_fee'      => $this->vpn_fee,
            'google_fee'   => $this->google_fee,
            'total_fee'    => number_format((float) $this->monthly_fee + (float) $this->vpn_fee + (float) $this->google_fee, 2, '.', ''),
            'billing_day'  => $this->billing_day,
            'power_status' => $this->power_status,
            'status'       => $this->status,
            'note'         => $this->note,
            'created_at'   => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }
}
