<?php

namespace App\Central\Models;

use App\Concerns\HasDynamicConnection;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasDynamicConnection;

    protected $connection = 'master';

    protected $fillable = [
        'name',
        'guard_name',
        'description',
    ];

    protected $casts = [
        //
    ];
}
