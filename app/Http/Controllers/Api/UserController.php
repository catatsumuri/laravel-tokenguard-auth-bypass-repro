<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::orderBy('id')->get([
            'id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at',
        ]));
    }
}
