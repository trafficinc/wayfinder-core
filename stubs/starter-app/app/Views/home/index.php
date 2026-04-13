<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg: #f4f7f5;
            --card: #ffffff;
            --ink: #183028;
            --muted: #5a6f67;
            --accent: #2f7d5c;
            --line: #d6e4dc;
            --danger: #9b2c2c;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top right, rgba(47, 125, 92, 0.10), transparent 25%),
                linear-gradient(180deg, #f8fbf9 0%, var(--bg) 100%);
            color: var(--ink);
        }

        main {
            max-width: 1080px;
            margin: 0 auto;
            padding: 48px 24px 80px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 18px 50px rgba(24, 48, 40, 0.08);
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.8fr);
            gap: 24px;
            align-items: stretch;
            margin-bottom: 24px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #f7fbf8;
            color: var(--accent);
            font-size: .92rem;
            font-weight: 700;
        }

        h1 {
            margin: 0 0 14px;
            font-size: clamp(2.8rem, 8vw, 5.2rem);
            line-height: .95;
        }

        p {
            font-size: 1.05rem;
            line-height: 1.65;
            margin: 0 0 16px;
        }

        .subtitle {
            color: var(--muted);
            max-width: 46rem;
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .button,
        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            border-radius: 12px;
            font: inherit;
            cursor: pointer;
            text-decoration: none;
        }

        .button-primary,
        button {
            border: 0;
            background: var(--accent);
            color: #fff;
        }

        .button-secondary {
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
        }

        .stats {
            display: grid;
            gap: 16px;
        }

        .stat {
            padding: 20px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #f7fbf8;
        }

        .stat-label {
            color: var(--muted);
            font-size: .92rem;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .feature-card h2,
        .runtime-card h2,
        .demo-card h2 {
            margin: 0 0 12px;
            font-size: 1.35rem;
        }

        .feature-card p,
        .runtime-card p,
        .demo-card p {
            color: var(--muted);
            margin-bottom: 0;
        }

        .runtime-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 12px 16px;
            margin: 20px 0 0;
        }

        dt {
            font-weight: 700;
            color: var(--accent);
        }

        dd {
            margin: 0;
        }

        form {
            margin-top: 22px;
            display: grid;
            gap: 12px;
        }

        label {
            font-weight: 700;
            color: var(--accent);
        }

        input,
        textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            font: inherit;
            background: #fff;
            color: var(--ink);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .flash {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #edf9f3;
            border: 1px solid #b7dfc9;
            color: #16543b;
        }

        .errors {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fff0f0;
            border: 1px solid #e0bcbc;
            color: var(--danger);
        }

        .inline-links {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 18px;
        }

        .inline-links a {
            color: var(--accent);
            text-decoration: none;
        }

        .inline-links a:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<main>
    <section class="hero">
        <div class="card">
            <div class="eyebrow">Wayfinder v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></div>
            <h1>Build PHP apps with explicit structure, not framework magic.</h1>
            <p class="subtitle">This is the default Wayfinder landing page. It proves the front controller, kernel, router, view layer, session middleware, and CSRF protection are installed and working.</p>
            <div class="cta-row">
                <a class="button button-secondary" href="/health">Health Check</a>
                <a class="button button-primary" href="/health">Run Health Check</a>
            </div>
            <div class="inline-links">
                <a href="/">Home</a>
                <a href="/health">Health</a>
            </div>
        </div>

        <aside class="card">
            <h2 style="margin:0 0 16px;">Live Runtime</h2>
            <div class="stats">
                <div class="stat">
                    <div class="stat-label">HTTP Method</div>
                    <div class="stat-value"><?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Current Path</div>
                    <div class="stat-value" style="font-size:1.35rem;word-break:break-word;"><?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">CSRF + Session</div>
                    <div class="stat-value" style="font-size:1.35rem;">Active</div>
                </div>
            </div>
        </aside>
    </section>

    <section class="grid">
        <article class="card feature-card">
            <h2>HTTP Core</h2>
            <p>Routes, middleware groups, request objects, validation redirects, CSRF protection, sessions, cookies, and auth all run through the same explicit kernel lifecycle.</p>
        </article>
        <article class="card feature-card">
            <h2>Builder-First Data</h2>
            <p>Use `DB::table(...)` style queries, migrations, rollback and refresh commands, and database-backed validation rules without committing the framework to a heavy ORM.</p>
        </article>
        <article class="card feature-card">
            <h2>App-Owned Starter</h2>
            <p>This page keeps its CSS inline on purpose, so the first screen is easy to replace or restyle without hunting through shared layout assets.</p>
        </article>
    </section>

    <section class="grid" style="grid-template-columns:1.2fr .8fr;">
        <article class="card demo-card">
            <h2>Interactive Demo</h2>
            <p>This form stays on the landing page on purpose. It exercises CSRF protection, validation, flash data, old input, and redirect-back behavior in one place.</p>
            <?php if (is_string($flashMessage) && $flashMessage !== ''): ?>
                <div class="flash"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if (is_string($form->error('message', 'contact'))): ?>
                <div class="errors"><?= htmlspecialchars($form->error('message', 'contact') ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" action="/contact">
                <?= $form->csrfField() ?>
                <input type="hidden" name="_redirect" value="/">
                <label for="message">CSRF-Protected Demo Form</label>
                <textarea id="message" name="message" placeholder="Post a short message to prove the request lifecycle is live."><?= htmlspecialchars((string) $form->old('message', '', 'contact'), ENT_QUOTES, 'UTF-8') ?></textarea>
                <button type="submit">Submit</button>
            </form>
        </article>

        <aside class="card runtime-card">
            <h2>Starter Routes</h2>
            <p>The default skeleton keeps the first-run app intentionally small and disposable. Domain features belong in the application, not in the framework starter.</p>
            <dl class="runtime-grid">
                <dt>Home</dt>
                <dd>`/` landing page</dd>
                <dt>Contact</dt>
                <dd>`POST /contact` CSRF + validation demo</dd>
                <dt>Health</dt>
                <dd>`/health` request lifecycle check</dd>
                <dt>Tests</dt>
                <dd>PHPUnit bootstrap included</dd>
            </dl>
        </aside>
    </section>
</main>
</body>
</html>
