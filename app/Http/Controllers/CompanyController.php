<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    // public function __construct()
    // {
    //     $this->middleware('auth')->except(['index']);
    // }

    /**
     * Display a listing of the resource.
     */
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
        ]);

        Company::create($validated);

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
