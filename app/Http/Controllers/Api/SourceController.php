<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Source;

class SourceController extends Controller
{
    //
    public function index()
    {
        return response()->json(Source::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate(
            [
                'name' => 'required|string|max:255',
                'url'  => 'required|url|unique:sources,url',
                'type' => 'nullable|string|in:html,rss',
            ]
        );

        $source = Source::create(
            [
                'name'      => $validated['name'],
                'url'       => $validated['url'],
                'type'      => $validated['type'] ?? null,
                'is_active' => true,
            ]
        );

        return response()->json(
            [
                'message' => 'Source registered successfully.',
                'data'    => $source
            ],
            201
        );
    }
}
