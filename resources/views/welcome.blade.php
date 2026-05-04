<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ND-Hotspot | Private Gateway</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --bg: #0f172a;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        body {
            background-color: var(--bg);
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(168, 85, 247, 0.15) 0px, transparent 50%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            overflow: hidden;
        }
        .container {
            text-align: center;
            padding: 3.5rem 2.5rem;
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 48px;
            max-width: 540px;
            width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 1.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9) translateY(30px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .icon-box {
            width: 88px;
            height: 88px;
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2.5rem;
            color: var(--primary);
        }
        h1 {
            font-weight: 800;
            font-size: 2.25rem;
            margin-bottom: 1.25rem;
            letter-spacing: -0.03em;
            background: linear-gradient(to bottom right, #ffffff 30%, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        p {
            color: #94a3b8;
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 3rem;
            font-weight: 500;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 1.125rem 2.5rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 15px 30px -5px rgba(99, 102, 241, 0.4);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .btn:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 25px 40px -10px rgba(99, 102, 241, 0.5);
            background: #4f46e5;
        }
        .footer {
            margin-top: 3rem;
            font-size: 0.7rem;
            color: #475569;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .status-dot {
            width: 6px;
            height: 6px;
            background: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 10px #22c55e;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon-box">
            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                <circle cx="12" cy="12" r="3"></circle>
            </svg>
        </div>
        <h1>Oops! Sepertinya Kamu Tersesat</h1>
        <p>Anda telah mencapai <b>Secure Private Gateway</b> ND-Hotspot. Halaman ini diproteksi dan hanya digunakan untuk komunikasi antar sistem.</p>
        <a href="https://nd-hostpot.net" class="btn">
            Kembali ke Beranda
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
        <div class="footer">
            <div class="status-dot"></div>
            Core Engine Node Active
        </div>
    </div>
</body>
</html>
