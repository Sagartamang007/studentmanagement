<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\Admission;
use App\Models\CashFlow;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        // Capture the search query if needed
        $search = $request->input('search');

        // Query builder with sorting and pagination
        $receipts = Receipt::with('admission')
            ->when($search, function ($query, $search) {
                return $query->where('phone', 'LIKE', "%{$search}%")
                             ->orWhereHas('admission', function ($q) use ($search) {
                                 $q->where('name', 'LIKE', "%{$search}%");
                             });
            })
            ->orderBy('payment_date', 'desc') // Latest receipts first
            ->paginate(10); // Number of items per page

        // Format payment_date for display
        $receipts->getCollection()->transform(function ($receipt) {
            $receipt->formatted_payment_date = \Carbon\Carbon::parse($receipt->payment_date)->format('Y-m-d');
            return $receipt;
        });

        return view('admin.receipts.index', compact('receipts', 'search'));
    }

    public function create()
    {
        return view('admin.receipts.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'phone' => 'required|exists:admissions,phone',
            'amount_received' => 'required|numeric|min:0',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string', // Cash or Bank
        ]);

        $admission = Admission::where('phone', $request->phone)->first();

        if (!$admission) {
            return back()->withErrors(['phone' => 'No admission found for this phone number.']);
        }

        // Check if the payment already clears the total fee
        $totalPaidFee = Receipt::where('phone', $request->phone)
            ->sum('amount_received') + $request->amount_received;

        if ($totalPaidFee >= $admission->total_fee) {
            if ($totalPaidFee > $admission->total_fee) {
                return back()->withErrors(['amount_received' => 'Payment amount exceeds the remaining fee.']);
            }

            // Payment already cleared
            return back()->withErrors(['amount_received' => 'Payment already cleared.']);
        }

        // Create the receipt
        $receipt = Receipt::create([
            'phone' => $request->phone,
            'amount_received' => $request->amount_received,
            'payment_date' => $request->payment_date,
            'payment_method' => $request->payment_method,
        ]);

        // Update the remaining fee in the admission record
        $admission->remaining_fee -= $request->amount_received;
        $admission->save();

        // Handle cash flow based on payment method (cash or bank)
        CashFlow::createInward(
            $request->payment_method,  // source
            $request->amount_received, // amount
            'Tuition Fee',             // category (optional)
            'Payment received for Receipt #' . $receipt->id, // description (optional)
            $request->payment_date     // date
        );

        return redirect()->route('admin.receipts.create')->with('success', 'Receipt created successfully and cash flow updated.');
    }

    public function getStudentByPhone($phone)
    {
        $admission = Admission::where('phone', $phone)->first();

        if (!$admission) {
            return response()->json(['error' => 'Student not found'], 404);
        }

        return response()->json([
            'name' => $admission->name,
            'remaining_fee' => $admission->remaining_fee,
        ]);
    }
    public function edit($id)
    {
        $receipt = Receipt::findOrFail($id);

        // Fetch the associated admission record for the receipt
        $admission = Admission::where('phone', $receipt->phone)->first();

        return view('admin.receipts.edit', compact('receipt', 'admission'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'phone' => 'required|exists:admissions,phone',
            'amount_received' => 'required|numeric',
            'payment_date' => 'required|date',
        ]);

        $receipt = Receipt::findOrFail($id);
        $admission = Admission::where('phone', $request->phone)->first();

        if (!$admission) {
            return back()->withErrors(['phone' => 'No admission found for this phone number.']);
        }

        // Update the receipt
        $receipt->update([
            'phone' => $request->phone,
            'amount_received' => $request->amount_received,
            'payment_date' => $request->payment_date,
        ]);

        // Update the remaining fee in the admission record
        $admission->remaining_fee -= $request->amount_received;
        $admission->save();

        return redirect()->route('admin.receipts.index')->with('success', 'Receipt updated successfully.');
    }
}
