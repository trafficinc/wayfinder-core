<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg: #f4f7f5;
            --surface: rgba(255, 255, 255, 0.82);
            --surface-strong: #ffffff;
            --ink: #193126;
            --muted: #62756d;
            --accent: #2f7d5c;
            --accent-deep: #1f5f45;
            --line: rgba(27, 58, 46, 0.12);
            --line-strong: rgba(27, 58, 46, 0.18);
            --danger: #9b2c2c;
            --shadow: 0 28px 70px rgba(20, 41, 34, 0.10);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(47, 125, 92, 0.12), transparent 26%),
                radial-gradient(circle at top right, rgba(27, 58, 46, 0.10), transparent 18%),
                linear-gradient(180deg, #f9fcfa 0%, var(--bg) 46%, #eef4f1 100%);
        }

        a {
            color: inherit;
        }

        .shell {
            max-width: 1160px;
            margin: 0 auto;
            padding: 28px 24px 88px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 34px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: linear-gradient(135deg, #2f7d5c 0%, #173d2f 100%);
            color: #ffffff;
            font-size: 1rem;
            font-weight: 700;
            box-shadow: 0 16px 34px rgba(47, 125, 92, 0.24);
        }

        .brand-copy {
            display: grid;
            gap: 2px;
        }

        .brand-name {
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand-meta {
            color: var(--muted);
            font-size: 0.92rem;
        }

        .topnav {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .topnav a,
        .topnav span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.64);
            color: var(--muted);
            text-decoration: none;
            font-size: 0.92rem;
        }

        .topnav a:hover {
            border-color: var(--line-strong);
            color: var(--ink);
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.28fr) minmax(300px, 0.72fr);
            gap: 26px;
            align-items: stretch;
            margin-bottom: 28px;
        }

        .panel {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 28px;
            background: var(--surface);
            backdrop-filter: blur(16px);
            box-shadow: var(--shadow);
        }

        .hero-panel {
            padding: 42px 42px 40px;
        }

        .hero-panel::before {
            content: "";
            position: absolute;
            inset: auto -8% 58% auto;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(47, 125, 92, 0.18) 0%, transparent 72%);
            pointer-events: none;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.72);
            color: var(--accent);
            font-size: 0.9rem;
            font-weight: 700;
        }

        h1 {
            margin: 0 0 16px;
            max-width: 11ch;
            font-size: clamp(3.15rem, 8vw, 5.7rem);
            line-height: 0.92;
            letter-spacing: -0.04em;
        }

        p {
            margin: 0 0 16px;
            font-size: 1.04rem;
            line-height: 1.7;
        }

        .lede {
            max-width: 40rem;
            color: var(--muted);
            font-size: 1.08rem;
        }

        .hero-proof {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 30px;
        }

        .proof {
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.76);
        }

        .proof strong {
            display: block;
            margin-bottom: 4px;
            font-size: 1.02rem;
        }

        .proof span {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 30px;
        }

        .button,
        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 14px;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
            transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease;
        }

        .button:hover,
        button:hover {
            transform: translateY(-1px);
        }

        .button-primary,
        button {
            border: 0;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-deep) 100%);
            color: #ffffff;
            box-shadow: 0 16px 28px rgba(47, 125, 92, 0.24);
        }

        .button-secondary {
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.74);
            color: var(--ink);
        }

        .status-panel {
            padding: 28px;
            display: grid;
            gap: 18px;
        }

        .status-head {
            display: grid;
            gap: 8px;
        }

        .status-head h2,
        .feature-card h2,
        .demo-card h2 {
            margin: 0;
            font-size: 1.36rem;
        }

        .status-head p,
        .feature-card p,
        .demo-card p {
            color: var(--muted);
            margin: 0;
        }

        .status-list {
            display: grid;
            gap: 12px;
        }

        .status-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 15px 16px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.76);
        }

        .status-item dt {
            margin: 0 0 4px;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .status-item dd {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .status-badge {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 84px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #edf9f3;
            color: #16543b;
            font-size: 0.86rem;
            font-weight: 700;
        }

        .section-label {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .feature-card {
            padding: 24px;
        }

        .feature-card strong {
            display: inline-block;
            margin-bottom: 12px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #f2f8f5;
            color: var(--accent);
            font-size: 0.8rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .lower-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) minmax(280px, 0.92fr);
            gap: 22px;
        }

        .demo-card,
        .info-card {
            padding: 28px;
        }

        .demo-card form {
            display: grid;
            gap: 12px;
            margin-top: 22px;
        }

        label {
            font-weight: 700;
            color: var(--accent);
        }

        input,
        textarea {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.94);
            color: var(--ink);
            font: inherit;
        }

        textarea {
            min-height: 130px;
            resize: vertical;
        }

        .flash {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #b7dfc9;
            background: #edf9f3;
            color: #16543b;
        }

        .errors {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #e0bcbc;
            background: #fff0f0;
            color: var(--danger);
        }

        .route-list {
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }

        .route-item {
            display: grid;
            gap: 3px;
            padding: 14px 15px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.72);
        }

        .route-item strong {
            font-size: 0.95rem;
        }

        .route-item span {
            color: var(--muted);
            font-size: 0.93rem;
        }

        .footer-note {
            margin-top: 16px;
            color: var(--muted);
            font-size: 0.94rem;
        }

        code {
            font-family: "SFMono-Regular", Menlo, Consolas, monospace;
            font-size: 0.94em;
        }

        @media (max-width: 980px) {
            .hero,
            .lower-grid,
            .feature-grid {
                grid-template-columns: 1fr;
            }

            .hero-proof {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .shell {
                padding: 20px 18px 64px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .hero-panel,
            .status-panel,
            .feature-card,
            .demo-card,
            .info-card {
                padding: 22px;
            }

            h1 {
                font-size: clamp(2.5rem, 16vw, 4rem);
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="/">
            <span class="brand-mark">W</span>
            <span class="brand-copy">
                <span class="brand-name"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="brand-meta">PHP framework starter</span>
            </span>
        </a>
        <nav class="topnav">
            <span>v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></span>
            <a href="/health">Health</a>
            <a href="https://github.com/trafficinc/wayfinder-app">GitHub</a>
        </nav>
    </header>

    <section class="hero">
        <article class="panel hero-panel">
            <div class="eyebrow">Wayfinder is installed</div>
            <h1>Build in public code, not hidden framework layers.</h1>
            <p class="lede">Wayfinder keeps the application surface explicit so developers and AI tools can reason about routing, requests, views, validation, and data access without digging through heavy framework indirection.</p>
            <div class="cta-row">
                <a class="button button-primary" href="/health">Run Health Check</a>
                <a class="button button-secondary" href="#demo">See Request Demo</a>
            </div>
            <div class="hero-proof">
                <div class="proof">
                    <strong>Explicit HTTP</strong>
                    <span>Requests, middleware, responses, and validation stay visible.</span>
                </div>
                <div class="proof">
                    <strong>Builder-first data</strong>
                    <span>Use migrations and query builders without a heavy ORM surface.</span>
                </div>
                <div class="proof">
                    <strong>App-owned starter</strong>
                    <span>The first screen is disposable and easy to restyle.</span>
                </div>
            </div>
        </article>

        <aside class="panel status-panel">
            <div class="status-head">
                <h2>Install Status</h2>
                <p>This panel is here for one reason: to show the framework booted correctly before you replace the starter.</p>
            </div>
            <dl class="status-list">
                <div class="status-item">
                    <div>
                        <dt>Framework</dt>
                        <dd>Wayfinder v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <span class="status-badge">Ready</span>
                </div>
                <div class="status-item">
                    <div>
                        <dt>Request Path</dt>
                        <dd><?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <span class="status-badge"><?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="status-item">
                    <div>
                        <dt>Session + CSRF</dt>
                        <dd>Loaded through the web middleware group</dd>
                    </div>
                    <span class="status-badge">Active</span>
                </div>
            </dl>
        </aside>
    </section>

    <section>
        <p class="section-label">Why Wayfinder</p>
        <div class="feature-grid">
            <article class="panel feature-card">
                <strong>HTTP</strong>
                <h2>One readable request lifecycle</h2>
                <p>Kernel, router, middleware, form validation, CSRF, and responses run through one understandable path.</p>
            </article>
            <article class="panel feature-card">
                <strong>Data</strong>
                <h2>Builder-first database access</h2>
                <p>Use <code>DB::table(...)</code>, migrations, rollback commands, and database-backed validation without committing the app to ORM magic.</p>
            </article>
            <article class="panel feature-card">
                <strong>Modules</strong>
                <h2>Feature packages stay app-owned</h2>
                <p>Install packaged modules into <code>Modules/</code> while keeping the application bootstrap and domain flow visible.</p>
            </article>
            <article class="panel feature-card">
                <strong>AI</strong>
                <h2>Smaller code surface, clearer reasoning</h2>
                <p>Wayfinder is designed so humans and AI tools can trace behavior quickly instead of sifting through hidden framework internals.</p>
            </article>
        </div>
    </section>

    <section class="lower-grid">
        <article id="demo" class="panel demo-card">
            <p class="section-label">Request Demo</p>
            <h2>Prove the lifecycle is live</h2>
            <p>This form is intentionally small. It exercises CSRF, validation, flash messages, old input, and redirect-back behavior from the default starter.</p>
            <?php if (is_string($flashMessage) && $flashMessage !== ''): ?>
                <div class="flash"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if (is_string($form->error('message', 'contact'))): ?>
                <div class="errors"><?= htmlspecialchars($form->error('message', 'contact') ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" action="/contact">
                <?= $form->csrfField() ?>
                <input type="hidden" name="_redirect" value="/">
                <label for="message">Message</label>
                <textarea id="message" name="message" placeholder="Submit a short message to verify the request lifecycle."><?= htmlspecialchars((string) $form->old('message', '', 'contact'), ENT_QUOTES, 'UTF-8') ?></textarea>
                <button type="submit">Submit Demo Request</button>
            </form>
        </article>

        <aside class="panel info-card">
            <p class="section-label">Starter Surface</p>
            <h2>Replace this page quickly</h2>
            <p>The default starter stays intentionally small. Keep the framework proving itself on first load, then swap the page out for your actual product.</p>
            <div class="route-list">
                <div class="route-item">
                    <strong><code>/</code></strong>
                    <span>Framework landing page</span>
                </div>
                <div class="route-item">
                    <strong><code>POST /contact</code></strong>
                    <span>CSRF + validation + redirect-back demo</span>
                </div>
                <div class="route-item">
                    <strong><code>/health</code></strong>
                    <span>Request lifecycle check</span>
                </div>
                <div class="route-item">
                    <strong><code>tests/</code></strong>
                    <span>PHPUnit bootstrap included from the start</span>
                </div>
            </div>
            <p class="footer-note">Keep the first screen inline and app-owned. That is the point of the starter.</p>
        </aside>
    </section>
</div>
</body>
</html>
