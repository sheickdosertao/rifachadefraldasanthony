<?php
// admin.php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);  

session_start();
require_once __DIR__ . '/db.php';

$pdo = getPDO();
initializeDatabase($pdo);
releaseExpiredPending($pdo);

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin123';

if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    unset($_SESSION['admin_logged']);
    header('Location: admin.php');
    exit;
}

// Tela de Login do Administrador
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    $loginError = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        $user = (string) ($_POST['username'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');

        if (hash_equals(ADMIN_USER, $user) && hash_equals(ADMIN_PASS, $pass)) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin.php');
            exit;
        }
        $loginError = 'Usuário ou senha inválidos.';
    }
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Login - Admin Rifa</title>
      <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-slate-100 flex items-center justify-center px-4">
      <form method="post" class="w-full max-w-sm rounded-2xl bg-white border border-slate-200 shadow-sm p-5">
        <h1 class="text-xl font-bold text-slate-800">Painel Admin</h1>
        <?php if ($loginError !== ''): ?>
          <div class="mt-3 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-rose-700 text-sm"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <input type="hidden" name="action" value="login">
        <div class="mt-4">
          <label class="block text-sm mb-1">Usuário</label>
          <input type="text" name="username" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
        <div class="mt-3">
          <label class="block text-sm mb-1">Senha</label>
          <input type="password" name="password" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
        <button type="submit" class="mt-4 w-full rounded-lg bg-cyan-600 text-white py-2 hover:bg-cyan-700">Entrar</button>
      </form>
    </body>
    </html>
    <?php
    exit;
}

$statusMessage = '';
$statusError = '';

// Ação de confirmar pagamento (Muda de 'pending' para 'paid')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_payment') {
    $buyerName = trim((string) ($_POST['buyer_name'] ?? ''));
    $buyerPhone = trim((string) ($_POST['buyer_phone'] ?? ''));
    $createdAt = trim((string) ($_POST['created_at'] ?? ''));

    if ($buyerName === '' || $buyerPhone === '' || $createdAt === '') {
        $statusError = 'Dados inválidos para confirmação.';
    } else {
        $stmt = $pdo->prepare(
            "UPDATE tickets SET status = 'paid' WHERE status = 'pending' AND buyer_name = ? AND buyer_phone = ? AND created_at = ?"
        );
        $stmt->execute([$buyerName, $buyerPhone, $createdAt]);

        if ($stmt->rowCount() > 0) {
            $statusMessage = 'Pagamento confirmado com sucesso! Os números agora constam como pagos.';
        } else {
            $statusError = 'Nenhuma reserva pendente encontrada.';
        }
    }
}

// Busca as reservas PENDENTES (aguardando PIX)
$pendingReservations = $pdo->query(
    "SELECT buyer_name, buyer_phone, created_at, GROUP_CONCAT(ticket_number ORDER BY ticket_number SEPARATOR ', ') AS numbers, COUNT(*) AS total_tickets
     FROM tickets WHERE status = 'pending' GROUP BY buyer_name, buyer_phone, created_at ORDER BY created_at ASC"
)->fetchAll();

