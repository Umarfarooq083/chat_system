<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = Company::query();
        $companies = $query->latest()->paginate(25);

        return Inertia::render('Company/Index', [
            'companies' => $companies,
            'filters' => [
                'trashed' => $request->trashed,
                'search' => $request->search,
            ],
        ]);
    }
  
    public function create()
    {
        return Inertia::render('Company/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|hexcolor',
        ]);

        Company::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company created successfully.');
    }

    public function show($id)
    {
        $company = Company::withTrashed()->findOrFail($id);

        return Inertia::render('Company/Show', [
            'company' => $company,
        ]);
    }

    public function edit(Company $company)
    {
        return Inertia::render('Company/Edit', [
            'company' => $company,
        ]);
    }

    public function update(Request $request, Company $company)
    {
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|hexcolor',
        ]);
        if($request->name == 'Default'){
            return redirect()
            ->route('companies.index')
            ->with('success', 'You can not update the default company.');
        }else{
            $company->update($validated);
        }
        return redirect()
            ->route('companies.index')
            ->with('success', 'Company updated successfully.');
    }

    public function destroy(Company $company)
    {
        $company->delete();

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company deleted successfully.');
    }
 
}
