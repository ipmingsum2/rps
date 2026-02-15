<?php
declare(strict_types=1);
session_start();

$clientId = getenv('DISCORD_CLIENT_ID') ?: 'YOUR_DISCORD_CLIENT_ID';
$clientSecret = getenv('DISCORD_CLIENT_SECRET') ?: 'YOUR_DISCORD_CLIENT_SECRET';
$socketEndpoint = getenv('SOCKET_IO_ENDPOINT') ?: '';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptName = $_SERVER['SCRIPT_NAME'];
$redirectUri = $scheme . '://' . $host . $scriptName;

if (!isset($_SESSION['flash'])) {
    $_SESSION['flash'] = [];
}

function setFlash(string $key, string $message): void {
    $_SESSION['flash'][$key] = $message;
}

function getFlash(string $key): ?string {
    if (!empty($_SESSION['flash'][$key])) {
        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $message;
    }
    return null;
}

function redirectHome(string $redirectUri): void {
    header('Location: ' . $redirectUri);
    exit;
}

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'login') {
        $state = bin2hex(random_bytes(16));
        $_SESSION['discord_oauth_state'] = $state;
        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'identify',
            'state' => $state,
            'prompt' => 'consent'
        ], '', '&', PHP_QUERY_RFC3986);
        header('Location: https://discord.com/oauth2/authorize?' . $params);
        exit;
    }

    if ($action === 'callback') {
        if (empty($_GET['code']) || empty($_GET['state'])) {
            setFlash('error', 'Missing OAuth parameters.');
            redirectHome($redirectUri);
        }
        if (empty($_SESSION['discord_oauth_state']) || $_GET['state'] !== $_SESSION['discord_oauth_state']) {
            setFlash('error', 'Invalid OAuth state. Please try again.');
            redirectHome($redirectUri);
        }
        unset($_SESSION['discord_oauth_state']);

        $code = $_GET['code'];
        $tokenPayload = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init('https://discord.com/api/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $tokenPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        $tokenResponse = curl_exec($ch);
        $tokenError = curl_error($ch);
        curl_close($ch);

        if ($tokenResponse === false) {
            setFlash('error', 'Token exchange failed: ' . $tokenError);
            redirectHome($redirectUri);
        }

        $tokenData = json_decode($tokenResponse, true);
        if (empty($tokenData['access_token'])) {
            setFlash('error', 'Token exchange returned no access token.');
            redirectHome($redirectUri);
        }

        $accessToken = $tokenData['access_token'];
        $ch = curl_init('https://discord.com/api/users/@me');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $userResponse = curl_exec($ch);
        $userError = curl_error($ch);
        curl_close($ch);

        if ($userResponse === false) {
            setFlash('error', 'Failed to fetch Discord profile: ' . $userError);
            redirectHome($redirectUri);
        }

        $userData = json_decode($userResponse, true);
        if (empty($userData['id'])) {
            setFlash('error', 'Discord profile payload missing ID.');
            redirectHome($redirectUri);
        }

        $displayName = $userData['global_name'] ?? $userData['username'] ?? 'Player';
        $_SESSION['discord_user'] = [
            'id' => $userData['id'],
            'username' => $userData['username'] ?? 'player',
            'discriminator' => $userData['discriminator'] ?? '0000',
            'display_name' => $displayName
        ];
        setFlash('success', 'Discord login successful. Multiplayer unlocked!');
        redirectHome($redirectUri);
    }

    if ($action === 'logout') {
        unset($_SESSION['discord_user']);
        setFlash('success', 'Logged out of Discord.');
        redirectHome($redirectUri);
    }
}