// Busca os compradores CONFIRMADOS (Lista de participantes ativos para achar o ganhador)
$paidReservations = $pdo->query(
    "SELECT buyer_name, buyer_phone, GROUP_CONCAT(ticket_number ORDER BY ticket_number SEPARATOR ', ') AS numbers, COUNT(*) AS total_tickets
     FROM tickets WHERE status = 'paid' GROUP BY buyer_name, buyer_phone ORDER BY buyer_name ASC"
)->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Painel de Controle - Rifa Anthony Benjamin</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 pb-12">
  <main class="max-w-6xl mx-auto px-4 py-6">
    
    <!-- Cabeçalho -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 pb-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Gerenciamento da Rifa</h1>
        <p class="text-sm text-slate-500">Prêmio: R$ 200,00 | Sorteio: 29/07/2026</p>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="w-full sm:w-auto rounded-lg bg-rose-600 text-white px-4 py-2 text-sm font-medium hover:bg-rose-700">Sair do Painel</button>
      </form>
    </div>

    <!-- Alertas de Ação -->
    <?php if ($statusMessage !== ''): ?>
      <div class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-emerald-800 font-medium"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($statusError !== ''): ?>
      <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-rose-800 font-medium"><?= htmlspecialchars($statusError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- 🔍 SISTEMA DE BUSCA PARA INDENTIFICAR O GANHADOR -->
    <section class="mt-6 rounded-2xl border border-cyan-100 bg-cyan-50/50 p-4 shadow-sm">
      <h2 class="text-md font-bold text-cyan-900 mb-2">🔍 Identificar Ganhador / Buscar Número</h2>
      <input id="searchWinnerInput" type="text" maxlength="4" placeholder="Digite o número sorteado (ex: 5412) para saber quem ganhou..."
             class="w-full rounded-xl border border-cyan-200 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-cyan-500 bg-white placeholder-slate-400 font-medium">
    </section>

    <!-- 1. SEÇÃO DE RESERVAS PENDENTES (Aguardando você conferir o Pix) -->
    <section class="mt-8">
      <h2 class="text-lg font-bold text-amber-700 mb-3">⏳ Reservas Pendentes (Aguardando Comprovante)</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php if (count($pendingReservations) === 0): ?>
          <p class="text-slate-500 italic text-sm">Nenhuma reserva aguardando aprovação no momento.</p>
        <?php else: ?>
          <?php foreach ($pendingReservations as $res):
              $bName = (string) $res['buyer_name'];
              $bPhone = (string) $res['buyer_phone'];
              $cAt = (string) $res['created_at'];
              $nums = (string) $res['numbers'];
              $waNum = preg_replace('/\D/', '', $bPhone);
              $waText = rawurlencode("Olá " . $bName . ", seu pagamento foi recebido e seus números da sorte (" . $nums . ") foram confirmados para o sorteio do dia 29/07! Boa sorte! 🎉");
          ?>
            <article class="buyer-card rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-numbers="<?= $nums ?>">
              <div class="space-y-1 text-sm">
                <p class="text-base">👤 <strong class="text-slate-900"><?= htmlspecialchars($bName, ENT_QUOTES, 'UTF-8') ?></strong></p>
                <p class="text-slate-600">📞 <strong>WhatsApp:</strong> <?= htmlspecialchars($bPhone, ENT_QUOTES, 'UTF-8') ?></p>
                <p class="text-slate-600">🔢 <strong>Números Reservados:</strong> <span class="bg-amber-50 text-amber-800 font-mono font-bold px-1.5 py-0.5 rounded border border-amber-200 text-xs"><?= htmlspecialchars($nums, ENT_QUOTES, 'UTF-8') ?></span></p>
                <p class="text-xs text-slate-400">📅 Solicitado em: <?= htmlspecialchars($cAt, ENT_QUOTES, 'UTF-8') ?></p>
              </div>
              <div class="mt-4 flex gap-2">
                <form method="post" class="inline">
                  <input type="hidden" name="action" value="confirm_payment">
                  <input type="hidden" name="buyer_name" value="<?= htmlspecialchars($bName, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="buyer_phone" value="<?= htmlspecialchars($bPhone, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="created_at" value="<?= htmlspecialchars($cAt, ENT_QUOTES, 'UTF-8') ?>">
                  <button type="submit" class="rounded-lg bg-emerald-600 text-white text-xs font-semibold px-3 py-2 hover:bg-emerald-700 transition">Confirmar Pago</button>
                </form>
                <a target="_blank" rel="noopener noreferrer" href="https://wa.me/55<?= $waNum ?>?text=<?= $waText ?>"
                   class="rounded-lg bg-slate-100 text-slate-700 text-xs font-semibold px-3 py-2 hover:bg-slate-200 transition text-center">Enviar Mensagem</a>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- 2. SEÇÃO DE BILHETES CONFIRMADOS / PAGOS (Onde você vai achar o ganhador) -->
    <section class="mt-10 border-t border-slate-200 pt-6">
      <h2 class="text-lg font-bold text-emerald-700 mb-3">✅ Participantes Confirmados (Pagamento Aprovado)</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php if (count($paidReservations) === 0): ?>
          <p class="text-slate-500 italic text-sm">Nenhum bilhete foi marcado como pago ainda.</p>
        <?php else: ?>
          <?php foreach ($paidReservations as $res): 
              $numsPaid = (string) $res['numbers'];
          ?>
            <article class="buyer-card rounded-xl border border-slate-200 bg-white p-4 shadow-sm border-l-4 border-l-emerald-500" data-numbers="<?= $numsPaid ?>">
              <div class="space-y-1 text-sm">
                <p class="text-base">👤 <strong class="text-slate-900"><?= htmlspecialchars((string)$res['buyer_name'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                <p class="text-slate-600">📞 <strong>WhatsApp:</strong> <?= htmlspecialchars((string)$res['buyer_phone'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="text-slate-600">🎟️ <strong>Bilhetes Comprados (<?= (int)$res['total_tickets'] ?>):</strong></p>
                <div class="flex flex-wrap gap-1 mt-1">
                  <?php 
                    $arrNums = explode(', ', $numsPaid);
                    foreach($arrNums as $n):
                  ?>
                    <span class="bg-emerald-50 text-emerald-800 font-mono font-bold px-2 py-0.5 rounded border border-emerald-200 text-xs"><?= htmlspecialchars($n, ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

  </main>

  <!-- Script Inteligente de Busca em Tempo Real -->
  <script>
    const searchInput = document.getElementById('searchWinnerInput');
    const cards = Array.from(document.querySelectorAll('.buyer-card'));

    searchInput.addEventListener('input', () => {
      const query = searchInput.value.trim();

      cards.forEach((card) => {
        // Puxa a string de números associada ao comprador
        const numbersString = card.dataset.numbers || '';
        // Transforma em uma lista limpa de números individuais
        const individualNumbers = numbersString.split(',').map(n => n.trim());

        if (query === '') {
          // Se a busca estiver vazia, exibe todos os cards normalmente
          card.style.display = '';
          card.classList.remove('bg-yellow-50', 'border-yellow-400', 'scale-105');
        } else {
          // Se o número digitado bater exatamente com algum número que o comprador possui
          const hasNumber = individualNumbers.includes(query);
          
          if (hasNumber) {
            card.style.display = '';
            // Destaca o card do ganhador visualmente
            card.classList.add('bg-yellow-50', 'border-yellow-400', 'scale-105');
          } else {
            card.style.display = 'none';
            card.classList.remove('bg-yellow-50', 'border-yellow-400', 'scale-105');
          }
        }
      });
    });
  </script>
</body>
</html>