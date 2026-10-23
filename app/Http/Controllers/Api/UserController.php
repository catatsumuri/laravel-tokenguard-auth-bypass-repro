<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\User;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::orderBy('id')->get([
            'id', 'name', 'email', 'created_at', 'updated_at',
        ]));
    }
}
