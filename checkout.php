<?php
// checkout.php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/db.php';

$pdo = getPDO();
initializeDatabase($pdo);
releaseExpiredPending($pdo);

const PRICE_PER_TICKET = 10.00;
const PIX_KEY = '05879753425';
const NOTIFY_WHATSAPP = '5571997135969';

if (!isset($_SESSION['selected_tickets']) || !is_array($_SESSION['selected_tickets'])) {
    $_SESSION['selected_tickets'] = [];
}

// Expressão regular mudada para aceitar 3 dígitos da centena
$selected = array_values(array_unique(array_filter(
    $_SESSION['selected_tickets'],
    static fn($t) => preg_match('/^\d{3}$/', (string) $t) === 1
)));

$message = '';
$error = '';
$redirectUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_reservation') {
    $name = trim((string) ($_POST['buyer_name'] ?? ''));
    $phone = preg_replace('/\D/', '', (string) ($_POST['buyer_phone'] ?? ''));

    if ($name === '' || mb_strlen($name) < 3) {
        $error = 'Informe um nome válido.';
    } elseif ($phone === '' || strlen($phone) < 10) {
        $error = 'Informe um telefone/WhatsApp válido.';
    } elseif (count($selected) === 0) {
        $error = 'Nenhum número selecionado.';
    } else {
        try {
            $pdo->beginTransaction();

            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            $checkSql = "SELECT ticket_number, status FROM tickets WHERE ticket_number IN ($placeholders) FOR UPDATE";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute($selected);
            $rows = $checkStmt->fetchAll();

            if (count($rows) !== count($selected)) {
                throw new RuntimeException('Alguns números não foram encontrados.');
            }

            foreach ($rows as $row) {
                if ($row['status'] !== 'available') {
                    throw new RuntimeException('Um ou mais números já não estão disponíveis.');
                }
            }

            $updateSql = "UPDATE tickets SET status = 'pending', buyer_name = ?, buyer_phone = ?, created_at = NOW() WHERE ticket_number IN ($placeholders)";
            $params = array_merge([$name, $phone], $selected);
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($params);

            $pdo->commit();

            $listaNumeros = implode(', ', $selected);
            $textoWhats = "Olá! Acabei de reservar as centenas na Rifa do Anthony Benjamin!\n\n👤 *Nome:* $name\n📞 *Contato:* $phone\n🔢 *Centenas:* $listaNumeros\n\nEstou enviando o comprovante do Pix!";
            $redirectUrl = 'https://wa.me/' . NOTIFY_WHATSAPP . '?text=' . rawurlencode($textoWhats);

            $_SESSION['selected_tickets'] = [];
            $selected = [];
            $message = 'Reserva realizada! Redirecionando para o WhatsApp do organizador para envio do comprovante...';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$total = count($selected) * PRICE_PER_TICKET;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Carrinho - Rifa Chá de Fraldas</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php if ($redirectUrl !== ''): ?>
    <meta http-equiv="refresh" content="3;url=<?= $redirectUrl ?>">
  <?php endif; ?>
</head>
<body class="min-h-screen bg-gradient-to-b from-rose-50 via-white to-cyan-50 text-slate-800">
  <main class="max-w-3xl mx-auto px-4 py-6">
    <a href="index.php" class="text-cyan-700 hover:underline">&larr; Voltar para números</a>

    <h1 class="mt-3 text-2xl md:text-3xl font-bold text-cyan-800">Carrinho da Rifa</h1>

    <?php if ($message !== ''): ?>
      <div class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-emerald-800">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        <br><br>
        <a href="<?= $redirectUrl ?>" class="font-bold underline text-emerald-900">Clique aqui se não for redirecionado automaticamente.</a>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-rose-800"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($redirectUrl === ''): ?>
    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-lg font-semibold">Centenas selecionadas</h2>
      <?php if (count($selected) > 0): ?>
        <div class="mt-3 flex flex-wrap gap-2">
          <?php foreach ($selected as $ticket): ?>
            <span class="rounded-full bg-cyan-100 px-3 py-1 text-cyan-700 font-semibold"><?= htmlspecialchars((string)$ticket, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="mt-3 text-slate-600">Nenhum número selecionado.</p>
      <?php endif; ?>
      <p class="mt-4 text-lg">Total: <strong>R$ <?= number_format($total, 2, ',', '.') ?></strong></p>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-lg font-semibold">Pagamento via Pix</h2>
      <p class="text-sm text-slate-600 mt-1">Prêmio de R$ 200,00 - Extração da Centena Federal em 29/07/2026</p>
      <div class="mt-4 flex flex-col md:flex-row gap-5 md:items-center">
        <img class="w-44 h-44 rounded-xl border border-slate-200 object-cover" src="qrcodeinter.jpeg" alt="QR Code Pix">
        <div>
          <p class="text-sm text-slate-600">Chave Pix manual</p>
          <div class="mt-1 flex items-center gap-2">
            <input id="pixKey" type="text" readonly value="<?= htmlspecialchars(PIX_KEY, ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-64">
            <button id="copyPixButton" type="button" class="rounded-lg bg-cyan-600 text-white px-3 py-2 text-sm hover:bg-cyan-700">Copiar chave</button>
          </div>
        </div>
      </div>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-lg font-semibold">Seus dados para a reserva</h2>
      <form method="post" class="mt-3 space-y-3">
        <input type="hidden" name="action" value="confirm_reservation">
        <div>
          <label class="block text-sm mb-1">Nome Completo</label>
          <input type="text" name="buyer_name" required minlength="3" class="w-full rounded-xl border border-slate-300 px-4 py-2">
        </div>
        <div>
          <label class="block text-sm mb-1">Seu WhatsApp</label>
          <input type="text" name="buyer_phone" required placeholder="(11) 99999-9999" class="w-full rounded-xl border border-slate-300 px-4 py-2">
        </div>
        <button type="submit" class="w-full md:w-auto rounded-xl bg-cyan-600 text-white px-6 py-2 font-semibold hover:bg-cyan-700" <?= count($selected) === 0 ? 'disabled' : '' ?>>
          Confirmar Reserva e Enviar Comprovante
        </button>
      </form>
    </section>
    <?php endif; ?>
  </main>

  <script>
    if(document.getElementById('copyPixButton')) {
        document.getElementById('copyPixButton').addEventListener('click', async () => {
          const pixKey = document.getElementById('pixKey').value;
          try {
            await navigator.clipboard.writeText(pixKey);
            alert('Chave Pix copiada!');
          } catch (e) {
            alert('Copie manualmente.');
          }
        });
    }
  </script>
</body>
</html>