<?php

namespace Database\Factories\Concerns;

use Illuminate\Database\Eloquent\Model;

trait HasParentRelationship
{
    public function useParentCompany(): self
    {
        return $this->state(function (array $attributes, Model $parent) {
            return [
                'company_id' => $parent->company_id,
                'created_by' => $parent->created_by ?? 1,
                'updated_by' => $parent->updated_by ?? 1,
            ];
        });
    }
}
