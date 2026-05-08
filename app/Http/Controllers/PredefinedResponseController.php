<?php

namespace App\Http\Controllers;

use App\Models\PredefinedResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PredefinedResponseController extends Controller
{
    public function index(Request $request)
    {
        $query = PredefinedResponse::query();

        if ($request->search) {
            $query->where('title', 'like', '%'.$request->search.'%')
                ->orWhere('response', 'like', '%'.$request->search.'%');
        }

        $responses = $query->orderBy('sort_order')->orderBy('id', 'desc')->paginate(25);

        return Inertia::render('PredefinedResponse/Index', [
            'responses' => $responses,
            'filters' => [
                'search' => $request->search,
            ],
        ]);
    }

    public function active()
    {
        // dd('daklshdasdhlasd');
        $responses = PredefinedResponse::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id', 'desc')
            ->select('id', 'title', 'response')
            ->get();

        return response()->json([
            'responses' => $responses,
        ]);
    }

    public function create()
    {
        return Inertia::render('PredefinedResponse/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'response' => 'required|string',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|unique:predefined_responses,sort_order',
        ]);

        PredefinedResponse::create([
            'title' => $validated['title'],
            'response' => $validated['response'],
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()
            ->route('predefined-responses.index')
            ->with('success', 'Predefined response created successfully.');
    }

    public function edit(PredefinedResponse $predefinedResponse)
    {
        return Inertia::render('PredefinedResponse/Edit', [
            'response' => $predefinedResponse,
        ]);
    }

    public function update(Request $request, PredefinedResponse $predefinedResponse)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'response' => 'required|string',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|unique:predefined_responses,sort_order,'.$predefinedResponse->id,
        ]);

        $predefinedResponse->update($validated);

        return redirect()
            ->route('predefined-responses.index')
            ->with('success', 'Predefined response updated successfully.');
    }

    public function destroy(PredefinedResponse $predefinedResponse)
    {
        $predefinedResponse->delete();

        return redirect()
            ->route('predefined-responses.index')
            ->with('success', 'Predefined response deleted successfully.');
    }
   
}
