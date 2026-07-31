<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExpenseRequest;
use App\Http\Requests\Admin\UpdateExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::query()
            ->with(['category:id,name,slug', 'enteredBy:id,name,email']);

        if ($request->filled('expense_category_id')) {
            $query->where('expense_category_id', $request->integer('expense_category_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->date('date_to'));
        }

        $sort = $request->get('sort', 'expense_date');
        $dir = $request->get('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sort, ['expense_date', 'amount', 'created_at'])) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('expense_date', 'desc')->orderBy('id', 'desc');
        }

        $expenses = $query->paginate((int) $request->get('per_page', 15));

        return response()->json($expenses);
    }

    public function store(StoreExpenseRequest $request)
    {
        $data = $request->validated();

        $expense = Expense::create([
            'expense_category_id' => $data['expense_category_id'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'note' => $data['note'] ?? null,
            'entered_by' => $request->user()?->id,
        ]);

        return response()->json(
            $expense->load(['category:id,name,slug', 'enteredBy:id,name,email']),
            201
        );
    }

    public function show(Expense $expense)
    {
        return response()->json(
            $expense->load(['category:id,name,slug', 'enteredBy:id,name,email'])
        );
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $expense->update($request->validated());

        return response()->json(
            $expense->fresh()->load(['category:id,name,slug', 'enteredBy:id,name,email'])
        );
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();

        return response()->json([
            'message' => 'Expense deleted successfully.',
        ]);
    }
}