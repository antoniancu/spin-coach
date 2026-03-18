<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function select(): View
    {
        $users = User::orderBy('name')->get();
        return view('users.select', compact('users'));
    }

    public function create(): View
    {
        return view('users.create');
    }

    public function apiIndex(): JsonResponse
    {
        $users = User::orderBy('name')->get(['id', 'name', 'avatar_emoji', 'color_hex']);
        return response()->json(['data' => $users, 'error' => null]);
    }

    public function apiStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:32',
            'avatar_emoji' => 'required|string',
            'color_hex' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $user = User::create($validated);

        $request->session()->put('user_id', $user->id);

        return response()->json(['data' => $user, 'error' => null], 201);
    }

    public function apiSelect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $request->session()->put('user_id', $user->id);

        return response()->json(['data' => ['user' => $user], 'error' => null]);
    }

    public function apiDeselect(Request $request): JsonResponse
    {
        $request->session()->forget('user_id');
        return response()->json(['data' => null, 'error' => null]);
    }

    public function apiDestroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($request->session()->get('user_id') === $user->id) {
            $request->session()->forget('user_id');
        }
        $user->delete();
        return response()->json(['data' => null, 'error' => null]);
    }
}
