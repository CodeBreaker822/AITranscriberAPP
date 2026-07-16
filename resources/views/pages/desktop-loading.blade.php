<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#081018">
        <title>Starting AITranscriber</title>
        <style>
            :root {
                color-scheme: dark;
                font-family: "Instrument Sans", "Segoe UI", sans-serif;
                background: #071018;
                color: #e2e8f0;
            }

            * {
                box-sizing: border-box;
            }

            body {
                align-items: center;
                background:
                    radial-gradient(circle at 20% 20%, rgba(34, 211, 238, 0.12), transparent 28rem),
                    linear-gradient(180deg, #071018 0%, #0d1620 52%, #101820 100%);
                display: flex;
                height: 100vh;
                justify-content: center;
                margin: 0;
                overflow: hidden;
            }

            main {
                max-width: 28rem;
                padding: 2rem;
                text-align: center;
                width: min(100%, 32rem);
            }

            img {
                height: 4rem;
                margin-bottom: 1.5rem;
                width: 4rem;
            }

            h1 {
                font-size: 1.5rem;
                line-height: 1.2;
                margin: 0;
            }

            p {
                color: #94a3b8;
                font-size: 0.9rem;
                line-height: 1.6;
                margin: 0.75rem 0 0;
            }

            .track {
                background: rgba(148, 163, 184, 0.18);
                border-radius: 999px;
                height: 0.5rem;
                margin-top: 1.5rem;
                overflow: hidden;
            }

            .bar {
                animation: load 1.25s ease-in-out infinite;
                background: linear-gradient(90deg, #22d3ee, #34d399, #fbbf24);
                border-radius: inherit;
                height: 100%;
                width: 45%;
            }

            .status {
                color: #67e8f9;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.18em;
                margin-top: 1rem;
                text-transform: uppercase;
            }

            @keyframes load {
                0% {
                    transform: translateX(-110%);
                }

                100% {
                    transform: translateX(230%);
                }
            }
        </style>
    </head>
    <body>
        <main>
            <img src="{{ asset('AILogo.png') }}" alt="">
            <h1>Starting AITranscriber</h1>
            <p>The desktop app is preparing its local workspace and frontend assets.</p>
            <div class="track" aria-hidden="true">
                <div class="bar"></div>
            </div>
            <div class="status" data-status>Preparing interface</div>
        </main>

        <script>
            const status = document.querySelector('[data-status]');
            const messages = [
                'Preparing interface',
                'Checking local server',
                'Loading app assets',
                'Almost ready',
            ];
            let attempts = 0;

            const poll = async () => {
                attempts += 1;
                status.textContent = messages[Math.min(messages.length - 1, Math.floor(attempts / 4))];

                try {
                    const response = await fetch('{{ route('desktop.assets-ready') }}', {
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                    });

                    if (response.ok) {
                        window.location.replace('{{ route('transcription.live') }}');
                        return;
                    }
                } catch (error) {
                }

                window.setTimeout(poll, 750);
            };

            poll();
        </script>
    </body>
</html>
