<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Method Not Allowed - {{ config('app.name', 'API') }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #1f2937 0%, #111827 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            color: #ffffff;
        }
        
        .error-container {
            max-width: 32rem;
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(16px);
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 2rem;
            text-align: center;
        }
        
        @media (min-width: 768px) {
            .error-container {
                padding: 3rem;
            }
        }
        
        .icon-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 6rem;
            height: 6rem;
            border-radius: 50%;
            background: rgba(234, 179, 8, 0.2);
            margin-bottom: 1.5rem;
        }
        
        .icon-wrapper svg {
            width: 3rem;
            height: 3rem;
            color: #fbbf24;
        }
        
        .error-code {
            font-size: 4rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 1rem;
            line-height: 1;
        }
        
        .error-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #e5e7eb;
            margin-bottom: 1rem;
        }
        
        @media (min-width: 768px) {
            .error-title {
                font-size: 1.875rem;
            }
        }
        
        .error-message {
            color: #9ca3af;
            font-size: 1.125rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .button-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .home-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            background: #2563eb;
            color: #ffffff;
            font-weight: 500;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        }
        
        .home-button:hover {
            background: #1d4ed8;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4);
            transform: translateY(-1px);
        }
        
        .home-button svg {
            width: 1.25rem;
            height: 1.25rem;
            margin-right: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div>
            <div class="icon-wrapper">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
            <h1 class="error-code">405</h1>
            <h2 class="error-title">Method Not Allowed</h2>
            <p class="error-message">
                The request method is not supported for this resource.
            </p>
        </div>
        
        <div class="button-container">
            <a href="{{ url('/') }}" class="home-button">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                Go to Home
            </a>
        </div>
    </div>
</body>
</html>
