<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'AutoRescue AI')</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --card: #ffffff;
            --text: #14213d;
            --muted: #5c677d;
            --accent: #d62828;
            --accent-dark: #9d0208;
            --ok: #2a9d8f;
            --warn: #e76f51;
            --border: #dde5f0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top right, #edf2ff, var(--bg));
            color: var(--text);
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(15, 35, 70, 0.06);
        }
        .grid {
            display: grid;
            gap: 16px;
        }
        .grid-2 {
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
        .btn {
            border: none;
            border-radius: 10px;
            padding: 12px 16px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
        }
        .btn-primary {
            background: var(--accent);
            color: #fff;
        }
        .btn-primary:hover {
            background: var(--accent-dark);
        }
        .tag {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            color: #fff;
            background: var(--ok);
        }
        .tag.warn { background: var(--warn); }
        .muted { color: var(--muted); }
        .error {
            color: #fff;
            background: #b42318;
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
    </style>
</head>
<body>
<div class="container">
    @yield('content')
</div>
</body>
</html>