$pageTitle = 'Rock Paper Scissors Party+';
$tagline = 'Face the AI or host a live Socket.IO party with dramatic reveals.';
$discordUser = $_SESSION['discord_user'] ?? null;
$loggedIn = !empty($discordUser);
$defaultName = $loggedIn ? ($discordUser['display_name'] ?? $discordUser['username'] ?? 'Player') : 'Player';
$flashError = getFlash('error');
$flashSuccess = getFlash('success');
?>
<!DOCTYPE html>
<html lang="en" data-auth="<?= $loggedIn ? 'true' : 'false'; ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    :root { color-scheme: light dark; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, sans-serif; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      padding: 1.5rem;
      background: radial-gradient(circle at top, #0f172a 0%, #020617 55%, #000 100%);
      color: #f9fafb;
      display: flex;
      justify-content: center;
    }
    .page { width: 100%; max-width: 1200px; }
    header { text-align: center; margin-bottom: 1rem; }
    header h1 { margin: 0; font-size: clamp(2rem, 4vw, 3rem); letter-spacing: 0.05em; text-transform: uppercase; }
    header p { margin-top: 0.35rem; color: #cbd5f5; }
    .auth-banner {
      display: flex;
      justify-content: flex-end;
      gap: 0.6rem;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 1.5rem;
    }
    .auth-banner span { color: #cbd5f5; font-size: 0.95rem; }
    .auth-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0.5rem 1.25rem;
      border-radius: 999px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      background: linear-gradient(135deg, #a855f7, #7c3aed);
      color: #fdf4ff;
      text-decoration: none;
      border: none;
      cursor: pointer;
    }
    .auth-btn.secondary { background: linear-gradient(135deg, #f87171, #ef4444); color: #fff; }
    .flash {
      margin-bottom: 1rem;
      padding: 0.75rem 1rem;
      border-radius: 0.85rem;
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    .flash.error { background: rgba(248, 113, 113, 0.2); border: 1px solid rgba(248, 113, 113, 0.5); color: #fecaca; }
    .flash.success { background: rgba(34, 197, 94, 0.2); border: 1px solid rgba(34, 197, 94, 0.5); color: #bbf7d0; }
    .card {
      background: rgba(15, 23, 42, 0.88);
      border: 1px solid rgba(148, 163, 184, 0.2);
      border-radius: 1rem;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      backdrop-filter: blur(10px);
      box-shadow: 0 25px 60px rgba(2, 6, 23, 0.6);
    }
    .card h2, .card h3 { margin-top: 0; }
    .mode-buttons {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    button {
      font: inherit;
      cursor: pointer;
      border: none;
      border-radius: 0.85rem;
      padding: 0.9rem 1.35rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #0b1120;
      background: linear-gradient(135deg, #38bdf8, #3b82f6);
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    button.secondary { background: linear-gradient(135deg, #f97316, #fbbf24); color: #1f1f1f; }
    button:disabled { opacity: 0.4; cursor: not-allowed; transform: none; box-shadow: none; }
    button:not(:disabled):hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(59, 130, 246, 0.45); }
    .choices { display: flex; flex-wrap: wrap; gap: 0.75rem; margin: 1.25rem 0; }
    .choices button {
      flex: 1 1 120px;
      background: linear-gradient(135deg, rgba(59, 130, 246, 0.85), rgba(14, 165, 233, 0.85));
      color: #e2e8f0;
      border: 1px solid rgba(148, 163, 184, 0.2);
    }
    .choices button.selected { box-shadow: 0 0 20px rgba(250, 204, 21, 0.5); border-color: #facc15; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; }
    .stats-grid.intense .stat-box {
      background: linear-gradient(145deg, rgba(59, 7, 100, 0.6), rgba(30, 64, 175, 0.4));
      border: 1px solid rgba(96, 165, 250, 0.3);
    }
    .stat-box { border-radius: 0.85rem; padding: 1rem; text-align: center; background: rgba(148, 163, 184, 0.12); }
    .stat-box span { display: block; font-size: 2rem; font-weight: 700; margin-top: 0.35rem; }
    .duel-display { display: flex; flex-wrap: wrap; gap: 1rem; align-items: stretch; }
    .fighter-card {
      flex: 1 1 220px;
      padding: 1rem;
      border-radius: 1rem;
      background: linear-gradient(160deg, rgba(8, 47, 73, 0.85), rgba(30, 58, 138, 0.75));
      border: 1px solid rgba(59, 130, 246, 0.2);
      position: relative;
      overflow: hidden;
    }
    .fighter-card.rival { background: linear-gradient(160deg, rgba(76, 5, 25, 0.85), rgba(153, 27, 27, 0.7)); border-color: rgba(248, 113, 113, 0.35); }
    .fighter-card .label { margin: 0; text-transform: uppercase; letter-spacing: 0.1em; font-size: 0.85rem; color: rgba(226, 232, 240, 0.8); }
    .choice-orb {
      width: 120px;
      height: 120px;
      margin: 0.75rem auto;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 3rem;
      background: radial-gradient(circle, rgba(15, 23, 42, 0.8), rgba(15, 23, 42, 0.3));
      border: 2px solid rgba(147, 197, 253, 0.5);
      box-shadow: 0 0 25px rgba(59, 130, 246, 0.35);
      transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
    }
    .choice-orb[data-outcome="win"] { border-color: #4ade80; box-shadow: 0 0 30px rgba(74, 222, 128, 0.6); transform: scale(1.05); }
    .choice-orb[data-outcome="loss"] { border-color: #f87171; box-shadow: 0 0 20px rgba(248, 113, 113, 0.3); opacity: 0.8; }
    .choice-orb[data-outcome="tie"] { border-color: #facc15; box-shadow: 0 0 25px rgba(250, 204, 21, 0.4); }
    .choice-orb[data-outcome="thinking"], .choice-orb.cycling { animation: orbPulse 0.9s ease-in-out infinite; }
    @keyframes orbPulse {
      0% { box-shadow: 0 0 12px rgba(148, 163, 184, 0.4); }
      50% { box-shadow: 0 0 24px rgba(148, 163, 184, 0.9); }
      100% { box-shadow: 0 0 12px rgba(148, 163, 184, 0.4); }
    }
    .choice-label { text-align: center; margin: 0; font-weight: 600; }
    .versus-burst {
      width: 90px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.3rem;
      text-align: center;
      background: radial-gradient(circle, rgba(248, 250, 252, 0.1), rgba(15, 23, 42, 0.3));
      border-radius: 1rem;
      border: 1px dashed rgba(148, 163, 184, 0.4);
    }
    .versus-burst span { font-size: 1.5rem; font-weight: 800; letter-spacing: 0.1em; }
    .streak-chip { font-size: 0.9rem; padding: 0.25rem 0.5rem; border-radius: 999px; background: rgba(34, 197, 94, 0.2); color: #86efac; }
    .result-banner,
    .online-result-banner {
      margin-top: 1rem;
      text-align: center;
      font-size: 1.2rem;
      font-weight: 700;
      padding: 0.9rem 1.1rem;
      border-radius: 999px;
      background: linear-gradient(135deg, rgba(59, 130, 246, 0.3), rgba(14, 165, 233, 0.2));
      border: 1px solid rgba(59, 130, 246, 0.3);
      transition: background 0.25s ease, border-color 0.25s ease, color 0.25s ease;
    }
    .result-banner[data-outcome="win"], .online-result-banner[data-state="win"] {
      background: linear-gradient(135deg, rgba(34, 197, 94, 0.3), rgba(22, 163, 74, 0.3));
      border-color: rgba(34, 197, 94, 0.6);
      color: #bbf7d0;
    }
    .result-banner[data-outcome="loss"], .online-result-banner[data-state="loss"] {
      background: linear-gradient(135deg, rgba(248, 113, 113, 0.3), rgba(185, 28, 28, 0.5));
      border-color: rgba(248, 113, 113, 0.5);
      color: #fee2e2;
    }
    .result-banner[data-outcome="tie"], .online-result-banner[data-state="tie"] {
      background: linear-gradient(135deg, rgba(250, 204, 21, 0.3), rgba(252, 211, 77, 0.3));
      border-color: rgba(250, 204, 21, 0.5);
      color: #fef9c3;
    }
    .online-result-banner[data-state="ready"] {
      background: linear-gradient(135deg, rgba(14, 165, 233, 0.25), rgba(59, 130, 246, 0.35));
      color: #bfdbfe;
    }
    .online-result-banner[data-state="idle"] { background: rgba(148, 163, 184, 0.15); color: #cbd5f5; }
    .online-result-banner[data-state="waiting"] {
      background: linear-gradient(135deg, rgba(250, 204, 21, 0.25), rgba(248, 113, 113, 0.25));
      color: #fde68a;
    }
    .online-result-banner[data-state="countdown"] {
      background: linear-gradient(135deg, rgba(34, 211, 238, 0.25), rgba(14, 165, 233, 0.25));
      color: #a5f3fc;
    }
    .online-result-banner[data-state="error"] {
      background: rgba(239, 68, 68, 0.25);
      color: #fecaca;
      border-color: rgba(248, 113, 113, 0.4);
    }
    .battle-feed {
      margin-top: 1.5rem;
      border-radius: 1rem;
      border: 1px solid rgba(148, 163, 184, 0.2);
      padding: 1rem;
      background: rgba(15, 23, 42, 0.7);
    }
    .battle-feed-list {
      list-style: none;
      margin: 0.75rem 0 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
    }
    .feed-item {
      padding: 0.65rem 0.85rem;
      border-radius: 0.85rem;
      background: rgba(15, 23, 42, 0.85);
      border: 1px solid rgba(148, 163, 184, 0.2);
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
      font-size: 0.95rem;
    }
    .feed-item.win { border-color: rgba(34, 197, 94, 0.4); color: #bbf7d0; }
    .feed-item.loss { border-color: rgba(248, 113, 113, 0.4); color: #fecaca; }
    .feed-item.tie { border-color: rgba(250, 204, 21, 0.4); color: #fef9c3; }
    .feed-title { font-weight: 600; }
    .online-grid { display: grid; gap: 1.5rem; }
    @media (min-width: 900px) { .online-grid { grid-template-columns: 1fr 1fr; } }
    .input-group { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    input {
      flex: 1;
      min-width: 160px;
      border-radius: 0.85rem;
      border: 1px solid rgba(148, 163, 184, 0.4);
      padding: 0.75rem 1rem;
      font: inherit;
      background: rgba(15, 23, 42, 0.65);
      color: #f8fafc;
    }
    input::placeholder { color: #94a3b8; }
    .info-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 999px;
      background: rgba(148, 163, 184, 0.15);
      font-size: 0.95rem;
    }
    .players-list { list-style: none; padding: 0; margin: 0.75rem 0 0; display: flex; flex-direction: column; gap: 0.75rem; }
    .players-list li {
      padding: 0.85rem 1rem;
      border-radius: 0.85rem;
      background: rgba(15, 23, 42, 0.65);
      border: 1px solid rgba(148, 163, 184, 0.2);
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    .player-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
    .player-name { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; }
    .player-status-block {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.5rem 0.75rem;
      border-radius: 0.85rem;
      background: rgba(30, 41, 59, 0.7);
      border: 1px solid rgba(148, 163, 184, 0.2);
    }
    .player-status-block.cycling { border-color: rgba(14, 165, 233, 0.5); }
    .player-status-block.locked { border-color: rgba(250, 204, 21, 0.4); }
    .player-choice-icon { font-size: 1.7rem; }
    .player-choice-icon.cycling { animation: flicker 0.8s linear infinite; }
    @keyframes flicker {
      0% { transform: translateY(0); opacity: 1; }
      50% { transform: translateY(-2px); opacity: 0.7; }
      100% { transform: translateY(0); opacity: 1; }
    }
    .player-status-text { font-size: 0.9rem; color: #cbd5f5; }
    .mini-badge { font-size: 0.7rem; padding: 0.15rem 0.5rem; border-radius: 999px; background: rgba(59, 130, 246, 0.2); color: #bfdbfe; text-transform: uppercase; letter-spacing: 0.05em; }
    .status-text { margin-top: 0.75rem; font-size: 0.95rem; color: #fbbf24; min-height: 1.5rem; }
    .chat { display: flex; flex-direction: column; height: 100%; min-height: 340px; border: 1px solid rgba(148, 163, 184, 0.2); border-radius: 1rem; overflow: hidden; }
    .chat-log { flex: 1; overflow-y: auto; padding: 1rem; background: rgba(15, 23, 42, 0.7); }
    .chat-message { margin-bottom: 0.85rem; line-height: 1.4; }
    .chat-message strong { color: #38bdf8; }
    .chat-form { display: flex; gap: 0.5rem; padding: 0.75rem; border-top: 1px solid rgba(148, 163, 184, 0.2); background: rgba(15, 23, 42, 0.8); }
    .chat-form input { flex: 1; }
    .log { font-size: 0.95rem; color: #cbd5f5; min-height: 1.3rem; }
    .last-round { margin-top: 1.25rem; border-top: 1px solid rgba(148, 163, 184, 0.2); padding-top: 1rem; }
    .last-round-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; }
    .round-card {
      border-radius: 1rem;
      padding: 0.9rem;
      background: rgba(15, 23, 42, 0.7);
      border: 1px solid rgba(148, 163, 184, 0.3);
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    .round-card.winner { border-color: rgba(34, 197, 94, 0.6); box-shadow: 0 0 25px rgba(34, 197, 94, 0.4); }
    .round-card.loser { opacity: 0.8; }
    .round-card.tie { border-color: rgba(250, 204, 21, 0.5); }
    .round-name { margin: 0; font-weight: 600; font-size: 0.95rem; }
    .round-choice { font-size: 2.2rem; margin: 0.4rem 0; }
    .round-label { margin: 0; font-size: 0.9rem; color: #cbd5f5; }
    .round-note {
      grid-column: 1 / -1;
      text-align: center;
      padding: 0.75rem;
      border-radius: 0.85rem;
      background: rgba(148, 163, 184, 0.12);
      color: #e2e8f0;
      font-style: italic;
    }
    @media (max-width: 640px) {
      body { padding: 1rem; }
      .versus-burst { width: 100%; flex-direction: row; }
      .fighter-card { flex: 1 1 100%; }
      .choices button { flex: 1 1 calc(50% - 0.75rem); }
    }
    #online-mode { position: relative; overflow: hidden; }
    .auth-overlay {
      position: absolute;
      inset: 0;
      background: rgba(2, 6, 23, 0.88);
      backdrop-filter: blur(6px);
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 1.5rem;
      z-index: 10;
    }
    .auth-overlay > div { max-width: 360px; }
    .hidden { display: none !important; }
  </style>
</head>
<body data-auth="<?= $loggedIn ? 'true' : 'false'; ?>">
  <div class="page">
    <header>
      <h1><?= htmlspecialchars($pageTitle) ?></h1>
      <p><?= htmlspecialchars($tagline) ?></p>
    </header>

    <div class="auth-banner">
      <?php if ($loggedIn): ?>
        <span>Logged in as <strong><?= htmlspecialchars($defaultName) ?></strong></span>
        <a class="auth-btn secondary" href="?action=logout">Logout</a>
      <?php else: ?>
        <a class="auth-btn" href="?action=login">Login with Discord</a>
      <?php endif; ?>
    </div>

    <?php if ($flashError): ?>
      <div class="flash error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>
    <?php if ($flashSuccess): ?>
      <div class="flash success"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>

    <section class="card">
      <h2>Select Mode</h2>
      <div class="mode-buttons">
        <button id="single-mode-btn">Single Player vs AI</button>
        <button id="online-mode-btn" class="secondary">Online Party (Socket.IO)</button>
      </div>
    </section>

    <section id="single-player" class="card hidden">
      <h2>Single Player</h2>
      <p>Charge into the arena and try to break the AI’s defenses.</p>

      <div class="duel-display">
        <div class="fighter-card">
          <p class="label">You</p>
          <div id="player-choice-icon" class="choice-orb" data-outcome="idle">❔</div>
          <p id="player-choice-label" class="choice-label">Awaiting command…</p>
        </div>
        <div class="versus-burst">
          <span>VS</span>
          <p class="streak-chip" id="streak-count">Streak x0</p>
        </div>
        <div class="fighter-card rival">
          <p class="label">AI Nemesis</p>
          <div id="ai-choice-icon" class="choice-orb" data-outcome="idle">❔</div>
          <p id="ai-choice-label" class="choice-label">Ready to counter…</p>
        </div>
      </div>

      <div id="duel-banner" class="result-banner" data-outcome="idle">Make your move!</div>

      <div class="choices" id="single-choices">
        <button data-choice="rock">🪨 Rock</button>
        <button data-choice="paper">📄 Paper</button>
        <button data-choice="scissors">✂️ Scissors</button>
      </div>

      <div class="stats-grid intense">
        <div class="stat-box">Wins<span id="sp-wins">0</span></div>
        <div class="stat-box">Losses<span id="sp-losses">0</span></div>
        <div class="stat-box">Ties<span id="sp-ties">0</span></div>
      </div>

      <div class="battle-feed">
        <h3>Battle Feed</h3>
        <ul id="battle-feed-list" class="battle-feed-list">
          <li class="feed-item feed-placeholder">
            <span class="feed-title">Awaiting your first duel.</span>
            <span class="feed-detail">Strike first to set the tone.</span>
          </li>
        </ul>
      </div>
    </section>

    <section id="online-mode" class="card hidden" data-locked="<?= $loggedIn ? '0' : '1'; ?>">
      <h2>Online Party</h2>
      <p>Create a party to get a code or join an existing one. Chat unlocks once you’re inside.</p>

      <div class="online-grid">
        <div>
          <div class="card" style="background: rgba(15,23,42,0.6); border: none; margin-bottom: 1rem;">
            <h3>1. Identity</h3>
            <div class="input-group">
              <input id="online-name" type="text" placeholder="Display name" maxlength="20" value="<?= htmlspecialchars($defaultName) ?>" <?= $loggedIn ? '' : 'disabled'; ?>>
            </div>
          </div>

          <div class="card" style="background: rgba(15,23,42,0.6); border: none;">
            <h3>2. Party Controls</h3>
            <div class="input-group" style="margin-bottom: 0.75rem;">
              <button id="create-party" <?= $loggedIn ? '' : 'disabled'; ?>>Create Party</button>
            </div>
            <div class="input-group">
              <input id="join-code" type="text" placeholder="Enter party code" <?= $loggedIn ? '' : 'disabled'; ?>>
              <button id="join-party" class="secondary" <?= $loggedIn ? '' : 'disabled'; ?>>Join Party</button>
            </div>
            <p id="online-log" class="log"></p>
          </div>

          <div class="card" style="background: rgba(15,23,42,0.6); border: none;">
            <h3>3. Party Info</h3>
            <div class="info-badge">Party Code: <strong id="party-code">—</strong></div>
            <ul id="party-players" class="players-list"></ul>
            <p id="party-status" class="status-text"></p>

            <div class="choices" id="online-choices">
              <button data-choice="rock" disabled>🪨 Rock</button>
              <button data-choice="paper" disabled>📄 Paper</button>
              <button data-choice="scissors" disabled>✂️ Scissors</button>
            </div>

            <div id="online-result" class="online-result-banner" data-state="idle">
              <?= $loggedIn ? 'Choose or create a party to begin.' : 'Login with Discord to unlock multiplayer.' ?>
            </div>

            <div class="last-round">
              <h4>Last Round Spotlight</h4>
              <div id="last-round-grid" class="last-round-grid">
                <div class="round-note">No rounds played yet.</div>
              </div>
            </div>
          </div>
        </div>

        <div>
          <div class="card" style="background: rgba(15,23,42,0.6); border: none;">
            <h3>Party Chat</h3>
            <div class="chat">
              <div id="chat-log" class="chat-log"></div>
              <form id="chat-form" class="chat-form">
                <input id="chat-input" type="text" placeholder="Type a message..." <?= $loggedIn ? 'disabled' : 'disabled'; ?>>
                <button type="submit" <?= $loggedIn ? 'disabled' : 'disabled'; ?>>Send</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <?php if (!$loggedIn): ?>
        <div class="auth-overlay">
          <div>
            <h3>Discord Login Required</h3>
            <p>Multiplayer is restricted to verified Discord users. Sign in to unlock party codes, matchmaking, and chat.</p>
            <a class="auth-btn" href="?action=login">Login with Discord</a>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <script>
    window.__AUTH__ = <?= json_encode([
      'isLoggedIn' => $loggedIn,
      'displayName' => $defaultName
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    window.__SOCKET_ENDPOINT__ = <?= json_encode($socketEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  </script>

  <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"
          integrity="sha384-1i1Cdm42Hcps225y7sY9qsK0kGugHgdGXN53BJ38qRAjPR9U1FVLtZL1NVr7DiJp"
          crossorigin="anonymous"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const authState = window.__AUTH__ || { isLoggedIn: false, displayName: 'Player' };
      const socketEndpoint = window.__SOCKET_ENDPOINT__ || '';
      const beats = { rock: 'scissors', paper: 'rock', scissors: 'paper' };
      const choiceLabels = { rock: '🪨 Rock', paper: '📄 Paper', scissors: '✂️ Scissors' };
      const choiceIcons = { rock: '🪨', paper: '📄', scissors: '✂️' };
      const CHOICE_SEQUENCE = ['rock', 'paper', 'scissors'];
      const THINKING_INTERVAL = 380;
      const SINGLE_REVEAL_DELAY = 1100;

      const singleSection = document.getElementById('single-player');
      const onlineSection = document.getElementById('online-mode');
      const singleModeBtn = document.getElementById('single-mode-btn');
      const onlineModeBtn = document.getElementById('online-mode-btn');

      const singleChoices = document.getElementById('single-choices');
      const spWinsEl = document.getElementById('sp-wins');
      const spLossesEl = document.getElementById('sp-losses');
      const spTiesEl = document.getElementById('sp-ties');
      const playerChoiceIcon = document.getElementById('player-choice-icon');
      const playerChoiceLabel = document.getElementById('player-choice-label');
      const aiChoiceIcon = document.getElementById('ai-choice-icon');
      const aiChoiceLabel = document.getElementById('ai-choice-label');
      const duelBanner = document.getElementById('duel-banner');
      const streakEl = document.getElementById('streak-count');
      const battleFeedList = document.getElementById('battle-feed-list');

      const nameInput = document.getElementById('online-name');
      const createBtn = document.getElementById('create-party');
      const joinBtn = document.getElementById('join-party');
      const joinInput = document.getElementById('join-code');
      const onlineLog = document.getElementById('online-log');
      const partyCodeEl = document.getElementById('party-code');
      const partyPlayersEl = document.getElementById('party-players');
      const partyStatusEl = document.getElementById('party-status');
      const onlineChoices = document.getElementById('online-choices');
      const onlineResultEl = document.getElementById('online-result');
      const lastRoundGrid = document.getElementById('last-round-grid');
      const chatLog = document.getElementById('chat-log');
      const chatForm = document.getElementById('chat-form');
      const chatInput = document.getElementById('chat-input');
      const chatSubmit = chatForm ? chatForm.querySelector('button') : null;

      const singleStats = { wins: 0, losses: 0, ties: 0 };
      const previewTimers = new Map();
      const onlineChoiceButtons = onlineChoices ? [...onlineChoices.querySelectorAll('button')] : [];

      let winStreak = 0;
      let duelLocked = false;
      let aiPreviewTimer = null;
      let currentPartyCode = null;
      let socket = null;
      let resultFreeze = false;
      let resultFreezeTimeout = null;

      if (nameInput && authState.displayName) {
        nameInput.value = authState.displayName;
      }

      function formatChoice(choice) {
        return choiceLabels[choice] || choice;
      }

      function randomChoice() {
        return CHOICE_SEQUENCE[Math.floor(Math.random() * CHOICE_SEQUENCE.length)];
      }

      function setSectionVisibility(mode = 'single') {
        const showSingle = mode === 'single';
        if (singleSection) singleSection.classList.toggle('hidden', !showSingle);
        if (onlineSection) onlineSection.classList.toggle('hidden', showSingle);
      }

      function setLog(message = '') {
        if (onlineLog) onlineLog.textContent = message;
      }

      function setDuelBanner(outcome = 'idle', text = '') {
        if (!duelBanner) return;
        duelBanner.dataset.outcome = outcome;
        duelBanner.textContent = text;
      }

      function updateStreak(outcome) {
        if (!streakEl) return;
        if (outcome === 'win') {
          winStreak += 1;
        } else if (outcome === 'tie') {
          winStreak = Math.max(0, winStreak - 1);
        } else {
          winStreak = 0;
        }
        streakEl.textContent = winStreak >= 3 ? `🔥 Streak x${winStreak}` : `Streak x${winStreak}`;
      }

      function pushBattleFeed(outcome, headline, detail) {
        if (!battleFeedList) return;
        const placeholder = battleFeedList.querySelector('.feed-placeholder');
        if (placeholder) placeholder.remove();
        const li = document.createElement('li');
        li.className = `feed-item ${outcome}`;
        li.innerHTML = `<span class="feed-title">${headline}</span><span class="feed-detail">${detail}</span>`;
        battleFeedList.prepend(li);
        while (battleFeedList.children.length > 5) {
          battleFeedList.removeChild(battleFeedList.lastChild);
        }
      }

      function beginAiPreview() {
        stopAiPreview();
        if (!aiChoiceIcon) return;
        let index = 0;
        aiChoiceIcon.dataset.outcome = 'thinking';
        aiChoiceIcon.classList.add('cycling');
        aiChoiceIcon.textContent = choiceIcons[CHOICE_SEQUENCE[index]];
        aiPreviewTimer = setInterval(() => {
          index = (index + 1) % CHOICE_SEQUENCE.length;
          aiChoiceIcon.textContent = choiceIcons[CHOICE_SEQUENCE[index]];
        }, 200);
      }

      function stopAiPreview() {
        if (aiPreviewTimer) {
          clearInterval(aiPreviewTimer);
          aiPreviewTimer = null;
        }
        if (aiChoiceIcon) {
          aiChoiceIcon.classList.remove('cycling');
        }
      }

      function toggleChoiceButtons(enabled) {
        onlineChoiceButtons.forEach((button) => {
          button.disabled = !enabled;
        });
      }

      function highlightOnlineChoice(choice) {
        onlineChoiceButtons.forEach((button) => {
          const isSelected = !!choice && button.dataset.choice === choice;
          button.classList.toggle('selected', isSelected);
        });
      }

      function resetLastRound() {
        if (!lastRoundGrid) return;
        lastRoundGrid.innerHTML = '<div class="round-note">No rounds played yet.</div>';
      }

      function setOnlineResult(state = 'idle', text = '', freezeDuration = 0) {
        if (!onlineResultEl) return;
        onlineResultEl.dataset.state = state;
        onlineResultEl.textContent = text;
        if (resultFreezeTimeout) {
          clearTimeout(resultFreezeTimeout);
          resultFreezeTimeout = null;
        }
        if (freezeDuration > 0) {
          resultFreeze = true;
          resultFreezeTimeout = setTimeout(() => {
            resultFreeze = false;
          }, freezeDuration);
        } else {
          resultFreeze = false;
        }
      }

      function clearPreviewCycle(playerId) {
        const entry = previewTimers.get(playerId);
        if (entry) {
          clearInterval(entry.timer);
          if (entry.element) entry.element.classList.remove('cycling');
          previewTimers.delete(playerId);
        }
      }

      function clearAllPreviewCycles() {
        previewTimers.forEach((entry) => {
          clearInterval(entry.timer);
          if (entry.element) entry.element.classList.remove('cycling');
        });
        previewTimers.clear();
      }

      function startPreviewCycle(playerId, iconElement) {
        clearPreviewCycle(playerId);
        if (!iconElement) return;
        let index = Math.floor(Math.random() * CHOICE_SEQUENCE.length);
        iconElement.classList.add('cycling');
        iconElement.textContent = choiceIcons[CHOICE_SEQUENCE[index]];
        const timer = setInterval(() => {
          index = (index + 1) % CHOICE_SEQUENCE.length;
          iconElement.textContent = choiceIcons[CHOICE_SEQUENCE[index]];
        }, THINKING_INTERVAL);
        previewTimers.set(playerId, { timer, element: iconElement });
      }

      function showRoundChoices(moves, winnerId, tie) {
        if (!lastRoundGrid) return;
        lastRoundGrid.innerHTML = '';
        moves.forEach((move) => {
          const card = document.createElement('div');
          card.className = 'round-card';
          if (tie) {
            card.classList.add('tie');
          } else if (move.id === winnerId) {
            card.classList.add('winner');
          } else {
            card.classList.add('loser');
          }
          card.innerHTML = `
            <p class="round-name">${move.name}${socket && move.id === socket.id ? ' (You)' : ''}</p>
            <div class="round-choice">${choiceIcons[move.choice] || '❔'}</div>
            <p class="round-label">${formatChoice(move.choice)}</p>
          `;
          lastRoundGrid.appendChild(card);
        });
        if (tie) {
          const note = document.createElement('div');
          note.className = 'round-note';
          note.textContent = 'Perfect stalemate! Rematch immediately.';
          lastRoundGrid.appendChild(note);
        }
      }

      function renderPartyPlayers(snapshot) {
        if (!partyPlayersEl) return;
        clearAllPreviewCycles();
        partyPlayersEl.innerHTML = '';

        if (!snapshot.players.length) {
          const li = document.createElement('li');
          li.textContent = 'No players connected yet.';
          partyPlayersEl.appendChild(li);
          toggleChoiceButtons(false);
          return;
        }

        snapshot.players.forEach((player) => {
          const li = document.createElement('li');

          const header = document.createElement('div');
          header.className = 'player-header';

          const nameWrap = document.createElement('div');
          nameWrap.className = 'player-name';
          nameWrap.textContent = player.name + (socket && player.id === socket.id ? ' (You)' : '');

          const badge = document.createElement('span');
          badge.className = 'mini-badge';
          badge.textContent = snapshot.hostId === player.id ? 'Host' : 'Guest';
          nameWrap.appendChild(badge);

          header.appendChild(nameWrap);
          li.appendChild(header);

          const statusBlock = document.createElement('div');
          statusBlock.className = 'player-status-block';

          const iconSpan = document.createElement('span');
          iconSpan.className = 'player-choice-icon';
          iconSpan.textContent = '❔';

          const statusText = document.createElement('span');
          statusText.className = 'player-status-text';

          if (player.choiceLocked) {
            statusBlock.classList.add('locked');
            iconSpan.textContent = choiceIcons[player.choice] || '🔒';
            statusText.textContent = `Locked: ${formatChoice(player.choice)}`;
          } else {
            statusBlock.classList.add('cycling');
            statusText.textContent = 'Cycling picks…';
            startPreviewCycle(player.id, iconSpan);
          }

          statusBlock.append(iconSpan, statusText);
          li.appendChild(statusBlock);
          partyPlayersEl.appendChild(li);
        });
      }

      function wireSocketEvents() {
        if (!socket) return;

        socket.on('partyJoined', ({ code }) => {
          currentPartyCode = code;
          if (partyCodeEl) partyCodeEl.textContent = code;
          if (chatInput) chatInput.disabled = false;
          if (chatSubmit) chatSubmit.disabled = false;
          highlightOnlineChoice(null);
          toggleChoiceButtons(false);
          resetLastRound();
          setOnlineResult('ready', 'Party synced! Lock in when you are.');
          setLog(`Joined party ${code}. Share the code with a friend!`);
        });

        socket.on('partyUpdate', (snapshot) => {
          if (!snapshot || snapshot.code !== currentPartyCode) return;
          renderPartyPlayers(snapshot);

          const ready = snapshot.players.length >= 2;
          const localPlayer = socket ? snapshot.players.find((p) => p.id === socket.id) : null;
          const everyoneLocked = ready && snapshot.players.every((p) => p.choiceLocked);

          if (partyStatusEl) {
            if (!ready) {
              partyStatusEl.textContent = `Waiting for challengers… Slots open: ${snapshot.slotsAvailable ?? '?'}`;
            } else if (everyoneLocked) {
              partyStatusEl.textContent = 'All picks locked. Awaiting reveal…';
            } else if (localPlayer && localPlayer.choiceLocked) {
              partyStatusEl.textContent = 'Hold tight—opponent still deciding.';
            } else {
              partyStatusEl.textContent = 'Gladiators ready! Choose your weapon.';
            }
          }

          const canAct = ready && localPlayer && !localPlayer.choiceLocked;
          toggleChoiceButtons(canAct);

          if (!resultFreeze) {
            if (!ready) {
              setOnlineResult('idle', 'Waiting for more challengers…');
            } else if (everyoneLocked) {
              setOnlineResult('countdown', 'All picks locked. Drum roll…');
            } else if (localPlayer && localPlayer.choiceLocked) {
              setOnlineResult('waiting', 'You are locked. Waiting for opponent…');
            } else {
              setOnlineResult('ready', 'Select rock, paper, or scissors!');
            }
          }
        });

        socket.on('roundResult', ({ moves, winnerId, tie }) => {
          if (!Array.isArray(moves) || !moves.length) return;
          const summary = moves.map((entry) => `${entry.name}: ${formatChoice(entry.choice)}`).join(' • ');
          const winner = moves.find((entry) => entry.id === winnerId);
          let state = 'loss';
          let message = '';
          if (tie) {
            state = 'tie';
            message = `Stalemate! ${summary}`;
          } else if (socket && winnerId === socket.id) {
            state = 'win';
            message = `You triumph! ${summary}`;
          } else {
            state = 'loss';
            message = `${winner ? winner.name : 'Opponent'} wins! ${summary}`;
          }
          setOnlineResult(state, message, 2600);
          showRoundChoices(moves, winnerId, tie);
          highlightOnlineChoice(null);
        });

        socket.on('chatMessage', (message) => {
          if (!message || !message.sender || !message.text || !chatLog) return;
          const container = document.createElement('div');
          container.className = 'chat-message';
          const time = new Date(message.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
          container.innerHTML = `<strong>${message.sender}</strong> <small>${time}</small><br>${message.text}`;
          chatLog.appendChild(container);
          chatLog.scrollTop = chatLog.scrollHeight;
        });

        socket.on('errorMessage', (text) => {
          setLog(text || 'Something went wrong.');
          setOnlineResult('error', text || 'Server error.');
        });

        socket.on('disconnect', () => {
          setLog('Disconnected from server. Online play is unavailable.');
          setOnlineResult('error', 'Disconnected from server.');
          toggleChoiceButtons(false);
        });
      }

      if (singleModeBtn) {
        singleModeBtn.addEventListener('click', () => setSectionVisibility('single'));
      }
      if (onlineModeBtn) {
        onlineModeBtn.addEventListener('click', () => setSectionVisibility('online'));
      }
      setSectionVisibility('single');
      setDuelBanner('idle', 'Make your move!');
      setOnlineResult('idle', authState.isLoggedIn ? 'Choose or create a party to begin.' : 'Login with Discord to unlock multiplayer.');
      resetLastRound();

      if (singleChoices) {
        singleChoices.addEventListener('click', (event) => {
          const choice = event.target.dataset.choice;
          if (!choice || duelLocked) return;
          duelLocked = true;
          const aiChoice = randomChoice();

          if (playerChoiceIcon) {
            playerChoiceIcon.textContent = choiceIcons[choice];
            playerChoiceIcon.dataset.outcome = 'locked';
          }
          if (playerChoiceLabel) {
            playerChoiceLabel.textContent = formatChoice(choice);
          }
          if (aiChoiceLabel) {
            aiChoiceLabel.textContent = 'Calculating counter…';
          }

          beginAiPreview();
          setDuelBanner('charging', 'Arena lights flare... hold steady!');

          setTimeout(() => {
            stopAiPreview();
            if (aiChoiceIcon) {
              aiChoiceIcon.textContent = choiceIcons[aiChoice];
            }
            if (aiChoiceLabel) {
              aiChoiceLabel.textContent = formatChoice(aiChoice);
            }

            let outcome = 'loss';
            if (choice === aiChoice) {
              outcome = 'tie';
            } else if (beats[choice] === aiChoice) {
              outcome = 'win';
            }

            const messageMap = {
              win: `${formatChoice(choice)} overwhelms ${formatChoice(aiChoice)}!`,
              loss: `${formatChoice(aiChoice)} crushes ${formatChoice(choice)}...`,
              tie: `${formatChoice(choice)} mirrors ${formatChoice(aiChoice)}. Deadlock!`
            };

            setDuelBanner(outcome, messageMap[outcome]);
            updateStreak(outcome);
            pushBattleFeed(outcome, messageMap[outcome], `You: ${formatChoice(choice)} • AI: ${formatChoice(aiChoice)}`);

            if (playerChoiceIcon) playerChoiceIcon.dataset.outcome = outcome;
            if (aiChoiceIcon) {
              aiChoiceIcon.dataset.outcome = outcome === 'win' ? 'loss' : outcome === 'loss' ? 'win' : 'tie';
            }

            if (outcome === 'win') singleStats.wins += 1;
            else if (outcome === 'loss') singleStats.losses += 1;
            else singleStats.ties += 1;

            if (spWinsEl) spWinsEl.textContent = singleStats.wins;
            if (spLossesEl) spLossesEl.textContent = singleStats.losses;
            if (spTiesEl) spTiesEl.textContent = singleStats.ties;

            duelLocked = false;
          }, SINGLE_REVEAL_DELAY);
        });
      }

      if (createBtn) {
        createBtn.addEventListener('click', () => {
          if (!authState.isLoggedIn) {
            setLog('Discord login required before creating a party.');
            return;
          }
          if (!socket) {
            setLog('Online mode unavailable. Is the server running?');
            setOnlineResult('error', 'Online mode unavailable. Start the server.');
            return;
          }
          const displayName = (nameInput && nameInput.value ? nameInput.value : authState.displayName).trim() || 'Player';
          socket.emit('createParty', { name: displayName });
        });
      }

      if (joinBtn) {
        joinBtn.addEventListener('click', () => {
          if (!authState.isLoggedIn) {
            setLog('Discord login required before joining a party.');
            return;
          }
          if (!socket) {
            setLog('Online mode unavailable. Is the server running?');
            setOnlineResult('error', 'Online mode unavailable. Start the server.');
            return;
          }
          const code = joinInput && joinInput.value ? joinInput.value.trim().toUpperCase() : '';
          if (!code) {
            setLog('Enter a party code to join.');
            return;
          }
          const displayName = (nameInput && nameInput.value ? nameInput.value : authState.displayName).trim() || 'Player';
          socket.emit('joinParty', { code, name: displayName });
        });
      }

      if (onlineChoices) {
        onlineChoices.addEventListener('click', (event) => {
          const choice = event.target.dataset.choice;
          if (!authState.isLoggedIn) {
            setLog('Discord login required before selecting a move.');
            return;
          }
          if (!choice || !currentPartyCode) return;
          if (!socket) {
            setLog('Online mode unavailable. Is the server running?');
            setOnlineResult('error', 'Online mode unavailable.');
            return;
          }
          socket.emit('makeMove', { code: currentPartyCode, choice });
          setLog(`You locked in ${formatChoice(choice)}. Waiting for opponent…`);
          highlightOnlineChoice(choice);
          toggleChoiceButtons(false);
          setOnlineResult('waiting', `You locked in ${formatChoice(choice)}. Awaiting reveal…`);
        });
      }

      if (chatForm) {
        chatForm.addEventListener('submit', (event) => {
          event.preventDefault();
          if (!authState.isLoggedIn) {
            setLog('Discord login required before chatting.');
            return;
          }
          if (!socket || !currentPartyCode) return;
          const text = chatInput && chatInput.value ? chatInput.value.trim() : '';
          if (!text) return;
          socket.emit('chatMessage', { code: currentPartyCode, text });
          if (chatInput) chatInput.value = '';
        });
      }

      if (authState.isLoggedIn) {
        try {
          if (window.io) {
            const options = {};
            if (socketEndpoint) {
              options.transports = ['websocket', 'polling'];
              socket = io(socketEndpoint, options);
            } else {
              socket = io();
            }
            wireSocketEvents();
            setLog('Connected. Create or join a party to begin.');
          } else {
            setLog('Socket.IO client script failed to load.');
            setOnlineResult('error', 'Socket.IO client script failed to load.');
          }
        } catch (error) {
          console.error('Socket.IO initialization failed:', error);
          setLog('Unable to connect to server. Online play disabled.');
          setOnlineResult('error', 'Unable to connect to server.');
        }
      } else {
        setLog('Discord login required before accessing multiplayer.');
      }
    });
  </script>
</body>
</html>
