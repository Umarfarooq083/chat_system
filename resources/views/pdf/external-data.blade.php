<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Chat Data</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 10px; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .meta { font-size: 11px; color: #444; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border: 1px solid #ddd; padding: 6px 8px; vertical-align: top; }
        th { background: #f5f5f5; text-align: left; width: 32%; }
        pre { background: #f7f7f7; border: 1px solid #e5e5e5; padding: 10px; white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body>
    <h1>Chat Data PDF</h1>
    <div class="meta">
        Chat ID: {{ $chat->id }}<br>
        Generated: {{ $generatedAt }}
    </div>

    <h2>User Info</h2>
    <table>
        <tr><th>Phone No</th><td>{{ $chat->phone ?: '-' }}</td></tr>
        <tr><th>Customer Name</th><td>{{ $chat->customer_name ?: '-' }}</td></tr>
        <tr><th>Registration No</th><td>{{ ($registrationNo ?? $chat->registration_no) ?: '-' }}</td></tr>
        <tr><th>Email</th><td>{{ $chat->email ?: '-' }}</td></tr>
    </table>

    <h2>Third-Party Response</h2>
    @php
        $cols = $data['data']['col'] ?? null;
        $cols = is_array($cols) ? $cols : null;
        $fileId = null;
        if ($cols && isset($cols[0]['file_id'])) {
            $fileId = $cols[0]['file_id'];
        }
    @endphp

    @if ($fileId)
        <div class="meta">File ID: {{ $fileId }}</div>
    @endif

    @if ($cols)
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Due Date</th>
                    <th>Amount</th>
                    <th>Credit</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Payments</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cols as $row)
                    @php
                        $payments = isset($row['payments']) && is_array($row['payments']) ? $row['payments'] : [];
                        $status = ($row['status'] ?? null) === 1 ? 'Paid' : 'Unpaid';
                    @endphp
                    <tr>
                        <td>{{ $row['title'] ?? '-' }}</td>
                        <td>{{ $row['due_date'] ?? '-' }}</td>
                        <td>{{ $row['amount'] ?? '-' }}</td>
                        <td>{{ $row['credit'] ?? '-' }}</td>
                        <td>{{ $row['balance'] ?? '-' }}</td>
                        <td>{{ $status }}</td>
                        <td>
                            @if (count($payments))
                                @foreach ($payments as $p)
                                    <div>
                                        {{ $p['recv_date'] ?? '-' }}:
                                        {{ $p['amount'] ?? '-' }}
                                        @if (!empty($p['surcharge']))
                                            (Surcharge {{ $p['surcharge'] }})
                                        @endif
                                        @if (!empty($p['transaction']))
                                            - Txn {{ $p['transaction'] }}
                                        @endif
                                    </div>
                                @endforeach
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <h4>File Not Available</h4>
    @endif
</body>
</html>
