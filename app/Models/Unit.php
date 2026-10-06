<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'sort'])]
class Unit extends Model
{
    use HasFactory;

    /**
     * @return HasMany<Voter, $this>
     */
    public function voters(): HasMany
    {
        return $this->hasMany(Voter::class);
    }
}
