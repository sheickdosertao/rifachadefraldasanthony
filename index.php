<?php
// index.php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);  

session_start();
require_once __DIR__ . '/db.php';

$pdo = getPDO();
initializeDatabase($pdo);
releaseExpiredPending($pdo);

if (!isset($_SESSION['selected_tickets']) || !is_array($_SESSION['selected_tickets'])) {
    $_SESSION['selected_tickets'] = [];
}

$action = $_POST['action'] ?? '';
if ($action === 'toggle' && isset($_POST['ticket'])) {
    $ticket = preg_replace('/\D/', '', (string) $_POST['ticket']);
    // Validação alterada para Centena: entre 000 e 999
    if ($ticket !== '' && (int) $ticket >= 000 && (int) $ticket <= 999) {
        $check = $pdo->prepare("SELECT status FROM tickets WHERE ticket_number = ? LIMIT 1");
        $check->execute([$ticket]);
        $status = $check->fetchColumn();

        if ($status === 'available') {
            if (in_array($ticket, $_SESSION['selected_tickets'], true)) {
                $_SESSION['selected_tickets'] = array_values(array_filter(
                    $_SESSION['selected_tickets'],
                    static fn($t) => $t !== $ticket
                ));
            } else {
                $_SESSION['selected_tickets'][] = $ticket;
                $_SESSION['selected_tickets'] = array_values(array_unique($_SESSION['selected_tickets']));
            }
        }
    }

    header('Location: index.php');
    exit;
}

if ($action === 'clear_selection') {
    $_SESSION['selected_tickets'] = [];
    header('Location: index.php');
    exit;
}

$selected = $_SESSION['selected_tickets'];
$selectedCount = count($selected);

$tickets = $pdo->query('SELECT ticket_number, status FROM tickets ORDER BY ticket_number ASC')->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rifa Chá de Fraldas Anthony Benjamin</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-b from-cyan-50 via-white to-rose-50 text-slate-800">
  <main class="max-w-6xl mx-auto px-4 py-6 pb-28">
    <section class="rounded-2xl border border-cyan-100 bg-white/80 backdrop-blur p-5 shadow-sm text-center md:text-left">
      <h1 class="text-2xl md:text-3xl font-bold text-cyan-800">Rifa Chá de Fraldas Anthony Benjamin</h1>
      
      <div class="mt-4 p-4 rounded-xl bg-cyan-50 border border-cyan-200 inline-block text-left w-full">
        <p class="text-lg">🎁 **Prêmio:** <strong class="text-cyan-700">R$ 200,00</strong></p>
        <p class="text-sm mt-1">📅 **Sorteio:** 29/07/2026 (Quarta-feira) pela Loteria Federal</p>
        <!-- Texto atualizado para CENTENA -->
        <p class="text-xs text-slate-500 mt-1">🔢 *Regra: O resultado será baseado nos 3 últimos números (Centena) do 1º prêmio da Loteria Federal.*</p>
        <p class="text-sm mt-2 font-medium text-slate-700">💵 Valor por número: <strong>R$5,00</strong></p>
      </div>

      <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-3">
        <!-- Maxlength alterado para 3 -->
        <input id="searchInput" type="text" maxlength="3" placeholder="Buscar número (ex: 123)"
               class="w-full rounded-xl border border-slate-300 px-4 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-400">
        <select id="statusFilter" class="w-full rounded-xl border border-slate-300 px-4 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-400">
          <option value="all">Todos</option>
          <option value="available">Disponíveis</option>
          <option value="pending">Pendentes</option>
          <option value="paid">Pagos</option>
        </select>
        <form method="post" class="w-full">
          <input type="hidden" name="action" value="clear_selection">
          <button type="submit" class="w-full rounded-xl bg-slate-100 text-slate-700 px-4 py-2 hover:bg-slate-200">Limpar seleção</button>
        </form>
      </div>
    </section>

    <section class="mt-6">
      <div id="ticketsGrid" class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 lg:grid-cols-10 gap-2">
        <?php foreach ($tickets as $ticket):
            $number = htmlspecialchars((string)$ticket['ticket_number'], ENT_QUOTES, 'UTF-8');
            $status = $ticket['status'];
            $isSelected = in_array($ticket['ticket_number'], $selected, true);

            $classes = 'rounded-lg border text-sm py-2 text-center font-semibold transition';
            if ($status === 'available') {
                $classes .= $isSelected
                    ? ' bg-cyan-600 text-white border-cyan-700'
                    : ' bg-white text-cyan-700 border-cyan-300 hover:bg-cyan-50';
            } elseif ($status === 'pending') {
                $classes .= ' bg-amber-100 text-amber-700 border-amber-300 cursor-not-allowed';
            } else {
                $classes .= ' bg-rose-100 text-rose-700 border-rose-300 cursor-not-allowed';
            }
        ?>
          <div class="ticket-item" data-number="<?= $number ?>" data-status="<?= htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($status === 'available'): ?>
              <form method="post">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="ticket" value="<?= $number ?>">
                <button type="submit" class="w-full <?= $classes ?>"><?= $number ?></button>
              </form>
            <?php else: ?>
              <div class="w-full <?= $classes ?>"><?= $number ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </main>

  <a href="checkout.php"
     class="fixed bottom-5 right-5 inline-flex items-center gap-2 rounded-full bg-cyan-600 px-5 py-3 text-white shadow-lg hover:bg-cyan-700">
      Ir para carrinho
      <span class="rounded-full bg-white/20 px-2 py-0.5 text-sm"><?= $selectedCount ?></span>
  </a>

  <script>
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const ticketItems = Array.from(document.querySelectorAll('.ticket-item'));

    function applyFilters() {
      const query = searchInput.value.trim();
      const status = statusFilter.value;

      ticketItems.forEach((item) => {
        const number = item.dataset.number;
        const ticketStatus = item.dataset.status;

        const matchesSearch = query === '' || number.includes(query);
        const matchesStatus = status === 'all' || ticketStatus === status;

        item.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
      });
    }

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
  </script>
</body>
</html>