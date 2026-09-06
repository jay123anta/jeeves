<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Jeeves -  Demo</title>
    <style>
        body { margin: 0; background: #f3f4f6; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .demo-shell { max-width: 900px; margin: 0 auto; padding: 32px 16px 64px; }
        .demo-head { text-align: center; margin-bottom: 24px; }
        .demo-head h1 { font-size: 1.5rem; margin: 0 0 6px; color: #111827; }
        .demo-head p { color: #6b7280; margin: 0; font-size: .92rem; }
    </style>
</head>
<body>
<div class="demo-shell">
    <div class="demo-head">
        <h1>Jeeves</h1>
        <p>Ask questions about your data in plain language -  by text or voice.
           The AI sees only your schema; your data never leaves this server.</p>
    </div>
    <x-jeeves::widget />
</div>
</body>
</html>
