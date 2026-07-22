<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions;

use Illuminate\Database\Eloquent\Model;

/**
 * "Yetakchi" resurslari (hokim yordamchisi / yoshlar yetakchisi) uchun umumiy
 * yaratish/yangilash. Ilgari CreateHy/CreateYy va UpdateHy/UpdateYy actionlari
 * bir xil bir qatorli mantiq edi (DRY) — endi bitta generic action.
 */
class PersistLeaderAction
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     */
    public function create(string $modelClass, array $data, string $createdBy): Model
    {
        return $modelClass::create([
            ...$data,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $model, array $data): Model
    {
        $model->update($data);

        return $model->refresh();
    }
}
